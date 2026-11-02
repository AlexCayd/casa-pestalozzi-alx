<?php
declare(strict_types=1);
namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Inventory\AdministracionAlmacenes;
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
        if (!in_array($vista,['existencias','almacenes','movimientos','catalogo','transferencias'],true) || ($vista==='catalogo'&&$ctx!=='global')) { http_response_code(404); return; }
        $filtros=[];
        foreach (['q','negocio','almacen_id','articulo_id','desde','hasta','tipo','pagina','transferencia_pagina'] as $campo) if (isset($_GET[$campo]) && $_GET[$campo]!=='') $filtros[$campo]=$_GET[$campo];
        if(array_key_exists('almacen_ids',$_GET) || isset($_GET['seleccion'])) $filtros['almacen_ids']=$_GET['almacen_ids']??[];
        if (isset($_GET['solo_alertas'])) $filtros['solo_alertas']=true;
        $alertas=$_SESSION['_almacenes_flash'][$ctx]??[];
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
        $perfil=ContextoNegocio::exigir('inventario','ver',$ctx);
        $facultades=$perfil['ambitos'][$ctx]['facultades']??[];
        AdminController::render('almacenes/index',array_merge($datos,[
            'activeModule'=>'inventario','title'=>'Almacenes','topbarTitle'=>'Almacenes','topbarSection'=>'Almacenes',
            'contextoAlmacenes'=>$ctx,'nombreContexto'=>Contextos::NOMBRES[$ctx], 'base'=>$base,'vista'=>$vista,'filtros'=>$filtros,'alertas'=>$alertas,
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
        $resultado=self::servicio()->guardar($ctx,$accion,$_POST);
        $vista=is_string($_POST['volver_vista']??null)?$_POST['volver_vista']:'existencias';
        if (!in_array($vista,['existencias','almacenes','movimientos','catalogo','transferencias'],true)||($vista==='catalogo'&&$ctx!=='global')) $vista='existencias';
        try {
            AdministracionAlmacenes::exigirResultado($resultado);
            $_SESSION['_almacenes_flash'][$ctx]=['exito'=>['Operación confirmada correctamente.']];
        } catch (\Throwable $e) { $_SESSION['_almacenes_flash'][$ctx]=['error'=>[$e instanceof InventarioError?$e->getMessage():'No se confirmó la operación.']]; }
        $volver=[];
        if(is_string($_POST['volver_filtros']??null) && strlen($_POST['volver_filtros'])<=8000) {
            parse_str($_POST['volver_filtros'],$volver);
            $volver=array_intersect_key($volver,array_flip(['q','almacen_ids','solo_alertas','desde','hasta','tipo','pagina']));
        }
        header('Location: '.self::base($ctx).'?'.http_build_query(['vista'=>$vista]+$volver),true,303);
    }

    public static function base(string $ctx): string { return '/admin/'.($ctx==='restaurante'?'':$ctx.'/').'almacenes'; }
    /** El mismo secreto CSRF existente, vinculado a la superficie y ámbito de destino. */
    private static function csrfContexto(string $ctx): string { return hash_hmac('sha256','almacenes:'.$ctx,AdminCsrfService::token()); }
    private static function servicio(): AdministracionAlmacenes { return new AdministracionAlmacenes(ContextoNegocio::db(),ContextoNegocio::actorId(),ContextoNegocio::nivelAuth()); }
}
