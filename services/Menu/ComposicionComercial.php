<?php
declare(strict_types=1);
namespace Services\Menu;

use Classes\Auth;
use Services\Inventory\Articulos;
use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio as C;
use Services\Security\RetornoAdministrativo;

/** Proyección de navegación. No convierte recetas ni decide consumos. */
final class ComposicionComercial
{
    public static function consultar(string $ctx,int $oferta): array
    {
        C::exigirUno(['menu'=>'ver','productos'=>'ver'],$ctx);$u=C::vendedora($ctx);
        $o=Articulos::sql('SELECT o.producto_id,r.id receta_id,r.version,EXISTS(SELECT 1 FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 WHERE p.oferta_id=o.id AND p.unidad_id=o.unidad_id) presentaciones FROM ofertas_producto o LEFT JOIN recetas r ON r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 WHERE o.id=? AND o.unidad_id=? ORDER BY r.version DESC LIMIT 1',[$oferta,$u])->get_result()->fetch_assoc();
        if(!$o)throw new InventarioError('OFERTA_DENEGADA','El producto no pertenece al negocio activo.');
        $modo=$o['presentaciones']?'presentaciones':(ProductosCasa::publicada((int)$o['producto_id'])?'casa':($o['receta_id']?'anterior':'sin'));
        $base=($ctx==='restaurante'?'/admin':'/admin/'.$ctx).'/menu';
        $ruta=match($modo){'anterior'=>$ctx==='restaurante'?'/admin/recetas/editar':$base.'/presentaciones','casa'=>$base.'/casa-receta',default=>$base.'/presentaciones'};
        $permitido=$modo==='anterior'&&$ctx==='restaurante'?Auth::puede('productos','ver',$ctx):(Auth::puede('menu','ver',$ctx)&&Auth::puede('productos','ver',$ctx));
        $referencias=Articulos::sql('SELECT ca.articulo_id,rc.subreceta_id,i.nombre,ar.id version_id,ar.version,u.slug contexto FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id JOIN articulo_recetas ar ON ar.id=ca.version_receta_id JOIN ingredientes i ON i.id=ca.articulo_id JOIN unidades u ON u.id=ar.unidad_id WHERE r.oferta_id=? AND r.unidad_id=? AND r.activo=1 ORDER BY rc.id',[$oferta,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        $porArticulo=[];foreach($referencias as $r)$porArticulo[$r['articulo_id']][$r['version_id']]=true;
        $variasVersiones=(bool)array_filter($porArticulo,static fn($versiones)=>count($versiones)>1);
        $preparacionVinculada=(bool)array_filter($referencias,static fn($r)=>$r['subreceta_id']!==null);
        $edicionCompatible=!$variasVersiones&&!$preparacionVinculada;
        $motivoConsulta=$variasVersiones?'esta receta conserva varias versiones de una misma preparación':($preparacionVinculada?'esta receta conserva una preparación anterior vinculada a una versión publicada':'');
        return ['modo'=>$modo,'receta_id'=>$o['receta_id'],'version'=>$o['version'],
            'referencias'=>$referencias,'edicionCompatible'=>$edicionCompatible,'motivoConsulta'=>$motivoConsulta,
            'texto'=>match($modo){'presentaciones'=>'Consumo definido por presentaciones.','casa'=>'Receta de la casa o adaptación autorizada.','anterior'=>'Receta anterior vigente · Versión '.$o['version'],default=>'Sin composición. Configura artículos, cantidades y elaboraciones desde Presentaciones.'},
            'accion'=>match($modo){'anterior'=>$ctx==='restaurante'?($edicionCompatible?'Consultar o editar receta vigente':'Consultar receta vigente'):'Composición y presentaciones','casa'=>'Consultar receta de la casa',default=>'Composición y presentaciones'},
            'url'=>$permitido?$ruta.'?id='.$oferta:null];
    }
    public static function preparacionesVersionadas(string $ctx): array
    {
        C::exigir('productos','ver',$ctx);
        return array_map('intval',array_column(Articulos::sql("SELECT DISTINCT r.articulo_id FROM articulo_recetas r JOIN unidades u ON u.id=r.unidad_id WHERE r.publicada=1 AND (r.unidad_id=? OR u.slug='global')",[C::unidad($ctx)])->get_result()->fetch_all(MYSQLI_ASSOC),'articulo_id'));
    }
    public static function conRetorno(string $url,string $ctx,mixed $volver): string
    {
        $r=RetornoAdministrativo::autorizado($volver,$ctx);
        return $url.($r?'&'.http_build_query(['volver'=>$r]):'');
    }
}
