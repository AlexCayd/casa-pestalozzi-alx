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
$mapCanvasId = trim((string)($mapVisual['canvasId'] ?? ''));
$mapCanvasMode = (string)($mapVisual['canvasMode'] ?? 'map');
$mapLoadingMode = (string)($mapVisual['loadingMode'] ?? 'empty');
// 'none' deja el mapa sin leyenda: lo usa el POS, donde el color de la mesa es
// la única nomenclatura.
$mapLegendPosition = (string)($mapVisual['legendPosition'] ?? 'header');
$mapLegendPosition = in_array($mapLegendPosition, ['header', 'footer', 'none'], true) ? $mapLegendPosition : 'header';
$mapShowHeading = $mapTitle !== '' || $mapSubtitle !== '';
$mapHeadClass = $mapToolbarActionsHtml !== '' ? ' operational-map-head--with-trigger' : '';
$mapHeadClass .= !$mapShowHeading ? ' operational-map-head--legend-only' : '';
$mapShowHeader = $mapShowHeading || $mapToolbarActionsHtml !== '' || $mapLegendPosition === 'header';
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

            <?php if ($mapToolbarActionsHtml !== ''): ?>
                <div class="operational-map__toolbar-actions mesas-map__toolbar-actions">
                    <?php echo $mapToolbarActionsHtml; ?>
                </div>
            <?php endif; ?>

            <?php if ($mapLegendPosition === 'header'): ?>
                <?php include __DIR__ . '/map-legend.php'; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="operational-map__viewport mesas-map__viewport mapa-canvas-wrap operational-map-canvas-wrap">
        <div
            class="operational-map__floor mesas-map__floor mapa-canvas"
            <?php echo $mapCanvasId !== '' ? 'id="' . $mapEscape($mapCanvasId) . '"' : ''; ?>
            <?php echo $mapCanvasMode === 'operation' ? 'data-operation-map' : ''; ?>
            data-map-context="<?php echo $mapEscape($mapContext); ?>"
        >
            <?php if ($mapLoadingMode === 'empty'): ?>
                <div class="mapa-empty-state" role="status" aria-live="polite"><span class="mapa-empty-icon" aria-hidden="true">o</span><span>Cargando mapa</span></div>
            <?php endif; ?>
        </div>

        <?php if ($mapLoadingMode === 'overlay'): ?>
            <div class="mapa-canvas-overlay" id="mapa-loading" role="status" aria-live="polite">
                <div class="mapa-spinner" aria-hidden="true"></div>
                <span class="operational-visually-hidden">Cargando mapa</span>
            </div>
        <?php endif; ?>
    </div>

    <details class="operational-map__structured" data-map-structured-details>
        <summary>Lista estructurada de mesas</summary>
        <div class="operational-map__structured-list" data-map-structured-list role="list"></div>
    </details>

    <?php if ($mapLegendPosition === 'footer'): ?>
        <div class="operational-map__footer mesas-map__footer">
            <?php include __DIR__ . '/map-legend.php'; ?>
        </div>
    <?php endif; ?>
</section>

<?php unset($mapVisual, $mapEscape, $mapContext, $mapSectionClass, $mapTitle, $mapAriaLabel, $mapTitleId, $mapSubtitle, $mapToolbarActionsHtml, $mapCanvasId, $mapCanvasMode, $mapLoadingMode, $mapLegendPosition, $mapShowHeading, $mapHeadClass, $mapShowHeader, $mapLegendBlueLabel); ?>
