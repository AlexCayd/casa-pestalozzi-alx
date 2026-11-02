<?php
declare(strict_types=1);
namespace Services\Security;

/** Límite compartido por IP, independiente de cookies y serializado entre procesos. */
final class IntentosAcceso
{
    public static function admitir(string $canal, string $ip, ?string $directorio = null, ?int $ahora = null): bool
    {
        if (!in_array($canal, ['nip', 'contrasena'], true)) return false;
        $directorio ??= sys_get_temp_dir();
        $ahora ??= time();
        $clave = hash('sha256', dirname(__DIR__, 2) . '|' . $canal . '|' . $ip);
        $archivo = @fopen($directorio . DIRECTORY_SEPARATOR . 'cp-acceso-' . $clave . '.json', 'c+');
        if (!$archivo) return false;
        try {
            if (!flock($archivo, LOCK_EX)) return false;
            $estado = json_decode((string)stream_get_contents($archivo), true);
            if (!is_array($estado) || $ahora - (int)($estado['inicio'] ?? 0) >= 60 || $ahora < (int)($estado['inicio'] ?? 0)) {
                $estado = ['inicio' => $ahora, 'intentos' => 0];
            }
            // Incluye intentos válidos para evitar carreras entre comprobar y registrar fallo.
            $limite = $canal === 'nip' ? 20 : 30;
            $permitido = (int)($estado['intentos'] ?? 0) < $limite;
            if ($permitido) $estado['intentos'] = (int)($estado['intentos'] ?? 0) + 1;
            rewind($archivo);
            if (!ftruncate($archivo, 0) || fwrite($archivo, json_encode($estado)) === false || !fflush($archivo)) return false;
            return $permitido;
        } finally {
            flock($archivo, LOCK_UN);
            fclose($archivo);
        }
    }
}
