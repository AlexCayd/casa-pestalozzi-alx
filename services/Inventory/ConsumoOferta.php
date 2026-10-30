<?php
declare(strict_types=1);

namespace Services\Inventory;

/** Resuelve snapshots comerciales a líneas internas del motor 3C. */
final class ConsumoOferta
{

    /** Bloquea el item y su ticket, validando ámbito y estado comercial. */
    public static function ticketItem(\mysqli $db, int $unidadId, int $ticketItemId, bool $reversion = false, bool $permitirEntregado = false): array
    {
        if ($unidadId < 1 || $ticketItemId < 1) throw new InventarioError('ID_INVALIDO', 'El item de venta no es válido.');
        $stmt = $db->prepare(
            'SELECT ti.id,ti.unidad_id,ti.ticket_id,ti.oferta_id,ti.producto_id,ti.receta_id,ti.nombre,ti.cantidad,ti.variante_ids,ti.estado,t.estado AS ticket_estado '
            . 'FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id '
            . 'WHERE ti.id=? AND ti.unidad_id=? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('ii', $ticketItemId, $unidadId);
        self::ejecutar($stmt);
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) throw new InventarioError('ITEM_NO_AUTORIZADO', 'No se encontró el item de venta autorizado.');
        if ($item['ticket_estado'] !== 'abierto') throw new InventarioError('TICKET_NO_ABIERTO', 'El ticket debe estar abierto para operar su consumo.');
        if ($reversion ? $item['estado'] !== 'cancelado' : ($item['estado'] === 'cancelado' || ($item['estado'] === 'entregado' && !$permitirEntregado))) {
            throw new InventarioError($reversion ? 'ITEM_NO_CANCELADO' : 'ITEM_CANCELADO', $reversion ? 'El item no está cancelado.' : 'No se consume un item cancelado.');
        }
        return $item;
    }

    /**
     * Resuelve el snapshot receta/variantes sin recibir cantidad o almacén del
     * cliente. Devuelve consumos por unidad vendida; la cantidad del ticket se
     * aplica con aritmética exacta y el redondeo se hace al final.
     *
     * @return array{consume:bool,lineas:list<array>,costo_unitario:?string}
     */
    public static function preparar(\mysqli $db, array $item, int $unidadId): array
    {
        if ((int)$item['unidad_id'] !== $unidadId || (int)$item['oferta_id'] < 1) {
            throw new InventarioError('OFERTA_NO_AUTORIZADA', 'La oferta del item no pertenece al negocio vendedor.');
        }
        $snapshot=$db->execute_query('SELECT snapshot,huella,unidad_id,oferta_id FROM ticket_item_resoluciones WHERE ticket_item_id=?',[(int)$item['id']])->fetch_assoc();
        if($snapshot){
            if((int)$snapshot['unidad_id']!==$unidadId || (int)$snapshot['oferta_id']!==(int)$item['oferta_id'] || !hash_equals($snapshot['huella'],Articulos::huella(json_decode($snapshot['snapshot'],true,512,JSON_THROW_ON_ERROR))))throw new InventarioError('SNAPSHOT_INVALIDO','La resolución congelada no corresponde a la venta.');
            $plan=json_decode($snapshot['snapshot'],true,512,JSON_THROW_ON_ERROR);
            $lineas=array_map(static fn($l)=>array_intersect_key($l,array_flip(['almacen_id','articulo_id','cantidad','costo_unitario'])),$plan['lineas']);
            return ['consume'=>$plan['consume'],'lineas'=>$lineas,'costo_unitario'=>$plan['costo_unitario']];
        }
        $cantidadTicket=self::enteroPositivo((string)$item['cantidad'],255,'CANTIDAD_INVALIDA');$oferta=(int)$item['oferta_id'];
        $virtuales=[];$recetasLegadas=[];
        $nodo=static function(?int $articulo,?int $sub,string $cantidad,?int $version=null)use($db,$unidadId,&$virtuales,&$recetasLegadas):array{
            if($articulo!==null){$a=Articulos::cargarArticulo($articulo,$unidadId);return ['articulo_id'=>$articulo,'cantidad'=>$cantidad,'unidad'=>$a['unidad']??'','modalidad'=>$version?'desglosar':'almacenado','version_receta_id'=>$version];}
            if(!$sub)throw new InventarioError('COMPONENTE_INVALIDO','La composición contiene una referencia inválida.');
            $m=$db->execute_query('SELECT articulo_id FROM articulo_correspondencias WHERE subreceta_id=? AND unidad_id=?',[$sub,$unidadId])->fetch_assoc();
            if($m && !$version){$a=Articulos::cargarArticulo((int)$m['articulo_id'],$unidadId);return ['articulo_id'=>(int)$m['articulo_id'],'cantidad'=>$cantidad,'unidad'=>$a['unidad']??'','modalidad'=>'almacenado','version_receta_id'=>null];}
            if($m && $version){$a=Articulos::cargarArticulo((int)$m['articulo_id'],$unidadId);return ['articulo_id'=>(int)$m['articulo_id'],'cantidad'=>$cantidad,'unidad'=>$a['unidad']??'','modalidad'=>'desglosar','version_receta_id'=>$version];}
            $s=self::uno($db,'SELECT * FROM subrecetas WHERE id=? AND unidad_id=? FOR SHARE',[$sub,$unidadId]);if(!$s||!(int)$s['activo'])throw new InventarioError('SUBRECETA_INVALIDA','La preparación está inactiva o pertenece a otro negocio.');
            // Referencia temporal del adaptador: no crea una identidad ni saldo.
            $ref=4294967296+$sub;$virtuales[$ref]=['unidad'=>$s['unidad'],'activo'=>1,'componente'=>1,'costo'=>null];$componentes=[];
            foreach($db->execute_query('SELECT si.articulo_id,si.cantidad,i.unidad FROM subreceta_ingredientes si JOIN ingredientes i ON i.id=si.articulo_id WHERE si.subreceta_id=? AND si.unidad_id=? ORDER BY si.id',[$sub,$unidadId])->fetch_all(MYSQLI_ASSOC) as $h)$componentes[]=$h+['modalidad'=>'almacenado','version_receta_id'=>null];
            $recetasLegadas[$ref]=['articulo_id'=>$ref,'unidad_id'=>$unidadId,'publicada'=>1,'rendimiento'=>$s['rendimiento'],'unidad'=>$s['unidad'],'componentes'=>$componentes,'subreceta_origen_id'=>$sub];
            return ['articulo_id'=>$ref,'cantidad'=>$cantidad,'unidad'=>$s['unidad'],'modalidad'=>'desglosar','version_receta_id'=>$ref];
        };
        $base=[];
        if($item['receta_id']!==null){
            if(!self::uno($db,'SELECT id FROM recetas WHERE id=? AND oferta_id=? AND unidad_id=? FOR SHARE',[(int)$item['receta_id'],$oferta,$unidadId]))throw new InventarioError('RECETA_INVALIDA','La receta del snapshot no pertenece a su oferta.');
            $rows=$db->execute_query('SELECT rc.articulo_id,rc.subreceta_id,rc.cantidad,ca.articulo_id articulo_unificado_id,ca.version_receta_id FROM receta_componentes rc LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id WHERE rc.receta_id=? AND rc.unidad_id=? ORDER BY rc.id',[(int)$item['receta_id'],$unidadId])->fetch_all(MYSQLI_ASSOC);
            if(!$rows)throw new InventarioError('RECETA_INCOMPLETA','La receta no tiene componentes.');
            foreach($rows as $r)$base[]=$nodo($r['articulo_unificado_id']!==null?(int)$r['articulo_unificado_id']:($r['articulo_id']===null?null:(int)$r['articulo_id']),$r['subreceta_id']===null?null:(int)$r['subreceta_id'],$r['cantidad'],$r['version_receta_id']===null?null:(int)$r['version_receta_id']);
        }
        $ids=self::idsVariantes($item['variante_ids']===null?'':(string)$item['variante_ids']);$ajustes=[];
        if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([$oferta,$unidadId],$ids);
            $ops=$db->execute_query("SELECT id,grupo FROM producto_variantes WHERE oferta_id=? AND unidad_id=? AND id IN ($in) ORDER BY id FOR SHARE",$params)->fetch_all(MYSQLI_ASSOC);
            if(count($ops)!==count($ids)||count(array_unique(array_column($ops,'grupo')))!==count($ops))throw new InventarioError('VARIANTE_INVALIDA','Las opciones no pertenecen a la oferta o repiten un grupo.');
            $rows=$db->execute_query("SELECT * FROM variante_ajustes WHERE oferta_id=? AND unidad_id=? AND variante_id IN ($in) ORDER BY id",$params)->fetch_all(MYSQLI_ASSOC);
            foreach($rows as $r){
                $version=$r['receta_destino_id']===null?null:(int)$r['receta_destino_id'];
                $h=$nodo($r['articulo_id']===null?null:(int)$r['articulo_id'],$r['subreceta_id']===null?null:(int)$r['subreceta_id'],$r['cantidad'],$r['accion']==='agregar'?$version:null);$h['accion']=$r['accion'];$h['etapa']='servicio';
                if($r['accion']==='sustituir')$h['nuevo']=$nodo($r['articulo_nuevo_id']===null?null:(int)$r['articulo_nuevo_id'],$r['subreceta_nueva_id']===null?null:(int)$r['subreceta_nueva_id'],$r['nueva_cantidad'],$version);
                $ajustes[]=$h;
            }
        }
        $cargarArticulo=static fn($id)=>$virtuales[$id]??Articulos::cargarArticulo($id,$unidadId);
        $cargarReceta=static fn($id)=>$recetasLegadas[$id]??Articulos::cargarReceta($id,$unidadId);
        $raices=ResolucionArticulos::aplicarVariantes($base,$ajustes,$unidadId,$cargarArticulo,$cargarReceta,true);
        $previa=ResolucionArticulos::resolver($raices,$unidadId,$cargarArticulo,$cargarReceta,[],false,true);
        $origenes=self::almacenesOferta($db,$oferta,$unidadId,array_values(array_unique(array_column($previa['lineas'],'articulo_id'))));foreach($origenes as &$o){$o['unidad_id']=$unidadId;$o['activo']=1;}unset($o);
        foreach($raices as &$r)$r['cantidad']=DecimalInventario::cantidad(DecimalInventario::leer(bcmul($r['cantidad'],(string)$cantidadTicket,3)));unset($r);
        $plan=ResolucionArticulos::resolver($raices,$unidadId,$cargarArticulo,$cargarReceta,$origenes,true,true);$plan['costo_unitario']=$previa['costo_unitario'];$plan['receta_legada_id']=$item['receta_id'];$plan['opciones']=$ids;$plan['ajustes']=$ajustes;$plan['composicion_base']=$base;$plan['subrecetas_pendientes']=$recetasLegadas;
        \Services\Menu\PresentacionesOferta::exigirExistenciasPrevias($plan,$unidadId);
        $lineas=array_map(static fn($l)=>array_intersect_key($l,array_flip(['almacen_id','articulo_id','cantidad','costo_unitario'])),$plan['lineas']);
        return ['consume'=>$plan['consume'],'lineas'=>$lineas,'costo_unitario'=>$plan['costo_unitario'],'snapshot'=>$plan];
    }

    /** Originales venta y saldo de compensación para reversión idempotente. */
    public static function movimientosPendientes(\mysqli $db,int $unidadId,int $ticketItemId): array
    {
        $stmt=$db->prepare("SELECT id,delta,costo_unitario FROM movimientos_inventario WHERE ticket_item_id=? AND unidad_id=? AND tipo='venta' ORDER BY id FOR UPDATE");
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('ii',$ticketItemId,$unidadId); self::ejecutar($stmt); $originales=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $lineas=[];
        foreach ($originales as $original) {
            $compensaciones=$db->prepare('SELECT delta FROM movimientos_inventario WHERE movimiento_origen_id=? FOR UPDATE');
            if (!$compensaciones) throw new \RuntimeException($db->error);
            $id=(int)$original['id']; $compensaciones->bind_param('i',$id); self::ejecutar($compensaciones); $res=$compensaciones->get_result();
            $compensado=0; while($row=$res->fetch_assoc()) $compensado=DecimalInventario::sumar($compensado,DecimalInventario::leer((string)$row['delta'],3,true));
            $compensaciones->close();
            $restante=-DecimalInventario::leer((string)$original['delta'],3,true)-$compensado;
            if ($restante<0) throw new InventarioError('REVERSION_EXCEDIDA','Las compensaciones exceden el consumo original.');
            if ($restante>0) $lineas[]=['movimiento_id'=>$id,'cantidad'=>DecimalInventario::cantidad($restante)];
        }
        return $lineas;
    }

    private static function almacenesOferta(\mysqli $db,int $ofertaId,int $unidadId,array $articulos): array
    {
        if ($articulos===[]) return [];
        $in=implode(',',array_fill(0,count($articulos),'?'));
        $stmt=$db->prepare("SELECT oc.articulo_id,oc.almacen_id,aa.activo AS asociacion_activa,a.activo AS almacen_activo FROM oferta_consumos oc JOIN almacen_articulos aa ON aa.almacen_id=oc.almacen_id AND aa.articulo_id=oc.articulo_id AND aa.unidad_id=oc.unidad_id JOIN almacenes a ON a.id=oc.almacen_id AND a.unidad_id=oc.unidad_id WHERE oc.oferta_id=? AND oc.unidad_id=? AND oc.articulo_id IN ({$in}) ORDER BY oc.articulo_id FOR UPDATE");
        if (!$stmt) throw new \RuntimeException($db->error);
        $params=array_merge([$ofertaId,$unidadId],$articulos); self::bind($stmt,'ii'.str_repeat('i',count($articulos)),$params); self::ejecutar($stmt); $out=[];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            if (!(int)$row['almacen_activo']) throw new InventarioError('ALMACEN_INACTIVO','El almacén de consumo asignado a la oferta está inactivo.');
            if (!(int)$row['asociacion_activa']) throw new InventarioError('ASOCIACION_INACTIVA','La asociación del artículo al almacén de consumo está inactiva.');
            $out[(int)$row['articulo_id']]=$row;
        }
        $stmt->close(); return $out;
    }

    private static function idsVariantes(string $csv): array
    {
        if ($csv==='') return [];
        if (!preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9})*$/D',$csv)) throw new InventarioError('VARIANTE_INVALIDA','El snapshot de variantes no tiene un formato válido.');
        $ids=array_map('intval',explode(',',$csv));
        if (count($ids)>6 || count(array_unique($ids))!==count($ids)) throw new InventarioError('VARIANTE_INVALIDA','El snapshot de variantes contiene IDs repetidos o excede los grupos permitidos.');
        return $ids;
    }

    private static function enteroPositivo(string $s,int $max,string $codigo): int
    {
        if (!preg_match('/^[1-9][0-9]{0,2}$/D',$s) || (int)$s>$max) throw new InventarioError($codigo,'La cantidad del item no es válida.');
        return (int)$s;
    }
    private static function uno(\mysqli $db,string $sql,array $params): ?array
    {
        $stmt=$db->prepare($sql); if (!$stmt) throw new \RuntimeException($db->error); self::bind($stmt,str_repeat('i',count($params)),$params); self::ejecutar($stmt); $row=$stmt->get_result()->fetch_assoc()?:null; $stmt->close(); return $row;
    }
    private static function bind(\mysqli_stmt $stmt,string $types,array &$params): void
    {
        $refs=[]; foreach ($params as $i=>&$value) $refs[$i]=&$value; unset($value);
        if (!$stmt->bind_param($types,...$refs)) throw new \RuntimeException($stmt->error);
    }
    private static function ejecutar(\mysqli_stmt $stmt): void { if (!$stmt->execute()) throw new \RuntimeException($stmt->error); }
}
