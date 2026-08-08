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
            $ventana = (string)($reservacion['ventana_visual_pos'] ?? $reservacion['ventana_pos'] ?? 'futura');
            if (self::booleano($reservacion['ausencia_pendiente'] ?? false)) {
                return self::resultado(
                    'libre',
                    ['ausencia_pendiente'],
                    'ausencia_pendiente',
                    'Mesa disponible visualmente. Acción pendiente: registrar ausencia.'
                );
            }
            if ($ventana === 'inicio') {
                return self::resultado('reservacion-proxima', ['reservacion_bloqueante'], 'reservacion_inicio', 'Mesa con reservación operable; iniciar servicio.');
            }
            if ($ventana === 'tolerancia') {
                return self::resultado('reservacion-proxima', ['reservacion_tolerancia'], 'tolerancia', 'Mesa con reservación dentro de tolerancia.');
            }
            if ($ventana === 'bloqueo') {
                return self::resultado('reservacion-proxima', ['reservacion_inminente'], 'reservacion_bloqueo', 'Mesa con reservación próxima.');
            }
            if ($ventana === 'advertencia') {
                return self::resultado('libre', ['reservacion_advertencia'], 'reservacion_advertencia', 'Mesa disponible con reservación próxima.');
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
