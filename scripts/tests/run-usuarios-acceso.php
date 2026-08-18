<?php

/** Contratos rápidos del acceso de usuarios, sin depender de una BD local. */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Services\NipService;

function comprobar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

$_ENV['NIP_LOOKUP_SECRET'] = 'secret-only-for-tests';
putenv('NIP_LOOKUP_SECRET=secret-only-for-tests');

$candidatos = ['1234', '1234', '5678'];
NipService::usarGenerador(static function () use (&$candidatos): string {
    return array_shift($candidatos) ?? '9999';
});

$ocupados = [NipService::lookup('1234') => true];
$credencial = NipService::generarCredencialDisponible(
    static fn (string $lookup): bool => isset($ocupados[$lookup])
);

comprobar($credencial['nip'] === '5678', 'La colisión controlada no reintentó el candidato.');
comprobar((bool) preg_match('/^\d{4}$/', $credencial['nip']), 'El NIP no conserva cuatro dígitos.');
comprobar(password_verify('5678', $credencial['hash']), 'El hash de prueba no verifica el NIP.');
comprobar(
    hash_equals(
        hash_hmac('sha256', '5678', 'secret-only-for-tests'),
        $credencial['lookup']
    ),
    'El lookup no es el HMAC esperado.'
);

$ddl = file_get_contents($root . '/database/ddl.sql');
$modelo = file_get_contents($root . '/models/Usuario.php');
$formulario = file_get_contents($root . '/views/admin/users/form.php');
$javascript = file_get_contents($root . '/src/js/admin/users/users-form.js');
$servicio = file_get_contents($root . '/services/UsuarioService.php');
$login = file_get_contents($root . '/models/Usuario.php');
$controlador = file_get_contents($root . '/controllers/AdminUsersController.php');
$edicion = file_get_contents($root . '/views/admin/users/edit.php');

comprobar(str_contains($ddl, 'nip_lookup'), 'El DDL no define nip_lookup.');
comprobar(str_contains($ddl, 'uq_usuarios_nip_lookup'), 'Falta la restricción UNIQUE de nip_lookup.');
comprobar(str_contains($ddl, 'chk_usuarios_admin_sin_nip'), 'Falta la regla de BD para admins sin NIP.');
comprobar(!str_contains($modelo, 'fecha_nacimiento'), 'El modelo aún contiene el campo retirado.');
comprobar(!str_contains($formulario, 'name="nip"'), 'El formulario administrativo acepta NIP manual.');
comprobar(!str_contains($formulario, 'nip_confirm'), 'El formulario administrativo conserva confirmación manual.');
comprobar(!str_contains($javascript, 'nip-disponible'), 'El frontend aún consulta disponibilidad manual.');
comprobar(str_contains($servicio, 'NipService::MAX_INTENTOS'), 'Faltan reintentos acotados en mutaciones.');
comprobar(str_contains($login, 'WHERE nip_lookup = ?'), 'El login no hace lookup directo.');
comprobar(str_contains($javascript, '¿Regenerar NIP?'), 'La regeneración no exige confirmación visible.');
comprobar(str_contains($javascript, 'form.submit()'), 'La regeneración no tiene envío confirmado.');
comprobar(str_contains($controlador, 'AdminCsrfService::validar'), 'La regeneración no valida CSRF.');
comprobar(str_contains($edicion, 'name="admin_csrf"'), 'La edición no entrega token CSRF para regenerar.');

NipService::usarGenerador(null);
echo "OK: contratos de acceso de usuarios\n";
