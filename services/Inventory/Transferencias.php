<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Un solo flujo físico para los tres contextos. Véase docs/modulos/transferencias.md. */
final class Transferencias
{
    private AlcanceInventario $alcance;
    public function __construct(private \mysqli $db, int $actor, private string $nivel)
    {
        $this->alcance=new AlcanceInventario($db,$actor,$nivel);
    }

    public function ejecutar(string $ctx,string $accion,array $datos): array
    {
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$accion,$datos){
            $permiso=$this->alcance->autorizar($ctx,'editar',null,true);
            // «autorizar» se retiró: entre negocios el origen envía directamente lo solicitado.
            if(!in_array($accion,['solicitar','enviar','recibir','incidencia','retornar','rechazar','cancelar','confirmar'],true))throw new InventarioError('ACCION_INVALIDA','Acción de transferencia no válida.');
            $super=!empty($permiso['super']);
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
                $this->visible($ctx,(int)$r['transferencia_id'],$unidad,false,$super);
                return $r+['reintento'=>true];
            }
            if($accion==='solicitar'){
                $origen=self::id($datos['origen_id']??null);$destino=self::id($datos['destino_id']??null);
                if($origen===$destino)throw new InventarioError('MISMO_ALMACEN','Origen y destino deben ser distintos.');
                $alm=$this->almacenes($origen,$destino);
                $uO=(int)$alm[$origen]['unidad_id'];$uD=(int)$alm[$destino]['unidad_id'];
                if(!$super&&$uO!==$unidad&&$uD!==$unidad)throw new InventarioError('AMBITO_DENEGADO','La solicitud requiere un almacén propio.');
                $lineas=self::lineas($datos['lineas']??null);
                // El origen debe manejar el artículo: sin asociación no hay existencias que
                // enviar. En el destino, recibir implica manejarlo: si falta la asociación se
                // crea aquí (mínimo 0, sin saldo) con la unidad dueña del almacén, y el
                // resultado dice cuáles se crearon para que la interfaz lo avise.
                $asociados=[];
                foreach($lineas as $art=>$q){
                    if(!$this->uno('SELECT 1 FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?',[$origen,$art]))throw new InventarioError('ASOCIACION_NO_EXISTE','Un artículo no está asociado al almacén de origen: ahí no hay existencias que enviar.');
                    $this->asociacion($origen,$art,true);
                    if(!$this->uno('SELECT 1 FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? FOR UPDATE',[$destino,$art])){
                        $this->sql('INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,minimo) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE almacen_id=VALUES(almacen_id)',[$destino,$art,$uD]);
                        $asociados[]=$art;
                    }
                    $this->asociacion($destino,$art,true);
                }
                $this->sql('INSERT INTO transferencias(almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id) VALUES (?,?,?,?,?)',[$origen,$uO,$destino,$uD,$actor]);
                $id=(int)$this->db->insert_id;
                foreach($lineas as $art=>$q)$this->sql('INSERT INTO transferencia_lineas(transferencia_id,articulo_id,cantidad_solicitada) VALUES (?,?,?)',[$id,$art,DecimalInventario::cantidad($q)]);
                $anterior=null;$estado='solicitada';$version=0;
                /*
                 * Fases según quién y entre qué almacenes (arquitectura §5, transferencias.md):
                 * - Mismo negocio, o superadministrador en cualquier par: un solo movimiento,
                 *   salida y entrada en esta transacción; nace recibida.
                 * - Entre negocios, registrada por el dueño del origen: nace enviada y el
                 *   destino sólo confirma la recepción.
                 * - Entre negocios, pedida por el destino: queda solicitada hasta que el
                 *   origen la envía.
                 */
                $directa=$super||$uO===$uD;
                if($directa||$uO===$unidad){
                    $estado=$this->despachar($id,0,$uO,$origen,$uD,$destino,$motivo,$hash,$actor,$directa);
                    $version=1;
                    $this->sql('UPDATE transferencias SET estado=?,version=1,emisor_id=?,receptor_id=?,enviada_at=NOW(),recibida_at=IF(?,NOW(),NULL) WHERE id=?',[$estado,$actor,$directa?$actor:null,$directa?1:0,$id]);
                }
            }else{
                $id=self::id($datos['transferencia_id']??null);
                $t=$this->visible($ctx,$id,$unidad,true,false);
                if((string)self::version($datos['version']??null)!==(string)$t['version'])throw new InventarioError('VERSION_CONFLICTO','La transferencia cambió; recarga su estado.');
                $origen=(int)$t['almacen_origen_id'];$destino=(int)$t['almacen_destino_id'];
                $esOrigen=(int)$t['origen_unidad_id']===$unidad;$esDestino=(int)$t['destino_unidad_id']===$unidad;
                if($t['tipo']==='regularizacion'||$accion==='confirmar')return $this->regularizacion($t,$accion,$esOrigen,$motivo,$actor,$unidad,$clave);
                if((in_array($accion,['enviar','retornar'],true)&&!$esOrigen)||(in_array($accion,['recibir','incidencia'],true)&&!$esDestino)||(!$esOrigen&&!$esDestino))throw new InventarioError('AMBITO_DENEGADO','Esta acción corresponde al propietario del almacén físico.');
                // `autorizada` sólo existe en documentos anteriores al retiro de «autorizar»: se envían igual.
                $estados=['enviar'=>['solicitada','autorizada'],'recibir'=>['enviada','incidencia'],'incidencia'=>['enviada','incidencia'],'retornar'=>['incidencia'],'rechazar'=>['solicitada','autorizada'],'cancelar'=>['solicitada','autorizada']];
                if(!in_array($t['estado'],$estados[$accion],true))throw new InventarioError('ESTADO_INVALIDO','La transferencia no admite esa acción en su estado actual.');
                $this->almacenes($origen,$destino);
                if(isset($datos['lineas'])&&!in_array($accion,['recibir','retornar'],true))throw new InventarioError('DATOS_INVALIDOS','Esta acción no recibe cantidades.');
                if($accion==='enviar')$estado=$this->despachar($id,(int)$t['version'],$unidad,$origen,(int)$t['destino_unidad_id'],$destino,$motivo,$hash,$actor,false);
                $lineas=$accion==='enviar'?[]:$this->sql('SELECT * FROM transferencia_lineas WHERE transferencia_id=? ORDER BY articulo_id FOR UPDATE',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
                $cantidades=in_array($accion,['recibir','retornar'],true)?self::lineas($datos['lineas']??null):[];
                $ids=array_map('intval',array_column($lineas,'articulo_id'));
                if(array_diff(array_keys($cantidades),$ids))throw new InventarioError('LINEAS_INVALIDAS','Un artículo no pertenece a esta transferencia.');
                $op=null;
                if(in_array($accion,['recibir','retornar'],true)){
                    $ref='transferencia-'.$id.'-'.$accion.'-'.($t['version']+1);
                    $op=$this->operacion($unidad,'transferencia_entrada',$ref,$hash,$actor);
                }
                foreach($lineas as $l){
                    $art=(int)$l['articulo_id'];
                    if(isset($cantidades[$art])){
                        $q=$cantidades[$art];$pendiente=DecimalInventario::leer($l['cantidad_enviada'])-DecimalInventario::leer($l['cantidad_recibida'])-DecimalInventario::leer($l['cantidad_retornada']);
                        if($q>$pendiente)throw new InventarioError('CANTIDAD_EXCEDIDA','La cantidad supera lo pendiente en tránsito.');
                        $campo=$accion==='recibir'?'cantidad_recibida':'cantidad_retornada';
                        $w=$accion==='recibir'?$destino:$origen;
                        $salida=$accion==='retornar'?$this->uno("SELECT id FROM movimientos_inventario WHERE transferencia_linea_id=? AND tipo='transferencia_salida'",[$l['id']]):null;
                        $this->mover($op,$unidad,$w,$l,$q,$l['costo_unitario'],$salida['id']??null,$motivo);
                        $this->sql('UPDATE transferencia_lineas SET '.$campo.'='.$campo.'+? WHERE id=?',[DecimalInventario::cantidad($q),$l['id']]);
                    }
                }
                $estado=['enviar'=>'enviada','rechazar'=>'rechazada','cancelar'=>'cancelada'][$accion]??'incidencia';
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
            if(!empty($asociados)){
                $marcas=implode(',',array_fill(0,count($asociados),'?'));
                $r['asociados']=['almacen'=>(string)$alm[$destino]['nombre'],'articulos'=>array_column($this->sql("SELECT nombre FROM ingredientes WHERE id IN ({$marcas}) ORDER BY nombre",$asociados)->get_result()->fetch_all(MYSQLI_ASSOC),'nombre')];
            }
            $this->sql('UPDATE transferencia_comandos SET transferencia_id=?,resultado=? WHERE actor_id=? AND unidad_id=? AND clave=?',[$id,json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor,$unidad,$clave]);
            return $r+['reintento'=>false];
        });
    }

    /**
     * Regularización de un faltante (§16): el dueño del origen la confirma
     * (envío, recepción y consumo en esta transacción) o la rechaza con motivo.
     * No admite el flujo ordinario: se cancela sólo al cancelar el item vendido.
     */
    private function regularizacion(array $t,string $accion,bool $esOrigen,string $motivo,int $actor,int $unidad,string $clave): array
    {
        if($t['tipo']!=='regularizacion'||!in_array($accion,['confirmar','rechazar'],true))throw new InventarioError('ESTADO_INVALIDO','Una regularización de faltante sólo se confirma o se rechaza.');
        if(!$esOrigen)throw new InventarioError('AMBITO_DENEGADO','La confirma o rechaza el dueño del almacén de origen.');
        if($t['estado']!=='solicitada')throw new InventarioError('ESTADO_INVALIDO','La regularización ya no espera confirmación.');
        $this->almacenes((int)$t['almacen_origen_id'],(int)$t['almacen_destino_id']);
        $faltantes=new Faltantes($this->db,$actor,$this->nivel);
        if($accion==='confirmar'){
            $faltantes->confirmarRegularizacion($t);
        }else{
            $faltantes->rechazarRegularizacion($t,$motivo);
            $this->sql("UPDATE transferencias SET estado='rechazada',version=version+1 WHERE id=?",[$t['id']]);
            $this->sql('INSERT INTO transferencia_historial(transferencia_id,estado_anterior,estado_nuevo,actor_id,motivo) VALUES (?,?,?,?,?)',[$t['id'],$t['estado'],'rechazada',$actor,mb_substr('rechazar: '.$motivo,0,255)]);
        }
        $n=$this->uno('SELECT estado,version FROM transferencias WHERE id=?',[$t['id']]);
        $r=['codigo'=>'TRANSFERENCIA_CONFIRMADA','transferencia_id'=>(int)$t['id'],'estado'=>$n['estado'],'version'=>(int)$n['version']];
        $this->sql('UPDATE transferencia_comandos SET transferencia_id=?,resultado=? WHERE actor_id=? AND unidad_id=? AND clave=?',[$t['id'],json_encode($r,JSON_THROW_ON_ERROR),$actor,$unidad,$clave]);
        return $r+['reintento'=>false];
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
                    if($t['tipo']==='regularizacion'){if($propioO&&$t['estado']==='solicitada')array_push($t['acciones'],'confirmar','rechazar');continue;}
                    // Entre negocios: el origen envía o rechaza lo pedido; el destino recibe o
                    // cancela su pedido. Diferencia y retorno sólo aparecen si algo no llegó.
                    $pendiente=in_array($t['estado'],['solicitada','autorizada'],true);
                    if($propioO&&$pendiente)array_push($t['acciones'],'enviar','rechazar');
                    if($propioD&&$pendiente)$t['acciones'][]='cancelar';
                    if($propioD&&in_array($t['estado'],['enviada','incidencia'],true))array_push($t['acciones'],'recibir','incidencia');
                    if($propioO&&$t['estado']==='incidencia')$t['acciones'][]='retornar';
                }catch(InventarioError){}
            }unset($t);
            $opciones=$this->sql("SELECT a.id,a.nombre,u.slug contexto FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.activo=1 AND u.slug IN('global','restaurante','panaderia') ORDER BY a.unidad_id,a.nombre")->get_result()->fetch_all(MYSQLI_ASSOC);
            // Qué almacenes manejan ya cada artículo (sólo identidad, sin saldos): la
            // interfaz avisa antes de registrar si el destino quedará asociado.
            $asociaciones=[];
            foreach($this->sql('SELECT articulo_id,almacen_id FROM almacen_articulos ORDER BY articulo_id,almacen_id')->get_result()->fetch_all(MYSQLI_NUM) as [$art,$w])$asociaciones[(int)$art][]=(int)$w;
            return ['transferencias'=>$filas,'contrapartes'=>$opciones,'asociaciones_transferencia'=>$asociaciones,'transferencias_total'=>(int)$tot['total'],'transferencias_pendientes'=>(int)$tot['pendientes'],'transferencias_pagina'=>$pagina];
        },'REPEATABLE READ');
    }

    /**
     * Salida de todo lo solicitado del origen y, con $recibir, su entrada completa en el
     * destino en la misma transacción (transferencia en un solo paso). Cada movimiento va
     * con la unidad dueña de su almacén, aunque lo registre otro contexto (superadministrador).
     * Revalida saldo línea por línea: una que no alcance revierte el documento entero.
     */
    private function despachar(int $id,int $version,int $uO,int $origen,int $uD,int $destino,string $motivo,string $hash,int $actor,bool $recibir): string
    {
        $lineas=$this->sql('SELECT * FROM transferencia_lineas WHERE transferencia_id=? ORDER BY articulo_id FOR UPDATE',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        $paso=$version+1;
        $salida=$this->operacion($uO,'transferencia_salida','transferencia-'.$id.'-enviar-'.$paso,$hash,$actor);
        $entrada=$recibir?$this->operacion($uD,'transferencia_entrada','transferencia-'.$id.'-recibir-'.$paso,$hash,$actor):null;
        foreach($lineas as $l){
            $art=(int)$l['articulo_id'];$sol=DecimalInventario::leer($l['cantidad_solicitada']);
            $a=$this->asociacion($origen,$art,true);$this->asociacion($destino,$art,true);
            if(DecimalInventario::leer($a['saldo'],3,true)<$sol)throw new InventarioError('SALDO_INSUFICIENTE','El origen no dispone de la cantidad solicitada.');
            $this->mover($salida,$uO,$origen,$l,-$sol,$a['costo'],null,$motivo);
            if($entrada!==null)$this->mover($entrada,$uD,$destino,$l,$sol,$a['costo'],null,$motivo);
            $this->sql('UPDATE transferencia_lineas SET cantidad_enviada=cantidad_solicitada,cantidad_recibida=IF(?,cantidad_solicitada,cantidad_recibida),costo_unitario=? WHERE id=?',[$recibir?1:0,$a['costo'],$l['id']]);
        }
        $estado=$recibir?'recibida':'enviada';
        $resultado=json_encode(['transferencia_id'=>$id,'estado'=>$estado],JSON_THROW_ON_ERROR);
        foreach(array_filter([$salida,$entrada]) as $op)$this->sql('UPDATE operaciones_inventario SET resultado=? WHERE id=?',[$resultado,$op]);
        return $estado;
    }

    private function operacion(int $unidad,string $tipo,string $ref,string $hash,int $actor): int
    {
        $this->sql('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (?,?,\'usuario\',?,\'transferencias\',?,?,?)',[$unidad,$tipo,$actor,$ref,$hash,$ref]);
        return (int)$this->db->insert_id;
    }

    /** El superadministrador puede reintentar desde otro contexto una transferencia que registró en un paso. */
    private function visible(string $ctx,int $id,int $unidad,bool $lock=false,bool $super=false): array
    {
        $t=$this->uno('SELECT * FROM transferencias WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if(!$t||(!$super&&$ctx!=='global'&&(int)$t['origen_unidad_id']!==$unidad&&(int)$t['destino_unidad_id']!==$unidad))throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró la transferencia autorizada.');
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
