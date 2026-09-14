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
    $migrationTest = ($argv[1] ?? '') === '--migrations';
    if ($migrationTest) {
        // Baseline explícito y sólo lectura; nunca se usa la BD de la aplicación.
        $legacy = proc_open(['git', 'show', 'f274eda:database/ddl.sql'],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $legacyPipes, $root);
        fclose($legacyPipes[0]);
        $legacySql = stream_get_contents($legacyPipes[1]); fclose($legacyPipes[1]);
        $legacyError = stream_get_contents($legacyPipes[2]); fclose($legacyPipes[2]);
        if (proc_close($legacy) !== 0 || $legacySql === '') throw new RuntimeException('Baseline SQL no disponible.');
        executeNotificationSql($db, $legacySql);
        // Tablas temporales sombrean las reales sólo en esta conexión: prueban
        // traducción de datos históricos sin inventar reservaciones de negocio.
        foreach (['horario_impacto_reservaciones', 'reservacion_recordatorios'] as $table) {
            $db->query("CREATE TEMPORARY TABLE {$table} (id INT PRIMARY KEY, notification_delivery_status ENUM('pending','accepted','delivered','failed') NOT NULL)");
            $db->query("INSERT INTO {$table} VALUES (1,'pending'),(2,'accepted'),(3,'delivered'),(4,'failed')");
        }
        $statesSql = file_get_contents($root . '/database/migrations/20260913_notification_states.sql');
        executeNotificationSql($db, $statesSql);
        foreach (['horario_impacto_reservaciones', 'reservacion_recordatorios'] as $table) {
            $states = array_column($db->query("SELECT notification_delivery_status FROM {$table} ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'notification_delivery_status');
            if ($states !== ['pending','pending','accepted','failed']) throw new RuntimeException('Traducción histórica incorrecta.');
            $db->query("DROP TEMPORARY TABLE {$table}");
        }
        foreach (['20260913_otp_resends.sql','20260913_notification_states.sql','20260913_reminder_recovery.sql'] as $migration) {
            executeNotificationSql($db, file_get_contents($root . '/database/migrations/' . $migration));
        }
        array_splice($argv, 1, 1);
        echo "Migraciones desde f274eda y traducción de estados históricos: PASS\n";
    } else {
        executeNotificationSql($db, file_get_contents($root . '/database/ddl.sql'));
    }
    executeNotificationSql($db, file_get_contents($root . '/database/deploy.sql'));
    $browser = ($argv[1] ?? '') === '--browser';
    if ($browser) {
        $log = tempnam(sys_get_temp_dir(), 'cp-notifications-http-');
        $env = array_merge(getenv(), ['CP_NOTIFICATION_TEST_DATABASE' => $name]);
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
    ]);
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
