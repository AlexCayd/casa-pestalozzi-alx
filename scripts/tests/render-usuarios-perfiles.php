<?php

/**
 * Renderiza el formulario real de Usuarios sin BD para run-usuarios-perfiles.cjs.
 * Recibe un caso JSON: ámbitos permitidos, techo del actor y facultades visibles.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Services\Security\PermisosUsuario;
use Services\Users\PerfilesSugeridos;

$caso = json_decode($argv[1] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
$ambitosPermitidos = (array) $caso['ambitos'];
$facultades = (array) ($caso['facultades'] ?? [
    'usuarios.gestionar' => 'Gestionar cuentas.',
    'permisos.delegar' => 'Delegar permisos.',
    'transferencias.enviar' => 'Enviar transferencias.',
]);
$catalogos = [];
foreach ($ambitosPermitidos as $slug) $catalogos[$slug] = PermisosUsuario::catalogo($slug);
$facultadesPorAmbito = array_fill_keys($ambitosPermitidos, $facultades);
$techosPorAmbito = array_fill_keys($ambitosPermitidos, $caso['techo'] ?? '*');
$perfilesSugeridos = PerfilesSugeridos::disponibles($ambitosPermitidos);
$contextoUsuarios = (string) ($caso['contexto'] ?? 'global');
$contextoNombre = 'Prueba';
$baseUrl = '/admin/usuarios';
$usuario = is_array($caso['usuario'] ?? null) ? $caso['usuario'] : [];
$identityEditable = true;
$puedeEditar = true;
$adminCsrfToken = 'csrf-prueba';
$alertas = [];
include $root . '/views/admin/users/directo-form.php';
