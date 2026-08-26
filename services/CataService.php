<?php

/**
 * Ciclo de vida de las catas dirigidas y de sus inscripciones.
 *
 * Concentra las tres reglas que no caben en el modelo porque dependen de más de
 * una fila: cuántos lugares quedan, si una cata sigue admitiendo gente, y el
 * paso automático a 'agotada'.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Cata;
use Model\CataInscripcion;

class CataService
{
    public const OK = 'OK';
    public const NO_EXISTE = 'NO_EXISTE';
    public const CERRADA = 'CERRADA';
    public const SIN_CUPO = 'SIN_CUPO';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const DEMASIADOS_ENVIOS = 'DEMASIADOS_ENVIOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    /**
     * Ventana y tope del freno de reenvíos. Los endpoints públicos de catas no
     * pasan por OTP como los de reservaciones, así que este contador es lo
     * único que separa un formulario abierto de un buzón de spam.
     */
    private const ENVIOS_MAX_POR_VENTANA = 3;
    private const ENVIOS_VENTANA_MINUTOS = 60;

    /**
     * Agenda pública: catas publicadas o agotadas que aún no han ocurrido.
     *
     * Las agotadas se incluyen a propósito — saber que la próxima cata se llenó
     * dice más que no ver nada—, y cada fila llega con sus lugares restantes ya
     * calculados para que la vista no consulte por su cuenta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function agendaPublica(int $limite = 6): array
    {
        $limite = max(1, min(24, $limite));
        $ahora = ReservacionConfig::ahora()->format('Y-m-d H:i:s');

        $sql = "SELECT c.*,
                       COALESCE(SUM(i.personas), 0) AS lugares_tomados
                FROM catas c
                LEFT JOIN cata_inscripciones i
                       ON i.cata_id = c.id
                      AND i.estado IN (" . self::listaEstadosQueOcupan() . ")
                WHERE c.estado IN ('publicada', 'agotada')
                  AND TIMESTAMP(c.fecha, c.hora) >= '" . ActiveRecord::escaparString($ahora) . "'
                GROUP BY c.id
                ORDER BY c.fecha ASC, c.hora ASC
                LIMIT {$limite}";

        return array_map([self::class, 'decorarFila'], self::filas($sql));
    }

    /**
     * Lugares que quedan libres. Nunca baja de cero: si el admin recorta el
     * cupo por debajo de lo ya inscrito, el sobrecupo se resuelve a mano y la
     * agenda pública se limita a dejar de ofrecer lugares.
     */
    public static function lugaresDisponibles(int $cataId): int
    {
        $cata = Cata::find($cataId);
        if (!$cata) {
            return 0;
        }

        return max(0, (int)$cata->cupo - self::lugaresTomados($cataId));
    }

    public static function lugaresTomados(int $cataId): int
    {
        $sql = "SELECT COALESCE(SUM(personas), 0) AS tomados
                FROM cata_inscripciones
                WHERE cata_id = " . (int)$cataId . "
                  AND estado IN (" . self::listaEstadosQueOcupan() . ")";

        $filas = self::filas($sql);
        return (int)($filas[0]['tomados'] ?? 0);
    }

    /**
     * Alta pública de una inscripción.
     *
     * @param array<string, mixed> $datos
     * @return array{ok: bool, codigo: string, mensaje: string, inscripcion?: CataInscripcion, disponibles?: int}
     */
    public static function inscribir(array $datos): array
    {
        $cataId = (int)($datos['cata_id'] ?? 0);
        $cata = $cataId > 0 ? Cata::find($cataId) : null;

        if (!$cata) {
            return self::error(self::NO_EXISTE, 'La cata seleccionada ya no está disponible.');
        }

        if (!self::admiteInscripciones($cata)) {
            return self::error(self::CERRADA, 'Las inscripciones para esta cata están cerradas.');
        }

        $inscripcion = new CataInscripcion();
        $inscripcion->cata_id = $cataId;
        $inscripcion->nombre = trim((string)($datos['nombre'] ?? ''));
        $inscripcion->contacto_tipo = (string)($datos['contacto_tipo'] ?? '');
        $inscripcion->contacto = (string)($datos['contacto'] ?? '');
        $inscripcion->personas = $datos['personas'] ?? 1;
        $nota = trim((string)($datos['nota'] ?? ''));
        $inscripcion->nota = $nota === '' ? null : $nota;
        $inscripcion->estado = 'pendiente';

        $alertas = $inscripcion->validar();
        if (!empty($alertas)) {
            return self::error(self::DATOS_INVALIDOS, self::primerMensaje($alertas));
        }

        // A partir de aquí el contacto ya validó, así que normalizar no lanza.
        $inscripcion->contacto = ContactoService::normalizar(
            $inscripcion->contacto_tipo,
            (string)$inscripcion->contacto
        );
        $inscripcion->personas = (int)$inscripcion->personas;

        if (self::excedeEnvios($inscripcion->contacto_tipo, $inscripcion->contacto)) {
            return self::error(
                self::DEMASIADOS_ENVIOS,
                'Ya registramos varias solicitudes con este contacto. Escríbenos si necesitas ayuda.'
            );
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            // El cupo se vuelve a leer dentro de la transacción y con la fila de
            // la cata bloqueada: sin esto, dos personas que envían el formulario
            // a la vez pueden pasar las dos por el mismo último lugar.
            $bloqueo = self::filas("SELECT cupo FROM catas WHERE id = {$cataId} FOR UPDATE");
            $cupo = (int)($bloqueo[0]['cupo'] ?? 0);
            $disponibles = max(0, $cupo - self::lugaresTomados($cataId));

            if ($inscripcion->personas > $disponibles) {
                $db->rollback();
                return array_merge(
                    self::error(self::SIN_CUPO, $disponibles > 0
                        ? "Sólo quedan {$disponibles} lugares en esta cata."
                        : 'Esta cata acaba de llenarse.'),
                    ['disponibles' => $disponibles]
                );
            }

            $stmt = $db->prepare(
                'INSERT INTO cata_inscripciones
                    (cata_id, nombre, contacto_tipo, contacto, personas, nota, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $stmt->bind_param(
                'isssiss',
                $inscripcion->cata_id,
                $inscripcion->nombre,
                $inscripcion->contacto_tipo,
                $inscripcion->contacto,
                $inscripcion->personas,
                $inscripcion->nota,
                $inscripcion->estado
            );

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($error);
            }

            $inscripcion->id = (int)$db->insert_id;
            $stmt->close();
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('CataService::inscribir - ' . $e->getMessage());
            return self::error(self::ERROR_GUARDADO, 'No pudimos registrar tu inscripción. Inténtalo de nuevo.');
        }

        // Fuera de la transacción: que el cierre por cupo lleno falle no debe
        // tirar una inscripción ya confirmada.
        self::sincronizarCupo($cataId);

        return [
            'ok' => true,
            'codigo' => self::OK,
            'mensaje' => 'Listo, tu lugar quedó apartado. Te confirmamos por ' .
                ($inscripcion->contacto_tipo === 'email' ? 'correo' : 'teléfono') . '.',
            'inscripcion' => $inscripcion,
            'disponibles' => self::lugaresDisponibles($cataId),
        ];
    }

    /**
     * Ajusta el estado de la cata al cupo real. Va en las dos direcciones: si se
     * cancela una inscripción de una cata agotada, vuelve a publicarse sola.
     */
    public static function sincronizarCupo(int $cataId): void
    {
        $cata = Cata::find($cataId);
        if (!$cata || !in_array($cata->estado, ['publicada', 'agotada'], true)) {
            return;
        }

        $lleno = self::lugaresTomados($cataId) >= (int)$cata->cupo;
        $nuevo = $lleno ? 'agotada' : 'publicada';

        if ($nuevo !== $cata->estado) {
            ActiveRecord::ejecutarSQL(
                "UPDATE catas SET estado = '" . ActiveRecord::escaparString($nuevo) . "'
                 WHERE id = " . (int)$cataId . " LIMIT 1"
            );
        }
    }

    public static function admiteInscripciones(Cata $cata): bool
    {
        if (!in_array($cata->estado, Cata::ESTADOS_ABIERTOS, true)) {
            return false;
        }

        $inicio = $cata->inicio();
        return $inicio !== null && $inicio > ReservacionConfig::ahora();
    }

    /**
     * Listado del panel, con los lugares ya contados. `$estado` vacío trae todo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listaAdministrativa(string $estado = '', string $busqueda = ''): array
    {
        $condiciones = [];

        if ($estado !== '' && in_array($estado, Cata::ESTADOS, true)) {
            $condiciones[] = "c.estado = '" . ActiveRecord::escaparString($estado) . "'";
        }

        $busqueda = trim($busqueda);
        if ($busqueda !== '') {
            $like = ActiveRecord::escaparString($busqueda);
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
            $condiciones[] = "c.titulo LIKE '%{$like}%'";
        }

        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        $sql = "SELECT c.*,
                       COALESCE(SUM(i.personas), 0) AS lugares_tomados,
                       COUNT(DISTINCT i.id) AS inscripciones
                FROM catas c
                LEFT JOIN cata_inscripciones i
                       ON i.cata_id = c.id
                      AND i.estado IN (" . self::listaEstadosQueOcupan() . ")
                {$where}
                GROUP BY c.id
                ORDER BY c.fecha DESC, c.hora DESC";

        return array_map([self::class, 'decorarFila'], self::filas($sql));
    }

    /** @return array<int, array<string, mixed>> */
    public static function inscripcionesDe(int $cataId): array
    {
        $sql = "SELECT * FROM cata_inscripciones
                WHERE cata_id = " . (int)$cataId . "
                ORDER BY created_at DESC, id DESC";

        return self::filas($sql);
    }

    /**
     * @return array{ok: bool, codigo: string, mensaje: string}
     */
    public static function cambiarEstadoInscripcion(int $inscripcionId, string $estado): array
    {
        if (!in_array($estado, CataInscripcion::ESTADOS, true)) {
            return self::error(self::DATOS_INVALIDOS, 'El estado indicado no es válido.');
        }

        $inscripcion = CataInscripcion::find($inscripcionId);
        if (!$inscripcion) {
            return self::error(self::NO_EXISTE, 'La inscripción no existe.');
        }

        $ok = ActiveRecord::ejecutarSQL(
            "UPDATE cata_inscripciones
             SET estado = '" . ActiveRecord::escaparString($estado) . "'
             WHERE id = " . (int)$inscripcionId . " LIMIT 1"
        );

        if (!$ok) {
            return self::error(self::ERROR_GUARDADO, 'No se pudo actualizar la inscripción.');
        }

        // Cancelar libera lugar y puede reabrir una cata agotada.
        self::sincronizarCupo((int)$inscripcion->cata_id);

        return ['ok' => true, 'codigo' => self::OK, 'mensaje' => 'Inscripción actualizada.'];
    }

    /**
     * Alta o edición desde el panel. Se usa SQL preparado porque `descripcion` e
     * `imagen` son nulables y el crear()/actualizar() genérico de ActiveRecord
     * entrecomilla todos los valores, convirtiendo el NULL en cadena vacía.
     *
     * @return array{ok: bool, codigo: string, mensaje: string, cata?: Cata}
     */
    public static function guardar(Cata $cata): array
    {
        $alertas = $cata->validar();
        if (!empty($alertas)) {
            return self::error(self::DATOS_INVALIDOS, self::primerMensaje($alertas));
        }

        $db = ActiveRecord::getDB();
        $descripcion = self::nuloSiVacio($cata->descripcion);
        $imagen = self::nuloSiVacio($cata->imagen);
        $hora = Cata::horaCompleta((string)$cata->hora);
        $duracion = (int)$cata->duracion_min;
        $cupo = (int)$cata->cupo;
        $precio = (float)$cata->precio;

        try {
            if ($cata->id) {
                $stmt = $db->prepare(
                    'UPDATE catas
                        SET titulo = ?, descripcion = ?, fecha = ?, hora = ?, duracion_min = ?,
                            cupo = ?, precio = ?, imagen = ?, estado = ?
                      WHERE id = ? LIMIT 1'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $id = (int)$cata->id;
                $stmt->bind_param(
                    'ssssiidssi',
                    $cata->titulo,
                    $descripcion,
                    $cata->fecha,
                    $hora,
                    $duracion,
                    $cupo,
                    $precio,
                    $imagen,
                    $cata->estado,
                    $id
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO catas
                        (titulo, descripcion, fecha, hora, duracion_min, cupo, precio, imagen, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $stmt->bind_param(
                    'ssssiidss',
                    $cata->titulo,
                    $descripcion,
                    $cata->fecha,
                    $hora,
                    $duracion,
                    $cupo,
                    $precio,
                    $imagen,
                    $cata->estado
                );
            }

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($error);
            }

            if (!$cata->id) {
                $cata->id = (int)$db->insert_id;
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('CataService::guardar - ' . $e->getMessage());
            return self::error(self::ERROR_GUARDADO, 'No se pudo guardar la cata.');
        }

        self::sincronizarCupo((int)$cata->id);

        return ['ok' => true, 'codigo' => self::OK, 'mensaje' => 'Cata guardada correctamente.', 'cata' => $cata];
    }

    /**
     * Añade a la fila lo que la vista necesita y no está en la tabla: lugares
     * restantes, si sigue abierta y las piezas de la fecha ya formateadas.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function decorarFila(array $fila): array
    {
        $cupo = (int)($fila['cupo'] ?? 0);
        $tomados = (int)($fila['lugares_tomados'] ?? 0);
        $fila['lugares_tomados'] = $tomados;
        $fila['lugares_disponibles'] = max(0, $cupo - $tomados);

        $inicio = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string)($fila['fecha'] ?? '') . ' ' . Cata::horaCompleta((string)($fila['hora'] ?? '')),
            ReservacionConfig::timezone()
        );

        $fila['inicio'] = $inicio ?: null;
        $fila['es_futura'] = $inicio ? $inicio > ReservacionConfig::ahora() : false;
        $fila['abierta'] = $fila['es_futura']
            && $fila['estado'] === 'publicada'
            && $fila['lugares_disponibles'] > 0;

        return $fila;
    }

    /**
     * Cuenta los envíos recientes del mismo contacto. Mira las dos tablas
     * públicas: quien inunda el formulario de catas suele probar también el de
     * catering, y un freno por tabla no lo detendría.
     */
    private static function excedeEnvios(string $tipo, string $contacto): bool
    {
        $desde = ReservacionConfig::ahora()
            ->modify('-' . self::ENVIOS_VENTANA_MINUTOS . ' minutes')
            ->format('Y-m-d H:i:s');

        $tipoSql = ActiveRecord::escaparString($tipo);
        $contactoSql = ActiveRecord::escaparString($contacto);
        $desdeSql = ActiveRecord::escaparString($desde);

        $sql = "SELECT
                  (SELECT COUNT(*) FROM cata_inscripciones
                    WHERE contacto_tipo = '{$tipoSql}' AND contacto = '{$contactoSql}'
                      AND created_at >= '{$desdeSql}')
                + (SELECT COUNT(*) FROM catering_solicitudes
                    WHERE contacto_tipo = '{$tipoSql}' AND contacto = '{$contactoSql}'
                      AND created_at >= '{$desdeSql}') AS envios";

        $filas = self::filas($sql);
        return (int)($filas[0]['envios'] ?? 0) >= self::ENVIOS_MAX_POR_VENTANA;
    }

    private static function listaEstadosQueOcupan(): string
    {
        return implode(', ', array_map(
            static fn (string $estado): string => "'" . ActiveRecord::escaparString($estado) . "'",
            CataInscripcion::ESTADOS_QUE_OCUPAN
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private static function filas(string $sql): array
    {
        $resultado = ActiveRecord::getDB()->query($sql);
        if (!$resultado) {
            error_log('CataService - consulta fallida: ' . ActiveRecord::getDB()->error);
            return [];
        }

        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $resultado->free();

        return $filas;
    }

    private static function nuloSiVacio($valor): ?string
    {
        $valor = trim((string)($valor ?? ''));
        return $valor === '' ? null : $valor;
    }

    /** @param array<int, array<string, string>> $alertas */
    private static function primerMensaje(array $alertas): string
    {
        foreach ($alertas as $mensajes) {
            foreach ((array)$mensajes as $mensaje) {
                return (string)$mensaje;
            }
        }

        return 'Revisa los datos del formulario.';
    }

    /** @return array{ok: bool, codigo: string, mensaje: string} */
    private static function error(string $codigo, string $mensaje): array
    {
        return ['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    private static function rollbackSeguro(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('CataService::rollback - ' . $e->getMessage());
        }
    }
}
