<?php

namespace Services;

final class FechaOperacionLock
{
    private const PREFIX = 'casa_pestalozzi_fecha_';

    public static function adquirir(\mysqli $db, string $fecha, int $timeoutSeconds = 5): bool
    {
        $nombre = self::nombre($fecha);
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
        $nombre = self::nombre($fecha);
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $stmt->close();
    }

    private static function nombre(string $fecha): string
    {
        return self::PREFIX . substr(hash('sha256', trim($fecha)), 0, 32);
    }
}
