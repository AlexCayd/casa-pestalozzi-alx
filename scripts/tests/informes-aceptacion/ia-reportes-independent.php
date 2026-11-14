<?php
require __DIR__ . '/informes-db-bootstrap.php';

$db = pdfTestDb();
$ids = json_decode(file_get_contents(pdfTestTemp() . '/fixture-ids.json'), true);
$server = null; $curl = null; $pipes = []; $count = 0;
$restaurante = (int) $ids['units']['restaurante'];
$reader = (int) $ids['users']['reader'];
$before = pdfTestSnapshot($db);
try {
    // Permisos exclusivos de esta prueba; al terminar se retiran.
    foreach (['reader' => 'restaurante', 'wrong' => 'restaurante', 'bakery' => 'panaderia', 'global' => 'global'] as $actor => $ctx) {
        $db->execute_query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,'ia','ver')", [$ids['users'][$actor], $ids['units'][$ctx]]);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
    if (!$socket) throw new RuntimeException('No hay puerto local disponible');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $token = bin2hex(random_bytes(32));
    $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $port, '-t', PDF_TEST_ROOT . '/public', __DIR__ . '/informes-http-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', pdfTestTemp() . '/ia-server.log', 'a'], 2 => ['file', pdfTestTemp() . '/ia-server.log', 'a']],
        $pipes, PDF_TEST_ROOT, array_merge(getenv(), pdfTestEnv(), ['PDF_TEST_SESSION_TOKEN' => $token]));
    if (!is_resource($server)) throw new RuntimeException('No se pudo iniciar el servidor');
    fclose($pipes[0]); $pipes = [];
    for ($i = 0; $i < 60; $i++) { usleep(100000); $ready = @fsockopen('127.0.0.1', $port, $eno, $estr, .1); if ($ready) { fclose($ready); break; } }
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['X-PDF-Test-Token: ' . $token], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 30]);
    $request = static function (string $path, bool $reset = false) use ($curl, $port): array {
        if ($reset) curl_setopt($curl, CURLOPT_COOKIELIST, 'ALL');
        curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1:' . $port . $path);
        $body = curl_exec($curl);
        if (!is_string($body)) throw new RuntimeException(curl_error($curl));
        return ['code' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body, 'json' => json_decode($body, true)];
    };
    $check = static function (bool $ok, string $label) use (&$count): void { pdfTestAssert($ok, $label); $count++; };
    $api = '/admin/api/consultas?';
    $q = ['intencion' => 'informe_finanzas', 'periodo' => 'mes_pasado'];
    $check(in_array($request($api . http_build_query($q), true)['code'], [302, 401, 403], true), 'IA informe anónimo');
    $request('/__fixture/session?actor=reader', true);
    $page = $request('/admin/ia');
    $borradorInforme = static function (string $html): array {
        $dom = new DOMDocument(); $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors(); libxml_use_internal_errors($prev);
        $boton = (new DOMXPath($dom))->query('//button[@data-ia-base="generar_informe"]')->item(0);
        return $boton ? json_decode($boton->getAttribute('data-ia-borrador'), true, 512, JSON_THROW_ON_ERROR) : [];
    };
    $check($page['code'] === 200 && substr_count($page['body'], 'data-ia-base="generar_informe"') === 1, 'Una sola píldora Generar informe');
    $borrador = $borradorInforme($page['body']);
    $check(count($borrador['partes'][1]['opciones'] ?? []) === 5 && count($borrador['partes'][3]['opciones'] ?? []) === 10, 'Cinco tipos y nueve periodos más Personalizado');
    $check(substr_count($page['body'], 'data-analytics-range-picker') === 1 && str_contains($page['body'], 'data-max-days="366"') && str_contains($page['body'], 'data-show-presets="0"') && !preg_match('/<input[^>]+type="date"/i', $page['body']), 'Calendario compartido único sin inputs date ni presets adicionales');
    file_put_contents(pdfTestTemp() . '/ia-reportes-page.html', $page['body']);
    $paths = ['finanzas' => 'finanzas', 'analytics' => 'analytics', 'reservaciones' => 'reservaciones', 'inventario' => 'almacenes', 'tickets' => 'tickets'];
    pdfTestLoadApp();
    foreach ($paths as $intent => $path) {
        foreach (Services\Analytics\ConsultasRapidas::PERIODOS as $periodo => $label) {
            $r = $request($api . http_build_query(['intencion' => 'informe_' . $intent, 'periodo' => $periodo]));
            $p = Services\Analytics\ConsultasRapidas::periodo($periodo);
            $expected = '/admin/' . $path . '/informe?' . http_build_query(['desde' => $p['desde'], 'hasta' => $p['hasta']]);
            $check($r['code'] === 200 && ($r['json']['informe']['url'] ?? null) === $expected, 'Presets compartidos ' . $intent . '/' . $periodo);
        }
        $r = $request($api . http_build_query(['intencion' => 'informe_' . $intent, 'periodo' => 'personalizado', 'desde' => '2024-01-01', 'hasta' => '2024-12-31']));
        $check($r['code'] === 200 && str_ends_with($r['json']['informe']['url'] ?? '', 'desde=2024-01-01&hasta=2024-12-31'), '366 días inclusivos ' . $intent);
        // Ejecuta la expresión real del cliente antes de seguir la URL entregada por la API.
        $url = $r['json']['informe']['url'] ?? '';
        $command = ['node', PDF_TEST_ROOT . '/scripts/tests/validar-url-informe.cjs', $url];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $jsPipes, PDF_TEST_ROOT);
        if (!is_resource($process)) throw new RuntimeException('No se pudo ejecutar la validación JavaScript');
        fclose($jsPipes[0]); $jsOut = stream_get_contents($jsPipes[1]); $jsError = stream_get_contents($jsPipes[2]);
        fclose($jsPipes[1]); fclose($jsPipes[2]);
        $check(proc_close($process) === 0, 'Validación JavaScript ' . $intent . ': ' . $jsOut . $jsError);
        $pdf = $request($url);
        $check($pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF-'), 'API → JavaScript → PDF ' . $intent);

    }
    foreach ([
        ['periodo' => 'personalizado'],
        ['periodo' => 'personalizado', 'desde' => '2026-02-30', 'hasta' => '2026-03-01'],
        ['periodo' => 'personalizado', 'desde' => '2026-03-02', 'hasta' => '2026-03-01'],
        ['periodo' => 'personalizado', 'desde' => '2024-01-01', 'hasta' => '2025-01-01'],
        ['periodo' => 'personalizado', 'desde' => ['2024-01-01'], 'hasta' => '2024-02-01'],
        ['periodo' => ['hoy']], ['periodo' => '8s'], ['periodo' => 'hoy', 'desde' => '2024-01-01'],
        ['intencion' => 'informe_desconocido'], ['intencion' => ['informe_finanzas']],
        ['unidad_id' => 2], ['negocio' => 'panaderia'], ['url' => 'https://example.com'],
    ] as $bad) {
        $r = $request($api . http_build_query(array_replace($q, $bad)));
        $check($r['code'] === 400 && empty($r['json']['ok']), 'Solicitud manipulada rechazada');
    }
    $ordinary = $request($api . 'intencion=ventas_total&periodo=hoy');
    $check($ordinary['code'] === 200 && !isset($ordinary['json']['informe']) && isset($ordinary['json']['respuesta']), 'Consulta convencional intacta');
    $caps = $request('/admin/api/consultas/capacidades');
    $check($caps['code'] === 200 && !str_contains($caps['body'], 'personalizado') && !str_contains($caps['body'], 'informe_finanzas'), 'Motor convencional sin periodos/intenciones PDF');
    $db->execute_query("DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo='finanzas'", [$reader, $restaurante]);
    $check($request($api . http_build_query($q))['code'] === 403, 'Revocación después de catálogo');
    $page = $request('/admin/ia');
    $borrador = $borradorInforme($page['body']);
    $tipos = array_column($borrador['partes'][1]['opciones'] ?? [], 'valor');
    $check(substr_count($page['body'], 'data-ia-base="generar_informe"') === 1 && count($tipos) === 4 && !in_array('informe_finanzas', $tipos, true), 'Catálogo único refresca tipos según permisos');
    $db->execute_query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,'finanzas','ver')", [$reader, $restaurante]);
    $db->execute_query("DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo='ia'", [$reader, $restaurante]);
    $check($request($api . http_build_query($q))['code'] === 403, 'Módulo PDF sin permiso IA');
    foreach (['wrong' => '/admin', 'bakery' => '/admin/panaderia', 'global' => '/admin/global'] as $actor => $base) {
        $request('/__fixture/session?actor=' . $actor, true);
        $page = $request($base . '/ia');
        $check($page['code'] === 200 && !str_contains($page['body'], 'data-ia-base="generar_informe"'), 'Sin reportes en contexto/permiso ' . $actor);
        $check($request($base . '/api/consultas?' . http_build_query($q))['code'] === 403, 'API deniega ' . $actor);
    }
    $request('/__fixture/session?actor=nip', true);
    $check(in_array($request($api . http_build_query($q))['code'], [302, 401, 403], true), 'NIP no genera informe');
    $check($before === pdfTestSnapshot($db), 'IA no modifica hechos de negocio');
    echo 'IA Reportes independiente PASS ' . $count . " comprobaciones: 5x9 presets, 366d, rangos, catálogo, permisos, revocación, aislamiento y consultas previas.\n";
} finally {
    if ($curl) curl_close($curl);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($ids['users'] as $id) $db->execute_query("DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND modulo='ia'", [$id]);
    // Restaurar permiso de seed incluso si falló una comprobación después de revocarlo.
    $db->execute_query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,'finanzas','ver') ON DUPLICATE KEY UPDATE nivel='ver'", [$reader, $restaurante]);
    $db->close();
}
