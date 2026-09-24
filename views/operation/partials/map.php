<?php
/**
 * Componente visual compartido del mapa de mesas.
 *
 * Espera un arreglo $mapVisual. El consumidor conserva fuera de este parcial
 * toda decision de seleccion, asignacion, tickets y reglas de disponibilidad.
 */

$mapVisual = is_array($mapVisual ?? null) ? $mapVisual : [];
$mapEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$mapContext = (string)($mapVisual['context'] ?? 'mapa-mesas');
$mapSectionClass = trim((string)($mapVisual['sectionClass'] ?? ''));
$mapTitle = (string)($mapVisual['title'] ?? 'Mapa de mesas');
$mapAriaLabel = (string)($mapVisual['ariaLabel'] ?? ($mapTitle !== '' ? $mapTitle : 'Mapa operativo'));
$mapTitleId = trim((string)($mapVisual['titleId'] ?? ''));
$mapSubtitle = (string)($mapVisual['subtitle'] ?? '');
$mapToolbarActionsHtml = (string)($mapVisual['toolbarActionsHtml'] ?? ($mapVisual['leadingHtml'] ?? ''));
$mapHelpPosition = (string)($mapVisual['helpPosition'] ?? 'header');
$mapHelpPosition = in_array($mapHelpPosition, ['header', 'overlay'], true) ? $mapHelpPosition : 'header';
$mapHelpIdSuffix = preg_replace('/[^a-z0-9_-]+/i', '-', $mapContext) ?: 'mapa-mesas';
$mapHelpDialogId = 'map-help-dialog-' . $mapHelpIdSuffix;
$mapHelpTitleId = $mapHelpDialogId . '-title';
$mapHelpButtonHtml = '<button type="button" class="map-help-button map-help-button--header" data-map-help-open aria-label="Ayuda sobre los estados del mapa" title="Ayuda sobre los estados del mapa" aria-controls="' . $mapEscape($mapHelpDialogId) . '" aria-haspopup="dialog">'
    . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M9.7 9a2.4 2.4 0 1 1 3.8 1.9c-1 .7-1.5 1.1-1.5 2.6M12 17h.01"></path></svg>'
    . '</button>';
$mapCanvasId = trim((string)($mapVisual['canvasId'] ?? ''));
$mapCanvasMode = (string)($mapVisual['canvasMode'] ?? 'map');
$mapLoadingMode = (string)($mapVisual['loadingMode'] ?? 'empty');
// 'none' deja el mapa sin leyenda: lo usa el POS, donde el color de la mesa es
// la única nomenclatura.
$mapLegendPosition = (string)($mapVisual['legendPosition'] ?? 'header');
$mapLegendPosition = in_array($mapLegendPosition, ['header', 'footer', 'none'], true) ? $mapLegendPosition : 'header';
// Alternativa accesible al mapa. Opt-in: en el POS le robaba alto al mapa en la
// tablet, y el listado de mesas ahora vive en /admin/punto-de-venta.
$mapStructuredList = (bool)($mapVisual['structuredList'] ?? false);
$mapShowHeading = $mapTitle !== '' || $mapSubtitle !== '';
$mapHasHeaderActions = $mapToolbarActionsHtml !== '' || $mapHelpPosition === 'header';
$mapHeadClass = $mapHasHeaderActions ? ' operational-map-head--with-trigger' : '';
$mapHeadClass .= !$mapShowHeading ? ' operational-map-head--legend-only' : '';
$mapShowHeader = $mapShowHeading || $mapHasHeaderActions || $mapLegendPosition === 'header';
?>

<section
    class="operational-map mesas-map operational-map-card<?php echo $mapSectionClass !== '' ? ' ' . $mapEscape($mapSectionClass) : ''; ?>"
    data-map-component
    data-map-context="<?php echo $mapEscape($mapContext); ?>"
    <?php echo $mapTitleId !== '' ? 'aria-labelledby="' . $mapEscape($mapTitleId) . '"' : 'aria-label="' . $mapEscape($mapAriaLabel) . '"'; ?>
>
    <?php if ($mapShowHeader): ?>
        <div class="operational-map__toolbar operational-map__header mesas-map__toolbar mesas-map__header operational-map-head<?php echo $mapHeadClass; ?>">
            <?php if ($mapShowHeading): ?>
                <div class="operational-map__heading mesas-map__heading operational-map-heading">
                    <h2 class="operational-map__title mesas-map__title operational-map-title"<?php echo $mapTitleId !== '' ? ' id="' . $mapEscape($mapTitleId) . '"' : ''; ?>><?php echo $mapEscape($mapTitle); ?></h2>
                    <?php if ($mapSubtitle !== ''): ?>
                        <p><?php echo $mapEscape($mapSubtitle); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($mapHasHeaderActions): ?>
                <div class="operational-map__toolbar-actions mesas-map__toolbar-actions">
                    <?php echo $mapToolbarActionsHtml; ?>
                    <?php if ($mapHelpPosition === 'header'): ?>
                        <?php echo str_replace('map-help-button--header', '', $mapHelpButtonHtml); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($mapLegendPosition === 'header'): ?>
                <?php include __DIR__ . '/map-legend.php'; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="operational-map__viewport mesas-map__viewport mapa-canvas-wrap operational-map-canvas-wrap">
        <?php if ($mapHelpPosition === 'overlay'): ?>
            <?php echo str_replace('map-help-button--header', 'map-help-button--overlay', $mapHelpButtonHtml); ?>
        <?php endif; ?>
        <div
            class="operational-map__floor mesas-map__floor mapa-canvas"
            <?php echo $mapCanvasId !== '' ? 'id="' . $mapEscape($mapCanvasId) . '"' : ''; ?>
            <?php echo $mapCanvasMode === 'operation' ? 'data-operation-map' : ''; ?>
            data-map-context="<?php echo $mapEscape($mapContext); ?>"
        >
            <?php if ($mapLoadingMode === 'empty'): ?>
                <div class="mapa-empty-state" role="status" aria-live="polite"><span class="mapa-empty-icon" aria-hidden="true"><span class="mapa-empty-spinner"></span></span><span>Cargando mapa</span></div>
            <?php endif; ?>
        </div>

        <?php if ($mapLoadingMode === 'overlay'): ?>
            <div class="mapa-canvas-overlay" id="mapa-loading" role="status" aria-live="polite">
                <div class="mapa-spinner" aria-hidden="true"></div>
                <span class="operational-visually-hidden">Cargando mapa</span>
            </div>
        <?php endif; ?>
    </div>

    <dialog
        class="map-help-dialog"
        id="<?php echo $mapEscape($mapHelpDialogId); ?>"
        aria-modal="true"
        aria-labelledby="<?php echo $mapEscape($mapHelpTitleId); ?>"
        tabindex="-1"
        data-map-help-dialog
    >
        <div class="map-help-dialog__surface">
            <header class="map-help-dialog__header">
                <h2 id="<?php echo $mapEscape($mapHelpTitleId); ?>">Cómo interpretar el mapa de mesas</h2>
                <button type="button" class="map-help-dialog__close" data-map-help-close aria-label="Cerrar ayuda del mapa" title="Cerrar ayuda del mapa" autofocus>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
                </button>
            </header>
            <div class="map-help-dialog__body">
                <ul class="map-help-dialog__states" aria-label="Estados del mapa">
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre"><span class="mesa-pin__label">Mesa 4</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Verde</strong><span>Mesa disponible en el contexto que muestra esta pantalla.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre mesa-pin--mod-reservacion_advertencia"><span class="mesa-pin__label">Mesa 5</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Verde con borde azul discontinuo</strong><span>Hay una reservación cercana. El fondo conserva la disponibilidad real; revisa la advertencia antes de operar.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--reservacion-proxima mesa-pin--mod-reservacion_inminente"><span class="mesa-pin__label">Mesa 6</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Azul</strong><span>Una reservación está próxima o el cliente llegó dentro de su tolerancia.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--reservacion-proxima mesa-pin--mod-ausencia_pendiente mesa-pin--mod-accion_pendiente"><span class="mesa-pin__label">Mesa 7</span><span class="mesa-pin__pending">!</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Azul oscuro con indicador de alerta</strong><span>Tolerancia vencida. Registra que el cliente no llegó antes de utilizar la mesa.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--ocupada"><span class="mesa-pin__label">Mesa 8</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Rojo</strong><span>Ocupada por un ticket o no disponible para el intervalo seleccionado, según el mapa.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre mesa-pin--seleccionada"><span class="mesa-pin__label">Mesa 9</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Amarillo</strong><span>Mesa seleccionada. La selección no elimina bloqueos ni conflictos.</span></span>
                    </li>
                    <li class="map-help-dialog__state">
                        <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--no-utilizable"><span class="mesa-pin__label">Mesa 10</span></span></span>
                        <span class="map-help-dialog__copy"><strong>Neutro</strong><span>Mesa no utilizable en este contexto.</span></span>
                    </li>
                </ul>
                <div class="map-help-dialog__contexts">
                    <section>
                        <h3>Punto de Venta</h3>
                        <p>Muestra principalmente la operación actual. El rojo indica un ticket abierto; el azul, una reservación que espera al cliente o está próxima. Si el backend bloquea un walk-in por una ausencia pendiente, resuélvela antes de usar la mesa.</p>
                    </section>
                    <section>
                        <h3>Reservaciones</h3>
                        <p>Muestra el intervalo elegido. El rojo puede indicar un ticket o el intervalo de una reservación, aunque el cliente aún no haya llegado. Una mesa ocupada ahora puede aparecer disponible en una proyección futura si su liberación estimada y las reglas lo permiten.</p>
                    </section>
                </div>
                <p class="map-help-dialog__note">Los colores son informativos. La disponibilidad definitiva y las acciones permitidas se verifican al realizar la operación.</p>
            </div>
        </div>
    </dialog>

    <?php if ($mapStructuredList): ?>
        <details class="operational-map__structured" data-map-structured-details>
            <summary>Lista de mesas</summary>
            <div class="operational-map__structured-list" data-map-structured-list role="list"></div>
        </details>
    <?php endif; ?>

    <?php if ($mapLegendPosition === 'footer'): ?>
        <div class="operational-map__footer mesas-map__footer">
            <?php include __DIR__ . '/map-legend.php'; ?>
        </div>
    <?php endif; ?>
</section>

<?php unset($mapVisual, $mapEscape, $mapContext, $mapSectionClass, $mapTitle, $mapAriaLabel, $mapTitleId, $mapSubtitle, $mapToolbarActionsHtml, $mapHelpPosition, $mapHelpIdSuffix, $mapHelpDialogId, $mapHelpTitleId, $mapHelpButtonHtml, $mapHasHeaderActions, $mapCanvasId, $mapCanvasMode, $mapLoadingMode, $mapLegendPosition, $mapStructuredList, $mapShowHeading, $mapHeadClass, $mapShowHeader, $mapLegendBlueLabel); ?>
