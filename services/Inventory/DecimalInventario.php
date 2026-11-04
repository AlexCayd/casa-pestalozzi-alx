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

    /**
     * «8 piezas + 450 ml» para un artículo con contenido por pieza; null si no
     * aplica o el saldo no es positivo. Sólo lectura: nunca redondea el saldo.
     */
    public static function enPiezas(string $saldo, ?string $contenido, string $unidad): ?string
    {
        if ($contenido === null || $contenido === '') return null;
        $s = self::leer($saldo, 3, true);
        $c = self::leer($contenido);
        if ($s <= 0 || $c <= 0) return null;
        $piezas = intdiv($s, $c);
        $resto = $s % $c;
        $texto = $piezas === 1 ? '1 pieza' : $piezas . ' piezas';
        if ($resto === 0) return $texto;
        $restoTxt = rtrim(rtrim(self::formato($resto), '0'), '.');
        return ($piezas > 0 ? $texto . ' + ' : '') . $restoTxt . ' ' . $unidad;
    }

    public static function sumar(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new InventarioError('DECIMAL_FUERA_RANGO', 'El total supera el rango de cálculo exacto.');
        }
        return $a + $b;
    }
}
