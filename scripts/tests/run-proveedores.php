<?php
// M5 · Proveedores por ámbito (docs/arquitectura.md §17): directorio propio y
// consolidado de Global, aislamiento por ámbito e IDs ajenos, permisos de consulta,
// asignaciones con un preferente por artículo, entrada ligada a su proveedor en una
// sola transacción (rollback, idempotencia, inactivo, ajeno, costo obligatorio),
// histórico exacto (recepciones, intervalo, artículos y evolución del costo) y retiro.
// Base temporal propia: php scripts/tests/run-proveedores.php --host=... --base=... --entorno=development
require __DIR__.'/soporte-m12.php';
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\DirectorioProveedores;
use Services\Security\ContextoNegocio as C;

m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $no=static function($r,string $m,?string $codigo=null)use($a){
        $ok=is_array($r)&&empty($r['ok']);$c=$r['codigo']??null;
        $a($ok&&($codigo===null||$c===$codigo),$m.($codigo!==null&&$c!==$codigo?" (código {$c})":''));
    };
    $lanza=static function(callable $f,string $m,string $codigo)use($a){
        try{$f();$c=null;}catch(Throwable $e){$c=$e->codigo??get_class($e);}
        $a($c===$codigo,$m." (código {$c})");
    };
    $actor=C::actorId();$nivel=C::nivelAuth();
    $dir=new DirectorioProveedores($db,$actor,$nivel);$adm=new AdministracionAlmacenes($db,$actor,$nivel);
    $uno=static fn(string $sql,array $p=[])=>$db->execute_query($sql,$p)->fetch_row()[0]??null;

    // 0. Esquema y fixture: cada ámbito con su proveedor y su apertura ligada.
    $a((int)$uno("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entradas_proveedor'")===1,'La instalación crea entradas_proveedor');
    $a((int)$uno('SELECT COUNT(*) FROM entradas_proveedor e JOIN proveedores p ON p.id=e.proveedor_id AND p.unidad_id=e.unidad_id')===3,'Las tres aperturas ligadas a un proveedor de su ámbito');
    $lanza(fn()=>$db->execute_query('INSERT INTO entradas_proveedor(operacion_id,unidad_id,proveedor_id) VALUES (1004,2,1002)'),'La FK impide ligar un proveedor de otro ámbito','mysqli_sql_exception');

    // 1. Directorio: cada ámbito ve sólo lo suyo; Global además consolida.
    $ids=static fn(array $l)=>array_map(static fn($p)=>(int)$p['id'],$l);
    $a($ids($dir->listar('restaurante'))===[1001],'Restaurante ve sólo su proveedor');
    $a($ids($dir->listar('panaderia'))===[1002],'Panadería ve sólo su proveedor');
    $a($ids($dir->listar('global'))===[1003],'Global ve los suyos por omisión');
    $cons=$dir->listar('global',true);
    $a(count($cons)===3&&array_column($cons,'editable','id')===[1003=>true,1001=>false,1002=>false],'El consolidado trae los tres y sólo el de Global es editable');
    $a($ids($dir->listar('restaurante',true))===[1001],'Un negocio no puede pedir el consolidado');
    $a((int)$dir->listar('restaurante')[0]['recepciones']===1,'El listado cuenta la recepción de apertura');

    // 2. IDs ajenos: un negocio no lee al de otro; Global sí, sin poder editarlo.
    $lanza(fn()=>$dir->proveedor('restaurante',1002),'Restaurante no abre un proveedor de Panadería','RECURSO_NO_ENCONTRADO');
    $lanza(fn()=>$dir->historial('panaderia',1001),'Panadería no lee el historial de Restaurante','RECURSO_NO_ENCONTRADO');
    $lanza(fn()=>$dir->proveedor('restaurante',999999),'Un ID inexistente responde igual que uno ajeno','RECURSO_NO_ENCONTRADO');
    $g=$dir->proveedor('global',1001);$a($g['editable']===false&&$g['slug']==='restaurante','Global lee al proveedor de Restaurante como consulta');
    $no($dir->guardar('global',1001,['nombre'=>'Tomado por Global']),'Global no edita a un proveedor de Restaurante','RECURSO_NO_ENCONTRADO');
    $no($dir->guardar('restaurante',1002,['nombre'=>'Tomado']),'Restaurante no edita a uno de Panadería','RECURSO_NO_ENCONTRADO');
    $a($uno('SELECT nombre FROM proveedores WHERE id=1001')==='Proveedor Restaurante'&&$uno('SELECT nombre FROM proveedores WHERE id=1002')==='Proveedor Panadería','Los intentos ajenos no cambian nada');

    // 3. Alta y validación.
    $r=$dir->guardar('restaurante',null,['nombre'=>'  Lácteos del Valle ','contacto'=>'Ana','telefono'=>'5512345678','correo'=>'ventas@lacteos.test','notas'=>'Entrega martes','activo'=>'1']);
    $a(!empty($r['ok'])&&!empty($r['confirmado']),'Alta confirmada: '.json_encode($r));$lac=(int)$r['proveedor_id'];
    $a($uno('SELECT CONCAT(nombre,"|",unidad_id,"|",activo) FROM proveedores WHERE id=?',[$lac])==='Lácteos del Valle|2|1','Se guarda recortado, en Restaurante y activo');
    $no($dir->guardar('restaurante',null,['nombre'=>'Lácteos del Valle']),'Nombre repetido en el mismo ámbito','PROVEEDOR_DUPLICADO');
    $a(!empty($dir->guardar('panaderia',null,['nombre'=>'Lácteos del Valle','activo'=>'1'])['ok']),'El mismo nombre es válido en otro ámbito');
    $no($dir->guardar('restaurante',null,['nombre'=>'Correo malo','correo'=>'no-es-correo']),'Correo inválido','CORREO_INVALIDO');
    $no($dir->guardar('restaurante',null,['nombre'=>'   ']),'Nombre vacío','DATOS_INVALIDOS');
    $no($dir->guardar('restaurante',null,['nombre'=>str_repeat('x',121)]),'Nombre demasiado largo','DATOS_INVALIDOS');
    $no($dir->guardar('restaurante',null,['nombre'=>['arreglo']]),'Un campo que no es texto','DATOS_INVALIDOS');

    // 4. Permisos: consulta sin edición y otro ámbito.
    $lector=FixtureAutorizacion3B::usuario($db,'prov_lector_pan',['panaderia'=>['permisos'=>['inventario'=>'ver']]]);
    $dl=new DirectorioProveedores($db,(int)$lector['id'],$nivel);
    $a(count($dl->listar('panaderia'))===2,'El lector de Panadería consulta su directorio');
    $no($dl->guardar('panaderia',null,['nombre'=>'Sin permiso']),'Sólo consulta no da de alta');
    $no($dl->guardarAsignaciones('panaderia',1002,[]),'Sólo consulta no asigna');
    $no($dl->eliminar('panaderia',1002),'Sólo consulta no elimina');
    try{$dl->listar('restaurante');$denegado=false;}catch(Services\Inventory\InventarioError $e){$denegado=true;}
    $a($denegado,'Sin membresía en Restaurante no hay directorio');
    $a((int)$uno('SELECT COUNT(*) FROM proveedores WHERE nombre=?',['Sin permiso'])===0,'Nada se creó sin permiso');

    // 5. Asignaciones: sólo artículos del ámbito, un preferente por artículo.
    $ajeno=(int)$uno('SELECT id FROM ingredientes i WHERE NOT EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id=i.id AND aa.unidad_id=2) ORDER BY id LIMIT 1');
    $a($ajeno>0,'Hay un artículo fuera de Restaurante para probar');
    $no($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>(string)$ajeno,'costo'=>'1','codigo'=>'','preferente'=>'0']]),'Un artículo que Restaurante no almacena','ARTICULO_AJENO');
    $no($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'1'],['articulo_id'=>'1002','costo'=>'2']]),'Artículo repetido','ARTICULO_REPETIDO');
    $no($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'-1']]),'Costo negativo','DECIMAL_INVALIDO');
    $no($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.12345']]),'Costo con más de cuatro decimales','DECIMAL_INVALIDO');
    $no($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'1','extra'=>'x']]),'Campos no admitidos','DATOS_INVALIDOS');
    $no($dir->guardarAsignaciones('restaurante',1002,[['articulo_id'=>'1002','costo'=>'1']]),'Asignar a un proveedor de otro ámbito','RECURSO_NO_ENCONTRADO');
    $a((int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores WHERE proveedor_id=?',[$lac])===0,'Los rechazos no dejan asignaciones');
    $a((int)$uno('SELECT preferente FROM ingrediente_proveedores WHERE proveedor_id=1001 AND ingrediente_id=1002')===1,'El proveedor de fixture es preferente de 1002');
    $r=$dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0180','codigo'=>'LEC-1','preferente'=>'1'],['articulo_id'=>'1001','costo'=>'0.0210','codigo'=>'','preferente'=>'0']]);
    $a(!empty($r['ok'])&&$r['articulos']===2,'Se asignan dos artículos');
    $a((int)$uno('SELECT preferente FROM ingrediente_proveedores WHERE proveedor_id=1001 AND ingrediente_id=1002')===0&&(int)$uno('SELECT preferente FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac])===1,'El nuevo preferente desmarca al anterior');
    $a($uno('SELECT costo FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac])==='0.0180','El costo de referencia se guarda exacto');
    $asig=$dir->asignaciones('restaurante',$lac);$a(array_column($asig,'codigo','articulo_id')===[1001=>null,1002=>'LEC-1']||count($asig)===2,'La ficha lista lo asignado');
    $pe=$dir->paraEntradas('restaurante');$a((int)$pe['porArticulo'][1002][0]['proveedor_id']===$lac,'La entrada propone primero al preferente');
    $a(!in_array(1002,array_map('intval',array_column($pe['proveedores'],'id')),true),'La entrada no ofrece proveedores de otro ámbito');
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0180','codigo'=>'LEC-1','preferente'=>'1']])['ok'])&&(int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores WHERE proveedor_id=?',[$lac])===1,'Reemplazo en bloque: quitar una fila la quita');

    // 6. Entrada con proveedor: atómica, idempotente y validada.
    $saldo=static fn()=>(string)$uno('SELECT saldo FROM almacen_articulos WHERE almacen_id=1002 AND articulo_id=1002');
    $ops=static fn()=>(int)$uno('SELECT COUNT(*) FROM operaciones_inventario');
    $entrada=static fn(array $extra)=>$adm->guardar('restaurante','entrada',$extra+['almacen_id'=>'1002','articulo_id'=>'1002','cantidad'=>'100']);
    $s0=$saldo();$o0=$ops();
    $no($entrada(['clave'=>'prov-sin-costo','proveedor_id'=>(string)$lac]),'Con proveedor el costo es obligatorio','COSTO_REQUERIDO');
    $no($entrada(['clave'=>'prov-ajeno','proveedor_id'=>'1002','costo_unitario'=>'1']),'Proveedor de otro ámbito','PROVEEDOR_INVALIDO');
    $no($entrada(['clave'=>'prov-inexistente','proveedor_id'=>'999999','costo_unitario'=>'1']),'Proveedor inexistente','PROVEEDOR_INVALIDO');
    $no($entrada(['clave'=>'prov-id-malo','proveedor_id'=>'abc','costo_unitario'=>'1']),'ID de proveedor inválido','ID_INVALIDO');
    $inactivo=(int)$dir->guardar('restaurante',null,['nombre'=>'Proveedor dormido'])['proveedor_id'];
    $no($entrada(['clave'=>'prov-inactivo','proveedor_id'=>(string)$inactivo,'costo_unitario'=>'1']),'Proveedor inactivo','PROVEEDOR_INACTIVO');
    $no($entrada(['clave'=>'prov-cant-mala','proveedor_id'=>(string)$lac,'costo_unitario'=>'1','cantidad'=>'-5']),'Cantidad inválida: el motor la rechaza y no queda vínculo');
    $a($saldo()===$s0&&$ops()===$o0&&(int)$uno('SELECT COUNT(*) FROM entradas_proveedor')===3,'Ningún rechazo deja operación, saldo ni vínculo');
    $r=$entrada(['clave'=>'prov-ok-1','proveedor_id'=>(string)$lac,'costo_unitario'=>'0.0180','nota'=>'Factura 77']);
    $a(!empty($r['ok'])&&!empty($r['confirmado'])&&isset($r['operacion_id']),'Entrada con proveedor confirmada: '.json_encode($r));$op1=(int)$r['operacion_id'];
    $a(DecimalOk::igual($saldo(),$s0,'100.000'),'El saldo sube exactamente 100');
    $a((int)$uno('SELECT proveedor_id FROM entradas_proveedor WHERE operacion_id=?',[$op1])===$lac,'La operación queda ligada a su proveedor');
    $a($uno("SELECT costo_unitario FROM movimientos_inventario WHERE operacion_id=? AND tipo='entrada'",[$op1])==='0.0180','El movimiento congela el costo capturado');
    $r=$entrada(['clave'=>'prov-ok-1','proveedor_id'=>(string)$lac,'costo_unitario'=>'0.0180','nota'=>'Factura 77']);
    $a(!empty($r['reintento'])&&(int)$uno('SELECT COUNT(*) FROM entradas_proveedor WHERE operacion_id=?',[$op1])===1&&DecimalOk::igual($saldo(),$s0,'100.000'),'Reintento idempotente: sin segundo saldo ni vínculo');
    $otro=(int)$dir->guardar('restaurante',null,['nombre'=>'Otro proveedor','activo'=>'1'])['proveedor_id'];
    $no($entrada(['clave'=>'prov-ok-1','proveedor_id'=>(string)$otro,'costo_unitario'=>'0.0180','nota'=>'Factura 77']),'La misma clave con otro proveedor es conflicto','CONFLICTO_IDEMPOTENCIA');
    $a((int)$uno('SELECT proveedor_id FROM entradas_proveedor WHERE operacion_id=?',[$op1])===$lac,'El conflicto no cambia al proveedor ligado');
    $r=$entrada(['clave'=>'prov-sin-proveedor']);$a(!empty($r['ok'])&&(int)$uno('SELECT COUNT(*) FROM entradas_proveedor WHERE operacion_id=?',[(int)$r['operacion_id']])===0,'Una entrada sin proveedor sigue funcionando y no se liga');
    $rg=$adm->guardar('global','entrada',['almacen_id'=>'1001','articulo_id'=>'1001','cantidad'=>'5','costo_unitario'=>'0.0200','proveedor_id'=>'1003','clave'=>'prov-global-1']);
    $a(!empty($rg['ok'])&&(int)$uno('SELECT unidad_id FROM entradas_proveedor WHERE operacion_id=?',[(int)$rg['operacion_id']])===1,'Global liga sus entradas a sus proveedores');
    $no($adm->guardar('global','entrada',['almacen_id'=>'1001','articulo_id'=>'1001','cantidad'=>'5','costo_unitario'=>'1','proveedor_id'=>'1001','clave'=>'prov-global-2']),'Global no liga un proveedor de Restaurante','PROVEEDOR_INVALIDO');

    // 7. Histórico: tres recepciones a diez días, dos entradas el mismo día cuentan una.
    $h=$dir->historial('restaurante',$lac);
    $a($h['resumen']['recepciones']===1&&$h['resumen']['intervalo_dias']===null,'Con una recepción no hay intervalo inventado');
    $mk=static function(string $clave,string $cant,string $costo,string $fecha)use($entrada,$db,$lac){
        $r=$entrada(['clave'=>$clave,'proveedor_id'=>(string)$lac,'costo_unitario'=>$costo,'cantidad'=>$cant]);
        $db->execute_query('UPDATE operaciones_inventario SET fecha=? WHERE id=?',[$fecha,(int)$r['operacion_id']]);
        return $r;
    };
    $db->execute_query('UPDATE operaciones_inventario SET fecha=? WHERE id=?',['2026-10-01 09:00:00',$op1]);
    $mk('prov-h-2','50','0.0190','2026-10-11 09:00:00');
    $mk('prov-h-2b','25.5','0.0200','2026-10-11 17:30:00');
    $mk('prov-h-3','30','0.0216','2026-10-21 08:15:00');
    $h=$dir->historial('restaurante',$lac);
    $a($h['resumen']['recepciones']===3&&$h['resumen']['primera']==='2026-10-01'&&$h['resumen']['ultima']==='2026-10-21','Tres días de recepción, del 1 al 21');
    $a($h['resumen']['intervalo_dias']===10.0&&$h['resumen']['proximo']==='2026-10-31','Cada 10 días: la siguiente toca el 31');
    $leche=$h['articulos'][0];
    $a((int)$leche['articulo_id']===1002&&$leche['veces']===3,'El artículo más pedido encabeza, con 3 recepciones');
    $a($leche['total']==='205.500'&&$leche['promedio']==='68.500','Total 205.5 y promedio 68.5 exactos');
    $a($leche['costo_primero']==='0.0180'&&$leche['costo_ultimo']==='0.0216'&&$leche['costo_min']==='0.0180'&&$leche['costo_max']==='0.0216','Primero, último, mínimo y máximo exactos');
    $a($leche['variacion']===20.0,'La variación del primer al último costo es +20 %');
    $a(count($leche['serie'])===4&&$leche['serie'][0]['costo']==='0.0216'&&$leche['serie'][3]['costo']==='0.0180','La serie va del más reciente al más antiguo');
    $a(count($h['recientes'])===4&&$h['recientes'][0]['costo']==='0.0216'&&$h['recientes'][3]['nota']==='Factura 77','Entradas recientes con nota y costo');
    $a((int)array_column($dir->listar('restaurante'),'recepciones','id')[$lac]===3,'El directorio cuenta 3 recepciones');
    $hg=$dir->historial('global',$lac);$a($hg['resumen']['recepciones']===3,'Global consulta el mismo histórico');

    // 8. Retiro: con historial se desactiva; sin historial se elimina con sus asignaciones.
    $no($dir->eliminar('restaurante',$lac),'Con historial no se elimina','PROVEEDOR_CON_HISTORIAL');
    $a((int)$uno('SELECT COUNT(*) FROM proveedores WHERE id=?',[$lac])===1,'Sigue existiendo');
    $a(!empty($dir->guardar('restaurante',$lac,['nombre'=>'Lácteos del Valle'])['ok'])&&(int)$uno('SELECT activo FROM proveedores WHERE id=?',[$lac])===0,'Desactivar conserva su historial');
    $a($dir->historial('restaurante',$lac)['resumen']['recepciones']===3,'El historial sobrevive a la desactivación');
    $no($entrada(['clave'=>'prov-tras-baja','proveedor_id'=>(string)$lac,'costo_unitario'=>'1']),'Desactivado ya no recibe entradas','PROVEEDOR_INACTIVO');
    $no($dir->eliminar('global',1001),'Global no elimina proveedores de un negocio','RECURSO_NO_ENCONTRADO');
    $dir->guardarAsignaciones('restaurante',$otro,[['articulo_id'=>'1001','costo'=>'0.0200']]);
    $a(!empty($dir->eliminar('restaurante',$otro)['ok'])&&(int)$uno('SELECT COUNT(*) FROM proveedores WHERE id=?',[$otro])===0&&(int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores WHERE proveedor_id=?',[$otro])===0,'Sin historial se elimina con sus asignaciones');
},"Proveedores");

/** Suma de cadenas decimales exactas para comparar saldos sin floats. */
final class DecimalOk
{
    public static function igual(string $actual, string $base, string $delta): bool
    {
        $l = static fn(string $v) => Services\Inventory\DecimalInventario::leer($v, 3, true);
        return $l($actual) === $l($base) + $l($delta);
    }
}
