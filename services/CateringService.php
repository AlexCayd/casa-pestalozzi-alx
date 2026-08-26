<?php

/**
 * Bandeja de solicitudes de cotización de catering.
 *
 * A diferencia de catas, aquí no hay cupo ni cierre por fecha: es seguimiento
 * comercial. La única regla dura es el freno de reenvíos del formulario público.
 */

namespace Services;

use Model\ActiveRecord;
use Model\CateringSolicitud;

class CateringService
{
    public const OK = 'OK';
    public const NO_EXISTE = 'NO_EXISTE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const DEMASIADOS_ENVIOS = 'DEMASIADOS_ENVIOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    private const ENVIOS_MAX_POR_VENTANA = 3;
    private const ENVIOS_VENTANA_MINUTOS = 60;

    /**
     * Alta pública de una solicitud.
     *
     * @param array<string, mixed> $datos
     * @return array{ok: bool, codigo: string, mensaje: string, solicitud?: CateringSolicitud}
     */
    public static function registrarSolicitud(array $datos): array
    {
        $solicitud = new CateringSolicitud();
        $solicitud->nombre = trim((string)($datos['nombre'] ?? ''));
        $solicitud->contacto_tipo = (string)($datos['contacto_tipo'] ?? '');
        $solicitud->contacto = (string)($datos['contacto'] ?? '');
        $solicitud->tipo_evento = trim((string)($datos['tipo_evento'] ?? ''));
        $solicitud->fecha_evento = self::nuloSiVacio($datos['fecha_evento'] ?? null);
        $solicitud->invitados = self::nuloSiVacio($datos['invitados'] ?? null);
        $solicitud->presupuesto = self::nuloSiVacio($datos['presupuesto'] ?? null);
        $solicitud->mensaje = self::nuloSiVacio($datos['mensaje'] ?? null);
        $solicitud->estado = 'nueva';

        $alertas = $solicitud->validar();
        if (!empty($alertas)) {
            return self::error(self::DATOS_INVALIDOS, self::primerMensaje($alertas));
        }

        $solicitud->contacto = ContactoService::normalizar(
            $solicitud->contacto_tipo,
            (string)$solicitud->contacto
        );

        if (self::excedeEnvios($solicitud->contacto_tipo, $solicitud->contacto)) {
            return self::error(
                self::DEMASIADOS_ENVIOS,
                'Ya recibimos varias solicitudes con este contacto. Te respondemos en breve.'
            );
        }

        $db = ActiveRecord::getDB();
        $invitados = $solicitud->invitados === null ? null : (int)$solicitud->invitados;

        try {
            $stmt = $db->prepare(
                'INSERT INTO catering_solicitudes
                    (nombre, contacto_tipo, contacto, tipo_evento, fecha_evento,
                     invitados, presupuesto, mensaje, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $stmt->bind_param(
                'sssssisss',
                $solicitud->nombre,
                $solicitud->contacto_tipo,
                $solicitud->contacto,
                $solicitud->tipo_evento,
                $solicitud->fecha_evento,
                $invitados,
                $solicitud->presupuesto,
                $solicitud->mensaje,
                $solicitud->estado
            );

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($error);
            }

            $solicitud->id = (int)$db->insert_id;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('CateringService::registrarSolicitud - ' . $e->getMessage());
            return self::error(self::ERROR_GUARDADO, 'No pudimos enviar tu solicitud. Inténtalo de nuevo.');
        }

        return [
            'ok' => true,
            'codigo' => self::OK,
            'mensaje' => 'Recibimos tu solicitud. Te contactamos con la cotización en menos de 24 horas.',
            'solicitud' => $solicitud,
        ];
    }

    /**
     * Bandeja del panel. `$estado` vacío trae todo; 'abiertas' agrupa las tres
     * que siguen vivas, que es la vista por defecto.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bandeja(string $estado = '', string $busqueda = ''): array
    {
        $condiciones = [];

        if ($estado === 'abiertas') {
            $lista = implode(', ', array_map(
                static fn (string $e): string => "'" . ActiveRecord::escaparString($e) . "'",
                CateringSolicitud::ESTADOS_ABIERTOS
            ));
            $condiciones[] = "estado IN ({$lista})";
        } elseif ($estado !== '' && in_array($estado, CateringSolicitud::ESTADOS, true)) {
            $condiciones[] = "estado = '" . ActiveRecord::escaparString($estado) . "'";
        }

        $busqueda = trim($busqueda);
        if ($busqueda !== '') {
            $like = ActiveRecord::escaparString($busqueda);
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
            $condiciones[] = "(nombre LIKE '%{$like}%' OR contacto LIKE '%{$like}%' OR tipo_evento LIKE '%{$like}%')";
        }

        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        return self::filas(
            "SELECT * FROM catering_solicitudes {$where} ORDER BY created_at DESC, id DESC"
        );
    }

    /**
     * Conteo por estado para las pastillas de filtro de la bandeja.
     *
     * @return array<string, int>
     */
    public static function conteoPorEstado(): array
    {
        $conteo = array_fill_keys(CateringSolicitud::ESTADOS, 0);
        $conteo['todas'] = 0;

        foreach (self::filas('SELECT estado, COUNT(*) AS total FROM catering_solicitudes GROUP BY estado') as $fila) {
            $estado = (string)$fila['estado'];
            $total = (int)$fila['total'];
            if (array_key_exists($estado, $conteo)) {
                $conteo[$estado] = $total;
            }
            $conteo['todas'] += $total;
        }

        $conteo['abiertas'] = array_sum(array_map(
            static fn (string $e): int => $conteo[$e] ?? 0,
            CateringSolicitud::ESTADOS_ABIERTOS
        ));

        return $conteo;
    }

    /** @return array{ok: bool, codigo: string, mensaje: string} */
    public static function cambiarEstado(int $solicitudId, string $estado): array
    {
        if (!in_array($estado, CateringSolicitud::ESTADOS, true)) {
            return self::error(self::DATOS_INVALIDOS, 'El estado indicado no es válido.');
        }

        if (!CateringSolicitud::find($solicitudId)) {
            return self::error(self::NO_EXISTE, 'La solicitud no existe.');
        }

        $ok = ActiveRecord::ejecutarSQL(
            "UPDATE catering_solicitudes
             SET estado = '" . ActiveRecord::escaparString($estado) . "'
             WHERE id = " . (int)$solicitudId . " LIMIT 1"
        );

        return $ok
            ? ['ok' => true, 'codigo' => self::OK, 'mensaje' => 'Solicitud actualizada.']
            : self::error(self::ERROR_GUARDADO, 'No se pudo actualizar la solicitud.');
    }

    /** @return array{ok: bool, codigo: string, mensaje: string} */
    public static function guardarComentario(int $solicitudId, string $comentario): array
    {
        $solicitud = CateringSolicitud::find($solicitudId);
        if (!$solicitud) {
            return self::error(self::NO_EXISTE, 'La solicitud no existe.');
        }

        $solicitud->comentario_admin = self::nuloSiVacio($comentario);
        $alertas = $solicitud->validar();
        if (!empty($alertas)) {
            return self::error(self::DATOS_INVALIDOS, self::primerMensaje($alertas));
        }

        $db = ActiveRecord::getDB();

        try {
            $stmt = $db->prepare('UPDATE catering_solicitudes SET comentario_admin = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $id = (int)$solicitudId;
            $stmt->bind_param('si', $solicitud->comentario_admin, $id);

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($error);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('CateringService::guardarComentario - ' . $e->getMessage());
            return self::error(self::ERROR_GUARDADO, 'No se pudo guardar el comentario.');
        }

        return ['ok' => true, 'codigo' => self::OK, 'mensaje' => 'Comentario guardado.'];
    }

    /** Mismo freno que en catas: se cuentan las dos tablas públicas juntas. */
    private static function excedeEnvios(string $tipo, string $contacto): bool
    {
        $desde = ReservacionConfig::ahora()
            ->modify('-' . self::ENVIOS_VENTANA_MINUTOS . ' minutes')
            ->format('Y-m-d H:i:s');

        $tipoSql = ActiveRecord::escaparString($tipo);
        $contactoSql = ActiveRecord::escaparString($contacto);
        $desdeSql = ActiveRecord::escaparString($desde);

        $sql = "SELECT
                  (SELECT COUNT(*) FROM catering_solicitudes
                    WHERE contacto_tipo = '{$tipoSql}' AND contacto = '{$contactoSql}'
                      AND created_at >= '{$desdeSql}')
                + (SELECT COUNT(*) FROM cata_inscripciones
                    WHERE contacto_tipo = '{$tipoSql}' AND contacto = '{$contactoSql}'
                      AND created_at >= '{$desdeSql}') AS envios";

        $filas = self::filas($sql);
        return (int)($filas[0]['envios'] ?? 0) >= self::ENVIOS_MAX_POR_VENTANA;
    }

    /** @return array<int, array<string, mixed>> */
    private static function filas(string $sql): array
    {
        $resultado = ActiveRecord::getDB()->query($sql);
        if (!$resultado) {
            error_log('CateringService - consulta fallida: ' . ActiveRecord::getDB()->error);
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
