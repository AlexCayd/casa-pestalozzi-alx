<?php

declare(strict_types=1);

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

$auth = readPermissionSource($root, 'classes/Auth.php');
$index = readPermissionSource($root, 'public/index.php');
$controller = readPermissionSource($root, 'controllers/ReservacionOperacionController.php');
$mapService = readPermissionSource($root, 'services/ReservacionMapaAdministrativaService.php');
$serializer = readPermissionSource($root, 'services/PosReservacionSerializer.php');
$operationView = readPermissionSource($root, 'views/operation/reservations/index.php');
$posWorkspace = readPermissionSource($root, 'views/punto-de-venta/partials/pos-workspace.php');
$operationJs = readPermissionSource($root, 'src/js/admin/reservations/operation.js');
$authority = readPermissionSource($root, 'docs/reservaciones/reservaciones.md');

$operationRoutes = [
    '/admin/reservaciones/operacion',
    '/admin/api/reservaciones/operacion',
    '/admin/api/reservaciones/operacion/crear',
    '/admin/api/reservaciones/operacion/disponibilidad',
    '/admin/api/reservaciones/operacion/asignar-mesas',
    '/admin/api/reservaciones/operacion/liberar-mesas',
    '/admin/api/reservaciones/operacion/reasignar',
    '/admin/api/reservaciones/operacion/comentario',
    '/admin/api/reservaciones/operacion/estado',
];

foreach ($operationRoutes as $route) {
    assertPermissionContract(str_contains($auth, "'{$route}'"), "Auth registra {$route}");
    assertPermissionContract(str_contains($index, "'{$route}'"), "Router registra {$route}");
}

assertPermissionContract(
    str_contains($auth, '$esMapaReservacionesUrl = in_array($url, self::RUTAS_MAPA_RESERVACIONES_OPERACION, true);')
        && str_contains($auth, '($esMapaReservacionesUrl && self::esMesero())')
        && str_contains($auth, 'str_starts_with($url, \'/admin/\')'),
    'waiter solo recibe la excepcion explicita del mapa, no todo /admin/*'
);
assertPermissionContract(
    !str_contains($auth, '($esMapaReservacionesUrl && self::esCocinero())'),
    'cook no comparte el permiso del mapa operativo'
);

assertPermissionContract(
    str_contains($controller, "'superficie' => Auth::esAdmin() ? 'admin' : 'waiter'")
        && str_contains($controller, 'PosReservacionSerializer::sanitizarParaWaiter($data)'),
    'la lectura y las respuestas del mapa proyectan waiter sin contacto'
);
assertPermissionContract(
    str_contains($serializer, "'contacto_visible'")
        && str_contains($serializer, "'contacto_presente'"),
    'la proyeccion waiter elimina aliases derivados de contacto'
);

assertPermissionContract(
    str_contains($controller, 'ReservacionMapaAdministrativaService::guardarAsignacion')
        && str_contains($controller, 'ReservacionMapaAdministrativaService::liberarAsignacion')
        && str_contains($controller, 'AsignacionMesasService::asignarAutomaticamente')
        && str_contains($controller, 'ReservacionService::actualizarComentario'),
    'el mapa conserva asignar, liberar, reasignar y comentar'
);
assertPermissionContract(
    str_contains($controller, '!in_array($estado, [\'en_curso\', \'cancelada\', \'no_show\'], true)')
        && str_contains($controller, "'permitir_liberacion_operativa' => Auth::esMesero()"),
    'waiter solo usa transiciones operativas autorizadas y puede liberar desde el mapa'
);
assertPermissionContract(
    str_contains($mapService, '!empty($opciones[\'permitir_liberacion_operativa\'])')
        && str_contains($mapService, '(string)($reservacion[\'origen\'] ?? \'\') !== \'admin\''),
    'la liberacion ampliada es una opcion operativa explicita y no elimina la regla admin heredada'
);

assertPermissionContract(
    str_contains($operationView, 'data-operation-surface=')
        && str_contains($operationView, 'if ($puedeCrearDesdeMapa):')
        && str_contains($operationView, '!$operacionEditable ? \'hidden disabled aria-disabled="true"\' : \'\'')
        && str_contains($operationView, '$mostrarCamposContacto = $puedeCapturarContacto;'),
    'admin y waiter comparten el CTA y formulario del mapa con campos por rol'
);
assertPermissionContract(
    str_contains($posWorkspace, '$puedeMapaReservaciones')
        && str_contains($posWorkspace, "'/admin/reservaciones/operacion'"),
    'el POS ofrece el acceso existente al mapa a admin y waiter'
);
assertPermissionContract(
    str_contains($operationJs, "surface: root.getAttribute('data-operation-surface') || 'admin'")
        && str_contains($operationJs, "mostrarContextoAdmin: state.surface === 'admin'")
        && str_contains($operationJs, "state.surface === 'waiter' || reservacion.origen === 'admin'")
        && str_contains($operationJs, "var detailLink = state.surface === 'admin'")
        && !str_contains($operationJs, "contacto: reservacion.contacto_visible || 'Sin contacto'"),
    'la UI del waiter omite contacto y edicion administrativa, pero conserva acciones operativas'
);

foreach ([
    '/admin/reservaciones',
    '/admin/reservaciones/crear',
    '/admin/reservaciones/detalle',
    '/admin/api/reservaciones/disponibilidad',
    '/admin/reservaciones/actualizar',
    '/admin/reservaciones/estado',
    '/admin/reservaciones/reasignar',
    '/admin/reservaciones/herramientas-desarrollo',
] as $adminOnlyRoute) {
    assertPermissionContract(
        str_contains($index, "'{$adminOnlyRoute}'")
            && str_contains($auth, 'str_starts_with($url, \'/admin/\')'),
        "{$adminOnlyRoute} permanece dentro del panel administrativo"
    );
}

assertPermissionContract(
    str_contains($authority, 'superficie compartida por los roles')
        && str_contains($authority, '`admin` y `waiter`'),
    'la autoridad documenta el acceso compartido sin duplicar el contrato de privacidad'
);

fwrite(STDOUT, "Reservaciones: matriz de permisos del mapa operativo OK\n");
