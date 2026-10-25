<?php
namespace Services\Menu;

use Model\ActiveRecord;
use Services\Security\ContextoNegocio;

/** Operaciones explícitas sobre ofertas por negocio; crear() nunca clona el producto maestro. */
final class Ofertas
{
    /** @return array{ok:bool,confirmado:bool,pendiente:bool,id?:int,oferta_id?:int,producto_id?:int,unidad_id?:int,codigo?:string} */
    public static function crear(int $productoId,string $ctx,array $datos): array
    {
        return ContextoNegocio::en($ctx,static function () use ($productoId,$ctx,$datos): array {
            ContextoNegocio::exigir('menu','editar',$ctx);
            $unidad=ContextoNegocio::vendedora($ctx); $db=ActiveRecord::getDB();
            $categoria=(int)($datos['categoria_id']??0); $area=(int)($datos['area_id']??0); $precio=$datos['precio']??null;
            $activo=isset($datos['activo'])?(int)(bool)$datos['activo']:1; $orden=(int)($datos['orden']??0);
            if ($productoId<1 || $categoria<1 || $area<1 || !is_numeric($precio) || (float)$precio<0) return ['ok'=>false,'confirmado'=>false,'pendiente'=>false,'codigo'=>'DATOS_INVALIDOS'];
            return ContextoNegocio::transaccion(static function () use ($db,$productoId,$unidad,$ctx,$categoria,$area,$precio,$activo,$orden): array {
                ContextoNegocio::exigir('menu','editar',$ctx,true);
                $stmt=$db->prepare('SELECT id FROM productos WHERE id=? LIMIT 1 FOR UPDATE');
                if (!$stmt) throw new \RuntimeException($db->error);
                $stmt->bind_param('i',$productoId); self::ejecutar($stmt); $existe=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
                if (!$existe) return ['ok'=>false,'confirmado'=>false,'pendiente'=>false,'codigo'=>'PRODUCTO_NO_EXISTE'];
                self::validarLocal($db,$categoria,$area,$unidad);
                $stmt=$db->prepare('SELECT id FROM ofertas_producto WHERE producto_id=? AND unidad_id=? LIMIT 1');
                if (!$stmt) throw new \RuntimeException($db->error);
                $stmt->bind_param('ii',$productoId,$unidad); self::ejecutar($stmt); $existente=$stmt->get_result()->fetch_row(); $stmt->close();
                if ($existente) return ['ok'=>false,'confirmado'=>false,'pendiente'=>false,'codigo'=>'OFERTA_YA_EXISTE','id'=>(int)$existente[0],'oferta_id'=>(int)$existente[0],'producto_id'=>$productoId,'unidad_id'=>$unidad];
                $precio=(float)$precio;
                $stmt=$db->prepare("INSERT INTO ofertas_producto (producto_id,unidad_id,unidad_tipo,categoria_id,precio,area_id,activo,orden) VALUES (?,?,'negocio',?,?,?,?,?)");
                if (!$stmt) throw new \RuntimeException($db->error);
                $stmt->bind_param('iiidiii',$productoId,$unidad,$categoria,$precio,$area,$activo,$orden); self::ejecutar($stmt); $id=(int)$db->insert_id; $stmt->close();
                return ['id'=>$id,'oferta_id'=>$id,'producto_id'=>$productoId,'unidad_id'=>$unidad];
            });
        });
    }

    /** Regresa el DTO consumido por el menú, solo cuando oferta y unidad coinciden. */
    public static function obtener(int $ofertaId,string $ctx): ?array
    {
        return ContextoNegocio::en($ctx,static function () use ($ofertaId,$ctx): ?array {
            ContextoNegocio::exigir('menu','ver',$ctx); $unidad=ContextoNegocio::vendedora($ctx);
            $stmt=ActiveRecord::getDB()->prepare('SELECT o.id,o.id AS oferta_id,o.producto_id,o.unidad_id,p.nombre,p.descripcion,p.img,o.categoria_id,o.precio,o.area_id,o.activo,o.orden FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id WHERE o.id=? AND o.unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException(ActiveRecord::getDB()->error);
            $stmt->bind_param('ii',$ofertaId,$unidad); self::ejecutar($stmt); $row=$stmt->get_result()->fetch_assoc()?:null; $stmt->close();
            if (!$row) return null;
            foreach (['id','oferta_id','producto_id','unidad_id','categoria_id','area_id','activo','orden'] as $key) $row[$key]=(int)$row[$key];
            $row['precio']=(float)$row['precio']; return $row;
        });
    }

    private static function validarLocal(\mysqli $db,int $categoria,int $area,int $unidad): void
    {
        $stmt=$db->prepare('SELECT (SELECT COUNT(*) FROM categorias WHERE id=? AND unidad_id=?),(SELECT COUNT(*) FROM areas_produccion WHERE id=? AND unidad_id=?)');
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('iiii',$categoria,$unidad,$area,$unidad); self::ejecutar($stmt); $row=$stmt->get_result()->fetch_row(); $stmt->close();
        if (!$row || (int)$row[0]!==1 || (int)$row[1]!==1) throw new \RuntimeException('La categoría o área no pertenece al negocio de la oferta.');
    }
    private static function ejecutar(\mysqli_stmt $stmt): void { if (!$stmt->execute()) throw new \RuntimeException($stmt->error); }
}
