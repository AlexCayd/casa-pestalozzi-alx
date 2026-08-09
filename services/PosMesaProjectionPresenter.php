<?php

namespace Services;

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

        if (self::booleano($hechos['ticket_bloquea_consulta'] ?? false)) {
            $estado = 'ocupada';
            $modificadores[] = 'ticket_abierto';
            $precedencia = 'ticket';
            $ariaLabel = 'Mesa ocupada por ticket abierto.';
        }

        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        if ($reservacion !== [] && $estado !== 'ocupada') {
            $ventana = (string)($reservacion['ventana_visual_pos'] ?? $reservacion['ventana_pos'] ?? 'futura');
            if ($ventana === 'inicio') {
                $estado = 'reservacion-proxima';
                $modificadores[] = 'reservacion_bloqueante';
                $precedencia = 'reservacion_inicio';
                $ariaLabel = 'Mesa con reservación operable; iniciar servicio.';
            } elseif ($ventana === 'tolerancia') {
                $estado = 'reservacion-proxima';
                $modificadores[] = 'reservacion_tolerancia';
                $precedencia = 'tolerancia';
                $ariaLabel = 'Mesa con reservación dentro de tolerancia.';
            } elseif ($ventana === 'bloqueo') {
                $estado = 'reservacion-proxima';
                $modificadores[] = 'reservacion_inminente';
                $precedencia = 'reservacion_bloqueo';
                $ariaLabel = 'Mesa con reservación próxima.';
            } elseif ($ventana === 'advertencia') {
                $modificadores[] = 'reservacion_advertencia';
                $precedencia = 'reservacion_advertencia';
                $ariaLabel = 'Mesa disponible con reservación próxima.';
            }

            if ($ventana === 'ausencia_pendiente'
                && self::booleano(
                    $reservacion['intervalo_planificado_vigente']
                        ?? $reservacion['bloquea_intervalo_reservacion']
                        ?? false
                )) {
                $estado = 'ocupada';
                $modificadores[] = 'reservacion_bloqueante';
                $precedencia = 'reservacion_intervalo';
                $ariaLabel = 'Mesa ocupada dentro del intervalo planificado.';
            }
        }

        if (self::booleano($hechos['asignada_actualmente'] ?? false)) {
            $modificadores[] = 'asignada_actualmente';
        }
        if ($reservacion !== [] && self::booleano($reservacion['ausencia_pendiente'] ?? false)) {
            $modificadores[] = 'ausencia_pendiente';
            $ariaLabel = rtrim($ariaLabel) . ' Acción pendiente: registrar ausencia.';
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
