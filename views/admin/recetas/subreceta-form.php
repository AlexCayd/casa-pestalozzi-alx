<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar';
    $sub = $subreceta ?? null;
    $unidades = isset($unidades) && is_iterable($unidades) ? $unidades : ['g', 'kg', 'ml', 'l', 'pza'];
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $ingredientesReceta = isset($ingredientesReceta) && is_iterable($ingredientesReceta) ? $ingredientesReceta : [];

    // Catálogo de opciones para el buscador de ingredientes.
    $opciones = [];
    foreach ($ingredientes as $ing) {
        $opciones[] = [
            'value' => (string) (int) $ing->id,
            'label' => $ing->nombre . ' (' . $ing->unidad . ')',
        ];
    }
    // Texto a mostrar para un ingrediente ya guardado.
    $ingLabel = static function ($refId) use ($ingredientes): string {
        foreach ($ingredientes as $ing) {
            if ((int) $ing->id === (int) $refId) {
                return $ing->nombre . ' (' . $ing->unidad . ')';
            }
        }
        return '';
    };
?>
<section class="admin-recetas admin-recetas--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Subrecetas</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Subreceta'); ?></h2>
            <p class="admin-page__subtitle">Define la preparación y los ingredientes que consume para producir su rendimiento base.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/recetas/subrecetas">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <form method="POST" data-recipe-builder>
        <section class="admin-panel admin-card">
            <?php include __DIR__ . '/../partials/alertas.php'; ?>

            <div class="admin-form-grid">
                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="nombre">Nombre de la subreceta</label>
                    <input type="text" id="nombre" name="nombre" maxlength="120" required
                           value="<?php echo htmlspecialchars($sub->nombre ?? ''); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="unidad">Unidad del rendimiento</label>
                    <select id="unidad" name="unidad">
                        <?php foreach ($unidades as $u) : ?>
                            <option value="<?php echo htmlspecialchars($u); ?>" <?php echo ($sub->unidad ?? 'g') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="rendimiento">Rendimiento (cantidad que produce)</label>
                    <input type="number" id="rendimiento" name="rendimiento" step="0.001" min="0.001" required
                           value="<?php echo htmlspecialchars((string) ($sub->rendimiento ?? 1)); ?>">
                    <p class="admin-field__hint">Lo que sale de un lote con las cantidades de abajo.</p>
                </div>

                <?php /* Las nuevas nacen por lote (el valor por defecto del modelo).
                         Al momento es la excepción: lo que se prepara con cada
                         plato, como un shot de espresso. */ ?>
                <div class="admin-field admin-form-grid__full">
                    <label class="admin-switch">
                        <input type="checkbox" name="por_lote" value="1" <?php echo (int) ($sub->por_lote ?? 1) === 1 ? 'checked' : ''; ?>>
                        <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                        <span class="admin-switch__label">Se produce por lote</span>
                    </label>
                    <p class="admin-field__hint">Encendido: se cocina por adelantado. Producir un lote saca sus ingredientes del almacén, y vender un platillo descuenta sólo la subreceta. Apagado: se prepara al momento, y cada venta descuenta sus ingredientes.</p>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="stock_minimo">Existencia mínima</label>
                    <input type="number" id="stock_minimo" name="stock_minimo" step="0.001" min="0"
                           value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) ($sub->stock_minimo ?? 0), 3, '.', ''), '0'), '.')); ?>">
                    <?php /* La unidad se nombra por su campo y no por su valor: escrita
                             («En l.») se quedaba vieja en cuanto se cambiaba el selector. */ ?>
                    <p class="admin-field__hint">En la unidad del rendimiento. Al llegar a esta cifra, la lista la marca por producir. Sólo cuenta si se produce por lote.</p>
                </div>

                <?php if (!empty($sub->id) && (int) ($sub->por_lote ?? 0) === 1) : ?>
                    <?php /* La existencia no se edita aquí: la mueven los lotes, las
                             ventas y el conteo. Se enseña para saber de dónde se parte. */ ?>
                    <div class="admin-field">
                        <span class="admin-field__label">Existencia</span>
                        <p class="admin-recetas__existencia">
                            <span class="admin-num"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $sub->stock, 3, '.', ','), '0'), '.')); ?> <?php echo htmlspecialchars($sub->unidad); ?></span>
                            <a href="/admin/recetas/subrecetas/producir?id=<?php echo (int) $sub->id; ?>">Producir o corregir</a>
                        </p>
                    </div>
                <?php endif; ?>

                <label class="admin-switch admin-form-grid__full">
                    <input type="checkbox" name="activo" value="1" <?php echo (int) ($sub->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Subreceta activa</span>
                </label>
            </div>
        </section>

        <section class="admin-panel admin-card admin-recipe">
            <div class="admin-recipe__head">
                <div>
                    <span class="admin-recipe__eyebrow">Ingredientes</span>
                    <h3>Composición de la subreceta</h3>
                    <p>Cantidades para producir el rendimiento indicado arriba.</p>
                </div>
            </div>

            <?php if (empty($ingredientes)) : ?>
                <p class="admin-empty">Primero registra ingredientes en el inventario.</p>
            <?php else : ?>
                <script type="application/json" data-recipe-options>
                    <?php echo json_encode($opciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                </script>

                <div class="admin-recipe__rows" data-recipe-rows>
                    <?php foreach ($ingredientesReceta as $ir) : ?>
                        <div class="admin-recipe__row" data-recipe-row>
                            <div class="admin-picker" data-picker>
                                <button type="button" class="admin-picker__trigger" data-picker-open>
                                    <span class="admin-picker__label" data-picker-label><?php echo htmlspecialchars($ingLabel($ir['ref_id']), ENT_QUOTES); ?></span>
                                    <svg class="admin-picker__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <input type="hidden" name="ing_ref[]" data-picker-value value="<?php echo (int) $ir['ref_id']; ?>">
                            </div>
                            <input type="number" name="ing_cant[]" step="0.001" min="0" placeholder="Cantidad"
                                   value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $ir['cantidad'], 3, '.', ''), '0'), '.')); ?>"
                                   aria-label="Cantidad">
                            <button type="button" class="admin-icon-button admin-icon-button--danger" data-recipe-remove aria-label="Quitar ingrediente">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <template data-recipe-template>
                    <div class="admin-recipe__row" data-recipe-row>
                        <div class="admin-picker" data-picker>
                            <button type="button" class="admin-picker__trigger" data-picker-open>
                                <span class="admin-picker__label" data-picker-label></span>
                                <svg class="admin-picker__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <input type="hidden" name="ing_ref[]" data-picker-value value="">
                        </div>
                        <input type="number" name="ing_cant[]" step="0.001" min="0" placeholder="Cantidad" aria-label="Cantidad">
                        <button type="button" class="admin-icon-button admin-icon-button--danger" data-recipe-remove aria-label="Quitar ingrediente">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                        </button>
                    </div>
                </template>

                <button type="button" class="admin-btn admin-btn--secondary admin-recipe__add" data-recipe-add>
                    <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    Agregar ingrediente
                </button>
            <?php endif; ?>
        </section>

        <div class="admin-form-grid__actions admin-recipe__actions">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="/admin/recetas/subrecetas">Cancelar</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/_ingredient-picker-modal.php'; ?>
