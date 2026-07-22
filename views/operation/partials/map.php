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
$mapTitleId = trim((string)($mapVisual['titleId'] ?? ''));
$mapSubtitle = (string)($mapVisual['subtitle'] ?? '');
$mapLeadingHtml = (string)($mapVisual['leadingHtml'] ?? '');
$mapCanvasId = trim((string)($mapVisual['canvasId'] ?? ''));
$mapCanvasMode = (string)($mapVisual['canvasMode'] ?? 'map');
$mapLoadingMode = (string)($mapVisual['loadingMode'] ?? 'empty');
$mapHeadClass = $mapLeadingHtml !== '' ? ' operational-map-head--with-trigger' : '';
?>

<section
    class="operational-map mesas-map operational-map-card<?php echo $mapSectionClass !== '' ? ' ' . $mapEscape($mapSectionClass) : ''; ?>"
    data-map-component
    data-map-context="<?php echo $mapEscape($mapContext); ?>"
    <?php echo $mapTitleId !== '' ? 'aria-labelledby="' . $mapEscape($mapTitleId) . '"' : 'aria-label="' . $mapEscape($mapTitle) . '"'; ?>
>
    <div class="operational-map__header mesas-map__header operational-map-head<?php echo $mapHeadClass; ?>">
        <?php echo $mapLeadingHtml; ?>

        <div class="operational-map__heading mesas-map__heading operational-map-heading">
            <span class="operational-map__title mesas-map__title operational-map-title"<?php echo $mapTitleId !== '' ? ' id="' . $mapEscape($mapTitleId) . '"' : ''; ?>><?php echo $mapEscape($mapTitle); ?></span>
            <?php if ($mapSubtitle !== ''): ?>
                <p<?php echo $mapCanvasMode === 'operation' ? ' data-operation-map-status' : ''; ?>><?php echo $mapEscape($mapSubtitle); ?></p>
            <?php endif; ?>
        </div>

        <div class="operational-map__legend mesas-map__legend mapa-leyenda" aria-label="Estados de mesas" data-map-legend>
            <span class="mapa-leyenda-item mapa-leyenda-item--libre">Libre</span>
            <span class="mapa-leyenda-item mapa-leyenda-item--ocupada">Ocupada</span>
            <span class="mapa-leyenda-item mapa-leyenda-item--bloqueada">Asignada</span>
            <span class="mapa-leyenda-item mapa-leyenda-item--seleccionada">Seleccionada</span>
            <span class="mapa-leyenda-item mapa-leyenda-item--zona">No reservable</span>
        </div>
    </div>

    <div class="operational-map__viewport mesas-map__viewport mapa-canvas-wrap operational-map-canvas-wrap">
        <div
            class="operational-map__floor mesas-map__floor mapa-canvas"
            <?php echo $mapCanvasId !== '' ? 'id="' . $mapEscape($mapCanvasId) . '"' : ''; ?>
            <?php echo $mapCanvasMode === 'operation' ? 'data-operation-map' : ''; ?>
            data-map-context="<?php echo $mapEscape($mapContext); ?>"
        >
            <?php if ($mapLoadingMode === 'empty'): ?>
                <div class="mapa-empty-state"><span class="mapa-empty-icon" aria-hidden="true">o</span><span>Cargando mapa</span></div>
            <?php endif; ?>
        </div>

        <?php if ($mapLoadingMode === 'overlay'): ?>
            <div class="mapa-canvas-overlay" id="mapa-loading">
                <div class="mapa-spinner"></div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php unset($mapVisual, $mapEscape, $mapContext, $mapSectionClass, $mapTitle, $mapTitleId, $mapSubtitle, $mapLeadingHtml, $mapCanvasId, $mapCanvasMode, $mapLoadingMode, $mapHeadClass); ?>
