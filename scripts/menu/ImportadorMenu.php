<?php
declare(strict_types=1);

namespace MenuPreservacion;

require_once __DIR__ . '/Comun.php';
require_once __DIR__ . '/VerificadorMenu.php';

/** Importación transaccional del catálogo v1 a un esquema de ensayo 3A. */
final class ImportadorMenu
{
    private const VERSION_ESQUEMA = 'multinegocio-3a-v1';
    private const BASE_DESTINO = '/^cp_multinegocio_test_[a-f0-9]{20}$/D';
    private const CARTAS = ['comida', 'maridaje', 'temporada'];

    public static function importar(\mysqli $db, string $paquete, string $raizImagenes, string $token): array
    {
        // MySQL rechaza SET TRANSACTION dentro de una transacción activa (1568),
        // sin confirmarla. mysqli no expone server_status de forma portable.
        try { $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
        catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1568) {
                throw new \RuntimeException('La importación requiere una conexión sin transacción activa.');
            }
            throw $e;
        }
        self::validarDestino($db, $token);
        $validacion = VerificadorMenu::verificar($paquete);
        if (!$validacion['ok']) {
            throw new \RuntimeException('El paquete de menú no superó la verificación completa.');
        }
        $menu = Comun::leerJson(self::archivoPaquete($paquete, 'menu.json'));
        $manifiesto = Comun::leerJson(self::archivoPaquete($paquete, 'manifiesto.json'));
        $packageHash = hash_file('sha256', self::archivoPaquete($paquete, 'manifiesto.json'));
        if (!is_string($packageHash)) {
            throw new \RuntimeException('No se pudo calcular la huella del manifiesto.');
        }

        $unidad = self::unidadRestaurante($db);
        $registrada = self::registroPaquete($db, $packageHash);
        if ($registrada !== null) {
            $persistedRoot = (string) ($registrada['correspondencias']['raiz_imagenes'] ?? '');
            $suppliedRoot = realpath($raizImagenes);
            if ($persistedRoot === '' || $suppliedRoot === false || $suppliedRoot !== $persistedRoot) {
                throw new \RuntimeException('La repetición debe señalar la misma raíz de imágenes persistida.');
            }
            self::verificarArchivosDestino($persistedRoot, $paquete, $manifiesto);
            $projection = self::proyectarInterno($db, $menu, $packageHash, $registrada, $unidad);
            self::exigirEquivalencia($menu, $projection);
            return self::resultado('YA_IMPORTADO', $registrada['conteos'], $registrada['correspondencias'], $projection, self::hashesArchivos($manifiesto));
        }

        self::exigirCatalogoVacio($db);
        self::exigirCartas($db, $unidad);
        $raiz = self::crearRaizNueva($raizImagenes, $paquete);
        $archivoMap = self::copiarArchivos($paquete, $raiz, $manifiesto);
        self::verificarArchivosDestino($raiz, $paquete, $manifiesto);

        $productoMap = [];
        $categoriaMap = [];
        $areaMap = [];
        $ofertaMap = [];
        $db->begin_transaction();
        try {
            self::validarDestino($db, $token);
            if (self::registroPaquete($db, $packageHash) !== null) {
                throw new \RuntimeException('El paquete fue importado concurrentemente; vuelva a proyectar el resultado.');
            }
            self::exigirCatalogoVacio($db);
            self::exigirCartas($db, $unidad);

            $insertArea = $db->prepare('INSERT INTO areas_produccion (id, unidad_id, nombre, slug, color, orden) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($menu['areas'] as $ordinal => $area) {
                $id = $area['id']; $nombre = $area['nombre']; $slug = $area['slug']; $color = $area['color']; $orden = $ordinal;
                $insertArea->bind_param('sssssi', $id, $unidad, $nombre, $slug, $color, $orden);
                $insertArea->execute();
                $areaMap[$area['id']] = $area['id'];
            }

            $insertCategoria = $db->prepare('INSERT INTO categorias (id, unidad_id, nombre, carta, img, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($menu['categorias'] as $ordinal => $categoria) {
                $id = $categoria['id']; $nombre = $categoria['nombre']; $carta = $categoria['carta']; $img = $categoria['img'];
                $activo = $categoria['activo']; $orden = $ordinal;
                $insertCategoria->bind_param('ssssssi', $id, $unidad, $nombre, $carta, $img, $activo, $orden);
                $insertCategoria->execute();
                $categoriaMap[$categoria['id']] = $categoria['id'];
            }

            $insertProducto = $db->prepare('INSERT INTO productos (id, nombre, descripcion, img) VALUES (?, ?, ?, ?)');
            foreach ($menu['productos'] as $producto) {
                $id = $producto['id']; $nombre = $producto['nombre']; $descripcion = $producto['descripcion']; $img = $producto['img'];
                $insertProducto->bind_param('ssss', $id, $nombre, $descripcion, $img);
                $insertProducto->execute();
                $productoMap[$producto['id']] = $producto['id'];
            }

            $insertOferta = $db->prepare('INSERT INTO ofertas_producto (producto_id, unidad_id, categoria_id, precio, area_id, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ordenPorProducto = [];
            foreach ($menu['orden']['productos'] as $ordinal => $id) $ordenPorProducto[$id] = $ordinal;
            foreach ($menu['productos'] as $producto) {
                $productoId = $producto['id']; $categoriaId = $producto['categoria_id']; $precio = $producto['precio'];
                $areaId = $producto['area_id']; $activo = $producto['activo']; $orden = $ordenPorProducto[$productoId];
                $insertOferta->bind_param('ssssssi', $productoId, $unidad, $categoriaId, $precio, $areaId, $activo, $orden);
                $insertOferta->execute();
                $ofertaMap[$producto['id']] = (string) $db->insert_id;
            }

            $correspondencias = [
                'version' => 1,
                'productos' => $productoMap,
                'categorias' => $categoriaMap,
                'areas' => $areaMap,
                'ofertas' => $ofertaMap,
                'archivos' => $archivoMap,
                'raiz_imagenes' => $raiz,
            ];
            $counts = [
                'productos' => count($productoMap), 'categorias' => count($categoriaMap),
                'areas' => count($areaMap), 'ofertas' => count($ofertaMap), 'archivos' => count($archivoMap),
            ];
            $conteosJson = json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $mapasJson = json_encode($correspondencias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insertLog = $db->prepare('INSERT INTO importaciones_menu (paquete_sha256, unidad_id, fecha, conteos, correspondencias) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)');
            $insertLog->bind_param('ssss', $packageHash, $unidad, $conteosJson, $mapasJson);
            $insertLog->execute();

            $projection = self::proyectarInterno($db, $menu, $packageHash, ['correspondencias' => $correspondencias], $unidad);
            self::exigirEquivalencia($menu, $projection);
            self::verificarArchivosDestino($raiz, $paquete, $manifiesto);
            self::confirmarPaqueteSinCambios($paquete, $manifiesto, $packageHash);
            $db->commit();
            return self::resultado('MENU_IMPORTADO', $counts, $correspondencias, $projection, self::hashesArchivos($manifiesto));
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable) {}
            if ($e instanceof \RuntimeException) throw $e;
            throw new \RuntimeException('Falló la importación; la transacción SQL se revirtió.');
        }
    }

    public static function proyectar(\mysqli $db, string $paquete): array
    {
        self::validarDestino($db, null);
        $validacion = VerificadorMenu::verificar($paquete);
        if (!$validacion['ok']) throw new \RuntimeException('El paquete de menú no superó la verificación completa.');
        $menu = Comun::leerJson(self::archivoPaquete($paquete, 'menu.json'));
        $packageHash = hash_file('sha256', self::archivoPaquete($paquete, 'manifiesto.json'));
        if (!is_string($packageHash)) throw new \RuntimeException('No se pudo calcular la huella del manifiesto.');
        $unidad = self::unidadRestaurante($db);
        $registro = self::registroPaquete($db, $packageHash);
        if ($registro === null) throw new \RuntimeException('El paquete todavía no está registrado en el destino.');
        $persistedRoot = (string) ($registro['correspondencias']['raiz_imagenes'] ?? '');
        if ($persistedRoot === '') throw new \RuntimeException('Falta la raíz de imágenes persistida.');
        self::verificarArchivosDestino($persistedRoot, $paquete, Comun::leerJson(self::archivoPaquete($paquete, 'manifiesto.json')));
        $projection = self::proyectarInterno($db, $menu, $packageHash, $registro, $unidad);
        self::exigirEquivalencia($menu, $projection);
        return $projection;
    }

    private static function validarDestino(\mysqli $db, ?string $token): void
    {
        $row = $db->query('SELECT DATABASE() AS nombre')->fetch_assoc();
        $nombre = is_array($row) ? (string) ($row['nombre'] ?? '') : '';
        if (preg_match(self::BASE_DESTINO, $nombre) !== 1) {
            throw new \RuntimeException('La base destino no es una base de ensayo multinegocio autorizada.');
        }
        $ownerRows = $db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_ASSOC);
        if (count($ownerRows) !== 1 || !is_string($ownerRows[0]['token'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $ownerRows[0]['token']) !== 1) {
            throw new \RuntimeException('La marca de propiedad de la base destino no es válida.');
        }
        if ($token !== null) {
            if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new \RuntimeException('Token de propiedad inválido.');
            if (!hash_equals($ownerRows[0]['token'], $token)) {
                throw new \RuntimeException('La base destino no coincide con la marca de propiedad.');
            }
        }
        $version = $db->prepare('SELECT version FROM esquema_versiones WHERE version = ? LIMIT 1');
        $expected = self::VERSION_ESQUEMA;
        $version->bind_param('s', $expected);
        $version->execute();
        if ($version->get_result()->num_rows !== 1) throw new \RuntimeException('La base destino no tiene el esquema multinegocio requerido.');
    }

    private static function unidadRestaurante(\mysqli $db): string
    {
        $slug = 'restaurante'; $tipo = 'negocio';
        $stmt = $db->prepare('SELECT id FROM unidades WHERE slug = ? AND tipo = ? LIMIT 1');
        $stmt->bind_param('ss', $slug, $tipo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!is_array($row) || !isset($row['id'])) throw new \RuntimeException('No existe la unidad Restaurante en el destino.');
        return (string) $row['id'];
    }

    private static function registroPaquete(\mysqli $db, string $hash): ?array
    {
        $stmt = $db->prepare('SELECT unidad_id, conteos, correspondencias FROM importaciones_menu WHERE paquete_sha256 = ? LIMIT 1');
        $stmt->bind_param('s', $hash); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!is_array($row)) return null;
        $counts = json_decode((string) $row['conteos'], true);
        $maps = json_decode((string) $row['correspondencias'], true);
        if (!is_array($counts) || !is_array($maps) || !isset($row['unidad_id'])) throw new \RuntimeException('El registro de importación está dañado.');
        return ['unidad_id' => (string) $row['unidad_id'], 'conteos' => $counts, 'correspondencias' => $maps];
    }

    private static function exigirCatalogoVacio(\mysqli $db): void
    {
        foreach (['productos', 'categorias', 'areas_produccion', 'ofertas_producto'] as $table) {
            $count = (int) $db->query('SELECT COUNT(*) AS total FROM `' . $table . '`')->fetch_assoc()['total'];
            if ($count !== 0) throw new \RuntimeException('El destino ya contiene catálogo comercial; se requiere un destino vacío.');
        }
    }

    private static function exigirCartas(\mysqli $db, string $unidad): void
    {
        $stmt = $db->prepare('SELECT slug FROM cartas WHERE unidad_id = ?');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        $got = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'slug');
        sort($got); $want = self::CARTAS; sort($want);
        if ($got !== $want) throw new \RuntimeException('Las cartas seed de Restaurante no coinciden con el contrato.');
    }

    private static function crearRaizNueva(string $path, string $package): string
    {
        if ($path === '' || file_exists($path) || is_link($path)) throw new \RuntimeException('La raíz de imágenes debe ser una carpeta nueva.');
        $parentPath = dirname($path); $name = basename($path);
        $parent = realpath($parentPath);
        if ($parent === false || is_link($parentPath) || !preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*$/D', $name) || $name === '.' || $name === '..') {
            throw new \RuntimeException('La raíz de imágenes requiere un padre real y un nombre seguro.');
        }
        $target = $parent . DIRECTORY_SEPARATOR . $name;
        $repoRoot = realpath(dirname(__DIR__, 2));
        $packageRoot = realpath($package);
        if ($repoRoot === false || $packageRoot === false || self::isWithin($target, $repoRoot) || self::isWithin($target, $packageRoot)) {
            throw new \RuntimeException('La raíz nueva debe quedar fuera del repositorio y del paquete fuente.');
        }
        if (!mkdir($target, 0700) || is_link($target)) throw new \RuntimeException('No se pudo crear la raíz nueva de imágenes.');
        $real = realpath($target);
        if ($real === false || dirname($real) !== $parent) throw new \RuntimeException('La raíz de imágenes quedó fuera de su padre validado.');
        return $real;
    }

    private static function copiarArchivos(string $paquete, string $destination, array $manifest): array
    {
        $maps = [];
        foreach (($manifest['archivos'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['ruta'] ?? null) || !is_string($item['sha256'] ?? null)) {
                throw new \RuntimeException('El manifiesto contiene una referencia de archivo inválida.');
            }
            $relative = $item['ruta'];
            $source = Comun::rutaArchivo(rtrim($paquete, '/\\') . DIRECTORY_SEPARATOR . 'archivos', $relative);
            $before = hash_file('sha256', $source);
            if (!is_string($before) || !hash_equals($item['sha256'], $before)) throw new \RuntimeException('Una imagen de origen cambió después de verificar el paquete.');
            $segments = explode('/', $relative); $target = $destination;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $target .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($target)) throw new \RuntimeException('La ruta destino contiene un enlace simbólico.');
                if (!is_dir($target) && !mkdir($target, 0700)) throw new \RuntimeException('No se pudo crear una carpeta de imágenes.');
            }
            $target .= DIRECTORY_SEPARATOR . end($segments);
            if (file_exists($target) || is_link($target) || !copy($source, $target)) throw new \RuntimeException('No se pudo copiar una imagen a la raíz aislada.');
            $afterSource = hash_file('sha256', $source); $afterTarget = hash_file('sha256', $target);
            if (!is_string($afterSource) || !is_string($afterTarget) || !hash_equals($item['sha256'], $afterSource) || !hash_equals($item['sha256'], $afterTarget)) {
                throw new \RuntimeException('La huella de una imagen cambió durante la copia.');
            }
            $maps[$relative] = $relative;
        }
        return $maps;
    }

    private static function proyectarInterno(\mysqli $db, array $menu, string $hash, array $registro, string $unidad): array
    {
        if (!hash_equals((string) ($registro['unidad_id'] ?? $unidad), $unidad)) throw new \RuntimeException('El paquete está registrado para otra unidad.');
        $maps = $registro['correspondencias'] ?? null;
        if (!is_array($maps) || !is_array($maps['productos'] ?? null) || !is_array($maps['categorias'] ?? null)
            || !is_array($maps['areas'] ?? null) || !is_array($maps['ofertas'] ?? null)) {
            throw new \RuntimeException('Las correspondencias persistidas del paquete están dañadas.');
        }

        $products = [];
        $rows = $db->query('SELECT id, nombre, descripcion, img FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) $products[(string) $r['id']] = $r;
        $categories = [];
        $stmt = $db->prepare('SELECT id, nombre, carta, img, activo FROM categorias WHERE unidad_id = ? ORDER BY id');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $categories[(string) $r['id']] = $r;
        $categoryOrder = [];
        $stmt = $db->prepare('SELECT id, nombre, carta, img, activo, orden FROM categorias WHERE unidad_id = ? ORDER BY orden, id');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        $categoryOrder = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $areas = [];
        $stmt = $db->prepare('SELECT id, nombre, slug, color FROM areas_produccion WHERE unidad_id = ? ORDER BY id');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $areas[(string) $r['id']] = $r;
        $areaOrder = [];
        $stmt = $db->prepare('SELECT id, nombre, slug, color, orden FROM areas_produccion WHERE unidad_id = ? ORDER BY orden, id');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        $areaOrder = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $offerRows = [];
        $stmt = $db->prepare('SELECT o.id AS oferta_id, o.producto_id, o.categoria_id, o.precio, o.area_id, o.activo, o.orden, p.nombre, p.descripcion, p.img, c.nombre AS categoria_nombre, c.carta, c.img AS categoria_img, c.activo AS categoria_activo, c.orden AS categoria_orden, a.nombre AS area_nombre, a.slug AS area_slug, a.color AS area_color, a.orden AS area_orden FROM ofertas_producto o JOIN productos p ON p.id = o.producto_id JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id JOIN areas_produccion a ON a.id = o.area_id AND a.unidad_id = o.unidad_id WHERE o.unidad_id = ? ORDER BY o.orden, o.id');
        $stmt->bind_param('s', $unidad); $stmt->execute();
        $offerRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $inverseProduct = self::invertirMapa($maps['productos']);
        $inverseCategory = self::invertirMapa($maps['categorias']);
        $inverseArea = self::invertirMapa($maps['areas']);
        self::validarOrdinales($offerRows, 'producto_id', 'orden', $maps['productos']);
        self::validarOrdinales($categoryOrder, 'id', 'orden', $maps['categorias']);
        self::validarOrdinales($areaOrder, 'id', 'orden', $maps['areas']);
        $projectionProducts = [];
        foreach ($offerRows as $row) {
            $productId = (string) $row['producto_id'];
            if (!isset($inverseProduct[$productId], $inverseCategory[(string)$row['categoria_id']], $inverseArea[(string)$row['area_id']])) {
                throw new \RuntimeException('El catálogo persistido contiene referencias ajenas al paquete importado.');
            }
            $sourceId = $inverseProduct[$productId];
            if (!isset($maps['ofertas'][$sourceId]) || (string)$maps['ofertas'][$sourceId] !== (string)$row['oferta_id']) {
                throw new \RuntimeException('La oferta persistida no coincide con el mapa de correspondencias.');
            }
            if (isset($projectionProducts[$sourceId])) throw new \RuntimeException('El catálogo persistido contiene ofertas duplicadas.');
            $projectionProducts[$sourceId] = [
                'id' => $sourceId, 'nombre' => (string)$row['nombre'], 'descripcion' => $row['descripcion'], 'img' => $row['img'],
                'categoria_id' => $inverseCategory[(string)$row['categoria_id']], 'precio' => (string)$row['precio'],
                'area_id' => $inverseArea[(string)$row['area_id']], 'activo' => (string)$row['activo'],
            ];
        }
        if (count($products) !== count($maps['productos']) || count($categories) !== count($maps['categorias']) || count($areas) !== count($maps['areas']) || count($offerRows) !== count($maps['ofertas'])) {
            throw new \RuntimeException('El catálogo del destino cambió después de la importación.');
        }
        foreach ($maps['productos'] as $source => $dest) {
            if (!isset($products[(string)$dest])) throw new \RuntimeException('Falta un producto importado.');
            $p = $products[(string)$dest];
            if (isset($projectionProducts[(string)$source])) {
                $projectionProducts[(string)$source]['nombre'] = (string)$p['nombre'];
                $projectionProducts[(string)$source]['descripcion'] = $p['descripcion'];
                $projectionProducts[(string)$source]['img'] = $p['img'];
            }
        }
        foreach ($maps['categorias'] as $source => $dest) if (!isset($categories[(string)$dest])) throw new \RuntimeException('Falta una categoría importada.');
        foreach ($maps['areas'] as $source => $dest) if (!isset($areas[(string)$dest])) throw new \RuntimeException('Falta un área importada.');

        $productRows = array_values($projectionProducts);
        $projectionCategories = [];
        foreach ($categoryOrder as $r) {
            $source = $inverseCategory[(string)$r['id']];
            $projectionCategories[] = ['id' => $source, 'nombre' => (string)$r['nombre'], 'carta' => (string)$r['carta'], 'img' => $r['img'], 'activo' => (string)$r['activo']];
        }
        $projectionAreas = [];
        foreach ($areaOrder as $r) {
            $source = $inverseArea[(string)$r['id']];
            $projectionAreas[] = ['id' => $source, 'nombre' => (string)$r['nombre'], 'slug' => (string)$r['slug'], 'color' => (string)$r['color']];
        }
        $categoryRows = $projectionCategories;
        return [
            'formato' => 'casa-pestalozzi.menu', 'version' => 1, 'negocio' => 'restaurante',
            'productos' => $productRows, 'categorias' => $categoryRows, 'areas' => $projectionAreas,
            'orden' => [
                'productos' => array_column($productRows, 'id'),
                'categorias' => array_column($categoryRows, 'id'),
            ],
        ];
    }

    private static function exigirEquivalencia(array $expected, array $projection): void
    {
        if (VerificadorMenu::comparar($expected, $projection) !== []) {
            throw new \RuntimeException('La proyección del destino difiere del paquete o el catálogo fue alterado.');
        }
    }

    private static function confirmarPaqueteSinCambios(string $package, array $manifest, string $manifestHash): void
    {
        $manifestPath = self::archivoPaquete($package, 'manifiesto.json');
        $menuPath = self::archivoPaquete($package, 'menu.json');
        $mapsPath = self::archivoPaquete($package, 'correspondencias.json');
        $currentManifest = hash_file('sha256', $manifestPath);
        if (!is_string($currentManifest) || !hash_equals($manifestHash, $currentManifest)) {
            throw new \RuntimeException('El manifiesto del paquete cambió durante la importación.');
        }
        foreach (['menu.json' => $menuPath, 'correspondencias.json' => $mapsPath] as $name => $path) {
            $actual = hash_file('sha256', $path);
            $expected = $manifest['hashes'][$name] ?? null;
            if (!is_string($actual) || !is_string($expected) || !hash_equals($expected, $actual)) {
                throw new \RuntimeException('El paquete cambió durante la importación.');
            }
        }
    }

    private static function invertirMapa(array $map): array
    {
        $inverse = [];
        foreach ($map as $source => $destination) {
            if ((!is_string($source) && !is_int($source)) || !is_string($destination) || isset($inverse[$destination])) throw new \RuntimeException('Mapa de correspondencias inválido o con fusiones.');
            $inverse[$destination] = (string) $source;
        }
        return $inverse;
    }

    /** Comprueba identidad y ordinal consecutivo de las filas ordenadas por SQL. */
    private static function validarOrdinales(array $rows, string $idColumn, string $orderColumn, array $sourceMap): void
    {
        $expected = array_values($sourceMap);
        if (count($rows) !== count($expected)) throw new \RuntimeException('Cambió la cantidad de filas ordenadas del catálogo.');
        foreach ($rows as $index => $row) {
            if ((string) ($row[$idColumn] ?? '') !== (string) ($expected[$index] ?? '')
                || (int) ($row[$orderColumn] ?? -1) !== $index) {
                throw new \RuntimeException('El orden persistido del catálogo fue alterado.');
            }
        }
    }

    private static function verificarArchivosDestino(string $root, string $package, array $manifest): void
    {
        if (!is_dir($root) || is_link($root)) throw new \RuntimeException('La raíz persistida de imágenes no está disponible.');
        $realRoot = realpath($root);
        $repoRoot = realpath(dirname(__DIR__, 2));
        $packageRoot = realpath($package);
        if ($realRoot === false || $realRoot !== $root || $repoRoot === false || $packageRoot === false
            || self::isWithin($realRoot, $repoRoot) || self::isWithin($realRoot, $packageRoot)) {
            throw new \RuntimeException('La raíz persistida de imágenes dejó de ser segura.');
        }
        $expected = [];
        foreach (($manifest['archivos'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['ruta'] ?? null) || !is_string($item['sha256'] ?? null)
                || !is_int($item['bytes'] ?? null) || !is_string($item['mime'] ?? null)) {
                throw new \RuntimeException('El manifiesto contiene una huella de archivo inválida.');
            }
            $relative = $item['ruta'];
            $source = Comun::rutaArchivo(rtrim($package, '/\\') . DIRECTORY_SEPARATOR . 'archivos', $relative);
            $destination = Comun::rutaArchivo($realRoot, $relative);
            $sourceHash = hash_file('sha256', $source);
            $destinationHash = hash_file('sha256', $destination);
            $bytes = filesize($destination);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($destination);
            if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($item['sha256'], $sourceHash)
                || !hash_equals($item['sha256'], $destinationHash) || $bytes !== $item['bytes'] || $mime !== $item['mime']) {
                throw new \RuntimeException('Una imagen destino no coincide con la huella del paquete.');
            }
            $expected[$relative] = true;
        }
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new \RuntimeException('La raíz de imágenes destino contiene enlaces simbólicos.');
            if (!$entry->isFile()) continue;
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($realRoot, '/\\')) + 1));
            $seen[$relative] = true;
        }
        if (array_keys($seen) !== array_keys($expected)) {
            $got = array_keys($seen); $want = array_keys($expected); sort($got); sort($want);
            if ($got !== $want) throw new \RuntimeException('La raíz de imágenes destino contiene archivos extra o faltantes.');
        }
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        $parent = strtolower(str_replace('\\', '/', rtrim($parent, '/\\')));
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private static function resultado(string $code, array $counts, array $maps, array $projection, array $fileHashes): array
    {
        return ['ok' => true, 'codigo' => $code, 'conteos' => $counts, 'correspondencias' => $maps,
            'hashes_archivos' => $fileHashes, 'proyeccion' => $projection];
    }

    private static function hashesArchivos(array $manifest): array
    {
        $hashes = [];
        foreach (($manifest['archivos'] ?? []) as $file) {
            if (is_array($file) && is_string($file['ruta'] ?? null) && is_string($file['sha256'] ?? null)) {
                $hashes[$file['ruta']] = $file['sha256'];
            }
        }
        return $hashes;
    }

    private static function archivoPaquete(string $root, string $name): string
    {
        if (!is_dir($root) || is_link($root)) throw new \RuntimeException('La ruta del paquete no es un directorio seguro.');
        $real = realpath($root);
        $path = $real . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('Falta un archivo requerido del paquete.');
        return $path;
    }

    private static function compararIds(string $a, string $b): int
    {
        $length = strlen($a) <=> strlen($b);
        return $length !== 0 ? $length : strcmp($a, $b);
    }
}
