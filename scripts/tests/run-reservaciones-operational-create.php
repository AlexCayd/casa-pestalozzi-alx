<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Controllers\ReservacionOperacionController;
use Model\Reservacion;

/** @param mixed $condition */
function assertOperationalCreate($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function readOperationalCreateSource(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assertOperationalCreate(is_string($contents), "se pudo leer {$path}");
    return $contents;
}

$auth = readOperationalCreateSource($root, 'classes/Auth.php');
$index = readOperationalCreateSource($root, 'public/index.php');
$controller = readOperationalCreateSource($root, 'controllers/ReservacionOperacionController.php');
$adminController = readOperationalCreateSource($root, 'controllers/AdminReservacionController.php');
$form = readOperationalCreateSource($root, 'views/admin/reservations/_form.php');
$operationView = readOperationalCreateSource($root, 'views/operation/reservations/index.php');
$operationJs = readOperationalCreateSource($root, 'src/js/admin/reservations/operation.js');

$routes = [
    '/admin/api/reservaciones/operacion/crear',
    '/admin/api/reservaciones/operacion/disponibilidad',
];
foreach ($routes as $route) {
    assertOperationalCreate(str_contains($auth, "'{$route}'"), "Auth registra {$route}");
    assertOperationalCreate(str_contains($index, "'{$route}'"), "Router registra {$route}");
}
assertOperationalCreate(
    !str_contains($auth, "'/admin/api/reservaciones/disponibilidad',"),
    'la disponibilidad administrativa general no se agrega a la excepcion del mapa'
);

$reflection = new ReflectionClass(ReservacionOperacionController::class);
$normalizer = $reflection->getMethod('normalizarDatosAltaOperativa');
$normalizer->setAccessible(true);

$manipulada = [
    'nombre' => 'Cliente operativo',
    'contacto_tipo' => 'email',
    'contacto' => 'cliente@example.test',
    'confirmaciones' => 'CAPACIDAD_INSUFICIENTE',
    'fecha' => '2026-08-20',
    'hora' => '14:00',
    'comensales' => '4',
];
$waiterData = $normalizer->invoke(null, $manipulada, true);
assertOperationalCreate($waiterData['contacto_tipo'] === 'ninguno', 'waiter fuerza contacto_tipo=ninguno');
assertOperationalCreate($waiterData['contacto'] === null, 'waiter fuerza contacto=NULL');
assertOperationalCreate($waiterData['confirmar_sin_contacto'] === '1', 'waiter confirma el alta interna sin contacto');
assertOperationalCreate(in_array('SIN_CONTACTO', $waiterData['confirmaciones'], true), 'waiter no abre una decision de contacto');
assertOperationalCreate(in_array('CAPACIDAD_INSUFICIENTE', $waiterData['confirmaciones'], true), 'waiter conserva confirmaciones operativas legitimas');

$adminData = $normalizer->invoke(null, $manipulada, false);
assertOperationalCreate($adminData === $manipulada, 'admin conserva el formulario completo de contacto');

assertOperationalCreate(
    str_contains($controller, '$_POST = self::normalizarDatosAltaOperativa($_POST, Auth::esMesero());')
        && str_contains($controller, 'AdminReservacionController::store($router);'),
    'el alta operativa delega al flujo administrativo existente'
);
assertOperationalCreate(
    str_contains($adminController, 'ReservacionService::crearAdministrativa(')
        && !str_contains($controller, 'VerificacionContacto')
        && !str_contains($controller, 'solicitarCodigo'),
    'el alta compartida conserva capacidad/asignacion y no inicia OTP'
);
assertOperationalCreate(
    str_contains($form, '$mostrarCamposContacto = (bool)($mostrarCamposContacto ?? true);')
        && str_contains($form, 'if ($mostrarCamposContacto)')
        && str_contains($form, '$disponibilidadEndpoint'),
    'el formulario compartido permite ocultar contacto y usar la API operativa de horarios'
);
assertOperationalCreate(
    str_contains($operationView, '$puedeCrearDesdeMapa')
        && str_contains($operationView, '$puedeCapturarContacto = (bool)($puedeCapturarContacto ?? $puedeCrearAdministrativa);')
        && str_contains($operationView, '$formAction = $createReservationAction;'),
    'el mapa comparte alta y separa accion/campos segun el rol'
);
assertOperationalCreate(
    str_contains($operationJs, 'postJson(createForm.action')
        && str_contains($operationJs, 'data-operation-surface'),
    'el mapa sigue usando el mismo submit del formulario compartido'
);
assertOperationalCreate(
    str_contains($form, "(\$modo === 'crear' ? '/admin/reservaciones/crear' : '/admin/reservaciones/actualizar')")
        && str_contains($form, "'/admin/api/reservaciones/disponibilidad'"),
    'admin conserva las rutas administrativas por defecto'
);

// Verifica el HTML efectivo del mismo partial en modo waiter: no se envian
// controles de contacto aunque la fuente compartida contenga el formulario de admin.
$modo = 'crear';
$reservacion = new Reservacion();
$reservacion->fecha = '2026-08-20';
$reservacion->comensales = 2;
$reservacion->estado = 'confirmada';
$reservacion->request_token = 'abcdefghijklmnop';
$errores = [];
$editable = true;
$returnUrl = '/admin/reservaciones/operacion';
$backUrl = $returnUrl;
$estadoLabels = [];
$fechaActual = '2026-08-16';
$diasActivos = range(0, 6);
$maxComensalesAdmin = 40;
$comentarioAdminDisponible = true;
$asignarAutomaticamente = true;
$mesasAsignadas = [];
$motivoNoEditable = '';
$formTransport = 'json';
$formActionsExternal = true;
$formAction = '/admin/api/reservaciones/operacion/crear';
$mostrarCamposContacto = false;
$disponibilidadEndpoint = '/admin/api/reservaciones/operacion/disponibilidad';
$adminCsrfToken = 'test-token';
ob_start();
include $root . '/views/admin/reservations/_form.php';
$waiterHtml = (string)ob_get_clean();
assertOperationalCreate(!str_contains($waiterHtml, 'name="contacto_tipo"'), 'HTML waiter no contiene contacto_tipo');
assertOperationalCreate(!str_contains($waiterHtml, 'name="contacto"'), 'HTML waiter no contiene contacto');
assertOperationalCreate(!str_contains($waiterHtml, 'Tipo de contacto'), 'HTML waiter no muestra tipo de contacto');
assertOperationalCreate(str_contains($waiterHtml, 'name="nombre"'), 'HTML waiter conserva nombre');
assertOperationalCreate(str_contains($waiterHtml, 'name="comentario_admin"'), 'HTML waiter conserva comentario interno');
assertOperationalCreate(str_contains($waiterHtml, 'data-schedules-endpoint="/admin/api/reservaciones/operacion/disponibilidad"'), 'HTML waiter usa disponibilidad operativa');

fwrite(STDOUT, "Reservaciones: alta operativa admin/waiter OK\n");
