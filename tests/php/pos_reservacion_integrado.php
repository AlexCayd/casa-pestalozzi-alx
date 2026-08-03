<?php

declare(strict_types=1);

/**
 * Validacion integrada del contrato POS-reservaciones.
 *
 * Usa el bootstrap real de la aplicacion y fixtures identificables. Las
 * mutaciones de servicio se ejecutan de verdad y se limpian al terminar.
 */

$dbArgument = getenv('ETAPA4_TEST_DB_NAME') ?: '';
$allowActive = false;
foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--db=')) {
        $dbArgument = substr($argumento, 5);
    } elseif ($argumento === '--allow-active') {
        $allowActive = true;
    }
}
if ($dbArgument === '') {
    fwrite(STDERR, "Uso: php pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test [--allow-active]\n");
    exit(2);
}
if ($dbArgument === 'casa-pestalozzi' && !$allowActive) {
    fwrite(STDERR, "La base activa requiere --allow-active de forma explícita.\n");
    exit(2);
}
if ($allowActive) {
    putenv('ETAPA45_ALLOW_ACTIVE=YES');
}
putenv('ETAPA4_TEST_DB_NAME=' . $dbArgument);
require __DIR__ . '/bootstrap_etapa4.php';

use Controllers\PuntoVentaController;
use Controllers\ReservacionOperacionController;
use Model\ActiveRecord;
use Services\MesaEstadoService;
use Services\PosReservacionQueryService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
$casos = [];
$errores = [];
$marker = 'ETAPA3_5_IT_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$fixtureIds = [];
$ticketIds = [];

$registrar = static function (
    string $caso,
    bool $ok,
    string $detalle = ''
) use (&$casos, &$errores): void {
    $casos[$caso] = [
        'ok' => $ok,
        'detalle' => $detalle,
    ];
    if (!$ok) {
        $errores[] = $caso . ($detalle !== '' ? ': ' . $detalle : '');
    }
};

$ids = static function ($valor): array {
    if (is_string($valor)) {
        $valor = explode(',', $valor);
    }
    if (!is_array($valor)) {
        return [];
    }

    $resultado = array_values(array_unique(array_filter(array_map('intval', $valor))));
    sort($resultado, SORT_NUMERIC);

    return $resultado;
};

$buscarReservacion = static function (array $lectura, int $id): ?array {
    foreach ((array)($lectura['reservaciones'] ?? []) as $reservacion) {
        if ((int)($reservacion['reservacion_id'] ?? $reservacion['id'] ?? 0) === $id) {
            return $reservacion;
        }
    }

    return null;
};

$buscarMesaEstado = static function (array $lectura, int $mesaId): ?array {
    foreach ((array)($lectura['mesas_estado'] ?? []) as $mesa) {
        if ((int)($mesa['id'] ?? 0) === $mesaId) {
            return $mesa;
        }
    }

    return null;
};

$tieneModificador = static function (array $mesa, string $modificador): bool {
    return in_array($modificador, (array)($mesa['modificadores'] ?? []), true);
};

$capturarJson = static function (callable $callback, array $get): array {
    $getAnterior = $_GET;
    $serverAnterior = $_SERVER;
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try {
        $callback();
        $salida = (string)ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $getAnterior;
        $_SERVER = $serverAnterior;
    }

    $json = json_decode($salida, true);
    if (!is_array($json)) {
        throw new RuntimeException('La ruta no devolvio JSON valido: ' . substr($salida, 0, 160));
    }

    return $json;
};

$limpiar = static function () use ($db, $marker): void {
    if (!$db instanceof mysqli) {
        return;
    }

    $prefijo = $marker . '%';
    $ticketIds = [];
    $stmt = $db->prepare('SELECT id FROM tickets WHERE nombre LIKE ?');
    if ($stmt) {
        $stmt->bind_param('s', $prefijo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($fila = $resultado->fetch_assoc()) {
            $ticketIds[] = (int)$fila['id'];
        }
        $stmt->close();
    }
    if ($ticketIds !== []) {
        $lista = implode(',', array_map('intval', $ticketIds));
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$lista})");
        $db->query("DELETE FROM tickets WHERE id IN ({$lista})");
    }

    $stmt = $db->prepare('SELECT id FROM reservaciones WHERE nombre LIKE ?');
    $reservacionIds = [];
    if ($stmt) {
        $stmt->bind_param('s', $prefijo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($fila = $resultado->fetch_assoc()) {
            $reservacionIds[] = (int)$fila['id'];
        }
        $stmt->close();
    }
    if ($reservacionIds !== []) {
        $lista = implode(',', array_map('intval', $reservacionIds));
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$lista})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$lista})");
    }
};

$fixture = [];
$lecturas = [];
$resultadoMutaciones = [];
$paridad = [];
$fechaActual = '';

try {
    if (!$db instanceof mysqli) {
        throw new RuntimeException('ActiveRecord no recibio una conexion mysqli.');
    }

    $db->set_charset('utf8mb4');
    $zona = ReservacionConfig::timezone();
    $ahora = ReservacionConfig::ahora();
    $ahoraFijo = new DateTimeImmutable($ahora->format('Y-m-d H:i:00'), $zona);
    $fechaActual = $ahoraFijo->format('Y-m-d');

    $registrar(
        'bootstrap real y reloj',
        date_default_timezone_get() === 'America/Mexico_City'
            && $zona->getName() === 'America/Mexico_City',
        'timezone=' . date_default_timezone_get() . ', app_timezone=' . $zona->getName()
    );

    $mesas = [];
    $resultadoMesas = $db->query(
        "SELECT id, numero, nombre, capacidad
         FROM mesas
         WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa'
           AND NOT EXISTS (
             SELECT 1
             FROM ticket_mesas tm
             INNER JOIN tickets t ON t.id = tm.ticket_id
             WHERE tm.mesa_id = mesas.id
               AND t.estado = 'abierto'
               AND t.closed_at IS NULL
           )
         ORDER BY numero ASC, id ASC"
    );
    if (!$resultadoMesas) {
        throw new RuntimeException('No fue posible consultar mesas: ' . $db->error);
    }
    while ($mesa = $resultadoMesas->fetch_assoc()) {
        $mesas[] = $mesa;
    }
    $resultadoMesas->free();
    if (count($mesas) < 9) {
        throw new RuntimeException(
            'La base de pruebas necesita al menos 9 mesas reservables activas libres; hay ' . count($mesas) . '.'
        );
    }
    $usuarioResultado = $db->query(
        "SELECT id FROM usuarios
         WHERE activo = 1 AND rol IN ('admin', 'waiter', 'cashier')
         ORDER BY id ASC LIMIT 1"
    );
    $usuarioFila = $usuarioResultado ? $usuarioResultado->fetch_assoc() : null;
    if ($usuarioResultado) {
        $usuarioResultado->free();
    }
    $usuarioId = (int)($usuarioFila['id'] ?? 0);
    if ($usuarioId < 1) {
        throw new RuntimeException('La base de pruebas necesita un usuario activo para auditar mutaciones.');
    }
    $mesaIds = array_map(static fn(array $mesa): int => (int)$mesa['id'], $mesas);
    $mesa = static fn(int $indice): int => $mesaIds[$indice];

    $insertarReservacion = static function (
        mysqli $db,
        string $nombre,
        DateTimeImmutable $inicio,
        string $estado,
        int $comensales,
        string $nota
    ): int {
        $stmt = $db->prepare(
            "INSERT INTO reservaciones
                (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
                 origen, estado)
             VALUES (?, 'email', ?, ?, ?, ?, ?, 'admin', ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar fixture de reservacion: ' . $db->error);
        }
        $contacto = strtolower($nombre) . '@example.invalid';
        $fecha = $inicio->format('Y-m-d');
        $hora = $inicio->format('H:i:00');
        $stmt->bind_param('ssssiss', $nombre, $contacto, $fecha, $hora, $comensales, $nota, $estado);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new RuntimeException($mensaje);
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    };

    $asignarMesas = static function (mysqli $db, int $reservacionId, array $ids): void {
        $stmt = $db->prepare(
            'INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar fixture de mesas: ' . $db->error);
        }
        $orden = 1;
        foreach ($ids as $mesaId) {
            $mesaId = (int)$mesaId;
            $stmt->bind_param('iii', $reservacionId, $mesaId, $orden);
            if (!$stmt->execute()) {
                $mensaje = $stmt->error;
                $stmt->close();
                throw new RuntimeException($mensaje);
            }
            $orden++;
        }
        $stmt->close();
    };

    $insertarTicket = static function (
        mysqli $db,
        string $nombre,
        DateTimeImmutable $apertura,
        ?int $reservacionId,
        array $ids
    ): int {
        $stmt = $db->prepare(
            "INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id)
             VALUES (2, ?, ?, 'abierto', ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar fixture de ticket: ' . $db->error);
        }
        $horaApertura = $apertura->format('Y-m-d H:i:00');
        $stmt->bind_param('ssi', $nombre, $horaApertura, $reservacionId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new RuntimeException($mensaje);
        }
        $ticketId = (int)$stmt->insert_id;
        $stmt->close();

        $mesaStmt = $db->prepare(
            'INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES (?, ?, ?)'
        );
        if (!$mesaStmt) {
            throw new RuntimeException('No fue posible preparar mesas del ticket: ' . $db->error);
        }
        $orden = 1;
        foreach ($ids as $mesaId) {
            $mesaId = (int)$mesaId;
            $mesaStmt->bind_param('iii', $ticketId, $mesaId, $orden);
            if (!$mesaStmt->execute()) {
                $mensaje = $mesaStmt->error;
                $mesaStmt->close();
                throw new RuntimeException($mensaje);
            }
            $orden++;
        }
        $mesaStmt->close();

        return $ticketId;
    };

    $crear = static function (
        string $codigo,
        DateTimeImmutable $inicio,
        string $estado,
        int $comensales,
        array $asignacion,
        string $nota = ''
    ) use (&$fixture, $db, $marker, $insertarReservacion, $asignarMesas): int {
        $nombre = $marker . ' ' . $codigo;
        $id = $insertarReservacion($db, $nombre, $inicio, $estado, $comensales, $nota ?: $codigo);
        if ($asignacion !== []) {
            $asignarMesas($db, $id, $asignacion);
        }
        $fixture[$codigo] = [
            'id' => $id,
            'inicio' => $inicio,
            'fecha' => $inicio->format('Y-m-d'),
            'hora' => $inicio->format('H:i:00'),
            'estado_inicial' => $estado,
            'mesa_ids' => array_values(array_map('intval', $asignacion)),
        ];

        return $id;
    };

    $r1 = $crear('R1_FUTURA', $ahoraFijo->modify('+120 minutes'), 'confirmada', 2, [$mesa(0)]);
    $r2 = $crear('R2_ADVERTENCIA', $ahoraFijo->modify('+45 minutes'), 'confirmada', 2, [$mesa(1)]);
    $r3 = $crear('R3_INICIO_MULTIMESA', $ahoraFijo->modify('+10 minutes'), 'confirmada', 4, [$mesa(2), $mesa(3)]);
    $r4 = $crear('R4_TOLERANCIA', $ahoraFijo->modify('-5 minutes'), 'confirmada', 2, [$mesa(4)]);
    $r5 = $crear('R5_VENCIDA', $ahoraFijo->modify('-20 minutes'), 'confirmada', 2, [$mesa(5)]);
    $r6 = $crear('R6_EN_CURSO_DIFERENCIA', $ahoraFijo->modify('-45 minutes'), 'en_curso', 4, [$mesa(6), $mesa(7)]);
    $r8 = $crear('R8_SIN_MESAS', $ahoraFijo->modify('+90 minutes'), 'confirmada', 12, []);
    $r11 = $crear('R11_FUTURA_MISMA_MESA', $ahoraFijo->modify('+40 minutes'), 'confirmada', 2, [$mesa(1)]);
    $fixtureIds = array_column($fixture, 'id');

    $ticketR6 = $insertarTicket($db, $marker . ' T9_EN_CURSO_MULTIMESA', $fixture['R6_EN_CURSO_DIFERENCIA']['inicio'], $r6, [$mesa(0), $mesa(8)]);
    $ticketR10 = $insertarTicket($db, $marker . ' T10_WALKIN_ADVERTENCIA', $fixture['R2_ADVERTENCIA']['inicio'], null, [$mesa(1)]);
    $ticketIds = [$ticketR6, $ticketR10];
    $fixture['R6_EN_CURSO_DIFERENCIA']['ticket_id'] = $ticketR6;
    $fixture['R6_EN_CURSO_DIFERENCIA']['ticket_mesa_ids'] = [$mesa(0), $mesa(8)];
    $fixture['R10_TICKET_PROXIMO'] = [
        'ticket_id' => $ticketR10,
        'mesa_ids' => [$mesa(1)],
        'fecha' => $fixture['R2_ADVERTENCIA']['fecha'],
    ];

    $registrar('fixtures insertados', count($fixture) === 9 && count($ticketIds) === 2, 'marker=' . $marker);

    $fechas = array_values(array_unique(array_column($fixture, 'fecha')));
    foreach ($fechas as $fecha) {
        $lecturas[$fecha] = PosReservacionQueryService::paraFecha(
            $fecha,
            '',
            [
                'incluir_inactivas' => true,
                'calcular_conflictos' => true,
                'ahora' => $ahoraFijo,
            ]
        );
        if (!($lecturas[$fecha]['ok'] ?? false)) {
            throw new RuntimeException('Lectura integrada invalida para ' . $fecha);
        }
    }

    $esperadas = [
        'R1_FUTURA' => ['ventana' => 'futura', 'advertencia' => false, 'bloqueo' => false, 'inicio' => false, 'ausencia' => false],
        'R2_ADVERTENCIA' => ['ventana' => '30_60', 'advertencia' => true, 'bloqueo' => false, 'inicio' => false, 'ausencia' => false],
        'R3_INICIO_MULTIMESA' => ['ventana' => '0_30', 'advertencia' => false, 'bloqueo' => true, 'inicio' => true, 'ausencia' => false],
        'R4_TOLERANCIA' => ['ventana' => 'tolerancia', 'advertencia' => false, 'bloqueo' => true, 'inicio' => true, 'ausencia' => false],
        'R5_VENCIDA' => ['ventana' => 'tolerancia_vencida', 'advertencia' => false, 'bloqueo' => true, 'inicio' => true, 'ausencia' => true],
        'R6_EN_CURSO_DIFERENCIA' => ['ventana' => 'en_curso', 'advertencia' => false, 'bloqueo' => false, 'inicio' => false, 'ausencia' => false],
        'R8_SIN_MESAS' => ['ventana' => 'futura', 'advertencia' => false, 'bloqueo' => false, 'inicio' => false, 'ausencia' => false],
        'R11_FUTURA_MISMA_MESA' => ['ventana' => '30_60', 'advertencia' => true, 'bloqueo' => false, 'inicio' => false, 'ausencia' => false],
    ];

    foreach ($esperadas as $codigo => $esperada) {
        $reservacion = $buscarReservacion($lecturas[$fixture[$codigo]['fecha']], (int)$fixture[$codigo]['id']);
        $ok = $reservacion !== null
            && ($reservacion['schema_version'] ?? '') === 'pos-reservacion.v1'
            && (int)$reservacion['reservacion_id'] === (int)$reservacion['id']
            && ($reservacion['ventana_operativa'] ?? '') === $esperada['ventana']
            && (bool)$reservacion['muestra_advertencia'] === $esperada['advertencia']
            && (bool)$reservacion['bloquea_walk_ins'] === $esperada['bloqueo']
            && (bool)$reservacion['puede_iniciar_servicio'] === $esperada['inicio']
            && (bool)$reservacion['puede_registrar_ausencia'] === $esperada['ausencia']
            && array_key_exists('server_time', $lecturas[$fixture[$codigo]['fecha']])
            && ($lecturas[$fixture[$codigo]['fecha']]['timezone'] ?? '') === 'America/Mexico_City';
        $registrar('contrato ' . $codigo, $ok, $ok ? '' : json_encode($reservacion, JSON_UNESCAPED_UNICODE));
    }

    $r8Data = $buscarReservacion($lecturas[$fixture['R8_SIN_MESAS']['fecha']], $r8);
    $registrar(
        'R8 sin mesas y motivo',
        $r8Data !== null
            && $r8Data['mesa_ids'] === []
            && $r8Data['mesas'] === []
            && $r8Data['ticket_id'] === null
            && $r8Data['motivo_operativo'] === 'sin_mesas',
        $r8Data['motivo_operativo'] ?? 'ausente'
    );

    $r6Data = $buscarReservacion($lecturas[$fixture['R6_EN_CURSO_DIFERENCIA']['fecha']], $r6);
    $r6MesaIds = $ids($r6Data['mesa_ids'] ?? []);
    $r6TicketMesaIds = $ids($r6Data['ticket_mesa_ids'] ?? []);
    $registrar(
        'R6 separacion mesa_ids/ticket_mesa_ids',
        $r6Data !== null
            && $r6Data['ticket_abierto'] === true
            && $r6Data['ticket_id'] === $ticketR6
            && $r6MesaIds === [$mesa(6), $mesa(7)]
            && $r6TicketMesaIds === [$mesa(0), $mesa(8)]
            && $r6MesaIds !== $r6TicketMesaIds,
        json_encode(['mesa_ids' => $r6MesaIds, 'ticket_mesa_ids' => $r6TicketMesaIds])
    );

    $r3Data = $buscarReservacion($lecturas[$fixture['R3_INICIO_MULTIMESA']['fecha']], $r3);
    $registrar(
        'R7 asignacion multimesa estable',
        $r3Data !== null
            && $ids($r3Data['mesa_ids'] ?? []) === [$mesa(2), $mesa(3)]
            && array_map(static fn(array $m): int => (int)$m['id'], $r3Data['mesas'] ?? []) === [$mesa(2), $mesa(3)]
            && count($r3Data['mesas'] ?? []) === 2,
        json_encode(['mesa_ids' => $r3Data['mesa_ids'] ?? null, 'mesas' => $r3Data['mesas'] ?? null])
    );

    $warningRead = $lecturas[$fixture['R2_ADVERTENCIA']['fecha']];
    $warningTicket = null;
    foreach ((array)$warningRead['tickets'] as $ticket) {
        if ((int)($ticket['id'] ?? 0) === $ticketR10) {
            $warningTicket = $ticket;
            break;
        }
    }
    $warningState = $buscarMesaEstado($warningRead, $mesa(1));
    $warningIds = array_map(
        static fn(array $r): int => (int)($r['reservacion_id'] ?? 0),
        (array)($warningTicket['reservaciones_proximas'] ?? [])
    );
    $registrar(
        'R10 ticket rojo con advertencias azules',
        $warningTicket !== null
            && $warningTicket['mesa_ids'] === [$mesa(1)]
            && in_array($r2, $warningIds, true)
            && in_array($r11, $warningIds, true)
            && $warningState !== null
            && ($warningState['estado_visual'] ?? '') === 'ocupada'
            && $tieneModificador($warningState, 'ticket_abierto')
            && $tieneModificador($warningState, 'reservacion_advertencia'),
        json_encode(['ticket' => $warningTicket, 'mesa' => $warningState], JSON_UNESCAPED_UNICODE)
    );

    $r3State = $buscarMesaEstado($lecturas[$fixture['R3_INICIO_MULTIMESA']['fecha']], $mesa(2));
    $r4State = $buscarMesaEstado($lecturas[$fixture['R4_TOLERANCIA']['fecha']], $mesa(4));
    $registrar(
        'R3/R4 estados visuales canonicos',
        $r3State !== null
            && ($r3State['estado_visual'] ?? '') === 'reservacion-proxima'
            && $tieneModificador($r3State, 'reservacion_inminente')
            && $r4State !== null
            && ($r4State['estado_visual'] ?? '') === 'reservacion-proxima'
            && $tieneModificador($r4State, 'reservacion_tolerancia'),
        json_encode(['r3' => $r3State, 'r4' => $r4State], JSON_UNESCAPED_UNICODE)
    );

    $inicioMulti = PuntoVentaReservacionService::comenzar($r3, $usuarioId, null);
    $inicioSingle = PuntoVentaReservacionService::comenzar($r4, $usuarioId, null);
    $resultadoMutaciones['inicio_multimesa'] = $inicioMulti;
    $resultadoMutaciones['inicio_una_mesa'] = $inicioSingle;
    $registrar(
        'inicio de servicio una y varias mesas',
        ($inicioMulti['ok'] ?? false)
            && ($inicioSingle['ok'] ?? false)
            && count((array)($inicioMulti['mesa_ids'] ?? [])) === 2
            && count((array)($inicioSingle['mesa_ids'] ?? [])) === 1,
        json_encode(['multi' => $inicioMulti, 'single' => $inicioSingle], JSON_UNESCAPED_UNICODE)
    );

    $repeatStart = PuntoVentaReservacionService::comenzar($r3, $usuarioId, null);
    $resultadoMutaciones['inicio_repetido'] = $repeatStart;
    $registrar(
        'inicio repetido idempotente',
        ($repeatStart['ok'] ?? false) && ($repeatStart['idempotente'] ?? false) === true
            && (int)($repeatStart['ticket_id'] ?? 0) === (int)($inicioMulti['ticket_id'] ?? -1),
        json_encode($repeatStart, JSON_UNESCAPED_UNICODE)
    );

    $noShow = PuntoVentaReservacionService::noShow($r5, $usuarioId, false, false, 'Fixture Etapa 3.5');
    $repeatNoShow = PuntoVentaReservacionService::noShow($r5, $usuarioId, false, false, 'Fixture Etapa 3.5');
    $resultadoMutaciones['no_show'] = $noShow;
    $resultadoMutaciones['no_show_repetido'] = $repeatNoShow;
    $registrar(
        'ausencia despues de tolerancia e idempotencia',
        ($noShow['ok'] ?? false)
            && ($repeatNoShow['ok'] ?? false)
            && ($repeatNoShow['idempotente'] ?? false) === true,
        json_encode(['primera' => $noShow, 'segunda' => $repeatNoShow], JSON_UNESCAPED_UNICODE)
    );

    $walkInDatos = [
        'mesa_ids' => [$mesa(7)],
        'comensales' => 2,
        'nombre' => $marker . ' T_CONCURRENCIA_WALKIN',
    ];
    $walkInPrimero = PuntoVentaReservacionService::abrirWalkIn($walkInDatos, $usuarioId);
    $walkInSegundo = PuntoVentaReservacionService::abrirWalkIn($walkInDatos, $usuarioId);
    $resultadoMutaciones['apertura_concurrente_simulada'] = [
        'primera' => $walkInPrimero,
        'segunda' => $walkInSegundo,
    ];
    if (!empty($walkInPrimero['ticket_id'])) {
        $ticketIds[] = (int)$walkInPrimero['ticket_id'];
    }
    $registrar(
        'conflicto de apertura sobre misma mesa',
        ($walkInPrimero['ok'] ?? false)
            && !($walkInSegundo['ok'] ?? false)
            && in_array(
                (string)($walkInSegundo['codigo'] ?? ''),
                [PuntoVentaReservacionService::MESA_OCUPADA, PuntoVentaReservacionService::TICKET_ABIERTO, PuntoVentaReservacionService::CONFLICTO_CONCURRENTE],
                true
            ),
        json_encode(['primera' => $walkInPrimero, 'segunda' => $walkInSegundo], JSON_UNESCAPED_UNICODE)
    );
    if (!empty($walkInPrimero['ticket_id'])) {
        $resultadoMutaciones['cierre_walkin_concurrencia'] = PuntoVentaReservacionService::cerrarTicket(
            (int)$walkInPrimero['ticket_id'],
            'efectivo',
            0.0,
            [],
            $usuarioId
        );
    }

    $ticketMulti = (int)($inicioMulti['ticket_id'] ?? 0);
    $ticketSingle = (int)($inicioSingle['ticket_id'] ?? 0);
    $cierreMulti = PuntoVentaReservacionService::cerrarTicket($ticketMulti, 'efectivo', 0.0, [], $usuarioId);
    $cierreSingle = PuntoVentaReservacionService::cerrarTicket($ticketSingle, 'efectivo', 0.0, [], $usuarioId);
    $resultadoMutaciones['cierre_multimesa'] = $cierreMulti;
    $resultadoMutaciones['cierre_una_mesa'] = $cierreSingle;
    $registrar(
        'cierre de ticket una y varias mesas',
        ($cierreMulti['ok'] ?? false) && ($cierreSingle['ok'] ?? false),
        json_encode(['multi' => $cierreMulti, 'single' => $cierreSingle], JSON_UNESCAPED_UNICODE)
    );

    $postMutacion = PosReservacionQueryService::paraFecha(
        $fechaActual,
        '',
        ['incluir_inactivas' => true, 'calcular_conflictos' => true, 'ahora' => ReservacionConfig::ahora()]
    );
    $r3Post = $buscarReservacion($postMutacion, $r3);
    $r4Post = $buscarReservacion($postMutacion, $r4);
    $r5Post = $buscarReservacion($postMutacion, $r5);
    $r6Post = $buscarReservacion($postMutacion, $r6);
    $registrar(
        'estados y ocupacion despues de mutaciones',
        ($r3Post['estado'] ?? '') === 'completada'
            && ($r4Post['estado'] ?? '') === 'completada'
            && ($r5Post['estado'] ?? '') === 'no_show'
            && ($r6Post['estado'] ?? '') === 'en_curso'
            && ($r6Post['ticket_abierto'] ?? false) === true,
        json_encode(['r3' => $r3Post, 'r4' => $r4Post, 'r5' => $r5Post, 'r6' => $r6Post], JSON_UNESCAPED_UNICODE)
    );

    $ticketHistory = $db->query(
        "SELECT ticket_id, COUNT(*) AS mesas
         FROM ticket_mesas
         WHERE ticket_id IN (" . implode(',', [$ticketMulti, $ticketSingle]) . ")
         GROUP BY ticket_id"
    );
    $historyCounts = [];
    if ($ticketHistory) {
        while ($fila = $ticketHistory->fetch_assoc()) {
            $historyCounts[(int)$fila['ticket_id']] = (int)$fila['mesas'];
        }
        $ticketHistory->free();
    }
    $registrar(
        'ticket_mesas conserva historial sin doble ocupacion',
        ($historyCounts[$ticketMulti] ?? 0) === 2
            && ($historyCounts[$ticketSingle] ?? 0) === 1,
        json_encode($historyCounts)
    );

    $router = new \MVC\Router();

    $posMapa = $capturarJson(static function (): void {
        global $router;
        PuntoVentaController::api($router);
    }, ['fecha' => $fechaActual]);
    $posReservaciones = $capturarJson(static function (): void {
        global $router;
        PuntoVentaController::reservaciones($router);
    }, ['fecha' => $fechaActual]);
    $operacion = $capturarJson(static function (): void {
        ReservacionOperacionController::operationData();
    }, ['fecha' => $fechaActual, 'hora' => '']);

    $campos = [
        'reservacion_id', 'estado', 'ventana_operativa', 'minutos_para_reservacion',
        'minutos_retraso', 'mesa_ids', 'ticket_id', 'ticket_abierto', 'ticket_mesa_ids',
        'puede_iniciar_servicio', 'puede_registrar_ausencia', 'bloquea_walk_ins',
        'muestra_advertencia', 'influye_disponibilidad', 'motivo_operativo',
    ];
    $posPorId = [];
    foreach ((array)($posMapa['reservaciones'] ?? []) as $reservacion) {
        $posPorId[(int)$reservacion['reservacion_id']] = $reservacion;
    }
    $opPorId = [];
    foreach ((array)($operacion['reservaciones'] ?? []) as $reservacion) {
        $opPorId[(int)$reservacion['reservacion_id']] = $reservacion;
    }
    $paridadFallos = [];
    foreach ($posPorId as $id => $posReservacion) {
        if (!isset($opPorId[$id])) {
            $paridadFallos[] = "operation missing {$id}";
            continue;
        }
        foreach ($campos as $campo) {
            if (($posReservacion[$campo] ?? null) !== ($opPorId[$id][$campo] ?? null)) {
                $paridadFallos[] = "{$id}.{$campo}";
            }
        }
    }
    foreach ((array)($posReservaciones['reservaciones'] ?? []) as $reservacion) {
        $id = (int)($reservacion['reservacion_id'] ?? 0);
        if (!isset($posPorId[$id])) {
            $paridadFallos[] = "pos-reservaciones extra {$id}";
        } else {
            foreach ($campos as $campo) {
                if (($reservacion[$campo] ?? null) !== ($posPorId[$id][$campo] ?? null)) {
                    $paridadFallos[] = "pos-reservaciones {$id}.{$campo}";
                }
            }
        }
    }
    $paridad = [
        'pos_mapa' => ['ok' => ($posMapa['ok'] ?? false) === true, 'count' => count($posPorId)],
        'pos_reservaciones' => ['ok' => ($posReservaciones['ok'] ?? false) === true, 'count' => count($posReservaciones['reservaciones'] ?? [])],
        'admin_operation' => ['ok' => ($operacion['ok'] ?? false) === true, 'count' => count($opPorId)],
        'fallos' => $paridadFallos,
    ];
    $registrar('paridad contractual entre tres rutas', $paridadFallos === [] && $paridad['pos_mapa']['ok'] && $paridad['pos_reservaciones']['ok'] && $paridad['admin_operation']['ok'], json_encode($paridad, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $registrar('ejecucion integrada', false, $e->getMessage());
} finally {
    try {
        $limpiar();
    } catch (Throwable $e) {
        $registrar('limpieza de fixtures', false, $e->getMessage());
    }
}

echo json_encode([
    'ok' => $errores === [],
    'marker' => $marker,
    'fecha_actual_fixture' => $fechaActual,
    'casos' => $casos,
    'mutaciones' => $resultadoMutaciones,
    'paridad' => $paridad,
    'errores' => $errores,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errores === [] ? 0 : 1);
