<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Motor multiunidad. No consulta recetas, tickets ni el stock mononegocio. */
final class Almacenes
{
    private AlcanceInventario $alcance;

    public function __construct(private \mysqli $db, int $actorId, string $authNivel)
    {
        $this->alcance = new AlcanceInventario($db, $actorId, $authNivel);
    }

    public function crearAlmacen(string $ctx, string $propietario, string $nombre, bool $permitirNegativos = false): array
    {
        return $this->escribir(function () use ($ctx,$propietario,$nombre,$permitirNegativos) {
            $this->global($ctx, 'almacenes.crear');
            $unidad = $this->unidad($propietario);
            $nombre = self::texto($nombre,100);
            $this->sql('INSERT INTO almacenes(unidad_id,nombre,permitir_negativos) VALUES (?,?,?)', [$unidad,$nombre,(int)$permitirNegativos]);
            return ['codigo'=>'ALMACEN_CREADO','almacen_id'=>(int)$this->db->insert_id];
        });
    }

    public function configurarAlmacen(string $ctx, int $id, array $datos): array
    {
        return $this->escribir(function () use ($ctx,$id,$datos) {
            self::claves($datos,['nombre','permitir_negativos','activo']);
            $a=$this->alcance->almacen($ctx,$id,'editar',true,false);
            $nombre=array_key_exists('nombre',$datos)?self::texto($datos['nombre'],100):$a['nombre'];
            $activo=array_key_exists('activo',$datos)?self::booleano($datos['activo']):(int)$a['activo'];
            $negativos=array_key_exists('permitir_negativos',$datos)?self::booleano($datos['permitir_negativos']):(int)$a['permitir_negativos'];
            if (!$negativos && $this->uno('SELECT 1 FROM almacen_articulos WHERE almacen_id=? AND saldo<0 LIMIT 1 FOR UPDATE',[$id])) throw new InventarioError('NEGATIVOS_PENDIENTES','Hay existencias negativas pendientes.');
            if (!$activo) $this->sinPendientes($id);
            $this->sql('UPDATE almacenes SET nombre=?,activo=?,permitir_negativos=? WHERE id=?',[$nombre,$activo,$negativos,$id]);
            return ['codigo'=>'ALMACEN_CONFIGURADO','almacen_id'=>$id];
        });
    }

    public function eliminarAlmacen(string $ctx, int $id): array
    {
        return $this->escribir(function () use ($ctx,$id) {
            $this->alcance->almacen($ctx,$id,'editar',true,false);
            $this->sinPendientes($id);
            if ($this->uno('SELECT 1 FROM movimientos_inventario WHERE almacen_id=? LIMIT 1 FOR SHARE',[$id])
                || $this->uno('SELECT 1 FROM transferencias WHERE almacen_origen_id=? OR almacen_destino_id=? LIMIT 1 FOR SHARE',[$id,$id])) {
                throw new InventarioError('ALMACEN_CON_HISTORIAL','Un almacén con historial no puede eliminarse.');
            }
            $this->sql('DELETE FROM almacen_articulos WHERE almacen_id=?',[$id]);
            $this->sql('DELETE FROM almacenes WHERE id=?',[$id]);
            return ['codigo'=>'ALMACEN_ELIMINADO','almacen_id'=>$id];
        });
    }

    public function crearArticulo(string $ctx, array $datos): array
    {
        return $this->escribir(function () use ($ctx,$datos) {
            $this->global($ctx,'catalogo.editar');
            self::claves($datos,['nombre','unidad','costo','activo']);
            $nombre=self::texto($datos['nombre']??null,120);
            $unidad=self::medida($datos['unidad']??null);
            $costo=DecimalInventario::formato(DecimalInventario::leer($datos['costo']??'0.0000',4),4);
            $activo=array_key_exists('activo',$datos)?self::booleano($datos['activo']):1;
            try { $this->sql('INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,?,?,?)',[$nombre,$unidad,$costo,$activo]); }
            catch (\mysqli_sql_exception $e) { if($e->getCode()===1062) throw new InventarioError('ARTICULO_DUPLICADO','El artículo ya existe en el catálogo maestro.'); throw $e; }
            return ['codigo'=>'ARTICULO_CREADO','articulo_id'=>(int)$this->db->insert_id];
        });
    }

    public function editarArticulo(string $ctx,int $id,array $datos): array
    {
        return $this->escribir(function () use($ctx,$id,$datos) {
            $this->global($ctx,'catalogo.editar');
            self::claves($datos,['nombre','unidad','costo','activo']);
            $a=$this->uno('SELECT * FROM ingredientes WHERE id=? FOR UPDATE',[$id]);
            if(!$a) throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró el artículo.');
            $unidad=array_key_exists('unidad',$datos)?self::medida($datos['unidad']):$a['unidad'];
            if($unidad!==$a['unidad'] && $this->uno('SELECT 1 FROM movimientos_inventario WHERE articulo_id=? LIMIT 1 FOR SHARE',[$id])) throw new InventarioError('UNIDAD_INMUTABLE','La unidad no puede cambiar después de registrar movimientos.');
            $activo=array_key_exists('activo',$datos)?self::booleano($datos['activo']):(int)$a['activo'];
            if(!$activo && $this->uno('SELECT 1 FROM almacen_articulos WHERE articulo_id=? AND saldo<>0 LIMIT 1 FOR SHARE',[$id])) throw new InventarioError('EXISTENCIAS_PENDIENTES','El artículo tiene existencias pendientes.');
            $nombre=array_key_exists('nombre',$datos)?self::texto($datos['nombre'],120):$a['nombre'];
            $costo=array_key_exists('costo',$datos)?DecimalInventario::formato(DecimalInventario::leer($datos['costo'],4),4):$a['costo'];
            try { $this->sql('UPDATE ingredientes SET nombre=?,unidad=?,costo=?,activo=? WHERE id=?',[$nombre,$unidad,$costo,$activo,$id]); }
            catch (\mysqli_sql_exception $e) { if($e->getCode()===1062) throw new InventarioError('ARTICULO_DUPLICADO','El artículo ya existe.'); throw $e; }
            return ['codigo'=>'ARTICULO_EDITADO','articulo_id'=>$id];
        });
    }

    public function asociarArticulo(string $ctx,int $almacen,int $articulo,string $minimo='0.000'): array
    {
        return $this->escribir(function () use($ctx,$almacen,$articulo,$minimo) {
            $a=$this->alcance->almacen($ctx,$almacen,'editar',true);
            $this->articuloActivo($articulo);
            $min=DecimalInventario::cantidad(DecimalInventario::leer($minimo));
            // Una asociación concurrente existente conserva saldo, mínimo y estado.
            $this->sql('INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,minimo) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE almacen_id=VALUES(almacen_id)',[$almacen,$articulo,$a['unidad_id'],$min]);
            return ['codigo'=>'ARTICULO_ASOCIADO','almacen_id'=>$almacen,'articulo_id'=>$articulo];
        });
    }

    public function configurarAsociacion(string $ctx,int $almacen,int $articulo,array $datos): array
    {
        return $this->escribir(function () use($ctx,$almacen,$articulo,$datos) {
            self::claves($datos,['minimo','activo']);
            $this->alcance->almacen($ctx,$almacen,'editar',true);
            $a=$this->uno('SELECT * FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? FOR UPDATE',[$almacen,$articulo]);
            if(!$a) throw new InventarioError('ASOCIACION_NO_EXISTE','El artículo no está asociado a este almacén.');
            $activo=array_key_exists('activo',$datos)?self::booleano($datos['activo']):(int)$a['activo'];
            if(!$activo && DecimalInventario::leer($a['saldo'],3,true)!==0) throw new InventarioError('EXISTENCIAS_PENDIENTES','La asociación tiene existencias pendientes.');
            $min=array_key_exists('minimo',$datos)?DecimalInventario::cantidad(DecimalInventario::leer($datos['minimo'])):$a['minimo'];
            $this->sql('UPDATE almacen_articulos SET minimo=?,activo=?,version=version+1 WHERE almacen_id=? AND articulo_id=?',[$min,$activo,$almacen,$articulo]);
            return ['codigo'=>'ASOCIACION_CONFIGURADA','almacen_id'=>$almacen,'articulo_id'=>$articulo];
        });
    }

    public function existencia(string $ctx,int $almacen,int $articulo): array
    {
        return TransaccionInventario::ejecutar($this->db,function () use($ctx,$almacen,$articulo) {
            $this->alcance->autorizar($ctx,'ver',null,true);
            $this->alcance->almacen($ctx,$almacen,'ver',false,false);
            $a=$this->uno('SELECT aa.*,i.nombre,i.unidad,i.activo AS articulo_activo FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=? AND aa.articulo_id=?',[$almacen,$articulo]);
            if(!$a) throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró la existencia autorizada.');
            return ['codigo'=>'EXISTENCIA_CONSULTADA','existencia'=>$a];
        },'REPEATABLE READ');
    }

    public function registrarEntrada(string $ctx,array $doc): array { return $this->operar($ctx,'entrada',$doc); }
    public function registrarMerma(string $ctx,array $doc): array { return $this->operar($ctx,'merma',$doc); }
    public function registrarSalida(string $ctx,array $doc): array { return $this->operar($ctx,'salida',$doc); }
    public function registrarAjuste(string $ctx,array $doc): array { return $this->operar($ctx,'ajuste',$doc); }
    public function revertirMovimientos(string $ctx,array $doc): array { return $this->operar($ctx,'cancelacion',$doc); }
    public function consultarExistencias(string $ctx,array $filtros=[]): array { return (new ConsultasAlmacen($this->db,$this->alcance))->existencias($ctx,$filtros); }
    public function consultarAlmacenes(string $ctx,array $filtros=[]): array { return (new ConsultasAlmacen($this->db,$this->alcance))->almacenes($ctx,$filtros); }
    public function consultarMovimientos(string $ctx,array $filtros=[]): array { return (new ConsultasAlmacen($this->db,$this->alcance))->movimientos($ctx,$filtros); }

    private function operar(string $ctx,string $tipo,array $doc): array
    {
        return $this->escribir(function () use($ctx,$tipo,$doc) {
            $alcance=$this->alcance->autorizar($ctx,'editar',null,true);
            $d=$this->normalizar($tipo,$doc);
            if($tipo==='merma' && !Inventario::motivoMermaValido($d['motivo'])) throw new InventarioError('MOTIVO_INVALIDO','Elige un motivo de merma válido.');
            $hash=hash('sha256',json_encode([$this->alcance->actorId(),$ctx,$tipo,$d],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            try {
                $this->sql('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (?,?,\'usuario\',?,?,?,?,?)',
                    [$alcance['unidad_id'],$tipo,$this->alcance->actorId(),$d['origen'],$d['clave'],$hash,$d['referencia']]);
                $operacion=(int)$this->db->insert_id;
            } catch(\mysqli_sql_exception $e) {
                if($e->getCode()!==1062) throw $e;
                $existentes=$this->sql('SELECT id,hash_solicitud,resultado FROM operaciones_inventario WHERE unidad_id=? AND ((origen=? AND clave_idempotencia=?) OR (tipo=? AND referencia=?)) FOR UPDATE',
                    [$alcance['unidad_id'],$d['origen'],$d['clave'],$tipo,$d['referencia']])->get_result()->fetch_all(MYSQLI_ASSOC);
                if(count($existentes)!==1 || !hash_equals($hash,$existentes[0]['hash_solicitud']) || $existentes[0]['resultado']===null) throw new InventarioError('CONFLICTO_IDEMPOTENCIA','La clave o referencia pertenece a otra solicitud.');
                $r=json_decode($existentes[0]['resultado'],true,512,JSON_THROW_ON_ERROR);
                $r['reintento']=true;
                return $r;
            }

            $lineas=$d['lineas'];
            if($tipo==='cancelacion') {
                $lineas=[];
                foreach($d['lineas'] as $l) {
                    $original=$this->uno('SELECT * FROM movimientos_inventario WHERE id=? AND unidad_id=? FOR UPDATE',[$l['movimiento_id'],$alcance['unidad_id']]);
                    if(!$original || DecimalInventario::leer($original['delta'],3,true)>=0) throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró la salida original autorizada.');
                    $original['cantidad']=$l['cantidad'];
                    $original['movimiento_id']=(int)$original['id'];
                    $lineas[]=$original;
                }
            }
            // Bloquear todos los almacenes antes de las asociaciones, siempre ordenados.
            $almacenes=[];
            foreach($lineas as $l) $almacenes[(int)$l['almacen_id']]=true;
            ksort($almacenes,SORT_NUMERIC);
            foreach($almacenes as $id=>$_) $almacenes[$id]=$this->alcance->almacen($ctx,$id,'editar',true);
            usort($lineas,static fn($a,$b)=>[(int)$a['almacen_id'],(int)$a['articulo_id'],(int)($a['movimiento_id']??0)] <=> [(int)$b['almacen_id'],(int)$b['articulo_id'],(int)($b['movimiento_id']??0)]);
            $movimientos=[];
            foreach($lineas as $l) {
                $almacen=(int)$l['almacen_id']; $articulo=(int)$l['articulo_id'];
                $a=$this->uno('SELECT * FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? AND unidad_id=? FOR UPDATE',[$almacen,$articulo,$alcance['unidad_id']]);
                if(!$a) throw new InventarioError('ASOCIACION_NO_EXISTE','El artículo no está asociado al almacén autorizado.');
                if(!(int)$a['activo']) throw new InventarioError('ASOCIACION_INACTIVA','La asociación está inactiva.');
                $maestro=$this->articuloActivo($articulo);
                $cantidad=DecimalInventario::leer($l['cantidad'],3,$tipo==='ajuste');
                $saldo=DecimalInventario::leer($a['saldo'],3,true);
                $delta=in_array($tipo,['merma','salida'],true)?-$cantidad:$cantidad;
                if($tipo==='ajuste') {
                    if((string)$a['version']!==(string)$l['version']) throw new InventarioError('VERSION_CONFLICTO','Las existencias cambiaron después del conteo.');
                    $delta=$cantidad-$saldo;
                }
                if($tipo==='cancelacion') {
                    // Lectura actual también si el coordinador usa REPEATABLE READ.
                    $compensaciones=$this->sql('SELECT delta FROM movimientos_inventario WHERE movimiento_origen_id=? FOR UPDATE',[$l['movimiento_id']])->get_result();
                    $repuesto=0;
                    while($comp=$compensaciones->fetch_assoc()) $repuesto=DecimalInventario::sumar($repuesto,DecimalInventario::leer($comp['delta'],3,true));
                    if($cantidad > -DecimalInventario::leer($l['delta'],3,true)-$repuesto) throw new InventarioError('REVERSION_EXCEDIDA','La compensación supera lo descontado.');
                }
                $nuevo=DecimalInventario::sumar($saldo,$delta);
                $nuevoSql=DecimalInventario::cantidad($nuevo);
                $deltaSql=DecimalInventario::cantidad($delta);
                if($nuevo<0 && !(int)$almacenes[$almacen]['permitir_negativos']) throw new InventarioError('SALDO_INSUFICIENTE','El almacén no permite existencias negativas.');
                if($delta===0) continue;
                $costo=$tipo==='cancelacion'?$l['costo_unitario']:($l['costo_unitario']??$maestro['costo']);
                $this->sql('UPDATE almacen_articulos SET saldo=?,version=version+1 WHERE almacen_id=? AND articulo_id=?',[$nuevoSql,$almacen,$articulo]);
                $this->sql('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,motivo,nota,costo_unitario,movimiento_origen_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$operacion,$alcance['unidad_id'],$almacen,$articulo,$tipo,$deltaSql,$d['motivo'],$d['nota'],$costo,$l['movimiento_id']??null]);
                $movimientos[]=['id'=>(int)$this->db->insert_id,'almacen_id'=>$almacen,'articulo_id'=>$articulo,'delta'=>$deltaSql,'saldo'=>$nuevoSql,'version'=>(int)$a['version']+1];
            }
            $resultado=['codigo'=>'OPERACION_REGISTRADA','operacion_id'=>$operacion,'movimientos'=>$movimientos,'reintento'=>false];
            $this->sql('UPDATE operaciones_inventario SET resultado=? WHERE id=?',[json_encode($resultado,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$operacion]);
            return $resultado;
        });
    }

    private function normalizar(string $tipo,array $doc): array
    {
        self::claves($doc,['clave','referencia','origen','motivo','nota','lineas']);
        $d=['clave'=>self::texto($doc['clave']??null,120),'referencia'=>self::texto($doc['referencia']??null,120),
            'origen'=>self::texto($doc['origen']??'motor_inventario',40),'motivo'=>self::texto($doc['motivo']??null,80),
            'nota'=>isset($doc['nota'])?self::texto($doc['nota'],255,false):null];
        if(!isset($doc['lineas']) || !is_array($doc['lineas']) || !array_is_list($doc['lineas']) || count($doc['lineas'])<1 || count($doc['lineas'])>200) throw new InventarioError('LINEAS_INVALIDAS','La operación requiere de 1 a 200 líneas.');
        $agregadas=[];
        foreach($doc['lineas'] as $l) {
            if(!is_array($l)) throw new InventarioError('LINEAS_INVALIDAS','La línea no es válida.');
            self::claves($l,$tipo==='cancelacion'?['movimiento_id','cantidad']:['almacen_id','articulo_id','cantidad','costo_unitario','version']);
            $q=DecimalInventario::leer($l['cantidad']??null,3,$tipo==='ajuste');
            if($tipo!=='ajuste' && $q<=0) throw new InventarioError('CANTIDAD_INVALIDA','La cantidad debe ser positiva.');
            if($tipo==='cancelacion') {
                $id=self::id($l['movimiento_id']??null); $key=(string)$id;
                $n=['movimiento_id'=>$id];
            } else {
                $n=['almacen_id'=>self::id($l['almacen_id']??null),'articulo_id'=>self::id($l['articulo_id']??null)];
                $key=$n['almacen_id'].':'.$n['articulo_id'];
                if($tipo==='ajuste') $n['version']=self::version($l['version']??null);
                elseif(array_key_exists('version',$l)) throw new InventarioError('DATOS_INVALIDOS','La versión solo corresponde a un ajuste.');
                if(isset($l['costo_unitario'])) {
                    if($tipo!=='entrada') throw new InventarioError('DATOS_INVALIDOS','El costo solo puede indicarse al registrar una entrada.');
                    $n['costo_unitario']=DecimalInventario::formato(DecimalInventario::leer($l['costo_unitario'],4),4);
                }
            }
            if(isset($agregadas[$key])) {
                if($tipo==='ajuste' || ($n['costo_unitario']??null)!==($agregadas[$key]['costo_unitario']??null)) throw new InventarioError('LINEAS_INVALIDAS','Hay líneas repetidas con conteos o costos incompatibles.');
                $q=DecimalInventario::sumar($q,DecimalInventario::leer($agregadas[$key]['cantidad']));
            }
            $n['cantidad']=DecimalInventario::cantidad($q);
            $agregadas[$key]=$n;
        }
        ksort($agregadas,SORT_NATURAL);
        $d['lineas']=array_values($agregadas);
        return $d;
    }

    private function escribir(callable $accion): array
    {
        return TransaccionInventario::ejecutar($this->db,function () use($accion) {
            return $accion();
        });
    }

    private function global(string $ctx,string $facultad): void
    {
        if($ctx!=='global') throw new InventarioError('AMBITO_DENEGADO','Esta operación requiere el ámbito Global.');
        $this->alcance->autorizar($ctx,'editar',$facultad,true);
    }

    private function unidad(string $slug): int
    {
        if(!in_array($slug,['global','restaurante','panaderia'],true)) throw new InventarioError('AMBITO_INVALIDO','El propietario no es válido.');
        $r=$this->uno('SELECT id FROM unidades WHERE slug=?',[$slug]);
        if(!$r) throw new InventarioError('AMBITO_INVALIDO','No se encontró el propietario.');
        return (int)$r['id'];
    }

    private function articuloActivo(int $id): array
    {
        $r=$this->uno('SELECT id,costo,activo FROM ingredientes WHERE id=? FOR SHARE',[$id]);
        if(!$r || !(int)$r['activo']) throw new InventarioError('ARTICULO_INACTIVO','El artículo no existe o está inactivo.');
        return $r;
    }

    private function sinPendientes(int $id): void
    {
        if($this->uno('SELECT 1 FROM almacen_articulos WHERE almacen_id=? AND saldo<>0 LIMIT 1 FOR UPDATE',[$id])
            || $this->uno("SELECT 1 FROM transferencias WHERE (almacen_origen_id=? OR almacen_destino_id=?) AND estado IN ('solicitada','enviada','incidencia') LIMIT 1 FOR SHARE",[$id,$id])) {
            throw new InventarioError('EXISTENCIAS_PENDIENTES','El almacén tiene existencias u operaciones pendientes.');
        }
    }

    private function sql(string $sql,array $parametros=[]): \mysqli_stmt
    {
        $stmt=$this->db->prepare($sql); $stmt->execute($parametros); return $stmt;
    }

    private function uno(string $sql,array $parametros=[]): ?array { return $this->sql($sql,$parametros)->get_result()->fetch_assoc(); }
    public static function errorLectura(\Throwable $e): array { return ['ok'=>false,'codigo'=>$e instanceof InventarioError?$e->codigo:'ERROR_INVENTARIO','mensaje'=>$e instanceof InventarioError?$e->getMessage():'No se pudo consultar el inventario.']; }
    private static function claves(array $datos,array $permitidas): void { if(array_diff(array_keys($datos),$permitidas)) throw new InventarioError('DATOS_INVALIDOS','Se enviaron campos no permitidos.'); }
    private static function texto(mixed $v,int $max,bool $obligatorio=true): string
    {
        if(!is_string($v) || ($obligatorio && trim($v)==='') || mb_strlen($v)>$max || str_contains($v,"\0")) throw new InventarioError('DATOS_INVALIDOS','Revisa los campos de texto y su longitud.');
        return trim($v);
    }
    private static function booleano(mixed $v): int { if(!in_array($v,[true,false,0,1],true)) throw new InventarioError('DATOS_INVALIDOS','El estado debe ser booleano.'); return (int)$v; }
    private static function medida(mixed $v): string { if(!in_array($v,['g','kg','ml','l','pza'],true)) throw new InventarioError('UNIDAD_INVALIDA','La unidad de medida no es válida.'); return $v; }
    private static function id(mixed $v): int { if((!is_int($v)&&!is_string($v)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$v)) throw new InventarioError('ID_INVALIDO','El identificador no es válido.'); return (int)$v; }
    private static function version(mixed $v): int { if((!is_int($v)&&!is_string($v)) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$v)) throw new InventarioError('VERSION_INVALIDA','Indica una versión de existencias válida.'); return (int)$v; }
}
