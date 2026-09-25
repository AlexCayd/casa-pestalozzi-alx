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
$mapHelpContext = (string)($mapVisual['helpContext'] ?? 'pos');
$mapHelpContext = in_array($mapHelpContext, ['pos', 'reservations'], true) ? $mapHelpContext : 'pos';
$mapHelpSubtitle = $mapHelpContext === 'reservations'
    ? 'Consulta la disponibilidad para la fecha y hora seleccionadas.'
    : 'Consulta el estado actual de las mesas.';
$mapHelpContextNotes = $mapHelpContext === 'reservations'
    ? [
        'El fondo representa la disponibilidad del intervalo seleccionado; los bordes y los iconos muestran alertas adicionales.',
        'Una reservación iniciada puede aparecer roja antes de que llegue el cliente.',
        'La ausencia pendiente puede coexistir con distintos colores de fondo.',
    ]
    : [
        'Entre 60 y 30 minutos antes aparece una advertencia. A partir de 30 minutos antes, se bloquea el walk-in.',
        'Hasta 15 minutos después del inicio hay tolerancia; después puede quedar una ausencia pendiente.',
        'En POS, el rojo suele indicar un ticket abierto; también puede reflejar una restricción operativa.',
    ];
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
$mapHelpStateHeadingId = $mapHelpDialogId . '-state-heading';
$mapHelpSignalsHeadingId = $mapHelpDialogId . '-signals-heading';
$mapHelpButtonHtml = '<button type="button" class="map-help-button map-help-button--header" data-map-help-open aria-label="Ayuda sobre los estados del mapa" title="Ayuda sobre los estados del mapa" aria-controls="' . $mapEscape($mapHelpDialogId) . '" aria-haspopup="dialog">'
    . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M9.7 9a2.4 2.4 0 1 1 3.8 1.9c-1 .7-1.5 1.1-1.5 2.6M12 17h.01"></path></svg>'
    . '</button>';
$mapCanvasId = trim((string)($mapVisual['canvasId'] ?? ''));
$mapCanvasMode = (string)($mapVisual['canvasMode'] ?? 'map');
$mapLoadingMode = (string)($mapVisual['loadingMode'] ?? 'empty');
// 'none' omite la leyenda permanente; ambos mapas usan el diálogo compartido
// para consultar la nomenclatura completa.
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

    <div class="operational-map__validation-status" data-map-validation-status role="status" aria-live="polite" hidden></div>
    <div class="operational-map__viewport mesas-map__viewport mapa-canvas-wrap operational-map-canvas-wrap">
        <div class="operational-map__query-notice" data-map-query-status role="status" aria-live="polite" hidden></div>
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
                <div class="map-help-dialog__heading">
                    <h2 id="<?php echo $mapEscape($mapHelpTitleId); ?>" tabindex="-1" data-map-help-initial-focus autofocus>Cómo interpretar el mapa de mesas</h2>
                    <p class="map-help-dialog__intro"><?php echo $mapEscape($mapHelpSubtitle); ?></p>
                </div>
                <button type="button" class="map-help-dialog__close" data-map-help-close aria-label="Cerrar ayuda del mapa" title="Cerrar ayuda del mapa">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
                </button>
            </header>
            <div class="map-help-dialog__body">
                <div class="map-help-dialog__groups">
                    <section class="map-help-dialog__group" aria-labelledby="<?php echo $mapEscape($mapHelpStateHeadingId); ?>">
                        <h3 id="<?php echo $mapEscape($mapHelpStateHeadingId); ?>">Estado de las mesas</h3>
                        <ul class="map-help-dialog__states">
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Disponible</strong><span>Mesa disponible en el contexto mostrado.</span></span>
                            </li>
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--ocupada"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Ocupada</strong><span>Existe un ticket o un bloqueo operativo.</span></span>
                            </li>
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--reservacion-proxima mesa-pin--mod-reservacion_inminente"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Reservación próxima</strong><span>Mesa comprometida por una reservación.</span></span>
                            </li>
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--no-utilizable"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>No utilizable</strong><span>Mesa o elemento no disponible para esta operación.</span></span>
                            </li>
                        </ul>
                    </section>
                    <section class="map-help-dialog__group" aria-labelledby="<?php echo $mapEscape($mapHelpSignalsHeadingId); ?>">
                        <h3 id="<?php echo $mapEscape($mapHelpSignalsHeadingId); ?>">Señales adicionales</h3>
                        <ul class="map-help-dialog__states">
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre mesa-pin--mod-reservacion_advertencia"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Reserva cercana</strong><span>Hay una reservación próxima. El borde no cambia por sí solo la disponibilidad.</span></span>
                            </li>
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--reservacion-proxima mesa-pin--mod-ausencia_pendiente mesa-pin--mod-accion_pendiente"><span class="mesa-pin__label">Mesa</span><span class="mesa-pin__pending">!</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Ausencia pendiente</strong><span>La tolerancia venció. Revisa la reservación y registra la ausencia cuando esté permitido.</span></span>
                            </li>
                            <li class="map-help-dialog__state">
                                <span class="map-help-dialog__sample" aria-hidden="true"><span class="mesa-pin mesa-pin--libre mesa-pin--seleccionada"><span class="mesa-pin__label">Mesa</span></span></span>
                                <span class="map-help-dialog__copy"><strong>Seleccionada</strong><span>La selección es una capa superpuesta; no garantiza disponibilidad ni elimina las restricciones.</span></span>
                            </li>
                        </ul>
                    </section>
                </div>
                <div class="map-help-dialog__context-note" role="note">
                    <ul>
                        <?php foreach ($mapHelpContextNotes as $mapHelpContextNote): ?>
                            <li><?php echo $mapEscape($mapHelpContextNote); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="map-help-dialog__note" role="note">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5m0-8h.01"></path></svg>
                    <p>Los colores orientan; las acciones se verifican al realizar la operación.</p>
                </div>
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

<?php unset($mapVisual, $mapEscape, $mapContext, $mapHelpContext, $mapHelpSubtitle, $mapHelpAvailableCopy, $mapHelpOccupiedCopy, $mapSectionClass, $mapTitle, $mapAriaLabel, $mapTitleId, $mapSubtitle, $mapToolbarActionsHtml, $mapHelpPosition, $mapHelpIdSuffix, $mapHelpDialogId, $mapHelpTitleId, $mapHelpButtonHtml, $mapHasHeaderActions, $mapCanvasId, $mapCanvasMode, $mapLoadingMode, $mapLegendPosition, $mapStructuredList, $mapShowHeading, $mapHeadClass, $mapShowHeader, $mapLegendBlueLabel); ?>
