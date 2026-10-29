<?php
declare(strict_types=1);

/** Identidades reales del DDL 3B; nunca selecciona la base configurada. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../multinegocio/SqlArchivo.php';
require __DIR__ . '/fixture-3b.php';

use Classes\Auth;
use Model\ActiveRecord;
use Model\Usuario;
use Multinegocio\SqlArchivo;
use Services\Security\DosFactores;

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
$op = getopt('', ['host:', 'base:', 'entorno:', 'auditar-original']);
foreach (['host'=>'DB_HOST', 'base'=>'DB_NAME', 'entorno'=>'APP_ENV'] as $arg=>$key) {
    if (($op[$arg] ?? null) !== ($_ENV[$key] ?? null)) throw new RuntimeException('Identifica --host --base --entorno conforme a la configuración local.');
}
if (!in_array($op['entorno'], ['development', 'test'], true)) throw new RuntimeException('Solo desarrollo/pruebas.');
Auth::start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
$token = bin2hex(random_bytes(32));
$created = false;
$count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void { if (!$ok) throw new RuntimeException($msg); $count++; };
try {
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');
    $s = $db->prepare('INSERT INTO _cp_multinegocio_owner VALUES (?)'); $s->execute([$token]);
    foreach (['ddl', 'deploy'] as $sql) SqlArchivo::ejecutar($db, dirname(__DIR__, 2) . '/database/' . $sql . '.sql');
    ActiveRecord::setDB($db);
    $_ENV['APP_ENV'] = 'test'; putenv('APP_ENV=test');
    $_SESSION = [];
    $super = FixtureAutorizacion3B::usuario($db, 'seguridad_super', [], true);
    $admin = FixtureAutorizacion3B::usuario($db, 'seguridad_admin', ['restaurante'=>['permisos'=>['users'=>'ver']]]);
    $piso = FixtureAutorizacion3B::usuario($db, 'seguridad_piso', ['restaurante'=>['permisos'=>['pdv'=>'operar']]]);
    $usuario = Usuario::porCredenciales($super['username'], 'fixture-pass-3b');
    $assert($usuario !== null && (int)$usuario->es_superadmin === 1 && $usuario->rol === 'waiter', 'Se reproduce la hidratación 3B sin rol almacenado.');
    if (isset($op['auditar-original'])) {
        $assert(!DosFactores::aplica((string)$usuario->rol), 'El código original omite el reto del superadministrador real.');
        Auth::login($usuario);
        $assert(Auth::check(), 'La contraseña sola abre sesión en el flujo original.');
        echo "BYPASS CONFIRMADO: superadministrador 3B autenticado sin reto; {$count} comprobaciones.\n";
    } else {
        $assert(DosFactores::requiere($usuario), 'Superadministrador real requiere segundo factor.');
        $directo = Usuario::porCredenciales($admin['username'], 'fixture-pass-3b');
        $assert($directo !== null && DosFactores::requiere($directo), 'Permisos administrativos directos requieren segundo factor.');
        $assert(!DosFactores::requiere(Usuario::find($piso['id'])), 'Personal exclusivamente operativo no usa segundo factor administrativo.');
        $assert(Usuario::porCredenciales($super['username'], 'incorrecta') === null, 'Contraseña incorrecta no produce identidad.');
        DosFactores::iniciar($usuario);
        $assert(!Auth::check(), 'El reto no abre sesión.');
        $assert(DosFactores::reto()['entrega'] === 'SIN_CORREO', 'Cuenta sin correo conserva reto cerrado, sin excepción de producción.');
        $code = (string)DosFactores::reto()['visible'];
        $db->query('UPDATE usuarios SET activo=0 WHERE id=' . $super['id']);
        $assert(DosFactores::verificar($code)['resultado'] === 'revocado', 'Desactivación durante el reto impide completar acceso.');
        DosFactores::iniciar($directo);
        $code = (string)DosFactores::reto()['visible'];
        FixtureAutorizacion3B::permiso($db, $admin['id'], 'restaurante', 'users', null);
        $assert(DosFactores::verificar($code)['resultado'] === 'revocado', 'Revocación durante el reto impide completar acceso.');
        $assert(!Auth::check(), 'Ningún caso de rechazo abre sesión.');
        echo "Segundo factor 3B: {$count} comprobaciones OK.\n";
    }
} finally {
    $_SESSION = [];
    if ($created && $name !== $op['base'] && preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) {
        $mark = $db->query('SELECT token FROM `' . $name . '`._cp_multinegocio_owner')->fetch_row()[0] ?? '';
        if (!hash_equals($token, (string)$mark)) throw new RuntimeException('No se verificó propiedad para limpiar.');
        $db->query('DROP DATABASE `' . $name . '`');
        echo "Base temporal propia eliminada.\n";
    }
    $db->close();
}
