<?php

declare(strict_types=1);

/*
 * Contrato del diagnóstico de Finanzas (Services\Finance\DiagnosticoFinanciero).
 *
 * No toca la base: el servicio recibe agregados y devuelve textos y
 * geometría, así que aquí se le dan números inventados, incluidos los raros
 * que la pantalla tiene que aguantar sin romperse ni mentir: periodo sin
 * ventas, sin gastos fijos, margen bruto negativo, un solo día, ganancia.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Finance\DiagnosticoFinanciero as D;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$texto = static fn (?array $partes): string => implode('', array_map(static fn ($p) => $p['t'], $partes ?? []));
$paso = static function (array $diag, string $clave): array {
    foreach ($diag['cascada']['pasos'] as $p) {
        if ($p['clave'] === $clave) {
            return $p;
        }
    }
    throw new RuntimeException("Sin paso {$clave}");
};
$signo = static function (array $diag, string $clave): array {
    foreach ($diag['signos'] as $s) {
        if ($s['clave'] === $clave) {
            return $s;
        }
    }
    throw new RuntimeException("Sin signo {$clave}");
};

// 1 · Formato: el signo va delante del símbolo, con el menos tipográfico.
$assert(D::dinero(-216738.95, 2) === '−$216,738.95', 'Negativo: «−$216,738.95», no «$-216,738.95».');
$assert(D::dinero(-0.004, 2) === '$0.00', 'Un valor que redondea a cero no lleva signo.');
$assert(D::dinero(31832) === '$31,832', 'Positivo sin decimales.');
$assert(D::dineroConSigno(1139.4) === '+$1,139', 'Diferencia positiva con +.');
$assert(D::porcentaje(-680.94) === '−680.9%', 'Porcentaje negativo con menos tipográfico.');

// 2 · El caso de la captura: pérdida por gastos fijos 7.6 veces la venta.
$base = [
    'ingresos' => 31832.0, 'cogs' => 6950.95, 'merma' => 220.0, 'gastos' => 241400.0,
    'gastosPorCategoria' => ['renta' => 90000.0, 'servicios' => 31400.0, 'nomina' => 120000.0],
    'dias' => 30, 'tickets' => 312, 'propinas' => 1500.0, 'inventarioValor' => 88073.9,
    'ingresosSinCosto' => 0.0, 'comparando' => true, 'periodoAnterior' => 'Del 21/08/2026 al 19/09/2026',
    'previo' => ['ingresos' => 0.0, 'cogs' => 0.0, 'merma' => 48.0, 'tickets' => 0],
];
$d = D::armar($base);
$v = $d['veredicto'];
$assert($v['tono'] === 'bad' && $v['estado'] === 'En pérdida', 'Pérdida: tono y estado.');
$assert($v['cifra'] === '$216,739', 'La cifra del veredicto es la magnitud redondeada.');
$assert(str_contains($texto($v['razon']), '7.6 veces'), 'La razón dice cuántas veces los fijos superan la venta.');
$assert(str_contains($texto($v['palanca']), 'al día'), 'La palanca dice cuánto vender al día.');
$assert($d['cascada']['seleccion'] === 'fijos', 'Se abre explicado el escalón que causa la pérdida.');
$assert($d['cascada']['hayNegativo'] === true, 'Con pérdida, el eje baja de cero.');
$neta = $paso($d, 'neta');
$assert($neta['tono'] === 'negativo' && $neta['montoTexto'] === '−$216,738.95', 'La neta va en negativo y con el formato pedido.');
$assert($neta['nodos'] === ['Faltante'], 'La neta negativa resalta el «Faltante» del Sankey.');
$fijos = $paso($d, 'fijos');
$assert($fijos['cambio'] === null, 'Los gastos fijos no se comparan entre periodos.');
$assert(count($fijos['reparto']) === 3 && $fijos['reparto'][0]['nombre'] === 'Nómina', 'El desglose va de mayor a menor.');
foreach ($d['cascada']['pasos'] as $p) {
    $assert($p['ini'] >= 0 && $p['fin'] <= 100 && $p['ini'] <= $p['fin'], "Barra {$p['clave']} dentro del eje.");
}
$eq = $signo($d, 'equilibrio');
$assert($eq['estado'] === 'bad' && $eq['medidor']['valor'] < $eq['medidor']['meta'], 'Equilibrio: por debajo, el relleno no llega a la marca.');
$inv = $signo($d, 'inventario');
$assert($inv['estado'] === 'bad' && $inv['medidor']['desborda'] === true, 'Días de inventario fuera de escala.');
$assert($signo($d, 'insumos')['estado'] === 'good', 'Insumos al 21.8%: bajo la referencia, sano.');
$assert($signo($d, 'merma')['estado'] === 'good', 'Merma al 3%: por debajo de lo habitual.');
$assert($d['cascada']['hayPrevio'] === false, 'Un periodo anterior sin ventas no se compara renglón a renglón.');
$assert(str_contains($texto($v['comparativo']), 'no tiene ventas'), 'El veredicto dice que no hay con qué comparar.');
$assert($paso($d, 'ingresos')['secundario'] === '312 tickets', 'Los ingresos dicen cuántos tickets, no «100% del ingreso».');

// 2b · Merma en cero: sin registro, no «baja».
$sinMerma = D::armar(array_merge($base, ['merma' => 0.0]));
$assert($signo($sinMerma, 'merma')['estado'] === 'neutral', 'Merma en cero no se pinta de verde.');

// 3 · Periodo vacío: ni ventas, ni consumo, ni gastos.
$vacio = D::armar(['dias' => 7]);
$assert($vacio['veredicto']['estado'] === 'Sin ventas' && $vacio['veredicto']['tono'] === 'neutral', 'Vacío: estado neutral.');
$assert($vacio['cascada']['vacia'] === true, 'Vacío: la cascada sabe que no tiene eje.');
foreach ($vacio['signos'] as $s) {
    $assert($s['estado'] === 'neutral' && $s['medidor'] === null, "Vacío: {$s['clave']} sin medidor ni semáforo.");
}

// 4 · Sin ventas pero con gastos fijos: la pérdida es el fijo.
$sinVentas = D::armar(['dias' => 1, 'gastos' => 8046.67, 'gastosPorCategoria' => ['renta' => 8046.67]]);
$assert($sinVentas['veredicto']['tono'] === 'bad', 'Sin ventas con fijos: tono de pérdida.');
$assert(str_contains($texto($sinVentas['veredicto']['razon']), 'un día'), 'Un día se dice en singular.');
$assert($sinVentas['cascada']['seleccion'] === 'fijos', 'Sin ventas con fijos se explican los fijos.');

// 5 · Margen bruto negativo: el problema está antes de la renta.
$negativo = D::armar(['ingresos' => 1000.0, 'cogs' => 1300.0, 'gastos' => 500.0, 'dias' => 3, 'tickets' => 10]);
$assert($negativo['cascada']['seleccion'] === 'insumos', 'Margen bruto negativo: se explican los insumos.');
$assert($signo($negativo, 'equilibrio')['estadoTexto'] === 'Inalcanzable', 'Sin margen no hay equilibrio posible.');
$assert($paso($negativo, 'bruta')['tono'] === 'negativo', 'Utilidad bruta negativa en rojo.');

// 6 · Ganancia sin gastos fijos registrados.
$gana = D::armar(['ingresos' => 100000.0, 'cogs' => 30000.0, 'merma' => 2000.0, 'dias' => 30, 'tickets' => 800]);
$assert($gana['veredicto']['tono'] === 'good' && $gana['cascada']['seleccion'] === 'neta', 'Ganancia: se abre la neta.');
$assert(str_contains($texto($gana['veredicto']['palanca']), 'No hay gastos fijos'), 'Sin fijos lo advierte en vez de calcular equilibrio.');
$assert($gana['cascada']['hayNegativo'] === false, 'Sin pérdida, el eje empieza en cero.');
$assert($paso($gana, 'neta')['tono'] === 'positivo', 'Neta positiva en verde.');

// 7 · Ventas sin receta costeada: el costo de insumos sale incompleto.
$sinCosto = D::armar(array_merge($base, ['ingresosSinCosto' => 9000.0]));
$assert($signo($sinCosto, 'insumos')['estadoTexto'] === 'Incompleto', 'Más de 5% sin costo: indicador incompleto.');

// 8 · Comparación con datos: la variación sale con signo y bueno/malo.
$compara = D::armar(array_merge($base, ['previo' => ['ingresos' => 28000.0, 'cogs' => 5800.0, 'merma' => 100.0, 'tickets' => 280]]));
$ing = $paso($compara, 'ingresos');
$assert($ing['cambio'] !== null && $ing['cambio']['bueno'] === true && str_starts_with($ing['cambio']['texto'], '+'), 'Más ventas: variación positiva y buena.');
$ins = $paso($compara, 'insumos');
$assert($ins['cambio']['bueno'] === false, 'Más costo de insumos: variación mala aunque suba.');
$assert(str_contains($texto($compara['veredicto']['comparativo']), 'perdiste'), 'El comparativo habla del resultado.');

fwrite(STDOUT, "PASS: diagnóstico financiero ({$assertions} comprobaciones).\n");
