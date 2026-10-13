<?php

/**
 * Ocupación de mesas para el núcleo de reservaciones.
 *
 * La ocupación sale de las reservaciones con mesas asignadas
 * (reservacion_mesas). Las consultas son de lectura; no expiran holds como
 * efecto secundario.
 */

namespace Services\Tables;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\CapacidadReservacionesService;
use Services\Reservations\ReservacionConfig;

use DateTimeImmutable;
use Model\Mesa;
use Model\ReservacionMesa;
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
                'mesa_ids_disponibles' => [],
                'mesa_ids_bloqueadas' => [],
                'causas_bloqueo_por_mesa' => [],
                'mesa_ids_proyectadas' => [],
            ];
        }

        $contextoTemporal = self::contextoTemporal($fecha, $horaSql, $ahora);
        $contexto = (string)$contextoTemporal['contexto'];
        $intervalo = [
            'inicio' => $objetivo,
            'fin' => $objetivo->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes'),
        ];
        $contextoFecha = $contextoFecha ?? self::prepararContextoFecha(
            $fecha,
            $bloquear,
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
        $mesas = (array)($contextoFecha['mesas'] ?? []);
        $mesasPorId = [];
        foreach ($mesas as $mesa) {
            $mesasPorId[(int)($mesa->id ?? 0)] = $mesa;
        }
        $porMesa = [];

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
                'tipo' => $elegible ? 'libre' : 'no_reservable',
            ];

            if (isset($ocupacionReservaciones[$mesaId])) {
                $reserva = $ocupacionReservaciones[$mesaId];
                $estado = array_merge($estado, [
                    'disponible' => false,
                    'fuente' => $reserva['fuente'],
                    'tipo' => $reserva['fuente'],
                    'reservacion_id' => (int)$reserva['reservacion_id'],
                ]);
            }

            if ($estado['fuente'] === 'libre' && $elegible) {
                $estado['disponible'] = true;
            }
            $porMesa[$mesaId] = $estado;
        }

        $disponibles = [];
        foreach ($porMesa as $mesaId => $estado) {
            if ($estado['disponible']) {
                $disponibles[] = (int)$mesaId;
            }
        }
        sort($disponibles, SORT_NUMERIC);

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
            'mesa_ids_disponibles' => $disponibles,
            // Sin tickets no hay liberaciones proyectadas; la llave se conserva
            // vacía porque capacidad y asignación la leen.
            'mesa_ids_proyectadas' => [],
            'mesas_proyectadas' => [],
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
        ?DateTimeImmutable $ahora = null,
        int|array $excluirReservacionId = 0,
        bool $incluirDemandaNoAsignada = false,
        bool $incluirAusenciasPendientesEnMapaAdmin = false
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $reservaciones = ReservacionMesa::obtenerOcupacionDelDia(
            $fecha,
            $excluirReservacionId,
            $bloquear,
            $ahora,
            $incluirAusenciasPendientesEnMapaAdmin
        );
        $contexto = [
            'fecha' => $fecha,
            'ahora' => $ahora,
            'bloquear' => $bloquear,
            'incluir_ausencias_pendientes_mapa_admin' => $incluirAusenciasPendientesEnMapaAdmin,
            'reservaciones' => $reservaciones,
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
     * Sitúa la consulta respecto al reloj: histórico, bloque actual, proyección
     * del mismo día o fecha futura.
     *
     * @return array<string, mixed>
     */
    public static function contextoTemporal(
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $objetivo = self::fechaHora($fecha, $hora);
        $fechaActual = $ahora->format('Y-m-d');
        $esDiaActual = $fecha === $fechaActual;
        $esFechaFutura = $fecha > $fechaActual;
        $esFechaPasada = $fecha < $fechaActual;
        $esBloqueActual = $esDiaActual
            && $objetivo instanceof DateTimeImmutable
            && $objetivo <= $ahora;
        $esProyeccionFutura = $esDiaActual
            && $objetivo instanceof DateTimeImmutable
            && $objetivo > $ahora;

        return [
            'fecha_consulta' => $fecha,
            'hora_consulta' => HorarioReservacionService::normalizarHoraSql($hora),
            'fecha_hora_consulta' => $objetivo,
            'fecha_hora_actual' => $ahora,
            'es_dia_actual' => $esDiaActual,
            'es_bloque_actual' => $esBloqueActual,
            'es_proyeccion_futura' => $esProyeccionFutura,
            'es_fecha_futura' => $esFechaFutura,
            'es_fecha_pasada' => $esFechaPasada,
            'zona_horaria' => $ahora->getTimezone()->getName(),
            'contexto' => $esFechaPasada
                ? self::CONTEXTO_HISTORICO
                : ($esFechaFutura
                    ? self::CONTEXTO_FUTURO
                    : ($esProyeccionFutura ? self::CONTEXTO_PROYECTADO : self::CONTEXTO_ACTUAL)),
            'objetivo' => $objetivo?->format('Y-m-d H:i:s'),
        ];
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
                && !(bool)$asignacion['reservacion_influye_en_disponibilidad']
                && empty($asignacion['bloquea_intervalo_mapa_admin'])) {
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
}
