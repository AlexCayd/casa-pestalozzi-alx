<?php
// M3 · integración vista ↔ servicio (docs/arquitectura.md §16): datos reales de una
// base temporal, render de la vista real de Almacenes y envío de los formularios
// tal como salen del HTML (conciliar, confirmar/rechazar regularización y entrada
// en piezas). No sustituye un recorrido HTTP con sesión, CSRF y navegador.
require __DIR__.'/soporte-m12.php';
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\Almacenes;
use Services\Inventory\Articulos as A;
use Services\Menu\PresentacionesOferta as P;
use Services\Pos\Comercial;
use Services\Security\ContextoNegocio as C;

m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $actor=C::actorId();$nivel=C::nivelAuth();
    $motor=new Almacenes($db,$actor,$nivel);$admin=new AdministracionAlmacenes($db,$actor,$nivel);
    $saldo=static fn($w,$id)=>(string)$db->execute_query('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?',[$w,$id])->fetch_row()[0];
    $render=static function(string $ctx,string $vista,array $filtros=[])use($admin,$root):string{
        $datos=$admin->consultar($ctx,$filtros,$vista);
        $base='/admin/'.($ctx==='restaurante'?'':$ctx.'/').'almacenes';
        $v=array_merge($datos,['contextoAlmacenes'=>$ctx,'nombreContexto'=>Services\Security\Contextos::NOMBRES[$ctx],'base'=>$base,'vista'=>$vista,'filtros'=>$filtros,'alertas'=>[],
            'volverContextual'=>null,'articuloContextual'=>null,'almacenesDisponiblesAsociacion'=>[],'almacenesAsociadosActuales'=>[],'abrirAsociacion'=>false,'mensajeAsociacion'=>null,
            'puedeEditar'=>true,'puedeCrearAlmacen'=>$ctx==='global','puedeNuevoArticulo'=>true,'puedeCatalogo'=>$ctx==='global','csrf'=>'csrf-prueba']);
        extract($v);ob_start();include $root.'/views/admin/almacenes/index.php';return (string)ob_get_clean();
    };
    $xp=static function(string $html):DOMXPath{$d=new DOMDocument();libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();return new DOMXPath($d);};
    // Campos de un formulario como los enviaría el navegador (sin botones ni casillas desmarcadas).
    $campos=static function(DOMXPath $x,DOMElement $form):array{
        $out=[];
        foreach($x->query('.//input|.//select|.//textarea',$form) as $el){
            $n=$el->getAttribute('name');if($n===''||$el->hasAttribute('disabled'))continue;
            $t=strtolower($el->getAttribute('type'));if(in_array($t,['submit','button'],true))continue;
            if(in_array($t,['checkbox','radio'],true)&&!$el->hasAttribute('checked'))continue;
            $val=$el->nodeName==='select'?(($o=$x->query('.//option[@selected]',$el)->item(0))?$o->getAttribute('value'):''):($el->nodeName==='textarea'?$el->textContent:$el->getAttribute('value'));
            $out[]=[$n,$val];
        }
        $q=implode('&',array_map(static fn($p)=>rawurlencode($p[0]).'='.rawurlencode($p[1]),$out));parse_str($q,$r);unset($r['volver_contextual']);return $r; // Igual que AdminAlmacenesController::guardar().
    };

    // Artículo y venta con faltante: Barra con 2, se venden 5.
    $r=$motor->crearArticulo('global',['nombre'=>'Limón vista M3','unidad'=>'pza','costo'=>'1.0000']);$lim=(int)$r['articulo_id'];
    foreach(['restaurante','global'] as $ctx)A::configurar($ctx,$lim,['comprable'=>1,'componente'=>1,'elaborable'=>0,'almacenable'=>1,'costo_conocido'=>1,'activo'=>1],0);
    foreach([['restaurante',1005],['restaurante',1006],['global',1001]] as [$ctx,$w])$motor->asociarArticulo($ctx,$w,$lim);
    foreach([['restaurante',1005,'2.000'],['restaurante',1006,'1.000'],['global',1001,'5.000']] as $i=>[$ctx,$w,$q])$motor->registrarEntrada($ctx,['clave'=>'vista-ent-'.$i,'referencia'=>'vista-ent-'.$i,'motivo'=>'compra','lineas'=>[['almacen_id'=>$w,'articulo_id'=>$lim,'cantidad'=>$q]]]);
    $db->query("INSERT INTO productos(nombre) VALUES('Limonada vista M3')");$prod=(int)$db->insert_id;$base=$db->query('SELECT * FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1')->fetch_assoc();
    $db->execute_query('INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES(?,2,?,?,?,1)',[$prod,$base['categoria_id'],'30.00',$base['area_id']]);$of=(int)$db->insert_id;
    $db->execute_query('INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img) VALUES(?,?,?,NULL)',[$of,'Limonada vista M3','Sintético']);
    $db->execute_query('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES(?,?,2,1005)',[$of,$lim]);
    $r=P::publicar('restaurante',$of,['clave'=>'vaso','nombre'=>'Vaso','precio'=>'30.00','activo'=>1,'componentes'=>[['articulo_id'=>$lim,'cantidad'=>'1.000','unidad'=>'pza','modalidad'=>'almacenado']]],0,'vista_presentacion_m3',true);
    $db->query("INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,'Vista M3','abierto')");$ticket=(int)$db->insert_id;
    $r=Comercial::enviarComanda($ticket,[['oferta_id'=>$of,'presentacion_id'=>(int)$r['presentacion_id'],'cantidad'=>5]],'vista-comanda-m3-0001','restaurante');$a(!empty($r['ok']),'Venta con faltante');
    $fid=(int)$db->query("SELECT id FROM faltantes_inventario WHERE articulo_id=$lim")->fetch_row()[0];

    // 1. Aviso y bandeja con datos reales.
    $html=$render('restaurante','existencias');$a(str_contains($html,'faltantes por conciliar'),'El aviso aparece con datos reales');
    $html=$render('restaurante','faltantes');$x=$xp($html);
    $form=null;foreach($x->query('//form') as $f){$c=$campos($x,$f);if(($c['accion']??'')==='conciliar_faltante'&&(int)($c['faltante_id']??0)===$fid)$form=$f;}
    $a($form!==null,'La bandeja pinta el formulario del faltante real');
    $a(!str_contains($form->ownerDocument->saveHTML($form),'5.000'),'El saldo de Global (5.000) no aparece en el formulario de Restaurante');
    // 2. Llenar como el usuario: 1 de Refrigeración (propio) + 2 del Almacén Central (Global).
    $payload=$campos($x,$form);
    foreach($payload['asignaciones'] as $i=>$fila){$w=(int)$fila['almacen_id'];$payload['asignaciones'][$i]['cantidad']=$w===1006?'1':($w===1001?'2':'');}
    $a(isset(array_column($payload['asignaciones'],'cantidad','almacen_id')[1001]),'El formulario ofrece el almacén de Global como origen');
    $r=$admin->guardar('restaurante','conciliar_faltante',$payload);$a(!empty($r['confirmado'])&&$r['estado']==='en_confirmacion','El envío del formulario concilia: '.json_encode($r));
    $a($saldo(1006,$lim)==='0.000','Refrigeración se aplicó');

    // 3. Global confirma desde el formulario de Transferencias.
    $html=$render('global','transferencias');$x=$xp($html);$conf=null;
    foreach($x->query('//form') as $f){$c=$campos($x,$f);if(($c['accion']??'')==='transferencia_confirmar')$conf=$c;}
    $a($conf!==null&&($conf['motivo']??'')!=='','Global ve el formulario de confirmación con motivo por omisión');
    $r=$admin->guardar('global','transferencia_confirmar',$conf);$a(!empty($r['confirmado'])&&$r['estado']==='recibida','Confirmar desde el formulario: '.json_encode($r));
    $a($db->query("SELECT estado FROM faltantes_inventario WHERE id=$fid")->fetch_row()[0]==='conciliado'&&$saldo(1001,$lim)==='3.000','El faltante queda conciliado y Central descuenta 2');
    $a(!str_contains($render('restaurante','existencias'),'faltantes por conciliar')||(int)$db->query("SELECT COUNT(*) FROM faltantes_inventario WHERE unidad_id=2 AND estado='pendiente'")->fetch_row()[0]>0,'El aviso refleja los pendientes reales');

    // 4. Entrada en piezas desde el formulario real.
    $r=$motor->crearArticulo('global',['nombre'=>'Vino vista M3','unidad'=>'ml','costo'=>'0.4000','contenido_pieza'=>'750.000']);$vino=(int)$r['articulo_id'];
    A::configurar('restaurante',$vino,['comprable'=>1,'componente'=>1,'elaborable'=>0,'almacenable'=>1,'costo_conocido'=>1,'activo'=>1],0);$motor->asociarArticulo('restaurante',1005,$vino);
    $html=$render('restaurante','existencias',['q'=>'Vino vista M3']);$x=$xp($html);$ent=null;
    foreach($x->query('//form') as $f){$c=$campos($x,$f);if(($c['accion']??'')==='entrada'&&(int)($c['articulo_id']??0)===$vino)$ent=$c;}
    $a($ent!==null&&array_key_exists('piezas',$ent),'La entrada del vino ofrece captura en piezas');
    $ent['piezas']='3';$ent['cantidad']='100';$ent['costo_unitario']='300.0000';$ent['costo_por']='pieza';
    $r=$admin->guardar('restaurante','entrada',$ent);$a(!empty($r['confirmado']),'Entrada en piezas desde el formulario: '.json_encode($r));
    $a($saldo(1005,$vino)==='2350.000'&&$db->query("SELECT costo_unitario FROM movimientos_inventario WHERE articulo_id=$vino ORDER BY id DESC LIMIT 1")->fetch_row()[0]==='0.4000','3 botellas + 100 ml = 2350 ml a 0.4000 por ml');
    $a(str_contains($render('restaurante','existencias',['q'=>'Vino vista M3']),'3 piezas + 100 ml'),'La vista lee el saldo en piezas');
    // 5. Crear y configurar almacén desde la vista ya no envía la política de negativos.
    $x=$xp($render('global','almacenes'));
    foreach($x->query('//form') as $f){$c=$campos($x,$f);if(in_array($c['accion']??'',['crear_almacen','configurar_almacen'],true))$a(!array_key_exists('permitir_negativos',$c),'El formulario '.$c['accion'].' no envía negativos');}

    // 6. Global no vende: el superadministrador ve allí los faltantes de los negocios y
    //    puede declarar su origen con las reglas del negocio dueño; la responsabilidad
    //    (y el aviso del buzón) sigue en ese negocio. Otro perfil de Global no ve ninguno.
    $motor->registrarEntrada('restaurante',['clave'=>'vista-ent-g','referencia'=>'vista-ent-g','motivo'=>'compra','lineas'=>[['almacen_id'=>1006,'articulo_id'=>$lim,'cantidad'=>'1.000']]]);
    $pres=(int)$db->query("SELECT id FROM oferta_presentaciones WHERE oferta_id=$of ORDER BY id LIMIT 1")->fetch_row()[0];
    $db->query("INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,'Vista M3 Global','abierto')");$ticket2=(int)$db->insert_id;
    $r=Comercial::enviarComanda($ticket2,[['oferta_id'=>$of,'presentacion_id'=>$pres,'cantidad'=>4]],'vista-comanda-m3-0002','restaurante');$a(!empty($r['ok']),'Segunda venta con faltante');
    $fid2=(int)$db->query("SELECT id FROM faltantes_inventario WHERE articulo_id=$lim AND estado='pendiente' ORDER BY id DESC LIMIT 1")->fetch_row()[0];$a($fid2>$fid,'Nace un faltante pendiente de 4');

    $html=$render('global','existencias',['q'=>'Limón vista M3']);
    $a(str_contains($html,'−4.000 pza') && str_contains($html,'sin conciliar'),'Existencias muestra la deuda con signo menos sin bajar el saldo de cero');
    $a($saldo(1005,$lim)==='0.000','El saldo de Barra sigue en cero');
    $a(str_contains($html,'Almacén de Restaurante'),'El globo presenta el nombre como almacén y su negocio');
    $a(str_contains($html,'almacen-faltantes-aviso--consulta')&&str_contains($html,'Las concilia el administrador de cada negocio'),'En Global el aviso informa y deja la responsabilidad al negocio');

    // Perfil de Global que no es superadministrador: nada cambia para él.
    $global=FixtureAutorizacion3B::usuario($db,'global_inventario',['global'=>['permisos'=>['inventario'=>'editar']]]);
    $otro=new AdministracionAlmacenes($db,(int)$global['id'],$nivel);
    $datos=$otro->consultar('global',[],'faltantes');
    $a(($datos['faltantes']??null)===[]&&(int)($datos['faltantesPendientes']??-1)===0&&empty($datos['faltantesConsolidado']),'Otro perfil de Global no ve faltantes de los negocios');
    $r=$otro->guardar('global','conciliar_faltante',['faltante_id'=>$fid2,'version'=>'1','clave'=>bin2hex(random_bytes(16)),'asignaciones'=>[['almacen_id'=>1001,'cantidad'=>'4']]]);
    $a(empty($r['confirmado'])&&$db->query("SELECT estado FROM faltantes_inventario WHERE id=$fid2")->fetch_row()[0]==='pendiente','Otro perfil de Global no concilia un faltante de Restaurante');

    // Superadministrador desde Global: la bandeja consolidada y el formulario real.
    $html=$render('global','faltantes');$x=$xp($html);
    $form=null;foreach($x->query('//form') as $f){$c=$campos($x,$f);if(($c['accion']??'')==='conciliar_faltante'&&(int)($c['faltante_id']??0)===$fid2)$form=$f;}
    $a($form!==null,'Global pinta el faltante de Restaurante');
    $a(str_contains($html,'Los concilia el administrador de cada negocio')&&str_contains($html,'Almacenes de Restaurante · se aplican al confirmar'),'Se leen las reglas del negocio dueño');
    $payload=$campos($x,$form);
    foreach($payload['asignaciones'] as $i=>$fila){$w=(int)$fila['almacen_id'];$payload['asignaciones'][$i]['cantidad']=$w===1006?'1':($w===1001?'3':'');}
    $r=$admin->guardar('global','conciliar_faltante',$payload);
    $a(!empty($r['confirmado'])&&$r['estado']==='en_confirmacion','El superadministrador declara el origen desde Global: '.json_encode($r));
    $a($saldo(1006,$lim)==='0.000','Refrigeración (de Restaurante) se aplica directo, como para su administrador');
    $t=$db->query("SELECT t.estado,t.origen_unidad_id,t.destino_unidad_id FROM faltante_asignaciones s JOIN transferencias t ON t.id=s.transferencia_id WHERE s.faltante_id=$fid2 AND s.almacen_origen_id=1001")->fetch_assoc();
    $a($t&&$t['estado']==='solicitada'&&(int)$t['destino_unidad_id']===2,'El Almacén Central queda en confirmación de su dueño, como para el administrador del negocio');
    $antes=(int)$db->query("SELECT COUNT(*) FROM faltante_asignaciones WHERE faltante_id=$fid2")->fetch_row()[0];
    $admin->guardar('global','conciliar_faltante',$payload);
    $a((int)$db->query("SELECT COUNT(*) FROM faltante_asignaciones WHERE faltante_id=$fid2")->fetch_row()[0]===$antes,'Reenviar el mismo formulario no duplica asignaciones');
    $a((int)$db->query("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND unidad_id=(SELECT id FROM unidades WHERE slug='global')")->fetch_row()[0]===0,'El aviso del buzón no se mueve a Global');
    $a((int)$db->query("SELECT COUNT(*) FROM buzon_notificaciones WHERE tipo='inventario.faltante' AND unidad_id=2 AND cerrada_at IS NULL AND dedup_key LIKE 'inventario.faltante:1005:$lim:%'")->fetch_row()[0]===0,'Sin pendientes, el aviso de Restaurante se cierra igual que si conciliara su administrador');
    $a((int)$db->query("SELECT COUNT(*) FROM almacen_articulos WHERE saldo<0")->fetch_row()[0]===0,'Ningún saldo queda negativo');
},'M3 faltantes vista',true);
