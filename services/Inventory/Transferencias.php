<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Un solo flujo físico para los tres contextos. Véase docs/modulos/transferencias.md. */
final class Transferencias
{
    private AlcanceInventario $alcance;
    public function __construct(private \mysqli $db, int $actor, string $nivel)
    {
        $this->alcance=new AlcanceInventario($db,$actor,$nivel);
    }

    public function ejecutar(string $ctx,string $accion,array $datos): array
    {
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$accion,$datos){
            $permiso=$this->alcance->autorizar($ctx,'editar',null,true);
            if(!in_array($accion,['solicitar','autorizar','enviar','recibir','incidencia','retornar','rechazar','cancelar'],true))throw new InventarioError('ACCION_INVALIDA','Acción de transferencia no válida.');
            $permitidas=$accion==='solicitar'?['clave','origen_id','destino_id','lineas','motivo']:['clave','transferencia_id','version','lineas','motivo'];
            if(array_diff(array_keys($datos),$permitidas))throw new InventarioError('DATOS_INVALIDOS','Campos de transferencia no permitidos.');
            $clave=self::texto($datos['clave']??null,120);
            $motivo=self::texto($datos['motivo']??null,255);
            $hash=hash('sha256',json_encode([$accion,$datos],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $actor=$this->alcance->actorId();$unidad=$permiso['unidad_id'];
            $this->sql('INSERT INTO transferencia_comandos(actor_id,unidad_id,clave,hash_solicitud) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE clave=VALUES(clave)',[$actor,$unidad,$clave,$hash]);
            $cmd=$this->uno('SELECT * FROM transferencia_comandos WHERE actor_id=? AND unidad_id=? AND clave=? FOR UPDATE',[$actor,$unidad,$clave]);
            if(!hash_equals($cmd['hash_solicitud'],$hash))throw new InventarioError('CONFLICTO_IDEMPOTENCIA','La clave ya pertenece a otra petición.');
            if($cmd['resultado']!==null){
                $r=json_decode($cmd['resultado'],true,512,JSON_THROW_ON_ERROR);
                // Releer autorización del recurso incluso en replay tras revocaciones.
                $this->visible($ctx,(int)$r['transferencia_id'],$unidad);
                return $r+['reintento'=>true];
            }
            if($accion==='solicitar'){
                $origen=self::id($datos['origen_id']??null);$destino=self::id($datos['destino_id']??null);
                if($origen===$destino)throw new InventarioError('MISMO_ALMACEN','Origen y destino deben ser distintos.');
                $alm=$this->almacenes($origen,$destino);
                if($alm[$origen]['unidad_id']!=$unidad&&$alm[$destino]['unidad_id']!=$unidad)throw new InventarioError('AMBITO_DENEGADO','La solicitud requiere un almacén propio.');
                $lineas=self::lineas($datos['lineas']??null);
                foreach($lineas as $art=>$q){
                    foreach([$origen,$destino] as $w)$this->asociacion($w,$art,true);
                }
                $this->sql('INSERT INTO transferencias(almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id) VALUES (?,?,?,?,?)',[$origen,$alm[$origen]['unidad_id'],$destino,$alm[$destino]['unidad_id'],$actor]);
                $id=(int)$this->db->insert_id;
                foreach($lineas as $art=>$q)$this->sql('INSERT INTO transferencia_lineas(transferencia_id,articulo_id,cantidad_solicitada) VALUES (?,?,?)',[$id,$art,DecimalInventario::cantidad($q)]);
                $anterior=null;$estado='solicitada';$version=0;
            }else{
                $id=self::id($datos['transferencia_id']??null);
                $t=$this->visible($ctx,$id,$unidad,true);
                if((string)self::version($datos['version']??null)!==(string)$t['version'])throw new InventarioError('VERSION_CONFLICTO','La transferencia cambió; recarga su estado.');
                $origen=(int)$t['almacen_origen_id'];$destino=(int)$t['almacen_destino_id'];
                $esOrigen=(int)$t['origen_unidad_id']===$unidad;$esDestino=(int)$t['destino_unidad_id']===$unidad;
                if((in_array($accion,['autorizar','enviar','retornar'],true)&&!$esOrigen)||(in_array($accion,['recibir','incidencia'],true)&&!$esDestino)||(!$esOrigen&&!$esDestino))throw new InventarioError('AMBITO_DENEGADO','Esta acción corresponde al propietario del almacén físico.');
                $estados=['autorizar'=>['solicitada'],'enviar'=>['autorizada'],'recibir'=>['enviada','incidencia'],'incidencia'=>['enviada','incidencia'],'retornar'=>['incidencia'],'rechazar'=>['solicitada','autorizada'],'cancelar'=>['solicitada','autorizada']];
                if(!in_array($t['estado'],$estados[$accion],true))throw new InventarioError('ESTADO_INVALIDO','La transferencia no admite esa acción en su estado actual.');
                $this->almacenes($origen,$destino);
                $lineas=$this->sql('SELECT * FROM transferencia_lineas WHERE transferencia_id=? ORDER BY articulo_id FOR UPDATE',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
                $cantidades=in_array($accion,['recibir','retornar'],true)?self::lineas($datos['lineas']??null):[];
                if(!in_array($accion,['recibir','retornar'],true)&&isset($datos['lineas']))throw new InventarioError('DATOS_INVALIDOS','Esta acción no recibe cantidades.');
                $ids=array_map('intval',array_column($lineas,'articulo_id'));
                if(array_diff(array_keys($cantidades),$ids))throw new InventarioError('LINEAS_INVALIDAS','Un artículo no pertenece a esta transferencia.');
                $op=null;
                if(in_array($accion,['enviar','recibir','retornar'],true)){
                    $tipo=$accion==='enviar'?'transferencia_salida':'transferencia_entrada';
                    $ref='transferencia-'.$id.'-'.$accion.'-'.($t['version']+1);
                    $this->sql('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (?,?,\'usuario\',?,\'transferencias\',?,?,?)',[$unidad,$tipo,$actor,$ref,$hash,$ref]);
                    $op=(int)$this->db->insert_id;
                }
                foreach($lineas as $l){
                    $art=(int)$l['articulo_id'];$sol=DecimalInventario::leer($l['cantidad_solicitada']);
                    if(in_array($accion,['autorizar','enviar'],true)){
                        $a=$this->asociacion($origen,$art,true);$this->asociacion($destino,$art,true);
                        if(DecimalInventario::leer($a['saldo'],3,true)<$sol)throw new InventarioError('SALDO_INSUFICIENTE','El origen no dispone de la cantidad solicitada.');
                        if($accion==='enviar'){
                            $this->mover($op,$unidad,$origen,$l,-$sol,$a['costo'],null,$motivo);
                            $this->sql('UPDATE transferencia_lineas SET cantidad_enviada=cantidad_solicitada,costo_unitario=? WHERE id=?',[$a['costo'],$l['id']]);
                        }
                    }elseif(isset($cantidades[$art])){
                        $q=$cantidades[$art];$pendiente=DecimalInventario::leer($l['cantidad_enviada'])-DecimalInventario::leer($l['cantidad_recibida'])-DecimalInventario::leer($l['cantidad_retornada']);
                        if($q>$pendiente)throw new InventarioError('CANTIDAD_EXCEDIDA','La cantidad supera lo pendiente en tránsito.');
                        $campo=$accion==='recibir'?'cantidad_recibida':'cantidad_retornada';
                        $w=$accion==='recibir'?$destino:$origen;
                        $salida=$accion==='retornar'?$this->uno("SELECT id FROM movimientos_inventario WHERE transferencia_linea_id=? AND tipo='transferencia_salida'",[$l['id']]):null;
                        $this->mover($op,$unidad,$w,$l,$q,$l['costo_unitario'],$salida['id']??null,$motivo);
                        $this->sql('UPDATE transferencia_lineas SET '.$campo.'='.$campo.'+? WHERE id=?',[DecimalInventario::cantidad($q),$l['id']]);
                    }
                }
                $estado=['autorizar'=>'autorizada','enviar'=>'enviada','rechazar'=>'rechazada','cancelar'=>'cancelada'][$accion]??'incidencia';
                if(in_array($accion,['recibir','retornar'],true)){
                    $tot=$this->uno('SELECT SUM(cantidad_enviada-cantidad_recibida-cantidad_retornada) pendiente,SUM(cantidad_retornada) retorno FROM transferencia_lineas WHERE transferencia_id=?',[$id]);
                    if($tot['pendiente']==='0.000')$estado=$tot['retorno']==='0.000'?'recibida':'cerrada';
                }
                $anterior=$t['estado'];$version=(int)$t['version']+1;
                $this->sql('UPDATE transferencias SET estado=?,version=?,emisor_id=IF(?=\'enviar\',?,emisor_id),receptor_id=IF(?=\'recibir\',?,receptor_id),enviada_at=IF(?=\'enviar\',NOW(),enviada_at),recibida_at=IF(? IN(\'recibida\',\'cerrada\'),NOW(),recibida_at) WHERE id=?',[$estado,$version,$accion,$actor,$accion,$actor,$accion,$estado,$id]);
                if($op!==null)$this->sql('UPDATE operaciones_inventario SET resultado=? WHERE id=?',[json_encode(['transferencia_id'=>$id,'estado'=>$estado],JSON_THROW_ON_ERROR),$op]);
            }
            $this->sql('INSERT INTO transferencia_historial(transferencia_id,estado_anterior,estado_nuevo,actor_id,motivo) VALUES (?,?,?,?,?)',[$id,$anterior,$estado,$actor,mb_substr($accion.': '.$motivo,0,255)]);
            $r=['codigo'=>'TRANSFERENCIA_CONFIRMADA','transferencia_id'=>$id,'estado'=>$estado,'version'=>$version];
            $this->sql('UPDATE transferencia_comandos SET transferencia_id=?,resultado=? WHERE actor_id=? AND unidad_id=? AND clave=?',[$id,json_encode($r,JSON_THROW_ON_ERROR),$actor,$unidad,$clave]);
            return $r+['reintento'=>false];
        });
    }

    /** Cabeceras y cantidades documentales; nunca expone saldo ajeno. */
    public function consultar(string $ctx,int $pagina=1): array
    {
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$pagina){
            $u=$this->alcance->autorizar($ctx,'ver',null,true)['unidad_id'];
            if($pagina<1||$pagina>100000)throw new InventarioError('FILTRO_INVALIDO','Página de transferencias inválida.');
            $where=$ctx==='global'?'':'WHERE t.origen_unidad_id=? OR t.destino_unidad_id=? ';
            $params=$ctx==='global'?[]:[$u,$u];
            $tot=$this->uno("SELECT COUNT(*) total,COALESCE(SUM(t.estado IN('solicitada','autorizada','enviada','incidencia')),0) pendientes FROM transferencias t ".$where,$params);
            $filas=$this->sql('SELECT t.*,o.nombre origen_nombre,d.nombre destino_nombre,uo.slug origen_contexto,ud.slug destino_contexto FROM transferencias t JOIN almacenes o ON o.id=t.almacen_origen_id JOIN almacenes d ON d.id=t.almacen_destino_id JOIN unidades uo ON uo.id=t.origen_unidad_id JOIN unidades ud ON ud.id=t.destino_unidad_id '.$where."ORDER BY (t.estado IN('solicitada','autorizada','enviada','incidencia')) DESC,t.id DESC LIMIT 100 OFFSET ?",array_merge($params,[($pagina-1)*100]))->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($filas as &$t){
                $t['lineas']=$this->sql('SELECT l.*,i.nombre,i.unidad FROM transferencia_lineas l JOIN ingredientes i ON i.id=l.articulo_id WHERE transferencia_id=? ORDER BY l.articulo_id',[$t['id']])->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach($t['lineas'] as &$l)$l['transito']=DecimalInventario::cantidad(DecimalInventario::leer($l['cantidad_enviada'])-DecimalInventario::leer($l['cantidad_recibida'])-DecimalInventario::leer($l['cantidad_retornada']));unset($l);
                $t['historial']=$this->sql('SELECT h.*,u.nombre actor_nombre FROM transferencia_historial h LEFT JOIN usuarios u ON u.id=h.actor_id WHERE transferencia_id=? ORDER BY h.id',[$t['id']])->get_result()->fetch_all(MYSQLI_ASSOC);
                $t['acciones']=[];
                try{$this->alcance->autorizar($ctx,'editar');$propioO=(int)$t['origen_unidad_id']===$u;$propioD=(int)$t['destino_unidad_id']===$u;
                    if($propioO&&$t['estado']==='solicitada')$t['acciones'][]='autorizar';
                    if($propioO&&$t['estado']==='autorizada')$t['acciones'][]='enviar';
                    if($propioD&&in_array($t['estado'],['enviada','incidencia'],true))$t['acciones'][]='recibir';
                    if($propioD&&in_array($t['estado'],['enviada','incidencia'],true))$t['acciones'][]='incidencia';
                    if($propioO&&$t['estado']==='incidencia')$t['acciones'][]='retornar';
                    if(($propioO||$propioD)&&in_array($t['estado'],['solicitada','autorizada'],true))array_push($t['acciones'],'rechazar','cancelar');
                }catch(InventarioError){}
            }unset($t);
            $opciones=$this->sql("SELECT a.id,a.nombre,u.slug contexto FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.activo=1 AND u.slug IN('global','restaurante','panaderia') ORDER BY a.unidad_id,a.nombre")->get_result()->fetch_all(MYSQLI_ASSOC);
            return ['transferencias'=>$filas,'contrapartes'=>$opciones,'transferencias_total'=>(int)$tot['total'],'transferencias_pendientes'=>(int)$tot['pendientes'],'transferencias_pagina'=>$pagina];
        },'REPEATABLE READ');
    }

    private function visible(string $ctx,int $id,int $unidad,bool $lock=false): array
    {
        $t=$this->uno('SELECT * FROM transferencias WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if(!$t||($ctx!=='global'&&(int)$t['origen_unidad_id']!==$unidad&&(int)$t['destino_unidad_id']!==$unidad))throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró la transferencia autorizada.');
        return $t;
    }
    private function almacenes(int $o,int $d): array
    {
        $r=$this->sql("SELECT a.* FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.id IN(?,?) AND u.slug IN('global','restaurante','panaderia') ORDER BY a.id FOR UPDATE",[$o,$d])->get_result()->fetch_all(MYSQLI_ASSOC);$a=[];
        foreach($r as $w){if(!(int)$w['activo'])throw new InventarioError('ALMACEN_INACTIVO','Un almacén está inactivo.');$a[(int)$w['id']]=$w;}
        if(count($a)!==2)throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontraron ambos almacenes.');
        return $a;
    }
    private function asociacion(int $w,int $art,bool $activa): array
    {
        $r=$this->uno('SELECT aa.*,i.costo,i.activo articulo_activo FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=? AND aa.articulo_id=? FOR UPDATE',[$w,$art]);
        if(!$r)throw new InventarioError('ASOCIACION_NO_EXISTE','El artículo debe estar asociado a ambos almacenes.');
        if($activa&&(!(int)$r['activo']||!(int)$r['articulo_activo']))throw new InventarioError('ASOCIACION_INACTIVA','El artículo o su asociación están inactivos.');
        return $r;
    }
    private function mover(int $op,int $unidad,int $w,array $l,int $delta,?string $costo,?int $original,string $motivo): void
    {
        $a=$this->asociacion($w,(int)$l['articulo_id'],true);
        $saldo=DecimalInventario::cantidad(DecimalInventario::sumar(DecimalInventario::leer($a['saldo'],3,true),$delta));
        $this->sql('UPDATE almacen_articulos SET saldo=?,version=version+1 WHERE almacen_id=? AND articulo_id=?',[$saldo,$w,$l['articulo_id']]);
        $this->sql('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,motivo,costo_unitario,movimiento_origen_id,transferencia_linea_id) VALUES (?,?,?,?,?,?,?,?,?,?)',[$op,$unidad,$w,$l['articulo_id'],$delta<0?'transferencia_salida':'transferencia_entrada',DecimalInventario::cantidad($delta),'Transferencia: '.mb_substr($motivo,0,60),$costo,$original,$l['id']]);
    }
    private static function lineas(mixed $v): array
    {
        if(!is_array($v)||!array_is_list($v)||count($v)<1||count($v)>100)throw new InventarioError('LINEAS_INVALIDAS','Indica de una a cien líneas.');
        $r=[];foreach($v as $l){if(!is_array($l)||array_diff(array_keys($l),['articulo_id','cantidad']))throw new InventarioError('LINEAS_INVALIDAS','Línea inválida.');$art=self::id($l['articulo_id']??null);$q=DecimalInventario::leer($l['cantidad']??null);if($q<=0||isset($r[$art]))throw new InventarioError('LINEAS_INVALIDAS','Cantidad positiva y un renglón por artículo.');$r[$art]=$q;}ksort($r,SORT_NUMERIC);return $r;
    }
    private static function texto(mixed $v,int $max): string {if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max||str_contains($v,"\0"))throw new InventarioError('DATOS_INVALIDOS','Indica clave y motivo con longitud válida.');return trim($v);}
    private static function id(mixed $v): int {if((!is_int($v)&&!is_string($v))||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$v))throw new InventarioError('ID_INVALIDO','Identificador inválido.');return (int)$v;}
    private static function version(mixed $v): int {if((!is_int($v)&&!is_string($v))||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$v))throw new InventarioError('VERSION_INVALIDA','Versión inválida.');return (int)$v;}
    private function sql(string $sql,array $p=[]): \mysqli_stmt {$s=$this->db->prepare($sql);$s->execute($p);return $s;}
    private function uno(string $sql,array $p=[]): ?array {return $this->sql($sql,$p)->get_result()->fetch_assoc();}
}
