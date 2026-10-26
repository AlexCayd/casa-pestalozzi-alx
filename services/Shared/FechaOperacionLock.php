<?php

namespace Services\Shared;

final class FechaOperacionLock
{
    private const PREFIX = 'casa_pestalozzi_fecha_';

    public static function adquirir(\mysqli $db, string $fecha, int $timeoutSeconds = 5): bool
    {
        $nombre = self::nombre($db,$fecha);
        $timeoutSeconds = max(0, min(30, $timeoutSeconds));
        $stmt = $db->prepare('SELECT GET_LOCK(?, ?) AS adquirido');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el lock de fecha.');
        }

        $stmt->bind_param('si', $nombre, $timeoutSeconds);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return (int)($fila['adquirido'] ?? 0) === 1;
    }

    public static function liberar(\mysqli $db, string $fecha): void
    {
        $nombre = self::nombre($db,$fecha);
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $stmt->close();
    }

    private static function nombre(\mysqli $db,string $fecha): string
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            $base=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
            return self::PREFIX.substr(hash('sha256',$base.'|restaurante|'.trim($fecha)),0,32);
        }
        return self::PREFIX . substr(hash('sha256', trim($fecha)), 0, 32);
    }
}
