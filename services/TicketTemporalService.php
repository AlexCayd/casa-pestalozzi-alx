<?php

/**
 * Contexto y proyección temporal canónica de tickets abiertos.
 *
 * La apertura y el cierre real del ticket determinan la ocupación física. La
 * liberación estimada sólo participa cuando se consulta un bloque futuro del
 * mismo día; nunca libera la fotografía operativa actual.
 */

namespace Services;

use DateTimeImmutable;

final class TicketTemporalService
{
    private const ESTADO_ABIERTO = 'abierto';
    public const CONTEXTO_ACTUAL = 'actual';
    public const CONTEXTO_PROYECTADO = 'proyectado';
    public const CONTEXTO_FUTURO = 'fecha_futura';
    public const CONTEXTO_HISTORICO = 'historico';

    /** @return array<string, mixed> */
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

    public static function calcularLiberacionEstimadaTicket(
        $horaApertura
    ): ?DateTimeImmutable {
        $apertura = $horaApertura instanceof DateTimeImmutable
            ? $horaApertura
            : self::fechaHoraTicket((string)$horaApertura);
        if (!$apertura) {
            return null;
        }

        return $apertura
            ->modify('+' . ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS . ' minutes')
            ->modify('+' . ReservacionConfig::RETRASO_ESTIMADO_TICKET_MINUTOS . ' minutes');
    }

    /** @param array<string, mixed>|object $ticket */
    public static function ticketEstaAbierto($ticket): bool
    {
        $ticket = self::aArray($ticket);
        if (array_key_exists('ticket_abierto', $ticket)) {
            $abierto = filter_var($ticket['ticket_abierto'], FILTER_VALIDATE_BOOL);
            if (!$abierto) {
                return false;
            }
            if (array_key_exists('estado', $ticket)
                && (string)$ticket['estado'] !== self::ESTADO_ABIERTO
            ) {
                return false;
            }
            if (array_key_exists('closed_at', $ticket) && $ticket['closed_at'] !== null) {
                return false;
            }
            return true;
        }

        return (string)($ticket['estado'] ?? '') === self::ESTADO_ABIERTO
            && ($ticket['closed_at'] ?? null) === null;
    }

    /**
     * @param array<string, mixed>|object $ticket
     * @return array<string, mixed>
     */
    public static function proyectar(
        $ticket,
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ticket = self::aArray($ticket);
        $contexto = self::contextoTemporal($fecha, $hora, $ahora);
        $abierto = self::ticketEstaAbierto($ticket);
        $ticketId = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
        $mesaIds = self::ids($ticket['mesa_ids'] ?? []);
        $apertura = self::fechaHoraTicket((string)($ticket['hora_apertura'] ?? ''));
        $liberacion = self::calcularLiberacionEstimadaTicket($apertura);
        $aplicaFecha = $abierto && (bool)$contexto['es_dia_actual'];
        $esProyeccion = $aplicaFecha && (bool)$contexto['es_proyeccion_futura'];
        $intervaloFin = $contexto['fecha_hora_consulta'] instanceof DateTimeImmutable
            ? $contexto['fecha_hora_consulta']->modify(
                '+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes'
            )
            : null;

        $bloquea = false;
        if ($aplicaFecha) {
            if ((bool)$contexto['es_bloque_actual']) {
                // La estimación jamás libera la fotografía física actual.
                $bloquea = true;
            } elseif ($esProyeccion) {
                // Intervalos semiabiertos: [inicio, fin). En el límite exacto
                // de liberación el ticket ya no participa en la proyección.
                $bloquea = $apertura === null || $liberacion === null
                    || ($intervaloFin !== null
                        && $apertura < $intervaloFin
                        && $liberacion > $contexto['fecha_hora_consulta']);
            }
        }

        $estadoProyeccion = !$abierto
            ? 'ignorado_ticket_cerrado'
            : (!$aplicaFecha
                ? 'ignorado_fecha'
                : ($bloquea ? 'ocupada' : 'liberado_proyectado'));

        return [
            'ticket_id' => $ticketId,
            'id' => $ticketId,
            'reservacion_id' => !empty($ticket['reservacion_id'])
                ? (int)$ticket['reservacion_id']
                : null,
            'origen' => (string)($ticket['origen'] ?? (!empty($ticket['reservacion_id']) ? 'reservacion' : 'walk_in')),
            'walk_in' => empty($ticket['reservacion_id']),
            'estado' => (string)($ticket['estado'] ?? ''),
            'closed_at' => $ticket['closed_at'] ?? null,
            'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
            'mesa_ids' => $mesaIds,
            'ocupada_fisicamente' => $abierto,
            'ticket_abierto' => $abierto,
            'aplica_fecha' => $aplicaFecha,
            'es_proyeccion' => $esProyeccion,
            'bloquea_disponibilidad' => $bloquea,
            'bloquea_en_consulta' => $bloquea,
            'disponible_proyectada' => $esProyeccion && !$bloquea,
            'tipo' => $esProyeccion && !$bloquea ? 'ticket_proyectado' : 'ticket_abierto',
            'estado_proyeccion' => $estadoProyeccion,
            'liberacion_estimada' => $liberacion?->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed>|object $registro */
    private static function aArray($registro): array
    {
        return is_array($registro) ? $registro : get_object_vars($registro);
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

    private static function fechaHoraTicket(string $valor): ?DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valor, ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
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

        $ids = array_values(array_unique(array_filter(array_map('intval', $valor), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
