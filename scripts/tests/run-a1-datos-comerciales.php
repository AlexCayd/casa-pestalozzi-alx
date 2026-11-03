<?php
declare(strict_types=1);

/** Integración A1: migración comercial por oferta sobre una base desechable propia. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este runner solo se ejecuta desde CLI.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/scripts/multinegocio/SqlArchivo.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();

function a1ValidarOrigen(): array
{
    $opciones = getopt('', ['host:', 'base:', 'entorno:']);
    foreach (['host' => 'DB_HOST', 'base' => 'DB_NAME', 'entorno' => 'APP_ENV'] as $argumento => $variable) {
        if (($opciones[$argumento] ?? null) !== ($_ENV[$variable] ?? null)) {
            throw new RuntimeException('Confirma --host, --base y --entorno con los valores de includes/.env.');
        }
    }
    if (!in_array($opciones['entorno'] ?? null, ['development', 'test'], true)) {
        throw new RuntimeException('La prueba solo puede crear una base desechable desde development/test.');
    }
    return $opciones;
}

function a1Afirmar(bool $condicion, string $mensaje, int &$comprobaciones): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    $comprobaciones++;
}

function a1Ids(mysqli $db, string $tabla): array
{
    if (!in_array($tabla, ['productos', 'ofertas_producto'], true)) {
        throw new InvalidArgumentException('Tabla no permitida para snapshot.');
    }
    return array_map('intval', array_column(
        $db->query("SELECT id FROM {$tabla} ORDER BY id")->fetch_all(MYSQLI_ASSOC),
        'id'
    ));
}

function a1Diferencias(mysqli $db, string $campo): array
{
    $igualdad = match ($campo) {
        'nombre' => 'CAST(d.nombre AS BINARY) <=> CAST(v.nombre AS BINARY)',
        'descripcion' => 'CAST(d.descripcion AS BINARY) <=> CAST(v.descripcion AS BINARY)',
        'img' => 'CAST(d.img AS BINARY) <=> CAST(v.img AS BINARY)',
        default => throw new InvalidArgumentException('Campo no permitido para comparación.'),
    };
    $filas = $db->query(
        "SELECT v.oferta_id FROM productos_oferta v
         LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=v.oferta_id
         WHERE d.oferta_id IS NULL OR NOT ({$igualdad}) ORDER BY v.oferta_id"
    )->fetch_all(MYSQLI_ASSOC);
    return array_map('intval', array_column($filas, 'oferta_id'));
}

function a1CrearProducto(mysqli $db, string $nombre, ?string $descripcion, ?string $img): int
{
    $db->execute_query('INSERT INTO productos(nombre,descripcion,img) VALUES (?,?,?)', [$nombre, $descripcion, $img]);
    return (int) $db->insert_id;
}

function a1CrearOferta(mysqli $db, int $productoId, int $unidadId, int $categoriaId, int $areaId): int
{
    $db->execute_query(
        'INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES (?,?,?,?,?,1)',
        [$productoId, $unidadId, $categoriaId, '25.00', $areaId]
    );
    return (int) $db->insert_id;
}

function a1CrearFixture(mysqli $db): array
{
    $destinos = [];
    foreach ([2 => 'restaurante', 3 => 'panaderia'] as $unidadId => $slug) {
        $fila = $db->execute_query(
            'SELECT c.id categoria_id,a.id area_id
             FROM categorias c JOIN areas_produccion a ON a.unidad_id=c.unidad_id
             WHERE c.unidad_id=? ORDER BY c.id,a.id LIMIT 1',
            [$unidadId]
        )->fetch_assoc();
        if (!$fila) {
            throw new RuntimeException("Faltan categoría o área para {$slug} en el esquema fixture.");
        }
        $destinos[$unidadId] = ['categoria_id' => (int) $fila['categoria_id'], 'area_id' => (int) $fila['area_id']];
    }

    $sufijo = bin2hex(random_bytes(5));
    $soloRest = a1CrearProducto($db, "A1 restaurante {$sufijo}", 'Descripción Restaurante base', '/a1/restaurante.webp');
    $soloPan = a1CrearProducto($db, "A1 panadería {$sufijo}", 'Descripción Panadería base', '/a1/panaderia.webp');
    $compartido = a1CrearProducto($db, "A1 compartido {$sufijo}", 'Descripción oficial compartida', '/a1/oficial.webp');

    $restaurante = a1CrearOferta($db, $soloRest, 2, $destinos[2]['categoria_id'], $destinos[2]['area_id']);
    $panaderia = a1CrearOferta($db, $soloPan, 3, $destinos[3]['categoria_id'], $destinos[3]['area_id']);
    $compartidoRest = a1CrearOferta($db, $compartido, 2, $destinos[2]['categoria_id'], $destinos[2]['area_id']);
    $compartidoPan = a1CrearOferta($db, $compartido, 3, $destinos[3]['categoria_id'], $destinos[3]['area_id']);

    $actorId = (int) ($db->execute_query("SELECT id FROM usuarios WHERE username='referencia_global' LIMIT 1")->fetch_row()[0] ?? 0);
    if ($actorId < 1) {
        throw new RuntimeException('No existe el usuario fixture necesario para personalizaciones M1.2.');
    }

    $reglas = json_encode(['reglas' => ['nombre' => true, 'descripcion' => true, 'img' => true]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', 'a1-' . $sufijo);
    $clavePublicacion = 'a1_' . $sufijo . '_publicacion';
    $claveCreacion = 'a1_' . $sufijo . '_creacion';
    $db->execute_query(
        'INSERT INTO producto_publicaciones(producto_id,version,datos,impacto,usuario_id,clave,solicitud_hash)
         VALUES (?,1,?,\'{}\',?,?,?)',
        [$compartido, $reglas, $actorId, $clavePublicacion, $hash]
    );
    $publicacionId = (int) $db->insert_id;
    $db->execute_query(
        'INSERT INTO producto_estandares(producto_id,borrador,revision,publicacion_id,clave_creacion,creacion_hash)
         VALUES (?,?,1,?,?,?)',
        [$compartido, $reglas, $publicacionId, $claveCreacion, hash('sha256', $claveCreacion)]
    );

    $insertarPersonalizacion = static function (int $ofertaId, string $campo, ?string $valorJson) use ($db, $actorId): void {
        $db->execute_query(
            'INSERT INTO oferta_personalizaciones(oferta_id,campo,valor,usuario_id) VALUES (?,?,?,?)',
            [$ofertaId, $campo, $valorJson, $actorId]
        );
    };
    $insertarPersonalizacion($compartidoPan, 'nombre', json_encode('Nombre personalizado A1', JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $insertarPersonalizacion($compartidoPan, 'descripcion', json_encode('Descripción personalizada A1', JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $insertarPersonalizacion($compartidoPan, 'img', json_encode('/a1/panaderia-personalizada.webp', JSON_THROW_ON_ERROR));
    $insertarPersonalizacion($compartidoRest, 'img', 'null');

    return [
        'solo_restaurante' => $restaurante,
        'solo_panaderia' => $panaderia,
        'compartido_restaurante' => $compartidoRest,
        'compartido_panaderia' => $compartidoPan,
        'producto_restaurante' => $soloRest,
        'producto_panaderia' => $soloPan,
        'producto_compartido' => $compartido,
    ];
}

$admin = null;
$nombreBase = '';
$token = '';
$creada = false;
$comprobaciones = 0;
$casos = [];
$fallo = null;

try {
    $opciones = a1ValidarOrigen();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli(
        (string) $_ENV['DB_HOST'],
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASS'],
        '',
        (int) ($_ENV['DB_PORT'] ?? 3306)
    );
    $admin->set_charset('utf8mb4');

    $nombreBase = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $nombreBase)) {
        throw new RuntimeException('No se generó un nombre válido para la base desechable.');
    }
    $admin->query("CREATE DATABASE `{$nombreBase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creada = true;
    $admin->select_db($nombreBase);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $admin->execute_query('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)', [$token]);
    if ($nombreBase === ($opciones['base'] ?? null) || $nombreBase === ($_ENV['DB_NAME'] ?? null)) {
        throw new RuntimeException('La base generada coincide con la base configurada; ejecución cancelada.');
    }

    foreach (['ddl', 'deploy', 'menu-restaurante', 'development'] as $archivo) {
        Multinegocio\SqlArchivo::ejecutar($admin, $root . '/database/' . $archivo . '.sql');
    }
    a1Afirmar(
        $admin->query("SHOW TABLES LIKE 'oferta_datos_comerciales'")->num_rows === 0,
        'La instalación fixture ya contiene oferta_datos_comerciales antes de aplicar A1.',
        $comprobaciones
    );
    $casos[] = 'instalación compatible cargada en base temporal propia';

    $fixture = a1CrearFixture($admin);
    $idsProductosAntes = a1Ids($admin, 'productos');
    $idsOfertasAntes = a1Ids($admin, 'ofertas_producto');

    $esOfertas = $admin->execute_query(
        'SELECT o.id,o.unidad_id FROM ofertas_producto o WHERE o.producto_id=? ORDER BY o.id',
        [$fixture['producto_restaurante']]
    )->fetch_all(MYSQLI_ASSOC);
    a1Afirmar(count($esOfertas) === 1 && (int) $esOfertas[0]['unidad_id'] === 2, 'El fixture incluye un producto usado únicamente en Restaurante.', $comprobaciones);
    $esOfertas = $admin->execute_query(
        'SELECT o.id,o.unidad_id FROM ofertas_producto o WHERE o.producto_id=? ORDER BY o.id',
        [$fixture['producto_panaderia']]
    )->fetch_all(MYSQLI_ASSOC);
    a1Afirmar(count($esOfertas) === 1 && (int) $esOfertas[0]['unidad_id'] === 3, 'El fixture incluye un producto usado únicamente en Panadería.', $comprobaciones);
    $esOfertas = $admin->execute_query(
        'SELECT o.id,o.unidad_id FROM ofertas_producto o WHERE o.producto_id=? ORDER BY o.id',
        [$fixture['producto_compartido']]
    )->fetch_all(MYSQLI_ASSOC);
    $unidadesCompartidas = array_map('intval', array_column($esOfertas, 'unidad_id'));
    a1Afirmar(count($esOfertas) === 2 && $unidadesCompartidas === [2, 3], 'El fixture incluye un producto compartido por ambos negocios.', $comprobaciones);

    $vistaPersonalizada = $admin->execute_query(
        'SELECT nombre,descripcion,img FROM productos_oferta WHERE oferta_id=?',
        [$fixture['compartido_panaderia']]
    )->fetch_assoc();
    a1Afirmar(
        $vistaPersonalizada !== null
            && $vistaPersonalizada['nombre'] === 'Nombre personalizado A1'
            && $vistaPersonalizada['descripcion'] === 'Descripción personalizada A1'
            && $vistaPersonalizada['img'] === '/a1/panaderia-personalizada.webp',
        'La vista efectiva resuelve nombre, descripción e imagen personalizados antes de migrar.',
        $comprobaciones
    );
    $vistaNull = $admin->execute_query('SELECT img FROM productos_oferta WHERE oferta_id=?', [$fixture['compartido_restaurante']])->fetch_assoc();
    a1Afirmar($vistaNull !== null && $vistaNull['img'] === null, 'El fixture contiene una imagen efectiva NULL aunque el producto tenga imagen oficial.', $comprobaciones);
    a1Afirmar(
        (int) $admin->execute_query('SELECT COUNT(*) FROM oferta_personalizaciones WHERE oferta_id=?', [$fixture['solo_restaurante']])->fetch_row()[0] === 0,
        'El fixture incluye una oferta sin personalizaciones.',
        $comprobaciones
    );
    $casos[] = 'escenarios exclusivos, compartido, nombre personalizado, imagen NULL y oferta sin personalización';

    $migracion = $root . '/database/actualizaciones/a1-datos-comerciales-oferta.sql';
    Multinegocio\SqlArchivo::ejecutar($admin, $migracion);

    $faltantes = array_map('intval', array_column($admin->query(
        'SELECT o.id FROM ofertas_producto o LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=o.id
         WHERE d.oferta_id IS NULL ORDER BY o.id'
    )->fetch_all(MYSQLI_ASSOC), 'id'));
    a1Afirmar($faltantes === [], 'Ofertas sin fila comercial: ' . implode(',', $faltantes), $comprobaciones);

    foreach (['nombre', 'descripcion', 'img'] as $campo) {
        $diferencias = a1Diferencias($admin, $campo);
        a1Afirmar($diferencias === [], "{$campo} difiere de productos_oferta en oferta_id: " . implode(',', $diferencias), $comprobaciones);
    }
    $casos[] = 'todas las ofertas materializadas con nombre, descripción e imagen idénticos byte a byte; NULL preservado';

    $huérfanas = array_map('intval', array_column($admin->query(
        'SELECT d.oferta_id FROM oferta_datos_comerciales d LEFT JOIN ofertas_producto o ON o.id=d.oferta_id
         WHERE o.id IS NULL ORDER BY d.oferta_id'
    )->fetch_all(MYSQLI_ASSOC), 'oferta_id'));
    a1Afirmar($huérfanas === [], 'Filas comerciales huérfanas: ' . implode(',', $huérfanas), $comprobaciones);
    a1Afirmar(a1Ids($admin, 'productos') === $idsProductosAntes, 'La migración cambió identificadores de productos.', $comprobaciones);
    a1Afirmar(a1Ids($admin, 'ofertas_producto') === $idsOfertasAntes, 'La migración cambió identificadores de ofertas.', $comprobaciones);
    $casos[] = 'integridad referencial sin huérfanos y snapshots de IDs de productos/ofertas intactos';

    $ofertaConservada = (int) $fixture['compartido_panaderia'];
    $admin->execute_query(
        "UPDATE oferta_datos_comerciales SET nombre=?,descripcion=?,img=NULL WHERE oferta_id=?",
        ['Valor manual que debe conservarse', '', $ofertaConservada]
    );
    $antesReejecucion = $admin->query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales ORDER BY oferta_id'
    )->fetch_all(MYSQLI_ASSOC);
    Multinegocio\SqlArchivo::ejecutar($admin, $migracion);
    $despuesReejecucion = $admin->query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales ORDER BY oferta_id'
    )->fetch_all(MYSQLI_ASSOC);
    a1Afirmar(
        $despuesReejecucion === $antesReejecucion,
        'La segunda ejecución alteró filas comerciales existentes.',
        $comprobaciones
    );
    $casos[] = 'segunda ejecución idempotente: filas previamente guardadas, incluidos NULL, permanecen intactas';
} catch (Throwable $error) {
    $fallo = $error;
} finally {
    if ($creada && $admin instanceof mysqli && preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $nombreBase)) {
        try {
            $admin->select_db($nombreBase);
            $tokens = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_ASSOC);
            if (count($tokens) === 1 && hash_equals($token, (string) ($tokens[0]['token'] ?? ''))) {
                $admin->query("DROP DATABASE `{$nombreBase}`");
                echo "Base temporal A1 propia eliminada.\n";
            } else {
                fwrite(STDERR, "No se eliminó la base temporal A1: no se pudo verificar la propiedad.\n");
            }
        } catch (Throwable $error) {
            fwrite(STDERR, 'No se eliminó la base temporal A1 tras comprobar su propiedad: ' . $error->getMessage() . "\n");
        }
    }
    if ($admin instanceof mysqli) {
        $admin->close();
    }
}

if ($fallo instanceof Throwable) {
    fwrite(STDERR, 'A1 datos comerciales: ' . $fallo->getMessage() . "\n");
    exit(1);
}
foreach ($casos as $indice => $caso) {
    echo 'Caso ' . ($indice + 1) . ': PASS — ' . $caso . "\n";
}
echo "Total: {$comprobaciones} comprobaciones PASS.\n";
