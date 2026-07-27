<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Services\ReservacionPublicaService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');

[$script, $databaseName, $payloadEncoded, $readyPath, $goPath, $resultPath] = $argv;
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    $databaseName
);
if (!$db) {
    exit(2);
}
$db->query("SET time_zone = '-06:00'");
$testNow = getenv('RESERVATION_TEST_NOW');
if (is_string($testNow) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $testNow) === 1) {
    $db->query("SET timestamp = UNIX_TIMESTAMP('" . $db->real_escape_string($testNow) . "')");
}
ActiveRecord::setDB($db);
$payload = json_decode((string)base64_decode($payloadEncoded, true), true);
if (!is_array($payload)) {
    exit(3);
}

file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($goPath) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($goPath)) {
    exit(4);
}

$operacion = (string)($payload['_operation'] ?? 'crear');
$sesion = [
    'contacto_tipo' => (string)($payload['tipo_contacto'] ?? ''),
    'contacto' => (string)($payload['contacto'] ?? ''),
];
unset($payload['_operation']);

// El mismo worker permite carreras heterogéneas sobre conexiones reales. La
// barrera se abre una sola vez para evitar que una prueba sea secuencial por
// accidente.
$resultado = match ($operacion) {
    'confirmar' => ReservacionPublicaService::confirmarRetencion($payload),
    'expirar' => ReservacionPublicaService::expirarRetenciones(
        (int)($payload['limite'] ?? 100),
        false
    ),
    'modificar' => ReservacionPublicaService::modificar($payload, $sesion),
    'cancelar' => ReservacionPublicaService::cancelar(
        (int)($payload['reservacion_id'] ?? 0),
        $sesion
    ),
    default => ReservacionPublicaService::crearConfirmada($payload, $sesion),
};
file_put_contents(
    $resultPath,
    json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
$db->close();
