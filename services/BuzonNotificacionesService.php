<?php

namespace Services;

use Model\ActiveRecord;

/**
 * Infraestructura genérica del buzón administrativo.
 *
 * Este servicio sólo conoce la notificación visual y su entidad polimórfica;
 * las reglas para decidir si una reserva sigue pendiente viven en el módulo
 * que creó el aviso.
 */
final class BuzonNotificacionesService
{
    public const PRIORIDAD_NORMAL = 'normal';
    public const PRIORIDAD_ALTA = 'alta';

    /** @param array<string, mixed> $datos */
    public static function crear(array $datos): int
    {
        return self::crearEnTransaccion(ActiveRecord::getDB(), $datos);
    }

    /** @param array<string, mixed> $datos */
    public static function crearEnTransaccion(\mysqli $db, array $datos): int
    {
        $tipo = trim((string)($datos['tipo'] ?? ''));
        $modulo = trim((string)($datos['modulo'] ?? ''));
        $entidadTipo = trim((string)($datos['entidad_tipo'] ?? ''));
        $entidadId = (int)($datos['entidad_id'] ?? 0);
        $prioridad = trim((string)($datos['prioridad'] ?? self::PRIORIDAD_NORMAL));
        $visibleFrom = $datos['visible_from'] ?? null;
        $dedupKey = trim((string)($datos['dedup_key'] ?? ''));

        if ($tipo === '' || $modulo === '' || $entidadTipo === '' || $entidadId < 1) {
            throw new \InvalidArgumentException('El buzón requiere tipo, módulo y entidad válida.');
        }
        if (!in_array($prioridad, [self::PRIORIDAD_NORMAL, self::PRIORIDAD_ALTA], true)) {
            $prioridad = self::PRIORIDAD_NORMAL;
        }
        if ($dedupKey === '') {
            $dedupKey = hash('sha256', $tipo . '|' . $modulo . '|' . $entidadTipo . '|' . $entidadId);
        }
        $visibleFrom = $visibleFrom === null || trim((string)$visibleFrom) === ''
            ? null
            : (string)$visibleFrom;

        $stmt = $db->prepare(
            "INSERT INTO buzon_notificaciones
                (tipo, modulo, entidad_tipo, entidad_id, prioridad, visible_from, dedup_key)
             VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()), ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                 prioridad = VALUES(prioridad), visible_from = VALUES(visible_from),
                 leida_at = NULL, cerrada_at = NULL, cerrada_por = NULL,
                 cierre_motivo = NULL, updated_at = NOW()"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el aviso del buzón.');
        }
        $stmt->bind_param(
            'sssisss',
            $tipo,
            $modulo,
            $entidadTipo,
            $entidadId,
            $prioridad,
            $visibleFrom,
            $dedupKey
        );
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $id = (int)$db->insert_id;
        $stmt->close();
        if ($id < 1) {
            throw new \RuntimeException('No fue posible identificar el aviso del buzón.');
        }
        return $id;
    }

    /** @return array{cantidad:int,prioridad_maxima:?string} */
    public static function resumen(): array
    {
        try {
            $resultado = ActiveRecord::getDB()->query(
                "SELECT COUNT(*) AS cantidad,
                        CASE WHEN SUM(prioridad = 'alta') > 0 THEN 'alta'
                             WHEN COUNT(*) > 0 THEN 'normal' ELSE NULL END AS prioridad_maxima
                 FROM buzon_notificaciones
                 WHERE cerrada_at IS NULL AND visible_from <= NOW()"
            );
            if (!$resultado) {
                throw new \RuntimeException(ActiveRecord::getDB()->error);
            }
            $fila = $resultado->fetch_assoc() ?: [];
            $resultado->free();
            return [
                'cantidad' => (int)($fila['cantidad'] ?? 0),
                'prioridad_maxima' => $fila['prioridad_maxima'] !== null
                    ? (string)$fila['prioridad_maxima']
                    : null,
            ];
        } catch (\Throwable $e) {
            error_log('BuzonNotificacionesService::resumen - ' . $e->getMessage());
            return ['cantidad' => 0, 'prioridad_maxima' => null];
        }
    }

    /** @param array<string, mixed> $filtros @return array<int, array<string, mixed>> */
    public static function listar(array $filtros = []): array
    {
        $db = ActiveRecord::getDB();
        $where = ["cerrada_at IS NULL", "visible_from <= NOW()"];
        $types = '';
        $params = [];
        foreach (['tipo', 'modulo', 'entidad_tipo'] as $campo) {
            if (($filtros[$campo] ?? '') !== '') {
                $where[] = "{$campo} = ?";
                $types .= 's';
                $params[] = (string)$filtros[$campo];
            }
        }
        $limit = min(100, max(1, (int)($filtros['limit'] ?? 50)));
        $sql = "SELECT id, tipo, modulo, entidad_tipo, entidad_id, prioridad,
                       visible_from, leida_at, cerrada_at, cerrada_por,
                       cierre_motivo, dedup_key, created_at, updated_at
                FROM buzon_notificaciones
                WHERE " . implode(' AND ', $where) . "
                ORDER BY CASE WHEN prioridad = 'alta' THEN 0 ELSE 1 END,
                         visible_from ASC, id ASC LIMIT {$limit}";
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            if ($types !== '') {
                $bindParams = [$types];
                foreach ($params as $index => &$param) {
                    $bindParams[] = &$param;
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                unset($param);
            }
            if (!$stmt->execute()) {
                throw new \RuntimeException($stmt->error);
            }
            $resultado = $stmt->get_result();
            $filas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $fila['id'] = (int)$fila['id'];
                $fila['entidad_id'] = (int)$fila['entidad_id'];
                $filas[] = $fila;
            }
            $stmt->close();
            return $filas;
        } catch (\Throwable $e) {
            error_log('BuzonNotificacionesService::listar - ' . $e->getMessage());
            return [];
        }
    }

    public static function marcarLeida(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            'UPDATE buzon_notificaciones SET leida_at = COALESCE(leida_at, NOW()), updated_at = NOW() WHERE id = ? AND cerrada_at IS NULL'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function cerrar(int $id, ?int $usuarioId, string $motivo): bool
    {
        if ($id < 1) {
            return false;
        }
        $db = ActiveRecord::getDB();
        return self::cerrarEnTransaccion($db, $id, $usuarioId, $motivo);
    }

    public static function cerrarEnTransaccion(\mysqli $db, int $id, ?int $usuarioId, string $motivo): bool
    {
        if ($id < 1) {
            return false;
        }
        $motivo = trim($motivo) !== '' ? trim($motivo) : 'resuelto';
        $usuario = $usuarioId ?? 0;
        $stmt = $db->prepare(
            'UPDATE buzon_notificaciones
             SET cerrada_at = COALESCE(cerrada_at, NOW()), cerrada_por = NULLIF(?, 0),
                 cierre_motivo = ?, updated_at = NOW()
             WHERE id = ? AND cerrada_at IS NULL'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isi', $usuario, $motivo, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function cerrarEntidad(string $entidadTipo, int $entidadId, ?int $usuarioId, string $motivo): int
    {
        if ($entidadTipo === '' || $entidadId < 1) {
            return 0;
        }
        $db = ActiveRecord::getDB();
        $usuario = $usuarioId ?? 0;
        $stmt = $db->prepare(
            'UPDATE buzon_notificaciones
             SET cerrada_at = COALESCE(cerrada_at, NOW()), cerrada_por = NULLIF(?, 0),
                 cierre_motivo = ?, updated_at = NOW()
             WHERE entidad_tipo = ? AND entidad_id = ? AND cerrada_at IS NULL'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('issi', $usuario, $motivo, $entidadTipo, $entidadId);
        $stmt->execute();
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();
        return $afectadas;
    }

    /**
     * Cierra avisos sólo cuando el módulo fuente confirma que ya no requieren
     * atención. La callback evita meter reglas de reservaciones aquí.
     */
    public static function reconciliarEntidad(
        string $entidadTipo,
        int $entidadId,
        callable $debeCerrar,
        ?int $usuarioId = null,
        string $motivo = 'fuente_resuelta'
    ): int {
        if ($entidadTipo === '' || $entidadId < 1 || !$debeCerrar($entidadTipo, $entidadId)) {
            return 0;
        }
        return self::cerrarEntidad($entidadTipo, $entidadId, $usuarioId, $motivo);
    }
}
