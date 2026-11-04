<?php
// M3 · Fase 1 (docs/arquitectura.md §16): tipo de venta, reventa, variantes de
// precio, copa y botella en ml, contenido por pieza y migración del sugeridor.
// Base temporal propia: php scripts/tests/run-tipo-venta.php --host=... --base=... --entorno=development
require __DIR__.'/soporte-m12.php';
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\Almacenes;
use Services\Inventory\Articulos as A;
use Services\Menu\PresentacionesOferta as P;
use Services\Menu\RecetasOferta;
use Services\Menu\TipoVenta;
use Services\Menu\Variantes;
use Services\Pos\Comercial;
use Services\Security\ContextoNegocio as C;

m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $no=static function(callable $f,string $m,?string $codigo=null)use($a){
        try{$r=$f();$ok=is_array($r)&&empty($r['ok']);$c=$r['codigo']??$r['error']??null;}catch(Throwable $e){$ok=true;$c=$e->codigo??null;}
        $a($ok&&($codigo===null||$c===$codigo),$m.($codigo&&$c!==$codigo?" (código {$c})":''));
    };
    $hash=static fn(string $sql)=>hash('sha256',json_encode($db->query($sql)->fetch_all(MYSQLI_ASSOC),JSON_THROW_ON_ERROR));

    // 1. Sugeridor: misma sentencia en PHP, migración y fixtures; los 112 quedan en «sugerido».
    $m3=file_get_contents($root.'/database/actualizaciones/m3-tipo-venta.sql');$dev=file_get_contents($root.'/database/development.sql');
    $a(str_contains($m3,TipoVenta::SQL_SUGERIR.';')&&str_contains($dev,TipoVenta::SQL_SUGERIR.';'),'Una sola sentencia de sugerencia en PHP, migración y fixtures');
    $originales="SELECT id,producto_id,precio,categoria_id,area_id,activo,orden FROM ofertas_producto WHERE id<1000 ORDER BY id";
    $recetasOriginales="SELECT r.*,(SELECT COUNT(*) FROM receta_componentes rc WHERE rc.receta_id=r.id) n FROM recetas r WHERE r.oferta_id<1000 ORDER BY r.id";
    $antes=[$hash($originales),$hash($recetasOriginales)];
    $a((int)$db->query("SELECT COUNT(*) FROM ofertas_producto WHERE id<1000 AND tipo_venta_estado='sugerido'")->fetch_row()[0]===112,'Los 112 productos originales quedan en sugerido');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m3-tipo-venta.sql');
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/m3-tipo-venta.sql');
    $a([$hash($originales),$hash($recetasOriginales)]===$antes,'Migración repetida no cambia precio, receta ni disponibilidad de los originales');
    $a((int)$db->query("SELECT COUNT(*) FROM ofertas_producto WHERE id<1000 AND tipo_venta_estado='sugerido'")->fetch_row()[0]===112,'Repetir la migración conserva los 112 en sugerido');

    $motor=new Almacenes($db,C::actorId(),C::nivelAuth());
    $admin=new AdministracionAlmacenes($db,C::actorId(),C::nivelAuth());
    $nuevo=static function(string $nombre,string $unidad,string $costo,?string $pieza=null)use($motor,$a){
        $d=['nombre'=>$nombre,'unidad'=>$unidad,'costo'=>$costo];if($pieza!==null)$d['contenido_pieza']=$pieza;
        $r=$motor->crearArticulo('global',$d);$a(!empty($r['ok']),'Alta artículo '.$nombre.' '.json_encode($r));$id=(int)$r['articulo_id'];
        $a(!empty(A::configurar('restaurante',$id,['comprable'=>1,'componente'=>1,'elaborable'=>0,'almacenable'=>1,'costo_conocido'=>1,'activo'=>1],0)['ok']),'Capacidades '.$nombre);
        return $id;
    };
    // 2. Contenido por pieza: sólo unidades divisibles, captura en piezas exacta.
    $no(fn()=>$motor->crearArticulo('global',['nombre'=>'Pieza con pieza M3','unidad'=>'pza','costo'=>'1.0000','contenido_pieza'=>'2.000']),'Un artículo en piezas no declara contenido por pieza','CONTENIDO_PIEZA_INVALIDO');
    $vino=$nuevo('Vino tinto M3','ml','0.4000','750.000');
    $a($db->query("SELECT contenido_pieza FROM ingredientes WHERE id=$vino")->fetch_row()[0]==='750.000','Contenido por pieza guardado');
    $a(AdministracionAlmacenes::enUnidadBase('8','450','750.000')==='6450.000','8 botellas + 450 ml = 6450 ml');
    $a(AdministracionAlmacenes::costoPorUnidadBase('300.0000','750.000')==='0.4000','Costo por botella se convierte a costo por ml');
    $a(AdministracionAlmacenes::costoPorUnidadBase('100.0000','3.000')==='33.3333','Costo por pieza redondea a cuatro decimales');
    $a(!empty($motor->asociarArticulo('restaurante',1005,$vino)['ok']),'Vino asociado a Barra y Maridaje');
    $r=$admin->guardar('restaurante','entrada',['almacen_id'=>'1005','articulo_id'=>(string)$vino,'piezas'=>'2','cantidad'=>'','costo_unitario'=>'300.0000','costo_por'=>'pieza','clave'=>'m3_entrada_vino_01','nota'=>'']);
    $a(!empty($r['confirmado']),'Entrada capturada en botellas: '.json_encode($r));
    $saldo=static fn($w,$id)=>(string)$db->execute_query('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=?',[$w,$id])->fetch_row()[0];
    $a($saldo(1005,$vino)==='1500.000','Dos botellas entran como 1500 ml');
    $a($db->query("SELECT costo_unitario FROM movimientos_inventario WHERE articulo_id=$vino ORDER BY id DESC LIMIT 1")->fetch_row()[0]==='0.4000','El ledger guarda costo por ml');
    $ex=$motor->consultarExistencias('restaurante',['articulo_id'=>$vino]);$fila=$ex['articulos'][0]??[];
    $a(($fila['saldo_piezas']??null)==='2 piezas','Lectura del saldo en piezas: '.json_encode($fila['saldo_piezas']??null));
    $no(fn()=>$admin->guardar('restaurante','entrada',['almacen_id'=>'1005','articulo_id'=>'1001','piezas'=>'1','cantidad'=>'','clave'=>'m3_entrada_sin_pieza','nota'=>'']),'Capturar en piezas exige contenido por pieza','CONTENIDO_PIEZA_INVALIDO');

    // 3. Oferta nueva exige tipo; copa (preparado) y botella (reventa) del mismo saldo.
    $base=$db->query('SELECT * FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1')->fetch_assoc();
    $crearOferta=static function(string $nombre,?string $tipo)use($base){
        $p=new Model\Producto();$p->nombre=$nombre;$p->descripcion='Oferta sintética M3';$p->categoria_id=$base['categoria_id'];$p->area_id=$base['area_id'];$p->precio='100.00';$p->activo=1;$p->tipo_venta=$tipo;
        $r=$p->guardar();return [$r,$p];
    };
    [$r,$p]=$crearOferta('Sin tipo M3',null);$a(empty($r['resultado'])&&!empty(Model\Producto::getAlertas()['error']),'Alta sin tipo de venta se rechaza');
    [$r,$p]=$crearOferta('Copa de vino M3','preparado');$a(!empty($r['resultado']),'Alta de copa como preparado');$copa=(int)$p->id;
    [$r,$p]=$crearOferta('Botella de vino M3','reventa');$a(!empty($r['resultado']),'Alta de botella como reventa');$botella=(int)$p->id;
    $a(TipoVenta::deOferta($botella,2)===['tipo_venta'=>'reventa','tipo_venta_estado'=>'confirmado'],'El panel guarda el tipo confirmado');
    foreach([$copa,$botella] as $o)$db->execute_query('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES(?,?,2,1005)',[$o,$vino]);
    $comp=static fn($q,$extra=[])=>['articulo_id'=>$vino,'cantidad'=>$q,'unidad'=>'ml','modalidad'=>'almacenado']+$extra;
    $r=P::publicar('restaurante',$copa,['clave'=>'copa','nombre'=>'Copa 150 ml','precio'=>'120.00','activo'=>1,'componentes'=>[$comp('150.000')]],0,'m3_copa_000000001',true);$a(!empty($r['ok']),'Copa de 150 ml publicada');$pCopa=(int)$r['presentacion_id'];
    $dos=['clave'=>'botella','nombre'=>'Botella','precio'=>'600.00','activo'=>1,'componentes'=>[$comp('750.000'),['articulo_id'=>1001,'cantidad'=>'1.000','unidad'=>'g','modalidad'=>'almacenado']]];
    $no(fn()=>P::publicar('restaurante',$botella,$dos,0,'m3_botella_dos_00001',true),'Reventa con dos componentes se rechaza al publicar','REVENTA_COMPOSICION');
    $a((int)$db->query("SELECT COUNT(*) FROM oferta_presentaciones WHERE oferta_id=$botella")->fetch_row()[0]===0,'El rechazo no deja presentación parcial');
    $r=P::publicar('restaurante',$botella,['clave'=>'botella','nombre'=>'Botella','precio'=>'600.00','activo'=>1,'componentes'=>[$comp('750.000')]],0,'m3_botella_000000001',true);$a(!empty($r['ok']),'Botella de reventa con un componente almacenado');$pBotella=(int)$r['presentacion_id'];

    $db->query("INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,'Comanda M3','abierto')");$ticket=(int)$db->insert_id;
    $r=Comercial::enviarComanda($ticket,[['oferta_id'=>$botella,'presentacion_id'=>$pBotella,'cantidad'=>1]],'m3_comanda_botella_01','restaurante');$a(!empty($r['ok']),'Venta de botella: '.json_encode($r));$itemBotella=(int)$r['item_ids'][0];
    $a($saldo(1005,$vino)==='750.000','Una botella de reventa descuenta 750 ml del almacén de consumo');
    $r=Comercial::enviarComanda($ticket,[['oferta_id'=>$copa,'presentacion_id'=>$pCopa,'cantidad'=>1]],'m3_comanda_copa_0001','restaurante');$a(!empty($r['ok']),'Venta de copa');$itemCopa=(int)$r['item_ids'][0];
    $a($saldo(1005,$vino)==='600.000','La copa descuenta 150 ml del mismo artículo y saldo');
    $a($db->query("SELECT COUNT(DISTINCT articulo_id) FROM movimientos_inventario WHERE ticket_item_id IN ($itemBotella,$itemCopa)")->fetch_row()[0]==='1','Copa y botella comparten un solo saldo por vino');

    // 4. Variantes: precio sí, artículos no (en reventa confirmada).
    $soloPrecio=[['nombre'=>'Temperatura','opciones'=>[['nombre'=>'Ambiente','precio_delta'=>'0.00','predeterminada'=>true,'activo'=>true,'ajustes'=>[]],['nombre'=>'Fría','precio_delta'=>'20.00','predeterminada'=>false,'activo'=>true,'ajustes'=>[]]]]];
    $r=Variantes::guardar($botella,$soloPrecio,'restaurante');$a(!empty($r['ok']),'Variante sólo de precio se guarda en reventa: '.json_encode($r['errores']??null));
    $conArticulo=[['nombre'=>'Extra','opciones'=>[['nombre'=>'Sin extra','precio_delta'=>'0.00','predeterminada'=>true,'activo'=>true,'ajustes'=>[]],['nombre'=>'Con hielo','precio_delta'=>'0.00','predeterminada'=>false,'activo'=>true,'ajustes'=>[['accion'=>'agregar','tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'10.000']]]]]];
    $r=Variantes::guardar($botella,$conArticulo,'restaurante');$a(empty($r['ok'])&&str_contains(implode(' ',$r['errores']??[]),'sólo admite variantes de precio'),'Variante que agrega un artículo a la botella se rechaza: '.json_encode($r['errores']??null));
    $a(count(Variantes::deProducto($botella,'restaurante')[0]['opciones']??[])===2,'El rechazo conserva las variantes de precio');
    $r=Variantes::guardar($copa,$conArticulo,'restaurante');$a(!empty($r['ok']),'Un preparado sí admite ajustes de artículos');

    // 5. Receta legada de reventa: un componente de artículo, sin preparación.
    [$r,$p]=$crearOferta('Refresco M3','reventa');$refresco=(int)$p->id;$lata=$nuevo('Lata de refresco M3','pza','12.0000');
    $no(fn()=>RecetasOferta::guardar('restaurante',$refresco,[['tipo'=>'ingrediente','ref_id'=>$lata,'cantidad'=>'1'],['tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'1']]),'Receta legada de reventa con dos componentes se rechaza','REVENTA_COMPOSICION');
    $r=RecetasOferta::guardar('restaurante',$refresco,[['tipo'=>'ingrediente','ref_id'=>$lata,'cantidad'=>'1']]);$a(!empty($r['ok']),'Receta legada de reventa de un componente: '.json_encode($r));

    // 6. Cambiar el tipo valida la composición vigente; sugerido no restringe.
    $edit=static function(int $id,?string $tipo){$p=Model\Producto::find($id);$p->tipo_venta=$tipo;Model\Producto::getAlertas();return [$p->guardar(),Model\Producto::getAlertas()];};
    [$ok,$al]=$edit($copa,'reventa');$a($ok===false&&str_contains(implode(' ',$al['error']??[]),'variantes de precio'),'No se confirma reventa con variantes de artículos: '.json_encode($al));
    $a(TipoVenta::deOferta($copa,2)['tipo_venta']==='preparado','El rechazo conserva el tipo anterior');
    [$ok]=$edit($botella,null);$a($ok!==false&&TipoVenta::deOferta($botella,2)['tipo_venta']==='reventa','Editar sin enviar el tipo no lo cambia');
    $db->execute_query("UPDATE ofertas_producto SET tipo_venta='reventa',tipo_venta_estado='sugerido' WHERE id=?",[$copa]);
    $r=Variantes::guardar($copa,$conArticulo,'restaurante');$a(!empty($r['ok']),'Una reventa sólo sugerida se edita con su comportamiento actual');

    // 7. Confirmación en lote: confirma lo válido, explica lo que no, rechaza IDs ajenos.
    $db->query("INSERT INTO productos(nombre) VALUES('Pan ajeno M3')");$prodPan=(int)$db->insert_id;
    $pan=$db->query('SELECT * FROM ofertas_producto WHERE unidad_id=3 ORDER BY id LIMIT 1')->fetch_assoc();
    $r=TipoVenta::confirmarLote('restaurante',[(string)$copa,'1','2']);
    $a(!empty($r['ok'])&&in_array(1,$r['confirmadas'],true)&&count($r['rechazadas'])===1&&$r['rechazadas'][0]['oferta_id']===$copa,'Lote confirma originales válidos y deja la reventa inválida en sugerido');
    $a(TipoVenta::deOferta(1,2)['tipo_venta_estado']==='confirmado'&&TipoVenta::deOferta($copa,2)['tipo_venta_estado']==='sugerido','Estados tras el lote');
    $no(fn()=>TipoVenta::confirmarLote('restaurante',[(string)$pan['id']]),'Una oferta de Panadería no se confirma desde Restaurante','OFERTA_DENEGADA');
    $lector=FixtureAutorizacion3B::usuario($db,'m3_lector_menu',['restaurante'=>['permisos'=>['menu'=>'ver']]]);$super=$_SESSION['id'];$_SESSION['id']=$lector['id'];
    $no(fn()=>TipoVenta::confirmarLote('restaurante',['3']),'Lectura de Menú no confirma tipos');$_SESSION['id']=$super;

    // 8. Tickets y snapshots anteriores se consultan y cancelan igual.
    $r=Comercial::cancelarItem($itemBotella,'restaurante');$a(!empty($r['ok'])&&$saldo(1005,$vino)==='1350.000','Cancelar la botella compensa 750 ml');
    $a($db->query("SELECT COUNT(*) FROM ticket_item_resoluciones WHERE ticket_item_id=$itemCopa")->fetch_row()[0]==='1','Snapshot de la copa intacto');
    $a((int)$db->query('SELECT COUNT(*) FROM productos WHERE id<1000')->fetch_row()[0]===112,'112 productos preservados');
},'M3 tipo de venta',true);
