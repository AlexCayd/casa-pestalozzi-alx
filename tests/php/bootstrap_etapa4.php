<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;

$targetDatabase = getenv('ETAPA4_TEST_DB_NAME') ?: '';
$allowActiveDatabase = getenv('ETAPA45_ALLOW_ACTIVE') === 'YES';
if ($targetDatabase !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $targetDatabase)) {
        throw new RuntimeException('El nombre de la base de prueba no es válido.');
    }
    if ($targetDatabase === 'casa-pestalozzi' && !$allowActiveDatabase) {
        throw new RuntimeException('Etapa 4 no permite apuntar a la base activa.');
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes');
$dotenv->safeLoad();

if ($targetDatabase !== '') {
    $_ENV['DB_NAME'] = $targetDatabase;
    $_SERVER['DB_NAME'] = $targetDatabase;
    putenv('DB_NAME=' . $targetDatabase);
}

date_default_timezone_set('America/Mexico_City');

require dirname(__DIR__, 2) . '/includes/funciones.php';
require dirname(__DIR__, 2) . '/includes/database.php';

ActiveRecord::setDB($db);
