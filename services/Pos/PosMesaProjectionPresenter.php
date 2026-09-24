<?php

namespace Services\Pos;

/**
 * Presentación exclusiva del POS.
 *
 * Recibe hechos comunes ya resueltos por MesaEstadoService. No calcula fechas,
 * minutos ni ventanas; sólo traduce esos hechos al contrato visual del POS.
 */
final class PosMesaProjectionPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,precedencia:string,aria_label:string} */
    public static function presentar(array $hechos): array
    {
        if (!self::booleano($hechos['utilizable'] ?? false)) {
            return self::resultado('no-utilizable', [], 'no-utilizable', 'Mesa no utilizable.');
        }

        $estado = 'libre';
        $modificadores = [];
        $precedencia = 'disponible';
        $ariaLabel = 'Mesa disponible.';

        $ticketAbierto = self::booleano($hechos['ticket_abierto'] ?? false);
        $ocupadaFisicamente = self::booleano($hechos['ocupada_fisicamente'] ?? false);
        $ticketPrioritario = $ticketAbierto || $ocupadaFisicamente
            || self::booleano($hechos['ticket_bloquea_consulta'] ?? false);
        if ($ticketPrioritario) {
            $estado = 'ocupada';
            $modificadores[] = 'ticket_abierto';
            $precedencia = 'ticket';
            $ariaLabel = 'Mesa ocupada por ticket abierto.';
        }

        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        if ($reservacion !== []) {
            $ventana = (string)($reservacion['ventana_visual_pos'] ?? $reservacion['ventana_pos'] ?? 'futura');
            $ausenciaPendiente = self::booleano($reservacion['ausencia_pendiente'] ?? false);
            if ($ausenciaPendiente) {
                $modificadores[] = 'accion_pendiente';
                if ($ventana === 'advertencia') {
                    $modificadores[] = 'reservacion_advertencia';
                } elseif ($ventana === 'bloqueo') {
                    $modificadores[] = 'reservacion_inminente';
                }
                $bloqueaWalkIns = array_key_exists('bloquea_walk_ins', $reservacion)
                    ? self::booleano($reservacion['bloquea_walk_ins'])
                    : !self::booleano($reservacion['disponible_para_ticket'] ?? false);
                if (!$ticketPrioritario && $bloqueaWalkIns) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'ausencia_pendiente';
                    $ariaLabel = 'Tolerancia vencida. Registra que el cliente no llegó antes de utilizar la mesa.';
                } elseif (!$ticketPrioritario) {
                    $ariaLabel = 'Hay una acción pendiente para esta reservación.';
                } else {
                    $ariaLabel .= ' Tolerancia vencida; revisa la reservación antes de continuar.';
                }
            } elseif ($ventana === 'inicio') {
                $modificadores[] = 'reservacion_bloqueante';
                if (!$ticketPrioritario) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'reservacion_inicio';
                    $ariaLabel = 'Mesa con reservación iniciada; espera al cliente.';
                } else {
                    $ariaLabel .= ' Reservación iniciada; espera al cliente.';
                }
            } elseif ($ventana === 'tolerancia') {
                $modificadores[] = 'reservacion_tolerancia';
                if (!$ticketPrioritario) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'tolerancia';
                    $ariaLabel = 'Mesa con reservación dentro de tolerancia; espera al cliente.';
                } else {
                    $ariaLabel .= ' Reservación dentro de tolerancia.';
                }
            } elseif ($ventana === 'bloqueo') {
                $modificadores[] = 'reservacion_inminente';
                if (!$ticketPrioritario) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'reservacion_bloqueo';
                    $ariaLabel = 'Mesa con reservación próxima.';
                } else {
                    $ariaLabel .= ' Reservación próxima.';
                }
            } elseif ($ventana === 'advertencia') {
                $modificadores[] = 'reservacion_advertencia';
                if (!$ticketPrioritario) {
                    $precedencia = 'reservacion_advertencia';
                    $ariaLabel = 'Mesa disponible con reservación próxima.';
                } else {
                    $ariaLabel .= ' Reservación cercana.';
                }
            }

        }

        if (self::booleano($hechos['asignada_actualmente'] ?? false)) {
            $modificadores[] = 'asignada_actualmente';
        }
        if ($reservacion !== [] && self::booleano($reservacion['ausencia_pendiente'] ?? false)) {
            $modificadores[] = 'ausencia_pendiente';
        }

        if (!$ticketPrioritario
            && array_key_exists('puede_abrir_ticket', $hechos)
            && !self::booleano($hechos['puede_abrir_ticket'])
            && $estado === 'libre') {
            $estado = 'ocupada';
            $modificadores[] = 'restriccion_operativa';
            $precedencia = 'restriccion_operativa';
            $ariaLabel = 'Mesa no disponible para abrir un ticket según las reglas operativas.';
        }

        return self::resultado(
            $estado,
            array_values(array_unique($modificadores)),
            $precedencia,
            $ariaLabel
        );
    }

    /** @return array{estado_visual:string,modificadores:array<int,string>,precedencia:string,aria_label:string} */
    private static function resultado(
        string $estado,
        array $modificadores,
        string $precedencia,
        string $ariaLabel
    ): array
    {
        return [
            'estado_visual' => $estado,
            'modificadores' => $modificadores,
            'precedencia' => $precedencia,
            'aria_label' => $ariaLabel,
        ];
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }

    private static function enteroNulo($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (int)$valor;
    }
}
