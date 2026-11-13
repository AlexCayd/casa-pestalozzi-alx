<?php
declare(strict_types=1);
/**
 * Caja de mostrador de Panadería sobre una base desechable propia: permisos de
 * piso, venta atómica, vitrina, horneada, anulación con NIP de encargado,
 * idempotencia, corte y aislamiento de Restaurante. Nunca toca DB_NAME.
 *
 *   php scripts/tests/run-caja-panaderia-db.php
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/scripts/multinegocio/SqlArchivo.php';
require __DIR__ . '/fixture-3b.php';

use Model\ActiveRecord;
use Multinegocio\SqlArchivo;
use Services\Menu\VitrinaCaja;
use Services\Pos\CajaMostrador;
use Services\Security\Contextos;
use Services\Security\Permisos;
use Services\Security\PermisosUsuario;

Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
if (!in_array($_ENV['APP_ENV'] ?? '', ['development', 'test'], true)) { fwrite(STDERR, "Solo desarrollo/pruebas.\n"); exit(1); }
// Secreto HMAC de NIP propio del proceso: no se lee ni se escribe el de la app.
$_ENV['NIP_LOOKUP_SECRET'] = bin2hex(random_bytes(32));
putenv('NIP_LOOKUP_SECRET=' . $_ENV['NIP_LOOKUP_SECRET']);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int)($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$db->query("SET time_zone='-06:00'");
$name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
$token = bin2hex(random_bytes(32));
$created = false;
$count = 0;
$assert = static function (bool $v, string $m) use (&$count): void { if (!$v) throw new RuntimeException($m); $count++; };
$uno = static fn(string $sql, array $p = []) => $db->execute_query($sql, $p)->fetch_row()[0] ?? null;
$como = static function (array $usuario, string $nivel = 'nip'): void {
    $_SESSION = ['id' => $usuario['id'], 'auth_nivel' => $nivel, '_auth3b' => true, 'login' => true];
};
$clave = static fn(): string => 'qa-' . bin2hex(random_bytes(12));
$saldo = static fn(int $articulo): string => (string)$uno('SELECT saldo FROM almacen_articulos WHERE almacen_id=1007 AND articulo_id=?', [$articulo]);

try {
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_multinegocio_owner VALUES (?)', [$token]);
    foreach (['ddl', 'deploy', 'menu-restaurante', 'development'] as $file) SqlArchivo::ejecutar($db, $root . '/database/' . $file . '.sql');
    SqlArchivo::ejecutar($db, $root . '/database/actualizaciones/a1-datos-comerciales-oferta.sql');
    $db->query('SET @cp_a3_preservar_locales=1');
    try {
        SqlArchivo::ejecutar($db, $root . '/database/actualizaciones/a3-datos-comerciales-local.sql');
    } finally {
        $db->query('SET @cp_a3_preservar_locales=NULL');
    }
    ActiveRecord::setDB($db);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // ── Esquema y clasificación ────────────────────────────────
    $falla = static function (callable $f) { try { $f(); return false; } catch (Throwable) { return true; } };
    $pruebaUsuario = FixtureAutorizacion3B::usuario($db, 'piso_mixto', ['restaurante' => [], 'panaderia' => []]);
    $assert($falla(static fn() => FixtureAutorizacion3B::permiso($db, $pruebaUsuario['id'], 'panaderia', 'area', 'operar')), 'Panadería no admite Producción de Restaurante');
    $assert($falla(static fn() => FixtureAutorizacion3B::permiso($db, $pruebaUsuario['id'], 'restaurante', 'vitrina', 'operar')), 'Restaurante no admite horneadas de caja');
    $assert($falla(static fn() => FixtureAutorizacion3B::permiso($db, $pruebaUsuario['id'], 'panaderia', 'pdv', 'editar')), 'La caja solo admite operar');
    FixtureAutorizacion3B::permiso($db, $pruebaUsuario['id'], 'panaderia', 'pdv', 'operar');
    $assert((int)$uno("SELECT COUNT(*) FROM usuario_unidad_permisos WHERE usuario_id=? AND modulo='pdv'", [$pruebaUsuario['id']]) === 1, 'Caja de Panadería admitida por el trigger');
    $assert(Contextos::deRuta('/panaderia/caja') === 'panaderia' && Contextos::deRuta('/api/panaderia/caja/vender') === 'panaderia', 'La ruta de caja fija el ámbito');
    $assert(Contextos::deRuta('/panaderia') === 'restaurante' && Contextos::deRuta('/panaderia/cajas') === 'restaurante', 'La landing no cambia de ámbito');
    $assert(Permisos::clasificarDirecto('/api/panaderia/caja/horneada')['modulos'] === ['vitrina'], 'Horneada exige vitrina');
    $assert(Permisos::clasificarDirecto('/api/panaderia/caja/vender')['modulos'] === ['pdv'], 'Vender exige caja');
    $assert(Permisos::clasificarDirecto('/api/panaderia/caja/desconocida')['tipo'] === 'solo_sistema', 'API de caja desconocida se deniega');
    $cat = PermisosUsuario::catalogo('panaderia');
    $assert(isset($cat['pdv'], $cat['vitrina'], $cat['anular']) && !isset($cat['area']) && !isset($cat['reservations_map']), 'Catálogo de piso de Panadería');
    $assert(!isset(PermisosUsuario::catalogo('restaurante')['vitrina']), 'Restaurante sin módulos de caja');

    // ── Personas ───────────────────────────────────────────────
    $cajera = FixtureAutorizacion3B::usuario($db, 'cajera', ['panaderia' => ['permisos' => ['pdv' => 'operar', 'vitrina' => 'operar']]]);
    $encargada = FixtureAutorizacion3B::usuario($db, 'encargada', ['panaderia' => ['permisos' => ['pdv' => 'operar', 'anular' => 'operar']]]);
    $mesero = FixtureAutorizacion3B::usuario($db, 'mesero', ['restaurante' => ['permisos' => ['pdv' => 'operar']]]);
    $hornero = FixtureAutorizacion3B::usuario($db, 'hornero', ['panaderia' => ['permisos' => ['vitrina' => 'operar']]]);
    $nipEncargada = FixtureAutorizacion3B::asignarNip($db, $encargada['id']);
    do { $nipCajera = FixtureAutorizacion3B::asignarNip($db, $cajera['id']); } while ($nipCajera === $nipEncargada);
    $assert(PermisosUsuario::tipoAcceso(PermisosUsuario::cargar($cajera['id'], $db)) === Permisos::ACCESO_NIP, 'La cajera entra con NIP');

    $como($mesero);
    $r = CajaMostrador::estado();
    $assert(empty($r['ok']) && in_array($r['codigo'], ['AMBITO_DENEGADO', 'PERMISO_DENEGADO'], true), 'Un mesero de Restaurante no abre la caja');
    $como($hornero);
    $r = CajaMostrador::estado();
    $assert(!empty($r['ok']) && $r['permisos'] === ['vender' => false, 'hornear' => true, 'anular' => false] && $r['catalogo']['productos'] === [], 'Hornero solo ve la vitrina');
    $assert(empty(CajaMostrador::vender([['oferta_id' => 1201, 'cantidad' => 1]], ['metodo' => 'tarjeta'], $clave())['ok']), 'Hornero no vende');

    $como($cajera);
    $estado = CajaMostrador::estado();
    $assert(!empty($estado['ok']) && $estado['permisos'] === ['vender' => true, 'hornear' => true, 'anular' => false], 'Permisos efectivos de la cajera');
    $porId = array_column($estado['catalogo']['productos'], null, 'id');
    $assert(isset($porId[1213]) && count($porId[1213]['g']) === 2 && !isset($porId[1212]), 'Carta: cuernito con dos grupos, sin ofertas inactivas');
    $assert(!array_filter($estado['catalogo']['productos'], static fn($p) => $p['id'] < 1000 || in_array($p['id'], [1401, 1402], true)), 'Carta sin ofertas de Restaurante');
    $assert($porId[1202]['pz'] === '1007:1302' && $porId[1202]['g'][0]['o'][1]['pz'] === '1007:1303', 'Pieza base y pieza de la opción');

    // ── Venta ──────────────────────────────────────────────────
    $tickets0 = (int)$uno('SELECT COUNT(*) FROM tickets WHERE unidad_id=3');
    $movs0 = (int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3');
    $venta = [['oferta_id' => 1202, 'cantidad' => 2, 'variantes' => [1302]], ['oferta_id' => 1210, 'cantidad' => 1]];
    $r = CajaMostrador::vender($venta, ['metodo' => 'efectivo', 'recibido' => '100'], $clave());
    $assert(empty($r['ok']) && $r['codigo'] === 'RECIBIDO_INSUFICIENTE', 'Efectivo insuficiente rechaza: ' . json_encode($r));
    $assert((int)$uno('SELECT COUNT(*) FROM tickets WHERE unidad_id=3') === $tickets0 && (int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3') === $movs0, 'Rechazo sin escritura parcial');

    $k1 = $clave();
    $r = CajaMostrador::vender($venta, ['metodo' => 'efectivo', 'recibido' => '200'], $k1);
    $assert(!empty($r['ok']) && !empty($r['confirmado']), 'Venta confirmada: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    $t1 = (int)$r['ticket']['id'];
    $assert($r['ticket']['total'] === '113.00' && $r['ticket']['cambio'] === '87.00' && $r['ticket']['recibido'] === '200.00', 'Total, recibido y cambio exactos');
    $assert($uno('SELECT CONCAT(estado,"|",metodo_pago,"|",propina,"|",mesero_id) FROM tickets WHERE id=?', [$t1]) === 'cerrado|efectivo|0.00|' . $cajera['id'], 'Ticket cerrado con cajero');
    $assert((int)$uno("SELECT COUNT(*) FROM ticket_items WHERE ticket_id=? AND estado='entregado' AND costo_unitario IS NOT NULL AND receta_id IS NULL", [$t1]) === 2, 'Líneas entregadas con costo de pieza');
    $assert($uno('SELECT variante_ids FROM ticket_items WHERE ticket_id=? AND oferta_id=1202', [$t1]) === '1302' && $uno('SELECT variante_ids FROM ticket_items WHERE ticket_id=? AND oferta_id=1210', [$t1]) === '1303', 'Snapshot de opciones con predeterminadas');
    $assert($saldo(1303) === '1.000' && $saldo(1311) === '8.000' && $saldo(1302) === '24.000', 'Vitrina descuenta la pieza de la opción');
    $assert((int)$uno("SELECT COUNT(*) FROM movimientos_inventario m JOIN ticket_items ti ON ti.id=m.ticket_item_id WHERE ti.ticket_id=? AND m.tipo='venta'", [$t1]) === 2, 'Movimientos de venta trazables');

    $movs1 = (int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3');
    $r = CajaMostrador::vender($venta, ['metodo' => 'efectivo', 'recibido' => '200'], $k1);
    $assert(!empty($r['ok']) && !empty($r['reintento']) && (int)$r['ticket']['id'] === $t1, 'Reintento devuelve la misma venta');
    $assert((int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3') === $movs1, 'Reintento sin doble descuento');
    $r = CajaMostrador::vender([['oferta_id' => 1201, 'cantidad' => 1]], ['metodo' => 'tarjeta'], $k1);
    $assert(empty($r['ok']) && $r['codigo'] === 'CONFLICTO_IDEMPOTENCIA', 'Clave reutilizada con otro contenido');

    $r = CajaMostrador::vender([['oferta_id' => 1213, 'cantidad' => 1, 'variantes' => [1309, 1311]]], ['metodo' => 'tarjeta'], $clave());
    // §16: ningún saldo es negativo; la pieza que faltó queda por conciliar.
    $assert(!empty($r['ok']) && $r['ticket']['total'] === '36.00' && $saldo(1316) === '0.000', 'Vitrina en cero vende y no queda en negativo');
    $assert(($r['avisos'][0]['codigo'] ?? '') === 'VITRINA_FALTANTE' && ($r['avisos'][0]['faltante'] ?? '') === '1.000', 'La venta avisa del faltante: ' . json_encode($r['avisos'] ?? null, JSON_UNESCAPED_UNICODE));
    $assert($uno("SELECT CONCAT(f.estado,'|',f.cantidad) FROM faltantes_inventario f JOIN ticket_items ti ON ti.id=f.ticket_item_id WHERE ti.ticket_id=? AND f.articulo_id=1316", [(int)$r['ticket']['id']]) === 'pendiente|1.000', 'Faltante pendiente ligado a la venta');
    $assert((int)$uno('SELECT COUNT(*) FROM almacen_articulos WHERE saldo<0') === 0, 'Ninguna existencia negativa');
    $r = CajaMostrador::vender([['oferta_id' => 1205, 'cantidad' => 1, 'variantes' => [1306]], ['oferta_id' => 1209, 'cantidad' => 1, 'variantes' => [1313]]], ['metodo' => 'tarjeta'], $clave());
    $assert(!empty($r['ok']) && $r['ticket']['total'] === '215.00' && $saldo(1306) === '5.500' && $saldo(1310) === '24.000', 'Media pieza y media docena exactas');
    foreach ([
        [[['oferta_id' => 1202, 'cantidad' => 1, 'variantes' => [1304]]], 'VARIANTE_INVALIDA', 'Opción de otro producto'],
        [[['oferta_id' => 1001, 'cantidad' => 1]], 'OFERTA_NO_DISPONIBLE', 'Oferta de Restaurante'],
        [[['oferta_id' => 1212, 'cantidad' => 1]], 'OFERTA_NO_DISPONIBLE', 'Oferta inactiva'],
        [[['oferta_id' => 1201, 'cantidad' => 0]], 'ITEM_INVALIDO', 'Cantidad cero'],
        [[['oferta_id' => 1201, 'cantidad' => 256]], 'ITEM_INVALIDO', 'Cantidad fuera de rango'],
    ] as [$items, $codigo, $m]) {
        $r = CajaMostrador::vender($items, ['metodo' => 'tarjeta'], $clave());
        $assert(empty($r['ok']) && $r['codigo'] === $codigo, $m . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    $r = CajaMostrador::vender([['oferta_id' => 1201, 'cantidad' => 1]], ['metodo' => 'cheque'], $clave());
    $assert(empty($r['ok']) && $r['codigo'] === 'METODO_INVALIDO', 'Método no admitido');

    // ── Horneada ───────────────────────────────────────────────
    $kh = $clave();
    $r = CajaMostrador::hornear([['almacen_id' => 1007, 'articulo_id' => 1316, 'cantidad' => '12']], $kh);
    // Sin negativos (§16) la horneada no descuenta la pieza vendida de más: sigue
    // como faltante pendiente hasta que alguien concilie su origen en Almacenes.
    $assert(!empty($r['ok']) && $saldo(1316) === '12.000', 'Horneada suma piezas: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = CajaMostrador::hornear([['almacen_id' => 1007, 'articulo_id' => 1316, 'cantidad' => '12']], $kh);
    $assert(!empty($r['ok']) && !empty($r['reintento']) && $saldo(1316) === '12.000', 'Horneada idempotente');
    $r = CajaMostrador::hornear([['almacen_id' => 1003, 'articulo_id' => 1001, 'cantidad' => '500']], $clave());
    $assert(empty($r['ok']) && $r['codigo'] === 'PIEZA_NO_VITRINA', 'Horneada solo de piezas de vitrina');
    $assert($uno("SELECT motivo FROM movimientos_inventario WHERE articulo_id=1316 AND tipo='entrada' ORDER BY id DESC LIMIT 1") === 'horneada', 'Horneada trazable');
    $como($encargada);
    $r = CajaMostrador::hornear([['almacen_id' => 1007, 'articulo_id' => 1301, 'cantidad' => '6']], $clave());
    $assert(empty($r['ok']), 'Sin vitrina no se registran horneadas');

    // ── Anulación ──────────────────────────────────────────────
    $como($cajera);
    $r = CajaMostrador::anular($t1, 'cobro_equivocado', null, $clave(), null);
    $assert(empty($r['ok']) && $r['codigo'] === 'AUTORIZACION_REQUERIDA', 'Sin permiso pide autorización');
    $r = CajaMostrador::anular($t1, 'cobro_equivocado', null, $clave(), $nipCajera);
    $assert(empty($r['ok']) && $r['codigo'] === 'AUTORIZACION_INVALIDA', 'Un NIP sin permiso no autoriza');
    $r = CajaMostrador::anular($t1, 'otro', null, $clave(), $nipEncargada);
    $assert(empty($r['ok']) && $r['codigo'] === 'NOTA_REQUERIDA', '«Otro motivo» exige nota');
    $ka = $clave();
    $r = CajaMostrador::anular($t1, 'cobro_equivocado', 'Marcó dos de nata', $ka, $nipEncargada);
    $assert(!empty($r['ok']) && $r['total'] === '113.00', 'Anulación autorizada por NIP: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    $assert($uno('SELECT estado FROM tickets WHERE id=?', [$t1]) === 'cancelado' && (int)$uno("SELECT COUNT(*) FROM ticket_items WHERE ticket_id=? AND estado<>'cancelado'", [$t1]) === 0, 'Ticket y líneas anulados');
    $assert($saldo(1303) === '3.000' && $saldo(1311) === '9.000', 'Piezas de vuelta a la vitrina');
    $assert($uno('SELECT CONCAT(solicitante_id,"|",autorizador_id,"|",motivo) FROM ticket_anulaciones WHERE ticket_id=?', [$t1]) === $cajera['id'] . '|' . $encargada['id'] . '|cobro_equivocado', 'Quién pidió, quién autorizó y por qué');
    $movs2 = (int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3');
    $r = CajaMostrador::anular($t1, 'cobro_equivocado', 'Marcó dos de nata', $ka, $nipEncargada);
    $assert(!empty($r['ok']) && !empty($r['reintento']) && (int)$uno('SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=3') === $movs2, 'Anulación idempotente');
    $r = CajaMostrador::anular($t1, 'cobro_equivocado', null, $clave(), $nipEncargada);
    $assert(empty($r['ok']) && $r['codigo'] === 'TICKET_NO_ANULABLE', 'No se anula dos veces');
    $assert(empty(CajaMostrador::anular(1001, 'cobro_equivocado', null, $clave(), $nipEncargada)['ok']), 'Ticket de Restaurante no anulable desde la caja');
    $como($encargada);
    $tEnc = CajaMostrador::vender([['oferta_id' => 1206, 'cantidad' => 2]], ['metodo' => 'tarjeta'], $clave());
    $r = CajaMostrador::anular((int)$tEnc['ticket']['id'], 'cliente_desistio', null, $clave(), null);
    $assert(!empty($r['ok']) && $saldo(1307) === '10.000', 'Con permiso propio anula sin NIP');

    // ── Lecturas: tickets, corte y lo que leen Finanzas ────────
    $como($cajera);
    $lista = CajaMostrador::ticketsDeHoy();
    $assert(!empty($lista['ok']) && count($lista['tickets']) === 4 && $lista['tickets'][3]['estado'] === 'anulado', 'Tickets de hoy con anulados');
    $corte = CajaMostrador::corte(null, null);
    $assert(!empty($corte['ok']) && $corte['resumen']['tickets'] === 2 && abs($corte['resumen']['ventas'] - 251.0) < 0.001, 'Corte sin anulados: ' . json_encode($corte['resumen']));
    $assert($corte['anulaciones']['tickets'] === 2 && $corte['metodos']['tarjeta'] == 251.0 && $corte['piezas'] === 3, 'Corte: anulaciones, métodos y piezas');
    $ventaFinanzas = (string)$uno("SELECT SUM(ti.precio*ti.cantidad) FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id WHERE t.unidad_id=3 AND t.estado='cerrado' AND ti.estado<>'cancelado'");
    $assert($ventaFinanzas === '251.00', 'El criterio de Finanzas suma lo mismo que el corte');
    $cobrado = array_values(array_filter($lista['tickets'], static fn($t) => $t['estado'] === 'cobrado'))[0];
    $comp = CajaMostrador::comprobante((int)$cobrado['id']);
    $assert(!empty($comp['ok']) && $comp['ticket']['metodo'] === 'tarjeta', 'Comprobante de ticket propio');
    $assert(empty(CajaMostrador::comprobante($t1)['ok']), 'Sin comprobante de un ticket anulado');

    // ── Vitrina de la caja (Menú) ──────────────────────────────
    $menuPan = FixtureAutorizacion3B::usuario($db, 'menu_pan', ['panaderia' => ['permisos' => ['menu' => 'editar']]]);
    $menuVer = FixtureAutorizacion3B::usuario($db, 'menu_ver', ['panaderia' => ['permisos' => ['menu' => 'ver']]]);
    $como($menuPan, 'contrasena');
    $vista = VitrinaCaja::vista();
    $assert($vista['puedeEditar'] === true && count($vista['productos']) === 14 && !array_filter($vista['piezas'], static fn($p) => $p['almacen_id'] !== 1003 && $p['almacen_id'] !== 1007), 'Vista de vitrina: panes y piezas sólo de Panadería');
    $tam = [['nombre' => 'Tamaño', 'opciones' => [
        ['id' => 0, 'nombre' => 'Entera', 'precio_delta' => 0, 'predeterminada' => true, 'pieza' => null],
        ['id' => 0, 'nombre' => 'Media', 'precio_delta' => -20, 'predeterminada' => false, 'pieza' => ['almacen_id' => 1007, 'articulo_id' => 1307, 'cantidad' => '0.5']],
    ]]];
    $r = VitrinaCaja::guardar(1206, ['almacen_id' => 1007, 'articulo_id' => 1307, 'cantidad' => '1'], $tam);
    $assert(!empty($r['ok']) && count($r['producto']['grupos'][0]['opciones']) === 2, 'Opciones y pieza guardadas juntas: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    $media = (int)$uno("SELECT id FROM producto_variantes WHERE oferta_id=1206 AND nombre='Media'");
    $assert($uno('SELECT cantidad FROM vitrina_piezas_variante WHERE variante_id=?', [$media]) === '0.500', 'Media baguette descuenta media pieza');
    $doble = $tam; $doble[] = ['nombre' => 'Horneado', 'opciones' => [
        ['id' => 0, 'nombre' => 'Normal', 'precio_delta' => 0, 'predeterminada' => true, 'pieza' => null],
        ['id' => 0, 'nombre' => 'Dorado', 'precio_delta' => 0, 'predeterminada' => false, 'pieza' => ['almacen_id' => 1007, 'articulo_id' => 1307, 'cantidad' => '1']],
    ]];
    $antes = (int)$uno('SELECT COUNT(*) FROM producto_variantes WHERE oferta_id=1206');
    $r = VitrinaCaja::guardar(1206, ['almacen_id' => 1007, 'articulo_id' => 1307, 'cantidad' => '1'], $doble);
    $assert(empty($r['ok']) && $r['codigo'] === 'VITRINA_AMBIGUA' && (int)$uno('SELECT COUNT(*) FROM producto_variantes WHERE oferta_id=1206') === $antes, 'Dos grupos con pieza se rechazan sin dejar opciones a medias');
    $r = VitrinaCaja::guardar(1206, ['almacen_id' => 1002, 'articulo_id' => 1001, 'cantidad' => '1'], null);
    $assert(empty($r['ok']) && $r['codigo'] === 'PIEZA_NO_DISPONIBLE', 'Pieza de un almacén de Restaurante rechazada');
    $r = VitrinaCaja::guardar(1001, ['almacen_id' => 1007, 'articulo_id' => 1307, 'cantidad' => '1'], null);
    $assert(empty($r['ok']), 'Oferta de Restaurante fuera de la vitrina de Panadería');
    $como($menuVer, 'contrasena');
    $assert(VitrinaCaja::vista()['puedeEditar'] === false && empty(VitrinaCaja::guardar(1206, null, null)['ok']), 'Menú en consulta no edita la vitrina');
    $como($encargada);
    $assert(empty(VitrinaCaja::guardar(1206, null, null)['ok']), 'La caja no edita la vitrina');
    $p1206 = array_column(CajaMostrador::estado()['catalogo']['productos'], null, 'id')[1206];
    $assert(count($p1206['g']) === 1 && $p1206['g'][0]['o'][1]['q'] === '0.500', 'La caja ve las opciones nuevas en la siguiente carga');
    $r = CajaMostrador::vender([['oferta_id' => 1206, 'cantidad' => 2, 'variantes' => [$media]]], ['metodo' => 'tarjeta'], $clave());
    $assert(!empty($r['ok']) && $r['ticket']['total'] === '56.00' && $saldo(1307) === '9.000', 'Dos medias baguettes: precio con delta y una pieza');

    // ── Revocación y Restaurante intacto ───────────────────────
    $como($cajera);
    FixtureAutorizacion3B::permiso($db, $cajera['id'], 'panaderia', 'pdv', null);
    $assert(empty(CajaMostrador::vender([['oferta_id' => 1201, 'cantidad' => 1]], ['metodo' => 'tarjeta'], $clave())['ok']), 'Revocar caja surte efecto en la siguiente venta');
    $assert((int)$uno("SELECT COUNT(*) FROM movimientos_inventario WHERE unidad_id=2 AND fecha>=CURDATE() AND tipo IN ('venta','cancelacion') AND operacion_id IN (SELECT id FROM operaciones_inventario WHERE origen='pos' AND unidad_id=2 AND fecha>=NOW()-INTERVAL 1 HOUR)") === 0, 'Restaurante sin movimientos de la caja');
    $assert((int)$uno('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=2') >= 112, 'Ofertas de Restaurante intactas');

    echo "Caja de Panadería: {$count} comprobaciones PASS\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $count = -1;
} finally {
    if ($created) {
        try {
            if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)
                || strcasecmp($name, (string)($_ENV['DB_NAME'] ?? '')) === 0) {
                throw new RuntimeException('Destino temporal inválido; se conserva la base.');
            }
            $owner = $db->query('SELECT token FROM `' . $name . '`._cp_multinegocio_owner')->fetch_row()[0] ?? null;
            if (!is_string($owner) || !hash_equals($token, $owner)) {
                throw new RuntimeException('Propiedad temporal no verificada; se conserva la base.');
            }
            $db->query('DROP DATABASE `' . $name . '`');
        } catch (Throwable $e) {
            fwrite(STDERR, 'Limpieza rechazada: ' . $e->getMessage() . "\n");
            $count = -1;
        }
    }
    $db->close();
}
exit($count < 0 ? 1 : 0);
