<?php
    /**
     * Formulario de categoría (sólo edición: el alta rápida vive en el tab «+»
     * del listado de Menú).
     *
     * Sigue la anatomía del formulario del platillo —tarjeta a todo el ancho,
     * rejilla de dos columnas y botones fuera de la tarjeta— y reutiliza su
     * campo de foto (`.admin-foto` + core/foto-platillo.js) en modo
     * «categoria»: aquí no hay foto prestada de nadie ni «Quitar», y la vista
     * previa va en grande porque es la que se ve en la carta.
     *
     * La comparten los dos negocios (AdministracionMenu::vista() pasa la base
     * de las rutas, el token, el permiso y las cartas del negocio activo).
     */
    use Model\CategoriasMenu;

    $menuBase = $menuBase ?? '/admin/menu';
    $menuPuedeEditar = $menuPuedeEditar ?? true;
    $menuContexto = $menuContexto ?? 'restaurante';
    $menuCsrf = $menuCsrf ?? '';

    require_once __DIR__ . '/../partials/_icons.php';

    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar cambios';
    // El catálogo de cartas lo pasa el controlador; el respaldo evita que
    // un render parcial deje el grupo de pastillas vacío y sin poder guardar.
    $cartas = $menuCartas ?? $cartas ?? [];
    $cartas = is_array($cartas ?? null) && $cartas ? $cartas : CategoriasMenu::CARTAS;
    $cartaActual = CategoriasMenu::normalizarCarta($categoria->carta ?? null);

    // Un POST fallido deshace la subida (AdminMenuController::categoryEdit), así
    // que aquí sólo hay dos estados de partida: con imagen guardada o sin ella.
    $imagenUrl = !empty($categoria->img) ? '/' . ltrim((string) $categoria->img, '/') : null;
    $fotoEstado = $imagenUrl !== null ? 'propia' : 'vacia';

    // Para qué sirve la imagen lo decide la CARTA, y no es lo mismo en todas:
    // la carta de comida del Restaurante la enseña con cada platillo sin foto
    // propia (menu.js); un especial sin foto propia ni de categoría no sale
    // (Carta::especiales); la de maridaje no enseña imágenes en ningún sitio;
    // y en la Panadería su carta de comida es la vitrina de /panaderia, que la
    // pone de foto de la sección y, sin ella, la genérica
    // (views/panaderia/_vitrina.php). Cada pastilla lleva los dos textos
    // (data-foto-con / data-foto-sin) y foto-platillo.js los lee de la marcada:
    // al cambiar de carta el estado dice la verdad sin que haya una segunda
    // copia de las frases en el JS.
    $textosImagen = [
        CategoriasMenu::CARTA_COMIDA => [
            'con' => 'En la carta de la landing, es la foto de los platillos de esta categoría que no tienen la suya.',
            'sin' => 'Sin imagen: en la carta, los platillos de esta categoría sin foto propia no tendrán ninguna.',
        ],
        CategoriasMenu::CARTA_MARIDAJE => [
            'con' => 'La carta de maridaje no lleva imágenes: ésta no sale ni en la landing ni en el PDF.',
            'sin' => 'Sin imagen. La carta de maridaje no lleva imágenes, así que no le hace falta.',
        ],
        CategoriasMenu::CARTA_TEMPORADA => [
            'con' => 'En la landing, es la foto de los especiales de esta categoría que no tienen la suya.',
            'sin' => 'Sin imagen: un especial de esta categoría sin foto propia no saldrá en la landing.',
        ],
    ];
    if ($menuContexto === 'panaderia') {
        $textosImagen[CategoriasMenu::CARTA_COMIDA] = [
            'con' => 'Es la foto de esta sección en la vitrina de /panaderia.',
            'sin' => 'Sin imagen: la vitrina de /panaderia pondrá en esta sección su foto genérica.',
        ];
        $textosImagen[CategoriasMenu::CARTA_TEMPORADA] = [
            'con' => 'Esta carta no sale en la landing de la panadería, así que la imagen no se publica.',
            'sin' => 'Sin imagen. Esta carta no sale en la landing de la panadería, así que no le hace falta.',
        ];
    }
    $textosDe = static function (string $carta) use ($textosImagen): array {
        return $textosImagen[$carta] ?? $textosImagen[CategoriasMenu::CARTA_COMIDA];
    };
    $fotoTexto = $textosDe($cartaActual)[$imagenUrl !== null ? 'con' : 'sin'];
?>

<section class="admin-menu admin-menu--modulo admin-menu--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Categorías</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Categoría'); ?></h2>
            <p class="admin-page__subtitle">Una categoría agrupa las ofertas de este negocio y determina su carta.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="<?php echo htmlspecialchars($menuBase, ENT_QUOTES); ?>/categorias">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="menu_csrf" value="<?php echo htmlspecialchars($menuCsrf, ENT_QUOTES); ?>">
        <section class="admin-panel admin-card">
            <?php include __DIR__ . '/../partials/alertas.php'; ?>

            <div class="admin-form-grid">
                <div class="admin-field">
                    <label class="admin-field__label" for="nombre">Nombre de la categoría</label>
                    <input type="text" id="nombre" name="nombre" maxlength="40" required
                           value="<?php echo htmlspecialchars($categoria->nombre ?? ''); ?>">
                </div>

                <?php /* Comparte fila con el nombre, que con 40 caracteres de tope no
                         necesita el ancho entero; se alinea con el CAMPO, no con su
                         rótulo (menu.scss). */ ?>
                <label class="admin-switch admin-menu__visible">
                    <input type="checkbox" name="activo" value="1" <?php echo (int) ($categoria->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Visible en el menú</span>
                </label>

                <?php /* Cuatro opciones excluyentes y el catálogo entero cabe en pantalla:
                         es justo el caso de admin-pills, no el de un <select>. En dos
                         columnas iguales (--columnas) para que las cuatro midan lo
                         mismo y su ayuda no parta cada una a su manera. */ ?>
                <fieldset class="admin-field admin-form-grid__full admin-pills admin-pills--columnas">
                    <legend class="admin-field__label">Carta</legend>
                    <div class="admin-pills__group">
                        <?php foreach ($cartas as $valor => $carta) : ?>
                            <?php $textos = $textosDe((string) $valor); ?>
                            <label class="admin-pill">
                                <input type="radio" name="carta" value="<?php echo htmlspecialchars((string) $valor, ENT_QUOTES); ?>"
                                       data-foto-con="<?php echo htmlspecialchars($textos['con'], ENT_QUOTES, 'UTF-8'); ?>"
                                       data-foto-sin="<?php echo htmlspecialchars($textos['sin'], ENT_QUOTES, 'UTF-8'); ?>"
                                       <?php echo $cartaActual === (string) $valor ? 'checked' : ''; ?> required>
                                <span class="admin-pill__body">
                                    <span class="admin-pill__title"><?php echo htmlspecialchars((string) ($carta['titulo'] ?? $valor)); ?></span>
                                    <span class="admin-pill__hint"><?php echo htmlspecialchars((string) ($carta['ayuda'] ?? '')); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <?php /* El mismo campo de foto que el platillo, en modo «categoria» y
                         en grande. Sin JS, el <input type="file"> se ve nativo y el
                         servidor resuelve igual. */ ?>
                <div class="admin-field admin-form-grid__full admin-foto admin-foto--grande"
                     data-foto-platillo
                     data-foto-modo="categoria"
                     data-estado="<?php echo $fotoEstado; ?>"
                     data-actual="<?php echo htmlspecialchars((string) $imagenUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="admin-field__label" id="foto-rotulo">Imagen de la categoría</span>

                    <div class="admin-foto__cuerpo" data-foto-zona>
                        <?php /* Etiqueta del input: un clic en la vista previa abre el
                                 selector. aria-hidden porque lo que dice ya lo dice el texto
                                 de estado, y para el teclado está el botón. */ ?>
                        <label class="admin-foto__vista" for="imagen" aria-hidden="true">
                            <img class="admin-foto__img" data-foto-img alt=""
                                 <?php echo $imagenUrl !== null ? 'src="' . htmlspecialchars($imagenUrl, ENT_QUOTES, 'UTF-8') . '"' : 'hidden'; ?>>
                            <span class="admin-foto__vacia" data-foto-vacia <?php echo $imagenUrl !== null ? 'hidden' : ''; ?>>
                                <?php echo admin_icon('foto', 36); ?>
                                <span class="admin-foto__vacia-titulo">Sin imagen</span>
                                <span class="admin-foto__vacia-pista">Haz clic para elegir una<span data-foto-arrastre> o arrástrala aquí</span></span>
                            </span>
                            <?php /* Sobre la imagen, al apuntar o al arrastrar un archivo
                                     encima: dice qué hace el clic, o que se puede soltar. */ ?>
                            <span class="admin-foto__invitacion">
                                <?php echo admin_icon('foto', 18); ?>
                                <span class="admin-foto__invitacion-clic">Cambiar imagen</span>
                                <span class="admin-foto__invitacion-soltar">Suéltala para usarla</span>
                            </span>
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
                                    <span id="foto-elegir-texto" data-foto-elegir-texto><?php echo $imagenUrl !== null ? 'Cambiar imagen' : 'Subir imagen'; ?></span>
                                </label>
                                <button type="button" class="admin-btn admin-btn--ghost admin-foto__boton" data-foto-deshacer hidden>Deshacer</button>
                            </div>

                            <?php /* El alcance —qué cambia en la landing al cambiar esta
                                     imagen— lo dice el estado de arriba, según la carta
                                     elegida; aquí sólo el formato, como en el platillo.
                                     Repetirlo aquí fue lo que dejó la misma frase dos veces
                                     seguidas, y además inexacta en tres de las cuatro cartas. */ ?>
                            <p class="admin-field__hint admin-foto__ayuda" id="foto-ayuda">JPG, PNG, WebP, GIF o AVIF, hasta 5 MB. Se guarda en WebP, a 1200 px por el lado mayor.</p>
                            <span class="admin-field__error" id="foto-error" data-foto-error role="alert"></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="admin-form-grid__actions">
            <button type="submit" <?php echo $menuPuedeEditar ? '' : 'disabled'; ?> class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="<?php echo htmlspecialchars($menuBase, ENT_QUOTES); ?>/categorias">Cancelar</a>
        </div>
    </form>
</section>
<?php unset($textosImagen, $textosDe, $textos); ?>
