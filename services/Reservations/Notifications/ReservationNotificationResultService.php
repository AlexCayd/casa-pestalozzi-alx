<?php

namespace Services\Reservations\Notifications;

use Model\ActiveRecord;
use Services\BuzonNotificacionesService;
use Services\ReservacionBuzonService;

/** Aplica callbacks idempotentes sin mezclar transporte con estados de dominio. */
final class ReservationNotificationResultService
{
    public static function registrar(
        string $event,
        int $sourceId,
        int $attempt,
        string $channel,
        string $status
    ): array
    {
        if (!in_array($event, [
                ReservationNotificationContract::EVENT_SCHEDULE_CHANGE,
                ReservationNotificationContract::EVENT_REMINDER,
            ], true)
            || !in_array($channel, ReservationNotificationContract::CHANNELS, true)
            || !in_array($status, ['delivered', 'failed'], true)
            || $sourceId < 1
            || $attempt < 1
        ) {
            return ['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO'];
        }
        return $event === ReservationNotificationContract::EVENT_SCHEDULE_CHANGE
            ? self::scheduleChange($sourceId, $attempt, $channel, $status)
            : self::reminder($sourceId, $attempt, $channel, $status);
    }

    private static function scheduleChange(int $sourceId, int $attempt, string $channel, string $status): array
    {
        $db = ActiveRecord::getDB();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'SELECT ir.id, ir.notification_attempts, ir.notification_delivery_status,
                        r.contacto_tipo
                 FROM horario_impacto_reservaciones ir
                 JOIN reservaciones r ON r.id = ir.reservacion_id
                 WHERE ir.id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => 'NOTIFICACION_SOURCE_NO_ENCONTRADO'];
            }
            if (!self::channelMatches((string)$fila['contacto_tipo'], $channel)) {
                $db->rollback();
                return ['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO'];
            }
            if ((int)$fila['notification_attempts'] !== $attempt) {
                $db->commit();
                return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_STALE', 'stale' => true];
            }
            if (in_array((string)$fila['notification_delivery_status'], ['delivered', 'failed'], true)) {
                $db->commit();
                return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_IDEMPOTENTE', 'idempotente' => true];
            }
            if ($status === 'delivered') {
                $stmt = $db->prepare(
                    "UPDATE horario_impacto_reservaciones
                     SET notification_delivery_status = 'delivered', notification_delivery_updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $db->prepare(
                    "UPDATE horario_impacto_reservaciones
                     SET notification_delivery_status = 'failed', notification_delivery_updated_at = NOW(),
                         access_invalidated_at = COALESCE(access_invalidated_at, NOW()),
                         access_expires_at = LEAST(COALESCE(access_expires_at, NOW()), NOW())
                     WHERE id = ?"
                );
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $stmt->close();
                BuzonNotificacionesService::establecerRequiereAccionEnTransaccion(
                    $db,
                    ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                    ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                    $sourceId,
                    true
                );
            }
            $db->commit();
            return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_REGISTRADO'];
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('ReservationNotificationResultService::scheduleChange - fallo redactado.');
            return ['ok' => false, 'codigo' => 'ERROR_INTERNO'];
        }
    }

    private static function reminder(int $sourceId, int $attempt, string $channel, string $status): array
    {
        if ($attempt !== 1) {
            return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_STALE', 'stale' => true];
        }
        $db = ActiveRecord::getDB();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                "SELECT rr.id, rr.notification_delivery_status, rr.tipo, r.contacto_tipo
                 FROM reservacion_recordatorios rr
                 JOIN reservaciones r ON r.id = rr.reservacion_id
                 WHERE rr.id = ? AND rr.tipo = 'dia_anterior' LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => 'NOTIFICACION_SOURCE_NO_ENCONTRADO'];
            }
            if (!self::channelMatches((string)$fila['contacto_tipo'], $channel)) {
                $db->rollback();
                return ['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO'];
            }
            if (in_array((string)$fila['notification_delivery_status'], ['delivered', 'failed'], true)) {
                $db->commit();
                return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_IDEMPOTENTE', 'idempotente' => true];
            }
            if ($status === 'delivered') {
                $stmt = $db->prepare(
                    "UPDATE reservacion_recordatorios
                     SET notification_delivery_status = 'delivered', notification_delivery_updated_at = NOW()
                     WHERE id = ?"
                );
            } else {
                $stmt = $db->prepare(
                    "UPDATE reservacion_recordatorios
                     SET notification_delivery_status = 'failed', notification_delivery_updated_at = NOW(),
                         access_invalidated_at = COALESCE(access_invalidated_at, NOW()),
                         access_expires_at = LEAST(COALESCE(access_expires_at, NOW()), NOW())
                     WHERE id = ?"
                );
            }
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $stmt->close();
            $db->commit();
            return ['ok' => true, 'codigo' => 'NOTIFICACION_CALLBACK_REGISTRADO'];
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('ReservationNotificationResultService::reminder - fallo redactado.');
            return ['ok' => false, 'codigo' => 'ERROR_INTERNO'];
        }
    }

    private static function channelMatches(string $legacyContactType, string $channel): bool
    {
        try {
            return ReservationNotificationContract::channelForLegacyType($legacyContactType) === $channel;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
