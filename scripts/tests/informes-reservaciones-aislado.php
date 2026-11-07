<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
use Services\Reservations\InformeReservaciones;
use Services\Shared\GeneradorPdf;
use Services\Shared\RangoPeriodo;

function verificarReserva(bool $ok, string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); }
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int) ($_ENV['DB_PORT'] ?? 3306));
$nombre = 'cp_informes_test_' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
$creada = false;
try {
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4");
    $creada = true;
    $db->select_db($nombre);
    verificarReserva($db->query('SELECT DATABASE()')->fetch_row()[0] === $nombre, 'Base aislada incorrecta');
    $db->query('CREATE TABLE _cp_informes_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_informes_owner VALUES (?)', [$token]);
    $db->query('CREATE TABLE reservaciones(id INT PRIMARY KEY, unidad_id INT, fecha DATE, estado VARCHAR(30), comensales INT, nombre VARCHAR(100), contacto VARCHAR(100), nota TEXT)');
    $db->query("INSERT INTO reservaciones VALUES (1,1,'2026-10-01','confirmada',2,'Nombre privado','contacto privado','nota privada'),(2,1,'2026-10-01','en_curso',3,'X','',''),(3,1,'2026-10-02','completada',4,'X','',''),(4,1,'2026-10-02','no_show',5,'X','',''),(5,1,'2026-10-02','cancelada',6,'X','',''),(6,1,'2026-10-02','reemplazada',20,'X','',''),(7,1,'2026-10-02','expirada',20,'X','',''),(8,1,'2026-10-02','pendiente_verificacion',20,'X','',''),(9,2,'2026-10-02','completada',40,'X','','')");
    $antes = $db->query('SELECT * FROM reservaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $rango = RangoPeriodo::resolverInforme(['desde' => '2026-10-01', 'hasta' => '2026-10-03']);
    $ahora = new DateTimeImmutable('2026-11-06 12:00:00', new DateTimeZone('America/Mexico_City'));
    $doc = InformeReservaciones::documento($db, 1, $rango, $ahora);
    $k = array_column($doc['kpis'], 'valor', 'etiqueta');
    verificarReserva($k['Reservaciones de negocio'] === 5 && $k['Comensales programados'] === 20, 'Población incorrecta');
    verificarReserva($k['Atendidas'] === 1 && $k['No llegaron registrados'] === 1 && $k['Canceladas'] === 1 && $k['Pendientes de cierre'] === 2, 'Estados incorrectos');
    verificarReserva($doc['secciones'][1]['filas'] === [['2026-10-01', 2], ['2026-10-02', 3], ['2026-10-03', 0]], 'Serie incorrecta');
    verificarReserva(str_contains($doc['resumen'], 'pendientes de cierre') && !str_contains($doc['titulo'], 'Provisional'), 'Advertencia histórica incorrecta');
    verificarReserva(!str_contains(json_encode($doc), 'privado'), 'Datos personales en documento');
    $pdf = GeneradorPdf::documento($doc);
    verificarReserva(str_starts_with($pdf->output(), '%PDF-'), 'PDF inválido');
    verificarReserva($antes === $db->query('SELECT * FROM reservaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'La lectura modificó reservas');
    $hoy = RangoPeriodo::resolverInforme(['desde' => '2026-11-06', 'hasta' => '2026-11-06']);
    verificarReserva(str_contains(InformeReservaciones::documento($db, 1, $hoy, $ahora)['titulo'], 'Provisional'), 'Hoy debe ser provisional');
    $futuro = RangoPeriodo::resolverInforme(['desde' => '2026-11-07', 'hasta' => '2026-11-10']);
    verificarReserva(str_contains(InformeReservaciones::documento($db, 1, $futuro, $ahora)['resumen'], 'provisionales'), 'Futuro debe ser provisional');
    verificarReserva(RangoPeriodo::resolverInforme([], $ahora->format('Y-m-d'))['start'] === '2026-10-08', 'Predeterminado retrospectivo incorrecto');
    $db->query('DROP TABLE reservaciones');
    try { InformeReservaciones::documento($db, 1, $rango, $ahora); throw new RuntimeException('Fuente ausente produjo informe'); }
    catch (mysqli_sql_exception $e) { /* error esperado */ }
    echo "PASS informe de reservaciones: estados, periodo, aislamiento y lectura sin cambios\n";
} finally {
    if ($creada) {
        $actual = $db->query('SELECT DATABASE()')->fetch_row()[0];
        $dueno = $db->query('SELECT token FROM _cp_informes_owner')->fetch_row()[0] ?? '';
        if ($actual !== $nombre || $dueno !== $token || !preg_match('/\Acp_informes_test_[a-f0-9]{12}\z/', $nombre)) throw new RuntimeException('No se pudo confirmar la propiedad de la base aislada.');
        $db->query("DROP DATABASE `{$nombre}`");
    }
}
