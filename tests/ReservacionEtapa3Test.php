<?php

/**
 * Integración reproducible de horarios, ocupación POS y concurrencia — Etapa 3.
 * Sólo muta casa_pestalozzi_etapa3_test después de comprobar SELECT DATABASE().
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\Mesa;
use Model\TicketMesa;
use Services\AsignacionMesasService;
use Services\DisponibilidadReservacionService;
use Services\HorarioOperacionService;
use Services\HorarioReservacionService;
use Services\MesaEstadoService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;
use Services\ReservacionMantenimientoService;
use Services\ReservacionService;
use Services\ReservacionVigenciaService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseName = 'casa_pestalozzi_etapa3_test';
$keepDatabase = getenv('E3_KEEP_DATABASE') === '1';
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? '')
);
$tests = 0;
$failures = [];

function e3Assert(string $name, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function e3Same(string $name, mixed $actual, mixed $expected): void
{
    e3Assert($name . ': esperado ' . var_export($expected, true) . ', recibido ' . var_export($actual, true), $actual === $expected);
}

function e3SqlFile(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    $db->multi_query((string)$sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function e3Count(mysqli $db, string $sql): int
{
    return (int)($db->query($sql)->fetch_assoc()['total'] ?? 0);
}

function e3Reservation(mysqli $db, string $date, string $time, int $table, string $state = 'confirmada'): int
{
    $name = $db->real_escape_string('Automática Etapa 3 ' . bin2hex(random_bytes(3)));
    $db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto,
             fecha, hora, comensales, estado, confirmed_at)
         VALUES
            ('{$name}', 'email', 'e3.auto@example.test',
             '{$date}', '{$time}', 2, '{$state}', NOW())"
    );
    $id = (int)$db->insert_id;
    $db->query("INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES ({$id}, {$table}, 1)");
    return $id;
}

/** Ejecuta dos conexiones mysqli reales contra la misma barrera. */
function e3Race(string $database, array $payloads): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'casa_e3_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = $dir . DIRECTORY_SEPARATOR . 'go';
    $processes = [];
    $paths = [];
    foreach ($payloads as $i => $payload) {
        $ready = $dir . DIRECTORY_SEPARATOR . "ready{$i}";
        $result = $dir . DIRECTORY_SEPARATOR . "result{$i}.json";
        $command = [
            PHP_BINARY,
            __DIR__ . '/ReservacionEtapa3ConcurrencyWorker.php',
            $database,
            base64_encode((string)json_encode($payload)),
            $ready,
            $go,
            $result,
        ];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
        $paths[] = [$ready, $result];
    }
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && (!is_file($paths[0][0]) || !is_file($paths[1][0]))) {
        usleep(10000);
    }
    file_put_contents($go, 'go');
    $responses = [];
    foreach ($processes as $i => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException("Worker {$i}: {$stdout} {$stderr}");
        }
        $responses[] = json_decode((string)file_get_contents($paths[$i][1]), true);
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
    return $responses;
}

try {
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->query("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($databaseName);
    $db->query("SET time_zone = '-06:00'");
    $db->query("SET timestamp = UNIX_TIMESTAMP('2026-11-30 12:00:00')");
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 12:00:00';
    putenv('APP_ENV=testing');
    putenv('RESERVATION_TEST_NOW=2026-11-30 12:00:00');
    $selected = (string)$db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'];
    if ($selected !== $databaseName) {
        throw new RuntimeException('SELECT DATABASE() no coincide con la base desechable.');
    }
    e3Assert('diagnóstico SELECT DATABASE antes de mutar', $selected === $databaseName);
    e3SqlFile($db, __DIR__ . '/../database/ddl.sql');
    e3SqlFile($db, __DIR__ . '/../database/dml.sql');
    ActiveRecord::setDB($db);

    $legacyTables = e3Count(
        $db,
        "SELECT COUNT(*) total
         FROM information_schema.tables
         WHERE table_schema = '{$databaseName}'
           AND table_name IN ('reservacion_eventos','dias_reservacion','horarios_reservacion')"
    );
    e3Same('tablas retiradas no existen', $legacyTables, 0);
    $legacyReservationColumns = e3Count(
        $db,
        "SELECT COUNT(*) total
         FROM information_schema.columns
         WHERE table_schema = '{$databaseName}'
           AND table_name = 'reservaciones'
           AND column_name IN (
             'email','telefono','contacto_valor','contacto_normalizado',
             'verification_expires_at','expired_at','cancelled_at','seated_at',
             'no_show_at','cancelled_by','no_show_by'
           )"
    );
    e3Same('columnas retiradas de reservaciones no existen', $legacyReservationColumns, 0);
    $legacyTicketColumns = e3Count(
        $db,
        "SELECT COUNT(*) total
         FROM information_schema.columns
         WHERE table_schema = '{$databaseName}'
           AND table_name = 'tickets'
           AND column_name IN ('mesa_id','mesa_secundaria_id')"
    );
    e3Same('columnas retiradas de tickets no existen', $legacyTicketColumns, 0);
    $legacyOtpColumns = e3Count(
        $db,
        "SELECT COUNT(*) total
         FROM information_schema.columns
         WHERE table_schema = '{$databaseName}'
           AND table_name = 'verificaciones_contacto'
           AND column_name IN ('request_token','max_attempts','updated_at')"
    );
    e3Same('columnas OTP redundantes no existen', $legacyOtpColumns, 0);
    e3Same(
        'fixtures de reservaciones respetan la ventana temporal',
        e3Count(
            $db,
            "SELECT COUNT(*) total
             FROM reservaciones
             WHERE fecha < '2026-11-27' OR fecha > '2026-12-03'"
        ),
        0
    );
    e3Same(
        'hold_expires_at existe',
        e3Count(
            $db,
            "SELECT COUNT(*) total FROM information_schema.columns
             WHERE table_schema = '{$databaseName}'
               AND table_name = 'reservaciones'
               AND column_name = 'hold_expires_at'"
        ),
        1
    );
    e3Same(
        'campos de último cambio existen',
        e3Count(
            $db,
            "SELECT COUNT(*) total FROM information_schema.columns
             WHERE table_schema = '{$databaseName}'
               AND table_name = 'reservaciones'
               AND column_name IN ('status_changed_at','last_modified_by',
                                   'last_modified_source','last_change_reason')"
        ),
        4
    );
    $estadoType = (string)$db->query(
        "SELECT column_type AS tipo_columna FROM information_schema.columns
         WHERE table_schema = '{$databaseName}'
           AND table_name = 'reservaciones'
           AND column_name = 'estado'"
    )->fetch_assoc()['tipo_columna'];
    e3Assert('estado pendiente no forma parte del enum', !str_contains($estadoType, "'pendiente'"));
    $ddl = (string)file_get_contents(__DIR__ . '/../database/ddl.sql');
    e3Assert('DDL no usa COMMENT de MySQL', preg_match('/\bCOMMENT\b/i', $ddl) !== 1);

    // Horarios.
    $week = HorarioOperacionService::obtenerHorarioSemanal();
    e3Same('leer siete días semanales', count($week), 7);
    e3Same('reloj controlado de Etapa 4', ReservacionConfig::fechaActual(), '2026-11-30');

    // Fronteras temporales de disponibilidad del mapa para 30/11/2026.
    $reserva2030 = [
        'id' => 39,
        'nombre' => 'Caso frontera 20:30',
        'fecha' => '2026-11-30',
        'hora' => '20:30:00',
        'comensales' => 6,
        'estado' => 'confirmada',
        'mesa_ids' => [1, 2, 3],
        'mesas_asignadas' => ['Mesa 1', 'Mesa 2', 'Mesa 3'],
    ];
    $clasificar2030 = static function (string $hora) use ($reserva2030): ?array {
        return MesaEstadoService::clasificarReservacion(
            $reserva2030,
            new DateTimeImmutable('2026-11-30 ' . $hora, ReservacionConfig::timezone())
        );
    };
    e3Same('19:29 aún no advierte', $clasificar2030('19:29:00'), null);
    e3Same('19:30 inicia próxima', $clasificar2030('19:30:00')['tipo'] ?? null, 'proxima');
    e3Same('19:45 continúa próxima', $clasificar2030('19:45:00')['tipo'] ?? null, 'proxima');
    e3Same('19:59 continúa próxima', $clasificar2030('19:59:00')['tipo'] ?? null, 'proxima');
    e3Same('20:00 inicia bloqueo', $clasificar2030('20:00:00')['tipo'] ?? null, 'bloqueada');
    e3Same('20:15 continúa bloqueada', $clasificar2030('20:15:00')['tipo'] ?? null, 'bloqueada');
    e3Same('20:29 continúa bloqueada', $clasificar2030('20:29:00')['tipo'] ?? null, 'bloqueada');
    e3Same('20:30 inicia ocupación', $clasificar2030('20:30:00')['tipo'] ?? null, 'ocupada');
    e3Same('20:44:59 continúa vigente', $clasificar2030('20:44:59')['tipo'] ?? null, 'ocupada');
    e3Same('20:45:00 vence tolerancia', $clasificar2030('20:45:00'), null);
    e3Same('22:00 confirmada vencida no ocupa', $clasificar2030('22:00:00'), null);
    $llego2030 = array_merge($reserva2030, ['estado' => 'llego', 'arrived_at' => '2026-11-30 20:40:00']);
    e3Same('llegada conserva ocupación después de 90 minutos', MesaEstadoService::clasificarReservacion(
        $llego2030,
        new DateTimeImmutable('2026-11-30 22:00:00', ReservacionConfig::timezone())
    )['tipo'] ?? null, 'ocupada');

    $cancelada2030 = array_merge($reserva2030, ['estado' => 'cancelada']);
    $noShow2030 = array_merge($reserva2030, ['estado' => 'no_show']);
    e3Same(
        'cancelada no influye',
        MesaEstadoService::clasificarReservacion(
            $cancelada2030,
            new DateTimeImmutable('2026-11-30 20:15:00', ReservacionConfig::timezone())
        ),
        null
    );
    e3Same(
        'no-show no influye',
        MesaEstadoService::clasificarReservacion(
            $noShow2030,
            new DateTimeImmutable('2026-11-30 20:15:00', ReservacionConfig::timezone())
        ),
        null
    );
    $pendienteVigente = array_merge($reserva2030, [
        'estado' => 'pendiente_verificacion',
        'hold_expires_at' => '2026-11-30 19:40:00',
    ]);
    $pendienteVencida = array_merge($pendienteVigente, [
        'hold_expires_at' => '2026-11-30 19:20:00',
    ]);
    $relojPendiente = new DateTimeImmutable('2026-11-30 19:30:00', ReservacionConfig::timezone());
    e3Same(
        'pendiente vigente aparece próxima',
        MesaEstadoService::clasificarReservacion($pendienteVigente, $relojPendiente)['tipo'] ?? null,
        'proxima'
    );
    e3Same(
        'pendiente vencida no influye',
        MesaEstadoService::clasificarReservacion($pendienteVencida, $relojPendiente),
        null
    );

    $mapaTicketFinal = MesaEstadoService::normalizarMesas(
        [[
            'id' => 1,
            'numero' => 1,
            'nombre' => 'Mesa 1',
            'tipo' => 'mesa',
            'capacidad' => 4,
            'pos_x' => 50,
            'pos_y' => 50,
            'activo' => 1,
            'reservable' => 1,
        ]],
        [$cancelada2030],
        [[
            'id' => 501,
            'hora_apertura' => '2026-11-30 19:00:00',
            'reservacion_id' => null,
            'mesa_ids' => [1, 2, 3],
        ]],
        '2026-11-30',
        new DateTimeImmutable('2026-11-30 20:15:00', ReservacionConfig::timezone())
    );
    e3Same('ticket prevalece sobre estado final', $mapaTicketFinal[0]['estado_base'], 'ocupada');
    e3Assert('ticket sin reserva conserva walk-in', in_array('walk_in', $mapaTicketFinal[0]['modificadores'], true));
    e3Assert('ticket de varias mesas conserva modificador', in_array('varias_mesas', $mapaTicketFinal[0]['modificadores'], true));
    e3Assert(
        'ticket de varias mesas conserva indicador accesible',
        in_array('varias_mesas', array_column($mapaTicketFinal[0]['indicadores'], 'tipo'), true)
            && str_contains($mapaTicketFinal[0]['titulo'], 'varias mesas')
    );

    // El mapa conserva el plano completo aunque el horario no tenga reservas.
    $mapaSinReservaciones = MesaEstadoService::normalizarMesas(
        [
            ['id' => 1, 'numero' => 1, 'nombre' => 'Mesa 1', 'tipo' => 'mesa', 'capacidad' => 2, 'pos_x' => 20, 'pos_y' => 50, 'activo' => 1, 'reservable' => 1],
            ['id' => 2, 'numero' => 2, 'nombre' => 'Mesa 2', 'tipo' => 'mesa', 'capacidad' => 4, 'pos_x' => 50, 'pos_y' => 50, 'activo' => 1, 'reservable' => 1],
            ['id' => 3, 'numero' => 3, 'nombre' => 'Barra', 'tipo' => 'barra', 'capacidad' => 0, 'pos_x' => 80, 'pos_y' => 50, 'activo' => 1, 'reservable' => 0],
        ],
        [],
        [],
        '2026-11-30',
        new DateTimeImmutable('2026-11-30 19:45:00', ReservacionConfig::timezone())
    );
    e3Same('horario vacío conserva todas las mesas', count($mapaSinReservaciones), 3);
    e3Same('horario vacío conserva mesa disponible', $mapaSinReservaciones[0]['estado_base'], 'disponible');
    e3Same('horario vacío conserva elemento no reservable', $mapaSinReservaciones[2]['estado_base'], 'no_reservable');
    $special = HorarioOperacionService::obtenerHorarioEfectivo('2026-12-02');
    e3Same('horario especial tiene prioridad', $special['origen'], 'excepcion');
    e3Same('horario especial abre', $special['abierto'], true);
    $closed = HorarioOperacionService::obtenerHorarioEfectivo('2026-11-29');
    e3Same('excepción de cierre gana', $closed['abierto'], false);
    $slots = HorarioReservacionService::generarIntervalos('12:00', '20:00');
    e3Same('primer slot especial', $slots[0], '12:00:00');
    e3Same('última reservación una hora antes', end($slots), '19:00:00');
    e3Assert('intervalo posterior rechazado', !in_array('19:30:00', $slots, true));
    e3Same('intervalos cada treinta minutos', count($slots), 15);

    // Horarios vigentes del día actual: el reloj y la zona del servidor son
    // la única referencia para endpoint, mapa y formulario administrativo.
    $horarioLunesOriginal = $db->query(
        "SELECT abierto, hora_apertura, hora_cierre
         FROM horarios_operacion
         WHERE dia_semana = 1"
    )->fetch_assoc();
    $db->query(
        "UPDATE horarios_operacion
         SET abierto = 1, hora_apertura = '08:00:00', hora_cierre = '14:00:00'
         WHERE dia_semana = 1"
    );
    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 10:56:00';
    putenv('RESERVATION_TEST_NOW=2026-11-30 10:56:00');

    $horariosHoy1056 = ReservacionService::obtenerHorariosDisponiblesParaFecha('2026-11-30');
    e3Same('endpoint hoy 10:56 inicia en siguiente bloque', $horariosHoy1056['horarios'][0] ?? '', '11:00:00');
    e3Assert(
        'endpoint hoy no devuelve horarios vencidos',
        count(array_filter(
            $horariosHoy1056['horarios'] ?? [],
            static fn(string $hora): bool => $hora <= '10:56:00'
        )) === 0
    );
    $resolucionVencida = HorarioReservacionService::resolverHorarioOperativo(
        '2026-11-30',
        '08:00',
        $horariosHoy1056['horarios'] ?? []
    );
    e3Same('URL vencida resuelve siguiente bloque', $resolucionVencida['hora_resuelta'], '11:00');
    e3Assert('URL vencida queda marcada como ajustada', $resolucionVencida['ajustada']);
    e3Assert('URL vencida conserva motivo temporal', $resolucionVencida['solicitada_vencida']);

    $horariosFuturos = ReservacionService::obtenerHorariosDisponiblesParaFecha('2026-12-01');
    $efectivoFuturo = HorarioOperacionService::obtenerHorarioEfectivo('2026-12-01');
    $intervalosFuturos = HorarioReservacionService::generarIntervalos(
        (string)$efectivoFuturo['hora_apertura'],
        (string)$efectivoFuturo['hora_cierre']
    );
    e3Same('fecha futura conserva todos sus horarios', $horariosFuturos['horarios'], $intervalosFuturos);

    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 13:30:00';
    putenv('RESERVATION_TEST_NOW=2026-11-30 13:30:00');
    $sinHorariosHoy = ReservacionService::obtenerHorariosDisponiblesParaFecha('2026-11-30');
    $resolucionSinHorarios = HorarioReservacionService::resolverHorarioOperativo(
        '2026-11-30',
        '08:00',
        $sinHorariosHoy['horarios'] ?? []
    );
    e3Same('URL vencida sin horarios futuros no resuelve hora', $resolucionSinHorarios['hora_resuelta'], '');
    e3Assert('URL vencida detecta fin de operación diaria', $resolucionSinHorarios['sin_horarios_futuros']);

    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 12:00:00';
    putenv('RESERVATION_TEST_NOW=2026-11-30 12:00:00');
    $db->query(
        "UPDATE horarios_operacion
         SET abierto = " . (int)$horarioLunesOriginal['abierto'] . ",
             hora_apertura = '" . $db->real_escape_string((string)$horarioLunesOriginal['hora_apertura']) . "',
             hora_cierre = '" . $db->real_escape_string((string)$horarioLunesOriginal['hora_cierre']) . "'
         WHERE dia_semana = 1"
    );

    $operationViewSource = (string)file_get_contents(__DIR__ . '/../views/operation/reservations/index.php');
    $operationLayoutSource = (string)file_get_contents(__DIR__ . '/../views/operation/layout.php');
    $operationNoticeSource = (string)file_get_contents(__DIR__ . '/../views/operation/partials/global-notice.php');
    $operationJavascriptSource = (string)file_get_contents(__DIR__ . '/../src/js/admin/reservations/operation.js');
    $operationFeedbackSource = (string)file_get_contents(__DIR__ . '/../src/scss/operation/_feedback.scss');
    e3Assert(
        'alerta global no se renderiza dentro del módulo de mapa',
        !str_contains($operationViewSource, "partials/global-notice.php")
    );
    e3Assert(
        'layout renderiza una sola raíz global después del shell',
        substr_count($operationLayoutSource, 'id="global-operation-notice-root"') === 1
            && strpos($operationLayoutSource, "partials/shell.php") < strpos($operationLayoutSource, 'id="global-operation-notice-root"')
    );
    e3Assert(
        'JavaScript consulta el aviso fuera de la raíz del mapa',
        str_contains(
            $operationJavascriptSource,
            "document.querySelector('#global-operation-notice-root [data-operation-global-notice]')"
        )
    );
    e3Assert(
        'aviso global conserva apilamiento de viewport',
        str_contains($operationFeedbackSource, 'z-index: 10000')
            && str_contains($operationFeedbackSource, 'position: fixed')
            && str_contains($operationFeedbackSource, 'left: 50%')
    );
    e3Assert(
        'alerta global elimina Reintentar y su espacio del DOM',
        stripos($operationNoticeSource, 'Reintentar') === false
            && !str_contains($operationNoticeSource, 'data-operation-retry')
            && stripos($operationJavascriptSource, 'retry: true') === false
    );
    e3Assert(
        'alerta global expande detalle dentro de la misma card',
        str_contains($operationNoticeSource, 'data-operation-global-notice-expand')
            && str_contains($operationNoticeSource, 'data-operation-global-notice-detail')
            && str_contains($operationJavascriptSource, "expanded ? 'Ocultar detalle' : 'Expandir'")
    );
    e3Assert(
        'detalle colapsable mantiene atributos accesibles',
        str_contains($operationNoticeSource, 'aria-expanded="false"')
            && str_contains($operationNoticeSource, 'aria-hidden="true"')
            && str_contains($operationJavascriptSource, "setAttribute('aria-hidden', expanded ? 'false' : 'true')")
    );
    e3Assert(
        'variante azul usa superficie opaca y texto de alto contraste',
        str_contains($operationFeedbackSource, 'var(--admin-info-text) 18%, var(--admin-surface-strong)')
            && str_contains($operationFeedbackSource, 'color: #dceeff')
            && !str_contains($operationFeedbackSource, "background: var(--admin-info-bg)")
    );
    e3Assert(
        'URL se sincroniza con hora resuelta mediante replaceState',
        str_contains($operationJavascriptSource, "params.set('hora', state.horaSeleccionada)")
            && str_contains($operationJavascriptSource, 'window.history.replaceState')
    );
    e3Assert(
        'fin de horarios de hoy no se mezcla con modo histórico',
        str_contains($operationJavascriptSource, "var historical = state.modo === 'solo_lectura'")
            && str_contains($operationJavascriptSource, "historical ? 'solo_lectura' : 'operacion'")
    );

    $modified = $week;
    $modified[2]['hora_apertura'] = '10:00';
    $modified[2]['hora_cierre'] = '21:00';
    $saved = HorarioOperacionService::guardarHorarioSemanal($modified, 1, true);
    e3Assert('actualizar apertura y cierre', (bool)$saved['ok']);
    e3Same('contrato de éxito de horarios', $saved['codigo'] ?? '', 'HORARIOS_ACTUALIZADOS');
    e3Same('respuesta de éxito devuelve siete días', count($saved['horarios'] ?? []), 7);
    $reloaded = HorarioOperacionService::obtenerHorarioSemanal();
    e3Same('persistencia apertura', $reloaded[2]['hora_apertura'], '10:00');
    e3Same('persistencia cierre', $reloaded[2]['hora_cierre'], '21:00');
    $invalid = $reloaded;
    $invalid[2]['hora_apertura'] = '21:00';
    $invalid[2]['hora_cierre'] = '10:00';
    $invalidResult = HorarioOperacionService::guardarHorarioSemanal($invalid, 1);
    e3Assert('horario imposible rechazado', !$invalidResult['ok']);
    e3Same('horario imposible devuelve contrato 422', $invalidResult['codigo'] ?? '', 'HORARIO_INVALIDO');

    // Los upserts y la sincronización reparan filas ausentes sin depender de
    // que el esquema ya tenga sembrados los siete días.
    $db->query('DELETE FROM horarios_operacion WHERE dia_semana = 6');
    $missingWeekly = HorarioOperacionService::guardarHorarioSemanal($reloaded, 1, true);
    e3Assert('crear fila semanal faltante', $missingWeekly['ok']);
    e3Same('fila semanal recreada', e3Count($db, 'SELECT COUNT(*) total FROM horarios_operacion WHERE dia_semana = 6'), 1);

    $closedWeek = $reloaded;
    $closedWeek[2]['abierto'] = false;
    $closedWeek[2]['hora_apertura'] = '';
    $closedWeek[2]['hora_cierre'] = '';
    e3Assert('cerrar día semanal', HorarioOperacionService::guardarHorarioSemanal($closedWeek, 1, true)['ok']);
    e3Same('día cerrado persiste', HorarioOperacionService::obtenerHorarioSemanal()[2]['abierto'], false);
    e3Assert('reabrir día semanal', HorarioOperacionService::guardarHorarioSemanal($reloaded, 1, true)['ok']);

    $conflictWeek = HorarioOperacionService::obtenerHorarioSemanal();
    $conflictWeek[1]['hora_cierre'] = '20:00';
    $conflict = HorarioOperacionService::guardarHorarioSemanal($conflictWeek, 1, false);
    e3Same('cambio con reservaciones exige confirmación', $conflict['codigo'] ?? '', 'RESERVACIONES_AFECTADAS');
    e3Assert('reservaciones afectadas no se cancelan', e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE id = @horario_afectado AND estado = 'confirmada'") === 1);
    $confirmed = HorarioOperacionService::guardarHorarioSemanal($conflictWeek, 1, true);
    e3Assert('confirmación administrativa guarda', (bool)$confirmed['ok']);
    e3Assert(
        'conflicto conserva último motivo',
        e3Count(
            $db,
            "SELECT COUNT(*) total FROM reservaciones
             WHERE id = @horario_afectado
               AND last_modified_source = 'personal'
               AND last_change_reason IS NOT NULL"
        ) > 0
    );

    // Capacidad con ticket canónico.
    $future = '2026-12-03';
    $db->query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado) VALUES (2, 'Bloqueo futuro', '2026-12-03 17:30:00', 'abierto')");
    $futureTicket = (int)$db->insert_id;
    $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$futureTicket}, 1, 1)");
    $occupied = TicketMesa::ocupacionAbierta();
    e3Assert('ticket abierto ocupa mesa', in_array(1, array_column($occupied, 'mesa_id'), true));
    $assignmentOccupation = AsignacionMesasService::obtenerOcupacionParaHorario($future, '18:00:00');
    e3Same(
        'asignación futura no traslada un ticket abierto actual',
        $assignmentOccupation[1]['tipo'] ?? null,
        null
    );
    e3Assert('walk-in no crea reservación', e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE id = {$futureTicket}") >= 0);

    // Flujo POS sobre hoy.
    $today = ReservacionConfig::fechaActual();
    $db->query(
        "UPDATE tickets t
         INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
         INNER JOIN mesas m ON m.id = tm.mesa_id
         SET t.estado='cerrado', t.closed_at=NOW()
         WHERE t.estado='abierto' AND m.tipo='mesa'"
    );

    // Etapa funcional: una estimación nunca libera un ticket que sigue abierto.
    $db->query(
        "INSERT INTO tickets (comensales, nombre, hora_apertura, estado)
         VALUES (6, 'Ticket persistente Etapa 4', '2026-11-30 08:00:00', 'abierto')"
    );
    $ticketPersistente = (int)$db->insert_id;
    $db->query(
        "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
         VALUES ({$ticketPersistente}, 1, 1), ({$ticketPersistente}, 2, 2)"
    );
    $abiertosPersistentes = TicketMesa::abiertosParaMapa();
    $ticketCanonico = current(array_filter(
        $abiertosPersistentes,
        static fn(array $ticket): bool => (int)$ticket['id'] === $ticketPersistente
    )) ?: [];
    e3Same('ticket canónico conserva estado abierto', $ticketCanonico['estado'] ?? '', 'abierto');
    e3Same('ticket canónico conserva closed_at nulo', $ticketCanonico['closed_at'] ?? null, null);
    e3Same('ticket canónico conserva relación N:M', $ticketCanonico['mesa_ids'] ?? [], [1, 2]);
    $ocupacionPersistente = TicketMesa::ocupacionAbierta();
    e3Assert(
        'ticket de un día anterior ocupa más allá de la duración estimada',
        in_array(1, array_column($ocupacionPersistente, 'mesa_id'), true)
            && in_array(2, array_column($ocupacionPersistente, 'mesa_id'), true)
    );
    $capacidadConTicket = DisponibilidadReservacionService::resumenHorario(
        '2026-12-06',
        '17:00',
        2
    );
    e3Same('capacidad total usa once mesas reservables', $capacidadConTicket['capacidad_total'] ?? -1, 44);
    e3Same('fecha futura ignora ticket N:M actual', $capacidadConTicket['capacidad_disponible'] ?? -1, 44);
    $reservacionDuplicadaConTicket = e3Reservation($db, '2026-12-06', '17:00:00', 1);
    e3Same(
        'misma mesa en reservación y ticket se descuenta una sola vez',
        DisponibilidadReservacionService::resumenHorario('2026-12-06', '17:00', 2)['capacidad_disponible'] ?? -1,
        40
    );
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id={$reservacionDuplicadaConTicket}");
    $db->query("DELETE FROM reservaciones WHERE id={$reservacionDuplicadaConTicket}");
    $db->query(
        "UPDATE tickets
         SET estado='cerrado', closed_at=NOW()
         WHERE id={$ticketPersistente}"
    );
    e3Same(
        'ticket cerrado libera capacidad y conserva pivotes',
        DisponibilidadReservacionService::resumenHorario('2026-12-06', '17:00', 2)['capacidad_disponible'] ?? -1,
        44
    );
    e3Same(
        'cierre conserva relaciones ticket_mesas',
        e3Count($db, "SELECT COUNT(*) total FROM ticket_mesas WHERE ticket_id={$ticketPersistente}"),
        2
    );

    $reservacionReasignable = e3Reservation($db, '2026-12-06', '17:00:00', 10);
    $reasignacionEditable = AsignacionMesasService::asignarManual(
        $reservacionReasignable,
        [11]
    );
    e3Assert(
        'backend permite reasignar una reservación vigente por su ID real',
        (bool)($reasignacionEditable['ok'] ?? false)
            && e3Count(
                $db,
                "SELECT COUNT(*) total
                 FROM reservacion_mesas
                 WHERE reservacion_id={$reservacionReasignable}
                   AND mesa_id=11"
            ) === 1
    );
    $db->query("UPDATE reservaciones SET estado='completada' WHERE id={$reservacionReasignable}");
    $reasignacionFinalizada = AsignacionMesasService::asignarManual(
        $reservacionReasignable,
        [10]
    );
    e3Assert(
        'backend rechaza reasignar una reservación realmente finalizada',
        !($reasignacionFinalizada['ok'] ?? false)
            && ($reasignacionFinalizada['codigo'] ?? '') === AsignacionMesasService::RESERVACION_NO_EDITABLE
            && e3Count(
                $db,
                "SELECT COUNT(*) total
                 FROM reservacion_mesas
                 WHERE reservacion_id={$reservacionReasignable}
                   AND mesa_id=11"
            ) === 1
    );
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id={$reservacionReasignable}");
    $db->query("DELETE FROM reservaciones WHERE id={$reservacionReasignable}");

    // El dominio, no el nombre visible, excluye barras y elementos especiales.
    $db->query("UPDATE mesas SET reservable=1 WHERE tipo<>'mesa'");
    e3Same('barras Caja y Llevar no suman capacidad aunque estén marcadas reservables', Mesa::capacidadReservableTotal(), 44);
    e3Same('consulta reservable excluye elementos no mesa', count(Mesa::reservables()), 11);
    $db->query("UPDATE mesas SET reservable=0 WHERE tipo<>'mesa'");

    // La creación administrativa exige confirmaciones de negocio explícitas.
    $adminSinContacto = [
        'nombre' => 'Administrativa sin contacto',
        'contacto_tipo' => '',
        'contacto' => '',
        'fecha' => '2026-12-06',
        'hora' => '10:00',
        'comensales' => 2,
        'request_token' => 'e4-admin-sin-contacto-01',
        'asignar_automaticamente' => '1',
    ];
    $sinConfirmacionContacto = ReservacionService::crearAdministrativa($adminSinContacto, 1);
    e3Same(
        'administración rechaza ausencia de contacto sin confirmación',
        $sinConfirmacionContacto['codigo'] ?? '',
        ReservacionService::REQUIERE_CONFIRMACION_SIN_CONTACTO
    );
    e3Same(
        'rechazo sin contacto no crea fila',
        e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE request_token='e4-admin-sin-contacto-01'"),
        0
    );
    $adminSinContacto['confirmar_sin_contacto'] = '1';
    $creadaSinContacto = ReservacionService::crearAdministrativa($adminSinContacto, 1);
    e3Assert(
        'administración crea sin contacto después de confirmar',
        (bool)($creadaSinContacto['ok'] ?? false)
            && (bool)($creadaSinContacto['sin_contacto'] ?? false)
            && !empty($creadaSinContacto['mesa_ids'])
    );
    $filaSinContacto = $db->query(
        "SELECT contacto_tipo, contacto, estado
         FROM reservaciones
         WHERE request_token='e4-admin-sin-contacto-01'"
    )->fetch_assoc();
    e3Same('esquema vigente representa ausencia de contacto con valor vacío', $filaSinContacto['contacto'] ?? null, '');
    e3Same('reservación administrativa queda confirmada directamente', $filaSinContacto['estado'] ?? '', 'confirmada');

    $adminGrupo = ReservacionService::crearAdministrativa([
        'nombre' => 'Grupo administrativo de trece',
        'contacto_tipo' => 'email',
        'contacto' => 'grupo13@example.test',
        'fecha' => '2026-12-06',
        'hora' => '13:00',
        'comensales' => 13,
        'request_token' => 'e4-admin-grupo-trece-01',
        'asignar_automaticamente' => '1',
    ], 1);
    e3Assert(
        'administración permite más de doce con capacidad',
        (bool)($adminGrupo['ok'] ?? false)
            && count($adminGrupo['mesa_ids'] ?? []) >= 4
            && !($adminGrupo['requiere_asignacion_manual'] ?? true)
    );

    $adminManual = ReservacionService::crearAdministrativa([
        'nombre' => 'Asignación manual solicitada',
        'contacto_tipo' => 'email',
        'contacto' => 'manual@example.test',
        'fecha' => '2026-12-06',
        'hora' => '15:00',
        'comensales' => 3,
        'request_token' => 'e4-admin-manual-000001',
        'asignar_automaticamente' => '0',
    ], 1);
    e3Assert(
        'administración puede crear sin asignación por decisión del operador',
        (bool)($adminManual['ok'] ?? false)
            && ($adminManual['mesa_ids'] ?? []) === []
            && (bool)($adminManual['requiere_asignacion_manual'] ?? false)
    );

    // La landing compara la identidad normalizada, sin sustituir request_token.
    $publicaBase = [
        'nombre' => 'Cliente duplicado',
        'tipo_contacto' => 'email',
        'contacto' => 'DUPLICADO@EXAMPLE.TEST',
        'fecha' => '2026-12-06',
        'hora' => '16:30',
        'personas' => 2,
        'notas' => '',
        'request_token' => 'e4-publica-duplicada-01',
    ];
    $sesionDuplicada = [
        'contacto_tipo' => 'email',
        'contacto' => 'duplicado@example.test',
    ];
    $publicaPrimera = ReservacionPublicaService::crearConfirmada($publicaBase, $sesionDuplicada);
    e3Same(
        'landing crea primera reservación normalizada',
        $publicaPrimera['codigo'] ?? '',
        ReservacionPublicaService::RESERVACION_CONFIRMADA
    );
    $publicaBase['contacto'] = 'duplicado@example.test';
    $publicaBase['request_token'] = 'e4-publica-duplicada-02';
    $publicaDuplicada = ReservacionPublicaService::crearConfirmada($publicaBase, $sesionDuplicada);
    e3Same(
        'landing rechaza mismo contacto y horario con otro token',
        $publicaDuplicada['codigo'] ?? '',
        ReservacionPublicaService::RESERVACION_DUPLICADA
    );
    $publicaBase['hora'] = '18:00';
    $publicaBase['request_token'] = 'e4-publica-otro-horario';
    e3Same(
        'landing permite mismo contacto en otro horario',
        ReservacionPublicaService::crearConfirmada($publicaBase, $sesionDuplicada)['codigo'] ?? '',
        ReservacionPublicaService::RESERVACION_CONFIRMADA
    );

    $telefonoBase = [
        'nombre' => 'Cliente teléfono normalizado',
        'tipo_contacto' => 'telefono',
        'contacto' => '+52 55 1234 5678',
        'fecha' => '2026-12-06',
        'hora' => '11:30',
        'personas' => 2,
        'notas' => '',
        'request_token' => 'e4-publica-telefono-01',
    ];
    $sesionTelefono = ['contacto_tipo' => 'telefono', 'contacto' => '+525512345678'];
    e3Assert(
        'landing normaliza teléfono con formato visual',
        (bool)(ReservacionPublicaService::crearConfirmada($telefonoBase, $sesionTelefono)['ok'] ?? false)
    );
    $telefonoBase['contacto'] = '+52 (55) 1234-5678';
    $telefonoBase['request_token'] = 'e4-publica-telefono-02';
    e3Same(
        'landing rechaza teléfono equivalente en el mismo horario',
        ReservacionPublicaService::crearConfirmada($telefonoBase, $sesionTelefono)['codigo'] ?? '',
        ReservacionPublicaService::RESERVACION_DUPLICADA
    );

    // Sin capacidad, administración sólo continúa con el indicador explícito.
    $capacidadAntesTicketCompleto = DisponibilidadReservacionService::resumenHorario(
        '2026-12-06',
        '17:00',
        2
    )['capacidad_disponible'] ?? -1;
    $_ENV['RESERVATION_TEST_NOW'] = '2026-12-06 16:30:00';
    putenv('RESERVATION_TEST_NOW=2026-12-06 16:30:00');
    $db->query("SET timestamp = UNIX_TIMESTAMP('2026-12-06 16:30:00')");
    $db->query(
        "INSERT INTO tickets (comensales, nombre, hora_apertura, estado)
         VALUES (44, 'Ocupación completa Etapa 4', '2026-12-06 16:00:00', 'abierto')"
    );
    $ticketCompleto = (int)$db->insert_id;
    $db->query(
        "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
         SELECT {$ticketCompleto}, id, numero
         FROM mesas
         WHERE activo=1 AND reservable=1 AND tipo='mesa'
         ORDER BY numero"
    );
    $adminSinCupo = [
        'nombre' => 'Grupo sin capacidad',
        'contacto_tipo' => 'email',
        'contacto' => 'sin.cupo@example.test',
        'fecha' => '2026-12-06',
        'hora' => '17:00',
        'comensales' => 18,
        'request_token' => 'e4-admin-sin-capacidad-01',
        'asignar_automaticamente' => '1',
    ];
    $warningCapacidad = ReservacionService::crearAdministrativa($adminSinCupo, 1);
    $resumenSinCupo = DisponibilidadReservacionService::resumenHorario(
        '2026-12-06',
        '17:00',
        2
    );
    e3Same(
        'todas las mesas con tickets abiertos dejan capacidad disponible cero',
        $resumenSinCupo['capacidad_disponible'] ?? -1,
        0
    );
    $publicaSinCupo = [
        'nombre' => 'Cliente público sin capacidad',
        'tipo_contacto' => 'email',
        'contacto' => 'publica.sin.cupo@example.test',
        'fecha' => '2026-12-06',
        'hora' => '17:00',
        'personas' => 2,
        'notas' => '',
        'request_token' => 'e4-publica-sin-capacidad',
    ];
    $resultadoPublicoSinCupo = ReservacionPublicaService::crearConfirmada(
        $publicaSinCupo,
        [
            'contacto_tipo' => 'email',
            'contacto' => 'publica.sin.cupo@example.test',
        ]
    );
    e3Assert(
        'landing rechaza creación cuando la capacidad real es cero',
        !($resultadoPublicoSinCupo['ok'] ?? false)
            && ($resultadoPublicoSinCupo['codigo'] ?? '') === ReservacionPublicaService::SIN_DISPONIBILIDAD
            && e3Count(
                $db,
                "SELECT COUNT(*) total
                 FROM reservaciones
                 WHERE request_token='e4-publica-sin-capacidad'"
            ) === 0
    );
    e3Assert(
        'administración advierte capacidad y no inserta antes de confirmar',
        ($warningCapacidad['codigo'] ?? '') === ReservacionService::REQUIERE_CONFIRMACION_CAPACIDAD
            && (int)($warningCapacidad['capacidad_disponible'] ?? -1) === 0
            && e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE request_token='e4-admin-sin-capacidad-01'") === 0
    );
    $adminSinCupo['permitir_capacidad_insuficiente'] = '1';
    $creadaSinCupo = ReservacionService::crearAdministrativa($adminSinCupo, 1);
    e3Assert(
        'confirmación explícita crea sin mesas y solicita asignación',
        (bool)($creadaSinCupo['ok'] ?? false)
            && ($creadaSinCupo['mesa_ids'] ?? []) === []
            && (bool)($creadaSinCupo['requiere_asignacion_manual'] ?? false)
    );
    $db->query(
        "UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$ticketCompleto}"
    );
    e3Same(
        'el cierre real de todos los tickets restaura la capacidad exacta',
        DisponibilidadReservacionService::resumenHorario('2026-12-06', '17:00', 2)['capacidad_disponible'] ?? -1,
        $capacidadAntesTicketCompleto
    );
    $_ENV['RESERVATION_TEST_NOW'] = '2026-11-30 12:00:00';
    putenv('RESERVATION_TEST_NOW=2026-11-30 12:00:00');
    $db->query("SET timestamp = UNIX_TIMESTAMP('2026-11-30 12:00:00')");

    // Fronteras exactas del warning POS, con revalidación backend.
    e3Reservation($db, $today, '12:45:00', 4);
    $warning45 = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [4],
        'comensales' => 2,
        'nombre' => 'Walk-in a 45 minutos',
    ], 1);
    e3Assert(
        'entre 31 y 60 minutos POS exige confirmación',
        ($warning45['codigo'] ?? '') === PuntoVentaReservacionService::REQUIERE_CONFIRMACION
            && (int)($warning45['advertencia']['minutos_restantes'] ?? 0) === 45
    );
    $walkin45 = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [4],
        'comensales' => 2,
        'nombre' => 'Walk-in confirmado a 45 minutos',
        'confirmar_reservacion_proxima' => '1',
    ], 1);
    e3Assert('confirmación explícita permite ticket entre 31 y 60', (bool)($walkin45['ok'] ?? false));
    PuntoVentaReservacionService::cerrarTicket((int)($walkin45['ticket_id'] ?? 0), 'efectivo', 0, [], 1);

    e3Reservation($db, $today, '12:30:00', 5);
    $bloqueo30 = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [5],
        'comensales' => 2,
        'nombre' => 'Walk-in bloqueado a 30 minutos',
        'confirmar_reservacion_proxima' => '1',
    ], 1);
    e3Assert(
        'a 30 minutos POS bloquea incluso con indicador',
        !($bloqueo30['ok'] ?? false)
            && ($bloqueo30['codigo'] ?? '') === PuntoVentaReservacionService::MESA_OCUPADA
            && (bool)($bloqueo30['bloqueo']['bloqueada'] ?? false)
    );

    $arrivalId = e3Reservation($db, $today, '23:00:00', 1);
    $arrival = PuntoVentaReservacionService::registrarLlegada($arrivalId, 1);
    e3Assert('registrar llegada', $arrival['ok']);
    e3Assert('llegada no abre ticket', e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$arrivalId}") === 0);
    e3Assert('llegada idempotente', PuntoVentaReservacionService::registrarLlegada($arrivalId, 1)['idempotente']);
    $begin = PuntoVentaReservacionService::comenzar($arrivalId, 1);
    e3Assert('comenzar llegada crea ticket', $begin['ok'] && $begin['ticket_id'] > 0);
    e3Same('comenzar cambia a en_curso', $db->query("SELECT estado FROM reservaciones WHERE id={$arrivalId}")->fetch_assoc()['estado'], 'en_curso');
    e3Same('ticket y mesa atómicos', e3Count($db, "SELECT COUNT(*) total FROM ticket_mesas WHERE ticket_id=" . (int)$begin['ticket_id']), 1);
    e3Assert('doble inicio idempotente', PuntoVentaReservacionService::comenzar($arrivalId, 1)['idempotente']);
    $context = PuntoVentaReservacionService::contextoMesa(1);
    e3Assert('contexto muestra ticket abierto', $context['ok'] && $context['ticket_abierto']['id'] === $begin['ticket_id']);
    $closedTicket = PuntoVentaReservacionService::cerrarTicket((int)$begin['ticket_id'], 'efectivo', 0, [], 1);
    e3Assert('cierre de ticket', $closedTicket['ok']);
    e3Same('cierre completa reservación', $db->query("SELECT estado FROM reservaciones WHERE id={$arrivalId}")->fetch_assoc()['estado'], 'completada');
    e3Assert('doble cierre idempotente', PuntoVentaReservacionService::cerrarTicket((int)$begin['ticket_id'], 'efectivo', 0, [], 1)['idempotente']);

    $futureNoShow = e3Reservation($db, $today, '23:59:00', 2);
    $tooSoon = PuntoVentaReservacionService::noShow($futureNoShow, 1, false, false);
    e3Same('no-show antes de tolerancia rechazado', $tooSoon['codigo'], PuntoVentaReservacionService::TOLERANCIA_VIGENTE);
    $overrideNoShow = PuntoVentaReservacionService::noShow($futureNoShow, 1, true, true, 'Override de prueba');
    e3Same('override anticipado ya no se permite', $overrideNoShow['codigo'], PuntoVentaReservacionService::TOLERANCIA_VIGENTE);
    e3Same('override anticipado conserva confirmada', $db->query("SELECT estado FROM reservaciones WHERE id={$futureNoShow}")->fetch_assoc()['estado'], 'confirmada');

    $pastNoShow = e3Reservation($db, $today, '00:00:00', 2);
    e3Assert('no-show después de tolerancia', PuntoVentaReservacionService::noShow($pastNoShow, 1, false, false)['ok']);
    $cancelId = e3Reservation($db, $today, '22:00:00', 2);
    e3Assert('cancelación administrativa', PuntoVentaReservacionService::cancelar($cancelId, 1, 'Cliente llamó')['ok']);
    e3Same('cancelación libera estado', $db->query("SELECT estado FROM reservaciones WHERE id={$cancelId}")->fetch_assoc()['estado'], 'cancelada');

    $walkin = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [1, 2, 3],
        'comensales' => 8,
        'nombre' => 'Walk-in tres mesas',
        'confirmar_reservacion_proxima' => 1,
    ], 1);
    e3Assert('walk-in de tres mesas', $walkin['ok'] && count($walkin['mesa_ids']) === 3);
    e3Same('walk-in N:M completo', e3Count($db, "SELECT COUNT(*) total FROM ticket_mesas WHERE ticket_id=" . (int)$walkin['ticket_id']), 3);
    $todayList = PuntoVentaReservacionService::listar($today);
    e3Assert('walk-in no visible en lista de reservaciones', !in_array($walkin['ticket_id'], array_column($todayList['reservaciones'], 'id'), true));
    e3Assert('walk-in visible en contexto', PuntoVentaReservacionService::contextoMesa(3)['ticket_abierto'] !== null);
    e3Assert('segunda apertura misma mesa rechazada', !PuntoVentaReservacionService::abrirWalkIn(['mesa_ids' => [3]], 1)['ok']);
    PuntoVentaReservacionService::cerrarTicket((int)$walkin['ticket_id'], 'efectivo', 0, [], 1);
    e3Assert('cierre libera walk-in', PuntoVentaReservacionService::contextoMesa(3)['ticket_abierto'] === null);

    // Dos conexiones reales.
    $raceReservation = e3Reservation($db, $today, '21:00:00', 1);
    $race = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $raceReservation, 'usuario_id' => 1],
        ['mode' => 'begin', 'reservacion_id' => $raceReservation, 'usuario_id' => 1],
    ]);
    e3Same('doble inicio crea un ticket', e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id={$raceReservation}"), 1);
    e3Assert('doble inicio sin estado parcial', count(array_filter($race, fn(array $r): bool => (bool)($r['ok'] ?? false))) === 2);

    $raceTicket = (int)$db->query("SELECT id FROM tickets WHERE reservacion_id={$raceReservation}")->fetch_assoc()['id'];
    $closeRace = e3Race($databaseName, [
        ['mode' => 'close', 'ticket_id' => $raceTicket, 'usuario_id' => 1],
        ['mode' => 'close', 'ticket_id' => $raceTicket, 'usuario_id' => 1],
    ]);
    e3Assert('cierre simultáneo idempotente', count(array_filter($closeRace, fn(array $r): bool => (bool)($r['ok'] ?? false))) === 2);
    e3Same('cierre simultáneo deja un token', e3Count($db, "SELECT COUNT(*) total FROM feedback_tokens WHERE ticket_id={$raceTicket}"), 1);

    $walkRace = e3Race($databaseName, [
        ['mode' => 'walkin', 'mesa_id' => 1, 'usuario_id' => 1],
        ['mode' => 'walkin', 'mesa_id' => 1, 'usuario_id' => 1],
    ]);
    e3Same('doble ticket tiene un ganador', count(array_filter($walkRace, fn(array $r): bool => (bool)($r['ok'] ?? false))), 1);
    e3Same('una mesa no tiene dos tickets abiertos', e3Count($db, "SELECT COUNT(DISTINCT t.id) total FROM tickets t LEFT JOIN ticket_mesas tm ON tm.ticket_id=t.id WHERE t.estado='abierto' AND tm.mesa_id=1"), 1);

    $walkRaceTicket = (int)$db->query(
        "SELECT t.id
         FROM tickets t
         JOIN ticket_mesas tm ON tm.ticket_id = t.id
         WHERE t.estado = 'abierto' AND tm.mesa_id = 1
         LIMIT 1"
    )->fetch_assoc()['id'];
    PuntoVentaReservacionService::cerrarTicket($walkRaceTicket, 'efectivo', 0, [], 1);

    $noShowRaceId = e3Reservation($db, $today, '00:00:00', 2);
    $noShowRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $noShowRaceId, 'usuario_id' => 1],
        ['mode' => 'no_show', 'reservacion_id' => $noShowRaceId, 'usuario_id' => 1],
    ]);
    $noShowRaceState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$noShowRaceId}"
    )->fetch_assoc()['estado'];
    e3Same(
        'no-show contra inicio tiene un ganador',
        count(array_filter($noShowRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Assert('no-show contra inicio deja estado terminal válido', in_array($noShowRaceState, ['no_show', 'en_curso'], true));
    e3Same(
        'no-show contra inicio no deja ticket parcial',
        e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$noShowRaceId}"),
        $noShowRaceState === 'en_curso' ? 1 : 0
    );
    if ($noShowRaceState === 'en_curso') {
        $ticketId = (int)$db->query(
            "SELECT id FROM tickets WHERE reservacion_id = {$noShowRaceId} LIMIT 1"
        )->fetch_assoc()['id'];
        PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0, [], 1);
    }

    $cancelRaceId = e3Reservation($db, $today, '22:00:00', 2);
    $cancelRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $cancelRaceId, 'usuario_id' => 1],
        ['mode' => 'cancel', 'reservacion_id' => $cancelRaceId, 'usuario_id' => 1],
    ]);
    $cancelRaceState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$cancelRaceId}"
    )->fetch_assoc()['estado'];
    e3Same(
        'cancelación contra inicio tiene un ganador',
        count(array_filter($cancelRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Assert('cancelación contra inicio deja estado válido', in_array($cancelRaceState, ['cancelada', 'en_curso'], true));
    e3Same(
        'cancelación contra inicio no deja ticket parcial',
        e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$cancelRaceId}"),
        $cancelRaceState === 'en_curso' ? 1 : 0
    );
    if ($cancelRaceState === 'en_curso') {
        $ticketId = (int)$db->query(
            "SELECT id FROM tickets WHERE reservacion_id = {$cancelRaceId} LIMIT 1"
        )->fetch_assoc()['id'];
        PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0, [], 1);
    }

    $ticketReservationRaceId = e3Reservation($db, $today, '21:30:00', 3);
    $ticketReservationRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $ticketReservationRaceId, 'usuario_id' => 1],
        ['mode' => 'walkin', 'mesa_id' => 3, 'usuario_id' => 1],
    ]);
    e3Same(
        'ticket contra reservación tiene un ganador',
        count(array_filter($ticketReservationRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Same(
        'ticket contra reservación deja una ocupación',
        e3Count($db, "SELECT COUNT(DISTINCT t.id) total FROM tickets t JOIN ticket_mesas tm ON tm.ticket_id=t.id WHERE t.estado='abierto' AND tm.mesa_id=3"),
        1
    );

    $duplicadoRace = e3Race($databaseName, [
        [
            'mode' => 'public_create',
            'fecha' => '2026-12-06',
            'hora' => '12:00',
            'contacto' => 'race.duplicate@example.test',
            'request_token' => 'e4-race-duplicate-a01',
        ],
        [
            'mode' => 'public_create',
            'fecha' => '2026-12-06',
            'hora' => '12:00',
            'contacto' => 'race.duplicate@example.test',
            'request_token' => 'e4-race-duplicate-b01',
        ],
    ]);
    e3Same(
        'duplicado público simultáneo tiene un ganador',
        count(array_filter($duplicadoRace, static fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Same(
        'duplicado público simultáneo tiene un rechazo específico',
        count(array_filter(
            $duplicadoRace,
            static fn(array $r): bool => ($r['codigo'] ?? '') === ReservacionPublicaService::RESERVACION_DUPLICADA
        )),
        1
    );
    e3Same(
        'duplicado público simultáneo deja una sola fila',
        e3Count(
            $db,
            "SELECT COUNT(*) total
             FROM reservaciones
             WHERE contacto='race.duplicate@example.test'
               AND fecha='2026-12-06'
               AND hora='12:00:00'"
        ),
        1
    );

    $scheduleDate = '2026-12-01';
    $scheduleDay = (int)(new DateTimeImmutable($scheduleDate))->format('N');
    $scheduleBeforeRace = HorarioOperacionService::obtenerHorarioSemanal();
    $availableForRace = ReservacionService::obtenerHorariosDisponiblesParaFecha($scheduleDate, true);
    $scheduleRace = e3Race($databaseName, [
        ['mode' => 'schedule_close', 'dia_semana' => $scheduleDay, 'usuario_id' => 1],
        [
            'mode' => 'reserve',
            'fecha' => $scheduleDate,
            'hora' => (string)($availableForRace['horarios'][0] ?? '12:00:00'),
            'contacto' => 'e3.schedule.race@example.test',
            'request_token' => 'e3-schedule-race-0001',
        ],
    ]);
    e3Assert('horario contra creación guarda cierre', (bool)($scheduleRace[0]['ok'] ?? false));
    e3Same('horario contra creación resuelve fecha cerrada', HorarioOperacionService::obtenerHorarioEfectivo($scheduleDate)['abierto'], false);
    e3Assert(
        'horario contra creación no deja más de una reservación',
        e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE contacto='e3.schedule.race@example.test'") <= 1
    );
    e3Assert('restaurar horario después de carrera', HorarioOperacionService::guardarHorarioSemanal($scheduleBeforeRace, 1, true)['ok']);

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo "OK: {$tests} comprobaciones de Etapa 3." . PHP_EOL;
} finally {
    if ($db instanceof mysqli) {
        // La base sólo se conserva bajo opt-in para pruebas visuales locales.
        if (!$keepDatabase) {
            $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
        }
        $db->close();
    }
}
