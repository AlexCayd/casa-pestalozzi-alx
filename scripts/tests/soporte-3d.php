<?php
declare(strict_types=1);

/** Helpers exclusivos de los ensayos desechables de la entrega Restaurante 3D. */
if (PHP_SAPI !== 'cli') { exit(1); }

use Multinegocio\SqlArchivo;

function restaurante3dOpciones(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if (!preg_match('/^--(host|base|entorno|paquete)$/D', $args[$i], $match)
            || !isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--') || isset($options[$match[1]])) {
            throw new RuntimeException('Uso: --host 127.0.0.1 --base casa-pestalozzi --entorno development|test --paquete ABS');
        }
        $options[$match[1]] = $args[++$i];
    }
    foreach (['host', 'base', 'entorno', 'paquete'] as $key) {
        if (!isset($options[$key]) || $options[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    if ($options['host'] !== '127.0.0.1' || $options['base'] !== 'casa-pestalozzi'
        || !in_array($options['entorno'], ['development', 'test'], true)) {
        throw new RuntimeException('3D solo acepta --host 127.0.0.1 --base casa-pestalozzi y --entorno development|test.');
    }
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $options['paquete'])) {
        throw new RuntimeException('--paquete debe ser una ruta absoluta.');
    }
    return $options;
}

function restaurante3dAssert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function restaurante3dDenegado(callable $accion, string $message, int &$count): void
{
    try {
        $result = $accion();
    } catch (Throwable) {
        $count++;
        return;
    }
    restaurante3dAssert($result === false || (is_array($result) && ($result['ok'] ?? true) === false), $message, $count);
}

function restaurante3dIdent(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Identificador SQL inesperado.');
    return '`' . $name . '`';
}

/** Conecta al servidor sin seleccionar DB_NAME; nunca lee/usa la base configurada. */
function restaurante3dCrearBase(mysqli $admin, string $root, array &$bases): array
{
    $name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name) || strlen($token) !== 64) {
        throw new RuntimeException('Identidad aleatoria de base temporal inválida.');
    }
    $admin->query('CREATE DATABASE ' . restaurante3dIdent($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bases[] = [$name, $token];
    $admin->select_db($name);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
    SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');
    return [$name, $token];
}

function restaurante3dBasePropia(mysqli $admin, string $name, string $token, string $origin): bool
{
    if ($name === $origin || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) return false;
    if (!$admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($name) . "'")->fetch_row()) return false;
    $admin->select_db($name);
    try {
        $rows = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) { return false; }
}

function restaurante3dCrearRaizImagenes(): array
{
    $parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'casa-pestalozzi-3d';
    if (!is_dir($parent) && !mkdir($parent, 0700, true)) throw new RuntimeException('No se pudo preparar el padre temporal de imágenes.');
    if (is_link($parent) || !is_dir($parent)) throw new RuntimeException('Padre temporal de imágenes inseguro.');
    $root = $parent . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
    if (file_exists($root) || is_link($root)) throw new RuntimeException('La ruta temporal aleatoria ya existe.');
    return [$root, $parent];
}

function restaurante3dLimpiarRaizImagenes(string $root, string $parent): void
{
    if (!file_exists($root) && !is_link($root)) return;
    $realParent = realpath($parent);
    $real = realpath($root);
    if ($realParent === false || $real === false || is_link($root) || dirname($real) !== $realParent
        || !preg_match('/^[a-f0-9]{24}$/D', basename($real))) {
        throw new RuntimeException('La raíz temporal no pasó la guarda de limpieza.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink()) throw new RuntimeException('No se eliminan enlaces inesperados en la raíz temporal.');
        if ($entry->isDir()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($real);
}

/** Reserva un .webp con nombre aleatorio propio dentro del workspace para probar la guarda de imágenes compartidas. */
function restaurante3dCrearImagenPropia(string $workspace): array
{
    $workspaceReal = realpath($workspace);
    $imagesReal = realpath(\Classes\ImagenUploader::directorioImagenes());
    $expectedImages = $workspaceReal === false ? false : realpath($workspaceReal . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'images');
    if ($workspaceReal === false || $imagesReal === false || $expectedImages === false || $imagesReal !== $expectedImages
        || !str_starts_with(strtolower($imagesReal . DIRECTORY_SEPARATOR), strtolower(rtrim($workspaceReal, '\\/') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('El directorio de imágenes no coincide con el workspace validado.');
    }
    $filename = bin2hex(random_bytes(16)) . '.webp';
    if (!preg_match('/^[a-f0-9]{32}\.webp$/D', $filename)) throw new RuntimeException('Nombre de fixture de imagen inválido.');
    $path = $imagesReal . DIRECTORY_SEPARATOR . $filename;
    $handle = @fopen($path, 'x+b');
    if ($handle === false) throw new RuntimeException('No se pudo reservar el archivo temporal de imagen sin sobrescribir.');
    try {
        if (fwrite($handle, 'synthetic-3d-shared-image-fixture') === false) throw new RuntimeException('No se pudo escribir el fixture sintético de imagen.');
    } finally { fclose($handle); }
    return ['path' => $path, 'filename' => $filename, 'images_root' => $imagesReal, 'public_path' => 'build/images/' . $filename];
}

function restaurante3dLimpiarImagenPropia(?string $path, ?string $filename, ?string $imagesRoot, string $workspace): void
{
    if ($path === null || $filename === null || $imagesRoot === null || !file_exists($path)) return;
    $workspaceReal = realpath($workspace);
    $imagesReal = realpath($imagesRoot);
    $fileReal = realpath($path);
    if ($workspaceReal === false || $imagesReal === false || $fileReal === false || is_link($path)
        || dirname($fileReal) !== $imagesReal || basename($fileReal) !== $filename
        || !preg_match('/^[a-f0-9]{32}\.webp$/D', $filename)
        || !str_starts_with(strtolower($imagesReal . DIRECTORY_SEPARATOR), strtolower(rtrim($workspaceReal, '\\/') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('El archivo temporal de imagen no pasó la guarda de limpieza.');
    }
    unlink($fileReal);
}

/** Crea una identidad sintética con permisos directos, sin roles implícitos. */
final class FixtureRestaurante3D
{
    public static function usuario(mysqli $db, string $alias, array $permisos, string $unidadSlug = 'restaurante', bool $activo = true): array
    {
        $username = 'qa3d_' . preg_replace('/[^a-z0-9_]/i', '_', $alias) . '_' . bin2hex(random_bytes(6));
        $name = 'Fixture sintético 3D ' . $alias;
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $isActive = $activo ? 1 : 0;
        $stmt = $db->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?,?,0)');
        $stmt->bind_param('sssi', $username, $name, $passwordHash, $isActive);
        $stmt->execute();
        $userId = (int)$db->insert_id;
        $stmt->close();

        $stmt = $db->prepare('SELECT id FROM unidades WHERE slug=?');
        $stmt->bind_param('s', $unidadSlug);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$unit) throw new RuntimeException("Falta la unidad estructural {$unidadSlug}.");
        $unitId = (int)$unit['id'];
        $stmt = $db->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1)');
        $stmt->bind_param('ii', $userId, $unitId);
        $stmt->execute();
        $stmt->close();

        $catalog = \Services\Security\PermisosUsuario::catalogo($unidadSlug);
        foreach ($permisos as $module => $level) {
            if (!isset($catalog[$module]) || !in_array($level, $catalog[$module]['niveles'], true)) {
                throw new RuntimeException("Nivel de fixture no válido para {$unidadSlug}/{$module}.");
            }
            $stmt = $db->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)');
            $stmt->bind_param('iiss', $userId, $unitId, $module, $level);
            $stmt->execute();
            $stmt->close();
        }
        return ['id' => $userId, 'username' => $username, 'unidad_id' => $unitId];
    }
}

function restaurante3dIniciarSesion(array $usuario): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = ['login' => true, 'id' => (int)$usuario['id'], 'auth_nivel' => 'contrasena', '_auth3b' => true];
}
