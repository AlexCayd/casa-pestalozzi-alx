<?php
declare(strict_types=1);
namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\Articulos as ArticulosM2;
use Services\Inventory\InventarioError;
use Services\Security\AdminCsrfService;
use Services\Security\ContextoNegocio;
use Services\Security\Contextos;

/** Una superficie administrativa compartida; el ámbito efectivo sale de la ruta. */
final class AdminAlmacenesController
{
    public static function index(Router $router): void
    {
        $ctx=ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario','ver',$ctx);
        $base=self::base($ctx);
        $vista=is_string($_GET['vista']??null)?$_GET['vista']:'existencias';
        $alertas=[];$articuloContextual=null;$almacenesDisponiblesAsociacion=[];$almacenesAsociadosActuales=[];$abrirAsociacion=false;$mensajeAsociacion=null;
        if(isset($_GET['asociar'])){
            $asociar=filter_var($_GET['asociar'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$asociar){http_response_code(400);$asociar=null;$mensajeAsociacion='El artículo seleccionado no es válido.';}
            elseif(isset($_GET['articulo_id'])&&(!is_string($_GET['articulo_id'])||(int)$_GET['articulo_id']!==$asociar)){http_response_code(400);$asociar=null;$mensajeAsociacion='El artículo del filtro no coincide con el seleccionado.';}
            else{$_GET['articulo_id']=(string)$asociar;$vista='existencias';}
        }
        if (!in_array($vista,['existencias','almacenes','movimientos','catalogo','transferencias'],true) || ($vista==='catalogo'&&$ctx!=='global')) { http_response_code(404); return; }
        if($vista==='catalogo'){
            $q=is_string($_GET['q']??null)?$_GET['q']:'';$volver=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx);
            header('Location: '.$base.'/catalogo?'.http_build_query(array_filter(['q'=>$q,'volver'=>$volver])),true,302);return;
        }
        $filtros=[];
        foreach (['q','negocio','almacen_id','articulo_id','desde','hasta','tipo','pagina','transferencia_pagina'] as $campo) if (isset($_GET[$campo]) && $_GET[$campo]!=='') $filtros[$campo]=$_GET[$campo];
        if(array_key_exists('almacen_ids',$_GET) || isset($_GET['seleccion'])) $filtros['almacen_ids']=$_GET['almacen_ids']??[];
        if (isset($_GET['solo_alertas'])) $filtros['solo_alertas']=true;
        $alertas=$_SESSION['_almacenes_flash'][$ctx]??$alertas;
        unset($_SESSION['_almacenes_flash'][$ctx]);
        $datos=['existencias'=>['articulos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>25], 'almacenes'=>['almacenes'=>[],'total'=>0], 'movimientos'=>['movimientos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>25], 'catalogo'=>['articulos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>100], 'opcionesAlmacen'=>[], 'totalOpcionesAlmacen'=>0];
        try {
            foreach ($filtros as $campo=>$v) if ($campo!=='almacen_ids' && !is_scalar($v)) throw new InventarioError('FILTRO_INVALIDO','Los filtros no son válidos.');
            $datos=self::servicio()->consultar($ctx,$filtros,$vista);
        } catch (\Throwable $e) {
            http_response_code($e instanceof InventarioError && in_array($e->codigo,['PERMISO_DENEGADO','AMBITO_DENEGADO','RECURSO_NO_ENCONTRADO'],true)?403:422);
            $alertas['error'][]=$e instanceof InventarioError?$e->getMessage():'No se pudo consultar Almacenes.';
            foreach($filtros as $campo=>$valor) if(!is_scalar($valor)) unset($filtros[$campo]);
        }
        $articuloId=filter_var($filtros['articulo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($articuloId&&isset($datos['catalogo']['articulos'])){
            foreach($datos['catalogo']['articulos'] as $item)if((int)$item['id']===$articuloId){$articuloContextual=$item;break;}
            if($articuloContextual){
                if(!(int)$articuloContextual['activo'])$mensajeAsociacion='El artículo está inactivo y no puede asociarse.';
                $estadoArticulo=ArticulosM2::estadoAdministrativo($ctx,(int)$articuloId);
                $almacenable=(int)($estadoArticulo['configuracion']['almacenable']??$estadoArticulo['efectivo']['almacenable']??0)===1;
                if((int)$articuloContextual['activo']===1&&!$almacenable){$mensajeAsociacion='Este artículo no tiene habilitada la capacidad de almacenamiento y no puede asociarse a un almacén.';$almacenesDisponiblesAsociacion=[];}
                $activosContexto=array_values(array_filter($datos['opcionesAlmacen']??[],static fn($a)=>(string)$a['slug']===$ctx&&(int)$a['activo']===1));
                $opcionesPorId=[];foreach($datos['opcionesAlmacen']??[] as $opcion)$opcionesPorId[(int)$opcion['almacen_id']]=$opcion;
                $relaciones=array_values(array_filter(ArticulosM2::usos($ctx,(int)$articuloId)['asociaciones']??[],static fn($a)=>(string)($a['slug']??'')===$ctx));
                $ids=[];foreach($relaciones as $relacion)$ids[(int)$relacion['id']]=true;
                foreach($relaciones as $relacion){$opcion=$opcionesPorId[(int)$relacion['id']]??null;if((int)$relacion['activo']!==1)$almacenesAsociadosActuales[]=$relacion['nombre'].' (asociación inactiva)';elseif($opcion&&(int)$opcion['activo']!==1)$almacenesAsociadosActuales[]=$relacion['nombre'].' (almacén inactivo)';elseif($opcion)$almacenesAsociadosActuales[]=$relacion['nombre'];}
                $propios=array_values(array_filter($activosContexto,static fn($a)=>!isset($ids[(int)$a['almacen_id']])));
                $almacenesDisponiblesAsociacion=$almacenable?$propios:[];
                if(isset($asociar)&&$asociar){
                    if(!(int)$articuloContextual['activo'])$mensajeAsociacion='El artículo está inactivo y no puede asociarse.';
                    elseif(!$almacenable)$mensajeAsociacion='Este artículo no tiene habilitada la capacidad de almacenamiento y no puede asociarse a un almacén.';
                    elseif(!Auth::puede('inventario','editar',$ctx))$mensajeAsociacion='Para asociar artículos necesitas permiso de edición de Almacenes en este contexto.';
                    elseif(!$activosContexto)$mensajeAsociacion='No hay almacenes activos en este contexto. Configura uno antes de asociar el artículo.';
                    elseif(!$propios)$mensajeAsociacion='No hay almacenes activos disponibles para una asociación nueva. Revisa las asociaciones existentes de este artículo.';
                    else $abrirAsociacion=true;
                }
            }elseif(isset($asociar)&&$asociar){http_response_code(404);$mensajeAsociacion='No se encontró el artículo seleccionado.';}
        }
        $perfil=ContextoNegocio::exigir('inventario','ver',$ctx);
        $facultades=$perfil['ambitos'][$ctx]['facultades']??[];
        AdminController::render('almacenes/index',array_merge($datos,[
            'activeModule'=>'inventario','title'=>'Almacenes','topbarTitle'=>'Almacenes','topbarSection'=>'Almacenes',
            'contextoAlmacenes'=>$ctx,'nombreContexto'=>Contextos::NOMBRES[$ctx], 'base'=>$base,'vista'=>$vista,'filtros'=>$filtros,'alertas'=>$alertas,
            'volverContextual'=>\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx),
            'articuloContextual'=>$articuloContextual,'almacenesDisponiblesAsociacion'=>$almacenesDisponiblesAsociacion,'almacenesAsociadosActuales'=>$almacenesAsociadosActuales,'abrirAsociacion'=>$abrirAsociacion,'mensajeAsociacion'=>$mensajeAsociacion,
            'puedeEditar'=>Auth::puede('inventario','editar',$ctx),
            'puedeCrearAlmacen'=>$ctx==='global'&&Auth::puede('inventario','editar',$ctx)&&in_array('almacenes.crear',$facultades,true),
            'puedeNuevoArticulo'=>Auth::puede('inventario','editar',$ctx)&&Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$perfil['ambitos']['global']['facultades']??[],true),
            'puedeCatalogo'=>$ctx==='global'&&Auth::puede('inventario','editar',$ctx)&&in_array('catalogo.editar',$facultades,true),
            'csrf'=>self::csrfContexto($ctx), 'styles'=>['/build/css/admin/inventario.css'], 'scripts'=>['/build/js/admin/inventario.js'],
        ]));
    }

    public static function guardar(Router $router): void
    {
        $ctx=ContextoNegocio::slug();
        ContextoNegocio::exigir('inventario','editar',$ctx);
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { http_response_code(405); return; }
        if (!is_string($_POST['admin_csrf']??null) || !hash_equals(self::csrfContexto($ctx),trim($_POST['admin_csrf']))) { http_response_code(403); echo 'La sesión del formulario venció. Recarga la página.'; return; }
        $accion=is_string($_POST['accion']??null)?$_POST['accion']:'';
        $payload=$_POST;unset($payload['volver_contextual']);
        $resultado=self::servicio()->guardar($ctx,$accion,$payload);
        $vista=is_string($_POST['volver_vista']??null)?$_POST['volver_vista']:'existencias';
        if (!in_array($vista,['existencias','almacenes','movimientos','catalogo','transferencias'],true)||($vista==='catalogo'&&$ctx!=='global')) $vista='existencias';
        try {
            AdministracionAlmacenes::exigirResultado($resultado);
            $_SESSION['_almacenes_flash'][$ctx]=['exito'=>['Operación confirmada correctamente.']];
        } catch (\Throwable $e) { $_SESSION['_almacenes_flash'][$ctx]=['error'=>[$e instanceof InventarioError?$e->getMessage():'No se confirmó la operación.']]; }
        $volver=[];
        if(is_string($_POST['volver_filtros']??null) && strlen($_POST['volver_filtros'])<=8000) {
            parse_str($_POST['volver_filtros'],$volver);
            $volver=array_intersect_key($volver,array_flip(['q','negocio','almacen_id','almacen_ids','articulo_id','solo_alertas','desde','hasta','tipo','pagina']));
        }
        $retorno=\Services\Security\RetornoAdministrativo::autorizado($_POST['volver_contextual']??null,$ctx);if($retorno)$volver['volver']=$retorno;
        header('Location: '.self::base($ctx).'?'.http_build_query(['vista'=>$vista]+$volver),true,303);
    }

    public static function base(string $ctx): string { return '/admin/'.($ctx==='restaurante'?'':$ctx.'/').'almacenes'; }
    /** El mismo secreto CSRF existente, vinculado a la superficie y ámbito de destino. */
    private static function csrfContexto(string $ctx): string { return hash_hmac('sha256','almacenes:'.$ctx,AdminCsrfService::token()); }
    private static function servicio(): AdministracionAlmacenes { return new AdministracionAlmacenes(ContextoNegocio::db(),ContextoNegocio::actorId(),ContextoNegocio::nivelAuth()); }
}
