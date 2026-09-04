<?php
/**
 * Agenda de catas del panel.
 *
 * Dejó de ser una tabla. Tenía seis columnas —fecha, cata, cupo con barra de
 * ocupación, precio, estado y acciones— para un módulo en el que casi nunca hay
 * más de cinco filas: el cupo y el estado se fueron con las inscripciones, y lo
 * que quedaba no llenaba una tabla ni se leía como una agenda.
 *
 * Ahora cada cata es una ficha con tres zonas fijas: la fecha a la izquierda
 * —el ancla al escanear—, lo que se lee en la landing en el centro, y a la
 * derecha lo único accionable: el interruptor y las dos acciones. Las tres
 * columnas se mantienen aunque el texto de una ficha sea corto, así que los
 * interruptores caen todos en la misma vertical y se pueden recorrer de un
 * vistazo.
 *
 * Las fichas van AGRUPADAS POR MES, con el encabezado pegajoso. Sustituye a las
 * tres pestañas de filtro (Todas / Con cupo / Sin cupo), que se retiraron: una
 * agenda se recorre por fecha, y filtrar por cupo dejó de servir de nada cuando
 * el interruptor pasó a leerse en la propia fila. El buscador sólo aparece a
 * partir de $minimoBuscador catas — con cuatro filas ocupaba más alto que media
 * lista.
 *
 * El controlador sigue aceptando ?disponible y ?q: quien tenga un enlace
 * guardado no se queda sin nada, y el buscador los usa cuando aparece.
 *
 * Dos cosas distintas que la ficha tiene que separar, porque es donde el módulo
 * se presta a confusión:
 *
 *   · el ESTADO DE PORTADA lo decide la fecha y no se puede tocar desde aquí.
 *     Se pinta en el cuerpo, como información, y en texto llano: como badge
 *     pedía que lo tocaran.
 *   · el CUPO lo decide el interruptor de la derecha, y apagarlo NO retira la
 *     cata de la landing: la deja anunciada como «sin cupo».
 */

$catas = $catas ?? [];
$disponibilidadActiva = (string)($disponibilidadActiva ?? '');
$busqueda = (string)($busqueda ?? '');
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

// $meses y $anioActual salieron con los encabezados de mes: la tabla imprime la
// fecha completa en cada fila, así que ya no hay rótulo de grupo que componer.
$diasCortos = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];

// A partir de aquí una lista deja de recorrerse con la vista y el buscador
// empieza a ganarse el sitio que ocupa. Variable y no `const`: Router::render()
// incluye la vista dentro de un método, y ahí `const` es error de compilación.
$minimoBuscador = 12;

$disponibles = 0;
foreach ($catas as $fila) {
    $disponibles += !empty($fila['disponible']) ? 1 : 0;
}

$hayBuscador = count($catas) >= $minimoBuscador || $busqueda !== '' || $disponibilidadActiva !== '';

// Los filtros viajan con cada interruptor para volver a la misma vista.
$filtrosDeVuelta = static function () use ($disponibilidadActiva, $busqueda, $e): string {
    $html = '';
    if ($disponibilidadActiva !== '') {
        $html .= '<input type="hidden" name="volver_disponible" value="' . $e($disponibilidadActiva) . '">';
    }
    if ($busqueda !== '') {
        $html .= '<input type="hidden" name="volver_q" value="' . $e($busqueda) . '">';
    }
    return $html;
};
?>
<section class="admin-catas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Experiencias</span>
            <h2 class="admin-page__title">Catas</h2>
            <p class="admin-page__subtitle">
                Programa las sesiones y marca cuáles siguen admitiendo gente. Toda cata con
                fecha por delante se anuncia en la landing; el interruptor sólo dice si le
                quedan lugares. Las inscripciones se atienden por WhatsApp.
            </p>
            <?php if (!empty($catas)) : ?>
                <p class="admin-page__subtitle">
                    <?php echo count($catas); ?> cata<?php echo count($catas) === 1 ? '' : 's'; ?>
                    en la agenda · <?php echo $disponibles; ?> con cupo.
                </p>
            <?php endif; ?>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--primary" href="/admin/catas/crear">Nueva cata</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php if ($hayBuscador) : ?>
        <div class="admin-catas__barra">
            <form class="admin-catas__buscador" method="GET" action="/admin/catas" role="search">
                <?php if ($disponibilidadActiva !== '') : ?>
                    <input type="hidden" name="disponible" value="<?php echo $e($disponibilidadActiva); ?>">
                <?php endif; ?>
                <label class="admin-catas__buscador-label" for="cata-q">Buscar cata</label>
                <input class="admin-catas__input" type="search" id="cata-q" name="q"
                       value="<?php echo $e($busqueda); ?>" placeholder="Título de la cata">
                <button class="admin-btn admin-btn--secondary" type="submit">Buscar</button>
                <?php if ($busqueda !== '' || $disponibilidadActiva !== '') : ?>
                    <a class="admin-btn admin-btn--ghost" href="/admin/catas">Limpiar</a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <section class="admin-panel admin-card admin-catas__panel">
        <?php if (empty($catas)) : ?>
            <div class="admin-catas__panel-head">
                <h3>Agenda</h3>
            </div>
            <p class="admin-empty">
                <?php if ($busqueda !== '' || $disponibilidadActiva !== '') : ?>
                    Ninguna cata coincide con este filtro.
                    <a href="/admin/catas">Ver todas</a>.
                <?php else : ?>
                    Todavía no hay catas programadas.
                    <a href="/admin/catas/crear">Programa la primera</a>.
                <?php endif; ?>
            </p>
        <?php else : ?>
            <?php /*
                Tabla de cuatro columnas: fecha, título, cupo y acciones.
                Antes era una lista de fichas con la descripción recortada, la
                meta de hora/duración/precio y una línea de estado de portada;
                eran cinco datos por fila para una agenda que se consulta de un
                vistazo. Todo eso sigue en el formulario de edición, que es
                donde se cambia.

                El corte entre lo que viene y lo que ya pasó se conserva —es lo
                único que decide si la cata está en la portada— pero ahora es una
                fila separadora dentro del <tbody> en vez de un encabezado
                pegajoso.

                SIN data-sortable, y a propósito: esa fila separadora es parte
                del <tbody>, así que cualquier reordenación la arrastraría a un
                sitio donde ya no separa nada. El orden de una agenda es el
                cronológico, que es el que da
                CataService::listaAdministrativa() —futuras hacia delante y
                luego pasadas hacia atrás—, y no hay un segundo criterio que
                aporte.
            */ ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-catas__tabla">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Cata</th>
                            <th>Cupo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $yaEnPasado = false; ?>
                        <?php foreach ($catas as $cata) : ?>
                            <?php
                            $id = (int)$cata['id'];
                            $inicio = $cata['inicio'] ?? null;
                            $esFutura = !empty($cata['es_futura']);
                            $disponible = !empty($cata['disponible']);
                            $switchId = 'cata-disponible-' . $id;
                            ?>

                            <?php if (!$esFutura && !$yaEnPasado) : ?>
                                <?php $yaEnPasado = true; ?>
                                <tr class="admin-catas__corte">
                                    <td colspan="4">Ya ocurrieron · fuera de la portada</td>
                                </tr>
                            <?php endif; ?>

                            <tr class="<?php echo $esFutura ? '' : 'admin-catas__fila--pasada'; ?>">
                                <td>
                                    <span class="admin-table__cell-main admin-num">
                                        <?php echo $inicio ? $e($inicio->format('d/m/Y')) : '—'; ?>
                                    </span>
                                    <span class="admin-table__cell-sub">
                                        <?php if ($inicio) : ?>
                                            <?php echo $e($diasCortos[(int)$inicio->format('w')] . ' · ' . $inicio->format('H:i') . ' h'); ?>
                                        <?php else : ?>
                                            Sin fecha
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="admin-catas__enlace" href="/admin/catas/editar?id=<?php echo $id; ?>">
                                        <?php echo $e($cata['titulo']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php /* El interruptor es un formulario por fila: catas.js lo
                                             envía al cambiar y el <noscript> deja el botón a la
                                             vista si el JS no llegó. El checkbox manda el estado
                                             que se QUIERE dejar, no el actual.

                                             Sólo decide el CUPO: la visibilidad en la portada la
                                             decide la fecha, que es lo que dice la primera
                                             columna y la fila separadora de más arriba. */ ?>
                                    <form class="admin-cata__switch-form" method="POST" action="/admin/catas/disponibilidad">
                                        <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <?php echo $filtrosDeVuelta(); ?>
                                        <label class="admin-switch" for="<?php echo $switchId; ?>">
                                            <input type="checkbox" id="<?php echo $switchId; ?>" name="disponible" value="1"
                                                   data-cata-switch <?php echo $disponible ? 'checked' : ''; ?>>
                                            <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                                            <span class="admin-switch__label" data-cata-switch-label><?php
                                                echo $disponible ? 'Con cupo' : 'Sin cupo';
                                            ?></span>
                                        </label>
                                        <noscript>
                                            <button class="admin-btn admin-btn--secondary admin-btn--small" type="submit">Aplicar</button>
                                        </noscript>
                                    </form>
                                </td>
                                <td>
                                    <?php /* Siempre visibles: en la lista de fichas se ocultaban
                                             hasta el hover, y en una tabla eso deja una columna
                                             vacía que nadie descubre. */ ?>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit"
                                           href="/admin/catas/editar?id=<?php echo $id; ?>"
                                           title="Editar"
                                           aria-label="Editar <?php echo $e($cata['titulo']); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <?php /* Sin confirm() nativo: admin.js engancha [data-confirm-delete]
                                                 al ConfirmationModal de la casa (ver CLAUDE.md). */ ?>
                                        <form method="POST" action="/admin/catas/eliminar"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar cata"
                                              data-confirm-title="¿Eliminar «<?php echo $e($cata['titulo']); ?>»?"
                                              data-confirm-description="Se borrará de la agenda y dejará de anunciarse en la landing."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger"
                                                    title="Eliminar"
                                                    aria-label="Eliminar <?php echo $e($cata['titulo']); ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
