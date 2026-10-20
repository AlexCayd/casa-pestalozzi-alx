<?php

declare(strict_types=1);

/*
 * Matriz de permisos de la operación de reservaciones. La operación vive en el
 * POS: meseros y admin la usan por las APIs de /api/punto-de-venta/..., que se
 * abren por allowlist EXACTA (Services\Security\Permisos::APIS, módulo pdv). La pantalla de Operación se
 * retiró; sus dos direcciones sólo redirigen al POS y sus APIs ya no existen.
 */

$root = dirname(__DIR__, 2);

/** @param mixed $condition */
function assertPermissionContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function readPermissionSource(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assertPermissionContract(is_string($contents), "se pudo leer {$path}");
    return $contents;
}

require_once $root . '/vendor/autoload.php';

use Services\Security\Permisos;

// La tabla de rutas y la decisión viven en Permisos desde Roles y permisos;
// Auth sólo carga el rol de la sesión y aplica la respuesta.
$auth = readPermissionSource($root, 'services/Security/Permisos.php');
$mesero = ['sistema' => false, 'permisos' => ['pdv' => Permisos::EDITAR]];
$cocinero = ['sistema' => false, 'permisos' => ['area' => Permisos::EDITAR]];
$decide = static fn (string $url, array $rol): string => Permisos::decidir($url, 'GET', $rol)['resultado'];
$index = readPermissionSource($root, 'public/index.php');
$posController = readPermissionSource($root, 'controllers/PosReservacionController.php');
$puntoVenta = readPermissionSource($root, 'controllers/PuntoVentaController.php');
$serializer = readPermissionSource($root, 'services/Pos/PosReservacionSerializer.php');

// La pantalla retirada: sin controlador, sin APIs, sólo redirección.
assertPermissionContract(
    !file_exists($root . '/controllers/ReservacionOperacionController.php'),
    'el controlador de Operación ya no existe'
);
assertPermissionContract(
    !str_contains($index, '/admin/api/reservaciones/operacion')
        && !str_contains($auth, '/admin/api/reservaciones/operacion'),
    'las APIs de Operación desaparecieron del router y de la tabla de permisos'
);
foreach (['/admin/reservaciones/operacion', '/admin/reservations/operation'] as $legacy) {
    assertPermissionContract(
        str_contains($index, "'{$legacy}',") && str_contains($index, "[PuntoVentaController::class, 'redirigirDesdeOperacion']"),
        "{$legacy} redirige al POS"
    );
    assertPermissionContract($decide($legacy, $mesero) === 'permitir', "Permisos abre {$legacy} a meseros");
    assertPermissionContract(
        Permisos::clasificar($legacy)['modulos'] === ['pdv'],
        "{$legacy} se abre con el permiso del POS, no como excepción de /admin/*"
    );
}
assertPermissionContract(
    str_contains($puntoVenta, 'public static function redirigirDesdeOperacion')
        && str_contains($puntoVenta, "header('Location: /punto-de-venta?' . http_build_query(\$consulta), true, 302);")
        && str_contains($puntoVenta, 'self::enlaceInicial($_GET)'),
    'la redirección valida el enlace antes de reenviarlo'
);

// Las APIs del POS: allowlist exacta, mismo controlador, CSRF de personal.
foreach ([
    'disponibilidad' => 'disponibilidad',
    'crear' => 'crear',
    'asignar-mesas' => 'asignarMesas',
    'reasignar' => 'reasignar',
    'comentario' => 'comentario',
    'reubicaciones/revisar' => 'revisarReubicacion',
] as $ruta => $metodo) {
    $url = '/api/punto-de-venta/reservaciones/' . $ruta;
    assertPermissionContract(Permisos::clasificar($url)['modulos'] === ['pdv'], "Permisos registra {$url} en el POS");
    assertPermissionContract(
        str_contains($index, "'{$url}', [PosReservacionController::class, '{$metodo}']"),
        "Router registra {$url}"
    );
}
assertPermissionContract(
    str_contains($posController, 'StaffCsrfService::validarRequest(')
        && str_contains($posController, 'PosReservacionSerializer::sanitizarParaWaiter($datos)'),
    'las APIs del POS exigen CSRF de personal y sanean la respuesta para el mesero'
);
assertPermissionContract(
    preg_match('/function comentario\(.*?if \(!Auth::puede\(\'reservations\', Permisos::EDITAR\)\) \{\s*self::json\(\[\'ok\' => false, \'codigo\' => \'PERMISO_DENEGADO\'\], 403\);/s', $posController) === 1,
    'el comentario interno sólo lo edita quien edita Reservaciones, y lo decide el servidor'
);
assertPermissionContract(
    str_contains($posController, "PosAltaReservacionService::normalizarEntrada(\$datos, !Auth::puede('reservations', Permisos::EDITAR))"),
    'el alta del mesero se normaliza sin contacto'
);
assertPermissionContract(
    str_contains($serializer, "'contacto_visible'")
        && str_contains($serializer, "'contacto_presente'"),
    'la proyeccion waiter elimina aliases derivados de contacto'
);
assertPermissionContract(
    $decide('/admin/reservaciones/operacion', $cocinero) === 'negar',
    'cook no hereda la operación de reservaciones'
);

foreach ([
    '/admin/reservaciones',
    '/admin/reservaciones/crear',
    '/admin/reservaciones/detalle',
    '/admin/api/reservaciones/disponibilidad',
    '/admin/api/reservaciones/estado',
    '/admin/reservaciones/actualizar',
    '/admin/reservaciones/estado',
    '/admin/reservaciones/reasignar',
    '/admin/reservaciones/herramientas-desarrollo',
] as $adminOnlyRoute) {
    assertPermissionContract(
        str_contains($index, "'{$adminOnlyRoute}'")
            && Permisos::clasificar($adminOnlyRoute)['modulos'] === ['reservations']
            && $decide($adminOnlyRoute, $mesero) === 'negar',
        "{$adminOnlyRoute} permanece dentro del panel administrativo"
    );
}

fwrite(STDOUT, "Reservaciones: matriz de permisos de la operación en el POS OK\n");
