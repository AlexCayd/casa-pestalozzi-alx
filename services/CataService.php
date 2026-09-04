<?php

/**
 * Agenda de catas dirigidas.
 *
 * Lo que queda del servicio son dos listas —la pública y la del panel— y el
 * guardado. Antes concentraba el ciclo de vida de las inscripciones: cuántos
 * lugares quedaban, si una cata seguía admitiendo gente y el paso automático a
 * 'agotada'. Ese flujo se fue entero a WhatsApp, así que aquí ya no hay nada
 * que CONTAR: el cupo no se lleva, se declara.
 *
 * `catas.disponible` significa exactamente eso y sólo eso: si quedan lugares.
 * No decide la visibilidad —la agenda pública publica todo lo que no ha
 * ocurrido— y por eso apagar el interruptor NO retira la cata de la portada:
 * la deja marcada como sin cupo.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Cata;

class CataService
{
    public const OK = 'OK';
    public const NO_EXISTE = 'NO_EXISTE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    /**
     * Agenda pública: TODAS las catas programadas que aún no han ocurrido.
     *
     * El único filtro es el reloj. `disponible` ya no decide si una cata se
     * publica —sólo si le quedan lugares—, así que una cata sin cupo sigue en la
     * portada, marcada: saber que la próxima se llenó dice bastante más que no
     * ver nada, y deja abierta la conversación por si se libera un sitio.
     *
     * Cuando no queda ninguna, la sección cae a su estado vacío, que ya invita a
     * preguntar por la siguiente.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function agendaPublica(int $limite = 6): array
    {
        $limite = max(1, min(24, $limite));
        $ahora = ReservacionConfig::ahora()->format('Y-m-d H:i:s');

        $sql = "SELECT *
                FROM catas
                WHERE TIMESTAMP(fecha, hora) >= '" . ActiveRecord::escaparString($ahora) . "'
                ORDER BY fecha ASC, hora ASC
                LIMIT {$limite}";

        return array_map([self::class, 'decorarFila'], self::filas($sql));
    }

    /**
     * Listado del panel. Trae también las pasadas y las que no tienen cupo: es
     * la mesa de trabajo de quien programa.
     *
     * `$disponibilidad` acepta '1' (con cupo), '0' (sin cupo) o cadena vacía
     * para todas.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listaAdministrativa(string $disponibilidad = '', string $busqueda = ''): array
    {
        $condiciones = [];

        if ($disponibilidad === '1' || $disponibilidad === '0') {
            $condiciones[] = 'disponible = ' . (int)$disponibilidad;
        }

        $busqueda = trim($busqueda);
        if ($busqueda !== '') {
            $like = ActiveRecord::escaparString($busqueda);
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
            $condiciones[] = "titulo LIKE '%{$like}%'";
        }

        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        // El «ahora» sale de ReservacionConfig y no de NOW(): la zona horaria del
        // restaurante y la del servidor de base de datos no tienen por qué
        // coincidir, y aquí decide qué cata es futura.
        $ahora = ActiveRecord::escaparString(ReservacionConfig::ahora()->format('Y-m-d H:i:s'));

        // Las próximas primero y en orden de llegada; las pasadas al fondo y de
        // la más reciente hacia atrás. La lista se abre para trabajar sobre lo
        // que viene, no para consultar el archivo.
        $sql = "SELECT *
                FROM catas
                {$where}
                ORDER BY (TIMESTAMP(fecha, hora) >= '{$ahora}') DESC,
                         CASE WHEN TIMESTAMP(fecha, hora) >= '{$ahora}'
                              THEN TIMESTAMP(fecha, hora) END ASC,
                         TIMESTAMP(fecha, hora) DESC";

        return array_map([self::class, 'decorarFila'], self::filas($sql));
    }

    /**
     * Abre o cierra el cupo de una cata. Es la única mutación que el panel hace
     * fuera del formulario, y por eso vive suelta: se dispara desde la lista.
     *
     * No toca la visibilidad: la cata sigue en la portada en los dos casos.
     *
     * @return array{ok: bool, codigo: string, mensaje: string, disponible?: bool}
     */
    public static function cambiarDisponibilidad(int $cataId, bool $disponible): array
    {
        $cata = Cata::find($cataId);
        if (!$cata) {
            return self::error(self::NO_EXISTE, 'La cata no existe.');
        }

        $ok = ActiveRecord::ejecutarSQL(
            'UPDATE catas SET disponible = ' . ($disponible ? 1 : 0) .
            ' WHERE id = ' . (int)$cataId . ' LIMIT 1'
        );

        if (!$ok) {
            return self::error(self::ERROR_GUARDADO, 'No se pudo cambiar el cupo.');
        }

        return [
            'ok' => true,
            'codigo' => self::OK,
            'mensaje' => $disponible
                ? 'La cata vuelve a admitir gente.'
                : 'La cata se marcó sin cupo. Sigue anunciada en la landing.',
            'disponible' => $disponible,
        ];
    }

    /**
     * Alta o edición desde el panel. Se usa SQL preparado porque `descripcion`
     * es nulable y el crear()/actualizar() genérico de ActiveRecord entrecomilla
     * todos los valores, convirtiendo el NULL en cadena vacía.
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
        $hora = Cata::horaCompleta((string)$cata->hora);
        $duracion = (int)$cata->duracion_min;
        $precio = (float)$cata->precio;
        $disponible = $cata->estaDisponible() ? 1 : 0;

        try {
            if ($cata->id) {
                $stmt = $db->prepare(
                    'UPDATE catas
                        SET titulo = ?, descripcion = ?, fecha = ?, hora = ?,
                            duracion_min = ?, precio = ?, disponible = ?
                      WHERE id = ? LIMIT 1'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $id = (int)$cata->id;
                $stmt->bind_param(
                    'ssssidii',
                    $cata->titulo,
                    $descripcion,
                    $cata->fecha,
                    $hora,
                    $duracion,
                    $precio,
                    $disponible,
                    $id
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO catas
                        (titulo, descripcion, fecha, hora, duracion_min, precio, disponible)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $stmt->bind_param(
                    'ssssidi',
                    $cata->titulo,
                    $descripcion,
                    $cata->fecha,
                    $hora,
                    $duracion,
                    $precio,
                    $disponible
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

        return ['ok' => true, 'codigo' => self::OK, 'mensaje' => 'Cata guardada correctamente.', 'cata' => $cata];
    }

    /**
     * Añade a la fila lo que la vista necesita y no está en la tabla: la fecha
     * ya montada como objeto y si la sesión sigue por delante.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function decorarFila(array $fila): array
    {
        $inicio = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string)($fila['fecha'] ?? '') . ' ' . Cata::horaCompleta((string)($fila['hora'] ?? '')),
            ReservacionConfig::timezone()
        );

        $fila['inicio'] = $inicio ?: null;
        $fila['es_futura'] = $inicio ? $inicio > ReservacionConfig::ahora() : false;
        $fila['disponible'] = (int)($fila['disponible'] ?? 0) === 1;

        return $fila;
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
}
