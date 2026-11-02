<?php
declare(strict_types=1);

/**
 * Pruebas de integración desechables para el contrato multinegocio-3a-v1.
 * Nunca selecciona ni consulta datos de la base configurada; se conecta al
 * servidor sin esquema y solo crea/elimina una base aleatoria con marca propia.
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/menu/ImportadorMenu.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';

use MenuPreservacion\Comun;
use MenuPreservacion\VerificadorMenu;
use MenuPreservacion\ImportadorMenu;
use Multinegocio\SqlArchivo;

function multinegocioOpciones(array $args): array
{
    $opciones = [];
    for ($i = 0; $i < count($args); $i++) {
        if (!preg_match('/^--(host|base|entorno|paquete)$/D', $args[$i], $m)
            || !isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--') || isset($opciones[$m[1]])) {
            throw new RuntimeException('Uso: --host HOST --base BASE --entorno development|test --paquete ABS');
        }
        $opciones[$m[1]] = $args[++$i];
    }
    foreach (['host','base','entorno','paquete'] as $key) {
        if (!isset($opciones[$key]) || $opciones[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    if (!str_starts_with($opciones['paquete'], '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $opciones['paquete'])) {
        throw new RuntimeException('--paquete debe ser una ruta absoluta.');
    }
    return $opciones;
}

function multinegocioAssert(bool $condicion, string $mensaje, int &$total): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
    $total++;
}

function multinegocioSqlFail(mysqli $db, callable $accion, array $codigos, string $mensaje, int &$total): void
{
    $db->begin_transaction();
    try {
        $accion();
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        multinegocioAssert(in_array($e->getCode(), $codigos, true), "$mensaje: código MySQL inesperado {$e->getCode()}.", $total);
        return;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $db->rollback();
    throw new RuntimeException("$mensaje: se esperaba rechazo SQL.");
}

function multinegocioLimpiarDirectorio(string $ruta, string $padreAutorizado): void
{
    $realParent = realpath($padreAutorizado);
    $real = realpath($ruta);
    if (!file_exists($ruta) && !is_link($ruta)) return;
    if ($realParent === false || $real === false || is_link($ruta) || dirname($real) !== $realParent) {
        throw new RuntimeException('No se pudo verificar la ruta temporal antes de limpiarla.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname());
        else rmdir($entry->getPathname());
    }
    rmdir($real);
}

function multinegocioIdent(string $nombre): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $nombre)) throw new RuntimeException('Nombre SQL inesperado.');
    return '`' . $nombre . '`';
}

function multinegocioCrearBase(mysqli $admin, string $root, array &$bases): array
{
    $nombre = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $nombre)) throw new RuntimeException('Nombre de base de prueba inválido.');
    $admin->query('CREATE DATABASE ' . multinegocioIdent($nombre) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bases[] = [$nombre, $token];
    try {
        $admin->select_db($nombre);
        $admin->query('CREATE TABLE _cp_multinegocio_owner (token VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner (token) VALUES (?)');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
        SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');
    } catch (Throwable $e) {
        // La limpieza común solo borra si puede verificar esta marca y token.
        throw $e;
    }
    return [$nombre, $token];
}

function multinegocioVerificarBase(mysqli $admin, string $nombre, string $token, string $baseOrigen): bool
{
    if ($nombre === $baseOrigen || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $nombre)) return false;
    $exists = $admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($nombre) . "'")->fetch_row();
    if (!$exists) return false;
    $admin->select_db($nombre);
    try {
        $stmt = $admin->prepare('SELECT token FROM _cp_multinegocio_owner');
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) {
        return false;
    }
}

function multinegocioContar(mysqli $db, string $tabla): int
{
    $r = $db->query('SELECT COUNT(*) FROM ' . multinegocioIdent($tabla))->fetch_row();
    return (int)$r[0];
}

function multinegocioSnapshot(mysqli $db): array
{
    $tablas = [];
    $result = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    foreach ($result->fetch_all(MYSQLI_NUM) as $row) {
        $tabla = (string)$row[0];
        if ($tabla === '_cp_multinegocio_owner') continue;
        $tablas[$tabla] = $db->query('SELECT * FROM ' . multinegocioIdent($tabla) . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    }
    ksort($tablas);
    return $tablas;
}

function multinegocioBaseVacia(mysqli $db): bool
{
    foreach (['productos','categorias','areas_produccion','ofertas_producto','importaciones_menu'] as $tabla) {
        if (multinegocioContar($db, $tabla) !== 0) return false;
    }
    return true;
}

function multinegocioCopiarPaqueteAlterado(string $source, string $dest, string $modo = 'precio'): void
{
    if (!mkdir($dest, 0700)) throw new RuntimeException('No se pudo crear copia temporal del paquete.');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $entry) {
        $rel = substr($entry->getPathname(), strlen(rtrim($source, '/\\')) + 1);
        $target = $dest . DIRECTORY_SEPARATOR . $rel;
        if ($entry->isLink()) throw new RuntimeException('El paquete contiene un enlace durante la copia de prueba.');
        if ($entry->isDir()) { if (!is_dir($target)) mkdir($target, 0700, true); }
        elseif ($entry->isFile()) { if (!copy($entry->getPathname(), $target)) throw new RuntimeException('No se pudo copiar el paquete de prueba.'); }
    }
    $menuPath = $dest . '/menu.json';
    $menu = Comun::leerJson($menuPath);
    if ($modo === 'slug_duplicado') {
        if (count($menu['areas'] ?? []) < 2) throw new RuntimeException('El paquete no tiene áreas para el caso SQL de rollback.');
        $menu['areas'][1]['slug'] = $menu['areas'][0]['slug'];
    } else {
        if (!isset($menu['productos'][0]['precio'])) throw new RuntimeException('Paquete sin productos para alterar hash.');
        $menu['productos'][0]['precio'] = $menu['productos'][0]['precio'] === '0.01' ? '0.02' : '0.01';
    }
    file_put_contents($menuPath, Comun::json($menu));
    $manifestPath = $dest . '/manifiesto.json';
    $manifest = Comun::leerJson($manifestPath);
    $manifest['hashes']['menu.json'] = hash_file('sha256', $menuPath);
    file_put_contents($manifestPath, Comun::json($manifest));
}

function multinegocioDebeRechazarImportacion(mysqli $db, string $paquete, string $raiz, string $token, string $mensaje, int &$total): void
{
    $rechazada = false;
    try {
        $resultado = multinegocioImportar($db, $paquete, $raiz, $token);
        $rechazada = ($resultado['ok'] ?? false) === false;
    } catch (Throwable) { $rechazada = true; }
    multinegocioAssert($rechazada, "$mensaje: importación aceptada.", $total);
}

function multinegocioUnico(mysqli $db, string $sql): int
{
    $row = $db->query($sql)->fetch_row();
    if ($row === null) throw new RuntimeException('No se encontró fixture requerido para la prueba de integridad.');
    return (int)$row[0];
}

function multinegocioPruebasIntegridad(mysqli $db, int &$total): void
{
    $suffix = bin2hex(random_bytes(5));
    $db->query("INSERT INTO usuarios(username,nombre,password_hash) VALUES ('qa_{$suffix}','Fixture de integridad','hash-solo-pruebas')");
    $user = (int)$db->insert_id;
    $db->query("INSERT INTO roles(slug,nombre,tipo,unidad_id) VALUES ('qa_r_{$suffix}','QA Restaurante','personalizado',2)");
    $roleRest = (int)$db->insert_id;
    $db->query("INSERT INTO roles(slug,nombre,tipo,unidad_id) VALUES ('qa_p_{$suffix}','QA Panadería','personalizado',3)");
    $rolePan = (int)$db->insert_id;
    $db->query("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id) VALUES ($user,2,$roleRest)");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id) VALUES ($user,2,$roleRest)"), [1062], 'Membresía duplicada', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id) VALUES ($user,3,$roleRest)"), [1644], 'Rol local cruzado entre unidades', $total);
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE usuario_unidades SET rol_id=$rolePan WHERE usuario_id=$user AND unidad_id=2"), [1644], 'Cambio de membresía a rol local ajeno', $total);
    $db->query("INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES ($user,2,'usuarios.gestionar')");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES ($user,2,'catalogo.editar')"), [1644], 'Facultad solo_global en negocio', $total);
    $db->query("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id) VALUES ($user,3,$rolePan)");
    $db->query("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id) VALUES ($user,1,2)");
    // En 3B la metadata de rol no concede panel; el permiso directo sí.
    $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($user,1,'users','ver')");
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE usuarios SET nip_hash='fixture-no-autenticable',nip_lookup=REPEAT('a',64) WHERE id=$user"), [1644], 'NIP denegado a cuenta con permiso administrativo directo', $total);
    $db->query("DELETE FROM usuario_unidad_permisos WHERE usuario_id=$user AND unidad_id=1");
    $db->query("UPDATE usuarios SET nip_hash='fixture-no-autenticable',nip_lookup=REPEAT('a',64) WHERE id=$user");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($user,1,'users','ver')"), [1644], 'Permiso directo de panel denegado a cuenta con NIP', $total);
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE usuarios SET es_superadmin=1 WHERE id=$user"), [1644,3819], 'Excepción superadministrador denegada a cuenta con NIP', $total);
    $db->query("UPDATE usuarios SET nip_hash=NULL,nip_lookup=NULL WHERE id=$user");
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE usuarios SET nip_lookup=REPEAT('a',64) WHERE id=$user"), [3819], 'NIP hash y lookup deben existir juntos', $total);
    $db->query("INSERT INTO rol_permisos(rol_id,modulo,nivel) VALUES ($roleRest,'qa','ver')");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO rol_permisos(rol_id,modulo,nivel) VALUES ($roleRest,'qa','ver')"), [1062], 'Permiso de rol duplicado', $total);
    $db->query("SET SESSION sql_mode=CONCAT(@@sql_mode,',STRICT_ALL_TABLES')");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO rol_permisos(rol_id,modulo,nivel) VALUES ($roleRest,'qa_invalido','admin')"), [1264,1265], 'Nivel de permiso inválido', $total);

    $areaRest = multinegocioUnico($db, 'SELECT id FROM areas_produccion WHERE unidad_id=2 ORDER BY id LIMIT 1');
    $categoryRest = multinegocioUnico($db, 'SELECT id FROM categorias WHERE unidad_id=2 ORDER BY id LIMIT 1');
    $offerRest = multinegocioUnico($db, 'SELECT id FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1');
    $productRest = multinegocioUnico($db, "SELECT producto_id FROM ofertas_producto WHERE id=$offerRest");
    $db->query("INSERT INTO areas_produccion(unidad_id,nombre,slug,color) VALUES (3,'QA Pan','qa_$suffix','#112233')");
    $areaPan = (int)$db->insert_id;
    $db->query("INSERT INTO categorias(unidad_id,carta,nombre) VALUES (3,'comida','QA {$suffix}')");
    $categoryPan = (int)$db->insert_id;
    $db->query("INSERT INTO productos(id,nombre) VALUES (4294967000,'QA producto {$suffix}')");
    $productPan = 4294967000;
    $precioRest = (string)$db->query("SELECT precio FROM ofertas_producto WHERE id=$offerRest")->fetch_row()[0];
    $db->query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id) VALUES ($productRest,3,$categoryPan,7.10,$areaPan)");
    $offerPan = (int)$db->insert_id;
    multinegocioAssert((string)$db->query("SELECT precio FROM ofertas_producto WHERE id=$offerPan")->fetch_row()[0] === '7.10'
        && (string)$db->query("SELECT precio FROM ofertas_producto WHERE id=$offerRest")->fetch_row()[0] === $precioRest,
        'La identidad compartida tiene precios independientes por oferta de negocio.', $total);
    $db->query("INSERT INTO recetas(oferta_id,unidad_id) VALUES ($offerPan,3)");
    multinegocioAssert((int)$db->query("SELECT COUNT(*) FROM recetas WHERE oferta_id=$offerPan AND unidad_id=3")->fetch_row()[0] === 1, 'Panadería admite su propia receta por oferta.', $total);
    $db->query("INSERT INTO productos(nombre) SELECT nombre FROM productos WHERE id=$productRest");
    multinegocioAssert((int)$db->query("SELECT COUNT(*) FROM productos WHERE nombre=(SELECT nombre FROM productos WHERE id=$productRest)")->fetch_row()[0] >= 2, 'Productos de identidad distinta no se fusionan por nombre.', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id) VALUES ($productPan,2,$categoryPan,10.00,$areaRest)"), [1452], 'Oferta con categoría de otra unidad', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id) VALUES ($productPan,1,$categoryRest,10.00,$areaRest)"), [1452,3819], 'Oferta de venta para Global', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id) VALUES ($productPan,3,$categoryPan,1000000.00,$areaPan)"), [1264,1265], 'Precisión DECIMAL de oferta', $total);

    $db->query("INSERT INTO almacenes(unidad_id,nombre,permitir_negativos) VALUES (2,'QA Rest {$suffix}',0)");
    $warehouseRest = (int)$db->insert_id;
    $db->query("INSERT INTO almacenes(unidad_id,nombre,permitir_negativos) VALUES (3,'QA Pan {$suffix}',1)");
    $warehousePan = (int)$db->insert_id;
    $db->query("INSERT INTO almacenes(unidad_id,nombre) VALUES (1,'QA Global {$suffix}')");
    $warehouseGlobal = (int)$db->insert_id;
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE almacenes SET permitir_negativos=2 WHERE id=$warehouseGlobal"), [3819], 'Configuración de negativos es booleana', $total);
    multinegocioAssert((int)$db->query("SELECT unidad_id FROM almacenes WHERE id=$warehouseGlobal")->fetch_row()[0] === 1, 'Almacén administrativo Global conserva propietario explícito.', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO almacenes(nombre,unidad_id) VALUES ('QA dueño nulo {$suffix}',NULL)"), [1048], 'Almacén sin propietario', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO almacenes(unidad_id,nombre) VALUES (99,'QA unidad inexistente {$suffix}')"), [1452], 'Almacén con unidad inexistente', $total);
    $db->query("INSERT INTO ingredientes(nombre,unidad) VALUES ('QA ingrediente {$suffix}','g')");
    $ingredient = (int)$db->insert_id;
    $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehousePan,$ingredient,3,-1,0)");
    $negativePan = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$warehousePan AND articulo_id=$ingredient")->fetch_row()[0];
    multinegocioAssert($negativePan === '-1.000', 'Almacén con negativos habilitados acepta saldo negativo.', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehouseRest,$ingredient,2,-1,0)"), [1644], 'Saldo negativo con negativos deshabilitados', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehouseRest,$ingredient,2,1,-0.001)"), [3819], 'Mínimo de inventario negativo', $total);
    $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehouseRest,$ingredient,2,0,0)");
    multinegocioSqlFail($db, static fn() => $db->query("UPDATE almacenes SET permitir_negativos=0 WHERE id=$warehousePan"), [1644], 'Deshabilitar negativos con saldo negativo existente', $total);

    $db->query("UPDATE almacen_articulos SET saldo=5 WHERE almacen_id=$warehousePan AND articulo_id=$ingredient");
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES ($offerRest,$ingredient,2,$warehousePan)"), [1452], 'Consumo desde almacén de otra unidad', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO recetas(oferta_id,unidad_id) VALUES ($offerRest,3)"), [1452], 'Receta vinculada a oferta de otra unidad', $total);
    $db->query("INSERT INTO recetas(oferta_id,unidad_id) VALUES ($offerRest,2)");
    $recipeRest = (int)$db->insert_id;
    $db->query("INSERT INTO subrecetas(unidad_id,nombre,rendimiento) VALUES (3,'QA subreceta {$suffix}',1)");
    $subrecipePan = (int)$db->insert_id;
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO receta_componentes(receta_id,unidad_id,subreceta_id,cantidad) VALUES ($recipeRest,2,$subrecipePan,1)"), [1452], 'Componente subreceta de otra unidad', $total);
    $db->query("INSERT INTO producto_variantes(oferta_id,unidad_id,grupo,nombre) VALUES ($offerRest,2,'QA','Variante {$suffix}')");
    $variant = (int)$db->insert_id;
    $db->query("INSERT INTO variante_ajustes(variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion) VALUES ($variant,$offerRest,2,$ingredient,1,'agregar')");
    multinegocioAssert((int)$db->query("SELECT COUNT(*) FROM variante_ajustes WHERE variante_id=$variant AND accion='agregar' AND articulo_nuevo_id IS NULL AND subreceta_nueva_id IS NULL")->fetch_row()[0] === 1, 'Ajuste agregar permite referencia original sin nueva referencia.', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO variante_ajustes(variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion) VALUES ($variant,$offerRest,2,$ingredient,1,'sustituir')"), [3819], 'Sustitución requiere referencia nueva', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO variante_ajustes(variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion,articulo_nuevo_id,nueva_cantidad) VALUES ($variant,$offerRest,2,$ingredient,1,'sustituir',$ingredient,NULL)"), [3819], 'Sustitución requiere cantidad nueva explícita', $total);

    $db->query('INSERT INTO tickets(unidad_id) VALUES (3)');
    $ticketPan = (int)$db->insert_id;
    $db->query('INSERT INTO tickets(unidad_id) VALUES (2)');
    $ticketRest = (int)$db->insert_id;
    $db->query("INSERT INTO mesas(unidad_id,numero,nombre) VALUES (2,999,'QA {$suffix}')");
    $mesaRest = (int)$db->insert_id;
    multinegocioAssert($ticketPan > 0 && $ticketRest > 0, 'Tickets de ambos negocios creados.', $total);
    multinegocioSqlFail($db, static fn() => $db->query('INSERT INTO tickets(unidad_id) VALUES (1)'), [1452,3819], 'Ticket vendedor Global', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,area_id) VALUES (3,$ticketPan,$offerRest,$productRest,'QA',1,'QA',$areaRest)"), [1452], 'Ticket item con oferta de otra unidad', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto) VALUES (2,$ticketPan,1,'efectivo',1)"), [1452], 'Pago con unidad distinta al ticket', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id) VALUES (3,$ticketPan,$mesaRest)"), [1452], 'Mesa de Restaurante asociada a ticket Panadería', $total);

    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO transferencias(almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id) VALUES ($warehousePan,2,$warehouseRest,2,$user)"), [1452], 'Transferencia con propietario de almacén incorrecto', $total);
    $db->query("INSERT INTO transferencias(almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id) VALUES ($warehouseRest,2,$warehousePan,3,$user)");
    $transfer = (int)$db->insert_id;
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO transferencia_lineas(transferencia_id,articulo_id,cantidad_solicitada,cantidad_enviada,cantidad_recibida) VALUES ($transfer,$ingredient,1,1,2)"), [3819], 'Cantidades de transferencia fuera de secuencia', $total);
    $operationHash = hash('sha256', 'qa-' . $suffix);
    $operationRef = 'ref-' . $suffix;
    $idem = 'idem-' . $suffix;
    $db->query("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (2,'ajuste','usuario',$user,'qa','$idem','$operationHash','$operationRef')");
    $operationRest = (int)$db->insert_id;
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (2,'ajuste','usuario',$user,'qa','$idem','$operationHash','otra-$suffix')"), [1062], 'Clave de idempotencia duplicada', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (2,'ajuste','usuario',$user,'qa','otra-$suffix','$operationHash','$operationRef')"), [1062], 'Referencia de operación duplicada por unidad/tipo', $total);
    $db->query("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (3,'ajuste','usuario',$user,'qa','idem-pan-$suffix','$operationHash','$operationRef')");
    multinegocioAssert((int)$db->insert_id > 0, 'La referencia financiera/inventario puede repetirse en otra unidad.', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta) VALUES ($operationRest,2,$warehouseRest,$ingredient,'ajuste',0)"), [3819], 'Movimiento con delta cero', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta) VALUES ($operationRest,2,$warehouseRest,$ingredient,'entrada',-1)"), [3819], 'Signo de movimiento no coincide con tipo', $total);
    multinegocioSqlFail($db, static fn() => $db->query("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta) VALUES ($operationRest,2,$warehousePan,$ingredient,'ajuste',1)"), [1452], 'Operación y almacén de movimiento cruzan unidad', $total);
    $stockPan = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$warehousePan AND articulo_id=$ingredient")->fetch_row()[0];
    $stockRest = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$warehouseRest AND articulo_id=$ingredient")->fetch_row()[0];
    multinegocioAssert($stockPan === '5.000' && $stockRest === '0.000', 'Los saldos del mismo artículo permanecen independientes por almacén/unidad.', $total);
}

function multinegocioProbarContrato(mysqli $db, int &$total): void
{
    $version = $db->query("SELECT version FROM esquema_versiones WHERE version = 'multinegocio-3a-v1'")->fetch_row();
    multinegocioAssert($version !== null, 'Falta esquema_versiones multinegocio-3a-v1.', $total);
    $unidades = $db->query('SELECT slug, tipo FROM unidades ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $normal = array_map(static fn($r) => $r['slug'] . ':' . $r['tipo'], $unidades);
    multinegocioAssert($normal === ['global:global','restaurante:negocio','panaderia:negocio'], 'Seeds de unidades incompletos o en orden inesperado.', $total);
    multinegocioAssert(multinegocioContar($db, 'cartas') >= 6, 'Faltan cartas por negocio.', $total);
    multinegocioAssert(multinegocioContar($db, 'roles') > 0 && multinegocioContar($db, 'rol_permisos') > 0, 'Faltan roles o permisos de seed.', $total);
    multinegocioAssert(multinegocioContar($db, 'facultades') > 0, 'Faltan facultades de seed.', $total);
    $menuParalelo = $db->query("SHOW TABLES LIKE 'menu'")->num_rows;
    multinegocioAssert($menuParalelo === 0, 'Se conservó una tabla paralela de menú descartada.', $total);
    multinegocioAssert(multinegocioContar($db, 'productos') === 0 && multinegocioContar($db, 'categorias') === 0
        && multinegocioContar($db, 'areas_produccion') === 0 && multinegocioContar($db, 'ofertas_producto') === 0,
        'DDL/deploy sembró menú o productos de muestra.', $total);
    $globalOffer = (int)$db->query("SELECT COUNT(*) FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.tipo='global'")->fetch_row()[0];
    multinegocioAssert($globalOffer === 0, 'Global no debe vender ofertas.', $total);
    $plantillas = (int)$db->query("SELECT COUNT(*) FROM roles WHERE unidad_id IS NULL AND tipo IN('sistema','predefinido')")->fetch_row()[0];
    multinegocioAssert($plantillas >= 4, 'Faltan roles de sistema/predefinidos.', $total);
    $mods = $db->query('SELECT DISTINCT modulo FROM rol_permisos ORDER BY modulo')->fetch_all(MYSQLI_NUM);
    multinegocioAssert(count($mods) >= 2, 'Rol-permisos no cubre módulos de permisos.', $total);
}

function multinegocioVerificarImagenes(string $package, string $root, array $resultado, int &$total): void
{
    $manifest = Comun::leerJson($package . '/manifiesto.json');
    $expected = [];
    foreach (($manifest['archivos'] ?? []) as $file) {
        $path = (string)($file['ruta'] ?? '');
        $actual = Comun::rutaArchivo($root, $path);
        $hash = hash_file('sha256', $actual);
        multinegocioAssert(is_string($hash) && hash_equals((string)$file['sha256'], $hash), "Imagen importada alterada: $path", $total);
        $expected[$path] = (string)$file['sha256'];
    }
    ksort($expected);
    $reported = $resultado['hashes_archivos'] ?? [];
    if (is_array($reported)) ksort($reported);
    multinegocioAssert(count($expected) === 13 && $reported === $expected, 'Hashes/cobertura de imágenes importadas incompletos.', $total);
}

function multinegocioImportar(mysqli $db, string $paquete, string $raiz, string $token): array
{
    return ImportadorMenu::importar($db, $paquete, $raiz, $token);
}

$admin = null;
$bases = [];
$salidas = [];
$error = null;
$salidaFinal = null;
$fase = 'argumentos/configuración';
$total = 0;
try {
    $opciones = multinegocioOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    $origen = Comun::origen($config, $opciones, $root); // valida identidad sin conectar al origen.
    $validacion = VerificadorMenu::verificar($opciones['paquete']);
    multinegocioAssert($validacion['ok'], 'Paquete de etapa 2 inválido.', $total);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $fase = 'conexión administrativa';
    $admin = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    if ($admin->thread_id === 0) throw new RuntimeException('No se abrió conexión administrativa sin esquema.');
    $tmp = $root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'menu-preservacion' . DIRECTORY_SEPARATOR . 'multinegocio-' . bin2hex(random_bytes(8));
    if (!mkdir($tmp, 0700, true)) throw new RuntimeException('No se pudo crear directorio temporal aislado.');
    $salidas[] = [$tmp, dirname($tmp)];
    $assetParent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'menu-preservacion';
    if (!is_dir($assetParent) && !mkdir($assetParent, 0700, true)) throw new RuntimeException('No se pudo crear padre temporal de imágenes.');
    if (is_link($assetParent)) throw new RuntimeException('Padre temporal de imágenes inseguro.');
    $assetContainer = $assetParent . DIRECTORY_SEPARATOR . 'multinegocio-' . bin2hex(random_bytes(8));
    if (!mkdir($assetContainer, 0700)) throw new RuntimeException('No se pudo crear contenedor de imágenes temporal.');
    $salidas[] = [$assetContainer, $assetParent];

    // Primera base: verificar esquema limpio, escenarios negativos e importación completa.
    $fase = 'DDL y seeds primera base';
    [$dbName, $token] = multinegocioCrearBase($admin, $root, $bases);
    if (!$admin->select_db($dbName)) throw new RuntimeException('No se pudo seleccionar destino aleatorio.');
    multinegocioProbarContrato($admin, $total);
    $fixtureRoot = $assetContainer . DIRECTORY_SEPARATOR . 'imagenes-1';
    $admin->begin_transaction();
    $admin->query("INSERT INTO usuarios(username,nombre,password_hash) VALUES ('qa_tx_sin_commit','QA transacción ajena','fixture-no-autenticable')");
    multinegocioDebeRechazarImportacion($admin, $opciones['paquete'], $assetContainer . '/imagenes-tx-ajena', $token, 'El importador no confirma transacciones del llamador', $total);
    $admin->rollback();
    multinegocioAssert((int)$admin->query("SELECT COUNT(*) FROM usuarios WHERE username='qa_tx_sin_commit'")->fetch_row()[0] === 0, 'La transacción del llamador fue confirmada indebidamente.', $total);
    $failRoot = $assetContainer . DIRECTORY_SEPARATOR . 'imagenes-fallo';
    $areasDuplicadas = $tmp . DIRECTORY_SEPARATOR . 'paquete-areas-duplicadas';
    multinegocioCopiarPaqueteAlterado($opciones['paquete'], $areasDuplicadas, 'slug_duplicado');
    multinegocioAssert(VerificadorMenu::verificar($areasDuplicadas)['ok'], 'El fixture con slug duplicado debe conservar hashes y referencias válidos.', $total);
    $fase = 'rollback de importación SQL fallida';
    multinegocioDebeRechazarImportacion($admin, $areasDuplicadas, $failRoot, $token, 'Fallo SQL parcial por slug de área duplicado', $total);
    multinegocioAssert(multinegocioBaseVacia($admin), 'Importación SQL fallida dejó filas de menú o registro.', $total);
    $fase = 'importación y proyección de Restaurante';
    $resultado = multinegocioImportar($admin, $opciones['paquete'], $fixtureRoot, $token);
    multinegocioAssert(($resultado['ok'] ?? false) === true, 'Importación del paquete falló.', $total);
    $proyeccion = ImportadorMenu::proyectar($admin, $opciones['paquete']);
    $proyeccionFile = $tmp . DIRECTORY_SEPARATOR . 'proyeccion-1.json';
    file_put_contents($proyeccionFile, Comun::json($proyeccion));
    $comparacion = VerificadorMenu::comparar(Comun::leerJson($opciones['paquete'] . '/menu.json'), $proyeccion);
    multinegocioAssert($comparacion === [], 'La proyección reconstruida difiere del paquete: ' . implode('; ', array_slice($comparacion, 0, 5)), $total);
    multinegocioAssert(($resultado['conteos']['productos'] ?? null) === 112
        && ($resultado['conteos']['categorias'] ?? null) === 15
        && ($resultado['conteos']['areas'] ?? null) === 4
        && ($resultado['conteos']['ofertas'] ?? null) === 112
        && ($resultado['conteos']['archivos'] ?? null) === 13, 'Conteos importados no son 112/15/4/112/13.', $total);
    multinegocioVerificarImagenes($opciones['paquete'], $fixtureRoot, $resultado, $total);
    $tamperOferta = multinegocioUnico($admin, 'SELECT id FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1');
    $precioOriginal = (string)$admin->query("SELECT precio FROM ofertas_producto WHERE id=$tamperOferta")->fetch_row()[0];
    $admin->query("UPDATE ofertas_producto SET precio=precio+1.00 WHERE id=$tamperOferta");
    multinegocioDebeRechazarImportacion($admin, $opciones['paquete'], $fixtureRoot, $token, 'Reimportación detecta precio persistido alterado', $total);
    $stmt = $admin->prepare('UPDATE ofertas_producto SET precio=? WHERE id=?');
    $stmt->bind_param('si', $precioOriginal, $tamperOferta); $stmt->execute();
    multinegocioAssert((string)$admin->query("SELECT precio FROM ofertas_producto WHERE id=$tamperOferta")->fetch_row()[0] === $precioOriginal, 'No se restauró precio original tras prueba negativa.', $total);
    $ordenOriginal = (int)$admin->query("SELECT orden FROM ofertas_producto WHERE id=$tamperOferta")->fetch_row()[0];
    $admin->query("UPDATE ofertas_producto SET orden=orden+1000 WHERE id=$tamperOferta");
    multinegocioDebeRechazarImportacion($admin, $opciones['paquete'], $fixtureRoot, $token, 'Reimportación detecta orden persistido alterado', $total);
    $stmt = $admin->prepare('UPDATE ofertas_producto SET orden=? WHERE id=?');
    $stmt->bind_param('ii', $ordenOriginal, $tamperOferta); $stmt->execute();
    multinegocioAssert((int)$admin->query("SELECT orden FROM ofertas_producto WHERE id=$tamperOferta")->fetch_row()[0] === $ordenOriginal, 'No se restauró el orden original tras prueba negativa.', $total);
    $manifest = Comun::leerJson($opciones['paquete'] . '/manifiesto.json');
    $imageEntry = $manifest['archivos'][0] ?? null;
    if (!is_array($imageEntry)) throw new RuntimeException('El paquete requiere archivos de imagen para la prueba de integridad.');
    $relativeImage = (string)$imageEntry['ruta'];
    $destinationImage = Comun::rutaArchivo($fixtureRoot, $relativeImage);
    $sourceImage = Comun::rutaArchivo($opciones['paquete'] . '/archivos', $relativeImage);
    if (file_put_contents($destinationImage, file_get_contents($sourceImage) . 'x') === false) throw new RuntimeException('No se pudo alterar imagen temporal de prueba.');
    multinegocioDebeRechazarImportacion($admin, $opciones['paquete'], $fixtureRoot, $token, 'Reimportación detecta bytes de imagen alterados', $total);
    if (!copy($sourceImage, $destinationImage)) throw new RuntimeException('No se pudo restaurar imagen temporal tras prueba negativa.');
    multinegocioAssert(hash_file('sha256', $destinationImage) === $imageEntry['sha256'], 'No se restauraron los bytes de imagen originales.', $total);
    multinegocioAssert(multinegocioContar($admin, 'productos') === 112 && multinegocioContar($admin, 'ofertas_producto') === 112
        && (int)$admin->query('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=2')->fetch_row()[0] === 112
        && (int)$admin->query('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=3')->fetch_row()[0] === 0,
        'Catálogo importado no preserva identidades o ofrece productos fuera de Restaurante.', $total);
    $unchanged = multinegocioSnapshot($admin);
    $repeat = multinegocioImportar($admin, $opciones['paquete'], $fixtureRoot, $token);
    multinegocioAssert(($repeat['codigo'] ?? null) === 'YA_IMPORTADO', 'Reimportación no respondió YA_IMPORTADO.', $total);
    multinegocioAssert(multinegocioSnapshot($admin) === $unchanged, 'Reimportación alteró filas persistidas.', $total);
    $alterado = $tmp . DIRECTORY_SEPARATOR . 'paquete-cambiado';
    multinegocioCopiarPaqueteAlterado($opciones['paquete'], $alterado);
    multinegocioAssert(VerificadorMenu::verificar($alterado)['ok'], 'El paquete cambiado con hash recalculado debe ser internamente válido.', $total);
    multinegocioDebeRechazarImportacion($admin, $alterado, $fixtureRoot, $token, 'Importación de paquete modificado debe rechazarse', $total);
    multinegocioAssert(multinegocioSnapshot($admin) === $unchanged, 'Rechazo de paquete cambiado alteró filas destino.', $total);
    $fase = 'pruebas de integridad relacional';
    multinegocioPruebasIntegridad($admin, $total);

    // Segunda instalación vacía asegura que el bootstrap no depende de sobras del primer destino.
    $fase = 'reproducibilidad segunda base';
    [$dbName2, $token2] = multinegocioCrearBase($admin, $root, $bases);
    $admin->select_db($dbName2);
    multinegocioProbarContrato($admin, $total);
    $fixtureRoot2 = $assetContainer . DIRECTORY_SEPARATOR . 'imagenes-2';
    $resultado2 = multinegocioImportar($admin, $opciones['paquete'], $fixtureRoot2, $token2);
    $proyeccion2 = ImportadorMenu::proyectar($admin, $opciones['paquete']);
    multinegocioAssert(($resultado2['ok'] ?? false) === true && VerificadorMenu::comparar($proyeccion, $proyeccion2) === [], 'Segundo destino no reproduce la misma proyección.', $total);
    multinegocioVerificarImagenes($opciones['paquete'], $fixtureRoot2, $resultado2, $total);
    $salidaFinal = ['ok' => true, 'comprobaciones' => $total, 'conteos' => $resultado['conteos'] ?? [], 'bases_temporales' => count($bases),
        'esquema' => ['version' => 'multinegocio-3a-v1', 'servidor' => $admin->server_info,
            'tablas' => $admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->num_rows - 1,
            'triggers' => $admin->query('SHOW TRIGGERS')->num_rows]];
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    $falloLimpieza = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$nombre, $token]) {
            try {
                if (multinegocioVerificarBase($admin, $nombre, $token, $config['DB_NAME'] ?? '')) {
                    $admin->query('DROP DATABASE ' . multinegocioIdent($nombre));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $nombre\n");
                } else { $falloLimpieza = true; }
            } catch (Throwable) { $falloLimpieza = true; }
        }
        $admin->close();
    }
    foreach ($salidas as [$ruta, $padre]) {
        try { multinegocioLimpiarDirectorio($ruta, $padre); }
        catch (Throwable) { $falloLimpieza = true; }
    }
    if ($falloLimpieza && $error === null) {
        $fase = 'limpieza de bases/fixtures';
        $error = 'No se pudo verificar o limpiar algún recurso temporal.';
    }
}
if ($error === null) echo Comun::json($salidaFinal ?? ['ok' => true, 'comprobaciones' => $total]);
else fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $fase, 'comprobaciones' => $total, 'error' => $error]));
exit($error === null ? 0 : 1);
