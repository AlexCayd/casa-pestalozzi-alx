<?php
declare(strict_types=1);

/** Regresiones de consumidores locales de Restaurante durante 3D.3. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once $root . '/scripts/tests/soporte-3d.php';
require_once $root . '/scripts/tests/pruebas-reportes-3d3.php';

use MenuPreservacion\Comun;
use MenuPreservacion\ImportadorMenu;
use MenuPreservacion\VerificadorMenu;
use Model\ActiveRecord;
use Model\ConfiguracionPos;
use Model\Mesa;
use Model\Reservacion;
use Model\TicketMesa;
use Multinegocio\SqlArchivo;
use MVC\Router;
use Services\Analytics\Analiticas;
use Services\Menu\Ofertas;
use Services\Pos\Comercial;
use Services\Pos\PosReservacionQueryService;
use Services\Security\ContextoNegocio;
use Services\Security\PermisosUsuario;

function restaurante3d3Assert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function restaurante3d3Insert(mysqli $db, string $sql, string $types, array $values): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $refs = [$types];
        foreach ($values as &$value) $refs[] = &$value;
        unset($value);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function restaurante3d3Login(int $actorId, string $level = 'contrasena'): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = ['login'=>true, 'id'=>$actorId, 'auth_nivel'=>$level, '_auth3b'=>true];
}

function restaurante3d3Ticket(mysqli $db, int $unitId, string $name, int $actorId): int
{
    return restaurante3d3Insert($db,
        "INSERT INTO tickets(unidad_id,unidad_tipo,comensales,nombre,mesero_id,estado) VALUES (?,'negocio',3,?,?,'abierto')",
        'isi', [$unitId,$name,$actorId]);
}

function restaurante3d3Item(mysqli $db, int $unitId, int $ticketId, array $offer, string $state = 'enviado'): int
{
    return restaurante3d3Insert($db,
        'INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,estado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        'iiiisssiiss', [$unitId,$ticketId,(int)$offer['id'],(int)$offer['producto_id'],(string)$offer['nombre'],(string)$offer['precio'],(string)$offer['categoria'],(int)$offer['categoria_id'],(int)$offer['area_id'],(string)$offer['area_nombre'],$state]);
}

function restaurante3d3Offer(mysqli $db, int $productId, string $ctx, int $categoryId, int $areaId, string $name, int &$count): array
{
    restaurante3d3Assert($productId > 0, 'La identidad de producto fixture existe.', $count);
    $result = Ofertas::crear($productId, $ctx, ['categoria_id'=>$categoryId,'precio'=>'17.25','area_id'=>$areaId,'activo'=>true,'orden'=>0]);
    restaurante3d3Assert(($result['ok'] ?? false) === true && (int)($result['oferta_id'] ?? 0) > 0,
        'Se crea oferta local fixture: ' . $name . '. ' . json_encode($result, JSON_UNESCAPED_UNICODE), $count);
    $id = (int)$result['oferta_id'];
    $row = $db->query("SELECT o.id,o.producto_id,o.precio,o.categoria_id,c.nombre categoria,o.area_id,a.nombre area_nombre,p.nombre FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id WHERE o.id=$id")->fetch_assoc();
    if (!$row) throw new RuntimeException('No se recuperó la oferta fixture creada.');
    return $row;
}

function restaurante3d3ContainsId(array $rows, int $id): bool
{
    foreach ($rows as $row) {
        $rowId = is_array($row) ? (int)($row['id'] ?? 0) : (int)($row->id ?? 0);
        if ($rowId === $id) return true;
    }
    return false;
}

function restaurante3d3KdsAreaItems(int $areaId): array
{
    $_GET['area_id'] = (string)$areaId;
    ob_start();
    \Controllers\AdminAreaController::areaItems(new Router());
    $json = (string)ob_get_clean();
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) throw new RuntimeException('KDS devolvió JSON inválido.');
    return $decoded;
}

$admin = null;
$bases = [];
$assetRoot = null;
$assetParent = null;
$error = null;
$phase = 'argumentos/configuración';
$count = 0;
$output = null;
try {
    $options = restaurante3dOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    Comun::origen($config, $options, $root);
    $verified = VerificadorMenu::verificar($options['paquete']);
    restaurante3d3Assert(($verified['ok'] ?? false) === true, 'Paquete preservado válido.', $count);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión sin seleccionar base configurada';
    $admin = new mysqli((string)$config['DB_HOST'], (string)$config['DB_USER'], (string)$config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    $phase = 'base aislada, DDL, deploy y menú preservado';
    [$database, $owner] = restaurante3dCrearBase($admin, $root, $bases);
    ActiveRecord::setDB($admin);
    [$assetRoot, $assetParent] = restaurante3dCrearRaizImagenes();
    $import = ImportadorMenu::importar($admin, $options['paquete'], $assetRoot, $owner);
    restaurante3d3Assert(($import['ok'] ?? false) === true && ($import['conteos']['productos'] ?? null) === 112,
        'La proyección parte de los 112 productos originales.', $count);

    $phase = 'actores directos y ofertas locales Rest/Pan';
    $rest = (int)$admin->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $pan = (int)$admin->query("SELECT id FROM unidades WHERE slug='panaderia'")->fetch_row()[0];
    $actorPos = FixtureRestaurante3D::usuario($admin, '3d3-pos', ['pdv'=>'operar']);
    $actorArea = FixtureRestaurante3D::usuario($admin, '3d3-area', ['area'=>'operar']);
    $actorReservations = FixtureRestaurante3D::usuario($admin, '3d3-reservations', ['reservations'=>'editar']);
    $actorMap = FixtureRestaurante3D::usuario($admin, '3d3-map', ['reservations_map'=>'operar']);
    $actorConfig = FixtureRestaurante3D::usuario($admin, '3d3-config', ['configuration'=>'editar']);
    $actorAnalytics = FixtureRestaurante3D::usuario($admin, '3d3-analytics', ['analytics'=>'ver']);
    $actorMenuRest = FixtureRestaurante3D::usuario($admin, '3d3-menu-rest', ['menu'=>'editar','productos'=>'editar']);
    $actorMenuPan = FixtureRestaurante3D::usuario($admin, '3d3-menu-pan', ['menu'=>'editar','productos'=>'editar'], 'panaderia');
    foreach ([[$actorPos,'pdv','operar'],[$actorArea,'area','operar'],[$actorReservations,'reservations','editar'],[$actorMap,'reservations_map','operar'],[$actorConfig,'configuration','editar'],[$actorAnalytics,'analytics','ver']] as [$actor,$module,$level]) {
        $profile = PermisosUsuario::cargar((int)$actor['id'], $admin);
        restaurante3d3Assert(PermisosUsuario::permitido($profile, 'restaurante', $module, $level), "Actor fixture conserva permiso directo {$module}/{$level}.", $count);
    }
    $restBaseOffer = $admin->query("SELECT o.id,o.producto_id,o.precio,o.categoria_id,c.nombre categoria,o.area_id,a.nombre area_nombre,p.nombre FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id WHERE o.unidad_id=$rest AND o.activo=1 AND NOT EXISTS (SELECT 1 FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1) ORDER BY o.id LIMIT 1")->fetch_assoc();
    if (!$restBaseOffer) throw new RuntimeException('No hay oferta activa sin receta en el menú preservado.');
    $restArea = (int)$restBaseOffer['area_id'];
    $panCategoryName = 'Fixture 3D3 ' . bin2hex(random_bytes(5));
    $panAreaSlug = 'qa3d3_' . bin2hex(random_bytes(4));
    $panCategory = restaurante3d3Insert($admin, "INSERT INTO categorias(unidad_id,carta,nombre,activo,orden) VALUES ($pan,'comida',?,1,0)", 's', [$panCategoryName]);
    $panArea = restaurante3d3Insert($admin, 'INSERT INTO areas_produccion(unidad_id,nombre,slug,color,orden) VALUES (?,?,?,\'#abcabc\',0)', 'iss', [$pan,'Área fixture 3D3',$panAreaSlug]);
    $mesaRest = restaurante3d3Insert($admin,"INSERT INTO mesas(unidad_id,unidad_tipo,numero,nombre,tipo,zona,capacidad,activo,reservable) VALUES ($rest,'negocio',9900,'QA 3D3 Restaurante','mesa','interior',4,1,1)",'',[]);
    $mesaPan = restaurante3d3Insert($admin,"INSERT INTO mesas(unidad_id,unidad_tipo,numero,nombre,tipo,zona,capacidad,activo,reservable) VALUES ($pan,'negocio',9901,'QA 3D3 Panadería','mesa','interior',4,1,1)",'',[]);
    $restProduct = restaurante3d3Insert($admin, 'INSERT INTO productos(nombre,descripcion) VALUES (?,?)', 'ss', ['QA 3D3 Rest ' . bin2hex(random_bytes(7)), 'Fixture sintético 3D.3']);
    $panProduct = restaurante3d3Insert($admin, 'INSERT INTO productos(nombre,descripcion) VALUES (?,?)', 'ss', ['QA 3D3 Pan ' . bin2hex(random_bytes(7)), 'Fixture sintético 3D.3']);
    restaurante3d3Login((int)$actorMenuRest['id']);
    $restOffer = restaurante3d3Offer($admin,$restProduct,'restaurante',(int)$restBaseOffer['categoria_id'],$restArea,'Rest', $count);
    restaurante3d3Login((int)$actorMenuPan['id']);
    $panOffer = restaurante3d3Offer($admin,$panProduct,'panaderia',$panCategory,$panArea,'Pan', $count);

    $phase = 'matriz de estados entregables y alcance comercial';
    restaurante3d3Login((int)$actorPos['id'], 'nip');
    $deliveryTicket = restaurante3d3Ticket($admin,$rest,'QA 3D3 estados',(int)$actorPos['id']);
    restaurante3d3Insert($admin,'INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden) VALUES (?,?,?,1)','iii',[$rest,$deliveryTicket,$mesaRest]);
    $sent = Comercial::enviarComanda($deliveryTicket,array_fill(0,3,['oferta_id'=>(int)$restOffer['id'],'cantidad'=>1,'variantes'=>[]]),'qa3d3-state-matrix-' . bin2hex(random_bytes(8)),'restaurante');
    restaurante3d3Assert(($sent['ok']??false)===true && count($sent['item_ids']??[])===3,'Comanda fixture de tres líneas creada.', $count);
    $states=['enviado','en_preparacion','listo'];
    foreach ($sent['item_ids'] as $index=>$itemId) {
        $itemId=(int)$itemId; $state=$states[$index];
        $admin->query("UPDATE ticket_items SET estado='".$admin->real_escape_string($state)."' WHERE id=$itemId AND unidad_id=$rest");
        $delivered=Comercial::entregarItem($itemId,'restaurante');
        restaurante3d3Assert(($delivered['ok']??false)===true && (string)$admin->query("SELECT estado FROM ticket_items WHERE id=$itemId AND unidad_id=$rest")->fetch_row()[0]==='entregado',
            "KDS/POS puede entregar línea desde {$state}.", $count);
    }
    foreach (['entregado','cancelado'] as $state) {
        $ticket=restaurante3d3Ticket($admin,$rest,'QA 3D3 estado no entregable',(int)$actorPos['id']);
        $line=Comercial::enviarComanda($ticket,[['oferta_id'=>(int)$restOffer['id'],'cantidad'=>1,'variantes'=>[]]],'qa3d3-deny-' . $state . '-' . bin2hex(random_bytes(5)),'restaurante');
        if (($line['ok']??false)!==true) throw new RuntimeException('No se pudo crear línea para estado no entregable.');
        $item=(int)$line['item_ids'][0];
        $admin->query("UPDATE ticket_items SET estado='".$admin->real_escape_string($state)."' WHERE id=$item AND unidad_id=$rest");
        $rejected=Comercial::entregarItem($item,'restaurante');
        restaurante3d3Assert(($rejected['ok']??true)===false && (string)$admin->query("SELECT estado FROM ticket_items WHERE id=$item AND unidad_id=$rest")->fetch_row()[0]===$state,
            "No entrega artículo en estado {$state}.", $count);
    }
    $closedTicket=restaurante3d3Ticket($admin,$rest,'QA 3D3 ticket cerrado',(int)$actorPos['id']);
    $closedLine=Comercial::enviarComanda($closedTicket,[['oferta_id'=>(int)$restOffer['id'],'cantidad'=>1,'variantes'=>[]]],'qa3d3-closed-' . bin2hex(random_bytes(8)),'restaurante');
    if (($closedLine['ok']??false)!==true) throw new RuntimeException('No se creó la línea de prueba de ticket cerrado.');
    $closedItem=(int)$closedLine['item_ids'][0];
    $admin->query("UPDATE tickets SET estado='cerrado',closed_at=NOW(),hora_cierre=NOW() WHERE id=$closedTicket AND unidad_id=$rest");
    $closedReject=Comercial::entregarItem($closedItem,'restaurante');
    restaurante3d3Assert(($closedReject['ok']??true)===false,'No entrega artículos de ticket ya cerrado.', $count);
    $panTicket=restaurante3d3Ticket($admin,$pan,'QA 3D3 Pan item',(int)$actorPos['id']);
    $panItem=restaurante3d3Item($admin,$pan,$panTicket,$panOffer);
    $crossReject=Comercial::entregarItem($panItem,'restaurante');
    restaurante3d3Assert(($crossReject['ok']??true)===false,'No entrega item de ticket de Panadería desde Restaurante.', $count);
    restaurante3d3Insert($admin,'INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden) VALUES (?,?,?,1)','iii',[$pan,$panTicket,$mesaPan]);

    $phase = 'lectura KDS local y rechazo de área ajena';
    restaurante3d3Login((int)$actorArea['id'],'nip');
    $kds = restaurante3d3KdsAreaItems($restArea);
    $kdsIds = array_map(static fn(array $item): int => (int)($item['id']??0), (array)($kds['items']??[]));
    restaurante3d3Assert(($kds['ok']??false)===true && in_array((int)$sent['item_ids'][0],$kdsIds,true),
        'El tablero KDS muestra la línea del área Restaurante local.', $count);
    $foreignAreaKds = restaurante3d3KdsAreaItems($panArea);
    $foreignPayload = json_encode($foreignAreaKds, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    restaurante3d3Assert(!str_contains((string)$foreignPayload,(string)$panItem),
        'Consultar el ID/área de Panadería no filtra ni revela sus tickets al KDS Restaurante.', $count);

    $phase = 'mapa, reservaciones y configuración de POS por unidad';
    $date = date('Y-m-d', strtotime('+2 days'));
    $hour = '17:30:00';
    $resRest = restaurante3d3Insert($admin,"INSERT INTO reservaciones(unidad_id,unidad_tipo,nombre,contacto_tipo,contacto,fecha,hora,comensales,origen,estado) VALUES ($rest,'negocio','QA 3D3 Rest','ninguno',NULL,?,?,2,'admin','confirmada')",'ss',[$date,$hour]);
    $resPan = restaurante3d3Insert($admin,"INSERT INTO reservaciones(unidad_id,unidad_tipo,nombre,contacto_tipo,contacto,fecha,hora,comensales,origen,estado) VALUES ($pan,'negocio','QA 3D3 Pan','ninguno',NULL,?,?,2,'admin','confirmada')",'ss',[$date,$hour]);
    restaurante3d3Insert($admin,'INSERT INTO reservacion_mesas(unidad_id,reservacion_id,mesa_id,orden) VALUES (?,?,?,1)','iii',[$rest,$resRest,$mesaRest]);
    restaurante3d3Insert($admin,'INSERT INTO reservacion_mesas(unidad_id,reservacion_id,mesa_id,orden) VALUES (?,?,?,1)','iii',[$pan,$resPan,$mesaPan]);
    restaurante3d3Login((int)$actorReservations['id']);
    $restRow=ContextoNegocio::en('restaurante',static fn()=>Reservacion::buscarFilaPorId($resRest));
    $wrongRow=ContextoNegocio::en('restaurante',static fn()=>Reservacion::buscarFilaPorId($resPan));
    $dayRows=ContextoNegocio::en('restaurante',static fn()=>Reservacion::buscarPorDiaOperacionAdmin($date));
    restaurante3d3Assert((int)($restRow['id']??0)===$resRest && $wrongRow===null && restaurante3d3ContainsId($dayRows,$resRest) && !restaurante3d3ContainsId($dayRows,$resPan),
        'Detalle y lista administrativa de reservaciones quedan delimitados a Restaurante.', $count);
    restaurante3d3Login((int)$actorMap['id'],'nip');
    $mapTables=ContextoNegocio::en('restaurante',static fn()=>Mesa::buscarTodasParaMapa());
    $mapTickets=ContextoNegocio::en('restaurante',static fn()=>TicketMesa::abiertosParaMapa());
    restaurante3d3Assert(restaurante3d3ContainsId($mapTables,$mesaRest) && !restaurante3d3ContainsId($mapTables,$mesaPan),
        'Mapa operativo lee únicamente mesas de Restaurante.', $count);
    $mapResult=ContextoNegocio::en('restaurante',static fn()=>PosReservacionQueryService::paraFecha($date,$hour,['ahora'=>new DateTimeImmutable($date.' 12:00:00'),'calcular_conflictos'=>false]));
    $mapReservations=array_map(static fn(array $r): int=>(int)($r['id']??0),(array)($mapResult['reservaciones']??[]));
    restaurante3d3Assert(($mapResult['ok']??false)===true && in_array($resRest,$mapReservations,true) && !in_array($resPan,$mapReservations,true),
        'POS y mapa de reservaciones serializan solo el negocio activo.', $count);
    $mapTicketIds=array_map(static fn(array $r): int=>(int)($r['id']??0),$mapTickets);
    restaurante3d3Assert(!in_array($panTicket,$mapTicketIds,true),'Ocupación de mapa excluye ticket de Panadería.', $count);

    restaurante3d3Login((int)$actorConfig['id']);
    $configBefore=(array)$admin->query("SELECT mesero_editable,impresion_activa,max_tickets_barra FROM configuracion_pos WHERE unidad_id=$pan AND id=1")->fetch_assoc();
    $saved=ContextoNegocio::en('restaurante',static function(): bool {
        $row=ConfiguracionPos::obtener();
        if (!$row) throw new RuntimeException('No se leyó configuración Restaurante.');
        $row->mesero_editable=0;
        return $row->guardarConfiguracion();
    });
    $configAfterRest=(array)$admin->query("SELECT mesero_editable,impresion_activa,max_tickets_barra FROM configuracion_pos WHERE unidad_id=$rest AND id=1")->fetch_assoc();
    $configAfterPan=(array)$admin->query("SELECT mesero_editable,impresion_activa,max_tickets_barra FROM configuracion_pos WHERE unidad_id=$pan AND id=1")->fetch_assoc();
    restaurante3d3Assert($saved && (int)$configAfterRest['mesero_editable']===0 && $configAfterRest['max_tickets_barra']===$configBefore['max_tickets_barra']
        && $configAfterPan===$configBefore,'Guardar ajuste POS Restaurante preserva las otras columnas y configuración Panadería.', $count);

    $phase = 'analíticas locales y permisos directos';
    $productRestReport=restaurante3d3Insert($admin,'INSERT INTO productos(nombre,descripcion) VALUES (?,?)','ss',['QA reporte Rest ' . bin2hex(random_bytes(5)),'Fixture de reporte']);
    $productPanReport=restaurante3d3Insert($admin,'INSERT INTO productos(nombre,descripcion) VALUES (?,?)','ss',['QA reporte Pan ' . bin2hex(random_bytes(5)),'Fixture de reporte']);
    restaurante3d3Login((int)$actorMenuRest['id']);
    $offerRestReport=restaurante3d3Offer($admin,$productRestReport,'restaurante',(int)$restBaseOffer['categoria_id'],$restArea,'reporte Rest',$count);
    restaurante3d3Login((int)$actorMenuPan['id']);
    $offerPanReport=restaurante3d3Offer($admin,$productPanReport,'panaderia',$panCategory,$panArea,'reporte Pan',$count);
    $reportDate=date('Y-m-d');
    $ticketRestReport=restaurante3d3Ticket($admin,$rest,'QA cierre Rest',(int)$actorPos['id']);
    $ticketPanReport=restaurante3d3Ticket($admin,$pan,'QA cierre Pan',(int)$actorPos['id']);
    restaurante3d3Item($admin,$rest,$ticketRestReport,$offerRestReport,'entregado');
    restaurante3d3Item($admin,$pan,$ticketPanReport,$offerPanReport,'entregado');
    foreach ([[$ticketRestReport,$rest],[$ticketPanReport,$pan]] as [$ticket,$unit]) {
        restaurante3d3Insert($admin,'INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto) VALUES (?,?,1,\'efectivo\',17.25)','ii',[$unit,$ticket]);
        $fechaCobro=$reportDate.' 12:00:00';
        $stmt=$admin->prepare("UPDATE tickets SET estado='cerrado',closed_at=?,hora_cierre=?,metodo_pago='efectivo' WHERE id=? AND unidad_id=?");
        $stmt->bind_param('ssii',$fechaCobro,$fechaCobro,$ticket,$unit); $stmt->execute(); $stmt->close();
    }
    $profile = PermisosUsuario::cargar((int)$actorAnalytics['id'],$admin);
    restaurante3d3Assert(PermisosUsuario::permitido($profile,'restaurante','analytics','ver') && !PermisosUsuario::permitido($profile,'restaurante','finanzas','ver'),
        'Actor de reporte tiene solo Analytics/ver, sin acceso indirecto a Finanzas.', $count);
    restaurante3d3Login((int)$actorAnalytics['id']);
    $range=Services\Shared\RangoPeriodo::resolver(['desde'=>$reportDate,'hasta'=>$reportDate]);
    $analyticsMethod=new ReflectionMethod(\Controllers\AdminController::class,'construirAnalytics');
    $analyticsMethod->setAccessible(true);
    $analytics=ContextoNegocio::en('restaurante',static fn()=>$analyticsMethod->invoke(null,$range));
    $analyticsJson=json_encode($analytics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    restaurante3d3Assert(is_array($analytics) && str_contains((string)$analyticsJson,(string)$offerRestReport['nombre'])
        && !str_contains((string)$analyticsJson,(string)$offerPanReport['nombre']),
        'Reporte principal contiene la venta local y excluye Panadería. Diagnóstico fixture: '.json_encode(['rango'=>$range,'productos'=>$analytics['charts']['topProducts']??[],'esperado'=>$offerRestReport['nombre'],'ajeno'=>$offerPanReport['nombre']],JSON_UNESCAPED_UNICODE), $count);
    $level1=ContextoNegocio::en('restaurante',static fn()=>Analiticas::nivel1($reportDate,$reportDate));
    $level1Json=json_encode($level1,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    restaurante3d3Assert(is_array($level1) && !str_contains((string)$level1Json,(string)$offerPanReport['nombre']),
        'Analíticas diagnósticas mantienen el mismo alcance local.', $count);

    $phase = 'regresiones de reportes, horarios y costos históricos';
    probarReportes3D3($admin,$rest,$pan,$offerRestReport,$offerPanReport,$count);

    $output=['ok'=>true,'fase'=>'3D.3','comprobaciones'=>$count,'base_temporal'=>$database,
        'matriz_entrega'=>['permitidos'=>['enviado','en_preparacion','listo'],'rechazados'=>['entregado','cancelado','ticket_cerrado','item_ajeno']],
        'alcances'=>['kds'=>'restaurante','reservaciones'=>'restaurante','mapa'=>'restaurante','configuracion'=>'restaurante','reportes'=>'restaurante']];
} catch (Throwable $e) {
    $error=$e instanceof mysqli_sql_exception ? 'Error MySQL; código '.$e->getCode().'.' : $e->getMessage();
} finally {
    $cleanupFailed=false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$name,$token]) {
            try {
                if (restaurante3dBasePropia($admin,$name,$token,(string)($_ENV['DB_NAME']??''))) {
                    $admin->query('DROP DATABASE '.restaurante3dIdent($name));
                    fwrite(STDOUT,"Base temporal verificada y eliminada: $name\n");
                } else $cleanupFailed=true;
            } catch (Throwable) { $cleanupFailed=true; }
        }
        $admin->close();
    }
    if ($assetRoot!==null && $assetParent!==null) {
        try { restaurante3dLimpiarRaizImagenes($assetRoot,$assetParent); }
        catch (Throwable) { $cleanupFailed=true; }
    }
    if ($cleanupFailed && $error===null) { $phase='limpieza protegida'; $error='No se pudo verificar la limpieza de algún recurso temporal.'; }
}
if ($error===null) echo Comun::json($output??['ok'=>true,'comprobaciones'=>$count]);
else fwrite(STDERR,Comun::json(['ok'=>false,'fase'=>$phase,'comprobaciones'=>$count,'error'=>$error]));
exit($error===null?0:1);
