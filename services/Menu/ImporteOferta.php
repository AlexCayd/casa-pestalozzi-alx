<?php
declare(strict_types=1);
namespace Services\Menu;

/** Contrato DECIMAL(8,2), sin redondeos implícitos ni exponentes. */
final class ImporteOferta
{
    public static function normalizar(mixed $valor): string
    {
        if (!is_scalar($valor) || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', (string)$valor)) {
            throw new \InvalidArgumentException('El precio debe tener hasta seis enteros y dos decimales.');
        }
        $partes = explode('.', (string)$valor);
        return (string)(int)$partes[0] . '.' . str_pad($partes[1] ?? '', 2, '0');
    }
}
