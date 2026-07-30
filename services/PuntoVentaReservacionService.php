<?php

/**
 * Orquesta las transiciones transaccionales entre reservaciones, mesas y POS.
 */

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\TicketMesa;

final class PuntoVentaReservacionService
{
    public const OK = 'OK';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const NO_EXISTE = 'NO_EXISTE';
    public const ESTADO_INVALIDO = 'ESTADO_INVALIDO';
    public const MESA_OCUPADA = 'MESA_OCUPADA';
    public const TOLERANCIA_VIGENTE = 'TOLERANCIA_VIGENTE';
    public const REQUIERE_CONFIRMACION = 'REQUIERE_CONFIRMACION';
    public const TICKET_ABIERTO = 'TICKET_ABIERTO';
    public const REQUIERE_REASIGNACION = 'REQUIERE_REASIGNACION';
    public const SIN_CAPACIDAD = 'SIN_CAPACIDAD';
    public const CONFLICTO_CONCURRENTE = 'CONFLICTO_CONCURRENTE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /**
     * Lista sólo reservaciones operativas; los walk-ins viven exclusivamente
     * como tickets y nunca aparecen aquí.
     */
    public static function listar(string $fecha): array
    {
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'reservaciones' => []];
        }

        $db = ActiveRecord::getDB();
        $estadosLista = ReservacionConfig::estadosSql(ReservacionConfig::ESTADOS_LISTA_OPERATIVA);
        $stmt = $db->prepare(
            "SELECT r.id, r.nombre, r.fecha, r.hora, r.comensales, r.estado,
                    r.arrived_at, r.completed_at, r.status_changed_at,
                    r.last_modified_by, r.last_modified_source, r.last_change_reason,
                    GROUP_CONCAT(rm.mesa_id ORDER BY rm.orden) AS mesa_ids,
                    GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', ') AS mesas,
                    MAX(t.id) AS ticket_id
             FROM reservaciones r
             LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
             LEFT JOIN mesas m ON m.id = rm.mesa_id
             LEFT JOIN tickets t ON t.reservacion_id = r.id
               AND " . TicketMesa::condicionSqlAbierto('t') . "
             WHERE r.fecha = ?
               AND r.estado IN ({$estadosLista})
             GROUP BY r.id
             ORDER BY r.hora, r.id"
        );
        $stmt->bind_param('s', $fecha);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $reservaciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $datos = [
                'id' => (int)$fila['id'],
                'nombre' => (string)$fila['nombre'],
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'personas' => (int)$fila['comensales'],
                'estado' => (string)$fila['estado'],
                'mesa_ids' => self::csvIds($fila['mesa_ids']),
                'mesas' => (string)($fila['mesas'] ?? ''),
                'ticket_id' => $fila['ticket_id'] !== null ? (int)$fila['ticket_id'] : null,
                'arrived_at' => $fila['arrived_at'],
                'completed_at' => $fila['completed_at'],
                'status_changed_at' => $fila['status_changed_at'],
                'last_modified_by' => $fila['last_modified_by'] !== null
                    ? (int)$fila['last_modified_by']
                    : null,
                'last_modified_source' => (string)$fila['last_modified_source'],
                'last_change_reason' => $fila['last_change_reason'],
            ];
            $vigencia = ReservacionVigenciaService::clasificar(array_merge($fila, [
                'ticket_id' => $datos['ticket_id'],
                'ticket_abierto' => $datos['ticket_id'] !== null,
            ]));
            $reservaciones[] = array_merge($datos, $vigencia, [
                'tolerancia_hasta' => $vigencia['limite_tolerancia'],
                'no_show_disponible' => (bool)$vigencia['elegible_no_show'],
                'retrasada' => (bool)$vigencia['tolerancia_vencida'],
            ]);
        }
        $stmt->close();

        $horarios = ReservacionService::obtenerHorariosDisponiblesParaFecha(
            $fecha,
            HorarioReservacionService::fechaPasada($fecha)
        );
        $reservaciones = ReservacionVigenciaService::filtrarPendientesOperacion(
            $reservaciones,
            $fecha,
            (array)($horarios['horarios'] ?? [])
        );

        return ['ok' => true, 'codigo' => self::OK, 'reservaciones' => $reservaciones];
    }

    /**
     * Registra llegada anticipada sin ocupar físicamente ni crear ticket.
     * Repetir la solicitud sobre `llego` es idempotente.
     */
    public static function registrarLlegada(int $reservacionId, int $usuarioId): array
    {
        return self::mutarReservacion($reservacionId, function (array $r, \mysqli $db) use ($usuarioId): array {
            if ($r['estado'] === 'llego') {
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true];
            }
            if ($r['estado'] !== 'confirmada') {
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }
            if (self::ticketAbiertoReservacion($db, (int)$r['id'])) {
                return ['ok' => false, 'codigo' => self::TICKET_ABIERTO];
            }

            $mesaIds = ReservacionMesa::obtenerIdsPorReservacion((int)$r['id']);
            if ($mesaIds === []) {
                return [
                    'ok' => false,
                    'codigo' => self::REQUIERE_REASIGNACION,
                    'requiere_reasignacion' => true,
                    'motivo' => 'SIN_MESAS',
                ];
            }
            $mesas = Mesa::reservablesParaActualizar($mesaIds);
            if (count($mesas) !== count($mesaIds)) {
                return [
                    'ok' => false,
                    'codigo' => self::REQUIERE_REASIGNACION,
                    'requiere_reasignacion' => true,
                    'motivo' => 'MESAS_NO_DISPONIBLES',
                ];
            }
            $ocupacion = AsignacionMesasService::obtenerOcupacionParaHorario(
                (string)$r['fecha'],
                (string)$r['hora'],
                (int)$r['id'],
                true,
                true
            );
            if (AsignacionMesasService::hayConflictoHorario($ocupacion, $mesaIds)) {
                return [
                    'ok' => false,
                    'codigo' => self::REQUIERE_REASIGNACION,
                    'requiere_reasignacion' => true,
                    'motivo' => 'MESAS_OCUPADAS',
                    'mesa_ids' => $mesaIds,
                ];
            }
            if (!AsignacionMesasService::validarCapacidad(
                $mesas,
                $mesaIds,
                (int)$r['comensales']
            )) {
                return [
                    'ok' => false,
                    'codigo' => self::SIN_CAPACIDAD,
                    'requiere_reasignacion' => true,
                    'motivo' => 'CAPACIDAD_INSUFICIENTE',
                    'mesa_ids' => $mesaIds,
                ];
            }

            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'llego',
                 arrived_at = COALESCE(arrived_at, NOW()),
                 status_changed_at = NOW(),
                 last_modified_by = {$usuarioId},
                 last_modified_source = 'personal',
                 last_change_reason = 'Llegada registrada'"
            );

            return ['ok' => true, 'codigo' => self::OK, 'idempotente' => false];
        });
    }

    /**
     * Crea ticket y ocupa todas las mesas de la reservación en una sola
     * transacción. El orden es reservación -> mesas por ID -> tickets.
     */
    public static function comenzar(int $reservacionId, int $usuarioId, ?int $meseroId = null): array
    {
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = null;
        $transaccion = false;

        try {
            $lockHorario = HorarioConfigLock::adquirir($db);
            if (!$lockHorario) {
                throw new \RuntimeException('No fue posible bloquear el horario operativo.');
            }
            $previa = self::fila("SELECT fecha FROM reservaciones WHERE id = {$reservacionId}");
            if (!$previa) {
                return ['ok' => false, 'codigo' => self::NO_EXISTE];
            }
            $lockFecha = (string)$previa['fecha'];
            if (!FechaOperacionLock::adquirir($db, $lockFecha, 10)) {
                throw new \RuntimeException('No fue posible bloquear la fecha operativa.');
            }
            $db->begin_transaction();
            $transaccion = true;

            $r = self::fila("SELECT * FROM reservaciones WHERE id = {$reservacionId} FOR UPDATE");
            if (!$r) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            if ($r['estado'] === 'en_curso') {
                $ticket = self::fila(
                    "SELECT t.id FROM tickets t
                     WHERE t.reservacion_id = {$reservacionId}
                       AND " . TicketMesa::condicionSqlAbierto('t') . "
                     FOR UPDATE"
                );
                $db->commit();
                $transaccion = false;
                return [
                    'ok' => true,
                    'codigo' => self::OK,
                    'idempotente' => true,
                    'ticket_id' => $ticket ? (int)$ticket['id'] : null,
                ];
            }
            if (!in_array($r['estado'], ['confirmada', 'llego'], true)) {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }
            if ((string)$r['fecha'] !== ReservacionConfig::fechaActual()) {
                return self::rollbackResultado($db, $transaccion, self::DATOS_INVALIDOS);
            }

            $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
            if ($mesaIds === []) {
                return self::rollbackResultado($db, $transaccion, self::DATOS_INVALIDOS);
            }
            self::bloquearMesas($db, $mesaIds);
            $ocupacion = AsignacionMesasService::obtenerOcupacionParaHorario(
                (string)$r['fecha'],
                (string)$r['hora'],
                $reservacionId,
                true,
                true
            );
            if (AsignacionMesasService::hayConflictoHorario($ocupacion, $mesaIds)) {
                return self::rollbackResultado($db, $transaccion, self::MESA_OCUPADA);
            }

            $ticketId = self::insertarTicket(
                $db,
                (int)$r['comensales'],
                (string)$r['nombre'],
                $reservacionId,
                $meseroId
            );
            TicketMesa::insertarTodas($ticketId, $mesaIds);
            self::actualizarReservacion(
                $db,
                $reservacionId,
                "estado = 'en_curso',
                 arrived_at = COALESCE(arrived_at, NOW()),
                 status_changed_at = NOW(),
                 last_modified_by = {$usuarioId},
                 last_modified_source = 'personal',
                 last_change_reason = 'Servicio iniciado'"
            );
            $db->commit();
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::OK,
                'idempotente' => false,
                'ticket_id' => $ticketId,
                'mesa_ids' => $mesaIds,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::comenzar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        } finally {
            if ($lockFecha !== null) {
                FechaOperacionLock::liberar($db, $lockFecha);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /**
     * Aplica no-show únicamente a una confirmada con tolerancia vencida, sin
     * llegada y sin ticket abierto. Los argumentos de override se conservan en
     * el contrato durante la transición, pero ya no habilitan excepciones.
     */
    public static function noShow(
        int $reservacionId,
        int $usuarioId,
        bool $override,
        bool $puedeOverride,
        string $motivo = ''
    ): array {
        return self::mutarReservacion($reservacionId, function (array $r, \mysqli $db) use (
            $usuarioId,
            $motivo
        ): array {
            if ($r['estado'] === 'no_show') {
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true];
            }
            if ($r['estado'] !== 'confirmada') {
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }
            if (self::ticketAbiertoReservacion($db, (int)$r['id'])) {
                return ['ok' => false, 'codigo' => self::TICKET_ABIERTO];
            }

            $vigencia = ReservacionVigenciaService::clasificar($r);
            if (!$vigencia['elegible_no_show']) {
                return [
                    'ok' => false,
                    'codigo' => self::TOLERANCIA_VIGENTE,
                    'tolerancia_hasta' => $vigencia['limite_tolerancia'],
                ];
            }

            $razon = trim($motivo) !== ''
                ? trim($motivo)
                : 'Tolerancia vencida';
            $razon = $db->real_escape_string($razon);
            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'no_show',
                 status_changed_at = NOW(),
                 last_modified_by = {$usuarioId},
                 last_modified_source = 'personal',
                 last_change_reason = '{$razon}'"
            );

            return ['ok' => true, 'codigo' => self::OK, 'idempotente' => false];
        });
    }

    /** Cancela administrativamente sin borrar mesas históricas. */
    public static function cancelar(
        int $reservacionId,
        int $usuarioId,
        string $motivo = ''
    ): array {
        return self::mutarReservacion($reservacionId, function (array $r, \mysqli $db) use ($usuarioId, $motivo): array {
            if ($r['estado'] === 'cancelada') {
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true];
            }
            if (!in_array($r['estado'], ['confirmada', 'llego'], true)) {
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }
            if (self::ticketAbiertoReservacion($db, (int)$r['id'])) {
                return ['ok' => false, 'codigo' => self::TICKET_ABIERTO];
            }
            if (trim($motivo) === '') {
                return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
            }

            $razon = $db->real_escape_string(
                trim($motivo)
            );
            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'cancelada',
                 status_changed_at = NOW(),
                 last_modified_by = {$usuarioId},
                 last_modified_source = 'personal',
                 last_change_reason = '{$razon}'"
            );

            return ['ok' => true, 'codigo' => self::OK, 'idempotente' => false];
        });
    }

    /**
     * Abre un ticket walk-in. Una reservación próxima genera advertencia, no
     * una reservación artificial ni un bloqueo automático.
     */
    public static function abrirWalkIn(array $datos, int $usuarioId): array
    {
        $mesaIds = $datos['mesa_ids'] ?? [];
        if (!is_array($mesaIds)) {
            $mesaIds = [];
        }
        if (!empty($datos['mesa_id'])) {
            array_unshift($mesaIds, (int)$datos['mesa_id']);
        }
        if (!empty($datos['mesa2_id'])) {
            $mesaIds[] = (int)$datos['mesa2_id'];
        }
        $mesaIds = array_values(array_unique(array_filter(array_map('intval', $mesaIds))));
        sort($mesaIds, SORT_NUMERIC);
        $comensales = max(1, (int)($datos['comensales'] ?? 1));
        if ($mesaIds === [] || count($mesaIds) > ReservacionConfig::MAX_PUBLIC_TABLES) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }

        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = false;
        $transaccion = false;
        try {
            $lockHorario = HorarioConfigLock::adquirir($db);
            $lockFecha = $lockHorario
                && FechaOperacionLock::adquirir($db, ReservacionConfig::fechaActual(), 10);
            if (!$lockHorario || !$lockFecha) {
                throw new \RuntimeException('No fue posible bloquear la operación del día.');
            }
            $db->begin_transaction();
            $transaccion = true;
            self::bloquearMesas($db, $mesaIds);
            if (empty($datos['allow_multiple']) && self::ticketAbiertoEnMesas($db, $mesaIds) !== null) {
                return self::rollbackResultado($db, $transaccion, self::MESA_OCUPADA);
            }

            $warning = self::proximaReservacion($db, $mesaIds);
            if ($warning && !empty($warning['bloqueada'])) {
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::MESA_OCUPADA,
                    ['bloqueo' => $warning]
                );
            }
            $confirmoReservacionProxima =
                (string)($datos['confirmar_reservacion_proxima'] ?? '') === '1';
            if ($warning && !$confirmoReservacionProxima) {
                $db->rollback();
                $transaccion = false;
                return [
                    'ok' => false,
                    'codigo' => self::REQUIERE_CONFIRMACION,
                    'requiere_confirmacion' => true,
                    'advertencia' => $warning,
                ];
            }

            $ticketId = self::insertarTicket(
                $db,
                $comensales,
                trim((string)($datos['nombre'] ?? '')),
                null,
                !empty($datos['mesero_id']) ? (int)$datos['mesero_id'] : null
            );
            TicketMesa::insertarTodas($ticketId, $mesaIds);
            if ($warning) {
                $razon = $db->real_escape_string(
                    trim((string)($datos['motivo'] ?? ''))
                        ?: 'Walk-in aceptado con reservación próxima'
                );
                self::actualizarReservacion(
                    $db,
                    (int)$warning['reservacion_id'],
                    "last_modified_by = {$usuarioId},
                     last_modified_source = 'personal',
                     last_change_reason = '{$razon}'"
                );
            }
            $db->commit();
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::OK,
                'ticket_id' => $ticketId,
                'mesa_ids' => $mesaIds,
                'advertencia' => $warning,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::abrirWalkIn - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        } finally {
            if ($lockFecha) {
                FechaOperacionLock::liberar($db, ReservacionConfig::fechaActual());
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /**
     * Cierra ticket, completa su reservación y registra pagos/token de feedback
     * en la misma transacción. Los walk-ins sólo liberan sus mesas.
     */
    public static function cerrarTicket(
        int $ticketId,
        string $metodoPago,
        float $propina,
        array $pagos,
        int $usuarioId
    ): array {
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;
            $ticket = self::fila("SELECT * FROM tickets WHERE id = {$ticketId} FOR UPDATE");
            if (!$ticket) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            if ($ticket['estado'] === 'cerrado') {
                $token = self::tokenFeedback($db, $ticketId);
                $db->commit();
                $transaccion = false;
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true, 'token' => $token];
            }
            if ($ticket['estado'] !== 'abierto') {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }

            $reservacionId = $ticket['reservacion_id'] !== null ? (int)$ticket['reservacion_id'] : null;
            $reservacion = null;
            if ($reservacionId) {
                $reservacion = self::fila(
                    "SELECT * FROM reservaciones WHERE id = {$reservacionId} FOR UPDATE"
                );
            }
            $metodo = $db->real_escape_string($metodoPago);
            $propinaSql = number_format(max(0, $propina), 2, '.', '');
            if (!$db->query(
                "UPDATE tickets
                 SET estado = 'cerrado', closed_at = COALESCE(closed_at, NOW()),
                     metodo_pago = '{$metodo}', propina = {$propinaSql}
                 WHERE id = {$ticketId}"
            )) {
                throw new \RuntimeException($db->error);
            }

            foreach ($pagos as $pago) {
                $comensal = (int)($pago['comensal'] ?? 0);
                $metodoPagoFila = (string)($pago['metodo'] ?? '');
                $monto = (float)($pago['monto'] ?? 0);
                if ($comensal < 1 || !in_array($metodoPagoFila, ['efectivo', 'tarjeta'], true) || $monto <= 0) {
                    throw new \DomainException('Un pago dividido no es válido.');
                }
                $metodoFila = $db->real_escape_string($metodoPagoFila);
                $montoSql = number_format($monto, 2, '.', '');
                if (!$db->query(
                    "INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto)
                     VALUES ({$ticketId}, {$comensal}, '{$metodoFila}', {$montoSql})"
                )) {
                    throw new \RuntimeException($db->error);
                }
            }

            $token = self::tokenFeedback($db, $ticketId);
            if ($reservacion && $reservacion['estado'] === 'en_curso') {
                self::actualizarReservacion(
                    $db,
                    $reservacionId,
                    "estado = 'completada',
                     completed_at = COALESCE(completed_at, NOW()),
                     status_changed_at = NOW(),
                     last_modified_by = {$usuarioId},
                     last_modified_source = 'personal',
                     last_change_reason = 'Ticket cerrado'"
                );
            }
            $db->commit();
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::OK,
                'idempotente' => false,
                'token' => $token,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::cerrarTicket - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    /**
     * Contexto informativo de una mesa. La mutación posterior vuelve a tomar
     * locks y recalcular todo el estado.
     */
    public static function contextoMesa(int $mesaId): array
    {
        if ($mesaId < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }
        $db = ActiveRecord::getDB();
        $ticket = self::fila(
            "SELECT t.id, t.nombre, t.comensales, t.hora_apertura, t.reservacion_id,
                    tm.mesa_id
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.mesa_id = {$mesaId}
             WHERE " . TicketMesa::condicionSqlAbierto('t') . "
             ORDER BY t.id LIMIT 1"
        );
        $ahora = ReservacionConfig::ahora();
        $hasta = $ahora->modify('+1 day')->format('Y-m-d H:i:s');
        $reservas = [];
        $condicionOcupacion = ReservacionConfig::condicionSqlOcupacionActiva('r');
        $resultado = $db->query(
            "SELECT r.id, r.nombre, r.fecha, r.hora, r.comensales, r.estado
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             WHERE rm.mesa_id = {$mesaId}
               AND {$condicionOcupacion}
               AND TIMESTAMP(r.fecha, r.hora) <= '{$hasta}'
             ORDER BY r.fecha, r.hora"
        );
        while ($fila = $resultado->fetch_assoc()) {
            $reservas[] = $fila;
        }
        $resultado->free();

        $actual = null;
        $proxima = null;
        foreach ($reservas as $reserva) {
            $inicio = new DateTimeImmutable($reserva['fecha'] . ' ' . $reserva['hora'], ReservacionConfig::timezone());
            if ($inicio <= $ahora) {
                $actual = self::reservaContexto($reserva);
            } elseif ($inicio > $ahora && $proxima === null) {
                $proxima = self::reservaContexto($reserva);
            }
        }

        $liberacion = null;
        if ($ticket) {
            $apertura = new DateTimeImmutable((string)$ticket['hora_apertura'], ReservacionConfig::timezone());
            $a = $apertura->modify('+' . ReservacionConfig::DURACION_SERVICIO_ESTIMADA_MINUTOS . ' minutes');
            $b = $ahora->modify('+' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes');
            $liberacion = ($a > $b ? $a : $b)->format('Y-m-d H:i:s');
        }
        $advertencia = null;
        if ($ticket && $proxima) {
            $recomendada = (new DateTimeImmutable(
                $proxima['fecha'] . ' ' . $proxima['hora'],
                ReservacionConfig::timezone()
            ))->modify('-' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes');
            $advertencia = sprintf(
                'Esta mesa tiene una reservación a las %s. Se recomienda liberarla antes de las %s.',
                $proxima['hora'],
                $recomendada->format('H:i')
            );
        }

        return [
            'ok' => true,
            'codigo' => self::OK,
            'ticket_abierto' => $ticket ? [
                'id' => (int)$ticket['id'],
                'reservacion_id' => $ticket['reservacion_id'] !== null ? (int)$ticket['reservacion_id'] : null,
            ] : null,
            'reservacion_actual' => $actual,
            'reservacion_proxima' => $proxima,
            'acciones' => [
                'puede_abrir_ticket' => $ticket === null,
                'puede_cerrar_ticket' => $ticket !== null,
            ],
            'advertencia' => $advertencia,
            'liberacion_estimada' => $liberacion,
        ];
    }

    private static function mutarReservacion(int $id, callable $operacion): array
    {
        if ($id < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;
            $r = self::fila("SELECT * FROM reservaciones WHERE id = {$id} FOR UPDATE");
            if (!$r) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            $resultado = $operacion($r, $db);
            if (!($resultado['ok'] ?? false)) {
                $db->rollback();
                $transaccion = false;
                return $resultado;
            }
            $db->commit();
            $transaccion = false;
            return $resultado;
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::mutarReservacion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    private static function insertarTicket(
        \mysqli $db,
        int $comensales,
        string $nombre,
        ?int $reservacionId,
        ?int $meseroId
    ): int {
        $stmt = $db->prepare(
            "INSERT INTO tickets
                (comensales, nombre, estado, reservacion_id, mesero_id)
             VALUES (?, NULLIF(?, ''), 'abierto', ?, ?)"
        );
        $stmt->bind_param(
            'isii',
            $comensales,
            $nombre,
            $reservacionId,
            $meseroId
        );
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error);
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /** Bloquea siempre las mesas en orden ascendente. */
    private static function bloquearMesas(\mysqli $db, array $mesaIds): void
    {
        $mesaIds = array_values(array_unique(array_filter(array_map('intval', $mesaIds))));
        sort($mesaIds, SORT_NUMERIC);
        if ($mesaIds === []) {
            throw new \DomainException('No hay mesas que bloquear.');
        }
        $resultado = $db->query(
            'SELECT id FROM mesas WHERE activo = 1 AND id IN (' . implode(',', $mesaIds) . ') ORDER BY id FOR UPDATE'
        );
        if (!$resultado || $resultado->num_rows !== count($mesaIds)) {
            throw new \DomainException('Una mesa no existe o está fuera de servicio.');
        }
        $resultado->free();
    }

    private static function ticketAbiertoEnMesas(\mysqli $db, array $mesaIds): ?array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        return self::fila(
            "SELECT DISTINCT t.id
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
             WHERE " . TicketMesa::condicionSqlAbierto('t') . "
               AND tm.mesa_id IN ({$ids})
             LIMIT 1 FOR UPDATE"
        );
    }

    private static function ticketAbiertoReservacion(\mysqli $db, int $reservacionId): bool
    {
        return self::fila(
            "SELECT t.id FROM tickets t
             WHERE t.reservacion_id = {$reservacionId}
               AND " . TicketMesa::condicionSqlAbierto('t') . "
             LIMIT 1 FOR UPDATE"
        ) !== null;
    }

    private static function proximaReservacion(\mysqli $db, array $mesaIds): ?array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        $reloj = ReservacionConfig::ahora();
        $ahora = $reloj->format('Y-m-d H:i:s');
        $limite = $reloj
            ->modify('+' . ReservacionConfig::MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA . ' minutes')
            ->format('Y-m-d H:i:s');
        $condicionOcupacion = ReservacionConfig::condicionSqlOcupacionActiva('r');
        $fila = self::fila(
            "SELECT r.id AS reservacion_id,
                    r.nombre,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    GROUP_CONCAT(DISTINCT rm_todas.mesa_id ORDER BY rm_todas.orden) AS mesa_ids
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             INNER JOIN reservacion_mesas rm_todas ON rm_todas.reservacion_id = r.id
             WHERE rm.mesa_id IN ({$ids})
               AND {$condicionOcupacion}
               AND TIMESTAMP(r.fecha, r.hora) > '{$ahora}'
               AND TIMESTAMP(r.fecha, r.hora) <= '{$limite}'
             GROUP BY r.id, r.nombre, r.fecha, r.hora, r.comensales
             ORDER BY r.fecha, r.hora
             LIMIT 1 FOR UPDATE"
        );
        if (!$fila) {
            return null;
        }

        $inicio = new DateTimeImmutable(
            (string)$fila['fecha'] . ' ' . (string)$fila['hora'],
            ReservacionConfig::timezone()
        );
        $segundosRestantes = max(0, $inicio->getTimestamp() - $reloj->getTimestamp());
        $minutosRestantes = (int)ceil($segundosRestantes / 60);

        return [
            'reservacion_id' => (int)$fila['reservacion_id'],
            'folio' => '#' . (int)$fila['reservacion_id'],
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => substr((string)$fila['hora'], 0, 5),
            'comensales' => (int)$fila['comensales'],
            'mesa_ids' => self::csvIds($fila['mesa_ids']),
            'minutos_restantes' => $minutosRestantes,
            'bloqueada' => $segundosRestantes
                <= ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO * 60,
        ];
    }

    private static function tokenFeedback(\mysqli $db, int $ticketId): string
    {
        $existente = self::fila(
            "SELECT token FROM feedback_tokens WHERE ticket_id = {$ticketId} LIMIT 1 FOR UPDATE"
        );
        if ($existente) {
            return (string)$existente['token'];
        }
        $token = bin2hex(random_bytes(16));
        if (!$db->query(
            "INSERT INTO feedback_tokens (ticket_id, token) VALUES ({$ticketId}, '{$token}')"
        )) {
            throw new \RuntimeException($db->error);
        }

        return $token;
    }

    private static function actualizarReservacion(\mysqli $db, int $id, string $set): void
    {
        if (!$db->query("UPDATE reservaciones SET {$set} WHERE id = {$id}")) {
            throw new \RuntimeException($db->error);
        }
    }

    private static function fila(string $query): ?array
    {
        $resultado = ActiveRecord::getDB()->query($query);
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();

        return $fila;
    }

    private static function rollbackResultado(
        \mysqli $db,
        bool &$transaccion,
        string $codigo,
        array $contexto = []
    ): array
    {
        $db->rollback();
        $transaccion = false;
        return array_merge(['ok' => false, 'codigo' => $codigo], $contexto);
    }

    /** @return array<int, int> */
    private static function csvIds(?string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$csv))));
    }

    private static function reservaContexto(array $fila): array
    {
        return [
            'id' => (int)$fila['id'],
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => substr((string)$fila['hora'], 0, 5),
            'personas' => (int)$fila['comensales'],
            'estado' => (string)$fila['estado'],
        ];
    }
}
