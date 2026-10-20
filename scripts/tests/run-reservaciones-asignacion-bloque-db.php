<?php

declare(strict_types=1);

/*
 * Bloque exterior y reubicación contra una base real (ddl + deploy).
 *
 * Un grupo de 10 que se da de alta con asignación automática toma las Mesas
 * 8–11 y la pareja que estaba en la 9 pasa sola al interior, en la misma
 * transacción, con su versión avanzada y su fila en la bitácora. Un segundo
 * grupo encima no despeja nada, y un plan obsoleto se deshace entero.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Model\ReservacionReubicacion;
use Services\Reservations\DisponibilidadReservacionService;
use Services\Reservations\ReservacionAdministrativaService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionReubicacionService;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar el bloque exterior.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$fecha = ReservacionConfig::ahora()->modify('+3 days')->format('Y-m-d');
$hora = '13:00:00';

// Premisa de la suite: un grupo de 10 entra en la asignación automática. Se
// fija aquí porque la base temporal la comparten todas las suites de la corrida.
$limites = new \Model\ConfiguracionReservaciones(['max_comensales_publico' => 12, 'whatsapp_reservas' => null]);
$assert($limites->guardarLimites(), 'No se pudo fijar el máximo de 12 para la prueba.');
\Services\Reservations\ReservacionAjustesService::limpiarCache();

/** Una reserva confirmada sembrada directamente en unas mesas concretas. */
$sembrar = static function (string $nombre, int $comensales, string $horaReserva, array $mesaIds) use ($db, $fecha): int {
    $token = 'fx-bloque-' . substr(md5($nombre . $horaReserva), 0, 16);
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES (?, 'email', ?, ?, ?, ?, '', 'admin', 'confirmada', ?)"
    );
    $correo = strtolower(str_replace(' ', '.', $nombre)) . '@example.test';
    $stmt->bind_param('ssssis', $nombre, $correo, $fecha, $horaReserva, $comensales, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, $mesaIds);
    return $id;
};

$mesasDe = static fn(int $id): array => (static function (array $ids): array {
    sort($ids, SORT_NUMERIC);
    return $ids;
})(ReservacionMesa::obtenerIdsPorReservacion($id));

$versionDe = static function (int $id) use ($db): string {
    $fila = $db->query("SELECT updated_at FROM reservaciones WHERE id = {$id}")->fetch_assoc();
    return (string)($fila['updated_at'] ?? '');
};

// ── 1. Un grupo de 10 despeja el bloque ──────────────────────────────
$pareja = $sembrar('Pareja En La Nueve', 2, $hora, [9]);
$versionAntes = $versionDe($pareja);
sleep(1);

$grupo = ReservacionAdministrativaService::crear([
    'nombre' => 'Grupo Exterior',
    'contacto_tipo' => 'email',
    'contacto' => 'grupo.exterior@example.test',
    'fecha' => $fecha,
    'hora' => $hora,
    'comensales' => 10,
    'asignar_automaticamente' => '1',
]);
$assert(($grupo['ok'] ?? false) === true && ($grupo['commit'] ?? true) !== false, 'El alta del grupo de 10 no se guardó: ' . json_encode($grupo));
$grupoId = (int)($grupo['id'] ?? $grupo['reservacion_id'] ?? 0);
$assert($grupoId > 0, 'El alta del grupo no devolvió su id.');
$assert($mesasDe($grupoId) === [8, 9, 10, 11], 'El grupo no tomó el exterior completo.');
$assert($mesasDe($pareja) === [1], 'La pareja de la Mesa 9 no se reubicó en la Mesa 1.');
$assert($versionDe($pareja) !== $versionAntes, 'La reubicación no avanzó la versión de la pareja.');

$bitacora = ReservacionReubicacion::ultimasPorReservacion([$pareja]);
$assert(isset($bitacora[$pareja]), 'La reubicación no quedó en la bitácora.');
$assert($bitacora[$pareja]['mesas_antes'] === [9] && $bitacora[$pareja]['mesas_despues'] === [1], 'La bitácora no registró 9 → 1.');
$assert($bitacora[$pareja]['causa_reservacion_id'] === $grupoId, 'La bitácora no apunta al grupo que causó el movimiento.');
$assert($bitacora[$pareja]['revisada'] === false, 'Una reubicación nueva nace revisada.');
$aviso = $db->query(
    "SELECT requiere_accion, cerrada_at FROM buzon_notificaciones
      WHERE tipo = 'reservacion_reubicada' AND entidad_id = " . (int)$bitacora[$pareja]['id']
)->fetch_assoc();
$assert($aviso !== null && (int)$aviso['requiere_accion'] === 0 && $aviso['cerrada_at'] === null,
    'La reubicación no dejó un aviso informativo abierto en el buzón.');
// La resolución contra la fuente salió del controlador a la bandeja del buzón,
// que es la que cuenta y lista con el mismo criterio.
$fuente = \Services\Reservations\ReservacionBuzonBandejaService::fuenteReubicacion((int)$bitacora[$pareja]['id']);
$assert(($fuente['etiqueta'] ?? '') === 'Reubicada' && str_contains((string)$fuente['descripcion'], 'Mesa 9'),
    'El buzón no presenta la reubicación con su «de… a…»: ' . json_encode($fuente));

// ── 2. Un segundo grupo encima no despeja nada ───────────────────────
$filasAntes = (int)$db->query('SELECT COUNT(*) AS n FROM reservacion_reubicaciones')->fetch_assoc()['n'];
$segundo = ReservacionAdministrativaService::crear([
    'nombre' => 'Segundo Grupo',
    'contacto_tipo' => 'email',
    'contacto' => 'segundo.grupo@example.test',
    'fecha' => $fecha,
    'hora' => $hora,
    'comensales' => 10,
    'asignar_automaticamente' => '1',
]);
// Sin mesas posibles, el alta no persiste ni ofrece «crear sin mesas»: toda
// reservación confirmada tiene mesas.
$assert(($segundo['ok'] ?? true) === false, 'Un segundo grupo de 10 en el mismo horario se guardó.');
$assert(($segundo['codigo'] ?? '') === ReservacionAdministrativaService::MESAS_REQUERIDAS, 'El segundo grupo no se rechazó por falta de mesas: ' . json_encode($segundo['codigo'] ?? null));
$sinGuardar = $db->query("SELECT COUNT(*) AS n FROM reservaciones WHERE nombre = 'Segundo Grupo'")->fetch_assoc();
$assert((int)$sinGuardar['n'] === 0, 'El intento del segundo grupo dejó una reservación guardada.');
$assert($mesasDe($grupoId) === [8, 9, 10, 11] && $mesasDe($pareja) === [1], 'El intento fallido movió mesas.');
$filasDespues = (int)$db->query('SELECT COUNT(*) AS n FROM reservacion_reubicaciones')->fetch_assoc()['n'];
$assert($filasAntes === $filasDespues, 'El intento fallido escribió en la bitácora.');

// ── 3. La landing no ofrece ese horario y propone otros ──────────────
$consulta = DisponibilidadReservacionService::consultar($fecha, 10, 0, '13:00');
$assert(($consulta['disponible'] ?? true) === false, 'La landing ofrece un bloque que ya está tomado.');
$assert(($consulta['alternativas'] ?? []) !== [], 'La landing no propone horarios alternativos.');

// ── 4. Vista previa pública: planea, no persiste ─────────────────────
$tarde = '15:00:00';
$otraPareja = $sembrar('Pareja En La Diez', 2, $tarde, [10]);
$vista = DisponibilidadReservacionService::evaluarHorario($fecha, $tarde, 10, 0, false, true);
$assert(($vista['disponible'] ?? false) === true, 'La vista previa no encontró el bloque de las 15:00.');
$assert(count($vista['reubicaciones'] ?? []) === 1, 'La vista previa no anticipó la reubicación.');
$assert($mesasDe($otraPareja) === [10], 'La vista previa movió mesas sin persistir nada.');

// ── 5. Un plan obsoleto se deshace entero ────────────────────────────
$db->begin_transaction();
$fallo = false;
try {
    ReservacionReubicacionService::aplicar([[
        'reservacion_id' => $otraPareja,
        'mesas_antes' => [11],
        'mesas_despues' => [2],
    ]], $grupoId);
} catch (RuntimeException $e) {
    $fallo = true;
}
$db->rollback();
$assert($fallo, 'Un plan con mesas de origen equivocadas se aplicó.');
$assert($mesasDe($otraPareja) === [10], 'El plan rechazado dejó cambios.');

// ── 6. «Cambiar mesas» al bloque completo, a mano ────────────────────
// Un grupo de 14 (mayor al máximo, fuera de la asignación automática) recibe
// a mano las Mesas 8–11 con una pareja dentro: primero se ENSEÑA a quién se
// mueve y se pide confirmarlo; confirmado, la pareja pasa al interior.
$noche = '17:00:00';
$parejaNoche = $sembrar('Pareja De La Noche', 2, $noche, [9]);
$grupoCatorce = $sembrar('Grupo Catorce', 14, $noche, [5, 6, 7]);
$contextoCambio = static function (int $id) use ($db, $fecha, $noche, $mesasDe): array {
    $fila = $db->query("SELECT updated_at, created_at FROM reservaciones WHERE id = {$id}")->fetch_assoc();
    return [
        'version_esperada' => \Services\Reservations\ReservacionAsignacionVersionService::calcular(
            (string)($fila['updated_at'] ?: $fila['created_at']),
            $mesasDe($id)
        ),
        'validar_contexto' => true,
        'contexto_completo' => true,
        'fecha_esperada' => $fecha,
        'hora_esperada' => $noche,
        'mesa_ids_actuales' => $mesasDe($id),
    ];
};
$sinConfirmar = \Services\Reservations\ReservacionMapaAdministrativaService::guardarAsignacion(
    $grupoCatorce,
    [8, 9, 10, 11],
    $contextoCambio($grupoCatorce)
);
$assert(($sinConfirmar['codigo'] ?? '') === \Services\Reservations\AsignacionMesasService::REUBICAR_RESERVACIONES,
    'Dar el bloque a mano no pidió confirmar la reubicación: ' . json_encode($sinConfirmar['codigo'] ?? null));
$assert(count($sinConfirmar['reubicaciones'] ?? []) === 1 && $mesasDe($parejaNoche) === [9],
    'La vista previa de la reubicación movió algo o no anticipó a la pareja.');
$decision = \Services\Reservations\ReservacionErrorCatalog::decisionesResultado($sinConfirmar);
$assert(($decision[0]['codigo'] ?? '') === 'REUBICAR_RESERVACIONES' && str_contains((string)($decision[0]['mensaje'] ?? ''), '1 reservación'),
    'La decisión de reubicar no se presenta con su conteo.');

$confirmado = \Services\Reservations\ReservacionMapaAdministrativaService::guardarAsignacion(
    $grupoCatorce,
    [8, 9, 10, 11],
    $contextoCambio($grupoCatorce) + ['confirmaciones' => ['REUBICAR_RESERVACIONES', 'CAPACIDAD_INSUFICIENTE']]
);
$assert(($confirmado['ok'] ?? false) === true, 'Confirmada la reubicación, el cambio de mesas falló: ' . json_encode($confirmado));
$assert($mesasDe($grupoCatorce) === [8, 9, 10, 11], 'El grupo de 14 no quedó en el bloque.');
$assert(!in_array(9, $mesasDe($parejaNoche), true) && $mesasDe($parejaNoche) !== [], 'La pareja no salió del bloque.');
$bitacoraNoche = ReservacionReubicacion::ultimasPorReservacion([$parejaNoche]);
$assert(($bitacoraNoche[$parejaNoche]['causa_reservacion_id'] ?? 0) === $grupoCatorce, 'La reubicación manual no quedó en la bitácora con su causa.');

fwrite(STDOUT, "run-reservaciones-asignacion-bloque-db.php: PASS\n");
