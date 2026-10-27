<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Adapta formularios al motor 3C; no mantiene saldos ni reglas de inventario propias. */
final class AdministracionAlmacenes
{
    private Almacenes $motor;
    private AlcanceInventario $alcance;
    private Transferencias $transferencias;

    public function __construct(private \mysqli $db, int $actorId, string $nivelAuth)
    {
        $this->motor = new Almacenes($db, $actorId, $nivelAuth);
        $this->alcance = new AlcanceInventario($db, $actorId, $nivelAuth);
        $this->transferencias = new Transferencias($db, $actorId, $nivelAuth);
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
        $paginaTransferencias=$filtros['transferencia_pagina']??'1';
        if(!is_scalar($paginaTransferencias)||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$paginaTransferencias))throw new InventarioError('FILTRO_INVALIDO','Página de transferencias inválida.');
        $transferencias=$this->transferencias->consultar($ctx,(int)$paginaTransferencias);
        self::exigirResultado($transferencias);
        $catalogoTransferencias=$this->catalogo($ctx);
        self::exigirResultado($catalogoTransferencias);
        return ['existencias'=>$existencias,'almacenes'=>$almacenes,'movimientos'=>$movimientos,'catalogo'=>$catalogo,'opcionesAlmacen'=>$opciones['almacenes'],'totalOpcionesAlmacen'=>$opciones['total'],'transferencias'=>$transferencias['transferencias'],'contrapartes'=>$transferencias['contrapartes'],'catalogoTransferencias'=>$catalogoTransferencias['articulos'],'transferenciasTotal'=>$transferencias['transferencias_total'],'transferenciasPendientes'=>$transferencias['transferencias_pendientes'],'transferenciasPagina'=>$transferencias['transferencias_pagina']];
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
            $permitidas=match($accion) {
                'crear_almacen'=>['propietario','nombre','permitir_negativos'],
                'configurar_almacen'=>['almacen_id','nombre','activo','permitir_negativos'],
                'eliminar_almacen'=>['almacen_id'],
                'crear_articulo','editar_articulo'=>['articulo_id','nombre','unidad','costo','activo'],
                'asociar'=>['almacen_id','articulo_id','minimo'],
                'configurar_asociacion'=>['almacen_id','articulo_id','minimo','activo'],
                'entrada','ajuste','merma'=>['almacen_id','articulo_id','cantidad','costo_unitario','version','clave','motivo','nota'],
                default=>throw new InventarioError('ACCION_INVALIDA','La operación solicitada no está disponible.'),
            };
            if(array_diff(array_keys($datos),array_merge(['admin_csrf','accion','volver_vista','volver_filtros'],$permitidas))) throw new InventarioError('DATOS_INVALIDOS','Hay campos no admitidos en el formulario.');
            $id = static fn(string $campo): int => self::id($datos[$campo]??null);
            switch ($accion) {
                case 'crear_almacen':
                    return $this->motor->crearAlmacen($ctx,self::texto($datos,'propietario'),self::texto($datos,'nombre'),isset($datos['permitir_negativos']));
                case 'configurar_almacen':
                    return $this->motor->configurarAlmacen($ctx,$id('almacen_id'),['nombre'=>self::texto($datos,'nombre'),'activo'=>isset($datos['activo']),'permitir_negativos'=>isset($datos['permitir_negativos'])]);
                case 'eliminar_almacen':
                    return $this->motor->eliminarAlmacen($ctx,$id('almacen_id'));
                case 'crear_articulo':
                case 'editar_articulo':
                    $articulo=['nombre'=>self::texto($datos,'nombre'),'unidad'=>self::texto($datos,'unidad'),'costo'=>self::texto($datos,'costo'),'activo'=>isset($datos['activo'])];
                    return $accion==='crear_articulo' ? $this->motor->crearArticulo($ctx,$articulo) : $this->motor->editarArticulo($ctx,$id('articulo_id'),$articulo);
                case 'asociar':
                    return $this->motor->asociarArticulo($ctx,$id('almacen_id'),$id('articulo_id'),self::texto($datos,'minimo'));
                case 'configurar_asociacion':
                    return $this->motor->configurarAsociacion($ctx,$id('almacen_id'),$id('articulo_id'),['minimo'=>self::texto($datos,'minimo'),'activo'=>isset($datos['activo'])]);
                case 'entrada':
                case 'ajuste':
                case 'merma':
                    $linea=['almacen_id'=>$id('almacen_id'),'articulo_id'=>$id('articulo_id'),'cantidad'=>self::texto($datos,'cantidad')];
                    if ($accion==='entrada' && isset($datos['costo_unitario']) && $datos['costo_unitario']!=='') $linea['costo_unitario']=self::texto($datos,'costo_unitario');
                    if ($accion==='ajuste') $linea['version']=self::texto($datos,'version');
                    $clave=self::texto($datos,'clave');
                    $documento=['clave'=>$clave,'referencia'=>$clave,'origen'=>'admin_almacenes','motivo'=>$accion==='merma'?self::texto($datos,'motivo'):'operacion_administrativa','nota'=>self::texto($datos,'nota',false),'lineas'=>[$linea]];
                    return match($accion) {
                        'entrada'=>$this->motor->registrarEntrada($ctx,$documento),
                        'ajuste'=>$this->motor->registrarAjuste($ctx,$documento),
                        'merma'=>$this->motor->registrarMerma($ctx,$documento),
                    };
                default:
                    throw new InventarioError('ACCION_INVALIDA','La operación solicitada no está disponible.');
            }
        } catch (\Throwable $e) { return Almacenes::errorLectura($e) + ['confirmado'=>false]; }
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
