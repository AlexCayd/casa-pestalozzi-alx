<?php
declare(strict_types=1);

namespace Services\Inventory;

/** Resuelve snapshots comerciales a líneas internas del motor 3C. */
final class ConsumoOferta
{
    private const ESCALA = 18;

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
        $cantidadTicket = self::enteroPositivo((string)$item['cantidad'], 255, 'CANTIDAD_INVALIDA');
        $ofertaId = (int)$item['oferta_id'];
        $componenteBase = [];
        $tieneReceta = $item['receta_id'] !== null;

        if ($tieneReceta) {
            $recetaId = (int)$item['receta_id'];
            $receta = self::uno($db, 'SELECT id FROM recetas WHERE id=? AND oferta_id=? AND unidad_id=? LIMIT 1 FOR SHARE', [$recetaId,$ofertaId,$unidadId]);
            if (!$receta) throw new InventarioError('RECETA_INVALIDA', 'La versión de receta del item no pertenece a su oferta.');
            $stmt = $db->prepare('SELECT articulo_id,subreceta_id,cantidad FROM receta_componentes WHERE receta_id=? AND unidad_id=? ORDER BY id FOR SHARE');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$recetaId,$unidadId);
            self::ejecutar($stmt);
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $tipo = $row['articulo_id'] !== null ? 'ingrediente' : 'subreceta';
                $ref = (int)($row['articulo_id'] ?? $row['subreceta_id']);
                self::sumarComponente($componenteBase,$tipo,$ref,self::cantidadInterna((string)$row['cantidad']));
            }
            $stmt->close();
            if ($componenteBase === []) throw new InventarioError('RECETA_INCOMPLETA', 'El item tiene una versión de receta sin componentes.');
        }

        $ids = self::idsVariantes($item['variante_ids'] === null ? '' : (string)$item['variante_ids']);
        $opciones = [];
        if ($ids !== []) {
            $in = implode(',',array_fill(0,count($ids),'?'));
            $stmt = $db->prepare("SELECT id,grupo FROM producto_variantes WHERE oferta_id=? AND unidad_id=? AND id IN ({$in}) ORDER BY id FOR SHARE");
            if (!$stmt) throw new \RuntimeException($db->error);
            $types = 'ii' . str_repeat('i',count($ids));
            $params = array_merge([$ofertaId,$unidadId],$ids);
            self::bind($stmt,$types,$params);
            self::ejecutar($stmt);
            $result = $stmt->get_result();
            while ($row=$result->fetch_assoc()) $opciones[(int)$row['id']] = (string)$row['grupo'];
            $stmt->close();
            if (count($opciones) !== count($ids)) throw new InventarioError('VARIANTE_INVALIDA', 'Una variante del snapshot no pertenece a la oferta.');
            if (count(array_unique(array_values($opciones))) !== count($opciones)) throw new InventarioError('VARIANTE_INVALIDA', 'El snapshot contiene más de una opción del mismo grupo.');
        }

        $actual = $componenteBase;
        if ($ids !== []) {
            $in = implode(',',array_fill(0,count($ids),'?'));
            $stmt = $db->prepare(
                "SELECT va.variante_id,va.articulo_id,va.subreceta_id,va.cantidad,va.accion,va.articulo_nuevo_id,va.subreceta_nueva_id,va.nueva_cantidad "
                . "FROM variante_ajustes va JOIN producto_variantes pv ON pv.id=va.variante_id AND pv.oferta_id=va.oferta_id AND pv.unidad_id=va.unidad_id "
                . "WHERE va.oferta_id=? AND va.unidad_id=? AND va.variante_id IN ({$in}) ORDER BY va.id FOR SHARE"
            );
            if (!$stmt) throw new \RuntimeException($db->error);
            $types='ii'.str_repeat('i',count($ids)); $params=array_merge([$ofertaId,$unidadId],$ids);
            self::bind($stmt,$types,$params); self::ejecutar($stmt);
            $ajustes=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $sustituciones=[];
            foreach ($ajustes as $aj) {
                $tipo=$aj['articulo_id']!==null?'ingrediente':'subreceta';
                $ref=(int)($aj['articulo_id']??$aj['subreceta_id']);
                $clave=self::clave($tipo,$ref); $monto=self::cantidadInterna((string)$aj['cantidad']);
                if ($aj['accion']==='sustituir') { $sustituciones[]=$aj; continue; }
                $antes=$actual[$clave]??self::cero();
                $actual[$clave]=$aj['accion']==='quitar'?self::maxCero(bcsub($antes,$monto,self::ESCALA)):bcadd($antes,$monto,self::ESCALA);
            }
            foreach ($sustituciones as $aj) {
                $tipo=$aj['articulo_id']!==null?'ingrediente':'subreceta';
                $ref=(int)($aj['articulo_id']??$aj['subreceta_id']);
                $nuevoTipo=$aj['articulo_nuevo_id']!==null?'ingrediente':($aj['subreceta_nueva_id']!==null?'subreceta':null);
                $nuevoRef=(int)($aj['articulo_nuevo_id']??$aj['subreceta_nueva_id']??0);
                if ($nuevoTipo===null || $nuevoRef<1 || ($nuevoTipo===$tipo && $nuevoRef===$ref)) throw new InventarioError('VARIANTE_INVALIDA','La sustitución del snapshot no es válida.');
                $cantidad=self::cantidadInterna((string)$aj['cantidad']);
                $nueva=self::cantidadInterna((string)$aj['nueva_cantidad']);
                $clave=self::clave($tipo,$ref); $base=$componenteBase[$clave]??self::cero(); $presente=$actual[$clave]??self::cero();
                $venta=bccomp($base,'0',self::ESCALA)>0
                    ? bcmul($presente,bccomp($cantidad,$base,self::ESCALA)>=0?'1':bcdiv($cantidad,$base,self::ESCALA),self::ESCALA)
                    : $cantidad;
                $entra=bccomp($cantidad,'0',self::ESCALA)>0?bcmul($venta,bcdiv($nueva,$cantidad,self::ESCALA),self::ESCALA):self::cero();
                $actual[$clave]=self::maxCero(bcsub($presente,$venta,self::ESCALA));
                $claveNueva=self::clave($nuevoTipo,$nuevoRef);
                $actual[$claveNueva]=bcadd($actual[$claveNueva]??self::cero(),$entra,self::ESCALA);
            }
        }

        $porArticulo=[];
        foreach ($actual as $clave=>$monto) {
            if (bccomp($monto,'0',self::ESCALA)<=0) continue;
            [$tipo,$refTexto]=explode(':',$clave,2); $ref=(int)$refTexto;
            if ($tipo==='ingrediente') self::sumarArticulo($porArticulo,$ref,$monto);
            else self::explotarSubreceta($db,$unidadId,$ref,$monto,$porArticulo);
        }
        if ($porArticulo===[]) {
            return ['consume'=>false,'lineas'=>[],'costo_unitario'=>null];
        }

        ksort($porArticulo,SORT_NUMERIC);
        $articulos=self::articulos($db,array_keys($porArticulo));
        $almacenes=self::almacenesOferta($db,$ofertaId,$unidadId,array_keys($porArticulo));
        $lineas=[]; $costoTotal=self::cero();
        foreach ($porArticulo as $articuloId=>$porcion) {
            if (!isset($articulos[$articuloId])) throw new InventarioError('ARTICULO_INACTIVO','La receta referencia un artículo inexistente o inactivo.');
            if (!isset($almacenes[$articuloId])) throw new InventarioError('ALMACEN_ORIGEN_FALTANTE','La oferta no tiene almacén de consumo para un artículo de su receta.');
            $cantidadTicketExacta=bcmul($porcion,(string)$cantidadTicket,self::ESCALA);
            $cantidadFinal=self::redondearCantidad($cantidadTicketExacta);
            $costo=self::costoCuatro((string)$articulos[$articuloId]['costo']);
            $costoTotal=bcadd($costoTotal,bcmul($porcion,$costo,self::ESCALA),self::ESCALA);
            $lineas[]=['almacen_id'=>(int)$almacenes[$articuloId]['almacen_id'],'articulo_id'=>(int)$articuloId,'cantidad'=>$cantidadFinal,'costo_unitario'=>$costo];
        }
        return ['consume'=>true,'lineas'=>$lineas,'costo_unitario'=>self::redondearCosto($costoTotal)];
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

    private static function explotarSubreceta(\mysqli $db,int $unidadId,int $id,string $monto,array &$porArticulo): void
    {
        $sub=self::uno($db,'SELECT id,rendimiento,activo FROM subrecetas WHERE id=? AND unidad_id=? LIMIT 1 FOR SHARE',[$id,$unidadId]);
        if (!$sub || !(int)$sub['activo']) throw new InventarioError('SUBRECETA_INVALIDA','La receta utiliza una subreceta inexistente, ajena o inactiva.');
        $rendimiento=self::cantidadInterna((string)$sub['rendimiento']);
        if (bccomp($rendimiento,'0',self::ESCALA)<=0) throw new InventarioError('SUBRECETA_INCOMPLETA','La subreceta no tiene un rendimiento válido.');
        $stmt=$db->prepare('SELECT articulo_id,cantidad FROM subreceta_ingredientes WHERE subreceta_id=? AND unidad_id=? ORDER BY id FOR SHARE');
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('ii',$id,$unidadId); self::ejecutar($stmt); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        if ($rows===[]) throw new InventarioError('SUBRECETA_INCOMPLETA','La subreceta no tiene componentes inventariables.');
        foreach ($rows as $row) {
            $q=bcmul($monto,self::cantidadInterna((string)$row['cantidad']),self::ESCALA);
            $q=bcdiv($q,$rendimiento,self::ESCALA);
            self::sumarArticulo($porArticulo,(int)$row['articulo_id'],$q);
        }
    }

    private static function articulos(\mysqli $db,array $ids): array
    {
        if ($ids===[]) return [];
        $in=implode(',',array_fill(0,count($ids),'?')); $stmt=$db->prepare("SELECT id,costo,activo FROM ingredientes WHERE id IN ({$in}) ORDER BY id FOR SHARE");
        if (!$stmt) throw new \RuntimeException($db->error);
        self::bind($stmt,str_repeat('i',count($ids)),$ids); self::ejecutar($stmt); $out=[];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) if ((int)$row['activo']===1) $out[(int)$row['id']]=$row;
        $stmt->close(); return $out;
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

    private static function sumarComponente(array &$map,string $tipo,int $id,string $cantidad): void
    {
        if (!in_array($tipo,['ingrediente','subreceta'],true) || $id<1) throw new InventarioError('RECETA_INCOMPLETA','Hay un componente inválido en la receta.');
        $key=self::clave($tipo,$id); $map[$key]=bcadd($map[$key]??self::cero(),$cantidad,self::ESCALA);
    }
    private static function sumarArticulo(array &$map,int $id,string $cantidad): void
    {
        if ($id<1) throw new InventarioError('ARTICULO_INVALIDO','La receta referencia un artículo inválido.');
        $map[$id]=bcadd($map[$id]??self::cero(),$cantidad,self::ESCALA);
    }
    private static function clave(string $tipo,int $id): string { return $tipo.':'.$id; }
    private static function cantidadInterna(string $s): string { return bcadd(DecimalInventario::cantidad(DecimalInventario::leer($s)),self::cero(),self::ESCALA); }
    private static function cero(): string { return '0.' . str_repeat('0',self::ESCALA); }
    private static function maxCero(string $v): string { return bccomp($v,'0',self::ESCALA)<0?self::cero():$v; }

    private static function redondearCantidad(string $cantidad): string
    {
        $sumado=bcadd($cantidad,'0.000500000000000000',self::ESCALA);
        $miles=bcmul($sumado,'1000',0);
        if (bccomp($miles,(string)DecimalInventario::MAX_CANTIDAD,0)>0) throw new InventarioError('DECIMAL_FUERA_RANGO','El consumo supera DECIMAL(12,3).');
        $escalado=(int)$miles;
        if (bccomp($cantidad,'0',self::ESCALA)>0 && $escalado===0) throw new InventarioError('CANTIDAD_DEMASIADO_PEQUENA','El consumo positivo es menor a la precisión de inventario.');
        return DecimalInventario::cantidad($escalado);
    }
    private static function costoCuatro(string $costo): string { return DecimalInventario::formato(DecimalInventario::leer($costo,4),4); }
    private static function redondearCosto(string $costo): string
    {
        $sumado=bcadd($costo,'0.000050000000000000',self::ESCALA);
        $unidades=bcmul($sumado,'10000',0);
        if (bccomp($unidades,'9999999999',0)>0) throw new InventarioError('DECIMAL_FUERA_RANGO','El costo unitario supera DECIMAL(10,4).');
        return DecimalInventario::formato(DecimalInventario::leer(DecimalInventario::formato((int)$unidades,4),4),4);
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
