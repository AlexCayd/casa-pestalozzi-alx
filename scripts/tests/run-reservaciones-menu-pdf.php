<?php

declare(strict_types=1);

$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para generar los PDFs.\n");
    exit(1);
}

$program = <<<'PHP'
$root = getcwd();
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida.\n");
    exit(1);
}
$_ENV['DB_NAME'] = $database;
$_ENV['APP_ENV'] = 'test';
require $root . '/includes/app.php';
\Services\Menu\MenuPdf::stream((string)getenv('CP_MENU_PDF_CARTA'));
PHP;

$environment = getenv();
$passed = true;
foreach (['comida', 'maridaje'] as $carta) {
    $environment['CP_NOTIFICATION_TEST_DATABASE'] = $database;
    $environment['CP_MENU_PDF_CARTA'] = $carta;
    $outputPath = tempnam(sys_get_temp_dir(), 'cp-menu-pdf-');
    $errorPath = tempnam(sys_get_temp_dir(), 'cp-menu-pdf-error-');
    $process = proc_open(
        [PHP_BINARY, '-r', $program],
        [0 => ['pipe', 'r'], 1 => ['file', $outputPath, 'w'], 2 => ['file', $errorPath, 'w']],
        $pipes,
        dirname(__DIR__, 2),
        $environment
    );
    if (!is_resource($process)) {
        @unlink($outputPath);
        @unlink($errorPath);
        fwrite(STDERR, "No se pudo iniciar el proceso PDF de {$carta}.\n");
        exit(1);
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $pdf = file_get_contents($outputPath);
    $errors = trim((string)file_get_contents($errorPath));
    @unlink($outputPath);
    @unlink($errorPath);

    $valid = $exitCode === 0
        && is_string($pdf)
        && strlen($pdf) > 1000
        && str_starts_with($pdf, '%PDF-')
        && str_contains($pdf, '%%EOF');
    if (!$valid) {
        $passed = false;
        fwrite(STDERR, "FAIL: PDF de {$carta} (exit={$exitCode}, bytes=" . (is_string($pdf) ? strlen($pdf) : 0) . "). {$errors}\n");
    }
}

if (!$passed) {
    exit(1);
}

fwrite(STDOUT, "PASS: PDFs de comida y maridaje renderizados en fixture aislado.\n");
