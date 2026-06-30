<?php
    $desde = $totalMenu === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalMenu);
?>

<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menu</span>
            <h2 class="admin-page__title">Platillos</h2>
            <p class="admin-page__subtitle">Administra los platillos disponibles, su precio, categoria, etiqueta y estado visible.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu">Resumen</a>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/items/create">Nuevo platillo</a>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo); ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <section class="admin-menu__panel admin-panel admin-card" id="items">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Platillos registrados</h3>
                <p>Mostrando <?php echo $desde; ?>-<?php echo $hasta; ?> de <?php echo $totalMenu; ?> platillos.</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items/create">Agregar</a>
        </div>

        <?php if (empty($platillos)) : ?>
            <p class="admin-menu__empty admin-empty">No hay platillos registrados.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table admin-menu__table--items">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripcion</th>
                            <th>Precio</th>
                            <th>Categoria</th>
                            <th>Tag</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($platillos as $platillo) : ?>
                            <tr>
                                <td><?php echo (int) $platillo->id; ?></td>
                                <td><?php echo htmlspecialchars($platillo->nombre); ?></td>
                                <td class="admin-menu__description"><?php echo htmlspecialchars($platillo->descripcion); ?></td>
                                <td>$<?php echo number_format((float) $platillo->precio, 2); ?></td>
                                <td><?php echo htmlspecialchars($categoriasMap[$platillo->categoria_id] ?? '#' . $platillo->categoria_id); ?></td>
                                <td>
                                    <?php if ($platillo->tag) : ?>
                                        <span class="admin-menu__tag"><?php echo htmlspecialchars($platillo->tag); ?></span>
                                    <?php else : ?>
                                        <span class="admin-menu__muted">Sin tag</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-menu__badge <?php echo $platillo->activo ? 'is-active' : 'is-inactive'; ?>">
                                        <?php echo $platillo->activo ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-menu__row-actions">
                                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/menu/items/edit?id=<?php echo (int) $platillo->id; ?>">Editar</a>
                                        <form method="POST" action="/admin/menu/items/delete" onsubmit="return confirm('Eliminar el platillo &quot;<?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                            <button type="submit" class="admin-btn admin-btn--danger admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--danger">Eliminar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1) : ?>
                <nav class="admin-menu__pagination" aria-label="Paginacion de platillos">
                    <?php if ($paginaActual > 1) : ?>
                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/menu/items?page=<?php echo $paginaActual - 1; ?>#items">Anterior</a>
                    <?php else : ?>
                        <span class="admin-btn admin-btn--disabled admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--disabled">Anterior</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPaginas; $i++) : ?>
                        <?php if ($i === $paginaActual) : ?>
                            <span class="admin-btn admin-btn--primary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--primary"><?php echo $i; ?></span>
                        <?php else : ?>
                            <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/menu/items?page=<?php echo $i; ?>#items"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($paginaActual < $totalPaginas) : ?>
                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/menu/items?page=<?php echo $paginaActual + 1; ?>#items">Siguiente</a>
                    <?php else : ?>
                        <span class="admin-btn admin-btn--disabled admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--disabled">Siguiente</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>
