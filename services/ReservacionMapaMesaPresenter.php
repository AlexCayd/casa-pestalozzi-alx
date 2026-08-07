<?php

namespace Services;

/**
 * Proyección visual exclusiva del mapa administrativo.
 *
 * Recibe el bloqueo físico ya evaluado por OcupacionMesasService. No calcula
 * traslapes, ventanas de proximidad ni estados de POS: sólo traduce el hecho
 * canónico a los cuatro estados visuales del mapa.
 */
final class ReservacionMapaMesaPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    public static function presentar(array $hechos): array
    {
        if (!self::booleano($hechos['utilizable'] ?? false)) {
            return self::resultado('no-utilizable', [], 'no utilizable', 'no-utilizable');
        }

        if (self::booleano($hechos['bloqueada_en_intervalo'] ?? false)) {
            return self::resultado(
                'ocupada',
                [],
                self::etiquetaBloqueo((array)($hechos['causas_bloqueo'] ?? [])),
                'ocupacion'
            );
        }

        return self::resultado('libre', [], 'disponible', 'disponible');
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
