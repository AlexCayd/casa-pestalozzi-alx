<?php

namespace Services;

/**
 * Proyección visual exclusiva del mapa administrativo.
 *
 * Recibe hechos de intervalo y de proyección temporal ya evaluados. No calcula
 * traslapes, ventanas, capacidad ni permisos: sólo traduce hechos a estados
 * visuales del mapa.
 */
final class ReservacionMapaMesaPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    public static function presentar(array $hechos): array
    {
        if (!self::booleano($hechos['utilizable'] ?? false)) {
            return self::resultado('no-utilizable', [], 'no utilizable', 'no-utilizable');
        }

        $estado = 'libre';
        $modificadores = [];
        $label = 'disponible';
        $precedencia = 'disponible';

        if (self::booleano($hechos['ticket_bloquea_consulta'] ?? false)) {
            $estado = 'ocupada';
            $label = 'no disponible por ticket';
            $precedencia = 'ticket';
        }

        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        if ($reservacion !== [] && $estado !== 'ocupada') {
            $ventana = (string)($reservacion['ventana_mapa'] ?? 'futura');
            $influyeEnConsulta = self::booleano(
                $reservacion['reservacion_influye_en_consulta']
                    ?? $reservacion['reservacion_influye_mapa']
                    ?? false
            );
            if ($influyeEnConsulta) {
                $estado = 'ocupada';
                $modificadores[] = 'reservacion_bloqueante';
                $label = 'reservacion dentro del intervalo planificado';
                $precedencia = 'reservacion_influye';
            } elseif ($ventana === 'inicio' || $ventana === 'tolerancia') {
                $estado = 'ocupada';
                $modificadores[] = 'reservacion_bloqueante';
                $label = $ventana === 'inicio'
                    ? 'reservación iniciada'
                    : 'reservación dentro de tolerancia';
                $precedencia = 'reservacion_influye';
            } elseif ($ventana === 'bloqueo') {
                $estado = 'reservacion-proxima';
                $modificadores[] = 'reservacion_inminente';
                $label = 'reservación próxima';
                $precedencia = 'reservacion_bloqueo';
            } elseif ($ventana === 'advertencia') {
                $modificadores[] = 'reservacion_advertencia';
                $label = 'reservación cercana';
                $precedencia = 'reservacion_advertencia';
            }
        }

        if ($estado === 'libre' && $reservacion === []
            && self::booleano($hechos['bloqueada_en_intervalo'] ?? false)) {
            $estado = 'ocupada';
            $label = self::etiquetaBloqueo((array)($hechos['causas_bloqueo'] ?? []));
            $precedencia = 'ocupacion';
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
        $causas = array_values(array_unique(array_map('strval', $causas)));
        if (in_array('ticket', $causas, true)) {
            return 'no disponible por ticket';
        }
        if (in_array('reservacion', $causas, true)) {
            return 'no disponible por reservación';
        }
        if (in_array('hold', $causas, true)) {
            return 'no disponible por retención';
        }
        return 'no disponible';
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }
}
