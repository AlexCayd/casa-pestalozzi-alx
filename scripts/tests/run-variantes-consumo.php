<?php
declare(strict_types=1);

/**
 * Variantes con sustitución de inventario y almacén de consumo elegido a mano,
 * sobre una base aleatoria marcada creada solo con DDL/deploy versionados.
 *
 *   php scripts/tests/run-variantes-consumo.php --host <DB_HOST> --base <DB_NAME> --entorno development|test
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__ . '/soporte-3d.php';

use Model\ActiveRecord;
use Services\Inventory\Almacenes;
use Services\Inventory\AlmacenesConsumo;
use Services\Menu\RecetasOferta;
use Services\Menu\Variantes;
use Services\Pos\Comercial;

$options = getopt('', ['host:', 'base:', 'entorno:']);
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
// Solo arranca contra el origen configurado; las escrituras van a una base temporal propia.
if (($options['host'] ?? '') !== ($_ENV['DB_HOST'] ?? null) || ($options['base'] ?? '') !== ($_ENV['DB_NAME'] ?? null)
    || !in_array($options['entorno'] ?? '', ['development', 'test'], true) || ($options['entorno'] ?? '') !== ($_ENV['APP_ENV'] ?? '')) {
    fwrite(STDERR, "Identifica el origen configurado: --host, --base y --entorno deben coincidir con includes/.env.\n");
    exit(1);
}

function variantesLogin(array $actor): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = ['login' => true, 'id' => (int) $actor['id'], 'auth_nivel' => 'contrasena', '_auth3b' => true];
}

function variantesInsert(mysqli $db, string $sql, array $values): int
{
    $db->prepare($sql)->execute($values);
    return (int) $db->insert_id;
}

function variantesSaldo(mysqli $db, int $almacen, int $articulo): string
{
    $s = $db->prepare('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?');
    $s->execute([$almacen, $articulo]);
    return (string) $s->get_result()->fetch_row()[0];
}

$db = null; $bases = []; $count = 0; $error = null; $fase = 'conexión';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
    restaurante3dCrearBase($db, $root, $bases);
    ActiveRecord::setDB($db);

    $fase = 'fixture de oferta, artículos y almacenes';
    $unidad = (int) $db->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $categoria = variantesInsert($db, "INSERT INTO categorias(unidad_id,carta,nombre,activo,orden) VALUES (?,'comida','Bebidas QA',1,0)", [$unidad]);
    $area = variantesInsert($db, "INSERT INTO areas_produccion(unidad_id,nombre,slug,color,orden) VALUES (?,'Barra QA','barra_qa','#aabbcc',0)", [$unidad]);
    $producto = variantesInsert($db, "INSERT INTO productos(nombre) VALUES ('Café con leche QA')", []);
    $oferta = variantesInsert($db, 'INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES (?,?,?,45.00,?,1,0)', [$producto, $unidad, $categoria, $area]);
    $cafe = variantesInsert($db, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES ('Café QA','g',0.5000,1)", []);
    $entera = variantesInsert($db, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES ('Leche entera QA','ml',0.0200,1)", []);
    $deslactosada = variantesInsert($db, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES ('Leche deslactosada QA','ml',0.0300,1)", []);
    $huerfano = variantesInsert($db, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES ('Jarabe sin almacén QA','ml',0.1000,1)", []);
    $barra = variantesInsert($db, "INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo) VALUES (?,'Barra QA',0,1)", [$unidad]);
    $refri = variantesInsert($db, "INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo) VALUES (?,'Refrigeración QA',0,1)", [$unidad]);
    foreach ([[$barra, $cafe], [$barra, $entera], [$barra, $deslactosada], [$refri, $deslactosada]] as [$alm, $art]) {
        variantesInsert($db, 'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,0,0,1)', [$alm, $art, $unidad]);
    }
    $pos = FixtureRestaurante3D::usuario($db, 'pos-variantes', ['pdv' => 'operar']);
    $recetas = FixtureRestaurante3D::usuario($db, 'recetas-variantes', ['productos' => 'editar', 'menu' => 'editar']);
    $inventario = FixtureRestaurante3D::usuario($db, 'inventario-variantes', ['inventario' => 'editar']);
    variantesLogin($inventario);
    $motor = new Almacenes($db, (int) $inventario['id'], 'contrasena');
    foreach ([[$barra, $cafe], [$barra, $entera], [$barra, $deslactosada], [$refri, $deslactosada]] as $i => [$alm, $art]) {
        $clave = 'qa-variantes-apertura-' . $i;
        $r = $motor->registrarEntrada('restaurante', ['clave' => $clave, 'referencia' => $clave, 'origen' => 'motor_inventario', 'motivo' => 'apertura',
            'lineas' => [['almacen_id' => $alm, 'articulo_id' => $art, 'cantidad' => '1000.000', 'costo_unitario' => '0.0200']]]);
        restaurante3dAssert(($r['ok'] ?? false) === true, 'Entrada de apertura del fixture: ' . json_encode($r, JSON_UNESCAPED_UNICODE), $count);
    }

    $fase = 'receta base y variante que sustituye la leche';
    variantesLogin($recetas);
    $receta = RecetasOferta::guardar('restaurante', $oferta, [
        ['tipo' => 'ingrediente', 'ref_id' => $cafe, 'cantidad' => '10'],
        ['tipo' => 'ingrediente', 'ref_id' => $entera, 'cantidad' => '200'],
    ]);
    restaurante3dAssert(($receta['ok'] ?? false) === true, 'Se guarda la receta base: ' . json_encode($receta, JSON_UNESCAPED_UNICODE), $count);
    restaurante3dAssert(AlmacenesConsumo::pendientes('restaurante', $oferta) === 2, 'Una receta nueva queda pendiente hasta elegir almacenes.', $count);
    $variantes = Variantes::guardar($oferta, [['nombre' => 'Leche', 'opciones' => [
        ['nombre' => 'Entera', 'precio_delta' => 0, 'predeterminada' => true, 'ajustes' => []],
        ['nombre' => 'Deslactosada', 'precio_delta' => 5, 'predeterminada' => false, 'ajustes' => [
            ['accion' => 'sustituir', 'tipo' => 'ingrediente', 'ref_id' => $entera, 'cantidad' => '200', 'nuevo_tipo' => 'ingrediente', 'nuevo_ref_id' => $deslactosada, 'nueva_cantidad' => '200'],
        ]],
    ]]], 'restaurante');
    restaurante3dAssert(($variantes['ok'] ?? false) === true, 'Se guardan las variantes: ' . json_encode($variantes, JSON_UNESCAPED_UNICODE), $count);
    $ids = [];
    foreach ($db->query("SELECT nombre,id FROM producto_variantes WHERE oferta_id=$oferta")->fetch_all() as [$n, $id]) $ids[$n] = (int) $id;
    $filas = array_column(AlmacenesConsumo::paraFormulario('restaurante', $oferta), null, 'articulo_id');
    restaurante3dAssert(count($filas) === 3 && isset($filas[$cafe], $filas[$entera], $filas[$deslactosada]),
        'El formulario lista receta base y el artículo que entra por la variante.', $count);
    restaurante3dAssert(array_keys($filas[$deslactosada]['opciones']) == [$barra, $refri] || array_keys($filas[$deslactosada]['opciones']) == [$refri, $barra],
        'Solo se ofrecen los almacenes del negocio donde está dado de alta el artículo.', $count);

    $fase = 'venta rechazada sin almacén de consumo';
    variantesLogin($pos);
    $antes = (int) $db->query('SELECT COUNT(*) FROM ticket_items')->fetch_row()[0];
    $ticket = variantesInsert($db, "INSERT INTO tickets(unidad_id,unidad_tipo,comensales,nombre,mesero_id,estado) VALUES (?,'negocio',1,'QA variantes',?,'abierto')", [$unidad, $pos['id']]);
    $linea = ['oferta_id' => $oferta, 'cantidad' => 2, 'comensal' => 1, 'variantes' => [$ids['Deslactosada']], 'nota' => null];
    $rechazo = Comercial::enviarComanda($ticket, [$linea], 'qa-variantes-sin-almacen', 'restaurante');
    restaurante3dAssert(($rechazo['ok'] ?? true) === false && ($rechazo['codigo'] ?? '') === 'ALMACEN_ORIGEN_FALTANTE'
        && (int) $db->query('SELECT COUNT(*) FROM ticket_items')->fetch_row()[0] === $antes,
        'Sin almacén elegido la comanda se rechaza completa y no busca otro origen.', $count);
    restaurante3dDenegado(fn() => AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => $barra, $entera => $barra, $deslactosada => $barra]),
        'El mesero sin permiso de Recetas no puede asignar almacenes.', $count);

    $fase = 'validación de asignaciones';
    variantesLogin($recetas);
    $incompleto = AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => $barra, $entera => $barra]);
    restaurante3dAssert(($incompleto['ok'] ?? true) === false && ($incompleto['codigo'] ?? '') === 'ALMACEN_CONSUMO_REQUERIDO',
        'Cada artículo requerido exige elegir almacén.', $count);
    $ajeno = AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => $refri, $entera => $barra, $deslactosada => $barra]);
    restaurante3dAssert(($ajeno['ok'] ?? true) === false, 'No se acepta un almacén donde el artículo no está dado de alta.', $count);
    $ok = AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => (string) $barra, $entera => (string) $barra, $deslactosada => (string) $refri]);
    restaurante3dAssert(($ok['ok'] ?? false) === true && AlmacenesConsumo::pendientes('restaurante', $oferta) === 0,
        'Con todos los almacenes elegidos no quedan pendientes: ' . json_encode($ok, JSON_UNESCAPED_UNICODE), $count);

    $fase = 'venta con variante: mismo producto, inventario dividido';
    variantesLogin($pos);
    $venta = Comercial::enviarComanda($ticket, [$linea, ['oferta_id' => $oferta, 'cantidad' => 1, 'comensal' => 1, 'variantes' => [], 'nota' => null]], 'qa-variantes-venta', 'restaurante');
    restaurante3dAssert(($venta['ok'] ?? false) === true, 'La comanda con variante se acepta: ' . json_encode($venta, JSON_UNESCAPED_UNICODE), $count);
    restaurante3dAssert(variantesSaldo($db, $refri, $deslactosada) === '600.000' && variantesSaldo($db, $barra, $deslactosada) === '1000.000',
        'La deslactosada se descuenta (2 × 200) solo del almacén elegido.', $count);
    restaurante3dAssert(variantesSaldo($db, $barra, $entera) === '800.000' && variantesSaldo($db, $barra, $cafe) === '970.000',
        'La entera solo baja por la línea sin variante; el café baja en ambas.', $count);
    $items = $db->query("SELECT producto_id,nombre,precio,variante_ids,variante,nota FROM ticket_items WHERE ticket_id=$ticket ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    restaurante3dAssert(count($items) === 2 && (int) $items[0]['producto_id'] === $producto && $items[0]['nombre'] === $items[1]['nombre']
        && $items[0]['precio'] === '50.00' && $items[0]['variante_ids'] === (string) $ids['Deslactosada']
        && str_contains((string) $items[0]['variante'], 'Deslactosada') && $items[0]['nota'] === null,
        'La variante queda registrada bajo el mismo producto, con su precio y sin usar la nota.', $count);

    $fase = 'artículo sin almacén posible y limpieza de asignaciones';
    variantesLogin($recetas);
    $receta2 = RecetasOferta::guardar('restaurante', $oferta, [
        ['tipo' => 'ingrediente', 'ref_id' => $cafe, 'cantidad' => '10'],
        ['tipo' => 'ingrediente', 'ref_id' => $huerfano, 'cantidad' => '5'],
    ]);
    restaurante3dAssert(($receta2['ok'] ?? false) === true, 'Se guarda una receta con un artículo sin almacén.', $count);
    $sinAlmacen = AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => $barra, $deslactosada => $refri, $huerfano => $barra]);
    restaurante3dAssert(($sinAlmacen['ok'] ?? true) === false && ($sinAlmacen['codigo'] ?? '') === 'ARTICULO_SIN_ALMACEN',
        'Un artículo sin alta en almacenes del negocio se explica en vez de guardarse.', $count);
    variantesInsert($db, 'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,0,0,1)', [$barra, $huerfano, $unidad]);
    $limpio = AlmacenesConsumo::guardar('restaurante', $oferta, [$cafe => $barra, $deslactosada => $refri, $huerfano => $barra, $entera => $barra]);
    restaurante3dAssert(($limpio['ok'] ?? false) === true
        && (int) $db->query("SELECT COUNT(*) FROM oferta_consumos WHERE oferta_id=$oferta AND articulo_id=$entera")->fetch_row()[0] === 0,
        'Guardar retira artículos que la receta y las variantes ya no consumen.', $count);
} catch (Throwable $e) {
    $error = "[$fase] " . ($e instanceof mysqli_sql_exception ? 'Error SQL aislado, código ' . $e->getCode() . ': ' . $e->getMessage() : $e->getMessage());
} finally {
    if ($db instanceof mysqli) {
        foreach (array_reverse($bases) as [$name, $token]) {
            try {
                if (!restaurante3dBasePropia($db, $name, $token, (string) ($_ENV['DB_NAME'] ?? ''))) throw new RuntimeException('Propietario no verificado.');
                $db->query('DROP DATABASE ' . restaurante3dIdent($name));
            } catch (Throwable) { $error ??= 'No se verificó la limpieza de la base temporal.'; }
        }
        $db->close();
    }
}
$result = ['ok' => $error === null, 'fase' => 'Variantes y almacén de consumo', 'comprobaciones' => $count];
if ($error !== null) $result['error'] = $error;
fwrite($error === null ? STDOUT : STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
exit($error === null ? 0 : 1);
