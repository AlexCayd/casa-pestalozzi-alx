<?php
declare(strict_types=1);

namespace MenuPreservacion;

/** Respaldo lógico íntegro de las tablas efectivas, nunca del DDL de siembra. */
final class RespaldoMenu
{
    public static function inspeccionar(\mysqli $db): array
    {
        $base = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
        $esc = $db->real_escape_string($base);
        $objetos = [];
        foreach (['VIEWS' => 'TABLE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $tipo => $campo) {
            $objetos[$tipo] = (int) $db->query("SELECT COUNT(*) FROM information_schema.{$tipo} WHERE {$campo} = '{$esc}'")->fetch_row()[0];
        }
        // Abortamos en vez de llamar «completo» a un respaldo que omite objetos.
        if ($objetos['ROUTINES'] !== 0 || $objetos['EVENTS'] !== 0) {
            throw new \RuntimeException('El origen tiene rutinas/eventos: esta versión debe ampliarse antes de respaldarlo.');
        }
        $tablas = Comun::filas($db, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$esc}' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $esquema = [];
        foreach ($tablas as $tabla) {
            if ($tabla['ENGINE'] !== 'InnoDB') {
                throw new \RuntimeException('El respaldo consistente exige todas las tablas InnoDB.');
            }
            $nombre = $tabla['TABLE_NAME'];
            $id = Comun::identificador($nombre);
            $columnas = Comun::filas($db, "SHOW FULL COLUMNS FROM {$id}");
            $create = $db->query("SHOW CREATE TABLE {$id}")->fetch_row()[1];
            $pk = Comun::filas($db, "SHOW INDEX FROM {$id} WHERE Key_name = 'PRIMARY'");
            usort($pk, static fn ($a, $b) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            $esquema[$nombre] = ['create' => $create, 'columnas' => $columnas,
                'orden' => array_column($pk, 'Column_name')];
        }
        if ($esquema === []) {
            throw new \RuntimeException('No hay tablas que respaldar.');
        }
        $defaults = $db->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$esc}'")->fetch_assoc();
        $vistas = [];
        foreach (Comun::filas($db, "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA='{$esc}' ORDER BY TABLE_NAME") as $vista) {
            $nombre = $vista['TABLE_NAME'];
            $create = $db->query('SHOW CREATE VIEW ' . Comun::identificador($nombre))->fetch_assoc();
            $vistas[$nombre] = ['create' => $create['Create View'], 'character_set_client' => $create['character_set_client'], 'collation_connection' => $create['collation_connection']];
        }
        $triggers = [];
        foreach (Comun::filas($db, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{$esc}' ORDER BY TRIGGER_NAME") as $trigger) {
            $nombre = $trigger['TRIGGER_NAME'];
            $create = $db->query('SHOW CREATE TRIGGER ' . Comun::identificador($nombre))->fetch_assoc();
            $triggers[$nombre] = ['create' => $create['SQL Original Statement'], 'sql_mode' => $create['sql_mode'],
                'character_set_client' => $create['character_set_client'], 'collation_connection' => $create['collation_connection']];
        }
        $contenido = ['tablas' => $esquema, 'vistas' => $vistas, 'triggers' => $triggers];
        return ['base' => $base, 'version_servidor' => $db->server_info, 'objetos' => $objetos,
            'defaults' => $defaults, ...$contenido, 'sha256' => hash('sha256', Comun::json($contenido))];
    }

    private static function filasCodificadas(\mysqli $db, string $tabla, array $orden): \Generator
    {
        $order = $orden === [] ? '' : ' ORDER BY ' . implode(', ', array_map([Comun::class, 'identificador'], $orden));
        $res = $db->query('SELECT * FROM ' . Comun::identificador($tabla) . $order, MYSQLI_USE_RESULT);
        try {
            while ($row = $res->fetch_row()) {
                yield array_map(static fn ($v) => $v === null ? null : base64_encode((string) $v), $row);
            }
        } finally {
            $res->free();
        }
    }

    private static function linea(array $row): string
    {
        return json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function huellaTabla(\mysqli $db, string $nombre, array $meta, ?string $salida = null): array
    {
        $handle = $salida === null ? null : fopen($salida, 'xb');
        if ($salida !== null && $handle === false) {
            throw new \RuntimeException('No se pudo crear el archivo de filas del respaldo.');
        }
        $hashes = [];
        $count = 0;
        $streamHash = hash_init('sha256');
        try {
            foreach (self::filasCodificadas($db, $nombre, $meta['orden']) as $row) {
                $linea = self::linea($row);
                if ($handle !== null && fwrite($handle, $linea) !== strlen($linea)) {
                    throw new \RuntimeException('Respaldo incompleto al escribir filas.');
                }
                hash_update($streamHash, $linea);
                if ($meta['orden'] === []) {
                    $hashes[] = hash('sha256', $linea);
                }
                $count++;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        // Una tabla sin PK se compara como multiconjunto, incluidos duplicados.
        sort($hashes, SORT_STRING);
        return ['filas' => $count, 'sha256' => hash_final($streamHash),
            'contenido_sha256' => $meta['orden'] === [] ? hash('sha256', implode('', $hashes)) : null];
    }

    public static function crear(\mysqli $db, string $root, string $destino, array $origen): array
    {
        Comun::destinoPrivado($destino, $root);
        $esquema = self::inspeccionar($db);
        if (file_exists($destino) || !mkdir($destino, 0700)) {
            throw new \RuntimeException('El respaldo requiere un directorio nuevo.');
        }
        if (!mkdir($destino . '/datos', 0700) || !mkdir($destino . '/entorno', 0700)) {
            throw new \RuntimeException('No se pudieron crear las carpetas del respaldo.');
        }
        Comun::escribirJson($destino . '/esquema.json', $esquema);
        $tablas = [];
        foreach ($esquema['tablas'] as $nombre => $meta) {
            $tablas[$nombre] = self::huellaTabla($db, $nombre, $meta, $destino . '/datos/' . $nombre . '.jsonl');
        }
        $vistas = [];
        foreach ($esquema['vistas'] as $nombre => $_) {
            $vistas[$nombre] = self::huellaTabla($db, $nombre, ['orden' => []]);
        }
        $archivos = self::copiarEntorno($root, $destino . '/entorno');
        $manifest = ['formato' => 'casa-pestalozzi.respaldo', 'version' => 1, 'origen' => $origen,
            'esquema_sha256' => hash_file('sha256', $destino . '/esquema.json'),
            'tablas' => $tablas, 'vistas' => $vistas, 'archivos' => $archivos,
            'exclusiones' => ['.git', 'vendor', 'node_modules', 'tmp', 'storage/sessions', 'n8n runtime']];
        Comun::escribirJson($destino . '/manifiesto-respaldo.json', $manifest);
        return $manifest;
    }

    private static function copiarEntorno(string $root, string $destino): array
    {
        // Código local, configuración privada y activos; dependencias se reinstalan con locks.
        $rutas = [];
        $dirs = ['classes', 'controllers', 'models', 'services', 'includes', 'views', 'src', 'public', 'assets', 'database', 'scripts', 'docs'];
        foreach ($dirs as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if ($file->isLink()) {
                    throw new \RuntimeException('El entorno contiene enlaces; revisar antes de respaldar.');
                }
                if ($file->isFile()) {
                    $rutas[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        foreach (new \DirectoryIterator($root) as $file) {
            if (!$file->isDot() && $file->isFile() && !$file->isLink()) {
                $rutas[] = $file->getFilename();
            }
        }
        sort($rutas, SORT_STRING);
        $manifest = [];
        foreach (array_unique($rutas) as $ruta) {
            $archivo = Comun::rutaArchivo($root, $ruta);
            $hash = hash_file('sha256', $archivo);
            $copia = $destino . '/' . $ruta;
            if (!is_dir(dirname($copia))) {
                if (!mkdir(dirname($copia), 0700, true)) {
                    throw new \RuntimeException('No se pudo crear la carpeta de un archivo de entorno.');
                }
            }
            if (!copy($archivo, $copia) || hash_file('sha256', $copia) !== $hash || hash_file('sha256', $archivo) !== $hash) {
                throw new \RuntimeException('Un archivo cambió o no pudo respaldarse: ' . $ruta);
            }
            $manifest[] = ['ruta' => $ruta, 'sha256' => $hash, 'bytes' => filesize($copia)];
        }
        return $manifest;
    }

    private static function ddlDestino(string $sql, string $origen, string $destino): string
    {
        // El respaldo conserva el DDL original. Solo el ensayo cambia referencias al clon.
        $sql = str_replace(Comun::identificador($origen) . '.', Comun::identificador($destino) . '.', $sql);
        return preg_replace('/DEFINER\s*=\s*`(?:[^`]|``)+`@`(?:[^`]|``)+`\s*/i', '', $sql);
    }

    public static function verificar(string $respaldo): array
    {
        $m = Comun::leerJson(Comun::rutaArchivo($respaldo, 'manifiesto-respaldo.json'));
        if (($m['formato'] ?? '') !== 'casa-pestalozzi.respaldo' || ($m['version'] ?? 0) !== 1
            || hash_file('sha256', Comun::rutaArchivo($respaldo, 'esquema.json')) !== $m['esquema_sha256']) {
            throw new \RuntimeException('Esquema de respaldo o hash inválido.');
        }
        $e = Comun::leerJson($respaldo . '/esquema.json');
        $contenido = ['tablas' => $e['tablas'], 'vistas' => $e['vistas'], 'triggers' => $e['triggers']];
        if (hash('sha256', Comun::json($contenido)) !== $e['sha256']) {
            throw new \RuntimeException('Huella interna de esquema incorrecta.');
        }
        if (array_keys($e['tablas']) !== array_keys($m['tablas'])) {
            throw new \RuntimeException('Respaldo con tablas faltantes.');
        }
        foreach ($m['tablas'] as $nombre => $meta) {
            Comun::identificador($nombre);
            $ruta = Comun::rutaArchivo($respaldo, 'datos/' . $nombre . '.jsonl');
            if (hash_file('sha256', $ruta) !== $meta['sha256']) {
                throw new \RuntimeException('Hash de datos incorrecto en respaldo.');
            }
            $f = fopen($ruta, 'rb');
            $conteo = 0;
            $hashes = [];
            try {
                while (($linea = fgets($f)) !== false) {
                    $row = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($row) || !array_is_list($row) || count($row) !== count($e['tablas'][$nombre]['columnas'])) {
                        throw new \RuntimeException('Fila de respaldo con columnas inválidas.');
                    }
                    foreach ($row as $value) {
                        if ($value !== null && (!is_string($value) || base64_decode($value, true) === false || base64_encode(base64_decode($value, true)) !== $value)) {
                            throw new \RuntimeException('Valor de respaldo no es base64 canónico o NULL.');
                        }
                    }
                    $conteo++;
                    if ($e['tablas'][$nombre]['orden'] === []) { $hashes[] = hash('sha256', $linea); }
                }
            } finally { fclose($f); }
            sort($hashes, SORT_STRING);
            if ($conteo !== $meta['filas'] || ($e['tablas'][$nombre]['orden'] === [] && hash('sha256', implode('', $hashes)) !== $meta['contenido_sha256'])) {
                throw new \RuntimeException('Cantidad o multiconjunto de filas de respaldo incorrecto.');
            }
        }
        $paths = [];
        foreach ($m['archivos'] as $file) {
            if (isset($paths[$file['ruta']])) { throw new \RuntimeException('Archivo duplicado en manifiesto de respaldo.'); }
            $paths[$file['ruta']] = true;
            $ruta = Comun::rutaArchivo($respaldo . '/entorno', $file['ruta']);
            if (hash_file('sha256', $ruta) !== $file['sha256'] || filesize($ruta) !== $file['bytes']) {
                throw new \RuntimeException('Archivo de entorno ausente o hash incorrecto.');
            }
        }
        foreach (['entorno' => $paths, 'datos' => array_fill_keys(array_map(static fn ($n) => $n . '.jsonl', array_keys($m['tablas'])), true)] as $dir => $expected) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($respaldo . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) { throw new \RuntimeException('Enlace no permitido dentro del respaldo.'); }
                if ($file->isFile()) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($respaldo . '/' . $dir) + 1));
                    if (!isset($expected[$rel])) { throw new \RuntimeException('Archivo no declarado dentro del respaldo.'); }
                }
            }
        }
        return $m;
    }

    public static function compararOrigen(\mysqli $db, string $respaldo): void
    {
        $m = self::verificar($respaldo);
        $e = Comun::leerJson($respaldo . '/esquema.json');
        if (self::inspeccionar($db)['sha256'] !== $e['sha256']) {
            throw new \RuntimeException('El esquema origen cambió durante el respaldo.');
        }
        foreach ($e['tablas'] as $nombre => $meta) {
            $actual = self::huellaTabla($db, $nombre, $meta);
            $esperado = $m['tablas'][$nombre];
            $clave = $meta['orden'] === [] ? 'contenido_sha256' : 'sha256';
            if ($actual['filas'] !== $esperado['filas'] || $actual[$clave] !== $esperado[$clave]) {
                throw new \RuntimeException('Los datos origen cambiaron durante el respaldo. Repetir en una ventana sin escritores.');
            }
        }
    }

    public static function restaurarArchivos(string $respaldo, string $destino): array
    {
        $m = self::verificar($respaldo);
        if (file_exists($destino) || !mkdir($destino, 0700)) {
            throw new \RuntimeException('Ensayo de archivos requiere un destino nuevo.');
        }
        foreach ($m['archivos'] as $file) {
            $origen = Comun::rutaArchivo($respaldo . '/entorno', $file['ruta']);
            $copia = $destino . '/' . $file['ruta'];
            if (!is_dir(dirname($copia)) && !mkdir(dirname($copia), 0700, true)) {
                throw new \RuntimeException('No se pudo crear un directorio de restauración.');
            }
            if (!copy($origen, $copia) || hash_file('sha256', $copia) !== $file['sha256']) {
                throw new \RuntimeException('La restauración de archivos difiere del respaldo.');
            }
        }
        return ['ok' => true, 'archivos' => count($m['archivos']), 'destino' => realpath($destino)];
    }

    /** Restaura solo a una base aleatoria creada en esta llamada y compara todas las filas. */
    public static function ensayar(array $config, string $respaldo, array $menuEsperado): array
    {
        $m = self::verificar($respaldo);
        if ($m['origen']['host'] !== $config['DB_HOST'] || $m['origen']['base'] !== $config['DB_NAME']) {
            throw new \RuntimeException('Respaldo no corresponde al origen confirmado.');
        }
        $e = Comun::leerJson($respaldo . '/esquema.json');
        $db = Comun::conectar($config, false);
        $destino = 'cp_menu_restore_' . bin2hex(random_bytes(10));
        $token = bin2hex(random_bytes(20));
        $id = Comun::identificador($destino);
        $creada = false;
        $marcada = false;
        $reporte = ['ok' => false, 'destino' => $destino, 'host' => $config['DB_HOST'],
            'tablas' => 0, 'filas' => 0, 'vistas' => 0, 'triggers' => 0, 'menu_igual' => false, 'base_eliminada' => false];
        try {
            if ($destino === $config['DB_NAME'] || !preg_match('/^cp_menu_restore_[a-f0-9]{20}$/D', $destino)) {
                throw new \RuntimeException('Destino de restauración no permitido.');
            }
            $charset = Comun::identificador($e['defaults']['DEFAULT_CHARACTER_SET_NAME']);
            $collation = Comun::identificador($e['defaults']['DEFAULT_COLLATION_NAME']);
            $db->query("CREATE DATABASE {$id} CHARACTER SET {$charset} COLLATE {$collation}");
            $creada = true;
            $db->select_db($destino);
            $db->query('CREATE TABLE __cp_menu_restore_owner (token VARCHAR(40) NOT NULL) ENGINE=InnoDB');
            $stmt = $db->prepare('INSERT INTO __cp_menu_restore_owner (token) VALUES (?)');
            $stmt->execute([$token]);
            $stmt->close();
            $marcada = true;
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            foreach ($e['tablas'] as $nombre => $meta) {
                $table = Comun::identificador($nombre);
                // Las sentencias se limitan al CREATE de una tabla del destino actual.
                if (!str_starts_with($meta['create'], 'CREATE TABLE ' . $table . ' (')
                    || str_contains($meta['create'], '`' . $config['DB_NAME'] . '`.')
                    || $nombre === '__cp_menu_restore_owner') {
                    throw new \RuntimeException('DDL de respaldo no permitido en ensayo.');
                }
                $db->query($meta['create']);
            }
            $db->begin_transaction();
            foreach ($e['tablas'] as $nombre => $meta) {
                $columnas = [];
                $indices = [];
                foreach ($meta['columnas'] as $index => $col) {
                    if (!str_contains((string) $col['Extra'], 'GENERATED') || str_contains((string) $col['Extra'], 'DEFAULT_GENERATED')) {
                        $columnas[] = Comun::identificador($col['Field']);
                        $indices[] = $index;
                    }
                }
                $sql = 'INSERT INTO ' . Comun::identificador($nombre) . ' (' . implode(',', $columnas) . ') VALUES (' . implode(',', array_fill(0, count($columnas), '?')) . ')';
                $stmt = $db->prepare($sql);
                $f = fopen(Comun::rutaArchivo($respaldo, 'datos/' . $nombre . '.jsonl'), 'rb');
                try {
                    while (($linea = fgets($f)) !== false) {
                        $row = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                        $valores = [];
                        foreach ($indices as $index) {
                            $valor = $row[$index];
                            $decodificado = $valor === null ? null : base64_decode($valor, true);
                            if ($decodificado === false) {
                                throw new \RuntimeException('Valor binario inválido en respaldo.');
                            }
                            $valores[] = $decodificado;
                        }
                        $stmt->execute($valores);
                    }
                } finally {
                    fclose($f);
                    $stmt->close();
                }
            }
            $db->commit();
            // Los triggers se crean después de insertar las filas: no ejecutar automatismos durante el ensayo.
            foreach ($e['triggers'] as $nombre => $meta) {
                self::configurarObjeto($db, $meta);
                $stmt = $db->prepare('SET SESSION sql_mode = ?');
                $stmt->execute([$meta['sql_mode']]);
                $stmt->close();
                $db->query(self::ddlDestino($meta['create'], $config['DB_NAME'], $destino));
                $reporte['triggers']++;
            }
            foreach ($e['vistas'] as $nombre => $meta) {
                self::configurarObjeto($db, $meta);
                $db->query(self::ddlDestino($meta['create'], $config['DB_NAME'], $destino));
                $reporte['vistas']++;
            }
            $db->set_charset('utf8mb4');
            foreach ($e['vistas'] as $nombre => $_) {
                $actual = self::huellaTabla($db, $nombre, ['orden' => []]);
                if ($actual['filas'] !== $m['vistas'][$nombre]['filas'] || $actual['contenido_sha256'] !== $m['vistas'][$nombre]['contenido_sha256']) {
                    throw new \RuntimeException('La vista restaurada no reproduce los datos del origen.');
                }
            }
            $restaurado = self::inspeccionar($db);
            unset($restaurado['tablas']['__cp_menu_restore_owner']);
            foreach (['tablas', 'vistas', 'triggers'] as $tipo) {
                if (array_keys($restaurado[$tipo]) !== array_keys($e[$tipo])) {
                    throw new \RuntimeException('Objetos de esquema restaurado incompletos.');
                }
                foreach ($e[$tipo] as $nombre => $meta) {
                    $esperado = self::ddlDestino($meta['create'], $config['DB_NAME'], '__schema__');
                    $actual = self::ddlDestino($restaurado[$tipo][$nombre]['create'], $destino, '__schema__');
                    if ($esperado !== $actual) {
                        throw new \RuntimeException('DDL restaurado difiere en ' . $nombre);
                    }
                }
            }
            foreach ($e['tablas'] as $nombre => $meta) {
                $actual = self::huellaTabla($db, $nombre, $meta);
                $esperado = $m['tablas'][$nombre];
                $clave = $meta['orden'] === [] ? 'contenido_sha256' : 'sha256';
                if ($actual['filas'] !== $esperado['filas'] || $actual[$clave] !== $esperado[$clave]) {
                    throw new \RuntimeException('Restauración difiere en tabla ' . $nombre);
                }
                $reporte['tablas']++;
                $reporte['filas'] += $actual['filas'];
            }
            self::validarReferencias($db, array_keys($e['tablas']));
            $diferencias = VerificadorMenu::comparar($menuEsperado, ExportadorMenu::capturar($db));
            if ($diferencias !== []) {
                throw new \RuntimeException('El menú restaurado difiere del paquete: ' . implode('; ', $diferencias));
            }
            $reporte['menu_igual'] = true;
            $reporte['ok'] = true;
        } catch (\Throwable $error) {
            $reporte['error'] = 'Ensayo de restauración fallido; código ' . $error->getCode() . '. ' . ($error instanceof \mysqli_sql_exception ? 'Revisar esquema y compatibilidad del servidor.' : $error->getMessage());
            try { $db->rollback(); } catch (\Throwable) {}
        } finally {
            // Nunca se acepta un nombre del usuario para DROP: solo creado aquí + token verificado.
            if ($creada && $marcada && $destino !== $config['DB_NAME']
                && (string) $db->query('SELECT DATABASE()')->fetch_row()[0] === $destino) {
                $guardado = $db->query('SELECT token FROM __cp_menu_restore_owner')->fetch_row()[0] ?? '';
                if (hash_equals($token, (string) $guardado)) {
                    $db->query("DROP DATABASE {$id}");
                    $reporte['base_eliminada'] = true;
                }
            }
            $db->close();
        }
        return $reporte;
    }

    private static function validarReferencias(\mysqli $db, array $tablas): void
    {
        // FOREIGN_KEY_CHECKS=1 no revalida filas importadas: comprobarlas explícitamente.
        $base = $db->real_escape_string((string) $db->query('SELECT DATABASE()')->fetch_row()[0]);
        $refs = Comun::filas($db, "SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{$base}' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION");
        $grupos = [];
        foreach ($refs as $ref) {
            $grupos[$ref['TABLE_NAME'] . ':' . $ref['CONSTRAINT_NAME']][] = $ref;
        }
        foreach ($grupos as $grupo) {
            $primero = $grupo[0];
            if (!in_array($primero['REFERENCED_TABLE_NAME'], $tablas, true)) {
                throw new \RuntimeException('Referencia a tabla externa al respaldo.');
            }
            $join = $notNull = [];
            foreach ($grupo as $ref) {
                $col = Comun::identificador($ref['COLUMN_NAME']);
                $rcol = Comun::identificador($ref['REFERENCED_COLUMN_NAME']);
                $join[] = "c.{$col}=p.{$rcol}";
                $notNull[] = "c.{$col} IS NOT NULL";
            }
            $sql = 'SELECT COUNT(*) FROM ' . Comun::identificador($primero['TABLE_NAME']) . ' c WHERE ' . implode(' AND ', $notNull)
                . ' AND NOT EXISTS (SELECT 1 FROM ' . Comun::identificador($primero['REFERENCED_TABLE_NAME']) . ' p WHERE ' . implode(' AND ', $join) . ')';
            if ((int) $db->query($sql)->fetch_row()[0] !== 0) {
                throw new \RuntimeException('Hay referencias huérfanas en el estado restaurado.');
            }
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function configurarObjeto(\mysqli $db, array $meta): void
    {
        $charset = Comun::identificador($meta['character_set_client']);
        $collation = Comun::identificador($meta['collation_connection']);
        $db->query("SET NAMES {$charset} COLLATE {$collation}");
    }
}
