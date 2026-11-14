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

    // El DML de instalación trae datos sintéticos SQL; validarlos antes de
    // reconstruir la tabla en la prueba de migración M5.
    $conteos=$db->query('SELECT CONCAT(unidad_id,":",proveedor_id,":",articulo_id) k,COUNT(*) n FROM historial_precios_proveedores GROUP BY unidad_id,proveedor_id,articulo_id ORDER BY unidad_id')->fetch_all(MYSQLI_ASSOC);
    $conteos=array_map('intval',array_column($conteos,'n','k'));ksort($conteos);
    $a($conteos===['1:1003:1001'=>5,'2:1001:1002'=>45,'3:1002:1001'=>10],'La instalación nueva incluye las series Global/Restaurante/Panadería 5/45/10: '.json_encode($conteos));
    $a((int)$uno("SELECT COUNT(*) FROM historial_precios_proveedores WHERE tipo='inicial'")===3&&(int)$uno("SELECT COUNT(*) FROM historial_precios_proveedores WHERE tipo='cambio'")===57,'La seed marca inicial y cambios sin simular actor real');
    $a((int)$uno("SELECT COUNT(*) FROM historial_precios_proveedores WHERE usuario_id IS NULL AND usuario_nombre_snapshot='Demostración sintética'")===60,'Las series identifican cada evento como demostración sintética');
    $a((int)$uno("SELECT COUNT(*) FROM historial_precios_proveedores h JOIN ingrediente_proveedores ip ON ip.unidad_id=h.unidad_id AND ip.proveedor_id=h.proveedor_id AND ip.ingrediente_id=h.articulo_id WHERE h.id=(SELECT x.id FROM historial_precios_proveedores x WHERE x.unidad_id=h.unidad_id AND x.proveedor_id=h.proveedor_id AND x.articulo_id=h.articulo_id ORDER BY x.created_at DESC,x.id DESC LIMIT 1) AND h.precio_nuevo=ip.costo")===3,'Cada serie termina en el costo vigente sin mutarlo');
    $sqlDev=(string)file_get_contents($root.'/database/development.sql');$sqlExistente=(string)file_get_contents($root.'/database/actualizaciones/sembrar-precios-proveedores-desarrollo.sql');
    $extraerFuente=static function(string $s):string{$inicio='-- INICIO FUENTE SQL COMPARTIDA: demo-historial-precios-proveedores-v1';$fin='-- FIN FUENTE SQL COMPARTIDA: demo-historial-precios-proveedores-v1';$a=strpos($s,$inicio);$b=$a===false?false:strpos($s,$fin,$a);return $a===false||$b===false?'':str_replace(["\r\n","\r"],"\n",substr($s,$a,$b+strlen($fin)-$a));};
    $a($extraerFuente($sqlDev)!==''&&$extraerFuente($sqlDev)===$extraerFuente($sqlExistente),'La seed de instalación y la carga controlada comparten el mismo bloque SQL');

    // Migración en un esquema preexistente con asociaciones M5; repetible y sin
    // atribuir precios/fechas anteriores que el sistema nunca registró.
    $db->query('DROP TABLE historial_precios_proveedores');
    $db->execute_query("DELETE FROM esquema_versiones WHERE version='etapa1-precios-proveedores'");
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/etapa1-precios-proveedores.sql');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/etapa1-precios-proveedores.sql');
    $a((int)$uno("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='historial_precios_proveedores'")===1,'La migración repetida crea la tabla histórica');
    $a((int)$uno("SELECT COUNT(*) FROM esquema_versiones WHERE version='etapa1-precios-proveedores'")===1,'La migración registra su versión una sola vez');
    $a((int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===0&&(int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores')>0,'La migración no inventa historial para asociaciones antiguas');
    $db->query('DROP TABLE historial_precios_proveedores');
    $db->execute_query("DELETE FROM esquema_versiones WHERE version='demo-historial-precios-proveedores-v1'");
    $pendiente=$dir->guardarAsignaciones('restaurante',1001,[]);
    $a(($pendiente['codigo']??'')==='MIGRACION_PENDIENTE'&&str_contains((string)($pendiente['mensaje']??''),'etapa1-precios-proveedores.sql'),'La escritura avisa con claridad si falta instalar la migración');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/etapa1-precios-proveedores.sql');

    // Instalaciones existentes reciben datos solo con opt-in, instalación de
    // desarrollo/test completa, asociaciones intactas y ausencia de historia previa.
    $carga=$root.'/database/actualizaciones/sembrar-precios-proveedores-desarrollo.sql';
    $intentarCarga=static function(?string $confirmacion)use($db,$carga):bool{
        $db->execute_query('SET @cp_confirmar_demo_precios=?',[$confirmacion]);
        try{Multinegocio\SqlArchivo::ejecutar($db,$carga);$rechazada=false;}catch(Throwable){$db->rollback();$rechazada=true;}
        $db->query('DROP TEMPORARY TABLE IF EXISTS _cp_guard_demo_precios');$db->query('SET @cp_confirmar_demo_precios=NULL');$db->query('SET @cp_demo_precios_aprobada=NULL');
        return $rechazada;
    };
    $a($intentarCarga('CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO')&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===0,'La carga controlada rechaza esquemas sin marcador de instalación development/test');
    $owner=(string)$uno('SELECT token FROM _cp_multinegocio_owner');$adminId=(int)$uno("SELECT id FROM usuarios WHERE username='admin_demo'");
    $db->execute_query("INSERT INTO instalacion_desarrollo(id,entorno,propietario_token,dml_sha256,administrador_id,completada) VALUES (1,'development',?,SHA2('fixture_m5_seed',256),?,1)",[$owner,$adminId]);
    $a($intentarCarga(null)&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===0,'La carga controlada requiere opt-in explícito');
    $db->execute_query('UPDATE ingrediente_proveedores SET costo=0.0151 WHERE unidad_id=2 AND proveedor_id=1001 AND ingrediente_id=1002');
    $a($intentarCarga('CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO')&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===0,'La carga controlada rechaza referencias alteradas sin cambiar datos');
    $db->execute_query('UPDATE ingrediente_proveedores SET costo=0.0150 WHERE unidad_id=2 AND proveedor_id=1001 AND ingrediente_id=1002');
    $db->execute_query("INSERT INTO historial_precios_proveedores(unidad_id,proveedor_id,articulo_id,proveedor_nombre_snapshot,articulo_nombre_snapshot,unidad_base_snapshot,precio_anterior,precio_nuevo,tipo,usuario_id,usuario_nombre_snapshot) VALUES(2,1001,1002,'Proveedor Restaurante','Leche compartida','ml',NULL,'0.0150','inicial',NULL,'Fixture de guardia')");$guardHistoriaId=$db->insert_id;
    $a($intentarCarga('CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO')&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===1,'La carga controlada rechaza historia previa sin insertar registros');
    $db->execute_query('DELETE FROM historial_precios_proveedores WHERE id=?',[$guardHistoriaId]);
    $a(!$intentarCarga('CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO')&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===60,'La carga controlada aprobada crea las tres series completas');
    $a((int)$uno("SELECT COUNT(*) FROM esquema_versiones WHERE version='demo-historial-precios-proveedores-v1'")===1,'La carga controlada registra su marcador una sola vez');
    $a($intentarCarga('CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO')&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores')===60,'La repetición de la carga controlada rechaza duplicar y no altera historia');
    $fechaSintetica=$uno("SELECT MAX(created_at) FROM historial_precios_proveedores WHERE proveedor_id=1001 AND articulo_id=1002");
    $a(strtotime((string)$fechaSintetica)<=time(),'Los cierres sintéticos quedan antes de una modificación real actual');
    $hpSeed=$dir->historialPrecios('restaurante',1001,1,1002);$graficaSeed=$dir->graficaPrecio('restaurante',1001,1002);
    $a($hpSeed['total']===45&&$hpSeed['paginas']===2&&count($hpSeed['filas'])===25,'La serie larga ofrece página de 25 sin cargar todos los eventos');
    $a($graficaSeed!==null&&$graficaSeed['eventos']===40&&$graficaSeed['recortada']===true&&count(explode(' ',trim($graficaSeed['puntos'])))===40,'La gráfica limita a los últimos 40 puntos de 45 comparables');
    $a(count($graficaSeed['eje_x'])===4&&$graficaSeed['eje_x'][0]['x']<$graficaSeed['eje_x'][3]['x']&&$graficaSeed['eje_y'][0]['valor']!==$graficaSeed['eje_y'][2]['valor'],'La gráfica usa fechas reales en X y escala de precio legible en Y');
    $graficaPan=$dir->graficaPrecio('panaderia',1002,1001);$graficaGlobal=$dir->graficaPrecio('global',1003,1001);
    $a($graficaPan!==null&&$graficaPan['eventos']===10&&!$graficaPan['recortada']&&$graficaGlobal!==null&&$graficaGlobal['eventos']===5,'Las series medianas/cortas de Panadería y Global se consultan en su ámbito');
    $idsGlobal=array_column($db->execute_query('SELECT id FROM historial_precios_proveedores WHERE unidad_id=1 AND proveedor_id=1003 AND articulo_id=1001 ORDER BY created_at,id')->fetch_all(MYSQLI_ASSOC),'id');
    foreach([2=>10,3=>9,4=>0] as $pos=>$dias)$db->execute_query('UPDATE historial_precios_proveedores SET created_at=DATE_SUB(TIMESTAMP(CURDATE(),\'12:00:00\'),INTERVAL ? DAY) WHERE id=?',[$dias,$idsGlobal[$pos]]);
    $graficaFechas=$dir->graficaPrecio('global',1003,1001);$coordsFecha=array_map(static fn($p)=>(float)explode(',',$p)[0],explode(' ',trim($graficaFechas['puntos'])));
    $a(count($coordsFecha)===5&&$coordsFecha[3]-$coordsFecha[2]<5,'Las posiciones horizontales respetan el intervalo real irregular entre fechas');
    $db->execute_query("UPDATE historial_precios_proveedores SET precio_nuevo='0.0200' WHERE unidad_id=1 AND proveedor_id=1003 AND articulo_id=1001");
    $graficaPlana=$dir->graficaPrecio('global',1003,1001);
    $a($graficaPlana!==null&&$graficaPlana['eje_maximo']>$graficaPlana['eje_minimo']&&count(array_unique(array_map(static fn($p)=>explode(',',$p)[1],explode(' ',trim($graficaPlana['puntos'])))))===1,'Una serie plana conserva una escala no nula y dibuja sin división entre cero');
    $articuloSeed=$dir->articulosHistorialPrecios('restaurante',1001);
    $a((string)array_values(array_filter($articuloSeed,static fn($x)=>(int)$x['articulo_id']===1002))[0]['precio_actual']==='0.0150','La selección contextual expone precio vigente de su asociación');
    $uiData=['datos_sinteticos'=>true,'fuente'=>'database/development.sql importado como SQL en base temporal M5','proveedor'=>$dir->proveedor('restaurante',1001),'historial'=>$dir->historial('restaurante',1001),'historialPrecios'=>$hpSeed,'articulosHistorialPrecios'=>$articuloSeed,'articuloPrecioContexto'=>array_values(array_filter($articuloSeed,static fn($x)=>(int)$x['articulo_id']===1002))[0],'graficaPrecio'=>$graficaSeed,'comparacionPrecios'=>$dir->compararPrecios('restaurante',1001,1002),'asignaciones'=>$dir->asignaciones('restaurante',1001)];
    file_put_contents($root.'/docs/desarrollo/evidencia-etapa1-ajuste/ui-fixture-seed-sql.json',json_encode($uiData,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
    $a(!empty($dir->guardarAsignaciones('restaurante',1001,[['articulo_id'=>'1002','costo'=>'0.0151','preferente'=>'1']])['ok']),'Un cambio real de referencia se registra sobre la seed');
    $real=$db->execute_query('SELECT tipo,precio_anterior,precio_nuevo,created_at FROM historial_precios_proveedores WHERE proveedor_id=1001 AND articulo_id=1002 ORDER BY created_at DESC,id DESC LIMIT 1')->fetch_assoc();
    $a($real['tipo']==='cambio'&&$real['precio_anterior']==='0.0150'&&$real['precio_nuevo']==='0.0151'&&strtotime((string)$real['created_at'])>strtotime((string)$fechaSintetica),'El cambio real actual queda como último evento y encadena el precio anterior vigente');
    $a(!empty($dir->guardarAsignaciones('restaurante',1001,[['articulo_id'=>'1002','costo'=>'0.0150','preferente'=>'1']])['ok']),'La fixture vuelve al precio original después del control de cambio real');
    $db->query('DELETE FROM historial_precios_proveedores');$db->execute_query("DELETE FROM esquema_versiones WHERE version='demo-historial-precios-proveedores-v1'");$db->query('DELETE FROM instalacion_desarrollo WHERE id=1');

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
    $a($dl->historialPrecios('panaderia',1002)['total']===0,'El permiso de lectura también permite abrir la sección de precios');
    $a(is_array($dl->compararPrecios('panaderia',1002,1002)),'El permiso de lectura permite consultar comparación dentro del ámbito');
    $no($dl->guardar('panaderia',null,['nombre'=>'Sin permiso']),'Sólo consulta no da de alta');
    $no($dl->guardarAsignaciones('panaderia',1002,[]),'Sólo consulta no asigna');
    $no($dl->eliminar('panaderia',1002),'Sólo consulta no elimina');
    try{$dl->listar('restaurante');$denegado=false;}catch(Services\Inventory\InventarioError $e){$denegado=true;}
    $a($denegado,'Sin membresía en Restaurante no hay directorio');
    $lanza(fn()=>$dl->historialPrecios('restaurante',1001),'Sin membresía en Restaurante no lee el historial de precios','AMBITO_DENEGADO');
    $lanza(fn()=>$dl->compararPrecios('restaurante',1001,1002),'Sin membresía en Restaurante no compara precios','AMBITO_DENEGADO');
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
    $baselines=(int)$uno("SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=? AND tipo='inicial'",[$lac]);
    $a($baselines===2,'Cada asociación nueva registra precio inicial');
    $snapshot=$db->execute_query('SELECT h.usuario_nombre_snapshot,h.articulo_nombre_snapshot,h.unidad_base_snapshot,i.nombre,i.unidad FROM historial_precios_proveedores h JOIN ingredientes i ON i.id=h.articulo_id WHERE h.proveedor_id=? AND h.articulo_id=1002 ORDER BY h.id LIMIT 1',[$lac])->fetch_assoc();
    $a($snapshot!==null&&$snapshot['usuario_nombre_snapshot']!==''&&$snapshot['articulo_nombre_snapshot']===$snapshot['nombre']&&$snapshot['unidad_base_snapshot']===$snapshot['unidad'],'El historial conserva actor y snapshots legibles de artículo/unidad');
    $asig=$dir->asignaciones('restaurante',$lac);$a(array_column($asig,'codigo','articulo_id')===[1001=>null,1002=>'LEC-1']||count($asig)===2,'La ficha lista lo asignado');
    $pe=$dir->paraEntradas('restaurante');$a((int)$pe['porArticulo'][1002][0]['proveedor_id']===$lac,'La entrada propone primero al preferente');
    $a(!in_array(1002,array_map('intval',array_column($pe['proveedores'],'id')),true),'La entrada no ofrece proveedores de otro ámbito');
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0180','codigo'=>'LEC-1','preferente'=>'1']])['ok'])&&(int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores WHERE proveedor_id=?',[$lac])===1,'Reemplazo en bloque: quitar una fila la quita');
    $h0=(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=?',[$lac]);
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0180','codigo'=>'LEC-UPDATED','preferente'=>'1']])['ok'])&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=?',[$lac])===$h0,'Editar código sin cambiar precio no agrega eventos');
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0190','codigo'=>'LEC-1','preferente'=>'1']])['ok']),'El cambio real de referencia se guarda');
    $ev=$db->execute_query("SELECT precio_anterior,precio_nuevo,tipo FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1002 ORDER BY id DESC LIMIT 1",[$lac])->fetch_assoc();
    $a($ev['precio_anterior']==='0.0180'&&$ev['precio_nuevo']==='0.0190'&&$ev['tipo']==='cambio','Cambio exacto enlaza el último precio registrado');
    // Asociación legada sin historial observada a la fecha actual; si cambia en el
    // mismo envío se registran baseline observado y cambio, sin fecha retroactiva.
    $legacyArticle=(int)$uno('SELECT DISTINCT aa.articulo_id FROM almacen_articulos aa WHERE aa.unidad_id=2 AND aa.articulo_id NOT IN (1001,1002) ORDER BY aa.articulo_id LIMIT 1');
    $a($legacyArticle>0,'Hay un artículo para probar una asociación M5 sin historial');
    $db->execute_query("INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo) VALUES (2,?,?,0.0200)",[$legacyArticle,$lac]);
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0190'],['articulo_id'=>(string)$legacyArticle,'costo'=>'0.0210']])['ok']),'La asociación legada se actualiza con historial');
    $tipos=$db->execute_query('SELECT tipo,precio_anterior,precio_nuevo FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=? ORDER BY id',[$lac,$legacyArticle])->fetch_all(MYSQLI_ASSOC);
    $a(count($tipos)===2&&$tipos[0]['tipo']==='base_observada'&&$tipos[0]['precio_anterior']===null&&$tipos[0]['precio_nuevo']==='0.0200'&&$tipos[1]['tipo']==='cambio'&&$tipos[1]['precio_anterior']==='0.0200'&&$tipos[1]['precio_nuevo']==='0.0210','Primer guardado de asociación antigua observa base y cambio sin inventar pasado');
    // Fallo posterior al INSERT histórico debe revertir también el evento.
    $antesRollback=(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=?',[$lac]);
    $db->query("CREATE TRIGGER qa_precios_forzar_rollback BEFORE UPDATE ON ingrediente_proveedores FOR EACH ROW BEGIN IF NEW.costo='0.0999' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rollback fixture'; END IF; END");
    $fallo=$dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0999']]);
    $db->query('DROP TRIGGER qa_precios_forzar_rollback');
    $a(empty($fallo['ok'])&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=?',[$lac])===$antesRollback&&$uno('SELECT costo FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac])==='0.0190','Rollback revierte evento y precio vigente ante fallo transaccional');
    // Retirar y reasignar no reinicia la serie: el nuevo precio se enlaza al último evento.
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0190']])['ok']),'Retirar otra asociación conserva su historia');
    $conteoReasig=(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1001',[$lac]);
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1001','costo'=>'0.0220']])['ok']),'Reasignar artículo conserva su trayectoria previa');
    $re=$db->execute_query('SELECT precio_anterior,precio_nuevo FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1001 ORDER BY id DESC LIMIT 1',[$lac])->fetch_assoc();
    $a($re['precio_anterior']==='0.0210'&&$re['precio_nuevo']==='0.0220'&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1001',[$lac])===$conteoReasig+1,'Reasignación compara contra el último precio y no crea baseline falsa');
    $hp=$dir->historialPrecios('restaurante',$lac,1,1001);
    $a($hp['total']===2&&count($hp['filas'])===2&&$hp['filas'][0]['precio_actual']==='0.0220'&&$hp['filas'][0]['diferencia']==='0.0010','Historial completo paginado incluye precio actual y diferencia exacta');
    $a($hp['filas'][0]['variacion_pct']!==null,'La variación porcentual se calcula con base positiva');
    $articuloComun=(int)$uno('SELECT a2.articulo_id FROM almacen_articulos a2 JOIN almacen_articulos a3 ON a3.articulo_id=a2.articulo_id WHERE a2.unidad_id=2 AND a3.unidad_id=3 ORDER BY a2.articulo_id LIMIT 1');
    $a($articuloComun>0,'Hay artículo almacenado en Restaurante y Panadería para validar comparación acotada');
    $db->execute_query('INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo) VALUES (2,?,?,0.0300) ON DUPLICATE KEY UPDATE costo=VALUES(costo)',[$articuloComun,$lac]);
    $db->execute_query('INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo) VALUES (3,?,1002,0.0280) ON DUPLICATE KEY UPDATE costo=VALUES(costo)',[$articuloComun]);
    $db->execute_query('INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo) VALUES (1,?,1003,0.0270) ON DUPLICATE KEY UPDATE costo=VALUES(costo)',[$articuloComun]);
    $cmp=$dir->compararPrecios('restaurante',$lac,$articuloComun);
    $a(count($cmp)>=1&&array_reduce($cmp,static fn($ok,$x)=>$ok&&(int)$x['unidad_id']===2,true),'Comparación excluye ofertas de Panadería y Global aunque compartan artículo');
    $cmpGlobal=$dir->compararPrecios('global',$lac,$articuloComun);
    $a(count($cmpGlobal)>=1&&array_reduce($cmpGlobal,static fn($ok,$x)=>$ok&&(int)$x['unidad_id']===2,true),'Global consulta proveedores del ámbito del proveedor abierto sin mezclar negocios');
    $no($dir->guardarAsignaciones('global',$lac,[['articulo_id'=>(string)$articuloComun,'costo'=>'1']]),'Global no edita precio de referencia de Restaurante','RECURSO_NO_ENCONTRADO');
    $carrera=m12Carrera($db,'precios_proveedor_race',['restaurante',$lac,[['articulo_id'=>'1002','costo'=>'0.0310'],['articulo_id'=>'1002','costo'=>'0.0320']]]);
    $a(count($carrera)===2&&!empty($carrera[0]['ok'])&&!empty($carrera[1]['ok']),'Las escrituras concurrentes terminan en procesos independientes');
    $cadena=$db->execute_query("SELECT precio_anterior,precio_nuevo FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1002 AND tipo='cambio' ORDER BY id",[$lac])->fetch_all(MYSQLI_ASSOC);
    $continua=true;
    for($i=1;$i<count($cadena);$i++)if($cadena[$i]['precio_anterior']!==$cadena[$i-1]['precio_nuevo'])$continua=false;
    $final=(string)$uno('SELECT costo FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac]);
    $a($continua&&$cadena[count($cadena)-1]['precio_nuevo']===$final&&in_array($final,['0.0310','0.0320'],true),'Las escrituras concurrentes encadenan precios sin anterior obsoleto');
    $unidadAntes=(string)$uno('SELECT unidad FROM ingredientes WHERE id=1002');
    $db->execute_query("UPDATE ingredientes SET unidad='kg' WHERE id=1002");
    $precioUnidad=(string)$uno('SELECT costo FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac]);
    $a(!empty($dir->guardarAsignaciones('restaurante',$lac,[['articulo_id'=>'1002','costo'=>$precioUnidad]])['ok']),'El cambio de unidad abre una base comparable nueva');
    $baseUnidad=$db->execute_query("SELECT tipo,unidad_base_snapshot,precio_anterior FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1002 ORDER BY id DESC LIMIT 1",[$lac])->fetch_assoc();
    $a($baseUnidad['tipo']==='base_observada'&&$baseUnidad['unidad_base_snapshot']==='kg'&&$baseUnidad['precio_anterior']===null,'El cambio de unidad no calcula variación contra la unidad anterior');
    $precioFilas=$dir->historialPrecios('restaurante',$lac,1,1002)['filas'];
    $a($precioFilas[0]['unidad']==='kg'&&$precioFilas[0]['unidad_actual']==='kg','La lectura muestra unidad histórica y unidad actual');
    $a($dir->graficaPrecio('restaurante',$lac,1002)===null,'La gráfica no mezcla con la unidad anterior cuando el tramo actual tiene menos de tres datos');
    $db->execute_query('UPDATE ingredientes SET unidad=? WHERE id=1002',[$unidadAntes]);

    // Precio anterior cero: variación absoluta válida y porcentual indefinida.
    $cero=(int)$dir->guardar('restaurante',null,['nombre'=>'Proveedor precio cero','activo'=>'1'])['proveedor_id'];
    $dir->guardarAsignaciones('restaurante',$cero,[['articulo_id'=>'1001','costo'=>'0.0000']]);
    $dir->guardarAsignaciones('restaurante',$cero,[['articulo_id'=>'1001','costo'=>'0.0001']]);
    $hCero=$dir->historialPrecios('restaurante',$cero,1,1001)['filas'];
    $a($hCero[0]['diferencia']==='0.0001'&&$hCero[0]['variacion_pct']===null,'Desde precio cero calcula diferencia absoluta y deja porcentaje nulo');
    $a($dir->graficaPrecio('restaurante',$cero,1001)===null,'Con menos de tres eventos se omite la gráfica');
    $no($dir->eliminar('restaurante',$cero),'Un proveedor solo con historial de precios tampoco se borra','PROVEEDOR_CON_HISTORIAL');
    $a(!empty($dir->guardar('restaurante',$cero,['nombre'=>'Proveedor precio cero','activo'=>'0'])['ok'])&&$dir->historialPrecios('restaurante',$cero,1,1001)['total']===2,'La baja lógica conserva historial sin entradas');

    // Más de una página, sin truncar el historial de consulta.
    $bulk=(int)$dir->guardar('restaurante',null,['nombre'=>'Proveedor paginado','activo'=>'1'])['proveedor_id'];
    $dir->guardarAsignaciones('restaurante',$bulk,[['articulo_id'=>'1001','costo'=>'0.0100']]);
    for($i=1;$i<=26;$i++)$dir->guardarAsignaciones('restaurante',$bulk,[['articulo_id'=>'1001','costo'=>($i%2?'0.0101':'0.0100')]]);
    $pagina1=$dir->historialPrecios('restaurante',$bulk,1,1001);$pagina2=$dir->historialPrecios('restaurante',$bulk,2,1001);
    $a($pagina1['total']===27&&$pagina1['paginas']===2&&count($pagina1['filas'])===25&&count($pagina2['filas'])===2,'El historial paginado conserva todos los eventos posteriores al límite');
    $a($dir->historialPrecios('restaurante',$bulk,PHP_INT_MAX,1001)['pagina']===2,'La página excesiva se limita al último bloque sin overflow');

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
    $dir->guardarAsignaciones('restaurante',$lac,[]);
    $traceRows=$db->execute_query('SELECT tipo,precio_anterior,precio_nuevo,unidad_base_snapshot,created_at,usuario_nombre_snapshot FROM historial_precios_proveedores WHERE proveedor_id=? AND articulo_id=1002 ORDER BY id',[$lac])->fetch_all(MYSQLI_ASSOC);
    $a((int)$uno('SELECT COUNT(*) FROM ingrediente_proveedores WHERE proveedor_id=? AND ingrediente_id=1002',[$lac])===0&&count($traceRows)>=4,'Retirar la asociación conserva la secuencia de precios');
    file_put_contents($root.'/docs/desarrollo/evidencia-etapa1-ajuste/traza-precios-sintetica.json',json_encode([
        'fixture_sintetica'=>true,'aviso'=>'Datos generados por prueba aislada; no son precios reales.',
        'ambito'=>'restaurante','proveedor'=>'Lácteos del Valle','articulo_id'=>1002,
        'asociacion_vigente'=>false,'eventos'=>$traceRows,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
    $no($dir->eliminar('restaurante',$lac),'Con historial no se elimina','PROVEEDOR_CON_HISTORIAL');
    $a((int)$uno('SELECT COUNT(*) FROM proveedores WHERE id=?',[$lac])===1,'Sigue existiendo');
    $a(!empty($dir->guardar('restaurante',$lac,['nombre'=>'Lácteos del Valle'])['ok'])&&(int)$uno('SELECT activo FROM proveedores WHERE id=?',[$lac])===0,'Desactivar conserva su historial');
    $a($dir->historial('restaurante',$lac)['resumen']['recepciones']===3,'El historial sobrevive a la desactivación');
    $no($entrada(['clave'=>'prov-tras-baja','proveedor_id'=>(string)$lac,'costo_unitario'=>'1']),'Desactivado ya no recibe entradas','PROVEEDOR_INACTIVO');
    $no($dir->eliminar('global',1001),'Global no elimina proveedores de un negocio','RECURSO_NO_ENCONTRADO');
    $dir->guardarAsignaciones('restaurante',$otro,[['articulo_id'=>'1001','costo'=>'0.0200']]);
    $no($dir->eliminar('restaurante',$otro),'Sin entradas pero con historial de precios no se borra','PROVEEDOR_CON_HISTORIAL');
    $a(!empty($dir->guardar('restaurante',$otro,['nombre'=>'Otro proveedor','activo'=>'0'])['ok'])&&(int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=?',[$otro])===1,'Proveedor inactivo y sin entradas conserva historial de precios');
    $sinHist=(int)$dir->guardar('restaurante',null,['nombre'=>'Proveedor sin trazabilidad','activo'=>'1'])['proveedor_id'];
    $a(!empty($dir->eliminar('restaurante',$sinHist)['ok'])&&(int)$uno('SELECT COUNT(*) FROM proveedores WHERE id=?',[$sinHist])===0,'Sin entradas ni precios todavía puede eliminarse');
    // El usuario puede borrarse conservando su nombre auditado en el evento.
    $actorSnap=FixtureAutorizacion3B::usuario($db,'prov_actor_snap',['restaurante'=>['permisos'=>['inventario'=>'editar']]]);
    $dirSnap=new DirectorioProveedores($db,(int)$actorSnap['id'],$nivel);
    $provSnap=(int)$dir->guardar('restaurante',null,['nombre'=>'Proveedor snapshot','activo'=>'1'])['proveedor_id'];
    $dirSnap->guardarAsignaciones('restaurante',$provSnap,[['articulo_id'=>'1001','costo'=>'0.0130']]);
    $actorName=(string)$uno('SELECT nombre FROM usuarios WHERE id=?',[$actorSnap['id']]);
    $db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=?',[$actorSnap['id']]);
    $db->execute_query('DELETE FROM usuario_unidades WHERE usuario_id=?',[$actorSnap['id']]);
    $db->execute_query('DELETE FROM usuarios WHERE id=?',[$actorSnap['id']]);
    $a((int)$uno('SELECT COUNT(*) FROM historial_precios_proveedores WHERE proveedor_id=? AND usuario_id IS NULL AND usuario_nombre_snapshot=?',[$provSnap,$actorName])===1,'El borrado de usuario nulifica FK y conserva snapshot legible');
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
