<?php
declare(strict_types=1);
namespace Services\Security;

use Classes\Auth;
use Model\ActiveRecord;

/** Retorno explícito por URL: no comparte navegación entre pestañas. */
final class RetornoAdministrativo
{
    public static function validar(mixed $valor,string $ctx,callable $autoriza,int $nivel=0): ?string
    {
        if(!in_array($ctx,['global','restaurante','panaderia'],true))return null;
        if($nivel>4||!is_string($valor)||strlen($valor)>4000||preg_match('/[\x00-\x20\\\\]/',$valor)||!str_starts_with($valor,'/')||str_starts_with($valor,'//'))return null;
        $u=parse_url($valor);if(!$u||isset($u['scheme'],$u['host'])||isset($u['host'])||isset($u['user'])||isset($u['pass']))return null;
        $base=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;
        $rutas=[$base.'/recetas/articulos',$base.'/recetas/articulos/editar'];
        if($ctx==='global')$rutas=array_merge($rutas,[$base.'/productos',$base.'/productos/edit',$base.'/productos/casa',$base.'/productos/receta',$base.'/productos/presentaciones']);
        else $rutas=array_merge($rutas,[$base.'/menu',$base.'/menu/edit',$base.'/menu/presentaciones',$base.'/menu/variantes',$base.'/menu/casa-receta']);
        if($ctx==='restaurante')$rutas=array_merge($rutas,['/admin/recetas','/admin/recetas/editar']);
        $path=$u['path']??'';if(!in_array($path,$rutas,true)||!in_array($u['fragment']??'',['','origenes','preparaciones-anteriores','crear-preparacion','versiones'],true))return null;
        parse_str($u['query']??'',$q);
        if(isset($q['presentacion'])&&($ctx==='global'||!str_ends_with($path,'/menu/presentaciones')))return null;
        if((isset($q['version'])||isset($q['comparar']))&&!str_ends_with($path,'/recetas/articulos/editar'))return null;
        if(isset($q['comparar'])&&!isset($q['version']))return null;
        if(isset($q['referencia'])&&($ctx!=='global'||!str_ends_with($path,'/productos/presentaciones')))return null;
        foreach($q as $key=>$v){
            if(!is_string($v)||preg_match('/[\x00-\x1f\x7f]/',$v)||!in_array($key,['id','presentacion','q','pagina','page','categoria','carta','pendiente','estado','volver','referencia','version','comparar'],true))return null;
            if($key==='volver'){ $r=self::validar($v,$ctx,$autoriza,$nivel+1);if(!$r)return null;$q[$key]=$r; }
            if(in_array($key,['id','presentacion','pagina','page','categoria','version','comparar'],true)&&!filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return null;
            if($key==='referencia'&&!preg_match('/^[a-z0-9_-]{1,40}$/D',$v))return null;
            if($key==='q'&&mb_strlen($v)>120)return null;
            if($key==='carta'&&!in_array($v,['comida','maridaje','temporada'],true))return null;
            if($key==='pendiente'&&$v!=='receta')return null;
            if($key==='estado'&&!in_array($v,['todas','con','sin'],true))return null;
        }
        if(!$autoriza($path,$q))return null;
        return $path.($q?'?'.http_build_query($q):'').(!empty($u['fragment'])?'#'.$u['fragment']:'');
    }

    public static function autorizado(mixed $valor,string $ctx): ?string
    {
        return self::validar($valor,$ctx,static function(string $path,array $q)use($ctx):bool{
            $art=str_contains($path,'/recetas/articulos');$mod=($art||str_starts_with($path,'/admin/recetas'))?'productos':'menu';
            if(!Auth::puede($mod,'ver',$ctx))return false;
            if(!isset($q['id']))return !str_ends_with($path,'/editar')&&!str_ends_with($path,'/edit')&&!str_ends_with($path,'/casa')&&!str_ends_with($path,'/presentaciones')&&!str_ends_with($path,'/receta')&&!str_ends_with($path,'/variantes')&&!str_ends_with($path,'/casa-receta');
            $db=ActiveRecord::getDB();
            if($art){$s=$db->prepare('SELECT id FROM ingredientes WHERE id=?');$s->execute([(int)$q['id']]);if(!$s->get_result()->fetch_row())return false;foreach(['version','comparar'] as $key)if(isset($q[$key])){$s=$db->prepare("SELECT r.id FROM articulo_recetas r JOIN unidades u ON u.id=r.unidad_id WHERE r.id=? AND r.articulo_id=? AND r.publicada=1 AND (r.unidad_id=? OR u.slug='global')");$s->execute([(int)$q[$key],(int)$q['id'],ContextoNegocio::unidad($ctx)]);if(!$s->get_result()->fetch_row())return false;}return true;}
            if($ctx==='global'){
                if(str_ends_with($path,'/presentaciones')&&!Auth::puede('productos','ver',$ctx))return false;
                if(str_ends_with($path,'/presentaciones')){try{\Services\Menu\PresentacionesOferta::previaEstandar((int)$q['id']);if(isset($q['referencia'])&&!isset(\Services\Menu\PresentacionesOferta::oficiales((int)$q['id'])[$q['referencia']]))return false;}catch(\Throwable){return false;}}
                if(preg_match('~/(casa|receta|presentaciones)$~',$path))return \Services\Menu\ProductosCasa::configuracion((int)$q['id'])!==null;
                $s=$db->prepare('SELECT id FROM productos WHERE id=?');$s->execute([(int)$q['id']]);return (bool)$s->get_result()->fetch_row();
            }
            $s=$db->prepare('SELECT id FROM ofertas_producto WHERE id=? AND unidad_id=?');$s->execute([(int)$q['id'],ContextoNegocio::unidad($ctx)]);
            if(!$s->get_result()->fetch_row())return false;
            if(str_ends_with($path,'/casa-receta')&&!Auth::puede('productos','ver',$ctx))return false;
            if(str_ends_with($path,'/presentaciones')){
                if(!Auth::puede('productos','ver',$ctx))return false;
                if(isset($q['presentacion'])){$s=$db->prepare('SELECT id FROM oferta_presentaciones WHERE id=? AND oferta_id=?');$s->execute([(int)$q['presentacion'],(int)$q['id']]);return (bool)$s->get_result()->fetch_row();}
            }
            return true;
        });
    }
}
