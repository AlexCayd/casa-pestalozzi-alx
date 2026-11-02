<?php

declare(strict_types=1);

/*
 * Finanzas de Panadería y de Global contra la base de desarrollo.
 *
 * Corre en UNA transacción que se revierte (autocommit apagado, para que las
 * transacciones de ContextoNegocio la reconozcan como externa y usen
 * SAVEPOINT en vez de confirmarla). Siembra tickets de caja de Panadería,
 * cuentas con permisos acotados y un gasto fijo, y no deja nada. Comprueba:
 *   - las cifras de un negocio (FinanzasNegocio) contra lo sembrado;
 *   - que el consolidado de Global es la suma exacta de los negocios, con el
 *     inventario de Global aparte y los márgenes sacados de los totales;
 *   - el pulso de hoy: cobrado, cuentas abiertas y lo que llevan;
 *   - el diagnóstico de Panadería con sus referencias y sin enlaces a
 *     módulos que no tiene;
 *   - los gastos fijos por negocio y su aislamiento;
 *   - los permisos del consolidado.
 *
 *   php scripts/tests/run-finanzas-ambito-db.php
 */

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\GastoFijo;
use Services\Finance\ConsolidadoFinanciero;
use Services\Finance\DiagnosticoFinanciero;
use Services\Finance\FinanzasNegocio as F;
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
    }
    return '';
};

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
$rango = RangoPeriodo::resolver(['rango' => '7d']);
$hoy = date('Y-m-d');

$db->autocommit(false);
try {
    // 1. Un día de caja de Panadería: dos tickets cerrados (uno con un
    // renglón cancelado y otro sin costo) y una cuenta abierta.
    $ofertas = $db->query("SELECT o.id, o.producto_id, o.categoria_id, o.area_id, po.nombre, c.nombre AS categoria
        FROM ofertas_producto o JOIN productos_oferta po ON po.oferta_id = o.id JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
        WHERE o.unidad_id = {$unidad['panaderia']} AND o.activo = 1 ORDER BY o.id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
    $assert(count($ofertas) === 2, 'Panadería tiene productos para sembrar ventas (carga development.sql).');

    $antes = F::agregados($db, $unidad['panaderia'], $hoy, $hoy);
    $antesHoy = F::pulsoHoy($db, $unidad['panaderia']);
    $ticket = static function (string $estado, string $metodo = 'efectivo') use ($db, $unidad): int {
        $s = $db->prepare("INSERT INTO tickets (unidad_id, unidad_tipo, estado, nombre, hora_apertura, hora_cierre, metodo_pago)
            VALUES (?, 'negocio', ?, 'QA caja', NOW() - INTERVAL 5 MINUTE, IF(? = 'cerrado', NOW() - INTERVAL 1 MINUTE, NULL), ?)");
        $s->execute([$unidad['panaderia'], $estado, $estado, $estado === 'cerrado' ? $metodo : null]);
        return (int) $db->insert_id;
    };
    $item = $db->prepare('INSERT INTO ticket_items (unidad_id, ticket_id, oferta_id, producto_id, nombre, precio, categoria, categoria_id, area_id, cantidad, estado, costo_unitario)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $renglon = static function (int $t, array $o, string $precio, int $cantidad, string $estado, ?string $costo) use ($item, $unidad): void {
        $item->execute([$unidad['panaderia'], $t, $o['id'], $o['producto_id'], $o['nombre'], $precio, $o['categoria'], $o['categoria_id'], $o['area_id'], $cantidad, $estado, $costo]);
    };
    $t1 = $ticket('cerrado', 'efectivo');
    $renglon($t1, $ofertas[0], '40.00', 2, 'entregado', '12.0000');   // 80 de venta, 24 de costo
    $renglon($t1, $ofertas[0], '40.00', 1, 'cancelado', '12.0000');   // no cuenta
    $t2 = $ticket('cerrado', 'tarjeta');
    $renglon($t2, $ofertas[1], '25.00', 1, 'entregado', null);        // 25 sin costo
    $t3 = $ticket('abierto');
    $renglon($t3, $ofertas[1], '25.00', 2, 'entregado', null);        // 50 en curso

    $despues = F::agregados($db, $unidad['panaderia'], $hoy, $hoy);
    $assert($casi($despues['ingresos'] - $antes['ingresos'], 105.0), 'Ingresos de caja: 80 + 25, sin cancelados ni cuentas abiertas.');
    $assert($despues['tickets'] - $antes['tickets'] === 2, 'Sólo cuentan los tickets cerrados.');
    $assert($casi($despues['cogs'] - $antes['cogs'], 24.0), 'El costo de insumos es el congelado en el ticket.');
    $assert($casi($despues['sinCosto'] - $antes['sinCosto'], 25.0), 'Lo vendido sin costo se separa, no se estima.');

    $pulso = F::pulsoHoy($db, $unidad['panaderia']);
    $assert($casi($pulso['ventas'] - $antesHoy['ventas'], 105.0), 'El pulso de hoy suma lo cobrado.');
    $assert($pulso['abiertas'] - $antesHoy['abiertas'] === 1 && $casi($pulso['enCurso'] - $antesHoy['enCurso'], 50.0), 'Las cuentas abiertas y lo que llevan.');

    $cortes = F::cortes($db, $unidad['panaderia'], $hoy, $hoy);
    $assert($cortes !== [] && $cortes[0]['fecha'] === $hoy, 'El corte del día existe.');
    $assert($cortes[0]['efectivo'] >= 80.0 && $cortes[0]['tarjeta'] >= 25.0, 'El corte reparte efectivo y tarjeta.');

    // 2. Estado de resultados y diagnóstico de Panadería con su giro.
    $pan = F::resumen($db, 'panaderia', $unidad['panaderia'], $rango);
    $assert($casi($pan['utilidadBruta'], $pan['ingresos'] - $pan['cogs'] - $pan['merma']), 'Utilidad bruta = ingresos − insumos − merma.');
    $assert($casi($pan['utilidadNeta'], $pan['utilidadBruta'] - $pan['gastos']), 'Utilidad neta = bruta − gastos fijos prorrateados.');
    $signos = array_column($pan['diagnostico']['signos'], null, 'clave');
    $assert(str_contains($signos['insumos']['referencia'], '25–35%'), 'Panadería se compara con referencias de panadería.');
    $assert($signos['inventario']['accion'][1] === '/admin/panaderia/almacenes', 'Las acciones de Panadería van a sus módulos.');
    $pasos = array_column($pan['diagnostico']['cascada']['pasos'], null, 'clave');
    $assert(($pasos['ingresos']['acciones'] ?? []) === [] && ($pasos['ingresos']['texto'] ?? []) === [], 'Sin Tickets ni propinas en la caja de Panadería.');
    $assert(DiagnosticoFinanciero::GIROS['restaurante']['INSUMOS_REF'] === DiagnosticoFinanciero::INSUMOS_REF, 'Restaurante conserva sus referencias.');
    $assert($codigo(static fn () => F::resumen($db, 'global', $unidad['global'], $rango)) === 'AMBITO_NO_VENDEDOR', 'Global no tiene estado de resultados propio.');

    // 3. El consolidado de Global es la suma exacta de los negocios.
    $c = N::en('global', static fn () => ConsolidadoFinanciero::armar($rango));
    $assert(array_keys($c['negocios']) === ['restaurante', 'panaderia'], 'Global lee los dos negocios, en orden.');
    foreach (['ingresos', 'cogs', 'merma', 'gastos', 'utilidadNeta'] as $k) {
        $suma = $c['negocios']['restaurante']['periodo'][$k] + $c['negocios']['panaderia']['periodo'][$k];
        $assert($casi($c['total'][$k], $suma), "El total de {$k} es la suma de los negocios.");
    }
    foreach (['ingresos', 'cogs', 'merma', 'utilidadBruta', 'utilidadNeta'] as $k) {
        $suma = $c['negocios']['restaurante']['periodo']['previo'][$k] + $c['negocios']['panaderia']['periodo']['previo'][$k];
        $assert($casi($c['total']['previo'][$k], $suma), "El periodo anterior de {$k} también se suma.");
    }
    $res = F::resumen($db, 'restaurante', $unidad['restaurante'], $rango);
    $assert($casi($c['negocios']['restaurante']['periodo']['utilidadNeta'], $res['utilidadNeta']), 'Global ve la misma utilidad que la pantalla del negocio.');
    $assert($casi($c['total']['inventarioGlobal'], F::inventarioValor($db, $unidad['global'])), 'El inventario de Global va aparte.');
    $assert($casi($c['total']['inventarioTotal'], $c['total']['inventarioValor'] + $c['total']['inventarioGlobal']), 'El inventario total incluye el de Global.');
    if ($c['total']['ingresos'] > 0) {
        $assert($casi((float) $c['total']['margenNeto'], $c['total']['utilidadNeta'] / $c['total']['ingresos'] * 100), 'El margen total sale de los totales.');
        $assert($casi((float) $c['total']['participacion']['restaurante']['ventas'] + (float) $c['total']['participacion']['panaderia']['ventas'], 100.0), 'La participación en ventas suma 100 %.');
    }
    $assert($casi($c['hoy']['ventas'], $c['negocios']['restaurante']['hoy']['ventas'] + $c['negocios']['panaderia']['hoy']['ventas']), 'El pulso de hoy total suma los dos negocios.');
    foreach ($c['negocios'] as $n) {
        foreach ($n['alertas'] as $a) {
            $assert(in_array($a['estado'], ['bad', 'warn'], true), 'Una alerta es un signo fuera de lo sano.');
        }
    }

    // 4. Gastos fijos por negocio, aislados.
    N::en('panaderia', static function () use ($assert, $uno, $unidad): void {
        $g = new GastoFijo();
        $g->sincronizar(['nombre' => 'QA renta panadería', 'categoria' => 'renta', 'monto' => '1500', 'activo' => 1]);
        // Dentro de la transacción de la prueba el motor deja el guardado como
        // pendiente de confirmar (devuelve false): lo que se comprueba es la fila.
        $assert($g->validar() === [], 'El gasto es válido.');
        $g->guardar();
        $assert((int) $g->id > 0, 'Panadería guarda su gasto fijo.');
        $assert((int) $uno('SELECT unidad_id FROM gastos_fijos WHERE id = ?', [(int) $g->id]) === $unidad['panaderia'], 'El gasto queda en Panadería.');
        $assert(GastoFijo::find((int) $g->id) !== null, 'Panadería lo encuentra.');
    });
    $deRest = (int) $uno('SELECT id FROM gastos_fijos WHERE unidad_id = ? LIMIT 1', [$unidad['restaurante']]);
    if ($deRest > 0) {
        $assert(N::en('panaderia', static fn () => GastoFijo::find($deRest)) === null, 'Un gasto de Restaurante no existe en Panadería.');
    }
    $assert($codigo(static fn () => N::en('global', static fn () => GastoFijo::todos())) === 'AMBITO_NO_DISPONIBLE', 'Global no tiene gastos fijos propios.');

    // 5. Permisos del consolidado.
    $cuenta = static function (string $nombre, array $permisos) use ($db, $unidad): int {
        $db->prepare("INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES (?, ?, 'sin-acceso', 1)")
            ->execute(['qa_fin_' . $nombre . '_' . bin2hex(random_bytes(3)), 'QA ' . $nombre]);
        $id = (int) $db->insert_id;
        foreach ($permisos as $slug => $modulos) {
            $db->prepare('INSERT INTO usuario_unidades (usuario_id, unidad_id, activo) VALUES (?, ?, 1)')->execute([$id, $unidad[$slug]]);
            foreach ($modulos as $modulo => $nivel) {
                $db->prepare('INSERT INTO usuario_unidad_permisos (usuario_id, unidad_id, modulo, nivel) VALUES (?, ?, ?, ?)')->execute([$id, $unidad[$slug], $modulo, $nivel]);
            }
        }
        return $id;
    };
    $lectorGlobal = $cuenta('glo', ['global' => ['finanzas' => 'ver']]);
    $soloPan = $cuenta('pan', ['panaderia' => ['finanzas' => 'ver']]);

    $como($lectorGlobal);
    $assert(isset(N::en('global', static fn () => ConsolidadoFinanciero::armar($rango))['total']), 'Finanzas en Global basta para el consolidado.');
    $assert($codigo(static fn () => N::en('panaderia', static fn () => GastoFijo::todos())) === 'AMBITO_DENEGADO', 'El consolidado no abre los gastos de un negocio.');

    $como($soloPan);
    $assert($codigo(static fn () => N::en('global', static fn () => ConsolidadoFinanciero::armar($rango))) === 'AMBITO_DENEGADO', 'Sin Global no hay consolidado.');
    $assert(is_array(N::en('panaderia', static fn () => GastoFijo::todos())), 'Panadería lee sus gastos con su permiso.');
    $assert($codigo(static function () {
        return N::en('panaderia', static function () {
            $g = new GastoFijo();
            $g->sincronizar(['nombre' => 'QA sin permiso', 'categoria' => 'otros', 'monto' => '1', 'activo' => 1]);
            return $g->guardar();
        });
    }) === 'PERMISO_DENEGADO', 'Con sólo ver no se guarda un gasto.');
} finally {
    $db->rollback();
    $db->autocommit(true);
    $_SESSION = [];
}

echo "Finanzas de Panadería y Global: {$assertions} comprobaciones OK\n";
