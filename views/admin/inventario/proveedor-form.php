<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar';
    $prov = $proveedor ?? null;
    $ingredientesSurtidos = $ingredientesSurtidos ?? [];
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario / Proveedores</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Proveedor'); ?></h2>
            <p class="admin-page__subtitle">Datos de contacto. Los precios se asignan desde cada ingrediente.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/inventario/proveedores">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-form-grid" method="POST">
            <div class="admin-field">
                <label class="admin-field__label" for="nombre">Nombre del proveedor</label>
                <input type="text" id="nombre" name="nombre" maxlength="120" required
                       value="<?php echo htmlspecialchars($prov->nombre ?? ''); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="contacto">Persona de contacto</label>
                <input type="text" id="contacto" name="contacto" maxlength="120"
                       value="<?php echo htmlspecialchars((string) ($prov->contacto ?? '')); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="telefono">Teléfono</label>
                <input type="tel" id="telefono" name="telefono" maxlength="30"
                       value="<?php echo htmlspecialchars((string) ($prov->telefono ?? '')); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="correo">Correo</label>
                <input type="email" id="correo" name="correo" maxlength="120"
                       value="<?php echo htmlspecialchars((string) ($prov->correo ?? '')); ?>">
            </div>

            <div class="admin-field admin-form-grid__full">
                <label class="admin-field__label" for="notas">Notas</label>
                <textarea id="notas" name="notas" rows="3"><?php echo htmlspecialchars((string) ($prov->notas ?? '')); ?></textarea>
                <p class="admin-field__hint">Días de entrega, pedido mínimo, condiciones de pago.</p>
            </div>

            <label class="admin-switch admin-form-grid__full">
                <input type="checkbox" name="activo" value="1" <?php echo (int) ($prov->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Proveedor activo</span>
            </label>

            <div class="admin-form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary" href="/admin/inventario/proveedores">Cancelar</a>
            </div>
        </form>
    </section>

    <?php /* Sólo lectura: quién surte qué se decide en la ficha del ingrediente,
             donde se ve el costo vigente contra el que ofrece cada proveedor. */ ?>
    <?php if (!empty($ingredientesSurtidos)) : ?>
        <section class="admin-panel admin-card">
            <div class="admin-panel-head">
                <div>
                    <h3>Insumos que surte</h3>
                    <p>Los precios se asignan desde la ficha de cada ingrediente.</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th class="admin-table__num">Su precio</th>
                            <th class="admin-table__num">Costo vigente</th>
                            <th>Clave</th>
                            <th>Preferente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredientesSurtidos as $fila) : ?>
                            <tr>
                                <td class="admin-table__cell-main">
                                    <a href="/admin/inventario/edit?id=<?php echo (int) $fila->ingrediente_id; ?>">
                                        <?php echo htmlspecialchars((string) $fila->ingrediente_nombre); ?>
                                    </a>
                                </td>
                                <td class="admin-table__num">
                                    $<?php echo number_format((float) $fila->costo, 4); ?>
                                    <span class="admin-table__cell-sub">/ <?php echo htmlspecialchars((string) $fila->ingrediente_unidad); ?></span>
                                </td>
                                <td class="admin-table__num">$<?php echo number_format((float) $fila->ingrediente_costo, 4); ?></td>
                                <td><?php echo htmlspecialchars((string) ($fila->codigo ?? '—')); ?></td>
                                <td>
                                    <?php if ((int) $fila->preferente === 1) : ?>
                                        <span class="admin-badge admin-badge--success">Preferente</span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--neutral">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</section>
