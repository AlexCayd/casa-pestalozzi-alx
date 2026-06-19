<?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
    <?php foreach ($mensajes as $mensaje) : ?>
        <div class="flash <?php echo htmlspecialchars($tipo); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<!-- ============================= CATEGORÍAS ============================= -->
<section class="panel">
    <div class="panel-head">
        <h2>Categorías <span class="muted">(<?php echo count($categorias); ?>)</span></h2>
        <a class="btn btn-primary" href="/dashboard/categorias/crear">+ Nueva categoría</a>
    </div>

    <?php if (empty($categorias)) : ?>
        <p class="empty">No hay categorías registradas.</p>
    <?php else : ?>
        <table>
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
                                <img class="thumb" src="/<?php echo htmlspecialchars(ltrim($cat->img, '/')); ?>"
                                     alt="<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>" loading="lazy">
                            <?php else : ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $cat->activo ? 'on' : 'off'; ?>">
                                <?php echo $cat->activo ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn btn-light btn-sm" href="/dashboard/categorias/editar?id=<?php echo (int) $cat->id; ?>">Editar</a>
                                <form method="POST" action="/dashboard/categorias/eliminar" onsubmit="return confirm('¿Eliminar la categoría &quot;<?php echo htmlspecialchars($cat->nombre, ENT_QUOTES); ?>&quot;?');">
                                    <input type="hidden" name="id" value="<?php echo (int) $cat->id; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- ============================= PLATILLOS ============================= -->
<?php
    $desde = $totalMenu === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalMenu);
?>
<section class="panel" id="menu">
    <div class="panel-head">
        <h2>Platillos del Menú
            <span class="muted">(mostrando <?php echo $desde; ?>–<?php echo $hasta; ?> de <?php echo $totalMenu; ?>)</span>
        </h2>
        <a class="btn btn-primary" href="/dashboard/menu/crear">+ Nuevo platillo</a>
    </div>

    <?php if (empty($platillos)) : ?>
        <p class="empty">No hay platillos registrados.</p>
    <?php else : ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Descripción</th>
                    <th>Precio</th>
                    <th>Categoría</th>
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
                        <td class="desc"><?php echo htmlspecialchars($platillo->descripcion); ?></td>
                        <td>$<?php echo number_format((float) $platillo->precio, 2); ?></td>
                        <td><?php echo htmlspecialchars($categoriasMap[$platillo->categoria_id] ?? '#' . $platillo->categoria_id); ?></td>
                        <td><?php echo $platillo->tag ? '<span class="tag">' . htmlspecialchars($platillo->tag) . '</span>' : '—'; ?></td>
                        <td>
                            <span class="badge <?php echo $platillo->activo ? 'on' : 'off'; ?>">
                                <?php echo $platillo->activo ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn btn-light btn-sm" href="/dashboard/menu/editar?id=<?php echo (int) $platillo->id; ?>">Editar</a>
                                <form method="POST" action="/dashboard/menu/eliminar" onsubmit="return confirm('¿Eliminar el platillo &quot;<?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>&quot;?');">
                                    <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPaginas > 1) : ?>
            <nav class="paginacion">
                <?php if ($paginaActual > 1) : ?>
                    <a class="btn btn-light btn-sm" href="/dashboard?page=<?php echo $paginaActual - 1; ?>#menu">← Anterior</a>
                <?php else : ?>
                    <span class="btn btn-light btn-sm disabled">← Anterior</span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPaginas; $i++) : ?>
                    <?php if ($i === $paginaActual) : ?>
                        <span class="btn btn-primary btn-sm"><?php echo $i; ?></span>
                    <?php else : ?>
                        <a class="btn btn-light btn-sm" href="/dashboard?page=<?php echo $i; ?>#menu"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($paginaActual < $totalPaginas) : ?>
                    <a class="btn btn-light btn-sm" href="/dashboard?page=<?php echo $paginaActual + 1; ?>#menu">Siguiente →</a>
                <?php else : ?>
                    <span class="btn btn-light btn-sm disabled">Siguiente →</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
