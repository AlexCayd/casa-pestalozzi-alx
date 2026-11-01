<?php

declare(strict_types=1);

/*
 * Análisis de datos de Panadería y de Global contra la base de desarrollo.
 *
 * Corre en UNA transacción que se revierte (autocommit apagado). Siembra un
 * día de caja de Panadería, con un producto de la casa, y cuentas con
 * permisos acotados; no deja nada. Comprueba:
 *   - el tablero de Panadería: cifras contra lo sembrado y métricas de caja
 *     (sin propinas, comensales, mesas ni reservaciones);
 *   - el mapa de calor de venta de Panadería en lugar del RevPASH;
 *   - el comparativo de Global: suma exacta de los negocios, participación y
 *     productos de la casa unidos por producto_id;
 *   - que Restaurante conserva su tablero y su RevPASH;
 *   - los permisos.
 *
 *   php scripts/tests/run-analisis-ambito-db.php
 */

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Analytics\AnalisisConsolidado;
use Services\Analytics\AnalisisNegocio as A;
use Services\Analytics\Analiticas;
use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio as N;
use Services\Shared\RangoPeriodo;

$entorno = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
if (!in_array($entorno, ['development', 'test'], true)) {
    echo "SKIP: sólo en desarrollo o pruebas.\n";
    exit(0);
}
$db = ActiveRecord::getDB();
$uno = static function (string $sql, array $args = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($args);
    return $s->get_result()->fetch_row()[0] ?? null;
};
if (!$db instanceof mysqli || $uno("SELECT COUNT(*) FROM esquema_versiones WHERE version = 'multinegocio-3b-v1'") !== 1) {
    echo "SKIP: la base no tiene el esquema multinegocio.\n";
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$assertions): void {
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
    $assertions++;
};
$casi = static fn (float $a, float $b): bool => abs($a - $b) < 0.005;
$codigo = static function (callable $fn): string {
    try {
        $fn();
    } catch (InventarioError $e) {
        return $e->codigo;
    } catch (\RuntimeException $e) {
        return 'RUNTIME';
    }
    return '';
};
$dinero = static fn (string $v): float => (float) str_replace(['$', ','], '', $v);

$unidad = [];
foreach ($db->query('SELECT slug, id FROM unidades')->fetch_all(MYSQLI_ASSOC) as $u) {
    $unidad[$u['slug']] = (int) $u['id'];
}
$super = (int) $uno('SELECT id FROM usuarios WHERE es_superadmin = 1 AND activo = 1 ORDER BY id LIMIT 1');
if ($super === 0 || count($unidad) < 3) {
    echo "SKIP: faltan el superadministrador o las unidades.\n";
    exit(0);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$como = static function (int $id): void {
    $_SESSION = ['id' => $id, 'auth_nivel' => 'contrasena', '_auth3b' => true, 'login' => true];
};
$como($super);
$hoy = date('Y-m-d');
$rango = RangoPeriodo::resolver(['rango' => '7d']);

$db->autocommit(false);
try {
    // Un producto de la casa que Panadería ofrece, y otro producto cualquiera.
    $casa = $db->query("SELECT o.id, o.producto_id, o.categoria_id, o.area_id, po.nombre, c.nombre AS categoria
        FROM ofertas_producto o JOIN productos_oferta po ON po.oferta_id = o.id JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
        JOIN producto_estandares pe ON pe.producto_id = o.producto_id
        WHERE o.unidad_id = {$unidad['panaderia']} ORDER BY o.id LIMIT 1")->fetch_assoc();
    $otro = $db->query("SELECT o.id, o.producto_id, o.categoria_id, o.area_id, po.nombre, c.nombre AS categoria
        FROM ofertas_producto o JOIN productos_oferta po ON po.oferta_id = o.id JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
        WHERE o.unidad_id = {$unidad['panaderia']} AND o.producto_id <> " . (int) ($casa['producto_id'] ?? 0) . ' ORDER BY o.id LIMIT 1')->fetch_assoc();
    $assert($casa !== null && $otro !== null, 'Panadería ofrece un producto de la casa y otro propio (carga development.sql).');

    $antes = A::totales($db, $unidad['panaderia'], $hoy, $hoy);
    $antesCasa = N::en('global', static fn () => AnalisisConsolidado::armar($rango));
    $hora = 9;
    $s = $db->prepare("INSERT INTO tickets (unidad_id, unidad_tipo, estado, nombre, hora_apertura, hora_cierre, metodo_pago)
        VALUES (?, 'negocio', 'cerrado', 'QA análisis', CURDATE() + INTERVAL ? HOUR, CURDATE() + INTERVAL ? HOUR + INTERVAL 3 MINUTE, ?)");
    $item = $db->prepare('INSERT INTO ticket_items (unidad_id, ticket_id, oferta_id, producto_id, nombre, precio, categoria, categoria_id, area_id, cantidad, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $renglon = static function (int $t, array $o, string $precio, int $cantidad, string $estado = 'entregado') use ($item, $unidad): void {
        $item->execute([$unidad['panaderia'], $t, $o['id'], $o['producto_id'], $o['nombre'], $precio, $o['categoria'], $o['categoria_id'], $o['area_id'], $cantidad, $estado]);
    };
    $s->execute([$unidad['panaderia'], $hora, $hora, 'efectivo']);
    $t1 = (int) $db->insert_id;
    $renglon($t1, $casa, '30.00', 2);              // 60, producto de la casa
    $renglon($t1, $otro, '20.00', 1);              // 20
    $renglon($t1, $otro, '20.00', 1, 'cancelado'); // no cuenta
    $s->execute([$unidad['panaderia'], $hora, $hora, 'tarjeta']);
    $t2 = (int) $db->insert_id;
    $renglon($t2, $casa, '30.00', 1);              // 30

    // 1. Totales y tablero de Panadería.
    $despues = A::totales($db, $unidad['panaderia'], $hoy, $hoy);
    $assert($casi($despues['ventas'] - $antes['ventas'], 110.0), 'Ventas de caja: 60 + 20 + 30, sin el cancelado.');
    $assert($despues['tickets'] - $antes['tickets'] === 2 && $despues['piezas'] - $antes['piezas'] === 4, 'Tickets y piezas sin cancelados.');

    $tablero = N::en('panaderia', static fn () => A::descriptivo($db, 'panaderia', $unidad['panaderia'], RangoPeriodo::resolver(['rango' => 'hoy'])));
    $rotulos = array_column($tablero['metrics'], 'label');
    $assert(in_array('Piezas por ticket', $rotulos, true), 'Panadería mide piezas por ticket.');
    $assert(!array_intersect(['Propinas', 'Comensales atendidos', 'Reservaciones'], $rotulos), 'Sin propinas, comensales ni reservaciones en la caja.');
    $assert($tablero['charts']['reservationsByDay']['values'] === [], 'Panadería no grafica reservaciones.');
    $assert($tablero['tickets'] !== [] && !array_key_exists('mesa', $tablero['tickets'][0]) && !array_key_exists('propina', $tablero['tickets'][0]), 'Los tickets de caja no llevan mesa ni propina.');
    $assert($casi($dinero($tablero['metrics'][0]['value']), round($despues['ventas'])), 'La cabecera dice lo cobrado.');
    $assert(in_array($casa['nombre'], $tablero['charts']['topProducts']['labels'], true), 'Lo más vendido incluye el producto sembrado.');

    // 2. El mapa de calor de venta en lugar del RevPASH.
    $n1 = N::en('panaderia', static fn () => Analiticas::nivel1($hoy, $hoy));
    $assert(isset($n1['mapa']) && !isset($n1['revpash']), 'Panadería lleva mapa de calor de venta, no RevPASH.');
    $assert($n1['mapa']['medida'] === 'dia' && $n1['mapa']['asientos'] === null, 'El mapa no divide entre asientos.');
    $dow = (int) date('w');
    $fila = array_search(sprintf('%02d:00', $hora), $n1['mapa']['horas'], true);
    $col = array_search($dow, array_column($n1['mapa']['dias'], 'dow'), true);
    $assert($fila !== false && $col !== false, 'La franja sembrada aparece en el mapa.');
    $celda = $n1['mapa']['celdas'][$fila][$col];
    $assert($celda['ing'] >= 110.0 && $casi($celda['v'], $celda['dias'] > 0 ? $celda['ing'] / $celda['dias'] : 0.0), 'La celda es la venta entre los días abiertos.');
    $assert(isset($n1['ingenieria'], $n1['porHora'], $n1['asociacion']), 'Panadería conserva ingeniería de menú, horas y qué se lleva junto.');
    $assert($codigo(static fn () => N::en('global', static fn () => Analiticas::nivel1($hoy, $hoy))) !== '', 'Global no tiene analíticas diagnósticas propias.');

    // 3. Restaurante conserva su tablero y su RevPASH.
    $rest = N::en('restaurante', static fn () => Analiticas::nivel1($rango['start'], $rango['end']));
    $assert(isset($rest['revpash']) && ($rest['revpash']['medida'] ?? '') === 'asiento', 'Restaurante conserva el RevPASH por asiento.');
    $tabRest = N::en('restaurante', static fn () => A::descriptivo($db, 'restaurante', $unidad['restaurante'], $rango));
    $assert(in_array('Propinas', array_column($tabRest['metrics'], 'label'), true), 'Restaurante conserva propinas.');
    $assert(count($tabRest['charts']['reservationsByDay']['values']) === (int) $rango['dias'], 'La serie de reservaciones cubre el periodo.');

    // 4. El comparativo de Global.
    $c = N::en('global', static fn () => AnalisisConsolidado::armar($rango));
    $assert(array_keys($c['negocios']) === ['restaurante', 'panaderia'], 'Global compara los dos negocios.');
    $assert($casi($c['total']['ventas'], $c['negocios']['restaurante']['ventas'] + $c['negocios']['panaderia']['ventas']), 'El total es la suma de los negocios.');
    $assert($c['total']['tickets'] === $c['negocios']['restaurante']['tickets'] + $c['negocios']['panaderia']['tickets'], 'Los tickets se suman.');
    if ($c['total']['ventas'] > 0) {
        $assert($casi((float) $c['total']['ticketPromedio'], $c['total']['ventas'] / $c['total']['tickets']), 'El ticket promedio total sale de los totales.');
        $assert($casi(array_sum(array_map('floatval', $c['total']['participacion'])), 100.0), 'La participación suma 100 %.');
    }
    $assert(count($c['etiquetasDia']) === count($c['negocios']['restaurante']['porDia']) && count($c['etiquetasDia']) === count($c['negocios']['panaderia']['porDia']), 'Las dos series de venta por día comparten fechas.');
    $resumenRest = A::totales($db, $unidad['restaurante'], (string) $rango['start'], (string) $rango['end']);
    $assert($casi($c['negocios']['restaurante']['ventas'], $resumenRest['ventas']), 'Global ve las mismas ventas que el negocio.');

    $porId = array_column($c['productosCasa'], null, 'id');
    $porIdAntes = array_column($antesCasa['productosCasa'], null, 'id');
    $assert(isset($porId[(int) $casa['producto_id']]), 'El producto sembrado es de la casa.');
    $pan = $porId[(int) $casa['producto_id']]['negocios']['panaderia'];
    $panAntes = $porIdAntes[(int) $casa['producto_id']]['negocios']['panaderia'] ?? ['piezas' => 0, 'ventas' => 0.0];
    $assert($pan !== null && $pan['piezas'] - $panAntes['piezas'] === 3 && $casi($pan['ventas'] - $panAntes['ventas'], 90.0), 'Lo vendido de un producto de la casa se une por producto_id.');
    $assert(count($c['productosCasa']) === (int) $uno('SELECT COUNT(*) FROM producto_estandares'), 'Están todos los productos de la casa.');

    // 5. Permisos.
    $cuenta = static function (string $nombre, array $permisos) use ($db, $unidad): int {
        $db->prepare("INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES (?, ?, 'sin-acceso', 1)")
            ->execute(['qa_an_' . $nombre . '_' . bin2hex(random_bytes(3)), 'QA ' . $nombre]);
        $id = (int) $db->insert_id;
        foreach ($permisos as $slug => $modulos) {
            $db->prepare('INSERT INTO usuario_unidades (usuario_id, unidad_id, activo) VALUES (?, ?, 1)')->execute([$id, $unidad[$slug]]);
            foreach ($modulos as $modulo) {
                $db->prepare("INSERT INTO usuario_unidad_permisos (usuario_id, unidad_id, modulo, nivel) VALUES (?, ?, ?, 'ver')")->execute([$id, $unidad[$slug], $modulo]);
            }
        }
        return $id;
    };
    $como($cuenta('glo', ['global' => ['analytics']]));
    $assert(isset(N::en('global', static fn () => AnalisisConsolidado::armar($rango))['total']), 'Análisis en Global basta para el comparativo.');
    $assert($codigo(static fn () => N::en('panaderia', static fn () => Analiticas::nivel1($hoy, $hoy))) === 'AMBITO_DENEGADO', 'El comparativo no abre el análisis de un negocio.');
    $como($cuenta('pan', ['panaderia' => ['analytics']]));
    $assert($codigo(static fn () => N::en('global', static fn () => AnalisisConsolidado::armar($rango))) === 'AMBITO_DENEGADO', 'Sin Global no hay comparativo.');
    $assert(isset(N::en('panaderia', static fn () => Analiticas::nivel1($hoy, $hoy))['mapa']), 'Panadería abre su análisis con su permiso.');
    $como($cuenta('pan_menu', ['panaderia' => ['menu']]));
    $assert($codigo(static fn () => N::en('panaderia', static fn () => Analiticas::nivel1($hoy, $hoy))) === 'PERMISO_DENEGADO', 'Sin Análisis no hay analíticas.');
} finally {
    $db->rollback();
    $db->autocommit(true);
    $_SESSION = [];
}

echo "Análisis de Panadería y Global: {$assertions} comprobaciones OK\n";
