<?php
declare(strict_types=1);
namespace Services\Menu;

use Services\Inventory\Articulos as A;
use Services\Inventory\InventarioError;
use Services\Inventory\ResolucionArticulos as R;
use Services\Inventory\DecimalInventario as D;
use Services\Security\ContextoNegocio as C;

/** Presentaciones hijas: preserva una oferta por identidad y negocio M1.2. */
final class PresentacionesOferta
{
    public static function listar(string $ctx,int $oferta): array
    {
        C::exigir('menu','ver',$ctx);$u=C::vendedora($ctx);self::oferta($oferta,$u);
        return A::sql('SELECT p.*,v.id version_id,v.version,v.precio FROM oferta_presentaciones p LEFT JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.oferta_id=? AND p.unidad_id=? ORDER BY p.id',[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public static function publicar(string $ctx,int $oferta,array $raw,int $revision,string $clave,bool $confirmado): array
    {
        return self::guardarVersion($ctx,$oferta,$raw,$revision,$clave,$confirmado);
    }
    private static function guardarVersion(string $ctx,int $oferta,array $raw,int $revision,string $clave,bool $confirmado,?int $publicacionOficial=null,?array $datosEstandar=null): array
    {
        return C::transaccion(static function()use($ctx,$oferta,$raw,$revision,$clave,$confirmado,$publicacionOficial,$datosEstandar){
            C::exigir('menu','editar',$ctx,true);C::exigir('productos','editar',$ctx,true);$u=C::vendedora($ctx);
            $o=self::oferta($oferta,$u,true);ProductosCasa::bloquear((int)$o['producto_id']);
            if($publicacionOficial!==null){C::facultad('comercial.identidad.editar','global','menu',true);$oficial=A::sql('SELECT id FROM producto_publicaciones WHERE id=? AND producto_id=?',[$publicacionOficial,$o['producto_id']])->get_result()->fetch_row();if(!$oficial)throw new InventarioError('ESTANDAR_INVALIDO','La publicación oficial no corresponde al producto.');}
            if($publicacionOficial===null&&!ProductosCasa::permite((int)$o['producto_id'],'receta'))throw new InventarioError('CAMPO_PROTEGIDO','La composición está definida por Global. Usa la receta oficial o habilita su adaptación.');
            $hash=hash('sha256',json_encode([C::actorId(),'presentacion',$oferta,$revision,$raw],JSON_THROW_ON_ERROR));
            $anterior=A::comando($u,$clave,$hash);if($anterior!==null)return $anterior+['reintento'=>true];
            if(!$confirmado)throw new InventarioError('CONFIRMACION_REQUERIDA','Confirma la composición y el precio de la presentación.');
            $key=A::texto($raw['clave']??'',40);if(!preg_match('/^[a-z0-9_-]+$/D',$key))throw new InventarioError('CLAVE_INVALIDA','Usa letras minúsculas, números o guiones para la presentación.');
            $nombre=A::texto($raw['nombre']??'',120);$precio=ImporteOferta::normalizar($raw['precio']??'');
            if($publicacionOficial===null&&!ProductosCasa::permite((int)$o['producto_id'],'precio') && bccomp($precio,(string)$o['precio'],2)!==0)throw new InventarioError('PRECIO_PROTEGIDO','El precio está definido por Global.');
            $activo=$raw['activo']??0;if(!in_array($activo,[0,1,'0','1'],true))throw new InventarioError('ESTADO_INVALIDO','Selecciona un estado válido.');
            if($publicacionOficial===null && $activo && !ProductosCasa::permite((int)$o['producto_id'],'activo') && !(int)$o['activo'])throw new InventarioError('DISPONIBILIDAD_PROTEGIDA','Global mantiene la oferta fuera de venta.');
            $p=A::sql('SELECT * FROM oferta_presentaciones WHERE oferta_id=? AND unidad_id=? AND clave=? FOR UPDATE',[$oferta,$u,$key])->get_result()->fetch_assoc();
            if((int)($p['revision']??0)!==$revision)throw new InventarioError('REVISION_OBSOLETA','La presentación cambió. Recarga la ficha.');
            if(!$p){A::sql('INSERT INTO oferta_presentaciones(oferta_id,unidad_id,clave,nombre) VALUES(?,?,?,?)',[$oferta,$u,$key,$nombre]);$pid=(int)C::db()->insert_id;}else $pid=(int)$p['id'];
            $version=(int)A::sql('SELECT COALESCE(MAX(version),0)+1 FROM presentacion_versiones WHERE presentacion_id=?',[$pid])->get_result()->fetch_row()[0];
            $componentes=A::componentes($raw['componentes']??null);
            foreach($componentes as $h)if($h['merma']!=='0.000')throw new InventarioError('MERMA_INVALIDA','La merma se define en la elaboración, no en la presentación de servicio.');
            A::sql('INSERT INTO presentacion_versiones(presentacion_id,oferta_id,unidad_id,version,precio,usuario_id) VALUES(?,?,?,?,?,?)',[$pid,$oferta,$u,$version,$precio,C::actorId()]);$vid=(int)C::db()->insert_id;
            foreach($componentes as $i=>$h){$etapa=$raw['componentes'][$i]['etapa']??'servicio';if(!in_array($etapa,['servicio','produccion'],true))throw new InventarioError('ETAPA_INVALIDA','Elige la etapa de consumo.');
                if($activo && $etapa==='produccion')throw new InventarioError('PRODUCCION_PENDIENTE','Esta presentación depende de producción M2.5 y no puede venderse todavía.');
                A::sql('INSERT INTO presentacion_componentes(version_id,articulo_id,cantidad,unidad,modalidad,version_receta_id,etapa) VALUES(?,?,?,?,?,?,?)',[$vid,$h['articulo_id'],$h['cantidad'],$h['unidad'],$h['modalidad'],$h['version_receta_id'],$etapa]);}
            $raices=self::componentes($vid);$plan=self::plan($u,$oferta,$raices,[],(bool)$activo);
            if($activo)self::exigirExistenciasPrevias($plan,$u);
            if($publicacionOficial!==null)A::sql('INSERT INTO presentacion_estandares(version_id,publicacion_producto_id,configuracion) VALUES(?,?,?)',[$vid,$publicacionOficial,json_encode($datosEstandar??$raw,JSON_THROW_ON_ERROR)]);
            A::sql('UPDATE presentacion_versiones SET publicada=1 WHERE id=?',[$vid]);A::sql('UPDATE oferta_presentaciones SET nombre=?,activo=?,revision=revision+1 WHERE id=?',[$nombre,(int)$activo,$pid]);
            $r=['ok'=>true,'presentacion_id'=>$pid,'version_id'=>$vid,'version'=>$version,'revision'=>$revision+1,'plan'=>$plan];A::recordar($u,$clave,$hash,$r);return $r;
        });
    }
    public static function requeridos(int $oferta,int $u): array
    {
        $ids=[];$versiones=A::sql('SELECT v.id FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.oferta_id=? AND p.unidad_id=?',[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($versiones as $v){$plan=self::plan($u,$oferta,self::componentes((int)$v['id']),[],false);foreach($plan['lineas'] as $l)$ids[]=(int)$l['articulo_id'];}
        $anidadas=A::sql('SELECT ca.articulo_id,ca.version_receta_id,rc.cantidad,i.unidad FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id JOIN ingredientes i ON i.id=ca.articulo_id WHERE r.oferta_id=? AND r.unidad_id=? AND r.activo=1',[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($anidadas as $c){$plan=self::plan($u,$oferta,[$c+['modalidad'=>'desglosar']],[],false);$ids=array_merge($ids,array_column($plan['lineas'],'articulo_id'));}
        $entradas=A::sql("SELECT ar.articulo_id,va.receta_destino_id version_receta_id,IF(va.accion='agregar',va.cantidad,va.nueva_cantidad) cantidad,i.unidad FROM variante_ajustes va JOIN producto_variantes pv ON pv.id=va.variante_id AND pv.oferta_id=va.oferta_id AND pv.unidad_id=va.unidad_id JOIN articulo_recetas ar ON ar.id=va.receta_destino_id JOIN ingredientes i ON i.id=ar.articulo_id WHERE va.oferta_id=? AND va.unidad_id=? AND pv.activo=1 AND va.accion<>'quitar' AND va.modalidad_destino='desglosar'",[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($entradas as $c){$plan=self::plan($u,$oferta,[$c+['modalidad'=>'desglosar']],[],false);$ids=array_merge($ids,array_column($plan['lineas'],'articulo_id'));}
        return array_values(array_unique($ids));
    }
    /** Proyección comercial compartida por carta, PDF y POS; no expone recetas. */
    public static function paraCarta(int $oferta,int $u): array {return self::paraCartas($u)[$oferta]??['configurada'=>false,'presentaciones'=>[]];}
    public static function paraCartas(int $u): array
    {
        $rows=A::sql('SELECT p.id,p.oferta_id,p.nombre,p.activo,v.precio,o.producto_id,o.precio precio_oferta,EXISTS(SELECT 1 FROM presentacion_estandares e WHERE e.version_id=v.id) es_estandar FROM oferta_presentaciones p JOIN ofertas_producto o ON o.id=p.oferta_id AND o.unidad_id=p.unidad_id JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.unidad_id=? ORDER BY p.oferta_id,p.id',[$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        $map=[];$reglas=[];
        foreach($rows as $r){$oferta=(int)$r['oferta_id'];$map[$oferta]??=['configurada'=>true,'presentaciones'=>[]];if(!(int)$r['activo'])continue;$producto=(int)$r['producto_id'];
            $reglas[$producto]??=['receta'=>ProductosCasa::permite($producto,'receta'),'precio'=>ProductosCasa::permite($producto,'precio')];
            if(empty($r['es_estandar'])&&(!$reglas[$producto]['receta']||(!$reglas[$producto]['precio']&&bccomp($r['precio'],$r['precio_oferta'],2)!==0)))continue;
            $map[$oferta]['presentaciones'][]=array_intersect_key($r,array_flip(['id','nombre','precio']));
        }
        return $map;
    }
    public static function previaEstandar(int $producto): array
    {
        C::exigir('menu','ver','global');$v=ProductosCasa::vistaPrevia($producto);$ofertas=$v['ofertas'];$presentaciones=[];
        foreach($ofertas as $o){C::exigir('productos','ver',$o['slug']);$presentaciones[$o['id']]=self::listar($o['slug'],(int)$o['id']);}
        return ['producto'=>$v,'ofertas'=>$ofertas,'presentaciones'=>$presentaciones,'huella'=>A::huella([$v['huella'],$presentaciones])];
    }
    public static function publicarEstandar(int $producto,array $raw,string $huella,string $clave,bool $confirmar): array
    {
        return C::transaccion(static function()use($producto,$raw,$huella,$clave,$confirmar){
            C::facultad('comercial.identidad.editar','global','menu',true);ProductosCasa::bloquear($producto);$v=self::previaEstandar($producto);$u=C::unidad('global');$hash=A::huella([C::actorId(),$producto,$raw,$huella]);$anterior=A::comando($u,$clave,$hash);if($anterior!==null)return $anterior+['reintento'=>true];
            if(!$confirmar||!hash_equals($v['huella'],$huella))throw new InventarioError('REVISION_OBSOLETA','Revisa y confirma de nuevo las presentaciones y negocios afectados.');
            $publicacion=(int)($v['producto']['configuracion']['publicacion_id']??0);if(!$publicacion||!$v['ofertas'])throw new InventarioError('PUBLICACION_PENDIENTE','Publica primero el producto y sus destinos desde Productos de la casa.');
            $componentes=A::componentes($raw['componentes']??null);foreach($componentes as $c)if($c['version_receta_id']!==null){$r=A::cargarReceta($c['version_receta_id'],$u);if(!$r||empty($r['global']))throw new InventarioError('RECETA_AJENA','Una presentación oficial solo utiliza elaboraciones Global.');}
            $resultados=[];foreach($v['ofertas'] as $o){C::exigir('menu','editar',$o['slug'],true);C::exigir('productos','editar',$o['slug'],true);$revision=0;foreach($v['presentaciones'][$o['id']] as $p)if($p['clave']===($raw['clave']??''))$revision=(int)$p['revision'];$efectivo=$raw;
                $anterior=null;foreach($v['presentaciones'][$o['id']] as $pp)if($pp['clave']===($raw['clave']??''))$anterior=$pp;
                if($anterior){$base=A::sql('SELECT e.configuracion FROM presentacion_estandares e JOIN presentacion_versiones v ON v.id=e.version_id WHERE v.presentacion_id=? ORDER BY v.version DESC LIMIT 1',[$anterior['id']])->get_result()->fetch_row();if($base){$base=json_decode($base[0],true,512,JSON_THROW_ON_ERROR);$componentesActuales=self::componentes((int)$anterior['version_id']);
                    if(ProductosCasa::permite($producto,'receta')&&A::huella(A::componentes($componentesActuales))!==A::huella(A::componentes($base['componentes'])))$efectivo['componentes']=$componentesActuales;
                    if(ProductosCasa::permite($producto,'precio')&&bccomp($anterior['precio'],(string)$base['precio'],2)!==0)$efectivo['precio']=$anterior['precio'];
                    if(ProductosCasa::permite($producto,'activo')&&(int)$anterior['activo']!==(int)($base['activo']??0))$efectivo['activo']=(int)$anterior['activo'];
                }}
                $r=self::guardarVersion($o['slug'],(int)$o['id'],$efectivo,$revision,$clave.'_'.$o['slug'],true,$publicacion,$raw);if(($r['ok']??null)===false)throw new InventarioError($r['codigo']??'PUBLICACION_FALLIDA',$r['mensaje']??'No se pudo publicar en todos los destinos.');$resultados[$o['slug']]=$r['version_id'];}
            $r=['ok'=>true,'versiones'=>$resultados];A::recordar($u,$clave,$hash,$r);return $r;
        });
    }
    public static function componentes(int $version): array {return A::sql('SELECT articulo_id,cantidad,unidad,modalidad,version_receta_id,etapa FROM presentacion_componentes WHERE version_id=? ORDER BY id',[$version])->get_result()->fetch_all(MYSQLI_ASSOC);}
    public static function version(int $presentacion,int $oferta,int $u): array
    {
        $p=A::sql('SELECT p.nombre,p.clave,p.activo,v.*,EXISTS(SELECT 1 FROM presentacion_estandares e WHERE e.version_id=v.id) es_estandar FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 WHERE p.id=? AND p.oferta_id=? AND p.unidad_id=? ORDER BY v.version DESC LIMIT 1 FOR UPDATE',[$presentacion,$oferta,$u])->get_result()->fetch_assoc();
        if(!$p || !(int)$p['activo'])throw new InventarioError('PRESENTACION_NO_DISPONIBLE','La presentación no está disponible en este negocio.');
        return $p;
    }
    public static function plan(int $u,int $oferta,array $raices,array $ajustes=[],bool $exigirOrigen=true): array
    {
        $raices=R::aplicarVariantes($raices,$ajustes,$u,static fn($id)=>A::cargarArticulo($id,$u),static fn($id)=>A::cargarReceta($id,$u));$orig=[];
        foreach(A::sql('SELECT oc.articulo_id,oc.almacen_id,oc.unidad_id,IF(a.activo=1 AND aa.activo=1,1,0) activo FROM oferta_consumos oc JOIN almacenes a ON a.id=oc.almacen_id AND a.unidad_id=oc.unidad_id JOIN almacen_articulos aa ON aa.almacen_id=oc.almacen_id AND aa.articulo_id=oc.articulo_id AND aa.unidad_id=oc.unidad_id WHERE oc.oferta_id=? AND oc.unidad_id=? ORDER BY oc.articulo_id FOR SHARE',[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC) as $o)$orig[(int)$o['articulo_id']]=$o;
        return R::resolver($raices,$u,static fn($id)=>A::cargarArticulo($id,$u),static fn($id)=>A::cargarReceta($id,$u),$orig,$exigirOrigen);
    }
    public static function paraVenta(string $ctx,int $oferta,int $presentacion,array $ids,int $cantidad): array
    {
        C::exigir('pdv','operar',$ctx,true);$u=C::vendedora($ctx);$o=self::oferta($oferta,$u,true);$p=self::version($presentacion,$oferta,$u);
        if(empty($p['es_estandar'])&&!ProductosCasa::permite((int)$o['producto_id'],'receta'))throw new InventarioError('CAMPO_PROTEGIDO','La presentación requiere una composición autorizada por Global.');
        if(empty($p['es_estandar'])&&!ProductosCasa::permite((int)$o['producto_id'],'precio') && bccomp($p['precio'],$o['precio'],2)!==0)throw new InventarioError('PRECIO_PROTEGIDO','El precio de esta presentación dejó de estar autorizado.');
        $seleccion=Variantes::resolver($oferta,$ids,$ctx);if(empty($seleccion['ok']))throw new InventarioError('VARIANTE_INVALIDA','Revisa las opciones de la presentación.');
        $ids=$seleccion['ids'];$ajustes=[];
        if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$rows=A::sql("SELECT va.* FROM variante_ajustes va WHERE oferta_id=? AND unidad_id=? AND variante_id IN ($in) ORDER BY id",array_merge([$oferta,$u],$ids))->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($rows as $aj){if($aj['subreceta_id']!==null || $aj['subreceta_nueva_id']!==null)throw new InventarioError('VARIANTE_NO_MIGRADA','Relaciona la preparación con un artículo antes de usarla en esta presentación.');
                $a=A::cargarArticulo((int)$aj['articulo_id'],$u);$h=['accion'=>$aj['accion'],'articulo_id'=>(int)$aj['articulo_id'],'cantidad'=>$aj['cantidad'],'unidad'=>$a['unidad']??'','modalidad'=>$aj['modalidad_destino'],'version_receta_id'=>$aj['receta_destino_id']===null?null:(int)$aj['receta_destino_id'],'etapa'=>'servicio'];
                if($aj['accion']==='sustituir'){$n=A::cargarArticulo((int)$aj['articulo_nuevo_id'],$u);$h['nuevo']=['articulo_id'=>(int)$aj['articulo_nuevo_id'],'cantidad'=>$aj['nueva_cantidad'],'unidad'=>$n['unidad']??'','modalidad'=>$aj['modalidad_destino'],'version_receta_id'=>$aj['receta_destino_id']===null?null:(int)$aj['receta_destino_id']];}$ajustes[]=$h;}}
        $raices=self::componentes((int)$p['id']);foreach($raices as $r)if($r['etapa']==='produccion')throw new InventarioError('PRODUCCION_PENDIENTE','Esta configuración no puede ejecutarse como venta.');
        $plan=self::plan($u,$oferta,$raices,$ajustes,true);
        $costo=$plan['costo_unitario'];foreach($plan['lineas'] as &$l){$l['cantidad']=D::cantidad(D::leer(bcmul($l['cantidad'],(string)$cantidad,3)));}unset($l);
        self::exigirExistenciasPrevias($plan,$u);
        return ['version'=>$p,'plan'=>$plan+['composicion_base'=>$raices,'cantidad_vendida'=>$cantidad,'opciones'=>$ids,'ajustes'=>$ajustes,'precio_base'=>$p['precio']],'costo_unitario'=>$costo];
    }
    public static function congelar(int $item,int $oferta,int $u,int $version,array $plan): void
    {
        A::sql('INSERT INTO ticket_item_resoluciones(ticket_item_id,unidad_id,oferta_id,presentacion_version_id,snapshot,huella) VALUES(?,?,?,?,?,?)',[$item,$u,$oferta,$version,json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),A::huella($plan)]);
    }
    public static function exigirExistenciasPrevias(array $plan,int $u): void
    {
        foreach($plan['lineas'] as $l){$a=A::cargarArticulo((int)$l['articulo_id'],$u);if(empty($a['produccion_previa']))continue;
            $saldo=A::sql('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? AND unidad_id=? FOR UPDATE',[$l['almacen_id'],$l['articulo_id'],$u])->get_result()->fetch_row();
            if(!$saldo||bccomp((string)$saldo[0],$l['cantidad'],3)<0)throw new InventarioError('PRODUCCION_PENDIENTE','No hay suficientes existencias terminadas recibidas. La producción física está pendiente de M2.5.');
        }
    }
    private static function oferta(int $id,int $u,bool $lock=false): array {$o=A::sql('SELECT * FROM ofertas_producto WHERE id=? AND unidad_id=?'.($lock?' FOR UPDATE':''),[$id,$u])->get_result()->fetch_assoc();if(!$o)throw new InventarioError('OFERTA_DENEGADA','La oferta no pertenece al negocio activo.');return $o;}
}
