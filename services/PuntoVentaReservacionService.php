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
use Model\VerificacionContacto;

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
    public const TICKET_CON_CONSUMO = 'TICKET_CON_CONSUMO';
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

        $lectura = PosReservacionQueryService::paraFecha($fecha, '', [
            'incluir_inactivas' => false,
            'calcular_conflictos' => true,
        ]);
        if (!($lectura['ok'] ?? false)) {
            return [
                'ok' => false,
                'codigo' => self::ERROR_INTERNO,
                'reservaciones' => [],
            ];
        }

        $horarios = ReservacionService::obtenerHorariosDisponiblesParaFecha(
            $fecha,
            HorarioReservacionService::fechaPasada($fecha)
        );
        $reservaciones = ReservacionVigenciaService::filtrarPendientesOperacion(
            (array)$lectura['reservaciones'],
            $fecha,
            (array)($horarios['horarios'] ?? [])
        );

        return [
            'ok' => true,
            'codigo' => self::OK,
            'schema_version' => $lectura['schema_version'],
            'reservaciones' => $reservaciones,
            'server_time' => $lectura['server_time'],
            'timezone' => $lectura['timezone'],
            'config' => $lectura['config'],
        ];
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
                if (!$ticket) {
                    return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
                }
                $db->commit();
                $transaccion = false;
                return [
                    'ok' => true,
                    'codigo' => self::OK,
                    'idempotente' => true,
                    'ticket_id' => $ticket ? (int)$ticket['id'] : null,
                ];
            }
            if ($r['estado'] !== 'confirmada') {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }
            if ((string)$r['fecha'] !== ReservacionConfig::fechaActual()) {
                return self::rollbackResultado($db, $transaccion, self::DATOS_INVALIDOS);
            }
            if (!ReservacionVigenciaService::clasificar($r)['puede_iniciar_servicio']) {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }
            if (self::ticketAbiertoReservacion($db, $reservacionId)) {
                return self::rollbackResultado($db, $transaccion, self::TICKET_ABIERTO);
            }
            if (!self::meseroActivo($db, $meseroId)) {
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
                 estado_changed_at = NOW()"
            );
            self::invalidarReemplazosPendientes($db, $reservacionId);
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

            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'no_show',
                 estado_changed_at = NOW()"
            );
            self::invalidarReemplazosPendientes($db, (int)$r['id']);

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
            if ($r['estado'] !== 'confirmada') {
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }
            if (self::ticketAbiertoReservacion($db, (int)$r['id'])) {
                return ['ok' => false, 'codigo' => self::TICKET_ABIERTO];
            }
            if (trim($motivo) === '') {
                return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
            }

            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'cancelada',
                 estado_changed_at = NOW()"
            );
            self::invalidarReemplazosPendientes($db, (int)$r['id']);

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
        $comensales = (int)($datos['comensales'] ?? 1);
        $meseroId = !empty($datos['mesero_id']) ? (int)$datos['mesero_id'] : null;
        if ($mesaIds === [] || $comensales < 1) {
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
            $mesasInvalidas = self::mesasNoTicketables($db, $mesaIds);
            if ($mesasInvalidas !== []) {
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::DATOS_INVALIDOS,
                    ['mesas_invalidas' => $mesasInvalidas]
                );
            }
            if (!self::meseroActivo($db, $meseroId)) {
                return self::rollbackResultado($db, $transaccion, self::DATOS_INVALIDOS);
            }
            if (empty($datos['allow_multiple'])) {
                $conflicto = self::ticketAbiertoEnMesas($db, $mesaIds);
                if ($conflicto !== null) {
                    return self::rollbackResultado(
                        $db,
                        $transaccion,
                        self::MESA_OCUPADA,
                        ['mesas_conflicto' => self::csvIds($conflicto['mesa_ids'] ?? '')]
                    );
                }
            }

            $warnings = self::proximasReservaciones($db, $mesaIds);
            $warning = $warnings[0] ?? null;
            $bloqueo = null;
            foreach ($warnings as $candidate) {
                if (!empty($candidate['bloqueada'])) {
                    $bloqueo = $candidate;
                    break;
                }
            }
            if ($bloqueo) {
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::MESA_OCUPADA,
                    ['bloqueo' => $bloqueo, 'bloqueos' => $warnings]
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
                    'advertencias' => $warnings,
                ];
            }

            $ticketId = self::insertarTicket(
                $db,
                $comensales,
                trim((string)($datos['nombre'] ?? '')),
                null,
                $meseroId
            );
            TicketMesa::insertarTodas($ticketId, $mesaIds);
            $db->commit();
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::OK,
                'ticket_id' => $ticketId,
                'mesa_ids' => $mesaIds,
                'advertencia' => $warning,
                'advertencias' => $warnings,
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
        $lockHorario = false;
        $lockFecha = null;
        $transaccion = false;
        try {
            $previo = self::fila(
                "SELECT t.reservacion_id, t.hora_apertura, r.fecha AS reservacion_fecha
                 FROM tickets t
                 LEFT JOIN reservaciones r ON r.id = t.reservacion_id
                 WHERE t.id = {$ticketId}
                 LIMIT 1"
            );
            if (!$previo) {
                return ['ok' => false, 'codigo' => self::NO_EXISTE];
            }
            $lockFecha = trim((string)($previo['reservacion_fecha'] ?? ''));
            if ($lockFecha === '') {
                $apertura = trim((string)($previo['hora_apertura'] ?? ''));
                $lockFecha = substr($apertura, 0, 10) ?: ReservacionConfig::fechaActual();
            }
            $lockHorario = HorarioConfigLock::adquirir($db);
            if (!$lockHorario || !FechaOperacionLock::adquirir($db, $lockFecha, 10)) {
                throw new \RuntimeException('No fue posible bloquear la fecha operativa.');
            }
            $db->begin_transaction();
            $transaccion = true;
            $reservacionId = $previo['reservacion_id'] !== null ? (int)$previo['reservacion_id'] : null;
            $reservacion = null;
            if ($reservacionId) {
                $reservacion = self::fila(
                    "SELECT * FROM reservaciones WHERE id = {$reservacionId} FOR UPDATE"
                );
                if (!$reservacion) {
                    return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
                }
            }
            $ticket = self::fila("SELECT * FROM tickets WHERE id = {$ticketId} FOR UPDATE");
            if (!$ticket) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            if ($ticket['estado'] === 'cerrado') {
                if ($reservacionId && (!$reservacion || $reservacion['estado'] !== 'completada')) {
                    return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
                }
                $token = self::tokenFeedback($db, $ticketId);
                $db->commit();
                $transaccion = false;
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true, 'token' => $token];
            }
            if ($ticket['estado'] !== 'abierto') {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }

            $mesasTicket = self::fila(
                "SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$ticketId} FOR UPDATE"
            );
            if ((int)($mesasTicket['total'] ?? 0) < 1) {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }
            if ($reservacionId && (!$reservacion || $reservacion['estado'] !== 'en_curso')) {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }
            $metodo = $db->real_escape_string($metodoPago);
            $propinaSql = number_format(max(0, $propina), 2, '.', '');
            if (!$db->query(
                "UPDATE tickets
                 SET estado = 'cerrado', closed_at = COALESCE(closed_at, NOW()),
                     hora_cierre = COALESCE(hora_cierre, NOW()),
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
                     estado_changed_at = NOW()"
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
     * Libera una mesa cuyo ticket no llegó a tener consumo: borra el ticket y
     * suelta las mesas.
     *
     * Cerrar esa cuenta la registraría como una venta de $0 y emitiría token de
     * feedback por una experiencia que no existió, así que el ticket se elimina
     * en lugar de cerrarse. Un ticket con productos NO se puede liberar: para
     * eso está el cobro.
     *
     * Nota: movimientos_inventario.ticket_item_id no tiene FK, así que quedan
     * filas huérfanas. En un ticket sin consumo los únicos ítems posibles son
     * cancelados, cuyos pares venta/cancelacion se anulan, de modo que el stock
     * queda correcto; solo se pierde la traza de auditoría.
     */
    public static function liberarMesa(int $ticketId, int $usuarioId): array
    {
        if ($ticketId < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;

            $ticket = self::fila("SELECT * FROM tickets WHERE id = {$ticketId} FOR UPDATE");
            if (!$ticket) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            // A propósito no es idempotente sobre 'cerrado': borrar un ticket
            // ya pagado destruiría el registro de ingresos.
            if ($ticket['estado'] !== 'abierto') {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }

            $consumo = self::fila(
                "SELECT COUNT(*) AS n FROM ticket_items
                 WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'"
            );
            // Un ticket con solo cancelados sí es liberable: tampoco hay consumo.
            if ((int)($consumo['n'] ?? 0) > 0) {
                return self::rollbackResultado($db, $transaccion, self::TICKET_CON_CONSUMO);
            }

            // Las mesas se leen antes del DELETE: ticket_mesas cae en cascada y
            // el cliente necesita saber cuáles refrescar.
            $mesaIds = [];
            $resMesas = $db->query("SELECT mesa_id FROM ticket_mesas WHERE ticket_id = {$ticketId}");
            if ($resMesas) {
                while ($fila = $resMesas->fetch_assoc()) {
                    $mesaIds[] = (int)$fila['mesa_id'];
                }
                $resMesas->free();
            }

            // Si la reservación se queda en 'en_curso', comenzar() hace
            // short-circuit y devuelve el ticket_id de un ticket ya borrado:
            // la mesa quedaría inservible. Se regresa a 'llego', que es el
            // estado desde el que el personal puede reiniciar el servicio.
            $reservacionId = $ticket['reservacion_id'] !== null ? (int)$ticket['reservacion_id'] : null;
            if ($reservacionId) {
                $reservacion = self::fila(
                    "SELECT * FROM reservaciones WHERE id = {$reservacionId} FOR UPDATE"
                );
                if ($reservacion && $reservacion['estado'] === 'en_curso') {
                    self::actualizarReservacion(
                        $db,
                        $reservacionId,
                        "estado = 'llego',
                         status_changed_at = NOW(),
                         last_modified_by = {$usuarioId},
                         last_modified_source = 'personal',
                         last_change_reason = 'Mesa liberada sin consumo'"
                    );
                }
            }

            // ticket_mesas, ticket_items, ticket_pagos y feedback_tokens caen
            // en cascada por sus FK.
            if (!$db->query("DELETE FROM tickets WHERE id = {$ticketId} LIMIT 1")) {
                throw new \RuntimeException($db->error);
            }
            if ($db->affected_rows !== 1) {
                throw new \RuntimeException('La liberación no borró exactamente un ticket.');
            }

            $db->commit();
            $transaccion = false;

            return ['ok' => true, 'codigo' => self::OK, 'mesa_ids' => $mesaIds];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::liberarMesa - ' . $e->getMessage());
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

    /** @return array<int, int> */
    private static function mesasNoTicketables(\mysqli $db, array $mesaIds): array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        $resultado = $db->query(
            "SELECT id, tipo, nombre, reservable
             FROM mesas
             WHERE id IN ({$ids})
             ORDER BY id
             FOR UPDATE"
        );
        if (!$resultado) {
            throw new \RuntimeException($db->error);
        }

        $invalidas = [];
        while ($mesa = $resultado->fetch_assoc()) {
            $ticketable = (int)$mesa['reservable'] === 1
                || (string)$mesa['tipo'] === 'barra'
                || ((string)$mesa['tipo'] === 'especial' && (string)$mesa['nombre'] !== 'Caja');
            if (!$ticketable) {
                $invalidas[] = (int)$mesa['id'];
            }
        }
        $resultado->free();

        return $invalidas;
    }

    private static function ticketAbiertoEnMesas(\mysqli $db, array $mesaIds): ?array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        return self::fila(
            "SELECT t.id, GROUP_CONCAT(DISTINCT tm.mesa_id ORDER BY tm.mesa_id) AS mesa_ids
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
             WHERE " . TicketMesa::condicionSqlAbierto('t') . "
               AND tm.mesa_id IN ({$ids})
             GROUP BY t.id
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

    /** Invalida reemplazos que ya no pueden convertirse en una reservación operativa. */
    private static function invalidarReemplazosPendientes(\mysqli $db, int $reservacionId): void
    {
        $resultado = $db->query(
            "SELECT id FROM reservaciones
             WHERE reemplaza_reservacion_id = {$reservacionId}
               AND estado = 'pendiente_verificacion'
             ORDER BY id DESC
             FOR UPDATE"
        );
        if ($resultado === false) {
            throw new \RuntimeException($db->error);
        }
        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['id'];
        }
        $resultado->free();
        if ($ids === []) {
            return;
        }
        $lista = implode(',', $ids);
        if (!$db->query(
            "UPDATE reservaciones
             SET estado = 'expirada', hold_expires_at = NULL, estado_changed_at = NOW()
             WHERE id IN ({$lista}) AND estado = 'pendiente_verificacion'"
        )) {
            throw new \RuntimeException($db->error);
        }
        VerificacionContacto::invalidarPorReservaciones($ids);
    }

    /**
     * Devuelve todas las reservaciones futuras que afectan las mesas elegidas.
     * La consulta se ejecuta dentro de la transacción y con las filas
     * bloqueadas para que la confirmación no dependa del estado que vio el JS.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function proximasReservaciones(\mysqli $db, array $mesaIds): array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        $reloj = ReservacionConfig::ahora();
        $ahora = $reloj->format('Y-m-d H:i:s');
        $limite = $reloj
            ->modify('+' . ReservacionConfig::MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA . ' minutes')
            ->format('Y-m-d H:i:s');
        $condicionOcupacion = ReservacionConfig::condicionSqlOcupacionActiva('r');
        $resultado = $db->query(
            "SELECT r.id AS reservacion_id,
                    r.nombre,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    GROUP_CONCAT(DISTINCT rm.mesa_id ORDER BY rm.orden) AS mesa_ids,
                    GROUP_CONCAT(DISTINCT rm_todas.mesa_id ORDER BY rm_todas.orden) AS reservation_mesa_ids
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             INNER JOIN reservacion_mesas rm_todas ON rm_todas.reservacion_id = r.id
             WHERE rm.mesa_id IN ({$ids})
               AND {$condicionOcupacion}
               AND TIMESTAMP(r.fecha, r.hora) > '{$ahora}'
               AND TIMESTAMP(r.fecha, r.hora) <= '{$limite}'
             GROUP BY r.id, r.nombre, r.fecha, r.hora, r.comensales
             ORDER BY r.fecha, r.hora
             FOR UPDATE"
        );
        if (!$resultado) {
            throw new \RuntimeException($db->error);
        }

        $reservaciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $inicio = new DateTimeImmutable(
                (string)$fila['fecha'] . ' ' . (string)$fila['hora'],
                ReservacionConfig::timezone()
            );
            $segundosRestantes = max(0, $inicio->getTimestamp() - $reloj->getTimestamp());
            $reservaciones[] = [
                'reservacion_id' => (int)$fila['reservacion_id'],
                'folio' => '#' . (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'comensales' => (int)$fila['comensales'],
                'mesa_ids' => self::csvIds($fila['mesa_ids']),
                'reservation_mesa_ids' => self::csvIds($fila['reservation_mesa_ids']),
                'minutos_restantes' => (int)ceil($segundosRestantes / 60),
                'bloqueada' => $segundosRestantes
                    <= ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO * 60,
            ];
        }
        $resultado->free();

        return $reservaciones;
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

    private static function meseroActivo(\mysqli $db, ?int $meseroId): bool
    {
        if ($meseroId === null) {
            return true;
        }

        $stmt = $db->prepare(
            "SELECT id FROM usuarios
             WHERE id = ? AND rol = 'waiter' AND activo = 1
             LIMIT 1"
        );
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }
        $stmt->bind_param('i', $meseroId);
        $stmt->execute();
        $stmt->store_result();
        $valido = $stmt->num_rows === 1;
        $stmt->close();

        return $valido;
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
