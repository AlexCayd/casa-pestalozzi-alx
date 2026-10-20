<?php
require_once __DIR__ . '/../partials/_icons.php';

use Services\Finance\DiagnosticoFinanciero;

    $rango = is_array($rango ?? null) ? $rango : [];
    $periodoLabel = (string) ($rango['label'] ?? 'Últimos 30 días');
    $ingresos = (float) ($ingresos ?? 0);
    $propinas = (float) ($propinas ?? 0);
    $cogs = (float) ($cogs ?? 0);
    $merma = (float) ($merma ?? 0);
    $utilidadBruta = (float) ($utilidadBruta ?? 0);
    $totalGastos = (float) ($totalGastos ?? 0);
    $utilidadNeta = (float) ($utilidadNeta ?? 0);
    $gastos = isset($gastos) && is_iterable($gastos) ? $gastos : [];
    $gastosPorCategoria = is_array($gastosPorCategoria ?? null) ? $gastosPorCategoria : [];
    $gastosMensuales = is_array($gastosMensuales ?? null) ? $gastosMensuales : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : ['renta', 'servicios', 'nomina', 'insumos', 'otros'];
    $rentabilidad = isset($rentabilidad) && is_iterable($rentabilidad) ? $rentabilidad : [];
    $cortes = isset($cortes) && is_iterable($cortes) ? $cortes : [];
    $totalesCortes = is_array($totalesCortes ?? null) ? $totalesCortes : [];
    $totalesCortes += ['dias' => 0, 'tickets' => 0, 'ventas' => 0.0, 'propinas' => 0.0, 'efectivo' => 0.0, 'tarjeta' => 0.0, 'total' => 0.0];
    $cortesPagina = max(1, (int) ($cortesPagina ?? 1));
    $cortesPaginas = max(1, (int) ($cortesPaginas ?? 1));
    $cortesDesde = max(0, (int) ($cortesDesde ?? 0));
    $diagnostico = is_array($diagnostico ?? null) ? $diagnostico : DiagnosticoFinanciero::armar([]);

    $e = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    // Un solo formato de dinero para toda la pantalla: el signo va DELANTE
    // del símbolo y es el menos tipográfico («−$216,738.95», no «$-216,738.95»).
    $money = static fn ($v): string => DiagnosticoFinanciero::dinero((float) $v, 2);

    /*
     * Pinta las «partes» de texto que arma el servicio: cada trozo se escapa
     * por separado y sólo aquí se decide qué va en negrita o enlazado. Así
     * ningún número del diagnóstico se formatea en la vista.
     */
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

    // El estado lo dicen el icono y la palabra; el color sólo lo confirma.
    $iconoEstado = static fn (string $tono): string => admin_icon(
        $tono === 'good' ? 'check' : ($tono === 'neutral' ? 'info' : 'alerta'),
        14,
        'fin-estado__icono'
    );

    $etiquetaCat = [
        'renta' => 'Renta', 'servicios' => 'Servicios', 'nomina' => 'Nómina',
        'insumos' => 'Insumos', 'otros' => 'Otros',
    ];
    $mesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $diasSemana = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $fechaBonita = static function (string $iso) use ($mesesCortos, $diasSemana): string {
        $ts = strtotime($iso);
        if (!$ts) {
            return $iso;
        }
        return ucfirst($diasSemana[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts) . ' ' . $mesesCortos[(int) date('n', $ts)];
    };
    $hoyIso = date('Y-m-d');

    // Descomposición para el Sankey: de dónde viene el dinero y en qué se va.
    //
    // Un Sankey sólo entiende caudales positivos, así que la utilidad NETA
    // negativa no se puede dibujar como tal. Antes se omitía el tramo y se
    // explicaba con una nota al pie: el diagrama quedaba con la utilidad bruta
    // repartiéndose en gastos y un hueco sin explicar. Ahora ese hueco se dibuja
    // como lo que es —un «Faltante», el dinero que hizo falta y no había— y se
    // pinta en rojo. Es el mismo dato, pero visible donde se está mirando.
    $sankey = [];
    if ($ingresos > 0) {
        if ($cogs > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Costo de insumos', 'flow' => round($cogs, 2)];
        }
        if ($merma > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Merma', 'flow' => round($merma, 2)];
        }
        if ($utilidadBruta > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Utilidad bruta', 'flow' => round($utilidadBruta, 2)];
            foreach ($gastosPorCategoria as $cat => $monto) {
                if ($monto > 0) {
                    $sankey[] = [
                        'from' => 'Utilidad bruta',
                        'to' => $etiquetaCat[$cat] ?? (string) $cat,
                        'flow' => round((float) $monto, 2),
                    ];
                }
            }
            if ($utilidadNeta > 0) {
                $sankey[] = ['from' => 'Utilidad bruta', 'to' => 'Utilidad neta', 'flow' => round($utilidadNeta, 2)];
            } elseif ($utilidadNeta < 0) {
                $sankey[] = [
                    'from' => 'Utilidad bruta',
                    'to' => 'Faltante',
                    'flow' => round(abs($utilidadNeta), 2),
                ];
            }
        }
    }
    // Los nodos que de verdad se dibujan: un escalón de la cascada sólo ofrece
    // «Ver en el flujo» si tiene algo que resaltar allí.
    $nodosSankey = [];
    foreach ($sankey as $flujo) {
        $nodosSankey[$flujo['from']] = true;
        $nodosSankey[$flujo['to']] = true;
    }

    $veredicto = $diagnostico['veredicto'];
    $cascada = $diagnostico['cascada'];
    $signos = $diagnostico['signos'];
    $seleccion = (string) $cascada['seleccion'];
    $comparable = !empty($cascada['hayPrevio']);

    // Los parámetros del periodo viajan en cada enlace de página: sin ellos,
    // pasar a la página 2 de los cortes devolvía la pantalla a 30 días.
    $consultaRango = ((int) ($rango['preset'] ?? 0)) > 0
        ? ['rango' => (int) $rango['preset']]
        : ['desde' => (string) ($rango['start'] ?? ''), 'hasta' => (string) ($rango['end'] ?? '')];
    $consultaRango['comparar'] = '1';
    $cortesUrl = static fn (int $pagina): string => '/admin/finanzas?'
        . http_build_query($consultaRango + ($pagina > 1 ? ['cortes' => $pagina] : []))
        . '#cortes';
?>
<script>
    window.AdminFinanzasData = <?php echo json_encode([
        'sankey' => $sankey,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<section class="admin-finanzas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas</span>
            <h2 class="admin-page__title">Situación financiera</h2>
            <p class="admin-page__subtitle"><?php echo $e($periodoLabel); ?>: qué tal va el negocio, por qué, y qué indicador se sale de lo sano.</p>
        </div>
        <?php /* El selector de periodo se queda solo en el encabezado: mide 82px
                 contra los 44 de un botón y, compartiendo fila, se los comía. Los
                 accesos a Inventario y Recetas, que vivían en una barra aparte,
                 están ahora DENTRO del diagnóstico, junto al indicador que manda
                 a cada uno: «revisar recetas» bajo el costo de insumos, «ver
                 inventario» bajo la merma y los días de existencias. */ ?>
        <div class="admin-actions">
            <?php include __DIR__ . '/../partials/_range-picker.php'; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php /*
      ── DIAGNÓSTICO ──────────────────────────────────────────────────────
      Sustituye a las siete tarjetas de KPI. Se lee en tres alturas: el
      veredicto en una frase (5 segundos), la cascada del estado de resultados
      (qué se comió el dinero) y el detalle del escalón elegido (por qué, contra
      qué referencia y dónde actuar).

      Los escalones son radios de verdad, como .admin-pill: funcionan sin JS
      (el detalle visible lo decide :has() en finanzas.scss), se recorren con
      las flechas y cada uno anuncia su explicación por aria-describedby, que sí
      se lee aunque el panel esté oculto. El JS sólo añade el resaltado en el
      Sankey y el respaldo para motores sin :has().
    */ ?>
    <section class="admin-panel fin-diag fin-diag--<?php echo $e($veredicto['tono']); ?>"
             aria-labelledby="fin-diag-titular" data-fin-diag>
        <div class="fin-diag__veredicto">
            <div class="fin-diag__principal">
                <div class="fin-diag__arriba">
                    <span class="admin-eyebrow">Diagnóstico · <?php echo $e($periodoLabel); ?></span>
                    <?php if ($veredicto['estado'] !== '') : ?>
                        <span class="fin-estado fin-estado--<?php echo $e($veredicto['tono']); ?>">
                            <?php echo $iconoEstado($veredicto['tono']); ?><?php echo $e($veredicto['estado']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h3 class="fin-diag__titular" id="fin-diag-titular">
                    <span class="fin-diag__antes"><?php echo $e($veredicto['antes']); ?></span>
                    <?php if ($veredicto['cifra'] !== '') : ?>
                        <span class="fin-diag__cifra"><?php echo $e($veredicto['cifra']); ?></span>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($veredicto['razon'])) : ?>
                    <p class="fin-diag__razon"><?php echo $partes($veredicto['razon']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($veredicto['palanca']) || !empty($veredicto['comparativo'])) : ?>
                <dl class="fin-diag__claves">
                    <?php if (!empty($veredicto['palanca'])) : ?>
                        <div class="fin-diag__clave">
                            <dt><?php echo $e($veredicto['palancaRotulo'] ?? 'Punto de equilibrio'); ?></dt>
                            <dd><?php echo $partes($veredicto['palanca']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($veredicto['comparativo'])) : ?>
                        <div class="fin-diag__clave">
                            <dt>Contra el periodo anterior</dt>
                            <dd><?php echo $partes($veredicto['comparativo']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>

        <div class="fin-diag__cuerpo">
            <div class="fin-cascada<?php echo $cascada['hayNegativo'] ? ' fin-cascada--bajo-cero' : ''; ?>"
                 style="--cero: <?php echo $e($cascada['cero']); ?>%">
                <div class="fin-cascada__cabeza">
                    <h4 class="fin-cascada__titulo" id="fin-cascada-titulo">Estado de resultados</h4>
                    <?php if ($comparable) : ?>
                        <?php /* Un checkbox real: compara sin JS (:has() en el
                                 SCSS) y se opera con la barra espaciadora. No
                                 es .admin-switch: ése pinta el «apagado» en rojo
                                 de peligro, que aquí sería mentir —no comparar
                                 no es un error, es la vista por defecto—. */ ?>
                        <label class="fin-interruptor">
                            <input type="checkbox" data-fin-comparar>
                            <span class="fin-interruptor__pista" aria-hidden="true"></span>
                            <span class="fin-interruptor__texto">Comparar con el periodo anterior</span>
                        </label>
                    <?php endif; ?>
                </div>
                <p class="fin-cascada__ayuda" id="fin-cascada-ayuda">
                    Elige un renglón para ver qué es, contra qué se compara y dónde actuar.
                </p>

                <div class="fin-cascada__pasos" role="radiogroup"
                     aria-labelledby="fin-cascada-titulo" aria-describedby="fin-cascada-ayuda">
                    <?php foreach ($cascada['pasos'] as $paso) : ?>
                        <?php
                            $clave = (string) $paso['clave'];
                            $nodos = array_values(array_filter(
                                (array) $paso['nodos'],
                                static fn ($n): bool => isset($nodosSankey[$n])
                            ));
                            $estilo = '--i: ' . (int) $paso['indice']
                                . '; --ini: ' . $paso['ini'] . '%; --fin: ' . $paso['fin'] . '%'
                                . '; --ini-prev: ' . $paso['iniPrev'] . '%; --fin-prev: ' . $paso['finPrev'] . '%'
                                . '; --origen: ' . $paso['origen']
                                . ($paso['enlace'] !== null ? '; --enlace: ' . $paso['enlace'] . '%' : '');
                            $cambio = $paso['cambio'];
                            $clasesPaso = 'fin-paso fin-paso--' . $paso['tipo'] . ' fin-paso--' . $paso['tono']
                                . ($paso['enlace'] !== null ? ' fin-paso--enlazado' : '')
                                . ($clave === $seleccion ? ' is-elegido' : '');
                        ?>
                        <label class="<?php echo $e($clasesPaso); ?>" style="<?php echo $e($estilo); ?>">
                            <input type="radio" name="fin-paso" value="<?php echo $e($clave); ?>"
                                   id="fin-paso-<?php echo $e($clave); ?>"
                                   aria-describedby="fin-detalle-<?php echo $e($clave); ?>-lead"
                                   data-nodos="<?php echo $e(json_encode($nodos, JSON_UNESCAPED_UNICODE)); ?>"
                                   <?php echo $clave === $seleccion ? 'checked' : ''; ?>>
                            <span class="fin-paso__rotulo">
                                <span class="fin-paso__op" aria-hidden="true"><?php echo $e($paso['op']); ?></span>
                                <span class="fin-paso__nombre"><?php echo $e($paso['rotulo']); ?></span>
                            </span>
                            <span class="fin-paso__pista" aria-hidden="true">
                                <?php if (!$paso['vacioPrev']) : ?><span class="fin-paso__fantasma"></span><?php endif; ?>
                                <?php if (!$paso['vacio']) : ?><span class="fin-paso__barra"></span><?php endif; ?>
                            </span>
                            <span class="fin-paso__cifras">
                                <span class="fin-paso__monto"><?php echo $e($paso['montoTexto']); ?></span>
                                <span class="fin-paso__pct"><?php echo $e($paso['secundario']); ?></span>
                                <?php if ($comparable) : ?>
                                    <?php if ($cambio !== null) : ?>
                                        <span class="fin-paso__cambio fin-delta fin-delta--<?php echo $cambio['bueno'] === null ? 'igual' : ($cambio['bueno'] ? 'bueno' : 'malo'); ?>">
                                            <?php if ($cambio['bueno'] !== null) : ?>
                                                <?php echo admin_icon($cambio['sube'] ? 'sube' : 'baja', 13, 'fin-delta__icono'); ?>
                                            <?php endif; ?>
                                            <?php echo $e($cambio['texto']); ?>
                                            <span class="admin-visually-hidden"> contra el periodo anterior</span>
                                        </span>
                                    <?php else : ?>
                                        <span class="fin-paso__cambio fin-delta fin-delta--igual">no se compara</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <?php if ($cascada['hayNegativo']) : ?>
                        <?php /* El cero sólo se rotula cuando hay algo debajo de
                                 él: a la izquierda de esa línea, el periodo
                                 pierde. Va dentro de la rejilla de los renglones
                                 para caer exactamente bajo la columna de barras. */ ?>
                        <div class="fin-cascada__eje" aria-hidden="true">
                            <span class="fin-cascada__eje-pista"><span class="fin-cascada__cero">$0</span></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($comparable) : ?>
                    <p class="fin-cascada__leyenda">
                        <span class="fin-cascada__muestra" aria-hidden="true"></span>
                        Periodo anterior, <?php echo $e(mb_strtolower(mb_substr((string) $cascada['etiquetaAnterior'], 0, 1)) . mb_substr((string) $cascada['etiquetaAnterior'], 1)); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="fin-detalle">
                <?php foreach ($cascada['pasos'] as $paso) : ?>
                    <?php
                        $clave = (string) $paso['clave'];
                        $nodos = array_values(array_filter(
                            (array) $paso['nodos'],
                            static fn ($n): bool => isset($nodosSankey[$n])
                        ));
                        $cambio = $paso['cambio'];
                    ?>
                    <article class="fin-detalle__panel<?php echo $clave === $seleccion ? ' is-activo' : ''; ?>"
                             id="fin-detalle-<?php echo $e($clave); ?>" data-paso="<?php echo $e($clave); ?>"
                             aria-labelledby="fin-detalle-<?php echo $e($clave); ?>-titulo">
                        <p class="fin-detalle__papel"><?php echo $e($paso['papel']); ?></p>
                        <h4 class="fin-detalle__titulo" id="fin-detalle-<?php echo $e($clave); ?>-titulo"><?php echo $e($paso['rotulo']); ?></h4>
                        <p class="fin-detalle__cifra fin-detalle__cifra--<?php echo $e($paso['tono']); ?>"><?php echo $e($paso['montoTexto']); ?></p>
                        <p class="fin-detalle__lead" id="fin-detalle-<?php echo $e($clave); ?>-lead"><?php echo $e($paso['lead']); ?></p>
                        <?php if (!empty($paso['texto'])) : ?>
                            <p class="fin-detalle__texto"><?php echo $partes($paso['texto']); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($paso['reparto'])) : ?>
                            <?php /* En qué se van los fijos. Un solo tono: aquí la
                                     magnitud es el dato, no la identidad. */ ?>
                            <ul class="fin-desglose" role="list" aria-label="Gastos fijos por categoría">
                                <?php foreach ($paso['reparto'] as $parte) : ?>
                                    <li class="fin-desglose__fila" style="--parte: <?php echo $e($parte['pct']); ?>%">
                                        <span class="fin-desglose__nombre"><?php echo $e($parte['nombre']); ?></span>
                                        <span class="fin-desglose__barra" aria-hidden="true"></span>
                                        <span class="fin-desglose__monto admin-num"><?php echo $e($parte['monto']); ?></span>
                                        <span class="fin-desglose__pct admin-num"><?php echo $e($parte['pctTexto']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if (!empty($paso['hechos'])) : ?>
                            <dl class="fin-detalle__hechos">
                                <?php foreach ($paso['hechos'] as [$rotulo, $valor]) : ?>
                                    <div>
                                        <dt><?php echo $e($rotulo); ?></dt>
                                        <dd class="admin-num"><?php echo $e($valor); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>

                        <?php if ($cambio !== null) : ?>
                            <p class="fin-detalle__antes">
                                Periodo anterior: <strong class="admin-num"><?php echo $e($cambio['antes']); ?></strong>
                                <span class="fin-delta fin-delta--<?php echo $cambio['bueno'] === null ? 'igual' : ($cambio['bueno'] ? 'bueno' : 'malo'); ?>">
                                    <?php if ($cambio['bueno'] !== null) : ?>
                                        <?php echo admin_icon($cambio['sube'] ? 'sube' : 'baja', 13, 'fin-delta__icono'); ?>
                                    <?php endif; ?>
                                    <?php if ($cambio['bueno'] === null) : ?>
                                        sin cambio
                                    <?php else : ?>
                                        <?php echo $e($cambio['diferencia']); ?><?php echo $cambio['pct'] !== null && $clave !== 'neta' ? ' (' . $e($cambio['pct']) . ')' : ''; ?>
                                    <?php endif; ?>
                                </span>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($paso['nota'])) : ?>
                            <p class="fin-detalle__nota"><?php echo $e($paso['nota']); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($paso['acciones']) || !empty($nodos)) : ?>
                            <div class="fin-detalle__acciones">
                                <?php foreach ((array) $paso['acciones'] as [$texto, $href]) : ?>
                                    <a class="fin-enlace" href="<?php echo $e($href); ?>">
                                        <?php echo $e($texto); ?><?php echo admin_icon('flecha-derecha', 15, 'fin-enlace__icono'); ?>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!empty($nodos)) : ?>
                                    <a class="fin-enlace" href="#fin-flujo" data-fin-ver-flujo="<?php echo $e($clave); ?>">
                                        Ver en el flujo<?php echo admin_icon('flecha-abajo', 15, 'fin-enlace__icono'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php /*
      ── SIGNOS VITALES ───────────────────────────────────────────────────
      Cuatro indicadores contra una referencia de la industria, cada uno con su
      semáforo (icono + palabra + color, nunca sólo color), una escala que
      enseña dónde cae la cifra respecto a lo sano y el sitio donde se corrige.
      La escala es decorativa para el lector de pantalla: lo que dibuja ya lo
      dicen la cifra, el estado y la frase.
    */ ?>
    <section class="admin-panel fin-signos" aria-labelledby="fin-signos-titulo">
        <div class="fin-signos__cabeza">
            <h3 class="fin-signos__titulo" id="fin-signos-titulo">Signos vitales</h3>
            <p class="fin-signos__bajada">Cada indicador contra la referencia de la industria restaurantera.</p>
        </div>
        <ul class="fin-signos__lista" role="list">
            <?php foreach ($signos as $signo) : ?>
                <?php $medidor = $signo['medidor']; ?>
                <li class="fin-signo fin-signo--<?php echo $e($signo['estado']); ?>">
                    <div class="fin-signo__cabeza">
                        <h4 class="fin-signo__rotulo"><?php echo $e($signo['rotulo']); ?></h4>
                        <span class="fin-estado fin-estado--<?php echo $e($signo['estado']); ?>">
                            <?php echo $iconoEstado($signo['estado']); ?><?php echo $e($signo['estadoTexto']); ?>
                        </span>
                    </div>
                    <p class="fin-signo__valor">
                        <span class="fin-signo__cifra"><?php echo $e($signo['valor']); ?></span>
                        <?php if ($signo['unidad'] !== '') : ?>
                            <span class="fin-signo__unidad"><?php echo $e($signo['unidad']); ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if (is_array($medidor)) : ?>
                        <?php
                            $estiloMedidor = '--valor: ' . $medidor['valor'] . '%';
                            if (!empty($medidor['banda'])) {
                                $estiloMedidor .= '; --banda-ini: ' . $medidor['banda'][0] . '%; --banda-fin: ' . $medidor['banda'][1] . '%';
                            }
                            if (isset($medidor['meta'])) {
                                $estiloMedidor .= '; --meta: ' . $medidor['meta'] . '%';
                            }
                        ?>
                        <div class="fin-medidor fin-medidor--<?php echo $e($medidor['tipo']); ?><?php echo !empty($medidor['desborda']) ? ' is-desborda' : ''; ?>"
                             style="<?php echo $e($estiloMedidor); ?>" aria-hidden="true">
                            <span class="fin-medidor__pista">
                                <?php if ($medidor['tipo'] === 'rango') : ?>
                                    <span class="fin-medidor__banda"></span>
                                <?php else : ?>
                                    <span class="fin-medidor__relleno"></span>
                                    <span class="fin-medidor__meta"></span>
                                <?php endif; ?>
                                <span class="fin-medidor__marca"></span>
                            </span>
                            <span class="fin-medidor__rotulos">
                                <span class="fin-medidor__rotulo">
                                    <?php echo $e($medidor['tipo'] === 'rango' ? ($medidor['bandaRotulo'] ?? '') : ($medidor['metaRotulo'] ?? '')); ?>
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($signo['frase'])) : ?>
                        <p class="fin-signo__frase"><?php echo $partes($signo['frase']); ?></p>
                    <?php endif; ?>
                    <p class="fin-signo__ref"><?php echo $e($signo['referencia']); ?></p>
                    <?php if (!empty($signo['accion'])) : ?>
                        <a class="fin-enlace fin-signo__accion" href="<?php echo $e($signo['accion'][1]); ?>">
                            <?php echo $e($signo['accion'][0]); ?><?php echo admin_icon('flecha-derecha', 15, 'fin-enlace__icono'); ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="fin-signos__fuentes">
            Referencias orientativas para un restaurante de servicio completo: costo de insumos de 28% a 35% de la
            venta, margen neto de 3% a 6%, nómina sana hasta el 33% y costo primo de 55% o menos (TouchBistro);
            merma de 5% a 15% de lo comprado (LeanPath); existencias para una o dos semanas (práctica habitual
            del sector). El costo de insumos sale de las recetas, no de las compras, y los días de inventario
            usan las existencias de hoy porque no hay histórico de almacén.
        </p>
    </section>

    <!-- Descomposición del ingreso -->
    <section class="admin-panel admin-card admin-finanzas__sankey-panel" id="fin-flujo">
        <div class="admin-panel-head">
            <div>
                <h3>A dónde va cada peso</h3>
                <p>Del ingreso del periodo al costo de insumos, la merma, los gastos fijos y lo que queda.</p>
            </div>
        </div>
        <?php if (empty($sankey)) : ?>
            <p class="admin-empty">Sin ingresos en el periodo para descomponer.</p>
        <?php else : ?>
            <p class="admin-finanzas__note admin-finanzas__note--ayuda">
                Pasa por encima de un nodo para seguir su cadena; pulsa para aislarla. Elegir un renglón del
                estado de resultados también la aísla aquí.
            </p>
            <div class="admin-finanzas__sankey" id="flujoFinanciero"></div>

            <?php if ($utilidadNeta < 0) : ?>
                <p class="admin-finanzas__note">
                    La utilidad neta es negativa (<?php echo $e($money($utilidadNeta)); ?>): los gastos fijos
                    superan a la utilidad bruta. El diagrama lo dibuja como «Faltante».
                </p>
            <?php endif; ?>

            <?php /* Alternativa accesible al SVG. Los nodos son enfocables y se
                     anuncian con su monto, pero un diagrama de caudales no se
                     recorre bien sólo con el foco: la tabla dice los mismos
                     pares origen → destino en orden de lectura. */ ?>
            <table class="admin-visually-hidden">
                <caption>Descomposición del ingreso del periodo</caption>
                <thead>
                    <tr><th scope="col">De</th><th scope="col">A</th><th scope="col">Monto</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($sankey as $flujo) : ?>
                        <tr>
                            <td><?php echo $e($flujo['from']); ?></td>
                            <td><?php echo $e($flujo['to']); ?></td>
                            <td><?php echo $e($money($flujo['flow'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- Cortes de caja por día -->
    <section class="admin-panel admin-card admin-cortes" id="cortes">
        <div class="admin-panel-head">
            <?php /* Ya no lleva su propio formulario de fechas: el selector de
                     periodo del encabezado manda sobre toda la pantalla, y dos
                     rangos distintos en la misma página se contradecían. */ ?>
            <div><h3>Cortes de caja</h3><p>Resumen diario de tickets cerrados en el periodo seleccionado.</p></div>
            <?php if ((int) $totalesCortes['dias'] > 0) : ?>
                <p class="admin-cortes__conteo">
                    <?php if ($cortesPaginas > 1) : ?>
                        Días <?php echo $cortesDesde + 1; ?>–<?php echo $cortesDesde + count($cortes); ?> de <?php echo (int) $totalesCortes['dias']; ?>
                    <?php else : ?>
                        <?php echo (int) $totalesCortes['dias']; ?> <?php echo (int) $totalesCortes['dias'] === 1 ? 'día' : 'días'; ?> con venta
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php if (empty($cortes)) : ?>
            <p class="admin-empty">Sin ventas cerradas en el rango seleccionado.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-cortes__table">
                    <thead>
                        <tr>
                            <th scope="col">Día</th>
                            <th scope="col" class="admin-num">Tickets</th>
                            <th scope="col" class="admin-num">Ventas</th>
                            <th scope="col" class="admin-num">Propinas</th>
                            <th scope="col" class="admin-num">Efectivo</th>
                            <th scope="col" class="admin-num">Tarjeta</th>
                            <th scope="col" class="admin-num">Total recibido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cortes as $c) : ?>
                            <tr<?php echo $c['fecha'] === $hoyIso ? ' class="is-today"' : ''; ?>>
                                <td>
                                    <div class="admin-cortes__day">
                                        <span class="admin-table__cell-main"><?php echo $e($fechaBonita($c['fecha'])); ?></span>
                                        <?php if ($c['fecha'] === $hoyIso) : ?><span class="admin-badge admin-badge--neutral">Hoy</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="admin-num"><?php echo (int) $c['tickets']; ?></td>
                                <td class="admin-num"><?php echo $e($money($c['ventas'])); ?></td>
                                <td class="admin-num"><?php echo $e($money($c['propinas'])); ?></td>
                                <td class="admin-num"><?php echo $e($money($c['efectivo'])); ?></td>
                                <td class="admin-num"><?php echo $e($money($c['tarjeta'])); ?></td>
                                <td class="admin-num"><strong class="admin-cortes__total"><?php echo $e($money($c['total'])); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php /* El total es del PERIODO, no de la página: se suma en
                             el controlador antes de recortar las filas. Va en
                             <tfoot> en todas las páginas y lo dice el rótulo. */ ?>
                    <tfoot>
                        <tr>
                            <th scope="row">
                                Todo el periodo
                                <span class="admin-cortes__dias"><?php echo (int) $totalesCortes['dias']; ?> <?php echo (int) $totalesCortes['dias'] === 1 ? 'día' : 'días'; ?></span>
                            </th>
                            <td class="admin-num"><?php echo (int) $totalesCortes['tickets']; ?></td>
                            <td class="admin-num"><?php echo $e($money($totalesCortes['ventas'])); ?></td>
                            <td class="admin-num"><?php echo $e($money($totalesCortes['propinas'])); ?></td>
                            <td class="admin-num"><?php echo $e($money($totalesCortes['efectivo'])); ?></td>
                            <td class="admin-num"><?php echo $e($money($totalesCortes['tarjeta'])); ?></td>
                            <td class="admin-num"><strong class="admin-cortes__total"><?php echo $e($money($totalesCortes['total'])); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php
                $pagPagina = $cortesPagina;
                $pagTotal = $cortesPaginas;
                $pagUrl = $cortesUrl;
                $pagEtiqueta = 'Páginas de cortes de caja';
                include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
    </section>

    <!-- Gastos fijos: reparto y administración -->
    <section class="admin-panel admin-card fin-gastos" id="gastos-fijos">
        <div class="admin-panel-head"><div><h3>Gastos fijos</h3><p>Renta, servicios, nómina y demás costos mensuales.</p></div></div>

        <?php if (!empty($gastosMensuales)) : ?>
            <?php
                /*
                 * El reparto del gasto fijo. Antes era una dona de Chart.js en
                 * su propio panel, a 208 KB de librería y con el total del
                 * centro en Georgia. Ahora es una barra junto a la lista que lo
                 * produce, en MESES —la unidad en la que se captura cada gasto—
                 * y en grises: la identidad de cada categoría la dice su
                 * nombre, y el panel no pinta de colores lo que no es estado.
                 */
                $totalMensual = array_sum($gastosMensuales);
                $tono = 0;
            ?>
            <div class="fin-reparto">
                <p class="fin-reparto__total">
                    <span class="fin-reparto__cifra"><?php echo $e(DiagnosticoFinanciero::dinero($totalMensual)); ?></span>
                    <span class="fin-reparto__unidad">al mes en gastos fijos activos</span>
                </p>
                <div class="fin-reparto__barra" aria-hidden="true">
                    <?php foreach ($gastosMensuales as $cat => $monto) : ?>
                        <?php $tono++; ?>
                        <?php /* --peso sin unidades: es el flex-grow del tramo,
                                 así el reparto suma 100% descontando los huecos. */ ?>
                        <span class="fin-reparto__seg fin-reparto__seg--<?php echo min($tono, 5); ?>"
                              style="--peso: <?php echo $e($totalMensual > 0 ? round($monto / $totalMensual * 1000) : 1); ?>"></span>
                    <?php endforeach; ?>
                </div>
                <ul class="fin-reparto__lista" role="list" aria-label="Gastos fijos al mes por categoría">
                    <?php $tono = 0; ?>
                    <?php foreach ($gastosMensuales as $cat => $monto) : ?>
                        <?php $tono++; ?>
                        <li class="fin-reparto__item">
                            <span class="fin-reparto__muestra fin-reparto__seg--<?php echo min($tono, 5); ?>" aria-hidden="true"></span>
                            <span class="fin-reparto__nombre"><?php echo $e($etiquetaCat[$cat] ?? $cat); ?></span>
                            <span class="fin-reparto__monto admin-num"><?php echo $e(DiagnosticoFinanciero::dinero((float) $monto)); ?></span>
                            <span class="fin-reparto__pct admin-num"><?php echo $e($totalMensual > 0 ? DiagnosticoFinanciero::porcentaje($monto / $totalMensual * 100, 0) : '0%'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="admin-finanzas__add" method="POST" action="/admin/finanzas/gasto/guardar">
            <input type="text" name="nombre" placeholder="Nombre del gasto" maxlength="120" required aria-label="Nombre del gasto">
            <select name="categoria" aria-label="Categoría">
                <?php foreach ($categorias as $c) : ?>
                    <option value="<?php echo $e($c); ?>"><?php echo $e($etiquetaCat[$c] ?? $c); ?></option>
                <?php endforeach; ?>
            </select>
            <?php /* Mismo envoltorio que el input de edición: sin él, el gasto
                     nuevo era el único campo de dinero sin su símbolo. */ ?>
            <div class="admin-finanzas__money">
                <span>$</span>
                <input type="number" name="monto" step="0.01" min="0" placeholder="Monto mensual" required aria-label="Monto mensual">
            </div>
            <button type="submit" class="admin-btn admin-btn--primary">Agregar</button>
        </form>

        <?php if (empty($gastos)) : ?>
            <p class="admin-empty">No hay gastos fijos registrados.</p>
        <?php else : ?>
            <div class="admin-gasto-list">
                <?php foreach ($gastos as $g) : ?>
                    <article class="admin-gasto-card <?php echo $g->activo ? '' : 'is-inactive'; ?>">
                        <form method="POST" action="/admin/finanzas/gasto/guardar" class="admin-gasto-card__form">
                            <input type="hidden" name="id" value="<?php echo (int) $g->id; ?>">
                            <div class="admin-gasto-card__field admin-gasto-card__field--name">
                                <label class="admin-gasto-card__label">Gasto</label>
                                <input type="text" name="nombre" value="<?php echo $e($g->nombre); ?>" maxlength="120" required aria-label="Nombre del gasto">
                            </div>
                            <div class="admin-gasto-card__field">
                                <label class="admin-gasto-card__label">Categoría</label>
                                <select name="categoria" aria-label="Categoría">
                                    <?php foreach ($categorias as $c) : ?>
                                        <option value="<?php echo $e($c); ?>" <?php echo $g->categoria === $c ? 'selected' : ''; ?>><?php echo $e($etiquetaCat[$c] ?? $c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-gasto-card__field">
                                <label class="admin-gasto-card__label">Monto mensual</label>
                                <div class="admin-finanzas__money">
                                    <span>$</span>
                                    <input type="number" name="monto" step="0.01" min="0" value="<?php echo $e((string) $g->monto); ?>" required aria-label="Monto mensual">
                                </div>
                            </div>
                            <label class="admin-switch admin-gasto-card__switch">
                                <input type="checkbox" name="activo" value="1" <?php echo $g->activo ? 'checked' : ''; ?>>
                                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                                <span class="admin-switch__label">Activo</span>
                            </label>
                            <div class="admin-gasto-card__actions">
                                <?php /*
                                  El botón de borrar vive en la fila de acciones, pero pertenece
                                  al OTRO formulario: el atributo form= lo enlaza con él. Es lo
                                  que permite tenerlos lado a lado sin anidar <form>, que el HTML
                                  no admite.

                                  Antes colgaba en absoluto de la esquina superior derecha de la
                                  tarjeta, y por eso el campo de nombre llevaba un canalón de
                                  46 px reservado para librarlo: la tarjeta quedaba descompensada
                                  —una acción arriba, otra abajo— y el título recortado sin que
                                  nada explicara por qué.

                                  Va ANTES de Guardar, no después: el extremo derecho de una fila
                                  de acciones es el sitio de la acción principal en todo el panel
                                  —y en el propio diálogo de confirmación—, y ahí la papelera era
                                  lo último que toca el cursor al ir a guardar.
                                */ ?>
                                <button type="submit" form="gasto-eliminar-<?php echo (int) $g->id; ?>"
                                        class="admin-icon-button admin-icon-button--danger admin-gasto-card__trash"
                                        title="Eliminar" aria-label="Eliminar gasto «<?php echo $e($g->nombre); ?>»">
                                    <?php echo admin_icon('basura', 17); ?>
                                </button>
                                <button type="submit" class="admin-btn admin-btn--primary admin-btn--small">
                                    <?php echo admin_icon('check', 16, 'admin-btn__icon'); ?>
                                    Guardar
                                </button>
                            </div>
                        </form>
                        <?php /* Sin botón dentro: lo dispara el de arriba por form=. */ ?>
                        <form method="POST" action="/admin/finanzas/gasto/eliminar" class="admin-gasto-card__delete"
                              id="gasto-eliminar-<?php echo (int) $g->id; ?>"
                              data-confirm-delete
                              data-confirm-eyebrow="Eliminar gasto fijo"
                              data-confirm-title="¿Eliminar «<?php echo $e($g->nombre); ?>»?"
                              data-confirm-require="<?php echo $e($g->nombre); ?>"
                              data-confirm-description="Dejará de restarse de la utilidad neta en todos los periodos."
                              data-confirm-consequence="Esta acción no se puede deshacer.">
                            <input type="hidden" name="id" value="<?php echo (int) $g->id; ?>">
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Rentabilidad por producto -->
    <section class="admin-panel admin-card" id="rentabilidad">
        <div class="admin-panel-head"><div><h3>Rentabilidad por producto</h3><p>Precio de venta vs. costo de insumos de cada producto con receta.</p></div></div>
        <?php if (empty($rentabilidad)) : ?>
            <p class="admin-empty">Aún no hay productos con receta. Define recetas en Productos para ver su margen.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Producto</th><th class="admin-num">Precio</th><th class="admin-num">Costo insumos</th><th class="admin-num">Margen</th><th class="admin-num">Margen %</th></tr></thead>
                    <tbody>
                        <?php foreach ($rentabilidad as $r) : ?>
                            <tr>
                                <td><span class="admin-table__cell-main"><?php echo $e($r['nombre']); ?></span></td>
                                <td class="admin-num"><?php echo $e($money($r['precio'])); ?></td>
                                <td class="admin-num"><?php echo $e($money($r['costo'])); ?></td>
                                <td class="admin-num"><?php echo $e($money($r['margen'])); ?></td>
                                <td class="admin-num">
                                    <span class="admin-badge admin-badge--<?php echo $r['margen_pct'] >= 65 ? 'success' : ($r['margen_pct'] >= 40 ? 'warning' : 'danger'); ?>">
                                        <?php echo $e(DiagnosticoFinanciero::porcentaje((float) $r['margen_pct'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
