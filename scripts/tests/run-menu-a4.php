<?php
declare(strict_types=1);

require __DIR__.'/soporte-m12.php';

$extensionM11=$GLOBALS['extensionM11']??null;
m12Pruebas(static function(mysqli $db,string $root,callable $a)use($extensionM11):void {
    $superId=(int)$_SESSION['id'];
    $no=static function(callable $fn,string $mensaje)use($a):void {
        try {$r=$fn();$denegado=is_array($r)?empty($r['ok'])&&empty($r['resultado']):!$r;}
        catch(Throwable){$denegado=true;}
        $a($denegado,$mensaje);
    };
    $columnas=static fn(string $tabla):array=>$db->query('SELECT * FROM '.$tabla.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    $global=Services\Security\PermisosUsuario::catalogo('global');
    $habilitados=Services\Security\ContextoNegocio::modulosHabilitados('global');
    $a(!isset($global['menu'])&&!in_array('menu',$habilitados,true),'Global ya no ofrece el módulo Menú.');
    $a(isset($global['productos'],$global['inventario'],$global['users']),'Global conserva Artículos, inventario y usuarios.');
    $a(!method_exists(Services\Menu\Ofertas::class,'crear'),'No existe escritor para asociar una oferta a otro negocio.');
    $a(!method_exists(Services\Menu\PresentacionesOferta::class,'publicarEstandar'),'No existe publicación de presentaciones comerciales desde Global.');
    $a(!method_exists(Controllers\AdminMenuController::class,'globalIndex')&&!method_exists(Controllers\AdminMenuController::class,'globalEdit'),'No existe administración Global del menú.');

    $archivosRetirados=[
        'controllers/AdminProductosCasaController.php','services/Menu/ProductosCasa.php',
        'services/Menu/RecetasCasa.php','services/Menu/CatalogoComercial.php',
        'views/admin/menu/global.php','views/admin/menu/asociar.php',
        'views/admin/menu/casa-form.php','views/admin/menu/casa-receta.php',
        'views/admin/menu/casa-receta-local.php','views/admin/menu/casa-recetario.php',
        'views/admin/recetas/presentaciones-global.php','src/js/admin/menu/casa.js',
    ];
    foreach($archivosRetirados as $rel)$a(!is_file($root.'/'.$rel),'Archivo operativo retirado: '.$rel);
    $rutas=file_get_contents($root.'/public/index.php');
    foreach(['/admin/global/productos','/admin/menu/casa-receta','/admin/panaderia/menu/casa-receta','/admin/global/recetas','/admin/global/productos/presentaciones'] as $ruta)
        $a(!str_contains($rutas,$ruta),'Ruta antigua no está registrada: '.$ruta);
    $a(Services\Security\RetornoAdministrativo::validar('/admin/menu/casa-receta?id=1402','restaurante',static fn()=>true)===null,'El retorno rechaza rutas antiguas de recetas de la casa.');
    $a(Services\Security\RetornoAdministrativo::validar('/admin/global/productos/edit?id=1201','global',static fn()=>true)===null,'El retorno rechaza rutas antiguas del catálogo comercial.');
    $composicion=file_get_contents($root.'/services/Menu/ComposicionComercial.php');
    $a(!str_contains($composicion,"'casa'")&&!str_contains($composicion,'ProductosCasa'),'La composición comercial no expone el modo casa.');

    $modulos=['controllers','classes','models','services','public','src','views'];
    $prohibidos=['ProductosCasa::','RecetasCasa::','CatalogoComercial::','presentacion_estandares'];
    foreach($modulos as $modulo){
        $base=$root.'/'.$modulo;
        $archivos=is_file($base)?[$base]:new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
        foreach($archivos as $archivo){
            if(!$archivo instanceof SplFileInfo||!$archivo->isFile()||!preg_match('/\.(php|js|scss)$/i',$archivo->getFilename()))continue;
            $contenido=file_get_contents($archivo->getPathname());
            foreach($prohibidos as $patron)$a(!str_contains($contenido,$patron),'Sin dependencia operativa «'.$patron.'» en '.$archivo->getPathname());
        }
    }

    $nav=Controllers\AdminContextosController::navegacion('global',[]);
    $a(!isset($nav['menu'])&&isset($nav['menus']),'Global muestra acceso a cartas PDF sin recuperar el módulo Menú.');
    $inicio=Services\Security\InicioAdministrativo::preparar(
        Services\Security\PermisosUsuario::cargar((int)$_SESSION['id']),
        'global','contrasena',[]
    );
    $tarjetas=[];foreach($inicio['seccionesInicio'] as $seccion)foreach($seccion['tarjetas'] as $tarjeta)$tarjetas[]=$tarjeta['titulo'];
    $a(!in_array('Menú',$tarjetas,true)&&!in_array('Productos de la casa',$tarjetas,true),'Inicio Global no ofrece administración comercial.');

    $db->execute_query("INSERT IGNORE INTO facultades(clave,solo_global,descripcion) VALUES('comercial.identidad.editar',1,'Retirada A4')");
    $a(!isset(Services\Users\AccesosUsuario::catalogoFacultades('global')['comercial.identidad.editar']),'La facultad comercial retirada ya no se delega en Global.');
    $perfilSuper=Services\Security\PermisosUsuario::cargar((int)$_SESSION['id']);
    $a(!in_array('comercial.identidad.editar',$perfilSuper['ambitos']['global']['facultades']??[],true),'El perfil runtime omite la facultad comercial histórica.');

    // A4 falla de forma cerrada si se intenta operar con el contrato anterior a A3.
    $db->execute_query("DELETE FROM esquema_versiones WHERE version='a3-datos-comerciales-local-v1'");
    $bloqueado=false;
    try {Services\Security\ContextoNegocio::exigir('menu','ver','restaurante');}
    catch(Services\Inventory\InventarioError $e){$bloqueado=$e->codigo==='A3_REQUERIDO';}
    finally {$db->execute_query("INSERT IGNORE INTO esquema_versiones(version) VALUES('a3-datos-comerciales-local-v1')");}
    $a($bloqueado,'El menú se niega a operar si falta el marcador A3.');
    $a(Services\Menu\DatosComercialesOferta::a3Activa(),'El marcador A3 de la base temporal quedó restaurado.');

    // Global solo ve PDF de los negocios para los que tiene permiso de Menú.
    $lectorPdf=FixtureAutorizacion3B::usuario($db,'a4_pdf_localizado',[
        'global'=>['permisos'=>['productos'=>'ver']],
        'restaurante'=>['permisos'=>['menu'=>'ver']],
        'panaderia'=>['permisos'=>['inventario'=>'ver']],
    ]);
    $_SESSION['id']=$lectorPdf['id'];
    $pdfs=Services\Menu\MenuPdf::biblioteca();
    $a($pdfs!==[]&&count(array_filter($pdfs,static fn(array $d):bool=>$d['contexto']==='restaurante'))===count($pdfs),'Global consulta PDF solo del negocio con permiso de Menú.');
    $a(count(array_filter($pdfs,static fn(array $d):bool=>$d['contexto']==='panaderia'))===0,'Global no obtiene PDF de Panadería sin permiso local de Menú.');
    $_SESSION['id']=$superId;

    $unidadRest=Services\Security\ContextoNegocio::unidad('restaurante');
    $unidadPan=Services\Security\ContextoNegocio::unidad('panaderia');
    $categoriaRest=(int)$db->execute_query('SELECT id FROM categorias WHERE unidad_id=? AND activo=1 ORDER BY id LIMIT 1',[$unidadRest])->fetch_row()[0];
    $areaRest=(int)$db->execute_query('SELECT id FROM areas_produccion WHERE unidad_id=? ORDER BY id LIMIT 1',[$unidadRest])->fetch_row()[0];
    $categoriaPan=(int)$db->execute_query('SELECT id FROM categorias WHERE unidad_id=? AND activo=1 ORDER BY id LIMIT 1',[$unidadPan])->fetch_row()[0];
    $areaPan=(int)$db->execute_query('SELECT id FROM areas_produccion WHERE unidad_id=? ORDER BY id LIMIT 1',[$unidadPan])->fetch_row()[0];
    $crear=static function(string $ctx,string $nombre,int $categoria,int $area):array {
        $p=new Model\Producto();
        $p->sincronizar(['nombre'=>$nombre,'descripcion'=>'Producto A4 local','img'=>null,'precio'=>'75.25','categoria_id'=>$categoria,'area_id'=>$area,'activo'=>1,'orden'=>2]);
        $r=Services\Security\ContextoNegocio::en($ctx,static fn()=>$p->crear());
        return [$p,$r];
    };
    [$nuevoRest,$altaRest]=$crear('restaurante','A4 Menú Restaurante '.bin2hex(random_bytes(3)),$categoriaRest,$areaRest);
    [$nuevoPan,$altaPan]=$crear('panaderia','A4 Menú Panadería '.bin2hex(random_bytes(3)),$categoriaPan,$areaPan);
    $a(!empty($altaRest['resultado'])&&!empty($altaPan['resultado']),'Crear productos locales en Restaurante y Panadería.');
    $ofertaNuevaRest=(int)$altaRest['id'];$productoNuevoRest=(int)$db->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?',[$ofertaNuevaRest])->fetch_row()[0];
    $ofertaNuevaPan=(int)$altaPan['id'];$productoNuevoPan=(int)$db->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?',[$ofertaNuevaPan])->fetch_row()[0];
    $a($productoNuevoRest>0&&$productoNuevoPan>0&&$productoNuevoRest!==$productoNuevoPan,'La creación no crea una identidad compartida entre negocios.');
    $localPan=$db->execute_query('SELECT o.categoria_id,o.area_id,o.precio,o.activo,o.orden,d.nombre,d.descripcion,d.img FROM ofertas_producto o JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE o.id=?',[$ofertaNuevaPan])->fetch_assoc();
    $a((int)$localPan['categoria_id']===$categoriaPan&&(int)$localPan['area_id']===$areaPan&&$localPan['precio']==='75.25'&&(int)$localPan['activo']===1&&(int)$localPan['orden']===2&&$localPan['nombre']===$nuevoPan->nombre&&$localPan['descripcion']==='Producto A4 local'&&$localPan['img']===null,'Panadería conserva precio, disponibilidad, categoría, área e imagen nula en su oferta.');
    $menuPan=Services\Security\ContextoNegocio::en('panaderia',static fn()=>Model\Producto::buscarAdmin([],null,0));$enMenuPan=false;
    foreach($menuPan as $platillo)if($platillo->nombre===$nuevoPan->nombre)$enMenuPan=true;
    $a($enMenuPan,'El menú administrativo de Panadería lee su producto comercial local.');
    $a((int)$db->execute_query('SELECT COUNT(*) FROM recetas WHERE oferta_id=? AND unidad_id=?',[$ofertaNuevaRest,$unidadRest])->fetch_row()[0]===0,'Crear un producto no inventa una receta.');
    $sin=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\ComposicionComercial::consultar('restaurante',$ofertaNuevaRest));
    $a($sin['modo']==='sin'&&str_contains($sin['texto'],'Sin composición.'),'La ficha comunica claramente que el producto aún no tiene composición.');

    // Editar la oferta 3B heredada actualiza A3 y precio local, nunca la identidad técnica ni el otro negocio.
    $ofertaRest=(int)$db->execute_query('SELECT id FROM ofertas_producto WHERE id=1402 AND unidad_id=?',[$unidadRest])->fetch_row()[0];
    $productoCompartido=(int)$db->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?',[$ofertaRest])->fetch_row()[0];
    $ofertaPan=(int)$db->execute_query('SELECT id FROM ofertas_producto WHERE producto_id=? AND unidad_id=?',[$productoCompartido,$unidadPan])->fetch_row()[0];
    $identidadAntes=$db->execute_query('SELECT nombre,descripcion,img FROM productos WHERE id=?',[$productoCompartido])->fetch_assoc();
    $comercialPanAntes=$db->execute_query('SELECT nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id=?',[$ofertaPan])->fetch_assoc();
    $categoriaNueva=(int)$db->execute_query('SELECT id FROM categorias WHERE unidad_id=? AND activo=1 ORDER BY id DESC LIMIT 1',[$unidadRest])->fetch_row()[0];
    $areaNueva=(int)$db->execute_query('SELECT id FROM areas_produccion WHERE unidad_id=? ORDER BY id DESC LIMIT 1',[$unidadRest])->fetch_row()[0];
    $editar=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Model\Producto::find($ofertaRest));
    $editar->nombre='A4 Restaurante local';$editar->descripcion='Descripción local Restaurante';$editar->img=null;
    $editar->precio='86.40';$editar->categoria_id=$categoriaNueva;$editar->area_id=$areaNueva;$editar->activo=0;$editar->orden=17;
    $a(Services\Security\ContextoNegocio::en('restaurante',static fn()=>$editar->actualizar()),'Editar precio, categoría, área, disponibilidad y datos comerciales locales.');
    $localRest=$db->execute_query('SELECT o.categoria_id,o.area_id,o.precio,o.activo,o.orden,d.nombre,d.descripcion,d.img FROM ofertas_producto o JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE o.id=?',[$ofertaRest])->fetch_assoc();
    $a((int)$localRest['categoria_id']===$categoriaNueva&&(int)$localRest['area_id']===$areaNueva&&$localRest['precio']==='86.40'&&(int)$localRest['activo']===0&&(int)$localRest['orden']===17&&$localRest['nombre']==='A4 Restaurante local'&&$localRest['descripcion']==='Descripción local Restaurante'&&$localRest['img']===null,'Los campos comerciales y operativos quedan en la oferta local.');
    $a($db->execute_query('SELECT nombre,descripcion,img FROM productos WHERE id=?',[$productoCompartido])->fetch_assoc()===$identidadAntes,'Editar Restaurante no escribe los atributos comerciales de productos.');
    $a($db->execute_query('SELECT nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id=?',[$ofertaPan])->fetch_assoc()===$comercialPanAntes,'Editar Restaurante no cambia la oferta de Panadería.');
    $editar->activo=1;
    $a(Services\Security\ContextoNegocio::en('restaurante',static fn()=>$editar->actualizar()),'Reactivar la oferta local.');
    $carta=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Carta::publica());
    $enCarta=false;foreach($carta as $grupo)foreach($grupo['dishes']??[] as $dish)if(($dish['n']??'')==='A4 Restaurante local')$enCarta=true;
    $a($enCarta,'La carta pública lee los datos comerciales A3 locales.');
    $pdf=Services\Menu\MenuPdf::datosAdministrativos('restaurante','comida');$enPdf=false;
    foreach($pdf['categorias'] as $grupo)foreach($grupo['platillos'] as $platillo)if($platillo->nombre==='A4 Restaurante local')$enPdf=true;
    $a($enPdf,'El PDF administrativo lee la oferta local.');
    $pos=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Carta::paraPos());$enPos=false;
    foreach($pos as $grupo)foreach($grupo['dishes']??[] as $dish)if(($dish['n']??'')==='A4 Restaurante local')$enPos=true;
    $a($enPos,'La lectura POS usa los datos comerciales locales.');
    $no(fn()=>Services\Security\ContextoNegocio::en('restaurante',static fn()=>Model\Producto::find($ofertaNuevaPan)),'Restaurante no puede leer la oferta de Panadería.');
    $a(Services\Security\RetornoAdministrativo::autorizado('/admin/panaderia/recetas/editar?id='.$ofertaNuevaPan,'panaderia')!==null,'Panadería conserva un retorno de receta local autorizado.');

    // El legado publicado sigue operando localmente y la versión nueva no reescribe su origen.
    $recetaAntes=$db->execute_query('SELECT id,version FROM recetas WHERE oferta_id=? AND unidad_id=? AND activo=1 ORDER BY version DESC LIMIT 1',[$ofertaRest,$unidadRest])->fetch_assoc();
    $a($recetaAntes!==null,'El fixture conserva una receta heredada publicada para Restaurante.');
    $recetaVieja=(int)$recetaAntes['id'];
    $origenAntes=$db->execute_query('SELECT receta_id,version_id FROM receta_origen_casa WHERE receta_id=?',[$recetaVieja])->fetch_assoc();
    $componentesAntes=$db->execute_query('SELECT * FROM receta_componentes WHERE receta_id=? ORDER BY id',[$recetaVieja])->fetch_all(MYSQLI_ASSOC);
    $db->execute_query('INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,?,\'abierto\')',['A4 receta heredada']);
    $ticketReceta=(int)$db->insert_id;
    $stockAntes=$db->query('SELECT almacen_id,articulo_id,saldo FROM almacen_articulos WHERE unidad_id=2 AND almacen_id=1002 AND articulo_id IN(1001,1002) ORDER BY articulo_id')->fetch_all(MYSQLI_ASSOC);
    $ventaReceta=Services\Pos\Comercial::enviarComanda($ticketReceta,[['oferta_id'=>$ofertaRest,'cantidad'=>1]],'a4_receta_heredada_01','restaurante');
    $a(!empty($ventaReceta['ok']),'POS continúa consumiendo una receta heredada.');
    $itemReceta=(int)$ventaReceta['item_ids'][0];
    $a((int)$db->execute_query('SELECT receta_id FROM ticket_items WHERE id=?',[$itemReceta])->fetch_row()[0]===$recetaVieja,'El ticket conserva la versión de receta que utilizó.');
    $cancelacion=Services\Pos\Comercial::cancelarItem($itemReceta,'restaurante');
    $a(!empty($cancelacion['ok']),'La cancelación revierte el consumo heredado.');
    $stockDespues=$db->query('SELECT almacen_id,articulo_id,saldo FROM almacen_articulos WHERE unidad_id=2 AND almacen_id=1002 AND articulo_id IN(1001,1002) ORDER BY articulo_id')->fetch_all(MYSQLI_ASSOC);
    $a($stockDespues===$stockAntes,'La reversión restablece las existencias y almacenes originales.');
    $movimientosCancelacion=$db->execute_query('SELECT * FROM movimientos_inventario WHERE ticket_item_id=? ORDER BY id',[$itemReceta])->fetch_all(MYSQLI_ASSOC);
    $reintentoCancelacion=Services\Pos\Comercial::cancelarItem($itemReceta,'restaurante');
    $movimientosTrasReintento=$db->execute_query('SELECT * FROM movimientos_inventario WHERE ticket_item_id=? ORDER BY id',[$itemReceta])->fetch_all(MYSQLI_ASSOC);
    $stockTrasReintento=$db->query('SELECT almacen_id,articulo_id,saldo FROM almacen_articulos WHERE unidad_id=2 AND almacen_id=1002 AND articulo_id IN(1001,1002) ORDER BY articulo_id')->fetch_all(MYSQLI_ASSOC);
    $a(!empty($reintentoCancelacion['ok'])&&!empty($reintentoCancelacion['idempotente'])&&$movimientosTrasReintento===$movimientosCancelacion&&$stockTrasReintento===$stockDespues,'Repetir una cancelación no duplica movimientos ni revierte existencias otra vez.');

    // Una presentación marcada históricamente como estándar se puede vender por su propia versión local.
    $db->execute_query('INSERT INTO oferta_presentaciones(oferta_id,unidad_id,clave,nombre,activo) VALUES(?,2,\'a4-heredada\',\'Presentación heredada A4\',0)',[$ofertaRest]);
    $presentacion=(int)$db->insert_id;
    $db->execute_query('INSERT INTO presentacion_versiones(presentacion_id,oferta_id,unidad_id,version,precio,publicada,usuario_id) VALUES(?,?,2,1,\'90.25\',0,?)',[$presentacion,$ofertaRest,(int)$_SESSION['id']]);
    $versionPresentacion=(int)$db->insert_id;
    $db->execute_query("INSERT INTO presentacion_componentes(version_id,articulo_id,cantidad,unidad,modalidad,etapa) VALUES(?,1001,'1.000','g','almacenado','servicio')",[$versionPresentacion]);
    $db->execute_query('INSERT INTO presentacion_estandares(version_id,publicacion_producto_id,configuracion) VALUES(?,?,?)',[$versionPresentacion,1401,json_encode(['clave'=>'a4-heredada','precio'=>'90.25','heredada'=>true],JSON_THROW_ON_ERROR)]);
    $db->execute_query('UPDATE presentacion_versiones SET publicada=1 WHERE id=?',[$versionPresentacion]);
    $db->execute_query('UPDATE oferta_presentaciones SET activo=1 WHERE id=?',[$presentacion]);
    $ventaPreparada=Services\Menu\PresentacionesOferta::paraVenta('restaurante',$ofertaRest,$presentacion,[],1);
    $a((int)$ventaPreparada['version']['id']===$versionPresentacion,'Una presentación con estándar histórico es válida sin consultarlo para autorizar la venta.');
    $legacyTables=['producto_estandares','producto_publicaciones','oferta_personalizaciones','producto_cambios_locales','recetas_casa','receta_casa_versiones','receta_casa_componentes','receta_casa_composiciones','receta_origen_casa','presentacion_estandares'];
    $legacyBefore=[];foreach($legacyTables as $tabla)$legacyBefore[$tabla]=$columnas($tabla);
    $ventaPresentacion=Services\Pos\Comercial::enviarComanda($ticketReceta,[['oferta_id'=>$ofertaRest,'presentacion_id'=>$presentacion,'cantidad'=>1]],'a4_presentacion_heredada_01','restaurante');
    $a(!empty($ventaPresentacion['ok']),'POS vende la presentación heredada.');
    $itemPresentacion=(int)$ventaPresentacion['item_ids'][0];
    $resolucionAntes=$db->execute_query('SELECT * FROM ticket_item_resoluciones WHERE ticket_item_id=?',[$itemPresentacion])->fetch_assoc();
    $a((int)$resolucionAntes['presentacion_version_id']===$versionPresentacion,'El snapshot del ticket fija la versión heredada vendida.');
    $a(Services\Pos\Comercial::cancelarItem($itemPresentacion,'restaurante')['ok']??false,'La cancelación de presentación se completa.');

    $ticketsAntesEdicion=$db->execute_query('SELECT * FROM ticket_items WHERE ticket_id=? ORDER BY id',[$ticketReceta])->fetch_all(MYSQLI_ASSOC);
    $componentesNuevos=[['tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'12.000']];
    $edicionReceta=Services\Menu\RecetasOferta::guardar('restaurante',$ofertaRest,$componentesNuevos);
    $a(!empty($edicionReceta['ok'])&&$edicionReceta['activa'],'La receta heredada se puede editar como receta local versionada.');
    $recetaNueva=(int)$edicionReceta['receta_id'];
    $a($recetaNueva!==$recetaVieja&&(int)$db->execute_query('SELECT version FROM recetas WHERE id=?',[$recetaNueva])->fetch_row()[0]>(int)$recetaAntes['version'],'La edición crea una versión local nueva.');
    $a($db->execute_query('SELECT receta_id,version_id FROM receta_origen_casa WHERE receta_id=?',[$recetaVieja])->fetch_assoc()===$origenAntes,'La fila de origen heredada permanece intacta.');
    $a($db->execute_query('SELECT * FROM receta_componentes WHERE receta_id=? ORDER BY id',[$recetaVieja])->fetch_all(MYSQLI_ASSOC)===$componentesAntes,'Los componentes de la receta anterior no se sobrescriben.');
    $a($db->execute_query('SELECT * FROM ticket_items WHERE ticket_id=? ORDER BY id',[$ticketReceta])->fetch_all(MYSQLI_ASSOC)===$ticketsAntesEdicion,'Editar la receta no modifica snapshots ni tickets anteriores.');
    $a($db->execute_query('SELECT * FROM ticket_item_resoluciones WHERE ticket_item_id=?',[$itemPresentacion])->fetch_assoc()===$resolucionAntes,'El snapshot de presentación permanece inmutable después de editar la receta.');
    foreach($legacyTables as $tabla)$a($columnas($tabla)===$legacyBefore[$tabla],'A4 no borra ni modifica datos históricos en '.$tabla.'.');
    $no(fn()=>$db->execute_query('UPDATE receta_casa_versiones SET rendimiento=\'9.000\' WHERE id=?',[$origenAntes['version_id']]),'Los triggers mantienen inmutable el estándar heredado publicado.');
    $no(fn()=>$db->execute_query('UPDATE presentacion_estandares SET configuracion=JSON_OBJECT() WHERE version_id=?',[$versionPresentacion]),'El trigger conserva inmutable la referencia histórica de presentación.');

    // Los precios de opciones son del Menú; cambiar ajustes de receta exige Artículos.
    $menuSolo=FixtureAutorizacion3B::usuario($db,'a4_menu_solo',['restaurante'=>['permisos'=>['menu'=>'editar']]]);
    $_SESSION['id']=$menuSolo['id'];
    $grupos=[['nombre'=>'Tamaño','opciones'=>[
        ['nombre'=>'Grande','precio_delta'=>'5.00','predeterminada'=>true,'activo'=>true,'ajustes'=>[]],
        ['nombre'=>'Regular','precio_delta'=>'0.00','predeterminada'=>false,'activo'=>true,'ajustes'=>[]],
    ]]];
    $guardarPrecio=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::guardar($ofertaNuevaRest,$grupos,'restaurante'));
    $a(!empty($guardarPrecio['ok']),'Menú puede fijar el precio de una variante.');
    $grupos=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::deProducto($ofertaNuevaRest,'restaurante'));
    foreach($grupos as &$grupo)foreach($grupo['opciones'] as &$opcion)$opcion['precio_delta']=number_format((float)$opcion['precio_delta'],2,'.','');
    unset($grupo,$opcion);
    $grupos[0]['opciones'][0]['precio_delta']='7.50';
    $guardarPrecio=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::guardar($ofertaNuevaRest,$grupos,'restaurante'));
    $a(!empty($guardarPrecio['ok']),'Menú puede editar el precio sin permisos de Artículos: '.json_encode($guardarPrecio,JSON_UNESCAPED_UNICODE));
    $grupos[0]['opciones'][0]['ajustes']=[['accion'=>'agregar','tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'5.000','modalidad_destino'=>'almacenado','receta_destino_id'=>null]];
    $no(fn()=>Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::guardar($ofertaNuevaRest,$grupos,'restaurante')),'Menú solo no puede cambiar la composición de una variante.');
    $editor=FixtureAutorizacion3B::usuario($db,'a4_articulos_editor',['restaurante'=>['permisos'=>['menu'=>'editar','productos'=>'editar']]]);
    $_SESSION['id']=$editor['id'];
    $grupos=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::deProducto($ofertaNuevaRest,'restaurante'));
    foreach($grupos as &$grupo)foreach($grupo['opciones'] as &$opcion)$opcion['precio_delta']=number_format((float)$opcion['precio_delta'],2,'.','');
    unset($grupo,$opcion);
    $grupos[0]['opciones'][0]['ajustes']=[['accion'=>'agregar','tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'5.000','modalidad_destino'=>'almacenado','receta_destino_id'=>null]];
    $guardarComposicion=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Variantes::guardar($ofertaNuevaRest,$grupos,'restaurante'));
    $a(!empty($guardarComposicion['ok']),'Artículos con permiso del Menú puede editar la composición.');
    $_SESSION['id']=$superId;

    $usuarioRest=FixtureAutorizacion3B::usuario($db,'a4_restaurante_aislado',['restaurante'=>['permisos'=>['menu'=>'editar']]]);
    $_SESSION['id']=$usuarioRest['id'];
    $no(fn()=>Services\Security\ContextoNegocio::en('panaderia',static fn()=>Model\Producto::find($ofertaRest)),'Permiso de Restaurante no permite leer ni editar una oferta de Panadería.');
    $_SESSION['id']=$superId;

    if(is_callable($extensionM11))$extensionM11($db,$root,$a);
},'A4',true);
