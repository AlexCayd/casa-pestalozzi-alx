<?php
namespace Services\Menu;

use Model\ActiveRecord;
use Services\Security\ContextoNegocio;

/** Lecturas acotadas de ofertas por negocio. Las altas y ediciones viven en Producto. */
final class Ofertas
{
    /** Regresa el DTO consumido por el menú, solo cuando oferta y unidad coinciden. */
    public static function obtener(int $ofertaId,string $ctx): ?array
    {
        return ContextoNegocio::en($ctx,static function () use ($ofertaId,$ctx): ?array {
            ContextoNegocio::exigir('menu','ver',$ctx); $unidad=ContextoNegocio::vendedora($ctx);
            $stmt=ActiveRecord::getDB()->prepare('SELECT o.id,o.id AS oferta_id,o.producto_id,o.unidad_id,p.nombre,p.descripcion,p.img,o.categoria_id,o.precio,o.area_id,o.activo,o.orden FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id WHERE o.id=? AND o.unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException(ActiveRecord::getDB()->error);
            $stmt->bind_param('ii',$ofertaId,$unidad); self::ejecutar($stmt); $row=$stmt->get_result()->fetch_assoc()?:null; $stmt->close();
            if (!$row) return null;
            foreach (['id','oferta_id','producto_id','unidad_id','categoria_id','area_id','activo','orden'] as $key) $row[$key]=(int)$row[$key];
            $row['precio']=(float)$row['precio']; return $row;
        });
    }

    public static function validarLocal(\mysqli $db,int $categoria,int $area,int $unidad): void
    {
        $stmt=$db->prepare('SELECT (SELECT COUNT(*) FROM categorias WHERE id=? AND unidad_id=?),(SELECT COUNT(*) FROM areas_produccion WHERE id=? AND unidad_id=?)');
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('iiii',$categoria,$unidad,$area,$unidad); self::ejecutar($stmt); $row=$stmt->get_result()->fetch_row(); $stmt->close();
        if (!$row || (int)$row[0]!==1 || (int)$row[1]!==1) throw new \RuntimeException('La categoría o área no pertenece al negocio de la oferta.');
    }
}
