<?php
declare(strict_types=1);
namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Inventory\Articulos as A;
use Services\Inventory\AlmacenesConsumo;
use Services\Inventory\InventarioError;
use Services\Menu\PresentacionesOferta as P;
use Services\Menu\ProductosCasa;
use Services\Security\ContextoNegocio as C;
use Services\Security\Contextos;
use Services\Security\AdminCsrfService;

/** Extensión contextual de Catálogo/Recetas/Menú, con los mismos componentes. */
final class AdminArticulosController
{
    public static function despachar(Router $router): void
    {
        try{$ruta=(string)($_SERVER['PATH_INFO']??'');if(str_ends_with($ruta,'/presentaciones')&&C::slug()==='global')self::presentacionesGlobal($router);elseif(str_ends_with($ruta,'/presentaciones'))self::presentaciones($router);elseif(str_ends_with($ruta,'/editar'))self::ficha($router);else self::index($router);}
        catch(\Throwable $e){
            $codigo=$e instanceof InventarioError?$e->codigo:'';
            http_response_code(in_array($codigo,['ID_INVALIDO','FILTRO_INVALIDO'],true)?400:(in_array($codigo,['ARTICULO_INVALIDO','OFERTA_DENEGADA'],true)?404:($e instanceof InventarioError?403:500)));
            self::errorVista( $e instanceof InventarioError?self::mensaje($e):'No se pudo cargar la información. Recarga la página o vuelve al inicio.');
        }
    }
    public static function index(Router $router): void
    {
        $ctx=C::slug();C::exigir('productos','ver',$ctx);
        $q=is_string($_GET['q']??null)?trim($_GET['q']):'';
        $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
        $alertas=[];
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;
            try{$sub=filter_var($_POST['subreceta_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$art=filter_var($_POST['articulo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$sub||!$art)throw new InventarioError('REFERENCIA_INVALIDA','Elige la preparación y el artículo.');$r=A::vincularPreparacion($ctx,$sub,$art,($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][A::base($ctx).'/editar:'.$r['articulo_id']]='Preparación vinculada correctamente.';header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$r['articulo_id'].'&guardado=1'),true,303);return;}catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo asociar la preparación. Revisa las identidades.';}
        }
        self::render('articulos',A::catalogo($ctx,$q,$pagina)+['q'=>$q,'pendientes'=>A::preparacionesPendientes($ctx),'alertas'=>$alertas]+self::opciones($ctx));
    }
    public static function ficha(Router $router): void
    {
        $ctx=C::slug();C::exigir('productos','ver',$ctx);$id=self::id();$f=A::ficha($ctx,$id);$alertas=[];$preview=null;
        $raw=['rendimiento'=>'1.000','unidad'=>$f['articulo']['unidad'],'procedencia'=>'Elaboración propia','componentes'=>[]];
        if($f['versiones']){$r=A::cargarReceta((int)$f['versiones'][0]['id'],C::unidad($ctx));$raw=array_intersect_key($r,$raw);}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;C::exigir('productos','editar',$ctx,true);
            try{
                $accion=self::valor($_POST['accion']??'');$revision=self::revision();
                if($accion==='configurar'){
                    $datos=[];foreach(['comprable','componente','elaborable','almacenable','produccion_previa','costo_conocido','activo'] as $k)$datos[$k]=isset($_POST[$k])?1:0;
                    $r=A::configurar($ctx,$id,$datos,$revision);self::resultado($r);$_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]='Cambios confirmados correctamente.';header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$id.'&guardado=1'),true,303);return;
                }
                if(!in_array($accion,['prever','publicar'],true))throw new InventarioError('ACCION_INVALIDA','La acción no está disponible.');
                $raw=['rendimiento'=>$_POST['rendimiento']??'','unidad'=>$_POST['unidad']??'','procedencia'=>$_POST['procedencia']??'','componentes'=>self::filas(),'huella'=>$_POST['huella']??''];
                $preview=A::prever($ctx,$id,$raw);
                if($accion==='publicar'){$r=A::publicar($ctx,$id,$raw,$revision,self::valor($_POST['clave']??''),($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]='Cambios confirmados correctamente.';header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$id.'&guardado=1'),true,303);return;}
            }catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo preparar la elaboración. Revisa los campos y vuelve a intentar.';if(($accion??'')==='configurar'&&isset($datos))$f['configuracion']=array_replace($f['configuracion'],$datos);}
        }
        self::render('articulo',$f+['raw'=>$raw,'preview'=>$preview,'alertas'=>$alertas,'borradorPendiente'=>(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['accion']??'')!=='configurar')]+self::opciones($ctx));
    }
    public static function presentacionesGlobal(Router $router): void
    {
        C::exigir('menu','ver','global');$producto=self::id();$v=P::previaEstandar($producto);$nombre=ProductosCasa::bloquear($producto)['nombre'];$raw=['clave'=>'','nombre'=>'','precio'=>$v['producto']['borrador']['precio'],'activo'=>0,'componentes'=>[]];$alertas=[];$preview=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;C::facultad('comercial.identidad.editar','global','menu');
            try{$raw=['clave'=>$_POST['presentacion_clave']??'','nombre'=>$_POST['nombre']??'','precio'=>$_POST['precio']??'','activo'=>isset($_POST['activo'])?1:0,'componentes'=>self::filas()];$preview=P::plan(C::unidad('global'),0,A::componentes($raw['componentes']),[],false);
                if(($_POST['accion']??'')==='publicar'){$r=P::publicarEstandar($producto,$raw,self::valor($_POST['huella']??''),self::valor($_POST['clave']??''),($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]='Cambios confirmados correctamente.';header('Location: '.self::conRetorno('/admin/global/productos/presentaciones?id='.$producto.'&guardado=1'),true,303);return;}
            }catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo publicar en todos los negocios. No se confirmó ningún cambio.';}
        }
        self::render('presentaciones-global',['productoId'=>$producto,'nombreProducto'=>$nombre,'revisionEstandar'=>$v,'raw'=>$raw,'preview'=>$preview,'alertas'=>$alertas]+self::opciones('global'));
    }
    public static function presentaciones(Router $router): void
    {
        $ctx=C::slug();C::exigir('menu','ver',$ctx);C::exigir('productos','ver',$ctx);$u=C::vendedora($ctx);$oferta=self::id();
        $o=A::sql('SELECT p.nombre,o.precio,o.producto_id FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id WHERE o.id=? AND o.unidad_id=?',[$oferta,$u])->get_result()->fetch_assoc();if(!$o){http_response_code(404);return;}
        $lista=P::listar($ctx,$oferta);$p=null;$pid=0;
        if(isset($_GET['presentacion'])){$pid=filter_var($_GET['presentacion'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$pid)throw new InventarioError('ID_INVALIDO','La presentación no es válida.');}foreach($lista as $h)if((int)$h['id']===$pid)$p=$h;
        if($pid&&!$p){http_response_code(404);return;}
        $raw=['clave'=>$p['clave']??'','nombre'=>$p['nombre']??'','precio'=>$p['precio']??$o['precio'],'activo'=>$p['activo']??0,'componentes'=>$p&&$p['version_id']?P::componentes((int)$p['version_id']):[]];$alertas=[];$preview=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;C::exigir('menu','editar',$ctx,true);C::exigir('productos','editar',$ctx,true);
            try{
                $accion=self::valor($_POST['accion']??'');
                if($accion==='origenes'){
                    $r=AlmacenesConsumo::guardar($ctx,$oferta,is_array($_POST['origenes']??null)?$_POST['origenes']:[]);self::resultado($r);
                    $resultado=bin2hex(random_bytes(16));
                    $_SESSION['_m2_origenes'][$resultado]=['ctx'=>$ctx,'oferta'=>$oferta,'presentacion'=>$pid,'fecha'=>time()];
                    foreach($_SESSION['_m2_origenes'] as $k=>$v)if(($v['fecha']??0)<time()-600)unset($_SESSION['_m2_origenes'][$k]);
                    $params=['id'=>$oferta,'resultado'=>$resultado];if($pid)$params['presentacion']=$pid;
                    $volver=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx);if($volver)$params['volver']=$volver;
                    header('Location: '.self::conRetorno(self::presentacionesBase($ctx).'?'.http_build_query($params).'#origenes'),true,303);return;
                }
                $raw=['clave'=>$_POST['presentacion_clave']??'','nombre'=>$_POST['nombre']??'','precio'=>$_POST['precio']??'','activo'=>isset($_POST['activo'])?1:0,'componentes'=>self::filas()];
                $componentes=A::componentes($raw['componentes']);$preview=P::plan($u,$oferta,$componentes,[],false);
                if($accion==='publicar'){$r=P::publicar($ctx,$oferta,$raw,self::revision(),self::valor($_POST['clave']??''),($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]='Cambios confirmados correctamente.';header('Location: '.self::conRetorno(self::presentacionesBase($ctx).'?id='.$oferta.'&presentacion='.$r['presentacion_id'].'&guardado=1'),true,303);return;}
                if($accion!=='prever')throw new InventarioError('ACCION_INVALIDA','La acción no está disponible.');
            }catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo guardar la presentación. Revisa los datos.';}
        }
        $origenes=AlmacenesConsumo::paraFormulario($ctx,$oferta);
        self::render('presentaciones',['oferta'=>$o,'ofertaId'=>$oferta,'presentaciones'=>$lista,'presentacion'=>$p,'raw'=>$raw,'preview'=>$preview,'alertas'=>$alertas,'origenes'=>$origenes,'protegida'=>!ProductosCasa::permite((int)$o['producto_id'],'receta'),'basePresentaciones'=>self::presentacionesBase($ctx)]+self::opciones($ctx));
    }
    private static function opciones(string $ctx): array
    {
        $u=C::unidad($ctx);$articulos=A::sql('SELECT id,nombre,unidad FROM ingredientes WHERE activo=1 ORDER BY nombre,id')->get_result()->fetch_all(MYSQLI_ASSOC);
        $versiones=A::sql("SELECT r.id,r.articulo_id,r.version,r.rendimiento,r.unidad,i.nombre,u.slug FROM articulo_recetas r JOIN ingredientes i ON i.id=r.articulo_id JOIN unidades u ON u.id=r.unidad_id WHERE r.publicada=1 AND (r.unidad_id=? OR u.slug='global') ORDER BY i.nombre,r.version DESC",[$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['opcionesArticulos'=>$articulos,'opcionesVersiones'=>$versiones];
    }
    private static function mensaje(InventarioError $e): string
    {
        return $e->codigo==='PRODUCCION_PENDIENTE'?'Esta presentación requiere existencias terminadas. La configuración está disponible; la producción física todavía no está disponible.':$e->getMessage();
    }
    private static function filas(): array { $v=$_POST['componentes']??[];if(!is_array($v)||count($v)>100)throw new InventarioError('COMPONENTES_INVALIDOS','Revisa la lista de componentes.');return array_values($v); }
    private static function id(): int {$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InventarioError('ID_INVALIDO','El recurso no es válido.');return $id;}
    private static function revision(): int {$v=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($v===false)throw new InventarioError('REVISION_INVALIDA','Recarga la ficha antes de guardar.');return $v;}
    private static function valor(mixed $v): string {if(!is_string($v))throw new InventarioError('CAMPO_INVALIDO','Revisa los datos enviados.');return $v;}
    private static function resultado(array $r): void {if(empty($r['ok']))throw new InventarioError($r['codigo']??'OPERACION_RECHAZADA',$r['mensaje']??'No se confirmó la operación.');}
    private static function csrf(): bool {if(!is_string($_POST['m2_csrf']??null)||!hash_equals(self::token(C::slug()),$_POST['m2_csrf'])){http_response_code(403);self::errorVista('La sesión del formulario venció. Recarga la página.');return false;}return true;}
    private static function token(string $ctx): string {return hash_hmac('sha256','articulos:'.$ctx,AdminCsrfService::token());}
    private static function presentacionesBase(string $ctx): string {return ($ctx==='restaurante'?'/admin':'/admin/'.$ctx).'/menu/presentaciones';}
    private static function conRetorno(string $url): string
    {
        $volver=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,C::slug());if(!$volver)return $url;
        $u=parse_url($url);parse_str($u['query']??'',$q);$q['volver']=$volver;
        return $u['path'].'?'.http_build_query($q).(isset($u['fragment'])?'#'.$u['fragment']:'');
    }
    private static function render(string $vista,array $data): void
    {
        $mensajeExito = null;
        $flashClave = (string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'');
        if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') $mensajeExito=$_SESSION['_m2_resultado'][$flashClave]??null;
        unset($_SESSION['_m2_resultado'][$flashClave]);
        $resultado=$_GET['resultado']??null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&is_string($resultado)&&preg_match('/^[a-f0-9]{32}$/D',$resultado)){
            $r=$_SESSION['_m2_origenes'][$resultado]??null;
            if($r&&$r['ctx']===C::slug()&&$r['oferta']===(int)($_GET['id']??0)&&$r['presentacion']===(int)($_GET['presentacion']??0)&&$r['fecha']>=time()-600){$mensajeExito='Orígenes de consumo guardados correctamente.';unset($_SESSION['_m2_origenes'][$resultado]);}
        }
        $data['mensajeExito']=$mensajeExito;
        $ctx=C::slug();$editar=Auth::puede('productos','editar',$ctx);$perfil=C::exigir('productos','ver',$ctx);$facultades=$perfil['ambitos']['global']['facultades']??[];
        $global=$ctx!=='global'||(Auth::puede('menu','editar','global')&&in_array('comercial.identidad.editar',$facultades,true));
        $configurar=$editar&&($ctx!=='global'||(Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$facultades,true)));
        $data['volverContextual']=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx);
        $data['puedeCrearArticulo']=Auth::puede('inventario','editar',$ctx)&&Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$facultades,true);
        $comercial=str_starts_with($vista,'presentaciones');
        AdminController::render('recetas/'.$vista,$data+['title'=>$comercial?'Presentaciones':'Artículos y preparaciones','topbarSection'=>($comercial?'Presentaciones':'Artículos y preparaciones').' · '.Contextos::NOMBRES[$ctx],'activeModule'=>$comercial?'menu':'productos','ctx'=>$ctx,'baseArticulos'=>A::base($ctx),'baseAlmacenes'=>AdminAlmacenesController::base($ctx),'puedeEditar'=>$editar&&$global,'puedeConfigurar'=>$configurar,'puedeInventario'=>Auth::puede('inventario','editar',$ctx),'csrf'=>self::token($ctx),'styles'=>['/build/css/admin/recetas.css','/build/css/admin/menu.css'],'scripts'=>['/build/js/admin/recipe-builder.js']]);
    }
    private static function errorVista(string $mensaje): void
    {
        $ctx=C::slug();
        $volver=$ctx==='global'?'/admin/global/productos':($ctx==='panaderia'?'/admin/panaderia/menu':'/admin/menu');
        AdminController::render('recetas/error',['title'=>'No se pudo completar la operación','activeModule'=>'productos','mensaje'=>$mensaje,'volver'=>$volver]);
    }
}
