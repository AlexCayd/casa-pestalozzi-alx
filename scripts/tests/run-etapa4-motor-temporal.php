<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Services\MesaEstadoService;
use Services\OcupacionMesasService;
use Services\PosReservacionQueryService;
use Services\PosReservacionSerializer;
use Services\ReservacionConfig;
use Services\ReservacionVigenciaService;
use Services\TicketTemporalService;

$fallos = [];
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

$tz = ReservacionConfig::timezone();
$fecha = '2026-08-05';
$ahoraProyeccion = new DateTimeImmutable($fecha . ' 09:30:00', $tz);
$ahoraActual = new DateTimeImmutable($fecha . ' 11:00:00', $tz);

$ticket = static function (
    int $id,
    string $hora,
    string $estado = 'abierto',
    ?string $closedAt = null,
    array $mesaIds = [7]
): array {
    return [
        'id' => $id,
        'estado' => $estado,
        'closed_at' => $closedAt,
        'hora_apertura' => $hora,
        'reservacion_id' => null,
        'mesa_ids' => $mesaIds,
    ];
};

$mesa = [
    'id' => 7,
    'numero' => 7,
    'nombre' => 'Mesa 7',
    'tipo' => 'mesa',
    'capacidad' => 4,
    'pos_x' => 50,
    'pos_y' => 50,
    'activo' => true,
    'reservable' => true,
];
$mesaContrato = [PosReservacionSerializer::mesa($mesa)];

$liberacion = TicketTemporalService::calcularLiberacionEstimadaTicket($fecha . ' 09:00:00');
$afirmar(
    $liberacion?->format('Y-m-d H:i:s') === $fecha . ' 10:30:00',
    'La fórmula canónica debe ser apertura + 90 minutos + retraso canónico.'
);

$t1 = TicketTemporalService::proyectar(
    $ticket(1, $fecha . ' 09:00:00'),
    $fecha,
    '11:00:00',
    $ahoraActual
);
$afirmar($t1['bloquea_en_consulta'] === true, 'T1: el bloque actual conserva el ticket abierto como bloqueo.');
$afirmar($t1['ocupada_fisicamente'] === true, 'T1: la ocupación física debe conservarse.');

$t2 = TicketTemporalService::proyectar(
    $ticket(2, $fecha . ' 09:00:00'),
    $fecha,
    '10:00:00',
    $ahoraProyeccion
);
$afirmar($t2['es_proyeccion'] === true && $t2['bloquea_en_consulta'] === true, 'T2: la proyección anterior a la liberación bloquea.');

$t3 = TicketTemporalService::proyectar(
    $ticket(3, $fecha . ' 09:00:00'),
    $fecha,
    '10:30:00',
    $ahoraProyeccion
);
$afirmar($t3['bloquea_en_consulta'] === false, 'T3: el límite exacto de liberación no bloquea.');

$t4 = TicketTemporalService::proyectar(
    $ticket(4, $fecha . ' 09:00:00'),
    $fecha,
    '11:00:00',
    $ahoraProyeccion
);
$afirmar($t4['disponible_proyectada'] === true, 'T4: el bloque posterior a la liberación queda disponible.');

$mañana = TicketTemporalService::proyectar(
    $ticket(5, $fecha . ' 09:00:00'),
    '2026-08-06',
    '10:00:00',
    $ahoraProyeccion
);
$afirmar($mañana['aplica_fecha'] === false && $mañana['bloquea_en_consulta'] === false, 'T6: un ticket actual no bloquea una fecha futura.');

$cerrado = TicketTemporalService::proyectar(
    $ticket(6, $fecha . ' 09:00:00', 'cerrado'),
    $fecha,
    '10:00:00',
    $ahoraProyeccion
);
$cerradoConMarca = TicketTemporalService::proyectar(
    $ticket(7, $fecha . ' 09:00:00', 'abierto', $fecha . ' 10:00:00'),
    $fecha,
    '10:00:00',
    $ahoraProyeccion
);
$afirmar($cerrado['ticket_abierto'] === false && $cerradoConMarca['ticket_abierto'] === false, 'T7: estado o closed_at excluyen el ticket abierto.');

$multi = OcupacionMesasService::evaluarTickets(
    [$ticket(8, $fecha . ' 09:00:00', 'abierto', null, [7, 8])],
    $fecha,
    '10:00:00',
    $ahoraProyeccion
);
$afirmar(count($multi['por_mesa']) === 2, 'T8: un ticket multimesa debe proyectarse en todas sus mesas.');
$afirmar(
    count(array_unique(array_map(
        static fn(array $estado): string => (string)$estado['liberacion_estimada'],
        $multi['por_mesa']
    ))) === 1,
    'T8: todas las mesas del ticket comparten la misma liberación estimada.'
);

$reservacion = [
    'id' => 45,
    'estado' => 'confirmada',
    'fecha' => $fecha,
    'hora' => '15:00:00',
    'nombre' => 'Cliente de prueba',
    'comensales' => 2,
    'mesa_ids' => [7],
];
$r1 = ReservacionVigenciaService::clasificar($reservacion, new DateTimeImmutable($fecha . ' 14:00:00', $tz));
$r2 = ReservacionVigenciaService::clasificar($reservacion, new DateTimeImmutable($fecha . ' 15:15:00', $tz));
$r3 = ReservacionVigenciaService::clasificar($reservacion, new DateTimeImmutable($fecha . ' 15:15:01', $tz));
$r6 = ReservacionVigenciaService::clasificar(
    $reservacion,
    new DateTimeImmutable($fecha . ' 15:30:00', $tz),
    $ticket(9, $fecha . ' 15:00:00')
);

$afirmar($r1['influye_disponibilidad'] === true && $r1['ausencia_pendiente'] === false, 'R1: una reservación futura conserva disponibilidad.');
$afirmar(
    $r2['dentro_tolerancia'] === true
        && $r2['tolerancia_vencida'] === false
        && $r2['influye_disponibilidad'] === true,
    'R2: el límite de tolerancia es inclusivo.'
);
$afirmar(
    $r3['tolerancia_vencida'] === true
        && $r3['ausencia_pendiente'] === true
        && $r3['influye_disponibilidad'] === false
        && $r3['puede_iniciar'] === false
        && $r3['puede_marcar_no_show'] === true,
    'R3: la tolerancia vencida libera disponibilidad y habilita sólo la ausencia.'
);
$afirmar(
    $r6['ausencia_pendiente'] === false && $r6['puede_marcar_no_show'] === false,
    'R6: un ticket abierto vinculado elimina la ausencia pendiente.'
);

$serialAusencia = PosReservacionSerializer::reservacion(
    $reservacion,
    null,
    $mesaContrato,
    new DateTimeImmutable($fecha . ' 15:15:01', $tz)
);
$evaluacionVacia = OcupacionMesasService::evaluarTickets([], $fecha, '15:15:01', new DateTimeImmutable($fecha . ' 15:15:01', $tz));
$mesasAusencia = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$serialAusencia],
    [],
    $fecha,
    new DateTimeImmutable($fecha . ' 15:15:01', $tz),
    '15:15:01',
    [
        'contexto' => $evaluacionVacia['contexto'],
        'tickets_por_mesa' => [],
        'mesas' => [],
    ]
);
$estadoAusencia = $mesasAusencia[0] ?? [];
$afirmar(
    ($estadoAusencia['estado_base'] ?? null) === MesaEstadoService::DISPONIBLE
        && in_array('AUSENCIA_PENDIENTE', $estadoAusencia['modificadores'] ?? [], true)
        && ($estadoAusencia['bloquea'] ?? true) === false,
    'R4: una ausencia pendiente deja la mesa verde con indicador gris.'
);
$afirmar(
    ($estadoAusencia['acciones'][0]['id'] ?? null) === 'REGISTRAR_AUSENCIA'
        && ($serialAusencia['puede_iniciar'] ?? true) === false,
    'R4: la acción canónica es registrar ausencia y no iniciar servicio.'
);

$ticketActual = $ticket(10, $fecha . ' 15:00:00');
$evaluacionTicketActual = OcupacionMesasService::evaluarTickets(
    [$ticketActual],
    $fecha,
    '15:15:01',
    new DateTimeImmutable($fecha . ' 15:15:01', $tz)
);
$mesasConTicket = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$serialAusencia],
    [$ticketActual],
    $fecha,
    new DateTimeImmutable($fecha . ' 15:15:01', $tz),
    '15:15:01',
    [
        'contexto' => $evaluacionTicketActual['contexto'],
        'tickets_por_mesa' => $evaluacionTicketActual['por_mesa'],
        'mesas' => [],
    ]
);
$afirmar(
    ($mesasConTicket[0]['estado_base'] ?? null) === MesaEstadoService::OCUPADA
        && ($mesasConTicket[0]['ticket_abierto']['bloquea_en_consulta'] ?? false) === true,
    'R5: un ticket abierto distinto conserva prioridad roja sobre la ausencia.'
);

$evaluacionTicketProyectado = OcupacionMesasService::evaluarTickets(
    [$ticket(11, $fecha . ' 09:00:00')],
    $fecha,
    '11:00:00',
    $ahoraProyeccion
);
$mesasProyectadas = MesaEstadoService::normalizarMesas(
    [$mesa],
    [],
    [$ticket(11, $fecha . ' 09:00:00')],
    $fecha,
    $ahoraProyeccion,
    '11:00:00',
    [
        'contexto' => $evaluacionTicketProyectado['contexto'],
        'tickets_por_mesa' => $evaluacionTicketProyectado['por_mesa'],
        'mesas' => [],
    ]
);
$estadoProyectado = $mesasProyectadas[0] ?? [];
$afirmar(
    ($estadoProyectado['estado_base'] ?? null) === MesaEstadoService::DISPONIBLE
        && ($estadoProyectado['ticket_abierto']['bloquea_en_consulta'] ?? true) === false
        && ($estadoProyectado['ocupacion_actual'] ?? false) === true,
    'T4: MesaEstadoService no fuerza rojo por la mera existencia de un ticket proyectado.'
);

$evaluacionFutura = OcupacionMesasService::evaluarTickets(
    [$ticket(12, $fecha . ' 09:00:00')],
    '2026-08-06',
    '11:00:00',
    $ahoraProyeccion
);
$mesasFuturas = MesaEstadoService::normalizarMesas(
    [$mesa],
    [],
    [$ticket(12, $fecha . ' 09:00:00')],
    '2026-08-06',
    $ahoraProyeccion,
    '11:00:00',
    [
        'contexto' => $evaluacionFutura['contexto'],
        'tickets_por_mesa' => $evaluacionFutura['por_mesa'],
        'mesas' => [],
    ]
);
$afirmar(
    ($mesasFuturas[0]['estado_base'] ?? null) === MesaEstadoService::DISPONIBLE
        && ($mesasFuturas[0]['ticket_abierto'] ?? null) === null,
    'T6: MesaEstadoService ignora tickets actuales en una fecha futura.'
);

$sqlVigencia = ReservacionVigenciaService::condicionSqlInfluyeDisponibilidad(
    'r',
    new DateTimeImmutable($fecha . ' 15:15:01', $tz)
);
$afirmar(str_contains($sqlVigencia, 'TIMESTAMPADD'), 'La condición SQL debe incluir la tolerancia canónica.');
$afirmar(str_contains($sqlVigencia, 'closed_at IS NULL'), 'La condición SQL debe exigir ticket no cerrado.');

if (in_array('--dynamic', $argv, true) && $fallos === []) {
    $fallos = array_merge($fallos, ejecutarFixturesDinamicos($fecha));
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, '- ' . $fallo . "\n");
    }
    exit(1);
}

echo "PASS: motor temporal Etapa 4; tickets, proyección, tolerancia y ausencia pendiente correctos.\n";
echo in_array('--dynamic', $argv, true)
    ? "INFO: fixtures dinámicos ejecutados y eliminados en una base temporal protegida.\n"
    : "INFO: fixtures ejecutados en memoria; no se modificó ninguna base de datos.\n";

/**
 * Ejecuta sólo cuando se solicita explícitamente --dynamic. Nunca selecciona
 * DB_NAME activa: crea un nombre nuevo, validado y con sufijo _tmp, y elimina
 * únicamente esa base al terminar.
 *
 * @return array<int, string>
 */
function ejecutarFixturesDinamicos(string $fecha): array
{
    $fallos = [];
    $env = [];
    $envPath = dirname(__DIR__, 2) . '/includes/.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            $env[trim($clave)] = trim(trim($valor), "\"'");
        }
    }

    $host = $env['DB_HOST'] ?? 'localhost';
    $user = $env['DB_USER'] ?? 'root';
    $pass = $env['DB_PASS'] ?? '';
    $activa = strtolower((string)($env['DB_NAME'] ?? ''));
    $temp = 'casa_pestalozzi_tmp_etapa4_' . gmdate('Ymd_His') . '_' . random_int(100, 999);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp)
        || (!str_contains(strtolower($temp), '_tmp') && !str_contains(strtolower($temp), '_test'))
        || $temp === $activa
        || $temp === 'casa-pestalozzi'
    ) {
        return ['La protección de base temporal rechazó el nombre generado.'];
    }

    $servidor = null;
    $db = null;
    try {
        $servidor = new mysqli($host, $user, $pass);
        if ($servidor->connect_errno) {
            throw new RuntimeException('No fue posible conectar a MySQL: ' . $servidor->connect_error);
        }
        $servidor->set_charset('utf8mb4');
        if (!$servidor->query(
            "CREATE DATABASE " . $temp . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        )) {
            throw new RuntimeException('No fue posible crear la base temporal: ' . $servidor->error);
        }

        $db = new mysqli($host, $user, $pass, $temp);
        if ($db->connect_errno) {
            throw new RuntimeException('No fue posible seleccionar la base temporal: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        $ddl = file_get_contents(dirname(__DIR__, 2) . '/database/ddl.sql');
        if (!is_string($ddl)) {
            throw new RuntimeException('No fue posible leer database/ddl.sql.');
        }
        // Los triggers no participan en estas pruebas de proyección. Se
        // omiten para que el DDL sea ejecutable mediante mysqli multi_query,
        // sin que el cliente mysql interprete DELIMITER.
        $ddl = preg_replace('/DELIMITER \\/\\/.*?DELIMITER ;/s', '', $ddl) ?? $ddl;
        if (!$db->multi_query($ddl)) {
            throw new RuntimeException('Falló el DDL temporal: ' . $db->error);
        }
        while ($db->more_results()) {
            $db->next_result();
            if ($db->errno) {
                throw new RuntimeException('Falló una sentencia del DDL temporal: ' . $db->error);
            }
        }

        $db->query(
            "INSERT INTO mesas (id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable)
             VALUES (7, 7, 'Mesa 7', 'mesa', 4, 50, 50, 1, 1),
                    (8, 8, 'Mesa 8', 'mesa', 4, 60, 50, 1, 1)"
        );
        $db->query(
            "INSERT INTO reservaciones
                (id, nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado)
             VALUES (45, 'Reservación futura', 'ninguno', NULL, '{$fecha}', '10:30:00', 2, 'admin', 'confirmada'),
                    (46, 'Ausencia pendiente', 'ninguno', NULL, '{$fecha}', '15:00:00', 2, 'admin', 'confirmada')"
        );
        $db->query(
            "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
             VALUES (45, 7, 1), (46, 8, 1)"
        );
        $db->query(
            "INSERT INTO tickets (id, comensales, nombre, hora_apertura, estado, reservacion_id)
             VALUES (100, 2, 'Ticket temporal', '{$fecha} 09:00:00', 'abierto', NULL)"
        );
        $db->query(
            "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES (100, 7, 1)"
        );
        if ($db->errno) {
            throw new RuntimeException('Falló la carga de fixtures temporales: ' . $db->error);
        }

        \Model\ActiveRecord::setDB($db);
        $ahora09 = new DateTimeImmutable($fecha . ' 09:30:00', ReservacionConfig::timezone());
        $ahora11 = new DateTimeImmutable($fecha . ' 11:00:00', ReservacionConfig::timezone());
        $ocupacion10 = OcupacionMesasService::evaluarHorario($fecha, '10:00:00', 0, false, null, $ahora09);
        $ocupacion1030 = OcupacionMesasService::evaluarHorario($fecha, '10:30:00', 0, false, null, $ahora09);
        $ocupacion11 = OcupacionMesasService::evaluarHorario($fecha, '11:00:00', 0, false, null, $ahora11);

        if (($ocupacion10['mesas'][7]['fuente'] ?? '') !== 'ticket_abierto') {
            $fallos[] = 'Dinámica T2: la mesa 7 no quedó bloqueada antes de la liberación.';
        }
        if (($ocupacion1030['mesas'][7]['fuente'] ?? '') === 'ticket_abierto') {
            $fallos[] = 'Dinámica T3: la mesa 7 siguió bloqueada en el límite exacto.';
        }
        if (($ocupacion11['mesas'][7]['fuente'] ?? '') !== 'ticket_abierto') {
            $fallos[] = 'Dinámica T1: el bloque actual liberó indebidamente un ticket abierto.';
        }
        if (($ocupacion11['mesas'][8]['fuente'] ?? '') !== 'libre') {
            $fallos[] = 'Dinámica R6: la mesa 8 no quedó libre sin otra ocupación.';
        }
        $lecturaCompartida = PosReservacionQueryService::paraFecha(
            $fecha,
            '10:00:00',
            [
                'incluir_inactivas' => true,
                'calcular_conflictos' => true,
                'ahora' => $ahora09,
            ]
        );
        $mesaCompartida = null;
        foreach ((array)($lecturaCompartida['mesas_estado'] ?? []) as $estadoMesa) {
            if ((int)($estadoMesa['id'] ?? 0) === 7) {
                $mesaCompartida = $estadoMesa;
                break;
            }
        }
        if (($lecturaCompartida['ok'] ?? false) !== true
            || ($mesaCompartida['estado_base'] ?? '') !== MesaEstadoService::OCUPADA
            || ($mesaCompartida['ticket_abierto']['bloquea_en_consulta'] ?? false) !== true
        ) {
            $fallos[] = 'Dinámica sincronía: POS y mapa no recibieron el mismo bloqueo de ticket.';
        }

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['RESERVATION_TEST_NOW'] = $fecha . ' 15:15:01';
        $noShow = \Services\PuntoVentaReservacionService::noShow(46, 0, false, false);
        $idempotente = \Services\PuntoVentaReservacionService::noShow(46, 0, false, false);
        $filaResultado = $db->query("SELECT estado, estado_changed_at FROM reservaciones WHERE id = 46");
        $fila = $filaResultado ? $filaResultado->fetch_assoc() : [];
        if (($noShow['ok'] ?? false) !== true || ($fila['estado'] ?? '') !== 'no_show') {
            $fallos[] = 'Dinámica R7: registrar ausencia no confirmó el no_show manual.';
        }
        if (($idempotente['ok'] ?? false) !== true || empty($idempotente['idempotente'])) {
            $fallos[] = 'Dinámica R8: registrar ausencia no fue idempotente.';
        }
    } catch (Throwable $error) {
        $fallos[] = 'Prueba dinámica bloqueada: ' . $error->getMessage();
    } finally {
        if ($db instanceof mysqli) {
            $db->close();
        }
        if ($servidor instanceof mysqli) {
            $servidor->query("DROP DATABASE IF EXISTS " . $temp);
            $servidor->close();
        }
    }

    return $fallos;
}
