<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
use Services\Analytics\AnalisisNegocio;
use Services\Shared\GeneradorPdf;
use Services\Shared\RangoPeriodo;

function verificarAnalytics(bool $ok, string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); }
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
$nombre = 'cp_informes_test_' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
$creada = false;
try {
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4");
    $creada = true;
    $db->select_db($nombre);
    verificarAnalytics($db->query('SELECT DATABASE()')->fetch_row()[0] === $nombre, 'Base aislada incorrecta');
    $db->query('CREATE TABLE _cp_informes_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_informes_owner VALUES (?)', [$token]);
    $db->query('CREATE TABLE tickets(id INT PRIMARY KEY, unidad_id INT, estado VARCHAR(20), hora_cierre DATETIME, hora_apertura DATETIME, comensales INT)');
    $db->query('CREATE TABLE ticket_items(id INT PRIMARY KEY, ticket_id INT, unidad_id INT, estado VARCHAR(20), nombre VARCHAR(120), precio DECIMAL(10,2), cantidad INT)');
    $db->query("INSERT INTO tickets VALUES (1,1,'cerrado','2024-01-01 23:59:59','2024-01-01 12:00:00',2),(2,1,'cerrado','2024-12-31 20:00:00','2024-12-31 19:00:00',3),(3,1,'cerrado','2024-01-02 12:00:00','2024-01-02 11:00:00',1),(4,1,'cancelado','2024-01-01 12:00:00','2024-01-01 11:00:00',5),(5,2,'cerrado','2024-01-01 12:00:00','2024-01-01 11:00:00',4)");
    $db->query("INSERT INTO ticket_items VALUES (1,1,1,'entregado','Nombre histórico',100,2),(2,1,1,'cancelado','Cancelado',500,1),(3,2,1,'entregado','Otro',50,1),(4,4,1,'entregado','Ticket cancelado',900,1),(5,5,2,'entregado','Unidad ajena',800,1)");
    $rango = RangoPeriodo::resolverInforme(['desde' => '2024-01-01', 'hasta' => '2024-12-31']);
    $autoridad = AnalisisNegocio::totales($db, 1, $rango['start'], $rango['end']);
    $doc = AnalisisNegocio::informe($db, 1, $rango, '2026-11-06 12:00:00');
    $k = array_column($doc['kpis'], 'valor', 'etiqueta');
    verificarAnalytics($k['Ventas'] === 250.0 && $k['Tickets cerrados'] === 3 && $k['Piezas vendidas'] === 3 && $k['Comensales en tickets cerrados'] === 6, 'Totales/exclusiones incorrectos');
    verificarAnalytics(abs($k['Ticket promedio'] - 250 / 3) < 0.00001 && $k['Ventas'] === $autoridad['ventas'], 'Paridad o denominador incorrectos');
    $serie = $doc['secciones'][0]['filas'];
    verificarAnalytics(count($serie) === 366 && $serie[0] === ['2024-01-01', 200.0] && $serie[1] === ['2024-01-02', 0.0] && $serie[365] === ['2024-12-31', 50.0], 'Serie de 366 días incorrecta');
    verificarAnalytics($doc['secciones'][1]['filas'][0] === ['Nombre histórico', 2, 200.0], 'Nombre histórico/top incorrecto');
    verificarAnalytics($doc['kpis'][0]['comparacion'] === null, 'Comparación sin base incorrecta');
    $pdf = GeneradorPdf::documento($doc);
    verificarAnalytics(str_starts_with($pdf->output(), '%PDF-') && $pdf->getCanvas()->get_page_count() > 1, 'PDF largo inválido');
    $vacio = RangoPeriodo::resolverInforme(['desde' => '2023-01-01', 'hasta' => '2023-01-02']);
    verificarAnalytics(AnalisisNegocio::informe($db, 1, $vacio, '2026-11-06 12:00:00')['kpis'][2]['valor'] === null, 'Promedio vacío incorrecto');
    for ($i = 6; $i <= 13; $i++) {
        $db->execute_query("INSERT INTO ticket_items VALUES (?,1,1,'entregado',?,1,1)", [$i, 'Producto ' . $i]);
    }
    verificarAnalytics(count(AnalisisNegocio::informe($db, 1, $rango, '2026-11-06 12:00:00')['secciones'][1]['filas']) === 7, 'Top no limitado a siete');
    $db->query('DROP TABLE ticket_items');
    try { AnalisisNegocio::informe($db, 1, $rango, '2026-11-06 12:00:00'); throw new RuntimeException('Fuente ausente produjo informe'); }
    catch (mysqli_sql_exception $e) { /* error esperado */ }
    echo "PASS informe de análisis: paridad, serie 366 días, exclusiones y fallo de fuente\n";
} finally {
    if ($creada) {
        $actual = $db->query('SELECT DATABASE()')->fetch_row()[0];
        $dueno = $db->query('SELECT token FROM _cp_informes_owner')->fetch_row()[0] ?? '';
        if ($actual !== $nombre || $dueno !== $token || !preg_match('/\Acp_informes_test_[a-f0-9]{12}\z/', $nombre)) throw new RuntimeException('No se pudo confirmar la propiedad de la base aislada.');
        $db->query("DROP DATABASE `{$nombre}`");
    }
}
