<?php

declare(strict_types=1);

/*
 * Contrato de Services\Security\JwtIntegracion y de la guardia de
 * /api/integraciones/* (API.md §9, fases 1 y 2).
 *
 * Lo que protege: que sólo pase un HS256 de n8n, firmado con la llave de
 * entrada, para ESA audiencia, vigente y de vida corta; que no se acepte
 * ningún header heredado; y que toda ruta bajo /api/integraciones/ exija JWT
 * aunque nadie la haya anotado.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Services\Security\JwtIntegracion;
use Services\Security\Permisos;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$leer = static fn (string $ruta): string => (string) file_get_contents($root . '/' . $ruta);

$llave = str_repeat('k', 48);
$aud = 'reservaciones.recordatorios.preparar';
$ahora = time();
$claims = static fn (array $cambios = []): array => array_merge([
    'iss' => 'n8n',
    'aud' => $aud,
    'iat' => $ahora,
    'exp' => $ahora + 120,
    'jti' => 'fixture-jti',
], $cambios);
$firmar = static fn (array $payload, string $alg = 'HS256', ?string $con = null): string =>
    JWT::encode($payload, $con ?? $llave, $alg);
$verifica = static fn (string $token, ?string $audiencia = null): array =>
    JwtIntegracion::verificar($token, $audiencia ?? $aud, $llave);
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

// 1 · Verificación.
$valido = $firmar($claims());
$assert($verifica($valido)['ok'] === true, 'Un token válido pasa.');
$assert($verifica($valido)['claims']['jti'] === 'fixture-jti', 'Devuelve los claims.');

$assert($verifica($firmar($claims(['iat' => $ahora - 200, 'exp' => $ahora - 60])))['ok'] === false, 'Un token caducado no pasa.');
$assert($verifica($firmar($claims(['iat' => $ahora - 20, 'exp' => $ahora - 10])))['ok'] === true, 'Dentro del desfase de 30 s, sigue valiendo.');
$assert($verifica($firmar($claims(['nbf' => $ahora + 120])))['ok'] === false, 'Un nbf futuro no pasa.');
$assert($verifica($valido, 'reservaciones.notificacion-resultado')['ok'] === false, 'Un token de otra audiencia no pasa.');
$assert($verifica($firmar($claims(['aud' => [$aud, 'otra']])))['ok'] === false, 'Una lista de audiencias no pasa.');
$assert($verifica($firmar($claims(['iss' => 'casa-pestalozzi'])))['ok'] === false, 'Un token propio (de salida) no entra.');
$assert($verifica($firmar($claims(), 'HS256', str_repeat('x', 48)))['ok'] === false, 'Otra llave no pasa.');

[$cabeza, $cuerpo, $firma] = explode('.', $valido);
$bytes = base64_decode(strtr($firma, '-_', '+/') . str_repeat('=', (4 - strlen($firma) % 4) % 4));
$bytes[0] = chr(ord($bytes[0]) ^ 1);
$assert($verifica("{$cabeza}.{$cuerpo}." . $b64($bytes))['ok'] === false, 'Una firma alterada en un byte no pasa.');

$none = $b64('{"alg":"none","typ":"JWT"}') . '.' . $b64((string) json_encode($claims())) . '.';
$assert($verifica($none)['ok'] === false, 'alg: none no pasa.');
$assert($verifica($firmar($claims(), 'HS512', str_repeat('k', 64)))['ok'] === false, 'alg: HS512 no pasa.');
$assert($verifica($firmar($claims(['exp' => $ahora + 301])))['ok'] === false, 'Una vida mayor de 300 s no pasa.');
$sinJti = $claims();
unset($sinJti['jti']);
$assert($verifica($firmar($sinJti))['ok'] === false, 'Sin jti no pasa.');
foreach (['iss', 'aud', 'iat', 'exp'] as $claim) {
    $sin = $claims();
    unset($sin[$claim]);
    $assert($verifica($firmar($sin))['ok'] === false, "Sin {$claim} no pasa.");
}
$assert($verifica('no.es.un-jwt')['ok'] === false, 'Basura con forma de JWT no pasa.');
$assert(!str_contains($verifica($firmar($claims(['exp' => $ahora - 60])))['motivo'], $llave), 'El motivo no filtra la llave.');

// 2 · El header.
$assert(JwtIntegracion::tokenDeLaPeticion([]) === null, 'Sin header no hay token.');
$assert(JwtIntegracion::tokenDeLaPeticion(['HTTP_AUTHORIZATION' => 'Bearer ' . $valido]) === $valido, 'Lee el Bearer.');
$assert(JwtIntegracion::tokenDeLaPeticion(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $valido]) === $valido, 'Lee el header que renombra Apache.');
foreach (['Bearer', 'Bearer ', 'Basic ' . base64_encode('a:b'), 'bearer ' . $valido, 'Bearer a.b', 'Bearer ' . $valido . ' extra', $valido] as $malo) {
    $assert(JwtIntegracion::tokenDeLaPeticion(['HTTP_AUTHORIZATION' => $malo]) === null, "Un Bearer mal formado no cuenta: «{$malo}».");
}
$assert(JwtIntegracion::tokenDeLaPeticion(['HTTP_X_N8N_CALLBACK_SECRET' => 'heredado', 'HTTP_X_N8N_SECRET' => 'heredado']) === null, 'Los headers heredados no cuentan como token.');

// 3 · Firma saliente: la verifica quien tenga la llave, sólo para su audiencia.
$saliente = JwtIntegracion::firmar('n8n.areas-mejora', 120, $llave);
$decodificado = (array) JWT::decode($saliente, new \Firebase\JWT\Key($llave, 'HS256'));
$assert($decodificado['iss'] === 'casa-pestalozzi' && $decodificado['aud'] === 'n8n.areas-mejora', 'La firma saliente lleva emisor y audiencia.');
$assert($decodificado['exp'] - $decodificado['iat'] === 120 && strlen((string) $decodificado['jti']) === 32, 'La firma saliente caduca a los 120 s y lleva jti.');
$assert(JwtIntegracion::verificar($saliente, 'n8n.areas-mejora', $llave)['ok'] === false, 'Un token de salida no sirve de entrada.');

// 4 · Sin llave, falla a la vista; con una corta, también.
$anterior = $_ENV['JWT_N8N_ENTRADA_SECRET'] ?? null;
putenv('JWT_N8N_ENTRADA_SECRET');
foreach (['' => 'no está configurado', 'corta' => 'al menos 32 bytes'] as $valor => $mensaje) {
    $_ENV['JWT_N8N_ENTRADA_SECRET'] = $valor;
    try {
        JwtIntegracion::verificar($valido, $aud);
        $assert(false, 'Sin llave utilizable, verificar() tenía que lanzar.');
    } catch (\RuntimeException $e) {
        $assert(str_contains($e->getMessage(), $mensaje), "Llave «{$valor}»: {$mensaje}.");
    }
}
$_ENV['JWT_N8N_ENTRADA_SECRET'] = $llave;
$assert(JwtIntegracion::verificar($valido, $aud)['ok'] === true, 'Con la llave del entorno, verifica.');
if ($anterior === null) unset($_ENV['JWT_N8N_ENTRADA_SECRET']); else $_ENV['JWT_N8N_ENTRADA_SECRET'] = $anterior;

// 5 · La guardia: todo /api/integraciones/* es integración y niega por defecto.
$index = $leer('public/index.php');
preg_match_all("/\\\$router->(?:get|post|delete)\\('(\\/api\\/integraciones\\/[^']*)'/", $index, $rutas);
$assert(count(array_unique($rutas[1])) === 4, 'Hay cuatro integraciones registradas.');
$audiencias = [];
foreach (array_unique($rutas[1]) as $ruta) {
    $ruta = (string) $ruta;
    $clase = Permisos::clasificar($ruta);
    $assert($clase['tipo'] === 'integracion' && is_string($clase['audiencia']), "{$ruta} tiene audiencia en Permisos::INTEGRACIONES.");
    $audiencias[] = $clase['audiencia'];
}
$assert(count(array_unique($audiencias)) === count($audiencias), 'Cada integración tiene su propia audiencia.');
$inventada = Permisos::clasificar('/api/integraciones/n8n/inventada');
$assert($inventada['tipo'] === 'integracion' && $inventada['audiencia'] === null, 'Una ruta inventada bajo el prefijo es integración sin audiencia.');
$assert(Permisos::clasificar('/api/integraciones')['tipo'] === 'integracion', 'El prefijo exacto también.');
$assert(Permisos::clasificar('/api/integracionesx')['tipo'] === 'publica', 'Casa por segmento, no por texto.');
$assert(!str_contains($index, "'/api/feedback-n8n'"), 'La ruta heredada de áreas de mejora ya no existe.');
foreach ([null, ['sistema' => true, 'permisos' => []]] as $rol) {
    $assert(Permisos::decidir('/api/integraciones/n8n/feedback/areas-mejora', 'POST', $rol)['resultado'] === 'negar', 'Una sesión no abre una integración, ni la de sistema.');
}

// Ni los controladores ni la configuración conservan el secreto heredado.
foreach (['controllers/N8nReservationsController.php', 'controllers/FeedbackController.php', 'services/Notifications/NotificationConfig.php'] as $archivo) {
    $codigo = $leer($archivo);
    $assert(!preg_match('/HTTP_X_N8N_(CALLBACK_)?SECRET|n8nCallbackSecret|N8N_RESERVATIONS_CALLBACK_SECRET/', $codigo), "{$archivo} no acepta el secreto heredado.");
}
$auth = $leer('classes/Auth.php');
$assert(str_contains($auth, 'JwtIntegracion::verificar') && str_contains($auth, "'integracion'"), 'Auth::proteger() verifica el JWT de las integraciones.');
$assert(str_contains($leer('public/.htaccess'), 'HTTP_AUTHORIZATION'), '.htaccess pasa el header Authorization a PHP.');

fwrite(STDOUT, "JWT de integraciones: {$assertions} comprobaciones OK\n");
