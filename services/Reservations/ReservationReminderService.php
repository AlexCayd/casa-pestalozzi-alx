<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Services\Contact\ContactoService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionNotificacionConfigService;
use Services\Reservations\ReservationAccessTokenService;

/** Prepara recordatorios idempotentes del día anterior en transacciones breves. */
final class ReservationReminderService
{
    public const EVENT = ReservationNotificationContract::EVENT_REMINDER;
    public const TYPE = 'dia_anterior';
    public const MAX_ATTEMPTS = 3;
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

        self::reconciliarPendientesAntiguos($ahora);
        $fechaObjetivo = $ahora->modify('+1 day')->format('Y-m-d');
        $notifications = [];
        foreach (self::candidatos($fechaObjetivo) as $reservacionId) {
            $notification = self::prepararReservacion($reservacionId, $fechaObjetivo, $ahora);
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
    private static function prepararReservacion(int $reservacionId, string $fechaObjetivo, DateTimeImmutable $ahora): ?array
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
            $stmt = $db->prepare('SELECT * FROM reservacion_recordatorios WHERE dedup_key = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('s', $dedupKey);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $attempt = 1;
            if ($existing) {
                $updated = new DateTimeImmutable($existing['notification_delivery_updated_at'] ?? $existing['created_at'], ReservacionConfig::timezone());
                $old = $updated->modify('+' . self::CALLBACK_TIMEOUT_MINUTES . ' minutes') <= $ahora;
                $notClaimed = $existing['notification_delivery_status'] === 'pending' && $existing['transport_claimed_at'] === null;
                $retryable = $existing['notification_delivery_status'] === 'failed' && (bool)$existing['retryable'];
                if (!$old || (!$notClaimed && !$retryable) || (int)$existing['notification_attempts'] >= self::MAX_ATTEMPTS) {
                    $db->rollback();
                    return null;
                }
                $attempt = (int)$existing['notification_attempts'] + 1;
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
            $nowSql = $ahora->format('Y-m-d H:i:s');
            if ($existing) {
                $sourceId = (int)$existing['id'];
                $stmt = $db->prepare("UPDATE reservacion_recordatorios SET reservacion_id = ?,
                    access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL,
                    notification_attempts = ?, transport_claimed_at = NULL, retryable = 0,
                    notification_delivery_status = 'pending', notification_delivery_updated_at = ? WHERE id = ?");
                $stmt->bind_param('issisi', $reservacionId, $token['hash'], $expira, $attempt, $nowSql, $sourceId);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO reservacion_recordatorios
                      (reservacion_id, reservacion_raiz_id, tipo, dedup_key,
                       access_token_hash, access_expires_at,
                       notification_delivery_status, notification_delivery_updated_at)
                     VALUES (?, ?, 'dia_anterior', ?, ?, ?, 'pending', ?)"
                );
                $stmt->bind_param('iissss', $reservacionId, $raizId, $dedupKey, $token['hash'], $expira, $nowSql);
            }
            if (!$stmt->execute()) {
                $duplicate = (int)$stmt->errno === 1062;
                $stmt->close();
                $db->rollback();
                return $duplicate ? null : throw new \RuntimeException('No fue posible crear el recordatorio.');
            }
            $sourceId = $existing ? (int)$existing['id'] : (int)$db->insert_id;
            $stmt->close();
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar el recordatorio.');
            }
            $transaccion = false;
            return ReservationNotificationContract::build(
                self::EVENT,
                $sourceId,
                $reservacionId,
                $attempt,
                (string)$fila['contacto_tipo'],
                (string)$fila['contacto'],
                (string)$fila['nombre'],
                (string)$fila['fecha'],
                substr((string)$fila['hora'], 0, 5),
                (int)$fila['comensales'],
                [
                    'management_url' => $managementUrl,
                    'access_expires_at' => (new DateTimeImmutable(
                        $expira,
                        ReservacionConfig::timezone()
                    ))->format(DATE_ATOM),
                ]
            );
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

    /**
     * Reclamo atómico previo al transporte. Un segundo scheduler no puede enviar
     * la misma fuente/intento. La respuesta perdida no autoriza repetir el claim.
     */
    public static function reclamar(int $sourceId, int $attempt, string $channel): array
    {
        if ($sourceId < 1 || $attempt < 1 || $attempt > self::MAX_ATTEMPTS
            || !in_array($channel, ReservationNotificationContract::CHANNELS, true)) {
            return ['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO', 'claimed' => false];
        }
        $db = ActiveRecord::getDB();
        $now = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
        $type = $channel === 'whatsapp' ? 'telefono' : 'email';
        $stmt = $db->prepare("UPDATE reservacion_recordatorios rr
            JOIN reservaciones r ON r.id = rr.reservacion_id
            SET rr.transport_claimed_at = ?, rr.notification_delivery_updated_at = ?
            WHERE rr.id = ? AND rr.notification_attempts = ?
              AND rr.notification_delivery_status = 'pending'
              AND rr.transport_claimed_at IS NULL AND rr.access_invalidated_at IS NULL
              AND rr.access_expires_at > ? AND r.contacto_tipo = ?
              AND r.estado = 'confirmada'
              AND NOT EXISTS (
                SELECT 1 FROM horario_impacto_reservaciones ir JOIN horario_impactos i ON i.id = ir.impacto_id
                WHERE ir.reservacion_id = r.id AND i.estado = 'pendiente'
                AND ir.estado IN ('pendiente_notificacion','notificacion_preparada','sin_contacto')
              )");
        $stmt->bind_param('ssiiss', $now, $now, $sourceId, $attempt, $now, $type);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return ['ok' => true, 'claimed' => $claimed];
    }

    /**
     * Reconciliación conservadora: no convierte ausencia de callback en rechazo.
     * preparar() recupera pending sin claim y failed reintentables tras cinco minutos.
     * Los reclamados sin resultado requieren revisar n8n/proveedor antes de reenviar.
     */
    public static function reconciliarPendientesAntiguos(?DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? ReservacionConfig::ahora())->modify('-' . self::CALLBACK_TIMEOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $stmt = ActiveRecord::getDB()->prepare("UPDATE reservacion_recordatorios
            SET notification_delivery_status = 'failed', retryable = 1
            WHERE notification_delivery_status = 'pending' AND transport_claimed_at IS NULL
              AND notification_delivery_updated_at <= ?
              AND notification_attempts < ?");
        $max = self::MAX_ATTEMPTS;
        $stmt->bind_param('si', $cutoff, $max);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}
