<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\ReservacionAsignacionVersionService;

function assertDbReassignment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = ActiveRecord::getDB();
$token = 'audit-fix-' . bin2hex(random_bytes(8));
$reservacionId = 0;
$pasos = [];

try {
    $tokenSql = $db->real_escape_string($token);
    assertDbReassignment($db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token, estado_changed_at)
         VALUES
            ('AUDIT FIX reasignacion', 'ninguno', NULL, DATE_ADD(CURDATE(), INTERVAL 10 DAY), '13:00:00', 2, '', 'admin', 'confirmada', '{$tokenSql}', NOW())"
    ) !== false, 'no se pudo crear el fixture de reservacion');
    $reservacionId = (int)$db->insert_id;

    $leer = static function () use ($db, &$reservacionId): array {
        $filaResult = $db->query(
            "SELECT id, fecha, hora, updated_at, created_at
             FROM reservaciones WHERE id = {$reservacionId} LIMIT 1"
        );
        assertDbReassignment($filaResult !== false, 'no se pudo leer la reservacion de prueba');
        $fila = $filaResult->fetch_assoc();
        $filaResult->free();
        assertDbReassignment(is_array($fila), 'la reservacion de prueba no existe');
        $ids = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
        return [
            'fila' => $fila,
            'mesa_ids' => $ids,
            'version' => ReservacionAsignacionVersionService::calcular(
                (string)($fila['updated_at'] ?: $fila['created_at']),
                $ids
            ),
        ];
    };

    $guardar = static function (array $mesaIds) use (&$pasos, &$leer, &$reservacionId): void {
        $antes = $leer();
        $resultado = AsignacionMesasService::asignarManual(
            $reservacionId,
            $mesaIds,
            false,
            true,
            [
                'version_esperada' => $antes['version'],
                'validar_contexto' => true,
                'contexto_completo' => true,
                'fecha_esperada' => (string)$antes['fila']['fecha'],
                'hora_esperada' => (string)$antes['fila']['hora'],
                'mesa_ids_actuales' => $antes['mesa_ids'],
            ]
        );
        assertDbReassignment(($resultado['ok'] ?? false) === true, 'fallo de reasignacion: ' . json_encode($resultado));
        $despues = $leer();
        $pasos[] = [
            'version_recibida' => $antes['version'],
            'version_nueva' => $despues['version'],
            'mesa_ids_persistidos' => $despues['mesa_ids'],
        ];
    };

    $guardar([7]);
    $guardar([7, 2]);
    $guardar([3, 4]);
    $guardar([7]);
    $guardar([7, 2]);
    $guardar([7]);

    echo json_encode(['ok' => true, 'pasos' => $pasos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($reservacionId > 0) {
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id = {$reservacionId}");
        $db->query("DELETE FROM reservaciones WHERE id = {$reservacionId}");
    }
}
