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
        $rutas=[$base.'/recetas/articulos',$base.'/recetas/articulos/editar',$base.'/almacenes',$base.'/almacenes/catalogo',$base.'/almacenes/catalogo/nuevo',$base.'/almacenes/catalogo/editar'];
        if($ctx!=='global')$rutas=array_merge($rutas,[$base.'/menu',$base.'/menu/edit',$base.'/menu/presentaciones',$base.'/menu/variantes',$base.'/recetas',$base.'/recetas/editar']);
        if($ctx==='restaurante')$rutas=array_merge($rutas,['/admin/recetas','/admin/recetas/editar']);
        $path=$u['path']??'';if(!in_array($path,$rutas,true)||!in_array($u['fragment']??'',['','origenes','preparaciones-anteriores','crear-preparacion','versiones','capacidades','elaboracion','identidad','usos'],true))return null;
        parse_str($u['query']??'',$q);
        if(isset($q['estado'])&&in_array($q['estado'],['todos','activos','inactivos'],true)&&!in_array($path,[$base.'/recetas/articulos',$base.'/almacenes/catalogo'],true))return null;
        if(isset($q['presentacion'])&&($ctx==='global'||!str_ends_with($path,'/menu/presentaciones')))return null;
        if((isset($q['version'])||isset($q['comparar']))&&!str_ends_with($path,'/recetas/articulos/editar'))return null;
        if(isset($q['comparar'])&&!isset($q['version']))return null;
        if(isset($q['referencia'])&&($ctx!=='global'||!str_ends_with($path,'/productos/presentaciones')))return null;
        $almacen=str_ends_with($path,'/almacenes');
        foreach($q as $key=>$v){
            if($key==='almacen_ids'){
                if(!$almacen||!is_array($v)||!array_is_list($v)||count($v)>100)return null;
                foreach($v as $ref)if(!is_string($ref)||!filter_var($ref,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return null;
                continue;
            }
            // Filtro «Tipo» de la lista de artículos (§18); en Almacenes `tipo` es el de movimientos.
            if($key==='tipo'&&$path===$base.'/recetas/articulos'){if(!is_string($v)||!in_array($v,['todos','insumo','preparacion','reventa'],true))return null;continue;}
            if(in_array($key,['vista','seleccion','solo_alertas','negocio','almacen_id','articulo_id','desde','hasta','tipo','transferencia_pagina'],true)){
                if(!$almacen||!is_string($v))return null;
                if(in_array($key,['seleccion','solo_alertas'],true)&&$v!=='1')return null;
                if($key==='vista'&&!in_array($v,['existencias','almacenes','movimientos','transferencias'],true))return null;
                if($key==='negocio'&&!in_array($v,['global','restaurante','panaderia'],true))return null;
                if(in_array($key,['almacen_id','articulo_id','transferencia_pagina'],true)&&!filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return null;
                if(in_array($key,['desde','hasta'],true)&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!($fecha=\DateTimeImmutable::createFromFormat('!Y-m-d',$v))||$fecha->format('Y-m-d')!==$v))return null;
                if($key==='tipo'&&!in_array($v,['entrada','ajuste','merma','salida','cancelacion','venta','transferencia_salida','transferencia_entrada'],true))return null;
                continue;
            }
            if(!is_string($v)||preg_match('/[\x00-\x1f\x7f]/',$v)||!in_array($key,['id','presentacion','q','pagina','page','categoria','carta','pendiente','estado','volver','referencia','version','comparar'],true))return null;
            if($key==='volver'){ $r=self::validar($v,$ctx,$autoriza,$nivel+1);if(!$r)return null;$q[$key]=$r; }
            if(in_array($key,['id','presentacion','pagina','page','categoria','version','comparar'],true)&&!filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))return null;
            if($key==='referencia'&&!preg_match('/^[a-z0-9_-]{1,40}$/D',$v))return null;
            if($key==='q'&&mb_strlen($v)>120)return null;
            if($key==='carta'&&!in_array($v,['comida','maridaje','temporada'],true))return null;
            if($key==='pendiente'&&$v!=='receta')return null;
            if($key==='estado'&&!in_array($v,['todas','con','sin','todos','activos','inactivos'],true))return null;
        }
        if(!$autoriza($path,$q))return null;
        return $path.($q?'?'.http_build_query($q):'').(!empty($u['fragment'])?'#'.$u['fragment']:'');
    }

    public static function autorizado(mixed $valor,string $ctx): ?string
    {
        return self::validar($valor,$ctx,static function(string $path,array $q)use($ctx):bool{
            if(str_contains($path,'/almacenes')){
                if(!Auth::puede('inventario','ver',$ctx))return false;
                if(str_ends_with($path,'/editar')&&!isset($q['id']))return false;
                if(str_ends_with($path,'/nuevo')){try{ContextoNegocio::exigir('inventario','editar',$ctx);ContextoNegocio::facultad('catalogo.editar','global','inventario');}catch(\Throwable){return false;}}
                if(isset($q['id'])||isset($q['articulo_id'])){$s=ActiveRecord::getDB()->prepare('SELECT id FROM ingredientes WHERE id=?');$s->execute([(int)($q['id']??$q['articulo_id'])]);if(!$s->get_result()->fetch_row())return false;}
                try{$a=new \Services\Inventory\AlcanceInventario(ActiveRecord::getDB(),ContextoNegocio::actorId(),ContextoNegocio::nivelAuth());foreach(array_merge($q['almacen_ids']??[],isset($q['almacen_id'])?[$q['almacen_id']]:[]) as $ref)$a->almacen($ctx,(int)$ref,'ver',false,false);if(isset($q['negocio'])&&$ctx!=='global'&&$q['negocio']!==$ctx)return false;}catch(\Throwable){return false;}
                return true;
            }
            $art=str_contains($path,'/recetas/articulos');$receta=str_ends_with($path,'/recetas/editar')||str_starts_with($path,'/admin/recetas');$mod=($art||$receta)?'productos':'menu';
            if(!Auth::puede($mod,'ver',$ctx))return false;
            if(!isset($q['id']))return !$receta&&!str_ends_with($path,'/editar')&&!str_ends_with($path,'/edit')&&!str_ends_with($path,'/presentaciones')&&!str_ends_with($path,'/variantes');
            $db=ActiveRecord::getDB();
            if($art){$s=$db->prepare('SELECT id FROM ingredientes WHERE id=?');$s->execute([(int)$q['id']]);if(!$s->get_result()->fetch_row())return false;foreach(['version','comparar'] as $key)if(isset($q[$key])){$s=$db->prepare("SELECT r.id FROM articulo_recetas r JOIN unidades u ON u.id=r.unidad_id WHERE r.id=? AND r.articulo_id=? AND r.publicada=1 AND (r.unidad_id=? OR u.slug='global')");$s->execute([(int)$q[$key],(int)$q['id'],ContextoNegocio::unidad($ctx)]);if(!$s->get_result()->fetch_row())return false;}return true;}
            if($ctx==='global'){
                return false;
            }
            $s=$db->prepare('SELECT id FROM ofertas_producto WHERE id=? AND unidad_id=?');$s->execute([(int)$q['id'],ContextoNegocio::unidad($ctx)]);
            if(!$s->get_result()->fetch_row())return false;
            if(str_ends_with($path,'/recetas/editar')&&!Auth::puede('productos','ver',$ctx))return false;
            if(str_ends_with($path,'/presentaciones')){
                if(!Auth::puede('productos','ver',$ctx))return false;
                if(isset($q['presentacion'])){$s=$db->prepare('SELECT id FROM oferta_presentaciones WHERE id=? AND oferta_id=?');$s->execute([(int)$q['presentacion'],(int)$q['id']]);return (bool)$s->get_result()->fetch_row();}
            }
            return true;
        });
    }
}
