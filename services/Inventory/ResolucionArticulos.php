<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Único resolvedor de composición. Fracciones de enteros BCMath evitan perder
 * precisión en rendimientos anidados; solo se redondea al límite físico. */
final class ResolucionArticulos
{
    public const MAX_PROFUNDIDAD = 24;
    public const MAX_COMPONENTES = 1000;
    private const MEDIDAS = ['g'=>['masa',1], 'kg'=>['masa',1000], 'ml'=>['volumen',1], 'l'=>['volumen',1000], 'pza'=>['pieza',1]];

    public static function resolver(array $raices, int $unidad, callable $articulo, callable $receta, array $origenes = [], bool $exigirOrigen = true, bool $redondear = false): array
    {
        if ($unidad < 1 || !array_is_list($raices) || count($raices)>self::MAX_COMPONENTES) self::error('COMPOSICION_INVALIDA','La composición no es válida.');
        $lineas=[]; $traza=[]; $n=0;
        $visitar = function(array $c, array $q, array $camino, int $profundidad, string $etapa) use (&$visitar,&$lineas,&$traza,&$n,$articulo,$receta,$unidad): void {
            if (++$n>self::MAX_COMPONENTES || $profundidad>self::MAX_PROFUNDIDAD) self::error('COMPOSICION_EXCESIVA','La composición supera el límite de componentes o niveles.');
            $id=self::id($c['articulo_id']??null);
            if (in_array($id,$camino,true)) self::error('RECETA_CIRCULAR','Una preparación depende de sí misma.');
            $a=$articulo($id);
            if (!$a || empty($a['activo'])) self::error('ARTICULO_INACTIVO','Un artículo de la composición está inactivo o no existe.');
            if (isset($a['unidad_id']) && (int)$a['unidad_id']!==$unidad && empty($a['global'])) self::error('ARTICULO_AJENO','La configuración del artículo pertenece a otro negocio.');
            if (array_key_exists('componente',$a) && !$a['componente']) self::error('COMPONENTE_DENEGADO','El artículo no está habilitado como componente.');
            $q=self::producto($q,self::conversion((string)($c['unidad']??''),(string)$a['unidad']));
            $modalidad=$c['modalidad']??'';
            $version=$c['version_receta_id']??null;
            $traza[]=['articulo_id'=>$id,'modalidad'=>$modalidad,'version_receta_id'=>$version,'etapa'=>$etapa,'ruta'=>$camino,'cantidad_fraccion'=>$q,'unidad'=>$a['unidad']];
            if ($modalidad==='almacenado') {
                if ($version!==null) self::error('MODALIDAD_INVALIDA','Un artículo almacenado no ejecuta su receta.');
                if (array_key_exists('almacenable',$a) && !$a['almacenable']) self::error('ARTICULO_NO_ALMACENABLE','Habilita almacenamiento antes de consumir existencias.');
                $key=$id.':'.$etapa;
                if (!isset($lineas[$key])) $lineas[$key]=['articulo_id'=>$id,'cantidad_fraccion'=>['0','1'],'unidad'=>$a['unidad'],'etapa'=>$etapa,'costo'=>$a['costo']??null];
                $lineas[$key]['cantidad_fraccion']=self::suma($lineas[$key]['cantidad_fraccion'],$q);
                return;
            }
            if ($modalidad!=='desglosar' || !$version) self::error('MODALIDAD_INVALIDA','Elige existencias o una versión concreta de elaboración.');
            $r=$receta(self::id($version));
            if (!$r || (int)$r['articulo_id']!==$id || empty($r['publicada'])) self::error('VERSION_INVALIDA','La versión no está publicada para este artículo.');
            if ((int)$r['unidad_id']!==$unidad && empty($r['global'])) self::error('RECETA_AJENA','No se puede utilizar una receta privada de otro negocio.');
            $rendimiento=self::cantidad($r['rendimiento']??null);
            $porLote=self::producto(self::producto($q,self::conversion((string)$a['unidad'],(string)$r['unidad'])),[$rendimiento[1],$rendimiento[0]]);
            $componentes=$r['componentes']??[];
            if (!array_is_list($componentes) || $componentes===[]) self::error('RECETA_INCOMPLETA','La elaboración no tiene componentes.');
            $camino[]=$id;
            foreach ($componentes as $hijo) {
                if (!is_array($hijo)) self::error('COMPONENTE_INVALIDO','Revisa los componentes de la elaboración.');
                $merma=DecimalInventario::leer($hijo['merma']??'0.000');
                if ($merma>=100000) self::error('MERMA_INVALIDA','La merma debe ser menor de cien por ciento.');
                $consumo=self::producto(self::producto($porLote,self::cantidad($hijo['cantidad']??null)),['100000',(string)(100000-$merma)]);
                $visitar($hijo,$consumo,$camino,$profundidad+1,$etapa);
            }
        };
        foreach ($raices as $c) {
            if (!is_array($c) || !in_array($c['etapa']??'servicio',['servicio','produccion'],true)) self::error('ETAPA_INVALIDA','La etapa del componente no es válida.');
            $q=self::cantidad($c['cantidad']??null);
            $visitar($c,$q,[],0,$c['etapa']??'servicio');
        }
        $salidas=[]; $costo=['0','1']; $conocido=$lineas!==[];
        foreach ($lineas as $l) {
            $id=$l['articulo_id']; $o=$origenes[$id]??null;
            if ($exigirOrigen && (!$o || (int)($o['unidad_id']??0)!==$unidad || empty($o['activo']) || (int)($o['almacen_id']??0)<1)) self::error('ALMACEN_ORIGEN_FALTANTE','Elige un almacén propio activo para cada artículo requerido.');
            $cantidad=self::decimal($l['cantidad_fraccion'],3,$redondear);
            if (DecimalInventario::leer($cantidad)===0) self::error('CANTIDAD_DEMASIADO_PEQUENA','El consumo es menor que la precisión de inventario.');
            $precio=$l['costo'];
            if ($precio===null) $conocido=false;
            else $costo=self::suma($costo,self::producto($l['cantidad_fraccion'],[(string)DecimalInventario::leer($precio,4),'10000']));
            $salidas[]=['articulo_id'=>$id,'almacen_id'=>$o['almacen_id']??null,'cantidad'=>$cantidad,'unidad'=>$l['unidad'],'etapa'=>$l['etapa'],'costo_unitario'=>$precio];
        }
        usort($salidas,static fn($a,$b)=>[$a['articulo_id'],$a['etapa']]<=>[$b['articulo_id'],$b['etapa']]);
        return ['unidad_id'=>$unidad,'lineas'=>$salidas,'traza'=>$traza,'costo_unitario'=>$conocido?self::decimal($costo,4,true):null,'consume'=>$salidas!==[]];
    }

    /** Ajustes sobre la composición visible antes del desglose. Nunca internos
     * de una preparación almacenada; IDs de opciones validados por Variantes. */
    public static function ajustar(array $base, array $ajustes): array
    {
        if (count($ajustes)>144) self::error('VARIANTE_INVALIDA','Demasiados ajustes de composición.');
        foreach ($ajustes as $aj) {
            if (($aj['etapa']??'servicio')!=='servicio') self::error('VARIANTE_PRODUCCION','Una variante de venta no modifica producción.');
            $accion=$aj['accion']??''; $id=self::id($aj['articulo_id']??null); $q=self::cantidad($aj['cantidad']??null);
            if ($accion==='agregar') {
                $base[]=$aj+['etapa'=>'servicio'];$base=self::agrupar($base,$id,false);continue;
            }
            if (!in_array($accion,['quitar','sustituir'],true)) self::error('VARIANTE_INVALIDA','La acción de la variante no es válida.');
            $idx=[];
            foreach ($base as $k=>$c) if ((int)$c['articulo_id']===$id && ($c['etapa']??'servicio')==='servicio') $idx[]=$k;
            if (count($idx)!==1) self::error('SUSTITUCION_INVALIDA','El componente debe existir una sola vez en la composición de servicio.');
            $k=$idx[0]; $c=$base[$k];
            if (($aj['unidad']??null)!==$c['unidad']) self::error('UNIDAD_INCOMPATIBLE','El ajuste debe usar la unidad del componente.');
            $actual=self::cantidad($c['cantidad']);
            if (bccomp(bcmul($q[0],$actual[1],0),bcmul($actual[0],$q[1],0),0)>0) self::error('CANTIDAD_INVALIDA','El ajuste excede el componente disponible.');
            $restante=self::suma($actual,['-'.$q[0],$q[1]]);
            if ($restante[0]==='0') unset($base[$k]); else $base[$k]['cantidad']=self::decimal($restante,3,false);
            if ($accion==='sustituir') {
                $nuevo=$aj['nuevo']??null;
                if (!is_array($nuevo) || self::id($nuevo['articulo_id']??null)===$id) self::error('SUSTITUCION_INVALIDA','Selecciona un artículo sustituto distinto.');
                self::cantidad($nuevo['cantidad']??null); $base[]=$nuevo+['etapa'=>'servicio'];
            }
        }
        return array_values($base);
    }

    /** Expone solo preparaciones al momento para ajustar sus componentes.
     * Un artículo almacenado siempre es una frontera, incluso con receta propia. */
    public static function aplicarVariantes(array $base,array $ajustes,int $u,callable $articulo,callable $receta,bool $redondear=false): array
    {
        self::resolver($base,$u,$articulo,$receta,[],false,$redondear);$original=$base;
        $ordenados=array_merge(array_values(array_filter($ajustes,static fn($a)=>($a['accion']??'')!=='sustituir')),array_values(array_filter($ajustes,static fn($a)=>($a['accion']??'')==='sustituir')));
        foreach($ordenados as $aj){
            if(($aj['accion']??'')!=='agregar'){
                $target=(int)($aj['articulo_id']??0);$base=self::exponer($base,$target,$receta,$redondear);
                if($aj['accion']==='sustituir'){
                    $original=self::exponer($original,$target,$receta,$redondear);$actual=null;$inicial=null;
                    foreach($base as $c)if((int)$c['articulo_id']===$target)$actual=$c;
                    foreach($original as $c)if((int)$c['articulo_id']===$target)$inicial=$c;
                    if($actual&&$inicial){
                        $declarada=self::cantidad($aj['cantidad']);$baseQ=self::cantidad($inicial['cantidad']);
                        $proporcion=self::producto($declarada,[$baseQ[1],$baseQ[0]]);
                        if(bccomp($proporcion[0],$proporcion[1],0)>0)$proporcion=['1','1'];
                        $sale=self::producto(self::cantidad($actual['cantidad']),$proporcion);
                        $entra=self::producto(self::producto($sale,self::cantidad($aj['nuevo']['cantidad']??null)),[$declarada[1],$declarada[0]]);
                        $aj['cantidad']=self::decimal($sale,3,$redondear);$aj['nuevo']['cantidad']=self::decimal($entra,3,$redondear);
                    }
                }
            }
            $base=self::ajustar($base,[$aj]);
        }
        return $base;
    }
    private static function exponer(array $base,int $target,callable $receta,bool $redondear): array
    {
        $pasos=0;
        $contiene=function(array $c,array $ruta=[])use(&$contiene,&$pasos,$target,$receta):bool{
            if(++$pasos>self::MAX_COMPONENTES*2)self::error('COMPOSICION_EXCESIVA','La variante excede los componentes admitidos.');
            if((int)$c['articulo_id']===$target)return true;
            if(($c['modalidad']??'')!=='desglosar')return false;
            if(in_array((int)$c['articulo_id'],$ruta,true))self::error('RECETA_CIRCULAR','La variante contiene una dependencia circular.');$ruta[]=(int)$c['articulo_id'];
            $r=$receta((int)$c['version_receta_id']);if(!$r)self::error('VERSION_INVALIDA','La elaboración no está disponible.');
            foreach($r['componentes'] as $h)if($contiene($h,$ruta))return true;
            return false;
        };
        $expandir=function(array $c)use(&$expandir,$contiene,$target,$receta,$redondear):array{
            if((int)$c['articulo_id']===$target||!$contiene($c))return [$c];
            $r=$receta((int)$c['version_receta_id']);$yield=self::cantidad($r['rendimiento']);$ratio=self::producto(self::producto(self::cantidad($c['cantidad']),self::conversion($c['unidad'],$r['unidad'])),[$yield[1],$yield[0]]);$out=[];
            foreach($r['componentes'] as $h){$merma=DecimalInventario::leer($h['merma']??'0.000');$q=self::producto(self::producto($ratio,self::cantidad($h['cantidad'])),['100000',(string)(100000-$merma)]);$h['cantidad']=self::decimal($q,3,$redondear);$h['etapa']=$c['etapa']??'servicio';array_push($out,...$expandir($h));}
            return $out;
        };
        $out=[];foreach($base as $c)array_push($out,...$expandir($c));
        if(!array_filter($out,static fn($c)=>(int)$c['articulo_id']===$target))self::error('SUSTITUCION_INVALIDA','La variante no puede modificar componentes internos de un artículo almacenado.');
        return self::agrupar($out,$target,$redondear);
    }
    private static function agrupar(array $base,int $target,bool $redondear): array
    {
        $out=[];$indices=[];
        foreach($base as $c){if((int)$c['articulo_id']!==$target){$out[]=$c;continue;}
            $key=($c['modalidad']??'').':'.($c['version_receta_id']??'').':'.($c['etapa']??'servicio');
            if(!isset($indices[$key])){$indices[$key]=count($out);$out[]=$c;continue;}
            $i=$indices[$key];$q=self::producto(self::cantidad($c['cantidad']),self::conversion($c['unidad'],$out[$i]['unidad']));$out[$i]['cantidad']=self::decimal(self::suma(self::cantidad($out[$i]['cantidad']),$q),3,$redondear);
        }
        return $out;
    }

    public static function conversion(string $de,string $a): array
    {
        if (!isset(self::MEDIDAS[$de],self::MEDIDAS[$a]) || self::MEDIDAS[$de][0]!==self::MEDIDAS[$a][0]) self::error('UNIDAD_INCOMPATIBLE','Las unidades no tienen la misma dimensión.');
        return [(string)self::MEDIDAS[$de][1],(string)self::MEDIDAS[$a][1]];
    }
    private static function cantidad(mixed $v): array { $n=DecimalInventario::leer($v); if($n<=0)self::error('CANTIDAD_INVALIDA','Cantidad y rendimiento deben ser positivos.'); return [(string)$n,'1000']; }
    private static function id(mixed $v): int { if(!is_int($v)&&(!is_string($v)||!preg_match('/^[1-9][0-9]{0,17}$/D',$v)))self::error('REFERENCIA_INVALIDA','La referencia no es válida.'); if((int)$v<1)self::error('REFERENCIA_INVALIDA','La referencia no es válida.');return (int)$v; }
    private static function suma(array $a,array $b): array { return self::reducir([bcadd(bcmul($a[0],$b[1],0),bcmul($b[0],$a[1],0),0),bcmul($a[1],$b[1],0)]); }
    private static function producto(array $a,array $b): array { return self::reducir([bcmul($a[0],$b[0],0),bcmul($a[1],$b[1],0)]); }
    private static function reducir(array $f): array { $x=ltrim($f[0],'-'); $y=$f[1]; while(bccomp($y,'0',0)!==0){$r=bcmod($x,$y,0);$x=$y;$y=$r;} return $x==='0'?['0','1']:[bcdiv($f[0],$x,0),bcdiv($f[1],$x,0)]; }
    private static function decimal(array $f,int $escala,bool $redondear): string
    {
        $n=bcmul($f[0],bcpow('10',(string)$escala,0),0); $resto=bcmod($n,$f[1],0);
        if(!$redondear&&bccomp($resto,'0',0)!==0)self::error('PRECISION_INCOMPATIBLE','El rendimiento no produce cantidades exactas a milésimas. Ajusta la fórmula.');
        $v=bcdiv($n,$f[1],0);
        if($redondear&&bccomp(bcmul($resto,'2',0),$f[1],0)>=0)$v=bcadd($v,'1',0);
        $max=$escala===3?'999999999999':'9999999999';if(bccomp($v,$max,0)>0)self::error('DECIMAL_FUERA_RANGO','El resultado supera el rango de inventario.');
        return DecimalInventario::formato((int)$v,$escala);
    }
    private static function error(string $c,string $m): never { throw new InventarioError($c,$m); }
}
