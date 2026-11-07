<?php
declare(strict_types=1);
require __DIR__ . '/informes-db-bootstrap.php';
use Services\Shared\LecturaConsistente as L;
use Services\Finance\FinanzasNegocio as F;
use Services\Analytics\AnalisisNegocio as A;

$reader = pdfTestServer();
$name = 'cp_pdf_consistency_' . bin2hex(random_bytes(10));
$token = bin2hex(random_bytes(32));
$created = false; $writer = null; $checks = 0;
$assert = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
try {
    $reader->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
    $created = true; $reader->select_db($name);
    $reader->query('CREATE TABLE _cp_snapshot_owner(token CHAR(64) PRIMARY KEY)');
    $reader->execute_query('INSERT INTO _cp_snapshot_owner VALUES(?)', [$token]);
    $reader->query('CREATE TABLE probe(id INT PRIMARY KEY, valor INT NOT NULL) ENGINE=InnoDB');
    $reader->query('INSERT INTO probe VALUES(1,0)');
    $reader->query('CREATE TABLE tickets(id INT PRIMARY KEY,unidad_id INT,estado VARCHAR(20),hora_apertura DATETIME,hora_cierre DATETIME NULL,propina DECIMAL(10,2)) ENGINE=InnoDB');
    $reader->query('CREATE TABLE ticket_items(id INT PRIMARY KEY,ticket_id INT,unidad_id INT,estado VARCHAR(20),precio DECIMAL(10,2),cantidad INT,costo_unitario DECIMAL(10,2)) ENGINE=InnoDB');
    $reader->query("INSERT INTO tickets VALUES(1,1,'cerrado','2001-01-15 12:00:00','2001-01-15 12:00:00',0),(2,1,'abierto','2001-01-15 13:00:00',NULL,0)");
    $reader->query("INSERT INTO ticket_items VALUES(1,1,1,'entregado',100,1,10),(2,2,1,'entregado',50,1,10)");
    $writer = pdfTestServer(); $writer->select_db($name);
    $writer->query('SET SESSION innodb_lock_wait_timeout=1');
    $assert($writer->query('SELECT token FROM _cp_snapshot_owner')->fetch_row()[0] === $token, 'Propiedad segunda conexión');
    $duration = 0.0;
    $snapshot = L::ejecutar($reader, static function() use ($reader, $writer, &$duration): array {
        $before = F::agregados($reader, 1, '2001-01-15', '2001-01-15')['ingresos'];
        $start = microtime(true);
        $writer->query("UPDATE tickets SET estado='cerrado',hora_cierre='2001-01-15 14:00:00' WHERE id=2");
        $duration = microtime(true) - $start;
        $series = A::ventasPorDia($reader, 1, '2001-01-15', '2001-01-15');
        return ['antes' => $before, 'serie' => array_sum($series)];
    });
    $assert($snapshot === ['antes' => 100.0, 'serie' => 100.0], 'KPI y serie deben conservar una misma instantánea');
    $assert($duration < 1.0, 'Escritor bloqueado por lectura documental');
    $assert(F::agregados($reader, 1, '2001-01-15', '2001-01-15')['ingresos'] === 150.0, 'Commit lector debe permitir ver cierre nuevo');

    $rejected = false;
    try { L::ejecutar($reader, static function() use ($reader): array { $reader->query('UPDATE probe SET valor=99 WHERE id=1'); return []; }); }
    catch (Throwable $e) { $rejected = true; }
    $assert($rejected && (int)$writer->query('SELECT valor FROM probe WHERE id=1')->fetch_row()[0] === 0, 'DML dentro de helper debe rechazarse');
    $reader->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $callbackError = false;
    try { L::ejecutar($reader, static function(): array { throw new RuntimeException('fallo de fixture'); }); }
    catch (RuntimeException $e) { $callbackError = $e->getMessage() === 'fallo de fixture'; }
    $assert($callbackError, 'Error del callback debe propagarse');
    $reader->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $assert(L::ejecutar($reader, static fn(): array => ['limpio' => true]) === ['limpio' => true], 'Errores deben dejar conexión utilizable sin transacción abierta');

    $reader->begin_transaction();
    $reader->query('UPDATE probe SET valor=10 WHERE id=1');
    $assert((int)$reader->query('SELECT @@autocommit')->fetch_row()[0] === 1, 'Caso transacción explícita conserva autocommit1');
    $ran = false; $rejected = false;
    try { L::ejecutar($reader, static function() use (&$ran): array { $ran = true; return []; }); }
    catch (Throwable) { $rejected = true; }
    $assert($rejected && !$ran, 'Helper debe rechazar transacción explícita ajena');
    $assert((int)$reader->query('SELECT valor FROM probe WHERE id=1')->fetch_row()[0] === 10, 'Helper no debe rollback ajeno');
    $assert((int)$writer->query('SELECT valor FROM probe WHERE id=1')->fetch_row()[0] === 0, 'Helper no debe commit ajeno');
    $reader->rollback();
    $assert((int)$reader->query('SELECT valor FROM probe WHERE id=1')->fetch_row()[0] === 0, 'Dueño debe conservar rollback de transacción externa');

    $reader->autocommit(false); $rejected = false;
    try { L::ejecutar($reader, static fn(): array => []); }
    catch (Throwable) { $rejected = true; }
    $assert($rejected && (int)$reader->query('SELECT @@autocommit')->fetch_row()[0] === 0, 'Autocommit0 debe rechazarse sin cambiar sesión');
    $reader->rollback(); $reader->autocommit(true);
    echo 'PASS consistencia independiente: ' . $checks . ' comprobaciones; dos conexiones KPI100/serie100, después150; escritor=' . round($duration, 4) . 's; DML prohibido, errores limpian sólo TX propia, outer TX/autocommit0 preservados.' . PHP_EOL;
} finally {
    if ($writer instanceof mysqli) $writer->close();
    if ($created) {
        $reader->rollback(); $reader->autocommit(true);
        if (!preg_match('/\Acp_pdf_consistency_[a-f0-9]{20}\z/', $name)
            || $reader->query('SELECT DATABASE()')->fetch_row()[0] !== $name
            || !hash_equals($token, (string)$reader->query('SELECT token FROM _cp_snapshot_owner')->fetch_row()[0])) {
            throw new RuntimeException('No se verificó propiedad para limpieza');
        }
        $reader->query('DROP DATABASE `' . $name . '`');
        echo 'Base de prueba de consistencia propia eliminada.' . PHP_EOL;
    }
    $reader->close();
}
