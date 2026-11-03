<?php
declare(strict_types=1);

namespace Services\Menu;

use Model\ActiveRecord;

/** Fuente y escrituras de los atributos comerciales asignados a cada oferta. */
final class DatosComercialesOferta
{
    private const UPSERT = 'INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),descripcion=VALUES(descripcion),img=VALUES(img)';

    /** El marcador se inserta al final de la migración que cambia la vista. */
    public static function a3Activa(): bool
    {
        $db = self::db();
        $stmt = $db->prepare("SELECT 1 FROM esquema_versiones WHERE version='a3-datos-comerciales-local-v1' LIMIT 1");
        if (!$stmt) throw new \RuntimeException($db->error);
        try {
            if (!$stmt->execute()) throw new \RuntimeException($stmt->error);
            return (bool) $stmt->get_result()->fetch_row();
        } finally {
            $stmt->close();
        }
    }

    /** Crea la fila local de una oferta nueva desde la identidad técnica. No reemplaza una fila existente. */
    public static function crearDesdeIdentidad(int $ofertaId, int $productoId): void
    {
        if ($ofertaId < 1 || $productoId < 1) throw new \InvalidArgumentException('La oferta o producto no es válido.');
        $db = self::db();
        $stmt = $db->prepare('INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img)
            SELECT o.id,p.nombre,p.descripcion,p.img FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
            WHERE o.id=? AND o.producto_id=? ON DUPLICATE KEY UPDATE oferta_id=oferta_datos_comerciales.oferta_id');
        if (!$stmt) throw new \RuntimeException($db->error);
        try {
            if (!$stmt->execute([$ofertaId, $productoId])) throw new \RuntimeException($stmt->error);
        } finally {
            $stmt->close();
        }
        $verificar=$db->prepare('SELECT d.nombre,d.descripcion,d.img,p.nombre nombre_tecnico,p.descripcion descripcion_tecnica,p.img img_tecnica
            FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE o.id=? AND o.producto_id=?');
        if(!$verificar)throw new \RuntimeException($db->error);
        try {
            if(!$verificar->execute([$ofertaId,$productoId]))throw new \RuntimeException($verificar->error);
            $fila=$verificar->get_result()->fetch_assoc();
            if(!$fila||$fila['nombre']!==$fila['nombre_tecnico']||$fila['descripcion']!==$fila['descripcion_tecnica']||$fila['img']!==$fila['img_tecnica'])
                throw new \RuntimeException("No se pudo validar la oferta {$ofertaId} desde su identidad técnica.");
        }
        finally { $verificar->close(); }
    }

    /** Guarda la proyección local completa y comprueba exactamente lo persistido, incluidos los NULL. */
    public static function guardarLocal(int $ofertaId, string $nombre, ?string $descripcion, ?string $img): void
    {
        if ($ofertaId < 1 || trim($nombre) === '' || mb_strlen($nombre) > 120) {
            throw new \InvalidArgumentException('Los datos comerciales locales no son válidos.');
        }
        $db = self::db();
        self::guardar($db, ['oferta_id' => $ofertaId, 'nombre' => $nombre, 'descripcion' => $descripcion, 'img' => $img]);
        self::validarPersistido($ofertaId, $nombre, $descripcion, $img, true);
    }

    /**
     * Compatibilidad A2 para código preparado antes del corte A3.
     * Calcula el antiguo valor efectivo con sus tablas originales; nunca lee productos_oferta.
     */
    public static function sincronizarOfertaLegacy(int $ofertaId): void
    {
        self::exigirModoLegacy();
        if ($ofertaId < 1) throw new \InvalidArgumentException('El identificador de oferta no es válido.');
        $stmt = self::db()->prepare(self::proyeccionLegacy() . ' WHERE o.id=? LIMIT 1');
        if (!$stmt) throw new \RuntimeException(self::db()->error);
        try {
            if (!$stmt->execute([$ofertaId])) throw new \RuntimeException($stmt->error);
            $datos = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if (!$datos) throw new \RuntimeException("La oferta {$ofertaId} no tiene una proyección heredada.");
        self::guardar(self::db(), $datos);
        self::validarPersistido($ofertaId, $datos['nombre'], $datos['descripcion'], $datos['img'], true);
    }

    /** Compatibilidad A2: conserva la proyección anterior para cada oferta de la identidad. */
    public static function sincronizarProductoLegacy(int $productoId): int
    {
        self::exigirModoLegacy();
        if ($productoId < 1) throw new \InvalidArgumentException('El identificador de producto no es válido.');
        $stmt = self::db()->prepare(self::proyeccionLegacy() . ' WHERE p.id=? ORDER BY o.id');
        if (!$stmt) throw new \RuntimeException(self::db()->error);
        try {
            if (!$stmt->execute([$productoId])) throw new \RuntimeException($stmt->error);
            $ofertas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }
        foreach ($ofertas as $oferta) {
            self::guardar(self::db(), $oferta);
            self::validarPersistido((int) $oferta['oferta_id'], $oferta['nombre'], $oferta['descripcion'], $oferta['img'], true);
        }
        return count($ofertas);
    }

    private static function proyeccionLegacy(): string
    {
        return "SELECT o.id AS oferta_id,
            CASE WHEN pn.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.nombre'))='true' THEN JSON_UNQUOTE(pn.valor) ELSE p.nombre END AS nombre,
            CASE WHEN pd.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.descripcion'))='true' THEN JSON_UNQUOTE(pd.valor) ELSE p.descripcion END AS descripcion,
            CASE WHEN pi.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.img'))='true' THEN CASE WHEN JSON_TYPE(pi.valor)='NULL' THEN NULL ELSE JSON_UNQUOTE(pi.valor) END ELSE p.img END AS img
            FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
            LEFT JOIN producto_estandares pe ON pe.producto_id=p.id
            LEFT JOIN producto_publicaciones pp ON pp.id=pe.publicacion_id
            LEFT JOIN oferta_personalizaciones pn ON pn.oferta_id=o.id AND pn.campo='nombre'
            LEFT JOIN oferta_personalizaciones pd ON pd.oferta_id=o.id AND pd.campo='descripcion'
            LEFT JOIN oferta_personalizaciones pi ON pi.oferta_id=o.id AND pi.campo='img'";
    }

    private static function guardar(\mysqli $db, array $datos): void
    {
        $stmt = $db->prepare(self::UPSERT);
        if (!$stmt) throw new \RuntimeException($db->error);
        try {
            if (!$stmt->execute([(int) $datos['oferta_id'], $datos['nombre'], $datos['descripcion'], $datos['img']])) {
                throw new \RuntimeException($stmt->error);
            }
        } finally {
            $stmt->close();
        }
    }

    private static function validarPersistido(int $ofertaId, ?string $nombre, ?string $descripcion, ?string $img, bool $comparar): void
    {
        $stmt = self::db()->prepare('SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id=?');
        if (!$stmt) throw new \RuntimeException(self::db()->error);
        try {
            if (!$stmt->execute([$ofertaId])) throw new \RuntimeException($stmt->error);
            $fila = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if (!$fila) throw new \RuntimeException("La oferta {$ofertaId} no tiene datos comerciales persistidos.");
        if ($comparar && ($fila['nombre'] !== $nombre || $fila['descripcion'] !== $descripcion || $fila['img'] !== $img)) {
            throw new \RuntimeException("La validación de datos comerciales falló para la oferta {$ofertaId}.");
        }
    }

    private static function exigirModoLegacy(): void
    {
        if (self::a3Activa()) throw new \LogicException('La sincronización heredada está deshabilitada después del corte A3.');
    }

    private static function db(): \mysqli
    {
        $db = ActiveRecord::getDB();
        if (!$db instanceof \mysqli) throw new \RuntimeException('No hay conexión disponible para los datos comerciales.');
        return $db;
    }
}
