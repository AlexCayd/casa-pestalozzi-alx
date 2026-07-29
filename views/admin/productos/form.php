<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar';
    $prod = $producto ?? null;
    $areas = isset($areas) && is_iterable($areas) ? $areas : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $subrecetas = isset($subrecetas) && is_iterable($subrecetas) ? $subrecetas : [];
    $componentes = isset($componentes) && is_iterable($componentes) ? $componentes : [];

    // Catálogo de opciones para el buscador de componentes (ingredientes + subrecetas).
    $opciones = [];
    foreach ($ingredientes as $ing) {
        $opciones[] = [
            'value' => 'ingrediente:' . (int) $ing->id,
            'label' => $ing->nombre . ' (' . $ing->unidad . ')',
            'group' => 'Ingredientes',
        ];
    }
    foreach ($subrecetas as $sub) {
        $opciones[] = [
            'value' => 'subreceta:' . (int) $sub->id,
            'label' => $sub->nombre . ' (subreceta)',
            'group' => 'Subrecetas',
        ];
    }

    // Texto a mostrar en el buscador para un componente ya guardado.
    $comboLabel = static function (array $comp): string {
        if (($comp['tipo'] ?? '') === 'subreceta') {
            return ($comp['nombre'] ?? '') . ' (subreceta)';
        }
        return ($comp['nombre'] ?? '') . ' (' . ($comp['unidad'] ?? '') . ')';
    };
?>
<section class="admin-productos admin-productos--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Productos</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Producto'); ?></h2>
            <p class="admin-page__subtitle">El nombre debe coincidir con el del platillo en el punto de venta para que la receta descuente inventario.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/productos">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <form method="POST" data-recipe-builder>
        <section class="admin-panel admin-card">
            <?php include __DIR__ . '/../partials/alertas.php'; ?>

            <div class="admin-form-grid">
                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="nombre">Nombre del producto</label>
                    <input type="text" id="nombre" name="nombre" maxlength="120" required
                           value="<?php echo htmlspecialchars($prod->nombre ?? ''); ?>">
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Categoría</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Categoría">
                        <?php foreach ($categorias as $cat) : $cid = (int) $cat['id']; ?>
                            <label class="admin-tabs__tab">
                                <input type="radio" name="categoria_id" value="<?php echo $cid; ?>"
                                       <?php echo (int) ($prod->categoria_id ?? 0) === $cid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($cat['nombre']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Área de producción</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Área de producción">
                        <?php foreach ($areas as $area) : $aid = (int) $area['id']; ?>
                            <label class="admin-tabs__tab">
                                <input type="radio" name="area_id" value="<?php echo $aid; ?>"
                                       <?php echo (int) ($prod->area_id ?? 0) === $aid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($area['nombre']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="precio">Precio (MXN)</label>
                    <input type="number" id="precio" name="precio" step="0.01" min="0" required
                           value="<?php echo htmlspecialchars((string) ($prod->precio ?? '')); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="tag">Etiqueta de la carta</label>
                    <input type="text" id="tag" name="tag" maxlength="60"
                           placeholder="Especialidad C.P., Estrella…"
                           value="<?php echo htmlspecialchars((string) ($prod->tag ?? '')); ?>">
                </div>

                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="3"
                              placeholder="Texto que se imprime en la carta y en el PDF"><?php echo htmlspecialchars((string) ($prod->descripcion ?? '')); ?></textarea>
                    <p class="admin-field__help">Opcional. Sin descripción el producto se imprime en la carta solo con nombre y precio, como las bebidas.</p>
                </div>

                <label class="admin-switch admin-form-grid__full">
                    <input type="checkbox" name="activo" value="1" <?php echo (int) ($prod->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Producto activo — se vende en el punto de venta y aparece en la carta</span>
                </label>
            </div>
        </section>

        <section class="admin-panel admin-card admin-recipe">
            <div class="admin-recipe__head">
                <div>
                    <span class="admin-recipe__eyebrow">Receta principal</span>
                    <h3>Componentes que consume una unidad</h3>
                    <p>Agrega ingredientes o subrecetas con la cantidad que consume el producto. Las subrecetas se explotan hasta sus ingredientes al descontar inventario.</p>
                </div>
            </div>

            <?php if (empty($ingredientes) && empty($subrecetas)) : ?>
                <p class="admin-empty">Primero registra ingredientes (y opcionalmente subrecetas) para poder armar la receta.</p>
            <?php else : ?>
                <script type="application/json" data-recipe-options>
                    <?php echo json_encode($opciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                </script>

                <div class="admin-recipe__rows" data-recipe-rows>
                    <?php foreach ($componentes as $comp) : ?>
                        <?php $sel = $comp['tipo'] . ':' . (int) $comp['ref_id']; ?>
                        <div class="admin-recipe__row" data-recipe-row>
                            <div class="admin-combo" data-combo>
                                <input type="text" class="admin-combo__search" data-combo-search autocomplete="off"
                                       placeholder="Buscar ingrediente o subreceta…"
                                       value="<?php echo htmlspecialchars($comboLabel($comp), ENT_QUOTES); ?>"
                                       aria-label="Componente">
                                <input type="hidden" name="comp[]" data-combo-value value="<?php echo htmlspecialchars($sel, ENT_QUOTES); ?>">
                                <ul class="admin-combo__list" data-combo-list hidden></ul>
                            </div>
                            <input type="number" name="comp_cant[]" step="0.001" min="0" placeholder="Cantidad"
                                   value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $comp['cantidad'], 3, '.', ''), '0'), '.')); ?>"
                                   aria-label="Cantidad">
                            <button type="button" class="admin-icon-button admin-icon-button--danger" data-recipe-remove aria-label="Quitar componente">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <template data-recipe-template>
                    <div class="admin-recipe__row" data-recipe-row>
                        <div class="admin-combo" data-combo>
                            <input type="text" class="admin-combo__search" data-combo-search autocomplete="off"
                                   placeholder="Buscar ingrediente o subreceta…" aria-label="Componente">
                            <input type="hidden" name="comp[]" data-combo-value value="">
                            <ul class="admin-combo__list" data-combo-list hidden></ul>
                        </div>
                        <input type="number" name="comp_cant[]" step="0.001" min="0" placeholder="Cantidad" aria-label="Cantidad">
                        <button type="button" class="admin-icon-button admin-icon-button--danger" data-recipe-remove aria-label="Quitar componente">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                        </button>
                    </div>
                </template>

                <button type="button" class="admin-btn admin-btn--secondary admin-recipe__add" data-recipe-add>
                    <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    Agregar componente
                </button>
            <?php endif; ?>
        </section>

        <div class="admin-form-grid__actions admin-recipe__actions">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="/admin/productos">Cancelar</a>
        </div>
    </form>
</section>
