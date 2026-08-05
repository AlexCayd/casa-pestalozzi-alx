<?php

declare(strict_types=1);

/** Instalación temporal y regresiones mínimas de Etapa 11.7.2. */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\MesaEstadoService;
use Services\PosReservacionSerializer;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$database = 'casa_pestalozzi_etapa1172_clean_' . $suffix;
$result = [
    'ok' => false,
    'suite' => 'etapa11_7_2_instalacion_limpia',
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'checks' => [],
    'dropped' => false,
];

$runScript = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') return;
        if (!$connection->multi_query($sql)) throw new RuntimeException($connection->error . ' - script');
        do {
            if ($stored = $connection->store_result()) $stored->free();
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) throw new RuntimeException($connection->error . ' - script');
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $matches) === 1) {
            $flush($buffer);
            $buffer = '';
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $line . "\n";
        if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
            $flush(substr(rtrim($buffer), 0, -strlen($delimiter)));
            $buffer = '';
        }
    }
    $flush($buffer);
};

$runSuite = static function (string $script, string $database): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
        . ' --db=' . escapeshellarg($database);
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);
    $decoded = json_decode(implode("\n", $output), true);
    return [
        'ok' => $exitCode === 0 && is_array($decoded) && ($decoded['ok'] ?? false) === true,
        'exit_code' => $exitCode,
        'output' => is_array($decoded) ? $decoded : implode("\n", $output),
    ];
};

$check = static function (bool $condition, string $name) use (&$result): void {
    $result['checks'][$name] = $condition;
    if (!$condition) {
        throw new RuntimeException('Falló la comprobación: ' . $name);
    }
};

$fecha = '2026-11-01';
$zona = ReservacionConfig::timezone();
$ahora = new DateTimeImmutable($fecha . ' 12:00:00', $zona);

try {
    if (!$db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($db->error);
    }
    if (!$db->select_db($database)) {
        throw new RuntimeException($db->error);
    }
    $db->query("SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);
    $runScript($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $result['ddl'] = true;
    $runScript($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $result['dml'] = true;

    $mapaHoy = array_map(
        static fn(string $hora): string => substr($hora, 0, 5),
        HorarioReservacionService::horariosConfiguradosParaMapa($fecha, $ahora)
    );
    $reservables = HorarioReservacionService::resolverFecha($fecha, $ahora)['horarios'];
    $mapaAntesDeAbrir = HorarioReservacionService::horariosConfiguradosParaMapa(
        $fecha,
        new DateTimeImmutable($fecha . ' 07:00:00', $zona)
    );
    $mapaDespuesDelCierre = HorarioReservacionService::horariosConfiguradosParaMapa(
        $fecha,
        new DateTimeImmutable($fecha . ' 20:00:00', $zona)
    );
    $mapaFuturo = HorarioReservacionService::horariosConfiguradosParaMapa(
        '2026-11-02',
        $ahora
    );

    $check($mapaHoy[0] === '12:00', 'mapa_empieza_en_bloque_actual');
    $check(!in_array('11:30', $mapaHoy, true), 'mapa_excluye_bloques_pasados');
    $check(substr((string)$reservables[0], 0, 5) === '13:00', 'reservable_aplica_mas_cuarenta');
    $check(substr((string)$mapaAntesDeAbrir[0], 0, 5) === '08:30', 'mapa_antes_de_abrir_muestra_toda_jornada');
    $check(count($mapaDespuesDelCierre) === 1 && substr((string)$mapaDespuesDelCierre[0], 0, 5) === '17:30', 'mapa_despues_de_cierre_no_inventa_horas');
    $check(substr((string)$mapaFuturo[0], 0, 5) === '13:00', 'mapa_fecha_futura_muestra_jornada');

    $resolucion = HorarioReservacionService::resolverHorarioMapa($fecha, '11:30', [], $ahora);
    $check($resolucion['hora_resuelta'] === '12:00' && $resolucion['solicitada_vencida'] === true, 'mapa_rechaza_hora_solicitada_vencida');
    $check(ReservacionConfig::ESTADO_LABELS['reemplazada'] === 'Reemplazada', 'etiqueta_reemplazada');
    $check(ReservacionConfig::ESTADOS_LISTA_OPERATIVA === ['confirmada'], 'lista_operativa_solo_confirmadas');

    $original = HorarioReservacionService::validarHoraParaModificacion($fecha, '12:30', $ahora);
    $nuevo = HorarioReservacionService::validarHora($fecha, '12:30', $ahora);
    $check(($original['ok'] ?? false) === true, 'modificacion_conserva_horario_original');
    $check(($nuevo['ok'] ?? false) === false, 'nuevo_horario_exige_mas_cuarenta');

    $consulta = DisponibilidadReservacionService::consultar(
        $fecha,
        2,
        999999,
        null,
        ['fecha' => $fecha, 'hora' => '12:30:00']
    );
    $horasConsulta = array_map(
        static fn(array $slot): string => (string)($slot['hora'] ?? ''),
        (array)($consulta['horarios'] ?? [])
    );
    $check(in_array('12:30', $horasConsulta, true), 'selector_publico_une_horario_original');

    $mesa = [
        'id' => 1, 'numero' => 1, 'nombre' => 'Mesa 1', 'tipo' => 'mesa',
        'capacidad' => 4, 'pos_x' => 10, 'pos_y' => 10, 'activo' => 1, 'reservable' => 1,
    ];
    $mesaDos = [
        'id' => 2, 'numero' => 2, 'nombre' => 'Mesa 2', 'tipo' => 'mesa',
        'capacidad' => 4, 'pos_x' => 20, 'pos_y' => 10, 'activo' => 1, 'reservable' => 1,
    ];
    $bloqueosMultimesa = PosReservacionSerializer::bloqueosOperativos(
        [2 => [
            'mesa_id' => 2,
            'tipo' => 'ticket_abierto',
            'bloquea_disponibilidad' => true,
        ]],
        [1, 2],
        [$mesa, $mesaDos],
        77
    );
    $check(count($bloqueosMultimesa) === 1, 'multimesa_identifica_solo_mesa_bloqueante');
    $check(($bloqueosMultimesa[0]['motivo'] ?? '') === 'TICKET_ABIERTO', 'multimesa_motivo_ticket_por_mesa');
    $check(str_contains((string)($bloqueosMultimesa[0]['descripcion'] ?? ''), 'Mesa 2'), 'multimesa_descripcion_segura');
    $dosBloqueos = PosReservacionSerializer::bloqueosOperativos(
        [
            1 => ['mesa_id' => 1, 'tipo' => 'ticket_abierto', 'bloquea_disponibilidad' => true],
            2 => ['mesa_id' => 2, 'fuente' => 'reservacion', 'reservacion_id' => 99],
        ],
        [1, 2],
        [$mesa, $mesaDos],
        77
    );
    $check(count($dosBloqueos) === 2, 'multimesa_detalla_multiples_bloqueos');
    $check(($dosBloqueos[1]['motivo'] ?? '') === 'OTRA_OPERACION', 'multimesa_motivo_operacion_por_mesa');

    $reservacionMultimesa = PosReservacionSerializer::reservacion(
        [
            'id' => 77, 'estado' => 'confirmada', 'fecha' => $fecha,
            'hora' => '12:00:00', 'nombre' => 'Reserva multimesa', 'comensales' => 6,
            'mesa_ids' => '1,2',
        ],
        null,
        [$mesa, $mesaDos],
        $ahora,
        [
            'conflicto_fisico' => true,
            'mesas_bloqueantes' => $bloqueosMultimesa,
        ]
    );
    $check($reservacionMultimesa['puede_iniciar'] === false, 'multimesa_inicio_atomico_deshabilitado');
    $check($reservacionMultimesa['puede_iniciar_servicio'] === false, 'multimesa_alias_compatibilidad_deshabilitado');
    $check(($reservacionMultimesa['motivo_bloqueo'] ?? '') === 'MESAS_ASIGNADAS_NO_DISPONIBLES', 'multimesa_motivo_bloqueo_canonico');
    $check(count((array)($reservacionMultimesa['mesas_bloqueantes'] ?? [])) === 1, 'multimesa_contrato_detalla_bloqueos');
    $check(str_contains((string)($reservacionMultimesa['mensaje_bloqueo'] ?? ''), 'No se puede iniciar'), 'multimesa_mensaje_final_bloqueo');

    $mesaInactiva = $mesaDos;
    $mesaInactiva['activo'] = 0;
    $bloqueosInactiva = PosReservacionSerializer::bloqueosOperativos(
        [],
        [2],
        [$mesaInactiva],
        78
    );
    $check(($bloqueosInactiva[0]['motivo'] ?? '') === 'MESA_NO_UTILIZABLE', 'mesa_inactiva_bloquea_inicio');

    $pendiente = PosReservacionSerializer::reservacion(
        [
            'id' => 1, 'estado' => 'pendiente_verificacion', 'fecha' => $fecha,
            'hora' => '13:00:00', 'nombre' => 'Hold privado', 'comensales' => 2,
            'mesa_ids' => '1', 'hold_expires_at' => '2026-11-01 12:15:00',
        ],
        null,
        [$mesa],
        $ahora,
        ['hora_consultada' => '13:00:00']
    );
    $check($pendiente['muestra_advertencia'] === false && $pendiente['bloquea_walk_ins'] === false, 'hold_no_muestra_identidad');
    $check($pendiente['influye_disponibilidad'] === true, 'hold_conserva_influencia_disponibilidad');

    $confirmadaIntervalo = PosReservacionSerializer::reservacion(
        [
            'id' => 2, 'estado' => 'confirmada', 'fecha' => $fecha,
            'hora' => '11:00:00', 'nombre' => 'Intervalo', 'comensales' => 2,
            'mesa_ids' => '1',
        ],
        null,
        [$mesa],
        $ahora,
        ['hora_consultada' => '11:30:00']
    );
    $check(
        (new DateTimeImmutable($fecha . ' 11:00:00', $zona))
            < (new DateTimeImmutable($fecha . ' 11:30:00', $zona))->modify('+90 minutes')
        && (new DateTimeImmutable($fecha . ' 12:30:00', $zona))
            > (new DateTimeImmutable($fecha . ' 11:30:00', $zona)),
        'mapa_usa_intervalo_canonico'
    );

    $mesasEstado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [],
        [],
        $fecha,
        $ahora,
        '13:00:00',
        ['mesas' => [1 => ['fuente' => 'hold', 'tipo' => 'hold']]]
    );
    $check(($mesasEstado[0]['estado_base'] ?? '') === MesaEstadoService::BLOQUEADA, 'hold_bloquea_mesa');
    $check(($mesasEstado[0]['motivo_bloqueo'] ?? '') === 'Mesa temporalmente comprometida', 'hold_mensaje_sin_identidad');

    $result['concurrencia'] = $runSuite('etapa11_5_concurrencia_completa.php', $database);
    $result['versionado'] = $runSuite('etapa11_5_version_asignaciones.php', $database);
    $result['integracion_pos'] = $runSuite('etapa10_integracion_operativa.php', $database);
    $result['confirmacion'] = ($result['integracion_pos']['ok'] ?? false) === true;
    $result['ok'] = ($result['concurrencia']['ok'] ?? false)
        && ($result['versionado']['ok'] ?? false)
        && ($result['integracion_pos']['ok'] ?? false);
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->query("DROP DATABASE IF EXISTS `{$database}`");
        $result['dropped'] = true;
    }
}

$result['ok'] = $result['ok'] && $result['ddl'] && $result['dml'] && $result['dropped'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
