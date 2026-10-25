<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Enteros escalados: nunca se convierte una cantidad o un costo a float. */
final class DecimalInventario
{
    public const MAX_CANTIDAD = 999999999999;

    public static function leer(mixed $valor, int $escala = 3, bool $negativo = false): int
    {
        $digitos = $escala === 3 ? 12 : 10;
        if (!is_string($valor) || !preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,' . $escala . '}))?$/D', $valor, $m)) {
            throw new InventarioError('DECIMAL_INVALIDO', 'Usa una cadena decimal con la precisión admitida.');
        }
        if ($m[1] === '-' && !$negativo) throw new InventarioError('DECIMAL_INVALIDO', 'La cantidad no puede ser negativa.');
        $entero = ltrim($m[2] . str_pad($m[3] ?? '', $escala, '0'), '0');
        if (strlen($entero) > $digitos) throw new InventarioError('DECIMAL_FUERA_RANGO', 'El decimal supera el rango admitido.');
        return ($m[1] === '-' ? -1 : 1) * (int)($entero === '' ? '0' : $entero);
    }

    public static function formato(int $valor, int $escala = 3): string
    {
        $cifras = str_pad((string)abs($valor), $escala + 1, '0', STR_PAD_LEFT);
        return ($valor < 0 ? '-' : '') . substr($cifras, 0, -$escala) . '.' . substr($cifras, -$escala);
    }

    public static function cantidad(int $valor): string
    {
        if (abs($valor) > self::MAX_CANTIDAD) throw new InventarioError('DECIMAL_FUERA_RANGO', 'La cantidad supera DECIMAL(12,3).');
        return self::formato($valor);
    }

    public static function sumar(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new InventarioError('DECIMAL_FUERA_RANGO', 'El total supera el rango de cálculo exacto.');
        }
        return $a + $b;
    }
}
