<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
use Services\Finance\FinanzasNegocio;
use Services\Shared\GeneradorPdf;
use Services\Shared\RangoPeriodo;

function verificarInforme(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
}
function igualInforme(float $actual, float $esperado): bool { return abs($actual - $esperado) < 0.001; }
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
$nombre = 'cp_informes_test_' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
$creada = false;
try {
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4");
    $creada = true;
    $db->select_db($nombre);
    verificarInforme($db->query('SELECT DATABASE()')->fetch_row()[0] === $nombre, 'Base aislada incorrecta');
    $db->query('CREATE TABLE _cp_informes_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_informes_owner VALUES (?)', [$token]);
    $db->query('CREATE TABLE tickets(id INT PRIMARY KEY, unidad_id INT, estado VARCHAR(20), hora_cierre DATETIME, hora_apertura DATETIME, propina DECIMAL(10,2))');
    $db->query('CREATE TABLE ticket_items(id INT PRIMARY KEY, ticket_id INT, unidad_id INT, estado VARCHAR(20), precio DECIMAL(10,2), cantidad DECIMAL(10,3), costo_unitario DECIMAL(10,2) NULL)');
    $db->query('CREATE TABLE movimientos_inventario(unidad_id INT, tipo VARCHAR(20), fecha DATETIME, delta DECIMAL(10,3), costo_unitario DECIMAL(10,2) NULL)');
    $db->query('CREATE TABLE gastos_fijos(unidad_id INT, activo INT, categoria VARCHAR(30), monto DECIMAL(10,2))');
    $db->query('CREATE TABLE almacenes(id INT, unidad_id INT, activo INT)');
    $db->query('CREATE TABLE almacen_articulos(almacen_id INT, unidad_id INT, articulo_id INT, saldo DECIMAL(10,2), activo INT)');
    $db->query('CREATE TABLE ingrediente_proveedores(unidad_id INT, ingrediente_id INT, preferente INT, costo DECIMAL(10,2))');
    $db->query("INSERT INTO tickets VALUES (1,1,'cerrado','2026-10-01 23:59:59','2026-10-01 12:00:00',15),(2,1,'cerrado','2026-10-02 09:00:00','2026-10-02 08:00:00',0),(3,1,'cancelado','2026-10-02 10:00:00','2026-10-02 09:00:00',0),(4,2,'cerrado','2026-10-01 12:00:00','2026-10-01 11:00:00',0),(5,1,'cerrado','2026-09-30 12:00:00','2026-09-30 11:00:00',0)");
    $db->query("INSERT INTO ticket_items VALUES (1,1,1,'entregado',100,1,40),(2,1,1,'cancelado',500,1,10),(3,2,1,'entregado',50,1,NULL),(4,2,1,'entregado',10,1,0),(5,3,1,'entregado',900,1,10),(6,4,2,'entregado',900,1,10),(7,5,1,'entregado',80,1,20)");
    $db->query("INSERT INTO movimientos_inventario VALUES (1,'merma','2026-10-02 11:00:00',-2,5),(1,'merma','2026-10-02 11:00:00',-1,NULL),(2,'merma','2026-10-02 11:00:00',-10,50)");
    $db->query("INSERT INTO gastos_fijos VALUES (1,1,'Renta',300),(2,1,'Renta',900)");
    $rango = RangoPeriodo::resolverInforme(['desde' => '2026-10-01', 'hasta' => '2026-10-02']);
    $resumen = FinanzasNegocio::resumen($db, 'restaurante', 1, $rango);
    $doc = FinanzasNegocio::informe($db, 1, $rango, '2026-11-06 12:00:00');
    $k = array_column($doc['kpis'], 'valor', 'etiqueta');
    verificarInforme(igualInforme($k['Ingresos por ventas'], 160) && $k['Tickets cerrados'] === 2, 'Ventas/tickets o aislamiento incorrectos');
    verificarInforme(igualInforme($k['Costo de insumos'], 40) && igualInforme($k['Utilidad bruta'], 110) && igualInforme($k['Utilidad neta'], 90), 'Balance incorrecto');
    verificarInforme(igualInforme($resumen['sinCosto'], 50) && igualInforme($resumen['previo']['ingresos'], 80), 'Costo desconocido o comparativo incorrecto');
    verificarInforme($k['Ingresos por ventas'] === $resumen['ingresos'] && $k['Utilidad neta'] === $resumen['utilidadNeta'], 'Sin paridad con autoridad');
    verificarInforme(str_starts_with(GeneradorPdf::documento($doc)->output(), '%PDF-'), 'PDF inválido');
    $vacio = RangoPeriodo::resolverInforme(['desde' => '2026-08-01', 'hasta' => '2026-08-02']);
    $docVacio = FinanzasNegocio::informe($db, 1, $vacio, '2026-11-06 12:00:00');
    verificarInforme($docVacio['kpis'][0]['comparacion'] === null && $docVacio['kpis'][2]['valor'] === null, 'Base vacía incorrecta');
    $db->query('DROP TABLE gastos_fijos');
    try { FinanzasNegocio::informe($db, 1, $rango, '2026-11-06 12:00:00'); throw new RuntimeException('La fuente ausente produjo informe'); }
    catch (mysqli_sql_exception $e) { /* error esperado */ }
    echo "PASS informes financieros: paridad, exclusiones, vacío y fallo de fuente\n";
} finally {
    if ($creada) {
        $actual = $db->query('SELECT DATABASE()')->fetch_row()[0];
        $dueno = $db->query('SELECT token FROM _cp_informes_owner')->fetch_row()[0] ?? '';
        if ($actual !== $nombre || $dueno !== $token || !preg_match('/\Acp_informes_test_[a-f0-9]{12}\z/', $nombre)) throw new RuntimeException('No se pudo confirmar la propiedad de la base aislada.');
        $db->query("DROP DATABASE `{$nombre}`");
    }
}
