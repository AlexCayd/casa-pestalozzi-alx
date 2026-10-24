<?php

declare(strict_types=1);

namespace MenuPreservacion;

use InvalidArgumentException;
use RuntimeException;
use mysqli;
use Throwable;

/** Crea un paquete de solo lectura con el catálogo actual de Restaurante. */
final class ExportadorMenu
{
    private const TABLAS = [
        'productos' => ['id', 'nombre', 'descripcion', 'img', 'categoria_id', 'precio', 'area_id', 'activo'],
        'categorias' => ['id', 'nombre', 'carta', 'img', 'activo'],
        'areas_produccion' => ['id', 'nombre', 'slug', 'color'],
    ];

    /** @return array<string, mixed> */
    public static function capturar(mysqli $db): array
    {
        self::validarEsquema($db);

        $productos = Comun::filas(
            $db,
            'SELECT id, nombre, descripcion, img, categoria_id, precio, area_id, activo FROM productos ORDER BY id ASC'
        );

        $categoriaIds = [];
        $areaIds = [];
        foreach ($productos as $fila) {
            $categoriaIds[] = self::id($fila['categoria_id'] ?? null, 'productos.categoria_id');
            $areaIds[] = self::id($fila['area_id'] ?? null, 'productos.area_id');
        }
        $categoriaIds = array_values(array_unique($categoriaIds));
        $areaIds = array_values(array_unique($areaIds));

        $categorias = self::filasReferenciadas(
            $db,
            'categorias',
            ['id', 'nombre', 'carta', 'img', 'activo'],
            $categoriaIds
        );
        $areas = self::filasReferenciadas(
            $db,
            'areas_produccion',
            ['id', 'nombre', 'slug', 'color'],
            $areaIds
        );

        self::validarReferencias($productos, $categorias, $areas);

        return [
            'formato' => 'casa-pestalozzi.menu',
            'version' => 1,
            'negocio' => 'restaurante',
            'productos' => $productos,
            'categorias' => $categorias,
            'areas' => $areas,
            'orden' => [
                'productos' => array_map(static fn(array $r): string => (string) $r['id'], $productos),
                'categorias' => array_map(static fn(array $r): string => (string) $r['id'], $categorias),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function esquema(mysqli $db): array
    {
        self::validarEsquema($db);
        $esquema = [];
        foreach (self::TABLAS as $tabla => $_) {
            $columnas = Comun::filas($db, 'SHOW FULL COLUMNS FROM `' . $tabla . '`');
            $creacion = Comun::filas($db, 'SHOW CREATE TABLE `' . $tabla . '`');
            if (count($creacion) !== 1) {
                throw new RuntimeException("No se pudo leer SHOW CREATE TABLE de {$tabla}.");
            }
            $fila = $creacion[0];
            $create = $fila['Create Table'] ?? array_values($fila)[1] ?? null;
            if (!is_string($create) || $create === '') {
                throw new RuntimeException("SHOW CREATE TABLE no devolvió el DDL de {$tabla}.");
            }
            $esquema[$tabla] = [
                'columns' => $columnas,
                'create' => $create,
            ];
        }

        // Las claves se insertan en orden fijo y las filas SHOW conservan el orden físico de columnas.
        $canon = Comun::json($esquema);
        return [
            'tablas' => $esquema,
            'sha256' => hash('sha256', $canon),
        ];
    }

    /** @param array<string, mixed> $origen
     *  @return array<string, mixed>
     */
    public static function exportar(mysqli $db, string $publicRoot, string $destino, array $origen): array
    {
        $menu = self::capturar($db);
        $esquema = self::esquema($db);
        $parent = dirname($destino);
        $parentReal = realpath($parent);
        $nombreDestino = basename($destino);
        if ($parentReal === false || !is_dir($parentReal) || $nombreDestino === '' || $nombreDestino === '.' || $nombreDestino === '..') {
            throw new InvalidArgumentException('El padre del destino debe existir y la ruta debe incluir un nombre de carpeta válido.');
        }
        // Crear siempre bajo el padre resuelto evita que un alias simbólico altere la raíz efectiva.
        $destino = $parentReal . DIRECTORY_SEPARATOR . $nombreDestino;
        if (file_exists($destino) || is_link($destino)) {
            throw new InvalidArgumentException('El directorio destino ya existe; la exportación no sobrescribe paquetes.');
        }

        if (!mkdir($destino, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio de exportación.');
        }
        $archivosDir = $destino . DIRECTORY_SEPARATOR . 'archivos';
        if (!mkdir($archivosDir, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio de archivos del paquete.');
        }

        try {
            $hashMenu = self::escribirJson($destino . DIRECTORY_SEPARATOR . 'menu.json', $menu);
            $archivos = [];
            $referencias = [];
            foreach (['productos' => 'img', 'categorias' => 'img'] as $coleccion => $campo) {
                foreach ($menu[$coleccion] as $fila) {
                    $ruta = $fila[$campo] ?? null;
                    if ($ruta === null || $ruta === '') {
                        continue;
                    }
                    if (!is_string($ruta)) {
                        throw new RuntimeException('Una ruta de imagen no es texto.');
                    }
                    $referencias[$ruta] = true;
                }
            }

            $rutas = array_keys($referencias);
            sort($rutas, SORT_STRING);
            foreach ($rutas as $ruta) {
                $origenArchivo = Comun::rutaArchivo($publicRoot, $ruta);
                $hashAntes = hash_file('sha256', $origenArchivo);
                if (!is_string($hashAntes)) {
                    throw new RuntimeException("No se pudo calcular el hash del archivo {$ruta}.");
                }
                $destinoArchivo = $archivosDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
                $dirArchivo = dirname($destinoArchivo);
                self::crearDirectorios($archivosDir, $dirArchivo);
                if (!copy($origenArchivo, $destinoArchivo)) {
                    throw new RuntimeException("No se pudo copiar el archivo {$ruta}.");
                }
                $hashDespues = hash_file('sha256', $origenArchivo);
                $hashCopia = hash_file('sha256', $destinoArchivo);
                if ($hashDespues === false || $hashCopia === false || !hash_equals($hashAntes, $hashDespues) || !hash_equals($hashAntes, $hashCopia)) {
                    throw new RuntimeException("El archivo {$ruta} cambió durante la copia o su copia no coincide.");
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($destinoArchivo);
                if (!is_string($mime) || $mime === '') {
                    throw new RuntimeException("No se pudo identificar el tipo de archivo de {$ruta}.");
                }
                $bytes = filesize($destinoArchivo);
                if (!is_int($bytes)) {
                    throw new RuntimeException("No se pudo leer el tamaño del archivo {$ruta}.");
                }
                $archivos[] = [
                    'ruta' => $ruta,
                    'sha256' => $hashCopia,
                    'bytes' => $bytes,
                    'mime' => $mime,
                ];
            }

            $mapaArchivos = $rutas === [] ? (object) [] : (object) array_combine($rutas, $rutas);
            $correspondencias = [
                'version' => 1,
                'productos' => self::mapaIdentidad($menu['productos']),
                'categorias' => self::mapaIdentidad($menu['categorias']),
                'areas' => self::mapaIdentidad($menu['areas']),
                'archivos' => $mapaArchivos,
            ];
            $hashCorrespondencias = self::escribirJson($destino . DIRECTORY_SEPARATOR . 'correspondencias.json', $correspondencias);

            $manifiesto = [
                'formato' => 'casa-pestalozzi.menu.manifiesto',
                'version' => 1,
                'origen' => $origen,
                'esquema' => $esquema,
                'conteos' => [
                    'productos' => count($menu['productos']),
                    'categorias' => count($menu['categorias']),
                    'areas' => count($menu['areas']),
                ],
                'hashes' => [
                    'menu.json' => $hashMenu,
                    'correspondencias.json' => $hashCorrespondencias,
                ],
                'archivos' => $archivos,
            ];
            self::escribirJson($destino . DIRECTORY_SEPARATOR . 'manifiesto.json', $manifiesto);
            return $manifiesto;
        } catch (Throwable $e) {
            // Se conserva el paquete parcial para diagnóstico; nunca se hace limpieza recursiva aquí.
            throw $e;
        }
    }

    private static function validarEsquema(mysqli $db): void
    {
        foreach (self::TABLAS as $tabla => $requeridas) {
            $tablas = Comun::filas($db, "SHOW FULL TABLES LIKE '" . $db->real_escape_string($tabla) . "'");
            if (count($tablas) !== 1 || strtolower((string) (array_values($tablas[0])[1] ?? '')) !== 'base table') {
                throw new RuntimeException("{$tabla} debe existir como BASE TABLE; no se admiten vistas.");
            }
            $columnas = Comun::filas($db, 'SHOW FULL COLUMNS FROM `' . $tabla . '`');
            $encontradas = [];
            foreach ($columnas as $columna) {
                $nombre = $columna['Field'] ?? null;
                if (!is_string($nombre)) {
                    throw new RuntimeException("SHOW FULL COLUMNS de {$tabla} devolvió una fila inválida.");
                }
                $encontradas[] = $nombre;
                if ($nombre === 'unidad_id') {
                    throw new RuntimeException("{$tabla}.unidad_id indica datos multinegocio; este exportador solo admite el catálogo mononegocio actual.");
                }
            }
            $permitidas = array_merge($requeridas, ['created_at', 'updated_at']);
            $faltantes = array_diff($requeridas, $encontradas);
            $extras = array_diff($encontradas, $permitidas);
            if ($faltantes !== [] || $extras !== []) {
                throw new RuntimeException(
                    "Esquema inesperado en {$tabla}; faltantes: " . implode(', ', $faltantes) . '; adicionales: ' . implode(', ', $extras) . '.'
                );
            }
            $tiposEnteros = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
            $nullable = [
                'productos' => ['descripcion', 'img'],
                'categorias' => ['img'],
                'areas_produccion' => [],
            ][$tabla];
            $tiposTexto = [
                'nombre' => '/^(?:char|varchar|tinytext|text|mediumtext|longtext)(?:\\(|$)/',
                'descripcion' => '/^(?:tinytext|text|mediumtext|longtext)(?:\\(|$)/',
                'img' => '/^(?:char|varchar|tinytext|text|mediumtext|longtext)(?:\\(|$)/',
                'carta' => '/^enum\\(/',
                'slug' => '/^(?:char|varchar)(?:\\(|$)/',
                'color' => '/^(?:char|varchar)(?:\\(|$)/',
            ];
            $referencias = ['productos' => ['categoria_id', 'area_id'], 'categorias' => [], 'areas_produccion' => []][$tabla];
            foreach ($columnas as $columna) {
                $nombre = (string) ($columna['Field'] ?? '');
                $tipo = strtolower((string) ($columna['Type'] ?? ''));
                $esNullable = strtoupper((string) ($columna['Null'] ?? '')) === 'YES';
                if (in_array($nombre, $requeridas, true) && ($esNullable !== in_array($nombre, $nullable, true))) {
                    throw new RuntimeException("{$tabla}.{$nombre} tiene nulabilidad inesperada.");
                }
                if (in_array($nombre, ['id', ...$referencias, 'activo'], true)) {
                    $patronEntero = '/^(?:' . implode('|', $tiposEnteros) . ')(?:\\(\\d+\\))?(?:\\s+unsigned)?(?:\\s+zerofill)?$/';
                    if (!preg_match($patronEntero, $tipo)) {
                        throw new RuntimeException("{$tabla}.{$nombre} debe ser de tipo entero.");
                    }
                }
                if ($tabla === 'productos' && $nombre === 'precio' && !preg_match('/^decimal\\(\\d+,2\\)(?: unsigned)?$/', $tipo)) {
                    throw new RuntimeException('productos.precio debe ser DECIMAL(p,2) para preservar su valor exacto.');
                }
                if (isset($tiposTexto[$nombre]) && in_array($nombre, $requeridas, true) && !preg_match($tiposTexto[$nombre], $tipo)) {
                    throw new RuntimeException("{$tabla}.{$nombre} debe ser de tipo texto compatible.");
                }
                if (in_array($nombre, ['created_at', 'updated_at'], true)
                    && !preg_match('/^(?:timestamp|datetime)(?:\\(|$)/', $tipo)) {
                    throw new RuntimeException("{$tabla}.{$nombre} se admite solo como metadata temporal; no se importa al catálogo.");
                }
            }
        }
    }

    /** @param list<string> $ids
     *  @param list<string> $campos
     *  @return list<array<string, mixed>>
     */
    private static function filasReferenciadas(mysqli $db, string $tabla, array $campos, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $lista = implode(',', array_map(static fn(string $id): string => "'" . $db->real_escape_string($id) . "'", $ids));
        return Comun::filas(
            $db,
            'SELECT ' . implode(', ', $campos) . ' FROM `' . $tabla . '` WHERE id IN (' . $lista . ') ORDER BY id ASC'
        );
    }

    private static function id(mixed $valor, string $campo): string
    {
        if (!is_string($valor) && !is_int($valor)) {
            throw new RuntimeException("{$campo} contiene un ID nulo o inválido.");
        }
        $id = (string) $valor;
        if (!preg_match('/^[0-9]+$/', $id)) {
            throw new RuntimeException("{$campo} contiene un ID inválido.");
        }
        return $id;
    }

    private static function validarReferencias(array $productos, array $categorias, array $areas): void
    {
        $cat = [];
        foreach ($categorias as $fila) {
            $cat[self::id($fila['id'] ?? null, 'categorias.id')] = true;
        }
        $area = [];
        foreach ($areas as $fila) {
            $area[self::id($fila['id'] ?? null, 'areas_produccion.id')] = true;
        }
        foreach ($productos as $fila) {
            if (!isset($cat[self::id($fila['categoria_id'] ?? null, 'productos.categoria_id')])) {
                throw new RuntimeException('Hay productos cuya categoría referenciada no existe.');
            }
            if (!isset($area[self::id($fila['area_id'] ?? null, 'productos.area_id')])) {
                throw new RuntimeException('Hay productos cuya área referenciada no existe.');
            }
        }
    }

    private static function mapaIdentidad(array $filas): object
    {
        $mapa = [];
        foreach ($filas as $fila) {
            $id = self::id($fila['id'] ?? null, 'id');
            $mapa[$id] = $id;
        }
        return (object) $mapa;
    }

    private static function escribirJson(string $ruta, mixed $valor): string
    {
        Comun::escribirJson($ruta, $valor);
        $hash = hash_file('sha256', $ruta);
        if (!is_string($hash)) {
            throw new RuntimeException('No se pudo calcular el hash de ' . basename($ruta) . '.');
        }
        return $hash;
    }

    private static function crearDirectorios(string $base, string $directorio): void
    {
        $relativo = substr($directorio, strlen($base));
        $actual = $base;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relativo), static fn(string $p): bool => $p !== '') as $segmento) {
            $actual .= DIRECTORY_SEPARATOR . $segmento;
            if (is_link($actual)) {
                throw new RuntimeException('La ruta de destino contiene un enlace simbólico.');
            }
            if (!is_dir($actual) && !mkdir($actual, 0700)) {
                throw new RuntimeException('No se pudo crear un directorio de archivos del paquete.');
            }
        }
    }

}
