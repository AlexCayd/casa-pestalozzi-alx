<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Services\Inventory\ResolucionArticulos as R;
$salida=[];
foreach(['0.0300',null,'0.0000'] as $costo){
 $articulos=[1=>['unidad'=>'g','activo'=>1,'costo'=>'0.2500'],2=>['unidad'=>'ml','activo'=>1,'costo'=>'0.0150'],3=>['unidad'=>'ml','activo'=>1,'costo'=>$costo]];
 $loader=static fn($id)=>$articulos[$id]??null;
 $base=[['articulo_id'=>1,'cantidad'=>'10.000','unidad'=>'g','modalidad'=>'almacenado'],['articulo_id'=>2,'cantidad'=>'100.000','unidad'=>'ml','modalidad'=>'almacenado']];
 $ajustes=[['accion'=>'sustituir','articulo_id'=>2,'cantidad'=>'100.000','unidad'=>'ml','nuevo'=>['articulo_id'=>3,'cantidad'=>'100.000','unidad'=>'ml','modalidad'=>'almacenado']]];
 $plan=R::aplicarVariantes($base,$ajustes,2,$loader,static fn($id)=>null);
 $salida[]=R::resolver($plan,2,$loader,static fn($id)=>null,[],false)['costo_unitario'];
}
echo json_encode($salida,JSON_THROW_ON_ERROR);
