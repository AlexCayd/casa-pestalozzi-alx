<?php
    $alertas = $alertas ?? [];
    $unidades = isset($unidades) && is_iterable($unidades) ? $unidades : ['g', 'kg', 'ml', 'l', 'pza'];
    $accion = $accion ?? 'Guardar';
    $ing = $ingrediente ?? null;
    $nuevo = (bool)($nuevo ?? false);
    $catalogoArticulos = $catalogoArticulos ?? [];
    $almacenesLocales = $almacenesLocales ?? [];
    // Llega con ?pendiente= cuando se viene de una lista de pendientes: volver
    // y cancelar regresan a esa lista, no al inventario sin filtro.
    $volverUrl = (string) ($volverUrl ?? '/admin/inventario');
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Ingrediente'); ?></h2>
            <p class="admin-page__subtitle"><?php echo $nuevo ? 'Configura la asociación del artículo con un almacén del Restaurante.' : 'Define el ingrediente y sus existencias actuales.'; ?></p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="<?php echo htmlspecialchars($volverUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <?php if ($nuevo && !empty($edicionAsociacion)) : ?>
        <form method="GET" action="/admin/inventario/edit" class="admin-form-grid">
            <input type="hidden" name="id" value="<?php echo (int)$ing->id; ?>">
            <div class="admin-field">
                <label class="admin-field__label" for="almacen-vista">Ver otra asociación</label>
                <select id="almacen-vista" name="almacen_id" required>
                    <?php foreach ($almacenesLocales as $almacen) : ?><option value="<?php echo (int)$almacen['almacen_id']; ?>" <?php echo (int)($almacenId??0)===(int)$almacen['almacen_id']?'selected':''; ?>><?php echo htmlspecialchars($almacen['nombre']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--secondary">Cargar asociación</button></div>
        </form>
        <?php endif; ?>

        <form class="admin-form-grid" method="POST">
            <?php if ($nuevo && empty($edicionAsociacion)) : ?>
            <div class="admin-field admin-form-grid__full">
                <label class="admin-field__label" for="articulo_id">Artículo del catálogo compartido</label>
                <select id="articulo_id" name="articulo_id" required>
                    <option value="">Elige un artículo existente</option>
                    <?php foreach ($catalogoArticulos as $articulo) : ?>
                        <?php if ((int)$articulo->activo === 1) : ?>
                        <option value="<?php echo (int)$articulo->id; ?>" <?php echo (int)($ing->articulo_id??0)===(int)$articulo->id?'selected':''; ?>><?php echo htmlspecialchars($articulo->nombre . ' — ' . $articulo->unidad); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <p class="admin-field__hint">El catálogo maestro se administra desde Global. Aquí puedes asociar artículos existentes al almacén local.</p>
            </div>
            <?php elseif ($nuevo) : ?>
            <div class="admin-field admin-form-grid__full">
                <span class="admin-field__label">Artículo compartido</span>
                <p><?php echo htmlspecialchars($ing->nombre . ' — ' . $ing->unidad); ?></p>
                <input type="hidden" name="id" value="<?php echo (int)$ing->id; ?>">
            </div>
            <?php else : ?>
            <div class="admin-field">
                <label class="admin-field__label" for="nombre">Nombre del ingrediente</label>
                <input type="text" id="nombre" name="nombre" maxlength="120" required
                       value="<?php echo htmlspecialchars($ing->nombre ?? ''); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="unidad">Unidad de medida</label>
                <select id="unidad" name="unidad">
                    <?php foreach ($unidades as $u) : ?>
                        <option value="<?php echo htmlspecialchars($u); ?>" <?php echo ($ing->unidad ?? 'g') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($nuevo) : ?>
            <div class="admin-field admin-form-grid__full">
                <?php if (!empty($edicionAsociacion)) : ?>
                <?php $almacenActual=null; foreach($almacenesLocales as $almacen) if((int)$almacen['almacen_id']===(int)($almacenId??0)) $almacenActual=$almacen; ?>
                <input type="hidden" name="almacen_id" value="<?php echo (int)($almacenId??0); ?>">
                <span class="admin-field__label">Almacén del Restaurante</span>
                <p><?php echo htmlspecialchars((string)($almacenActual['nombre']??'Asociación no disponible')); ?></p>
                <?php else : ?>
                <label class="admin-field__label" for="almacen_id">Almacén del Restaurante</label>
                <select id="almacen_id" name="almacen_id" required>
                    <option value="">Elige un almacén</option>
                    <?php foreach ($almacenesLocales as $almacen) : ?>
                        <option value="<?php echo (int)$almacen['almacen_id']; ?>" <?php echo (int)($almacenId??0)===(int)$almacen['almacen_id']?'selected':''; ?>><?php echo htmlspecialchars($almacen['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!$nuevo) : ?>
            <div class="admin-field">
                <label class="admin-field__label" for="stock">Stock actual</label>
                <input type="number" id="stock" name="stock" step="0.001"
                       value="<?php echo htmlspecialchars((string) ($ing->stock ?? 0)); ?>">
            </div>
            <?php endif; ?>

            <?php if ($nuevo) : ?>
            <div class="admin-field">
                <label class="admin-field__label" for="stock_minimo">Mínimo de esta asociación</label>
                <input type="number" id="stock_minimo" name="stock_minimo" step="0.001" min="0" value="<?php echo htmlspecialchars((string)($ing->stock_minimo??'0.000')); ?>">
            </div>
            <?php else : ?>
            <div class="admin-field">
                <label class="admin-field__label" for="stock_minimo">Stock mínimo (alerta)</label>
                <input type="number" id="stock_minimo" name="stock_minimo" step="0.001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->stock_minimo ?? 0)); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label admin-field__label--split" for="costo">
                    <span>Costo por unidad</span>
                    <span>MXN / <?php echo htmlspecialchars($ing->unidad ?? 'g'); ?></span>
                </label>
                <input type="number" id="costo" name="costo" step="0.0001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->costo ?? 0)); ?>">
                <?php if (!empty($ing->id)) : ?>
                    <p class="admin-field__hint">
                        <button type="button" class="admin-btn admin-btn--ghost admin-proveedores__historial"
                                data-historial-precios
                                data-historial-entidad="ingrediente"
                                data-historial-id="<?php echo (int) $ing->id; ?>"
                                data-historial-titulo="<?php echo htmlspecialchars($ing->nombre ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            Ver histórico de costos
                        </button>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
                /*
                 * Proveedores del ingrediente.
                 *
                 * El costo de arriba es el que usan el COGS y la valorización de
                 * existencias; esto es a cuánto lo vende cada quien, que es otra
                 * cosa y por eso convive con él en vez de sustituirlo. El
                 * preferente es el que se propone al recibir mercancía.
                 */
                $proveedores = $proveedores ?? [];
                $proveedoresAsignados = $proveedoresAsignados ?? [];
            ?>
            <?php /* id="proveedores": la lista de pendientes «Sin proveedor»
                     enlaza aquí, y inventario.js abre una fila vacía si no hay
                     ninguna. scroll-margin en inventario.scss, por la topbar. */ ?>
            <div class="admin-field admin-form-grid__full admin-proveedores" id="proveedores" data-proveedores>
                <span class="admin-field__label" id="proveedores-rotulo">Proveedores</span>

                <?php if (empty($proveedores)) : ?>
                    <p class="admin-field__hint">
                        Todavía no hay proveedores dados de alta.
                        <a href="/admin/inventario/proveedores/create">Crear el primero</a>.
                    </p>
                <?php else : ?>
                    <input type="hidden" name="proveedores_presentes" value="1">
                    <table class="admin-table admin-proveedores__table">
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th>Costo / <?php echo htmlspecialchars($ing->unidad ?? 'g'); ?></th>
                                <th>Clave del proveedor</th>
                                <th>Preferente</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody data-proveedores-filas>
                            <?php foreach ($proveedoresAsignados as $indice => $asignado) : ?>
                                <tr class="admin-proveedores__fila">
                                    <td>
                                        <?php /* aria-label: la celda no tiene <label>, y sin
                                                 nombre el selector se anunciaba sólo con su valor
                                                 («Abarrotes El Puente») sin decir qué campo es. */ ?>
                                        <select name="proveedor_id[]" aria-label="Proveedor">
                                            <?php foreach ($proveedores as $prov) : ?>
                                                <option value="<?php echo (int) $prov->id; ?>"
                                                    <?php echo (int) $prov->id === (int) $asignado->proveedor_id ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($prov->nombre); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="proveedor_costo[]" step="0.0001" min="0" aria-label="Costo del proveedor"
                                               value="<?php echo htmlspecialchars((string) $asignado->costo); ?>"></td>
                                    <td><input type="text" name="proveedor_codigo[]" maxlength="60" aria-label="Clave del proveedor"
                                               value="<?php echo htmlspecialchars((string) ($asignado->codigo ?? '')); ?>"></td>
                                    <td class="admin-table__num">
                                        <input type="radio" name="proveedor_preferente" value="<?php echo (int) $indice; ?>" aria-label="Preferente"
                                            <?php echo (int) $asignado->preferente === 1 ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="admin-table__num">
                                        <button type="button" class="admin-btn admin-btn--ghost" data-proveedor-quitar>Quitar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="admin-field__hint" data-proveedores-vacio <?php echo empty($proveedoresAsignados) ? '' : 'hidden'; ?>>
                        <?php echo $nuevo ? 'Sin proveedores asignados. Los costos de las ventas se conservan en cada movimiento.' : 'Sin proveedores asignados. El costo de arriba se sigue usando para el COGS.'; ?>
                    </p>

                    <?php /* La plantilla vive en el marcado y no en el JS: así el
                             <select> de proveedores se pinta una sola vez desde PHP
                             y no hay que serializar el catálogo a JavaScript. */ ?>
                    <template data-proveedor-plantilla>
                        <tr class="admin-proveedores__fila">
                            <td>
                                <select name="proveedor_id[]" aria-label="Proveedor">
                                    <?php foreach ($proveedores as $prov) : ?>
                                        <option value="<?php echo (int) $prov->id; ?>"><?php echo htmlspecialchars($prov->nombre); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="proveedor_costo[]" step="0.0001" min="0" value="0" aria-label="Costo del proveedor"></td>
                            <td><input type="text" name="proveedor_codigo[]" maxlength="60" aria-label="Clave del proveedor"></td>
                            <td class="admin-table__num"><input type="radio" name="proveedor_preferente" value="" aria-label="Preferente"></td>
                            <td class="admin-table__num">
                                <button type="button" class="admin-btn admin-btn--ghost" data-proveedor-quitar>Quitar</button>
                            </td>
                        </tr>
                    </template>

                    <div class="admin-proveedores__acciones">
                        <button type="button" class="admin-btn admin-btn--secondary" data-proveedor-agregar>Agregar proveedor</button>
                        <a class="admin-btn admin-btn--ghost" href="/admin/inventario/proveedores">Administrar proveedores</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$nuevo) : ?><label class="admin-switch admin-form-grid__full">
                <input type="checkbox" name="activo" value="1" <?php echo (int) ($ing->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Ingrediente activo</span>
            </label><?php elseif (!empty($edicionAsociacion)) : ?><label class="admin-switch admin-form-grid__full">
                <input type="checkbox" name="asociacion_activa" value="1" <?php echo (int)($ing->activo??1)===1?'checked':''; ?>>
                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Asociación activa</span>
            </label><?php endif; ?>

            <div class="admin-form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary" href="<?php echo htmlspecialchars($volverUrl, ENT_QUOTES, 'UTF-8'); ?>">Cancelar</a>
            </div>
        </form>
    </section>

    <?php include __DIR__ . '/../partials/_historial-precios-modal.php'; ?>
</section>
