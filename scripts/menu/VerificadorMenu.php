<?php
declare(strict_types=1);

namespace MenuPreservacion;

/** Verificación de solo lectura para paquetes de preservación del menú. */
final class VerificadorMenu
{
    private const MENU_FORMATO = 'casa-pestalozzi.menu';
    private const MANIFIESTO_FORMATO = 'casa-pestalozzi.menu.manifiesto';
    private const TABLAS = ['productos', 'categorias', 'areas'];

    public static function verificar(string $package, ?array $esperado = null): array
    {
        $errores = [];
        $conteos = ['productos' => 0, 'categorias' => 0, 'areas' => 0, 'archivos' => 0];
        try {
            if (!is_dir($package) || is_link($package)) {
                return self::resultado(['La ruta del paquete debe ser un directorio real.'], $conteos);
            }
            $menu = self::leerJson($package, 'menu.json', $errores);
            $manifiesto = self::leerJson($package, 'manifiesto.json', $errores);
            $mapas = self::leerJson($package, 'correspondencias.json', $errores);
            if (!is_array($menu) || !is_array($manifiesto) || !is_array($mapas)) {
                return self::resultado($errores, $conteos);
            }

            self::validarMenu($menu, $conteos, $errores);
            self::validarManifiesto($package, $manifiesto, $menu, $conteos, $errores);
            self::validarMapas($mapas, $menu, $manifiesto, $errores);
            if ($esperado !== null) {
                $errores = array_merge($errores, self::comparar($esperado, $menu));
            }
        } catch (\Throwable $e) {
            $errores[] = 'No se pudo validar el paquete: ' . $e->getMessage();
        }
        return self::resultado(array_values(array_unique($errores)), $conteos);
    }

    /** Devuelve diferencias por ruta, respetando tipo, null, vacío y orden. */
    public static function comparar(array $esperado, array $actual): array
    {
        $errores = [];
        self::compararValor($esperado, $actual, '$', $errores);
        return $errores;
    }

    private static function compararValor(mixed $esperado, mixed $actual, string $ruta, array &$errores): void
    {
        if (is_array($esperado) && is_array($actual)) {
            $esLista = array_is_list($esperado);
            if ($esLista !== array_is_list($actual)) {
                $errores[] = "$ruta: estructura de lista/objeto distinta.";
                return;
            }
            if ($esLista) {
                $max = max(count($esperado), count($actual));
                for ($i = 0; $i < $max; $i++) {
                    if (!array_key_exists($i, $esperado)) $errores[] = "{$ruta}[$i]: elemento extra en actual.";
                    elseif (!array_key_exists($i, $actual)) $errores[] = "{$ruta}[$i]: elemento faltante en actual.";
                    else self::compararValor($esperado[$i], $actual[$i], "{$ruta}[$i]", $errores);
                }
                return;
            }
            foreach ($esperado as $k => $v) {
                if (!array_key_exists($k, $actual)) $errores[] = "$ruta.$k: campo faltante en actual.";
                else self::compararValor($v, $actual[$k], "$ruta.$k", $errores);
            }
            foreach ($actual as $k => $_) if (!array_key_exists($k, $esperado)) $errores[] = "$ruta.$k: campo extra en actual.";
            return;
        }
        if (get_debug_type($esperado) !== get_debug_type($actual) || $esperado !== $actual) {
            $errores[] = "$ruta: valor distinto (incluye tipo, NULL y cadena vacía).";
        }
    }

    private static function validarMenu(array $m, array &$c, array &$e): void
    {
        self::claves($m, ['formato','version','negocio','productos','categorias','areas','orden'], 'menu.json', $e);
        if (($m['formato'] ?? null) !== self::MENU_FORMATO) $e[] = 'menu.json: formato no reconocido.';
        if (($m['version'] ?? null) !== 1) $e[] = 'menu.json: versión debe ser entero 1.';
        if (($m['negocio'] ?? null) !== 'restaurante') $e[] = 'menu.json: negocio debe ser restaurante.';
        $schemas = [
            'productos' => ['id'=>'string','nombre'=>'string','descripcion'=>'nullable-string','img'=>'nullable-string','categoria_id'=>'string','precio'=>'string','area_id'=>'string','activo'=>'active'],
            'categorias' => ['id'=>'string','nombre'=>'string','carta'=>'string','img'=>'nullable-string','activo'=>'active'],
            'areas' => ['id'=>'string','nombre'=>'string','slug'=>'string','color'=>'string'],
        ];
        $ids = [];
        foreach ($schemas as $table => $schema) {
            $rows = $m[$table] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) { $e[] = "menu.json: $table debe ser una lista."; continue; }
            $c[$table] = count($rows); $ids[$table] = [];
            foreach ($rows as $i => $row) {
                $at = "{$table}[$i]";
                if (!is_array($row) || array_is_list($row)) { $e[] = "$at debe ser objeto."; continue; }
                self::claves($row, array_keys($schema), $at, $e);
                foreach ($schema as $field => $type) {
                    if (!array_key_exists($field, $row)) continue;
                    $v = $row[$field];
                    $valid = match ($type) {
                        'string' => is_string($v),
                        'nullable-string' => $v === null || is_string($v),
                        'active' => $v === '0' || $v === '1',
                        default => true,
                    };
                    if (!$valid) $e[] = "$at.$field tiene tipo o valor inválido.";
                }
                if (isset($row['id']) && is_string($row['id'])) {
                    if (!self::esId($row['id'])) $e[] = "$at.id debe ser ID entero positivo canónico en texto.";
                    if (isset($ids[$table][$row['id']])) $e[] = "$at.id duplicado: {$row['id']}.";
                    $ids[$table][$row['id']] = true;
                }
                if ($table === 'productos' && isset($row['precio']) && (!is_string($row['precio']) || !preg_match('/^-?(?:0|[1-9]\d*)\.\d{2}$/D', $row['precio']))) $e[] = "$at.precio debe ser decimal exacto con dos posiciones.";
                if ($table === 'categorias' && isset($row['carta']) && !in_array($row['carta'], ['comida','maridaje','temporada'], true)) $e[] = "$at.carta no es una carta válida.";
            }
        }
        $catIds = $ids['categorias'] ?? []; $areaIds = $ids['areas'] ?? [];
        foreach (($m['productos'] ?? []) as $i => $p) {
            if (!is_array($p)) continue;
            if (isset($p['categoria_id']) && (!is_string($p['categoria_id']) || !self::esId($p['categoria_id']) || !isset($catIds[$p['categoria_id']]))) $e[] = "productos[$i].categoria_id no tiene categoría válida.";
            if (isset($p['area_id']) && (!is_string($p['area_id']) || !self::esId($p['area_id']) || !isset($areaIds[$p['area_id']]))) $e[] = "productos[$i].area_id no tiene área válida.";
        }
        $orden = $m['orden'] ?? null;
        if (!is_array($orden)) { $e[] = 'menu.json: orden debe ser objeto.'; return; }
        self::claves($orden, ['productos','categorias'], 'menu.json.orden', $e);
        foreach (['productos'=>'productos','categorias'=>'categorias'] as $key => $table) {
            $got = $orden[$key] ?? null;
            $want = array_map(static fn($r) => is_array($r) && is_string($r['id'] ?? null) ? $r['id'] : '', $m[$table] ?? []);
            $sorted = $want;
            usort($sorted, static fn(string $a, string $b): int => self::compararIds($a, $b));
            if (!is_array($got) || !array_is_list($got) || $got !== $sorted) $e[] = "menu.json.orden.$key debe enumerar exactamente los IDs ascendentes.";
        }
    }

    private static function validarManifiesto(string $root, array $mf, array $menu, array &$c, array &$e): void
    {
        self::claves($mf, ['formato','version','origen','esquema','conteos','hashes','archivos'], 'manifiesto.json', $e);
        if (($mf['formato'] ?? null) !== self::MANIFIESTO_FORMATO || ($mf['version'] ?? null) !== 1) $e[] = 'manifiesto.json: formato o versión inválidos.';
        foreach (['origen','esquema','conteos','hashes'] as $k) if (!is_array($mf[$k] ?? null)) $e[] = "manifiesto.json.$k debe ser objeto.";
        $esquema = $mf['esquema'] ?? null;
        if (is_array($esquema)) {
            self::claves($esquema, ['tablas','sha256'], 'manifiesto.json.esquema', $e);
            if (!is_array($esquema['tablas'] ?? null) || !is_string($esquema['sha256'] ?? null)) {
                $e[] = 'manifiesto.json.esquema requiere tablas y sha256.';
            } else {
                $canon = \MenuPreservacion\Comun::json($esquema['tablas']);
                if (!hash_equals($esquema['sha256'], hash('sha256', $canon))) $e[] = 'manifiesto.json.esquema.sha256 no corresponde a tablas canonizadas.';
            }
        }
        $counts = $mf['conteos'] ?? [];
        if (is_array($counts)) self::claves($counts, self::TABLAS, 'manifiesto.json.conteos', $e);
        foreach (self::TABLAS as $t) if (($counts[$t] ?? null) !== ($c[$t] ?? null)) $e[] = "manifiesto.json.conteos.$t no coincide.";
        $files = $mf['archivos'] ?? null;
        if (!is_array($files) || !array_is_list($files)) { $e[] = 'manifiesto.json.archivos debe ser lista.'; return; }
        $c['archivos'] = count($files); $seen = []; $metaByPath = [];
        foreach ($files as $i => $f) {
            $at = "manifiesto.json.archivos[$i]";
            if (!is_array($f)) { $e[] = "$at debe ser objeto."; continue; }
            self::claves($f, ['ruta','sha256','bytes','mime'], $at, $e);
            $path = $f['ruta'] ?? null;
            if (!is_string($path) || $path === '' || isset($seen[$path])) { $e[] = "$at.ruta inválida o duplicada."; continue; }
            $seen[$path] = true; $metaByPath[$path] = $f;
            try { $file = \MenuPreservacion\Comun::rutaArchivo(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'archivos', $path); }
            catch (\Throwable) { $e[] = "$at.ruta insegura o inexistente: $path."; continue; }
            if (!is_file($file) || is_link($file)) { $e[] = "$at.ruta no es archivo regular."; continue; }
            $hash = hash_file('sha256', $file); $bytes = filesize($file); $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
            if (!is_string($f['sha256'] ?? null) || !hash_equals($f['sha256'], (string)$hash)) $e[] = "$at.sha256 no coincide.";
            if (!is_int($f['bytes'] ?? null) || $f['bytes'] !== $bytes) $e[] = "$at.bytes no coincide.";
            if (!is_string($f['mime'] ?? null) || $f['mime'] !== $mime) $e[] = "$at.mime no coincide.";
        }
        $used = [];
        foreach (['productos','categorias'] as $table) foreach (($menu[$table] ?? []) as $row) {
            $img = is_array($row) ? ($row['img'] ?? null) : null;
            if ($img !== null && $img !== '') $used[$img] = true;
        }
        foreach ($used as $path => $_) if (!isset($seen[$path])) $e[] = "Imagen referenciada ausente del manifiesto: $path.";
        foreach ($seen as $path => $_) if (!isset($used[$path])) $e[] = "Archivo del manifiesto no referenciado: $path.";
        $assetRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'archivos';
        if (is_dir($assetRoot) && !is_link($assetRoot)) {
            $disk = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assetRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $entry) {
                if ($entry->isLink()) { $e[] = 'archivos/: no se admiten enlaces simbólicos.'; continue; }
                if ($entry->isFile()) {
                    $rel = substr($entry->getPathname(), strlen(rtrim($assetRoot, '/\\')) + 1);
                    $disk[str_replace('\\', '/', $rel)] = true;
                }
            }
            foreach ($disk as $path => $_) if (!isset($seen[$path])) $e[] = "Archivo físico no declarado en manifiesto: $path.";
        } elseif ($files !== []) {
            $e[] = 'Falta el directorio archivos/ del paquete.';
        }
        $hashes = $mf['hashes'] ?? [];
        if (is_array($hashes)) self::claves($hashes, ['menu.json','correspondencias.json'], 'manifiesto.json.hashes', $e);
        foreach (['menu.json','correspondencias.json'] as $name) {
            $file = self::safeRootFile($root, $name);
            $actual = $file === null ? false : hash_file('sha256', $file);
            if (!is_string($hashes[$name] ?? null) || !is_string($actual) || !hash_equals($hashes[$name], $actual)) $e[] = "manifiesto.json.hashes.$name no coincide.";
        }
    }

    private static function validarMapas(array $maps, array $menu, array $mf, array &$e): void
    {
        self::claves($maps, ['version','productos','categorias','areas','archivos'], 'correspondencias.json', $e);
        if (($maps['version'] ?? null) !== 1) $e[] = 'correspondencias.json.version debe ser entero 1.';
        foreach (self::TABLAS as $table) {
            $map = $maps[$table] ?? null;
            if (!is_array($map)) { $e[] = "correspondencias.json.$table debe ser objeto."; continue; }
            $expected = [];
            foreach (($menu[$table] ?? []) as $r) if (is_array($r) && is_string($r['id'] ?? null)) $expected[$r['id']] = $r['id'];
            if ($map !== $expected) $e[] = "correspondencias.json.$table debe mapear cada ID a sí mismo, completa y exactamente.";
        }
        $pathMap = $maps['archivos'] ?? null; $manifestPaths = [];
        foreach (($mf['archivos'] ?? []) as $f) if (is_array($f) && is_string($f['ruta'] ?? null)) $manifestPaths[$f['ruta']] = $f['ruta'];
        if (!is_array($pathMap) || $pathMap !== $manifestPaths) $e[] = 'correspondencias.json.archivos debe mapear todas las rutas a sí mismas.';
    }

    private static function leerJson(string $root, string $name, array &$e): ?array
    {
        $file = self::safeRootFile($root, $name);
        if ($file === null) { $e[] = "Falta $name o su ruta no es segura."; return null; }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) { $e[] = "$name no contiene JSON objeto válido."; return null; }
        return $data;
    }
    private static function safeRootFile(string $root, string $name): ?string
    {
        if (str_contains($name, '/') || str_contains($name, '\\')) return null;
        $p = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $name;
        return is_file($p) && !is_link($p) ? $p : null;
    }
    private static function claves(array $a, array $want, string $at, array &$e): void
    {
        foreach ($want as $k) if (!array_key_exists($k, $a)) $e[] = "$at: falta campo $k.";
        foreach ($a as $k => $_) if (!in_array((string)$k, $want, true)) $e[] = "$at: campo no permitido $k.";
    }
    private static function esId(string $id): bool { return preg_match('/^[1-9][0-9]*$/D', $id) === 1; }
    private static function compararIds(string $a, string $b): int
    {
        $longitud = strlen($a) <=> strlen($b);
        return $longitud !== 0 ? $longitud : strcmp($a, $b);
    }
    private static function resultado(array $e, array $c): array { return ['ok' => $e === [], 'errores' => $e, 'conteos' => $c]; }
}
