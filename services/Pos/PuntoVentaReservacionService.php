<?php

/**
 * Orquesta las transiciones transaccionales entre reservaciones, mesas y POS.
 */

namespace Services\Pos;
use Services\Reservations\ReservacionAdministrativaService;
use Services\Reservations\ReservacionMantenimientoService;
use Services\Reservations\ReservacionService;
use Services\Tables\OcupacionMesasService;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\ReservacionVigenciaService;
use Services\Reservations\ReservacionConfig;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\ConfiguracionPos;
use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Model\VerificacionContacto;
use Services\Reservations\HorarioReservacionService;
use Services\Scheduling\HorarioConfigLock;
use Services\Shared\FechaOperacionLock;
use Services\Security\ContextoNegocio;
use Model\Ticket;

final class PuntoVentaReservacionService
{
    private static function unidadSql(string $alias=''): string
    {
        return Ticket::filtroUnidad($alias);
    }

    private static function iniciarTransaccion(\mysqli $db): void
    {
        if (ContextoNegocio::nuevo()) {
            if ((int)$db->query('SELECT @@autocommit')->fetch_row()[0]===0) throw new \DomainException('El coordinador de reservaciones requiere su propia transacción.');
            // MySQL rechaza SET TRANSACTION si ya hay una transacción activa;
            // así no confirmamos implícitamente trabajo de otro coordinador.
            $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $db->begin_transaction();
    }

    private static function autorizar(?int $usuarioId=null): void
    {
        if (!ContextoNegocio::nuevo()) return;
        if (ContextoNegocio::slug()!=='restaurante') throw new \DomainException('El POS solo está habilitado en Restaurante.');
        ContextoNegocio::exigir('pdv','operar','restaurante',true);
        if ($usuarioId!==null && $usuarioId!==ContextoNegocio::actorId()) throw new \DomainException('El actor debe proceder de la sesión autenticada.');
    }
    public const OK = ReservacionMantenimientoService::OK;
    public const DATOS_INVALIDOS = ReservacionService::DATOS_INVALIDOS;
    public const NO_EXISTE = ReservacionService::RESERVACION_NO_EXISTE;
    public const ESTADO_INVALIDO = ReservacionService::ESTADO_INVALIDO;
    public const MESA_OCUPADA = AsignacionMesasService::MESA_OCUPADA;
    public const TOLERANCIA_VIGENTE = 'TOLERANCIA_VIGENTE';
    public const TOLERANCIA_LLEGADA_VENCIDA = 'TOLERANCIA_LLEGADA_VENCIDA';
    public const REQUIERE_CONFIRMACION = 'REQUIERE_CONFIRMACION';
    public const TICKET_ABIERTO = 'TICKET_ABIERTO';
    public const BARRA_LLENA = 'BARRA_LLENA';
    public const TICKET_CON_CONSUMO = 'TICKET_CON_CONSUMO';
    public const REQUIERE_REASIGNACION = 'REQUIERE_REASIGNACION';
    public const SIN_CAPACIDAD = ReservacionAdministrativaService::CAPACIDAD_INSUFICIENTE;
    public const CONFLICTO_CONCURRENTE = AsignacionMesasService::CONFLICTO_CONCURRENTE;
    public const ERROR_INTERNO = ReservacionService::ERROR_INTERNO;
    /**
     * Lista sólo reservaciones operativas; los walk-ins viven exclusivamente
     * como tickets y nunca aparecen aquí.
     */
    public static function listar(string $fecha, string $superficie = 'waiter'): array
    {
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'reservaciones' => []];
        }

        $lectura = PosReservacionQueryService::paraFecha($fecha, '', [
            'incluir_inactivas' => false,
            'calcular_conflictos' => true,
            'superficie' => $superficie,
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
            self::autorizar($usuarioId);
            $previa = self::fila("SELECT fecha FROM reservaciones WHERE id = {$reservacionId}" . self::unidadSql());
            if (!$previa) {
                return ['ok' => false, 'codigo' => self::NO_EXISTE];
            }
            $lockFecha = (string)$previa['fecha'];
            if (!FechaOperacionLock::adquirir($db, $lockFecha, 10)) {
                throw new \RuntimeException('No fue posible bloquear la fecha operativa.');
            }
            self::iniciarTransaccion($db);
            $transaccion = true;
            self::autorizar();

            $r = Reservacion::buscarFilaPorIdParaActualizar($reservacionId);
            if (!$r) {
                return self::rollbackResultado($db, $transaccion, self::NO_EXISTE);
            }
            if ($r['estado'] === 'en_curso') {
                $ticket = self::fila(
                    "SELECT t.id FROM tickets t
                     WHERE t.reservacion_id = {$reservacionId}" . self::unidadSql('t') . "
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
            $politica = ReservacionPoliticaPosService::evaluar($r);
            if (!empty($politica['ausencia_pendiente'])) {
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::TOLERANCIA_LLEGADA_VENCIDA,
                    [
                        'motivo_bloqueo' => self::TOLERANCIA_LLEGADA_VENCIDA,
                        'accion_pendiente' => 'REGISTRAR_AUSENCIA',
                    ]
                );
            }
            if (!$politica['puede_iniciar_reservacion']) {
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
                false
            );
            if (AsignacionMesasService::hayConflictoHorario($ocupacion, $mesaIds)) {
                $mesasContrato = array_map(
                    static fn($mesa): array => PosReservacionSerializer::mesa($mesa),
                    Mesa::buscarTodasParaMapa()
                );
                $mesasBloqueantes = PosReservacionSerializer::bloqueosOperativos(
                    $ocupacion,
                    $mesaIds,
                    $mesasContrato,
                    $reservacionId
                );
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::MESA_OCUPADA,
                    [
                        'motivo_bloqueo' => 'MESAS_ASIGNADAS_NO_DISPONIBLES',
                        'mesas_bloqueantes' => $mesasBloqueantes,
                    ]
                );
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
        $motivo = trim($motivo);
        if (strlen($motivo) > 500) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }
        $resultado = self::mutarReservacion($reservacionId, function (array $r, \mysqli $db) use ($motivo): array {
            if ($r['estado'] === 'cancelada') {
                return ['ok' => true, 'codigo' => self::OK, 'idempotente' => true];
            }
            if ($r['estado'] !== 'confirmada') {
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }
            if (self::ticketAbiertoReservacion($db, (int)$r['id'])) {
                return ['ok' => false, 'codigo' => self::TICKET_ABIERTO];
            }
            if ($motivo === '') {
                return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
            }

            // El motivo se pedía y se tiraba: ahora se guarda igual que en el
            // panel, que es donde se consulta después.
            $motivoSql = ActiveRecord::escaparString($motivo);
            self::actualizarReservacion(
                $db,
                (int)$r['id'],
                "estado = 'cancelada',
                 motivo_cancelacion = '{$motivoSql}',
                 estado_changed_at = NOW()"
            );
            self::invalidarReemplazosPendientes($db, (int)$r['id']);

            return ['ok' => true, 'codigo' => self::OK, 'idempotente' => false];
        });
        if (($resultado['ok'] ?? false) && empty($resultado['idempotente'])) {
            // Fuera de la transacción, como el camino del panel: una reserva
            // cancelada deja de contar en las afectaciones por cambio de horario.
            \Services\Scheduling\HorarioOperacionImpactoService::reconciliarReservacion($reservacionId, $usuarioId ?: null);
        }

        return $resultado;
    }

    /**
     * Abre un ticket walk-in. Una reservación próxima genera advertencia, no
     * una reservación artificial ni un bloqueo automático.
     */
    public static function abrirWalkIn(array $datos, int $usuarioId): array
    {
        self::autorizar($usuarioId);
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
            self::iniciarTransaccion($db);
            $transaccion = true;
            self::autorizar();
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
            // Llevar y las barras admiten varios tickets a la vez sobre la misma
            // fila de `mesas`: Llevar sin tope, la barra hasta el de
            // Configuración → POS. La excepción la decide la mesa y no el
            // cliente — antes bastaba con mandar `allow_multiple` para abrir un
            // segundo ticket sobre cualquier mesa del salón. Ninguno de los dos
            // se une a otras mesas: no ocupan lugar en el salón.
            $multiTicket = self::elementoMultiTicket($db, $mesaIds);
            if ($multiTicket !== null && count($mesaIds) > 1) {
                return self::rollbackResultado($db, $transaccion, self::DATOS_INVALIDOS);
            }
            if ($multiTicket !== null && $multiTicket['tope'] !== null) {
                // Los locks de fecha del día ya serializan las aperturas; el
                // FOR UPDATE de ticketsAbiertosEnMesa() es la revalidación.
                $abiertos = self::ticketsAbiertosEnMesa($db, $multiTicket['mesa_id']);
                if ($abiertos >= $multiTicket['tope']) {
                    return self::rollbackResultado(
                        $db,
                        $transaccion,
                        self::BARRA_LLENA,
                        [
                            'mesa_id' => $multiTicket['mesa_id'],
                            'nombre' => $multiTicket['nombre'],
                            'tickets_abiertos' => $abiertos,
                            'tope_tickets' => $multiTicket['tope'],
                        ]
                    );
                }
            }
            if ($multiTicket === null) {
                $conflicto = self::ticketAbiertoEnMesas($db, $mesaIds);
                if ($conflicto !== null) {
                    return self::rollbackResultado(
                        $db,
                        $transaccion,
                        self::TICKET_ABIERTO,
                        [
                            'ticket_id' => (int)($conflicto['id'] ?? 0),
                            'mesas_conflicto' => self::csvIds($conflicto['mesa_ids'] ?? '')
                        ]
                    );
                }
            }

            $ocupacionCanonica = OcupacionMesasService::evaluarHorario(
                ReservacionConfig::fechaActual(),
                ReservacionConfig::horaActual(),
                0,
                true
            );
            $mesasBloqueantes = (array)($ocupacionCanonica['ocupacion_bloqueante'] ?? []);
            $bloqueoHold = [];
            foreach ($mesaIds as $mesaId) {
                $estadoMesa = $mesasBloqueantes[$mesaId] ?? null;
                if (is_array($estadoMesa) && ($estadoMesa['fuente'] ?? '') === 'hold') {
                    $bloqueoHold[] = $mesaId;
                }
            }
            if ($bloqueoHold !== []) {
                return self::rollbackResultado(
                    $db,
                    $transaccion,
                    self::MESA_OCUPADA,
                    ['mesas_conflicto' => $bloqueoHold, 'fuente' => 'hold']
                );
            }

            $politicasReservaciones = self::politicasReservacionesMesa($db, $mesaIds);
            $warnings = [];
            foreach ($politicasReservaciones as $candidate) {
                if (!empty($candidate['ausencia_pendiente'])) {
                    return self::rollbackResultado(
                        $db,
                        $transaccion,
                        self::TOLERANCIA_LLEGADA_VENCIDA,
                        [
                            'motivo_bloqueo' => self::TOLERANCIA_LLEGADA_VENCIDA,
                            'accion_pendiente' => ReservacionPoliticaPosService::ACCION_REGISTRAR_AUSENCIA,
                            'reservacion' => $candidate,
                        ]
                    );
                }
                if (!empty($candidate['bloqueo_walk_in'])) {
                    return self::rollbackResultado(
                        $db,
                        $transaccion,
                        self::MESA_OCUPADA,
                        ['bloqueo' => $candidate, 'bloqueos' => $politicasReservaciones]
                    );
                }
                if (!empty($candidate['requiere_advertencia_ticket'])) {
                    $warnings[] = $candidate;
                }
            }
            $warning = $warnings[0] ?? null;
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
        int $usuarioId,
        ?int $totalEsperadoCentavos=null
    ): array {
        self::autorizar($usuarioId);
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = null;
        $transaccion = false;
        try {
            $previo = self::fila(
                "SELECT t.reservacion_id, t.hora_apertura, r.fecha AS reservacion_fecha
                 FROM tickets t
                 LEFT JOIN reservaciones r ON r.id = t.reservacion_id
                 WHERE t.id = {$ticketId}" . self::unidadSql('t') . "
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
            self::iniciarTransaccion($db);
            $transaccion = true;
            self::autorizar();
            $reservacionId = $previo['reservacion_id'] !== null ? (int)$previo['reservacion_id'] : null;
            $reservacion = null;
            if ($reservacionId) {
                $reservacion = Reservacion::buscarFilaPorIdParaActualizar($reservacionId);
                if (!$reservacion) {
                    return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
                }
            }
            $ticket = self::fila("SELECT * FROM tickets WHERE id = {$ticketId}" . self::unidadSql() . " FOR UPDATE");
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

            if (ContextoNegocio::nuevo()) {
                self::autorizar($usuarioId);
                if (!in_array($metodoPago,['efectivo','tarjeta','dividido'],true) || !is_finite($propina) || $propina<0) {
                    return self::rollbackResultado($db,$transaccion,self::DATOS_INVALIDOS);
                }
                $filas=$db->query("SELECT precio,cantidad,estado FROM ticket_items WHERE ticket_id={$ticketId}".self::unidadSql().' ORDER BY id FOR UPDATE');
                $totalActualCentavos=0;
                while ($linea=$filas->fetch_assoc()) {
                    if (!in_array($linea['estado'],['entregado','cancelado'],true)) return self::rollbackResultado($db,$transaccion,'TICKET_ITEMS_PENDIENTES');
                    if ($linea['estado']==='cancelado') continue;
                    $totalActualCentavos+=(int)str_replace('.','',(string)$linea['precio'])*(int)$linea['cantidad'];
                }
                if ($totalEsperadoCentavos!==null && $totalEsperadoCentavos!==$totalActualCentavos) {
                    return self::rollbackResultado($db,$transaccion,'TICKET_TOTAL_CAMBIO');
                }
                if ($metodoPago==='dividido') {
                    $suma=0;
                    foreach($pagos as $pago) {
                        if (!is_numeric($pago['monto']??null)) return self::rollbackResultado($db,$transaccion,'PAGO_INVALIDO');
                        $suma+=(int)round((float)$pago['monto']*100);
                    }
                    if ($pagos===[] || $suma<$totalActualCentavos-1) return self::rollbackResultado($db,$transaccion,'PAGO_INSUFICIENTE');
                } elseif ($pagos!==[]) return self::rollbackResultado($db,$transaccion,'PAGO_INVALIDO');
            }

            $mesasTicket = self::fila(
                "SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$ticketId}" . self::unidadSql() . " FOR UPDATE"
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
                 WHERE id = {$ticketId}" . self::unidadSql()
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
                    "INSERT INTO ticket_pagos (" . (ContextoNegocio::nuevo()?'unidad_id,':'') . "ticket_id, comensal, metodo_pago, monto)
                     VALUES (" . (ContextoNegocio::nuevo()?Ticket::unidadRestaurante().',':'') . "{$ticketId}, {$comensal}, '{$metodoFila}', {$montoSql})"
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
     * Descarta un ticket abierto que nunca registró consumo: el cliente se
     * sentó y se fue sin pedir nada.
     *
     * No cierra el ticket, lo BORRA. Un ticket cerrado en cero seguiría
     * contando como cuenta del día en el corte de caja, en las analíticas y en
     * el histórico de la mesa, y arrastraría un token de feedback para una
     * visita que no existió. La ocupación vive en `ticket_mesas`, que cae sola
     * por ON DELETE CASCADE, así que borrar la fila libera la mesa.
     *
     * La condición es `ticket_items` COMPLETAMENTE vacía, no "sin items
     * activos": un item cancelado ya movió inventario y dejó rastro en el
     * tablero del área, y ese rastro no se tira. Ahí el camino es el cierre
     * normal.
     *
     * Si el ticket venía de una reservación, la devuelve a `confirmada` para
     * deshacer lo que hizo comenzar(). Lo único que no se revierte es
     * invalidarReemplazosPendientes(): esos reemplazos ya se notificaron.
     */
    public static function descartarTicketVacio(int $ticketId, int $usuarioId): array
    {
        self::autorizar($usuarioId);
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = null;
        $transaccion = false;
        try {
            $previo = self::fila(
                "SELECT t.reservacion_id, t.hora_apertura, r.fecha AS reservacion_fecha
                 FROM tickets t
                 LEFT JOIN reservaciones r ON r.id = t.reservacion_id
                 WHERE t.id = {$ticketId}" . self::unidadSql('t') . "
                 LIMIT 1"
            );
            if (!$previo) {
                // NO_EXISTE resuelve a "reservación no encontrada": aquí el mesero
                // pulsó el botón de un ticket, y ese mensaje lo mandaría a buscar
                // una reservación que nunca existió.
                return ['ok' => false, 'codigo' => 'TICKET_NO_VALIDO'];
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
            self::iniciarTransaccion($db);
            $transaccion = true;
            self::autorizar();

            $ticket = self::fila("SELECT * FROM tickets WHERE id = {$ticketId}" . self::unidadSql() . " FOR UPDATE");
            if (!$ticket) {
                return self::rollbackResultado($db, $transaccion, 'TICKET_NO_VALIDO');
            }
            // Un ticket ya cobrado o ya descartado no se toca: el cierre es el
            // único camino cuando hubo cuenta.
            if ($ticket['estado'] !== 'abierto') {
                return self::rollbackResultado($db, $transaccion, self::ESTADO_INVALIDO);
            }

            // FOR UPDATE sobre los items: sin él, una comanda enviada desde
            // otra tablet entre esta cuenta y el DELETE se perdería con el
            // ticket, y el área seguiría preparando platillos ya borrados.
            $items = self::fila(
                "SELECT COUNT(*) AS total FROM ticket_items WHERE ticket_id = {$ticketId}" . self::unidadSql() . " FOR UPDATE"
            );
            if ((int)($items['total'] ?? 0) > 0) {
                return self::rollbackResultado($db, $transaccion, self::TICKET_CON_CONSUMO);
            }

            $reservacionId = $previo['reservacion_id'] !== null ? (int)$previo['reservacion_id'] : null;
            if ($reservacionId) {
                $reservacion = Reservacion::buscarFilaPorIdParaActualizar($reservacionId);
                if ($reservacion && $reservacion['estado'] === 'en_curso') {
                    self::actualizarReservacion(
                        $db,
                        $reservacionId,
                        "estado = 'confirmada',
                         estado_changed_at = NOW()"
                    );
                }
            }

            // ticket_mesas, ticket_items y feedback_tokens caen por CASCADE.
            if (ContextoNegocio::nuevo()) {
                $db->query("DELETE FROM ticket_mesas WHERE ticket_id = {$ticketId}" . self::unidadSql());
                // Los registros de idempotencia son evidencia comercial; no borrar
                // tickets que ya tengan una operación registrada aunque estén vacíos.
                $operada=self::fila("SELECT id FROM pos_operaciones WHERE ticket_id={$ticketId}" . self::unidadSql() . " LIMIT 1 FOR UPDATE");
                if ($operada) return self::rollbackResultado($db,$transaccion,self::TICKET_CON_CONSUMO);
            }
            if (!$db->query("DELETE FROM tickets WHERE id = {$ticketId}" . self::unidadSql())) {
                throw new \RuntimeException($db->error);
            }

            $db->commit();
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::OK,
                'reservacion_id' => $reservacionId,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('PuntoVentaReservacionService::descartarTicketVacio - ' . $e->getMessage());
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
    /**
     * Contexto informativo de una mesa. La mutación posterior vuelve a tomar
     * locks y recalcular todo el estado.
     */
    public static function contextoMesa(int $mesaId, string $superficie = 'waiter'): array
    {
        if ($mesaId < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }
        $db = ActiveRecord::getDB();
        $ticket = self::fila(
            "SELECT t.id, t.nombre, t.comensales, t.hora_apertura, t.reservacion_id,
                    t.estado, t.closed_at,
                    tm.mesa_id
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.mesa_id = {$mesaId}
             WHERE " . TicketMesa::condicionSqlAbierto('t') . self::unidadSql('t') . self::unidadSql('tm') . "
             ORDER BY t.id LIMIT 1"
        );
        $ahora = ReservacionConfig::ahora();
        $hasta = $ahora
            ->modify('+' . ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS . ' minutes')
            ->format('Y-m-d H:i:s');
        $fechaActual = $ahora->format('Y-m-d');
        $reservas = [];
        $resultado = $db->query(
            "SELECT r.id, r.nombre, r.fecha, r.hora, r.comensales, r.estado
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             WHERE rm.mesa_id = {$mesaId}" . self::unidadSql('r') . self::unidadSql('rm') . "
               AND r.estado = 'confirmada'
               AND r.fecha = '{$fechaActual}'
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
            $ticketDeReserva = $ticket
                && (int)($ticket['reservacion_id'] ?? 0) === (int)$reserva['id']
                ? $ticket
                : null;
            $politica = ReservacionPoliticaPosService::evaluar($reserva, $ahora, $ticketDeReserva);
            $contexto = array_merge(self::reservaContexto($reserva), $politica);
            if ($inicio <= $ahora) {
                $actual = $contexto;
            } elseif ($inicio > $ahora && $proxima === null) {
                $proxima = $contexto;
            }
        }

        $liberacionEstimada = null;
        $horaObjetivoPreparacion = null;
        if ($ticket) {
            $liberacionEstimada = TicketTemporalService::calcularLiberacionEstimadaTicket(
                $ticket['hora_apertura'] ?? null
            )?->format('Y-m-d H:i:s');
        }
        $advertenciaCodigo = null;
        $advertenciaContexto = [];
        if ($ticket && $proxima) {
            $horaObjetivoPreparacion = (new DateTimeImmutable(
                $proxima['fecha'] . ' ' . $proxima['hora'],
                ReservacionConfig::timezone()
            ))->modify('-' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes')
                ->format('Y-m-d H:i:s');
            $advertenciaCodigo = 'RESERVACION_PROXIMA';
            $advertenciaContexto = [
                'hora' => $proxima['hora'],
                'hora_objetivo' => substr($horaObjetivoPreparacion, 11, 5),
                'minutos_restantes' => $proxima['minutos_restantes'],
            ];
        }

        $politicaMesa = $actual ?? $proxima;
        $puedeAbrirTicket = !$ticket
            && ($politicaMesa === null || (bool)($politicaMesa['disponible_para_ticket'] ?? false));
        $accionPrimaria = $ticket
            ? ReservacionPoliticaPosService::ACCION_CONSULTAR_TICKET
            : (string)($politicaMesa['accion_primaria'] ?? ReservacionPoliticaPosService::ACCION_ABRIR_TICKET);

        $respuesta = [
            'ok' => true,
            'codigo' => self::OK,
            'ticket_abierto' => $ticket ? [
                'id' => (int)$ticket['id'],
                'reservacion_id' => $ticket['reservacion_id'] !== null ? (int)$ticket['reservacion_id'] : null,
            ] : null,
            'reservacion_actual' => $actual,
            'reservacion_proxima' => $proxima,
            'acciones' => [
                'puede_abrir_ticket' => $puedeAbrirTicket,
                'puede_cerrar_ticket' => $ticket !== null,
            ],
            'puede_abrir_ticket' => $puedeAbrirTicket,
            'puede_iniciar_reservacion' => (bool)($politicaMesa['puede_iniciar_reservacion'] ?? false),
            'puede_marcar_no_show' => (bool)($politicaMesa['puede_marcar_no_show'] ?? false),
            'accion_primaria' => $accionPrimaria,
            'advertencia_codigo' => $advertenciaCodigo,
            'advertencia_contexto' => $advertenciaContexto,
            'liberacion_estimada' => $liberacionEstimada,
            'hora_objetivo_preparacion' => $horaObjetivoPreparacion,
        ];

        return strtolower($superficie) === 'waiter'
            ? PosReservacionSerializer::sanitizarParaWaiter($respuesta)
            : $respuesta;
    }

    private static function mutarReservacion(int $id, callable $operacion): array
    {
        if ($id < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            self::iniciarTransaccion($db);
            $transaccion = true;
            self::autorizar();
            $r = Reservacion::buscarFilaPorIdParaActualizar($id);
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
                (" . (ContextoNegocio::nuevo()?'unidad_id,':'') . "comensales, nombre, estado, reservacion_id, mesero_id)
             VALUES (" . (ContextoNegocio::nuevo()?Ticket::unidadRestaurante().',':'') . "?, NULLIF(?, ''), 'abierto', ?, ?)"
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
            'SELECT id FROM mesas WHERE activo = 1 AND id IN (' . implode(',', $mesaIds) . ')' . self::unidadSql() . ' ORDER BY id FOR UPDATE'
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
             WHERE id IN ({$ids})" . self::unidadSql() . "
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

    /**
     * La pieza multi-ticket de la selección, si la hay. La barra se reconoce
     * por tipo; Llevar, por nombre, igual que la Caja en mesasNoTicketables():
     * las dos son `especial` y el nombre es lo único que las distingue.
     *
     * @return array{mesa_id:int, nombre:string, tipo:string, tope:?int}|null
     */
    private static function elementoMultiTicket(\mysqli $db, array $mesaIds): ?array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        $fila = self::fila(
            "SELECT id, nombre, tipo FROM mesas
             WHERE id IN ({$ids})" . self::unidadSql() . "
               AND (tipo = 'barra' OR (tipo = 'especial' AND nombre = 'Llevar'))
             ORDER BY id
             LIMIT 1"
        );
        if ($fila === null) {
            return null;
        }
        $esBarra = (string)$fila['tipo'] === 'barra';

        return [
            'mesa_id' => (int)$fila['id'],
            'nombre' => (string)$fila['nombre'],
            'tipo' => $esBarra ? 'barra' : 'llevar',
            'tope' => $esBarra ? ConfiguracionPos::maxTicketsBarra() : null,
        ];
    }

    private static function ticketsAbiertosEnMesa(\mysqli $db, int $mesaId): int
    {
        $resultado = $db->query(
            "SELECT t.id
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
             WHERE " . TicketMesa::condicionSqlAbierto('t') . self::unidadSql('t') . self::unidadSql('tm') . "
               AND tm.mesa_id = {$mesaId}
             FOR UPDATE"
        );
        if ($resultado === false) {
            throw new \RuntimeException($db->error);
        }
        $abiertos = $resultado->num_rows;
        $resultado->free();

        return $abiertos;
    }

    private static function ticketAbiertoEnMesas(\mysqli $db, array $mesaIds): ?array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        return self::fila(
            "SELECT t.id, GROUP_CONCAT(DISTINCT tm.mesa_id ORDER BY tm.mesa_id) AS mesa_ids
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
             WHERE " . TicketMesa::condicionSqlAbierto('t') . self::unidadSql('t') . self::unidadSql('tm') . "
               AND tm.mesa_id IN ({$ids})
             GROUP BY t.id
             LIMIT 1 FOR UPDATE"
        );
    }

    private static function ticketAbiertoReservacion(\mysqli $db, int $reservacionId): bool
    {
        return self::fila(
            "SELECT t.id FROM tickets t
             WHERE t.reservacion_id = {$reservacionId}" . self::unidadSql('t') . "
               AND " . TicketMesa::condicionSqlAbierto('t') . "
             LIMIT 1 FOR UPDATE"
        ) !== null;
    }

    /** Invalida reemplazos que ya no pueden convertirse en una reservación operativa. */
    private static function invalidarReemplazosPendientes(\mysqli $db, int $reservacionId): void
    {
        $resultado = $db->query(
            "SELECT id FROM reservaciones
             WHERE reemplaza_reservacion_id = {$reservacionId}" . self::unidadSql() . "
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
             WHERE id IN ({$lista}) AND estado = 'pendiente_verificacion'" . self::unidadSql()
        )) {
            throw new \RuntimeException($db->error);
        }
        VerificacionContacto::invalidarPorReservaciones($ids);
    }

    /**
     * Devuelve las reservaciones de hoy que pueden afectar un walk-in,
     * incluyendo el inicio exacto y las ausencias pendientes. La consulta y la
     * decisión se ejecutan bajo la misma transacción para revalidar antes del
     * commit y evitar que el cliente salte la política por API directa.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function politicasReservacionesMesa(\mysqli $db, array $mesaIds): array
    {
        $ids = implode(',', array_map('intval', $mesaIds));
        $reloj = ReservacionConfig::ahora();
        $ahora = $reloj->format('Y-m-d H:i:s');
        $fecha = $reloj->format('Y-m-d');
        $limite = $reloj
            ->modify('+' . ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS . ' minutes')
            ->format('Y-m-d H:i:s');
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
             WHERE rm.mesa_id IN ({$ids})" . self::unidadSql('r') . self::unidadSql('rm') . self::unidadSql('rm_todas') . "
               AND r.estado = 'confirmada'
               AND r.fecha = '{$fecha}'
               AND NOT EXISTS (
                    SELECT 1 FROM tickets vigencia_ticket
                    WHERE vigencia_ticket.reservacion_id = r.id" . self::unidadSql('vigencia_ticket') . "
                      AND " . TicketMesa::condicionSqlAbierto('vigencia_ticket') . "
               )
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
            $hora = substr((string)$fila['hora'], 0, 5);
            $datos = [
                'id' => (int)$fila['reservacion_id'],
                'estado' => 'confirmada',
                'fecha' => (string)$fila['fecha'],
                'hora' => (string)$fila['hora'],
                'nombre' => (string)$fila['nombre'],
                'comensales' => (int)$fila['comensales'],
                'ticket_abierto' => false,
            ];
            $politica = ReservacionPoliticaPosService::evaluar($datos, $reloj);
            $reservaciones[] = array_merge($datos, $politica, [
                'codigo' => 'RESERVACION_PROXIMA',
                'contexto' => [
                    'hora' => $hora,
                    'minutos_restantes' => $politica['minutos_para_inicio'],
                ],
                'folio' => '#' . (int)$fila['reservacion_id'],
                'hora' => $hora,
                'mesa_ids' => self::csvIds($fila['mesa_ids']),
                'reservation_mesa_ids' => self::csvIds($fila['reservation_mesa_ids']),
                'reservacion_id' => (int)$fila['reservacion_id'],
                'minutos_restantes' => $politica['minutos_para_inicio'],
            ]);
        }
        $resultado->free();

        return $reservaciones;
    }

    private static function tokenFeedback(\mysqli $db, int $ticketId): string
    {
        $existente = self::fila(
            "SELECT token FROM feedback_tokens WHERE ticket_id = {$ticketId}" . self::unidadSql() . " LIMIT 1 FOR UPDATE"
        );
        if ($existente) {
            return (string)$existente['token'];
        }
        $token = bin2hex(random_bytes(16));
        if (!$db->query(
            "INSERT INTO feedback_tokens (" . (ContextoNegocio::nuevo()?'unidad_id,':'') . "ticket_id, token) VALUES (" . (ContextoNegocio::nuevo()?Ticket::unidadRestaurante().',':'') . "{$ticketId}, '{$token}')"
        )) {
            throw new \RuntimeException($db->error);
        }

        return $token;
    }

    private static function actualizarReservacion(\mysqli $db, int $id, string $set): void
    {
        if (!$db->query("UPDATE reservaciones SET {$set} WHERE id = {$id}" . self::unidadSql())) {
            throw new \RuntimeException($db->error);
        }
    }

    private static function meseroActivo(\mysqli $db, ?int $meseroId): bool
    {
        if ($meseroId === null) {
            return true;
        }

        if (ContextoNegocio::nuevo()) {
            $perfil=\Services\Security\PermisosUsuario::cargar($meseroId,$db,true);
            return \Services\Security\PermisosUsuario::permitido($perfil,'restaurante','pdv','operar');
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
