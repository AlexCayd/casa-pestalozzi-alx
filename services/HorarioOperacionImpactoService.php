<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Reservacion;

/**
 * Autoridad del seguimiento que nace al cambiar el horario efectivo.
 *
 * La fila afectada conserva dominio, acceso y estado de transporte. El token
 * plano sólo sale de una preparación post-commit hacia el dispatcher.
 */
final class HorarioOperacionImpactoService
{
    public const ESTADO_IMPACTO_PENDIENTE = 'pendiente';
    public const ESTADO_IMPACTO_RESUELTO = 'resuelto';
    public const ESTADO_ITEM_PENDIENTE = 'pendiente_notificacion';
    public const ESTADO_ITEM_PREPARADO = 'notificacion_preparada';
    public const ESTADO_ITEM_SIN_CONTACTO = 'sin_contacto';
    public const ESTADO_ITEM_MANUAL = 'atendida_manual';
    public const ESTADO_ITEM_CLIENTE = 'resuelta_por_cliente';

    private const ESTADOS_ITEM_PENDIENTES = [
        self::ESTADO_ITEM_PENDIENTE,
        self::ESTADO_ITEM_PREPARADO,
        self::ESTADO_ITEM_SIN_CONTACTO,
    ];

    private const ESTADOS_ITEM_FINALES = [
        self::ESTADO_ITEM_MANUAL,
        self::ESTADO_ITEM_CLIENTE,
    ];

    /** @return array{semanal: array<int, array<string, mixed>>, excepciones: array<string, array<string, mixed>>} */
    public static function snapshotActual(): array
    {
        $semanal = [];
        foreach (\Model\HorarioOperacion::todosOrdenados() as $horario) {
            $semanal[(int)$horario->dia_semana] = [
                'abierto' => (int)$horario->abierto === 1,
                'hora_apertura' => self::hora((string)$horario->hora_apertura),
                'hora_cierre' => self::hora((string)$horario->hora_cierre),
            ];
        }

        $excepciones = [];
        foreach (\Model\ExcepcionOperacion::listarOrdenadas() as $excepcion) {
            $excepciones[(string)$excepcion->fecha] = [
                'id' => (int)$excepcion->id,
                'fecha' => (string)$excepcion->fecha,
                'tipo' => (string)$excepcion->tipo,
                'hora_apertura' => self::hora((string)$excepcion->hora_apertura),
                'hora_cierre' => self::hora((string)$excepcion->hora_cierre),
                'activo' => (int)$excepcion->activo === 1,
            ];
        }

        return ['semanal' => $semanal, 'excepciones' => $excepciones];
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarHorarioSemanal(array $horarios): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;
        $despues['semanal'] = [];
        foreach ($horarios as $horario) {
            $dia = (int)($horario['dia_semana'] ?? -1);
            $despues['semanal'][$dia] = [
                'abierto' => (int)($horario['abierto'] ?? 0) === 1,
                'hora_apertura' => self::hora((string)($horario['hora_apertura'] ?? '')),
                'hora_cierre' => self::hora((string)($horario['hora_cierre'] ?? '')),
            ];
        }
        ksort($despues['semanal']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarExcepcion(?array $datos, ?int $id = null): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;

        if ($id !== null) {
            foreach ($despues['excepciones'] as $fecha => $excepcion) {
                if ((int)($excepcion['id'] ?? 0) === $id) {
                    unset($despues['excepciones'][$fecha]);
                }
            }
        }

        if ($datos !== null) {
            $fecha = (string)($datos['fecha'] ?? '');
            if ($fecha !== '') {
                $despues['excepciones'][$fecha] = [
                    'id' => $id ?? 0,
                    'fecha' => $fecha,
                    'tipo' => (string)($datos['tipo'] ?? 'cerrado'),
                    'hora_apertura' => self::hora((string)($datos['hora_apertura'] ?? '')),
                    'hora_cierre' => self::hora((string)($datos['hora_cierre'] ?? '')),
                    'activo' => (int)($datos['activo'] ?? 0) === 1,
                ];
            }
        }
        ksort($despues['excepciones']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarSnapshots(array $antes, array $despues): array
    {
        $conflictos = [];
        foreach (Reservacion::buscarFuturasActivas(
            ReservacionConfig::fechaActual(),
            ReservacionConfig::horaActual()
        ) as $reservacion) {
            $fecha = (string)$reservacion->fecha;
            $hora = self::hora((string)$reservacion->hora);
            if ($hora === null) {
                continue;
            }
            if (self::estaDentro($fecha, $hora, $antes) && !self::estaDentro($fecha, $hora, $despues)) {
                $conflictos[] = [
                    'id' => (int)$reservacion->id,
                    'reservacion_id' => (int)$reservacion->id,
                    'nombre' => (string)$reservacion->nombre,
                    'fecha' => $fecha,
                    'hora' => substr($hora, 0, 5),
                    'estado' => (string)$reservacion->estado,
                ];
            }
        }

        return ['antes' => $antes, 'despues' => $despues, 'conflictos' => $conflictos];
    }

    /** Persiste el lote y sus filas hijas dentro de la transacción del horario. */
    public static function persistir(
        array $evaluacion,
        string $tipoOrigen,
        ?int $origenId,
        ?int $usuarioId
    ): ?int {
        $conflictos = (array)($evaluacion['conflictos'] ?? []);
        if ($conflictos === []) {
            return null;
        }

        $db = ActiveRecord::getDB();
        $dedupKey = self::dedupKey($tipoOrigen, $origenId, $evaluacion);
        $stmt = $db->prepare(
            "INSERT INTO horario_impactos
                (tipo_origen, origen_id, estado, dedup_key, created_by)
             VALUES (?, NULLIF(?, 0), 'pendiente', ?, NULLIF(?, 0))
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el impacto de horario.');
        }
        $origen = $origenId ?? 0;
        $usuario = $usuarioId ?? 0;
        $stmt->bind_param('sisi', $tipoOrigen, $origen, $dedupKey, $usuario);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $impactoId = (int)$db->insert_id;
        $stmt->close();
        if ($impactoId < 1) {
            throw new \RuntimeException('No fue posible identificar el impacto de horario.');
        }

        $ids = array_values(array_filter(array_unique(array_map(
            static fn(array $conflicto): int => (int)($conflicto['reservacion_id'] ?? $conflicto['id'] ?? 0),
            $conflictos
        )), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new \RuntimeException('El impacto no contiene reservaciones válidas.');
        }

        $listaIds = implode(',', $ids);
        $stmt = $db->prepare(
            "INSERT IGNORE INTO horario_impacto_reservaciones
                (impacto_id, reservacion_id, estado)
             SELECT ?, r.id,
                CASE WHEN r.contacto_tipo IN ('email', 'telefono')
                           AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                     THEN 'pendiente_notificacion' ELSE 'sin_contacto' END
             FROM reservaciones r
             WHERE r.id IN ({$listaIds})"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar las reservaciones afectadas.');
        }
        $stmt->bind_param('i', $impactoId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
        self::clasificarSeguimientosEnTransaccion($db, $impactoId, $usuarioId);
        self::actualizarEstadoImpacto($db, $impactoId);

        return $impactoId;
    }

    /** Una sola consulta para la alerta global y su primer destino. */
    public static function resumenPendientes(): array
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT COUNT(*) AS cantidad,
                    COALESCE(SUM(
                        ir.estado IN ('pendiente_notificacion', 'sin_contacto')
                        OR (ir.estado = 'notificacion_preparada'
                            AND (ir.access_expires_at IS NULL OR ir.access_expires_at <= NOW()))
                    ), 0) AS cantidad_accionable,
                    COALESCE(SUM(
                        ir.estado = 'notificacion_preparada'
                        AND ir.access_expires_at > NOW()
                    ), 0) AS cantidad_seguimiento,
                    MIN(ir.impacto_id) AS primer_impacto_id
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             WHERE i.estado = 'pendiente'
               AND ir.estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: [];
        $resultado->free();

        return [
            'cantidad' => (int)($fila['cantidad'] ?? 0),
            'cantidad_accionable' => (int)($fila['cantidad_accionable'] ?? 0),
            'cantidad_seguimiento' => (int)($fila['cantidad_seguimiento'] ?? 0),
            'primer_impacto_id' => !empty($fila['primer_impacto_id']) ? (int)$fila['primer_impacto_id'] : null,
        ];
    }

    public static function contarPendientes(): int
    {
        return (int)self::resumenPendientes()['cantidad'];
    }

    public static function contarPendientesReservaciones(): int
    {
        return self::contarPendientes();
    }

    public static function primerPendienteId(): ?int
    {
        return self::resumenPendientes()['primer_impacto_id'];
    }

    public static function esPendiente(int $impactoId): bool
    {
        if ($impactoId < 1) {
            return false;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT 1
             FROM horario_impactos i
             JOIN horario_impacto_reservaciones ir ON ir.impacto_id = i.id
             WHERE i.id = ? AND i.estado = 'pendiente'
               AND ir.estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')
             LIMIT 1"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $pendiente = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $pendiente;
    }

    /** @return array<string, mixed>|null */
    public static function obtener(int $impactoId): ?array
    {
        if ($impactoId < 1) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT i.id AS impacto_id, i.tipo_origen, i.origen_id, i.estado AS impacto_estado,
                    i.created_at AS impacto_created_at, ir.id AS impacto_reservacion_id,
                    ir.reservacion_id, ir.estado, ir.notification_prepared_at,
                    ir.access_expires_at, ir.access_invalidated_at,
                    ir.notification_attempts, ir.last_notification_at,
                    ir.notification_delivery_status, ir.notification_delivery_updated_at,
                    ir.resolved_by, ir.resolved_at,
                    r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora, r.comensales
             FROM horario_impactos i
             JOIN horario_impacto_reservaciones ir ON ir.impacto_id = i.id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE i.id = ?
             ORDER BY
                 CASE WHEN r.contacto_tipo IN ('email', 'telefono')
                           AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                      THEN 0 ELSE 1 END,
                 r.fecha ASC, r.hora ASC, r.id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible consultar el seguimiento.');
        }
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        $impacto = null;
        while ($fila = $resultado->fetch_assoc()) {
            $impacto ??= [
                'id' => (int)$fila['impacto_id'],
                'tipo_origen' => (string)$fila['tipo_origen'],
                'origen_id' => $fila['origen_id'] !== null ? (int)$fila['origen_id'] : null,
                'estado' => (string)$fila['impacto_estado'],
                'created_at' => (string)$fila['impacto_created_at'],
            ];
            $tieneContacto = self::filaTieneContacto($fila);
            $filas[] = [
                'id' => (int)$fila['impacto_reservacion_id'],
                'reservacion_id' => (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'comensales' => (int)$fila['comensales'],
                'contacto_tipo' => $tieneContacto ? (string)$fila['contacto_tipo'] : 'ninguno',
                'contacto' => $tieneContacto
                    ? ContactoService::enmascarar((string)$fila['contacto_tipo'], (string)$fila['contacto'])
                    : '',
                'tiene_contacto' => $tieneContacto,
                'estado' => (string)$fila['estado'],
                'notification_prepared_at' => $fila['notification_prepared_at'],
                'access_expires_at' => $fila['access_expires_at'],
                'access_invalidated_at' => $fila['access_invalidated_at'],
                'notification_attempts' => (int)($fila['notification_attempts'] ?? 0),
                'last_notification_at' => $fila['last_notification_at'],
                'notification_delivery_status' => (string)($fila['notification_delivery_status'] ?? 'pending'),
                'notification_delivery_updated_at' => $fila['notification_delivery_updated_at'],
                'test_link_disponible' => self::esEntornoPruebas()
                    && (string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO
                    && $fila['access_invalidated_at'] === null,
            ];
        }
        $stmt->close();
        if ($impacto === null) {
            return null;
        }

        foreach ($filas as &$fila) {
            $fila['requiere_accion'] = self::filaRequiereAccion($fila);
            $fila['puede_mandar_aviso'] = self::puedeMandarAviso($fila);
            $fila['cooldown_hasta'] = self::cooldownHasta($fila);
        }
        unset($fila);
        $impacto['reservaciones'] = $filas;
        $impacto['pendientes'] = count(array_filter(
            $filas,
            static fn(array $fila): bool => self::esEstadoPendiente((string)$fila['estado'])
        ));

        return $impacto;
    }

    /** Alias administrativo: prepara y despacha por el provider operativo. */
    public static function prepararAviso(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        $fila = self::obtenerPorItem($impactoReservacionId);
        if (!$fila || (int)$fila['impacto_id'] !== $impactoId) {
            return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
        }
        return ReservationNotificationDispatcher::dispatchScheduleChangeItem($impactoReservacionId);
    }

    /**
     * Prepara un único intento y devuelve PII/token solamente en memoria al
     * dispatcher. No realiza llamadas externas ni expone el resultado al admin.
     */
    public static function prepararAvisoParaEntrega(int $impactoReservacionId): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoReservacionId): array {
            $stmt = $db->prepare(
                "SELECT ir.id AS impacto_reservacion_id, ir.impacto_id, ir.reservacion_id,
                        ir.estado, ir.access_expires_at, ir.notification_attempts,
                        ir.last_notification_at, i.estado AS impacto_estado,
                        r.estado AS reservacion_estado, r.nombre, r.contacto_tipo, r.contacto,
                        r.fecha, r.hora, r.comensales
                 FROM horario_impacto_reservaciones ir
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 JOIN reservaciones r ON r.id = ir.reservacion_id
                 WHERE ir.id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('i', $impactoReservacionId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$fila) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if ((string)$fila['impacto_estado'] !== self::ESTADO_IMPACTO_PENDIENTE
                || (string)$fila['reservacion_estado'] !== 'confirmada'
                || !self::filaTieneContacto($fila)
                || !in_array((string)$fila['estado'], [self::ESTADO_ITEM_PENDIENTE, self::ESTADO_ITEM_PREPARADO], true)
                || (int)$fila['comensales'] > ReservacionConfig::MAX_COMENSALES_PUBLICO
            ) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $ahora = ReservacionConfig::ahora();
            $intentos = (int)($fila['notification_attempts'] ?? 0);
            if ((string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO) {
                $expiraActual = $fila['access_expires_at'] !== null
                    ? new DateTimeImmutable((string)$fila['access_expires_at'], ReservacionConfig::timezone())
                    : null;
                if ($expiraActual instanceof DateTimeImmutable && $expiraActual > $ahora) {
                    return ['ok' => false, 'codigo' => 'AVISO_VIGENTE', 'expires_at' => $expiraActual->format('Y-m-d H:i:s')];
                }
                if ($intentos >= ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_MAX_ATTEMPTS) {
                    return ['ok' => false, 'codigo' => 'AVISOS_LIMITE_ALCANZADO'];
                }
                $ultimoAviso = $fila['last_notification_at'] !== null
                    ? new DateTimeImmutable((string)$fila['last_notification_at'], ReservacionConfig::timezone())
                    : null;
                $cooldownHasta = $ultimoAviso?->modify('+' . ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_COOLDOWN_MINUTES . ' minutes');
                if ($cooldownHasta instanceof DateTimeImmutable && $cooldownHasta > $ahora) {
                    return ['ok' => false, 'codigo' => 'AVISO_EN_COOLDOWN', 'retry_at' => $cooldownHasta->format('Y-m-d H:i:s')];
                }
            }

            $token = ReservationAccessTokenService::generar();
            $expira = $ahora
                ->modify('+' . ReservacionConfig::scheduleChangeAccessTtlMinutes() . ' minutes')
                ->format('Y-m-d H:i:s');
            $managementUrl = ReservationAccessTokenService::url($token['token']);
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'notificacion_preparada', notification_prepared_at = NOW(),
                     access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL,
                     notification_attempts = notification_attempts + 1,
                     last_notification_at = NOW(),
                     notification_delivery_status = 'pending', notification_delivery_updated_at = NOW(),
                     resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado IN ('pendiente_notificacion', 'notificacion_preparada')"
            );
            $stmt->bind_param('ssi', $token['hash'], $expira, $impactoReservacionId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $stmt->close();
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $impactoReservacionId,
                BuzonNotificacionesService::PRIORIDAD_NORMAL,
                null,
                true
            );
            return [
                'ok' => true,
                'codigo' => 'AVISO_PREPARADO',
                'impacto_id' => (int)$fila['impacto_id'],
                'impacto_reservacion_id' => $impactoReservacionId,
                'notification' => [
                    'source_id' => $impactoReservacionId,
                    'reservation_id' => (int)$fila['reservacion_id'],
                    'attempt' => $intentos + 1,
                    'contact_type' => (string)$fila['contacto_tipo'],
                    'contact' => (string)$fila['contacto'],
                    'name' => (string)$fila['nombre'],
                    'reservation_date' => (string)$fila['fecha'],
                    'reservation_time' => substr((string)$fila['hora'], 0, 5),
                    'guests' => (int)$fila['comensales'],
                    'management_url' => $managementUrl,
                    'access_expires_at' => (new DateTimeImmutable($expira, ReservacionConfig::timezone()))->format(DATE_ATOM),
                ],
            ];
        });
    }

    public static function agregarContacto(
        int $impactoId,
        int $impactoReservacionId,
        string $tipo,
        string $contacto,
        ?int $adminId
    ): array {
        try {
            $normalizado = ContactoService::normalizar($tipo, $contacto);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'codigo' => 'CONTACTO_INVALIDO'];
        }

        $resultado = self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $tipo, $normalizado): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_SIN_CONTACTO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            $stmt = $db->prepare(
                "UPDATE reservaciones
                 SET contacto_tipo = ?, contacto = ?
                 WHERE id = ? AND contacto_tipo = 'ninguno'
                   AND (contacto IS NULL OR TRIM(contacto) = '')"
            );
            $reservacion = (int)$fila['reservacion_id'];
            $stmt->bind_param('ssi', $tipo, $normalizado, $reservacion);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                return ['ok' => false, 'codigo' => 'CONTACTO_NO_EDITABLE'];
            }
            $stmt->close();
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'pendiente_notificacion', resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado = 'sin_contacto'"
            );
            $stmt->bind_param('i', $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $impactoReservacionId,
                BuzonNotificacionesService::PRIORIDAD_NORMAL,
                null,
                true
            );
            self::actualizarEstadoImpacto($db, $impactoId);
            return ['ok' => true, 'codigo' => 'CONTACTO_AGREGADO'];
        });
        if (!is_array($resultado) || !($resultado['ok'] ?? false)) {
            return is_array($resultado) ? $resultado : ['ok' => false, 'codigo' => 'ERROR_SEGUIMIENTO_HORARIO'];
        }
        $dispatch = ReservationNotificationDispatcher::dispatchScheduleChangeItem($impactoReservacionId);
        $resultado['notification_dispatch'] = $dispatch;
        return $resultado;
    }

    public static function atenderManual(
        int $impactoId,
        int $impactoReservacionId,
        ?int $adminId
    ): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $adminId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || !in_array((string)$fila['estado'], [
                self::ESTADO_ITEM_PENDIENTE,
                self::ESTADO_ITEM_PREPARADO,
                self::ESTADO_ITEM_SIN_CONTACTO,
            ], true)) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'atendida_manual', resolved_by = NULLIF(?, 0),
                     resolved_at = NOW(), access_invalidated_at = NOW()
                 WHERE id = ? AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')"
            );
            $usuario = $adminId ?? 0;
            $stmt->bind_param('ii', $usuario, $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                $db,
                ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                (int)$fila['impacto_reservacion_id'],
                $adminId,
                'resuelta_admin'
            );
            self::actualizarEstadoImpacto($db, $impactoId);
            return [
                'ok' => true,
                'codigo' => 'AFECTACION_ATENDIDA_MANUALMENTE',
            ];
        });
    }

    public static function regenerarAccesoDePrueba(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        if (!self::esEntornoPruebas()) {
            return ['ok' => false, 'codigo' => 'NO_DISPONIBLE'];
        }
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_PREPARADO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if (!self::filaTieneContacto($fila)
                || (int)($fila['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO
            ) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $token = ReservationAccessTokenService::generar();
            $expira = ReservacionConfig::ahora()
                ->modify('+' . ReservacionConfig::scheduleChangeAccessTtlMinutes() . ' minutes')
                ->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL
                 WHERE id = ? AND estado = 'notificacion_preparada'"
            );
            $stmt->bind_param('ssi', $token['hash'], $expira, $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            return [
                'ok' => true,
                'codigo' => 'LINK_PRUEBA_GENERADO',
                'test_access_url' => ReservationAccessTokenService::url($token['token']),
                'expires_at' => $expira,
            ];
        });
    }

    public static function resolverPorCliente(int $reservacionId, string $fecha, string $hora, ?int $adminId = null): bool
    {
        return self::conTransaccion(function (\mysqli $db) use ($reservacionId, $fecha, $hora): bool {
            return self::resolverPorClienteEnTransaccion($db, $reservacionId, $fecha, $hora);
        }) === true;
    }

    /** Debe llamarse dentro de la transacción que confirma el reemplazo. */
    public static function accesoValidoEnTransaccion(\mysqli $db, int $impactoReservacionId, int $reservacionId): bool
    {
        if ($impactoReservacionId < 1 || $reservacionId < 1) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT id
             FROM horario_impacto_reservaciones
             WHERE id = ? AND reservacion_id = ?
               AND estado = 'notificacion_preparada'
               AND access_invalidated_at IS NULL
               AND access_expires_at > NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $impactoReservacionId, $reservacionId);
        $stmt->execute();
        $valido = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $valido;
    }

    /** Debe llamarse dentro de la transacción que confirma el reemplazo. */
    public static function resolverPorClienteEnTransaccion(\mysqli $db, int $reservacionId, string $fecha, string $hora): bool
    {
        if ($reservacionId < 1 || !HorarioOperacionService::estaAbierto($fecha, $hora)) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT ir.id, ir.impacto_id
             FROM horario_impacto_reservaciones ir
             WHERE ir.reservacion_id = ? AND ir.estado = 'notificacion_preparada'
             FOR UPDATE"
        );
        $stmt->bind_param('i', $reservacionId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $stmt->close();
        foreach ($filas as $fila) {
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'resuelta_por_cliente', resolved_by = NULL,
                     resolved_at = NOW(), access_invalidated_at = NOW()
                 WHERE id = ? AND estado = 'notificacion_preparada'"
            );
            $id = (int)$fila['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                $db,
                ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                $id,
                null,
                'resuelta_por_cliente'
            );
            self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
        }
        return $filas !== [];
    }

    public static function horarioValidoEnSnapshot(string $fecha, string $hora, array $snapshot): bool
    {
        $excepcion = $snapshot['excepciones'][$fecha] ?? null;
        if (is_array($excepcion) && !empty($excepcion['activo'])) {
            return ($excepcion['tipo'] ?? '') === 'horario_especial'
                && self::entre($hora, $excepcion['hora_apertura'] ?? null, $excepcion['hora_cierre'] ?? null);
        }
        $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        if (!$fechaObjeto instanceof DateTimeImmutable) {
            return false;
        }
        $horario = $snapshot['semanal'][(int)$fechaObjeto->format('w')] ?? null;
        return is_array($horario) && !empty($horario['abierto'])
            && self::entre($hora, $horario['hora_apertura'] ?? null, $horario['hora_cierre'] ?? null);
    }

    /**
     * Clasifica de inmediato las filas nuevas. El único caso que obtiene
     * acceso automático es 1–12 con contacto; los demás reciben un aviso
     * visible ahora y quedan para acción administrativa.
     */
    private static function clasificarSeguimientosEnTransaccion(\mysqli $db, int $impactoId, ?int $adminId): void
    {
        $stmt = $db->prepare(
            "SELECT ir.id, ir.estado, ir.access_expires_at,
                    r.comensales, r.contacto_tipo, r.contacto
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.impacto_id = ?
             FOR UPDATE"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible clasificar las reservaciones afectadas.');
        }
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $stmt->close();

        foreach ($filas as $fila) {
            $id = (int)$fila['id'];
            $estado = (string)$fila['estado'];
            $tieneContacto = self::filaTieneContacto($fila);
            $comensales = (int)$fila['comensales'];
            if (in_array($estado, self::ESTADOS_ITEM_FINALES, true)) {
                BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                    $db,
                    ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                    ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                    $id,
                    $adminId,
                    'fuente_resuelta'
                );
                continue;
            }
            $prioridad = $comensales > ReservacionConfig::MAX_COMENSALES_PUBLICO
                ? BuzonNotificacionesService::PRIORIDAD_ALTA
                : BuzonNotificacionesService::PRIORIDAD_NORMAL;
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $id,
                $prioridad,
                null,
                true
            );
        }
    }

    /** @return int[] */
    public static function itemsPendientesNotificables(int $impactoId): array
    {
        if ($impactoId < 1) {
            return [];
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT ir.id
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.impacto_id = ? AND i.estado = 'pendiente'
               AND ir.estado = 'pendiente_notificacion'
               AND r.estado = 'confirmada'
               AND r.comensales <= ?
               AND r.contacto_tipo IN ('email', 'telefono')
               AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
             ORDER BY ir.id ASC"
        );
        $maximo = ReservacionConfig::MAX_COMENSALES_PUBLICO;
        $stmt->bind_param('ii', $impactoId, $maximo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['id'];
        }
        $stmt->close();
        return $ids;
    }

    public static function marcarEntregaAceptada(int $impactoReservacionId, int $attempt): bool
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoReservacionId, $attempt): bool {
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET notification_delivery_status = 'accepted', notification_delivery_updated_at = NOW()
                 WHERE id = ? AND notification_attempts = ?
                   AND notification_delivery_status = 'pending'"
            );
            $stmt->bind_param('ii', $impactoReservacionId, $attempt);
            $stmt->execute();
            $actualizada = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$actualizada) {
                return false;
            }
            BuzonNotificacionesService::establecerRequiereAccionEnTransaccion(
                $db,
                ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                $impactoReservacionId,
                false
            );
            return true;
        }) === true;
    }

    public static function marcarEntregaFallida(int $impactoReservacionId, int $attempt): bool
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoReservacionId, $attempt): bool {
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET notification_delivery_status = 'failed', notification_delivery_updated_at = NOW(),
                     access_invalidated_at = COALESCE(access_invalidated_at, NOW()),
                     access_expires_at = LEAST(COALESCE(access_expires_at, NOW()), NOW())
                 WHERE id = ? AND notification_attempts = ?
                   AND notification_delivery_status IN ('pending', 'accepted')"
            );
            $stmt->bind_param('ii', $impactoReservacionId, $attempt);
            $stmt->execute();
            $actualizada = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$actualizada) {
                return false;
            }
            BuzonNotificacionesService::establecerRequiereAccionEnTransaccion(
                $db,
                ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                $impactoReservacionId,
                true
            );
            return true;
        }) === true;
    }

    /** Finaliza exactamente la afectación que autorizó el acceso temporal. */
    public static function resolverAccesoTemporalEnTransaccion(\mysqli $db, int $impactoReservacionId): bool
    {
        $stmt = $db->prepare(
            "SELECT id, impacto_id
             FROM horario_impacto_reservaciones
             WHERE id = ? AND estado = 'notificacion_preparada'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila) {
            return false;
        }
        $stmt = $db->prepare(
            "UPDATE horario_impacto_reservaciones
             SET estado = 'resuelta_por_cliente', resolved_by = NULL,
                 resolved_at = NOW(), access_invalidated_at = NOW()
             WHERE id = ? AND estado = 'notificacion_preparada'"
        );
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $actualizada = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$actualizada) {
            return false;
        }
        BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
            $db,
            ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
            ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
            $impactoReservacionId,
            null,
            'resuelta_por_cliente'
        );
        self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
        return true;
    }

    /** @return array<string, mixed>|null */
    public static function obtenerPorItem(int $impactoReservacionId): ?array
    {
        if ($impactoReservacionId < 1) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT i.id AS impacto_id, ir.id AS impacto_reservacion_id,
                    ir.reservacion_id, ir.estado, ir.notification_prepared_at,
                    ir.access_expires_at, ir.access_invalidated_at,
                    ir.notification_attempts, ir.last_notification_at,
                    ir.notification_delivery_status, ir.notification_delivery_updated_at,
                    r.nombre, r.fecha, r.hora, r.comensales,
                    r.contacto_tipo, r.contacto, i.estado AS impacto_estado
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila) {
            return null;
        }
        $tieneContacto = self::filaTieneContacto($fila);
        $resultado = [
            'impacto_id' => (int)$fila['impacto_id'],
            'impacto_reservacion_id' => (int)$fila['impacto_reservacion_id'],
            'reservacion_id' => (int)$fila['reservacion_id'],
            'estado' => (string)$fila['estado'],
            'impacto_estado' => (string)$fila['impacto_estado'],
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => substr((string)$fila['hora'], 0, 5),
            'comensales' => (int)$fila['comensales'],
            'tiene_contacto' => $tieneContacto,
            'access_expires_at' => $fila['access_expires_at'],
            'access_invalidated_at' => $fila['access_invalidated_at'],
            'notification_attempts' => (int)($fila['notification_attempts'] ?? 0),
            'last_notification_at' => $fila['last_notification_at'],
            'notification_delivery_status' => (string)($fila['notification_delivery_status'] ?? 'pending'),
            'notification_delivery_updated_at' => $fila['notification_delivery_updated_at'],
            'test_link_disponible' => self::esEntornoPruebas()
                && (string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO
                && $fila['access_invalidated_at'] === null,
        ];
        $resultado['requiere_accion'] = self::filaRequiereAccion($resultado);
        $resultado['puede_mandar_aviso'] = self::puedeMandarAviso($resultado);
        $resultado['cooldown_hasta'] = self::cooldownHasta($resultado);
        return $resultado;
    }

    /** Reconciliación idempotente después de una edición/cancelación administrativa. */
    public static function reconciliarReservacion(int $reservacionId, ?int $adminId = null): int
    {
        if ($reservacionId < 1) {
            return 0;
        }
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $stmt = $db->prepare('SELECT id, estado, fecha, hora FROM reservaciones WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new \RuntimeException('No fue posible bloquear la reservación.');
            }
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $reservacion = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$reservacion) {
                $db->commit();
                return 0;
            }
            $resuelta = in_array((string)$reservacion['estado'], ReservacionConfig::ESTADOS_FINALES, true)
                || HorarioOperacionService::estaAbierto((string)$reservacion['fecha'], (string)$reservacion['hora']);
            if (!$resuelta) {
                $db->commit();
                return 0;
            }

            $stmt = $db->prepare(
                "SELECT id, impacto_id
                 FROM horario_impacto_reservaciones
                 WHERE reservacion_id = ?
                   AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')
                 FOR UPDATE"
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible bloquear las afectaciones.');
            }
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $filas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $filas[] = $fila;
            }
            $stmt->close();
            foreach ($filas as $fila) {
                $id = (int)$fila['id'];
                $stmt = $db->prepare(
                    "UPDATE horario_impacto_reservaciones
                     SET estado = 'atendida_manual', resolved_by = NULLIF(?, 0),
                         resolved_at = NOW(), access_invalidated_at = NOW()
                     WHERE id = ? AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')"
                );
                if (!$stmt) {
                    throw new \RuntimeException('No fue posible reconciliar la afectación.');
                }
                $admin = $adminId ?? 0;
                $stmt->bind_param('ii', $admin, $id);
                if (!$stmt->execute()) {
                    $mensaje = $stmt->error;
                    $stmt->close();
                    throw new \RuntimeException($mensaje);
                }
                $stmt->close();
                BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                    $db,
                    ReservacionBuzonService::TIPO_HORARIO_AFECTADO,
                    ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION,
                    $id,
                    $adminId,
                    'fuente_resuelta'
                );
                self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
            }
            $db->commit();
            return count($filas);
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('HorarioOperacionImpactoService::reconciliarReservacion - ' . $e->getMessage());
            return 0;
        }
    }

    private static function actualizarEstadoImpacto(\mysqli $db, int $impactoId): void
    {
        $stmt = $db->prepare(
            "UPDATE horario_impactos i
             LEFT JOIN (
                 SELECT impacto_id,
                        SUM(estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')) AS pendientes
                 FROM horario_impacto_reservaciones
                 GROUP BY impacto_id
             ) pendientes ON pendientes.impacto_id = i.id
             SET i.estado = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                 THEN 'resuelto' ELSE 'pendiente' END,
                 i.resolved_at = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                      THEN COALESCE(i.resolved_at, NOW()) ELSE NULL END
             WHERE i.id = ?"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $stmt->close();
    }

    public static function actualizarSeguimientosVencidosEnTransaccion(\mysqli $db): void
    {
        $stmt = $db->prepare(
            "UPDATE horario_impacto_reservaciones ir
             JOIN buzon_notificaciones bn
               ON bn.tipo = 'reservacion_horario_afectado'
              AND bn.entidad_tipo = 'horario_impacto_reservacion'
              AND bn.entidad_id = ir.id
             SET ir.notification_delivery_status = 'failed',
                 ir.notification_delivery_updated_at = NOW(),
                 ir.access_invalidated_at = COALESCE(ir.access_invalidated_at, NOW()),
                 ir.access_expires_at = LEAST(COALESCE(ir.access_expires_at, NOW()), NOW()),
                 bn.requiere_accion = 1, bn.updated_at = NOW()
             WHERE bn.cerrada_at IS NULL
               AND ir.notification_delivery_status IN ('pending', 'accepted')
               AND ir.notification_delivery_updated_at IS NOT NULL
               AND ir.notification_delivery_updated_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare(
            "UPDATE buzon_notificaciones bn
             JOIN horario_impacto_reservaciones ir
               ON bn.tipo = 'reservacion_horario_afectado'
              AND bn.entidad_tipo = 'horario_impacto_reservacion'
              AND bn.entidad_id = ir.id
             SET bn.requiere_accion = 1, bn.visible_from = LEAST(bn.visible_from, NOW()), bn.updated_at = NOW()
             WHERE bn.cerrada_at IS NULL
               AND ir.estado = 'notificacion_preparada'
               AND ir.access_expires_at IS NOT NULL
               AND ir.access_expires_at <= NOW()"
        );
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function esEstadoPendiente(string $estado): bool
    {
        return in_array($estado, self::ESTADOS_ITEM_PENDIENTES, true);
    }

    private static function filaRequiereAccion(array $fila): bool
    {
        if (!$fila['tiene_contacto'] || (int)($fila['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO) {
            return true;
        }
        if ((string)($fila['estado'] ?? '') !== self::ESTADO_ITEM_PREPARADO) {
            return true;
        }
        if (in_array((string)($fila['notification_delivery_status'] ?? 'pending'), ['pending', 'failed'], true)) {
            return true;
        }
        return $fila['access_expires_at'] === null
            || (string)$fila['access_expires_at'] <= ReservacionConfig::ahora()->format('Y-m-d H:i:s');
    }

    private static function puedeMandarAviso(array $fila): bool
    {
        if (!$fila['tiene_contacto']
            || (int)($fila['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO
            || (string)($fila['estado'] ?? '') !== self::ESTADO_ITEM_PREPARADO
            || !$fila['access_expires_at']
            || (string)$fila['access_expires_at'] > ReservacionConfig::ahora()->format('Y-m-d H:i:s')
            || (int)($fila['notification_attempts'] ?? 0) >= ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_MAX_ATTEMPTS
        ) {
            return false;
        }
        $cooldown = self::cooldownHasta($fila);
        return $cooldown === null || $cooldown <= ReservacionConfig::ahora()->format('Y-m-d H:i:s');
    }

    private static function cooldownHasta(array $fila): ?string
    {
        if (empty($fila['last_notification_at'])) {
            return null;
        }
        try {
            return (new DateTimeImmutable(
                (string)$fila['last_notification_at'],
                ReservacionConfig::timezone()
            ))->modify('+' . ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_COOLDOWN_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $error) {
            return null;
        }
    }

    private static function bloquearFilaImpacto(\mysqli $db, int $impactoId, int $impactoReservacionId): ?array
    {
        $stmt = $db->prepare(
            "SELECT ir.id AS impacto_reservacion_id, ir.impacto_id, ir.reservacion_id,
                    ir.estado, ir.access_expires_at, ir.notification_attempts,
                    ir.last_notification_at, r.contacto_tipo, r.contacto, r.comensales
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? AND ir.impacto_id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $impactoReservacionId, $impactoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function accessUrl(string $token): string
    {
        return ReservationAccessTokenService::url($token);
    }

    private static function filaTieneContacto(array $fila): bool
    {
        return in_array((string)($fila['contacto_tipo'] ?? ''), ['email', 'telefono'], true)
            && trim((string)($fila['contacto'] ?? '')) !== '';
    }

    private static function estaDentro(string $fecha, string $hora, array $snapshot): bool
    {
        return self::horarioValidoEnSnapshot($fecha, $hora, $snapshot);
    }

    private static function entre(string $hora, ?string $apertura, ?string $cierre): bool
    {
        return $apertura !== null && $cierre !== null && $hora >= $apertura && $hora < $cierre;
    }

    private static function hora(string $hora): ?string
    {
        $hora = trim($hora);
        if ($hora === '') {
            return null;
        }
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $hora) === 1
            ? substr($hora, 0, 5) . ':00'
            : null;
    }

    private static function dedupKey(string $tipo, ?int $id, array $evaluacion): string
    {
        return hash('sha256', $tipo . '|' . ($id ?? 0) . '|'
            . json_encode($evaluacion['antes'] ?? []) . '|'
            . json_encode($evaluacion['despues'] ?? []));
    }

    private static function esEntornoPruebas(): bool
    {
        return in_array(ReservacionConfig::appEnvironment(), ['development', 'testing'], true);
    }

    /** @param callable(\mysqli): array|bool $callback */
    private static function conTransaccion(callable $callback): array|bool
    {
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $resultado = $callback($db);
            $fallo = is_array($resultado) ? (($resultado['ok'] ?? true) === false) : $resultado === false;
            if ($fallo) {
                $db->rollback();
                return $resultado;
            }
            $db->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('HorarioOperacionImpactoService - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => 'ERROR_SEGUIMIENTO_HORARIO'];
        }
    }
}
