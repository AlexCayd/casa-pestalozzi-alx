<?php

namespace Services;

/**
 * Serializa las mutaciones públicas de una identidad canónica.
 *
 * El lock asesor vive en la misma conexión mysqli que la transacción. Así,
 * dos pestañas no pueden observar simultáneamente cuatro activas y crear dos
 * quintas reservaciones.
 */
final class ContactoOperacionLock
{
    private const PREFIX = 'casa_pestalozzi_contacto_';

    public static function adquirir(
        \mysqli $db,
        string $tipo,
        string $contactoNormalizado,
        int $timeoutSeconds = 5
    ): bool {
        $nombre = self::nombre($tipo, $contactoNormalizado);
        $timeoutSeconds = max(0, min(30, $timeoutSeconds));
        $stmt = $db->prepare('SELECT GET_LOCK(?, ?) AS adquirido');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el lock de contacto.');
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

    public static function liberar(\mysqli $db, string $tipo, string $contactoNormalizado): void
    {
        $nombre = self::nombre($tipo, $contactoNormalizado);
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $stmt->close();
    }

    private static function nombre(string $tipo, string $contactoNormalizado): string
    {
        $identidad = trim($tipo) . ':' . trim($contactoNormalizado);

        return self::PREFIX . substr(hash('sha256', $identidad), 0, 32);
    }
}
