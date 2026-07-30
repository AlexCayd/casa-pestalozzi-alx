<?php

/**
 * Fuente única para ocupación física, proyección de tickets y agrupaciones
 * públicas autorizadas. Ninguna proyección modifica o cierra tickets.
 */

namespace Services;

use DateTimeImmutable;
use Model\ReservacionMesa;
use Model\TicketMesa;

final class OcupacionMesasService
{
    public const CONTEXTO_ACTUAL = 'actual';
    public const CONTEXTO_PROYECTADO = 'proyectado';
    public const CONTEXTO_FUTURO = 'fecha_futura';
    public const CONTEXTO_HISTORICO = 'historico';

    /**
     * @return array<string, mixed>
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int $excluirReservacionId = 0,
        bool $bloquear = false,
        ?array $ticketsAbiertos = null,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $horaNormalizada = HorarioReservacionService::normalizarHoraSql($hora);
        $objetivo = self::fechaHora($fecha, $horaNormalizada);
        if (!$objetivo) {
            return [
                'ok' => false,
                'contexto' => self::CONTEXTO_HISTORICO,
                'ocupacion_bloqueante' => [],
                'ocupacion_reservaciones' => [],
                'tickets_por_mesa' => [],
                'mesas_proyectadas' => [],
                'alertas_operativas' => [],
            ];
        }

        $contexto = self::contexto($objetivo, $ahora);
        $asignaciones = ReservacionMesa::obtenerOcupacionDelDia(
            $fecha,
            max(0, $excluirReservacionId),
            $bloquear
        );
        $ocupacionReservaciones = self::ocupacionReservacionesEnVentana(
            $asignaciones,
            $horaNormalizada,
            $excluirReservacionId
        );
        $tickets = $ticketsAbiertos ?? TicketMesa::abiertosParaMapa($bloquear);
        $evaluacionTickets = self::evaluarTickets(
            $tickets,
            $fecha,
            $horaNormalizada,
            $ahora
        );

        $ocupacion = $ocupacionReservaciones;
        $alertas = [];
        foreach ($evaluacionTickets['por_mesa'] as $mesaId => &$ticketMesa) {
            $tieneReservacion = isset($ocupacionReservaciones[$mesaId]);
            $entraBloqueo = $objetivo > $ahora
                && $ahora >= $objetivo->modify(
                    '-' . ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO . ' minutes'
                );
            $debiaLiberarse = $ticketMesa['liberacion_base'] !== null
                && $ticketMesa['limite_bloqueo'] !== null
                && $ticketMesa['liberacion_base'] <= $ticketMesa['limite_bloqueo'];
            $conflictoProximo = $contexto === self::CONTEXTO_PROYECTADO
                && $ticketMesa['afecta_horario']
                && $tieneReservacion
                && $entraBloqueo
                && $debiaLiberarse;

            if ($conflictoProximo) {
                $ticketMesa['estado_proyeccion'] = 'conflicto_proximo';
                $ticketMesa['tipo'] = 'conflicto_proximo';
                $ticketMesa['conflicto_proximo'] = true;
                $reservacion = $ocupacionReservaciones[$mesaId];
                $alertas[] = [
                    'tipo' => 'conflicto_proximo',
                    'mesa_id' => (int)$mesaId,
                    'ticket_id' => (int)$ticketMesa['ticket_id'],
                    'reservacion_id' => (int)($reservacion['reservacion_id'] ?? 0),
                    'hora' => substr($horaNormalizada, 0, 5),
                    'mensaje' => sprintf(
                        'El ticket #%d sigue abierto dentro del bloqueo de la reservación #%d.',
                        (int)$ticketMesa['ticket_id'],
                        (int)($reservacion['reservacion_id'] ?? 0)
                    ),
                ];
            }

            if ($ticketMesa['afecta_horario']) {
                $ocupacion[(int)$mesaId] = $ticketMesa;
            }
        }
        unset($ticketMesa);

        return [
            'ok' => true,
            'fecha' => $fecha,
            'hora' => $horaNormalizada,
            'contexto' => $contexto,
            'objetivo' => $objetivo->format('Y-m-d H:i:s'),
            'ocupacion_bloqueante' => $ocupacion,
            'ocupacion_reservaciones' => $ocupacionReservaciones,
            'tickets_por_mesa' => $evaluacionTickets['por_mesa'],
            'tickets_bloqueantes' => $evaluacionTickets['bloqueantes'],
            'ocupacion_fisica' => $evaluacionTickets['fisica'],
            'mesas_proyectadas' => $evaluacionTickets['mesas_proyectadas'],
            'tickets_ignorados' => $evaluacionTickets['ignorados'],
            'alertas_operativas' => self::unicos($alertas, 'ticket_id', 'reservacion_id'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tickets
     * @return array<string, mixed>
     */
    public static function evaluarTickets(
        array $tickets,
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $objetivo = self::fechaHora($fecha, $hora);
        $contexto = $objetivo
            ? self::contexto($objetivo, $ahora)
            : self::CONTEXTO_HISTORICO;
        $limiteBloqueo = $objetivo
            ? $objetivo->modify('-' . ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO . ' minutes')
            : null;
        $seguridad = $ahora->modify(
            '+' . ReservacionConfig::MARGEN_MINIMO_SEGURIDAD_MINUTOS . ' minutes'
        );

        $porMesa = [];
        $fisica = [];
        $bloqueantesPorTicket = [];
        $ignoradosPorTicket = [];
        $mesasProyectadas = [];

        foreach ($tickets as $ticketRaw) {
            $ticket = is_array($ticketRaw) ? $ticketRaw : get_object_vars($ticketRaw);
            $ticketId = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
            $mesaIds = self::ids((array)($ticket['mesa_ids'] ?? []));
            if ($ticketId < 1 || $mesaIds === []) {
                continue;
            }

            $apertura = self::fechaHoraTicket((string)($ticket['hora_apertura'] ?? ''));
            $liberacionBase = $apertura
                ? $apertura
                    ->modify('+' . ReservacionConfig::DURACION_SERVICIO_ESTIMADA_MINUTOS . ' minutes')
                    ->modify('+' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes')
                : null;
            $liberacionProyectada = $liberacionBase;
            if ($liberacionProyectada === null || $seguridad > $liberacionProyectada) {
                $liberacionProyectada = $seguridad;
            }

            $aplicaFecha = $contexto === self::CONTEXTO_ACTUAL
                || $contexto === self::CONTEXTO_PROYECTADO;
            $disponibleProyectada = $contexto === self::CONTEXTO_PROYECTADO
                && $limiteBloqueo instanceof DateTimeImmutable
                && $liberacionProyectada <= $limiteBloqueo;
            $afectaHorario = $aplicaFecha && !$disponibleProyectada;
            $estado = match (true) {
                !$aplicaFecha => 'ignorado_fecha',
                $disponibleProyectada => 'disponible_proyectada',
                $contexto === self::CONTEXTO_ACTUAL => 'ocupada_actual',
                default => 'ocupada_proyectada',
            };
            $tipo = $disponibleProyectada ? 'ticket_proyectado' : 'ticket_abierto';

            $resumenTicket = [
                'ticket_id' => $ticketId,
                'reservacion_id' => !empty($ticket['reservacion_id'])
                    ? (int)$ticket['reservacion_id']
                    : null,
                'origen' => (string)($ticket['origen'] ?? (
                    !empty($ticket['reservacion_id']) ? 'reservacion' : 'walk_in'
                )),
                'walk_in' => empty($ticket['reservacion_id']),
                'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
                'mesa_ids' => $mesaIds,
                'ocupada_fisicamente' => true,
                'aplica_fecha' => $aplicaFecha,
                'afecta_horario' => $afectaHorario,
                'disponible_proyectada' => $disponibleProyectada,
                'estado_proyeccion' => $estado,
                'tipo' => $tipo,
                'liberacion_base' => $liberacionBase?->format('Y-m-d H:i:s'),
                'liberacion_proyectada' => $liberacionProyectada->format('Y-m-d H:i:s'),
                'liberacion_estimada' => $liberacionProyectada->format('Y-m-d H:i:s'),
                'limite_bloqueo' => $limiteBloqueo?->format('Y-m-d H:i:s'),
                'conflicto_proximo' => false,
            ];

            $fisica[] = $resumenTicket;
            if (!$aplicaFecha) {
                $ignoradosPorTicket[$ticketId] = $resumenTicket;
            } elseif ($afectaHorario) {
                $bloqueantesPorTicket[$ticketId] = $resumenTicket;
            }

            foreach ($mesaIds as $mesaId) {
                $porMesa[$mesaId] = ['mesa_id' => $mesaId] + $resumenTicket;
                if ($disponibleProyectada) {
                    $mesasProyectadas[$mesaId] = true;
                }
            }
        }

        ksort($porMesa, SORT_NUMERIC);
        $mesasProyectadas = array_map('intval', array_keys($mesasProyectadas));
        sort($mesasProyectadas, SORT_NUMERIC);

        return [
            'contexto' => $contexto,
            'por_mesa' => $porMesa,
            'fisica' => array_values($fisica),
            'bloqueantes' => array_values($bloqueantesPorTicket),
            'ignorados' => array_values($ignoradosPorTicket),
            'mesas_proyectadas' => $mesasProyectadas,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $asignaciones
     * @return array<int, array<string, mixed>>
     */
    public static function ocupacionReservacionesEnVentana(
        array $asignaciones,
        string $hora,
        int $excluirReservacionId = 0
    ): array {
        $ocupadas = [];
        foreach ($asignaciones as $asignacion) {
            if (
                $excluirReservacionId > 0
                && (int)($asignacion['reservacion_id'] ?? 0) === $excluirReservacionId
            ) {
                continue;
            }
            if (
                empty($asignacion['mesa_id'])
                || !self::hayTraslapeHorario($hora, (string)($asignacion['hora'] ?? ''))
            ) {
                continue;
            }

            $mesaId = (int)$asignacion['mesa_id'];
            $ocupadas[$mesaId] = [
                'tipo' => 'reservacion',
                'mesa_id' => $mesaId,
                'reservacion_id' => (int)$asignacion['reservacion_id'],
                'nombre' => (string)$asignacion['nombre'],
                'contacto' => (string)$asignacion['contacto'],
                'hora' => (string)$asignacion['hora'],
                'comensales' => (int)$asignacion['comensales'],
                'estado' => (string)$asignacion['estado'],
            ];
        }

        return $ocupadas;
    }

    /**
     * @param array<int, object|array<string, mixed>> $mesas
     * @param array<string, mixed> $evaluacion
     * @return array<string, mixed>
     */
    public static function resumenCapacidad(array $mesas, array $evaluacion): array
    {
        $mesas = self::mesasValidas($mesas);
        $reservadas = array_fill_keys(
            array_map('intval', array_keys($evaluacion['ocupacion_reservaciones'] ?? [])),
            true
        );
        $bloqueadas = array_fill_keys(
            array_map('intval', array_keys($evaluacion['ocupacion_bloqueante'] ?? [])),
            true
        );
        $proyectadas = array_fill_keys(
            self::ids((array)($evaluacion['mesas_proyectadas'] ?? [])),
            true
        );
        $fisicasAplicables = [];
        foreach ((array)($evaluacion['tickets_por_mesa'] ?? []) as $mesaId => $ticket) {
            if (!empty($ticket['aplica_fecha'])) {
                $fisicasAplicables[(int)$mesaId] = true;
            }
        }

        $reales = [];
        $adicionalesProyectadas = [];
        $estimadas = [];
        foreach ($mesas as $mesa) {
            $id = (int)self::valor($mesa, 'id', 0);
            if (!isset($reservadas[$id]) && !isset($fisicasAplicables[$id])) {
                $reales[] = $mesa;
            }
            if (isset($proyectadas[$id]) && !isset($reservadas[$id])) {
                $adicionalesProyectadas[] = $mesa;
            }
            if (!isset($bloqueadas[$id])) {
                $estimadas[] = $mesa;
            }
        }

        return [
            'capacidad_total' => self::capacidad($mesas),
            'capacidad_realmente_libre' => self::capacidad($reales),
            'capacidad_proyectada' => self::capacidad($adicionalesProyectadas),
            'capacidad_estimada_horario' => self::capacidad($estimadas),
            'capacidad_disponible' => self::capacidad($estimadas),
            'capacidad_ocupada' => max(0, self::capacidad($mesas) - self::capacidad($estimadas)),
            'mesa_ids_realmente_libres' => self::mesaIds($reales),
            'mesa_ids_proyectadas' => self::mesaIds($adicionalesProyectadas),
            'mesa_ids_estimadas' => self::mesaIds($estimadas),
        ];
    }

    /**
     * @param array<int, object|array<string, mixed>> $mesasDisponibles
     * @param array<int, int> $mesaIdsProyectadas
     * @return array<int, object|array<string, mixed>>
     */
    public static function seleccionarAgrupacionAutorizada(
        array $mesasDisponibles,
        int $comensales,
        array $mesaIdsProyectadas = []
    ): array {
        if ($comensales < 1 || $comensales > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return [];
        }

        $mesas = self::mesasValidas($mesasDisponibles);
        $porId = [];
        foreach ($mesas as $mesa) {
            $porId[(int)self::valor($mesa, 'id', 0)] = $mesa;
        }
        $proyectadas = array_fill_keys(self::ids($mesaIdsProyectadas), true);

        if ($comensales <= 4) {
            $candidatas = [];
            foreach ($mesas as $mesa) {
                if ((int)self::valor($mesa, 'capacidad', 0) >= $comensales) {
                    $candidatas[] = [$mesa];
                }
            }
            return self::mejorCandidata($candidatas, $comensales, $proyectadas);
        }

        $grupos = [];
        if ($comensales <= 8) {
            $grupos = ReservacionConfig::PAREJAS_MESAS_PUBLICAS_AUTORIZADAS;
        } else {
            $grupos = ReservacionConfig::TRIOS_MESAS_PUBLICAS_AUTORIZADOS;
        }

        $candidatas = self::candidatasConfiguradas($grupos, $porId, $comensales);
        if ($comensales === 8 && $candidatas === []) {
            $candidatas = self::candidatasConfiguradas(
                ReservacionConfig::TRIOS_MESAS_PUBLICAS_AUTORIZADOS,
                $porId,
                $comensales
            );
        }

        return self::mejorCandidata($candidatas, $comensales, $proyectadas);
    }

    /**
     * @param array<int, object|array<string, mixed>> $mesas
     */
    public static function agrupacionValida(array $mesas, int $comensales): bool
    {
        $mesas = self::mesasValidas($mesas);
        $seleccion = self::seleccionarAgrupacionAutorizada($mesas, $comensales);
        $idsEsperados = self::mesaIds($mesas);
        $idsSeleccion = self::mesaIds($seleccion);
        sort($idsEsperados, SORT_NUMERIC);
        sort($idsSeleccion, SORT_NUMERIC);

        return $idsEsperados !== [] && $idsEsperados === $idsSeleccion;
    }

    /**
     * @param array<int, array<int, int>> $grupos
     * @param array<int, object|array<string, mixed>> $porId
     * @return array<int, array<int, object|array<string, mixed>>>
     */
    private static function candidatasConfiguradas(
        array $grupos,
        array $porId,
        int $comensales
    ): array {
        $candidatas = [];
        foreach ($grupos as $ids) {
            $seleccion = [];
            foreach (self::ids($ids) as $id) {
                if (!isset($porId[$id])) {
                    $seleccion = [];
                    break;
                }
                $seleccion[] = $porId[$id];
            }
            if ($seleccion !== [] && self::capacidad($seleccion) >= $comensales) {
                $candidatas[] = $seleccion;
            }
        }

        return $candidatas;
    }

    /**
     * @param array<int, array<int, object|array<string, mixed>>> $candidatas
     * @param array<int, bool> $proyectadas
     * @return array<int, object|array<string, mixed>>
     */
    private static function mejorCandidata(
        array $candidatas,
        int $comensales,
        array $proyectadas
    ): array {
        if ($candidatas === []) {
            return [];
        }
        usort($candidatas, static function (array $a, array $b) use (
            $comensales,
            $proyectadas
        ): int {
            $proyectadasA = count(array_filter(
                self::mesaIds($a),
                static fn(int $id): bool => isset($proyectadas[$id])
            ));
            $proyectadasB = count(array_filter(
                self::mesaIds($b),
                static fn(int $id): bool => isset($proyectadas[$id])
            ));

            return ($proyectadasA <=> $proyectadasB)
                ?: ((self::capacidad($a) - $comensales) <=> (self::capacidad($b) - $comensales))
                ?: (count($a) <=> count($b))
                ?: strcmp(
                    implode('-', self::mesaIds($a)),
                    implode('-', self::mesaIds($b))
                );
        });

        return $candidatas[0];
    }

    /**
     * @param array<int, object|array<string, mixed>> $mesas
     * @return array<int, object|array<string, mixed>>
     */
    private static function mesasValidas(array $mesas): array
    {
        return array_values(array_filter($mesas, static function ($mesa): bool {
            return (int)self::valor($mesa, 'id', 0) > 0
                && (int)self::valor($mesa, 'activo', 1) === 1
                && (int)self::valor($mesa, 'reservable', 1) === 1
                && (string)self::valor($mesa, 'tipo', 'mesa') === 'mesa'
                && (int)self::valor($mesa, 'capacidad', 0) > 0;
        }));
    }

    /** @param array<int, object|array<string, mixed>> $mesas */
    private static function capacidad(array $mesas): int
    {
        return array_reduce(
            $mesas,
            static fn(int $total, $mesa): int => $total + (int)self::valor($mesa, 'capacidad', 0),
            0
        );
    }

    /** @param array<int, object|array<string, mixed>> $mesas */
    private static function mesaIds(array $mesas): array
    {
        $ids = array_map(
            static fn($mesa): int => (int)self::valor($mesa, 'id', 0),
            $mesas
        );
        $ids = self::ids($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function contexto(
        DateTimeImmutable $objetivo,
        DateTimeImmutable $ahora
    ): string {
        $fechaObjetivo = $objetivo->format('Y-m-d');
        $fechaActual = $ahora->format('Y-m-d');
        if ($fechaObjetivo < $fechaActual) {
            return self::CONTEXTO_HISTORICO;
        }
        if ($fechaObjetivo > $fechaActual) {
            return self::CONTEXTO_FUTURO;
        }

        return $objetivo <= $ahora
            ? self::CONTEXTO_ACTUAL
            : self::CONTEXTO_PROYECTADO;
    }

    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $valor = trim($fecha) . ' ' . trim($hora);
        $fechaHora = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $valor,
            ReservacionConfig::timezone()
        );
        $errores = DateTimeImmutable::getLastErrors();
        if (
            !$fechaHora
            || ($errores !== false && (
                ($errores['warning_count'] ?? 0) > 0
                || ($errores['error_count'] ?? 0) > 0
            ))
        ) {
            return null;
        }

        return $fechaHora;
    }

    private static function fechaHoraTicket(string $valor): ?DateTimeImmutable
    {
        try {
            $fecha = new DateTimeImmutable(trim($valor), ReservacionConfig::timezone());
            return trim($valor) === '' ? null : $fecha;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function hayTraslapeHorario(string $horaA, string $horaB): bool
    {
        $a = self::minutosDesdeHora($horaA);
        $b = self::minutosDesdeHora($horaB);
        $inicioA = $a - ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO;
        $finA = $a + ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        $inicioB = $b - ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO;
        $finB = $b + ReservacionConfig::DURACION_RESERVACION_MINUTOS;

        return $inicioA < $finB && $inicioB < $finA;
    }

    private static function minutosDesdeHora(string $hora): int
    {
        $partes = explode(':', $hora);
        return ((int)($partes[0] ?? 0) * 60) + (int)($partes[1] ?? 0);
    }

    /** @return array<int, int> */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function valor($registro, string $campo, $default = null)
    {
        if (is_array($registro)) {
            return $registro[$campo] ?? $default;
        }

        return is_object($registro) ? ($registro->{$campo} ?? $default) : $default;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function unicos(array $items, string ...$campos): array
    {
        $unicos = [];
        foreach ($items as $item) {
            $clave = implode(':', array_map(
                static fn(string $campo): string => (string)($item[$campo] ?? ''),
                $campos
            ));
            $unicos[$clave] = $item;
        }

        return array_values($unicos);
    }
}
