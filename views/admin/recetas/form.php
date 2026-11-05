<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar receta';
    $prod = $producto ?? null;
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $subrecetas = isset($subrecetas) && is_iterable($subrecetas) ? $subrecetas : [];
    $componentes = isset($componentes) && is_iterable($componentes) ? $componentes : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
    // Si se vino desde una lista de pendientes (?volver=, validado en el
    // controlador), «Volver» y «Cancelar» regresan allí. El formulario no
    // lleva `action`: se envía a esta misma URL, con su `volver`, y al guardar
    // el controlador redirige a esa lista.
    $recetasBase = $recetasBase ?? '/admin/recetas';
    $recetasContexto = $recetasContexto ?? 'restaurante';
    $recetaMenuBase = $recetaMenuBase ?? '/admin/menu';
    $volverUrl = (string) ($volverUrl ?? $recetasBase);
    $almacenesConsumo = is_array($almacenesConsumo ?? null) ? $almacenesConsumo : [];
    $pendientesAlmacen = count(array_filter($almacenesConsumo, static fn(array $f): bool => $f['almacen_id'] === null));
    $volverQuery = $volverUrl !== $recetasBase ? '&volver=' . rawurlencode($volverUrl) : '';

    // Reventa confirmada (arquitectura §16): la receta es un solo artículo de
    // existencias, sin preparación anterior ni elaboración fijada. El editor se
    // reduce a eso; la sugerida sigue con el editor de siempre.
    $recetaReventa = $prod && ($prod->tipo_venta ?? '') === 'reventa' && ($prod->tipo_venta_estado ?? '') === 'confirmado';
    if ($recetaReventa) {
        $subrecetas = [];
        $componentes = array_slice(array_values(array_filter(
            is_array($componentes) ? $componentes : iterator_to_array($componentes),
            static fn($h): bool => ($h['tipo'] ?? '') === 'ingrediente'
        )), 0, 1);
    }

    $referenciasActuales=array_map('intval',array_column(array_filter($componentes,static fn($h)=>$h['tipo']==='ingrediente'),'ref_id'));
    $ingredientes=array_filter(is_array($ingredientes)?$ingredientes:iterator_to_array($ingredientes),static fn($i)=>!in_array((int)$i->id,$preparacionesVersionadas??[],true)||in_array((int)$i->id,$referenciasActuales,true));
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
            'label' => $sub->nombre . ' (preparación anterior)',
            'group' => 'Preparaciones anteriores',
        ];
    }

    // Texto a mostrar en el buscador para un componente ya guardado.
    $comboLabel = static function (array $comp): string {
        if (($comp['tipo'] ?? '') === 'subreceta') {
            return ($comp['nombre'] ?? '') . ' (preparación anterior)';
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
        <a class="admin-btn admin-btn--secondary admin-back-button" href="<?php echo htmlspecialchars($volverUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>
    <p class="admin-field__hint">Este editor conserva las recetas anteriores. Las preparaciones versionadas y sus modalidades de consumo se configuran en Presentaciones. Las recetas existentes no se convierten automáticamente.</p>
    <?php if($prod && \Classes\Auth::puede('menu','ver',$recetasContexto)): ?><p><a class="admin-btn admin-btn--secondary" href="<?= htmlspecialchars($recetaMenuBase.'/presentaciones?'.http_build_query(['id'=>(int)$prod->id,'volver'=>$recetasBase.'/editar?id='.(int)$prod->id]),ENT_QUOTES,'UTF-8') ?>">Configurar presentaciones</a></p><?php endif; ?>

    <?php if(!empty($configuracionComposicion['referencias'])): ?><section class="admin-panel admin-card"><h3>Elaboraciones fijadas en la receta vigente</h3><p><?= !empty($configuracionComposicion['edicionCompatible'])?'Las cantidades se editan conservando estas versiones.':'Estas versiones se conservan en consulta.' ?> Para añadir una preparación o elegir otra modalidad, configura una presentación.</p><ul><?php foreach($configuracionComposicion['referencias'] as $r): ?><li><?= htmlspecialchars($r['nombre'].' · Versión '.$r['version'].' · '.($r['contexto']==='global'?'Global':ucfirst($r['contexto'])),ENT_QUOTES,'UTF-8') ?> · Preparar usando su elaboración</li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if(empty($configuracionComposicion['edicionCompatible'])): ?><p role="status">Solo consulta: <?= htmlspecialchars($configuracionComposicion['motivoConsulta'],ENT_QUOTES,'UTF-8') ?>. No se modificará desde el editor anterior.</p><?php endif; ?>
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
            <div class="admin-actions">
                <?php if (\Classes\Auth::puede('menu', 'ver')) : ?>
                    <a class="admin-btn admin-btn--secondary admin-btn--small" href="/admin/menu/variantes?id=<?php echo (int) $prod->id; ?>">Variantes</a>
                <?php endif; ?>
                <?php if (\Classes\Auth::puede('menu','ver',$recetasContexto)): ?><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?= htmlspecialchars($recetaMenuBase.'/edit?id='.(int)$prod->id,ENT_QUOTES,'UTF-8') ?>">
                    Datos del producto
                </a><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <form method="POST" data-recipe-builder>
    <fieldset class="admin-menu__fieldset" <?= empty($configuracionComposicion['edicionCompatible'])||!\Classes\Auth::puede('productos','editar',$recetasContexto)?'disabled':'' ?>>
        <section class="admin-panel admin-card admin-recipe">
            <div class="admin-recipe__head">
                <div>
                    <?php if ($recetaReventa) : ?>
                        <span class="admin-recipe__eyebrow">Reventa</span>
                        <h3>Artículo que se descuenta</h3>
                        <p>Cada unidad vendida descuenta <strong>un solo artículo</strong> de existencias, sin preparación. Anota cuánto por unidad: 1 si el artículo se cuenta por pieza, o su contenido, como 750 ml, si se guarda en ml. Para otra composición, cambia el tipo de venta en los datos del producto.</p>
                    <?php else : ?>
                        <span class="admin-recipe__eyebrow">Receta principal</span>
                        <h3>Ingredientes de una porción</h3>
                        <p>Anota cuánto lleva <strong>una sola porción</strong>. Al vender el platillo se descuenta justo esa cantidad del inventario. Una subreceta (una salsa, un shot de espresso) se explota hasta sus propios ingredientes.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($ingredientes) && empty($subrecetas)) : ?>
                <p class="admin-empty">Aún no hay ingredientes en el inventario. Registra al menos uno en <a href="/admin/inventario">Inventario</a> y vuelve aquí para armar la receta.</p>
            <?php else : ?>
                <script type="application/json" data-recipe-options>
                    <?php echo json_encode($opciones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                </script>

                <div class="admin-recipe__rows" data-recipe-rows>
                    <?php if ($recetaReventa && empty($componentes)) : ?>
                        <?php /* Sin «Agregar» la fila tiene que existir de entrada. */ ?>
                        <div class="admin-recipe__row admin-recipe__row--reventa" data-recipe-row>
                            <div class="admin-picker" data-picker>
                                <button type="button" class="admin-picker__trigger" data-picker-open>
                                    <span class="admin-picker__label" data-picker-label>Elige el artículo</span>
                                    <svg class="admin-picker__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <input type="hidden" name="comp[]" data-picker-value value="">
                            </div>
                            <div class="admin-recipe__qty">
                                <input type="number" name="comp_cant[]" step="0.001" min="0" placeholder="Cantidad" aria-label="Cantidad por unidad vendida">
                                <span class="admin-recipe__unit" data-recipe-unit aria-hidden="true"></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($componentes as $comp) : ?>
                        <?php $sel = $comp['tipo'] . ':' . (int) $comp['ref_id']; ?>
                        <div class="admin-recipe__row<?php echo $recetaReventa ? ' admin-recipe__row--reventa' : ''; ?>" data-recipe-row>
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
                                       aria-label="<?php echo $recetaReventa ? 'Cantidad por unidad vendida' : 'Cantidad'; ?>">
                                <span class="admin-recipe__unit" data-recipe-unit aria-hidden="true"></span>
                            </div>
                            <?php if (!$recetaReventa) : ?>
                            <button type="button" class="admin-icon-button admin-icon-button--danger" data-recipe-remove aria-label="Quitar ingrediente">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$recetaReventa) : ?>

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
            <?php endif; ?>
        </section>

        <div class="admin-form-grid__actions admin-recipe__actions">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="<?php echo htmlspecialchars($volverUrl, ENT_QUOTES, 'UTF-8'); ?>">Cancelar</a>
        </div>
    </fieldset></form>

    <?php if ($prod && $almacenesConsumo !== []) : ?>
        <?php /* Formulario aparte: la lista sale de la receta y las variantes ya
                 guardadas. Sin fila, ConsumoOferta rechaza la venta; no hay respaldo. */ ?>
        <form method="POST" action="<?= htmlspecialchars($recetasBase.'/almacenes?id='.(int)$prod->id.$volverQuery,ENT_QUOTES,'UTF-8') ?>" id="almacenes" class="admin-panel admin-card admin-recipe">
            <input type="hidden" name="admin_csrf" value="<?php echo htmlspecialchars((string) ($adminCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="admin-recipe__head">
                <div>
                    <span class="admin-recipe__eyebrow">Inventario</span>
                    <h3>¿De dónde sale cada ingrediente?</h3>
                    <p>Incluye los ingredientes de la receta, de sus subrecetas y los que agregan o cambian sus <a href="/admin/menu/variantes?id=<?php echo (int) $prod->id; ?>">variantes</a>. Al vender se descuenta del almacén elegido.</p>
                    <?php if ($pendientesAlmacen > 0) : ?>
                        <p class="admin-recipe__aviso" role="status"><?php echo $pendientesAlmacen === 1 ? 'Falta 1 ingrediente' : 'Faltan ' . $pendientesAlmacen . ' ingredientes'; ?> por asignar: mientras tanto el POS no puede vender este platillo.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <thead><tr><th>Ingrediente</th><th>Almacén de consumo</th></tr></thead>
                    <tbody>
                    <?php foreach ($almacenesConsumo as $fila) : $campo = 'almacen-' . (int) $fila['articulo_id']; ?>
                        <tr>
                            <td><label for="<?php echo $campo; ?>"><strong><?php echo htmlspecialchars($fila['nombre']); ?></strong></label> <span class="admin-table__cell-sub"><?php echo htmlspecialchars($fila['unidad']); ?></span></td>
                            <td>
                                <?php if ($fila['opciones'] === []) : ?>
                                    <span class="admin-table__cell-sub">No está en ningún almacén activo. <a href="/admin/almacenes">Asócialo en Almacenes</a>.</span>
                                <?php else : ?>
                                    <select id="<?php echo $campo; ?>" name="almacen[<?php echo (int) $fila['articulo_id']; ?>]" required>
                                        <option value="" <?php echo $fila['almacen_id'] === null ? 'selected' : ''; ?>>Elige un almacén</option>
                                        <?php foreach ($fila['opciones'] as $almacenId => $almacenNombre) : ?>
                                            <option value="<?php echo (int) $almacenId; ?>" <?php echo $fila['almacen_id'] === (int) $almacenId ? 'selected' : ''; ?>><?php echo htmlspecialchars($almacenNombre); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-form-grid__actions admin-recipe__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Guardar almacenes</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/_ingredient-picker-modal.php'; ?>
