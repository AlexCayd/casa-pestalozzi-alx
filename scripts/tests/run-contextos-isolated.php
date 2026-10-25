<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$nombre = 'cp_notifications_test_' . bin2hex(random_bytes(6));
$creada = false;
$servidor = null;
$log = tempnam(sys_get_temp_dir(), 'cp-contextos-');
$cookies = [];
$fallo = false;

function contextosSql(mysqli $db, string $sql): void {
    $delimitador = ';'; $sentencia = '';
    foreach (preg_split('/\R/', $sql) as $linea) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)/i', $linea, $m)) { $delimitador = $m[1]; continue; }
        if (preg_match('/^\s*--/', $linea) || trim($linea) === '') { continue; }
        $sentencia .= preg_replace('/;\s*--.*$/', ';', $linea) . "\n";
        if (str_ends_with(rtrim($sentencia), $delimitador)) {
            $db->query(substr(rtrim($sentencia), 0, -strlen($delimitador)));
            $sentencia = '';
        }
    }
    if (trim($sentencia) !== '') { throw new RuntimeException('SQL incompleto'); }
}

function contextosHttp(string $ruta, string $jar, ?array $datos = null, ?string $metodo = null): array {
    $curl = curl_init('http://127.0.0.1:8088' . $ruta);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
    if ($datos !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($datos)); }
    if ($metodo !== null) { curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $metodo); }
    $respuesta = curl_exec($curl);
    if ($respuesta === false) { throw new RuntimeException(curl_error($curl)); }
    $codigo = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$codigo, $respuesta];
}

try {
    $ocupado = @fsockopen('127.0.0.1', 8088);
    if ($ocupado) { fclose($ocupado); throw new RuntimeException('Puerto 8088 ocupado; no se enviaron peticiones.'); }
    $db->query("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $db->select_db($nombre);
    contextosSql($db, file_get_contents($root . '/database/ddl.sql'));
    contextosSql($db, file_get_contents($root . '/database/deploy.sql'));
    $db->query("INSERT INTO roles (slug, nombre, tipo) VALUES ('ctx_lector', 'Consulta de menú de prueba', 'personalizado')");
    $db->query("INSERT INTO rol_permisos (rol_id, modulo, nivel) SELECT id, 'menu', 'ver' FROM roles WHERE slug = 'ctx_lector'");
    $hash = password_hash('Contextos-Prueba-2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO usuarios (username, nombre, password_hash, rol, unidad_id, activo) VALUES (?, ?, ?, ?, ?, 1)');
    foreach (['ctx_super' => ['super_admin', null], 'ctx_restaurante' => ['admin', 1], 'ctx_panaderia' => ['admin', 2], 'ctx_sin_unidad' => ['admin', null], 'ctx_lector' => ['ctx_lector', 2]] as $usuario => [$rol, $unidad]) {
        $stmt->bind_param('ssssi', $usuario, $usuario, $hash, $rol, $unidad);
        $stmt->execute();
    }
    $env = array_merge(getenv(), ['CP_NOTIFICATION_TEST_DATABASE' => $nombre]);
    $servidor = proc_open([PHP_BINARY, '-S', '127.0.0.1:8088', '-t', $root . '/public', __DIR__ . '/notifications-http-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
    fclose($pipes[0]);
    for ($intento = 0; $intento < 40; $intento++) {
        $socket = @fsockopen('127.0.0.1', 8088);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    if (($argv[1] ?? '') === '--browser') {
        echo "QA aislado: http://127.0.0.1:8088/login?tab=admin\nUsuarios: ctx_super, ctx_restaurante, ctx_panaderia, ctx_sin_unidad\nContraseña exclusiva de este fixture: Contextos-Prueba-2026\nEnter para cerrar y eliminar la base temporal.\n";
        fgets(STDIN);
    } else {
        $total = 0;
        $assert = static function (bool $bien, string $mensaje) use (&$total): void {
            if (!$bien) { throw new RuntimeException($mensaje); }
            $total++;
        };
        foreach (['ctx_super', 'ctx_restaurante', 'ctx_panaderia', 'ctx_sin_unidad'] as $usuario) {
            $jar = tempnam(sys_get_temp_dir(), 'cp-ctx-cookie-'); $cookies[] = $jar;
            [$codigo, $contenido] = contextosHttp('/admin/login', $jar, ['username' => $usuario, 'password' => 'Contextos-Prueba-2026']);
            $assert($codigo === 302, 'Login vigente: ' . $usuario);
            foreach (['/admin/global', '/admin/panaderia', '/admin/panaderia/menu', '/admin/panaderia/recetas', '/admin/panaderia/inventario', '/admin/menu', '/admin/recetas', '/admin/inventario', '/punto-de-venta', '/area'] as $ruta) {
                [$codigo, $contenido] = contextosHttp($ruta, $jar);
                $permitida = $usuario === 'ctx_super' || ($usuario === 'ctx_restaurante' && !str_starts_with($ruta, '/admin/global') && !str_starts_with($ruta, '/admin/panaderia')) || ($usuario === 'ctx_panaderia' && str_starts_with($ruta, '/admin/panaderia'));
                $assert($codigo === ($permitida ? 200 : 403), "{$usuario} {$ruta}: {$codigo}");
                if ($permitida && str_starts_with($ruta, '/admin/panaderia')) {
                    $assert(str_contains($contenido, 'Datos de prueba'), 'Aviso explícito de fixture');
                    $assert(!str_contains($contenido, 'data-inbox-open'), 'Buzón operativo aislado');
                }
            }
            if ($usuario === 'ctx_panaderia' || $usuario === 'ctx_sin_unidad') {
                foreach (['/api/abrir-ticket', '/api/enviar-comanda', '/api/cerrar-ticket', '/api/avanzar-item', '/admin/api/buzon', '/admin/inventario/crear', '/admin/usuarios/crear'] as $ruta) {
                    [$codigo] = contextosHttp($ruta, $jar, []);
                    $assert($codigo === 403, 'Mutación restaurante bloqueada: ' . $ruta);
                }
                [$codigo] = contextosHttp('/admin/contexto?contexto=global', $jar);
                $assert($codigo === 403, 'Selector no amplía permisos');
            }
            if ($usuario === 'ctx_panaderia') {
                $registro = file_get_contents($root . '/public/index.php');
                preg_match_all("/\\\$router->(get|post|delete)\\('([^']+)'/", $registro, $rutas, PREG_SET_ORDER);
                foreach ($rutas as $registrada) {
                    $ruta = $registrada[2];
                    if (\Services\Security\Permisos::clasificar($ruta)['tipo'] === 'publica' || in_array($ruta, ['/admin', '/admin/contexto'], true) || \Services\Security\Contextos::deRuta($ruta) !== 'restaurante') { continue; }
                    [$codigo] = contextosHttp($ruta, $jar, $registrada[1] === 'get' ? null : [], strtoupper($registrada[1]));
                    $assert($codigo === 403, 'Toda ruta protegida de Restaurante bloqueada: ' . $ruta);
                }
                $db->query("UPDATE usuarios SET unidad_id = 1 WHERE username = 'ctx_panaderia'");
                [$codigo] = contextosHttp('/admin/menu', $jar);
                $assert($codigo === 200, 'Reasignación válida desde la siguiente petición');
                [$codigo] = contextosHttp('/admin/panaderia/menu', $jar);
                $assert($codigo === 403, 'No se conserva la unidad anterior en sesión');
                $db->query("UPDATE usuarios SET unidad_id = 2 WHERE username = 'ctx_panaderia'");
            }
        }
        $lector = tempnam(sys_get_temp_dir(), 'cp-ctx-cookie-'); $cookies[] = $lector;
        contextosHttp('/admin/login', $lector, ['username' => 'ctx_lector', 'password' => 'Contextos-Prueba-2026']);
        [$codigo, $contenido] = contextosHttp('/admin/panaderia/menu', $lector);
        $assert($codigo === 200 && !str_contains($contenido, 'href="/admin/panaderia/inventario"'), 'Menú adaptado a permisos actuales');
        [$codigo, $contenido] = contextosHttp('/admin/panaderia/inventario', $lector);
        $assert($codigo === 302 && str_contains($contenido, 'Location: /admin/panaderia'), 'Módulo no autorizado no se abre por URL');
        [$codigo] = contextosHttp('/admin/menu', $lector);
        $assert($codigo === 403, 'Permiso de menú no abre otro negocio');
        $anonimo = tempnam(sys_get_temp_dir(), 'cp-ctx-cookie-'); $cookies[] = $anonimo;
        [$codigo, $contenido] = contextosHttp('/admin/panaderia/menu', $anonimo);
        $assert($codigo === 302 && str_contains($contenido, 'Location: /login'), 'Demo no pública');
        $assert((int) $db->query('SELECT COUNT(*) FROM tickets')->fetch_row()[0] === 0, 'No se escribieron ventas');
        $assert((int) $db->query("SELECT COUNT(*) FROM productos WHERE nombre IN ('Rol de canela','Pan de masa madre')")->fetch_row()[0] === 0, 'Fixture aislado del catálogo real');
        echo "Contextos HTTP/DB: {$total} comprobaciones OK\n";
    }
} catch (Throwable $e) {
    $fallo = true;
    fwrite(STDERR, 'Contextos aislados: ' . $e->getMessage() . "\n");
} finally {
    if (is_resource($servidor)) { proc_terminate($servidor); proc_close($servidor); }
    foreach ($cookies as $jar) { unlink($jar); }
    if (is_file($log)) { unlink($log); }
    if ($creada && preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $nombre)) {
        $db->query("DROP DATABASE `{$nombre}`");
        echo "Base temporal eliminada; datos operativos intactos.\n";
    }
}
exit($fallo ? 1 : 0);
