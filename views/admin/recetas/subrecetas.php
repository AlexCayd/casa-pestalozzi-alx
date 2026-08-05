<?php
    $subrecetas = isset($subrecetas) && is_iterable($subrecetas) ? $subrecetas : [];
    $conteosSub = is_array($conteosSub ?? null) ? $conteosSub : [];
?>
<section class="admin-recetas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Productos</span>
            <h2 class="admin-page__title">Subrecetas</h2>
            <p class="admin-page__subtitle">Preparaciones intermedias reutilizables (salsas, bases, mezclas). Se componen de ingredientes y pueden usarse dentro de la receta de varios productos.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/recetas">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver
            </a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/recetas/subrecetas/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nueva subreceta</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card">
        <?php if (empty($subrecetas)) : ?>
            <p class="admin-empty">No hay subrecetas registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Subreceta</th>
                            <th>Unidad</th>
                            <th>Rendimiento</th>
                            <th>Ingredientes</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subrecetas as $sub) : ?>
                            <?php $n = (int) ($conteosSub[(int) $sub->id] ?? 0); ?>
                            <tr data-row-href="/admin/recetas/subrecetas/edit?id=<?php echo (int) $sub->id; ?>">
                                <td><span class="admin-table__cell-main"><?php echo htmlspecialchars($sub->nombre); ?></span></td>
                                <td><span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($sub->unidad); ?></span></td>
                                <td><span class="admin-table__cell-sub"><?php echo rtrim(rtrim(number_format((float) $sub->rendimiento, 3, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($sub->unidad); ?></span></td>
                                <td>
                                    <?php if ($n > 0) : ?>
                                        <span class="admin-badge admin-badge--success"><?php echo $n; ?></span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning">Sin ingredientes</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-badge admin-badge--<?php echo $sub->activo ? 'neutral' : 'danger'; ?>"><?php echo $sub->activo ? 'Activa' : 'Inactiva'; ?></span></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/recetas/subrecetas/edit?id=<?php echo (int) $sub->id; ?>" title="Editar" aria-label="Editar <?php echo htmlspecialchars($sub->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/recetas/subrecetas/delete" onsubmit="return confirm('¿Eliminar la subreceta &quot;<?php echo htmlspecialchars($sub->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $sub->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo htmlspecialchars($sub->nombre, ENT_QUOTES); ?>">
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
