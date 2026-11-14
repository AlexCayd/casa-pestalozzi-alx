<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();

// Configuración local se mantiene en memoria. El arnés crea un esquema cp_pdf_test_*
// propio, comprueba su marcador y lo elimina; nunca migra DB_NAME de la app.
foreach (['TEST_DB_HOST' => 'DB_HOST', 'TEST_DB_PORT' => 'DB_PORT', 'TEST_DB_USER' => 'DB_USER', 'TEST_DB_PASS' => 'DB_PASS'] as $testKey => $appKey) {
    if (getenv($testKey) !== false) continue;
    $value = $_ENV[$appKey] ?? ($testKey === 'TEST_DB_PORT' ? '3306' : null);
    if (is_string($value) && ($value !== '' || $testKey === 'TEST_DB_PASS')) putenv($testKey . '=' . $value);
}

require __DIR__ . '/informes-aceptacion/run.php';
