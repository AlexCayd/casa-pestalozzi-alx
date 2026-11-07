<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
use Services\Pos\HistorialTickets;
use Services\Finance\FinanzasNegocio;
use Services\Shared\RangoPeriodo;
use Services\Shared\GeneradorPdf;
function verificarTickets(bool $ok, string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); }
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
$nombre = 'cp_informes_test_' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
$creada = false;
try {
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4");
    $creada = true;
    $db->select_db($nombre);
    verificarTickets($db->query('SELECT DATABASE()')->fetch_row()[0] === $nombre, 'Base aislada incorrecta');
    $db->query('CREATE TABLE _cp_informes_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_informes_owner VALUES (?)', [$token]);
    $db->query('CREATE TABLE tickets(id INT PRIMARY KEY, unidad_id INT, estado VARCHAR(20), hora_cierre DATETIME, hora_apertura DATETIME, propina DECIMAL(10,2))');
    $db->query('CREATE TABLE ticket_items(id INT PRIMARY KEY, ticket_id INT, unidad_id INT, estado VARCHAR(20), precio DECIMAL(10,2), cantidad DECIMAL(10,3), costo_unitario DECIMAL(10,2) NULL)');
    $db->query("INSERT INTO tickets VALUES (1,1,'cerrado','2024-01-01 23:59:59','2024-01-01 12:00:00',15),(2,1,'cerrado',NULL,'2024-12-31 23:59:59',5),(3,1,'cancelado',NULL,'2024-01-01 00:00:00',0),(4,1,'cancelado','2025-01-01 00:00:00','2024-12-31 23:00:00',0),(5,1,'abierto',NULL,'2024-01-01 12:00:00',0),(6,2,'cerrado','2024-01-01 12:00:00','2024-01-01 11:00:00',0),(7,2,'cancelado','2024-01-01 12:00:00','2024-01-01 11:00:00',0)");
    $db->query("INSERT INTO ticket_items VALUES (1,1,1,'entregado',100,2,20),(2,1,1,'cancelado',500,1,10),(3,2,1,'entregado',50,1,NULL),(4,3,1,'entregado',900,1,10),(5,6,2,'entregado',900,1,10)");
    $rango = RangoPeriodo::resolverInforme(['desde'=>'2024-01-01','hasta'=>'2024-12-31']);
    $autoridad = FinanzasNegocio::agregados($db, 1, $rango['start'], $rango['end']);
    $doc = HistorialTickets::informe($db, 1, $rango, '2026-11-07 12:00:00');
    $k = array_column($doc['kpis'], 'valor', 'etiqueta');
    verificarTickets($k['Tickets cerrados'] === 2 && $k['Tickets cancelados'] === 1 && $k['Ventas'] === 250.0 && $k['Ticket promedio'] === 125.0, 'Cifras o exclusiones incorrectas');
    verificarTickets($k['Ventas'] === $autoridad['ingresos'] && $k['Tickets cerrados'] === $autoridad['tickets'], 'Sin paridad financiera');
    $serie = $doc['secciones'][1]['filas'];
    verificarTickets(count($serie) === 366 && $serie[0] === ['2024-01-01', 200.0] && $serie[1] === ['2024-01-02', 0.0] && $serie[365] === ['2024-12-31', 50.0], 'Serie diaria incorrecta');
    $pdf = GeneradorPdf::documento($doc);
    verificarTickets(str_starts_with($pdf->output(), '%PDF-') && $pdf->getCanvas()->get_page_count() > 1, 'PDF largo inválido');
    $vacio = RangoPeriodo::resolverInforme(['desde'=>'2023-01-01','hasta'=>'2023-01-02']);
    $kv = array_column(HistorialTickets::informe($db, 1, $vacio, '2026-11-07 12:00:00')['kpis'], 'valor', 'etiqueta');
    verificarTickets($kv['Ticket promedio'] === null && $kv['Tickets cancelados'] === 0, 'Periodo vacío incorrecto');
    $db->query('DROP TABLE ticket_items');
    try { HistorialTickets::informe($db, 1, $rango, '2026-11-07 12:00:00'); throw new RuntimeException('Fuente ausente produjo informe'); }
    catch (mysqli_sql_exception $e) { /* esperado */ }
    echo "PASS tickets: paridad, límites, exclusiones, vacío, 366 días y fallo de fuente\n";
} finally {
    if ($creada) {
        $actual = $db->query('SELECT DATABASE()')->fetch_row()[0];
        $dueno = $db->query('SELECT token FROM _cp_informes_owner')->fetch_row()[0] ?? '';
        if ($actual !== $nombre || $dueno !== $token || !preg_match('/\Acp_informes_test_[a-f0-9]{12}\z/', $nombre)) throw new RuntimeException('No se pudo confirmar la propiedad de la base aislada.');
        $db->query("DROP DATABASE `{$nombre}`");
    }
}
