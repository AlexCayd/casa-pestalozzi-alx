<?php

declare(strict_types=1);

/** Contrato de alta, consulta, edicion y cancelacion administrativa. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\Reservacion;
use Model\VerificacionContacto;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionConfig;
use Services\ReservacionService;

$db = ActiveRecord::getDB();
$options = getopt('', ['db:']);
if (!empty($options['db']) && preg_match('/^[A-Za-z0-9_]+$/', (string)$options['db']) === 1) {
    $db->select_db((string)$options['db']);
    ActiveRecord::setDB($db);
}
$prefix = 'ETAPA8_ADMIN_' . bin2hex(random_bytes(4));
$contact = strtolower($prefix) . '@example.test';
$failures = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $message;
};
$escape = static fn(string $value): string => $db->real_escape_string($value);
$row = static function (int $id) use ($db): ?array {
    $result = $db->query("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1");
    return $result ? ($result->fetch_assoc() ?: null) : null;
};

$fecha = '2026-11-02';
$calendario = HorarioReservacionService::resolverFecha($fecha);
$hora = (string)($calendario['horarios_candidatos'][0] ?? '13:00');

try {
    $admin = DisponibilidadReservacionService::consultarAdministrativa($fecha, 13, 0, $hora);
    $publico = DisponibilidadReservacionService::consultar($fecha, 13, 0, $hora);
    $detalle = (array)($admin['detalle_horarios'][$hora] ?? []);
    $assert(($admin['horario_valido'] ?? false) === true, '8.1: el horario administrativo es valido');
    $assert(array_key_exists('capacidad_estimada', $detalle), '8.1: disponibilidad expone capacidad estimada');
    $assert(($detalle['asignacion_automatica_habilitada'] ?? true) === false, '8.1: mas de 12 deshabilita autoasignacion');
    $assert(($publico['disponible'] ?? true) === false, '8.1: flujo publico conserva limite de 12');

    $base = [
        'nombre' => $prefix . ' sin mesas',
        'contacto_tipo' => 'ninguno',
        'contacto' => '',
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 2,
        'nota' => '',
        'comentario_admin' => 'alta sin contacto',
        'request_token' => $prefix . '_SIN_MESAS_001',
        'asignar_automaticamente' => '0',
    ];
    $sinConfirmar = ReservacionService::crearAdministrativa($base);
    $assert(($sinConfirmar['ok'] ?? true) === false, '8.2: alta sin advertencias no guarda');
    $assert(($sinConfirmar['confirmaciones_requeridas'] ?? []) === [
        ReservacionAdministrativaService::SIN_CONTACTO,
        ReservacionAdministrativaService::SIN_ASIGNACION,
    ], '8.2: alta devuelve codigos de advertencia explicitos');

    $creada = ReservacionService::crearAdministrativa($base + [
        'confirmaciones' => [
            ReservacionAdministrativaService::SIN_CONTACTO,
            ReservacionAdministrativaService::SIN_ASIGNACION,
        ],
    ]);
    $idSinMesa = (int)($creada['id'] ?? 0);
    $filaSinMesa = $row($idSinMesa);
    $assert(($creada['ok'] ?? false) === true && $idSinMesa > 0, '8.2: alta confirmada guarda');
    $assert(($filaSinMesa['origen'] ?? '') === 'admin' && ($filaSinMesa['estado'] ?? '') === 'confirmada', '8.2: origen y estado administrativos');
    $assert(($filaSinMesa['hold_expires_at'] ?? null) === null, '8.2: alta administrativa no crea hold');
    $assert((int)$db->query("SELECT COUNT(*) AS total FROM verificaciones_contacto WHERE reservacion_id = {$idSinMesa}")->fetch_assoc()['total'] === 0, '8.2: alta administrativa no crea OTP');
    $assert((int)$db->query("SELECT COUNT(*) AS total FROM reservacion_mesas WHERE reservacion_id = {$idSinMesa}")->fetch_assoc()['total'] === 0, '8.2: alta sin auto queda sin mesas');

    $auto = ReservacionService::crearAdministrativa([
        'nombre' => $prefix . ' auto',
        'contacto_tipo' => 'email',
        'contacto' => $contact,
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 2,
        'nota' => 'nota inicial',
        'comentario_admin' => '',
        'request_token' => $prefix . '_AUTO_001',
        'asignar_automaticamente' => '1',
    ]);
    $idAuto = (int)($auto['id'] ?? 0);
    $assert(($auto['ok'] ?? false) === true && !empty($auto['mesa_ids']), '8.3: autoasignacion usa la combinacion canonica');

    $large = ReservacionService::crearAdministrativa([
        'nombre' => $prefix . ' grande',
        'contacto_tipo' => 'email',
        'contacto' => $contact,
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 13,
        'nota' => '',
        'comentario_admin' => '',
        'request_token' => $prefix . '_LARGE_001',
        'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ]);
    $assert(($large['ok'] ?? false) === true, '8.3: alta administrativa supera el maximo publico');

    $edit = ReservacionService::actualizarDatos($idSinMesa, [
        'id' => $idSinMesa,
        'nombre' => $prefix . ' corregida',
        'contacto_tipo' => 'email',
        'contacto' => $contact,
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 2,
        'nota' => 'nota corregida',
        'comentario_admin' => 'editada',
        'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ]);
    $filaEditada = $row($idSinMesa);
    $assert(($edit['ok'] ?? false) === true && ($filaEditada['nota'] ?? '') === 'nota corregida', '8.4: edicion actualiza nota y datos');
    $cambioTipo = ReservacionService::actualizarDatos($idSinMesa, [
        'nombre' => $prefix . ' tipo', 'contacto_tipo' => 'telefono', 'contacto' => '+525512345678',
        'fecha' => $fecha, 'hora' => $hora, 'comensales' => 2, 'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ]);
    $assert(($cambioTipo['codigo'] ?? '') === ReservacionAdministrativaService::CONTACTO_TIPO_NO_EDITABLE, '8.4: edicion no cambia tipo de contacto existente');

    $cancel = ReservacionService::ejecutarAccionOperativa($idAuto, 'cancelada', 1, 'prueba etapa 8');
    $cancelAgain = ReservacionService::ejecutarAccionOperativa($idAuto, 'cancelada', 1, 'repetida');
    $assert(($cancel['ok'] ?? false) === true && ($row($idAuto)['estado'] ?? '') === 'cancelada', '8.5: cancelacion conserva fila e historial');
    $assert(($cancelAgain['ok'] ?? false) === true && ($cancelAgain['idempotente'] ?? false) === true, '8.5: cancelacion es idempotente');

    $pendingToken = $prefix . '_PENDING_001';
    $stmt = $db->prepare("INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, hold_expires_at, request_token, estado_changed_at) VALUES (?, 'email', ?, ?, ?, 2, '', 'landing', 'pendiente_verificacion', '2026-11-01 12:15:00', ?, NOW())");
    $pendingName = $prefix . ' pending';
    $stmt->bind_param('sssss', $pendingName, $contact, $fecha, $hora, $pendingToken);
    $stmt->execute();
    $pendingId = (int)$stmt->insert_id;
    $stmt->close();
    VerificacionContacto::crearHash('email', $contact, password_hash('111111', PASSWORD_DEFAULT), '2026-11-01 12:10:00', $pendingId);
    $cancelPending = ReservacionAdministrativaService::cancelar($pendingId, 1, 'cancelacion administrativa');
    $otpInvalidated = $db->query("SELECT invalidated_at FROM verificaciones_contacto WHERE reservacion_id = {$pendingId} LIMIT 1")->fetch_assoc();
    $assert(($cancelPending['ok'] ?? false) === true && ($row($pendingId)['estado'] ?? '') === 'cancelada', '8.6: admin puede cancelar pendiente sin confirmarla');
    $assert(($otpInvalidated['invalidated_at'] ?? null) !== null, '8.6: cancelacion invalida OTP ligado');
} catch (Throwable $error) {
    $failures[] = '8: excepcion no controlada: ' . $error->getMessage();
} finally {
    $ids = [];
    $result = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '" . $escape($prefix) . "%'");
    if ($result) {
        while ($fila = $result->fetch_assoc()) {
            $ids[] = (int)$fila['id'];
        }
        $result->free();
    }
    if ($ids !== []) {
        $db->query('DELETE FROM verificaciones_contacto WHERE reservacion_id IN (' . implode(',', $ids) . ')');
        $db->query('DELETE FROM reservacion_mesas WHERE reservacion_id IN (' . implode(',', $ids) . ')');
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', $ids) . ')');
    }
}

echo json_encode([
    'ok' => $failures === [],
    'passed' => $passed,
    'failed' => $failures,
    'prefix' => $prefix,
    'timezone' => ReservacionConfig::timezone()->getName(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
