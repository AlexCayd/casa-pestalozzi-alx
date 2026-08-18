<?php

namespace Services;

use Model\ActiveRecord;

/** Valida y consume enlaces públicos de cambio de horario. */
final class ReservacionMagicLinkService
{
    public static function consumir(string $publicId, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $publicId) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return ['ok' => false, 'codigo' => 'MAGIC_LINK_INVALIDO'];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar el enlace de cambio.');
            }
            $transaccion = true;
            $stmt = $db->prepare(
                "SELECT ml.id, ml.reservacion_id, ml.impacto_reservacion_id,
                        ml.purpose, ml.token_hash, ml.expires_at, ml.used_at,
                        ml.invalidated_at, ir.estado AS impacto_reservacion_estado,
                        i.estado AS impacto_estado, r.estado AS reservacion_estado,
                        r.contacto_tipo, r.contacto
                 FROM reservacion_magic_links ml
                 JOIN horario_impacto_reservaciones ir ON ir.id = ml.impacto_reservacion_id
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 JOIN reservaciones r ON r.id = ml.reservacion_id
                 WHERE ml.public_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar el enlace de cambio.');
            }
            $stmt->bind_param('s', $publicId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('No fue posible consultar el enlace de cambio.');
            }
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            $ahora = ReservacionConfig::ahora();
            $valido = $fila
                && (string)$fila['purpose'] === 'schedule_change'
                && hash_equals((string)$fila['token_hash'], hash('sha256', $token))
                && empty($fila['used_at'])
                && empty($fila['invalidated_at'])
                && (string)$fila['expires_at'] > $ahora->format('Y-m-d H:i:s')
                && (string)$fila['impacto_reservacion_estado'] === HorarioOperacionImpactoService::ESTADO_ITEM_ENCOLADO
                && in_array((string)$fila['reservacion_estado'], ReservacionConfig::ESTADOS_EDITABLES, true)
                && in_array((string)$fila['contacto_tipo'], ContactoService::TIPOS, true)
                && trim((string)$fila['contacto']) !== '';
            if (!$valido) {
                $db->rollback();
                $transaccion = false;
                return ['ok' => false, 'codigo' => 'MAGIC_LINK_INVALIDO'];
            }

            $usadoAt = $ahora->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "UPDATE reservacion_magic_links SET used_at = ? WHERE id = ? AND used_at IS NULL AND invalidated_at IS NULL"
            );
            $id = (int)$fila['id'];
            $stmt->bind_param('si', $usadoAt, $id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                $db->rollback();
                $transaccion = false;
                return ['ok' => false, 'codigo' => 'MAGIC_LINK_INVALIDO'];
            }
            $stmt->close();
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible consumir el enlace de cambio.');
            }
            $transaccion = false;

            ReservationClientSession::crear(
                (string)$fila['contacto_tipo'],
                (string)$fila['contacto']
            );
            ReservationClientSession::setTargetReservationId((int)$fila['reservacion_id']);

            return [
                'ok' => true,
                'codigo' => 'CAMBIO_HORARIO_ACCESO_CONCEDIDO',
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionMagicLinkService::consumir - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => 'MAGIC_LINK_INVALIDO'];
        }
    }
}
