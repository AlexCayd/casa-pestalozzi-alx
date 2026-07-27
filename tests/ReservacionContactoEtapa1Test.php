<?php

/**
 * Pruebas reproducibles de integración para identidad por contacto — Etapa 1.
 *
 * Crea y elimina únicamente la base desechable casa_pestalozzi_etapa1_test.
 * No modifica la base configurada para la aplicación.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\Reservacion;
use Services\ContactoAccesoService;
use Services\ContactoService;
use Services\ReservationClientSession;
use Services\ReservacionConfig;

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
ini_set('session.save_path', __DIR__ . '/.sessions');

$databaseName = 'casa_pestalozzi_etapa1_test';
$keepDatabase = in_array('--keep-database', $argv ?? [], true);
$cleanupOnly = in_array('--cleanup', $argv ?? [], true);
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? '')
);

if (!$db) {
    throw new RuntimeException('No fue posible conectar con MySQL para la prueba.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$tests = 0;
$failures = [];

if ($cleanupOnly) {
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->close();
    echo "Base desechable eliminada." . PHP_EOL;
    exit(0);
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function assertSameValue(string $name, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    if ($actual !== $expected) {
        $failures[] = $name . ': esperado ' . var_export($expected, true)
            . ', recibido ' . var_export($actual, true);
    }
}

function assertTrueValue(string $name, bool $condition): void
{
    assertSameValue($name, $condition, true);
}

function runSqlFile(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('No fue posible leer ' . $path);
    }

    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function setPreviewEnvironment(string $environment, bool $preview): void
{
    $_ENV['APP_ENV'] = $environment;
    $_ENV['CONTACT_OTP_PREVIEW'] = $preview ? 'true' : 'false';
    putenv('APP_ENV=' . $environment);
    putenv('CONTACT_OTP_PREVIEW=' . ($preview ? 'true' : 'false'));
}

try {
    if (!$keepDatabase) {
        $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    }
    $db->query(
        "CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $db->select_db($databaseName);
    $selectedDatabase = (string)$db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'];
    if ($selectedDatabase !== $databaseName) {
        throw new RuntimeException('SELECT DATABASE() no coincide con la base desechable.');
    }
    assertSameValue(
        'diagnóstico SELECT DATABASE antes de mutar',
        $selectedDatabase,
        $databaseName
    );
    $db->query("SET time_zone = '-06:00'");
    $db->query("SET timestamp = UNIX_TIMESTAMP('2026-11-30 12:00:00')");
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 12:00:00';
    putenv('APP_ENV=testing');
    putenv('RESERVATION_TEST_NOW=2026-11-30 12:00:00');

    runSqlFile($db, __DIR__ . '/../database/ddl.sql');
    runSqlFile($db, __DIR__ . '/../database/dml.sql');
    ActiveRecord::setDB($db);

    assertSameValue(
        'normalización de correo',
        ContactoService::normalizarEmail(' Cliente@Example.COM '),
        'cliente@example.com'
    );
    assertSameValue(
        'normalización de teléfono con espacios',
        ContactoService::normalizarTelefono('+52 55 1234 5678'),
        '+525512345678'
    );
    assertSameValue(
        'normalización de teléfono con presentación',
        ContactoService::normalizarTelefono('+52 (55) 1234-5678'),
        '+525512345678'
    );

    setPreviewEnvironment('development', true);
    assertTrueValue('preview habilitado en desarrollo', ReservacionConfig::otpPreviewEnabled());
    setPreviewEnvironment('testing', true);
    assertSameValue('reloj controlado de Etapa 4', ReservacionConfig::fechaActual(), '2026-11-30');

    $solicitud = ContactoAccesoService::solicitarCodigo('email', ' OTP@Example.Test ');
    assertTrueValue('generación OTP correo', (bool)($solicitud['ok'] ?? false));
    $otp = (string)($solicitud['preview_code'] ?? '');
    assertTrueValue('OTP de seis dígitos', preg_match('/^\d{6}$/', $otp) === 1);

    $fila = $db->query(
        "SELECT id, codigo_hash, attempts, used_at, invalidated_at
         FROM verificaciones_contacto
         WHERE contacto = 'otp@example.test'
         ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    assertTrueValue('base almacena hash verificable', password_verify($otp, (string)$fila['codigo_hash']));
    assertTrueValue('base no almacena OTP plano', (string)$fila['codigo_hash'] !== $otp);

    $columnasPlanas = (int)$db->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = '{$databaseName}'
           AND table_name = 'verificaciones_contacto'
           AND column_name IN ('codigo','codigo_original','codigo_visible','otp_texto')"
    )->fetch_assoc()['total'];
    assertSameValue('no existen columnas OTP planas', $columnasPlanas, 0);

    $reenvioRapido = ContactoAccesoService::solicitarCodigo('email', 'otp@example.test');
    assertSameValue(
        'límite de reenvío',
        $reenvioRapido['codigo'] ?? '',
        ContactoAccesoService::REENVIO_NO_DISPONIBLE
    );

    $db->query(
        "UPDATE verificaciones_contacto
         SET created_at = DATE_SUB(NOW(), INTERVAL 61 SECOND)
         WHERE id = " . (int)$fila['id']
    );
    $reenvio = ContactoAccesoService::solicitarCodigo('email', 'otp@example.test');
    assertTrueValue('reenvío después del límite', (bool)($reenvio['ok'] ?? false));
    $anteriorInvalidada = $db->query(
        'SELECT invalidated_at FROM verificaciones_contacto WHERE id = ' . (int)$fila['id']
    )->fetch_assoc();
    assertTrueValue('reenvío invalida código anterior', $anteriorInvalidada['invalidated_at'] !== null);

    $incorrecto = ContactoAccesoService::verificarCodigo(
        'email',
        'otp@example.test',
        '000000' === ($reenvio['preview_code'] ?? '') ? '111111' : '000000'
    );
    assertSameValue(
        'código incorrecto',
        $incorrecto['codigo'] ?? '',
        ContactoAccesoService::CODIGO_INVALIDO
    );
    $intentos = (int)$db->query(
        "SELECT attempts FROM verificaciones_contacto
         WHERE contacto = 'otp@example.test' ORDER BY id DESC LIMIT 1"
    )->fetch_assoc()['attempts'];
    assertSameValue('incremento de intentos', $intentos, 1);

    $max = ContactoAccesoService::solicitarCodigo('email', 'max@example.test');
    $wrong = ($max['preview_code'] ?? '') === '000000' ? '111111' : '000000';
    $maxResult = [];
    for ($i = 0; $i < ReservacionConfig::OTP_MAX_ATTEMPTS; $i++) {
        $maxResult = ContactoAccesoService::verificarCodigo('email', 'max@example.test', $wrong);
    }
    assertSameValue(
        'bloqueo tras máximo de intentos',
        $maxResult['codigo'] ?? '',
        ContactoAccesoService::DEMASIADOS_INTENTOS
    );

    $expired = ContactoAccesoService::solicitarCodigo('email', 'expired@example.test');
    $db->query(
        "UPDATE verificaciones_contacto SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
         WHERE contacto = 'expired@example.test'"
    );
    $expiredResult = ContactoAccesoService::verificarCodigo(
        'email',
        'expired@example.test',
        (string)$expired['preview_code']
    );
    assertSameValue(
        'código vencido',
        $expiredResult['codigo'] ?? '',
        ContactoAccesoService::CODIGO_EXPIRADO
    );

    $invalidated = ContactoAccesoService::solicitarCodigo('telefono', '+52 (55) 8765-4321');
    assertTrueValue('generación OTP teléfono', (bool)($invalidated['ok'] ?? false));
    $db->query(
        "UPDATE verificaciones_contacto SET invalidated_at = NOW()
         WHERE contacto = '+525587654321'"
    );
    $invalidatedResult = ContactoAccesoService::verificarCodigo(
        'telefono',
        '+52 55 8765 4321',
        (string)$invalidated['preview_code']
    );
    assertSameValue(
        'código invalidado',
        $invalidatedResult['codigo'] ?? '',
        ContactoAccesoService::CODIGO_NO_DISPONIBLE
    );

    ReservationClientSession::start();
    $_SESSION['login'] = true;
    $_SESSION['id'] = 99;
    $_SESSION['rol'] = 'admin';
    $sessionBefore = session_id();
    $valid = ContactoAccesoService::solicitarCodigo('email', 'limite.una@example.test');
    $verified = ContactoAccesoService::verificarCodigo(
        'email',
        'limite.una@example.test',
        (string)$valid['preview_code']
    );
    assertTrueValue('código correcto', (bool)($verified['ok'] ?? false));
    assertTrueValue('sesión pública creada', is_array($_SESSION['reservation_client'] ?? null));
    $sessionTtl = (int)$_SESSION['reservation_client']['expires_at'] - time();
    assertTrueValue('sesión pública dura 15 minutos', $sessionTtl >= 895 && $sessionTtl <= 900);
    $_SESSION['reservation_client']['expires_at'] = time() + 30;
    $renewedSession = ReservationClientSession::obtener();
    assertTrueValue(
        'actividad válida renueva 15 minutos',
        is_array($renewedSession)
            && ((int)$renewedSession['expires_at'] - time()) >= 895
    );
    assertTrueValue('ID de sesión regenerado', session_id() !== $sessionBefore);
    assertSameValue('sesión admin coexiste', $_SESSION['rol'] ?? '', 'admin');

    $usedAgain = ContactoAccesoService::verificarCodigo(
        'email',
        'limite.una@example.test',
        (string)$valid['preview_code']
    );
    assertSameValue(
        'código usado no se reutiliza',
        $usedAgain['codigo'] ?? '',
        ContactoAccesoService::CODIGO_NO_DISPONIBLE
    );

    $oneCount = Reservacion::contarActivasPorContacto(
        'email',
        'limite.una@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual()
    );
    assertSameValue('contacto con una activa', $oneCount, 1);

    $five = Reservacion::buscarActivasPorContacto(
        'email',
        'limite.cinco@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual(),
        ReservacionConfig::MAX_ACTIVE_RESERVATIONS
    );
    assertSameValue('contacto con cinco activas', count($five), 5);

    $historyCount = Reservacion::contarActivasPorContacto(
        'email',
        'historial@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual()
    );
    assertSameValue('históricas fuera del límite', $historyCount, 0);

    $noneCount = Reservacion::contarActivasPorContacto(
        'email',
        'limite.cero@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual()
    );
    assertSameValue('contacto sin reservaciones', $noneCount, 0);

    $otherCount = Reservacion::contarActivasPorContacto(
        'email',
        'otro@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual()
    );
    assertSameValue('aislamiento entre contactos', $otherCount, 0);

    $_SESSION['reservation_client']['expires_at'] = time() - 1;
    assertSameValue('expiración de sesión', ReservationClientSession::obtener(), null);

    ReservationClientSession::crear('email', 'limite.una@example.test');
    ReservationClientSession::cerrar();
    assertSameValue('cierre de sesión pública', ReservationClientSession::obtener(false), null);
    assertSameValue('logout conserva admin', $_SESSION['rol'] ?? '', 'admin');

    setPreviewEnvironment('production', true);
    assertSameValue('preview bloqueado en producción', ReservacionConfig::otpPreviewEnabled(), false);
    $production = ContactoAccesoService::solicitarCodigo('email', 'production@example.test');
    assertSameValue('respuesta producción sin preview', array_key_exists('preview_code', $production), false);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: {$tests} comprobaciones de Etapa 1." . PHP_EOL;
