<?php

/**
 * Lector único del contrato POS–reservaciones.
 *
 * Este servicio concentra las lecturas que antes se repetían en POS y en el
 * panel de operación. No asigna mesas ni cambia estados: sólo compone filas,
 * ocupación física y la serialización canónica para sus consumidores.
 */

namespace Services;

use DateTimeImmutable;
use Model\Mesa;
use Model\Reservacion;
use Model\TicketMesa;

final class PosReservacionQueryService
{
    /**
     * @return array<string, mixed>
     */
    public static function paraFecha(
        string $fecha,
        string $hora = '',
        array $opciones = []
    ): array {
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return [
                'ok' => false,
                'codigo' => 'FECHA_INVALIDA',
                'mensaje' => 'La fecha solicitada no es válida.',
            ];
        }

        $ahora = $opciones['ahora'] ?? ReservacionConfig::ahora();
        if (!$ahora instanceof DateTimeImmutable) {
            $ahora = ReservacionConfig::ahora();
        }

        $incluirInactivas = (bool)($opciones['incluir_inactivas'] ?? false);
        $mesasLeidas = $incluirInactivas
            ? Mesa::buscarTodasParaMapa()
            : Mesa::consultarSQL(
                "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
                 FROM mesas
                 WHERE activo = 1
                 ORDER BY numero ASC, id ASC"
            );
        $mesas = array_map(
            static fn($mesa): array => PosReservacionSerializer::mesa($mesa),
            $mesasLeidas
        );

        $filasReservaciones = Reservacion::buscarPorDiaOperacionAdmin($fecha);
        $ticketsLeidos = TicketMesa::abiertosParaMapa();
        $tickets = array_map(
            static fn(array $ticket): array => PosReservacionSerializer::ticket($ticket),
            $ticketsLeidos
        );
        $ticketsPorReservacion = [];
        foreach ($tickets as $ticket) {
            $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
            if ($reservacionId > 0) {
                $ticketsPorReservacion[$reservacionId] = $ticket;
            }
        }

        $ocupacionPorReservacion = [];
        if (($opciones['calcular_conflictos'] ?? true) === true) {
            $ocupacionPorReservacion = AsignacionMesasService::obtenerOcupacionPorReservacionDelDia(
                $fecha,
                $filasReservaciones,
                $ticketsLeidos
            );
        }

        $reservaciones = [];
        foreach ($filasReservaciones as $fila) {
            $reservacionId = (int)($fila->id ?? 0);
            $mesaIds = self::ids($fila->mesa_ids ?? '');
            $reservaciones[] = PosReservacionSerializer::reservacion(
                $fila,
                $ticketsPorReservacion[$reservacionId] ?? null,
                $mesas,
                $ahora,
                [
                    'conflicto_fisico' => self::hayConflicto(
                        $ocupacionPorReservacion[$reservacionId] ?? [],
                        $reservacionId,
                        $mesaIds
                    ),
                    'incluir_contexto_administrativo' => !empty($opciones['incluir_contexto_administrativo']),
                ]
            );
        }

        $horaEvaluacion = HorarioReservacionService::normalizarHoraSql($hora);
        if ($horaEvaluacion === '') {
            $horaEvaluacion = $ahora->format('H:i:s');
        }
        $evaluacionOcupacion = [];
        try {
            $evaluacionOcupacion = OcupacionMesasService::evaluarHorario(
                $fecha,
                $horaEvaluacion,
                0,
                false,
                $ticketsLeidos,
                $ahora
            );
        } catch (\Throwable $error) {
            // La lectura del contrato sigue siendo útil aunque el horario no
            // pueda evaluarse; el consumidor recibe una lista vacía de estados.
            $evaluacionOcupacion = [
                'ok' => false,
                'contexto' => OcupacionMesasService::CONTEXTO_HISTORICO,
                'ocupacion_bloqueante' => [],
                'ocupacion_reservaciones' => [],
                'tickets_por_mesa' => [],
                'fisica' => [],
                'alertas_operativas' => [],
            ];
        }

        $tickets = self::adjuntarAdvertencias($tickets, $reservaciones);
        $mesasEstado = MesaEstadoService::normalizarMesas(
            $mesas,
            $reservaciones,
            $tickets,
            $fecha,
            $ahora,
            $horaEvaluacion,
            $evaluacionOcupacion
        );

        $config = ReservacionConfig::configuracionOperacion();
        $config['server_time'] = $ahora->format(DATE_ATOM);
        $config['timezone'] = $ahora->getTimezone()->getName();

        return [
            'ok' => true,
            'codigo' => 'OK',
            'schema_version' => PosReservacionSerializer::SCHEMA_VERSION,
            'fecha' => $fecha,
            'hora' => $horaEvaluacion,
            'mesas' => $mesas,
            'mesas_estado' => $mesasEstado,
            'reservaciones' => $reservaciones,
            'reservaciones_operativas' => ReservacionVigenciaService::filtrarPendientesOperacion(
                $reservaciones,
                $fecha,
                []
            ),
            'tickets' => $tickets,
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'evaluacion_ocupacion' => $evaluacionOcupacion,
            'server_time' => $ahora->format(DATE_ATOM),
            'timezone' => $ahora->getTimezone()->getName(),
            'config' => [
                'temporal' => $config,
            ],
            'actualizado_en' => $ahora->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tickets
     * @param array<int, array<string, mixed>> $reservaciones
     * @return array<int, array<string, mixed>>
     */
    private static function adjuntarAdvertencias(array $tickets, array $reservaciones): array
    {
        foreach ($tickets as &$ticket) {
            $mesaIdsTicket = self::ids($ticket['mesa_ids'] ?? []);
            $proximas = [];
            foreach ($reservaciones as $reservacion) {
                if (
                    empty($reservacion['muestra_advertencia'])
                    || !self::intersecta($mesaIdsTicket, self::ids($reservacion['mesa_ids'] ?? []))
                ) {
                    continue;
                }
                $proximas[] = [
                    'schema_version' => PosReservacionSerializer::SCHEMA_VERSION,
                    'reservacion_id' => (int)$reservacion['reservacion_id'],
                    'id' => (int)$reservacion['reservacion_id'],
                    'hora' => (string)$reservacion['hora'],
                    'nombre' => (string)$reservacion['nombre'],
                    'mesa_ids' => $reservacion['mesa_ids'],
                    'ventana_operativa' => $reservacion['ventana_operativa'],
                    'minutos_para_reservacion' => $reservacion['minutos_para_reservacion'],
                    'muestra_advertencia' => true,
                ];
            }
            $ticket['reservaciones_proximas'] = $proximas;
            $ticket['muestra_advertencia'] = $proximas !== [];
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * @param array<int, array<string, mixed>> $ocupacion
     * @param array<int, int> $mesaIds
     */
    private static function hayConflicto(array $ocupacion, int $reservacionId, array $mesaIds): bool
    {
        foreach ($ocupacion as $mesaId => $evento) {
            $mesaId = (int)($evento['mesa_id'] ?? $mesaId);
            if (!in_array($mesaId, $mesaIds, true)) {
                continue;
            }
            $eventoReservacionId = (int)($evento['reservacion_id'] ?? 0);
            if ($eventoReservacionId > 0 && $eventoReservacionId === $reservacionId) {
                continue;
            }
            $eventoTicketReservacionId = (int)($evento['ticket_reservacion_id'] ?? 0);
            if ($eventoTicketReservacionId > 0 && $eventoTicketReservacionId === $reservacionId) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return array<int, int> */
    private static function ids($valor): array
    {
        if (is_string($valor)) {
            $valor = explode(',', $valor);
        }
        if (!is_array($valor)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $valor))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param array<int, int> $a @param array<int, int> $b */
    private static function intersecta(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }
}
