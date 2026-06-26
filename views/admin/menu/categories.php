<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menu</span>
            <h2 class="admin-page__title">Categorias</h2>
            <p class="admin-page__subtitle">Administra las categorias visibles del menu publico.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu">Resumen</a>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/categories/create">Nueva categoria</a>
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
                <h3>Categorias registradas</h3>
                <p><?php echo count($categorias); ?> categorias registradas.</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categories/create">Agregar</a>
        </div>

        <?php if (empty($categorias)) : ?>
            <p class="admin-menu__empty admin-empty">No hay categorias registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Imagen</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categorias as $cat) : ?>
                            <tr>
                                <td><?php echo (int) $cat->id; ?></td>
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
                                    <span class="admin-menu__badge <?php echo $cat->activo ? 'is-active' : 'is-inactive'; ?>">
                                        <?php echo $cat->activo ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-menu__row-actions">
                                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/menu/categories/edit?id=<?php echo (int) $cat->id; ?>">Editar</a>
                                        <form method="POST" action="/admin/menu/categories/delete" onsubmit="return confirm('Eliminar la categoria &quot;<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $cat->id; ?>">
                                            <button type="submit" class="admin-btn admin-btn--danger admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--danger">Eliminar</button>
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
