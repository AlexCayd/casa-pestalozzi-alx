<?php
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $ingredientesBajos = isset($ingredientesBajos) && is_iterable($ingredientesBajos) ? $ingredientesBajos : [];
    $totalIngredientes = (int) ($totalIngredientes ?? 0);
    $bajoStock = (int) ($bajoStock ?? 0);
    $fmt = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas</span>
            <h2 class="admin-page__title">Inventario de ingredientes</h2>
            <p class="admin-page__subtitle">Controla las existencias de cada ingrediente. Las ventas descuentan el stock automáticamente según la receta de cada producto.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/recetas">Ver recetas</a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/inventario/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nuevo ingrediente</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-stat-strip">
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">Ingredientes</span>
            <span class="admin-stat-card__value"><?php echo $totalIngredientes; ?></span>
        </div>
        <div class="admin-stat-card <?php echo $bajoStock > 0 ? 'admin-stat-card--alert' : ''; ?>">
            <span class="admin-stat-card__label">Bajo stock</span>
            <span class="admin-stat-card__value"><?php echo $bajoStock; ?></span>
        </div>
    </div>

    <?php if (!empty($ingredientesBajos)) : ?>
        <section class="admin-panel admin-card admin-restock">
            <div class="admin-panel-head">
                <div>
                    <h3>Reabastecimiento rápido</h3>
                    <p>Estos ingredientes están por debajo del mínimo. Registra la entrada de mercancía recibida y se sumará al stock.</p>
                </div>
                <span class="admin-badge admin-badge--warning"><?php echo count($ingredientesBajos); ?> por surtir</span>
            </div>

            <div class="admin-restock__grid">
                <?php foreach ($ingredientesBajos as $ing) : ?>
                    <?php
                        // Cuánto falta para volver al mínimo y qué porción del mínimo hay cubierta.
                        $rStock   = (float) $ing->stock;
                        $rMin     = (float) $ing->stock_minimo;
                        $rFaltan  = max(0.0, $rMin - $rStock);
                        $rPct     = $rMin > 0 ? max(0.0, min(100.0, ($rStock / $rMin) * 100)) : ($rStock > 0 ? 100.0 : 0.0);
                        $rUnidad  = htmlspecialchars($ing->unidad);
                        $rCritico = $rStock < 0 || ($rMin > 0 && $rStock < $rMin * 0.5);
                    ?>
                    <article class="admin-restock__card">
                        <header class="admin-restock__header">
                            <span class="admin-restock__name" title="<?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>"><?php echo htmlspecialchars($ing->nombre); ?></span>
                            <span class="admin-badge admin-badge--<?php echo $rCritico ? 'danger' : 'warning'; ?>">
                                Faltan <?php echo $fmt($rFaltan); ?> <?php echo $rUnidad; ?>
                            </span>
                        </header>

                        <div class="admin-restock__levels">
                            <div class="admin-restock__level">
                                <span class="admin-restock__level-label">Actual</span>
                                <span class="admin-restock__level-value admin-restock__level-value--now"><?php echo $fmt($rStock); ?> <?php echo $rUnidad; ?></span>
                            </div>
                            <div class="admin-restock__level admin-restock__level--target">
                                <span class="admin-restock__level-label">Mínimo</span>
                                <span class="admin-restock__level-value"><?php echo $fmt($rMin); ?> <?php echo $rUnidad; ?></span>
                            </div>
                        </div>

                        <div class="admin-restock__bar" role="img"
                             aria-label="<?php echo $fmt($rStock); ?> de <?php echo $fmt($rMin); ?> <?php echo $rUnidad; ?>">
                            <span class="admin-restock__bar-fill <?php echo $rCritico ? 'is-critical' : ''; ?>" style="width: <?php echo round($rPct, 1); ?>%"></span>
                        </div>

                        <form class="admin-restock__form" method="POST" action="/admin/inventario/entrada">
                            <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                            <div class="admin-restock__input" style="--restock-unit: <?php echo mb_strlen((string) $ing->unidad); ?>ch">
                                <input type="number" name="cantidad" step="0.001" min="0.001" placeholder="Cantidad recibida"
                                       aria-label="Cantidad recibida de <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                <span class="admin-restock__unit"><?php echo $rUnidad; ?></span>
                            </div>
                            <button type="submit" class="admin-btn admin-btn--gold admin-restock__add">
                                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                Sumar
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="admin-panel admin-card">
        <div class="admin-panel-head">
            <div>
                <h3>Existencias</h3>
                <p><?php echo $totalIngredientes; ?> ingredientes registrados.</p>
            </div>
        </div>

        <?php if (empty($ingredientes)) : ?>
            <p class="admin-empty">No hay ingredientes registrados. Crea el primero para comenzar a controlar el inventario.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th>Unidad</th>
                            <th>Stock actual</th>
                            <th>Stock mínimo</th>
                            <th>Costo/u</th>
                            <th>Ajuste rápido</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredientes as $ing) : ?>
                            <?php
                                $stock = (float) $ing->stock;
                                $min = (float) $ing->stock_minimo;
                                $bajo = $stock <= $min;
                                $neg = $stock < 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($ing->nombre); ?></span>
                                    <?php if (!$ing->activo) : ?>
                                        <span class="admin-table__cell-sub">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($ing->unidad); ?></span></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $neg ? 'danger' : ($bajo ? 'warning' : 'success'); ?>">
                                        <?php echo rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.'); ?>
                                    </span>
                                </td>
                                <td><span class="admin-table__cell-sub"><?php echo rtrim(rtrim(number_format($min, 3, '.', ''), '0'), '.'); ?></span></td>
                                <td><span class="admin-table__cell-sub">$<?php echo number_format((float) $ing->costo, 4); ?></span></td>
                                <td>
                                    <form class="admin-inline-form" method="POST" action="/admin/inventario/ajustar">
                                        <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                        <input type="number" name="stock" step="0.001" value="<?php echo htmlspecialchars($fmt($stock)); ?>" aria-label="Nuevo stock de <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                        <button type="submit" class="admin-btn admin-btn--gold admin-inventario__save" title="Fijar stock">
                                            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>
                                            Guardar
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/inventario/edit?id=<?php echo (int) $ing->id; ?>" title="Editar" aria-label="Editar <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/inventario/delete" onsubmit="return confirm('¿Eliminar el ingrediente &quot;<?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
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
