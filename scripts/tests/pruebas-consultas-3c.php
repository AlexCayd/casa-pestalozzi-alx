<?php
declare(strict_types=1);

use Services\Inventory\Almacenes;

/** Se ejecuta únicamente en el fixture marcado del runner principal 3C. */
function test3cConsultas(mysqli $db,array $fx,int &$n): void
{
    $g=test3cService($db,(int)$fx['global']['id']);
    $r=test3cService($db,(int)$fx['rest']['id']);
    $p=test3cService($db,(int)$fx['pan']['id']);
    $gr=FixtureAlmacenes3C::usuario($db,'lectura_global',['global'=>['permisos'=>['inventario'=>'ver']]]);
    $rr=FixtureAlmacenes3C::usuario($db,'lectura_rest',['restaurante'=>['permisos'=>['inventario'=>'ver']]]);
    $globalRead=test3cService($db,(int)$gr['id']);
    $restRead=test3cService($db,(int)$rr['id']);
    $r2=test3cCall(fn()=>$g->crearAlmacen('global','restaurante','Segundo almacén Restaurante',false),'Segundo almacén del mismo negocio.',$n)['almacen_id'];
    $fresa=test3cCall(fn()=>$g->crearArticulo('global',['nombre'=>'Fresa QA','unidad'=>'kg','costo'=>'12.3456']),'Artículo consolidado.',$n)['articulo_id'];
    foreach([[$g,'global',$fx['gid'],'6.000'],[$r,'restaurante',$fx['rid'],'2.000'],[$r,'restaurante',$r2,'1.000'],[$p,'panaderia',$fx['pid'],'1.000']] as [$s,$ctx,$alm,$cant]) {
        test3cCall(fn()=>$s->asociarArticulo($ctx,(int)$alm,(int)$fresa,'1.500'),'Asociar artículo por almacén.',$n);
        test3cCall(fn()=>$s->registrarEntrada($ctx,test3cDoc('fresa-'.$alm,'fresa-'.$alm,[['almacen_id'=>(int)$alm,'articulo_id'=>(int)$fresa,'cantidad'=>$cant]])),'Entrada de fixture por almacén.',$n);
    }
    $one=$globalRead->consultarExistencias('global',['articulo_id'=>$fresa]);
    test3cAssert($one['ok']===true && $one['total']===1,'Global lectura consulta artículo consolidado.',$n);
    $a=$one['articulos'][0];
    test3cAssert($a['saldo_total']==='10.000' && count($a['ambitos'])===3,'Total físico 10 exacto sin duplicaciones.',$n);
    $byScope=array_column($a['ambitos'],null,'slug');
    test3cAssert($byScope['global']['saldo']==='6.000' && $byScope['restaurante']['saldo']==='3.000' && $byScope['panaderia']['saldo']==='1.000','Desglose explica el total por negocio.',$n);
    test3cAssert(count($byScope['restaurante']['almacenes'])===2 && array_sum(array_map(fn($w)=>(int)str_replace('.','',$w['saldo']),$byScope['restaurante']['almacenes']))===3000,'Varios almacenes explican la cantidad del negocio.',$n);
    test3cAssert($a['alerta']===true && $a['minimo_total']==='6.000','Mínimos por almacén; un excedente no oculta una alerta local.',$n);
    $local=$restRead->consultarExistencias('restaurante',['articulo_id'=>$fresa]);
    test3cAssert($local['ok']===true && $local['articulos'][0]['saldo_total']==='3.000' && count($local['articulos'][0]['ambitos'])===1,'Restaurante consulta solo sus cantidades.',$n);
    $pan=$p->consultarExistencias('panaderia',['articulo_id'=>$fresa]);
    test3cAssert($pan['articulos'][0]['saldo_total']==='1.000','Panadería conserva consulta independiente.',$n);
    test3cDenied(fn()=>$restRead->consultarExistencias('restaurante',['negocio'=>'panaderia']),'Filtro de otro ámbito denegado.',$n);
    test3cDenied(fn()=>$restRead->consultarExistencias('restaurante',['almacen_id'=>$fx['pid']]),'ID ajeno no revela cantidades.',$n);
    test3cDenied(fn()=>$restRead->consultarMovimientos('restaurante',['almacen_id'=>$fx['pid']]),'Historial de almacén ajeno denegado.',$n);
    test3cDenied(fn()=>$restRead->consultarAlmacenes('restaurante',['almacen_id'=>$fx['pid']]),'Detalle ajeno denegado.',$n);
    test3cDenied(fn()=>$globalRead->registrarEntrada('global',test3cDoc('read-write','read-write',[['almacen_id'=>$fx['gid'],'articulo_id'=>$fresa,'cantidad'=>'1.000']])),'Lector no realiza escrituras.',$n);
    test3cDenied(fn()=>$g->registrarEntrada('global',test3cDoc('global-rest-write','global-rest-write',[['almacen_id'=>$fx['rid'],'articulo_id'=>$fresa,'cantidad'=>'1.000']])),'Consolidación Global no concede escritura local.',$n);

    $onlyPan=test3cCall(fn()=>$g->crearArticulo('global',['nombre'=>'Solo Panadería QA','unidad'=>'pza','costo'=>'1.0000']),'Artículo solo Panadería.',$n)['articulo_id'];
    $onlyGlobal=test3cCall(fn()=>$g->crearArticulo('global',['nombre'=>'Solo Global QA','unidad'=>'l','costo'=>'1.0000']),'Artículo solo Global.',$n)['articulo_id'];
    foreach([[$p,'panaderia',$fx['pid'],$onlyPan],[$g,'global',$fx['gid'],$onlyGlobal]] as [$s,$ctx,$alm,$id]) {
        test3cCall(fn()=>$s->asociarArticulo($ctx,(int)$alm,(int)$id),'Asociar artículos exclusivos.',$n);
        test3cCall(fn()=>$s->registrarEntrada($ctx,test3cDoc('solo-'.$id,'solo-'.$id,[['almacen_id'=>(int)$alm,'articulo_id'=>(int)$id,'cantidad'=>'2.500']])),'Entrada exclusiva.',$n);
    }
    $localOther=$r->consultarExistencias('restaurante',['articulo_id'=>$onlyPan]);
    test3cAssert($localOther['total']===0 && $localOther['articulos']===[],'Artículo exclusivo de otro negocio no revela existencias.',$n);
    foreach([$onlyPan,$onlyGlobal] as $id) {
        $exclusive=$globalRead->consultarExistencias('global',['articulo_id'=>$id]);
        test3cAssert($exclusive['articulos'][0]['saldo_total']==='2.500' && count($exclusive['articulos'][0]['ambitos'])===1,'Global explica artículo con un solo propietario físico.',$n);
    }
    $empty=test3cCall(fn()=>$g->crearArticulo('global',['nombre'=>'Sin asociación QA','unidad'=>'g','costo'=>'0.0000']),'Artículo sin asociación.',$n)['articulo_id'];
    $without=$globalRead->consultarExistencias('global',['sin_asociacion'=>true]);
    test3cAssert(in_array($empty,array_column($without['articulos'],'articulo_id'),true),'Catálogo Global incluye artículos sin asociaciones.',$n);
    $emptyRow=array_values(array_filter($without['articulos'],fn($x)=>$x['articulo_id']===$empty))[0];
    test3cAssert($emptyRow['saldo_total']==='0.000' && $emptyRow['ambitos']===[],'Sin asociación no inventa existencias ni almacén.',$n);
    $filtered=$globalRead->consultarExistencias('global',['q'=>'Fresa','negocio'=>'restaurante','almacen_id'=>$r2,'solo_alertas'=>true]);
    test3cAssert($filtered['total']===1 && $filtered['articulos'][0]['saldo_total']==='1.000','Filtros combinados no amplían alcance ni duplican joins.',$n);
    test3cDenied(fn()=>$globalRead->consultarExistencias('global',['negocio'=>'restaurante','almacen_id'=>$fx['pid']]),'Filtros físicos incompatibles rechazados.',$n);
    $pg1=$globalRead->consultarExistencias('global',['pagina'=>1,'por_pagina'=>2]);
    $pg2=$globalRead->consultarExistencias('global',['pagina'=>2,'por_pagina'=>2]);
    test3cAssert(count($pg1['articulos'])===2 && count($pg2['articulos'])===2 && $pg1['total']===$pg2['total'],'Paginación tiene total estable.',$n);
    test3cAssert(array_intersect(array_column($pg1['articulos'],'articulo_id'),array_column($pg2['articulos'],'articulo_id'))===[],'Páginas no repiten artículos.',$n);
    $literal=$globalRead->consultarExistencias('global',['q'=>'%']);
    test3cAssert($literal['total']===0,'Comodín del cliente se busca como texto literal.',$n);
    test3cDenied(fn()=>$globalRead->consultarExistencias('global',['unidades_permitidas'=>[1,2,3]]),'No acepta alcance enviado por el cliente.',$n);
    foreach([['pagina'=>0],['por_pagina'=>101],['negocio'=>'otro'],['solo_alertas'=>'1']] as $bad) test3cDenied(fn()=>$globalRead->consultarExistencias('global',$bad),'Filtro inválido rechazado.',$n);

    $inactiveStore=test3cCall(fn()=>$g->crearAlmacen('global','global','Historial inactivo',false),'Almacén con historial para baja lógica.',$n)['almacen_id'];
    test3cCall(fn()=>$g->asociarArticulo('global',(int)$inactiveStore,(int)$fresa),'Asociación inactiva histórica.',$n);
    $in=test3cCall(fn()=>$g->registrarEntrada('global',test3cDoc('hist-in','hist-in',[['almacen_id'=>$inactiveStore,'articulo_id'=>$fresa,'cantidad'=>'0.125']])),'Entrada histórica.',$n);
    test3cCall(fn()=>$g->registrarSalida('global',test3cDoc('hist-out','hist-out',[['almacen_id'=>$inactiveStore,'articulo_id'=>$fresa,'cantidad'=>'0.125']])),'Saldo cero para desactivar.',$n);
    test3cCall(fn()=>$g->configurarAlmacen('global',(int)$inactiveStore,['activo'=>false]),'Baja lógica con historial preservado.',$n);
    test3cDenied(fn()=>$g->eliminarAlmacen('global',(int)$inactiveStore),'Historial impide borrado físico con saldo cero.',$n);
    $fixtureDate=(string)$db->query("SELECT DATE(fecha) FROM movimientos_inventario WHERE almacen_id=$inactiveStore LIMIT 1")->fetch_row()[0];
    $hist=$globalRead->consultarMovimientos('global',['almacen_id'=>$inactiveStore,'desde'=>$fixtureDate,'hasta'=>$fixtureDate,'por_pagina'=>1]);
    test3cAssert($hist['ok']===true && $hist['total']===2 && count($hist['movimientos'])===1 && (int)$hist['movimientos'][0]['almacen_activo']===0,'Historial de inactivo conserva paginación, actor y cantidades: '.json_encode($hist),$n);
    test3cAssert((int)$hist['movimientos'][0]['usuario_id']===$fx['global']['id'] && $hist['movimientos'][0]['origen']==='motor_inventario','Actor y origen se obtienen de la operación.',$n);
    $states=$globalRead->consultarAlmacenes('global',['estado'=>'inactivos','almacen_id'=>$inactiveStore]);
    test3cAssert($states['total']===1,'Almacén inactivo sigue consultable por estado.',$n);
    $limited=$restRead->consultarMovimientos('restaurante');
    test3cAssert($limited['total']>0 && array_unique(array_column($limited['movimientos'],'slug'))===['restaurante'],'SQL del historial filtra propietario.',$n);
    foreach([['desde'=>'2026-02-30'],['desde'=>'2026-03-01','hasta'=>'2026-02-01'],['tipo'=>'DROP']] as $bad) test3cDenied(fn()=>$globalRead->consultarMovimientos('global',$bad),'Historial valida fechas y tipos.',$n);
    $st=$db->prepare("DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND modulo='inventario'");
    $st->execute([$rr['id']]);
    test3cDenied(fn()=>$restRead->consultarExistencias('restaurante'),'La siguiente consulta refleja revocación.',$n);
    test3cDenied(fn()=>$restRead->consultarMovimientos('restaurante'),'Historial refleja revocación.',$n);
    $check=$globalRead->consultarExistencias('global',['articulo_id'=>$fresa]);
    test3cAssert($check['articulos'][0]['saldo_total']==='10.000','Movimientos e inactivo no duplican total físico.',$n);
    $expected=$db->query("SELECT unidad_id,SUM(saldo) AS saldo FROM almacen_articulos WHERE articulo_id=$fresa GROUP BY unidad_id")->fetch_all(MYSQLI_ASSOC);
    $actual=array_column($check['articulos'][0]['ambitos'],'saldo','unidad_id');
    foreach($expected as $x) test3cAssert($actual[$x['unidad_id']]===$x['saldo'],'Desglose coincide con suma SQL sin joins multiplicadores.',$n);

    $huge=test3cCall(fn()=>$g->crearArticulo('global',['nombre'=>'Total mayor al saldo individual','unidad'=>'g','costo'=>'0.0000']),'Artículo de prueba de suma amplia.',$n)['articulo_id'];
    $extraGlobal=test3cCall(fn()=>$g->crearAlmacen('global','global','Almacén suma amplia',false),'Almacén para suma amplia.',$n)['almacen_id'];
    foreach([$fx['gid'],$extraGlobal] as $alm) {
        test3cCall(fn()=>$g->asociarArticulo('global',(int)$alm,(int)$huge),'Asociación de suma amplia.',$n);
        test3cCall(fn()=>$g->registrarEntrada('global',test3cDoc('huge-'.$alm,'huge-'.$alm,[['almacen_id'=>(int)$alm,'articulo_id'=>(int)$huge,'cantidad'=>'900000000.000']])),'Saldo individual dentro de rango.',$n);
    }
    $wide=$globalRead->consultarExistencias('global',['articulo_id'=>$huge]);
    test3cAssert($wide['ok']===true && $wide['articulos'][0]['saldo_total']==='1800000000.000','El consolidado supera un saldo individual sin perder precisión.',$n);
    $before=(int)$db->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_row()[1];
    $globalRead->consultarExistencias('global',['por_pagina'=>1]);
    $afterSmall=(int)$db->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_row()[1];
    $globalRead->consultarExistencias('global',['por_pagina'=>100]);
    $afterLarge=(int)$db->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_row()[1];
    test3cAssert($afterSmall-$before===$afterLarge-$afterSmall && $afterSmall-$before>=7,'El número de consultas no aumenta por artículo (sin N+1).',$n);
    foreach([['unidad_id',$fx['gid'],'idx_mov_unidad_fecha'],['almacen_id',$fx['gid'],'idx_mov_almacen_fecha']] as [$campo,$value,$index]) {
        $id=$campo==='unidad_id'?(int)$db->query("SELECT unidad_id FROM almacenes WHERE id=$value")->fetch_row()[0]:(int)$value;
        $plan=$db->query("EXPLAIN FORMAT=TRADITIONAL SELECT id FROM movimientos_inventario WHERE $campo=$id AND fecha>='2000-01-01' ORDER BY fecha DESC,id DESC LIMIT 10")->fetch_assoc();
        test3cAssert(str_contains((string)$plan['possible_keys'],$index),'El índice temporal por propietario/almacén es elegible para el historial.',$n);
    }

    $otra=test3cOtraConexion($db);
    try {
        $salida=test3cCall(fn()=>$g->registrarSalida('global',test3cDoc('rr-origen','rr-origen',[['almacen_id'=>$fx['gid'],'articulo_id'=>$fresa,'cantidad'=>'0.500']])),'Salida para instantánea externa.',$n);
        $mid=(int)$salida['movimientos'][0]['id'];
        $db->begin_transaction();
        $db->query('SELECT COUNT(*) FROM movimientos_inventario')->fetch_row();
        $g2=test3cService($otra,(int)$fx['global']['id']);
        test3cCall(fn()=>$g2->revertirMovimientos('global',test3cDoc('rr-compensacion','rr-compensacion',[['movimiento_id'=>$mid,'cantidad'=>'0.500']])),'Otra conexión compensa y confirma.',$n);
        $res=$g->revertirMovimientos('global',test3cDoc('rr-exceso','rr-exceso',[['movimiento_id'=>$mid,'cantidad'=>'0.500']]));
        test3cAssert($res['ok']===false && $res['codigo']==='REVERSION_EXCEDIDA','Snapshot externo antiguo no permite compensar dos veces.',$n);
        $db->rollback();

        $db->begin_transaction();
        $db->query('SELECT COUNT(*) FROM usuario_unidad_permisos')->fetch_row();
        $rev=$otra->prepare("DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND modulo='inventario'");
        $rev->execute([$fx['rest']['id']]);
        test3cDenied(fn()=>$r->consultarExistencias('restaurante'),'Revocación se ve incluso con snapshot externo antiguo.',$n);
        test3cDenied(fn()=>$r->existencia('restaurante',(int)$fx['rid'],(int)$fresa),'Lectura individual tampoco reutiliza permiso antiguo.',$n);
        $db->rollback();
        $restUnit=(int)$db->query("SELECT unidad_id FROM almacenes WHERE id=".$fx['rid'])->fetch_row()[0];
        $grant=$otra->prepare("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,'inventario','editar')");
        $grant->execute([$fx['rest']['id'],$restUnit]);
    } finally { $db->rollback(); $otra->close(); }
    $db->query("DELETE FROM esquema_versiones WHERE version='multinegocio-3c-v1'");
    try {
        $incompatible=$globalRead->consultarExistencias('global');
        test3cAssert($incompatible['ok']===false && $incompatible['codigo']==='ESQUEMA_INCOMPATIBLE','Sin marca 3C no se activa parcialmente el motor.',$n);
        $writeIncompatible=$g->registrarEntrada('global',test3cDoc('esquema-incompleto','esquema-incompleto',[['almacen_id'=>$fx['gid'],'articulo_id'=>$fresa,'cantidad'=>'1.000']]));
        test3cAssert($writeIncompatible['ok']===false && $writeIncompatible['codigo']==='ESQUEMA_INCOMPATIBLE','Esquema incompleto tampoco permite movimientos.',$n);
    } finally { $db->query("INSERT INTO esquema_versiones(version) VALUES ('multinegocio-3c-v1')"); }
}

function test3cOtraConexion(mysqli $actual): mysqli
{
    $cfg=$GLOBALS['config'];
    $name=(string)$actual->query('SELECT DATABASE()')->fetch_row()[0];
    if(!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name) || $name===$cfg['DB_NAME']) throw new RuntimeException('Destino de segunda conexión inválido.');
    $token=(string)$actual->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];
    $otra=new mysqli($cfg['DB_HOST'],$cfg['DB_USER'],$cfg['DB_PASS'],'',(int)($cfg['DB_PORT']??3306));
    $rows=$otra->query('SELECT token FROM '.test3cIdent($name).'._cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
    if(count($rows)!==1 || !hash_equals($token,(string)$rows[0][0])) throw new RuntimeException('La segunda conexión no verificó el propietario.');
    $otra->select_db($name);
    $otra->set_charset('utf8mb4');
    return $otra;
}
