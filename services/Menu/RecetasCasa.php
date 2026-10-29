<?php
declare(strict_types=1);
namespace Services\Menu;
use Services\Security\ContextoNegocio as C;
use Services\Inventory\DecimalInventario as D;
use Model\ActiveRecord;

/** Publicaciones inmutables y activación mediante recetas operativas existentes. */
final class RecetasCasa
{
    public static function borrador(int $id): ?array {return ProductosCasa::sql('SELECT * FROM recetas_casa WHERE producto_id=?',[$id])->get_result()->fetch_assoc()?:null;}
    public static function normalizar(array $d): array {
        $rendimiento=D::leer($d['rendimiento']??null);if($rendimiento<=0)ProductosCasa::error('RENDIMIENTO_INVALIDO','El rendimiento debe ser positivo.');
        $unidad=$d['unidad']??'pza';if(!in_array($unidad,['pza','g','kg','ml','l'],true))ProductosCasa::error('UNIDAD_INVALIDA','La unidad de rendimiento no es válida.');
        $componentes=$d['componentes']??null;if(!is_array($componentes)||!$componentes||count($componentes)>100)ProductosCasa::error('COMPONENTES_INVALIDOS','Agrega entre uno y cien ingredientes.');
        $out=[];foreach($componentes as $c){
            if(!is_array($c)||isset($c['subreceta_id'])||isset($c['tipo'])&&$c['tipo']!=='ingrediente')ProductosCasa::error('SUBRECETA_DENEGADA','La receta de la casa no puede utilizar subrecetas privadas.');
            $i=filter_var($c['articulo_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$cantidad=D::leer($c['cantidad']??null);
            if(!$i||$cantidad<=0||isset($out[$i]))ProductosCasa::error('COMPONENTE_INVALIDO','Usa ingredientes distintos y cantidades positivas.');
            $item=ProductosCasa::sql('SELECT id,unidad FROM ingredientes WHERE id=? AND activo=1 FOR SHARE',[$i])->get_result()->fetch_assoc();if(!$item)ProductosCasa::error('INGREDIENTE_INVALIDO','El ingrediente no existe o está inactivo.');
            if(isset($c['unidad'])&&$c['unidad']!==$item['unidad'])ProductosCasa::error('UNIDAD_INVALIDA','La cantidad debe estar expresada en la unidad del ingrediente.');
            // Cantidad de lote / rendimiento. Rechazar, no redondear silenciosamente.
            $numerador=$cantidad*1000;if($numerador%$rendimiento!==0)ProductosCasa::error('PRECISION_INVALIDA','La cantidad por porción debe poder expresarse exactamente con tres decimales.');
            $porcion=intdiv($numerador,$rendimiento);if($porcion<=0)ProductosCasa::error('PRECISION_INVALIDA','La porción debe ser al menos una milésima.');
            $out[$i]=['articulo_id'=>(int)$i,'cantidad'=>D::cantidad($cantidad),'unidad'=>$item['unidad'],'porcion'=>D::cantidad($porcion)];
        }
        ksort($out);return ['rendimiento'=>D::cantidad($rendimiento),'unidad'=>$unidad,'componentes'=>array_values($out)];
    }
    public static function guardar(int $id,array $d,int $revision): array {
        ProductosCasa::autorizar();
        return C::transaccion(static function()use($id,$d,$revision){
            ProductosCasa::autorizar();ProductosCasa::bloquear($id);if(!ProductosCasa::configuracion($id))ProductosCasa::error('SIN_ESTANDAR','Guarda primero el producto de la casa.');
            $d=self::normalizar($d);$r=self::borrador($id);
            if($r&&(int)$r['revision']!==$revision||!$r&&$revision!==0)ProductosCasa::error('VERSION_CONFLICTO','La receta cambió. Recarga el editor.');
            ProductosCasa::sql('INSERT INTO recetas_casa(producto_id,borrador) VALUES (?,?) ON DUPLICATE KEY UPDATE borrador=VALUES(borrador),revision=revision+1',[$id,ProductosCasa::json($d)]);
            return ['revision'=>$revision+1];
        });
    }
    public static function previa(int $id): array {
        C::exigir('menu','ver','global');$r=self::borrador($id);if(!$r)ProductosCasa::error('RECETA_PENDIENTE','Guarda el borrador de receta.');
        $v=ProductosCasa::vistaPrevia($id);$actuales=ProductosCasa::sql('SELECT r.* FROM recetas r JOIN ofertas_producto o ON o.id=r.oferta_id WHERE o.producto_id=? AND r.activo=1 ORDER BY r.id',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['receta'=>$r,'datos'=>ProductosCasa::dato($r['borrador']),'producto'=>$v,'operativas'=>$actuales,'huella'=>hash('sha256',ProductosCasa::json([$r,$v['huella'],$actuales]))];
    }
    public static function publicar(int $id,int $revision,string $huella,string $clave,bool $confirmar): array {
        ProductosCasa::autorizar();
        return C::transaccion(static function()use($id,$revision,$huella,$clave,$confirmar){
            ProductosCasa::autorizar();ProductosCasa::bloquear($id);$c=ProductosCasa::configuracion($id,true);ProductosCasa::ofertas($id,true);$v=self::previa($id);
            if(!$confirmar)ProductosCasa::error('CONFIRMACION_REQUERIDA','Revisa y confirma los negocios afectados.');
            if((int)$v['receta']['revision']!==$revision||!hash_equals($v['huella'],$huella))ProductosCasa::error('VERSION_CONFLICTO','Cambió la receta, el producto o alguna oferta. Revisa de nuevo.');
            $d=self::normalizar($v['datos']);$producto=ProductosCasa::dato($c['borrador']);$publicado=ProductosCasa::dato($c['publicados']);
            if($publicado!==null&&ProductosCasa::json($producto)!==ProductosCasa::json($publicado))ProductosCasa::error('BORRADOR_PENDIENTE','Publica o revisa primero los cambios comerciales pendientes. La receta no los publicará de forma implícita.');
            $numero=(int)ProductosCasa::sql('SELECT COALESCE(MAX(version),0)+1 FROM receta_casa_versiones WHERE producto_id=?',[$id])->get_result()->fetch_row()[0];
            ProductosCasa::sql('INSERT INTO receta_casa_versiones(producto_id,version,rendimiento,unidad,usuario_id) VALUES (?,?,?,?,?)',[$id,$numero,$d['rendimiento'],$d['unidad'],C::actorId()]);$version=(int)ActiveRecord::getDB()->insert_id;
            foreach($d['componentes'] as $comp)ProductosCasa::sql('INSERT INTO receta_casa_componentes(version_id,articulo_id,cantidad) VALUES (?,?,?)',[$version,$comp['articulo_id'],$comp['cantidad']]);
            ProductosCasa::sql('UPDATE receta_casa_versiones SET publicada=1 WHERE id=?',[$version]);
            $producto['receta_version_id']=$version;
            ProductosCasa::sql('UPDATE producto_estandares SET borrador=?,revision=revision+1 WHERE producto_id=?',[ProductosCasa::json($producto),$id]);
            if($publicado!==null){$p=ProductosCasa::vistaPrevia($id);$r=ProductosCasa::publicar($id,(int)$p['configuracion']['revision'],$p['huella'],$clave,true);if(empty($r['pendiente'])&&empty($r['ok']))ProductosCasa::error($r['codigo']??'RECETA_NO_PUBLICADA',$r['mensaje']??'No se pudo activar la receta.');}
            ProductosCasa::sql('UPDATE recetas_casa SET revision=revision+1 WHERE producto_id=?',[$id]);
            return ['version_id'=>$version,'version'=>$numero,'activada'=>$publicado!==null];
        });
    }
    /** Solo se invoca dentro de un commit coordinador; nunca modifica componentes históricos. */
    public static function aplicar(int $producto,int $oferta,string $ctx,int $version): void {
        C::exigir('productos','editar',$ctx,true);$u=C::vendedora($ctx);
        $v=ProductosCasa::sql('SELECT * FROM receta_casa_versiones WHERE id=? AND producto_id=? AND publicada=1',[$version,$producto])->get_result()->fetch_assoc();if(!$v)ProductosCasa::error('RECETA_AJENA','La receta no pertenece al producto o todavía no está publicada.');
        $o=ProductosCasa::sql('SELECT id FROM ofertas_producto WHERE id=? AND producto_id=? AND unidad_id=? FOR UPDATE',[$oferta,$producto,$u])->get_result()->fetch_assoc();if(!$o)ProductosCasa::error('OFERTA_DENEGADA','La receta no corresponde a la oferta.');
        $actual=ProductosCasa::sql('SELECT r.id,l.version_id FROM recetas r LEFT JOIN receta_origen_casa l ON l.receta_id=r.id WHERE r.oferta_id=? AND r.activo=1 FOR UPDATE',[$oferta])->get_result()->fetch_assoc();if($actual&&(int)$actual['version_id']===$version)return;
        $rows=ProductosCasa::sql('SELECT rc.articulo_id,rc.cantidad,i.unidad FROM receta_casa_componentes rc JOIN ingredientes i ON i.id=rc.articulo_id AND i.activo=1 WHERE rc.version_id=? ORDER BY rc.articulo_id',[$version])->get_result()->fetch_all(MYSQLI_ASSOC);
        if(count($rows)!==(int)ProductosCasa::sql('SELECT COUNT(*) FROM receta_casa_componentes WHERE version_id=?',[$version])->get_result()->fetch_row()[0]||!$rows)ProductosCasa::error('INGREDIENTE_INVALIDO','Un ingrediente publicado dejó de estar disponible.');
        $componentes=self::normalizar(['rendimiento'=>$v['rendimiento'],'unidad'=>$v['unidad'],'componentes'=>$rows])['componentes'];$cantidades=array_column($componentes,'porcion','articulo_id');
        $ajustes=ProductosCasa::sql('SELECT va.* FROM variante_ajustes va JOIN producto_variantes pv ON pv.id=va.variante_id WHERE va.oferta_id=? AND pv.activo=1',[$oferta])->get_result()->fetch_all(MYSQLI_ASSOC);
        $necesarios=array_keys($cantidades);
        foreach($ajustes as $aj){if($aj['subreceta_id']!==null||$aj['subreceta_nueva_id']!==null)ProductosCasa::error('VARIANTE_INCOMPATIBLE','Revisa las variantes con subrecetas locales antes de activar el estándar.');
            if($aj['accion']!=='agregar'&&(!isset($cantidades[$aj['articulo_id']])||D::leer($aj['cantidad'])>D::leer($cantidades[$aj['articulo_id']])))ProductosCasa::error('VARIANTE_INCOMPATIBLE','Una variante modifica un componente que no coincide con la nueva receta.');
            if($aj['accion']==='agregar')$necesarios[]=(int)$aj['articulo_id'];if($aj['articulo_nuevo_id'])$necesarios[]=(int)$aj['articulo_nuevo_id'];
        }
        foreach(array_unique($necesarios) as $art){$origen=ProductosCasa::sql('SELECT oc.almacen_id FROM oferta_consumos oc JOIN almacen_articulos aa ON aa.almacen_id=oc.almacen_id AND aa.articulo_id=oc.articulo_id AND aa.unidad_id=oc.unidad_id JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN ingredientes i ON i.id=aa.articulo_id WHERE oc.oferta_id=? AND oc.unidad_id=? AND oc.articulo_id=? AND aa.activo=1 AND a.activo=1 AND i.activo=1 FOR UPDATE',[$oferta,$u,$art])->get_result()->fetch_assoc();if(!$origen)ProductosCasa::error('ORIGEN_PENDIENTE','Falta un origen de consumo activo en '.$ctx.'. Configúralo explícitamente antes de publicar.');}
        $numero=(int)ProductosCasa::sql('SELECT COALESCE(MAX(version),0)+1 FROM recetas WHERE oferta_id=?',[$oferta])->get_result()->fetch_row()[0];
        ProductosCasa::sql('UPDATE recetas SET activo=0 WHERE oferta_id=? AND activo=1',[$oferta]);
        ProductosCasa::sql('INSERT INTO recetas(oferta_id,unidad_id,version,activo) VALUES (?,?,?,1)',[$oferta,$u,$numero]);$receta=(int)ActiveRecord::getDB()->insert_id;
        foreach($componentes as $comp)ProductosCasa::sql('INSERT INTO receta_componentes(receta_id,unidad_id,articulo_id,cantidad) VALUES (?,?,?,?)',[$receta,$u,$comp['articulo_id'],$comp['porcion']]);
        ProductosCasa::sql('INSERT INTO receta_origen_casa(receta_id,version_id) VALUES (?,?)',[$receta,$version]);
    }
    public static function origenes(int $id,array $datos): array {
        ProductosCasa::autorizar();return C::transaccion(static function()use($id,$datos){
            ProductosCasa::autorizar();ProductosCasa::bloquear($id);$r=self::borrador($id);if(!$r)ProductosCasa::error('RECETA_PENDIENTE','Guarda primero los ingredientes.');$ids=array_column(ProductosCasa::dato($r['borrador'])['componentes'],'articulo_id');
            foreach($datos as $oferta=>$articulos){$o=ProductosCasa::sql('SELECT o.id,o.unidad_id,u.slug FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE o.id=? AND o.producto_id=?',[(int)$oferta,$id])->get_result()->fetch_assoc();if(!$o||!is_array($articulos))ProductosCasa::error('OFERTA_DENEGADA','La oferta no corresponde al producto.');C::exigir('inventario','editar',$o['slug'],true);C::exigir('productos','editar',$o['slug'],true);
                foreach($articulos as $art=>$almacen){if(!$almacen)continue;if(!in_array((int)$art,$ids,true)||!is_scalar($almacen))ProductosCasa::error('ORIGEN_INVALIDO','El origen no corresponde a la receta.');
                    $valido=ProductosCasa::sql('SELECT aa.articulo_id FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE aa.almacen_id=? AND aa.articulo_id=? AND aa.unidad_id=? AND aa.activo=1 AND a.activo=1 FOR UPDATE',[(int)$almacen,(int)$art,$o['unidad_id']])->get_result()->fetch_assoc();if(!$valido)ProductosCasa::error('ALMACEN_DENEGADO','Selecciona un almacén propio con asociación activa.');
                    ProductosCasa::sql('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE almacen_id=VALUES(almacen_id)',[$o['id'],(int)$art,$o['unidad_id'],(int)$almacen]);
                }
            }return ['origenes_guardados'=>true];
        });
    }
    public static function volver(string $ctx,int $oferta): array {
        C::exigir('productos','editar',$ctx);return C::transaccion(static function()use($ctx,$oferta){
            C::exigir('productos','editar',$ctx,true);$u=C::vendedora($ctx);$o=ProductosCasa::sql('SELECT producto_id FROM ofertas_producto WHERE id=? AND unidad_id=?',[$oferta,$u])->get_result()->fetch_assoc();if(!$o)ProductosCasa::error('OFERTA_DENEGADA','La oferta no es válida.');$id=(int)$o['producto_id'];ProductosCasa::bloquear($id);$d=ProductosCasa::publicada($id);if(!$d)ProductosCasa::error('SIN_ESTANDAR','No hay una receta de la casa publicada.');
            if($d['receta_version_id'])self::aplicar($id,$oferta,$ctx,(int)$d['receta_version_id']);else ProductosCasa::sql('UPDATE recetas SET activo=0 WHERE oferta_id=?',[$oferta]);
            ProductosCasa::retirarPersonalizacion($oferta,'receta');return ['receta_restaurada'=>true];
        });
    }
    public static function validarAdaptacion(int $oferta,int $unidad,array $componentes): void {
        foreach($componentes as $comp){if($comp['subreceta_id']!==null)ProductosCasa::error('SUBRECETA_DENEGADA','Adapta el producto de la casa con ingredientes desglosados.');
            if(!ProductosCasa::sql('SELECT oc.almacen_id FROM oferta_consumos oc JOIN almacen_articulos aa ON aa.almacen_id=oc.almacen_id AND aa.articulo_id=oc.articulo_id AND aa.unidad_id=oc.unidad_id JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN ingredientes i ON i.id=aa.articulo_id WHERE oc.oferta_id=? AND oc.unidad_id=? AND oc.articulo_id=? AND aa.activo=1 AND a.activo=1 AND i.activo=1 FOR UPDATE',[$oferta,$unidad,$comp['articulo_id']])->get_result()->fetch_row())ProductosCasa::error('ORIGEN_PENDIENTE','Configura un origen local activo para cada ingrediente antes de adaptar la receta.');
        }
    }
}
