<?php
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
?>

<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title">Categorías</h2>
            <p class="admin-page__subtitle">Administra las categorías visibles del menú público.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/menu">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-create-button" href="/admin/menu/categories/create" title="Nueva categoría" aria-label="Nueva categoría">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
                <span>Nueva categoría</span>
            </a>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo); ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3><?php echo count($categorias); ?> categorías registradas</h3>
                <p>Organiza las secciones visibles en la carta pública.</p>
            </div>
        </div>

        <?php if (empty($categorias)) : ?>
            <p class="admin-menu__empty admin-empty">No hay categorías registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Imagen</th>
                            <th>Visibilidad</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categorias as $cat) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat->nombre); ?></td>
                                <td>
                                    <?php if (!empty($cat->img)) : ?>
                                        <img class="admin-menu__thumb" src="/<?php echo htmlspecialchars(ltrim($cat->img, '/')); ?>"
                                             alt="<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>" loading="lazy">
                                    <?php else : ?>
                                        <span class="admin-menu__muted">Sin imagen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="/admin/menu/categories/edit?id=<?php echo (int) $cat->id; ?>">
                                        <input type="hidden" name="nombre" value="<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>">
                                        <?php if (!$cat->activo) : ?>
                                            <input type="hidden" name="activo" value="1">
                                        <?php endif; ?>
                                        <button
                                            type="submit"
                                            class="admin-status-switch <?php echo $cat->activo ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $cat->activo ? 'Ocultar categoría' : 'Mostrar categoría'; ?>"
                                            aria-label="<?php echo $cat->activo ? 'Ocultar categoría ' : 'Mostrar categoría '; ?><?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>"
                                        >
                                            <span class="admin-status-switch__track" aria-hidden="true">
                                                <span class="admin-status-switch__thumb"></span>
                                            </span>
                                            <span class="admin-sr-only"><?php echo $cat->activo ? 'Visible' : 'Oculto'; ?></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a
                                            class="admin-icon-button admin-icon-button--edit"
                                            href="/admin/menu/categories/edit?id=<?php echo (int) $cat->id; ?>"
                                            title="Editar"
                                            aria-label="Editar categoría <?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                            </svg>
                                        </a>
                                        <form method="POST" action="/admin/menu/categories/delete" onsubmit="return confirm('Eliminar la categoría &quot;<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $cat->id; ?>">
                                            <button
                                                type="submit"
                                                class="admin-icon-button admin-icon-button--danger"
                                                title="Eliminar"
                                                aria-label="Eliminar categoría <?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M3 6h18"/>
                                                    <path d="M8 6V4h8v2"/>
                                                    <path d="M19 6l-1 14H6L5 6"/>
                                                    <path d="M10 11v5"/>
                                                    <path d="M14 11v5"/>
                                                </svg>
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
