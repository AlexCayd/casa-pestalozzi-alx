<?php

namespace Services\Reservations;

/**
 * Proyección visual exclusiva del mapa administrativo.
 *
 * Recibe hechos de ocupación y la ventana proyectada del mapa. El intervalo
 * completo conserva su autoridad para asignabilidad, no para decidir por sí
 * solo el estado visual.
 */
final class ReservacionMapaMesaPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    public static function presentar(array $hechos): array
    {
        if (!self::booleano($hechos['utilizable'] ?? false)) {
            return self::resultado('no-utilizable', [], 'No utilizable', 'no-utilizable');
        }

        $causas = array_values(array_unique(array_map('strval', (array)($hechos['causas_bloqueo'] ?? []))));
        $ticketBloquea = self::booleano($hechos['ticket_bloquea_consulta'] ?? false);
        $bloqueada = self::booleano($hechos['bloqueada_en_intervalo'] ?? false);
        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        $ventana = (string)($reservacion['ventana_mapa'] ?? 'futura');
        $bloqueoIndependiente = $bloqueada && self::bloqueoIndependiente($causas);

        // Tickets, holds y restricciones independientes conservan prioridad
        // roja. La reserva se presenta según la ventana del instante consultado.
        if ($ticketBloquea) {
            $estado = 'ocupada';
            $label = self::booleano($hechos['ocupada_fisicamente'] ?? false)
                ? 'Ocupada por servicio activo'
                : 'No disponible por ticket';
            $precedencia = 'ticket';
        } elseif ($bloqueoIndependiente) {
            $estado = 'ocupada';
            $label = self::etiquetaBloqueo($causas);
            $precedencia = 'restriccion_intervalo';
        } elseif (in_array($ventana, ['inicio', 'activa'], true)) {
            $estado = 'ocupada';
            $label = 'Ocupada por reservación';
            $precedencia = 'reservacion_activa';
        } elseif ($ventana === 'bloqueo') {
            $estado = 'reservacion-proxima';
            $label = 'Reservación próxima';
            $precedencia = 'reservacion_bloqueo_previo';
        } else {
            $estado = 'libre';
            $label = 'Disponible';
            $precedencia = 'disponible';
        }

        $modificadores = [];
        if ($ventana === 'advertencia') {
            $modificadores[] = 'reservacion_advertencia';
            if ($estado === 'libre') {
                $label = 'Disponible con reservación próxima';
                $precedencia = 'reservacion_advertencia';
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
            return 'No disponible por ticket';
        }
        if (in_array('hold', $causas, true)) {
            return 'No disponible por retención';
        }
        if (in_array('ocupacion', $causas, true)) {
            return 'No disponible para el intervalo seleccionado';
        }
        if (in_array('reservacion', $causas, true)) {
            return 'No disponible por reservación';
        }
        return 'No disponible para el intervalo seleccionado';
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
