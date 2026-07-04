<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Impresión</span>
            <h2 class="admin-page__title">Estaciones de impresión</h2>
            <p class="admin-page__subtitle">Administra las impresoras térmicas por red (ESC/POS). Una por área de producción para comandas y una para la cuenta del cliente.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/printers/create">Nueva impresora</a>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo); ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <section class="admin-menu__panel admin-panel admin-card" id="printers">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Impresoras registradas</h3>
                <p>Total: <?php echo count($impresoras); ?> impresora(s).</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/printers/create">Agregar</a>
        </div>

        <?php if (empty($impresoras)) : ?>
            <p class="admin-menu__empty admin-empty">No hay impresoras registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table admin-menu__table--items">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Área</th>
                            <th>Destino</th>
                            <th>Ancho</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($impresoras as $impresora) : ?>
                            <tr>
                                <td><?php echo (int) $impresora->id; ?></td>
                                <td><?php echo htmlspecialchars($impresora->nombre); ?></td>
                                <td>
                                    <span class="admin-menu__tag"><?php echo $impresora->rol === 'cuenta' ? 'Cuenta' : 'Comanda'; ?></span>
                                </td>
                                <td>
                                    <?php if ($impresora->area_id !== null && isset($areas[(int) $impresora->area_id])) : ?>
                                        <?php echo htmlspecialchars($areas[(int) $impresora->area_id]); ?>
                                    <?php else : ?>
                                        <span class="admin-menu__muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $etiquetaConexion = ['red' => 'Red', 'windows' => 'Windows'];
                                    ?>
                                    <span class="admin-menu__tag"><?php echo $etiquetaConexion[$impresora->conexion] ?? 'Red'; ?></span>
                                    <?php echo htmlspecialchars($impresora->destino()); ?>
                                </td>
                                <td><?php echo (int) $impresora->ancho; ?> col</td>
                                <td>
                                    <span class="admin-menu__badge <?php echo $impresora->activo ? 'is-active' : 'is-inactive'; ?>">
                                        <?php echo $impresora->activo ? 'Activa' : 'Inactiva'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-menu__row-actions">
                                        <form method="POST" action="/admin/printers/test">
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
                                            <button type="submit" class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light">Imprimir prueba</button>
                                        </form>
                                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="/admin/printers/edit?id=<?php echo (int) $impresora->id; ?>">Editar</a>
                                        <form method="POST" action="/admin/printers/delete" onsubmit="return confirm('Eliminar la impresora &quot;<?php echo htmlspecialchars($impresora->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
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
