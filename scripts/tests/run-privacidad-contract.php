<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/FakeContactNotificationProvider.php';

use Services\PosReservacionSerializer;

/** @param mixed $condition */
function assertPrivacidad($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @param mixed $value */
function assertSinContacto($value): void
{
    $prohibidos = [
        'contacto',
        'contacto_tipo',
        'email',
        'telefono',
        'phone',
        'mobile',
        'correo',
        'correo_electronico',
        'numero_telefono',
        'contact',
        'contact_info',
        'contacto_visible',
        'contacto_presente',
    ];

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        assertPrivacidad(
            !is_string($key) || !in_array(strtolower($key), $prohibidos, true),
            "payload waiter contiene el campo prohibido {$key}"
        );
        assertSinContacto($child);
    }
}

$payload = [
    'reservaciones' => [[
        'nombre' => 'Cliente de prueba',
        'contacto_tipo' => 'email',
        'contacto' => 'cliente@example.test',
        'nota' => 'Ubicación preferida',
        'comentario_admin' => 'Avisar al llegar',
        'fecha' => '2026-08-16',
        'hora' => '14:00',
        'comensales' => 2,
        'mesas' => [['nombre' => 'Mesa 1']],
        'estado' => 'confirmada',
    ]],
    'ocupacion_por_reservacion' => [[
        'nombre' => 'Otra reserva',
        'email' => 'otra@example.test',
        'telefono' => '+525500000000',
    ]],
    'ticket' => [
        'reservaciones_proximas' => [[
            'nombre' => 'Reserva próxima',
            'phone' => '+525511111111',
        ]],
    ],
];

$waiter = PosReservacionSerializer::sanitizarParaWaiter($payload);
assertSinContacto($waiter);
assertPrivacidad($waiter['reservaciones'][0]['nombre'] === 'Cliente de prueba', 'waiter conserva nombre');
assertPrivacidad($waiter['reservaciones'][0]['nota'] === 'Ubicación preferida', 'waiter conserva nota');
assertPrivacidad($waiter['reservaciones'][0]['comentario_admin'] === 'Avisar al llegar', 'waiter conserva comentario_admin');
assertPrivacidad($waiter['reservaciones'][0]['mesas'][0]['nombre'] === 'Mesa 1', 'waiter conserva mesas');

$admin = PosReservacionSerializer::reservacion(
    [
        'id' => 1,
        'nombre' => 'Cliente de prueba',
        'contacto_tipo' => 'email',
        'contacto' => 'cliente@example.test',
        'fecha' => '2026-08-16',
        'hora' => '14:00:00',
        'comensales' => 2,
        'nota' => 'Ubicación preferida',
        'estado' => 'confirmada',
        'updated_at' => '2026-08-15 12:00:00',
    ],
    null,
    [],
    new DateTimeImmutable('2026-08-16 10:00:00')
);
assertPrivacidad($admin['contacto'] === 'cliente@example.test', 'admin conserva contacto');
assertPrivacidad($admin['contacto_tipo'] === 'email', 'admin conserva contacto_tipo');

$fake = new FakeContactNotificationProvider();
$fake->sendOtp('email', 'cliente@example.test', '123456');
assertPrivacidad($fake->ultimoCodigo() === '123456', 'fake provider controla el OTP sin respuesta HTTP');

$root = dirname(__DIR__, 2);
$otpService = file_get_contents($root . '/services/ContactoAccesoService.php');
$publicService = file_get_contents($root . '/services/ReservacionPublicaService.php');
$landing = file_get_contents($root . '/views/home/_reserva.php');
$form = file_get_contents($root . '/src/js/modules/form.js');
$access = file_get_contents($root . '/src/js/modules/reservation-access.js');
$pos = file_get_contents($root . '/src/js/modules/punto-de-venta.js');
$exporter = file_get_contents($root . '/n8n/exportar.js');
$n8nSuggestionWorkflow = file_get_contents($root . '/n8n/sugerencias.json');
$n8nFeedbackWorkflow = file_get_contents($root . '/n8n/areas-de-mejora.json');
$browserBundles = [
    $root . '/assets/js/bundle.min.js',
    $root . '/public/build/js/bundle.min.js',
    $root . '/public/build/js/admin/map.js',
];

assertPrivacidad(!str_contains($otpService, 'preview_code'), 'servicio OTP no devuelve preview_code');
assertPrivacidad(!str_contains($publicService, 'preview_code'), 'flujo público no propaga preview_code');
assertPrivacidad(!str_contains($form, 'preview_code') && !str_contains($form, 'renderPreview'), 'landing no consume preview OTP');
assertPrivacidad(!str_contains($access, 'preview_code') && !str_contains($access, 'renderPreview'), 'gestión pública no consume preview OTP');
assertPrivacidad(!str_contains($pos, '<dt>Contacto</dt>') && !str_contains($pos, 'Sin contacto'), 'UI POS no renderiza contacto');
assertPrivacidad(!str_contains($pos, 'mostrarContextoAdmin'), 'UI POS no activa contexto administrativo');
assertPrivacidad(!preg_match('/alergias?/iu', $landing), 'landing no solicita alergias');
assertPrivacidad(!str_contains($exporter, 'pinData'), 'exportador n8n no versiona pinData');
foreach ([$n8nSuggestionWorkflow, $n8nFeedbackWorkflow] as $workflow) {
    assertPrivacidad(!str_contains($workflow, 'pinData'), 'workflow n8n no versiona pinData');
    assertPrivacidad(!str_contains($workflow, 'r.email'), 'workflow n8n no consulta correo de reservaciones');
    assertPrivacidad(!str_contains($workflow, 'cliente_email'), 'workflow n8n no recibe correo de cliente');
}
foreach ($browserBundles as $browserBundle) {
    $browserContents = file_get_contents($browserBundle);
    assertPrivacidad(
        !str_contains($browserContents, 'preview_code')
            && !str_contains($browserContents, 'Código de prueba')
            && !str_contains($browserContents, 'Modo de desarrollo'),
        'bundle de navegador sin preview OTP: ' . basename($browserBundle)
    );
}
assertPrivacidad(
    !file_exists($root . '/assets/js/bundle.js.min.map')
        && !file_exists($root . '/public/build/js/bundle.js.min.map'),
    'no quedan mapas fuente heredados con preview OTP'
);

fwrite(STDOUT, "Privacidad: contratos POS, admin, OTP, landing y n8n OK\n");
