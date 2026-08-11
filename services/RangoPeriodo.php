<?php

namespace Services;

/**
 * Rango de fechas compartido por los tableros del admin (analíticas, finanzas e
 * inventario).
 *
 * Antes cada módulo resolvía su periodo por su cuenta: analíticas con presets y
 * rango libre, finanzas fija al mes en curso e inventario sin filtro. Con una
 * sola resolución los tres hablan del mismo periodo y pueden compararlo contra
 * el inmediatamente anterior.
 *
 * Las fechas se validan con regex antes de salir de aquí porque los consumidores
 * las interpolan en SQL crudo.
 */
class RangoPeriodo
{
    private const FORMATO = '/^\d{4}-\d{2}-\d{2}$/';

    /** Presets ofrecidos por el selector, en días. */
    public const PRESETS = [3, 7, 30, 60, 365];

    private const ETIQUETAS = [
        3 => 'Últimos 3 días',
        7 => 'Últimos 7 días',
        30 => 'Últimos 30 días',
        60 => 'Últimos 60 días',
        365 => 'Último año',
    ];

    /**
     * @param array<string, mixed> $query Normalmente $_GET.
     * @return array{
     *     start: string, end: string, preset: int, label: string, dias: int,
     *     comparar: bool, prevStart: string, prevEnd: string, prevLabel: string
     * }
     */
    public static function resolver(array $query, int $presetPorDefecto = 30): array
    {
        $desde = trim((string) ($query['desde'] ?? ''));
        $hasta = trim((string) ($query['hasta'] ?? ''));

        if (preg_match(self::FORMATO, $desde) && preg_match(self::FORMATO, $hasta) && $desde <= $hasta) {
            $rango = [
                'start' => $desde,
                'end' => $hasta,
                'preset' => 0,
                'label' => 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            ];
        } else {
            $preset = (int) ($query['rango'] ?? $presetPorDefecto);
            if (!in_array($preset, self::PRESETS, true)) {
                $preset = $presetPorDefecto;
            }

            $rango = [
                'start' => date('Y-m-d', strtotime('-' . ($preset - 1) . ' days')),
                'end' => date('Y-m-d'),
                'preset' => $preset,
                'label' => self::ETIQUETAS[$preset] ?? ('Últimos ' . $preset . ' días'),
            ];
        }

        return $rango + self::comparativo($rango['start'], $rango['end'], !empty($query['comparar']));
    }

    /**
     * Periodo anterior de la misma duración, pegado al inicio del actual. Es la
     * comparación que evita confundir "vendimos más" con "el periodo era más
     * largo".
     *
     * @return array{comparar: bool, dias: int, prevStart: string, prevEnd: string, prevLabel: string}
     */
    private static function comparativo(string $start, string $end, bool $comparar): array
    {
        $dias = self::dias($start, $end);
        $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($dias - 1) . ' days'));

        return [
            'comparar' => $comparar,
            'dias' => $dias,
            'prevStart' => $prevStart,
            'prevEnd' => $prevEnd,
            'prevLabel' => 'Del ' . date('d/m/Y', strtotime($prevStart))
                . ' al ' . date('d/m/Y', strtotime($prevEnd)),
        ];
    }

    /** Días que abarca el rango, ambos extremos incluidos. Nunca menos de 1. */
    public static function dias(string $start, string $end): int
    {
        $inicio = strtotime($start);
        $fin = strtotime($end);
        if ($inicio === false || $fin === false || $fin < $inicio) {
            return 1;
        }

        return (int) floor(($fin - $inicio) / 86400) + 1;
    }

    /**
     * Variación porcentual entre dos magnitudes del mismo tipo. Devuelve null
     * cuando la base es cero: un "+∞ %" no le dice nada a nadie.
     */
    public static function delta(float $actual, float $previo): ?float
    {
        if ($previo <= 0.0) {
            return null;
        }

        return round((($actual - $previo) / $previo) * 100, 1);
    }
}
