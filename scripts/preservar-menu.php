<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/menu/bootstrap.php';

use MenuPreservacion\Comun;
use MenuPreservacion\ExportadorMenu;
use MenuPreservacion\VerificadorMenu;
use MenuPreservacion\RespaldoMenu;

function opcionesMenu(array $args): array
{
    $result = [];
    foreach ($args as $arg) {
        if (!preg_match('/^--(host|base|entorno|salida|paquete|respaldo|proyeccion)=(.+)$/D', $arg, $m) || isset($result[$m[1]])) {
            throw new RuntimeException('Opción inválida o repetida; usar --nombre=valor.');
        }
        $result[$m[1]] = $m[2];
    }
    return $result;
}

function snapshotMenu(mysqli $db): void
{
    $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
}

function salidaNuevaMenu(string $ruta): string
{
    if ($ruta === '' || file_exists($ruta) || is_link($ruta)) {
        throw new RuntimeException('La salida debe ser una carpeta nueva.');
    }
    $padre = realpath(dirname($ruta));
    $nombre = basename($ruta);
    if ($padre === false || is_link(dirname($ruta)) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $nombre)) {
        throw new RuntimeException('La salida requiere padre existente y nombre seguro.');
    }
    return $padre . DIRECTORY_SEPARATOR . $nombre;
}

$db = null;
try {
    $comando = $argv[1] ?? 'ayuda';
    if ($comando === 'ayuda' || $comando === '--help') {
        echo "Preservación del menú (sin reconstrucción del origen)\n"
            . "inspeccionar --host=HOST --base=BASE --entorno=development|test\n"
            . "exportar|preservar --host=HOST --base=BASE --entorno=development|test --salida=CARPETA_NUEVA\n"
            . "verificar --paquete=CARPETA\n"
            . "verificar-respaldo --respaldo=CARPETA\n"
            . "comparar --paquete=CARPETA --proyeccion=JSON_RECONSTRUIDO\n"
            . "ensayar --host=HOST --base=BASE --entorno=development|test --paquete=CARPETA --respaldo=CARPETA --salida=CARPETA_NUEVA\n";
        exit(0);
    }
    $opciones = opcionesMenu(array_slice($argv, 2));
    if ($comando === 'comparar') {
        $paquete = $opciones['paquete'] ?? '';
        $resultado = VerificadorMenu::verificar($paquete);
        if ($resultado['ok']) {
            $resultado['errores'] = VerificadorMenu::comparar(Comun::leerJson($paquete . '/menu.json'), Comun::leerJson($opciones['proyeccion'] ?? ''));
            $resultado['ok'] = $resultado['errores'] === [];
        }
        echo Comun::json($resultado);
        exit($resultado['ok'] ? 0 : 1);
    }
    if ($comando === 'verificar-respaldo') {
        $m = RespaldoMenu::verificar($opciones['respaldo'] ?? '');
        echo Comun::json(['ok' => true, 'tablas' => count($m['tablas']), 'archivos' => count($m['archivos'])]);
        exit(0);
    }
    if ($comando === 'verificar') {
        $resultado = VerificadorMenu::verificar($opciones['paquete'] ?? '');
        echo Comun::json($resultado);
        exit($resultado['ok'] ? 0 : 1);
    }
    if (!in_array($comando, ['inspeccionar', 'exportar', 'preservar', 'ensayar'], true)) {
        throw new RuntimeException('Comando desconocido.');
    }
    $root = dirname(__DIR__);
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    $origen = Comun::origen($config, $opciones, $root);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = Comun::conectar($config);
    if ((string) $db->query('SELECT DATABASE()')->fetch_row()[0] !== $origen['base']) {
        throw new RuntimeException('La conexión no corresponde al origen confirmado.');
    }
    snapshotMenu($db);
    $menu = ExportadorMenu::capturar($db);
    $esquema = ExportadorMenu::esquema($db);
    $origen['version_servidor'] = $db->server_info;
    $origen['esquema_sha256'] = $esquema['sha256'];
    $origen['estado_codigo'] = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain --untracked-files=no')) === '' ? 'limpio' : 'cambios_locales';
    $totales = ['productos' => count($menu['productos']), 'categorias' => count($menu['categorias']), 'areas' => count($menu['areas']),
        'inactivos' => count(array_filter($menu['productos'], static fn ($p) => $p['activo'] === '0'))];
    if ($comando === 'inspeccionar') {
        $total = RespaldoMenu::inspeccionar($db);
        echo Comun::json(['origen' => $origen, 'conteos' => $totales, 'tablas_respaldo' => count($total['tablas']), 'objetos' => $total['objetos'], 'esquema_catalogo' => $esquema]);
        $db->rollback();
        exit(0);
    }
    $salida = salidaNuevaMenu($opciones['salida'] ?? '');
    if (in_array($comando, ['preservar', 'ensayar'], true)) {
        Comun::destinoPrivado($salida, $root);
    }
    if ($comando === 'ensayar') {
        $paquete = $opciones['paquete'] ?? '';
        $respaldo = $opciones['respaldo'] ?? '';
        $validacion = VerificadorMenu::verificar($paquete, $menu);
        if (!$validacion['ok']) {
            throw new RuntimeException('Paquete inválido o diferente del origen confirmado.');
        }
        mkdir($salida, 0700);
        $db->rollback();
        $ensayo = RespaldoMenu::ensayar($config, $respaldo, $menu);
        Comun::escribirJson($salida . '/restauracion.json', $ensayo);
        if (!$ensayo['ok'] || !$ensayo['base_eliminada']) {
            throw new RuntimeException('El ensayo de base no fue satisfactorio; revisar reporte.');
        }
        $archivos = RespaldoMenu::restaurarArchivos($respaldo, $salida . '/entorno-restaurado');
        Comun::escribirJson($salida . '/restauracion-archivos.json', $archivos);
        echo Comun::json(['ok' => true, 'restauracion' => $ensayo, 'archivos' => $archivos]);
        exit(0);
    }
    if ($comando === 'preservar') {
        mkdir($salida, 0700);
        $paquete = $salida . '/paquete';
    } else {
        $paquete = $salida;
    }
    $manifest = ExportadorMenu::exportar($db, $root . '/public', $paquete, $origen);
    $validacion = VerificadorMenu::verificar($paquete, $menu);
    if (!$validacion['ok']) {
        Comun::escribirJson($paquete . '/validacion.json', $validacion);
        throw new RuntimeException('Validación del paquete fallida; revisar reporte.');
    }
    if ($comando === 'preservar') {
        $respaldo = $salida . '/respaldo';
        $backup = RespaldoMenu::crear($db, $root, $respaldo, $origen);
        $db->rollback();
        snapshotMenu($db);
        RespaldoMenu::compararOrigen($db, $respaldo);
        $validacion['origen_sin_cambios'] = VerificadorMenu::comparar($menu, ExportadorMenu::capturar($db)) === [];
        if (!$validacion['origen_sin_cambios']) {
            throw new RuntimeException('El catálogo origen cambió durante el respaldo.');
        }
    } else {
        $db->rollback();
        snapshotMenu($db);
        if (VerificadorMenu::comparar($menu, ExportadorMenu::capturar($db)) !== []) {
            throw new RuntimeException('El catálogo origen cambió durante la exportación.');
        }
    }
    // Incluye las imágenes originales en la comprobación posterior, sin tocarlas.
    foreach ($manifest['archivos'] as $file) {
        if (hash_file('sha256', Comun::rutaArchivo($root . '/public', $file['ruta'])) !== $file['sha256']) {
            throw new RuntimeException('Una imagen origen cambió durante la exportación.');
        }
    }
    $db->rollback();
    $validacion['origen_sin_cambios'] = true;
    Comun::escribirJson($paquete . '/validacion.json', $validacion);
    if ($comando === 'preservar') {
        $ensayo = RespaldoMenu::ensayar($config, $respaldo, $menu);
        Comun::escribirJson($salida . '/restauracion.json', $ensayo);
        if (!$ensayo['ok'] || !$ensayo['base_eliminada']) {
            throw new RuntimeException('El ensayo no fue satisfactorio; revisar restauracion.json. El origen no se modifica.');
        }
        $archivos = RespaldoMenu::restaurarArchivos($respaldo, $salida . '/entorno-restaurado');
        Comun::escribirJson($salida . '/restauracion-archivos.json', $archivos);
    }
    echo Comun::json(['ok' => true, 'paquete' => $paquete, 'conteos' => $totales,
        'manifiesto_sha256' => hash_file('sha256', $paquete . '/manifiesto.json'),
        'respaldo' => $respaldo ?? null, 'restauracion' => $ensayo ?? null]);
} catch (Throwable $e) {
    // Evitar trazas/credenciales; los errores de datos propios sí son accionables.
    fwrite(STDERR, Comun::json(['ok' => false, 'error' => $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '. No se modificó la base origen.' : $e->getMessage()]));
    exit(1);
} finally {
    if ($db instanceof mysqli) {
        try { $db->rollback(); $db->close(); } catch (Throwable) {}
    }
}
