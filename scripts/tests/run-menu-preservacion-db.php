<?php
declare(strict_types=1);

use MenuPreservacion\Comun;
use MenuPreservacion\ExportadorMenu;
use MenuPreservacion\VerificadorMenu;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este runner solo se ejecuta desde CLI.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
require_once $root . '/scripts/menu/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function menuPreservacionArgs(array $argv): array
{
    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (!in_array($arg, ['--host', '--base', '--entorno'], true) || !isset($argv[$i + 1])) {
            throw new InvalidArgumentException('Uso: php scripts/tests/run-menu-preservacion-db.php --host HOST --base BASE --entorno development|test');
        }
        if (isset($args[substr($arg, 2)])) {
            throw new InvalidArgumentException('Argumento repetido: ' . $arg);
        }
        $args[substr($arg, 2)] = $argv[++$i];
    }
    $keys = array_keys($args);
    sort($keys, SORT_STRING);
    if ($keys !== ['base', 'entorno', 'host']) {
        throw new InvalidArgumentException('Se requieren exactamente --host, --base y --entorno.');
    }
    return $args;
}

function menuPreservacionCheck(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function menuPreservacionEsperaError(callable $accion, string $mensaje): Throwable
{
    try {
        $accion();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException($mensaje);
}

function menuPreservacionFilas(mysqli $db, string $sql): array
{
    return Comun::filas($db, $sql);
}

function menuPreservacionEliminarArbol(string $ruta, string $raizPropia): void
{
    $base = realpath($raizPropia);
    $real = realpath($ruta);
    if ($base === false || $real === false || is_link($ruta)) {
        return;
    }
    $prefijo = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $realNormal = rtrim(str_replace('\\', '/', $real), '/') . '/';
    if (!str_starts_with(strtolower($realNormal), strtolower($prefijo))) {
        throw new RuntimeException('Se rechazó limpiar una ruta temporal fuera de su raíz propia.');
    }
    foreach (new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS) as $entrada) {
        $hijo = $entrada->getPathname();
        if ($entrada->isLink()) {
            unlink($hijo);
        } elseif ($entrada->isDir()) {
            menuPreservacionEliminarArbol($hijo, $real);
        } else {
            unlink($hijo);
        }
    }
    rmdir($real);
}

$admin = null;
$db = null;
$baseTemporal = '';
$tokenPropietario = '';
$creada = false;
$scratch = '';
$casos = [];
$fallo = null;

try {
    $opciones = menuPreservacionArgs($argv);
    $config = [
        'DB_HOST' => $_ENV['DB_HOST'] ?? '',
        'DB_USER' => $_ENV['DB_USER'] ?? '',
        'DB_PASS' => $_ENV['DB_PASS'] ?? '',
        'DB_NAME' => $_ENV['DB_NAME'] ?? '',
        'DB_PORT' => $_ENV['DB_PORT'] ?? 3306,
        'APP_ENV' => $_ENV['APP_ENV'] ?? '',
    ];
    // La confirmación explícita de configuración ocurre antes de abrir conexión administrativa.
    $origen = Comun::origen($config, $opciones, $root);
    $admin = Comun::conectar($config, false);

    $baseTemporal = 'cp_menu_test_' . bin2hex(random_bytes(10));
    $tokenPropietario = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_menu_test_[a-f0-9]{20}$/D', $baseTemporal)) {
        throw new RuntimeException('No se pudo generar un nombre de base temporal válido.');
    }
    $admin->query("CREATE DATABASE `{$baseTemporal}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $admin->select_db($baseTemporal);
    $admin->query('CREATE TABLE `_cp_menu_test_owner` (`token` CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO `_cp_menu_test_owner` (`token`) VALUES (?)');
    $stmt->bind_param('s', $tokenPropietario);
    $stmt->execute();
    $stmt->close();
    if ($admin->query('SELECT DATABASE()')->fetch_row()[0] !== $baseTemporal) {
        throw new RuntimeException('No quedó seleccionada la base temporal propia.');
    }
    $casos[] = 'base aleatoria identificada y propiedad marcada antes de cualquier DROP';

    $db = $admin;
    $db->query("CREATE TABLE `categorias` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(40) NOT NULL,
        `carta` ENUM('comida','maridaje','temporada') NOT NULL DEFAULT 'comida',
        `img` VARCHAR(200) NULL,
        `activo` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE `areas_produccion` (
        `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(60) NOT NULL,
        `slug` VARCHAR(20) NOT NULL,
        `color` VARCHAR(10) NOT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE `productos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(120) NOT NULL,
        `descripcion` TEXT NULL,
        `img` VARCHAR(255) NULL DEFAULT NULL,
        `categoria_id` INT NOT NULL,
        `precio` DECIMAL(8,2) NOT NULL,
        `area_id` TINYINT UNSIGNED NOT NULL,
        `activo` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY (`nombre`),
        CONSTRAINT `fk_cp_menu_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
        CONSTRAINT `fk_cp_menu_area` FOREIGN KEY (`area_id`) REFERENCES `areas_produccion` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $tmpParent = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'menu-preservacion';
    if (!is_dir($tmpParent) && !mkdir($tmpParent, 0700, true)) {
        throw new RuntimeException('No se pudo crear el directorio temporal de fixtures.');
    }
    if (is_link($tmpParent) || !is_dir($tmpParent)) {
        throw new RuntimeException('La raíz temporal de fixtures no es un directorio seguro.');
    }
    $scratch = $tmpParent . DIRECTORY_SEPARATOR . bin2hex(random_bytes(10));
    if (!mkdir($scratch, 0700)) {
        throw new RuntimeException('No se pudo crear el directorio aleatorio de fixtures.');
    }
    $publicRoot = $scratch . DIRECTORY_SEPARATOR . 'public';
    $assetDir = $publicRoot . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'menu-preservacion';
    if (!mkdir($assetDir, 0700, true)) {
        throw new RuntimeException('No se pudieron crear las rutas temporales de imágenes.');
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    if (!is_string($png)) {
        throw new RuntimeException('No se pudo preparar la imagen PNG de fixture.');
    }
    $assetPaths = [
        'build/images/menu-preservacion/categoria-comida.png',
        'build/images/menu-preservacion/categoria-temporada.png',
        'build/images/menu-preservacion/platillo-propio.png',
    ];
    foreach ($assetPaths as $assetPath) {
        $full = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $assetPath);
        if (file_put_contents($full, $png, LOCK_EX) !== strlen($png)) {
            throw new RuntimeException('No se pudo escribir una imagen de fixture.');
        }
    }

    $insertCategoria = $db->prepare('INSERT INTO categorias (id,nombre,carta,img,activo) VALUES (?,?,?,?,?)');
    foreach ([
        [1, 'Comida', 'comida', $assetPaths[0], 1],
        [2, 'Maridaje', 'maridaje', '', 1],
        [10, 'Temporada', 'temporada', $assetPaths[1], 0],
    ] as [$id, $nombre, $carta, $img, $activo]) {
        $insertCategoria->bind_param('isssi', $id, $nombre, $carta, $img, $activo);
        $insertCategoria->execute();
    }
    $insertCategoria->close();

    $insertArea = $db->prepare('INSERT INTO areas_produccion (id,nombre,slug,color) VALUES (?,?,?,?)');
    foreach ([[1, 'Cocina', 'cocina', '#AA0011'], [2, 'Barra', 'barra', '#1122AA']] as [$id, $nombre, $slug, $color]) {
        $insertArea->bind_param('isss', $id, $nombre, $slug, $color);
        $insertArea->execute();
    }
    $insertArea->close();

    $insertProducto = $db->prepare('INSERT INTO productos (id,nombre,descripcion,img,categoria_id,precio,area_id,activo) VALUES (?,?,?,?,?,?,?,?)');
    $productosFixture = [
        [1, 'Té de limón', null, null, 1, '0.10', 1, 1],
        [2, 'Pan del día', '', $assetPaths[2], 2, '123.45', 2, 0],
        [3, 'Especial de estación', 'Sin foto propia', '', 10, '0.00', 1, 0],
    ];
    foreach ($productosFixture as [$id, $nombre, $descripcion, $img, $categoriaId, $precio, $areaId, $activo]) {
        $insertProducto->bind_param('isssisii', $id, $nombre, $descripcion, $img, $categoriaId, $precio, $areaId, $activo);
        $insertProducto->execute();
    }
    $insertProducto->close();
    $casos[] = 'fixture mínimo con tres cartas, inactivos, NULL/vacío, acentos, decimales exactos y foto fallback';

    $esperado = [
        'formato' => 'casa-pestalozzi.menu',
        'version' => 1,
        'negocio' => 'restaurante',
        'productos' => [
            ['id'=>'1','nombre'=>'Té de limón','descripcion'=>null,'img'=>null,'categoria_id'=>'1','precio'=>'0.10','area_id'=>'1','activo'=>'1'],
            ['id'=>'2','nombre'=>'Pan del día','descripcion'=>'','img'=>$assetPaths[2],'categoria_id'=>'2','precio'=>'123.45','area_id'=>'2','activo'=>'0'],
            ['id'=>'3','nombre'=>'Especial de estación','descripcion'=>'Sin foto propia','img'=>'','categoria_id'=>'10','precio'=>'0.00','area_id'=>'1','activo'=>'0'],
        ],
        'categorias' => [
            ['id'=>'1','nombre'=>'Comida','carta'=>'comida','img'=>$assetPaths[0],'activo'=>'1'],
            ['id'=>'2','nombre'=>'Maridaje','carta'=>'maridaje','img'=>'','activo'=>'1'],
            ['id'=>'10','nombre'=>'Temporada','carta'=>'temporada','img'=>$assetPaths[1],'activo'=>'0'],
        ],
        'areas' => [
            ['id'=>'1','nombre'=>'Cocina','slug'=>'cocina','color'=>'#AA0011'],
            ['id'=>'2','nombre'=>'Barra','slug'=>'barra','color'=>'#1122AA'],
        ],
        'orden' => ['productos'=>['1','2','3'], 'categorias'=>['1','2','10']],
    ];
    $antes = menuPreservacionFilas($db, 'SELECT id,nombre,descripcion,img,categoria_id,precio,area_id,activo FROM productos ORDER BY id');
    $antesCategorias = menuPreservacionFilas($db, 'SELECT id,nombre,carta,img,activo FROM categorias ORDER BY id');
    $antesAreas = menuPreservacionFilas($db, 'SELECT id,nombre,slug,color FROM areas_produccion ORDER BY id');
    $db->query('START TRANSACTION READ ONLY');
    try {
        $capturado = ExportadorMenu::capturar($db);
        menuPreservacionCheck(VerificadorMenu::comparar($esperado, $capturado) === [], 'capturar no conservó valores/NULL/decimales/orden esperados.');
        menuPreservacionCheck(count(array_filter($capturado['productos'], static fn(array $p): bool => $p['activo'] === '0')) === 2, 'capturar filtró productos inactivos.');
        $esquema = ExportadorMenu::esquema($db);
        menuPreservacionCheck(array_keys($esquema['tablas']) === ['productos','categorias','areas_produccion'], 'esquema no contiene las tres tablas esperadas.');
        menuPreservacionCheck(preg_match('/^[a-f0-9]{64}$/D', $esquema['sha256']) === 1, 'hash del esquema no es SHA256.');

        $paquete1 = $scratch . DIRECTORY_SEPARATOR . 'paquete-1';
        $manifest1 = ExportadorMenu::exportar($db, $publicRoot, $paquete1, $origen);
        $validacion1 = VerificadorMenu::verificar($paquete1, $esperado);
        menuPreservacionCheck($validacion1['ok'], 'primer paquete inválido: ' . implode(' | ', $validacion1['errores']));
        menuPreservacionCheck($manifest1['conteos'] === ['productos'=>3,'categorias'=>3,'areas'=>2], 'conteos de manifiesto inesperados.');
        menuPreservacionCheck(count($manifest1['archivos']) === 3, 'el manifiesto no incluye cada imagen única.');

        $paquete2 = $scratch . DIRECTORY_SEPARATOR . 'paquete-2';
        ExportadorMenu::exportar($db, $publicRoot, $paquete2, $origen);
        $hashMenu1 = hash_file('sha256', $paquete1 . DIRECTORY_SEPARATOR . 'menu.json');
        $hashMenu2 = hash_file('sha256', $paquete2 . DIRECTORY_SEPARATOR . 'menu.json');
        menuPreservacionCheck(is_string($hashMenu1) && hash_equals($hashMenu1, (string) $hashMenu2), 'exportaciones idénticas produjeron hashes distintos.');
    } finally {
        $db->query('COMMIT');
    }
    $despues = menuPreservacionFilas($db, 'SELECT id,nombre,descripcion,img,categoria_id,precio,area_id,activo FROM productos ORDER BY id');
    menuPreservacionCheck(VerificadorMenu::comparar($antes, $despues) === [], 'exportaciones modificaron filas fuente.');
    menuPreservacionCheck(VerificadorMenu::comparar($antesCategorias, menuPreservacionFilas($db, 'SELECT id,nombre,carta,img,activo FROM categorias ORDER BY id')) === [], 'exportaciones modificaron categorías fuente.');
    menuPreservacionCheck(VerificadorMenu::comparar($antesAreas, menuPreservacionFilas($db, 'SELECT id,nombre,slug,color FROM areas_produccion ORDER BY id')) === [], 'exportaciones modificaron áreas fuente.');
    $casos[] = 'captura, introspección, paquete íntegro, verificador, repetibilidad y lectura sin cambios fuente';

    $db->query('ALTER TABLE productos ADD COLUMN columna_desconocida INT NULL');
    try {
        menuPreservacionEsperaError(static fn() => ExportadorMenu::capturar($db), 'el exportador aceptó una columna desconocida.');
    } finally {
        $db->query('ALTER TABLE productos DROP COLUMN columna_desconocida');
    }
    $casos[] = 'rechazo de columna desconocida y restitución de esquema del fixture';

    $db->query('ALTER TABLE productos MODIFY precio DECIMAL(8,3) NOT NULL');
    try {
        menuPreservacionEsperaError(static fn() => ExportadorMenu::capturar($db), 'el exportador aceptó precio con escala distinta de dos.');
    } finally {
        $db->query('ALTER TABLE productos MODIFY precio DECIMAL(8,2) NOT NULL');
    }
    $casos[] = 'rechazo de precio DECIMAL con escala 3 y restitución de esquema del fixture';

    $sourceImagesBefore = menuPreservacionFilas($db, 'SELECT id,img FROM productos ORDER BY id');
    $db->begin_transaction();
    try {
        $db->query("UPDATE productos SET img='https://example.invalid/foto.png' WHERE id=1");
        $paqueteExterno = $scratch . DIRECTORY_SEPARATOR . 'paquete-externo';
        $errorExterno = menuPreservacionEsperaError(static fn() => ExportadorMenu::exportar($db, $publicRoot, $paqueteExterno, $origen), 'el exportador aceptó o descargó una URL externa.');
        menuPreservacionCheck($errorExterno->getMessage() === 'Ruta de archivo no admitida.', 'El rechazo de URL no provino de la barrera de rutas.');
        menuPreservacionCheck(is_dir($paqueteExterno . DIRECTORY_SEPARATOR . 'archivos'), 'el error de URL no dejó evidencia parcial esperada.');
        // Las imágenes locales previas pueden haberse copiado antes del rechazo de la URL.
        $locales = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($paqueteExterno . DIRECTORY_SEPARATOR . 'archivos', FilesystemIterator::SKIP_DOTS));
        foreach ($locales as $file) {
            if ($file->isFile()) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($paqueteExterno . DIRECTORY_SEPARATOR . 'archivos') + 1));
                menuPreservacionCheck(in_array($rel, $assetPaths, true) && hash_file('sha256', $file->getPathname()) === hash('sha256', $png), 'Apareció un archivo distinto de las imágenes locales permitidas.');
            }
        }
    } finally {
        $db->rollback();
    }
    menuPreservacionCheck(VerificadorMenu::comparar($sourceImagesBefore, menuPreservacionFilas($db, 'SELECT id,img FROM productos ORDER BY id')) === [], 'la prueba de URL externa alteró imágenes fuente.');
    $casos[] = 'rechazo de URL externa, cero descargas y rollback de la referencia de prueba';

    $db->begin_transaction();
    try {
        $db->query("UPDATE productos SET img='build/images/menu-preservacion/ausente.png' WHERE id=1");
        $paqueteAusente = $scratch . DIRECTORY_SEPARATOR . 'paquete-ausente';
        menuPreservacionEsperaError(static fn() => ExportadorMenu::exportar($db, $publicRoot, $paqueteAusente, $origen), 'el exportador aceptó una imagen ausente.');
    } finally {
        $db->rollback();
    }
    menuPreservacionCheck(VerificadorMenu::comparar($sourceImagesBefore, menuPreservacionFilas($db, 'SELECT id,img FROM productos ORDER BY id')) === [], 'la prueba de imagen ausente alteró imágenes fuente.');
    $casos[] = 'rechazo de imagen ausente y rollback de la referencia de prueba';
} catch (Throwable $e) {
    $fallo = $e;
} finally {
    if ($creada && $admin instanceof mysqli && preg_match('/^cp_menu_test_[a-f0-9]{20}$/D', $baseTemporal)) {
        try {
            $admin->select_db($baseTemporal);
            $tokens = Comun::filas($admin, 'SELECT token FROM `_cp_menu_test_owner`');
            if (count($tokens) === 1 && is_string($tokens[0]['token'] ?? null) && hash_equals($tokenPropietario, $tokens[0]['token'])) {
                $admin->query("DROP DATABASE `{$baseTemporal}`");
                echo "Base temporal propia eliminada tras verificar token de propiedad.\n";
            } else {
                fwrite(STDERR, "No se eliminó la base temporal: no se pudo verificar el token de propiedad.\n");
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'No se eliminó la base temporal; fallo al verificar propiedad: ' . $e->getMessage() . "\n");
        }
    }
    if ($scratch !== '' && is_dir($scratch) && !is_link($scratch)) {
        try {
            menuPreservacionEliminarArbol($scratch, $tmpParent ?? sys_get_temp_dir());
        } catch (Throwable $e) {
            fwrite(STDERR, 'No se limpió el directorio temporal de fixtures: ' . $e->getMessage() . "\n");
        }
    }
    if ($admin instanceof mysqli) {
        $admin->close();
    }
}

if ($fallo instanceof Throwable) {
    fwrite(STDERR, 'Runner menú preservación: ' . $fallo->getMessage() . "\n");
    exit(1);
}
foreach ($casos as $i => $caso) {
    echo 'Caso ' . ($i + 1) . ': PASS — ' . $caso . "\n";
}
echo 'Total: ' . count($casos) . " casos PASS.\n";
exit(0);
