<?php

declare(strict_types=1);

/*
 * Subrecetas por lote contra una base AISLADA (ddl.sql + deploy.sql), que el
 * runner crea y borra:
 *
 *   php scripts/tests/run-notifications-isolated.php run-subrecetas-produccion-db.php
 *   npm run test:subrecetas
 *
 * Comprueba la regla del modelo: producir saca los ingredientes del almacén y
 * suma la subreceta; vender descuenta la subreceta por lote (no sus
 * ingredientes) y desarma la al momento; cancelar repone lo que se movió; el
 * costo del platillo sigue siendo el de sus ingredientes.
 */

use Model\ActiveRecord;
use Services\Inventory\Inventario;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar las subrecetas por lote.\n");
    exit(1);
}

require $root . '/includes/app.php';

$db = ActiveRecord::getDB();
$comprobaciones = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$comprobaciones): void {
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    $comprobaciones++;
};
$cerca = static fn (float $a, float $b): bool => abs($a - $b) < 0.0005;
$uno = static function (string $sql) use ($db) {
    $res = $db->query($sql);
    $fila = $res ? $res->fetch_row() : null;
    return $fila[0] ?? null;
};
$stockIng = static fn (int $id): float => (float) $uno("SELECT stock FROM ingredientes WHERE id = {$id}");
$stockSub = static fn (int $id): float => (float) $uno("SELECT stock FROM subrecetas WHERE id = {$id}");
$insertar = static function (string $sql) use ($db): int {
    $db->query($sql);
    return (int) $db->insert_id;
};

// ── Catálogo de la prueba ─────────────────────────────────────────
$jitomate = $insertar("INSERT INTO ingredientes (nombre, unidad, stock, costo) VALUES ('Jitomate prueba', 'g', 5000, 0.02)");
$chile = $insertar("INSERT INTO ingredientes (nombre, unidad, stock, costo) VALUES ('Chile prueba', 'g', 100, 0.1)");
$cafe = $insertar("INSERT INTO ingredientes (nombre, unidad, stock, costo) VALUES ('Café prueba', 'g', 1000, 0.3)");

$salsa = $insertar("INSERT INTO subrecetas (nombre, unidad, rendimiento, por_lote) VALUES ('Salsa prueba', 'ml', 1000, 1)");
$db->query("INSERT INTO subreceta_ingredientes (subreceta_id, ingrediente_id, cantidad) VALUES ({$salsa}, {$jitomate}, 800), ({$salsa}, {$chile}, 50)");
$shot = $insertar("INSERT INTO subrecetas (nombre, unidad, rendimiento, por_lote) VALUES ('Shot prueba', 'ml', 60, 0)");
$db->query("INSERT INTO subreceta_ingredientes (subreceta_id, ingrediente_id, cantidad) VALUES ({$shot}, {$cafe}, 18)");

$categoria = (int) $uno('SELECT id FROM categorias ORDER BY id LIMIT 1');
$area = (int) $uno('SELECT id FROM areas_produccion ORDER BY id LIMIT 1');
$platillo = $insertar(
    "INSERT INTO productos (nombre, descripcion, categoria_id, precio, area_id)
     VALUES ('Platillo prueba subrecetas', 'Prueba', {$categoria}, 100, {$area})"
);
$db->query(
    "INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad) VALUES
     ({$platillo}, 'subreceta', {$salsa}, 80), ({$platillo}, 'subreceta', {$shot}, 60)"
);

// ── 1 · Producir un lote ──────────────────────────────────────────
$r = Inventario::producirSubreceta($salsa, 1000, 'Lote de prueba', null);
$assert($r['ok'], 'Producir un lote: ' . $r['mensaje']);
$assert($cerca($stockIng($jitomate), 4200), 'Un lote saca 800 g de jitomate.');
$assert($cerca($stockIng($chile), 50), 'Un lote saca 50 g de chile.');
$assert($cerca($stockSub($salsa), 1000), 'La salsa sube a 1000 ml.');
$assert($cerca((float) $uno("SELECT costo_total FROM producciones_subreceta WHERE id = {$r['produccion_id']}"), 21.0),
    'El lote congela su costo: 800 × 0.02 + 50 × 0.1 = 21.');
$assert((int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE produccion_id = {$r['produccion_id']} AND tipo = 'produccion'") === 3,
    'Un lote deja tres movimientos: dos ingredientes y la subreceta.');
$assert((int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE produccion_id = {$r['produccion_id']} AND ingrediente_id IS NOT NULL AND subreceta_id IS NOT NULL") === 0,
    'Cada movimiento mueve una sola existencia.');

// ── 2 · Producir lote y medio: escala, y avisa del negativo ───────
$r = Inventario::producirSubreceta($salsa, 2500, '', null);
$assert($r['ok'], 'Producir 2.5 lotes: ' . $r['mensaje']);
$assert($cerca($stockIng($jitomate), 2200), '2500 ml sacan 2000 g de jitomate.');
$assert($cerca($stockIng($chile), -75), 'El chile puede quedar en negativo: la cocina ya cocinó.');
$assert($r['negativos'] === ['Chile prueba'] && str_contains($r['mensaje'], 'negativo'), 'El aviso nombra lo que quedó en negativo.');
$assert($cerca($stockSub($salsa), 3500), 'La salsa suma lo producido.');

// ── 3 · Vender: la salsa baja; sus ingredientes, no; el shot se desarma ──
$item = 987654;
Inventario::aplicarVenta('Platillo prueba subrecetas', 2, $item);
$assert($cerca($stockSub($salsa), 3340), 'Dos platillos descuentan 160 ml de salsa.');
$assert($cerca($stockIng($jitomate), 2200), 'Vender no vuelve a sacar el jitomate de la salsa.');
$assert($cerca($stockIng($cafe), 964), 'El shot al momento se desarma: 2 × 18 g de café.');
$assert($cerca((float) $uno("SELECT cantidad FROM movimientos_inventario WHERE ticket_item_id = {$item} AND subreceta_id = {$salsa} AND tipo = 'venta'"), -160),
    'La venta deja el movimiento en la subreceta.');
$assert($stockSub($shot) == 0.0, 'La subreceta al momento no lleva existencia.');

// ── 4 · Cancelar repone exactamente lo que se movió ───────────────
Inventario::revertir($item);
$assert($cerca($stockSub($salsa), 3500), 'Cancelar repone la salsa.');
$assert($cerca($stockIng($cafe), 1000), 'Cancelar repone el café.');
Inventario::revertir($item);
$assert($cerca($stockSub($salsa), 3500), 'Cancelar dos veces no repone dos veces.');

// ── 5 · El costo del platillo sigue siendo el de sus ingredientes ──
// 80 ml de salsa (21 / 1000 por ml) + 60 ml de shot (18 g × 0.3 / 60 por ml).
$assert($cerca(round(Inventario::costoDeProducto($platillo), 4), round(80 * 0.021 + 60 * (18 * 0.3 / 60), 4)),
    'costoDeProducto desarma también la subreceta por lote.');
$assert($cerca(round(Inventario::costoUnitarioSubreceta($salsa), 4), 0.021), 'Costo unitario teórico de la salsa.');

// ── 6 · Conteo y merma ────────────────────────────────────────────
$r = Inventario::ajustarSubreceta($salsa, 3000, null);
$assert($r['ok'] && $cerca($stockSub($salsa), 3000), 'El conteo fija la existencia.');
$assert($cerca((float) $uno("SELECT cantidad FROM movimientos_inventario WHERE subreceta_id = {$salsa} AND tipo = 'ajuste' ORDER BY id DESC LIMIT 1"), -500),
    'La diferencia queda como ajuste.');

$r = Inventario::registrarMermaSubreceta($salsa, 200, 'caducidad', 'Se cortó', null);
$assert($r['ok'] && $cerca($stockSub($salsa), 2800), 'La merma descuenta la salsa.');
// El último lote costó 2000 × 0.02 + 125 × 0.1 = 52.5 por 2500 ml.
$assert($cerca(round($r['valor'], 4), 200 * 52.5 / 2500), 'La merma se valoriza al costo del último lote.');
$assert(!Inventario::registrarMermaSubreceta($salsa, 10, 'inventado', '', null)['ok'], 'Un motivo fuera del catálogo se rechaza.');

// ── 7 · Lo que no se produce ──────────────────────────────────────
$assert(!Inventario::producirSubreceta($shot, 60, '', null)['ok'], 'Una subreceta al momento no se produce.');
$assert(!Inventario::ajustarSubreceta($shot, 10, null)['ok'], 'Ni se cuenta.');
$assert(!Inventario::producirSubreceta($salsa, 0, '', null)['ok'], 'Producir cero se rechaza.');
$assert(!Inventario::producirSubreceta($salsa, Inventario::MAX_CANTIDAD + 1, '', null)['ok'], 'Una cantidad desmedida se rechaza.');
$db->query("UPDATE subrecetas SET activo = 0 WHERE id = {$salsa}");
$assert(!Inventario::producirSubreceta($salsa, 1000, '', null)['ok'], 'Una inactiva no se produce.');
$db->query("UPDATE subrecetas SET activo = 1 WHERE id = {$salsa}");
$vacia = $insertar("INSERT INTO subrecetas (nombre, unidad, rendimiento, por_lote) VALUES ('Vacía prueba', 'g', 100, 1)");
$assert(!Inventario::producirSubreceta($vacia, 100, '', null)['ok'], 'Sin ingredientes no se produce.');

$assert(count(Inventario::lotesDeSubreceta($salsa)) === 2, 'El historial trae los dos lotes.');

// ── 8 · Borrar la subreceta no devuelve lo que se gastó ───────────
$db->query("DELETE FROM subrecetas WHERE id = {$salsa}");
$assert((int) $uno("SELECT COUNT(*) FROM producciones_subreceta WHERE subreceta_id = {$salsa}") === 0, 'Sus lotes se van con ella.');
$assert((int) $uno("SELECT COUNT(*) FROM movimientos_inventario WHERE ingrediente_id = {$jitomate} AND tipo = 'produccion' AND produccion_id IS NULL") === 2,
    'Las salidas de ingredientes de sus lotes se quedan, sin lote.');

fwrite(STDOUT, "Subrecetas por lote: {$comprobaciones} comprobaciones OK\n");
