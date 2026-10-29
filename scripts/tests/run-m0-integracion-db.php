<?php
declare(strict_types=1);
/** Importación SQL directa y corte contra fixtures; destino aleatorio propio. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require __DIR__ . '/../multinegocio/SqlArchivo.php';
require __DIR__ . '/pruebas-datos-3e.php';
use Multinegocio\SqlArchivo;
use Services\Pos\CorteCaja;
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
$op = getopt('', ['host:', 'base:', 'entorno:']);
foreach (['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $arg=>$key) {
    if (($op[$arg] ?? null) !== ($_ENV[$key] ?? null)) throw new RuntimeException('Identifica --host --base --entorno conforme a la configuración.');
}
if (!in_array($op['entorno'], ['development','test'], true)) throw new RuntimeException('Solo desarrollo/pruebas.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10)); $token = bin2hex(random_bytes(32));
$created = false; $count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void { if (!$ok) throw new RuntimeException($msg); $count++; };
try {
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');
    $s = $db->prepare('INSERT INTO _cp_multinegocio_owner VALUES (?)'); $s->execute([$token]);
    // Sin marca _cp_instalacion_owner: reproduce la importación SQL directa.
    foreach (['ddl','deploy','menu-restaurante','development'] as $sql) SqlArchivo::ejecutar($db, $root . '/database/' . $sql . '.sql');
    Model\ActiveRecord::setDB($db);
    $datos = probarDatos3E($db, $root, $count);
    $password = (string)($_ENV['DEV_SUPERADMIN_PASSWORD'] ?? getenv('DEV_SUPERADMIN_PASSWORD'));
    $user = Model\Usuario::porCredenciales('admin_demo', $password); unset($password);
    $assert($user !== null && (int)$user->es_superadmin === 1, 'SQL directo preserva la identidad admin_demo con hash válido.');
    $assert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE username='admin_demo'")->fetch_row()[0] === 1, 'No hay duplicados.');
    $assert(count(Services\Security\PermisosUsuario::cargar((int)$user->id)['ambitos']) === 3, 'SQL directo habilita tres ámbitos autorizados.');
    $assert(Services\Security\DosFactores::requiere($user), 'admin_demo SQL directo exige segundo factor.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets WHERE estado='abierto'")->fetch_row()[0] > 0, 'El fixture contiene tickets abiertos para comprobar su exclusión.');
    foreach ([2,3] as $unidad) {
        $corte = CorteCaja::resumen('2000-01-01 00:00:00', '2100-01-01 00:00:00', $unidad);
        $row = $db->query("SELECT COUNT(*) n,COALESCE(SUM(propina),0) propinas FROM tickets WHERE estado='cerrado' AND unidad_id={$unidad}")->fetch_assoc();
        $ventas = (float)$db->query("SELECT COALESCE(SUM(i.precio*i.cantidad),0) FROM ticket_items i JOIN tickets t ON t.id=i.ticket_id AND t.unidad_id=i.unidad_id WHERE t.estado='cerrado' AND i.estado<>'cancelado' AND t.unidad_id={$unidad}")->fetch_row()[0];
        $assert($corte['resumen']['tickets'] === (int)$row['n'], 'Corte excluye abiertos y respeta negocio vendedor.');
        $assert(abs($corte['resumen']['ventas'] - $ventas) < .01, 'Ventas coinciden con agregación independiente usada por Finanzas.');
        $assert(abs($corte['resumen']['propinas'] - (float)$row['propinas']) < .01, 'Propinas conservadas.');
        foreach (['efectivo','tarjeta'] as $metodo) {
            $monto = (float)$db->query("SELECT COALESCE(SUM(i.precio*i.cantidad),0) FROM ticket_items i JOIN tickets t ON t.id=i.ticket_id AND t.unidad_id=i.unidad_id WHERE t.estado='cerrado' AND i.estado<>'cancelado' AND t.unidad_id={$unidad} AND t.metodo_pago='{$metodo}'")->fetch_row()[0];
            $monto += (float)$db->query("SELECT COALESCE(SUM(propina),0) FROM tickets WHERE estado='cerrado' AND unidad_id={$unidad} AND metodo_pago='{$metodo}'")->fetch_row()[0];
            $monto += (float)$db->query("SELECT COALESCE(SUM(p.monto),0) FROM ticket_pagos p JOIN tickets t ON t.id=p.ticket_id AND t.unidad_id=p.unidad_id WHERE t.estado='cerrado' AND t.unidad_id={$unidad} AND t.metodo_pago='dividido' AND p.metodo_pago='{$metodo}'")->fetch_row()[0];
            $assert(abs($corte['metodos'][$metodo] - $monto) < .01, 'Método de pago y cobros divididos coherentes.');
        }
        $fecha = (string)$db->query('SELECT DATE_SUB(CURDATE(),INTERVAL 1 DAY)')->fetch_row()[0];
        $dia = CorteCaja::ventana($fecha, null);
        $entero = CorteCaja::resumen($dia['desde'], $dia['hasta'], $unidad);
        $a = CorteCaja::resumen($dia['desde'], $fecha . ' 15:00:00', $unidad);
        $b = CorteCaja::resumen($fecha . ' 15:00:00', $dia['hasta'], $unidad);
        $assert($a['resumen']['tickets'] + $b['resumen']['tickets'] === $entero['resumen']['tickets'], 'Ventanas sin solape conservan tickets diarios.');
        $assert(abs($a['resumen']['total'] + $b['resumen']['total'] - $entero['resumen']['total']) < .01, 'Suma de turnos conserva total diario.');
    }
    echo json_encode(['ok'=>true,'comprobaciones'=>$count,'menu'=>$datos['menu'],'sql_directo'=>'OK','corte_por_negocio'=>'OK'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    if ($created && $name !== $op['base'] && preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) {
        $mark = $db->query('SELECT token FROM `' . $name . '`._cp_multinegocio_owner')->fetch_row()[0] ?? '';
        if (!hash_equals($token, (string)$mark)) throw new RuntimeException('Propiedad no verificada.');
        $db->query('DROP DATABASE `' . $name . '`'); echo "Base temporal propia eliminada.\n";
    }
    $db->close();
}
