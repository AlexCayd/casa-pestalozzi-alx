<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;

/** Prepara una confirmación única; el dispatcher transporta después del commit. */
final class ReservationConfirmationService
{
    public const EVENT = 'reservation.confirmed';
    public const TYPE = 'confirmacion';

    /** @return array<string,mixed>|null */
    public static function preparar(int $reservacionId): ?array
    {
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la preparación del mensaje de confirmación.');
            }
            $transaccion = true;
            $stmt = $db->prepare(
                "SELECT id, nombre, contacto_tipo, contacto, fecha, hora, comensales,
                        estado, reemplaza_reservacion_id
                 FROM reservaciones WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$fila || !self::esElegible($fila)) {
                $db->rollback();
                return null;
            }

            $raizId = $reservacionId;
            $dedupKey = self::TYPE . '|' . $reservacionId;
            $stmt = $db->prepare('SELECT id FROM reservacion_recordatorios WHERE dedup_key = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('s', $dedupKey);
            $stmt->execute();
            $existe = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existe) {
                $db->rollback();
                return null;
            }

            $token = ReservationAccessTokenService::generar();
            $inicio = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string)$fila['fecha'] . ' ' . substr((string)$fila['hora'], 0, 8),
                ReservacionConfig::timezone()
            );
            if (!$inicio instanceof DateTimeImmutable) {
                throw new \RuntimeException('La fecha del mensaje de confirmación no es válida.');
            }
            $expira = $inicio
                ->modify('+' . ReservacionConfig::TOLERANCIA_CANCELACION_PUBLICA_MINUTOS . ' minutes')
                ->format('Y-m-d H:i:s');
            $managementUrl = ReservationAccessTokenService::url($token['token']);
            $stmt = $db->prepare(
                "INSERT INTO reservacion_recordatorios
                  (reservacion_id, reservacion_raiz_id, tipo, dedup_key,
                   access_token_hash, access_expires_at,
                   notification_delivery_status, notification_delivery_updated_at)
                 VALUES (?, ?, 'confirmacion', ?, ?, ?, 'pending', NOW())"
            );
            $stmt->bind_param('iisss', $reservacionId, $raizId, $dedupKey, $token['hash'], $expira);
            if (!$stmt->execute()) {
                $duplicate = (int)$stmt->errno === 1062;
                $stmt->close();
                $db->rollback();
                return $duplicate ? null : throw new \RuntimeException('No fue posible crear el mensaje de confirmación.');
            }
            $sourceId = (int)$db->insert_id;
            $stmt->close();
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar el mensaje de confirmación.');
            }
            $transaccion = false;
            return [
                'source_id' => $sourceId,
                'reservation_id' => $reservacionId,
                'attempt' => 1,
                'contact_type' => (string)$fila['contacto_tipo'],
                'contact' => (string)$fila['contacto'],
                'name' => (string)$fila['nombre'],
                'reservation_date' => (string)$fila['fecha'],
                'reservation_time' => substr((string)$fila['hora'], 0, 5),
                'guests' => (int)$fila['comensales'],
                'management_url' => $managementUrl,
                'access_expires_at' => (new DateTimeImmutable($expira, ReservacionConfig::timezone()))->format(DATE_ATOM),
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservationConfirmationService::preparar - fallo redactado.');
            return null;
        }
    }

    private static function esElegible(array $fila): bool
    {
        if ((string)($fila['estado'] ?? '') !== 'confirmada'
            || !in_array((string)($fila['contacto_tipo'] ?? ''), ContactoService::TIPOS, true)
        ) {
            return false;
        }
        try {
            return ContactoService::normalizar(
                (string)$fila['contacto_tipo'],
                (string)($fila['contacto'] ?? '')
            ) === (string)$fila['contacto'];
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

}
