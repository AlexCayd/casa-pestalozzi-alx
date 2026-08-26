<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;

/** Prepara recordatorios idempotentes del día anterior en transacciones breves. */
final class ReservationReminderService
{
    public const EVENT = 'reservation.reminder_next_day';
    public const TYPE = 'dia_anterior';
    private const ROOT_MAX_DEPTH = 64;
    private const CALLBACK_TIMEOUT_MINUTES = 5;

    /** @return array{ok:bool,due:bool,event?:string,notifications:array} */
    public static function preparar(?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $configuracion = ReservacionNotificacionConfigService::obtener();
        if (empty($configuracion['recordatorio_dia_anterior_activo'])) {
            return ['ok' => true, 'due' => false, 'notifications' => []];
        }
        $hora = (string)($configuracion['hora_recordatorio'] ?? '');
        if (!ReservacionNotificacionConfigService::horaValida($hora)) {
            $hora = ReservacionNotificacionConfigService::HORA_PREDETERMINADA;
        }
        if ($ahora->format('H:i') < $hora) {
            return ['ok' => true, 'due' => false, 'notifications' => []];
        }

        self::reconciliarPendientesAntiguos();
        $fechaObjetivo = $ahora->modify('+1 day')->format('Y-m-d');
        $notifications = [];
        foreach (self::candidatos($fechaObjetivo) as $reservacionId) {
            $notification = self::prepararReservacion($reservacionId, $fechaObjetivo);
            if ($notification !== null) {
                $notifications[] = $notification;
            }
        }
        return [
            'ok' => true,
            'due' => true,
            'event' => self::EVENT,
            'notifications' => $notifications,
        ];
    }

    /** @return int[] */
    private static function candidatos(string $fecha): array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT r.id
             FROM reservaciones r
             WHERE r.fecha = ?
               AND r.estado = 'confirmada'
               AND r.contacto_tipo IN ('email', 'telefono')
               AND r.contacto IS NOT NULL
               AND TRIM(r.contacto) <> ''
               AND NOT EXISTS (
                 SELECT 1
                 FROM horario_impacto_reservaciones ir
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 WHERE ir.reservacion_id = r.id
                   AND i.estado = 'pendiente'
                   AND ir.estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')
               )
             ORDER BY r.id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $fecha);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $resultado = $stmt->get_result();
        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['id'];
        }
        $stmt->close();
        return $ids;
    }

    /** @return array<string,mixed>|null */
    private static function prepararReservacion(int $reservacionId, string $fechaObjetivo): ?array
    {
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la preparación del recordatorio.');
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
            if (!$fila || !self::esElegible($fila, $fechaObjetivo) || self::tieneAfectacionActiva($db, $reservacionId)) {
                $db->rollback();
                return null;
            }

            $raizId = self::resolverRaiz($db, $fila);
            if ($raizId === null) {
                $db->rollback();
                return null;
            }
            $dedupKey = self::TYPE . '|' . $raizId . '|' . $fechaObjetivo;
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
                throw new \RuntimeException('La fecha del recordatorio no es válida.');
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
                 VALUES (?, ?, 'dia_anterior', ?, ?, ?, 'pending', NOW())"
            );
            $stmt->bind_param('iisss', $reservacionId, $raizId, $dedupKey, $token['hash'], $expira);
            if (!$stmt->execute()) {
                $duplicate = (int)$stmt->errno === 1062;
                $stmt->close();
                $db->rollback();
                return $duplicate ? null : throw new \RuntimeException('No fue posible crear el recordatorio.');
            }
            $sourceId = (int)$db->insert_id;
            $stmt->close();
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar el recordatorio.');
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
            error_log('ReservationReminderService::prepararReservacion - fallo redactado.');
            return null;
        }
    }

    private static function esElegible(array $fila, string $fechaObjetivo): bool
    {
        if ((string)($fila['estado'] ?? '') !== 'confirmada'
            || (string)($fila['fecha'] ?? '') !== $fechaObjetivo
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

    private static function tieneAfectacionActiva(\mysqli $db, int $reservacionId): bool
    {
        $stmt = $db->prepare(
            "SELECT ir.id
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             WHERE ir.reservacion_id = ? AND i.estado = 'pendiente'
               AND ir.estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $reservacionId);
        $stmt->execute();
        $existe = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $existe;
    }

    /** @return int|null null indica ciclo o una cadena inválida. */
    private static function resolverRaiz(\mysqli $db, array $fila): ?int
    {
        $actual = (int)($fila['id'] ?? 0);
        $padre = (int)($fila['reemplaza_reservacion_id'] ?? 0);
        $visitados = [];
        for ($profundidad = 0; $profundidad < self::ROOT_MAX_DEPTH; $profundidad++) {
            if ($actual < 1 || isset($visitados[$actual])) {
                return null;
            }
            $visitados[$actual] = true;
            if ($padre < 1) {
                return $actual;
            }
            $stmt = $db->prepare('SELECT id, reemplaza_reservacion_id FROM reservaciones WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $padre);
            $stmt->execute();
            $anterior = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$anterior) {
                return null;
            }
            $actual = (int)$anterior['id'];
            $padre = (int)($anterior['reemplaza_reservacion_id'] ?? 0);
        }
        return null;
    }

    public static function reconciliarPendientesAntiguos(): int
    {
        $minutos = self::CALLBACK_TIMEOUT_MINUTES;
        $stmt = ActiveRecord::getDB()->prepare(
            "UPDATE reservacion_recordatorios
             SET notification_delivery_status = 'failed',
                 notification_delivery_updated_at = NOW(),
                 access_invalidated_at = COALESCE(access_invalidated_at, NOW()),
                 access_expires_at = LEAST(COALESCE(access_expires_at, NOW()), NOW())
             WHERE notification_delivery_status IN ('pending', 'accepted')
               AND COALESCE(notification_delivery_updated_at, created_at) <= DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE)"
        );
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return 0;
        }
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();
        return $afectadas;
    }
}
