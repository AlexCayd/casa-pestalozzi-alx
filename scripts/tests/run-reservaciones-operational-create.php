<?php

declare(strict_types=1);

/*
 * Alta operativa de reservaciones: el «Nueva reservación» del POS. Mesero y
 * admin comparten diálogo y endpoint; el contacto sólo lo captura un admin, y
 * el servidor lo descarta si llega de un mesero aunque alguien lo fabrique.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/views/admin/partials/_icons.php';

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

// La tabla de rutas vive en Permisos desde Roles y permisos.
$auth = readOperationalCreateSource($root, 'services/Security/Permisos.php');
$index = readOperationalCreateSource($root, 'public/index.php');
$posController = readOperationalCreateSource($root, 'controllers/PosReservacionController.php');
$adminController = readOperationalCreateSource($root, 'controllers/AdminReservacionController.php');
$altaJs = readOperationalCreateSource($root, 'src/js/operation/pos-alta.js');

foreach (['/api/punto-de-venta/reservaciones/crear', '/api/punto-de-venta/reservaciones/disponibilidad'] as $route) {
    assertOperationalCreate(
        \Services\Security\Permisos::clasificar($route)['modulos'] === ['pdv'],
        "Permisos registra {$route} en el POS"
    );
    assertOperationalCreate(str_contains($index, "'{$route}'"), "Router registra {$route}");
}
assertOperationalCreate(
    \Services\Security\Permisos::decidir(
        '/admin/api/reservaciones/disponibilidad',
        'GET',
        ['sistema' => false, 'permisos' => ['pdv' => 'editar']]
    )['resultado'] === 'negar',
    'la disponibilidad administrativa general no se abre a meseros'
);

$normalizer = new ReflectionMethod(\Services\Pos\PosAltaReservacionService::class, 'normalizarEntrada');

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
    // Normaliza por PERMISO: captura el contacto quien edita Reservaciones.
    str_contains($posController, "PosAltaReservacionService::normalizarEntrada(\$datos, !Auth::puede('reservations', Permisos::EDITAR));")
        && str_contains($posController, 'ReservacionService::crearAdministrativa(')
        && str_contains($posController, 'ReservacionAltaPresenter::json($resultado, $datos)'),
    'el alta del POS normaliza por rol y usa el mismo dominio y presenter que el panel'
);
assertOperationalCreate(
    str_contains($adminController, 'ReservacionAltaPresenter')
        && !str_contains($posController, 'VerificacionContacto')
        && !str_contains($posController, 'solicitarCodigo'),
    'el alta compartida no inicia OTP'
);
assertOperationalCreate(
    str_contains($altaJs, 'request_token: estado.token')
        && str_contains($altaJs, 'confirmaciones: confirmaciones || []'),
    'el reenvío tras una decisión conserva el request_token (un doble toque no duplica)'
);

// El HTML efectivo del diálogo, por rol.
$render = static function (string $rol) use ($root): string {
    $_SESSION['rol'] = $rol;
    ob_start();
    include $root . '/views/punto-de-venta/partials/pos-alta.php';
    return (string)ob_get_clean();
};
$waiterHtml = $render('waiter');
assertOperationalCreate(!str_contains($waiterHtml, 'name="contacto_tipo"'), 'HTML waiter no contiene contacto_tipo');
assertOperationalCreate(!str_contains($waiterHtml, 'name="contacto"'), 'HTML waiter no contiene contacto');
assertOperationalCreate(str_contains($waiterHtml, 'data-alta-nombre'), 'HTML waiter conserva nombre');
assertOperationalCreate(str_contains($waiterHtml, 'data-alta-horas'), 'HTML waiter conserva la rejilla de horas');
$adminHtml = $render('admin');
assertOperationalCreate(str_contains($adminHtml, 'name="contacto_tipo"') && str_contains($adminHtml, 'name="contacto"'), 'HTML admin captura contacto');

fwrite(STDOUT, "Reservaciones: alta operativa admin/waiter desde el POS OK\n");
