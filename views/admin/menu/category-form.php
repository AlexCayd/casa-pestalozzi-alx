<?php
    use Model\CategoriasMenu;

    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar cambios';
    // El catálogo de cartas lo pasa el controlador; el respaldo evita que
    // un render parcial deje el grupo de pastillas vacío y sin poder guardar.
    $cartas = is_array($cartas ?? null) && $cartas ? $cartas : CategoriasMenu::CARTAS;
    $cartaActual = CategoriasMenu::normalizarCarta($categoria->carta ?? null);
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Categorías</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Categoría'); ?></h2>
            <p class="admin-page__subtitle">Una categoría agrupa platillos y decide en cuál de las dos cartas se imprimen.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/menu/categorias">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos de la categoría</h3>
                <p>Completa nombre, carta, imagen y visibilidad.</p>
            </div>
        </div>

        <?php if (!empty($alertas['error'])) : ?>
            <div class="admin-menu__alert">
                <strong>Revisa los siguientes datos:</strong>
                <ul>
                    <?php foreach ($alertas['error'] as $error) : ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="admin-menu__form" method="POST" enctype="multipart/form-data">
            <label for="nombre">Nombre de la categoría</label>
            <input type="text" id="nombre" name="nombre" maxlength="40"
                   value="<?php echo htmlspecialchars($categoria->nombre ?? ''); ?>" required>

            <?php /* Dos opciones excluyentes y el catálogo entero cabe en pantalla:
                     es justo el caso de admin-pills, no el de un <select>. El radio es
                     real, así que conserva el envío, el teclado y el estado tras un
                     POST fallido sin una línea de JS. */ ?>
            <fieldset class="admin-menu__field admin-menu__field--full admin-pills">
                <legend class="admin-pills__legend">Carta</legend>
                <div class="admin-pills__group">
                    <?php foreach ($cartas as $valor => $carta) : ?>
                        <label class="admin-pill">
                            <input type="radio" name="carta" value="<?php echo htmlspecialchars((string) $valor, ENT_QUOTES); ?>"
                                   <?php echo $cartaActual === (string) $valor ? 'checked' : ''; ?> required>
                            <span class="admin-pill__body">
                                <span class="admin-pill__title"><?php echo htmlspecialchars((string) ($carta['titulo'] ?? $valor)); ?></span>
                                <span class="admin-pill__hint"><?php echo htmlspecialchars((string) ($carta['ayuda'] ?? '')); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label for="imagen">Imagen de la categoría</label>
            <?php if (!empty($categoria->img)) : ?>
                <div class="admin-menu__current-image">
                    <img src="/<?php echo htmlspecialchars(ltrim($categoria->img, '/')); ?>"
                         alt="Imagen actual de la categoría">
                    <span>Imagen actual. Sube una nueva para reemplazarla.</span>
                </div>
            <?php endif; ?>
            <input type="file" id="imagen" name="imagen" accept="image/*"
                   <?php echo empty($categoria->img) ? 'required' : ''; ?>>
            <p class="admin-menu__help">Formatos: JPG, PNG, WebP, GIF o AVIF. La imagen se convierte a WebP desde el uploader actual.</p>

            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($categoria->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Categoría visible en el menú</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categorias">Cancelar</a>
            </div>
        </form>
    </section>
</section>
