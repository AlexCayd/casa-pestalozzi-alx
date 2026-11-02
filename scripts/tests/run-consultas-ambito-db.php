<?php

declare(strict_types=1);

/*
 * Asistente IA de Panadería y Global (Services\Analytics\ConsultasRapidasAmbito)
 * contra la base de desarrollo.
 *
 * Todo corre dentro de UNA transacción que se revierte al final: siembra un
 * ticket de caja de Panadería y tres cuentas con permisos acotados, y no deja
 * nada. Comprueba:
 *   - la forma de cada respuesta (la misma que pinta el Asistente de
 *     Restaurante) y que el despacho por ámbito no toca a Restaurante;
 *   - el aislamiento: un id de otro negocio no revela nada, y un ámbito que no
 *     es el de la ruta se rechaza;
 *   - las cifras contra SQL directo: ventas en caja, consolidado de Global,
 *     entradas, productos sin receta y productos de la casa;
 *   - los permisos: cada familia de preguntas con su módulo y sin membresía,
 *     nada.
 *
 *   php scripts/tests/run-consultas-ambito-db.php   (necesita deploy.sql + development.sql)
 */

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Analytics\ConsultasRapidas as C;
use Services\Analytics\ConsultasRapidasAmbito as A;
use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio as N;

$entorno = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
if (!in_array($entorno, ['development', 'test'], true)) {
    echo "SKIP: sólo en desarrollo o pruebas.\n";
    exit(0);
}

$db = ActiveRecord::getDB();
$uno = static function (string $sql, array $args = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($args);
    $fila = $s->get_result()->fetch_row();
    return $fila[0] ?? null;
};
if (!$db instanceof mysqli || $uno("SELECT COUNT(*) FROM esquema_versiones WHERE version = 'multinegocio-3b-v1'") !== 1) {
    echo "SKIP: la base no tiene el esquema multinegocio.\n";
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$assertions): void {
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
    $assertions++;
};
$codigo = static function (callable $fn): string {
    try {
        $fn();
    } catch (InventarioError $e) {
        return $e->codigo;
    }
    return '';
};
// «$1,234.50», «12.5», «3» → número.
$num = static fn ($cifra): float => (float) str_replace([',', '$'], '', (string) $cifra);

$unidad = [];
foreach ($db->query('SELECT slug, id FROM unidades')->fetch_all(MYSQLI_ASSOC) as $u) {
    $unidad[$u['slug']] = (int) $u['id'];
}
$super = (int) $uno('SELECT id FROM usuarios WHERE es_superadmin = 1 AND activo = 1 ORDER BY id LIMIT 1');
if ($super === 0 || count($unidad) < 3) {
    echo "SKIP: faltan el superadministrador o las unidades (carga deploy.sql).\n";
    exit(0);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$como = static function (int $id): void {
    $_SESSION = ['id' => $id, 'auth_nivel' => 'contrasena', '_auth3b' => true, 'login' => true];
};
$como($super);

$forma = static function (array $r, string $caso) use ($assert): void {
    foreach (['ok', 'entendida', 'consulta', 'periodo', 'interpretacion', 'plantilla', 'respuesta', 'alternativas', 'sugerencias'] as $k) {
        $assert(array_key_exists($k, $r), "{$caso}: falta «{$k}»");
    }
    foreach ($r['plantilla'] as $p) {
        $assert(in_array($p['tipo'], ['texto', 'hueco'], true), "{$caso}: parte de plantilla sin tipo válido");
        if ($p['tipo'] === 'hueco') {
            foreach (['campo', 'rotulo', 'valor', 'etiqueta'] as $k) {
                $assert(array_key_exists($k, $p), "{$caso}: hueco sin «{$k}»");
            }
            $assert(isset($p['catalogo']) || isset($p['opciones']), "{$caso}: hueco sin catálogo ni opciones");
        }
    }
};

$db->begin_transaction();
try {
    // 1. Despacho por ámbito: cada uno con su catálogo, Restaurante intacto.
    $rest = N::en('restaurante', static fn () => C::capacidades());
    $pan = N::en('panaderia', static fn () => C::capacidades());
    $glo = N::en('global', static fn () => C::capacidades());
    $claves = static fn (array $c): array => array_column($c['intenciones'], 'intencion');
    $assert(in_array('meseros', $claves($rest), true), 'Restaurante conserva sus preguntas (meseros).');
    $assert($claves($pan) === A::INTENCIONES['panaderia'], 'Panadería ofrece su catálogo completo y en orden.');
    $assert($claves($glo) === A::INTENCIONES['global'], 'Global ofrece su catálogo completo y en orden.');
    $assert(!in_array('meseros', $claves($pan), true), 'La caja de Panadería no tiene meseros.');
    $assert(!array_intersect(['ventas_total', 'top', 'ticket_promedio'], $claves($glo)), 'Global no vende: sin preguntas de ventas.');
    $assert(count($pan['periodos']) === count(C::PERIODOS), 'Los periodos son los del motor.');

    // 2. El ámbito lo decide la ruta, no quien llama.
    $assert($codigo(static fn () => N::en('panaderia', static fn () => A::capacidades('global'))) === 'AMBITO_NO_DISPONIBLE', 'Desde Panadería no se consulta Global.');
    $assert($codigo(static fn () => N::en('restaurante', static fn () => A::catalogo('panaderia'))) === 'AMBITO_NO_DISPONIBLE', 'Desde Restaurante no se consulta Panadería.');

    // 3. Cada pregunta, por la vía estructurada, con la forma del Asistente.
    foreach (['panaderia' => $pan, 'global' => $glo] as $ctx => $capacidades) {
        $catalogo = N::en($ctx, static fn () => C::catalogo());
        foreach ($capacidades['intenciones'] as $i) {
            $int = $i['intencion'];
            $consulta = $i['ejemplo']['consulta'] ?? ['id' => null, 'periodo' => null];
            if (in_array($int, ['ventas_producto', 'ventas_categoria', 'stock', 'consumo', 'entradas'], true) && empty($consulta['id'])) {
                continue; // sin catálogo en esta base: no hay cosa que preguntar
            }
            $r = N::en($ctx, static fn () => C::responderEstructurada($int, $consulta['id'] ?? null, null, $consulta['periodo'] ?? 'semana'));
            $forma($r, "{$ctx}/{$int}");
            $assert($r['entendida'] === true && $r['respuesta']['tipo'] === $int, "{$ctx}/{$int}: entendida y con su tipo");
            $assert($r['interpretacion']['intencion'] === $int && $r['interpretacion']['texto'] !== '', "{$ctx}/{$int}: interpretación con texto");
            $assert(is_string($r['respuesta']['frase']) && $r['respuesta']['frase'] !== '', "{$ctx}/{$int}: con frase");
            $assert($i['descripcion'] !== '' && $i['plantilla'] !== [], "{$ctx}/{$int}: capacidades con descripción y plantilla");
        }
        $assert(N::en($ctx, static fn () => C::responderEstructurada('meseros'))['ok'] === false, "{$ctx}: una intención ajena no se reconoce");
    }
    $vacio = N::en('panaderia', static fn () => C::catalogo());
    $assert(($vacio['ventas'][0]['etiqueta'] ?? '') === 'Toda la panadería', 'El total de Panadería se llama por su nombre.');
    $assert(N::en('global', static fn () => C::catalogo())['ventas'] === [], 'Global no tiene lista de ventas.');

    // 4. Aislamiento: lo de otro negocio no existe aquí.
    $ofertaRest = (int) $uno('SELECT id FROM ofertas_producto WHERE unidad_id = ? ORDER BY id LIMIT 1', [$unidad['restaurante']]);
    $categoriaRest = (int) $uno('SELECT id FROM categorias WHERE unidad_id = ? ORDER BY id LIMIT 1', [$unidad['restaurante']]);
    $assert(N::en('panaderia', static fn () => C::responderEstructurada('ventas_producto', $ofertaRest, null, 'mes'))['entendida'] === false, 'Un producto de Restaurante no existe en Panadería.');
    $assert(N::en('panaderia', static fn () => C::responderEstructurada('ventas_categoria', $categoriaRest, null, 'mes'))['entendida'] === false, 'Una categoría de Restaurante no existe en Panadería.');
    $ajeno = (int) $uno('SELECT i.id FROM ingredientes i WHERE NOT EXISTS (SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id = i.id AND aa.unidad_id = ?) ORDER BY i.id LIMIT 1', [$unidad['panaderia']]);
    if ($ajeno > 0) {
        $assert(N::en('panaderia', static fn () => C::responderEstructurada('stock', $ajeno))['entendida'] === false, 'Un artículo fuera de los almacenes de Panadería no revela existencias.');
        $assert(N::en('global', static fn () => C::responderEstructurada('stock', $ajeno))['entendida'] === true, 'Global pregunta por cualquier artículo del catálogo maestro.');
    }

    // 5. Consolidado de Global frente a la existencia propia de Panadería.
    $compartido = (int) $uno('SELECT articulo_id FROM almacen_articulos GROUP BY articulo_id HAVING COUNT(DISTINCT unidad_id) >= 2 ORDER BY articulo_id LIMIT 1');
    if ($compartido > 0) {
        $saldo = static fn (?int $u) => (float) $uno('SELECT COALESCE(SUM(aa.saldo), 0) FROM almacen_articulos aa JOIN almacenes a ON a.id = aa.almacen_id AND a.unidad_id = aa.unidad_id
            WHERE aa.articulo_id = ? AND aa.activo = 1 AND a.activo = 1' . ($u !== null ? ' AND aa.unidad_id = ' . $u : ''), [$compartido]);
        $g = N::en('global', static fn () => C::responderEstructurada('stock', $compartido));
        $assert(abs($num($g['respuesta']['cifra']) - $saldo(null)) < 0.001, 'Global suma la existencia de todos los almacenes.');
        $assert(count($g['respuesta']['detalle']) >= 2 && str_contains($g['respuesta']['detalle'][0]['etiqueta'], ' · '), 'El consolidado se desglosa por negocio y almacén.');
        $enPan = (int) $uno('SELECT COUNT(*) FROM almacen_articulos WHERE articulo_id = ? AND unidad_id = ?', [$compartido, $unidad['panaderia']]);
        if ($enPan > 0) {
            $p = N::en('panaderia', static fn () => C::responderEstructurada('stock', $compartido));
            $assert(abs($num($p['respuesta']['cifra']) - $saldo($unidad['panaderia'])) < 0.001, 'Panadería sólo ve sus almacenes.');
        }
        $entradas = (float) $uno("SELECT COALESCE(SUM(delta), 0) FROM movimientos_inventario WHERE articulo_id = ? AND tipo = 'entrada' AND fecha >= ?",
            [$compartido, C::periodo('30d')['desde'] . ' 00:00:00']);
        $e = N::en('global', static fn () => C::responderEstructurada('entradas', $compartido, null, '30d'));
        $assert(abs($num($e['respuesta']['cifra']) - $entradas) < 0.001, 'Las entradas consolidadas cuadran con los movimientos.');
    }

    // 6. Menú y productos de la casa contra SQL directo.
    $sinReceta = (int) $uno('SELECT COUNT(*) FROM ofertas_producto o WHERE o.unidad_id = ? AND o.activo = 1
        AND NOT EXISTS (SELECT 1 FROM recetas r WHERE r.oferta_id = o.id AND r.unidad_id = o.unidad_id AND r.activo = 1)', [$unidad['panaderia']]);
    $assert($num(N::en('panaderia', static fn () => C::responderEstructurada('sin_receta'))['respuesta']['cifra']) == $sinReceta, 'Productos sin receta cuadra.');
    $casa = (int) $uno('SELECT COUNT(*) FROM producto_estandares');
    $assert($num(N::en('global', static fn () => C::responderEstructurada('productos_casa'))['respuesta']['cifra']) == $casa, 'Productos de la casa cuadra.');

    // 7. Ventas en caja: un ticket sembrado de Panadería se cuenta como en Restaurante.
    $ofertas = $db->query("SELECT o.id, o.producto_id, o.categoria_id, o.area_id, po.nombre, c.nombre AS categoria
        FROM ofertas_producto o JOIN productos_oferta po ON po.oferta_id = o.id JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
        WHERE o.unidad_id = {$unidad['panaderia']} AND o.activo = 1 AND c.activo = 1 ORDER BY o.id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
    if (count($ofertas) === 2) {
        $antes = N::en('panaderia', static fn () => C::responderEstructurada('ventas_total', null, null, 'hoy'));
        $antesA = N::en('panaderia', static fn () => C::responderEstructurada('ventas_producto', (int) $ofertas[0]['id'], null, 'hoy'));
        $antesCancel = N::en('panaderia', static fn () => C::responderEstructurada('cancelaciones', null, null, 'hoy'));
        $s = $db->prepare("INSERT INTO tickets (unidad_id, unidad_tipo, estado, nombre, hora_apertura, hora_cierre, metodo_pago)
            VALUES (?, 'negocio', 'cerrado', 'QA caja', CURDATE() + INTERVAL 12 HOUR, CURDATE() + INTERVAL 12 HOUR + INTERVAL 5 MINUTE, 'efectivo')");
        $s->execute([$unidad['panaderia']]);
        $ticket = (int) $db->insert_id;
        $item = $db->prepare('INSERT INTO ticket_items (unidad_id, ticket_id, oferta_id, producto_id, nombre, precio, categoria, categoria_id, area_id, cantidad, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([[$ofertas[0], '30.00', 3, 'entregado'], [$ofertas[1], '50.00', 1, 'entregado'], [$ofertas[0], '30.00', 1, 'cancelado']] as [$o, $precio, $cantidad, $estado]) {
            $item->execute([$unidad['panaderia'], $ticket, $o['id'], $o['producto_id'], $o['nombre'], $precio, $o['categoria'], $o['categoria_id'], $o['area_id'], $cantidad, $estado]);
        }
        $despues = N::en('panaderia', static fn () => C::responderEstructurada('ventas_total', null, null, 'hoy'));
        $assert(abs($num($despues['respuesta']['cifra']) - $num($antes['respuesta']['cifra']) - 140.0) < 0.001, 'Ventas en caja: 3×30 + 50, sin el renglón cancelado.');
        $despuesA = N::en('panaderia', static fn () => C::responderEstructurada('ventas_producto', (int) $ofertas[0]['id'], null, 'hoy'));
        $assert($num($despuesA['respuesta']['cifra']) - $num($antesA['respuesta']['cifra']) == 3.0, 'Piezas de un producto sin las canceladas.');
        $despuesCancel = N::en('panaderia', static fn () => C::responderEstructurada('cancelaciones', null, null, 'hoy'));
        $assert($num($despuesCancel['respuesta']['cifra']) - $num($antesCancel['respuesta']['cifra']) == 1.0, 'Cancelaciones en caja cuenta la pieza cancelada.');
        $top = N::en('panaderia', static fn () => C::responderEstructurada('top', null, null, 'hoy'));
        $assert(in_array($ofertas[0]['nombre'], array_column($top['respuesta']['detalle'], 'etiqueta'), true), 'Lo más vendido incluye el producto del ticket.');
        $sinVenta = N::en('panaderia', static fn () => C::responderEstructurada('sin_venta', null, null, 'hoy'));
        $assert(!in_array($ofertas[0]['nombre'], array_column($sinVenta['respuesta']['detalle'], 'etiqueta'), true), 'Un producto vendido hoy no sale sin venta.');
        $assert(N::en('restaurante', static fn () => C::responderEstructurada('ventas_total', null, null, 'hoy'))['respuesta']['cifra'] !== null, 'Restaurante sigue respondiendo con su motor.');
        $assert(!str_contains(json_encode($despues, JSON_UNESCAPED_UNICODE), '/admin/analytics'), 'Las ventas de Panadería no enlazan al análisis de Restaurante.');
    }

    // 8. Permisos: cada familia con su módulo; sin membresía, nada.
    $cuenta = static function (string $nombre, array $permisos) use ($db, $unidad): int {
        $s = $db->prepare("INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES (?, ?, 'sin-acceso', 1)");
        $usuario = 'qa_ia_' . $nombre . '_' . bin2hex(random_bytes(3));
        $s->execute([$usuario, 'QA ' . $nombre]);
        $id = (int) $db->insert_id;
        foreach ($permisos as $slug => $modulos) {
            $db->prepare('INSERT INTO usuario_unidades (usuario_id, unidad_id, activo) VALUES (?, ?, 1)')->execute([$id, $unidad[$slug]]);
            foreach ($modulos as $modulo) {
                $db->prepare("INSERT INTO usuario_unidad_permisos (usuario_id, unidad_id, modulo, nivel) VALUES (?, ?, ?, 'ver')")->execute([$id, $unidad[$slug], $modulo]);
            }
        }
        return $id;
    };
    $soloIaPan = $cuenta('ia_pan', ['panaderia' => ['ia']]);
    $soloMenuPan = $cuenta('menu_pan', ['panaderia' => ['menu']]);
    $iaGlobal = $cuenta('ia_glo', ['global' => ['ia']]);

    $como($soloIaPan);
    $assert($claves(N::en('panaderia', static fn () => C::capacidades())) === A::INTENCIONES['panaderia'], 'El Asistente de Panadería abre todas sus preguntas.');
    $assert($codigo(static fn () => N::en('global', static fn () => C::capacidades())) === 'AMBITO_DENEGADO', 'Sin membresía en Global no hay consolidado.');
    $assert($codigo(static fn () => N::en('restaurante', static fn () => C::capacidades())) !== '', 'El Asistente de Panadería no abre Restaurante.');

    $como($soloMenuPan);
    $assert($claves(N::en('panaderia', static fn () => C::capacidades())) === ['sin_receta'], 'Con sólo Menú se pregunta sólo por el menú.');
    $assert($codigo(static fn () => N::en('panaderia', static fn () => C::responderEstructurada('ventas_total', null, null, 'hoy'))) === 'PERMISO_DENEGADO', 'Sin el Asistente no hay ventas.');
    $assert($codigo(static fn () => N::en('panaderia', static fn () => C::responderEstructurada('bajo_stock'))) === 'PERMISO_DENEGADO', 'Sin Inventario ni Asistente no hay almacén.');

    $como($iaGlobal);
    $assert($claves(N::en('global', static fn () => C::capacidades())) === A::INTENCIONES['global'], 'El Asistente de Global abre el consolidado.');
    $assert($codigo(static fn () => N::en('panaderia', static fn () => C::capacidades())) === 'AMBITO_DENEGADO', 'Leer el consolidado no abre el Asistente de Panadería.');
} finally {
    $db->rollback();
    $_SESSION = [];
}

echo "Consultas de Panadería y Global: {$assertions} comprobaciones OK\n";
