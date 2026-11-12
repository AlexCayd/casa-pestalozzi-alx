<?php
declare(strict_types=1);
namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Inventory\Articulos as A;
use Services\Inventory\AlmacenesConsumo;
use Services\Inventory\InventarioError;
use Services\Menu\PresentacionesOferta as P;
use Services\Security\ContextoNegocio as C;
use Services\Security\Contextos;
use Services\Security\AdminCsrfService;

/** Extensión contextual de Catálogo/Recetas/Menú, con los mismos componentes. */
final class AdminArticulosController
{
    public static function despachar(Router $router): void
    {
        try{$ruta=(string)($_SERVER['PATH_INFO']??'');if(str_ends_with($ruta,'/presentaciones'))self::presentaciones($router);elseif(str_ends_with($ruta,'/editar'))self::ficha($router);else self::index($router);}
        catch(\Throwable $e){
            $codigo=$e instanceof InventarioError?$e->codigo:'';
            http_response_code(in_array($codigo,['ID_INVALIDO','FILTRO_INVALIDO'],true)?400:(in_array($codigo,['ARTICULO_INVALIDO','OFERTA_DENEGADA','VERSION_DENEGADA'],true)?404:($e instanceof InventarioError?403:500)));
            self::errorVista( $e instanceof InventarioError?self::mensaje($e):'No se pudo cargar la información. Recarga la página o vuelve al inicio.');
        }
    }
    public static function index(Router $router): void
    {
        $ctx=C::slug();C::exigir('productos','ver',$ctx);
        $q=is_string($_GET['q']??null)?trim($_GET['q']):'';
        $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
        $estado=is_string($_GET['estado']??null)?$_GET['estado']:'todos';
        $tipo=is_string($_GET['tipo']??null)?$_GET['tipo']:'todos';
        $alertas=[];
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;
            try{$sub=filter_var($_POST['subreceta_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$art=filter_var($_POST['articulo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$sub||!$art)throw new InventarioError('REFERENCIA_INVALIDA','Elige la preparación y el artículo.');$r=A::vincularPreparacion($ctx,$sub,$art,($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][A::base($ctx).'/editar:'.$r['articulo_id']]='Preparación vinculada correctamente.';header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$r['articulo_id'].'&guardado=1'),true,303);return;}catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo asociar la preparación. Revisa las identidades.';}
        }
        $creado=($_SERVER['REQUEST_METHOD']??'GET')==='GET'?($_SESSION['_m6_alta'][$ctx]??null):null;unset($_SESSION['_m6_alta'][$ctx]);
        self::render('articulos',A::catalogo($ctx,$q,$pagina,$estado,$tipo)+['q'=>$q,'estado'=>$estado,'pendientes'=>A::preparacionesPendientes($ctx),'alertas'=>$alertas,'articuloCreado'=>$creado]+self::opcionesPendientes($ctx)+self::alta($ctx));
    }
    /**
     * Permisos y datos del alta en un paso (§18), iguales en el diálogo de la lista y
     * en la página de respaldo. El escritor vuelve a comprobarlos: esto sólo decide
     * qué se ofrece.
     */
    private static function alta(string $ctx): array
    {
        $perfil=C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);$fac=$perfil['ambitos']['global']['facultades']??[];
        $crear=Auth::puede('inventario','editar',$ctx)&&Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$fac,true);
        return [
            'puedeCrearArticulo'=>$crear,
            // Marcar «Se prepara en casa» guarda capacidades: pide edición de Artículos aquí.
            'puedePrepararAlta'=>$crear&&Auth::puede('productos','editar',$ctx),
            'almacenesAlta'=>$crear?(new \Services\Inventory\AdministracionAlmacenes(C::db(),C::actorId(),C::nivelAuth()))->almacenesEditables():[],
        ];
    }
    private static function opcionesPendientes(string $ctx): array
    {
        return A::preparacionesPendientes($ctx)?['opcionesArticulos'=>A::sql('SELECT id,nombre,unidad FROM ingredientes ORDER BY nombre,id')->get_result()->fetch_all(MYSQLI_ASSOC)]:['opcionesArticulos'=>[]];
    }
    /** Superficie persistente del mismo maestro; el escritor sigue siendo Almacenes. */
    public static function catalogoMaestro(Router $router): void
    {
        try{self::maestro($router);}catch(\Throwable $e){http_response_code($e instanceof InventarioError?($e->codigo==='ARTICULO_INVALIDO'?404:(in_array($e->codigo,['ID_INVALIDO','FILTRO_INVALIDO'],true)?400:403)):500);self::errorVista($e instanceof InventarioError?$e->getMessage():'No se pudo cargar el catálogo.');}
    }
    private static function maestro(Router $router): void
    {
        $ctx=C::slug();$perfil=C::exigir('inventario','ver',$ctx);
        $base=AdminAlmacenesController::base($ctx).'/catalogo';$ruta=(string)($_SERVER['PATH_INFO']??'');
        $nuevo=str_ends_with($ruta,'/nuevo');$ficha=$nuevo||str_ends_with($ruta,'/editar');
        $fac=$perfil['ambitos']['global']['facultades']??[];$permisosAlta=self::alta($ctx);$alta=$permisosAlta['puedeCrearArticulo'];
        $editar=$ctx==='global'&&$alta;if($nuevo&&!$alta){http_response_code(403);self::errorVista('El alta requiere edición de Almacenes y la facultad de catálogo Global.');return;}
        $volver=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx);
        $post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$id=$nuevo?0:($ficha?self::id():0);
        $puedeProductos=Auth::puede('productos','ver',$ctx);$puedeInventarioEditar=Auth::puede('inventario','editar',$ctx);
        $q=is_string($_GET['q']??null)?trim($_GET['q']):'';$pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;$estado=is_string($_GET['estado']??null)?$_GET['estado']:'todos';$tipo=is_string($_GET['tipo']??null)?$_GET['tipo']:'todos';
        $fichaUnica=static fn(int $articulo,string $ancla=''):string=>A::base($ctx).'/editar?'.http_build_query(array_filter(['id'=>$articulo,'volver'=>$volver])).$ancla;
        // Una sola lista y una sola ficha (§18): quien ve Artículos y preparaciones llega a
        // ellas; el catálogo sólo queda como pantalla para quien tiene Almacenes y nada más.
        if(!$post&&!$nuevo&&$puedeProductos){
            $filtros=array_filter(['q'=>$q,'estado'=>$estado==='todos'?'':$estado,'tipo'=>$tipo==='todos'?'':$tipo,'pagina'=>$pagina>1?$pagina:null,'volver'=>$volver]);
            header('Location: '.($ficha?$fichaUnica($id):A::base($ctx).($filtros?'?'.http_build_query($filtros):'')),true,303);return;
        }
        $alertas=!$post?($_SESSION['_almacenes_flash'][$ctx]??[]):[];unset($_SESSION['_almacenes_flash'][$ctx]);$mensaje=null;
        $datos=['nombre'=>'','unidad'=>'g','contenido_pieza'=>'','costo'=>'','activo'=>1,'tipo'=>'insumo'];
        if($id){$datos=A::sql('SELECT id,nombre,unidad,contenido_pieza,activo'.($ctx==='global'?',costo':'').' FROM ingredientes WHERE id=?',[$id])->get_result()->fetch_assoc();if(!$datos)throw new InventarioError('ARTICULO_INVALIDO','El artículo no existe.');}
        if($post){
            if(!$ficha){http_response_code(405);return;}
            if(!self::csrf())return;
            if(!($nuevo?$alta:$editar)){http_response_code(403);self::errorVista('No tienes autorización para modificar la identidad maestra.');return;}
            $campos=array_flip(['nombre','unidad','contenido_pieza','costo','activo']);
            $datos=array_intersect_key($_POST,$campos);$datos['activo']=$nuevo||isset($_POST['activo'])?1:0;
            $payload=array_intersect_key($_POST,$campos);if($id)$payload['articulo_id']=$id;
            // Un artículo nace activo: el alta no pregunta lo que casi nunca se cambia.
            if($nuevo)$payload['activo']='1';
            // Sin costo capturado el artículo nace con costo desconocido (cero), no con un error.
            if($nuevo&&trim((string)($payload['costo']??''))==='')$payload['costo']='0';
            $tipoAlta=$nuevo&&is_string($_POST['tipo']??null)?$_POST['tipo']:'insumo';$datos['tipo']=$tipoAlta;
            // El alta puede asociar el artículo a varios almacenes en el mismo paso.
            $almacenesAlta=$nuevo&&is_array($_POST['almacen_ids']??null)?array_values($_POST['almacen_ids']):[];if($almacenesAlta)$payload['almacen_ids']=$almacenesAlta;
            try{
                if(!in_array($tipoAlta,A::TIPOS,true))throw new InventarioError('TIPO_INVALIDO','Elige si el artículo se compra o se prepara en casa.');
                if($tipoAlta==='preparacion'&&!$permisosAlta['puedePrepararAlta'])throw new InventarioError('PERMISO_DENEGADO','Registrar una preparación requiere edición de Artículos y preparaciones. Créalo como artículo que se compra o coordina el alta.');
                $servicio=new \Services\Inventory\AdministracionAlmacenes(C::db(),C::actorId(),C::nivelAuth());
                $r=$servicio->guardar($ctx,$nuevo?'crear_articulo':'editar_articulo',$payload);
                \Services\Inventory\AdministracionAlmacenes::exigirResultado($r);$guardado=(int)($r['articulo_id']??$id);
                if(!$nuevo){
                    if($puedeProductos){$_SESSION['_m2_resultado'][A::base($ctx).'/editar:'.$guardado]='Datos generales guardados.';header('Location: '.$fichaUnica($guardado),true,303);return;}
                    $_SESSION['_ux4_catalogo'][$ctx.':'.$guardado]='Identidad maestra guardada.';header('Location: '.$base.'/editar?id='.$guardado.($volver?'&'.http_build_query(['volver'=>$volver]):''),true,303);return;
                }
                $n=count((array)($r['almacenes_asociados']??[]));
                $creado=['articulo_id'=>$guardado,'nombre'=>trim((string)($payload['nombre']??'')),'almacenes'=>$n,'tipo'=>$tipoAlta,'aviso'=>null];
                // La clasificación es una segunda transacción: si falla, el artículo ya existe
                // como insumo utilizable y se dice así, sin fingir que no se creó.
                if($tipoAlta==='preparacion'){
                    try{self::resultado(A::clasificar($ctx,$guardado,['tipo'=>'preparacion'],0));}
                    catch(\Throwable $e){$creado['tipo']='insumo';$creado['aviso']='Se creó como artículo que se compra: no se pudo marcar como preparación. '.($e instanceof InventarioError?$e->getMessage():'Cámbialo desde su ficha.');}
                }
                if($creado['tipo']==='preparacion'&&$puedeProductos){
                    $_SESSION['_m2_resultado'][A::base($ctx).'/editar:'.$guardado]='Preparación creada'.($n>0?' y asociada a '.$n.($n===1?' almacén':' almacenes'):'').'. Falta publicar su receta.';
                    header('Location: '.$fichaUnica($guardado,'#elaboracion'),true,303);return;
                }
                // El aviso de alta sólo lo pinta la lista. Quien llegó desde otra pantalla
                // (Almacenes) aterriza en la ficha del artículo nuevo, que conserva su regreso.
                $lista=$puedeProductos?A::base($ctx):$base;
                if($volver&&parse_url($volver,PHP_URL_PATH)!==$lista){
                    $aviso='Artículo creado'.($n>0?' y asociado a '.$n.($n===1?' almacén':' almacenes'):' sin existencias').'.'.($creado['aviso']?' '.$creado['aviso']:'');
                    if($puedeProductos){$_SESSION['_m2_resultado'][A::base($ctx).'/editar:'.$guardado]=$aviso;header('Location: '.$fichaUnica($guardado),true,303);return;}
                    $_SESSION['_ux4_catalogo'][$ctx.':'.$guardado]=$aviso;header('Location: '.$base.'/editar?'.http_build_query(['id'=>$guardado,'volver'=>$volver]),true,303);return;
                }
                $_SESSION['_m6_alta'][$ctx]=$creado;
                header('Location: '.($volver?:$lista),true,303);return;
            }catch(\Throwable $e){
                $error=$e instanceof InventarioError?$e->getMessage():'No se pudo guardar el artículo. Revisa los datos.';
                // La identidad se edita dentro de la ficha única: el error vuelve a ella con lo capturado.
                if(!$nuevo&&$puedeProductos){$_SESSION['_m6_identidad'][$ctx.':'.$id]=['error'=>$error,'datos'=>array_map(static fn($v)=>is_scalar($v)?(string)$v:'',$datos)];header('Location: '.$fichaUnica($id,'#identidad'),true,303);return;}
                http_response_code(422);$alertas['error'][]=$error;
            }
        }else{$mensaje=$_SESSION['_ux4_catalogo'][$ctx.':'.$id]??null;unset($_SESSION['_ux4_catalogo'][$ctx.':'.$id]);}
        $puedeInventarioVer=Auth::puede('inventario','ver',$ctx);
        $creado=!$post&&!$ficha?($_SESSION['_m6_alta'][$ctx]??null):null;if(!$post&&!$ficha)unset($_SESSION['_m6_alta'][$ctx]);
        $data=$ficha
            ?['identidad'=>$datos,'nuevo'=>$nuevo,'uso'=>$id?A::dondeSeUsa($ctx,$id):null,'estadoEfectivo'=>$id?A::estadoAdministrativo($ctx,$id):null]
            :A::catalogo($ctx,$q,$pagina,$estado,$tipo)+['q'=>$q,'estado'=>$estado,'pendientes'=>[],'articuloCreado'=>$creado];
        AdminController::render('recetas/'.($ficha?'articulo-identidad':'articulos'),$data+$permisosAlta+['title'=>$ficha?($nuevo?'Nuevo artículo':'Identidad del artículo'):'Catálogo de artículos','topbarSection'=>'Catálogo de artículos','activeModule'=>'inventario','ctx'=>$ctx,'maestro'=>true,'almacenesMarcados'=>array_map('intval',is_array($_POST['almacen_ids']??null)?$_POST['almacen_ids']:[]),'puedeIdentidad'=>$editar,'puedeProductos'=>$puedeProductos,'puedeEditar'=>false,'puedeInventarioVer'=>$puedeInventarioVer,'puedeInventarioEditar'=>$puedeInventarioEditar,'baseArticulos'=>A::base($ctx),'baseCatalogo'=>$base,'baseAlmacenes'=>AdminAlmacenesController::base($ctx),'volverContextual'=>$volver,'csrf'=>self::token($ctx),'alertas'=>$alertas,'mensajeExito'=>$mensaje,'styles'=>['/build/css/admin/recetas.css','/build/css/admin/menu.css'],'scripts'=>['/build/js/admin/recipe-builder.js']]);
    }
    public static function ficha(Router $router): void
    {
        $ctx=C::slug();C::exigir('productos','ver',$ctx);$id=self::id();$f=A::ficha($ctx,$id);$alertas=[];$preview=null;
        if(isset($_GET['version'])||isset($_GET['comparar'])){
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);self::errorVista('Las versiones publicadas se consultan sin modificarlas. Abre la ficha actual para preparar una versión nueva.');return;}
            $version=filter_var($_GET['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$version)throw new InventarioError('ID_INVALIDO','Selecciona una versión publicada válida.');
            $consulta=A::consultarVersion($ctx,$id,$version);$comparacion=null;
            if(isset($_GET['comparar'])){$comparar=filter_var($_GET['comparar'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$comparar)throw new InventarioError('ID_INVALIDO','Selecciona una versión válida para comparar.');$comparacion=A::consultarVersion($ctx,$id,$comparar);}
            self::render('articulo-version',$f+['versionConsultada'=>$consulta,'versionComparada'=>$comparacion]+self::opciones($ctx));return;
        }
        $raw=['rendimiento'=>'1.000','unidad'=>$f['articulo']['unidad'],'procedencia'=>'Elaboración propia','componentes'=>[]];
        if($f['versiones']){$r=A::cargarReceta((int)$f['versiones'][0]['id'],C::unidad($ctx));$raw=array_intersect_key($r,$raw);}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!self::csrf())return;C::exigir('productos','editar',$ctx,true);
            try{
                $accion=self::valor($_POST['accion']??'');$revision=self::revision();
                if($accion==='clasificar'){
                    // «¿Cómo se obtiene?» y estado; las opciones avanzadas sólo viajan si se muestran,
                    // y las físicas sólo se leen de quien puede cambiarlas: un campo deshabilitado
                    // no llega y no debe interpretarse como «apagar».
                    $datos=['tipo'=>self::valor($_POST['tipo']??''),'activo'=>isset($_POST['activo'])?1:0];
                    if(($_POST['avanzadas']??'')==='1'){
                        $datos['componente']=isset($_POST['componente'])?1:0;
                        if(Auth::puede('inventario','editar',$ctx))foreach(['almacenable','costo_conocido'] as $k)$datos[$k]=isset($_POST[$k])?1:0;
                    }
                    $r=A::clasificar($ctx,$id,$datos,$revision);self::resultado($r);
                    $_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]=$datos['tipo']==='preparacion'&&empty($f['configuracion']['elaborable'])?'Ahora es una preparación. Falta publicar su receta.':'Cambios guardados.';
                    header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$id.'&guardado=1'.($datos['tipo']==='preparacion'&&empty($f['configuracion']['elaborable'])?'#elaboracion':'')),true,303);return;
                }
                if(!in_array($accion,['prever','publicar'],true))throw new InventarioError('ACCION_INVALIDA','La acción no está disponible.');
                $raw=['rendimiento'=>$_POST['rendimiento']??'','unidad'=>$_POST['unidad']??'','procedencia'=>$_POST['procedencia']??'','componentes'=>self::filas(),'huella'=>$_POST['huella']??''];
                $preview=A::prever($ctx,$id,$raw);
                if($accion==='publicar'){$r=A::publicar($ctx,$id,$raw,$revision,self::valor($_POST['clave']??''),($_POST['confirmar']??'')==='1');self::resultado($r);$_SESSION['_m2_resultado'][(string)($_SERVER['PATH_INFO']??'').':'.(string)($_GET['id']??'')]='Cambios confirmados correctamente.';header('Location: '.self::conRetorno(A::base($ctx).'/editar?id='.$id.'&guardado=1'),true,303);return;}
            }catch(\Throwable $e){http_response_code(422);$alertas['error'][]= $e instanceof InventarioError?self::mensaje($e):'No se pudo preparar la elaboración. Revisa los campos y vuelve a intentar.';if(($accion??'')==='clasificar'&&isset($datos)){$f['configuracion']=array_replace($f['configuracion'],array_diff_key($datos,['tipo'=>1]));$f['tipoCapturado']=in_array($datos['tipo']??'',A::TIPOS,true)?$datos['tipo']:null;}}
        }
        // Error de identidad devuelto por el escritor del catálogo (PRG): se muestra aquí con lo capturado.
        $identidad=$f['articulo'];
        if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$pendiente=$_SESSION['_m6_identidad'][$ctx.':'.$id]??null;unset($_SESSION['_m6_identidad'][$ctx.':'.$id]);if(is_array($pendiente)){$alertas['error'][]=(string)$pendiente['error'];$identidad=array_replace($identidad,array_intersect_key((array)$pendiente['datos'],array_flip(['nombre','unidad','contenido_pieza','costo','activo'])));}}
        self::render('articulo',$f+['identidad'=>$identidad,'raw'=>$raw,'preview'=>$preview,'alertas'=>$alertas,'uso'=>A::dondeSeUsa($ctx,$id),'puedeInventarioVer'=>Auth::puede('inventario','ver',$ctx),'puedeInventarioEditar'=>Auth::puede('inventario','editar',$ctx),'borradorPendiente'=>(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['accion']??'')!=='clasificar')]+self::opciones($ctx));
    }
    public static function presentaciones(Router $router): void
    {
        $ctx=C::slug();C::exigir('menu','ver',$ctx);C::exigir('productos','ver',$ctx);$u=C::vendedora($ctx);$oferta=self::id();
        $o=A::sql('SELECT p.nombre,o.precio,o.producto_id,o.tipo_venta,o.tipo_venta_estado FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id WHERE o.id=? AND o.unidad_id=?',[$oferta,$u])->get_result()->fetch_assoc();if(!$o){http_response_code(404);return;}
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
        $configuracionComercial=\Services\Menu\ComposicionComercial::consultar($ctx,$oferta);
        $recetaAnterior=$configuracionComercial['receta_id']?\Services\Menu\RecetasOferta::componentes($ctx,$oferta):[];
        self::render('presentaciones',['oferta'=>$o,'ofertaId'=>$oferta,'configuracionComercial'=>$configuracionComercial,'recetaAnterior'=>$recetaAnterior,'presentaciones'=>$lista,'presentacion'=>$p,'raw'=>$raw,'preview'=>$preview,'alertas'=>$alertas,'origenes'=>$origenes,'basePresentaciones'=>self::presentacionesBase($ctx)]+self::opciones($ctx));
    }
    private static function opciones(string $ctx): array
    {
        $u=C::unidad($ctx);$articulos=A::sql('SELECT id,nombre,unidad,activo FROM ingredientes ORDER BY nombre,id')->get_result()->fetch_all(MYSQLI_ASSOC);
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
        $global=$ctx!=='global'||(Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$facultades,true));
        $configurar=$editar&&($ctx!=='global'||(Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$facultades,true)));
        // Sólo se le explica a quien podría guardar: en solo consulta el aviso es ruido.
        $data['requiereConfiguracionFisica']=$editar&&isset($data['estadoEfectivo'])&&(int)($data['configuracion']['revision']??0)===0&&!Auth::puede('inventario','editar',$ctx)&&(!empty($data['configuracion']['almacenable'])||!empty($data['configuracion']['produccion_previa'])||!empty($data['configuracion']['costo_conocido']));
        if($data['requiereConfiguracionFisica'])$configurar=false;
        $data['volverContextual']=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx);
        $data['baseCatalogo']=AdminAlmacenesController::base($ctx).'/catalogo';
        $data['puedeProductos']=Auth::puede('productos','ver',$ctx);
        $data['puedeIdentidad']=$ctx==='global'&&Auth::puede('inventario','editar','global')&&in_array('catalogo.editar',$facultades,true);
        $data+=self::alta($ctx);
        $comercial=str_starts_with($vista,'presentaciones');
        AdminController::render('recetas/'.$vista,$data+['title'=>$comercial?'Presentaciones':'Artículos y preparaciones','topbarSection'=>($comercial?'Presentaciones':'Artículos y preparaciones').' · '.Contextos::NOMBRES[$ctx],'activeModule'=>$comercial?'menu':'productos','ctx'=>$ctx,'baseArticulos'=>A::base($ctx),'baseAlmacenes'=>AdminAlmacenesController::base($ctx),'puedeEditar'=>$editar&&$global,'puedeConfigurar'=>$configurar,'puedeInventario'=>Auth::puede('inventario','editar',$ctx),'csrf'=>self::token($ctx),'styles'=>['/build/css/admin/recetas.css','/build/css/admin/menu.css'],'scripts'=>['/build/js/admin/recipe-builder.js']]);
    }
    private static function errorVista(string $mensaje): void
    {
        $ctx=C::slug();
        $volver=\Services\Security\RetornoAdministrativo::autorizado($_GET['volver']??null,$ctx)??(Auth::puede('inventario','ver',$ctx)?AdminAlmacenesController::base($ctx).'/catalogo':A::base($ctx));
        AdminController::render('recetas/error',['title'=>'No se pudo completar la operación','activeModule'=>'productos','mensaje'=>$mensaje,'volver'=>$volver]);
    }
}
