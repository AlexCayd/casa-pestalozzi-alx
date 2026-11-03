<?php
declare(strict_types=1);

namespace Services\Menu;

use Model\ActiveRecord;

/** Materializa la proyección comercial efectiva sin administrar transacciones. */
final class DatosComercialesOferta
{
    private const UPSERT = 'INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),descripcion=VALUES(descripcion),img=VALUES(img)';

    /** Sincroniza una oferta que ya existe dentro de la transacción del escritor. */
    public static function sincronizarOferta(int $ofertaId): void
    {
        if ($ofertaId < 1) {
            throw new \InvalidArgumentException('El identificador de oferta no es válido.');
        }

        $db = self::db();
        $stmt = $db->prepare('SELECT oferta_id,nombre,descripcion,img FROM productos_oferta WHERE oferta_id=? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }

        try {
            if (!$stmt->execute([$ofertaId])) {
                throw new \RuntimeException($stmt->error);
            }
            $datos = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }

        if (!$datos) {
            throw new \RuntimeException("La oferta {$ofertaId} no tiene una proyección en productos_oferta.");
        }

        self::guardar($db, $datos);
    }

    /** Sincroniza todas las ofertas de una identidad compartida. */
    public static function sincronizarProducto(int $productoId): int
    {
        if ($productoId < 1) {
            throw new \InvalidArgumentException('El identificador de producto no es válido.');
        }

        $db = self::db();
        $consulta = $db->prepare('SELECT oferta_id,nombre,descripcion,img FROM productos_oferta WHERE id=? ORDER BY oferta_id');
        if (!$consulta) {
            throw new \RuntimeException($db->error);
        }
        try {
            if (!$consulta->execute([$productoId])) {
                throw new \RuntimeException($consulta->error);
            }
            $ofertas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $consulta->close();
        }

        foreach ($ofertas as $oferta) {
            self::guardar($db, $oferta);
        }

        return count($ofertas);
    }

    private static function guardar(\mysqli $db, array $datos): void
    {
        $stmt = $db->prepare(self::UPSERT);
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }
        try {
            // mysqli conserva NULL como NULL al ejecutar parámetros con arreglo.
            if (!$stmt->execute([
                (int) $datos['oferta_id'],
                $datos['nombre'],
                $datos['descripcion'],
                $datos['img'],
            ])) {
                throw new \RuntimeException($stmt->error);
            }
        } finally {
            $stmt->close();
        }
    }

    private static function db(): \mysqli
    {
        $db = ActiveRecord::getDB();
        if (!$db instanceof \mysqli) {
            throw new \RuntimeException('No hay conexión disponible para sincronizar los datos comerciales.');
        }
        return $db;
    }
}
