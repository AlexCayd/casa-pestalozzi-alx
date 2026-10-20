<?php
    /**
     * Formulario único del platillo.
     *
     * Fusiona el antiguo item-form.php (nombre, descripción, precio, tag) con
     * la parte de datos del form de Productos (categoría y área en pestañas).
     * La receta se compone aparte, en /admin/recetas.
     */
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar';
    $platillo = $platillo ?? null;
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $areas = isset($areas) && is_iterable($areas) ? $areas : [];
    $esNuevo = empty($platillo->id);

    require_once __DIR__ . '/../partials/_icons.php';

    // Estado de la foto (Services\Menu\FotoPlatillo::vista()). La «propia» es
    // la que el formulario sostiene ahora: la pendiente de un POST fallido
    // gana a la guardada, porque es la que se guardará si nadie la quita.
    $foto = is_array($foto ?? null) ? $foto : [];
    $fotoActual = $foto['actual'] ?? null;
    $fotoPendiente = is_array($foto['pendiente'] ?? null) ? $foto['pendiente'] : null;
    $fotoQuitar = !empty($foto['quitar']);
    $fotoErrores = is_array($foto['errores'] ?? null) ? $foto['errores'] : [];
    $fotoPropia = $fotoPendiente['url'] ?? $fotoActual;

    // La foto por defecto es la de la categoría elegida: es lo que la carta
    // enseña cuando el platillo no tiene la suya.
    $categoriaFoto = null;
    $categoriaNombre = '';
    $esEspecial = false;
    foreach ($categorias as $cat) {
        if ((int) $cat->id === (int) ($platillo->categoria_id ?? 0)) {
            $categoriaFoto = \Services\Menu\FotoPlatillo::url($cat->img ?? null);
            $categoriaNombre = (string) $cat->nombre;
            // Los especiales de temporada no salen en la carta sino en la
            // vitrina de la landing: mismos estados, con otro destino en el
            // texto. Y sin foto propia ni de categoría no salen (Carta::especiales()).
            $esEspecial = \Model\CategoriasMenu::normalizarCarta($cat->carta ?? null) === \Model\CategoriasMenu::CARTA_TEMPORADA;
            break;
        }
    }

    $usaPropia = $fotoPropia !== null && !$fotoQuitar;
    if ($usaPropia) {
        $fotoEstado = $fotoPendiente !== null ? 'pendiente' : 'propia';
        $fotoSrc = $fotoPropia;
    } else {
        $fotoEstado = $categoriaFoto !== null ? 'categoria' : 'vacia';
        $fotoSrc = $categoriaFoto;
    }

    // El texto de estado lo reescribe el JS al cambiar de archivo o de
    // categoría; éste es el del primer render, y el único sin JS. Espejo de
    // foto-platillo.js: los dos textos tienen que coincidir.
    $categoriaCita = $categoriaNombre !== '' ? '«' . $categoriaNombre . '»' : '';
    $destino = $esEspecial ? 'La landing' : 'La carta';
    $fueraDeLanding = $esEspecial ? ': el especial no saldrá en la landing hasta tener una' : '';
    if ($fotoEstado === 'pendiente') {
        $fotoTexto = 'Foto nueva, todavía sin guardar: se guarda junto con el platillo.';
    } elseif ($fotoEstado === 'propia') {
        $fotoTexto = $esEspecial ? 'La landing muestra esta foto en «Especiales de temporada».' : 'La carta muestra esta foto.';
    } elseif ($fotoQuitar) {
        if ($categoriaFoto !== null) {
            $fotoTexto = 'Se quitará al guardar. ' . $destino . ' volverá a la de ' . $categoriaCita . '.';
        } elseif ($categoriaCita !== '') {
            $fotoTexto = 'Se quitará al guardar, y ' . $categoriaCita . ' tampoco tiene foto' . $fueraDeLanding . '.';
        } else {
            $fotoTexto = 'Se quitará al guardar.';
        }
    } elseif ($categoriaFoto !== null) {
        $fotoTexto = 'Sin foto propia: ' . mb_strtolower($destino) . ' muestra la de ' . $categoriaCita . '.';
    } elseif ($categoriaCita !== '') {
        $fotoTexto = 'Ni el platillo ni ' . $categoriaCita . ' tienen foto' . $fueraDeLanding . '.';
    } else {
        $fotoTexto = 'Sin foto propia: la carta usará la de su categoría.';
    }
?>
<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Platillo'); ?></h2>
            <p class="admin-page__subtitle">Este platillo se publica en la carta y en el PDF, y es el mismo que el mesero toca en el punto de venta.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/menu">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <form method="POST" enctype="multipart/form-data">
        <section class="admin-panel admin-card">
            <?php include __DIR__ . '/../partials/alertas.php'; ?>

            <div class="admin-form-grid">
                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="nombre">Nombre del platillo</label>
                    <input type="text" id="nombre" name="nombre" maxlength="120" required
                           value="<?php echo htmlspecialchars($platillo->nombre ?? ''); ?>">
                    <p class="admin-field__hint">Debe ser único: es el nombre con el que la venta descuenta inventario.</p>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="3" required><?php echo htmlspecialchars($platillo->descripcion ?? ''); ?></textarea>
                    <p class="admin-field__hint">Es el texto que ve el comensal en la carta y en el PDF.</p>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Categoría</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Categoría">
                        <?php foreach ($categorias as $cat) : $cid = (int) $cat->id; ?>
                            <label class="admin-tabs__tab">
                                <?php /* data-foto: la foto por defecto que tomaría el platillo
                                         con esta categoría; data-carta, a dónde se publica. Los
                                         dos los lee el campo de foto de abajo. */ ?>
                                <input type="radio" name="categoria_id" value="<?php echo $cid; ?>"
                                       data-foto="<?php echo htmlspecialchars((string) \Services\Menu\FotoPlatillo::url($cat->img ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                       data-carta="<?php echo htmlspecialchars(\Model\CategoriasMenu::normalizarCarta($cat->carta ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                       data-nombre="<?php echo htmlspecialchars((string) $cat->nombre, ENT_QUOTES, 'UTF-8'); ?>"
                                       <?php echo (int) ($platillo->categoria_id ?? 0) === $cid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($cat->nombre); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php /* Va justo debajo de Categoría porque depende de ella: sin foto
                         propia, la carta enseña la de la categoría, y la vista previa
                         cambia en cuanto se elige otra.

                         Sin JS funciona igual: el <input type="file"> y la casilla de
                         «Quitar foto» se ven nativos y el servidor resuelve. Con JS
                         (src/js/admin/core/foto-platillo.js) se recortan y los
                         sustituyen los botones, con vista previa al instante. Quién
                         decide cuál de los dos se ve es menu.scss, por el
                         [data-admin-theme] que el layout pone antes de pintar. */ ?>
                <div class="admin-field admin-form-grid__full admin-foto<?php echo !empty($fotoErrores) ? ' has-error' : ''; ?>"
                     data-foto-platillo
                     data-estado="<?php echo $fotoEstado; ?>"
                     data-actual="<?php echo htmlspecialchars((string) $fotoActual, ENT_QUOTES, 'UTF-8'); ?>"
                     data-pendiente="<?php echo htmlspecialchars((string) ($fotoPendiente['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                     data-especial="<?php echo $esEspecial ? '1' : '0'; ?>">
                    <?php /* El rótulo cambia con la categoría (foto-platillo.js): en un
                             especial de temporada esta foto es la que sale en la landing,
                             y hay que decirlo así, no como «foto del platillo». */ ?>
                    <span class="admin-field__label" id="foto-rotulo"><span data-foto-titulo><?php echo $esEspecial ? 'Foto para la landing' : 'Foto del platillo'; ?></span> <span class="admin-foto__opcional">Opcional</span></span>
                    <p class="admin-field__hint admin-foto__especial" data-foto-especial <?php echo $esEspecial ? '' : 'hidden'; ?>>
                        Es la imagen de referencia con la que este especial sale en «Especiales de temporada» de la landing. Súbela cuando la tengas: sin foto propia, el especial sale con la de su categoría, que se cambia en Categorías.
                    </p>

                    <div class="admin-foto__cuerpo" data-foto-zona>
                        <?php /* Etiqueta del input: un clic en la vista previa abre el
                                 selector. aria-hidden porque lo que dice ya lo dice el texto
                                 de estado, y para el teclado está el botón. */ ?>
                        <label class="admin-foto__vista" for="imagen" aria-hidden="true">
                            <?php /* Sin src cuando no hay foto, y no src="": un src vacío
                                     dispara el evento de error y en algunos motores pide la
                                     propia página como imagen. */ ?>
                            <img class="admin-foto__img" data-foto-img alt=""
                                 <?php echo $fotoSrc !== null ? 'src="' . htmlspecialchars($fotoSrc, ENT_QUOTES, 'UTF-8') . '"' : 'hidden'; ?>>
                            <span class="admin-foto__vacia" data-foto-vacia <?php echo $fotoSrc !== null ? 'hidden' : ''; ?>>
                                <?php echo admin_icon('foto', 28); ?>
                                <span>Sin foto</span>
                            </span>
                            <?php /* Uno u otro según data-estado (menu.scss). */ ?>
                            <span class="admin-foto__sello admin-foto__sello--categoria">De la categoría</span>
                            <span class="admin-foto__sello admin-foto__sello--nueva">Sin guardar</span>
                        </label>

                        <div class="admin-foto__panel">
                            <p class="admin-foto__estado" data-foto-estado aria-live="polite"><?php echo htmlspecialchars($fotoTexto); ?></p>

                            <div class="admin-foto__acciones">
                                <input type="file" id="imagen" name="imagen" class="admin-foto__input" data-foto-input
                                       accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
                                       aria-labelledby="foto-rotulo foto-elegir-texto"
                                       aria-describedby="foto-ayuda foto-error">
                                <label class="admin-btn admin-btn--secondary admin-foto__boton" for="imagen" data-foto-elegir>
                                    <?php echo admin_icon('foto', 16, 'admin-btn__icon'); ?>
                                    <span id="foto-elegir-texto" data-foto-elegir-texto><?php echo $usaPropia ? 'Cambiar foto' : 'Subir foto'; ?></span>
                                </label>

                                <?php if ($fotoPropia !== null) : ?>
                                    <label class="admin-btn admin-btn--ghost admin-foto__boton admin-foto__quitar" data-foto-quitar-boton>
                                        <input type="checkbox" name="<?php echo \Services\Menu\FotoPlatillo::CAMPO_QUITAR; ?>" value="1"
                                               data-foto-quitar <?php echo $fotoQuitar ? 'checked' : ''; ?>>
                                        <?php echo admin_icon('basura', 16, 'admin-btn__icon'); ?>
                                        <span>Quitar foto</span>
                                    </label>
                                <?php endif; ?>

                                <button type="button" class="admin-btn admin-btn--ghost admin-foto__boton" data-foto-deshacer hidden>Deshacer</button>
                            </div>

                            <?php /* El arrastre lo pone el JS: sin él, soltar sobre la vista
                                     previa abriría la imagen en la pestaña, así que la frase
                                     sólo se ve con JS (menu.scss). */ ?>
                            <p class="admin-field__hint admin-foto__ayuda" id="foto-ayuda">JPG, PNG, WebP, GIF o AVIF, hasta 5 MB. Se guarda en WebP, a 1200 px por el lado mayor.<span data-foto-arrastre> También puedes arrastrarla sobre la vista previa.</span></p>
                            <span class="admin-field__error" id="foto-error" data-foto-error role="alert"><?php echo htmlspecialchars(implode(' ', $fotoErrores)); ?></span>
                        </div>
                    </div>

                    <?php if ($fotoPendiente !== null) : ?>
                        <input type="hidden" name="<?php echo \Services\Menu\FotoPlatillo::CAMPO_PENDIENTE; ?>"
                               value="<?php echo htmlspecialchars((string) $fotoPendiente['ficha'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Área de producción</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Área de producción">
                        <?php foreach ($areas as $area) : $aid = (int) $area['id']; ?>
                            <label class="admin-tabs__tab">
                                <input type="radio" name="area_id" value="<?php echo $aid; ?>"
                                       <?php echo (int) ($platillo->area_id ?? 0) === $aid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($area['nombre']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="admin-field__hint">Determina a qué comanda se envía cuando el mesero lo pide.</p>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="precio">Precio (MXN)</label>
                    <input type="number" id="precio" name="precio" step="0.01" min="0" required
                           value="<?php echo htmlspecialchars((string) ($platillo->precio ?? '')); ?>">
                    <?php if (!empty($platillo->id)) : ?>
                        <p class="admin-field__hint">
                            <button type="button" class="admin-btn admin-btn--ghost"
                                    data-historial-precios
                                    data-historial-entidad="producto"
                                    data-historial-id="<?php echo (int) $platillo->id; ?>"
                                    data-historial-titulo="<?php echo htmlspecialchars($platillo->nombre ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                Ver histórico de precios
                            </button>
                        </p>
                    <?php endif; ?>
                </div>

                <label class="admin-switch admin-form-grid__full">
                    <input type="checkbox" name="activo" value="1" <?php echo (int) ($platillo->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Platillo activo (visible en la carta y vendible en el punto de venta)</span>
                </label>
            </div>
        </section>

        <div class="admin-form-grid__actions">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="/admin/menu">Cancelar</a>
            <?php if (!$esNuevo) : ?>
                <a class="admin-btn admin-btn--secondary" href="/admin/recetas/editar?id=<?php echo (int) $platillo->id; ?>">Editar receta</a>
            <?php endif; ?>
        </div>
    </form>

    <?php include __DIR__ . '/../partials/_historial-precios-modal.php'; ?>
</section>
