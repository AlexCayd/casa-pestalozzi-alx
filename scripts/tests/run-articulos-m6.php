<?php
require __DIR__.'/soporte-m12.php';
use Services\Inventory\Almacenes;
use Services\Inventory\Articulos as A;
use Services\Security\ContextoNegocio as C;

m12Pruebas(static function(mysqli $db,string $root,callable $a):void {
    $superId=C::actorId();
    $motor=new Almacenes($db,$superId,C::nivelAuth());
    $alta=$motor->crearArticulo('global',['nombre'=>'Fixture clasificación M6 '.bin2hex(random_bytes(4)),'unidad'=>'kg','costo'=>'0.0000']);
    $a(!empty($alta['ok']),'El artículo de prueba se crea en catálogo Global.');
    $id=(int)$alta['articulo_id'];

    $antes=A::estadoAdministrativo('restaurante',$id)['configuracion'];
    $insumo=A::clasificar('restaurante',$id,['tipo'=>'insumo'],0);
    $estado=A::estadoAdministrativo('restaurante',$id)['configuracion'];
    $a(!empty($insumo['ok'])&&(int)$estado['comprable']===1&&(int)$estado['elaborable']===0,'“Se compra” clasifica como comprable y no elaborable.');
    $a((int)$estado['almacenable']===(int)$antes['almacenable']&&(int)$estado['costo_conocido']===(int)$antes['costo_conocido'],'Clasificar conserva almacenamiento y costo conocido del estado efectivo por omisión.');

    $preparacion=A::clasificar('restaurante',$id,['tipo'=>'preparacion'],(int)$insumo['revision']);
    $estado=A::estadoAdministrativo('restaurante',$id)['configuracion'];
    $a(!empty($preparacion['ok'])&&(int)$estado['elaborable']===1,'“Se prepara en casa” clasifica como elaborable.');
    $a((int)$estado['revision']===(int)$preparacion['revision'],'La clasificación respeta y avanza la revisión de M6.');

    try { A::clasificar('restaurante',$id,['tipo'=>''],(int)$preparacion['revision']); $codigo=null; }
    catch(Throwable $e) { $codigo=$e->codigo??null; }
    $a($codigo==='TIPO_INVALIDO','La clasificación explícita rechaza el tipo vacío.');
    $obsoleta=A::clasificar('restaurante',$id,['tipo'=>'insumo'],0);
    $a(empty($obsoleta['ok'])&&($obsoleta['codigo']??null)==='REVISION_OBSOLETA','La clasificación rechaza revisiones obsoletas: '.json_encode($obsoleta,JSON_UNESCAPED_UNICODE));

    $revisionActual=(int)$estado['revision'];
    $lector=FixtureAutorizacion3B::usuario($db,'m6_lector',['restaurante'=>['permisos'=>['inventario'=>'ver','productos'=>'ver']]]);
    $_SESSION['id']=$lector['id'];
    $sinEdicion=A::clasificar('restaurante',$id,['tipo'=>'insumo'],$revisionActual);
    $a(empty($sinEdicion['ok'])&&($sinEdicion['codigo']??null)==='PERMISO_DENEGADO','Clasificar requiere permiso de edición M6.');
    try { A::clasificar('panaderia',$id,['tipo'=>'insumo'],0); $ambito=false; }
    catch(Throwable $e) { $ambito=($e->codigo??null)==='PERMISO_DENEGADO'; }
    $a($ambito,'La clasificación no cruza el ámbito de la membresía.');
    $_SESSION['id']=$superId;
    $sinCambios=A::estadoAdministrativo('restaurante',$id)['configuracion'];
    $a((int)$sinCambios['revision']===$revisionActual&&(int)$sinCambios['elaborable']===(int)$estado['elaborable'],'Denegación de permiso/ámbito conserva clasificación y revisión.');
},'M6',true);
