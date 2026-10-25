<?php
declare(strict_types=1);

/** POS e inventario comercial 3D.2 en una base aislada y marcada. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once $root . '/scripts/tests/soporte-3d.php';

use MenuPreservacion\Comun;
use MenuPreservacion\ImportadorMenu;
use MenuPreservacion\VerificadorMenu;
use Classes\ImagenUploader;
use Model\ActiveRecord;
use Model\HistorialPrecio;
use Model\Producto;
use Model\Subreceta;
use Multinegocio\SqlArchivo;
use Services\Inventory\Almacenes;
use Services\Menu\Ofertas;
use Services\Menu\RecetasOferta;
use Services\Pos\Comercial;
use Services\Pos\PuntoVentaReservacionService;
use Services\Security\ContextoNegocio;
use Services\Users\NipService;

function restaurante3d2Assert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function restaurante3d2Call(callable $action, string $message, int &$count): array
{
    $result = $action();
    restaurante3d2Assert(is_array($result) && ($result['ok'] ?? false) === true && ($result['confirmado'] ?? true) === true,
        $message . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $count);
    return $result;
}

function restaurante3d2Denied(callable $action, string $message, int &$count): array
{
    try { $result = $action(); }
    catch (Throwable $e) { $count++; return ['exception' => $e::class]; }
    restaurante3d2Assert(is_array($result) && ($result['ok'] ?? true) === false, $message . ': ' . json_encode($result), $count);
    return $result;
}

function restaurante3d2Ticket(mysqli $db, int $unitId, string $name, int $actorId): int
{
    $stmt = $db->prepare("INSERT INTO tickets(unidad_id,unidad_tipo,comensales,nombre,mesero_id,estado) VALUES (?, 'negocio', 2, ?, ?, 'abierto')");
    $stmt->bind_param('isi', $unitId, $name, $actorId);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function restaurante3d2Login(int $actorId, string $level = 'nip'): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = ['login' => true, 'id' => $actorId, 'auth_nivel' => $level, '_auth3b' => true];
}

function restaurante3d2Insert(mysqli $db, string $sql, string $types, array $values): int
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $refs = [$types];
    foreach ($values as $index => &$value) $refs[] = &$value;
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function restaurante3d2StockEntry(Almacenes $service, int $storeId, int $articleId, string $amount, string $key, int &$count): void
{
    restaurante3d2Call(fn() => $service->registrarEntrada('restaurante', [
        'clave' => $key, 'referencia' => $key, 'origen' => 'motor_inventario', 'motivo' => 'apertura',
        'lineas' => [['almacen_id' => $storeId, 'articulo_id' => $articleId, 'cantidad' => $amount, 'costo_unitario' => '2.5000']],
    ]), 'Registrar entrada de apertura para fixture POS.', $count);
}

function restaurante3d2CreateBarrier(string $owner): array
{
    $root = sys_get_temp_dir();
    $dir = $root . DIRECTORY_SEPARATOR . 'casa-pestalozzi-3d2-' . bin2hex(random_bytes(10));
    if (!mkdir($dir, 0700)) throw new RuntimeException('No se pudo crear barrera de concurrencia temporal.');
    $marker = $dir . DIRECTORY_SEPARATOR . 'owner';
    $handle = fopen($marker, 'xb');
    if ($handle === false) throw new RuntimeException('No se pudo crear marca de barrera.');
    try { if (fwrite($handle, $owner) !== strlen($owner)) throw new RuntimeException('No se pudo escribir marca de barrera.'); }
    finally { fclose($handle); }
    return ['dir' => $dir, 'prefix' => 'sync_' . bin2hex(random_bytes(6))];
}

function restaurante3d2LaunchWorker(array $input, string $database, string $owner, array $config, string $root, array &$processes): array
{
    $env = getenv();
    if (!is_array($env)) $env = [];
    $env = array_merge($env, [
        'DB_HOST' => (string)$config['DB_HOST'], 'DB_USER' => (string)$config['DB_USER'], 'DB_PASS' => (string)$config['DB_PASS'],
        'DB_PORT' => (string)($config['DB_PORT'] ?? 3306), 'CP_3D2_DATABASE' => $database, 'CP_3D2_OWNER' => $owner,
    ]);
    $pipes = [];
    $proc = proc_open([PHP_BINARY, $root . '/scripts/tests/worker-restaurante-3d2.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar worker de concurrencia.');
    $processes[] = $proc;
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    return [$proc, $pipes];
}

function restaurante3d2WaitWorkers(array $workers, array $barrier, string $owner): array
{
    $dir = realpath($barrier['dir']);
    $tmp = realpath(sys_get_temp_dir());
    if ($dir === false || $tmp === false || !str_starts_with(strtolower($dir . DIRECTORY_SEPARATOR), strtolower($tmp . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('La barrera quedó fuera de TEMP.');
    }
    $deadline = microtime(true) + 30.0;
    do {
        $ready = glob($dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '-*.ready') ?: [];
        if (count($ready) >= count($workers)) break;
        if (microtime(true) >= $deadline) throw new RuntimeException('Workers no llegaron a la barrera.');
        usleep(10000);
    } while (true);
    foreach ($ready as $file) if (is_link($file) || !hash_equals($owner, trim((string)file_get_contents($file)))) throw new RuntimeException('Ready de worker sin owner válido.');
    $go = $dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '.go';
    if (file_put_contents($go, $owner, LOCK_EX) !== strlen($owner)) throw new RuntimeException('No se pudo liberar la barrera.');
    $results = [];
    foreach ($workers as [$proc, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);
        $results[] = ['exit' => $exit, 'result' => json_decode($stdout === false ? '' : $stdout, true), 'stderr' => $stderr];
    }
    return $results;
}

function restaurante3d2CleanBarrier(array $barrier, string $owner): void
{
    $dir = realpath((string)($barrier['dir'] ?? ''));
    $temp = realpath(sys_get_temp_dir());
    if ($dir === false) return;
    if ($temp === false || dirname($dir) !== $temp || is_link((string)$barrier['dir'])) throw new RuntimeException('Barrera temporal no pasó validación de limpieza.');
    $ownerFile = $dir . DIRECTORY_SEPARATOR . 'owner';
    if (!is_file($ownerFile) || !hash_equals($owner, trim((string)file_get_contents($ownerFile)))) throw new RuntimeException('Owner de barrera no coincide al limpiar.');
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_link($file) || !is_file($file) || dirname(realpath($file) ?: '') !== $dir) throw new RuntimeException('Archivo inesperado en barrera.');
        unlink($file);
    }
    rmdir($dir);
}

function restaurante3d2Conteos(mysqli $db): array
{
    $tables = ['tickets','ticket_items','ticket_pagos','operaciones_inventario','movimientos_inventario','operaciones_financieras','pos_operaciones'];
    $counts = [];
    foreach ($tables as $table) {
        if ($db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")->num_rows === 0) continue;
        $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . restaurante3dIdent($table))->fetch_row()[0];
    }
    return $counts;
}

function restaurante3d2Saldo(mysqli $db, int $storeId, int $articleId): string
{
    $stmt = $db->prepare('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?');
    $stmt->bind_param('ii', $storeId, $articleId);
    $stmt->execute();
    $value = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$value) throw new RuntimeException('No existe la asociación de saldo fixture.');
    return (string)$value[0];
}

function restaurante3d2Expected(array $result, string $code): void
{
    if (($result['ok'] ?? true) !== false || ($result['codigo'] ?? '') !== $code) {
        throw new RuntimeException('Se esperaba ' . $code . '; resultado=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

$admin = null;
$bases = [];
$assetRoot = null;
$assetParent = null;
$phase = 'argumentos/configuración';
$error = null;
$count = 0;
$output = null;
$workerProcesses = [];
$barrierDirs = [];
try {
    $options = restaurante3dOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $_ENV['NIP_LOOKUP_SECRET'] = bin2hex(random_bytes(32));
    putenv('NIP_LOOKUP_SECRET=' . $_ENV['NIP_LOOKUP_SECRET']);
    $config = $_ENV;
    Comun::origen($config, $options, $root);
    $valid = VerificadorMenu::verificar($options['paquete']);
    restaurante3d2Assert(($valid['ok'] ?? false) === true, 'El paquete comercial no superó la verificación.', $count);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión administrativa sin esquema';
    $admin = new mysqli((string)$config['DB_HOST'], (string)$config['DB_USER'], (string)$config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    $phase = 'DDL/deploy y paquete comercial en destino temporal';
    [$database, $owner] = restaurante3dCrearBase($admin, $root, $bases);
    ActiveRecord::setDB($admin);
    [$assetRoot, $assetParent] = restaurante3dCrearRaizImagenes();
    $import = ImportadorMenu::importar($admin, $options['paquete'], $assetRoot, $owner);
    restaurante3d2Assert(($import['ok'] ?? false) === true && ($import['conteos']['productos'] ?? null) === 112,
        'El menú preservado se importa íntegramente antes de preparar escenarios POS.', $count);

    $phase = 'actores sintéticos y ofertas de fixture';
    $restaurantUnit = (int)$admin->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $bakeryUnit = (int)$admin->query("SELECT id FROM unidades WHERE slug='panaderia'")->fetch_row()[0];
    $posActor = FixtureRestaurante3D::usuario($admin, 'pos-operar-nip', ['pdv' => 'operar']);
    $inventoryActor = FixtureRestaurante3D::usuario($admin, 'inventory-fixture', ['inventario' => 'editar']);
    $menuActor = FixtureRestaurante3D::usuario($admin, 'menu-fixture', ['menu' => 'editar', 'productos' => 'editar'], 'panaderia');
    $credential = NipService::credencial(str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT));
    $stmt = $admin->prepare('UPDATE usuarios SET nip_hash=?,nip_lookup=? WHERE id=?');
    $stmt->bind_param('ssi', $credential['hash'], $credential['lookup'], $posActor['id']); $stmt->execute(); $stmt->close();
    $posPermissionCount = (int)$admin->query("SELECT COUNT(*) FROM usuario_unidad_permisos WHERE usuario_id={$posActor['id']}")->fetch_row()[0];
    restaurante3d2Assert($posPermissionCount === 1 && (int)$admin->query("SELECT COUNT(*) FROM usuario_unidad_permisos WHERE usuario_id={$posActor['id']} AND modulo='pdv' AND nivel='operar'")->fetch_row()[0] === 1,
        'El actor de piso tiene exclusivamente PDV/operar directo.', $count);
    unset($credential);

    $restOffer = (int)$admin->query("SELECT o.id FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='restaurante' AND o.activo=1 ORDER BY o.id LIMIT 1")->fetch_row()[0];
    $noRecipeOffer = (int)$admin->query("SELECT o.id FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='restaurante' AND o.activo=1 AND o.id<>$restOffer AND NOT EXISTS (SELECT 1 FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1) ORDER BY o.id LIMIT 1")->fetch_row()[0];
    $restProductId = (int)$admin->query("SELECT producto_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0];
    $panCategoryName = 'Fixture 3D2 ' . bin2hex(random_bytes(5));
    $panAreaName = 'Área fixture 3D2'; $panAreaSlug = 'qa3d2_' . bin2hex(random_bytes(4));
    $panCategory = restaurante3d2Insert($admin, "INSERT INTO categorias(unidad_id,carta,nombre,activo,orden) VALUES (?, 'comida', ?,1,0)", 'is', [$bakeryUnit,$panCategoryName]);
    $panArea = restaurante3d2Insert($admin, "INSERT INTO areas_produccion(unidad_id,nombre,slug,color,orden) VALUES (?, ?, ?, '#aabbcc',0)", 'iss', [$bakeryUnit,$panAreaName,$panAreaSlug]);
    restaurante3d2Login((int)$menuActor['id'], 'contrasena');
    $panOfferResult = Ofertas::crear($restProductId, 'panaderia', ['categoria_id'=>$panCategory,'precio'=>'19.40','area_id'=>$panArea,'activo'=>true,'orden'=>0]);
    restaurante3d2Assert(($panOfferResult['ok'] ?? false) === true, 'Se prepara oferta Panadería aislada para probar rechazo comercial cruzado.', $count);
    $panOffer = (int)($panOfferResult['oferta_id'] ?? 0);

    $phase = 'receta, variantes, almacenes y entradas de apertura';
    $articleName = 'Artículo venta 3D2 ' . bin2hex(random_bytes(5));
    $variantArticleName = 'Artículo variante 3D2 ' . bin2hex(random_bytes(5));
    $missingArticleName = 'Artículo sin origen 3D2 ' . bin2hex(random_bytes(5));
    $articleMain = restaurante3d2Insert($admin, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?, 'g',2.5000,1)", 's', [$articleName]);
    $articleVariant = restaurante3d2Insert($admin, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?, 'g',3.0000,1)", 's', [$variantArticleName]);
    $articleMissing = restaurante3d2Insert($admin, "INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?, 'g',1.0000,1)", 's', [$missingArticleName]);
    $recipeId = restaurante3d2Insert($admin, 'INSERT INTO recetas(oferta_id,unidad_id,version,activo) VALUES (?,?,1,1)', 'ii', [$restOffer,$restaurantUnit]);
    restaurante3d2Insert($admin, 'INSERT INTO receta_componentes(receta_id,unidad_id,articulo_id,subreceta_id,cantidad) VALUES (?,?,?,NULL,?)', 'iiis', [$recipeId,$restaurantUnit,$articleMain,'1.000']);
    $incompleteOffer = (int)$admin->query("SELECT o.id FROM ofertas_producto o WHERE o.unidad_id=$restaurantUnit AND o.activo=1 AND o.id NOT IN ($restOffer,$noRecipeOffer) AND NOT EXISTS (SELECT 1 FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1) ORDER BY o.id LIMIT 1")->fetch_row()[0];
    $incompleteRecipeId = restaurante3d2Insert($admin, 'INSERT INTO recetas(oferta_id,unidad_id,version,activo) VALUES (?,?,1,1)', 'ii', [$incompleteOffer,$restaurantUnit]);
    $variantBase = restaurante3d2Insert($admin, 'INSERT INTO producto_variantes(oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,activo,orden_grupo,orden) VALUES (?,?,\'Porción\',\'Normal\',0.00,1,1,0,0)', 'ii', [$restOffer,$restaurantUnit]);
    $variantExtra = restaurante3d2Insert($admin, 'INSERT INTO producto_variantes(oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,activo,orden_grupo,orden) VALUES (?,?,\'Porción\',\'Extra\',3.00,0,1,0,1)', 'ii', [$restOffer,$restaurantUnit]);
    restaurante3d2Insert($admin, "INSERT INTO variante_ajustes(variante_id,oferta_id,unidad_id,articulo_id,subreceta_id,cantidad,accion,articulo_nuevo_id,subreceta_nueva_id,nueva_cantidad) VALUES (?,?,?, ?,NULL,'1.000','agregar',NULL,NULL,NULL)", 'iiii', [$variantExtra,$restOffer,$restaurantUnit,$articleVariant]);

    $storeName = 'Almacén consumo 3D2 ' . bin2hex(random_bytes(4));
    $fallbackName = 'Almacén alterno 3D2 ' . bin2hex(random_bytes(4));
    $store = restaurante3d2Insert($admin, 'INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo) VALUES (?,?,0,1)', 'is', [$restaurantUnit,$storeName]);
    $fallbackStore = restaurante3d2Insert($admin, 'INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo) VALUES (?,?,0,1)', 'is', [$restaurantUnit,$fallbackName]);
    foreach ([$articleMain,$articleVariant,$articleMissing] as $articleId) {
        restaurante3d2Insert($admin, 'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,0,0,1)', 'iii', [$store,$articleId,$restaurantUnit]);
    }
    restaurante3d2Insert($admin, 'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,0,0,1)', 'iii', [$fallbackStore,$articleMain,$restaurantUnit]);
    restaurante3d2Insert($admin, 'INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)', 'iiii', [$restOffer,$articleMain,$restaurantUnit,$store]);
    restaurante3d2Insert($admin, 'INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)', 'iiii', [$restOffer,$articleVariant,$restaurantUnit,$store]);
    $inventoryService = new Almacenes($admin, (int)$inventoryActor['id'], 'contrasena');
    restaurante3d2Login((int)$inventoryActor['id'], 'contrasena');
    restaurante3d2StockEntry($inventoryService,$store,$articleMain,'20.000','qa3d2-opening-main-' . bin2hex(random_bytes(5)),$count);
    restaurante3d2StockEntry($inventoryService,$store,$articleVariant,'20.000','qa3d2-opening-variant-' . bin2hex(random_bytes(5)),$count);
    restaurante3d2StockEntry($inventoryService,$fallbackStore,$articleMain,'50.000','qa3d2-opening-fallback-' . bin2hex(random_bytes(5)),$count);

    restaurante3d2Login((int)$posActor['id']);
    $phase = 'venta sin receta e idempotencia de comanda';
    $ticketNoRecipe = restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 sin receta',(int)$posActor['id']);
    $itemNoRecipe = ['oferta_id'=>$noRecipeOffer,'cantidad'=>1,'comensal'=>1,'variantes'=>[],'nota'=>'fixture'];
    $beforeNoRecipe = restaurante3d2Conteos($admin);
    $mainBeforeNoRecipe = restaurante3d2Saldo($admin,$store,$articleMain);
    $noRecipeKey = 'qa3d2-no-recipe-' . bin2hex(random_bytes(8));
    $sentNoRecipe = restaurante3d2Call(fn() => Comercial::enviarComanda($ticketNoRecipe,[$itemNoRecipe],$noRecipeKey,'restaurante'),'Venta sin receta permitida.', $count);
    $noRecipeItemId = (int)($sentNoRecipe['item_ids'][0] ?? 0);
    restaurante3d2Assert($noRecipeItemId > 0 && restaurante3d2Saldo($admin,$store,$articleMain) === $mainBeforeNoRecipe
        && (int)$admin->query("SELECT COUNT(*) FROM movimientos_inventario WHERE ticket_item_id=$noRecipeItemId")->fetch_row()[0] === 0,
        'Venta sin receta no consume inventario.', $count);
    $afterNoRecipe = restaurante3d2Conteos($admin);
    restaurante3d2Assert((int)$admin->query("SELECT COUNT(*) FROM operaciones_inventario WHERE origen='pos' AND tipo='venta' AND referencia='ticket-item-$noRecipeItemId' AND JSON_EXTRACT(resultado,'$.sin_consumo')=true")->fetch_row()[0]===1,
        'La venta sin receta congela explícitamente su decisión de no consumir.', $count);
    $noConsumptionReplay=(new Almacenes($admin,(int)$posActor['id'],'nip'))->consumirVenta('restaurante',$noRecipeItemId);
    restaurante3d2Assert(($noConsumptionReplay['ok']??false)===true && !empty($noConsumptionReplay['reintento']) && !empty($noConsumptionReplay['sin_consumo'])
        && restaurante3d2Conteos($admin)===$afterNoRecipe,
        'El motor reproduce la decisión original sin crear operaciones ni movimientos nuevos.', $count);
    $retryNoRecipe = restaurante3d2Call(fn() => Comercial::enviarComanda($ticketNoRecipe,[$itemNoRecipe],$noRecipeKey,'restaurante'),'Reintento de comanda idempotente.', $count);
    restaurante3d2Assert(!empty($retryNoRecipe['reintento']) && restaurante3d2Conteos($admin) === $afterNoRecipe,
        'La misma clave/hash devuelve resultado previo sin duplicar item ni movimientos.', $count);
    restaurante3d2Denied(fn() => Comercial::enviarComanda($ticketNoRecipe,[array_merge($itemNoRecipe,['cantidad'=>2])],$noRecipeKey,'restaurante'),
        'La misma clave con payload mutado se rechaza.', $count);
    restaurante3d2Assert(restaurante3d2Conteos($admin) === $afterNoRecipe, 'Conflicto idempotente no deja datos parciales.', $count);
    restaurante3d2Denied(fn() => Comercial::enviarComanda($ticketNoRecipe,[['oferta_id'=>$panOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-bakery-' . bin2hex(random_bytes(8)),'restaurante'),
        'Restaurante no acepta una oferta de Panadería.', $count);
    $globalBefore = restaurante3d2Conteos($admin);
    restaurante3d2Denied(fn() => ContextoNegocio::en('global', static fn() => Comercial::enviarComanda($ticketNoRecipe,[$itemNoRecipe],'qa3d2-global-' . bin2hex(random_bytes(8)),'global')),
        'Global no puede generar una comanda vendedora.', $count);
    restaurante3d2Assert(restaurante3d2Conteos($admin) === $globalBefore, 'El intento Global no escribe tickets ni items.', $count);

    $phase = 'ventas con receta, variantes y varias líneas';
    $ticketMulti = restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 varias líneas',(int)$posActor['id']);
    $multiItems = [
        ['oferta_id'=>$restOffer,'cantidad'=>2,'comensal'=>1,'variantes'=>[$variantBase],'nota'=>null],
        ['oferta_id'=>$restOffer,'cantidad'=>1,'comensal'=>2,'variantes'=>[$variantExtra],'nota'=>'extra'],
        ['oferta_id'=>$noRecipeOffer,'cantidad'=>1,'comensal'=>2,'variantes'=>[],'nota'=>null],
    ];
    $keyMulti = 'qa3d2-multi-' . bin2hex(random_bytes(8));
    $multiResult = restaurante3d2Call(fn() => Comercial::enviarComanda($ticketMulti,$multiItems,$keyMulti,'restaurante'),'Comanda de varias líneas con receta y variantes.', $count);
    $multiIds = array_map('intval',$multiResult['item_ids']??[]);
    restaurante3d2Assert(count($multiIds)===3, 'Una comanda atómica conserva todas las líneas solicitadas.', $count);
    $mainAfterMulti = restaurante3d2Saldo($admin,$store,$articleMain);
    $variantAfterMulti = restaurante3d2Saldo($admin,$store,$articleVariant);
    restaurante3d2Assert($mainAfterMulti==='17.000' && $variantAfterMulti==='19.000',
        'Consumo exacto suma receta base y ajuste de variante por cantidad de línea.', $count);
    $snapshot = $admin->query("SELECT oferta_id,producto_id,receta_id,nombre,precio,variante_ids FROM ticket_items WHERE id={$multiIds[1]}")->fetch_assoc();
    $offerInfo = $admin->query("SELECT o.producto_id,o.precio,p.nombre,r.id receta_id FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id LEFT JOIN recetas r ON r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 WHERE o.id=$restOffer AND o.unidad_id=$restaurantUnit")->fetch_assoc();
    restaurante3dAssert((int)$snapshot['oferta_id']===$restOffer && (int)$snapshot['producto_id']===(int)$offerInfo['producto_id']
        && (int)$snapshot['receta_id']===$recipeId && $snapshot['nombre']===$offerInfo['nombre']
        && (string)$snapshot['precio']===number_format((float)$offerInfo['precio']+3,2,'.','')
        && $snapshot['variante_ids']!==null,
        'Item conserva oferta/identidad/receta/nombre/precio calculado/variantes desde servidor, no desde el payload.', $count);

    $phase = 'cancelación con movimientos originales y cambio posterior de receta';
    $recipeAdmin = FixtureRestaurante3D::usuario($admin,'recipe-admin-3d2',['productos'=>'editar']);
    restaurante3d2Login((int)$recipeAdmin['id'],'contrasena');
    $newRecipe = RecetasOferta::guardar('restaurante',$restOffer,[['tipo'=>'ingrediente','ref_id'=>$articleMain,'cantidad'=>'9.000']]);
    restaurante3d2Assert(($newRecipe['ok']??false)===true && (int)$newRecipe['version']===2,'Una versión nueva de receta queda activa después de la venta.', $count);
    restaurante3d2Login((int)$posActor['id']);
    restaurante3d2Call(fn() => Comercial::cancelarItem($multiIds[1],'restaurante'),'Cancelar una línea enviada.', $count);
    $mainAfterCancel = restaurante3d2Saldo($admin,$store,$articleMain); $variantAfterCancel = restaurante3d2Saldo($admin,$store,$articleVariant);
    $reversalsBeforeRepeat=(int)$admin->query("SELECT COUNT(*) FROM movimientos_inventario WHERE ticket_item_id={$multiIds[1]} AND tipo='cancelacion'")->fetch_row()[0];
    $repeatCancel=restaurante3d2Call(fn() => Comercial::cancelarItem($multiIds[1],'restaurante'),'Repetir cancelación idempotente.', $count);
    restaurante3d2Assert(!empty($repeatCancel['idempotente']) && $mainAfterCancel==='18.000' && $variantAfterCancel==='20.000'
        && (int)$admin->query("SELECT COUNT(*) FROM movimientos_inventario WHERE ticket_item_id={$multiIds[1]} AND tipo='cancelacion'")->fetch_row()[0]===$reversalsBeforeRepeat,
        'La cancelación usa los movimientos originales y no repone dos veces al reintentar.', $count);
    restaurante3d2Assert((int)$admin->query("SELECT receta_id FROM ticket_items WHERE id={$multiIds[1]}")->fetch_row()[0]===$recipeId,
        'Cambiar la receta vigente no altera el snapshot histórico del item vendido.', $count);

    $phase = 'línea entregada no cancelable';
    $ticketDelivered = restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 entregado',(int)$posActor['id']);
    $sentDelivered = restaurante3d2Call(fn() => Comercial::enviarComanda($ticketDelivered,[['oferta_id'=>$restOffer,'cantidad'=>1,'comensal'=>1,'variantes'=>[]]],'qa3d2-delivered-' . bin2hex(random_bytes(8)),'restaurante'),'Enviar item para prueba de entrega.', $count);
    $deliveredId=(int)$sentDelivered['item_ids'][0];
    restaurante3d2Call(fn() => Comercial::entregarItem($deliveredId,'restaurante'),'Entregar el item antes de cancelación.', $count);
    $balanceDelivered=restaurante3d2Saldo($admin,$store,$articleMain);
    restaurante3d2Denied(fn() => Comercial::cancelarItem($deliveredId,'restaurante'),'Un item entregado no se cancela.', $count);
    restaurante3d2Assert(restaurante3d2Saldo($admin,$store,$articleMain)===$balanceDelivered
        && (string)$admin->query("SELECT estado FROM ticket_items WHERE id=$deliveredId")->fetch_row()[0]==='entregado',
        'Rechazar cancelación de entregado conserva saldo y estado.', $count);

    $phase = 'origen único y existencias insuficientes/negativos';
    // El stock alterno tiene sobrante, pero borrar el mapeo de oferta debe fallar sin fallback.
    $admin->query("DELETE FROM oferta_consumos WHERE oferta_id=$restOffer AND articulo_id=$articleMain AND unidad_id=$restaurantUnit");
    $ticketNoSource=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 sin origen',(int)$posActor['id']);
    $noSourceBefore=restaurante3d2Conteos($admin); $fallbackBefore=restaurante3d2Saldo($admin,$fallbackStore,$articleMain);
    $noSource=Comercial::enviarComanda($ticketNoSource,[['oferta_id'=>$restOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-source-' . bin2hex(random_bytes(8)),'restaurante');
    restaurante3d2Expected($noSource,'ALMACEN_ORIGEN_FALTANTE');
    restaurante3d2Assert(restaurante3d2Conteos($admin)===$noSourceBefore && restaurante3d2Saldo($admin,$fallbackStore,$articleMain)===$fallbackBefore,
        'No encontrar mapeo local no busca ni consume saldo de un almacén alterno.', $count);
    restaurante3d2Insert($admin, 'INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)', 'iiii', [$restOffer,$articleMain,$restaurantUnit,$store]);
    $stockNow=restaurante3d2Saldo($admin,$store,$articleMain);
    restaurante3d2Login((int)$inventoryActor['id'], 'contrasena');
    $inventoryService->registrarSalida('restaurante',['clave'=>'qa3d2-drain-' . bin2hex(random_bytes(8)),'referencia'=>'qa3d2-drain-' . bin2hex(random_bytes(8)),'origen'=>'motor_inventario','motivo'=>'fixture','lineas'=>[['almacen_id'=>$store,'articulo_id'=>$articleMain,'cantidad'=>$stockNow]]]);
    restaurante3d2Login((int)$posActor['id']);
    $ticketShort=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 stock corto',(int)$posActor['id']);
    $shortBefore=restaurante3d2Conteos($admin); $fallbackShortBefore=restaurante3d2Saldo($admin,$fallbackStore,$articleMain);
    $short=Comercial::enviarComanda($ticketShort,[['oferta_id'=>$restOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-short-' . bin2hex(random_bytes(8)),'restaurante');
    restaurante3d2Expected($short,'SALDO_INSUFICIENTE');
    restaurante3d2Assert(restaurante3d2Saldo($admin,$store,$articleMain)==='0.000' && restaurante3d2Saldo($admin,$fallbackStore,$articleMain)===$fallbackShortBefore
        && restaurante3d2Conteos($admin)===$shortBefore,
        'Saldo insuficiente con negativos apagados revierte ticket_item, idempotencia y movimientos sin fallback.', $count);
    restaurante3d2Login((int)$inventoryActor['id'], 'contrasena');
    $inventoryService->configurarAlmacen('restaurante',$store,['permitir_negativos'=>true]);
    restaurante3d2Login((int)$posActor['id']);
    $ticketNegative=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 negativos',(int)$posActor['id']);
    $negativeResult=restaurante3d2Call(fn() => Comercial::enviarComanda($ticketNegative,[['oferta_id'=>$restOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-negative-' . bin2hex(random_bytes(8)),'restaurante'),'Almacén con negativos habilitados admite salida.', $count);
    $negativeItem=(int)$negativeResult['item_ids'][0];
    restaurante3d2Assert(restaurante3d2Saldo($admin,$store,$articleMain)==='-9.000'
        && (string)$admin->query("SELECT delta FROM movimientos_inventario WHERE ticket_item_id=$negativeItem AND tipo='venta' AND articulo_id=$articleMain")->fetch_row()[0]==='-9.000',
        'Negativos habilitados descuentan la versión vigente de 9 unidades y dejan movimiento de venta trazable.', $count);
    restaurante3d2Call(fn() => Comercial::cancelarItem($negativeItem,'restaurante'),'Cancelar salida negativa.', $count);
    restaurante3d2Assert(restaurante3d2Saldo($admin,$store,$articleMain)==='0.000','La compensación devuelve el saldo negativo a cero en el almacén origen.', $count);

    $phase = 'receta incompleta no produce consumos';
    $ticketIncomplete=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 receta incompleta',(int)$posActor['id']);
    $incompleteBefore=restaurante3d2Conteos($admin); $balanceIncomplete=restaurante3d2Saldo($admin,$store,$articleMissing);
    $incomplete=Comercial::enviarComanda($ticketIncomplete,[['oferta_id'=>$incompleteOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-incomplete-' . bin2hex(random_bytes(8)),'restaurante');
    restaurante3d2Expected($incomplete,'RECETA_INCOMPLETA');
    restaurante3d2Assert(restaurante3d2Conteos($admin)===$incompleteBefore && restaurante3d2Saldo($admin,$store,$articleMissing)===$balanceIncomplete,
        'Receta inventariable sin componentes falla controladamente y no deja una venta parcial.', $count);

    $phase = 'fallos SQL antes de item y durante movimientos';
    foreach ([
        ['name'=>'qa3d2_fail_item','table'=>'ticket_items','type'=>'item'],
        ['name'=>'qa3d2_fail_movement','table'=>'movimientos_inventario','type'=>'movement'],
    ] as $failure) {
        $ticketFail=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 trigger ' . $failure['type'],(int)$posActor['id']);
        $before=restaurante3d2Conteos($admin); $balanceBefore=restaurante3d2Saldo($admin,$store,$articleMain);
        $admin->query("CREATE TRIGGER {$failure['name']} BEFORE INSERT ON {$failure['table']} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa 3d2 injected failure'");
        try {
            $failed=Comercial::enviarComanda($ticketFail,[['oferta_id'=>$restOffer,'cantidad'=>1,'variantes'=>[]]],'qa3d2-trigger-' . $failure['type'] . '-' . bin2hex(random_bytes(8)),'restaurante');
            restaurante3d2Assert(($failed['ok']??true)===false, 'El trigger de fallo debía abortar la comanda ' . $failure['type'], $count);
            restaurante3d2Assert(restaurante3d2Conteos($admin)===$before && restaurante3d2Saldo($admin,$store,$articleMain)===$balanceBefore,
                'Fallo SQL de ' . $failure['type'] . ' no deja ticket, item, operación o saldo parcial.', $count);
        } finally { $admin->query('DROP TRIGGER IF EXISTS ' . restaurante3dIdent($failure['name'])); }
    }

    $phase = 'errores de cierre y pago en transacción';
    $mesaId=restaurante3d2Insert($admin,"INSERT INTO mesas(unidad_id,unidad_tipo,numero,nombre,tipo,capacidad) VALUES (?,'negocio',9001,'QA 3D2 cierre','mesa',2)",'i',[$restaurantUnit]);
    $ticketClose=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 cierre',(int)$posActor['id']);
    restaurante3d2Insert($admin,'INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden) VALUES (?,?,?,1)','iii',[$restaurantUnit,$ticketClose,$mesaId]);
    $closeLine=restaurante3d2Call(fn() => Comercial::enviarComanda($ticketClose,[['oferta_id'=>$noRecipeOffer,'cantidad'=>1,'comensal'=>1,'variantes'=>[]]],'qa3d2-close-line-' . bin2hex(random_bytes(8)),'restaurante'),'Crear línea para cierre/pago.', $count);
    $closeItem=(int)$closeLine['item_ids'][0];
    restaurante3d2Call(fn() => Comercial::entregarItem($closeItem,'restaurante'),'Entregar línea antes del pago.', $count);
    $price=(float)$admin->query("SELECT precio FROM ticket_items WHERE id=$closeItem")->fetch_row()[0];
    foreach ([
        ['name'=>'qa3d2_fail_close','sql'=>"CREATE TRIGGER qa3d2_fail_close BEFORE UPDATE ON tickets FOR EACH ROW BEGIN IF NEW.estado='cerrado' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa close'; END IF; END",'phase'=>'cierre'],
        ['name'=>'qa3d2_fail_payment','sql'=>"CREATE TRIGGER qa3d2_fail_payment BEFORE INSERT ON ticket_pagos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa payment'",'phase'=>'pago'],
    ] as $failure) {
        $before=restaurante3d2Conteos($admin);
        $admin->query($failure['sql']);
        try {
            $closeResult=ContextoNegocio::en('restaurante', static fn() => PuntoVentaReservacionService::cerrarTicket($ticketClose,'dividido',0,[['comensal'=>1,'metodo'=>'tarjeta','monto'=>$price]],(int)$posActor['id'],(int)round($price*100)));
            restaurante3d2Assert(($closeResult['ok']??true)===false, 'El trigger ' . $failure['phase'] . ' debía abortar el cierre.', $count);
            restaurante3d2Assert((string)$admin->query("SELECT estado FROM tickets WHERE id=$ticketClose")->fetch_row()[0]==='abierto'
                && (int)$admin->query("SELECT COUNT(*) FROM ticket_pagos WHERE ticket_id=$ticketClose AND unidad_id=$restaurantUnit")->fetch_row()[0]===0,
                'Error de ' . $failure['phase'] . ' revierte estado y pagos.', $count);
            restaurante3d2Assert(restaurante3d2Conteos($admin)===$before, 'Error de ' . $failure['phase'] . ' no deja documentos parciales.', $count);
        } finally { $admin->query('DROP TRIGGER IF EXISTS ' . restaurante3dIdent($failure['name'])); }
    }
    $closed=ContextoNegocio::en('restaurante', static fn() => PuntoVentaReservacionService::cerrarTicket($ticketClose,'dividido',0,[['comensal'=>1,'metodo'=>'tarjeta','monto'=>$price]],(int)$posActor['id'],(int)round($price*100)));
    restaurante3dAssert(($closed['ok']??false)===true && (string)$admin->query("SELECT estado FROM tickets WHERE id=$ticketClose")->fetch_row()[0]==='cerrado'
        && (int)$admin->query("SELECT COUNT(*) FROM ticket_pagos WHERE ticket_id=$ticketClose AND unidad_id=$restaurantUnit")->fetch_row()[0]===1,
        'El cierre exitoso confirma ticket y pago juntos.', $count);

    // Las carreras reales y repetición de cancelación usan procesos separados y una barrera marcada.
    $phase = 'apertura y descarte atómicos de ticket vacío';
    $_ENV['APP_ENV']='test';
    $_ENV['RESERVATION_TEST_NOW']='2026-10-24 12:00:00';
    $antesApertura=restaurante3d2Conteos($admin);
    $relacionesAntes=(int)$admin->query('SELECT COUNT(*) FROM ticket_mesas')->fetch_row()[0];
    $admin->query("CREATE TRIGGER qa3d2_fail_open BEFORE INSERT ON tickets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa open'");
    try {
        $fallida=PuntoVentaReservacionService::abrirWalkIn(['mesa_ids'=>[$mesaId],'comensales'=>2,'nombre'=>'Fixture apertura fallida'],(int)$posActor['id']);
        restaurante3d2Assert(($fallida['ok']??true)===false && restaurante3d2Conteos($admin)===$antesApertura
            && (int)$admin->query('SELECT COUNT(*) FROM ticket_mesas')->fetch_row()[0]===$relacionesAntes,
            'Un error antes de insertar ticket no confirma documento ni asignaciones.', $count);
    } finally { $admin->query('DROP TRIGGER qa3d2_fail_open'); }
    $apertura=PuntoVentaReservacionService::abrirWalkIn(['mesa_ids'=>[$mesaId],'comensales'=>2,'nombre'=>'Fixture apertura válida'],(int)$posActor['id']);
    restaurante3d2Assert(($apertura['ok']??false)===true && (int)($apertura['ticket_id']??0)>0,
        'La apertura válida confirma ticket y relaciones de mesa.', $count);
    $nuevoTicket=(int)$apertura['ticket_id'];
    $descarte=PuntoVentaReservacionService::descartarTicketVacio($nuevoTicket,(int)$posActor['id']);
    restaurante3d2Assert(($descarte['ok']??false)===true && (int)$admin->query("SELECT COUNT(*) FROM tickets WHERE id=$nuevoTicket")->fetch_row()[0]===0
        && (int)$admin->query("SELECT COUNT(*) FROM ticket_mesas WHERE ticket_id=$nuevoTicket")->fetch_row()[0]===0,
        'Descartar un ticket vacío elimina sus relaciones con FK restrictivas y libera la mesa.', $count);
    $phase = 'preparación de entrada de concurrencia';
    restaurante3d2Login((int)$inventoryActor['id'], 'contrasena');
    $negativosOff=$inventoryService->configurarAlmacen('restaurante',$store,['permitir_negativos'=>false]);
    restaurante3d2Assert(($negativosOff['ok']??false)===true,'La carrera se prueba con negativos deshabilitados.', $count);
    restaurante3d2StockEntry($inventoryService,$store,$articleMain,'9.000','qa3d2-race-seed-' . bin2hex(random_bytes(8)),$count);
    restaurante3d2Login((int)$posActor['id']);
    $raceTicketA=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 race A',(int)$posActor['id']);
    $raceTicketB=restaurante3d2Ticket($admin,$restaurantUnit,'QA 3D2 race B',(int)$posActor['id']);
    $barrier=restaurante3d2CreateBarrier($owner); $barrierDirs[]=$barrier;
    $workerItems=[['oferta_id'=>$restOffer,'cantidad'=>1,'variantes'=>[]]];
    $workers=[
        restaurante3d2LaunchWorker(['operation'=>'send','ctx'=>'restaurante','actor_id'=>(int)$posActor['id'],'ticket_id'=>$raceTicketA,'items'=>$workerItems,'key'=>'qa3d2-race-a-' . bin2hex(random_bytes(8)),'barrier'=>$barrier],$database,$owner,$config,$root,$workerProcesses),
        restaurante3d2LaunchWorker(['operation'=>'send','ctx'=>'restaurante','actor_id'=>(int)$posActor['id'],'ticket_id'=>$raceTicketB,'items'=>$workerItems,'key'=>'qa3d2-race-b-' . bin2hex(random_bytes(8)),'barrier'=>$barrier],$database,$owner,$config,$root,$workerProcesses),
    ];
    $raceResults=restaurante3d2WaitWorkers($workers,$barrier,$owner);
    $raceSucceeded=count(array_filter($raceResults,static fn($r)=>($r['result']['ok']??false)===true));
    $raceFailed=count(array_filter($raceResults,static fn($r)=>($r['result']['ok']??true)===false && ($r['result']['codigo']??'')==='SALDO_INSUFICIENTE'));
    restaurante3d2Assert($raceSucceeded===1 && $raceFailed===1 && restaurante3d2Saldo($admin,$store,$articleMain)==='0.000',
        'Dos ventas concurrentes de la última porción de nueve unidades producen un único consumo, sin sobrescribir saldo.', $count);
    $winner=$admin->query("SELECT id FROM ticket_items WHERE ticket_id IN ($raceTicketA,$raceTicketB) AND unidad_id=$restaurantUnit")->fetch_row();
    $winnerItem=(int)($winner[0]??0);
    if ($winnerItem<1) throw new RuntimeException('La carrera no dejó un item ganador para probar cancelación doble.');
    restaurante3d2Login((int)$inventoryActor['id'], 'contrasena');
    $barrierCancel=restaurante3d2CreateBarrier($owner); $barrierDirs[]=$barrierCancel;
    $cancelWorkers=[
        restaurante3d2LaunchWorker(['operation'=>'cancel','ctx'=>'restaurante','actor_id'=>(int)$posActor['id'],'item_id'=>$winnerItem,'barrier'=>$barrierCancel],$database,$owner,$config,$root,$workerProcesses),
        restaurante3d2LaunchWorker(['operation'=>'cancel','ctx'=>'restaurante','actor_id'=>(int)$posActor['id'],'item_id'=>$winnerItem,'barrier'=>$barrierCancel],$database,$owner,$config,$root,$workerProcesses),
    ];
    $cancelResults=restaurante3d2WaitWorkers($cancelWorkers,$barrierCancel,$owner);
    restaurante3d2Login((int)$posActor['id']);
    restaurante3d2Assert(count(array_filter($cancelResults,static fn($r)=>($r['result']['ok']??false)===true))===2
        && restaurante3d2Saldo($admin,$store,$articleMain)==='9.000'
        && (int)$admin->query("SELECT COUNT(*) FROM movimientos_inventario WHERE ticket_item_id=$winnerItem AND tipo='cancelacion'")->fetch_row()[0]===1,
        'La cancelación doble concurrente repone una sola vez usando el movimiento original.', $count);

    $output = ['ok' => true, 'fase' => '3D.2', 'comprobaciones' => $count, 'base_temporal' => $database,
        'ventas_concurrentes' => ['exitosas'=>$raceSucceeded,'insuficientes'=>$raceFailed], 'cancelacion_concurrente' => 'una compensación'];
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    foreach ($workerProcesses as $process) { if (is_resource($process)) @proc_terminate($process); }
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$name, $token]) {
            try {
                if (restaurante3dBasePropia($admin, $name, $token, (string)($_ENV['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . restaurante3dIdent($name));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $name\n");
                } else { $cleanupFailed = true; }
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($assetRoot !== null && $assetParent !== null) {
        try { restaurante3dLimpiarRaizImagenes($assetRoot, $assetParent); }
        catch (Throwable) { $cleanupFailed = true; }
    }
    foreach ($barrierDirs as $dir) {
        try { restaurante3d2CleanBarrier($dir, $owner ?? ''); }
        catch (Throwable) { $cleanupFailed = true; }
    }
    if ($cleanupFailed && $error === null) { $phase = 'limpieza protegida'; $error = 'No se pudo verificar la limpieza de algún recurso temporal.'; }
}
if ($error === null) echo Comun::json($output ?? ['ok' => true, 'comprobaciones' => $count]);
else fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $phase, 'comprobaciones' => $count, 'error' => $error]));
exit($error === null ? 0 : 1);
