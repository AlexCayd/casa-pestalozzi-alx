<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar receta';
    $prod = $producto ?? null;
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $subrecetas = isset($subrecetas) && is_iterable($subrecetas) ? $subrecetas : [];
    $componentes = isset($componentes) && is_iterable($componentes) ? $componentes : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];

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
<section class="admin-recetas admin-recetas--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Recetas</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($prod->nombre ?? 'Receta'); ?></h2>
            <p class="admin-page__subtitle">Lo que se descuenta del inventario cada vez que se vende una unidad de este platillo.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/recetas">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php if ($prod) : ?>
        <?php
            // Los datos del platillo van en solo lectura: se editan en Gestión
            // de menú, que ahora escribe en esta misma tabla.
            $catNombre = $categoriasMap[(int) $prod->categoria_id] ?? '—';
        ?>
        <section class="admin-panel admin-card admin-recetas__resumen">
            <div class="admin-recetas__resumen-datos">
                <div>
                    <span class="admin-recetas__resumen-label">Categoría</span>
                    <strong><?php echo htmlspecialchars($catNombre); ?></strong>
                </div>
                <div>
                    <span class="admin-recetas__resumen-label">Precio de venta</span>
                    <strong>$<?php echo number_format((float) $prod->precio, 2); ?></strong>
                </div>
                <div>
                    <span class="admin-recetas__resumen-label">Estado</span>
                    <strong><?php echo $prod->activo ? 'Activo' : 'Inactivo'; ?></strong>
                </div>
            </div>
            <a class="admin-btn admin-btn--secondary admin-btn--small" href="/admin/menu/edit?id=<?php echo (int) $prod->id; ?>">
                Editar datos del platillo
            </a>
        </section>
    <?php endif; ?>

    <form method="POST" data-recipe-builder>
        <section class="admin-panel admin-card admin-recipe">
            <div class="admin-recipe__head">
                <div>
                    <span class="admin-recipe__eyebrow">Receta principal</span>
                    <h3>Ingredientes de una porción</h3>
                    <p>Anota cuánto lleva <strong>una sola porción</strong>. Al vender el platillo se descuenta justo esa cantidad del inventario. Una subreceta (una salsa, un shot de espresso) se explota hasta sus propios ingredientes.</p>
                </div>
            </div>

            <?php if (empty($ingredientes) && empty($subrecetas)) : ?>
                <p class="admin-empty">Aún no hay ingredientes en el inventario. Registra al menos uno en <a href="/admin/inventario">Inventario</a> y vuelve aquí para armar la receta.</p>
            <?php else : ?>
                <script type="application/json" data-recipe-options>
                    <?php echo json_encode($opciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                </script>

                <div class="admin-recipe__rows" data-recipe-rows>
                    <?php foreach ($componentes as $comp) : ?>
                        <?php $sel = $comp['tipo'] . ':' . (int) $comp['ref_id']; ?>
                        <div class="admin-recipe__row" data-recipe-row>
                            <div class="admin-picker" data-picker>
                                <button type="button" class="admin-picker__trigger" data-picker-open>
                                    <span class="admin-picker__label" data-picker-label><?php echo htmlspecialchars($comboLabel($comp), ENT_QUOTES); ?></span>
                                    <svg class="admin-picker__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <input type="hidden" name="comp[]" data-picker-value value="<?php echo htmlspecialchars($sel, ENT_QUOTES); ?>">
                            </div>
                            <div class="admin-recipe__qty">
                                <input type="number" name="comp_cant[]" step="0.001" min="0" placeholder="Cantidad"
                                       value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float) $comp['cantidad'], 3, '.', ''), '0'), '.')); ?>"
                                       aria-label="Cantidad">
                                <span class="admin-recipe__unit" data-recipe-unit aria-hidden="true"></span>
                            </div>
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
                            <input type="hidden" name="comp[]" data-picker-value value="">
                        </div>
                        <div class="admin-recipe__qty">
                            <input type="number" name="comp_cant[]" step="0.001" min="0" placeholder="Cantidad" aria-label="Cantidad">
                            <span class="admin-recipe__unit" data-recipe-unit aria-hidden="true"></span>
                        </div>
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
            <a class="admin-btn admin-btn--secondary" href="/admin/recetas">Cancelar</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/_ingredient-picker-modal.php'; ?>
