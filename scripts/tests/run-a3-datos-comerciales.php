<?php
declare(strict_types=1);

require __DIR__ . '/soporte-m12.php';

/** @return array{unidad_id:int,categoria_id:int,area_id:int} */
function a3Destino(mysqli $db, string $ctx): array
{
    $fila = $db->execute_query(
        'SELECT u.id unidad_id,c.id categoria_id,a.id area_id
         FROM unidades u JOIN categorias c ON c.unidad_id=u.id AND c.activo=1
         JOIN areas_produccion a ON a.unidad_id=u.id
         WHERE u.slug=? ORDER BY c.id,a.id LIMIT 1', [$ctx]
    )->fetch_assoc();
    if (!$fila) throw new RuntimeException("Falta una categoría o área activa de {$ctx}.");
    return array_map('intval', $fila);
}

/** @return array{producto_id:int,oferta_id:int} */
function a3Crear(string $ctx, string $nombre, string $descripcion, ?string $img, array $destino): array
{
    $producto = new Model\Producto();
    $producto->sincronizar(['nombre'=>$nombre,'descripcion'=>$descripcion,'precio'=>'28.50','categoria_id'=>$destino['categoria_id'],'area_id'=>$destino['area_id'],'activo'=>1]);
    $producto->img = $img;
    $resultado = Services\Security\ContextoNegocio::en($ctx, static fn() => $producto->crear());
    $ofertaId = (int) ($resultado['id'] ?? 0);
    $relacion = $ofertaId ? Model\ActiveRecord::getDB()->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?', [$ofertaId])->fetch_row() : null;
    return ['producto_id'=>(int)($relacion[0]??0),'oferta_id'=>$ofertaId];
}

function a3Editar(string $ctx, int $ofertaId, string $nombre, ?string $descripcion, ?string $img, ?string $precio=null, ?int $activo=null): bool
{
    return Services\Security\ContextoNegocio::en($ctx, static function () use ($ofertaId,$nombre,$descripcion,$img,$precio,$activo): bool {
        $producto = Model\Producto::find($ofertaId);
        if (!$producto) return false;
        $producto->nombre=$nombre; $producto->descripcion=$descripcion; $producto->img=$img;
        if ($precio!==null) $producto->precio=$precio;
        if ($activo!==null) $producto->activo=$activo;
        return (bool)$producto->actualizar();
    });
}

/** @return array<int,array{nombre:string,descripcion:string,img:?string}> */
function a3Comerciales(mysqli $db, array $ofertas): array
{
    if (!$ofertas) return [];
    $placeholders=implode(',',array_fill(0,count($ofertas),'?'));
    $rows=$db->execute_query("SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id IN ({$placeholders}) ORDER BY oferta_id",array_values($ofertas))->fetch_all(MYSQLI_ASSOC);
    $out=[];foreach($rows as $row)$out[(int)$row['oferta_id']]=['nombre'=>$row['nombre'],'descripcion'=>$row['descripcion'],'img'=>$row['img']];
    return $out;
}

function a3CrearTicketHistorico(mysqli $db, int $ofertaId): array
{
    $row=$db->execute_query(
        'SELECT o.id,o.producto_id,o.unidad_id,o.categoria_id,o.area_id,o.precio,p.nombre,c.nombre categoria,a.nombre area
         FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id
         JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
         JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id WHERE o.id=?',[$ofertaId]
    )->fetch_assoc();
    if(!$row)throw new RuntimeException('No se pudo preparar el ticket histórico de prueba.');
    $db->execute_query("INSERT INTO tickets(unidad_id,unidad_tipo,estado) VALUES (?, 'negocio','cerrado')",[(int)$row['unidad_id']]);
    $ticket=(int)$db->insert_id;
    $db->execute_query(
        'INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [(int)$row['unidad_id'],$ticket,(int)$row['id'],(int)$row['producto_id'],$row['nombre'],$row['precio'],$row['categoria'],(int)$row['categoria_id'],(int)$row['area_id'],$row['area']]
    );
    return $db->execute_query('SELECT id,ticket_id,unidad_id,oferta_id,producto_id,nombre,precio FROM ticket_items WHERE ticket_id=?',[$ticket])->fetch_assoc();
}

function a3BuscarDish(array $grupos, string $nombre): ?array
{
    foreach($grupos as $grupo)foreach($grupo['dishes']??[] as $dish)if(($dish['n']??null)===$nombre)return $dish;
    return null;
}

m12Pruebas(static function (mysqli $db, string $root, callable $afirmar): void {
    $migracion=$root.'/database/actualizaciones/a3-datos-comerciales-local.sql';
    $superadminId=(int)$_SESSION['id'];
    $sufijo=bin2hex(random_bytes(5));
    $rest=a3Destino($db,'restaurante');$pan=a3Destino($db,'panaderia');
    $candidato=(int)$db->query('SELECT id FROM ofertas_producto WHERE unidad_id=2 ORDER BY id LIMIT 1')->fetch_row()[0];
    $presentacionesAntes=Services\Menu\PresentacionesOferta::paraCartas($rest['unidad_id']);
    $recetaAntes=Services\Menu\RecetasOferta::componentes('restaurante',$candidato);
    $tiposVistaAntes=$db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_oferta' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    $productosAntes=array_map('intval',array_column($db->query('SELECT id FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC),'id'));
    $ofertasAntes=$db->query('SELECT id,producto_id,unidad_id FROM ofertas_producto ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $referenciasAntes=$db->query('SELECT id,ticket_id,unidad_id,oferta_id,producto_id,nombre,precio FROM ticket_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $tablasM2=['recetas','oferta_presentaciones','presentacion_versiones','presentacion_componentes','receta_origen_casa'];
    $m2Antes=[];foreach($tablasM2 as $tabla)$m2Antes[$tabla]=$db->query("SELECT * FROM {$tabla} ORDER BY 1")->fetch_all(MYSQLI_ASSOC);

    // Una fila histórica enlaza el snapshot del ticket con sus claves compuestas.
    $ticketPreCorte=a3CrearTicketHistorico($db,$candidato);
    $referenciasAntes=$db->query('SELECT id,ticket_id,unidad_id,oferta_id,producto_id,nombre,precio FROM ticket_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $productosAntes=array_map('intval',array_column($db->query('SELECT id FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC),'id'));
    $ofertasAntes=$db->query('SELECT id,producto_id,unidad_id FROM ofertas_producto ORDER BY id')->fetch_all(MYSQLI_ASSOC);

    // El corte estricto detecta una divergencia deliberada y conserva la vista vieja.
    $ofertaDiscrepante=(int)$db->query('SELECT oferta_id FROM oferta_datos_comerciales ORDER BY oferta_id LIMIT 1')->fetch_row()[0];
    $valorLocal='A3 valor local preservado '.$sufijo;
    $db->execute_query('UPDATE oferta_datos_comerciales SET nombre=? WHERE oferta_id=?',[$valorLocal,$ofertaDiscrepante]);
    $falloEstricto=false;
    try { Multinegocio\SqlArchivo::ejecutar($db,$migracion); }
    catch(Throwable $e) { $falloEstricto=str_contains($e->getMessage(),'diferencias locales'); }
    $afirmar($falloEstricto,'La migración estricta rechaza discrepancias sin decisión de reconciliación.');
    $afirmar(!(bool)$db->query("SELECT 1 FROM esquema_versiones WHERE version='a3-datos-comerciales-local-v1'")->fetch_row(),'La vista no se activa cuando falla la guardia de diferencias.');
    $vieja=$db->execute_query('SELECT nombre FROM productos_oferta WHERE oferta_id=?',[$ofertaDiscrepante])->fetch_row()[0];
    $afirmar($vieja!==$valorLocal,'La vista anterior sigue sirviendo su proyección legacy tras el aborto.');

    // Decisión explícita: conserva la fila local revisada y cambia la vista.
    $db->query('SET @cp_a3_preservar_locales=1');
    Multinegocio\SqlArchivo::ejecutar($db,$migracion);
    $afirmar(Services\Menu\DatosComercialesOferta::a3Activa(),'El marcador A3 se inserta después del cambio de vista.');
    $legacyDesactivado=false;
    try { Services\Menu\DatosComercialesOferta::sincronizarOfertaLegacy($ofertaDiscrepante); }
    catch(LogicException) { $legacyDesactivado=true; }
    $afirmar($legacyDesactivado,'El sincronizador legacy rechaza escrituras después del corte y no forma una dependencia circular.');
    $vista=$db->execute_query('SELECT nombre FROM productos_oferta WHERE oferta_id=?',[$ofertaDiscrepante])->fetch_row()[0];
    $afirmar($vista===$valorLocal,'La vista A3 lee el dato local preservado exactamente.');
    $afirmar($db->query('SELECT COUNT(*) FROM ofertas_producto o LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE d.oferta_id IS NULL')->fetch_row()[0]==0,'Cada oferta cuenta con datos comerciales después de la migración.');
    $afirmar($db->query('SELECT COUNT(*) FROM oferta_datos_comerciales d LEFT JOIN ofertas_producto o ON o.id=d.oferta_id WHERE o.id IS NULL')->fetch_row()[0]==0,'No hay filas locales huérfanas.');
    $productosDespues=array_map('intval',array_column($db->query('SELECT id FROM productos ORDER BY id')->fetch_all(MYSQLI_ASSOC),'id'));
    $ofertasDespues=$db->query('SELECT id,producto_id,unidad_id FROM ofertas_producto ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $ticketsDespues=$db->query('SELECT id,ticket_id,unidad_id,oferta_id,producto_id,nombre,precio FROM ticket_items ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $afirmar($productosDespues===$productosAntes&&$ofertasDespues===$ofertasAntes,'La migración conserva IDs y relaciones técnicas de productos y ofertas.');
    $idsTicketDespues=array_map(static fn(array $r):int=>(int)$r['id'],$ticketsDespues);
    $afirmar($ticketsDespues===$referenciasAntes&&in_array((int)$ticketPreCorte['id'],$idsTicketDespues,true),'La migración conserva las referencias y snapshots históricos del ticket.');
    $afirmar(Services\Menu\PresentacionesOferta::paraCartas($rest['unidad_id'])===$presentacionesAntes,'Las presentaciones M2 conservan su lectura antes y después de cambiar productos_oferta.');
    $afirmar(Services\Menu\RecetasOferta::componentes('restaurante',$candidato)===$recetaAntes,'La receta M2 conserva su DTO antes y después del cambio de vista.');
    foreach($tablasM2 as $tabla)$afirmar($db->query("SELECT * FROM {$tabla} ORDER BY 1")->fetch_all(MYSQLI_ASSOC)===$m2Antes[$tabla],"La migración no cambia registros de {$tabla}.");
    $tiposVistaDespues=$db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_oferta' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    $afirmar($tiposVistaDespues===$tiposVistaAntes&&array_column($tiposVistaDespues,'COLUMN_NAME')===['id','oferta_id','created_at','updated_at','nombre','descripcion','img'],'La vista conserva nombres, orden y tipos de columnas del contrato previo: '.json_encode([$tiposVistaAntes,$tiposVistaDespues]));
    $casos=['migración estricta bloquea diferencias','activación preserva datos locales, IDs, tickets y compatibilidad M2'];

    $standalone=a3Crear('panaderia','A3 Panadería '.$sufijo,'Producto nuevo Panadería',null,$pan);
    $afirmar($standalone['producto_id']>0&&$standalone['oferta_id']>0,'Crear un producto local desde Panadería inicializa su oferta.');
    $filaNueva=$db->execute_query('SELECT nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id=?',[$standalone['oferta_id']])->fetch_assoc();
    $afirmar($filaNueva===['nombre'=>'A3 Panadería '.$sufijo,'descripcion'=>'Producto nuevo Panadería','img'=>null],'La creación conserva imagen NULL y crea datos comerciales antes del commit.');

    $shared=a3Crear('restaurante','A3 Compartido '.$sufijo,'Identidad técnica original', 'build/images/a3-original.webp',$rest);
    $asociacion=Services\Menu\Ofertas::crear($shared['producto_id'],'panaderia',['categoria_id'=>$pan['categoria_id'],'area_id'=>$pan['area_id'],'precio'=>'28.50','activo'=>1]);
    $ofertaPan=(int)($asociacion['oferta_id']??0);$ofertaRest=$shared['oferta_id'];
    $afirmar(!empty($asociacion['confirmado'])&&$ofertaPan>0,'Crear segunda oferta para una identidad técnica compartida.');
    $relaciones=$db->execute_query('SELECT id,producto_id,unidad_id FROM ofertas_producto WHERE id IN (?,?) ORDER BY id',[$ofertaRest,$ofertaPan])->fetch_all(MYSQLI_ASSOC);
    $afirmar(count($relaciones)===2&&(int)$relaciones[0]['producto_id']===$shared['producto_id']&&(int)$relaciones[1]['producto_id']===$shared['producto_id'],'Ambas ofertas conservan producto_id y sus IDs propios.');
    $afirmar($db->execute_query('SELECT nombre FROM productos WHERE id=?',[$shared['producto_id']])->fetch_row()[0]==='A3 Compartido '.$sufijo,'La identidad técnica queda intacta al crear ofertas.');

    $ticketCompartido=a3CrearTicketHistorico($db,$ofertaRest);
    $usuarioLocal=FixtureAutorizacion3B::usuario($db,'a3_local',['restaurante'=>['permisos'=>['menu'=>'editar']]]);
    $_SESSION['id']=$usuarioLocal['id'];
    try {
        $edicionRest=a3Editar('restaurante',$ofertaRest,'A3 Restaurante local '.$sufijo,'Descripción solo Restaurante',null,'31.25',0);
        $cruce=Services\Security\ContextoNegocio::en('restaurante',static function()use($ofertaRest,$ofertaPan):bool{
            $p=Model\Producto::find($ofertaRest);if(!$p)return false;$p->id=$p->oferta_id=$ofertaPan;$p->nombre='Cruce prohibido';return (bool)$p->actualizar();
        });
    } finally { $_SESSION['id']=$superadminId; }
    $afirmar($edicionRest,'Restaurante edita nombre, descripción, imagen NULL, precio y disponibilidad sin facultad Global.');
    $afirmar(!$cruce,'El usuario limitado a Restaurante edita sin facultad Global.');
    $datosCompartidos=a3Comerciales($db,[$ofertaRest,$ofertaPan]);
    $afirmar($datosCompartidos[$ofertaRest]===['nombre'=>'A3 Restaurante local '.$sufijo,'descripcion'=>'Descripción solo Restaurante','img'=>null],'La edición local cambia nombre, descripción e imagen NULL de Restaurante.');
    $afirmar($datosCompartidos[$ofertaPan]===['nombre'=>'A3 Compartido '.$sufijo,'descripcion'=>'Identidad técnica original','img'=>'build/images/a3-original.webp'],'La oferta Panadería conserva sus propios datos comerciales.');
    $comercial=$db->execute_query('SELECT nombre,descripcion,img FROM productos WHERE id=?',[$shared['producto_id']])->fetch_assoc();
    $afirmar($comercial===['nombre'=>'A3 Compartido '.$sufijo,'descripcion'=>'Identidad técnica original','img'=>'build/images/a3-original.webp'],'La edición local no actualiza la identidad técnica compartida.');
    $precios=$db->execute_query('SELECT id,precio,activo FROM ofertas_producto WHERE id IN (?,?) ORDER BY id',[$ofertaRest,$ofertaPan])->fetch_all(MYSQLI_ASSOC);
    $afirmar($precios[0]['precio']==='31.25'&&(int)$precios[0]['activo']===0&&$precios[1]['precio']==='28.50'&&(int)$precios[1]['activo']===1,'Precio y disponibilidad permanecen propios de cada oferta.');
    $afirmar((int)$ticketCompartido['oferta_id']===$ofertaRest&&(int)$ticketCompartido['producto_id']===$shared['producto_id'],'El ticket conserva las claves compuestas de su oferta.');

    $afirmar(!$cruce&&$db->execute_query('SELECT nombre FROM oferta_datos_comerciales WHERE oferta_id=?',[$ofertaPan])->fetch_row()[0]==='A3 Compartido '.$sufijo,'Se rechaza una edición cruzada desde el contexto equivocado.');

    // Un fallo en la tabla fuente hace fallar la edición completa sin alterar filas locales ni precio.
    $db->query("CREATE TRIGGER trg_a3_falla_datos BEFORE UPDATE ON oferta_datos_comerciales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo A3 intencional'");
    try { $rollback=!a3Editar('restaurante',$ofertaRest,'A3 debe revertirse','Cambio que no debe quedar','build/images/a3-no.webp','33.00',1); }
    finally { $db->query('DROP TRIGGER trg_a3_falla_datos'); }
    $afirmar($rollback,'Un error de escritura comercial provoca rollback de la edición.');
    $persistido=$db->execute_query('SELECT d.nombre,d.descripcion,d.img,o.precio,o.activo FROM oferta_datos_comerciales d JOIN ofertas_producto o ON o.id=d.oferta_id WHERE d.oferta_id=?',[$ofertaRest])->fetch_assoc();
    $afirmar($persistido===['nombre'=>'A3 Restaurante local '.$sufijo,'descripcion'=>'Descripción solo Restaurante','img'=>null,'precio'=>'31.25','activo'=>0],'El rollback deja intactos los datos comerciales y operativos anteriores.');

    $reactivada=a3Editar('restaurante',$ofertaRest,'A3 Restaurante local '.$sufijo,'Descripción solo Restaurante',null,'31.25',1);
    $afirmar($reactivada,'Restaurante vuelve a habilitar su oferta sin tocar Panadería.');
    $admin=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Model\Producto::find($ofertaRest));
    $afirmar($admin&&$admin->nombre==='A3 Restaurante local '.$sufijo&&$admin->descripcion==='Descripción solo Restaurante'&&$admin->img===null,'El menú administrativo devuelve los datos propios de Restaurante.');
    $carta=a3BuscarDish(Services\Menu\Carta::publica(), 'A3 Restaurante local '.$sufijo);
    $afirmar($carta!==null&&$carta['d']==='Descripción solo Restaurante'&&$carta['img']===null,'La carta pública devuelve descripción local e imagen NULL.');
    $pdf=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\MenuPdf::datosAdministrativos('restaurante','comida'));
    $pdfNombres=[];foreach($pdf['categorias'] as $grupo)foreach($grupo['platillos'] as $platillo)$pdfNombres[]=$platillo->nombre;
    $afirmar(in_array('A3 Restaurante local '.$sufijo,$pdfNombres,true),'El PDF administrativo imprime el nombre local de Restaurante.');
    $pos=Services\Security\ContextoNegocio::en('restaurante',static fn()=>Services\Menu\Carta::paraPos());
    $posNombres=[];foreach($pos as $grupo)foreach($grupo['dishes'] as $dish)$posNombres[]=$dish['n'];
    $afirmar(in_array('A3 Restaurante local '.$sufijo,$posNombres,true),'El catálogo POS muestra el nombre comercial local.');
    $ticketActual=$db->execute_query('SELECT id,ticket_id,unidad_id,oferta_id,producto_id,nombre,precio FROM ticket_items WHERE id=?',[(int)$ticketCompartido['id']])->fetch_assoc();
    $afirmar($ticketActual===$ticketCompartido,'Editar una oferta no reescribe snapshots históricos de tickets.');
    $casos[]='creación, independencia por negocio, permisos, rollback y lectores administrativo/público/PDF/POS';

    // Productos de la casa permanece disponible, con restricciones explícitas tras activar A3.
    $casaDatos=['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp','precio'=>'40.00','activo'=>1,'tipo'=>'compartido',
        'reglas'=>['nombre'=>false,'descripcion'=>false,'img'=>false,'precio'=>false,'receta'=>false,'activo'=>false],
        'negocios'=>['restaurante'=>['categoria_id'=>$rest['categoria_id'],'area_id'=>$rest['area_id'],'precio'=>'40.00','activo'=>1],
            'panaderia'=>['categoria_id'=>$pan['categoria_id'],'area_id'=>$pan['area_id'],'precio'=>'42.00','activo'=>1]]];
    $productoCasa=(int)(Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::crear($casaDatos,'a3_crear_'.$sufijo.bin2hex(random_bytes(4))))['producto_id']??0);
    $previa=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::vistaPrevia($productoCasa));
    $publicada=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::publicar($productoCasa,(int)$previa['configuracion']['revision'],$previa['huella'],'a3_pub_'.$sufijo.bin2hex(random_bytes(4)),true));
    $ofertasCasa=$db->execute_query('SELECT id,unidad_id FROM ofertas_producto WHERE producto_id=? ORDER BY unidad_id',[$productoCasa])->fetch_all(MYSQLI_ASSOC);
    $ofertaCasaRest=(int)$ofertasCasa[0]['id'];$ofertaCasaPan=(int)$ofertasCasa[1]['id'];
    $afirmar(!empty($publicada['ok'])&&count($ofertasCasa)===2,'Productos de la casa puede publicar nuevas ofertas con filas locales inicializadas.');
    $_SESSION['id']=$usuarioLocal['id'];
    try {
        $cambioCasa=Services\Menu\ProductosCasa::guardarLocal('restaurante',$ofertaCasaRest,['nombre'=>'Casa Restaurante local','descripcion'=>'Texto local casa','img'=>null]);
        $datosCasa=a3Comerciales($db,[$ofertaCasaRest,$ofertaCasaPan]);
        $restaurar=Services\Menu\ProductosCasa::guardarLocal('restaurante',$ofertaCasaRest,[],['nombre','descripcion','img']);
    } finally { $_SESSION['id']=$superadminId; }
    $afirmar(!empty($cambioCasa['ok'])&&$datosCasa[$ofertaCasaRest]===['nombre'=>'Casa Restaurante local','descripcion'=>'Texto local casa','img'=>null],'Personalización de casa permite datos locales aunque sus reglas Global sean falsas: '.json_encode($cambioCasa,JSON_UNESCAPED_UNICODE));
    $afirmar($datosCasa[$ofertaCasaPan]===['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp'],'La personalización de casa no altera la oferta del otro negocio.');
    $afirmar(!empty($restaurar['ok'])&&a3Comerciales($db,[$ofertaCasaRest])[$ofertaCasaRest]===['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp'],'La restauración local devuelve los valores de publicación sin cambiar otras ofertas.');
    $db->query("CREATE TRIGGER trg_a3_no_redundante BEFORE UPDATE ON oferta_datos_comerciales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='escritura comercial redundante'");
    try { $guardadoSinCambios=Services\Menu\ProductosCasa::guardarLocal('restaurante',$ofertaCasaRest,[]); }
    finally { $db->query('DROP TRIGGER trg_a3_no_redundante'); }
    $afirmar(!empty($guardadoSinCambios['ok']),'Una operación sin cambio comercial no escribe otra vez en la fuente A3.');
    $configCasa=Services\Menu\ProductosCasa::configuracion($productoCasa);
    $borradorAlterado=array_replace($casaDatos,['nombre'=>'Cambio Global no debe propagar '.$sufijo]);
    $guardado=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::guardar($productoCasa,$borradorAlterado,(int)$configCasa['revision']));
    $previaAlterada=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::vistaPrevia($productoCasa));
    $publicacionAlterada=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\ProductosCasa::publicar($productoCasa,(int)$previaAlterada['configuracion']['revision'],$previaAlterada['huella'],'a3_pub_bloqueada_'.$sufijo.bin2hex(random_bytes(3)),true));
    $tecnicos=$db->execute_query('SELECT nombre,descripcion,img FROM productos WHERE id=?',[$productoCasa])->fetch_assoc();
    $afirmar(!empty($guardado['ok'])&&empty($publicacionAlterada['ok'])&&str_contains($publicacionAlterada['mensaje']??'','pertenece a cada negocio'),'La publicación rechaza un cambio global de identidad cuando ya hay ofertas locales.');
    $comercialesCasaFinal=a3Comerciales($db,[$ofertaCasaRest,$ofertaCasaPan]);
    $esperadosCasa=[$ofertaCasaRest=>['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp'],$ofertaCasaPan=>['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp']];ksort($comercialesCasaFinal);ksort($esperadosCasa);
    $afirmar($tecnicos===['nombre'=>'A3 Casa '.$sufijo,'descripcion'=>'Publicación base A3','img'=>'build/images/a3-casa.webp']&&$comercialesCasaFinal===$esperadosCasa,'La publicación rechazada no cambia productos ni ofertas: '.json_encode([$tecnicos,$comercialesCasaFinal],JSON_UNESCAPED_UNICODE));
    $actual=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\CatalogoComercial::identidad($productoCasa));
    $globalBloqueado=Services\Security\ContextoNegocio::en('global',static fn()=>Services\Menu\CatalogoComercial::actualizar($productoCasa,['nombre'=>'Global no debe cambiar','descripcion'=>'Prueba','img'=>null],Services\Menu\CatalogoComercial::huella($actual),true));
    $afirmar(empty($globalBloqueado['ok'])&&str_contains($globalBloqueado['mensaje'],'edición comercial por negocio'),'La edición compartida de Global queda deshabilitada con respuesta explícita.');
    $afirmar(!empty($publicada['publicacion_id']),'La publicación oficial mantiene su referencia histórica.');
    $casos[]='compatibilidad transitoria con publicación, personalización, restauración y bloqueo de propagación Global';

    // Reejecutar A1 y A3 debe ser no destructivo incluso después de ediciones locales.
    $comercialesAntes=a3Comerciales($db,[$ofertaRest,$ofertaPan,$ofertaCasaRest,$ofertaCasaPan]);
    $datosM2Antes=[];foreach($tablasM2 as $tabla)$datosM2Antes[$tabla]=$db->query("SELECT * FROM {$tabla} ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
    Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/actualizaciones/a1-datos-comerciales-oferta.sql');
    Multinegocio\SqlArchivo::ejecutar($db,$migracion);
    $afirmar(a3Comerciales($db,[$ofertaRest,$ofertaPan,$ofertaCasaRest,$ofertaCasaPan])===$comercialesAntes,'Repetir A1 y A3 no sobrescribe ediciones locales posteriores al corte.');
    foreach($tablasM2 as $tabla)$afirmar($db->query("SELECT * FROM {$tabla} ORDER BY 1")->fetch_all(MYSQLI_ASSOC)===$datosM2Antes[$tabla],"La activación repetida conserva {$tabla}.");
    foreach(['nombre','descripcion','img'] as $campo){
        $columnasPermitidas=['nombre'=>'nombre','descripcion'=>'descripcion','img'=>'img'];$col=$columnasPermitidas[$campo];
        $diff=$db->query("SELECT COUNT(*) FROM productos_oferta v JOIN oferta_datos_comerciales d ON d.oferta_id=v.oferta_id WHERE NOT (CAST(v.{$col} AS BINARY) <=> CAST(d.{$col} AS BINARY))")->fetch_row()[0];
        $afirmar((int)$diff===0,"La vista A3 coincide con oferta_datos_comerciales para {$campo} después del reintento.");
    }
    $afirmar($db->query('SELECT COUNT(*) FROM oferta_datos_comerciales d LEFT JOIN ofertas_producto o ON o.id=d.oferta_id WHERE o.id IS NULL')->fetch_row()[0]==0,'Las filas locales conservan integridad referencial tras repetir las migraciones.');
    echo 'A3 casos: '.implode('; ',$casos)."\n";
},'A3');
