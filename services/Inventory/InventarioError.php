<?php
declare(strict_types=1);
namespace Services\Inventory;

final class InventarioError extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
