<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
use Services\Inventory\AlcanceInventario;
use Services\Inventory\ConsultasAlmacen;
use Services\Shared\GeneradorPdf;
use Services\Shared\RangoPeriodo;

function verificarInventario(bool $ok, string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); }
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
$nombre = 'cp_informes_test_' . bin2hex(random_bytes(6)); $token = bin2hex(random_bytes(32)); $creada = false;
try {
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4"); $creada = true; $db->select_db($nombre);
    verificarInventario($db->query('SELECT DATABASE()')->fetch_row()[0] === $nombre, 'Base aislada incorrecta');
    $db->query('CREATE TABLE _cp_informes_owner(token CHAR(64) PRIMARY KEY)'); $db->execute_query('INSERT INTO _cp_informes_owner VALUES (?)', [$token]);
    $db->query('CREATE TABLE usuarios(id INT, nombre VARCHAR(20), username VARCHAR(20), activo INT, es_superadmin INT)');
    $db->query('CREATE TABLE unidades(id INT, slug VARCHAR(20))');
    $db->query('CREATE TABLE usuario_unidades(usuario_id INT, unidad_id INT, activo INT)');
    $db->query('CREATE TABLE usuario_unidad_permisos(usuario_id INT, unidad_id INT, modulo VARCHAR(30), nivel VARCHAR(20))');
    $db->query('CREATE TABLE usuario_unidad_facultades(usuario_id INT, unidad_id INT, facultad VARCHAR(50))');
    $db->query("INSERT INTO usuarios VALUES(1,'Test','test',1,0)");
    $db->query("INSERT INTO unidades VALUES(1,'restaurante'),(2,'panaderia')");
    $db->query('INSERT INTO usuario_unidades VALUES(1,1,1)');
    $db->query("INSERT INTO usuario_unidad_permisos VALUES(1,1,'inventario','ver')");
    $db->query('CREATE TABLE almacenes(id INT, unidad_id INT, nombre VARCHAR(60), activo INT)');
    $db->query('CREATE TABLE ingredientes(id INT, nombre VARCHAR(100), unidad VARCHAR(20), activo INT)');
    $db->query('CREATE TABLE almacen_articulos(almacen_id INT, unidad_id INT, articulo_id INT, saldo DECIMAL(12,3), minimo DECIMAL(12,3), activo INT)');
    $db->query('CREATE TABLE movimientos_inventario(id INT, unidad_id INT, almacen_id INT, tipo VARCHAR(30), delta DECIMAL(12,3), costo_unitario DECIMAL(10,4), fecha DATETIME)');
    $db->query("INSERT INTO almacenes VALUES(1,1,'Almacén A',1),(2,1,'Almacén B',1),(3,1,'Inactivo',0),(4,2,'Ajeno',1)");
    for($i=1;$i<=30;$i++) {
        $db->execute_query('INSERT INTO ingredientes VALUES(?,?,?,1)',[$i,'Artículo '.str_pad((string)$i,2,'0',STR_PAD_LEFT),$i%2?'kg':'pza']);
        $db->execute_query('INSERT INTO almacen_articulos VALUES(1,1,?,?,2,1)',[$i,$i===1?-0.123:1.001]);
    }
    $db->query('INSERT INTO almacen_articulos VALUES(2,1,1,4,2,1),(3,1,1,0,2,1),(4,2,1,0,2,1)');
    $db->query("INSERT INTO movimientos_inventario VALUES(1,1,1,'merma',-2,5,'2026-10-01 23:59:59'),(2,1,1,'merma',-1,NULL,'2026-10-02 10:00:00'),(3,1,3,'entrada',1,0,'2026-10-02 10:00:00'),(4,2,4,'venta',-1,5,'2026-10-01 10:00:00'),(5,1,1,'venta',-1,5,'2026-10-03 00:00:00')");
    $antes=$db->query('SELECT * FROM almacen_articulos ORDER BY almacen_id,articulo_id')->fetch_all(MYSQLI_ASSOC);
    $rango=RangoPeriodo::resolverInforme(['desde'=>'2026-10-01','hasta'=>'2026-10-02']);
    $doc=(new ConsultasAlmacen($db,new AlcanceInventario($db,1,'contrasena')))->informe('restaurante',$rango,'2026-11-06 12:00:00');
    $k=array_column($doc['kpis'],'valor','etiqueta');
    verificarInventario($k['Almacenes activos']===2 && $k['Relaciones físicas activas']===31 && $k['Artículos distintos activos']===30 && $k['Relaciones bajo mínimo']===30,'Foto incorrecta');
    verificarInventario($k['Movimientos del periodo']===3 && $k['Merma con costo conocido']===10.0,'Movimientos incorrectos');
    verificarInventario(count($doc['secciones'][2]['filas'])===10 && $doc['secciones'][2]['filas'][0][3]===-0.123,'Alertas o milésimas incorrectas');
    verificarInventario($antes===$db->query('SELECT * FROM almacen_articulos ORDER BY almacen_id,articulo_id')->fetch_all(MYSQLI_ASSOC),'La lectura modificó saldos');
    verificarInventario(str_starts_with(GeneradorPdf::documento($doc)->output(),'%PDF-'),'PDF inválido');
    $db->query('DROP TABLE movimientos_inventario');
    try { (new ConsultasAlmacen($db,new AlcanceInventario($db,1,'contrasena')))->informe('restaurante',$rango,'2026-11-06 12:00:00'); throw new RuntimeException('Fuente ausente produjo informe'); }
    catch (mysqli_sql_exception $e) { /* error esperado */ }
    echo "PASS informe inventario: foto, movimientos, unidades, precisión y lectura sin cambios\n";
} finally {
    if($creada) {
        $actual=$db->query('SELECT DATABASE()')->fetch_row()[0]; $dueno=$db->query('SELECT token FROM _cp_informes_owner')->fetch_row()[0]??'';
        if($actual!==$nombre || $dueno!==$token || !preg_match('/\Acp_informes_test_[a-f0-9]{12}\z/',$nombre)) throw new RuntimeException('No se pudo confirmar la propiedad de la base aislada.');
        $db->query("DROP DATABASE `{$nombre}`");
    }
}
