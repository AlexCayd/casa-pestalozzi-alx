<?php

namespace Services\Reservations;

/**
 * Proyección visual exclusiva del mapa administrativo.
 *
 * Recibe hechos de intervalo ya evaluados. La disponibilidad decide el fondo;
 * la cercanía de una reservación sólo añade una señal secundaria.
 */
final class ReservacionMapaMesaPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    public static function presentar(array $hechos): array
    {
        if (!self::booleano($hechos['utilizable'] ?? false)) {
            return self::resultado('no-utilizable', [], 'no utilizable', 'no-utilizable');
        }

        $causas = array_values(array_unique(array_map('strval', (array)($hechos['causas_bloqueo'] ?? []))));
        $ticketBloquea = self::booleano($hechos['ticket_bloquea_consulta'] ?? false);
        $bloqueada = self::booleano($hechos['bloqueada_en_intervalo'] ?? false);
        $reservacionSeSolapa = $bloqueada && in_array('reservacion', $causas, true);
        $reservacionBloquea = $reservacionSeSolapa
            && !self::bloqueoIndependiente($causas);

        // Primero decide exclusivamente el hecho de intervalo. Las ventanas
        // temporales POS nunca cambian por sí solas el estado base del mapa.
        if ($ticketBloquea) {
            $estado = 'ocupada';
            $label = 'no disponible por ticket';
            $precedencia = 'ticket';
        } elseif ($bloqueada && $reservacionBloquea) {
            $estado = 'reservacion-proxima';
            $label = 'no disponible por reservación';
            $precedencia = 'reservacion_intervalo';
        } elseif ($bloqueada) {
            $estado = 'ocupada';
            $label = self::etiquetaBloqueo($causas);
            $precedencia = 'restriccion_intervalo';
        } else {
            $estado = 'libre';
            $label = 'disponible';
            $precedencia = 'disponible';
        }

        $modificadores = [];
        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        $ventana = (string)($reservacion['ventana_mapa'] ?? 'futura');
        $advertencia = self::booleano($reservacion['reservacion_cercana_mapa'] ?? false)
            || $ventana === 'advertencia';

        // Una reserva que ya explica el bloqueo no repite la misma señal como
        // advertencia; una reserva consecutiva sí puede advertir sin bloquear.
        if ($advertencia && !$reservacionSeSolapa) {
            $modificadores[] = 'reservacion_advertencia';
            if ($estado === 'libre') {
                $label = 'disponible con reservación cercana';
                $precedencia = 'reservacion_advertencia';
            } else {
                $label .= '; reservación cercana';
            }
        }

        if (self::booleano($hechos['asignada_actualmente'] ?? false)) {
            $modificadores[] = 'asignada_actualmente';
        }

        $ausenciaPendiente = $reservacion !== []
            && (self::booleano($reservacion['ausencia_pendiente_mapa'] ?? false)
                || self::booleano($reservacion['ausencia_pendiente'] ?? false));
        if ($ausenciaPendiente) {
            $modificadores[] = 'ausencia_pendiente';
            $label .= '. Acción pendiente: registrar ausencia';
        }

        return self::resultado(
            $estado,
            array_values(array_unique($modificadores)),
            $label,
            $precedencia
        );
    }

    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    private static function resultado(string $estado, array $modificadores, string $label, string $precedencia): array
    {
        return [
            'estado_visual' => $estado,
            'modificadores' => $modificadores,
            'label' => $label,
            'precedencia' => $precedencia,
        ];
    }

    /** @param array<int, mixed> $causas */
    private static function etiquetaBloqueo(array $causas): string
    {
        if (in_array('ticket', $causas, true)) {
            return 'no disponible por ticket';
        }
        if (in_array('hold', $causas, true)) {
            return 'no disponible por retención';
        }
        if (in_array('reservacion', $causas, true)) {
            return 'no disponible por reservación';
        }
        return 'no disponible para el intervalo seleccionado';
    }

    /** @param array<int, mixed> $causas */
    private static function bloqueoIndependiente(array $causas): bool
    {
        return array_intersect(['ticket', 'hold', 'ocupacion'], $causas) !== [];
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }
}
