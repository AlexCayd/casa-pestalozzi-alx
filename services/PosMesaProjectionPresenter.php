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

        if (self::booleano($hechos['ticket_bloquea_consulta'] ?? false)) {
            return self::resultado('ocupada', ['ticket_abierto'], 'ticket', 'Mesa ocupada por ticket abierto.');
        }

        $reservacion = is_array($hechos['reservacion'] ?? null)
            ? $hechos['reservacion']
            : [];
        if ($reservacion !== []) {
            if (self::booleano($reservacion['en_inicio_exacto'] ?? false)) {
                return self::resultado('reservacion-proxima', ['reservacion_bloqueante'], 'reservacion_exacta', 'Mesa con reservación a la hora de inicio.');
            }
            if (self::booleano($reservacion['en_tolerancia'] ?? false)) {
                return self::resultado('reservacion-proxima', ['reservacion_tolerancia'], 'tolerancia', 'Mesa con reservación dentro de tolerancia.');
            }
            $minutos = self::enteroNulo($reservacion['minutos_para_inicio'] ?? null);
            if ($minutos !== null && $minutos > 0 && $minutos <= 30) {
                return self::resultado('reservacion-proxima', ['reservacion_inminente'], 'reservacion_0_30', 'Mesa con reservación próxima.');
            }
            if ($minutos !== null && $minutos > 30 && $minutos <= 60) {
                return self::resultado('libre', ['reservacion_advertencia'], 'reservacion_30_60', 'Mesa disponible con reservación próxima.');
            }
            if (self::booleano($reservacion['tolerancia_vencida'] ?? false)
                && self::booleano($reservacion['ausencia_pendiente'] ?? false)) {
                return self::resultado('libre', ['accion_pendiente', 'AUSENCIA_PENDIENTE'], 'tolerancia_vencida', 'Mesa disponible con ausencia pendiente.');
            }
        }

        return self::resultado('libre', [], 'disponible', 'Mesa disponible.');
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
