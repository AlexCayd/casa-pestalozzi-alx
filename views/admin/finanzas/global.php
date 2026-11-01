<?php
require_once __DIR__ . '/../partials/_icons.php';

use Services\Finance\DiagnosticoFinanciero;
use Services\Shared\RangoPeriodo;

/*
 * Finanzas de Global: cómo van los dos negocios, para el dueño. Sólo lectura:
 * Global no vende ni tiene gastos propios, y esta pantalla no ofrece ningún
 * formulario.
 *
 * Se lee en tres alturas, de lo urgente a lo estructural:
 *   1. Hoy: lo cobrado por cada negocio contra el mismo día de la semana
 *      pasada a esta misma hora.
 *   2. El periodo elegido, con los negocios lado a lado en una tabla: el
 *      veredicto de cada uno arriba de su columna y la organización al final,
 *      como la suma que es.
 *   3. El consolidado (participación e inventario) y lo que pide atención.
 *
 * Todas las columnas siguen el mismo orden en las tres alturas —Restaurante,
 * Panadería, Organización— para que el ojo no tenga que reubicarse.
 */

$rango = is_array($rango ?? null) ? $rango : [];
$periodoLabel = (string) ($rango['label'] ?? 'Últimos 30 días');
$consolidado = is_array($consolidado ?? null) ? $consolidado : null;
$enlacesNegocio = is_array($enlacesNegocio ?? null) ? $enlacesNegocio : [];

$e = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
// Pesos enteros: es un resumen para decidir, no un corte de caja. El formato
// (signo menos tipográfico delante del símbolo) es el del servicio.
$money = static fn ($v): string => DiagnosticoFinanciero::dinero((float) $v);
$pct = static fn (?float $v, int $dec = 1): string => $v === null ? '—' : DiagnosticoFinanciero::porcentaje($v, $dec);

// Mismo pintado de «partes» que index.php: el servicio arma el texto y la
// vista sólo decide qué va en negrita o enlazado.
$partes = static function (?array $lista) use ($e): string {
    $html = '';
    foreach ($lista ?? [] as $parte) {
        $texto = $e($parte['t'] ?? '');
        if (!empty($parte['href'])) {
            $texto = '<a class="fin-enlace fin-enlace--linea" href="' . $e($parte['href']) . '">' . $texto . '</a>';
        } elseif (!empty($parte['fuerte'])) {
            $texto = '<strong>' . $texto . '</strong>';
        }
        $html .= $texto;
    }
    return $html;
};

$iconoEstado = static fn (string $tono): string => admin_icon(
    $tono === 'good' ? 'check' : ($tono === 'neutral' ? 'info' : 'alerta'),
    14,
    'fin-estado__icono'
);

/*
 * Variación contra la referencia, con la misma regla que la cascada de cada
 * negocio: en porcentaje mientras la base sea positiva y la cifra no cruce el
 * cero; en pesos para la utilidad neta, donde «de −$100 a −$50 = −50 %» se
 * leería al revés. Sin base no se compara: null y no se pinta nada.
 */
$cambio = static function (float $actual, ?float $antes, bool $subirEsBueno, bool $enPesos = false): ?array {
    if ($antes === null) {
        return null;
    }
    $dif = $actual - $antes;
    if ($enPesos || $actual < 0) {
        if (!$enPesos && $antes <= 0) {
            return null;
        }
        $texto = abs($dif) < 0.5 ? 'igual' : DiagnosticoFinanciero::dineroConSigno($dif);
    } else {
        $delta = RangoPeriodo::delta($actual, $antes);
        if ($delta === null) {
            return null;
        }
        $texto = abs($delta) < 0.05 ? 'igual' : DiagnosticoFinanciero::porcentajeConSigno($delta);
    }
    $igual = $texto === 'igual';

    return [
        'texto' => $texto,
        'sube' => $dif >= 0,
        'bueno' => $igual ? null : (($dif > 0) === $subirEsBueno),
    ];
};

$pintaCambio = static function (?array $c, string $contra = 'contra el periodo anterior') use ($e): string {
    if ($c === null) {
        return '';
    }
    $clase = $c['bueno'] === null ? 'igual' : ($c['bueno'] ? 'bueno' : 'malo');
    $icono = $c['bueno'] === null ? '' : admin_icon($c['sube'] ? 'sube' : 'baja', 13, 'fin-delta__icono');

    return '<span class="fin-delta fin-delta--' . $clase . '">' . $icono . $e($c['texto'])
        . '<span class="admin-visually-hidden"> ' . $e($contra) . '</span></span>';
};

// «el sábado pasado a las 14:05»: la comparación de hoy sólo es justa a la
// misma hora, y eso hay que decirlo con la hora.
$diasLargos = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$horaAhora = date('H:i');
$diaPasado = 'el ' . $diasLargos[(int) date('w')] . ' pasado';

$negocios = $consolidado['negocios'] ?? [];
$total = $consolidado['total'] ?? [];
$hoyTotal = $consolidado['hoy'] ?? [];

/*
 * Panadería todavía no cobra en el sistema (su caja está pendiente). Sin un
 * solo ticket hoy, en el periodo ni en el anterior, y sin cuentas abiertas,
 * sus ceros no son un mal mes: son ausencia de registro. Se dice así y sus
 * cifras de venta salen como «—», no como $0 ni como márgenes imposibles.
 */
$sinCaja = [];
foreach ($negocios as $slug => $n) {
    $sinCaja[$slug] = $slug === 'panaderia'
        && (int) ($n['periodo']['tickets'] ?? 0) === 0
        && (int) ($n['periodo']['previo']['tickets'] ?? 0) === 0
        && (int) ($n['hoy']['tickets'] ?? 0) === 0
        && (int) ($n['hoy']['abiertas'] ?? 0) === 0;
}
$textoSinCaja = 'La caja de Panadería todavía no registra tickets.';
?>
<section class="admin-finanzas admin-page fin-global">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas · Organización</span>
            <h2 class="admin-page__title">Cómo van los negocios</h2>
            <p class="admin-page__subtitle"><?php echo $e($periodoLabel); ?>: Restaurante y Panadería lado a lado, y lo que suman. Sólo consulta.</p>
        </div>
        <div class="admin-actions">
            <?php include __DIR__ . '/../partials/_range-picker.php'; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php if ($consolidado === null || $negocios === []) : ?>
        <?php /* Sin datos (error o sin permiso): un estado vacío que dice qué
                 pasó y a dónde se puede ir, en vez de una tabla de ceros. */ ?>
        <section class="admin-panel fin-gl-vacio" aria-labelledby="fin-gl-vacio-titulo">
            <h3 class="fin-gl-vacio__titulo" id="fin-gl-vacio-titulo">No hay cifras de la organización que mostrar</h3>
            <p class="fin-gl-vacio__texto">El aviso de arriba explica por qué. Las finanzas de cada negocio se consultan en su propia pantalla, si tienes acceso.</p>
            <?php if ($enlacesNegocio !== []) : ?>
                <div class="fin-gl-vacio__acciones">
                    <?php foreach ($enlacesNegocio as $slug => $url) : ?>
                        <a class="fin-enlace" href="<?php echo $e($url); ?>">
                            Ver finanzas de <?php echo $e($slug === 'panaderia' ? 'Panadería' : ucfirst((string) $slug)); ?><?php echo admin_icon('flecha-derecha', 15, 'fin-enlace__icono'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php else : ?>

    <?php /*
      ── 1 · HOY ──────────────────────────────────────────────────────────
      Lo cobrado al momento de abrir la página. La cifra va en la cara de
      indicador; la comparación, debajo y en pequeño, siempre con su día y su
      hora para que nadie compare una mañana con un día entero.
    */ ?>
    <section class="admin-panel fin-hoy" aria-labelledby="fin-hoy-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="fin-hoy-titulo">Hoy</h3>
                <p>Corte a las <?php echo $e($horaAhora); ?>, contra <?php echo $e($diaPasado); ?> hasta la misma hora.</p>
            </div>
        </div>
        <ul class="fin-hoy__lista" role="list">
            <?php
                $celdasHoy = [];
                foreach ($negocios as $slug => $n) {
                    $celdasHoy[] = ['slug' => $slug, 'nombre' => $n['nombre'], 'hoy' => $n['hoy'], 'total' => false];
                }
                $celdasHoy[] = ['slug' => 'organizacion', 'nombre' => 'Organización', 'hoy' => $hoyTotal, 'total' => true];
            ?>
            <?php foreach ($celdasHoy as $celda) : ?>
                <?php
                    $h = $celda['hoy'];
                    $ventasHoy = (float) ($h['ventas'] ?? 0);
                    $pasada = (float) ($h['ventasSemanaPasada'] ?? 0);
                    $ticketsHoy = (int) ($h['tickets'] ?? 0);
                    $abiertas = (int) ($h['abiertas'] ?? 0);
                    $esSinCaja = !empty($sinCaja[$celda['slug']]);
                ?>
                <li class="fin-hoy__celda<?php echo $celda['total'] ? ' fin-hoy__celda--total' : ''; ?>">
                    <h4 class="fin-hoy__nombre"><?php echo $e($celda['nombre']); ?></h4>
                    <?php if ($esSinCaja) : ?>
                        <p class="fin-hoy__pendiente">
                            <span class="fin-estado fin-estado--neutral"><?php echo $iconoEstado('neutral'); ?>Caja pendiente</span>
                        </p>
                        <p class="fin-hoy__nota"><?php echo $e($textoSinCaja); ?></p>
                    <?php else : ?>
                        <p class="fin-hoy__cifra"><?php echo $e($money($ventasHoy)); ?></p>
                        <p class="fin-hoy__rotulo">
                            cobrado hoy · <span class="admin-num"><?php echo $ticketsHoy; ?></span> <?php echo $ticketsHoy === 1 ? 'ticket' : 'tickets'; ?>
                            <?php if (!$celda['total'] && ($h['ticketPromedio'] ?? null) !== null) : ?>
                                · promedio <span class="admin-num"><?php echo $e($money($h['ticketPromedio'])); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="fin-hoy__contra">
                            <?php if ($pasada > 0) : ?>
                                <?php echo $pintaCambio($cambio($ventasHoy, $pasada, true), 'contra ' . $diaPasado . ' a esta hora'); ?>
                                <span>contra <span class="admin-num"><?php echo $e($money($pasada)); ?></span> <?php echo $e($diaPasado); ?></span>
                            <?php elseif ($ventasHoy > 0) : ?>
                                <?php echo $e(ucfirst($diaPasado)); ?> a esta hora no había cobros.
                            <?php else : ?>
                                <?php echo $e(ucfirst($diaPasado)); ?> a esta hora tampoco había cobros.
                            <?php endif; ?>
                        </p>
                        <p class="fin-hoy__abiertas">
                            <?php if ($abiertas > 0) : ?>
                                <span class="admin-num"><?php echo $abiertas; ?></span> <?php echo $abiertas === 1 ? 'cuenta abierta' : 'cuentas abiertas'; ?>
                                que <?php echo $abiertas === 1 ? 'lleva' : 'llevan'; ?> <span class="admin-num"><?php echo $e($money($h['enCurso'] ?? 0)); ?></span>
                            <?php else : ?>
                                Sin cuentas abiertas
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php /*
      ── 2 · EL PERIODO, LADO A LADO ──────────────────────────────────────
      Una tabla de verdad (encabezados de fila y de columna) y no tarjetas:
      lo que se hace aquí es comparar en horizontal, y es lo que una tabla
      anuncia bien al lector de pantalla. El veredicto de cada negocio es el
      primer renglón, encima de sus cifras; la columna Organización va en una
      hoja más oscura porque es la suma, no un tercer negocio.
    */ ?>
    <?php
        // Columnas: cada negocio con sus cifras y la organización con las que
        // suma el servicio, periodo anterior incluido (ConsolidadoFinanciero
        // suma, nunca promedia). Los días de inventario no se suman: «—».
        $columnas = [];
        foreach ($negocios as $slug => $n) {
            $p = $n['periodo'];
            $columnas[$slug] = [
                'nombre' => $n['nombre'], 'total' => false, 'sinCaja' => !empty($sinCaja[$slug]),
                'p' => $p, 'previo' => $p['previo'] ?? [],
            ];
        }
        $columnas['organizacion'] = [
            'nombre' => 'Organización', 'total' => true, 'sinCaja' => false,
            'p' => $total + ['diasInventario' => null],
            'previo' => is_array($total['previo'] ?? null) ? $total['previo'] : [],
        ];

        /*
         * Cada renglón dice qué cifra pinta, qué la acompaña y si se compara.
         * 'venta' marca las que dependen de que haya ventas: en una caja que
         * aún no registra tickets salen como «—».
         */
        $renglones = [
            'resultados' => [
                ['rotulo' => 'Ventas', 'campo' => 'ingresos', 'previo' => 'ingresos', 'sube' => true, 'venta' => true],
                ['rotulo' => 'Costo de insumos', 'campo' => 'cogs', 'previo' => 'cogs', 'sube' => false, 'venta' => true,
                    'sec' => static fn (array $p): string => $p['insumosPct'] === null ? '' : DiagnosticoFinanciero::porcentaje((float) $p['insumosPct']) . ' de la venta'],
                ['rotulo' => 'Merma', 'campo' => 'merma', 'previo' => 'merma', 'sube' => false, 'venta' => false],
                ['rotulo' => 'Utilidad bruta', 'campo' => 'utilidadBruta', 'previo' => 'utilidadBruta', 'sube' => true, 'venta' => true, 'signo' => true,
                    'sec' => static fn (array $p): string => $p['margenBruto'] === null ? '' : DiagnosticoFinanciero::porcentaje((float) $p['margenBruto']) . ' de margen'],
                ['rotulo' => 'Gastos fijos', 'campo' => 'gastos', 'previo' => null, 'venta' => false],
                ['rotulo' => 'Utilidad neta', 'campo' => 'utilidadNeta', 'previo' => 'utilidadNeta', 'sube' => true, 'venta' => false, 'signo' => true, 'pesos' => true, 'fuerte' => true],
                ['rotulo' => 'Margen neto', 'campo' => 'margenNeto', 'tipo' => 'pct', 'previo' => null, 'venta' => true, 'signo' => true],
            ],
            'operacion' => [
                ['rotulo' => 'Ticket promedio', 'campo' => 'ticketPromedio', 'previo' => 'ticketPromedio', 'sube' => true, 'venta' => true],
                ['rotulo' => 'Tickets', 'campo' => 'tickets', 'tipo' => 'entero', 'previo' => 'tickets', 'sube' => true, 'venta' => true],
                ['rotulo' => 'Días de inventario', 'campo' => 'diasInventario', 'tipo' => 'dias', 'previo' => null, 'venta' => true],
            ],
        ];
        $gruposRotulo = ['resultados' => 'Estado de resultados', 'operacion' => 'Operación'];
        $prevLabel = (string) ($rango['prevLabel'] ?? '');
    ?>
    <section class="admin-panel fin-lado" aria-labelledby="fin-lado-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="fin-lado-titulo">El periodo, lado a lado</h3>
                <p>
                    <?php echo $e($periodoLabel); ?>. Bajo cada cifra, su variación contra el periodo anterior<?php echo $prevLabel !== '' ? ' (' . $e(mb_strtolower(mb_substr($prevLabel, 0, 1)) . mb_substr($prevLabel, 1)) . ')' : ''; ?>.
                </p>
            </div>
        </div>

        <p class="fin-lado__pista">Desliza la tabla hacia un lado para ver Panadería y la organización.</p>
        <?php /* data-scrollable: en móvil la tabla se recorre en horizontal
                 dentro de su caja, con la columna de rótulos fija. Es región
                 enfocable para que también se desplace con las flechas: la
                 columna Organización no tiene ningún enlace que la traiga a la
                 vista con el tabulador. */ ?>
        <div class="admin-table-wrap fin-lado__caja" data-scrollable tabindex="0" role="region" aria-labelledby="fin-lado-titulo">
            <table class="admin-table fin-lado__tabla">
                <caption class="admin-visually-hidden">Estado de resultados de cada negocio y de la organización, <?php echo $e($periodoLabel); ?></caption>
                <thead>
                    <tr>
                        <th scope="col" class="fin-lado__esquina"><span class="admin-visually-hidden">Concepto</span></th>
                        <?php foreach ($columnas as $col) : ?>
                            <th scope="col" class="fin-lado__col<?php echo $col['total'] ? ' fin-lado__col--total' : ''; ?>"><?php echo $e($col['nombre']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="fin-lado__veredictos">
                    <tr>
                        <th scope="row">Veredicto</th>
                        <?php foreach ($columnas as $slug => $col) : ?>
                            <td class="fin-lado__veredicto<?php echo $col['total'] ? ' fin-lado__col--total' : ''; ?>">
                                <?php if ($col['total']) : ?>
                                    <p class="fin-lado__suma">Suma de los dos negocios. Global no vende ni tiene gastos propios.</p>
                                <?php elseif ($col['sinCaja']) : ?>
                                    <span class="fin-estado fin-estado--neutral"><?php echo $iconoEstado('neutral'); ?>Caja pendiente</span>
                                    <p class="fin-lado__frase"><?php echo $e($textoSinCaja); ?></p>
                                <?php else : ?>
                                    <?php $v = $negocios[$slug]['veredicto']; ?>
                                    <?php if (($v['estado'] ?? '') !== '') : ?>
                                        <span class="fin-estado fin-estado--<?php echo $e($v['tono']); ?>"><?php echo $iconoEstado((string) $v['tono']); ?><?php echo $e($v['estado']); ?></span>
                                    <?php endif; ?>
                                    <p class="fin-lado__frase">
                                        <?php echo $e($v['antes'] ?? ''); ?>
                                        <?php if (($v['cifra'] ?? '') !== '') : ?>
                                            <strong class="fin-lado__cifra fin-lado__cifra--<?php echo $e($v['tono']); ?>"><?php echo $e($v['cifra']); ?></strong>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!$col['total'] && isset($enlacesNegocio[$slug])) : ?>
                                    <a class="fin-enlace" href="<?php echo $e($enlacesNegocio[$slug]); ?>">
                                        Ver finanzas<span class="admin-visually-hidden"> de <?php echo $e($col['nombre']); ?></span><?php echo admin_icon('flecha-derecha', 15, 'fin-enlace__icono'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
                <?php foreach ($renglones as $grupo => $filas) : ?>
                    <tbody class="fin-lado__grupo">
                        <tr class="fin-lado__grupo-rotulo">
                            <th scope="rowgroup" colspan="<?php echo count($columnas) + 1; ?>"><?php echo $e($gruposRotulo[$grupo]); ?></th>
                        </tr>
                        <?php foreach ($filas as $fila) : ?>
                            <tr<?php echo !empty($fila['fuerte']) ? ' class="fin-lado__fila--fuerte"' : ''; ?>>
                                <th scope="row"><?php echo $e($fila['rotulo']); ?></th>
                                <?php foreach ($columnas as $col) : ?>
                                    <?php
                                        $p = $col['p'];
                                        $valor = $p[$fila['campo']] ?? null;
                                        $tipo = $fila['tipo'] ?? 'dinero';
                                        $clasesCelda = 'admin-num' . ($col['total'] ? ' fin-lado__col--total' : '');
                                        $vacio = $valor === null || ($col['sinCaja'] && !empty($fila['venta']));
                                    ?>
                                    <td class="<?php echo $clasesCelda; ?>">
                                        <?php if ($vacio) : ?>
                                            <span class="fin-lado__vacio" aria-hidden="true">—</span>
                                            <span class="admin-visually-hidden"><?php echo $col['sinCaja'] ? 'Sin ventas registradas' : 'Sin dato'; ?></span>
                                        <?php else : ?>
                                            <?php
                                                $num = (float) $valor;
                                                $texto = match ($tipo) {
                                                    'pct' => DiagnosticoFinanciero::porcentaje($num),
                                                    'entero' => number_format((int) $num),
                                                    'dias' => number_format($num, 0) . ' ' . ((int) round($num) === 1 ? 'día' : 'días'),
                                                    default => $money($num),
                                                };
                                                // El rojo sólo dice «pierde», y sólo donde hay ventas
                                                // que lo sostengan: los gastos de una caja que aún no
                                                // cobra no son una pérdida de operación.
                                                $negativo = !empty($fila['signo']) && $num < 0 && !$col['sinCaja'];
                                            ?>
                                            <span class="fin-lado__valor<?php echo $negativo ? ' is-negativo' : ''; ?>"><?php echo $e($texto); ?></span>
                                            <?php if (isset($fila['sec']) && ($sec = ($fila['sec'])($p)) !== '') : ?>
                                                <span class="fin-lado__sec"><?php echo $e($sec); ?></span>
                                            <?php elseif ($col['sinCaja'] && $fila['campo'] === 'utilidadNeta') : ?>
                                                <span class="fin-lado__sec">sólo costos, sin ventas</span>
                                            <?php endif; ?>
                                            <?php if (!$col['sinCaja'] && ($fila['previo'] ?? null) !== null) : ?>
                                                <?php
                                                    $antes = $col['previo'][$fila['previo']] ?? null;
                                                    $c = $cambio($num, $antes === null ? null : (float) $antes, (bool) $fila['sube'], !empty($fila['pesos']));
                                                ?>
                                                <?php if ($c !== null) : ?>
                                                    <span class="fin-lado__cambio"><?php echo $pintaCambio($c); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <p class="fin-lado__nota">
            Los gastos fijos son mensuales y se prorratean a los días del periodo; no llevan variación porque no tienen
            fecha. Los días de inventario usan las existencias de hoy. Las referencias de cada negocio, con sus
            fuentes, están en su pantalla de Finanzas.
        </p>
    </section>

    <?php /*
      ── 3 · ATENCIÓN Y CONSOLIDADO ───────────────────────────────────────
      Primero lo que pide atención en cada negocio (los signos vitales fuera
      de lo sano, con la misma frase que en su pantalla): es lo accionable.
      Después el consolidado, que es estructura y cambia despacio.
    */ ?>
    <section class="admin-panel fin-atencion" aria-labelledby="fin-atencion-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="fin-atencion-titulo">Pide atención</h3>
                <p>Los indicadores de cada negocio que se salen de lo sano en el periodo, lo más grave primero.</p>
            </div>
        </div>
        <div class="fin-atencion__rejilla">
            <?php foreach ($negocios as $slug => $n) : ?>
                <?php $alertasNegocio = is_array($n['alertas'] ?? null) ? $n['alertas'] : []; ?>
                <article class="fin-atencion__negocio" aria-labelledby="fin-atencion-<?php echo $e($slug); ?>">
                    <h4 class="fin-atencion__nombre" id="fin-atencion-<?php echo $e($slug); ?>"><?php echo $e($n['nombre']); ?></h4>
                    <?php if (!empty($sinCaja[$slug])) : ?>
                        <?php /* Sin ventas no hay signos que medir: decir «todo
                                 sano» aquí sería mentir por omisión. */ ?>
                        <p class="fin-atencion__vacio">
                            <span class="fin-estado fin-estado--neutral"><?php echo $iconoEstado('neutral'); ?>Sin medir</span>
                            <span>Sin ventas registradas todavía no hay indicadores que revisar.</span>
                        </p>
                    <?php elseif ($alertasNegocio === []) : ?>
                        <p class="fin-atencion__vacio">
                            <span class="fin-estado fin-estado--good"><?php echo $iconoEstado('good'); ?>En orden</span>
                            <span>Ningún indicador fuera de lo sano.</span>
                        </p>
                    <?php else : ?>
                        <ul class="fin-atencion__lista" role="list">
                            <?php foreach ($alertasNegocio as $alerta) : ?>
                                <li class="fin-alerta">
                                    <div class="fin-alerta__cabeza">
                                        <span class="fin-alerta__rotulo"><?php echo $e($alerta['rotulo']); ?></span>
                                        <span class="fin-estado fin-estado--<?php echo $e($alerta['estado']); ?>"><?php echo $iconoEstado((string) $alerta['estado']); ?><?php echo $e($alerta['estadoTexto']); ?></span>
                                    </div>
                                    <p class="fin-alerta__valor admin-num"><?php echo $e($alerta['valor']); ?></p>
                                    <?php if (!empty($alerta['frase'])) : ?>
                                        <p class="fin-alerta__frase"><?php echo $partes($alerta['frase']); ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (isset($enlacesNegocio[$slug])) : ?>
                        <a class="fin-enlace fin-atencion__ir" href="<?php echo $e($enlacesNegocio[$slug]); ?>">
                            Ver finanzas de <?php echo $e($n['nombre']); ?><?php echo admin_icon('flecha-derecha', 15, 'fin-enlace__icono'); ?>
                        </a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php /*
      El consolidado: la parte de cada negocio en la venta y en la utilidad, y
      el capital que hay en almacenes (incluido el de Global, que es de la
      organización aunque no lo venda nadie).
    */ ?>
    <?php
        $participacion = $total['participacion'] ?? [];
        $hayVentasOrg = ((float) ($total['ingresos'] ?? 0)) > 0;
        $hayUtilidad = false;
        foreach ($participacion as $part) {
            if (($part['utilidad'] ?? null) !== null) {
                $hayUtilidad = true;
            }
        }
        $quienesPierden = [];
        foreach ($negocios as $slug => $n) {
            if ((float) $n['periodo']['utilidadNeta'] <= 0 && empty($sinCaja[$slug])) {
                $quienesPierden[] = $n['nombre'];
            }
        }
        $tono = 0;
    ?>
    <section class="admin-panel fin-conso" aria-labelledby="fin-conso-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="fin-conso-titulo">Consolidado</h3>
                <p>Qué parte pone cada negocio y cuánto dinero hay en almacenes.</p>
            </div>
        </div>
        <div class="fin-conso__rejilla">
            <div class="fin-conso__bloque">
                <h4 class="fin-conso__titulo">Participación</h4>
                <?php
                    // Dos barras con la misma escala: ventas y utilidad. Grises
                    // del reparto de gastos: la identidad la dice el nombre.
                    $barras = [['clave' => 'ventas', 'rotulo' => 'En las ventas']];
                    if ($hayUtilidad) {
                        $barras[] = ['clave' => 'utilidad', 'rotulo' => 'En la utilidad neta'];
                    }
                ?>
                <?php if (!$hayVentasOrg) : ?>
                    <p class="fin-conso__texto">Sin ventas en el periodo: no hay nada que repartir.</p>
                <?php else : ?>
                    <?php foreach ($barras as $barra) : ?>
                        <div class="fin-parte">
                            <p class="fin-parte__rotulo"><?php echo $e($barra['rotulo']); ?></p>
                            <div class="fin-parte__barra" aria-hidden="true">
                                <?php $tono = 0; ?>
                                <?php foreach ($negocios as $slug => $n) : ?>
                                    <?php $tono++; $valorParte = (float) ($participacion[$slug][$barra['clave']] ?? 0); ?>
                                    <?php if ($valorParte > 0) : ?>
                                        <span class="fin-parte__seg fin-parte__seg--<?php echo $tono; ?>" style="--parte: <?php echo $e(round($valorParte, 2)); ?>"></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <ul class="fin-parte__lista" role="list">
                                <?php $tono = 0; ?>
                                <?php foreach ($negocios as $slug => $n) : ?>
                                    <?php $tono++; $valorParte = $participacion[$slug][$barra['clave']] ?? null; ?>
                                    <li class="fin-parte__item">
                                        <span class="fin-parte__muestra fin-parte__seg--<?php echo $tono; ?>" aria-hidden="true"></span>
                                        <span class="fin-parte__nombre"><?php echo $e($n['nombre']); ?></span>
                                        <?php if (!empty($sinCaja[$slug])) : ?>
                                            <span class="fin-parte__pct fin-parte__pct--tenue">sin ventas registradas</span>
                                        <?php else : ?>
                                            <span class="fin-parte__pct admin-num"><?php echo $e($pct($valorParte === null ? null : (float) $valorParte, 0)); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hayUtilidad) : ?>
                        <p class="fin-conso__texto">
                            <?php if ($quienesPierden !== []) : ?>
                                La utilidad no se reparte: <?php echo $e(implode(' y ', $quienesPierden)); ?> no <?php echo count($quienesPierden) === 1 ? 'tuvo' : 'tuvieron'; ?> utilidad en el periodo, y un porcentaje de una pérdida no se lee.
                            <?php else : ?>
                                La utilidad no se reparte hasta que los dos negocios registren ventas.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="fin-conso__bloque">
                <h4 class="fin-conso__titulo">Capital en inventario</h4>
                <dl class="fin-inv">
                    <?php foreach ($negocios as $slug => $n) : ?>
                        <div class="fin-inv__fila">
                            <dt><?php echo $e($n['nombre']); ?></dt>
                            <dd class="admin-num"><?php echo $e($money($n['periodo']['inventarioValor'] ?? 0)); ?></dd>
                        </div>
                    <?php endforeach; ?>
                    <div class="fin-inv__fila">
                        <dt>Global <span class="fin-inv__aclara">almacenes de la organización</span></dt>
                        <dd class="admin-num"><?php echo $e($money($total['inventarioGlobal'] ?? 0)); ?></dd>
                    </div>
                    <div class="fin-inv__fila fin-inv__fila--total">
                        <dt>Total</dt>
                        <dd class="admin-num"><?php echo $e($money($total['inventarioTotal'] ?? 0)); ?></dd>
                    </div>
                </dl>
                <p class="fin-conso__texto">Existencias de hoy, al costo del proveedor preferente.</p>
            </div>
        </div>
    </section>

    <?php endif; ?>
</section>
