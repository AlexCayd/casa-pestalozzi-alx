<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar las alertas de impresión.\n");
    exit(1);
}

require $root . '/includes/app.php';

use Classes\TicketPrinter;
use Model\ActiveRecord;
use Services\Pos\ImpresionAlertaService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$assert(
    $db->query('INSERT INTO configuracion_pos (id, impresion_activa) VALUES (1, 1)
                ON DUPLICATE KEY UPDATE impresion_activa = 1') !== false,
    'No se pudo activar impresión en la fixture aislada.'
);
$assert($db->query('UPDATE impresoras SET activo = 0') !== false, 'No se pudieron desactivar los destinos de hardware de la fixture.');

$_SESSION['rol'] = 'waiter';
$resultadoComanda = TicketPrinter::imprimirComanda(
    [[
        'nombre' => 'Artículo de prueba',
        'cantidad' => 1,
        'comensal' => null,
        'nota' => '',
        'precio' => 10.00,
        'area_id' => 3,
    ]],
    ['mesa' => 'Prueba', 'mesa_nombre' => 'Mesa de prueba', 'cliente' => 'Fixture', 'mesero' => 'Test']
);
$fallosComanda = TicketPrinter::fallos();
$assert(($resultadoComanda[3] ?? true) === false, 'La comanda de prueba no registró el destino ausente.');
$assert(($fallosComanda[0]['motivo'] ?? '') === 'sin_impresora', 'TicketPrinter no clasificó la comanda sin impresora.');
$alertasComanda = ImpresionAlertaService::registrar($fallosComanda, ['mesa_nombre' => 'Mesa de prueba']);
$assert(count($alertasComanda) === 1, 'La falla de comanda no creó una alerta persistida.');

$resultadoCuenta = TicketPrinter::imprimirCuenta(
    ['id' => 900001, 'cliente' => 'Fixture', 'comensales' => 1, 'mesa' => 'Prueba'],
    [['nombre' => 'Artículo de prueba', 'precio' => 10.00, 'cantidad' => 1]],
    'efectivo'
);
$fallosCuenta = TicketPrinter::fallos();
$assert($resultadoCuenta === false, 'La cuenta de prueba no registró el destino ausente.');
$assert(($fallosCuenta[0]['documento'] ?? '') === 'cuenta', 'TicketPrinter no clasificó el documento cuenta.');
$assert(($fallosCuenta[0]['motivo'] ?? '') === 'sin_impresora', 'TicketPrinter no clasificó la cuenta sin impresora.');
$alertasCuenta = ImpresionAlertaService::registrar($fallosCuenta, ['mesa_nombre' => 'Mesa de prueba']);
$assert(count($alertasCuenta) === 1, 'La falla de cuenta no creó una alerta persistida.');

$pendientes = ImpresionAlertaService::pendientes();
$assert(count($pendientes) === 2, 'El POS no recibe las dos alertas pendientes.');
$assert(!array_key_exists('detalle', $pendientes[0]), 'El detalle técnico se expuso a un mesero.');
$id = (int)$alertasCuenta[0]['id'];
$assert(ImpresionAlertaService::atender([$id], 0) === 1, 'No se pudo atender una alerta específica.');
$assert(count(ImpresionAlertaService::pendientes()) === 1, 'La bandeja no quitó la alerta atendida.');
$assert(ImpresionAlertaService::atender(null, 0) === 1, 'No se pudieron atender las alertas restantes.');
$assert(ImpresionAlertaService::pendientes() === [], 'La bandeja conserva alertas después de atenderlas todas.');

foreach ([
    [$root . '/public/index.php', "'/api/impresion/alertas/atender'", 'La ruta de atención no está registrada.'],
    [$root . '/controllers/PuntoVentaController.php', 'atenderAlertasImpresion', 'El controlador POS no expone la atención.'],
    [$root . '/views/punto-de-venta/partials/pos-workspace.php', 'id="pos-print-alerts-toggle"', 'Falta el disparador visible de alertas en el POS.'],
    [$root . '/src/js/modules/punto-de-venta.js', 'function sincronizarAlertasImpresion', 'El POS no sincroniza alertas de impresión.'],
    [$root . '/src/js/modules/punto-de-venta.js', 'function atenderAlertasImpresion', 'El POS no conecta la acción de atención.'],
] as [$path, $needle, $message]) {
    $source = file_get_contents($path);
    $assert(is_string($source) && str_contains($source, $needle), $message);
}

fwrite(STDOUT, "PASS: fallos de comanda/cuenta, persistencia, bandeja POS y atención de alertas.\n");
