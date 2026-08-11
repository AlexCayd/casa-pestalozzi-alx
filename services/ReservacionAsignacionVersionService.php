<?php

namespace Services;

/**
 * Canonicaliza la identidad de una asignación para control de concurrencia.
 * El orden de la tabla pivote sigue siendo independiente y puede conservarse
 * para presentación o persistencia.
 */
final class ReservacionAsignacionVersionService
{
    /** @return array<int, int> */
    public static function normalizarMesaIds($mesaIds): array
    {
        if (is_string($mesaIds)) {
            $mesaIds = explode(',', $mesaIds);
        }
        if (!is_array($mesaIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mesaIds),
            static fn(int $mesaId): bool => $mesaId > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public static function calcular(string $marcaTemporal, $mesaIds): string
    {
        return hash(
            'sha256',
            $marcaTemporal . '|' . implode(',', self::normalizarMesaIds($mesaIds))
        );
    }
}
