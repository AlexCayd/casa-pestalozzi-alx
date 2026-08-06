<?php

declare(strict_types=1);

/**
 * Valida que el módulo pueda instalarse desde database/ddl.sql + dml.sql.
 * La base activa nunca se toca: el runner sólo acepta un nombre temporal suyo.
 */

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__, 2);
$keepDb = in_array('--keep-db', $argv, true);
$fallos = [];
$baseTemporal = null;
$servidor = null;
$db = null;

$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

try {
    $env = etapa7LeerEnv($root . '/includes/.env');
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $usuario = $env['DB_USER'] ?? 'root';
    $password = $env['DB_PASS'] ?? '';
    $baseActiva = strtolower(trim((string)($env['DB_NAME'] ?? '')));
    $baseTemporal = 'casa_pestalozzi_tmp_install_' . gmdate('Ymd_His') . '_' . random_int(100, 999);

    $afirmar(is_string($baseActiva) && $baseActiva !== '', 'No fue posible identificar DB_NAME activa.');
    $afirmar((bool)preg_match('/^casa_pestalozzi_tmp_install_[a-z0-9_]+$/i', $baseTemporal), 'El nombre temporal no cumple el prefijo protegido.');
    $afirmar(strtolower($baseTemporal) !== $baseActiva, 'La base temporal coincide con la base activa.');

    $ddlPath = $root . '/database/ddl.sql';
    $dmlPath = $root . '/database/dml.sql';
    $afirmar(is_file($ddlPath), 'Falta database/ddl.sql.');
    $afirmar(is_file($dmlPath), 'Falta database/dml.sql.');
    if ($fallos !== []) {
        throw new RuntimeException(implode(' ', $fallos));
    }

    $servidor = new mysqli($host, $usuario, $password);
    $servidor->set_charset('utf8mb4');
    $servidor->query("CREATE DATABASE `{$baseTemporal}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $usuario, $password, $baseTemporal);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '-06:00'");

    $ddlStatements = etapa7CargarSql($db, $ddlPath);
    $dmlStatements = etapa7CargarSql($db, $dmlPath);
    $afirmar($ddlStatements > 0, 'El DDL no produjo sentencias ejecutables.');
    $afirmar($dmlStatements > 0, 'El DML no produjo sentencias ejecutables.');

    $tablas = [
        'mesas',
        'reservaciones',
        'reservacion_mesas',
        'tickets',
        'ticket_mesas',
        'verificaciones_contacto',
        'horarios_operacion',
    ];
    foreach ($tablas as $tabla) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->bind_param('s', $tabla);
        $stmt->execute();
        $stmt->bind_result($existe);
        $stmt->fetch();
        $stmt->close();
        $afirmar((int)$existe === 1, "INST: falta la tabla canónica {$tabla}.");
    }

    $cuentas = [
        'mesas' => 'SELECT COUNT(*) FROM mesas WHERE tipo = \'mesa\' AND activo = 1 AND reservable = 1',
        'reservaciones' => 'SELECT COUNT(*) FROM reservaciones',
        'horarios' => 'SELECT COUNT(*) FROM horarios_operacion WHERE abierto = 1',
        'usuarios' => 'SELECT COUNT(*) FROM usuarios WHERE activo = 1',
    ];
    foreach ($cuentas as $nombre => $query) {
        $fila = $db->query($query)->fetch_row();
        $afirmar((int)($fila[0] ?? 0) > 0, "INST: el DML no sembró {$nombre} utilizable.");
    }

    $indices = $db->query("SHOW INDEX FROM reservaciones WHERE Key_name = 'uq_reservaciones_request_token'");
    $afirmar($indices->num_rows > 0, 'INST: falta la unicidad de request_token.');
    $indices->free();
    $indices = $db->query("SHOW INDEX FROM ticket_mesas WHERE Key_name = 'uq_ticket_mesa'");
    $afirmar($indices->num_rows > 0, 'INST: falta la unicidad de ticket_mesas.');
    $indices->free();

    $schema = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch_row();
    $afirmar((int)($schema[0] ?? 0) >= 20, 'INST: el esquema cargado está incompleto.');

    $routerSource = (string)file_get_contents($root . '/public/index.php');
    $routeMatches = [];
    preg_match_all('/\$router->(?:get|post|delete)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $routerSource, $routeMatches);
    $afirmar(count($routeMatches[1] ?? []) === 168, 'INST: el inventario de rutas ya no coincide con las 168 registradas.');
    foreach ([
        '/api/reservaciones/disponibilidad',
        '/api/reservaciones/contacto/verificar',
        '/admin/reservations/operation',
        '/admin/api/reservations/operation',
        '/api/punto-de-venta/reservaciones',
        '/api/punto-de-venta/mesa-contexto',
    ] as $ruta) {
        $afirmar(str_contains($routerSource, "'{$ruta}'"), "INST: falta la ruta contractual {$ruta}.");
    }

    require_once $root . '/vendor/autoload.php';
    \Model\ActiveRecord::setDB($db);
    $afirmar(\Services\ReservacionConfig::timezone()->getName() === 'America/Mexico_City', 'INST: zona horaria canónica incorrecta.');
    $afirmar(count(\Services\ReservacionService::transiciones()) > 0, 'INST: no se cargaron las transiciones de reservación.');
    $afirmar(\Services\ReservacionErrorCatalog::has('CAPACIDAD_INSUFICIENTE'), 'INST: catálogo de errores no disponible.');
    $afirmar(method_exists(\Controllers\ReservacionController::class, 'disponibilidad'), 'INST: contrato público sin controlador.');
    $afirmar(method_exists(\Controllers\AdminReservacionController::class, 'disponibilidad'), 'INST: contrato administrativo sin controlador.');
    $afirmar(method_exists(\Controllers\PuntoVentaController::class, 'mesaContexto'), 'INST: contrato POS/mapa sin controlador.');

    $catalogo = etapa7Ejecutar([PHP_BINARY, $root . '/scripts/tests/run-reservaciones-catalogo.php'], $root);
    $afirmar($catalogo['exit'] === 0, 'INST: la suite del catálogo falló: ' . trim($catalogo['output']));

    echo "PASS: instalación limpia; DDL={$ddlStatements}, DML={$dmlStatements}, tablas=" . (int)($schema[0] ?? 0) . ".\n";
} catch (Throwable $e) {
    $fallos[] = 'INST: ' . $e->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
    if ($servidor instanceof mysqli && !$keepDb && is_string($baseTemporal) && preg_match('/^casa_pestalozzi_tmp_install_[a-z0-9_]+$/i', $baseTemporal)) {
        try {
            $servidor->query("DROP DATABASE IF EXISTS `{$baseTemporal}`");
        } catch (Throwable $e) {
            $fallos[] = 'INST: no se pudo eliminar la base temporal: ' . $e->getMessage();
        }
        $servidor->close();
    }
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: instalación limpia de reservaciones\n");
    foreach (array_unique($fallos) as $fallo) {
        fwrite(STDERR, '- ' . $fallo . "\n");
    }
    exit(1);
}
if ($keepDb) {
    echo "INFO: base temporal conservada: {$baseTemporal}\n";
}

/** @return array<string, string> */
function etapa7LeerEnv(string $path): array
{
    $resultado = [];
    if (!is_file($path)) {
        return $resultado;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $resultado[trim($clave)] = trim($valor, " \t\"'");
    }
    return $resultado;
}

function etapa7CargarSql(mysqli $db, string $path): int
{
    $contenido = file_get_contents($path);
    if (!is_string($contenido)) {
        throw new RuntimeException("No fue posible leer {$path}.");
    }
    $delimiter = ';';
    $buffer = '';
    $ejecutadas = 0;
    foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $linea, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $linea . "\n";
        $recortado = rtrim($buffer);
        if (!str_ends_with($recortado, $delimiter)) {
            continue;
        }
        $sentencia = trim(substr($recortado, 0, -strlen($delimiter)));
        $buffer = '';
        $sentencia = preg_replace('/\A\s*(?:(?:--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\/)\s*)+/s', '', $sentencia) ?? $sentencia;
        if (trim($sentencia) === '') {
            continue;
        }
        $db->query($sentencia);
        $ejecutadas++;
    }
    $restante = trim($buffer);
    if ($restante !== '') {
        $db->query($restante);
        $ejecutadas++;
    }
    return $ejecutadas;
}

/** @param array<int, string> $command @return array{exit:int, output:string} */
function etapa7Ejecutar(array $command, string $cwd, array $env = []): array
{
    $out = tempnam(sys_get_temp_dir(), 'etapa7-out-');
    $err = tempnam(sys_get_temp_dir(), 'etapa7-err-');
    $descriptor = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $out, 'a'],
        2 => ['file', $err, 'a'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd, $env === [] ? null : array_merge($_ENV, $env), ['bypass_shell' => true]);
    if (!is_resource($process)) {
        @unlink($out);
        @unlink($err);
        return ['exit' => 1, 'output' => 'No fue posible iniciar el proceso.'];
    }
    $exit = proc_close($process);
    $output = (string)@file_get_contents($out) . (string)@file_get_contents($err);
    @unlink($out);
    @unlink($err);
    return ['exit' => $exit, 'output' => $output];
}
