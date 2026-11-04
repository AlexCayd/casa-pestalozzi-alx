<?php
// M3 · Fase 2 (docs/arquitectura.md §16): faltante parcial, reparto, saldo total
// insuficiente, producción previa, confirmación y rechazo de Global, replay,
// cancelación, IDs ajenos, conflicto de versión, migración de negativos y costo congelado.
// Base temporal propia: php scripts/tests/run-faltantes.php --host=... --base=... --entorno=development
require __DIR__.'/soporte-m12.php';
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\Almacenes;
use Services\Inventory\Articulos as A;
use Services\Inventory\Faltantes;
use Services\Inventory\Transferencias;
use Services\Menu\PresentacionesOferta as P;
use Services\Pos\Comercial;
use Services\Security\ContextoNegocio as C;

m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $no=static function(callable $f,string $m,?string $codigo=null)use($a){
        try{$r=$f();$ok=is_array($r)&&empty($r['ok']);$c=$r['codigo']??null;}catch(Throwable $e){$ok=true;$c=$e->codigo??null;}
        $a($ok&&($codigo===null||$c===$codigo),$m.($codigo!==null&&$c!==$codigo?" (código {$c})":''));
    };
    $actor=C::actorId();$nivel=C::nivelAuth();
    $motor=new Almacenes($db,$actor,$nivel);$falt=new Faltantes($db,$actor,$nivel);$tr=new Transferencias($db,$actor,$nivel);
    $saldo=static fn($w,$id)=>(string)$db->execute_query('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?',[$w,$id])->fetch_row()[0];
    $f1=static fn($id)=>$db->execute_query('SELECT * FROM faltantes_inventario WHERE id=?',[$id])->fetch_assoc();
    $clave=static fn(string $s)=>substr(str_pad($s,16,'0'),0,60);

    // 0. Fixture migrada: sin negativos; Cocina conserva su faltante.
    $a((int)$db->query('SELECT COUNT(*) FROM almacen_articulos WHERE saldo<0')->fetch_row()[0]===0,'Ningún saldo negativo tras instalar');
    $a((int)$db->query("SELECT COUNT(*) FROM faltantes_inventario WHERE almacen_id=1002 AND estado='pendiente' AND origen='migracion'")->fetch_row()[0]===1,'Cocina muestra su faltante pendiente');
    $no(fn()=>$db->query('UPDATE almacenes SET permitir_negativos=1 WHERE id=1002'),'El CHECK fija permitir_negativos en 0');
    $no(fn()=>$motor->configurarAlmacen('restaurante',1002,['nombre'=>'Cocina','permitir_negativos'=>true]),'La política de negativos ya no se configura','DATOS_INVALIDOS');

    // Artículo y oferta de prueba.
    $r=$motor->crearArticulo('global',['nombre'=>'Fresa M3','unidad'=>'pza','costo'=>'2.0000']);$fresa=(int)$r['articulo_id'];
    foreach(['restaurante','global'] as $ctx)A::configurar($ctx,$fresa,['comprable'=>1,'componente'=>1,'elaborable'=>0,'almacenable'=>1,'costo_conocido'=>1,'activo'=>1],0);
    foreach([[ 'restaurante',1005],['restaurante',1006],['global',1001]] as [$ctx,$w])$a(!empty($motor->asociarArticulo($ctx,$w,$fresa)['ok']),"Asociar fresa a $w");
    $entrada=static fn($ctx,$w,$q,$k)=>$motor->registrarEntrada($ctx,['clave'=>$k,'referencia'=>$k,'motivo'=>'compra','lineas'=>[['almacen_id'=>$w,'articulo_id'=>$fresa,'cantidad'=>$q]]]);
    $a(!empty($entrada('restaurante',1005,'20.000','m3f_ent_barra')['ok'])&&!empty($entrada('restaurante',1006,'20.000','m3f_ent_refri')['ok'])&&!empty($entrada('global',1001,'10.000','m3f_ent_central')['ok']),'Entradas iniciales 20/20/10');
    $db->query("INSERT INTO productos(nombre) VALUES('Postre de fresa M3')");$prod=(int)$db->insert_id;$base=$db->query('SELECT * FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1')->fetch_assoc();
    $db->execute_query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo,tipo_venta,tipo_venta_estado) VALUES(?,2,?,?,?,1,'preparado','confirmado')",[$prod,$base['categoria_id'],'50.00',$base['area_id']]);$oferta=(int)$db->insert_id;
    $db->execute_query('INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img) VALUES(?,?,?,NULL)',[$oferta,'Postre de fresa M3','Sintético']);
    $db->execute_query('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES(?,?,2,1005)',[$oferta,$fresa]);
    $r=P::publicar('restaurante',$oferta,['clave'=>'pieza','nombre'=>'Pieza','precio'=>'50.00','activo'=>1,'componentes'=>[['articulo_id'=>$fresa,'cantidad'=>'1.000','unidad'=>'pza','modalidad'=>'almacenado']]],0,'m3f_presentacion_01',true);$pres=(int)$r['presentacion_id'];
    $db->query("INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,'Faltantes M3','abierto')");$ticket=(int)$db->insert_id;
    $vender=static fn(int $q,string $k)=>Comercial::enviarComanda($ticket,[["oferta_id"=>$oferta,"presentacion_id"=>$pres,"cantidad"=>$q]],$clave($k),"restaurante");

    // 1. Vender 50 con saldo 20: sale la comanda, saldo 0, faltante 30.
    $r=$vender(50,'m3f_comanda_50');$a(!empty($r['ok']),'La comanda sale aunque falte saldo: '.json_encode($r));$item=(int)$r['item_ids'][0];
    $a($saldo(1005,$fresa)==='0.000','El almacén de consumo queda en 0, nunca negativo');
    $fal=$db->execute_query('SELECT * FROM faltantes_inventario WHERE ticket_item_id=?',[$item])->fetch_all(MYSQLI_ASSOC);
    $a(count($fal)===1&&$fal[0]['cantidad']==='30.000'&&$fal[0]['estado']==='pendiente'&&(int)$fal[0]['almacen_id']===1005,'Nace un faltante pendiente de 30');$fid=(int)$fal[0]['id'];
    $a($db->query("SELECT SUM(delta) FROM movimientos_inventario WHERE ticket_item_id=$item")->fetch_row()[0]==='-20.000','Sólo se descuentan las 20 disponibles');
    $a((int)$db->query("SELECT COUNT(*) FROM buzon_notificaciones WHERE unidad_id=2 AND tipo='inventario.faltante' AND prioridad='alta' AND cerrada_at IS NULL AND dedup_key LIKE 'inventario.faltante:1005:{$fresa}:%'")->fetch_row()[0]===1,'Aviso de prioridad alta en el buzón');
    $a($falt->contar('restaurante')>=2,'Almacenes cuenta los faltantes por conciliar');
    $costoTicket=(string)$db->query("SELECT costo_unitario FROM ticket_items WHERE id=$item")->fetch_row()[0];

    // 2. Replay: no duplica descuento ni faltante.
    $r=$vender(50,'m3f_comanda_50');$a(!empty($r['reintento']),'Replay idempotente');
    $a((int)$db->query("SELECT COUNT(*) FROM faltantes_inventario WHERE ticket_item_id=$item")->fetch_row()[0]===1&&$saldo(1005,$fresa)==='0.000','Replay sin segundo faltante ni descuento');
    $a((string)Model\ActiveRecord::getDB()->query("SELECT resultado->>'$.faltantes[0].cantidad' FROM operaciones_inventario WHERE clave_idempotencia='ticket-item-$item'")->fetch_row()[0]==='30.000','La operación de venta congela la parte faltante');

    // 3. Rechazos sin cambios: suma distinta, origen sin saldo, versión vieja, ID ajeno.
    $cons=static fn(array $as,string $k,?int $v=null)=>$falt->conciliar('restaurante',['faltante_id'=>(string)$fid,'version'=>(string)($v??$f1($fid)['version']),'clave'=>$clave($k),'asignaciones'=>$as]);
    $antes=json_encode([$f1($fid),$saldo(1006,$fresa),$saldo(1001,$fresa)]);
    $no(fn()=>$cons([['almacen_id'=>'1006','cantidad'=>'20'],['almacen_id'=>'1001','cantidad'=>'9']],'m3f_suma_mala'),'Una suma distinta de 30 se rechaza','SUMA_INVALIDA');
    $no(fn()=>$cons([['almacen_id'=>'1006','cantidad'=>'30']],'m3f_sin_saldo'),'Un origen sin saldo suficiente se rechaza','SALDO_INSUFICIENTE');
    $a(json_encode([$f1($fid),$saldo(1006,$fresa),$saldo(1001,$fresa)])===$antes,'Los rechazos no dejan cambios');
    $pan=FixtureAutorizacion3B::usuario($db,'m3f_panaderia',['panaderia'=>['permisos'=>['inventario'=>'editar']]]);$super=$_SESSION['id'];$_SESSION['id']=$pan['id'];
    $fp=new Faltantes($db,(int)$pan['id'],$nivel);
    $no(fn()=>$fp->conciliar('panaderia',['faltante_id'=>(string)$fid,'version'=>(string)$f1($fid)['version'],'clave'=>$clave('m3f_ajeno'),'asignaciones'=>[['almacen_id'=>'1006','cantidad'=>'30']]]),'Un ID de faltante ajeno se rechaza','RECURSO_NO_ENCONTRADO');
    $_SESSION['id']=$super;

    // 4. Reparto 20 Refrigeración + 10 Almacén Central (Global confirma).
    $v0=(int)$f1($fid)['version'];
    $r=$cons([['almacen_id'=>'1006','cantidad'=>'20'],['almacen_id'=>'1001','cantidad'=>'10']],'m3f_reparto_1',$v0);$a(!empty($r['ok'])&&$r['estado']==='en_confirmacion','Refrigeración se aplica y Central espera: '.json_encode($r));
    $no(fn()=>$cons([['almacen_id'=>'1006','cantidad'=>'30']],'m3f_reparto_2',$v0),'Segundo admin con la misma versión recibe conflicto','VERSION_CONFLICTO');
    $r2=$cons([['almacen_id'=>'1006','cantidad'=>'20'],['almacen_id'=>'1001','cantidad'=>'10']],'m3f_reparto_1',$v0);$a(!empty($r2['reintento']),'La misma clave repite el resultado sin efectos');
    $a($saldo(1006,$fresa)==='0.000'&&$saldo(1005,$fresa)==='0.000'&&$saldo(1001,$fresa)==='10.000','Refrigeración aplicada al instante; destino sin cambio neto; Central intacto');
    $t=$db->query("SELECT * FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=1001 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $a($t['estado']==='solicitada','La parte de Global queda solicitada');
    $tRef=$db->query("SELECT * FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=1006 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $a($tRef['estado']==='recibida'&&(int)$db->query("SELECT COUNT(*) FROM transferencia_historial WHERE transferencia_id={$tRef['id']}")->fetch_row()[0]===3,'Mismo negocio: transferencia creada, enviada y recibida con historial');
    $lista=$tr->consultar('global');$fila=null;foreach($lista['transferencias'] as $x)if((int)$x['id']===(int)$t['id'])$fila=$x;
    $a($fila&&$fila['acciones']===['confirmar','rechazar']&&!array_key_exists('saldo',$fila),'Global ve confirmar/rechazar y sólo la identidad del destino');
    $listaR=$tr->consultar('restaurante');foreach($listaR['transferencias'] as $x)if((int)$x['id']===(int)$t['id'])$a($x['acciones']===[],'El destino no opera la regularización');
    $no(fn()=>$tr->ejecutar('restaurante','confirmar',['clave'=>$clave('m3f_conf_rest'),'transferencia_id'=>(string)$t['id'],'version'=>(string)$t['version'],'motivo'=>'ok']),'Restaurante no confirma un origen de Global','AMBITO_DENEGADO');
    $no(fn()=>$tr->ejecutar('global','autorizar',['clave'=>$clave('m3f_aut_global'),'transferencia_id'=>(string)$t['id'],'version'=>(string)$t['version'],'motivo'=>'ok']),'La regularización no admite el flujo ordinario','ESTADO_INVALIDO');
    // Costo: cambia el costo maestro después de la venta.
    $db->execute_query('UPDATE ingredientes SET costo=? WHERE id=?',['3.5000',$fresa]);
    $r=$tr->ejecutar('global','confirmar',['clave'=>$clave('m3f_conf_global'),'transferencia_id'=>(string)$t['id'],'version'=>(string)$t['version'],'motivo'=>'Confirmado por Global']);
    $a(!empty($r['ok'])&&$r['estado']==='recibida','Global confirma: '.json_encode($r));
    $a($f1($fid)['estado']==='conciliado'&&$f1($fid)['cantidad_conciliada']==='30.000','Conciliar 20 + 10 deja el faltante conciliado');
    $a($saldo(1001,$fresa)==='0.000'&&$saldo(1005,$fresa)==='0.000','Central descuenta 10 y el destino queda igual');
    $a($db->query("SELECT SUM(delta) FROM movimientos_inventario WHERE ticket_item_id=$item AND tipo='venta'")->fetch_row()[0]==='-50.000','El historial suma el consumo completo de 50');
    $a((string)$db->query("SELECT costo_unitario FROM ticket_items WHERE id=$item")->fetch_row()[0]===$costoTicket,'El costo del ticket no se recalcula');
    $a($db->query("SELECT costo_unitario FROM movimientos_inventario WHERE faltante_id=$fid AND almacen_id=1005 AND tipo='venta' ORDER BY id DESC LIMIT 1")->fetch_row()[0]==='3.5000','El consumo conciliado guarda el costo del origen');
    $a((int)$db->query("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL AND dedup_key LIKE 'inventario.faltante:1005:{$fresa}:%'")->fetch_row()[0]===0,'El aviso se cierra al conciliar');

    // 5. Global rechaza: la parte vuelve a pendiente.
    $r=$vender(4,'m3f_comanda_cuatro');$item4=(int)$r['item_ids'][0];$fid4=(int)$db->query("SELECT id FROM faltantes_inventario WHERE ticket_item_id=$item4")->fetch_row()[0];
    $a($f1($fid4)['cantidad']==='4.000','Sin saldo, todo es faltante');
    $a(!empty($entrada('global',1001,'4.000','m3f_ent_central2')['ok']),'Entrada en Central');
    $r=$falt->conciliar('restaurante',['faltante_id'=>(string)$fid4,'version'=>(string)$f1($fid4)['version'],'clave'=>$clave('m3f_reparto_4'),'asignaciones'=>[['almacen_id'=>'1001','cantidad'=>'4']]]);$a(!empty($r['ok']),'Solicitud a Global');
    $t=$db->query("SELECT * FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=1001 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $r=$tr->ejecutar('global','rechazar',['clave'=>$clave('m3f_rech_global'),'transferencia_id'=>(string)$t['id'],'version'=>(string)$t['version'],'motivo'=>'Reservado para Panadería']);
    $a(!empty($r['ok'])&&$r['estado']==='rechazada','Global rechaza con motivo');
    $a($f1($fid4)['estado']==='pendiente'&&$f1($fid4)['cantidad_en_confirmacion']==='0.000','El rechazo devuelve la parte a pendiente');
    $a($db->query("SELECT motivo FROM faltante_asignaciones WHERE transferencia_id={$t['id']}")->fetch_row()[0]==='Reservado para Panadería'&&$saldo(1001,$fresa)==='4.000','Se guarda el motivo y Central no cambia');

    // 6. Saldo total insuficiente; tras una entrada de 25 se concilia completo.
    $r=$vender(30,'m3f_comanda_30');$item30=(int)$r['item_ids'][0];$fid30=(int)$db->query("SELECT id FROM faltantes_inventario WHERE ticket_item_id=$item30")->fetch_row()[0];
    $a(!empty($entrada('restaurante',1006,'6.000','m3f_ent_refri2')['ok']),'Quedan 6 en Refrigeración + 4 en Central = 10');
    $no(fn()=>$falt->conciliar('restaurante',['faltante_id'=>(string)$fid30,'version'=>(string)$f1($fid30)['version'],'clave'=>$clave('m3f_insuf'),'asignaciones'=>[['almacen_id'=>'1006','cantidad'=>'26'],['almacen_id'=>'1005','cantidad'=>'4']]]),'Con 30 faltantes y 10 disponibles se rechaza','SALDO_INSUFICIENTE');
    $bandeja=$falt->bandeja('restaurante')['faltantes'];$b=array_values(array_filter($bandeja,static fn($x)=>(int)$x['id']===$fid30))[0];
    $a($f1($fid30)['estado']==='pendiente'&&$b['cubierto_por_propios']===false,'Sigue pendiente y la bandeja marca que ningún almacén propio lo cubre');
    $ajeno=array_values(array_filter($b['origenes'],static fn($o)=>(int)$o['almacen_id']===1001))[0];$a(!array_key_exists('saldo',$ajeno)&&$ajeno['requiere_confirmacion'],'El saldo de Global no se expone a Restaurante');
    $db->query("UPDATE buzon_notificaciones SET cerrada_at=NOW() WHERE tipo='inventario.faltante' AND dedup_key LIKE 'inventario.faltante:1005:{$fresa}:%'");
    $a(!empty($entrada('restaurante',1005,'25.000','m3f_ent_barra25')['ok']),'Entrada de 25 en Barra');
    $a((int)$db->query("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND cerrada_at IS NULL AND dedup_key LIKE 'inventario.faltante:1005:{$fresa}:%'")->fetch_row()[0]===1,'La entrada reactiva el aviso');
    $r=$falt->conciliar('restaurante',['faltante_id'=>(string)$fid30,'version'=>(string)$f1($fid30)['version'],'clave'=>$clave('m3f_tras_entrada'),'asignaciones'=>[['almacen_id'=>'1005','cantidad'=>'25'],['almacen_id'=>'1006','cantidad'=>'5']]]);
    $a(!empty($r['ok'])&&$f1($fid30)['estado']==='conciliado','Tras la entrada se concilia completo: '.json_encode($r));
    $a($saldo(1005,$fresa)==='0.000'&&$saldo(1006,$fresa)==='1.000','Mismo almacén consume directo y Refrigeración traslada 5');

    // 7. Cancelación: pendiente se anula; en confirmación cancela su transferencia; lo aplicado se compensa.
    $r=$vender(3,'m3f_comanda_tres');$item3=(int)$r['item_ids'][0];$fid3=(int)$db->query("SELECT id FROM faltantes_inventario WHERE ticket_item_id=$item3")->fetch_row()[0];
    $r=$falt->conciliar('restaurante',['faltante_id'=>(string)$fid3,'version'=>(string)$f1($fid3)['version'],'clave'=>$clave('m3f_cancel_conf'),'asignaciones'=>[['almacen_id'=>'1006','cantidad'=>'1'],['almacen_id'=>'1001','cantidad'=>'2']]]);$a(!empty($r['ok']),'Una parte aplicada y otra en confirmación');
    $t3=$db->query("SELECT * FROM transferencias WHERE tipo='regularizacion' AND almacen_origen_id=1001 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $r=Comercial::cancelarItem($item3,'restaurante');$a(!empty($r['ok']),'Cancelar el item: '.json_encode($r));
    $a($f1($fid3)['estado']==='anulado'&&$db->query("SELECT estado FROM transferencias WHERE id={$t3['id']}")->fetch_row()[0]==='cancelada','Lo pendiente se anula y la transferencia en confirmación se cancela');
    $a($db->query("SELECT estado FROM faltante_asignaciones WHERE transferencia_id={$t3['id']}")->fetch_row()[0]==='anulada','La asignación queda anulada');
    $a($saldo(1005,$fresa)==='1.000'&&$saldo(1006,$fresa)==='0.000','Lo conciliado regresa al almacén de consumo; el traslado no se revierte');
    $no(fn()=>$tr->ejecutar('global','confirmar',['clave'=>$clave('m3f_conf_cancel'),'transferencia_id'=>(string)$t3['id'],'version'=>(string)($t3['version']+1),'motivo'=>'tarde']),'Global ya no puede confirmar lo anulado','ESTADO_INVALIDO');
    $r=$vender(2,'m3f_comanda_2b');$item2=(int)$r['item_ids'][0];$fid2=(int)$db->query("SELECT id FROM faltantes_inventario WHERE ticket_item_id=$item2")->fetch_row()[0];
    Comercial::cancelarItem($item2,'restaurante');$a($f1($fid2)['estado']==='anulado'&&$saldo(1005,$fresa)==='1.000','Pendiente se anula y sólo se compensa lo descontado');

    // 8. «Se elabora antes de vender» sin existencias: sale y crea faltante.
    $r=$motor->crearArticulo('global',['nombre'=>'Pay del día M3','unidad'=>'pza','costo'=>'20.0000']);$pay=(int)$r['articulo_id'];
    A::configurar('restaurante',$pay,['comprable'=>0,'componente'=>1,'elaborable'=>1,'almacenable'=>1,'produccion_previa'=>1,'costo_conocido'=>1,'activo'=>1],0);
    $motor->asociarArticulo('restaurante',1005,$pay);
    $db->query("INSERT INTO productos(nombre) VALUES('Pay M3')");$prodPay=(int)$db->insert_id;
    $db->execute_query("INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES(?,2,?,?,?,1)",[$prodPay,$base['categoria_id'],'60.00',$base['area_id']]);$ofPay=(int)$db->insert_id;
    $db->execute_query('INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img) VALUES(?,?,?,NULL)',[$ofPay,'Pay M3','Sintético']);
    $db->execute_query('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES(?,?,2,1005)',[$ofPay,$pay]);
    $r=P::publicar('restaurante',$ofPay,['clave'=>'rebanada','nombre'=>'Rebanada','precio'=>'60.00','activo'=>1,'componentes'=>[['articulo_id'=>$pay,'cantidad'=>'1.000','unidad'=>'pza','modalidad'=>'almacenado']]],0,'m3f_pay_publicar_01',true);
    $a(!empty($r['ok']),'Activar una presentación de producción previa sin existencias: '.json_encode($r['mensaje']??null));
    $r=Comercial::enviarComanda($ticket,[['oferta_id'=>$ofPay,'presentacion_id'=>(int)$r['presentacion_id'],'cantidad'=>1]],$clave('m3f_comanda_pay'),'restaurante');
    $a(!empty($r['ok'])&&(int)$db->query("SELECT COUNT(*) FROM faltantes_inventario WHERE articulo_id=$pay AND estado='pendiente'")->fetch_row()[0]===1,'Producción previa sin existencias envía la comanda y crea faltante');

    // 9. Fuera de venta, la falta de saldo sigue rechazando y un conteo no es negativo.
    $no(fn()=>$motor->registrarMerma('restaurante',['clave'=>'m3f_merma_neg','referencia'=>'m3f_merma_neg','motivo'=>'caducidad','lineas'=>[['almacen_id'=>1006,'articulo_id'=>$fresa,'cantidad'=>'5']]]),'Una merma no deja saldo negativo','SALDO_INSUFICIENTE');

    // 10. Migración de negativos (base existente): ajuste a 0 + faltante + CHECK.
    $db->query('ALTER TABLE almacenes DROP CHECK chk_almacen_sin_negativos');
    $db->query('UPDATE almacenes SET permitir_negativos=1 WHERE id=1006');
    $db->execute_query('UPDATE almacen_articulos SET saldo=? WHERE almacen_id=1006 AND articulo_id=?',['-2.500',$fresa]);
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m4-faltantes.sql');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m4-faltantes.sql');
    $a($saldo(1006,$fresa)==='0.000'&&(int)$db->query('SELECT COUNT(*) FROM almacenes WHERE permitir_negativos<>0')->fetch_row()[0]===0,'Migración: saldo a 0 y ningún almacén con negativos');
    $mig=$db->query("SELECT * FROM faltantes_inventario WHERE origen='migracion' AND almacen_id=1006")->fetch_all(MYSQLI_ASSOC);
    $a(count($mig)===1&&$mig[0]['cantidad']==='2.500'&&$mig[0]['ticket_item_id']===null,'Migración repetida crea un solo faltante de origen migración');
    $a($db->query("SELECT motivo FROM movimientos_inventario WHERE almacen_id=1006 AND articulo_id=$fresa AND tipo='ajuste' ORDER BY id DESC LIMIT 1")->fetch_row()[0]==='migracion_faltantes','El ajuste queda en el ledger con motivo migracion_faltantes');
    $no(fn()=>$db->query('UPDATE almacenes SET permitir_negativos=1 WHERE id=1006'),'El CHECK vuelve a fijar la política');
    $ledger=$db->query('SELECT COUNT(*) FROM almacen_articulos aa WHERE aa.saldo<>(SELECT COALESCE(SUM(m.delta),0) FROM movimientos_inventario m WHERE m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id) AND aa.almacen_id IN(1001,1005,1006) AND aa.articulo_id IN('.$fresa.','.$pay.')')->fetch_row()[0];
    $a((int)$ledger===1,'Saldos cuadran con el ledger salvo el negativo forzado a mano en la prueba');
    $a((int)$db->query('SELECT COUNT(*) FROM productos WHERE id<1000')->fetch_row()[0]===112,'112 productos preservados');
},'M3 faltantes',true);
