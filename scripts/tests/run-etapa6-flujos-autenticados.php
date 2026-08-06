<?php

declare(strict_types=1);

/**
 * Etapa 6: smoke test HTTP autenticado contra una base temporal.
 *
 * El runner levanta el front controller real, inicia sesión por los mismos
 * formularios que usa el personal, conserva cookies y obtiene los tokens CSRF
 * de las vistas. Nunca modifica la base configurada en includes/.env.
 */

date_default_timezone_set('America/Mexico_City');

$GLOBALS['__etapa6_debug'] = in_array('--debug', $argv, true);
$GLOBALS['__etapa6_debug_path'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'etapa6-debug.log';
if (!empty($GLOBALS['__etapa6_debug'])) @unlink($GLOBALS['__etapa6_debug_path']);

require_once __DIR__ . '/../../vendor/autoload.php';

$fallos = [];
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

$componente = file_get_contents(__DIR__ . '/../../src/js/components/confirmation-modal.js') ?: '';
$estilos = file_get_contents(__DIR__ . '/../../src/scss/components/_confirmation-modal.scss') ?: '';
$GLOBALS['__etapa6_pos_js'] = file_get_contents(__DIR__ . '/../../src/js/modules/punto-de-venta.js') ?: '';
$afirmar(str_contains($componente, 'window.ConfirmationModal'), 'S1: no se encontró el componente global canónico.');
$afirmar(!str_contains($componente, 'CPConfirmationModal'), 'S2: permanece el alias ejecutable CPConfirmationModal.');
$afirmar(str_contains($estilos, 'width: clamp(560px, 64vw, 760px)'), 'S3: falta el ancho de escritorio del shell.');
$afirmar(str_contains($estilos, 'width: calc(100vw - 24px)'), 'S4: falta el ancho móvil del shell.');
$afirmar(str_contains($estilos, 'max-height: calc(100dvh - 32px)'), 'S5: falta el límite vertical de escritorio.');
$afirmar(str_contains($estilos, 'max-height: calc(100dvh - 24px)'), 'S6: falta el límite vertical móvil.');

$fuentes = [
    'src/js' => __DIR__ . '/../../src/js',
    'views' => __DIR__ . '/../../views',
];
foreach ($fuentes as $etiqueta => $ruta) {
    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta));
    foreach ($iterador as $archivo) {
        if (!$archivo->isFile() || !in_array($archivo->getExtension(), ['js', 'php'], true)) {
            continue;
        }
        $contenido = file_get_contents($archivo->getPathname()) ?: '';
        $afirmar(!str_contains($contenido, 'CPConfirmationModal'), "S7: alias histórico en {$etiqueta}/{$archivo->getFilename()}.");
    }
}

if (!in_array('--static-only', $argv, true)) {
    $fallos = array_merge($fallos, etapa6EjecutarHttp());
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: runner autenticado de Etapa 6\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, "- {$fallo}\n");
    }
    exit(1);
}

echo in_array('--static-only', $argv, true)
    ? "PASS: contratos estáticos del shell y consumidores de Etapa 6.\n"
    : "PASS: flujos autenticados HTTP, CSRF, tickets, ausencia y capacidad de Etapa 6.\n";

/** @return array<int, string> */
function etapa6EjecutarHttp(): array
{
    $fallos = [];
    $servidorDb = null;
    $db = null;
    $proceso = null;
    $cookieAdmin = null;
    $cookiePos = null;
    $cookiePublic = null;
    $logSalida = null;
    $logError = null;

    try {
        etapa6Debug('preparando base temporal');
        $env = etapa6LeerEnv(dirname(__DIR__, 2) . '/includes/.env');
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $usuario = $env['DB_USER'] ?? 'root';
        $password = $env['DB_PASS'] ?? '';
        $baseActiva = strtolower((string)($env['DB_NAME'] ?? ''));
        $baseTemporal = 'casa_pestalozzi_tmp_etapa6_' . gmdate('Ymd_His') . '_' . random_int(100, 999);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $baseTemporal)
            || $baseTemporal === $baseActiva
            || $baseTemporal === 'casa-pestalozzi'
        ) {
            throw new RuntimeException('La protección de base temporal rechazó el nombre generado.');
        }

        $servidorDb = new mysqli($host, $usuario, $password);
        if ($servidorDb->connect_errno) {
            throw new RuntimeException('No fue posible conectar a MySQL: ' . $servidorDb->connect_error);
        }
        $servidorDb->set_charset('utf8mb4');
        if (!$servidorDb->query("CREATE DATABASE {$baseTemporal} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new RuntimeException('No fue posible crear la base temporal: ' . $servidorDb->error);
        }

        $db = new mysqli($host, $usuario, $password, $baseTemporal);
        if ($db->connect_errno) {
            throw new RuntimeException('No fue posible seleccionar la base temporal: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '-06:00'");

        $ddl = file_get_contents(dirname(__DIR__, 2) . '/database/ddl.sql');
        if (!is_string($ddl)) {
            throw new RuntimeException('No fue posible leer database/ddl.sql.');
        }
        $ddl = preg_replace('/DELIMITER \/\/.*?DELIMITER ;/s', '', $ddl) ?? $ddl;
        if (!$db->multi_query($ddl)) {
            throw new RuntimeException('Falló el DDL temporal: ' . $db->error);
        }
        while ($db->more_results()) {
            $db->next_result();
            if ($db->errno) {
                throw new RuntimeException('Falló una sentencia del DDL temporal: ' . $db->error);
            }
        }

        $zona = new DateTimeZone('America/Mexico_City');
        $ahora = new DateTimeImmutable('now', $zona);
        $hoy = $ahora->format('Y-m-d');
        $manana = $ahora->modify('+1 day')->format('Y-m-d');
        $ticketApertura = $ahora->modify('-15 minutes')->format('Y-m-d H:i:s');
        $horaAusencia = $ahora->modify('-16 minutes');
        if ($horaAusencia->format('Y-m-d') !== $hoy) {
            $horaAusencia = $ahora->setTime(0, 1, 0);
        }
        $proxima = $ahora->modify('+1 day')->setTime(12, 0, 0);
        $horaInicio = $ahora->modify('-5 minutes');

        etapa6Fixture($db, [
            'hoy' => $hoy,
            'manana' => $manana,
            'ticket_apertura' => $ticketApertura,
            'ausencia_fecha' => $hoy,
            'ausencia_hora' => $horaAusencia->format('H:i:s'),
            'proxima_fecha' => $proxima->format('Y-m-d'),
            'proxima_hora' => $proxima->format('H:i:s'),
            'inicio_fecha' => $hoy,
            'inicio_hora' => $horaInicio->format('H:i:s'),
        ]);
        if (!empty($GLOBALS['__etapa6_debug'])) {
            $diagnostico = $db->query("SELECT id, fecha, hora, estado FROM reservaciones ORDER BY id");
            $filasDiagnostico = [];
            while ($diagnostico && ($filaDiagnostico = $diagnostico->fetch_assoc())) {
                $filasDiagnostico[] = $filaDiagnostico;
            }
            etapa6Debug('fixture reservaciones: ' . json_encode($filasDiagnostico, JSON_UNESCAPED_UNICODE));
        }
        etapa6Debug('fixture cargado');

        $puerto = random_int(8090, 8170);
        $raiz = dirname(__DIR__, 2);
        $public = $raiz . DIRECTORY_SEPARATOR . 'public';
        $logSalida = tempnam(sys_get_temp_dir(), 'etapa6-out-');
        $logError = tempnam(sys_get_temp_dir(), 'etapa6-err-');
        $cookieAdmin = tempnam(sys_get_temp_dir(), 'etapa6-admin-');
        $cookiePos = tempnam(sys_get_temp_dir(), 'etapa6-pos-');
        $cookiePublic = tempnam(sys_get_temp_dir(), 'etapa6-public-');
        $php = PHP_BINARY;
        $sessionPath = str_replace('\\', '/', sys_get_temp_dir());
        $comando = [
            $php,
            '-d',
            'variables_order=EGPCS',
            '-d',
            'session.save_path=' . $sessionPath,
            '-S',
            '127.0.0.1:' . $puerto,
            '-t',
            $public,
            $public . DIRECTORY_SEPARATOR . 'index.php',
        ];
        $descriptor = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $logSalida, 'a'],
            2 => ['file', $logError, 'a'],
        ];
        $entornoProceso = array_merge(etapa6EntornoProceso(), [
            'DB_HOST' => $host,
            'DB_USER' => $usuario,
            'DB_PASS' => $password,
            'DB_NAME' => $baseTemporal,
            'APP_ENV' => 'testing',
            'CONTACT_OTP_PREVIEW' => '1',
        ]);
        $proceso = proc_open($comando, $descriptor, $pipes, $raiz, $entornoProceso, ['bypass_shell' => true]);
        if (!is_resource($proceso)) {
            throw new RuntimeException('No fue posible iniciar el servidor PHP temporal.');
        }
        etapa6Debug('servidor iniciado en ' . $puerto);

        $baseUrl = 'http://127.0.0.1:' . $puerto;
        $listo = false;
        for ($intento = 0; $intento < 10; $intento++) {
            $respuesta = etapa6Http($baseUrl, null, 'GET', '/login');
            if (($respuesta['status'] ?? 0) > 0) {
                $listo = true;
                break;
            }
            usleep(100000);
        }
        if (!$listo) {
            throw new RuntimeException('El servidor PHP temporal no respondió.');
        }
        etapa6Debug('servidor responde');

        $afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
            if (!$condicion) {
                $fallos[] = $mensaje;
            }
        };

        // A1 — login admin, vista operativa y CSRF administrativo real.
        $loginAdmin = etapa6Http($baseUrl, $cookieAdmin, 'POST', '/admin/login', [
            'username' => 'etapa6_admin',
            'password' => 'Etapa6Admin1!',
        ]);
        etapa6Debug('login admin: ' . (string)($loginAdmin['status'] ?? 0));
        $afirmar(($loginAdmin['status'] ?? 0) === 302 && str_contains((string)($loginAdmin['location'] ?? ''), '/admin'), 'A1: el login administrativo no redirigió a /admin.');
        $operation = etapa6Http($baseUrl, $cookieAdmin, 'GET', '/admin/reservations/operation?fecha=' . rawurlencode($hoy) . '&hora=15:00');
        $adminCsrf = etapa6ExtraerAtributo((string)($operation['body'] ?? ''), 'data-admin-csrf');
        $afirmar(($operation['status'] ?? 0) === 200 && $adminCsrf !== '', 'A1: no se obtuvo la vista operativa o su CSRF real.');
        $operationApi = etapa6Json($baseUrl, $cookieAdmin, 'GET', '/admin/api/reservations/operation?fecha=' . rawurlencode($hoy) . '&hora=15:00');
        etapa6Debug('A1 operación API resumen: ' . json_encode([
            'reservaciones' => array_map(
                static fn($reserva): int => (int)($reserva['id'] ?? $reserva['reservacion_id'] ?? 0),
                (array)($operationApi['json']['reservaciones'] ?? [])
            ),
            'codigo' => $operationApi['json']['codigo'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
        $afirmar(($operationApi['status'] ?? 0) === 200 && is_array($operationApi['json'] ?? null), 'A1: el endpoint operativo autenticado no devolvió HTTP 200 JSON.');

        // A2 — login POS por NIP, vista y CSRF de personal.
        $loginPos = etapa6Http($baseUrl, $cookiePos, 'POST', '/login', ['nip' => '2468']);
        etapa6Debug('login pos: ' . (string)($loginPos['status'] ?? 0));
        $afirmar(($loginPos['status'] ?? 0) === 302 && str_contains((string)($loginPos['location'] ?? ''), '/punto-de-venta'), 'A2: el login POS no redirigió al mapa.');
        $posPage = etapa6Http($baseUrl, $cookiePos, 'GET', '/punto-de-venta');
        $staffCsrf = etapa6ExtraerAtributo((string)($posPage['body'] ?? ''), 'data-staff-csrf');
        $afirmar(($posPage['status'] ?? 0) === 200 && $staffCsrf !== '', 'A2: no se obtuvo la vista POS o su CSRF real.');
        $mapa = etapa6Json($baseUrl, $cookiePos, 'GET', '/api/punto-de-venta?fecha=' . rawurlencode($hoy));
        $afirmar(($mapa['status'] ?? 0) === 200 && (($mapa['json']['ok'] ?? false) === true), 'A2: el mapa POS autenticado no devolvió HTTP 200 ok.');

        // A3 — cualquiera de las dos mesas del ticket multimesa resuelve 100.
        foreach ([7, 8] as $mesaId) {
            $contexto = etapa6Json($baseUrl, $cookiePos, 'GET', '/api/punto-de-venta/mesa-contexto?mesa_id=' . $mesaId);
            etapa6Debug('A3 contexto ' . $mesaId . ': ' . json_encode($contexto['json'] ?? null, JSON_UNESCAPED_UNICODE));
            $afirmar(($contexto['status'] ?? 0) === 200, "A3: mesa {$mesaId} no respondió HTTP 200.");
            $afirmar((int)($contexto['json']['ticket_abierto']['id'] ?? 0) === 100, "A3: mesa {$mesaId} no resolvió el ticket 100.");
            $afirmar(($contexto['json']['puede_abrir_ticket'] ?? true) === false, "A3: mesa {$mesaId} aún permite abrir ticket.");
            $afirmar(($contexto['json']['accion_primaria'] ?? '') === 'CONSULTAR_TICKET', "A3: mesa {$mesaId} no expuso CONSULTAR_TICKET como acción primaria.");
        }
        $ticketsMapa = (array)($mapa['json']['tickets'] ?? []);
        $ticketMapa = null;
        foreach ($ticketsMapa as $ticket) {
            if ((int)($ticket['id'] ?? 0) === 100) {
                $ticketMapa = $ticket;
                break;
            }
        }
        $afirmar(is_array($ticketMapa) && in_array(7, array_map('intval', (array)($ticketMapa['mesa_ids'] ?? [])), true)
            && in_array(8, array_map('intval', (array)($ticketMapa['mesa_ids'] ?? [])), true), 'A3: el mapa no conservó la relación multimesa 7/8.');

        // A4 — apertura duplicada: rechazo canónico y cero tickets nuevos.
        $duplicado = etapa6Json($baseUrl, $cookiePos, 'POST', '/api/abrir-ticket', [
            'mesa_ids' => [7],
            'comensales' => 2,
            'csrf_token' => $staffCsrf,
        ], ['X-CSRF-Token: ' . $staffCsrf]);
        $afirmar(($duplicado['json']['codigo'] ?? '') === 'TICKET_ABIERTO', 'A4: la apertura duplicada no devolvió TICKET_ABIERTO.');
        $afirmar((int)($duplicado['json']['ticket_id'] ?? 0) === 100, 'A4: el rechazo no identificó el ticket existente.');
        $afirmar(($duplicado['json']['ok'] ?? true) === false, 'A4: la apertura duplicada no fue rechazada.');
        $totalTickets = (int)etapa6Valor($db, 'SELECT COUNT(*) FROM tickets');
        $afirmar($totalTickets === 1, 'A4: la apertura duplicada creó un ticket paralelo.');

        // A5 — reservación próxima; el contrato contiene causa y decisión.
        $proximas = etapa6Json($baseUrl, $cookiePos, 'GET', '/api/punto-de-venta/reservaciones?fecha=' . rawurlencode($manana));
        $proximaEncontrada = null;
        foreach ((array)($proximas['json']['reservaciones'] ?? []) as $reserva) {
            if ((int)($reserva['id'] ?? 0) === 201) {
                $proximaEncontrada = $reserva;
                break;
            }
        }
        $afirmar(is_array($proximaEncontrada), 'A5: la reservación próxima no apareció en el listado autenticado.');
        $afirmar(is_array($proximaEncontrada) && (string)($proximaEncontrada['hora'] ?? '') !== '', 'A5: la reservación próxima no expuso hora para el resumen.');
        $afirmar(is_array($proximaEncontrada) && is_array($proximaEncontrada['mesa_ids'] ?? null), 'A5: la reservación próxima no expuso sus mesas para el resumen.');
        $afirmar(str_contains($GLOBALS['__etapa6_pos_js'] ?? '', 'Hay una reservación próxima'), 'A5: el consumidor POS no contiene el título canónico de reservación próxima.');

        // A6/A7 — ausencia pendiente, POST con CSRF y repetición idempotente.
        $reservacionesHoy = etapa6Json($baseUrl, $cookiePos, 'GET', '/api/punto-de-venta/reservaciones?fecha=' . rawurlencode($hoy));
        $ausencia = null;
        $inicio = null;
        foreach ((array)($reservacionesHoy['json']['reservaciones'] ?? []) as $reserva) {
            if ((int)($reserva['id'] ?? 0) === 202) $ausencia = $reserva;
            if ((int)($reserva['id'] ?? 0) === 203) $inicio = $reserva;
        }
        etapa6Debug('A6 respuesta: ' . json_encode($reservacionesHoy['json'] ?? null, JSON_UNESCAPED_UNICODE));
        etapa6Debug('A6 ausencia: ' . json_encode($ausencia, JSON_UNESCAPED_UNICODE));
        $afirmar(is_array($ausencia) && ($ausencia['accion_pendiente'] ?? '') === 'REGISTRAR_AUSENCIA', 'A6: la ausencia no aparece como acción pendiente.');
        $afirmar(is_array($ausencia) && ($ausencia['puede_iniciar_servicio'] ?? true) === false, 'A6: una ausencia pendiente todavía permite iniciar servicio.');
        $noShow1 = etapa6Json($baseUrl, $cookiePos, 'POST', '/api/punto-de-venta/reservaciones/no-show', [
            'reservacion_id' => 202,
            'csrf_token' => $staffCsrf,
        ], ['X-CSRF-Token: ' . $staffCsrf]);
        $noShow2 = etapa6Json($baseUrl, $cookiePos, 'POST', '/api/punto-de-venta/reservaciones/no-show', [
            'reservacion_id' => 202,
            'csrf_token' => $staffCsrf,
        ], ['X-CSRF-Token: ' . $staffCsrf]);
        $afirmar(($noShow1['json']['ok'] ?? false) === true, 'A7: el primer registro de no-show no confirmó.');
        $afirmar(($noShow2['json']['ok'] ?? false) === true && ($noShow2['json']['idempotente'] ?? false) === true, 'A7: repetir no-show no fue idempotente.');
        $estadoAusencia = (string)etapa6Valor($db, 'SELECT estado FROM reservaciones WHERE id = 202');
        $afirmar($estadoAusencia === 'no_show', 'A7: la reservación de ausencia no quedó en no_show.');

        // Flujo de inicio de servicio desde reservación autenticada.
        $inicioRespuesta = etapa6Json($baseUrl, $cookiePos, 'POST', '/api/punto-de-venta/reservaciones/comenzar', [
            'reservacion_id' => 203,
            'csrf_token' => $staffCsrf,
        ], ['X-CSRF-Token: ' . $staffCsrf]);
        etapa6Debug('A7 inicio respuesta: ' . json_encode($inicioRespuesta['json'] ?? null, JSON_UNESCAPED_UNICODE));
        $afirmar(($inicioRespuesta['json']['ok'] ?? false) === true && (int)($inicioRespuesta['json']['ticket_id'] ?? 0) > 0, 'A7b: iniciar servicio no creó ticket para la reservación elegible.');

        // A8 — advertencia y confirmación administrativa sin mesas.
        $datosSinMesas = etapa6DatosAdmin($manana, 'etapa6_sin_mesas_' . bin2hex(random_bytes(4)), 2);
        $sinMesas1 = etapa6FormJson($baseUrl, $cookieAdmin, 'POST', '/admin/reservations/create', array_merge($datosSinMesas, [
            'admin_csrf' => $adminCsrf,
            'response_format' => 'json',
        ]));
        $sinMesas2 = etapa6FormJson($baseUrl, $cookieAdmin, 'POST', '/admin/reservations/create', array_merge($datosSinMesas, [
            'admin_csrf' => $adminCsrf,
            'response_format' => 'json',
            'confirmaciones' => ['SIN_ASIGNACION'],
        ]));
        $afirmar(in_array('SIN_ASIGNACION', (array)($sinMesas1['json']['requiredConfirmations'] ?? []), true), 'A8: crear sin mesas no pidió confirmación explícita.');
        $idSinMesas = (int)($sinMesas2['json']['reservationId'] ?? 0);
        $afirmar(($sinMesas2['json']['success'] ?? false) === true && $idSinMesas > 0, 'A8: confirmar sin mesas no guardó la reservación.');
        $afirmar((int)etapa6Valor($db, 'SELECT COUNT(*) FROM reservacion_mesas WHERE reservacion_id = ' . $idSinMesas) === 0, 'A8: confirmar sin mesas creó una asignación física.');

        // A9 — sobrecapacidad: primer POST sin bandera, segundo con bandera.
        $datosSobre = etapa6DatosAdmin($manana, 'etapa6_sobre_' . bin2hex(random_bytes(4)), 44);
        $sobre1 = etapa6FormJson($baseUrl, $cookieAdmin, 'POST', '/admin/reservations/create', array_merge($datosSobre, [
            'admin_csrf' => $adminCsrf,
            'response_format' => 'json',
        ]));
        $sobre2 = etapa6FormJson($baseUrl, $cookieAdmin, 'POST', '/admin/reservations/create', array_merge($datosSobre, [
            'admin_csrf' => $adminCsrf,
            'response_format' => 'json',
            'confirmar_sobrecapacidad' => '1',
        ]));
        $afirmar(($sobre1['json']['requiresCapacityConfirmation'] ?? false) === true, 'A9: la sobrecapacidad no exigió una decisión explícita.');
        etapa6Debug('A9 primera: ' . json_encode($sobre1['json'] ?? null, JSON_UNESCAPED_UNICODE));
        etapa6Debug('A9 segunda: ' . json_encode($sobre2['json'] ?? null, JSON_UNESCAPED_UNICODE));
        $idSobre = (int)($sobre2['json']['reservationId'] ?? 0);
        $afirmar(($sobre2['json']['success'] ?? false) === true && $idSobre > 0, 'A9: confirmar sobrecapacidad no guardó la reservación.');
        $afirmar((int)etapa6Valor($db, 'SELECT COUNT(*) FROM reservacion_mesas WHERE reservacion_id = ' . $idSobre) === 0, 'A9: la sobrecapacidad confirmada asignó mesas automáticamente.');

        // A10 — propuesta pública, revisión y confirmación del reemplazo.
        $fechaPropuestaPublica = $ahora->modify('+2 days')->format('Y-m-d');
        $paginaPublica = etapa6Http($baseUrl, $cookiePublic, 'GET', '/');
        $publicCsrf = etapa6ExtraerAtributo((string)($paginaPublica['body'] ?? ''), 'data-reservation-csrf');
        $afirmar(($paginaPublica['status'] ?? 0) === 200 && $publicCsrf !== '', 'A10: no se obtuvo el CSRF público real.');
        $otpSolicitud = etapa6Json($baseUrl, $cookiePublic, 'POST', '/api/reservaciones/contacto/codigo', [
            'tipo' => 'email',
            'contacto' => 'etapa6@example.test',
            'csrf_token' => $publicCsrf,
        ], ['X-CSRF-Token: ' . $publicCsrf]);
        $codigoOtp = (string)($otpSolicitud['json']['preview_code'] ?? '');
        $afirmar(($otpSolicitud['json']['ok'] ?? false) === true && preg_match('/^\d{6}$/', $codigoOtp) === 1, 'A10: no se obtuvo el OTP de prueba controlado por entorno.');
        $otpVerificacion = etapa6Json($baseUrl, $cookiePublic, 'POST', '/api/reservaciones/contacto/verificar', [
            'tipo' => 'email',
            'contacto' => 'etapa6@example.test',
            'codigo' => $codigoOtp,
            'csrf_token' => $publicCsrf,
        ], ['X-CSRF-Token: ' . $publicCsrf]);
        $afirmar(($otpVerificacion['json']['ok'] ?? false) === true, 'A10: la verificación pública autenticada no creó la sesión de contacto.');
        $misReservaciones = etapa6Json($baseUrl, $cookiePublic, 'GET', '/api/reservaciones/mis-reservaciones');
        $publicaOriginal = null;
        foreach ((array)($misReservaciones['json']['reservations'] ?? []) as $reserva) {
            if ((int)($reserva['id'] ?? 0) === 204) {
                $publicaOriginal = $reserva;
                break;
            }
        }
        $afirmar(is_array($publicaOriginal), 'A10: la sesión pública no pudo leer la reservación original.');
        $tokenPublico = 'etapa6_public_' . bin2hex(random_bytes(12));
        $propuesta = etapa6Json($baseUrl, $cookiePublic, 'POST', '/api/reservaciones/modificar', [
            'reservacion_id' => 204,
            'fecha' => $fechaPropuestaPublica,
            'hora' => '16:00',
            'personas' => 2,
            'notas' => 'Cambio autenticado Etapa 6',
            'request_token' => $tokenPublico,
            'csrf_token' => $publicCsrf,
        ], ['X-CSRF-Token: ' . $publicCsrf]);
        $afirmar(($propuesta['json']['ok'] ?? false) === true, 'A10: crear la propuesta pública no devolvió una revisión pendiente.');
        $afirmar((string)($propuesta['json']['request_token'] ?? $tokenPublico) === $tokenPublico, 'A10: la propuesta pública no conservó el request_token.');
        $confirmacionPublica = etapa6Json($baseUrl, $cookiePublic, 'POST', '/api/reservaciones/confirmar-modificacion', [
            'request_token' => $tokenPublico,
            'csrf_token' => $publicCsrf,
        ], ['X-CSRF-Token: ' . $publicCsrf]);
        $afirmar(($confirmacionPublica['json']['ok'] ?? false) === true, 'A10: confirmar la modificación pública no completó el reemplazo.');
        $estadoOriginalPublico = (string)etapa6Valor($db, 'SELECT estado FROM reservaciones WHERE id = 204');
        $estadoReemplazoPublico = (string)etapa6Valor($db, "SELECT estado FROM reservaciones WHERE reemplaza_reservacion_id = 204 ORDER BY id DESC LIMIT 1");
        $afirmar($estadoOriginalPublico === 'reemplazada' && $estadoReemplazoPublico === 'confirmada', 'A10: los estados del reemplazo público no quedaron atómicos.');
    } catch (Throwable $error) {
        etapa6Debug('excepción: ' . $error->getMessage());
        $fallos[] = 'Prueba dinámica bloqueada: ' . $error->getMessage();
    } finally {
        etapa6Debug('limpieza inicia');
        if (is_resource($proceso)) {
            $estadoProceso = proc_get_status($proceso);
            etapa6Debug('pid proceso: ' . (string)($estadoProceso['pid'] ?? 0));
            proc_terminate($proceso);
            $pidProceso = (int)($estadoProceso['pid'] ?? 0);
            if (PHP_OS_FAMILY === 'Windows' && $pidProceso > 0) {
                exec('taskkill /PID ' . $pidProceso . ' /T /F > NUL 2>&1');
            }
            foreach (($pipes ?? []) as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            // `proc_close()` puede esperar a un hijo de PHP que ya fue
            // terminado con taskkill; el runner debe entregar el resultado
            // funcional sin quedar bloqueado en la limpieza.
            $proceso = null;
            etapa6Debug('proceso terminado sin espera bloqueante');
        }
        if ($db instanceof mysqli) {
            $db->close();
            etapa6Debug('base fixture desconectada');
        }
        foreach ([$cookieAdmin, $cookiePos, $cookiePublic, $logSalida, $logError] as $temporal) {
            if (is_string($temporal) && is_file($temporal)) @unlink($temporal);
        }
        if ($servidorDb instanceof mysqli) {
            if (!empty($baseTemporal ?? null)) {
                $servidorDb->query("DROP DATABASE IF EXISTS {$baseTemporal}");
                etapa6Debug('base temporal eliminada');
            }
            $servidorDb->close();
        }
    }

    etapa6Debug('fallos acumulados: ' . json_encode($fallos, JSON_UNESCAPED_UNICODE));
    return $fallos;
}

/** @param array<string, string> $fechas */
function etapa6Fixture(mysqli $db, array $fechas): void
{
    $adminHash = password_hash('Etapa6Admin1!', PASSWORD_DEFAULT);
    $nipHash = password_hash('2468', PASSWORD_DEFAULT);
    $adminHashSql = $db->real_escape_string($adminHash);
    $nipHashSql = $db->real_escape_string($nipHash);
    if (!$db->query("INSERT INTO usuarios (id, username, nombre, password_hash, nip_hash, rol, activo)
        VALUES (1, 'etapa6_admin', 'Admin Etapa 6', '{$adminHashSql}', NULL, 'admin', 1),
               (2, 'etapa6_waiter', 'Mesero Etapa 6', '{$adminHashSql}', '{$nipHashSql}', 'waiter', 1)")) {
        throw new RuntimeException('Falló fixture de usuarios: ' . $db->error);
    }

    $horarios = [];
    for ($dia = 0; $dia <= 6; $dia++) {
        $horarios[] = "({$dia},1,'00:00:00','23:59:59')";
    }
    if (!$db->query('INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES ' . implode(',', $horarios))) {
        throw new RuntimeException('Falló fixture de horarios: ' . $db->error);
    }

    $mesas = [];
    for ($id = 1; $id <= 11; $id++) {
        $mesas[] = "({$id},{$id},'Mesa {$id}','mesa',4,{$id},10,1,1)";
    }
    if (!$db->query('INSERT INTO mesas (id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable) VALUES ' . implode(',', $mesas))) {
        throw new RuntimeException('Falló fixture de mesas: ' . $db->error);
    }
    if (!$db->query("INSERT INTO areas_produccion (id, nombre, slug, color) VALUES (1,'Cocina','cocina','#aa0000')
        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)")) {
        throw new RuntimeException('Falló fixture de áreas: ' . $db->error);
    }
    if (!$db->query("INSERT INTO categorias (id, nombre, activo) VALUES (1,'Etapa 6',1)")) {
        throw new RuntimeException('Falló fixture de categorías: ' . $db->error);
    }
    if (!$db->query("INSERT INTO productos (id, nombre, descripcion, categoria_id, precio, area_id, activo)
        VALUES (1,'Platillo Etapa 6','Fixture',1,10.00,1,1)")) {
        throw new RuntimeException('Falló fixture de productos: ' . $db->error);
    }

    $reservaciones = [
        "(201,'Reservación próxima','email','proxima@example.test','{$fechas['proxima_fecha']}','{$fechas['proxima_hora']}',4,'admin','confirmada')",
        "(202,'Ausencia pendiente','email','ausencia@example.test','{$fechas['ausencia_fecha']}','{$fechas['ausencia_hora']}',2,'admin','confirmada')",
        "(203,'Inicio de servicio','email','inicio@example.test','{$fechas['inicio_fecha']}','{$fechas['inicio_hora']}',2,'admin','confirmada')",
        "(204,'Cambio público','email','etapa6@example.test','{$fechas['manana']}','14:00:00',2,'landing','confirmada')",
    ];
    if (!$db->query('INSERT INTO reservaciones (id, nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado) VALUES ' . implode(',', $reservaciones))) {
        throw new RuntimeException('Falló fixture de reservaciones: ' . $db->error);
    }
    if (!$db->query("INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES
        (201,9,1),(202,10,1),(203,11,1),(204,6,1)")) {
        throw new RuntimeException('Falló fixture de mesas de reservación: ' . $db->error);
    }
    if (!$db->query("UPDATE reservaciones SET request_token = 'etapa6_public_original_204' WHERE id = 204")) {
        throw new RuntimeException('Falló fixture de token público: ' . $db->error);
    }
    if (!$db->query("INSERT INTO tickets (id, comensales, nombre, hora_apertura, estado, reservacion_id, mesero_id)
        VALUES (100,4,'Ticket multimesa','{$fechas['ticket_apertura']}','abierto',NULL,2)")) {
        throw new RuntimeException('Falló fixture del ticket: ' . $db->error);
    }
    if (!$db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES (100,7,1),(100,8,2)")) {
        throw new RuntimeException('Falló fixture de mesas del ticket: ' . $db->error);
    }
}

/** @return array<string, string> */
function etapa6LeerEnv(string $ruta): array
{
    $env = [];
    foreach (is_file($ruta) ? (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) continue;
        [$clave, $valor] = explode('=', $linea, 2);
        $env[trim($clave)] = trim(trim($valor), "\"'");
    }
    return $env;
}

/** @return array<string, string> */
function etapa6EntornoProceso(): array
{
    $entorno = [];
    foreach (getenv() ?: [] as $clave => $valor) {
        if (is_string($valor)) $entorno[$clave] = $valor;
    }
    return $entorno;
}

/** @return array<string, mixed> */
function etapa6Http(string $baseUrl, ?string $cookie, string $metodo, string $ruta, $datos = null, array $headers = []): array
{
    etapa6Debug($metodo . ' ' . $ruta . ' inicio');
    $curl = curl_init($baseUrl . $ruta);
    if ($curl === false) throw new RuntimeException('No se pudo inicializar cURL.');
    $headers[] = 'Accept: application/json, text/html;q=0.9';
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($cookie) {
        $opciones[CURLOPT_COOKIEFILE] = $cookie;
        $opciones[CURLOPT_COOKIEJAR] = $cookie;
    }
    if ($datos !== null) {
        $esJson = false;
        foreach ($headers as $header) {
            if (stripos($header, 'content-type: application/json') === 0) $esJson = true;
        }
        if ($esJson) {
            $opciones[CURLOPT_POSTFIELDS] = is_string($datos) ? $datos : json_encode($datos, JSON_UNESCAPED_UNICODE);
        } else {
            $opciones[CURLOPT_POSTFIELDS] = is_string($datos) ? $datos : http_build_query((array)$datos);
        }
    }
    curl_setopt_array($curl, $opciones);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    if ($raw === false) {
        etapa6Debug($metodo . ' ' . $ruta . ' error ' . $error);
        return ['status' => $status, 'body' => '', 'headers' => '', 'error' => $error];
    }
    $headersRaw = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    preg_match('/^Location:\s*(.+)$/mi', $headersRaw, $ubicacion);
    etapa6Debug($metodo . ' ' . $ruta . ' status ' . $status);
    return [
        'status' => $status,
        'headers' => $headersRaw,
        'body' => $body,
        'location' => trim((string)($ubicacion[1] ?? '')),
    ];
}

/** @return array<string, mixed> */
function etapa6Json(string $baseUrl, ?string $cookie, string $metodo, string $ruta, $datos = null, array $headers = []): array
{
    $headers[] = 'Content-Type: application/json';
    $respuesta = etapa6Http($baseUrl, $cookie, $metodo, $ruta, $datos, $headers);
    $respuesta['json'] = json_decode((string)($respuesta['body'] ?? ''), true);
    return $respuesta;
}

/** @return array<string, mixed> */
function etapa6FormJson(string $baseUrl, ?string $cookie, string $metodo, string $ruta, array $datos): array
{
    $respuesta = etapa6Http($baseUrl, $cookie, $metodo, $ruta, $datos, ['Content-Type: application/x-www-form-urlencoded']);
    $respuesta['json'] = json_decode((string)($respuesta['body'] ?? ''), true);
    return $respuesta;
}

function etapa6ExtraerAtributo(string $html, string $atributo): string
{
    return preg_match('/' . preg_quote($atributo, '/') . '=["\']([^"\']*)["\']/', $html, $coincidencia)
        ? html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8')
        : '';
}

/** @return array<string, string> */
function etapa6DatosAdmin(string $fecha, string $token, int $comensales): array
{
    return [
        'nombre' => 'Fixture Etapa 6',
        'contacto_tipo' => 'email',
        'contacto' => 'fixture@example.test',
        'fecha' => $fecha,
        'hora' => '15:00',
        'comensales' => (string)$comensales,
        'nota' => 'Prueba autenticada',
        'request_token' => $token,
        'asignar_automaticamente' => '0',
        'confirmar_sobrecapacidad' => '0',
    ];
}

function etapa6Valor(mysqli $db, string $sql)
{
    $resultado = $db->query($sql);
    if (!$resultado) throw new RuntimeException('Consulta de aserción falló: ' . $db->error);
    $fila = $resultado->fetch_row();
    $resultado->free();
    return $fila[0] ?? null;
}

function etapa6Debug(string $mensaje): void
{
    if (!empty($GLOBALS['__etapa6_debug'])) {
        $linea = date('H:i:s') . " {$mensaje}\n";
        file_put_contents((string)$GLOBALS['__etapa6_debug_path'], $linea, FILE_APPEND);
        fwrite(STDERR, "DEBUG Etapa 6: {$mensaje}\n");
    }
}
