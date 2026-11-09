<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/sql-loader.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$name = 'cp_notifications_test_' . bin2hex(random_bytes(6));
$testEncryptionKey = random_bytes(32);
do { $testLookupKey = random_bytes(32); } while (hash_equals($testEncryptionKey, $testLookupKey));
$testContactKeys = [
    'CP_TEST_CONTACT_ENCRYPTION_KEY' => base64_encode($testEncryptionKey),
    'CP_TEST_CONTACT_LOOKUP_KEY' => base64_encode($testLookupKey),
];
$created = false;
$failed = false;

try {
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($name);
    if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== $name) throw new RuntimeException('Base aislada no seleccionada.');
    executeSqlFile($db, file_get_contents($root . '/database/ddl.sql'));
    executeSqlFile($db, file_get_contents($root . '/database/deploy.sql'));
    $browser = ($argv[1] ?? '') === '--browser';
    if ($browser) {
        $log = tempnam(sys_get_temp_dir(), 'cp-notifications-http-');
        $env = array_merge(getenv(), ['CP_NOTIFICATION_TEST_DATABASE' => $name], $testContactKeys);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8087', '-t', $root . '/public', __DIR__ . '/notifications-http-router.php'],
            [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, $root, $env);
        fclose($pipes[0]);
        echo "Vista de prueba aislada: http://127.0.0.1:8087 — Enter para cerrar y limpiar.\n";
        try { fgets(STDIN); } finally { proc_terminate($server); proc_close($server); unlink($log); }
    }
    $suites = $browser ? [] : (array_slice($argv, 1) ?: [
        'run-reservaciones-otp-resends-db.php',
        'run-reservaciones-hold-notifications-db.php',
        'run-reservaciones-reminder-recovery-db.php',
        'run-reservaciones-comunicaciones-db.php',
        'run-reservaciones-notificaciones-development-db.php',
        'run-reservaciones-cambio-horario-matrix-db.php',
        'run-reservaciones-map-state-integration-db.php',
    ]);
    foreach ($suites as $suite) {
        $suiteEsValida = preg_match('/^run-reservaciones-[a-z-]+\.php$/D', $suite)
            || in_array($suite, ['run-pos-print-alerts-db.php', 'run-pos-llevar-multiples-db.php', 'run-punto-venta-cierre-db.php'], true);
        if (!$suiteEsValida || !is_file(__DIR__ . '/' . $suite)) {
            throw new RuntimeException('Suite no disponible: ' . basename($suite));
        }
        $env = array_merge(getenv(), ['CP_NOTIFICATION_TEST_DATABASE' => $name], $testContactKeys);
        $outFile = tempnam(sys_get_temp_dir(), 'cp-notifications-out-');
        $errFile = tempnam(sys_get_temp_dir(), 'cp-notifications-err-');
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/notifications-test-bootstrap.php', __DIR__ . '/' . $suite],
            [0 => ['pipe','r'], 1 => ['file',$outFile,'w'], 2 => ['file',$errFile,'w']], $pipes, $root, $env);
        fclose($pipes[0]);
        // Windows no garantiza pipes no bloqueantes: usar archivos temporales.
        $code = proc_close($process);
        $stdout = file_get_contents($outFile); $stderr = file_get_contents($errFile);
        unlink($outFile); unlink($errFile);
        echo $suite . ': ' . ($code === 0 ? 'PASS' : 'FAIL') . "\n";
        if ($code !== 0) { $failed = true; echo $stdout . $stderr; }
    }
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, 'Runner aislado: ' . $e->getMessage() . "\n");
} finally {
    if ($created && preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $name)) {
        $db->query("DROP DATABASE `{$name}`");
        echo "Base temporal de fixtures eliminada; base de la aplicación intacta.\n";
    }
}
exit($failed ? 1 : 0);
