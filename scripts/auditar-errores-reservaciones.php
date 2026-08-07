<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Services\ReservacionErrorCatalog;

/** @param mixed $condition */
function auditAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$required = [
    'SIN_ASIGNACION',
    'CAPACIDAD_OPERATIVA_EXCEDIDA',
    'CAPACIDAD_INSUFICIENTE',
    'RESERVACION_PROXIMA',
    'CONFLICTO_TICKETS_ABIERTOS',
];

foreach ($required as $code) {
    auditAssert(ReservacionErrorCatalog::has($code), "{$code} esta catalogado");
    $presentation = ReservacionErrorCatalog::presentar($code);
    auditAssert($presentation['mensaje'] !== '', "{$code} tiene mensaje");
    auditAssert($presentation['descripcion'] !== '', "{$code} tiene descripcion");
    auditAssert($presentation['consecuencia'] !== '', "{$code} tiene consecuencia");
    auditAssert($presentation['acciones'] !== [], "{$code} tiene acciones");
}

$decision = ReservacionErrorCatalog::presentar('SIN_ASIGNACION');
auditAssert($decision['tipo'] === ReservacionErrorCatalog::TIPO_DECISION, 'SIN_ASIGNACION es decision');
auditAssert($decision['http_status'] === 409, 'SIN_ASIGNACION usa HTTP 409');
auditAssert($decision['commit'] === false, 'SIN_ASIGNACION no confirma');

$pos = file_get_contents($root . '/src/js/modules/punto-de-venta.js');
$form = file_get_contents($root . '/src/js/admin/reservations/form.js');
$operation = file_get_contents($root . '/src/js/admin/reservations/operation.js');
foreach ([
    'warningsLocalesParaTicket',
    'bloqueo.motivo',
] as $forbidden) {
    auditAssert(strpos($pos, $forbidden) === false, "POS no contiene {$forbidden}");
}
foreach (['labels[code] || code', 'warningCodesForSubmit', 'confirmaciones_requeridas_presentaciones'] as $forbidden) {
    auditAssert(strpos($form . $operation, $forbidden) === false, "admin no contiene {$forbidden}");
}

$adminController = file_get_contents($root . '/controllers/AdminReservacionController.php');
$operationController = file_get_contents($root . '/controllers/ReservacionOperacionController.php');
auditAssert(strpos($adminController, 'decisionesResultado') !== false, 'admin enriquece decisiones desde catalogo');
auditAssert(strpos($operationController, 'decisionesResultado') !== false, 'operacion enriquece decisiones desde catalogo');

fwrite(STDOUT, "Reservaciones: auditoria contractual OK\n");
