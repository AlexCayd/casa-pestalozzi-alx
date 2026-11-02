<?php
declare(strict_types=1);
namespace Controllers;
use MVC\Router;
use Model\Producto;
use Services\Menu\AdministracionMenu as M;
use Services\Menu\ProductosCasa as P;
use Services\Menu\FotoPlatillo;
use Services\Security\ContextoNegocio as C;

final class AdminProductosCasaController
{
    public static function recetario(Router $router): void {self::manejar(static function(){
        M::autorizar();C::exigir('menu','ver','global');
        if(!is_scalar($_GET['q']??'')||!is_scalar($_GET['page']??1)){http_response_code(400);return;}
        $q=trim((string)($_GET['q']??''));$pagina=max(1,(int)($_GET['page']??1));
        $where=' WHERE p.nombre LIKE ?';$params=['%'.mb_substr($q,0,100).'%'];
        $total=(int)P::sql('SELECT COUNT(*) FROM producto_estandares e JOIN productos p ON p.id=e.producto_id'.$where,$params)->get_result()->fetch_row()[0];
        $paginas=max(1,(int)ceil($total/20));$pagina=min($pagina,$paginas);
        $filas=P::sql('SELECT p.id,p.nombre,(SELECT MAX(version) FROM receta_casa_versiones v WHERE v.producto_id=p.id AND v.publicada=1) AS version,(SELECT MAX(created_at) FROM receta_casa_versiones v WHERE v.producto_id=p.id AND v.publicada=1) AS fecha,EXISTS(SELECT 1 FROM recetas_casa r WHERE r.producto_id=p.id) AS borrador FROM producto_estandares e JOIN productos p ON p.id=e.producto_id'.$where.' ORDER BY p.nombre LIMIT 20 OFFSET '.(($pagina-1)*20),$params)->get_result()->fetch_all(MYSQLI_ASSOC);
        AdminController::render('menu/casa-recetario',M::vista()+['activeModule'=>'menu','title'=>'Recetario de la casa','filasCasa'=>$filas,'q'=>$q,'pagina'=>$pagina,'paginas'=>$paginas,'styles'=>['/build/css/admin/menu.css']]);
    });}
    public static function formulario(Router $router): void {self::manejar(static fn()=>self::formularioInterno($router));}
    public static function receta(Router $router): void {self::manejar(static fn()=>self::recetaInterna($router));}
    public static function recetaLocal(Router $router): void {self::manejar(static fn()=>self::recetaLocalInterna($router));}
    private static function manejar(callable $accion): void {
        try{$accion();}catch(\Services\Inventory\InventarioError $e){http_response_code(self::status($e));$tituloAcceso='No se pudo abrir el producto';$mensajeAcceso=$e->getMessage();$destinoContexto=M::base();$textoDestino='Volver a productos';require __DIR__.'/../views/auth/contexto-denegado.php';}
    }
    private static function status(\Throwable $e):int {return $e instanceof \Services\Inventory\InventarioError&&in_array($e->codigo,['PERMISO_DENEGADO','FACULTAD_DENEGADA','AMBITO_DENEGADO','NIVEL_AUTH_REQUERIDO'],true)?403:422;}
    private static function id():int {$v=filter_var($_GET['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($v===false){http_response_code(400);exit('El identificador no es válido.');}return $v;}
    private static function formularioInterno(Router $router): void {
        M::autorizar();C::exigir('menu','ver','global');$id=self::id();
        $c=$id?P::configuracion($id):null;$v=null;$alertas=[];$foto=null;
        $d=$c?P::dato($c['borrador']):['nombre'=>'','descripcion'=>'','img'=>null,'precio'=>'0.00','activo'=>0,'tipo'=>'estandar','reglas'=>[],'negocios'=>[],'receta_version_id'=>0,'requiere_receta'=>false];
        if($id&&!$c){$p=\Services\Menu\CatalogoComercial::identidad($id);if(!$p){http_response_code(404);return;}$d=array_replace($d,$p);foreach(P::ofertas($id) as $o){C::exigir('menu','ver',$o['slug']);$d['negocios'][$o['slug']]=$o;}}
        $revision=(int)($c['revision']??0);$clave=bin2hex(random_bytes(24));
        try {
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                P::autorizar();$accion=$_POST['accion']??'';
                if($accion==='publicar'){
                    $r=P::publicar($id,(int)($_POST['revision']??0),(string)($_POST['huella']??''),(string)($_POST['clave']??''),!empty($_POST['confirmar']));
                } else {
                    $nuevo=self::datos($_POST);$dto=new Producto();$dto->id=$id;
                    $foto=FotoPlatillo::desdeFormulario($dto,$d['img']??null,$_POST,$_FILES);
                    if($foto->errores())throw new \RuntimeException(implode(' ',array_merge(...array_values($foto->errores()))));
                    $nuevo['img']=$dto->img;$d=$nuevo;
                    $r=$c?P::guardar($id,$d,(int)($_POST['revision']??0)):P::crear($d,(string)($_POST['clave']??''),$id);
                }
                if(empty($r['ok'])||empty($r['confirmado']))P::error($r['codigo']??'NO_CONFIRMADO',$r['mensaje']??'No se pudo confirmar el cambio.');
                if($foto)$foto->confirmar();$id=(int)($r['producto_id']??$id);
                header('Location: /admin/global/productos/casa?id='.$id.'&guardado=1',true,303);return;
            }
        } catch(\Throwable $e){http_response_code($e instanceof \Services\Inventory\InventarioError&&in_array($e->codigo,['PERMISO_DENEGADO','FACULTAD_DENEGADA','AMBITO_DENEGADO'],true)?403:422);$alertas['error'][]=$e instanceof \Services\Inventory\InventarioError?$e->getMessage():'No fue posible guardar. Revisa los datos y vuelve a intentar.';if($foto)$foto->revertir($dto);}
        if($id&&$c)$v=P::vistaPrevia($id);
        $negocios=[];foreach(['restaurante'=>'Restaurante','panaderia'=>'Panadería'] as $ctx=>$nombre){try{C::exigir('menu','ver',$ctx);$u=C::unidad($ctx);$negocios[$ctx]=['nombre'=>$nombre,'categorias'=>P::sql('SELECT id,nombre,carta FROM categorias WHERE unidad_id=? AND activo=1 ORDER BY carta,orden',[$u])->get_result()->fetch_all(MYSQLI_ASSOC),'areas'=>P::sql('SELECT id,nombre FROM areas_produccion WHERE unidad_id=? ORDER BY orden,id',[$u])->get_result()->fetch_all(MYSQLI_ASSOC)];}catch(\Throwable){}}
        $versiones=$id?P::sql('SELECT id,version FROM receta_casa_versiones WHERE producto_id=? ORDER BY version DESC',[$id])->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $historial=$id?P::sql('SELECT p.version,p.created_at,u.nombre FROM producto_publicaciones p JOIN usuarios u ON u.id=p.usuario_id WHERE p.producto_id=? ORDER BY p.version DESC',[$id])->get_result()->fetch_all(MYSQLI_ASSOC):[];
        AdminController::render('menu/casa-form',M::vista()+['activeModule'=>'menu','title'=>'Producto de la casa','d'=>$d,'productoCasaId'=>$id,'revision'=>$revision,'clave'=>$clave,'preview'=>$v,'negociosCasa'=>$negocios,'versionesCasa'=>$versiones,'historialCasa'=>$historial,'puedeCasa'=>\Services\Menu\CatalogoComercial::puedeEditar(),'alertas'=>$alertas,'foto'=>$foto?$foto->vista():FotoPlatillo::vistaInicial($d['img']??null),'styles'=>['/build/css/admin/menu.css']]);
    }
    public static function datos(array $p): array {
        $out=[];foreach(['nombre','descripcion','precio','activo','tipo','receta_version_id','requiere_receta'] as $k){if(isset($p[$k])&&!is_scalar($p[$k]))P::error('DATO_INVALIDO','El formulario contiene un dato inválido.');$out[$k]=$p[$k]??(in_array($k,['nombre','descripcion'])?'':0);}
        $out['reglas']=$p['reglas']??[];$out['negocios']=[];$destinos=$p['destinos']??[];
        if(!is_array($destinos))P::error('DESTINO_INVALIDO','Elige los negocios disponibles.');
        foreach($destinos as $ctx){if(!is_string($ctx)||!in_array($ctx,['restaurante','panaderia'],true))P::error('DESTINO_INVALIDO','El negocio no es válido.');$out['negocios'][$ctx]=$p['negocios'][$ctx]??[];}
        return $out;
    }
    private static function recetaInterna(Router $router): void {
        M::autorizar();C::exigir('menu','ver','global');$id=self::id();$c=P::configuracion($id);if(!$c){http_response_code(404);return;}
        $alertas=[];$r=\Services\Menu\RecetasCasa::borrador($id);$d=$r?P::dato($r['borrador']):['rendimiento'=>'1.000','unidad'=>'pza','componentes'=>[]];
        try{if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $accion=$_POST['accion']??'guardar';
            if($accion==='publicar')$result=\Services\Menu\RecetasCasa::publicar($id,(int)($_POST['revision']??0),(string)($_POST['huella']??''),(string)($_POST['clave']??''),!empty($_POST['confirmar']));
            elseif($accion==='origenes')$result=\Services\Menu\RecetasCasa::origenes($id,is_array($_POST['origenes']??null)?$_POST['origenes']:[]);
            else {$d=['rendimiento'=>$_POST['rendimiento']??null,'unidad'=>$_POST['unidad']??'pza','componentes'=>self::componentes($_POST)];$result=\Services\Menu\RecetasCasa::guardar($id,$d,(int)($_POST['revision']??0));}
            if(empty($result['ok'])||empty($result['confirmado']))P::error($result['codigo']??'RECETA_NO_GUARDADA',$result['mensaje']??'No se pudo confirmar la receta.');
            header('Location: /admin/global/productos/receta?id='.$id.'&guardado=1',true,303);return;
        }}catch(\Throwable $e){http_response_code(self::status($e));$alertas['error'][]=$e instanceof \Services\Inventory\InventarioError?$e->getMessage():'Revisa ingredientes, cantidades y rendimiento.';}
        $previa=$r?\Services\Menu\RecetasCasa::previa($id):null;$ofertas=P::ofertas($id);$origenes=[];
        foreach($ofertas as $o){C::exigir('menu','ver',$o['slug']);$origenes[$o['id']]=[];try{C::exigir('inventario','ver',$o['slug']);}catch(\Throwable){continue;}$origenes[$o['id']]=P::sql('SELECT aa.almacen_id,aa.articulo_id,a.nombre FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE aa.unidad_id=? AND aa.activo=1 AND a.activo=1 ORDER BY a.nombre',[$o['unidad_id']])->get_result()->fetch_all(MYSQLI_ASSOC);}
        $hist=P::sql('SELECT v.version,v.created_at,v.rendimiento,v.unidad,u.nombre FROM receta_casa_versiones v JOIN usuarios u ON u.id=v.usuario_id WHERE v.producto_id=? ORDER BY v.version DESC',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        AdminController::render('menu/casa-receta',M::vista()+['activeModule'=>'menu','title'=>'Receta de la casa','productoCasaId'=>$id,'d'=>$d,'revision'=>(int)($r['revision']??0),'previewReceta'=>$previa,'ingredientesCasa'=>P::sql('SELECT id,nombre,unidad FROM ingredientes WHERE activo=1 ORDER BY nombre')->get_result()->fetch_all(MYSQLI_ASSOC),'ofertasCasa'=>$ofertas,'origenesCasa'=>$origenes,'historialReceta'=>$hist,'alertas'=>$alertas,'puedeCasa'=>\Services\Menu\CatalogoComercial::puedeEditar(),'styles'=>['/build/css/admin/menu.css'],'scripts'=>['/build/js/admin/casa.js']]);
    }
    private static function recetaLocalInterna(Router $router): void {
        M::autorizar();$ctx=C::slug();C::exigir('productos','ver',$ctx);$oferta=self::id();$p=\Services\Menu\Ofertas::obtener($oferta,$ctx);if(!$p||!($oficial=P::publicada((int)$p['producto_id']))){http_response_code(404);return;}
        $alertas=[];$editable=\Classes\Auth::puede('menu','editar',$ctx)&&\Classes\Auth::puede('productos','editar',$ctx)&&!empty($oficial['reglas']['receta']);
        try{if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(($_POST['accion']??'')==='volver')$result=\Services\Menu\RecetasCasa::volver($ctx,$oferta);
            else {C::exigir('productos','editar',$ctx);$items=array_map(static fn($i)=>['tipo'=>'ingrediente','ref_id'=>$i['articulo_id'],'cantidad'=>$i['cantidad']],self::componentes($_POST));$result=\Services\Menu\RecetasOferta::guardar($ctx,$oferta,$items);}
            if(empty($result['ok']))P::error($result['codigo']??$result['error']??'RECETA_NO_GUARDADA',$result['mensaje']??'No se pudo guardar la receta.');
            header('Location: '.M::base().'/casa-receta?id='.$oferta.'&guardado=1',true,303);return;
        }}catch(\Throwable $e){http_response_code(self::status($e));$alertas['error'][]=$e instanceof \Services\Inventory\InventarioError?$e->getMessage():'No se pudo guardar la receta.';}
        $componentes=\Services\Menu\RecetasOferta::componentes($ctx,$oferta);$d=['componentes'=>array_map(static fn($i)=>['articulo_id'=>$i['ref_id'],'cantidad'=>$i['cantidad']],$componentes)];
        $linea=P::sql('SELECT l.version_id,v.version FROM recetas r JOIN receta_origen_casa l ON l.receta_id=r.id JOIN receta_casa_versiones v ON v.id=l.version_id WHERE r.oferta_id=? AND r.activo=1',[$oferta])->get_result()->fetch_assoc();
        AdminController::render('menu/casa-receta-local',M::vista()+['activeModule'=>'menu','title'=>'Receta del producto','productoLocal'=>$p,'d'=>$d,'modoReceta'=>P::locales($oferta)['receta']??($linea?'casa':'sin'),'versionAplicada'=>$linea['version']??null,'ingredientesCasa'=>P::sql('SELECT id,nombre,unidad FROM ingredientes WHERE activo=1 ORDER BY nombre')->get_result()->fetch_all(MYSQLI_ASSOC),'puedeCasa'=>$editable,'puedeRestaurar'=>\Classes\Auth::puede('menu','editar',$ctx)&&\Classes\Auth::puede('productos','editar',$ctx),'alertas'=>$alertas,'styles'=>['/build/css/admin/menu.css'],'scripts'=>['/build/js/admin/casa.js']]);
    }
    private static function componentes(array $p): array {
        $rows=$p['componentes']??[];if(!is_array($rows))P::error('COMPONENTES_INVALIDOS','Revisa los ingredientes.');$out=[];
        foreach($rows as $row){if(!is_array($row))P::error('COMPONENTE_INVALIDO','Revisa los ingredientes.');if(empty($row['articulo_id'])&&($row['cantidad']??'')==='')continue;$out[]=['articulo_id'=>$row['articulo_id']??0,'cantidad'=>$row['cantidad']??''];}return $out;
    }
}
