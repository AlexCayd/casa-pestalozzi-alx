<?php
declare(strict_types=1);

/** Router local del harness 3B; nunca despacha archivos PHP solicitados por URL. */
$root = dirname(__DIR__, 2);
$public = realpath($root . '/public');
$requested = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requested = is_string($requested) && $requested !== '' ? $requested : '/';
$normalized = $requested === '/index.php' ? '/' : $requested;

// Solo se entregan archivos estáticos existentes y contenidos en public/.
$extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
$staticExtensions = ['css','js','png','jpg','jpeg','gif','webp','svg','ico','woff','woff2','ttf','otf','eot','map','webmanifest','txt'];
$candidate = $public ? realpath($public . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR)) : false;
if ($public && $candidate && is_file($candidate)
    && str_starts_with(strtolower($candidate), strtolower(rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
    && in_array($extension, $staticExtensions, true)) {
    return false;
}
if (in_array($extension, ['php','phtml','phar'], true) && $normalized !== '/') {
    http_response_code(403);
    echo 'Acceso denegado.';
    return true;
}

$required = [
    'DB_HOST' => 'CP_3B_DB_HOST',
    'DB_USER' => 'CP_3B_DB_USER',
    'DB_NAME' => 'CP_3B_TEST_DB',
    'NIP_LOOKUP_SECRET' => 'CP_3B_NIP_SECRET',
    'SESSION_SAVE_PATH' => 'CP_3B_SESSION_PATH',
    'APP_ENV' => 'CP_3B_APP_ENV',
];
$values = [];
foreach ($required as $target => $source) {
    $value = getenv($source);
    if (!is_string($value) || $value === '') {
        http_response_code(503);
        echo 'Harness temporal no configurado.';
        return true;
    }
    $values[$target] = $value;
}
$dbPass = getenv('CP_3B_DB_PASS');
// Windows puede devolver false cuando el proceso recibió una variable vacía.
// La conexión sigue limitada a la base efímera validada abajo.
if ($dbPass === false) $dbPass = '';
$ownerToken = getenv('CP_3B_OWNER_TOKEN');
$originalBase = getenv('CP_3B_BASE_ORIGEN');
$dbPort = (int)(getenv('CP_3B_DB_PORT') ?: 3306);
if (!is_string($dbPass) || !is_string($ownerToken) || !preg_match('/^[a-f0-9]{64}$/D', $ownerToken)
    || !is_string($originalBase) || $originalBase === ''
    || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $values['DB_NAME'])
    || $values['DB_NAME'] === $originalBase || $dbPort < 1 || $dbPort > 65535) {
    http_response_code(503);
    echo 'Configuración temporal inválida.';
    return true;
}

// La única conexión de este router va a la base efímera y valida su marca
// antes de incluir la aplicación. Nunca consulta la base configurada del sitio.
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $check = new mysqli($values['DB_HOST'], (string)getenv('CP_3B_DB_USER'), $dbPass, $values['DB_NAME'], $dbPort);
    $check->set_charset('utf8mb4');
    $rows = $check->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
    $check->close();
    if (count($rows) !== 1 || !hash_equals($ownerToken, (string)$rows[0][0])) throw new RuntimeException('La marca de propiedad no coincide.');
} catch (Throwable) {
    http_response_code(503);
    echo 'No se verificó la base temporal.';
    return true;
}

$values['DB_USER'] = (string)getenv('CP_3B_DB_USER');
$values['DB_PASS'] = $dbPass; // Una contraseña vacía configurada es válida.
foreach ($values as $key => $value) $_ENV[$key] = $value;
$host = $values['DB_HOST'];
if ($dbPort !== 3306 && !preg_match('/:\\d+$/', $host)) $_ENV['DB_HOST'] = $host . ':' . $dbPort;

// Las llamadas salientes van a un puerto local cerrado con secretos sintéticos.
$externalUrl = getenv('CP_3B_EXTERNAL_URL');
$externalSecret = getenv('CP_3B_EXTERNAL_SECRET');
if (!is_string($externalUrl) || !str_starts_with($externalUrl, 'http://127.0.0.1:9')
    || !is_string($externalSecret) || !preg_match('/^[a-f0-9]{48}$/D', $externalSecret)) {
    http_response_code(503);
    echo 'No se fijaron integraciones sintéticas.';
    return true;
}
foreach ([
    'N8N_BASE_URL' => $externalUrl,
    'N8N_WEBHOOK_SUGERENCIAS_URL' => $externalUrl . '/sugerencias',
    'N8N_WEBHOOK_AREAS_MEJORA_URL' => $externalUrl . '/areas',
    'IA_N8N_WEBHOOK_URL' => $externalUrl . '/ia',
    'N8N_SECRET' => $externalSecret,
    'N8N_RESERVATIONS_WEBHOOK_SECRET' => $externalSecret,
    'N8N_RESERVATIONS_CALLBACK_SECRET' => bin2hex(random_bytes(24)),
] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

putenv('NIP_LOOKUP_SECRET=' . $values['NIP_LOOKUP_SECRET']);
putenv('SESSION_SAVE_PATH=' . $values['SESSION_SAVE_PATH']);
putenv('APP_ENV=' . $values['APP_ENV']);

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = $normalized;
$_SERVER['DOCUMENT_ROOT'] = $public ?: $root . '/public';
require $root . '/public/index.php';
