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
                    <?php /* Sin columna de ID: es la clave de la base, no un dato con el
                             que se opere una impresora —se la busca por su nombre y su
                             destino—. El id sigue viajando en el data-row-href de la
                             fila, en el enlace de editar y en los dos formularios POST. */ ?>
                    <thead>
                        <tr>
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
                            <tr data-row-href="/admin/printers/edit?id=<?php echo (int) $impresora->id; ?>">
                                <td><?php echo htmlspecialchars($impresora->nombre); ?></td>
                                <?php /* El rol es la categoría por la que se busca una fila:
                                         índigo para la que imprime la cuenta del cliente y
                                         turquesa para las que imprimen comandas de área.
                                         Antes ROL y CONEXIÓN compartían el mismo dorado y
                                         "Cuenta" y "Red" se leían como el mismo tipo de dato. */ ?>
                                <td>
                                    <span class="admin-tag admin-tag--<?php echo $impresora->rol === 'cuenta' ? 'cuenta' : 'comanda'; ?>">
                                        <?php echo $impresora->rol === 'cuenta' ? 'Cuenta' : 'Comanda'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($impresora->area_id !== null && isset($areas[(int) $impresora->area_id])) : ?>
                                        <?php echo htmlspecialchars($areas[(int) $impresora->area_id]); ?>
                                    <?php else : ?>
                                        <span class="admin-menu__muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php /* La conexión va neutra a propósito: es un detalle
                                         técnico, no una categoría que se busque de un
                                         vistazo. El destino baja a su propia línea —
                                         pegado a la etiqueta se leía como una sola cosa—
                                         y en mono, que es una dirección de red. */ ?>
                                <td>
                                    <?php
                                        $etiquetaConexion = ['red' => 'Red', 'windows' => 'Windows'];
                                    ?>
                                    <span class="admin-tag admin-tag--neutral"><?php echo $etiquetaConexion[$impresora->conexion] ?? 'Red'; ?></span>
                                    <span class="admin-printers__destino admin-num"><?php echo htmlspecialchars($impresora->destino()); ?></span>
                                </td>
                                <td class="admin-num"><?php echo (int) $impresora->ancho; ?> col</td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $impresora->activo ? 'success' : 'danger'; ?>">
                                        <?php echo $impresora->activo ? 'Activa' : 'Inactiva'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <?php /* Imprimir prueba es la única acción del panel sin
                                                 equivalente icónico previo: lleva impresora, y el
                                                 título y el aria-label cargan lo que decía el
                                                 texto. Va en neutro —no es destructiva ni es la
                                                 acción principal de la fila. */ ?>
                                        <form method="POST" action="/admin/printers/test">
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
                                            <button type="submit" class="admin-icon-button"
                                                    title="Imprimir prueba"
                                                    aria-label="Imprimir prueba en <?php echo htmlspecialchars($impresora->nombre, ENT_QUOTES); ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>
                                            </button>
                                        </form>
                                        <a class="admin-icon-button admin-icon-button--edit"
                                           href="/admin/printers/edit?id=<?php echo (int) $impresora->id; ?>"
                                           title="Editar"
                                           aria-label="Editar <?php echo htmlspecialchars($impresora->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/printers/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar impresora"
                                              data-confirm-title="¿Eliminar «<?php echo htmlspecialchars($impresora->nombre, ENT_QUOTES); ?>»?"
                                              data-confirm-description="Las comandas que se enviaban a ella dejarán de imprimirse."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger"
                                                    title="Eliminar"
                                                    aria-label="Eliminar <?php echo htmlspecialchars($impresora->nombre, ENT_QUOTES); ?>">
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
