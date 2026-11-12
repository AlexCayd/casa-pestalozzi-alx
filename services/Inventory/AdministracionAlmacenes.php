<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Adapta formularios al motor 3C; no mantiene saldos ni reglas de inventario propias. */
final class AdministracionAlmacenes
{
    private Almacenes $motor;
    private AlcanceInventario $alcance;
    private Transferencias $transferencias;
    private Faltantes $faltantes;

    public function __construct(private \mysqli $db, int $actorId, string $nivelAuth)
    {
        $this->motor = new Almacenes($db, $actorId, $nivelAuth);
        $this->alcance = new AlcanceInventario($db, $actorId, $nivelAuth);
        $this->transferencias = new Transferencias($db, $actorId, $nivelAuth);
        $this->faltantes = new Faltantes($db, $actorId, $nivelAuth);
    }

    public function consultar(string $ctx, array $filtros, string $vista = 'existencias'): array
    {
        $comunes = array_intersect_key($filtros, array_flip(['q','negocio','almacen_id','almacen_ids','articulo_id','pagina']));
        $comunes['por_pagina'] = 25;
        $existencias = $this->motor->consultarExistencias($ctx, $comunes + (!empty($filtros['solo_alertas']) ? ['solo_alertas'=>true] : []));
        self::exigirResultado($existencias);
        $almacenes = $this->motor->consultarAlmacenes($ctx, array_intersect_key($comunes,array_flip($vista==='almacenes'?['q','negocio','almacen_id','almacen_ids','pagina']:['negocio','almacen_id','almacen_ids'])) + ['por_pagina'=>$vista==='almacenes'?25:100]);
        self::exigirResultado($almacenes);
        $opciones = $this->motor->consultarAlmacenes($ctx, ['por_pagina'=>100]);
        self::exigirResultado($opciones);
        for($pagina=2;count($opciones['almacenes'])<$opciones['total'];$pagina++) {
            $siguiente=$this->motor->consultarAlmacenes($ctx,['por_pagina'=>100,'pagina'=>$pagina]);
            self::exigirResultado($siguiente);
            if(!$siguiente['almacenes']) break;
            array_push($opciones['almacenes'],...$siguiente['almacenes']);
        }
        if(isset($filtros['almacen_id']) && !in_array((int)$filtros['almacen_id'],array_map(static fn($a)=>(int)$a['almacen_id'],$opciones['almacenes']),true)) {
            $elegido=$this->motor->consultarAlmacenes($ctx,['almacen_id'=>$filtros['almacen_id']]);
            self::exigirResultado($elegido);
            $opciones['almacenes']=array_merge($opciones['almacenes'],$elegido['almacenes']);
        }
        $movimientos = $this->motor->consultarMovimientos($ctx, $comunes + array_intersect_key($filtros,array_flip(['desde','hasta','tipo'])));
        self::exigirResultado($movimientos);
        $catalogo = $this->catalogo($ctx, (string)($filtros['q']??''), (int)($filtros['pagina']??1));
        self::exigirResultado($catalogo);
        // La ficha de origen conserva su artículo aunque quede fuera de esta página del selector.
        $seleccionado=(int)($filtros['articulo_id']??0);
        if($seleccionado&&!in_array($seleccionado,array_map('intval',array_column($catalogo['articulos'],'id')),true)){
            $columnas=$ctx==='global'?'id,nombre,unidad,costo,activo':'id,nombre,unidad,activo';
            $s=$this->db->prepare("SELECT {$columnas} FROM ingredientes WHERE id=?");$s->execute([$seleccionado]);$elegido=$s->get_result()->fetch_assoc();$s->close();
            if($elegido)array_unshift($catalogo['articulos'],$elegido);
        }
        $paginaTransferencias=$filtros['transferencia_pagina']??'1';
        if(!is_scalar($paginaTransferencias)||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$paginaTransferencias))throw new InventarioError('FILTRO_INVALIDO','Página de transferencias inválida.');
        $transferencias=$this->transferencias->consultar($ctx,(int)$paginaTransferencias);
        self::exigirResultado($transferencias);
        $catalogoTransferencias=$this->catalogo($ctx);
        self::exigirResultado($catalogoTransferencias);
        // Faltantes por conciliar (§16): el aviso va arriba de todo; la bandeja sólo en su vista.
        $faltantes=$vista==='faltantes'?$this->faltantes->bandeja($ctx):['ok'=>true,'confirmado'=>true,'faltantes'=>[]];
        self::exigirResultado($faltantes);
        $faltantesPendientes=$this->faltantes->contar($ctx);
        return ['existencias'=>$existencias,'almacenes'=>$almacenes,'movimientos'=>$movimientos,'catalogo'=>$catalogo,'opcionesAlmacen'=>$opciones['almacenes'],'totalOpcionesAlmacen'=>$opciones['total'],'transferencias'=>$transferencias['transferencias'],'contrapartes'=>$transferencias['contrapartes'],'asociacionesTransferencia'=>$transferencias['asociaciones_transferencia'],'catalogoTransferencias'=>$catalogoTransferencias['articulos'],'transferenciasTotal'=>$transferencias['transferencias_total'],'transferenciasPendientes'=>$transferencias['transferencias_pendientes'],'transferenciasPagina'=>$transferencias['transferencias_pagina'],'faltantes'=>$faltantes['faltantes'],'faltantesPendientes'=>$faltantesPendientes,'faltantesConsolidado'=>Faltantes::consolidado($ctx,$this->alcance->autorizar($ctx,'ver'))];
    }

    /** Datos maestros para elegir una asociación: no incluye existencias ajenas. */
    public function catalogo(string $ctx, string $q = '', int $pagina = 1): array
    {
        return TransaccionInventario::ejecutar($this->db, function () use($ctx,$q,$pagina): array {
            $this->alcance->autorizar($ctx,'ver',null,true);
            if ($pagina<1 || $pagina>100000 || mb_strlen($q)>120) throw new InventarioError('FILTRO_INVALIDO','Revisa la búsqueda y la página.');
            $query = '%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($q)).'%';
            $s=$this->db->prepare("SELECT COUNT(*) FROM ingredientes WHERE nombre LIKE ? ESCAPE '!'");
            $s->execute([$query]); $total=(int)$s->get_result()->fetch_row()[0]; $s->close();
            $columnas=$ctx==='global'?'id,nombre,unidad,costo,activo':'id,nombre,unidad,activo';
            $s=$this->db->prepare("SELECT {$columnas} FROM ingredientes WHERE nombre LIKE ? ESCAPE '!' ORDER BY nombre,id LIMIT 100 OFFSET ?");
            $s->execute([$query,($pagina-1)*100]); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
            return ['codigo'=>'CATALOGO_CONSULTADO','articulos'=>$rows,'total'=>$total,'pagina'=>$pagina,'por_pagina'=>100];
        },'REPEATABLE READ');
    }

    public function guardar(string $ctx, string $accion, array $datos): array
    {
        try {
            if(str_starts_with($accion,'transferencia_')){
                $comando=substr($accion,14);
                $permitidas=$comando==='solicitar'?['clave','origen_id','destino_id','lineas','motivo']:['clave','transferencia_id','version','lineas','motivo'];
                if(array_diff(array_keys($datos),array_merge(['admin_csrf','accion','volver_vista','volver_filtros'],$permitidas)))throw new InventarioError('DATOS_INVALIDOS','Campos de transferencia no permitidos.');
                $doc=array_intersect_key($datos,array_flip($permitidas));
                if(in_array($comando,['recibir','retornar'],true)&&isset($doc['lineas'])&&is_array($doc['lineas'])){
                    // Campos vacíos del formulario no declaran una recepción de cero.
                    $doc['lineas']=array_values(array_filter($doc['lineas'],static fn($l)=>!is_array($l)||!in_array($l['cantidad']??null,['','0','0.000'],true)));
                }
                return $this->transferencias->ejecutar($ctx,$comando,$doc);
            }
            if($accion==='conciliar_faltante'){
                $permitidas=['faltante_id','version','clave','asignaciones'];
                if(array_diff(array_keys($datos),array_merge(['admin_csrf','accion','volver_vista','volver_filtros'],$permitidas)))throw new InventarioError('DATOS_INVALIDOS','Campos de conciliación no permitidos.');
                $doc=array_intersect_key($datos,array_flip($permitidas));
                if(isset($doc['asignaciones'])&&is_array($doc['asignaciones'])){
                    // Las filas vacías del formulario no declaran un origen de cero.
                    $doc['asignaciones']=array_values(array_filter($doc['asignaciones'],static fn($l)=>!is_array($l)||!in_array($l['cantidad']??null,['','0','0.000'],true)));
                }
                return $this->faltantes->conciliar($ctx,$doc);
            }
            $permitidas=match($accion) {
                'crear_almacen'=>['propietario','nombre'],
                'configurar_almacen'=>['almacen_id','nombre','activo'],
                'eliminar_almacen'=>['almacen_id'],
                'crear_articulo'=>['nombre','unidad','costo','activo','contenido_pieza','almacen_ids'],
                'editar_articulo'=>['articulo_id','nombre','unidad','costo','activo','contenido_pieza'],
                'asociar'=>['almacen_id','almacen_ids','articulo_id','minimo'],
                'configurar_asociacion'=>['almacen_id','articulo_id','minimo','activo'],
                'entrada'=>['almacen_id','articulo_id','cantidad','piezas','costo_unitario','costo_por','proveedor_id','version','clave','motivo','nota'],
                // «ajuste» (conteo físico) se retiró: sobrescribía el saldo sin motivo (auditoría A-4).
                'merma'=>['almacen_id','articulo_id','cantidad','piezas','costo_unitario','costo_por','version','clave','motivo','nota'],
                default=>throw new InventarioError('ACCION_INVALIDA','La operación solicitada no está disponible.'),
            };
            if(array_diff(array_keys($datos),array_merge(['admin_csrf','accion','volver_vista','volver_filtros'],$permitidas))) throw new InventarioError('DATOS_INVALIDOS','Hay campos no admitidos en el formulario.');
            $id = static fn(string $campo): int => self::id($datos[$campo]??null);
            switch ($accion) {
                case 'crear_almacen':
                    return $this->motor->crearAlmacen($ctx,self::texto($datos,'propietario'),self::texto($datos,'nombre'));
                case 'configurar_almacen':
                    return $this->motor->configurarAlmacen($ctx,$id('almacen_id'),['nombre'=>self::texto($datos,'nombre'),'activo'=>isset($datos['activo'])]);
                case 'eliminar_almacen':
                    return $this->motor->eliminarAlmacen($ctx,$id('almacen_id'));
                case 'crear_articulo':
                case 'editar_articulo':
                    $articulo=['nombre'=>self::texto($datos,'nombre'),'unidad'=>self::texto($datos,'unidad'),'costo'=>self::texto($datos,'costo'),'activo'=>isset($datos['activo'])];
                    // Campo ausente conserva el contenido vigente; vacío lo retira.
                    if(array_key_exists('contenido_pieza',$datos)) $articulo['contenido_pieza']=self::texto($datos,'contenido_pieza',false);
                    return $accion==='crear_articulo' ? $this->crearArticulo($ctx,$articulo,self::ids($datos['almacen_ids']??[])) : $this->motor->editarArticulo($ctx,$id('articulo_id'),$articulo);
                case 'asociar':
                    $destinos=self::ids($datos['almacen_ids']??[]);
                    if(isset($datos['almacen_id'])&&$datos['almacen_id']!=='')$destinos[]=$id('almacen_id');
                    return $this->asociarVarios($ctx,$id('articulo_id'),array_values(array_unique($destinos)),self::texto($datos,'minimo'));
                case 'configurar_asociacion':
                    return $this->motor->configurarAsociacion($ctx,$id('almacen_id'),$id('articulo_id'),['minimo'=>self::texto($datos,'minimo'),'activo'=>isset($datos['activo'])]);
                case 'entrada':
                case 'merma':
                    $linea=['almacen_id'=>$id('almacen_id'),'articulo_id'=>$id('articulo_id')];
                    $pieza=null;
                    if(self::texto($datos,'piezas',false)!==''){
                        // Captura en piezas (8 botellas + 450 ml): el motor sólo recibe la unidad base.
                        $pieza=$this->contenidoPieza($linea['articulo_id']);
                        $linea['cantidad']=self::enUnidadBase(self::texto($datos,'piezas'),self::texto($datos,'cantidad',false),$pieza);
                    } else $linea['cantidad']=self::texto($datos,'cantidad');
                    if ($accion==='entrada' && isset($datos['costo_unitario']) && $datos['costo_unitario']!=='') {
                        $costo=self::texto($datos,'costo_unitario');
                        if(($datos['costo_por']??'')==='pieza'){
                            $pieza??=$this->contenidoPieza($linea['articulo_id']);
                            $costo=self::costoPorUnidadBase($costo,$pieza);
                        } elseif(isset($datos['costo_por']) && !in_array($datos['costo_por'],['','unidad'],true)) throw new InventarioError('DATOS_INVALIDOS','Indica si el costo es por pieza o por unidad.');
                        $linea['costo_unitario']=$costo;
                    }
                    $clave=self::texto($datos,'clave');
                    $documento=['clave'=>$clave,'referencia'=>$clave,'origen'=>'admin_almacenes','motivo'=>$accion==='merma'?self::texto($datos,'motivo'):'operacion_administrativa','nota'=>self::texto($datos,'nota',false),'lineas'=>[$linea]];
                    if($accion==='entrada' && self::texto($datos,'proveedor_id',false)!=='') {
                        // Sin costo capturado el motor usaría el maestro, que no es lo que
                        // cobró este proveedor: el histórico de costos quedaría falso.
                        if(!isset($linea['costo_unitario'])) throw new InventarioError('COSTO_REQUERIDO','Indica el costo de esta entrada para registrarla en el historial del proveedor.');
                        return $this->entradaConProveedor($this->contextoAlmacen($linea['almacen_id'],$ctx),$documento,$id('proveedor_id'));
                    }
                    return match($accion) {
                        // La entrada se autoriza en el negocio dueño del almacén (§5).
                        'entrada'=>$this->motor->registrarEntrada($this->contextoAlmacen($linea['almacen_id'],$ctx),$documento),
                        'merma'=>$this->motor->registrarMerma($ctx,$documento),
                    };
                default:
                    throw new InventarioError('ACCION_INVALIDA','La operación solicitada no está disponible.');
            }
        } catch (\Throwable $e) { return Almacenes::errorLectura($e) + ['confirmado'=>false]; }
    }

    /**
     * Entrada y su proveedor en la misma transacción (arquitectura §17): el motor
     * registra la operación como siempre y aquí sólo se liga quién la surtió. Si
     * cualquiera de las dos partes falla no queda ni la entrada ni el vínculo.
     */
    private function entradaConProveedor(string $ctx,array $documento,int $proveedor): array
    {
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$documento,$proveedor):array {
            $alcance=$this->alcance->autorizar($ctx,'editar',null,true);
            DirectorioProveedores::exigirActivo($this->db,$alcance['unidad_id'],$proveedor);
            $r=$this->motor->registrarEntrada($ctx,$documento);
            if(($r['ok']??null)===false || !isset($r['operacion_id'])) throw new InventarioError((string)($r['codigo']??'ENTRADA_RECHAZADA'),(string)($r['mensaje']??'No se registró la entrada.'));
            DirectorioProveedores::ligar($this->db,$alcance['unidad_id'],(int)$r['operacion_id'],$proveedor);
            // El coordinador exterior publica su propio estado de confirmación.
            unset($r['ok'],$r['confirmado'],$r['pendiente']);
            return $r+['proveedor_id'=>$proveedor];
        });
    }

    /**
     * Alta del artículo y, si se eligieron, sus asociaciones en la misma transacción:
     * o queda creado y asociado a todos los almacenes marcados, o no queda nada.
     * Nunca crea saldo.
     */
    private function crearArticulo(string $ctx,array $articulo,array $almacenes=[]): array
    {
        if ($ctx==='global' && !$almacenes) return $this->motor->crearArticulo('global',$articulo);
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$articulo,$almacenes):array {
            $this->alcance->autorizar($ctx,'editar',null,true);
            $r=self::interno($this->motor->crearArticulo('global',$articulo),'No se pudo crear el artículo.');
            foreach($almacenes as $almacen) self::interno($this->motor->asociarArticulo($this->contextoAlmacen($almacen,$ctx),$almacen,(int)$r['articulo_id']),'No se pudo asociar el artículo.');
            return $r+['almacenes_asociados'=>$almacenes];
        });
    }

    /** Asocia el artículo a uno o varios almacenes, todos o ninguno. */
    private function asociarVarios(string $ctx,int $articulo,array $almacenes,string $minimo): array
    {
        if(!$almacenes) throw new InventarioError('ALMACEN_REQUERIDO','Elige al menos un almacén.');
        if(count($almacenes)===1) return $this->motor->asociarArticulo($this->contextoAlmacen($almacenes[0],$ctx),$almacenes[0],$articulo,$minimo);
        return TransaccionInventario::ejecutar($this->db,function()use($ctx,$articulo,$almacenes,$minimo):array {
            foreach($almacenes as $almacen) self::interno($this->motor->asociarArticulo($this->contextoAlmacen($almacen,$ctx),$almacen,$articulo,$minimo),'No se pudo asociar el artículo.');
            return ['codigo'=>'ARTICULO_ASOCIADO','articulo_id'=>$articulo,'almacenes_asociados'=>$almacenes];
        });
    }

    /** Resultado de una operación anidada: confirmada o pendiente del coordinador; si no, se revierte todo. */
    private static function interno(array $r,string $mensaje): array
    {
        if (empty($r['confirmado']) && !(($r['ok']??null)===null && !empty($r['pendiente']))) throw new InventarioError((string)($r['codigo']??'OPERACION_RECHAZADA'),(string)($r['mensaje']??$mensaje));
        unset($r['ok'],$r['confirmado'],$r['pendiente']);
        return $r;
    }

    /**
     * Negocio dueño del almacén. Asociar y dar entrada se autorizan ahí (arquitectura §5):
     * la pantalla de un negocio puede escribir en almacenes de otro sólo si el usuario
     * ya tiene edición de Almacenes en ese otro; el motor lo vuelve a comprobar.
     * Un almacén inexistente conserva el contexto actual y el motor lo rechaza.
     */
    private function contextoAlmacen(int $almacen,string $ctx): string
    {
        $s=$this->db->prepare("SELECT u.slug FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.id=? AND u.slug IN('global','restaurante','panaderia')");
        $s->execute([$almacen]);$r=$s->get_result()->fetch_row();$s->close();
        return $r?(string)$r[0]:$ctx;
    }

    /**
     * Almacenes activos de todos los negocios donde el actor edita Almacenes, con la
     * marca de si $articulo ya está asociado. Sólo identidad: nunca saldos.
     * @return list<array{almacen_id:int,nombre:string,slug:string,asociado:bool}>
     */
    public function almacenesEditables(?int $articulo=null): array
    {
        $editables=[];
        foreach(['global','restaurante','panaderia'] as $slug){try{$this->alcance->autorizar($slug,'editar');$editables[]=$slug;}catch(InventarioError){}}
        if(!$editables) return [];
        $marcas=implode(',',array_fill(0,count($editables),'?'));
        $s=$this->db->prepare("SELECT a.id almacen_id,a.nombre,u.slug,EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.almacen_id=a.id AND aa.articulo_id=?) asociado FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.activo=1 AND u.slug IN ({$marcas}) ORDER BY a.unidad_id,a.nombre");
        $s->execute(array_merge([(int)$articulo],$editables));$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return array_map(static fn(array $r):array=>['almacen_id'=>(int)$r['almacen_id'],'nombre'=>(string)$r['nombre'],'slug'=>(string)$r['slug'],'asociado'=>(bool)$r['asociado']],$rows);
    }

    /** @return list<int> */
    private static function ids(mixed $v): array
    {
        if($v===null||$v===''||$v===[]) return [];
        if(!is_array($v)||!array_is_list($v)||count($v)>100) throw new InventarioError('DATOS_INVALIDOS','La selección de almacenes no es válida.');
        return array_values(array_unique(array_map(static fn($x):int=>self::id($x),$v)));
    }

    private function contenidoPieza(int $articulo): string
    {
        $s=$this->db->prepare('SELECT contenido_pieza FROM ingredientes WHERE id=?');$s->execute([$articulo]);$r=$s->get_result()->fetch_row();$s->close();
        if(!$r || $r[0]===null) throw new InventarioError('CONTENIDO_PIEZA_INVALIDO','Este artículo no declara contenido por pieza; captura la cantidad en su unidad.');
        return (string)$r[0];
    }

    /** piezas × contenido + resto, exacto a milésimas. Un conteo admite cero. */
    public static function enUnidadBase(string $piezas,string $resto,string $contenido): string
    {
        $p=DecimalInventario::leer($piezas);$c=DecimalInventario::leer($contenido);$r=$resto===''?0:DecimalInventario::leer($resto);
        // p y c tienen tres decimales implícitos: el producto trae seis y se exige exacto.
        if($p>0 && $c>intdiv(PHP_INT_MAX,$p)) throw new InventarioError('DECIMAL_FUERA_RANGO','La cantidad supera el rango admitido.');
        $bruto=$p*$c;
        if($bruto%1000!==0) throw new InventarioError('CANTIDAD_INVALIDA','Las piezas por su contenido deben dar una cantidad exacta a milésimas.');
        $total=DecimalInventario::sumar(intdiv($bruto,1000),$r);
        if($total<=0) throw new InventarioError('CANTIDAD_INVALIDA','La cantidad debe ser positiva.');
        return DecimalInventario::cantidad($total);
    }

    /** Costo por pieza → costo por unidad base a cuatro decimales, mitad hacia arriba. */
    public static function costoPorUnidadBase(string $costoPieza,string $contenido): string
    {
        $costo=DecimalInventario::leer($costoPieza,4);$c=DecimalInventario::leer($contenido);
        if($c<=0) throw new InventarioError('CONTENIDO_PIEZA_INVALIDO','El contenido por pieza debe ser positivo.');
        // (costo/10^4)/(c/10^3) expresado en diezmilésimas: costo*1000/c, redondeo half-up.
        if($costo>intdiv(PHP_INT_MAX,1000)) throw new InventarioError('DECIMAL_FUERA_RANGO','El costo supera el rango admitido.');
        $num=$costo*1000;$q=intdiv($num,$c);if(($num%$c)*2>=$c)$q++;
        return DecimalInventario::formato($q,4);
    }

    public static function exigirResultado(array $resultado): void
    {
        if (($resultado['ok']??false)!==true || empty($resultado['confirmado'])) throw new InventarioError((string)($resultado['codigo']??'TRANSACCION_NO_CONFIRMADA'),(string)($resultado['mensaje']??'No se pudo confirmar la operación.'));
    }

    private static function id(mixed $valor): int
    {
        if ((!is_string($valor)&&!is_int($valor)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$valor)) throw new InventarioError('ID_INVALIDO','El identificador no es válido.');
        return (int)$valor;
    }

    private static function texto(array $datos,string $campo,bool $obligatorio=true): string
    {
        $valor=$datos[$campo]??'';
        if (!is_string($valor) || ($obligatorio && trim($valor)==='')) throw new InventarioError('DATOS_INVALIDOS','Revisa los campos obligatorios.');
        return trim($valor);
    }
}
