<?php
require_once __DIR__ . '/../partials/_icons.php';
$paletaMarca = is_array($paletaMarca ?? null) ? $paletaMarca : \Services\Configuration\PaletaLanding::marca();
$paleta = is_array($paleta ?? null) ? $paleta : $paletaMarca;
$coloresPaleta = is_array($coloresPaleta ?? null) ? $coloresPaleta : \Services\Configuration\PaletaLanding::COLORES;
$comprobacionesContraste = is_array($comprobacionesContraste ?? null) ? $comprobacionesContraste : [];
$contrasteActual = is_array($contrasteActual ?? null) ? $contrasteActual : [];
$contrasteFallido = is_array($contrasteFallido ?? null) ? $contrasteFallido : [];
$contrasteMinimo = (float) ($contrasteMinimo ?? 4.5);
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn ($value): string => htmlspecialchars(
    json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// Lo que pinta la muestra y la vista previa. Un valor que no es color (tras un
// POST con un error de formato) cae al de marca SÓLO aquí: el campo de texto
// sigue enseñando lo que se tecleó, que es lo que hay que corregir.
$normalizar = [\Services\Configuration\PaletaLanding::class, 'normalizar'];
$pintable = [];
foreach ($paletaMarca as $clave => $deMarca) {
    $pintable[$clave] = $normalizar($paleta[$clave] ?? null) ?? $deMarca;
}
$estiloPrevia = '';
foreach ($pintable as $clave => $hex) {
    $estiloPrevia .= '--pv-' . $clave . ':' . $hex . ';';
}
$formatoRatio = static fn ($ratio): string => number_format((float) $ratio, 2, '.', '') . ':1';

// El resumen sale ya escrito y con el MISMO texto que compone el JS
// (initPalette): así la primera pasada del script no muta la región viva y el
// lector de pantalla no anuncia nada al cargar, sólo cuando algo cambia.
$bajosContraste = count(array_filter($contrasteActual, static fn ($c): bool => empty($c['ok'])));
if ($bajosContraste === 0) {
    $resumenContraste = 'Todas las secciones se leen con esta paleta.';
} else {
    $resumenContraste = ($bajosContraste === 1 ? 'Una comprobación queda' : $bajosContraste . ' comprobaciones quedan')
        . ' por debajo de ' . $formatoRatio($contrasteMinimo)
        . '. Se puede guardar, pero el sistema pedirá confirmarlo.';
}
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="palette">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Paleta de la landing</h1>
            <p class="admin-page__subtitle">Los cuatro colores de marca. Fondos, textos, líneas y botones de la página pública se derivan de ellos, así que cambiar uno cambia todas las secciones donde aparece.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/configuracion">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-config-layout">
        <section class="admin-panel admin-card admin-config-panel" aria-labelledby="palette-form-title">
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="palette-form-title">Colores de marca</h2>
                    <p>Se aplican a la landing, a la pantalla de opinión del comensal y a la carta en PDF. El panel y las pantallas de piso no cambian.</p>
                </div>
            </div>

            <form
                class="admin-palette-form"
                method="POST"
                action="/admin/configuracion/paleta"
                novalidate
                data-palette-form
                data-palette-marca="<?php echo $json($paletaMarca); ?>"
                data-palette-checks="<?php echo $json($comprobacionesContraste); ?>"
                data-palette-min="<?php echo $h($contrasteMinimo); ?>"
            >
                <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

                <div class="admin-palette-fields">
                    <?php foreach ($coloresPaleta as $clave => $color) : ?>
                        <?php
                        $valor = (string) ($paleta[$clave] ?? $paletaMarca[$clave]);
                        $deMarca = (string) $paletaMarca[$clave];
                        $esDeMarca = $normalizar($valor) === $deMarca;
                        $idBase = 'paleta-' . $clave;
                        ?>
                        <div class="admin-palette-field<?php echo $esDeMarca ? '' : ' is-custom'; ?>" data-palette-field="<?php echo $h($clave); ?>">
                            <label class="admin-palette-field__swatch" style="--swatch: <?php echo $h($pintable[$clave]); ?>">
                                <span class="admin-palette-field__swatch-label">Elegir <?php echo $h(mb_strtolower($color['etiqueta'])); ?></span>
                                <input
                                    type="color"
                                    value="<?php echo $h($pintable[$clave]); ?>"
                                    data-palette-picker
                                    aria-describedby="<?php echo $h($idBase); ?>-uso"
                                >
                            </label>

                            <div class="admin-palette-field__body">
                                <label class="admin-palette-field__name" for="<?php echo $h($idBase); ?>">
                                    <?php echo $h($color['etiqueta']); ?>
                                    <code class="admin-palette-field__token" aria-hidden="true"><?php echo $h($color['token']); ?></code>
                                </label>
                                <p class="admin-palette-field__use" id="<?php echo $h($idBase); ?>-uso"><?php echo $h($color['uso']); ?></p>
                            </div>

                            <div class="admin-palette-field__value">
                                <input
                                    type="text"
                                    class="admin-palette-field__hex admin-num"
                                    id="<?php echo $h($idBase); ?>"
                                    name="paleta[<?php echo $h($clave); ?>]"
                                    value="<?php echo $h($valor); ?>"
                                    maxlength="7"
                                    inputmode="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    aria-describedby="<?php echo $h($idBase); ?>-marca <?php echo $h($idBase); ?>-error"
                                    data-palette-hex
                                >
                                <span class="admin-palette-field__brand" id="<?php echo $h($idBase); ?>-marca">
                                    <span class="admin-palette-field__brand-text">Marca <span class="admin-num"><?php echo $h($deMarca); ?></span></span>
                                    <button type="button" class="admin-palette-field__reset" data-palette-reset <?php echo $esDeMarca ? 'hidden' : ''; ?>>
                                        Restablecer
                                    </button>
                                </span>
                                <span class="admin-palette-field__error" id="<?php echo $h($idBase); ?>-error" data-palette-error aria-live="polite"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <section class="admin-palette-checks" aria-labelledby="palette-checks-title">
                    <h3 id="palette-checks-title" class="admin-palette-checks__title admin-eyebrow">Legibilidad</h3>
                    <p class="admin-palette-checks__intro">El texto más tenue de cada sección tiene que llegar a <span class="admin-num"><?php echo $h($formatoRatio($contrasteMinimo)); ?></span> de contraste (WCAG AA).</p>
                    <ul class="admin-palette-checks__list" data-palette-checks-list>
                        <?php foreach ($contrasteActual as $comprobacion) : ?>
                            <li class="admin-palette-check<?php echo $comprobacion['ok'] ? '' : ' is-low'; ?>" data-palette-check="<?php echo $h($comprobacion['id']); ?>">
                                <span class="admin-palette-check__icon" aria-hidden="true">
                                    <?php echo admin_icon($comprobacion['ok'] ? 'check' : 'alerta', 14); ?>
                                </span>
                                <span class="admin-palette-check__label"><?php echo $h($comprobacion['rotulo']); ?></span>
                                <span class="admin-palette-check__ratio admin-num" data-palette-ratio><?php echo $h($formatoRatio($comprobacion['ratio'])); ?></span>
                                <span class="admin-palette-check__state" data-palette-state><?php echo $comprobacion['ok'] ? 'Cumple' : 'Insuficiente'; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="admin-palette-checks__summary" data-palette-summary aria-live="polite"><?php echo $h($resumenContraste); ?></p>
                </section>

                <?php if ($contrasteFallido !== []) : ?>
                    <div class="admin-config-conflict admin-palette-confirm" role="alert" data-palette-confirm>
                        <p><strong>Esta paleta deja texto por debajo de AA.</strong> No se guardó todavía.</p>
                        <ul>
                            <?php foreach ($contrasteFallido as $fallo) : ?>
                                <li><?php echo $h($fallo['rotulo']); ?>: <span class="admin-num"><?php echo $h($formatoRatio($fallo['ratio'])); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <p>Si es una decisión de marca, puedes publicarla igual.</p>
                        <button type="submit" class="admin-btn admin-btn--secondary" name="confirmar_contraste" value="1">Guardar de todos modos</button>
                    </div>
                <?php endif; ?>

                <div class="admin-config-form-actions">
                    <button type="button" class="admin-btn admin-btn--ghost" data-palette-reset-all>Volver a los colores de marca</button>
                    <button type="submit" class="admin-btn admin-btn--primary">Guardar paleta</button>
                </div>
            </form>
        </section>

        <aside class="admin-panel admin-card admin-config-preview" aria-labelledby="palette-preview-title">
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="palette-preview-title">Vista previa</h2>
                    <p>Las cuatro secciones de la landing con estos colores.</p>
                </div>
            </div>

            <div class="admin-palette-preview" data-palette-preview style="<?php echo $h($estiloPrevia); ?>" aria-hidden="true">
                <?php
                $bandas = [
                    'crema' => ['eyebrow' => '01 — La nostra storia', 'titulo' => 'Casa Pestalozzi', 'cta' => 'Reservar'],
                    'beige' => ['eyebrow' => '02 — Il menù', 'titulo' => 'La carta', 'cta' => 'Ver menú'],
                    'verde' => ['eyebrow' => '03 — Le degustazioni', 'titulo' => 'Catas', 'cta' => 'Escribir'],
                    'cafe' => ['eyebrow' => '04 — Gli eventi', 'titulo' => 'Eventos', 'cta' => 'Cotizar'],
                ];
                ?>
                <?php foreach ($bandas as $tono => $banda) : ?>
                    <div class="admin-palette-preview__band admin-palette-preview__band--<?php echo $h($tono); ?>">
                        <span class="admin-palette-preview__eyebrow"><?php echo $h($banda['eyebrow']); ?></span>
                        <span class="admin-palette-preview__title"><?php echo $h($banda['titulo']); ?></span>
                        <span class="admin-palette-preview__text">Cocina mediterránea con corazón mexicano.</span>
                        <span class="admin-palette-preview__faint">Martes a domingo · 13:00 a 23:00</span>
                        <span class="admin-palette-preview__rule"></span>
                        <span class="admin-palette-preview__cta"><?php echo $h($banda['cta']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</section>
