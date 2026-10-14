<?php

/**
 * Coordina la asignacion manual y automatica de mesas.
 * Protege las operaciones con transacciones y bloqueos de registros.
 */

namespace Services\Reservations;

use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Services\Tables\OcupacionMesasService;

class AsignacionMesasService
{
    public const ASIGNACION_GUARDADA = 'ASIGNACION_GUARDADA';
    public const SIN_CAPACIDAD = ReservacionAdministrativaService::CAPACIDAD_INSUFICIENTE;
    public const MESA_OCUPADA = 'MESA_OCUPADA';
    public const ESTADO_INVALIDO = ReservacionService::ESTADO_INVALIDO;
    public const RESERVACION_NO_EXISTE = ReservacionService::RESERVACION_NO_EXISTE;
    public const ASIGNACION_VACIA = 'ASIGNACION_VACIA';
    public const MESAS_INVALIDAS = 'MESAS_INVALIDAS';
    public const CAPACIDAD_INSUFICIENTE = ReservacionAdministrativaService::CAPACIDAD_INSUFICIENTE;
    public const CONFLICTO_TICKETS_ABIERTOS = 'CONFLICTO_TICKETS_ABIERTOS';
    public const CONFLICTO_CONCURRENTE = 'CONFLICTO_CONCURRENTE';
    public const VERSION_DESACTUALIZADA = 'VERSION_DESACTUALIZADA';
    public const DATOS_INCOMPLETOS = 'DATOS_INCOMPLETOS';
    public const RESERVACION_NO_EDITABLE = 'RESERVACION_NO_EDITABLE';
    public const SUPERPOSICION_NO_AUTORIZADA = 'SUPERPOSICION_NO_AUTORIZADA';
    public const AGRUPACION_NO_AUTORIZADA = 'AGRUPACION_NO_AUTORIZADA';
    public const CONFLICTO_TICKET_ABIERTO = 'CONFLICTO_TICKET_ABIERTO';
    public const DEPENDE_LIBERACION_PROYECTADA = 'DEPENDE_LIBERACION_PROYECTADA';
    public const SIN_CONTACTO = ReservacionAdministrativaService::SIN_CONTACTO;
    public const LIBERAR_ASIGNACION_ACTUAL = 'LIBERAR_ASIGNACION_ACTUAL';
    public const LIBERACION_NO_AUTORIZADA = 'LIBERACION_NO_AUTORIZADA';
    // Dar el bloque exterior a mano mueve a otras reservas: se confirma antes.
    public const REUBICAR_RESERVACIONES = 'REUBICAR_RESERVACIONES';
    public const ERROR_INTERNO = ReservacionService::ERROR_INTERNO;

    private const TIPO_AUTOMATICA_GENERAL = 'general';
    private const TIPO_AUTOMATICA_PUBLICA = 'publica';

    public static function asignarManual(
        int $reservacionId,
        array $mesaIds,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true,
        array $opciones = []
    ): array
    {
        $mesaIds = self::normalizarMesaIds($mesaIds);

        if (empty($mesaIds)) {
            return ['ok' => false, 'codigo' => self::ASIGNACION_VACIA];
        }

        return self::asignar(
            $reservacionId,
            $mesaIds,
            'manual',
            $permitirCapacidadInsuficiente,
            $gestionarTransaccion,
            $opciones
        );
    }

    public static function asignarAutomaticamente(int $reservacionId, bool $gestionarTransaccion = true): array
    {
        return self::asignar($reservacionId, [], self::TIPO_AUTOMATICA_GENERAL, false, $gestionarTransaccion);
    }

    /**
     * Reasigna como la automática, pero sin contar con ninguna mesa que tenga
     * un ticket abierto en este momento. Para la reserva cuya mesa sigue
     * ocupada cuando ya está por llegar (ReservacionReubicacionPorTicketService).
     */
    public static function reasignarEvitandoTicketsAbiertos(int $reservacionId, bool $gestionarTransaccion = true): array
    {
        return self::asignar($reservacionId, [], self::TIPO_AUTOMATICA_GENERAL, false, $gestionarTransaccion, [
            'evitar_tickets_abiertos' => true,
        ]);
    }

    public static function asignarAutomaticamentePublica(int $reservacionId, bool $gestionarTransaccion = true): array
    {
        return self::asignar($reservacionId, [], self::TIPO_AUTOMATICA_PUBLICA, false, $gestionarTransaccion);
    }

    public static function obtenerOcupacionParaHorario(
        string $fecha,
        string $hora,
        int $excluirReservacionId = 0,
        bool $bloquear = false
    ): array {
        return (array)(OcupacionMesasService::evaluarHorario(
            $fecha,
            $hora,
            $excluirReservacionId,
            $bloquear
        )['ocupacion_bloqueante'] ?? []);
    }

    public static function obtenerOcupacionPorReservacionDelDia(
        string $fecha,
        array $reservaciones,
        ?array $ticketsAbiertos = null
    ): array
    {
        $tickets = $ticketsAbiertos ?? TicketMesa::abiertosParaMapa();
        $contextoFecha = OcupacionMesasService::prepararContextoFecha(
            $fecha,
            false,
            $tickets
        );
        $ocupacion = [];

        foreach ($reservaciones as $reservacion) {
            $reservacionId = (int)($reservacion->id ?? 0);
            $hora = (string)($reservacion->hora ?? '');
            $ocupacion[$reservacionId] = (array)(OcupacionMesasService::evaluarHorarioConContexto(
                $contextoFecha,
                $hora,
                $reservacionId
            )['ocupacion_bloqueante'] ?? []);
        }

        return $ocupacion;
    }

    public static function validarCapacidad(array $mesas, array $mesaIds, int $comensales): bool
    {
        return self::capacidadTotal($mesas, $mesaIds) >= $comensales;
    }

    /**
     * Valida la agrupación pública sin seleccionar ni mutar mesas: una mesa,
     * una pareja canónica o el bloque exterior completo, según el tamaño.
     */
    public static function agrupacionPublicaValida(array $mesas, int $comensales): bool
    {
        return OcupacionMesasService::agrupacionValida($mesas, $comensales)
            && AsignacionPlanificadorService::esAgrupacionCanonica(
                $mesas,
                $comensales,
                Mesa::buscarTodasParaMapa()
            );
    }

    public static function hayConflictoHorario(array $ocupacion, array $mesaIds): bool
    {
        foreach (self::normalizarMesaIds($mesaIds) as $mesaId) {
            if (!empty($ocupacion[$mesaId])) {
                return true;
            }
        }

        return false;
    }

    public static function capacidadTotal(array $mesas, array $mesaIds = []): int
    {
        $mesaIds = self::normalizarMesaIds($mesaIds);
        $filtrar = !empty($mesaIds);
        $porId = array_fill_keys($mesaIds, true);

        return array_reduce($mesas, static function (int $total, $mesa) use ($filtrar, $porId): int {
            $mesaId = (int)($mesa->id ?? 0);

            if ($filtrar && !isset($porId[$mesaId])) {
                return $total;
            }

            return $total + (int)($mesa->capacidad ?? 0);
        }, 0);
    }

    private static function asignar(
        int $reservacionId,
        array $mesaIds,
        string $tipoAsignacion,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true,
        array $opciones = []
    ): array {
        $automatico = in_array($tipoAsignacion, [self::TIPO_AUTOMATICA_GENERAL, self::TIPO_AUTOMATICA_PUBLICA], true);

        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();

        try {
            if ($gestionarTransaccion && !$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transaccion de asignacion.');
            }

            $reservacion = self::fila(
                "SELECT id, fecha, hora, comensales, estado, origen,
                        contacto_tipo, contacto,
                        created_at, updated_at
                 FROM reservaciones
                 WHERE id = {$reservacionId}
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$reservacion) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
            }

            $modoMapaAdministrativo = !empty($opciones['modo_administrativo_mapa']);
            if ($modoMapaAdministrativo) {
                $ticketVinculado = self::fila(
                    "SELECT id FROM tickets WHERE reservacion_id = {$reservacionId} AND "
                    . TicketMesa::condicionSqlAbierto('tickets') . " LIMIT 1 FOR UPDATE"
                );
                if ($ticketVinculado) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::RESERVACION_NO_EDITABLE];
                }
            }
            $codigoNoEditable = ReservacionService::codigoNoEditable($reservacion);
            if ($codigoNoEditable !== '') {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => in_array($codigoNoEditable, [
                        ReservacionService::RESERVACION_PASADA,
                        ReservacionService::RESERVACION_HORARIO_PASADO,
                    ], true) ? $codigoNoEditable : self::RESERVACION_NO_EDITABLE,
                ];
            }
            if ($modoMapaAdministrativo && (string)$reservacion['estado'] !== 'confirmada') {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }

            $mesas = $automatico
                ? Mesa::reservablesParaActualizar()
                : Mesa::reservablesParaActualizar($mesaIds);

            if (!$automatico && count($mesas) !== count($mesaIds)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::MESAS_INVALIDAS];
            }

            $versionEsperada = trim((string)($opciones['version_esperada'] ?? ''));
            $asignacionActualIds = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
            $validarContexto = !empty($opciones['validar_contexto']);
            if ($validarContexto && empty($opciones['contexto_completo'])) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::DATOS_INCOMPLETOS];
            }

            $versionActual = ReservacionAsignacionVersionService::calcular(
                (string)($reservacion['updated_at'] ?: $reservacion['created_at']),
                $asignacionActualIds
            );
            if ($validarContexto) {
                $fechaEsperada = trim((string)($opciones['fecha_esperada'] ?? ''));
                $horaEsperada = HorarioReservacionService::normalizarHoraSql(
                    (string)($opciones['hora_esperada'] ?? '')
                );
                $mesasActualesEsperadas = self::normalizarMesaIds(
                    (array)($opciones['mesa_ids_actuales'] ?? [])
                );
                $mesasActualesComparables = $asignacionActualIds;
                sort($mesasActualesEsperadas, SORT_NUMERIC);
                sort($mesasActualesComparables, SORT_NUMERIC);
                if (
                    $fechaEsperada !== (string)$reservacion['fecha']
                    || $horaEsperada !== HorarioReservacionService::normalizarHoraSql(
                        (string)$reservacion['hora']
                    )
                    || $mesasActualesEsperadas !== $mesasActualesComparables
                ) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return [
                        'ok' => false,
                        'codigo' => self::VERSION_DESACTUALIZADA,
                        'version_actual' => $versionActual,
                    ];
                }
            }
            if (!$automatico && $versionEsperada !== '' && !hash_equals($versionActual, $versionEsperada)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::VERSION_DESACTUALIZADA,
                    'version_actual' => $versionActual,
                ];
            }

            $ticketsAbiertos = TicketMesa::abiertosParaMapa(true);
            // Los hechos del día se cargan una vez: la evaluación de este
            // horario y, si el grupo toma el bloque exterior, la de cada reserva
            // que haya que reubicar, salen del mismo contexto bloqueado.
            $contextoFecha = OcupacionMesasService::prepararContextoFecha(
                (string)$reservacion['fecha'],
                true,
                $ticketsAbiertos,
                null,
                $reservacionId
            );
            $evaluacionOcupacion = OcupacionMesasService::evaluarHorarioConContexto(
                $contextoFecha,
                (string)$reservacion['hora'],
                $reservacionId
            );
            $ocupacionReservaciones = (array)(
                $evaluacionOcupacion['ocupacion_reservaciones'] ?? []
            );
            $ocupacion = (array)($evaluacionOcupacion['ocupacion_bloqueante'] ?? []);
            $ticketsBloqueantes = (array)($evaluacionOcupacion['tickets_bloqueantes'] ?? []);
            $mesaIdsProyectadas = self::normalizarMesaIds(
                (array)($evaluacionOcupacion['mesas_proyectadas'] ?? [])
            );
            $conflictosTicket = [];
            $ticketConfirmacionPendiente = false;
            $confirmaciones = self::normalizarConfirmaciones($opciones['confirmaciones'] ?? []);
            $reubicacionesPlaneadas = [];

            if ($automatico && !empty($opciones['evitar_tickets_abiertos'])) {
                // Reubicar porque la mesa sigue ocupada: la proyección diría que
                // ese ticket «ya se habrá ido» a la hora de la reserva, pero el
                // motivo de mover es justo que todavía no se va. Toda mesa con
                // un ticket abierto AHORA cuenta como no disponible.
                foreach ($ticketsAbiertos as $ticketAbierto) {
                    if ((int)($ticketAbierto['reservacion_id'] ?? 0) === $reservacionId) {
                        continue;
                    }
                    foreach ((array)($ticketAbierto['mesa_ids'] ?? []) as $mesaOcupada) {
                        $evaluacionOcupacion['mesas'][(int)$mesaOcupada]['disponible'] = false;
                        $evaluacionOcupacion['mesas'][(int)$mesaOcupada]['fuente'] = 'ticket_abierto';
                    }
                }
            }

            if ($automatico) {
                $plan = AsignacionPlanificadorService::planificarConContexto(
                    $contextoFecha,
                    $evaluacionOcupacion,
                    (int)$reservacion['comensales'],
                    (string)$reservacion['hora'],
                    [$reservacionId]
                );

                if (!$plan['ok']) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::SIN_CAPACIDAD, 'motivo' => $plan['motivo']];
                }

                // El plan sale del contexto; las filas bloqueadas FOR UPDATE
                // son las de $mesas, y con ésas se sigue.
                $bloqueadasPorId = [];
                foreach ($mesas as $mesa) {
                    $bloqueadasPorId[(int)$mesa->id] = $mesa;
                }
                $mesaIds = $plan['mesa_ids'];
                $mesas = [];
                foreach ($mesaIds as $mesaId) {
                    if (!isset($bloqueadasPorId[$mesaId])) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return ['ok' => false, 'codigo' => self::MESAS_INVALIDAS];
                    }
                    $mesas[] = $bloqueadasPorId[$mesaId];
                }
                $reubicacionesPlaneadas = $plan['reubicaciones'];
            } else {
                /*
                 * El bloque exterior elegido a mano con reservas dentro: en vez
                 * de rechazarlo como «mesa ocupada», se planifica la misma
                 * reubicación que haría el alta automática. Mover a otros es
                 * una decisión, así que la primera vuelta sólo la ENSEÑA
                 * (quién, de qué mesa a cuál) y pide confirmarla.
                 */
                if (self::hayConflictoHorario($ocupacionReservaciones, $mesaIds)
                    && AsignacionPlanificadorService::esBloqueExterior($mesaIds, (array)($contextoFecha['mesas'] ?? []))) {
                    $planBloque = AsignacionPlanificadorService::planificarBloqueConContexto(
                        $contextoFecha,
                        $evaluacionOcupacion,
                        (int)$reservacion['comensales'],
                        (string)$reservacion['hora'],
                        [$reservacionId]
                    );
                    if (!$planBloque['ok']) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return ['ok' => false, 'codigo' => self::MESA_OCUPADA, 'motivo' => $planBloque['motivo']];
                    }
                    if ($planBloque['reubicaciones'] !== []
                        && !in_array(self::REUBICAR_RESERVACIONES, $confirmaciones, true)) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return [
                            'ok' => false,
                            'codigo' => self::REUBICAR_RESERVACIONES,
                            'requiere_confirmacion' => true,
                            'confirmaciones_requeridas' => [self::REUBICAR_RESERVACIONES],
                            'reubicaciones' => $planBloque['reubicaciones'],
                            'reservaciones_a_mover' => count($planBloque['reubicaciones']) === 1
                                ? '1 reservación'
                                : count($planBloque['reubicaciones']) . ' reservaciones',
                            'mesa_ids' => $mesaIds,
                        ];
                    }
                    $reubicacionesPlaneadas = $planBloque['reubicaciones'];
                } elseif (self::hayConflictoHorario($ocupacionReservaciones, $mesaIds)) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::MESA_OCUPADA];
                }

                $conflictosTicket = self::conflictosTicketsSeleccionados(
                    $ticketsBloqueantes,
                    $mesaIds
                );
                if ($conflictosTicket !== []) {
                    if ($modoMapaAdministrativo || empty($opciones['permitir_superposicion_ticket_abierto'])) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return [
                            'ok' => false,
                            'codigo' => self::SUPERPOSICION_NO_AUTORIZADA,
                            'requiere_confirmacion' => false,
                            'confirmaciones_requeridas' => [],
                            'conflictos_ticket' => $conflictosTicket,
                        ];
                    }
                    $ticketIdsActuales = array_column($conflictosTicket, 'ticket_id');
                    $ticketIdsAceptados = self::normalizarMesaIds(
                        (array)($opciones['ticket_ids_aceptados'] ?? [])
                    );
                    $tokenActual = self::tokenConflictosTicket($conflictosTicket);
                    $tokenAceptado = trim((string)($opciones['conflicto_token'] ?? ''));

                    if ($ticketIdsAceptados === []) {
                        $ticketConfirmacionPendiente = true;
                    }

                    sort($ticketIdsActuales, SORT_NUMERIC);
                    sort($ticketIdsAceptados, SORT_NUMERIC);
                    if (
                        !$ticketConfirmacionPendiente
                        && ($ticketIdsActuales !== $ticketIdsAceptados
                        || $tokenAceptado === ''
                        || !hash_equals($tokenActual, $tokenAceptado))
                    ) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return [
                            'ok' => false,
                            'codigo' => self::CONFLICTO_CONCURRENTE,
                            'conflictos_ticket' => $conflictosTicket,
                            'conflicto_token' => $tokenActual,
                        ];
                    }
                } elseif ((array)($opciones['ticket_ids_aceptados'] ?? []) !== []) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return [
                        'ok' => false,
                        'codigo' => self::CONFLICTO_CONCURRENTE,
                        'conflictos_ticket' => [],
                    ];
                }
            }

            if (
                !$modoMapaAdministrativo
                && (int)$reservacion['comensales'] <= ReservacionConfig::maxComensalesPublico()
                && !OcupacionMesasService::agrupacionValida(
                    $mesas,
                    (int)$reservacion['comensales']
                )
            ) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::AGRUPACION_NO_AUTORIZADA];
            }

            $capacidadInsuficiente = !self::validarCapacidad(
                $mesas,
                $mesaIds,
                (int)$reservacion['comensales']
            );
            $seleccionProyectada = array_values(array_intersect(
                self::normalizarMesaIds($mesaIds),
                $mesaIdsProyectadas
            ));
            $dependeLiberacionProyectada = $seleccionProyectada !== [];
            $advertencias = [];
            $confirmacionesRequeridas = [];
            if ($capacidadInsuficiente) {
                $advertencias[] = self::CAPACIDAD_INSUFICIENTE;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::CAPACIDAD_INSUFICIENTE;
                }
            }
            if ($dependeLiberacionProyectada) {
                $advertencias[] = self::DEPENDE_LIBERACION_PROYECTADA;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::DEPENDE_LIBERACION_PROYECTADA;
                }
            }
            if ($conflictosTicket !== []) {
                $advertencias[] = self::CONFLICTO_TICKET_ABIERTO;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::CONFLICTO_TICKET_ABIERTO;
                }
            }
            if (
                trim((string)($reservacion['contacto'] ?? '')) === ''
                || (string)($reservacion['contacto_tipo'] ?? 'ninguno') === 'ninguno'
            ) {
                $advertencias[] = self::SIN_CONTACTO;
            }
            $advertencias = array_values(array_unique($advertencias));
            $confirmacionesRequeridas = array_values(array_unique($confirmacionesRequeridas));

            if ($ticketConfirmacionPendiente || (
                $modoMapaAdministrativo
                && $conflictosTicket !== []
                && !in_array(self::CONFLICTO_TICKET_ABIERTO, $confirmaciones, true)
            )) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::CONFLICTO_TICKETS_ABIERTOS,
                    'requiere_confirmacion' => true,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                    'conflictos_ticket' => $conflictosTicket,
                    'conflicto_token' => self::tokenConflictosTicket($conflictosTicket),
                ];
            }

            $capacidadConfirmada = $permitirCapacidadInsuficiente
                || in_array(self::CAPACIDAD_INSUFICIENTE, $confirmaciones, true);
            if ($capacidadInsuficiente && !$capacidadConfirmada) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::CAPACIDAD_INSUFICIENTE,
                    'requiere_confirmacion' => $modoMapaAdministrativo,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                ];
            }
            if (
                $modoMapaAdministrativo
                && $dependeLiberacionProyectada
                && !in_array(self::DEPENDE_LIBERACION_PROYECTADA, $confirmaciones, true)
            ) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::DEPENDE_LIBERACION_PROYECTADA,
                    'requiere_confirmacion' => true,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                ];
            }

            // Primero se despeja el bloque y luego se ocupa: en la misma
            // transacción, así que un fallo deshace las dos cosas.
            $reubicaciones = ReservacionReubicacionService::aplicar($reubicacionesPlaneadas, $reservacionId);
            ReservacionMesa::reemplazarAsignacion($reservacionId, $mesaIds);
            ReservacionMesa::avanzarVersion($reservacionId);
            $motivo = !empty($conflictosTicket)
                ? 'Asignación manual aceptó tickets abiertos #' . implode(', #', array_column($conflictosTicket, 'ticket_id'))
                : ($automatico ? 'Asignación automática de mesas' : 'Asignación manual de mesas');
            if ($dependeLiberacionProyectada) {
                $motivo .= ' con liberación proyectada de mesas #'
                    . implode(', #', $seleccionProyectada);
            }
            $motivo = ActiveRecord::escaparString($motivo);
            if ($gestionarTransaccion && !$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transaccion de asignacion.');
            }

            return [
                'ok' => true,
                'codigo' => self::ASIGNACION_GUARDADA,
                'mesa_ids' => $mesaIds,
                'reubicaciones' => $reubicaciones,
                'tickets_aceptados' => array_column($conflictosTicket ?? [], 'ticket_id'),
                'depende_liberacion_proyectada' => $dependeLiberacionProyectada,
                'mesas_proyectadas' => $seleccionProyectada,
                'advertencias' => $advertencias,
                'confirmaciones_requeridas' => [],
                'advertencia_codigo' => $dependeLiberacionProyectada
                    ? self::DEPENDE_LIBERACION_PROYECTADA
                    : null,
            ];
        } catch (\Throwable $e) {
            try {
                if ($gestionarTransaccion) {
                    $db->rollback();
                }
            } catch (\Throwable $rollbackError) {
                error_log('AsignacionMesasService rollback - ' . $rollbackError->getMessage());
            }

            error_log('AsignacionMesasService::asignar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    private static function rollbackSiPropia(\mysqli $db, bool $gestionarTransaccion): void
    {
        if ($gestionarTransaccion) {
            $db->rollback();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function conflictosTicketsSeleccionados(array $tickets, array $mesaIds): array
    {
        $seleccion = array_fill_keys(self::normalizarMesaIds($mesaIds), true);
        $conflictos = [];

        foreach ($tickets as $ticket) {
            $todas = self::normalizarMesaIds((array)($ticket['mesa_ids'] ?? []));
            $enConflicto = array_values(array_filter(
                $todas,
                static fn(int $mesaId): bool => isset($seleccion[$mesaId])
            ));
            if ($enConflicto === []) {
                continue;
            }

            $conflictos[] = [
                'ticket_id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
                'reservacion_id' => $ticket['reservacion_id'] ?? null,
                'origen' => (string)($ticket['origen'] ?? 'walk_in'),
                'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
                'mesa_ids' => $todas,
                'mesas_conflicto' => $enConflicto,
            ];
        }

        usort(
            $conflictos,
            static fn(array $a, array $b): int => $a['ticket_id'] <=> $b['ticket_id']
        );

        return $conflictos;
    }

    private static function tokenConflictosTicket(array $conflictos): string
    {
        return hash('sha256', json_encode($conflictos, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private static function normalizarMesaIds(array $mesaIds): array
    {
        $ids = [];

        foreach ($mesaIds as $mesaId) {
            $mesaId = (int)$mesaId;

            if ($mesaId > 0 && !in_array($mesaId, $ids, true)) {
                $ids[] = $mesaId;
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    private static function normalizarConfirmaciones($confirmaciones): array
    {
        if (!is_array($confirmaciones)) {
            $confirmaciones = [$confirmaciones];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn($codigo): string => trim((string)$codigo),
            $confirmaciones
        ))));
    }

    private static function capacidadSeleccion(array $mesas): int
    {
        return array_reduce($mesas, static function (int $total, $mesa): int {
            return $total + (int)($mesa->capacidad ?? 0);
        }, 0);
    }

    private static function numerosSeleccion(array $mesas): string
    {
        $numeros = array_map(static function ($mesa): int {
            return (int)($mesa->numero ?? 0);
        }, $mesas);

        sort($numeros, SORT_NUMERIC);

        return implode('-', array_map(static function (int $numero): string {
            return str_pad((string)$numero, 3, '0', STR_PAD_LEFT);
        }, $numeros));
    }

    private static function fila(string $query): ?array
    {
        $resultado = ActiveRecord::getDB()->query($query);

        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();

        return $fila;
    }

}
