<?php
    $alertas = $alertas ?? [];
    $unidades = isset($unidades) && is_iterable($unidades) ? $unidades : ['g', 'kg', 'ml', 'l', 'pza'];
    $accion = $accion ?? 'Guardar';
    $ing = $ingrediente ?? null;
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Ingrediente'); ?></h2>
            <p class="admin-page__subtitle">Define el ingrediente y sus existencias actuales.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/inventario">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-form-grid" method="POST">
            <div class="admin-field">
                <label class="admin-field__label" for="nombre">Nombre del ingrediente</label>
                <input type="text" id="nombre" name="nombre" maxlength="120" required
                       value="<?php echo htmlspecialchars($ing->nombre ?? ''); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="unidad">Unidad de medida</label>
                <select id="unidad" name="unidad">
                    <?php foreach ($unidades as $u) : ?>
                        <option value="<?php echo htmlspecialchars($u); ?>" <?php echo ($ing->unidad ?? 'g') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="stock">Stock actual</label>
                <input type="number" id="stock" name="stock" step="0.001"
                       value="<?php echo htmlspecialchars((string) ($ing->stock ?? 0)); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="stock_minimo">Stock mínimo (alerta)</label>
                <input type="number" id="stock_minimo" name="stock_minimo" step="0.001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->stock_minimo ?? 0)); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label admin-field__label--split" for="costo">
                    <span>Costo por unidad</span>
                    <span>MXN / <?php echo htmlspecialchars($ing->unidad ?? 'g'); ?></span>
                </label>
                <input type="number" id="costo" name="costo" step="0.0001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->costo ?? 0)); ?>">
                <?php if (!empty($ing->id)) : ?>
                    <p class="admin-field__hint">
                        <button type="button" class="admin-btn admin-btn--ghost admin-proveedores__historial"
                                data-historial-precios
                                data-historial-entidad="ingrediente"
                                data-historial-id="<?php echo (int) $ing->id; ?>"
                                data-historial-titulo="<?php echo htmlspecialchars($ing->nombre ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            Ver histórico de costos
                        </button>
                    </p>
                <?php endif; ?>
            </div>

            <?php
                /*
                 * Proveedores del ingrediente.
                 *
                 * El costo de arriba es el que usan el COGS y la valorización de
                 * existencias; esto es a cuánto lo vende cada quien, que es otra
                 * cosa y por eso convive con él en vez de sustituirlo. El
                 * preferente es el que se propone al recibir mercancía.
                 */
                $proveedores = $proveedores ?? [];
                $proveedoresAsignados = $proveedoresAsignados ?? [];
            ?>
            <div class="admin-field admin-form-grid__full admin-proveedores" data-proveedores>
                <span class="admin-field__label">Proveedores</span>

                <?php if (empty($proveedores)) : ?>
                    <p class="admin-field__hint">
                        Todavía no hay proveedores dados de alta.
                        <a href="/admin/inventario/proveedores/create">Crear el primero</a>.
                    </p>
                <?php else : ?>
                    <table class="admin-table admin-proveedores__table">
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th>Costo / <?php echo htmlspecialchars($ing->unidad ?? 'g'); ?></th>
                                <th>Clave del proveedor</th>
                                <th>Preferente</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody data-proveedores-filas>
                            <?php foreach ($proveedoresAsignados as $indice => $asignado) : ?>
                                <tr class="admin-proveedores__fila">
                                    <td>
                                        <select name="proveedor_id[]">
                                            <?php foreach ($proveedores as $prov) : ?>
                                                <option value="<?php echo (int) $prov->id; ?>"
                                                    <?php echo (int) $prov->id === (int) $asignado->proveedor_id ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($prov->nombre); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="proveedor_costo[]" step="0.0001" min="0"
                                               value="<?php echo htmlspecialchars((string) $asignado->costo); ?>"></td>
                                    <td><input type="text" name="proveedor_codigo[]" maxlength="60"
                                               value="<?php echo htmlspecialchars((string) ($asignado->codigo ?? '')); ?>"></td>
                                    <td class="admin-table__num">
                                        <input type="radio" name="proveedor_preferente" value="<?php echo (int) $indice; ?>"
                                            <?php echo (int) $asignado->preferente === 1 ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="admin-table__num">
                                        <button type="button" class="admin-btn admin-btn--ghost" data-proveedor-quitar>Quitar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="admin-field__hint" data-proveedores-vacio <?php echo empty($proveedoresAsignados) ? '' : 'hidden'; ?>>
                        Sin proveedores asignados. El costo de arriba se sigue usando para el COGS.
                    </p>

                    <?php /* La plantilla vive en el marcado y no en el JS: así el
                             <select> de proveedores se pinta una sola vez desde PHP
                             y no hay que serializar el catálogo a JavaScript. */ ?>
                    <template data-proveedor-plantilla>
                        <tr class="admin-proveedores__fila">
                            <td>
                                <select name="proveedor_id[]">
                                    <?php foreach ($proveedores as $prov) : ?>
                                        <option value="<?php echo (int) $prov->id; ?>"><?php echo htmlspecialchars($prov->nombre); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="proveedor_costo[]" step="0.0001" min="0" value="0"></td>
                            <td><input type="text" name="proveedor_codigo[]" maxlength="60"></td>
                            <td class="admin-table__num"><input type="radio" name="proveedor_preferente" value=""></td>
                            <td class="admin-table__num">
                                <button type="button" class="admin-btn admin-btn--ghost" data-proveedor-quitar>Quitar</button>
                            </td>
                        </tr>
                    </template>

                    <div class="admin-proveedores__acciones">
                        <button type="button" class="admin-btn admin-btn--secondary" data-proveedor-agregar>Agregar proveedor</button>
                        <a class="admin-btn admin-btn--ghost" href="/admin/inventario/proveedores">Administrar proveedores</a>
                    </div>
                <?php endif; ?>
            </div>

            <label class="admin-switch admin-form-grid__full">
                <input type="checkbox" name="activo" value="1" <?php echo (int) ($ing->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Ingrediente activo</span>
            </label>

            <div class="admin-form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary" href="/admin/inventario">Cancelar</a>
            </div>
        </form>
    </section>

    <?php include __DIR__ . '/../partials/_historial-precios-modal.php'; ?>
</section>
