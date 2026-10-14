<?php

declare(strict_types=1);

/*
 * Contrato de la lectura del POS (fase 4A) contra una base real.
 *
 * El POS deja de tener una pantalla aparte para mirar otra hora: la misma
 * lectura responde en AHORA (hoy, sin hora) o en PROYECCIÓN (una hora elegida
 * desde el cajón, otro día, o una reserva enfocada). Aquí se fija qué contexto
 * sale en cada caso, que la proyección pinta según la hora consultada, y los
 * datos nuevos que viajan con el mapa: horario, próxima llegada y reubicación.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Model\ReservacionReubicacion;
use Services\Pos\PosReservacionQueryService;
use Services\Reservations\ReservacionConfig;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar la API del POS.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$fecha = ReservacionConfig::ahora()->modify('+4 days')->format('Y-m-d');
$hoy = ReservacionConfig::fechaActual();
$creadas = [];

$sembrar = static function (string $nombre, int $comensales, string $hora, array $mesaIds) use ($db, $fecha, &$creadas): int {
    $token = 'fx-pos-api-' . substr(md5($nombre . $hora), 0, 16);
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES (?, 'email', ?, ?, ?, ?, '', 'admin', 'confirmada', ?)"
    );
    $correo = strtolower(str_replace(' ', '.', $nombre)) . '@example.test';
    $stmt->bind_param('ssssis', $nombre, $correo, $fecha, $hora, $comensales, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, $mesaIds);
    $creadas[] = $id;
    return $id;
};

$leer = static function (string $fechaLectura, string $hora = '', array $extra = []): array {
    $lectura = PosReservacionQueryService::paraFecha($fechaLectura, $hora, $extra + [
        'superficie' => 'admin',
        'contexto_pos' => true,
    ]);
    if (!($lectura['ok'] ?? false)) {
        throw new RuntimeException('La lectura del POS falló: ' . json_encode($lectura));
    }
    return $lectura;
};

$estadoMesa = static function (array $lectura, int $mesaId): array {
    foreach ((array)$lectura['mesas_estado'] as $mesa) {
        if ((int)($mesa['id'] ?? 0) === $mesaId) {
            return $mesa;
        }
    }
    throw new RuntimeException("La lectura no trae la mesa {$mesaId}.");
};

try {
    $temprana = $sembrar('Comida Temprana', 2, '14:00:00', [2]);
    $cena = $sembrar('Cena Enfocada', 4, '19:00:00', [5]);

    // ── 1. Hoy sin hora: la operación en vivo ─────────────────────────
    $ahora = $leer($hoy);
    $assert($ahora['contexto']['modo'] === 'ahora' && $ahora['contexto']['solo_lectura'] === false,
        'Hoy sin hora no es el modo AHORA operable: ' . json_encode($ahora['contexto']));

    // ── 2. Otro día sin hora: no hay «ahora», se proyecta a la primera reserva
    $otroDia = $leer($fecha);
    $assert($otroDia['contexto']['modo'] === 'proyeccion' && $otroDia['contexto']['solo_lectura'] === true,
        'Otro día sin hora debe abrir en proyección de sólo lectura.');
    $assert($otroDia['contexto']['hora'] === '14:00:00', 'Otro día debe proyectar a la primera reserva: ' . $otroDia['contexto']['hora']);
    $assert((int)($otroDia['horario']['proxima_llegada']['reservacion_id'] ?? 0) === $temprana,
        'La próxima llegada de otro día es la primera del día.');
    $assert(in_array($otroDia['horario']['codigo'], ['abierto', 'cerrado', 'sin_configurar'], true),
        'El horario no trae un código conocido.');

    // ── 3. Proyección explícita: manda la hora consultada ─────────────
    $cena1830 = $leer($fecha, '18:30');
    $mesaCena = $estadoMesa($cena1830, 5);
    $assert($mesaCena['estado_visual_pos'] === 'reservacion-proxima' && $mesaCena['subtitulo_pos'] === '19:00',
        'A las 18:30 la mesa de la cena es azul y dice la hora: ' . json_encode([$mesaCena['estado_visual_pos'], $mesaCena['subtitulo_pos']]));
    $assert($estadoMesa($cena1830, 2)['estado_visual_pos'] === 'libre', 'La comida de las 14:00 ya no aparta su mesa a las 18:30.');
    $cena1700 = $leer($fecha, '17:00');
    $assert($estadoMesa($cena1700, 5)['estado_visual_pos'] === 'libre', 'Dos horas antes la mesa de la cena sigue libre.');

    // ── 4. Reserva enfocada sin hora: se proyecta a SU hora ───────────
    $enfocada = $leer($fecha, '', ['reservacion_enfocada_id' => $cena, 'intencion' => 'asignar']);
    $assert($enfocada['contexto']['hora'] === '19:00:00' && (int)$enfocada['contexto']['reservacion_id'] === $cena
        && $enfocada['contexto']['intencion'] === 'asignar', 'Enfocar una reserva no proyecta a su hora: ' . json_encode($enfocada['contexto']));

    // ── 5. Reubicación: insignia hasta que alguien la revisa ──────────
    ReservacionReubicacion::registrar($temprana, $cena, [9], [2]);
    $buscar = static function (array $lectura, int $id): array {
        foreach ((array)$lectura['reservaciones'] as $reservacion) {
            if ((int)($reservacion['id'] ?? 0) === $id) {
                return $reservacion;
            }
        }
        throw new RuntimeException("La lectura no trae la reserva {$id}.");
    };
    $conInsignia = $buscar($leer($fecha), $temprana);
    $assert($conInsignia['reubicada'] === true && $conInsignia['reubicacion']['mesas_antes'] === [9]
        && $conInsignia['reubicacion']['mesas_despues'] === [2], 'La reserva reubicada no trae su insignia: ' . json_encode($conInsignia['reubicacion'] ?? null));
    $assert($buscar($leer($fecha), $cena)['reubicada'] === false, 'Una reserva sin reubicación no lleva insignia.');
    ReservacionReubicacion::marcarRevisada((int)$conInsignia['reubicacion']['id'], null);
    $revisada = $buscar($leer($fecha), $temprana);
    $assert($revisada['reubicada'] === false && $revisada['reubicacion'] !== null, 'Revisada, la insignia se va y el rastro queda.');

    // ── 6. El mesero no ve el contacto, tampoco en el contexto nuevo ──
    $mesero = PosReservacionQueryService::paraFecha($fecha, '', ['superficie' => 'waiter', 'contexto_pos' => true]);
    $assert(strpos(json_encode($mesero), '@example.test') === false, 'El contacto llegó a la lectura del mesero.');

    // ── 7. Lectores que no son el POS: sin cambio de comportamiento ───
    $operacion = PosReservacionQueryService::paraFecha($fecha, '18:30', ['superficie' => 'admin']);
    $assert($operacion['contexto']['modo'] === 'ahora' && !array_key_exists('reubicada', $operacion['reservaciones'][0] ?? []),
        'Un lector que no es el POS cambió de comportamiento.');

    // ── 8. Grupo mayor al máximo con mesas elegidas a mano ────────────
    $grande = \Services\Reservations\ReservacionService::crearAdministrativa(
        \Services\Pos\PosAltaReservacionService::normalizarEntrada([
            'nombre' => 'Grupo Catorce',
            'contacto_tipo' => 'email',
            'contacto' => 'grupo.catorce@example.test',
            'fecha' => $fecha,
            'hora' => '15:00',
            'comensales' => 14,
            'mesa_ids' => [8, 9, 10, 11],
        ], true),
        null
    );
    $assert(($grande['ok'] ?? false) === true && (int)($grande['id'] ?? 0) > 0,
        'Un grupo de 14 con mesas elegidas no se creó: ' . json_encode($grande));
    $creadas[] = (int)$grande['id'];
    $mesasGrande = ReservacionMesa::obtenerIdsPorReservacion((int)$grande['id']);
    sort($mesasGrande);
    $assert($mesasGrande === [8, 9, 10, 11], 'El grupo de 14 no quedó en las mesas elegidas.');
    $filaGrande = $db->query('SELECT contacto_tipo, contacto FROM reservaciones WHERE id = ' . (int)$grande['id'])->fetch_assoc();
    $assert($filaGrande['contacto_tipo'] === 'ninguno' && $filaGrande['contacto'] === null,
        'El alta de un mesero guardó el contacto que llegó en la petición.');

    $chocan = \Services\Reservations\ReservacionService::crearAdministrativa([
        'nombre' => 'Grupo Que Choca',
        'contacto_tipo' => 'ninguno',
        'confirmaciones' => ['SIN_CONTACTO'],
        'confirmar_sin_contacto' => '1',
        'fecha' => $fecha,
        'hora' => '15:30',
        'comensales' => 10,
        'mesa_ids' => [8, 9, 10, 11],
    ], null);
    $assert(($chocan['ok'] ?? true) === false, 'Unas mesas ocupadas por otra reserva se aceptaron en el alta.');
    $huerfana = $db->query("SELECT COUNT(*) AS n FROM reservaciones WHERE nombre = 'Grupo Que Choca'")->fetch_assoc();
    $assert((int)$huerfana['n'] === 0, 'El alta rechazada dejó una reservación sin mesas.');

    // ── 9. Cancelar desde el POS guarda el motivo ─────────────────────
    $cancelacion = \Services\Pos\PuntoVentaReservacionService::cancelar($temprana, 0, 'El cliente avisó por teléfono');
    $assert(($cancelacion['ok'] ?? false) === true, 'No se pudo cancelar desde el POS: ' . json_encode($cancelacion));
    $filaCancelada = $db->query("SELECT estado, motivo_cancelacion FROM reservaciones WHERE id = {$temprana}")->fetch_assoc();
    $assert($filaCancelada['estado'] === 'cancelada' && $filaCancelada['motivo_cancelacion'] === 'El cliente avisó por teléfono',
        'La cancelación del POS no guardó su motivo.');

    // ── 10. Rutas nuevas: en el router y en la lista exacta del POS ───
    $auth = (string)file_get_contents($root . '/classes/Auth.php');
    $index = (string)file_get_contents($root . '/public/index.php');
    foreach (['disponibilidad', 'crear', 'asignar-mesas', 'reasignar', 'comentario', 'reubicaciones/revisar'] as $accion) {
        $ruta = '/api/punto-de-venta/reservaciones/' . $accion;
        $assert(str_contains($auth, "'{$ruta}'") && str_contains($index, "'{$ruta}'"),
            "La ruta {$ruta} falta en Auth::APIS_POS o en el router: sin la lista quedaría pública.");
    }
    $controlador = (string)file_get_contents($root . '/controllers/PosReservacionController.php');
    $assert(str_contains($controlador, "if (!Auth::esAdmin()) {\n            self::json(['ok' => false, 'codigo' => 'PERMISO_DENEGADO'], 403);")
        || str_contains($controlador, "'PERMISO_DENEGADO'], 403"), 'El comentario interno no está cerrado a administración en el servidor.');
} finally {
    if ($creadas !== []) {
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', array_map('intval', $creadas)) . ')');
    }
}

fwrite(STDOUT, "PASS: contexto ahora/proyección, hora consultada, reserva enfocada, horario, reubicación y saneado del mesero.\n");
