<?php
    $alertas = $alertas ?? [];
    $proveedores = $proveedores ?? [];
    $conteoIngredientes = $conteoIngredientes ?? [];
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title">Proveedores</h2>
            <p class="admin-page__subtitle">Quién surte cada insumo y a qué precio.</p>
        </div>
        <div class="admin-toolbar">
            <a class="admin-btn admin-btn--primary" href="/admin/inventario/proveedores/create">Nuevo proveedor</a>
            <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/inventario">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver a inventario
            </a>
        </div>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <?php if (empty($proveedores)) : ?>
            <p class="admin-field__hint">
                Todavía no hay proveedores. Al darlos de alta podrás asignarlos a cada
                ingrediente desde su ficha y comparar precios.
            </p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th>Correo</th>
                            <th class="admin-table__num">Insumos</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proveedores as $proveedor) : ?>
                            <?php $surtidos = $conteoIngredientes[(int) $proveedor->id] ?? 0; ?>
                            <tr>
                                <td class="admin-table__cell-main"><?php echo htmlspecialchars($proveedor->nombre); ?></td>
                                <td><?php echo htmlspecialchars((string) ($proveedor->contacto ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($proveedor->telefono ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($proveedor->correo ?? '—')); ?></td>
                                <td class="admin-table__num"><?php echo (int) $surtidos; ?></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo (int) $proveedor->activo === 1 ? 'success' : 'neutral'; ?>">
                                        <?php echo (int) $proveedor->activo === 1 ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td class="admin-table__num">
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/inventario/proveedores/edit?id=<?php echo (int) $proveedor->id; ?>" title="Editar" aria-label="Editar <?php echo htmlspecialchars($proveedor->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <?php /* El borrado se lleva por cascada los precios asignados;
                                                 el histórico de precios sí sobrevive, con el proveedor
                                                 en NULL: la subida ocurrió aunque ya no le compremos. */ ?>
                                        <form method="POST" action="/admin/inventario/proveedores/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar proveedor"
                                              data-confirm-title="¿Eliminar «<?php echo htmlspecialchars($proveedor->nombre, ENT_QUOTES); ?>»?"
                                              data-confirm-description="Se perderán sus precios asignados a <?php echo (int) $surtidos; ?> insumo(s)."
                                              data-confirm-consequence="El histórico de precios se conserva, pero deja de decir quién los surtió. Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $proveedor->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo htmlspecialchars($proveedor->nombre, ENT_QUOTES); ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
