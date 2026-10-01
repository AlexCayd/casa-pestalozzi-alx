<?php

/**
 * Ocupación de mesas para el núcleo de reservaciones.
 *
 * La clase separa la ocupación planificada (reservacion_mesas) de la física
 * (ticket_mesas). Las consultas son de lectura; no expiran holds ni cierran
 * tickets como efecto secundario.
 */

namespace Services\Tables;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\CapacidadReservacionesService;
use Services\Reservations\ReservacionConfig;

use DateTimeImmutable;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Services\Pos\TicketTemporalService;
use Services\Reservations\HorarioReservacionService;

final class OcupacionMesasService
{
    public const CONTEXTO_ACTUAL = 'actual';
    public const CONTEXTO_PROYECTADO = 'proyectado';
    public const CONTEXTO_FUTURO = 'fecha_futura';
    public const CONTEXTO_HISTORICO = 'historico';

    /**
     * Devuelve el estado interno de todas las mesas para un intervalo.
     *
     * @return array<string, mixed>
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?array $ticketsAbiertos = null,
        ?DateTimeImmutable $ahora = null,
        ?array $contextoFecha = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $horaSql = HorarioReservacionService::normalizarHoraSql($hora);
        $objetivo = self::fechaHora($fecha, $horaSql);
        if (!$objetivo) {
            return [
                'ok' => false,
                'contexto' => self::CONTEXTO_HISTORICO,
                'mesas' => [],
                'ocupacion_bloqueante' => [],
                'ocupacion_reservaciones' => [],
                'tickets_por_mesa' => [],
                'mesa_ids_disponibles' => [],
                'mesa_ids_bloqueadas' => [],
                'causas_bloqueo_por_mesa' => [],
                'mesa_ids_proyectadas' => [],
                'tickets_ignorados' => [],
                'alertas_operativas' => [],
            ];
        }

        $contextoTemporal = TicketTemporalService::contextoTemporal($fecha, $horaSql, $ahora);
        $contexto = (string)$contextoTemporal['contexto'];
        $intervalo = [
            'inicio' => $objetivo,
            'fin' => $objetivo->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes'),
        ];
        $contextoFecha = $contextoFecha ?? self::prepararContextoFecha(
            $fecha,
            $bloquear,
            $ticketsAbiertos,
            $ahora,
            $excluirReservacionId
        );
        if ((string)($contextoFecha['fecha'] ?? '') !== $fecha) {
            throw new \InvalidArgumentException('El contexto de ocupación pertenece a otra fecha.');
        }
        $reservaciones = (array)($contextoFecha['reservaciones'] ?? []);
        $ocupacionReservaciones = self::ocupacionReservacionesEnIntervalo(
            $reservaciones,
            $intervalo,
            $excluirReservacionId
        );

        $tickets = (array)($contextoFecha['tickets'] ?? []);
        $evaluacionTickets = self::evaluarTickets($tickets, $fecha, $horaSql, $ahora);
        $mesas = (array)($contextoFecha['mesas'] ?? []);
        $mesasPorId = [];
        foreach ($mesas as $mesa) {
            $mesasPorId[(int)($mesa->id ?? 0)] = $mesa;
        }
        $porMesa = [];
        $alertas = [];

        foreach ($mesas as $mesa) {
            $mesaId = (int)$mesa->id;
            $elegible = self::mesaElegible($mesa);
            $estado = [
                'mesa_id' => $mesaId,
                'numero' => (int)$mesa->numero,
                'capacidad' => (int)$mesa->capacidad,
                'disponible' => false,
                'fuente' => $elegible ? 'libre' : 'no_reservable',
                'reservacion_id' => null,
                'ticket_id' => null,
                'tipo' => $elegible ? 'libre' : 'no_reservable',
                'liberacion_estimada' => null,
            ];

            if (isset($evaluacionTickets['por_mesa'][$mesaId])
                && $evaluacionTickets['por_mesa'][$mesaId]['bloquea_disponibilidad']
            ) {
                $ticket = $evaluacionTickets['por_mesa'][$mesaId];
                $estado = array_merge($estado, [
                    'disponible' => false,
                    'fuente' => 'ticket_abierto',
                    'tipo' => 'ticket_abierto',
                    'ticket_id' => (int)$ticket['ticket_id'],
                    'reservacion_id' => $ticket['reservacion_id'],
                    'liberacion_estimada' => $ticket['liberacion_estimada'],
                ]);
            }

            if (isset($ocupacionReservaciones[$mesaId])) {
                $reserva = $ocupacionReservaciones[$mesaId];
                // Un ticket físico gana sobre la asignación planificada. Si
                // no hay ticket bloqueante, la reservación sí ocupa la mesa.
                if ($estado['fuente'] !== 'ticket_abierto') {
                    $estado = array_merge($estado, [
                        'disponible' => false,
                        'fuente' => $reserva['fuente'],
                        'tipo' => $reserva['fuente'],
                        'reservacion_id' => (int)$reserva['reservacion_id'],
                    ]);
                }
            }

            if ($estado['fuente'] === 'libre' && $elegible) {
                $estado['disponible'] = true;
            }
            $porMesa[$mesaId] = $estado;
        }

        foreach ($evaluacionTickets['por_mesa'] as $mesaId => $ticket) {
            if (($ticket['tipo'] ?? '') === 'ticket_proyectado'
                && ($porMesa[$mesaId]['fuente'] ?? '') === 'libre'
            ) {
                $porMesa[$mesaId]['fuente'] = 'ticket_proyectado';
                $porMesa[$mesaId]['tipo'] = 'ticket_proyectado';
                $porMesa[$mesaId]['ticket_id'] = (int)$ticket['ticket_id'];
                $porMesa[$mesaId]['liberacion_estimada'] = $ticket['liberacion_estimada'];
                $porMesa[$mesaId]['disponible'] = true;
            }
        }

        foreach ($porMesa as $estado) {
            if ($estado['fuente'] === 'ticket_abierto' && $estado['reservacion_id'] !== null) {
                $alertas[] = [
                    'tipo' => 'ticket_abierto',
                    'mesa_id' => (int)$estado['mesa_id'],
                    'ticket_id' => (int)$estado['ticket_id'],
                    'reservacion_id' => (int)$estado['reservacion_id'],
                ];
            }
        }

        $disponibles = [];
        $proyectadas = [];
        foreach ($porMesa as $mesaId => $estado) {
            if ($estado['disponible']) {
                $disponibles[] = (int)$mesaId;
            }
            if ($estado['fuente'] === 'ticket_proyectado') {
                $proyectadas[] = (int)$mesaId;
            }
        }
        sort($disponibles, SORT_NUMERIC);
        sort($proyectadas, SORT_NUMERIC);

        $ocupacionBloqueante = [];
        foreach ($porMesa as $mesaId => $estado) {
            if (!$estado['disponible'] && $estado['fuente'] !== 'no_reservable') {
                $ocupacionBloqueante[(int)$mesaId] = $estado;
            }
        }

        $mesaIdsBloqueadas = [];
        $causasBloqueoPorMesa = [];
        foreach ($porMesa as $mesaId => &$estado) {
            $mesa = $mesasPorId[(int)$mesaId] ?? null;
            $esMesaFisicaElegible = $mesa !== null && self::mesaElegible($mesa);
            $bloqueadaEnIntervalo = $esMesaFisicaElegible && !$estado['disponible'];
            $causas = [];
            if ($bloqueadaEnIntervalo) {
                $ticket = $evaluacionTickets['por_mesa'][(int)$mesaId] ?? null;
                if ($ticket !== null && !empty($ticket['bloquea_disponibilidad'])) {
                    $causas[] = 'ticket';
                }
                $reservacion = $ocupacionReservaciones[(int)$mesaId] ?? null;
                if ($reservacion !== null) {
                    $causas[] = ($reservacion['fuente'] ?? '') === 'hold'
                        ? 'hold'
                        : 'reservacion';
                }
                if (($estado['fuente'] ?? '') === 'hold' && !in_array('hold', $causas, true)) {
                    $causas[] = 'hold';
                }
                if ($causas === []) {
                    $causas[] = 'ocupacion';
                }
                $mesaIdsBloqueadas[] = (int)$mesaId;
                $causasBloqueoPorMesa[(int)$mesaId] = $causas;
            }
            $estado['bloqueada_en_intervalo'] = $bloqueadaEnIntervalo;
            $estado['causas_bloqueo'] = $causas;
        }
        unset($estado);
        sort($mesaIdsBloqueadas, SORT_NUMERIC);

        $resultado = [
            'ok' => true,
            'fecha' => $fecha,
            'hora' => $horaSql,
            'bloquear' => $bloquear,
            'excluir_reservacion_ids' => self::normalizarExclusiones($excluirReservacionId),
            'contexto' => $contexto,
            'contexto_temporal' => $contextoTemporal,
            'objetivo' => $objetivo->format('Y-m-d H:i:s'),
            'intervalo' => [
                'inicio' => $intervalo['inicio']->format('Y-m-d H:i:s'),
                'fin' => $intervalo['fin']->format('Y-m-d H:i:s'),
            ],
            'mesas' => $porMesa,
            'ocupacion_bloqueante' => $ocupacionBloqueante,
            'ocupacion_reservaciones' => $ocupacionReservaciones,
            'mesa_ids_bloqueadas' => $mesaIdsBloqueadas,
            'causas_bloqueo_por_mesa' => $causasBloqueoPorMesa,
            'tickets_por_mesa' => $evaluacionTickets['por_mesa'],
            'tickets_bloqueantes' => $evaluacionTickets['bloqueantes'],
            'ocupacion_fisica' => $evaluacionTickets['fisica'],
            'mesa_ids_disponibles' => $disponibles,
            'mesa_ids_proyectadas' => $proyectadas,
            'mesas_proyectadas' => $proyectadas,
            'tickets_ignorados' => $evaluacionTickets['ignorados'],
            'alertas_operativas' => $alertas,
        ];
        if (array_key_exists('demanda_no_asignada', $contextoFecha)) {
            $resultado['demanda_no_asignada_reservaciones'] = self::demandaNoAsignadaEnIntervalo(
                (array)$contextoFecha['demanda_no_asignada'],
                $intervalo,
                $excluirReservacionId
            );
        }
        return $resultado;
    }

    /** Carga una sola vez los hechos persistidos compartidos por los slots del día. */
    public static function prepararContextoFecha(
        string $fecha,
        bool $bloquear = false,
        ?array $ticketsAbiertos = null,
        ?DateTimeImmutable $ahora = null,
        int|array $excluirReservacionId = 0,
        bool $incluirDemandaNoAsignada = false
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $reservaciones = ReservacionMesa::obtenerOcupacionDelDia(
            $fecha,
            $excluirReservacionId,
            $bloquear,
            $ahora
        );
        $contexto = [
            'fecha' => $fecha,
            'ahora' => $ahora,
            'bloquear' => $bloquear,
            'reservaciones' => $reservaciones,
            'tickets' => $ticketsAbiertos ?? TicketMesa::abiertosParaMapa($bloquear),
            'mesas' => Mesa::buscarTodasParaMapa(),
        ];
        $contexto['mesas_reservables'] = array_values(array_filter(
            $contexto['mesas'],
            static fn($mesa): bool => self::mesaElegible($mesa)
        ));
        if ($incluirDemandaNoAsignada && !$bloquear) {
            $contexto['demanda_no_asignada'] = ReservacionMesa::obtenerDemandaNoAsignadaDelDia(
                $fecha,
                $ahora
            );
        }
        return $contexto;
    }

    /** Evalúa un horario sobre hechos previamente cargados para la misma fecha. */
    public static function evaluarHorarioConContexto(
        array $contextoFecha,
        string $hora,
        int|array $excluirReservacionId = 0,
        ?DateTimeImmutable $ahora = null
    ): array {
        return self::evaluarHorario(
            (string)($contextoFecha['fecha'] ?? ''),
            $hora,
            $excluirReservacionId,
            (bool)($contextoFecha['bloquear'] ?? false),
            (array)($contextoFecha['tickets'] ?? []),
            $ahora ?? ($contextoFecha['ahora'] ?? null),
            $contextoFecha
        );
    }

    /**
     * Predicado SQL equivalente al intervalo semiabierto que evalúa
     * intervalosSeTraslapan(). Las fechas son parámetros del Model.
     */
    public static function predicadoSqlTraslape(string $alias = 'r'): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $alias) !== 1) {
            throw new \InvalidArgumentException('Alias SQL inválido para traslape.');
        }
        return "TIMESTAMP({$alias}.fecha, {$alias}.hora) < ? AND "
            . 'TIMESTAMPADD(MINUTE, ' . ReservacionConfig::DURACION_RESERVACION_MINUTOS
            . ", TIMESTAMP({$alias}.fecha, {$alias}.hora)) > ?";
    }

    /**
     * Clasifica tickets abiertos sin alterar su estado. Sólo los tickets del
     * día actual participan en una consulta del día actual; una fecha futura
     * ignora los tickets abiertos actuales.
     *
     * @param array<int, array<string, mixed>|object> $tickets
     * @return array<string, mixed>
     */
    public static function evaluarTickets(
        array $tickets,
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $porMesa = [];
        $fisica = [];
        $bloqueantes = [];
        $ignorados = [];
        $contextoTemporal = TicketTemporalService::contextoTemporal($fecha, $hora, $ahora);
        $contexto = (string)$contextoTemporal['contexto'];

        foreach ($tickets as $raw) {
            $ticket = is_array($raw) ? $raw : get_object_vars($raw);
            $ticketId = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
            $mesaIds = self::normalizarIds((array)($ticket['mesa_ids'] ?? []));
            if ($ticketId < 1 || $mesaIds === []) {
                continue;
            }

            $resumen = TicketTemporalService::proyectar($ticket, $fecha, $hora, $ahora);
            if (!$resumen['ticket_abierto']) {
                continue;
            }
            $fisica[] = $resumen;
            if (!$resumen['aplica_fecha']) {
                $ignorados[] = $resumen;
                continue;
            }
            if ($resumen['bloquea_en_consulta']) {
                $bloqueantes[] = $resumen;
            }
            foreach ($mesaIds as $mesaId) {
                if (!isset($porMesa[$mesaId]) || $resumen['bloquea_en_consulta']) {
                    $porMesa[$mesaId] = ['mesa_id' => $mesaId] + $resumen;
                }
            }
        }

        ksort($porMesa, SORT_NUMERIC);
        return [
            'contexto' => $contexto,
            'contexto_temporal' => $contextoTemporal,
            'por_mesa' => $porMesa,
            'fisica' => $fisica,
            'bloqueantes' => $bloqueantes,
            'ignorados' => $ignorados,
            'mesas_proyectadas' => array_values(array_map(
                'intval',
                array_keys(array_filter($porMesa, static fn(array $ticket): bool => $ticket['tipo'] === 'ticket_proyectado'))
            )),
        ];
    }

    public static function calcularLiberacionEstimadaTicket($horaApertura): ?DateTimeImmutable
    {
        return TicketTemporalService::calcularLiberacionEstimadaTicket($horaApertura);
    }

    /**
     * Compatibilidad pura para lectores existentes. Usa el intervalo canónico
     * y no el antiguo bloqueo previo de 30 minutos.
     */
    public static function ocupacionReservacionesEnVentana(
        array $asignaciones,
        string $hora,
        int $excluirReservacionId = 0
    ): array {
        $inicio = self::fechaHora('2000-01-01', $hora);
        if ($inicio === null) {
            return [];
        }
        $resultado = [];
        foreach ($asignaciones as $asignacion) {
            if ($excluirReservacionId > 0 && (int)($asignacion['reservacion_id'] ?? 0) === $excluirReservacionId) {
                continue;
            }
            $horaReserva = self::fechaHora('2000-01-01', (string)($asignacion['hora'] ?? ''));
            if ($horaReserva === null || !self::intervalosSeTraslapan($horaReserva, $inicio)) {
                continue;
            }
            $mesaId = (int)($asignacion['mesa_id'] ?? 0);
            if ($mesaId < 1) {
                continue;
            }
            $resultado[$mesaId] = [
                'mesa_id' => $mesaId,
                'reservacion_id' => (int)($asignacion['reservacion_id'] ?? 0),
                'fuente' => ($asignacion['estado'] ?? '') === ReservacionConfig::ESTADO_RETENCION_PENDIENTE
                    ? 'hold'
                    : 'reservacion',
                'estado' => (string)($asignacion['estado'] ?? ''),
                'hora' => (string)($asignacion['hora'] ?? ''),
            ];
        }
        return $resultado;
    }

    /** @return array<string, mixed> */
    public static function resumenCapacidad(array $mesas, array $evaluacion): array
    {
        return CapacidadReservacionesService::desdeEvaluacion(
            $mesas,
            $evaluacion,
            (array)($evaluacion['excluir_reservacion_ids'] ?? []),
            (bool)($evaluacion['bloquear'] ?? false),
            null
        ) + [
            'mesa_ids_estimadas' => array_values(array_map('intval', (array)($evaluacion['mesa_ids_libres'] ?? []))),
        ];
    }

    /** @return array<int, object> */
    public static function seleccionarAgrupacionAutorizada(
        array $mesas,
        int $comensales,
        array $mesaIdsProyectadas = []
    ): array
    {
        return AsignacionMesasService::seleccionarMesasPublicas(
            $mesas,
            $comensales,
            $mesaIdsProyectadas
        );
    }

    public static function agrupacionValida(array $mesas, int $comensales): bool
    {
        if ($mesas === []) {
            return false;
        }
        foreach ($mesas as $mesa) {
            if (!self::mesaElegible($mesa)) {
                return false;
            }
        }
        return array_sum(array_map(static fn($mesa): int => (int)($mesa->capacidad ?? 0), $mesas)) >= $comensales;
    }

    /** @return array<int, array<string, mixed>> */
    private static function demandaNoAsignadaEnIntervalo(
        array $filas,
        array $intervalo,
        int|array $excluirReservacionId
    ): array {
        $exclusiones = array_fill_keys(self::normalizarExclusiones($excluirReservacionId), true);
        $resultado = [];
        foreach ($filas as $fila) {
            $id = (int)($fila['id'] ?? 0);
            if ($id < 1 || isset($exclusiones[$id])) {
                continue;
            }
            $inicio = self::fechaHora((string)($fila['fecha'] ?? ''), (string)($fila['hora'] ?? ''));
            if (!$inicio || !self::intervalosSeTraslapan($inicio, $intervalo['inicio'])) {
                continue;
            }
            $resultado[] = $fila;
        }
        return $resultado;
    }

    /** @return array<int, array<string, mixed>> */
    private static function ocupacionReservacionesEnIntervalo(
        array $asignaciones,
        array $intervalo,
        int|array $excluirReservacionId = 0
    ): array
    {
        $exclusiones = array_fill_keys(self::normalizarExclusiones($excluirReservacionId), true);
        $resultado = [];
        $inicio = $intervalo['inicio'];
        foreach ($asignaciones as $asignacion) {
            if (isset($exclusiones[(int)($asignacion['reservacion_id'] ?? 0)])) {
                continue;
            }
            if (array_key_exists('reservacion_influye_en_disponibilidad', $asignacion)
                && !(bool)$asignacion['reservacion_influye_en_disponibilidad']) {
                continue;
            }
            $reserva = self::fechaHora((string)$asignacion['fecha'], (string)$asignacion['hora']);
            if (!$reserva) {
                continue;
            }
            if (self::intervalosSeTraslapan($reserva, $inicio)) {
                $resultado[(int)$asignacion['mesa_id']] = $asignacion;
            }
        }
        return $resultado;
    }

    /** @return array<int, int> */
    private static function normalizarExclusiones(int|array $ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function mesaElegible($mesa): bool
    {
        return (int)($mesa->activo ?? 0) === 1
            && (int)($mesa->reservable ?? 0) === 1
            && (string)($mesa->tipo ?? '') === 'mesa'
            && (int)($mesa->capacidad ?? 0) > 0;
    }

    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = HorarioReservacionService::normalizarHoraSql($hora);
        if (!HorarioReservacionService::fechaValida($fecha) || $hora === '') {
            return null;
        }
        $resultado = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $hora,
            ReservacionConfig::timezone()
        );
        $errores = DateTimeImmutable::getLastErrors();
        return $resultado instanceof DateTimeImmutable
            && ($errores === false || (($errores['warning_count'] ?? 0) === 0 && ($errores['error_count'] ?? 0) === 0))
            ? $resultado
            : null;
    }

    /**
     * Evalúa el traslape semiabierto de dos intervalos con duración configurable.
     * La duración productiva se toma de ReservacionConfig; las pruebas pueden
     * pasar otra duración sin mutar la configuración de producción.
     */
    public static function intervalosSeTraslapan(
        DateTimeImmutable $ocupacionInicio,
        DateTimeImmutable $consultaInicio,
        ?int $duracionMinutos = null
    ): bool {
        $duracion = $duracionMinutos ?? ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        if ($duracion < 1) {
            return false;
        }
        $ocupacionFin = $ocupacionInicio->modify('+' . $duracion . ' minutes');
        $consultaFin = $consultaInicio->modify('+' . $duracion . ' minutes');

        return $ocupacionInicio < $consultaFin && $ocupacionFin > $consultaInicio;
    }

    /** @return array<int, int> */
    private static function normalizarIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
