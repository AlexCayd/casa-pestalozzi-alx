<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$name = 'cp_notifications_test_' . bin2hex(random_bytes(6));
$created = false;
$failed = false;

function executeNotificationSql(mysqli $db, string $sql): void {
    $delimiter = ';'; $statement = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $m)) { $delimiter = $m[1]; continue; }
        if (preg_match('/^\s*--/', $line) || trim($line) === '') continue;
        $line = preg_replace('/;\s*--.*$/', ';', $line);
        $statement .= $line . "\n";
        if (str_ends_with(rtrim($statement), $delimiter)) {
            $db->query(substr(rtrim($statement), 0, -strlen($delimiter)));
            $statement = '';
        }
    }
    if (trim($statement) !== '') throw new RuntimeException('SQL aislado incompleto.');
}

try {
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($name);
    if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== $name) throw new RuntimeException('Base aislada no seleccionada.');
    executeNotificationSql($db, file_get_contents($root . '/database/ddl.sql'));
    executeNotificationSql($db, file_get_contents($root . '/database/deploy.sql'));
    $suites = array_slice($argv, 1) ?: [
        'run-reservaciones-otp-resends-db.php',
        'run-reservaciones-reminder-recovery-db.php',
        'run-reservaciones-comunicaciones-db.php',
        'run-reservaciones-notificaciones-development-db.php',
        'run-reservaciones-cambio-horario-matrix-db.php',
    ];
    foreach ($suites as $suite) {
        if (!preg_match('/^run-reservaciones-[a-z-]+\.php$/D', $suite) || !is_file(__DIR__ . '/' . $suite)) {
            throw new RuntimeException('Suite no disponible: ' . basename($suite));
        }
        $env = array_merge(getenv(), ['CP_NOTIFICATION_TEST_DATABASE' => $name]);
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
