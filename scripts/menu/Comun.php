<?php
declare(strict_types=1);

namespace MenuPreservacion;

final class Comun
{
    public static function json(mixed $valor): string
    {
        return json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function escribirJson(string $ruta, mixed $valor): void
    {
        self::escribir($ruta, self::json($valor));
    }

    public static function escribir(string $ruta, string $contenido): void
    {
        if (file_exists($ruta) || is_link($ruta)) {
            throw new \RuntimeException('La salida ya existe; no se sobrescribe.');
        }
        if (file_put_contents($ruta, $contenido, LOCK_EX) !== strlen($contenido)) {
            throw new \RuntimeException('No se pudo escribir el archivo completo.');
        }
    }

    public static function leerJson(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new \RuntimeException('Archivo JSON no disponible.');
        }
        $valor = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($valor)) {
            throw new \RuntimeException('Se esperaba un objeto JSON.');
        }
        return $valor;
    }

    public static function filas(\mysqli $db, string $sql): array
    {
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public static function identificador(string $nombre): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $nombre)) {
            throw new \RuntimeException('Identificador SQL no admitido.');
        }
        return '`' . $nombre . '`';
    }

    public static function rutaArchivo(string $raiz, string $relativa): string
    {
        if ($relativa === '' || str_contains($relativa, '\\') || str_contains($relativa, ':')
            || str_starts_with($relativa, '/') || str_contains($relativa, "\0")) {
            throw new \RuntimeException('Ruta de archivo no admitida.');
        }
        $segmentos = explode('/', $relativa);
        if (array_intersect($segmentos, ['.', '..', '']) !== []) {
            throw new \RuntimeException('Ruta con segmentos inseguros.');
        }
        $base = realpath($raiz);
        if ($base === false) {
            throw new \RuntimeException('Raíz de archivos inexistente.');
        }
        $camino = $base;
        foreach ($segmentos as $segmento) {
            $camino .= DIRECTORY_SEPARATOR . $segmento;
            if (is_link($camino)) {
                throw new \RuntimeException('No se admiten enlaces en archivos preservados.');
            }
        }
        $real = realpath($camino);
        $prefijo = rtrim(str_replace('\\', '/', $base), '/') . '/';
        if ($real === false || !is_file($real)
            || !str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower($prefijo))) {
            throw new \RuntimeException('Archivo ausente o fuera de la raíz autorizada.');
        }
        return $real;
    }

    public static function conectar(array $config, bool $seleccionar = true): \mysqli
    {
        $db = new \mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], $seleccionar ? $config['DB_NAME'] : '', (int) ($config['DB_PORT'] ?? 3306));
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '+00:00'");
        return $db;
    }

    public static function origen(array $config, array $opciones, string $root): array
    {
        if (!isset($opciones['host'], $opciones['base'], $opciones['entorno'])
            || $opciones['host'] !== ($config['DB_HOST'] ?? null)
            || $opciones['base'] !== ($config['DB_NAME'] ?? null)
            || !in_array($opciones['entorno'], ['development', 'test'], true)
            || ($config['APP_ENV'] ?? '') !== $opciones['entorno']) {
            throw new \RuntimeException('Origen no confirmado: host, base y entorno deben coincidir explícitamente con la configuración development/test.');
        }
        self::identificador($opciones['base']);
        $revision = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
        if (!preg_match('/^[a-f0-9]{40}$/D', $revision)) {
            throw new \RuntimeException('No se pudo identificar la revisión de código.');
        }
        return ['host' => $opciones['host'], 'puerto' => (int) ($config['DB_PORT'] ?? 3306),
            'base' => $opciones['base'], 'entorno' => $opciones['entorno'],
            'revision' => $revision, 'fecha_utc' => gmdate('c')];
    }

    public static function destinoPrivado(string $ruta, string $root): void
    {
        // XAMPP puede servir htdocs entero: no basta quedar fuera de public/.
        $servido = rtrim(strtolower(str_replace('\\', '/', dirname(realpath($root)))), '/') . '/';
        $padre = realpath(dirname($ruta));
        if ($padre === false || str_starts_with(rtrim(strtolower(str_replace('\\', '/', $padre)), '/') . '/', $servido)) {
            throw new \RuntimeException('El respaldo privado debe quedar fuera del repositorio y de su raíz web contenedora.');
        }
    }
}
