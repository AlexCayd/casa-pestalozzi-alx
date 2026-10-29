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
    public static function formulario(Router $router): void {
        M::autorizar();C::exigir('menu','ver','global');$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT)?:0;
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
}
