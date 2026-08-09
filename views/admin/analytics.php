<?php

/**
 * Vista principal de analytics del panel de administración.
 * Métricas, gráficas y resumen operativo con datos REALES de la BD
 * ($analytics, construido en AdminController::construirAnalytics).
 */
$analytics = is_array($analytics ?? null) ? $analytics : ['metrics' => [], 'tickets' => [], 'payments' => [], 'charts' => []];
$rango = is_array($rango ?? null) ? $rango : ['start' => date('Y-m-d', strtotime('-29 days')), 'end' => date('Y-m-d'), 'preset' => 30, 'label' => 'Últimos 30 días'];
$rangoPreset = (int) ($rango['preset'] ?? 0);
$esCustom = $rangoPreset === 0;
$hoyIso = date('Y-m-d');
?>
<script>
    window.AdminAnalyticsMock = <?php echo json_encode($analytics, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<section class="admin-analytics" data-admin-analytics>
    <header class="admin-page-header">
        <div class="admin-page-header__intro">
            <div class="admin-page-title-row">
                <h2>Análisis de datos</h2>
            </div>
            <p class="admin-page-summary">
                Resumen operativo <span aria-hidden="true">·</span>
                <span data-analytics-caption><?php echo htmlspecialchars($rango['label']); ?></span>
            </p>
        </div>

        <?php include __DIR__ . '/partials/_range-picker.php'; ?>
    </header>

    <?php
    /*
     * Tres niveles de lectura, en vez de seis cajas del mismo peso:
     *   1. titular  → ventas del periodo (tarjeta) + la gráfica diaria al lado
     *   2. soporte  → ticket promedio, propinas y comensales
     *   3. contexto → platillos y reservaciones, en una franja compacta
     */
    ?>
    <section class="admin-metrics-section" aria-label="Indicadores principales">
        <?php /* La cifra y la gráfica comparten fila: la tendencia se lee junto
                 al número del que habla, no tres pantallas más abajo. */ ?>
        <div class="admin-metrics-lead">
            <div class="admin-metrics-hero" data-admin-metrics-hero></div>
            <article class="admin-panel admin-chart-card admin-chart-card--lead">
                <header>
                    <div>
                        <h3>Ventas diarias del periodo</h3>
                        <p>Tickets cerrados por día</p>
                    </div>
                    <span>MXN</span>
                </header>
                <div class="admin-chart-card__canvas">
                    <canvas id="salesByDayChart"></canvas>
                </div>
            </article>
        </div>
        <div class="admin-metrics-grid admin-metrics-grid--support" data-admin-metrics-primary></div>
        <div class="admin-metrics-strip" data-admin-metrics-secondary></div>
    </section>

    <div class="admin-chart-grid">
        <article class="admin-panel admin-chart-card">
            <header>
                <div>
                    <h3>Ventas por categoría</h3>
                    <p>Distribución del ingreso por familia</p>
                </div>
                <span>Subtotal</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="salesByCategoryChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Métodos de pago</h3>
                <span>Pagos</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="paymentMethodsChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Productos más vendidos</h3>
                <span>Unidades</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="topProductsChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Reservaciones por día</h3>
                <span>Personas</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="reservationsByDayChart"></canvas>
            </div>
        </article>

    </div>

    <article class="admin-panel admin-table-panel">
        <header>
            <div>
                <p class="admin-page-eyebrow">Actividad operativa</p>
                <h3>Resumen reciente</h3>
            </div>
            <span>Últimos tickets</span>
        </header>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Mesa</th>
                        <th>Fecha</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Propina</th>
                        <th>Pago</th>
                    </tr>
                </thead>
                <tbody data-admin-summary></tbody>
            </table>
        </div>
    </article>

    <!-- ================= Analíticas diagnósticas (Nivel 1) ================= -->
    <!-- Datos en window.AdminAnalyticsMock.nivel1 (Services\Analiticas). -->
    <section class="admin-nivel1" data-admin-nivel1 aria-label="Analíticas diagnósticas de Nivel 1">
        <header class="admin-page-header admin-nivel1__intro">
            <div class="admin-page-header__intro">
                <div class="admin-page-title-row">
                    <h2>Analíticas diagnósticas</h2>
                </div>
                <p class="admin-page-summary">
                    Del <em>qué pasó</em> al <em>por qué</em> y <em>qué hacer</em>
                </p>
            </div>
        </header>

        <!-- §3.1 · Ingeniería de menú (matriz Kasavana-Smith) -->
        <article class="admin-panel admin-nivel1-menu" data-n1-menu>
            <header>
                <div>
                    <p class="admin-page-eyebrow">3.1 · Ingeniería de menú</p>
                    <h3>Matriz popularidad × margen</h3>
                    <p>Qué platillo proteger, cuál subir de precio y cuál retirar de la carta</p>
                </div>
                <button
                    type="button"
                    class="admin-nivel1-info"
                    data-admin-modal-open="n1-menu-info"
                    aria-label="Cómo leer la ingeniería de menú"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 16v-4"></path>
                        <path d="M12 8h.01"></path>
                    </svg>
                    <span>Cómo leer esto</span>
                </button>
            </header>
            <div class="admin-nivel1-menu__body">
                <div class="admin-nivel1-menu__chart">
                    <div class="admin-nivel1-legend" data-n1-menu-summary></div>
                    <div class="admin-chart-card__canvas">
                        <canvas id="menuEngineeringChart"></canvas>
                    </div>
                </div>
                <?php // Tres columnas en vez de cinco: la categoría baja como
                      // subtítulo del platillo y unidades/margen se apilan en
                      // una sola celda numérica. En un panel a media anchura,
                      // cinco columnas obligaban a scrollear en los dos ejes. ?>
                <div class="admin-table-wrap admin-nivel1-menu__table">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th scope="col">Platillo</th>
                                <th scope="col">Clase</th>
                                <th scope="col" class="admin-table__num">Uds. · Margen</th>
                            </tr>
                        </thead>
                        <tbody data-n1-menu-table></tbody>
                    </table>
                </div>
            </div>
        </article>

        <!-- §3.2 · RevPASH -->
        <article class="admin-panel admin-chart-card admin-nivel1-panel" data-n1-revpash>
            <header>
                <div>
                    <p class="admin-page-eyebrow">3.2 · RevPASH</p>
                    <h3>Ingreso por asiento disponible por hora</h3>
                    <p class="admin-nivel1-sub">
                        Mapa de calor por franja y día de la semana, y abajo el mismo
                        indicador tomando el día completo
                    </p>
                </div>
                <div class="admin-nivel1-headside">
                    <span data-n1-revpash-seats></span>
                    <button
                        type="button"
                        class="admin-nivel1-info admin-nivel1-info--icon"
                        data-admin-modal-open="n1-revpash-info"
                        title="Cómo leer el RevPASH"
                        aria-label="Cómo leer el RevPASH"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 16v-4"></path>
                            <path d="M12 8h.01"></path>
                        </svg>
                    </button>
                </div>
            </header>
            <!-- El mapa lo construye nivel1.js como <table> real: las horas
                 son encabezados de fila y los días de columna, así el lector
                 de pantalla puede recorrer la matriz. -->
            <div class="admin-n1-heat" data-n1-revpash-heat></div>
            <div class="admin-n1-heat__scale" data-n1-revpash-scale hidden>
                <span>Menos</span>
                <span class="admin-n1-heat__ramp" aria-hidden="true"></span>
                <span data-n1-revpash-max></span>
            </div>
            <p class="admin-nivel1-note" data-n1-revpash-note></p>

            <!-- Vista diaria del mismo indicador. Va en su propia gráfica a
                 propósito: el mapa divide por franja y esta por el día
                 completo, así que los valores no son comparables celda a
                 celda y compartir eje mentiría. -->
            <section class="admin-n1-daily" aria-label="RevPASH por día completo">
                <div class="admin-n1-daily__head">
                    <h4>Por día completo</h4>
                    <p>Ingreso del día ÷ (asientos × horas de operación de ese día)</p>
                </div>
                <div class="admin-n1-daily__canvas">
                    <canvas id="revpashDailyChart"></canvas>
                </div>
                <p class="admin-nivel1-note" data-n1-revpash-daily-note></p>
            </section>
        </article>

        <!-- §3.4 · Reglas de asociación con lift -->
        <article class="admin-panel admin-table-panel admin-nivel1-panel" data-n1-asociacion>
            <header>
                <div>
                    <p class="admin-page-eyebrow">3.4 · Reglas de asociación</p>
                    <h3>Qué se pide junto con qué</h3>
                </div>
                <div class="admin-nivel1-headside">
                    <span data-n1-asociacion-tickets></span>
                    <button
                        type="button"
                        class="admin-nivel1-info admin-nivel1-info--icon"
                        data-admin-modal-open="n1-asociacion-info"
                        title="Cómo leer las reglas de asociación"
                        aria-label="Cómo leer las reglas de asociación"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 16v-4"></path>
                            <path d="M12 8h.01"></path>
                        </svg>
                    </button>
                </div>
            </header>
            <?php // A y B se apilan en una sola celda "Combinación" y el
                  // lift baja como subtítulo de la confianza: el par es un
                  // solo dato, no dos columnas independientes. ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <th scope="col">Combinación</th>
                            <th scope="col" class="admin-table__num">Juntos</th>
                            <th scope="col" class="admin-table__num">Confianza</th>
                        </tr>
                    </thead>
                    <tbody data-n1-asociacion-table></tbody>
                </table>
            </div>
        </article>
    </section>
</section>

<!-- ===== Guía de lectura de la ingeniería de menú (§3.1) =====
     Vive fuera de .admin-analytics a propósito: el modal se posiciona fijo y
     así no hereda las reglas anidadas de .admin-nivel1. Lo abre el botón
     [data-admin-modal-open] del panel; el foco, ESC y el backdrop los maneja
     initAdminModals() en admin.js. -->
<div class="admin-modal" id="n1-menu-info" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <?php /* --xwide en vez de --wide: fija el encabezado y deja que scrollee
             solo el cuerpo. Con --wide se iba el título al bajar y el ancho no
             daba para poner los bloques lado a lado. */ ?>
    <div
        class="admin-modal__dialog admin-modal__dialog--xwide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="n1-menu-info-title"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Ingeniería de menú</span>
                <h2 class="admin-modal__title" id="n1-menu-info-title">Qué hacer con cada platillo</h2>
                <p class="admin-modal__text">
                    Cada platillo se cruza en dos preguntas: <strong>cuánto se vende</strong> y
                    <strong>cuánto deja</strong>. Del cruce sale la acción.
                </p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <section class="admin-n1-guide">
            <h3 class="admin-n1-guide__title">Las cuatro clases</h3>
            <?php /* La acción va primero y la definición después: el admin abre
                     esto para decidir, no para estudiar la matriz. */ ?>
            <div class="admin-n1-guide__grid">
                <article class="admin-n1-guide__card">
                    <span class="admin-nivel1-badge admin-nivel1-badge--estrella">⭐ Estrella</span>
                    <p class="admin-n1-guide__action">Protégelo.</p>
                    <p class="admin-n1-guide__meaning">
                        Se vende mucho y deja mucho. No le muevas receta ni precio, dale el mejor
                        lugar en la carta y que nunca falte el insumo.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <span class="admin-nivel1-badge admin-nivel1-badge--vaca">🐎 Vaca</span>
                    <p class="admin-n1-guide__action">Bájale el costo.</p>
                    <p class="admin-n1-guide__meaning">
                        Se vende mucho pero deja poco. Trae gente, así que no lo quites: renegocia
                        insumos, revisa el gramaje o sube el precio con cuidado.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <span class="admin-nivel1-badge admin-nivel1-badge--incognita">❓ Incógnita</span>
                    <p class="admin-n1-guide__action">Dale visibilidad.</p>
                    <p class="admin-n1-guide__meaning">
                        Deja mucho pero casi nadie lo pide. Es la mayor oportunidad: muévelo en la
                        carta o vuélvelo sugerencia del mesero antes de descartarlo.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <span class="admin-nivel1-badge admin-nivel1-badge--perro">🐕 Perro</span>
                    <p class="admin-n1-guide__action">Considera retirarlo.</p>
                    <p class="admin-n1-guide__meaning">
                        Ni se vende ni deja margen. Antes de quitarlo, revisa si comparte insumos
                        con una Estrella: a veces conviene por eso.
                    </p>
                </article>
            </div>

            <h3 class="admin-n1-guide__title">Los dos ejes</h3>
            <dl class="admin-n1-guide__list">
                <div class="admin-n1-guide__item">
                    <dt>Popularidad <span>eje horizontal</span></dt>
                    <dd>
                        Qué porcentaje de las unidades vendidas se llevó este platillo.
                        <b class="admin-n1-guide__formula">Popular = vendió ≥ 70 % del promedio de su categoría</b>
                        Se compara contra su propia categoría, no contra el menú entero: así un
                        plato fuerte no compite contra un café.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Margen de contribución <span>eje vertical</span></dt>
                    <dd>
                        Lo que deja cada unidad vendida.
                        <b class="admin-n1-guide__formula">Margen = precio − costo de la receta</b>
                        No es utilidad final: no descuenta renta ni nómina, sino que aporta para
                        cubrirlas. La línea punteada de la gráfica es el promedio ponderado.
                    </dd>
                </div>
            </dl>

            <h3 class="admin-n1-guide__title">Antes de decidir</h3>
            <ul class="admin-n1-guide__notes">
                <li>
                    Todo se calcula sobre el <strong>periodo seleccionado arriba</strong>. Un rango
                    corto o una temporada atípica mueve las clasificaciones.
                </li>
                <li>
                    Un platillo <strong>sin receta cargada</strong> sale con costo cero y margen
                    del 100 %, lo que lo empuja a Estrella o Incógnita sin merecerlo. Si algo se ve
                    demasiado rentable, revisa su receta primero.
                </li>
                <li>
                    Las clases son relativas a tu propio menú: siempre habrá Perros, incluso en una
                    carta sana. Lo que importa es cómo se mueven entre periodos.
                </li>
            </ul>
        </section>

        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--primary" data-admin-modal-close>Entendido</button>
        </div>
    </div>
</div>

<!-- ===== Guía de lectura del RevPASH (§3.2) ===== -->
<div class="admin-modal" id="n1-revpash-info" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div
        class="admin-modal__dialog admin-modal__dialog--wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="n1-revpash-info-title"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">3.2 · RevPASH</span>
                <h2 class="admin-modal__title" id="n1-revpash-info-title">Cómo leer esta analítica</h2>
                <p class="admin-modal__text">
                    <strong>RevPASH</strong> es el ingreso por asiento disponible por hora.
                    Responde a algo que las ventas totales esconden:
                    <strong>qué tan bien aprovechas el comedor en cada franja</strong>. Cada celda
                    cruza una <strong>hora</strong> (filas) con un <strong>día de la semana</strong>
                    (columnas), y su color indica qué tan fuerte rinde esa combinación.
                </p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <section class="admin-n1-guide">
            <p class="admin-n1-guide__formula">
                RevPASH<span>día, hora</span> = ingreso de esa celda ÷ (asientos × días abiertos en esa celda)
            </p>

            <h3 class="admin-n1-guide__title">De dónde sale cada parte</h3>
            <dl class="admin-n1-guide__list">
                <div class="admin-n1-guide__item">
                    <dt>Ingreso de la celda</dt>
                    <dd>
                        Suma de lo consumido en los tickets <strong>abiertos</strong> en esa hora y
                        en ese día de la semana, contando solo tickets cerrados y renglones no
                        cancelados. Todos los viernes del periodo se suman en la misma columna.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Asientos</dt>
                    <dd>
                        La capacidad del comedor: <strong>44 lugares</strong> (las 11 mesas de sala).
                        Es un número fijo, así que todas las celdas se comparan contra la misma base.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Días abiertos en esa celda</dt>
                    <dd>
                        Cuántas fechas del periodo cayeron en ese día de la semana <em>y</em> tuvieron
                        el restaurante abierto en esa franja, según el horario y sus excepciones. Es lo
                        que permite comparar columnas: en 30 días puede haber cuatro viernes y cinco
                        sábados, y sin esta división el sábado se vería más fuerte solo por ser más
                        veces.
                    </dd>
                </div>
            </dl>

            <h3 class="admin-n1-guide__title">Por qué se divide entre asientos disponibles</h3>
            <p class="admin-n1-guide__text">
                Dividir entre los asientos <em>disponibles</em> —y no entre los ocupados— hace que la
                métrica castigue las dos formas de perder dinero a la vez: la
                <strong>mesa vacía</strong> y la <strong>mesa ocupada que consume poco</strong>.
                Una franja llena de clientes que solo piden café puede rendir menos que una
                media entrada que consume comida completa.
            </p>

            <h3 class="admin-n1-guide__title">Cómo leer las celdas</h3>
            <div class="admin-n1-guide__grid">
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Celda oscura</p>
                    <p class="admin-n1-guide__action">
                        Esa hora en ese día exprime bien el comedor. El color es
                        <strong>relativo a la celda más fuerte</strong> del periodo, que marca el
                        extremo de la rampa. Cuida que no le falte personal ni cocina: es donde más
                        cuesta cada error.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Celda pálida o en cero</p>
                    <p class="admin-n1-guide__action">
                        Abierto y produciendo poco o nada: el hueco que buscas. Estás pagando renta y
                        nómina por asientos vacíos. Es la franja candidata a promoción, menú especial
                        u horario reducido <em>ese día en particular</em>.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Celda con guion “·”</p>
                    <p class="admin-n1-guide__action">
                        El restaurante estaba <strong>cerrado</strong> en esa franja. No es bajo
                        desempeño ni entra en ningún promedio: es calendario, no operación.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Filas y columnas completas</p>
                    <p class="admin-n1-guide__action">
                        Una <em>fila</em> pálida es una hora floja toda la semana: revisa el horario.
                        Una <em>columna</em> pálida es un día flojo a cualquier hora: revisa la
                        demanda de ese día. El problema y la solución son distintos.
                    </p>
                </article>
            </div>

            <h3 class="admin-n1-guide__title">Antes de tomar decisiones</h3>
            <ul class="admin-n1-guide__notes">
                <li>
                    El consumo se atribuye a la hora en que <strong>se abrió la cuenta</strong>, no a
                    cuándo se pidió cada platillo. Una mesa que se sienta a las 20:00 y sigue pidiendo
                    hasta las 22:30 suma todo a las 20:00, así que las horas de entrada se ven más
                    fuertes y las de salida más débiles de lo que fueron.
                </li>
                <li>
                    Si una celda tuvo venta <strong>fuera del horario declarado</strong>, su
                    denominador no son los días abiertos (que serían cero) sino los días con ventas
                    registradas. Va marcada con un borde punteado: usa otra base y no es
                    estrictamente comparable con el resto. Por eso la
                    <strong>franja más fuerte y la más floja del pie solo consideran celdas dentro
                    de horario</strong>: recomendar una franja en la que el negocio está cerrado no
                    accionaría nada. La celda más intensa del mapa puede entonces no ser la que se
                    nombra abajo.
                </li>
                <li>
                    El horario que se usa es el que esté capturado en el sistema, incluidas sus
                    excepciones por fecha. Si cambias el horario de un día, este mapa se recalcula
                    solo: no hay franjas fijas escritas en el código.
                </li>
                <li>
                    Con periodos cortos una celda puede apoyarse en <strong>uno o dos días</strong>.
                    Un solo evento privado un martes a las 14:00 puede pintar esa celda de oscuro sin
                    que sea un patrón. El detalle al pasar el cursor indica cuántos días la sostienen.
                </li>
                <li>
                    Compara celdas <strong>dentro del mismo periodo</strong>. Cambiar el rango de
                    fechas cambia los días abiertos de cada celda y también la celda más fuerte, que
                    es la referencia del color.
                </li>
            </ul>

            <h3 class="admin-n1-guide__title">La gráfica de abajo: por día completo</h3>
            <p class="admin-n1-guide__formula">
                RevPASH<span>día</span> = ingreso del día ÷ (asientos × horas de operación de ese día)
            </p>
            <p class="admin-n1-guide__text">
                Es el mismo indicador con otra unidad: en vez de una franja de una hora, el
                <strong>día entero, de apertura a cierre</strong>. Responde a una pregunta que el
                mapa no contesta: <em>¿qué día aprovecha mejor el comedor en conjunto?</em> Las
                horas de operación se acumulan sobre todas las fechas del rango que cayeron en ese
                día de la semana —cuatro lunes de nueve horas dan 36— y la línea punteada es el
                promedio del periodo completo.
            </p>
            <ul class="admin-n1-guide__notes">
                <li>
                    <strong>No es el promedio de la columna del mapa.</strong> El mapa divide cada
                    celda entre las veces que <em>esa franja</em> estuvo abierta; aquí el divisor
                    son todas las horas que abrió el día, así que las <strong>horas muertas
                    también pesan</strong>. Por eso las dos gráficas están separadas: sus números
                    no son comparables entre sí.
                </li>
                <li>
                    Un día que abre pocas horas y las llena puede superar a uno que abre todo el
                    día y se diluye. Eso es información, no un error: es exactamente el argumento
                    para <strong>ajustar el horario</strong> de los días flojos.
                </li>
                <li>
                    Si un día vendió sin horario declarado, se normaliza con las horas que sí
                    tuvieron venta. El detalle al pasar el cursor lo indica.
                </li>
            </ul>
        </section>

        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--primary" data-admin-modal-close>Entendido</button>
        </div>
    </div>
</div>

<!-- ===== Guía de lectura de las reglas de asociación (§3.4) ===== -->
<div class="admin-modal" id="n1-asociacion-info" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div
        class="admin-modal__dialog admin-modal__dialog--wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="n1-asociacion-info-title"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">3.4 · Reglas de asociación</span>
                <h2 class="admin-modal__title" id="n1-asociacion-info-title">Cómo leer esta tabla</h2>
                <p class="admin-modal__text">
                    Cada renglón es un <strong>par de platillos que se piden en la misma cuenta
                    más seguido de lo que el azar explicaría</strong>. Sirve para armar paquetes,
                    guiar la venta sugerida y decidir qué conviene tener listo al mismo tiempo.
                </p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <section class="admin-n1-guide">
            <p class="admin-n1-guide__formula">
                soporte(A,B) = tickets con A y B ÷ tickets totales<br>
                confianza(A→B) = tickets con A y B ÷ tickets con A<br>
                lift(A,B) = soporte(A,B) ÷ (soporte(A) × soporte(B))
            </p>

            <h3 class="admin-n1-guide__title">Qué significa cada columna</h3>
            <dl class="admin-n1-guide__list">
                <div class="admin-n1-guide__item">
                    <dt>Platillo A y Platillo B <span>el par</span></dt>
                    <dd>
                        El par <strong>no tiene dirección</strong>: se ordena alfabéticamente, así que
                        “A” no es la causa de “B”. Es una coincidencia medida, no una secuencia.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Juntos <span>coocurrencias</span></dt>
                    <dd>
                        En cuántas cuentas aparecieron los dos. Se cuenta
                        <strong>una vez por ticket</strong>, aunque el platillo se haya pedido varias
                        veces en esa misma cuenta. Es el peso real de la regla: dice si el par pasa
                        seguido o casi nunca.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Confianza <span>qué tan fiable es</span></dt>
                    <dd>
                        De las cuentas que llevan uno de los dos platillos, qué porcentaje lleva
                        también el otro. Se reporta en la <strong>dirección más fuerte</strong>: se
                        divide entre el platillo <em>menos</em> frecuente de los dos, así que léela
                        como “cuando piden el menos común, tan seguido piden el otro”.
                    </dd>
                </div>
                <div class="admin-n1-guide__item">
                    <dt>Lift <span>la afinidad</span></dt>
                    <dd>
                        Cuántas veces más de lo esperado se piden juntos. <strong>×1 es
                        independencia</strong> (coinciden solo porque ambos son populares) y ×2
                        significa que el par ocurre al doble de lo que predice el azar. La tabla
                        muestra <strong>solo pares con lift mayor a 1</strong>, ordenados de mayor
                        a menor. Esta columna es la que separa afinidad real de simple volumen.
                    </dd>
                </div>
            </dl>

            <h3 class="admin-n1-guide__title">Cómo leer las combinaciones</h3>
            <div class="admin-n1-guide__grid">
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Lift alto, pocos “juntos”</p>
                    <p class="admin-n1-guide__action">
                        Afinidad fuerte pero de nicho: quienes lo piden lo piden casi siempre así,
                        solo que son pocos. Buen candidato a sugerencia dirigida, no a promoción
                        masiva.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Lift moderado, muchos “juntos”</p>
                    <p class="admin-n1-guide__action">
                        La combinación más segura para un paquete o un combo: mueve volumen real y
                        el par ya está probado por los clientes.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Confianza alta</p>
                    <p class="admin-n1-guide__action">
                        Regla accionable para el mesero: al capturar uno de los dos, ofrecer el otro
                        acierta la mayoría de las veces.
                    </p>
                </article>
                <article class="admin-n1-guide__card">
                    <p class="admin-n1-guide__meaning">Un par que esperabas y no aparece</p>
                    <p class="admin-n1-guide__action">
                        No quiere decir que no se pidan juntos: puede que no llegue al mínimo de
                        coocurrencias, que su lift no pase de ×1, o que quedara fuera de los 12
                        primeros.
                    </p>
                </article>
            </div>

            <h3 class="admin-n1-guide__title">Antes de tomar decisiones</h3>
            <ul class="admin-n1-guide__notes">
                <li>
                    Solo entran <strong>tickets cerrados</strong> y renglones no cancelados. El
                    encabezado del panel indica cuántas cuentas se analizaron: con pocos tickets,
                    cualquier par es frágil.
                </li>
                <li>
                    Hay un <strong>mínimo de coocurrencias</strong> para que un par se reporte: 3
                    cuentas cuando el periodo tiene 40 tickets o más, y 2 cuando tiene menos. Evita
                    que una casualidad aislada se lea como patrón.
                </li>
                <li>
                    Se muestran <strong>los 12 pares con mayor lift</strong>. La tabla es un
                    ranking, no el inventario completo de combinaciones.
                </li>
                <li>
                    El lift mide coincidencia, <strong>no causa</strong>. Un par puede aparecer
                    porque ambos platillos pertenecen al mismo momento del día o al mismo tipo de
                    mesa, no porque uno arrastre al otro.
                </li>
            </ul>
        </section>

        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--primary" data-admin-modal-close>Entendido</button>
        </div>
    </div>
</div>