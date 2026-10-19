<?php

declare(strict_types=1);

/*
 * Variantes contra la base de desarrollo (bloque 4 de development.sql).
 *
 * Todo corre dentro de una transacción que se revierte al final: no deja
 * tickets, movimientos ni stock cambiados. Sin el bloque 4 cargado se salta.
 *
 *   php scripts/tests/run-variantes-db.php
 */

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Inventory\Inventario;
use Services\Menu\Variantes;

$db = ActiveRecord::getDB();
$assertions = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$assertions, $db): void {
    if (!$condicion) {
        $db->rollback();
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
    $assertions++;
};
$uno = static fn (string $sql) => ($r = $db->query($sql)) ? $r->fetch_row()[0] ?? null : null;
$variante = static fn (string $producto, string $grupo, string $nombre): int => (int) $uno(
    "SELECT v.id FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
      WHERE p.nombre = '" . $db->real_escape_string($producto) . "' AND v.grupo = '" . $db->real_escape_string($grupo) . "'
        AND v.nombre = '" . $db->real_escape_string($nombre) . "'"
);
$stock = static fn (string $ingrediente): float => (float) $uno(
    "SELECT stock FROM ingredientes WHERE nombre = '" . $db->real_escape_string($ingrediente) . "'"
);

$capId = (int) $uno("SELECT id FROM productos WHERE nombre = 'Cappuccino'");
$almendra = $variante('Cappuccino', 'Leche', 'Almendra');
$grande = $variante('Cappuccino', 'Tamaño', 'Grande');
if (!$capId || !$almendra || !$grande) {
    fwrite(STDOUT, "SKIP: sin el bloque 4 de development.sql (variantes) cargado.\n");
    exit(0);
}

$db->begin_transaction();

// 1 · Resolver: predeterminadas, rótulo, precio y errores.
$r = Variantes::resolver($capId, [$almendra, $grande]);
$assert($r['ok'], 'Almendra + Grande es una selección válida.');
$assert($r['etiqueta'] === 'Almendra · Grande', 'El rótulo omite las predeterminadas (' . $r['etiqueta'] . ').');
$assert(abs($r['delta'] - 30.0) < 0.001, 'Almendra (+15) y Grande (+15) suman 30.');
$assert(count($r['ids']) === 3, 'Los ids incluyen la predeterminada del grupo no tocado (Jarabe).');

$base = Variantes::resolver($capId, []);
$assert($base['ok'] && $base['etiqueta'] === null && $base['delta'] == 0.0, 'Sin elegir nada va la receta base, sin rótulo.');

$ribeye = (int) $uno("SELECT id FROM productos WHERE nombre = 'Rib Eye (450 grs.)'");
$sinTermino = Variantes::resolver($ribeye, []);
$assert(!$sinTermino['ok'] && str_contains((string) $sinTermino['error'], 'Término'), 'El término es obligatorio.');

$ajena = $variante('Latte', 'Leche', 'Avena');
$assert(!Variantes::resolver($capId, [$ajena])['ok'], 'Un id de otro platillo se rechaza.');
$assert(!Variantes::resolver($capId, [$almendra, $variante('Cappuccino', 'Leche', 'Avena')])['ok'], 'Dos opciones del mismo grupo se rechazan.');

// 2 · Descuento con variantes: dos Cappuccino grandes con almendra.
$antesLeche = $stock('Leche');
$antesAlmendra = $stock('Leche de almendra');
$db->query("INSERT INTO tickets (comensales, estado) VALUES (1, 'abierto')");
$ticketId = (int) $db->insert_id;
$db->query(
    "INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, variante_ids, variante, estado)
     VALUES ({$ticketId}, 'Cappuccino', 105, 'Café & Bebidas', 1, 2, '" . implode(',', $r['ids']) . "', 'Almendra · Grande', 'enviado')"
);
$itemId = (int) $db->insert_id;
Inventario::aplicarVenta('Cappuccino', 2, $itemId, $r['ids']);

$assert(abs(($antesAlmendra - $stock('Leche de almendra')) - 360.0) < 0.01,
    'Dos grandes con almendra descuentan 360 ml de almendra: la sustitución alcanza también la leche que suma «Grande».');
$assert(abs($antesLeche - $stock('Leche')) < 0.01, 'Y no tocan la leche entera.');
$movs = (int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE ticket_item_id = {$itemId} AND tipo = 'venta'");
$assert($movs > 0, 'La venta deja movimientos ligados al ticket_item.');

// 3 · Cancelar repone exactamente lo que se movió, sin volver a la receta.
Inventario::revertir($itemId);
$assert(abs($stock('Leche de almendra') - $antesAlmendra) < 0.01, 'Cancelar repone la almendra.');

// 4 · Costo: la combinación cuesta más que la base.
$assert(Inventario::costoDeProducto($capId, $r['ids']) > Inventario::costoDeProducto($capId), 'Almendra + Grande cuesta más que la receta base.');

// 5 · Catálogo del POS: grupos comprimidos con la regla de obligatoriedad.
$pos = Variantes::paraPos();
$assert(!empty($pos[$capId]), 'El Cappuccino lleva grupos en CP_MENU.');
$grupoLeche = array_values(array_filter($pos[$capId], static fn ($g) => $g['n'] === 'Leche'))[0] ?? null;
$assert($grupoLeche !== null && $grupoLeche['req'] === false, '«Leche» tiene predeterminada: no es obligatorio.');
$grupoTermino = array_values(array_filter($pos[$ribeye] ?? [], static fn ($g) => $g['n'] === 'Término'))[0] ?? null;
$assert($grupoTermino !== null && $grupoTermino['req'] === true, '«Término» no tiene predeterminada: es obligatorio.');

$db->rollback();
fwrite(STDOUT, "Variantes contra la base: {$assertions} comprobaciones OK (transacción revertida)\n");
