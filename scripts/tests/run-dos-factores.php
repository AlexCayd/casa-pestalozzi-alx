<?php

declare(strict_types=1);

/*
 * Contrato de Services\Security\DosFactores y de su cableado: que la
 * contraseña de un rol de sistema no abra sesión, que el código sea de un
 * solo reto, con vigencia, intentos y reenvíos acotados, que nunca se guarde
 * en claro fuera de pruebas y que el envío a n8n lleve lo que el correo
 * necesita. Sin base de datos.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\Usuario;
use Services\Integrations\N8nClient;
use Services\Security\DosFactores;
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
$entorno = static function (string $valor): void {
    $_ENV['APP_ENV'] = $valor;
    putenv('APP_ENV=' . $valor);
};

// ── A quién aplica ──────────────────────────────────────────
$assert(DosFactores::aplica(Permisos::SUPER_ADMIN) && DosFactores::aplica(Permisos::ADMIN), 'aplica a los roles de sistema');
$assert(!DosFactores::aplica(Permisos::MESERO) && !DosFactores::aplica(Permisos::COCINERO) && !DosFactores::aplica(''), 'no aplica a roles de piso');

// ── comprobar(): la decisión pura ───────────────────────────
$ahora = 1_800_000_000;
$reto = ['id' => 7, 'hash' => hash('sha256', '012345'), 'expira' => $ahora + 60, 'intentos' => 0];
$assert(DosFactores::comprobar($reto, '012345', $ahora) === 'ok', 'código correcto (con cero a la izquierda)');
$assert(DosFactores::comprobar($reto, '012346', $ahora) === 'incorrecto', 'código equivocado');
foreach (['', '12345', '1234567', 'abcdef', '01234 5', '12345a'] as $malo) {
    $assert(DosFactores::comprobar($reto, $malo, $ahora) === 'formato', "formato rechazado: '{$malo}'");
}
$assert(DosFactores::comprobar($reto, '012345', $ahora + 61) === 'vencido', 'el correcto ya vencido no pasa');
$assert(DosFactores::comprobar($reto + ['intentos' => 0], '012345', $ahora + 60) === 'ok', 'vale hasta el segundo exacto de vencer');
$assert(DosFactores::comprobar(['intentos' => DosFactores::INTENTOS] + $reto, '012345', $ahora) === 'agotado', 'sin intentos ni el correcto pasa');
$assert(DosFactores::comprobar(['id' => 7], '000000', $ahora) !== 'ok', 'un reto sin hash nunca pasa');

// ── esperaReenvio() ─────────────────────────────────────────
$assert(DosFactores::esperaReenvio(['enviado' => $ahora, 'envios' => 1], $ahora) === DosFactores::ESPERA_REENVIO, 'espera completa tras enviar');
$assert(DosFactores::esperaReenvio(['enviado' => $ahora - DosFactores::ESPERA_REENVIO, 'envios' => 1], $ahora) === 0, 'reenvío libre al cumplir la espera');
$assert(DosFactores::esperaReenvio(['enviado' => 0, 'envios' => DosFactores::ENVIOS], $ahora) === -1, 'tope de envíos por reto');

// ── mostrarCodigo(): nunca con APP_ENV=production ───────────
// Vacío también enseña: los .env de hoy no declaran APP_ENV.
foreach (['development' => true, 'test' => true, '' => true, 'production' => false, ' Production ' => false] as $valor => $esperado) {
    $entorno($valor);
    $assert(DosFactores::mostrarCodigo() === $esperado, "mostrarCodigo con APP_ENV={$valor}");
}

// ── enviar(): contrato con n8n ──────────────────────────────
$capturas = [];
$cliente = static function (int $status, array $cuerpo) use (&$capturas): N8nClient {
    return new N8nClient('http://n8n.test', 'fixture-secret', static function (string $url, string $secreto, string $json) use (&$capturas, $status, $cuerpo): array {
        $capturas[] = ['url' => $url, 'secreto' => $secreto, 'payload' => json_decode($json, true)];
        return ['status' => $status, 'body' => json_encode($cuerpo), 'error' => ''];
    });
};
$retoEnvio = ['id' => 7, 'nombre' => 'Mariana López', 'email' => 'mariana@casa.test'];

$assert(DosFactores::enviar(['id' => 7, 'email' => ''], '123456', $cliente(202, ['ok' => true, 'accepted' => true]))['codigo'] === 'SIN_CORREO', 'sin correo no se llama a n8n');
$assert($capturas === [], 'sin correo, ninguna petición');

$envio = DosFactores::enviar($retoEnvio, '123456', $cliente(202, ['ok' => true, 'accepted' => true]));
$assert($envio['ok'] === true, 'n8n acepta con 202 + ok/accepted');
$assert($capturas[0]['url'] === 'http://n8n.test' . DosFactores::WEBHOOK_PATH, 'webhook del código de acceso');
$payload = $capturas[0]['payload'];
$assert($payload['email'] === 'mariana@casa.test' && $payload['codigo'] === '123456' && $payload['nombre'] === 'Mariana López', 'el payload lleva destinatario y código');
$assert($payload['vigencia_minutos'] === intdiv(DosFactores::VIGENCIA, 60) && $payload['tipo'] === 'admin_codigo_acceso', 'el payload dice la vigencia y el tipo');

$assert(DosFactores::enviar($retoEnvio, '123456', $cliente(500, ['ok' => false]))['ok'] === false, 'un rechazo de n8n no cuenta como enviado');
$assert(DosFactores::enviar($retoEnvio, '123456', $cliente(200, ['ok' => true, 'accepted' => true]))['ok'] === false, 'sólo 202 cuenta como aceptado');

$entorno('development');
$assert(DosFactores::enviar($retoEnvio, '123456')['codigo'] === 'NOTIFICACION_PREPARADA_DESARROLLO', 'development no transporta');

// ── El reto en la sesión ────────────────────────────────────
$_ENV['SESSION_SAVE_PATH'] = sys_get_temp_dir();
$usuario = new Usuario();
$usuario->id = 7;
$usuario->nombre = 'Mariana López';
$usuario->email = 'mariana@casa.test';
$usuario->rol = Permisos::SUPER_ADMIN;

DosFactores::iniciar($usuario);
$sesion = DosFactores::reto();
$assert($sesion !== null && $sesion['id'] === 7 && $sesion['envios'] === 1, 'iniciar abre el reto con el primer envío');
$assert(preg_match('/^\d{6}$/', (string) $sesion['visible']) === 1, 'en development el código queda para la pantalla');
$assert($sesion['hash'] === hash('sha256', $sesion['visible']), 'se guarda su sha256');
$assert(DosFactores::correoVisible($sesion) === 'ma•••••@casa.test', 'el correo se enseña enmascarado');
$codigo = (string) $sesion['visible'];
$otro = $codigo === '999999' ? '000000' : '999999';

for ($i = 1; $i < DosFactores::INTENTOS; $i++) {
    $r = DosFactores::verificar($otro);
    $assert($r['resultado'] === 'incorrecto' && $r['restantes'] === DosFactores::INTENTOS - $i, "fallo {$i} descuenta un intento");
}
$assert(DosFactores::verificar('12')['resultado'] === 'formato' && DosFactores::reto()['intentos'] === DosFactores::INTENTOS - 1, 'un formato inválido no gasta intento');
$r = DosFactores::verificar(" {$codigo} ");
$assert($r === ['resultado' => 'ok', 'id' => 7], 'el código correcto (con espacios) da el id');
$assert(DosFactores::reto() === null, 'y cierra el reto: no se reutiliza');
$assert(DosFactores::verificar($codigo)['resultado'] === 'sin_reto', 'el mismo código ya no sirve');

DosFactores::iniciar($usuario);
for ($i = 0; $i < DosFactores::INTENTOS - 1; $i++) {
    DosFactores::verificar('999999' === DosFactores::reto()['visible'] ? '000000' : '999999');
}
$ultimo = DosFactores::verificar('999999' === DosFactores::reto()['visible'] ? '000000' : '999999');
$assert($ultimo['resultado'] === 'agotado' && DosFactores::reto() === null, 'el quinto fallo cierra el reto');

DosFactores::iniciar($usuario);
$viejo = (string) DosFactores::reto()['visible'];
DosFactores::emitir();
$nuevo = DosFactores::reto();
$assert($nuevo['envios'] === 2 && $nuevo['intentos'] === 0, 'reenviar cuenta el envío y repone los intentos');
if ($viejo !== $nuevo['visible']) {
    $assert(DosFactores::verificar($viejo)['resultado'] === 'incorrecto', 'el código anterior deja de servir');
}
$_SESSION['_dos_factores']['expira'] = time() - 1;
$assert(DosFactores::verificar((string) $nuevo['visible'])['resultado'] === 'vencido', 'vencido no entra');
$assert(DosFactores::reto() !== null, 'vencido no cierra el reto: se puede pedir otro');

$entorno('production');
DosFactores::iniciar($usuario);
// El envío real falla (no hay n8n): lo que importa es qué queda en la sesión.
$enProduccion = DosFactores::reto();
$assert($enProduccion['visible'] === null, 'en production el código no se guarda en claro');
$assert($enProduccion['entrega'] !== 'NOTIFICACION_ACEPTADA', 'el fallo de entrega queda para la pantalla');
DosFactores::cancelar();

$sinCorreo = clone $usuario;
$sinCorreo->email = null;
DosFactores::iniciar($sinCorreo);
$assert(DosFactores::reto()['entrega'] === 'SIN_CORREO', 'una cuenta sin correo lo dice');
DosFactores::cancelar();
$entorno('development');

// ── Cableado ────────────────────────────────────────────────
$index = $leer('public/index.php');
foreach (["get('/login/verificacion'", "post('/login/verificacion'", "post('/login/verificacion/reenviar'"] as $ruta) {
    $assert(str_contains($index, $ruta), "ruta registrada: {$ruta}");
}
$assert(Permisos::clasificar('/login/verificacion')['tipo'] === 'publica', 'la verificación es pública (aún no hay sesión)');

$controlador = $leer('controllers/AuthController.php');
$loginAdmin = substr($controlador, (int) strpos($controlador, 'function loginAdmin'), 2500);
$assert(strpos($loginAdmin, 'DosFactores::aplica') < strpos($loginAdmin, 'Auth::login'), 'la contraseña de sistema abre el reto antes que cualquier sesión');

$vista = $leer('views/auth/dos-factores.php');
$assert(str_contains($vista, 'autocomplete="one-time-code"') && str_contains($vista, 'inputmode="numeric"'), 'el campo admite autocompletar del código');
$assert(str_contains($vista, '$codigoVisible !== null'), 'el recuadro de pruebas depende de mostrarCodigo()');
$assert(str_contains($leer('views/auth/_salida-marca.php'), 'login(\/verificacion)?'), 'la marca no viaja entre contraseña y código');
$assert(preg_match('/^\s*email\s+VARCHAR\(160\) NULL,/m', $leer('database/ddl.sql')) === 1, 'usuarios.email en el esquema');

echo "Dos factores: {$assertions} comprobaciones OK\n";
