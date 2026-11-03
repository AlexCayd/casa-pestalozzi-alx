<?php
/**
 * Configuración → Avisos de la carta.
 *
 * Lista y editor en la misma pantalla. A la izquierda, los avisos agrupados
 * por el lugar donde se imprimen (al inicio, al final) y en su orden: la lista
 * es un mapa del PDF, no un índice alfabético. A la derecha, el editor: nuevo
 * por omisión, o el aviso de ?editar=ID.
 *
 * Todo funciona sin JS: el contenido se escribe con sus marcas (## y **), cada
 * acción de la lista es un formulario POST y la vista previa la pinta el
 * servidor con AvisosCarta::html(). avisos.js añade la barra de formato con
 * sus atajos, la vista previa en vivo, los contadores y la guarda de cambios.
 *
 * Con sólo `ver`, la lista se lee y el editor queda deshabilitado.
 */
require_once __DIR__ . '/../partials/_icons.php';

use Services\Configuration\AvisosCarta;

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$avisos = is_array($avisos ?? null) ? $avisos : [];
$formulario = is_array($formulario ?? null) ? $formulario : AvisosCarta::nuevo();
$errores = is_array($erroresAviso ?? null) ? $erroresAviso : [];
$cartas = is_array($cartasAviso ?? null) ? $cartasAviso : AvisosCarta::CARTAS;
$posiciones = is_array($posicionesAviso ?? null) ? $posicionesAviso : AvisosCarta::POSICIONES;
$plantillas = is_array($plantillasAviso ?? null) ? $plantillasAviso : AvisosCarta::PLANTILLAS;
$limites = is_array($limitesAviso ?? null) ? $limitesAviso : ['avisos' => AvisosCarta::MAX_AVISOS, 'titulo' => AvisosCarta::MAX_TITULO, 'contenido' => AvisosCarta::MAX_CONTENIDO];
$puede = !empty($puedeEditarAvisos);
$disponible = ($avisosDisponibles ?? true) !== false;
$csrf = (string) ($adminCsrfToken ?? '');

$idActual = (int) ($formulario['id'] ?? 0);
$editando = $idActual > 0;
$lleno = count($avisos) >= (int) $limites['avisos'];
// Un aviso nuevo con el tope alcanzado no se puede crear: el editor se apaga
// y lo dice, en vez de dejar escribir para rechazar al guardar.
$editorActivo = $puede && $disponible && ($editando || !$lleno);

$porLugar = array_fill_keys(array_keys($posiciones), []);
foreach ($avisos as $aviso) {
    $porLugar[$aviso['posicion']][] = $aviso;
}

$cartasTexto = static function (array $marcadas) use ($cartas): string {
    $nombres = [];
    foreach ($cartas as $clave => $carta) {
        if (!empty($marcadas[$clave])) {
            $nombres[] = $carta['titulo'];
        }
    }
    return implode(' · ', $nombres);
};

$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$titulo = (string) ($formulario['titulo'] ?? '');
$contenido = (string) ($formulario['contenido'] ?? '');
$previaHtml = AvisosCarta::html($contenido, [
    'h2' => 'admin-aviso-preview__h2',
    'p' => 'admin-aviso-preview__p',
    'b' => 'admin-aviso-preview__b',
]);
$urlEditar = static fn (int $id): string => '/admin/configuracion/avisos?editar=' . $id . '#editor';
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="avisos">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Avisos de la carta</h1>
            <p class="admin-page__subtitle">Notas del restaurante que se imprimen en las cartas PDF: alergias, formas de pago, lo que depende del mercado. Se escriben una vez aquí y salen en el Menú, en el Maridaje o en los dos.</p>
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

    <div class="admin-avisos">
        <section class="admin-panel admin-card admin-config-panel admin-avisos__lista" aria-labelledby="avisos-lista-titulo">
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="avisos-lista-titulo">En las cartas</h2>
                    <p>En el orden en que se imprimen. <span class="admin-avisos__cuenta"><?php echo count($avisos); ?> de <?php echo (int) $limites['avisos']; ?></span></p>
                </div>
                <?php if ($puede && $disponible && $editando && !$lleno) : ?>
                    <a class="admin-btn admin-btn--secondary admin-btn--small" href="/admin/configuracion/avisos#editor">
                        <?php echo admin_icon('mas', 16, 'admin-btn__icon'); ?>
                        Nuevo aviso
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($avisos === []) : ?>
                <div class="admin-avisos__vacio">
                    <span class="admin-avisos__vacio-icono" aria-hidden="true"><?php echo admin_icon('nota', 22); ?></span>
                    <strong>Todavía no hay avisos.</strong>
                    <p><?php echo $puede ? 'Escribe el primero en el editor, o empieza con uno de los ejemplos.' : 'Cuando el equipo agregue uno, aparecerá aquí.'; ?></p>
                </div>
            <?php else : ?>
                <?php foreach ($porLugar as $lugar => $lista) : ?>
                    <div class="admin-avisos__grupo">
                        <h3 class="admin-avisos__lugar">
                            <?php echo $h($posiciones[$lugar]['titulo']); ?>
                            <span><?php echo $h($posiciones[$lugar]['ayuda']); ?></span>
                        </h3>
                        <?php if ($lista === []) : ?>
                            <p class="admin-avisos__lugar-vacio">Ninguno en este lugar.</p>
                        <?php else : ?>
                            <ol class="admin-avisos__items">
                                <?php foreach ($lista as $n => $aviso) : ?>
                                    <?php
                                    $actual = $aviso['id'] === $idActual;
                                    $clases = 'admin-aviso-item' . ($actual ? ' is-actual' : '') . (!$aviso['activo'] ? ' is-pausado' : '');
                                    ?>
                                    <li class="<?php echo $clases; ?>" id="aviso-<?php echo (int) $aviso['id']; ?>">
                                        <a class="admin-aviso-item__abrir" href="<?php echo $h($urlEditar($aviso['id'])); ?>"<?php echo $actual ? ' aria-current="true"' : ''; ?>>
                                            <span class="admin-aviso-item__titulo"><?php echo $h($aviso['titulo']); ?></span>
                                            <span class="admin-aviso-item__extracto"><?php echo $h(AvisosCarta::extracto($aviso['contenido'])); ?></span>
                                        </a>
                                        <div class="admin-aviso-item__meta">
                                            <span class="admin-badge admin-badge--outline"><?php echo $h($cartasTexto($aviso['cartas'])); ?></span>
                                            <?php if (!$aviso['activo']) : ?>
                                                <span class="admin-badge admin-badge--neutral">Pausado</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($puede && $disponible) : ?>
                                            <div class="admin-aviso-item__acciones">
                                                <form method="POST" action="/admin/configuracion/avisos/estado" class="admin-aviso-item__form">
                                                    <input type="hidden" name="admin_csrf" value="<?php echo $h($csrf); ?>">
                                                    <input type="hidden" name="aviso_id" value="<?php echo (int) $aviso['id']; ?>">
                                                    <input type="hidden" name="editar" value="<?php echo $idActual ?: ''; ?>">
                                                    <input type="hidden" name="activo" value="<?php echo $aviso['activo'] ? '0' : '1'; ?>">
                                                    <?php /* Un botón de envío con forma de interruptor: sin JS
                                                             también cambia el estado, y el lector anuncia
                                                             «Se imprime, interruptor, activado». */ ?>
                                                    <button type="submit" class="admin-aviso-switch" role="switch" aria-checked="<?php echo $aviso['activo'] ? 'true' : 'false'; ?>"
                                                            aria-label="Se imprime «<?php echo $h($aviso['titulo']); ?>»">
                                                        <span class="admin-aviso-switch__track" aria-hidden="true"><span class="admin-aviso-switch__thumb"></span></span>
                                                        <span class="admin-aviso-switch__texto" aria-hidden="true"><?php echo $aviso['activo'] ? 'Se imprime' : 'Pausado'; ?></span>
                                                    </button>
                                                </form>
                                                <span class="admin-aviso-item__orden">
                                                    <?php foreach (['subir' => ['sube', 'Subir'], 'bajar' => ['baja', 'Bajar']] as $direccion => [$icono, $verbo]) : ?>
                                                        <?php $tope = $direccion === 'subir' ? $n === 0 : $n === count($lista) - 1; ?>
                                                        <form method="POST" action="/admin/configuracion/avisos/mover" class="admin-aviso-item__form">
                                                            <input type="hidden" name="admin_csrf" value="<?php echo $h($csrf); ?>">
                                                            <input type="hidden" name="aviso_id" value="<?php echo (int) $aviso['id']; ?>">
                                                            <input type="hidden" name="editar" value="<?php echo $idActual ?: ''; ?>">
                                                            <input type="hidden" name="direccion" value="<?php echo $direccion; ?>">
                                                            <button type="submit" class="admin-icon-button admin-aviso-item__mover" <?php echo $tope ? 'disabled' : ''; ?>
                                                                    aria-label="<?php echo $h($verbo . ' «' . $aviso['titulo'] . '»'); ?>" title="<?php echo $h($verbo); ?>">
                                                                <?php echo admin_icon($icono, 17); ?>
                                                            </button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                </span>
                                                <form method="POST" action="/admin/configuracion/avisos/eliminar" class="admin-aviso-item__form"
                                                      data-confirm-delete
                                                      data-confirm-eyebrow="Avisos de la carta"
                                                      data-confirm-title="¿Eliminar «<?php echo $h($aviso['titulo']); ?>»?"
                                                      data-confirm-description="Dejará de imprimirse en <?php echo $h(mb_strtolower($cartasTexto($aviso['cartas']))); ?>."
                                                      data-confirm-consequence="El texto no se puede recuperar. Si sólo quieres quitarlo un tiempo, páusalo."
                                                      data-confirm-primary="Eliminar aviso">
                                                    <input type="hidden" name="admin_csrf" value="<?php echo $h($csrf); ?>">
                                                    <input type="hidden" name="aviso_id" value="<?php echo (int) $aviso['id']; ?>">
                                                    <input type="hidden" name="editar" value="<?php echo $idActual ?: ''; ?>">
                                                    <button type="submit" class="admin-icon-button admin-icon-button--danger" aria-label="<?php echo $h('Eliminar «' . $aviso['titulo'] . '»'); ?>" title="Eliminar">
                                                        <?php echo admin_icon('basura', 17); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <p class="admin-avisos__ver">
                Ver las cartas impresas:
                <a href="/menu/pdf" target="_blank" rel="noopener">Menú<?php echo admin_icon('externo', 13); ?><span class="admin-sr-only"> (PDF, se abre en otra pestaña)</span></a>
                <a href="/maridaje/pdf" target="_blank" rel="noopener">Maridaje<?php echo admin_icon('externo', 13); ?><span class="admin-sr-only"> (PDF, se abre en otra pestaña)</span></a>
            </p>
        </section>

        <section class="admin-panel admin-card admin-config-panel admin-avisos__editor" id="editor" aria-labelledby="aviso-editor-titulo">
            <?php
            // Solo consulta: el editor es un lector. Sin un aviso abierto no
            // hay nada que leer, así que en vez de un formulario vacío y
            // apagado se dice qué hacer.
            if (!$puede) {
                $editorTitulo = 'Consultar aviso';
                $editorBajada = $editando
                    ? 'Así está escrito y así sale en la carta.'
                    : ($avisos === [] ? 'Cuando el equipo agregue un aviso, podrás leerlo aquí.' : 'Elige un aviso de la lista para leerlo completo y ver cómo sale en la carta.');
            } else {
                $editorTitulo = $editando ? 'Editar aviso' : 'Nuevo aviso';
                $editorBajada = $editando ? 'Los cambios salen en la carta desde la próxima vez que se abre el PDF.' : 'Título, contenido y en qué carta se imprime.';
            }
            ?>
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="aviso-editor-titulo"><?php echo $editorTitulo; ?></h2>
                    <p><?php echo $editorBajada; ?></p>
                </div>
                <?php if ($editorActivo) : ?>
                    <p class="admin-form-status admin-avisos__estado" data-aviso-estado role="status" aria-live="polite"><?php echo !empty($avisoConCambios) ? 'Sin guardar' : ''; ?></p>
                <?php endif; ?>
            </div>

            <?php if (!$puede) : ?>
                <?php /* Arriba y no al pie: explica por qué los campos están
                         apagados antes de que alguien intente escribir. */ ?>
                <p class="admin-avisos__solo-lectura">Solo consulta: necesitas permiso de edición en Configuración para cambiar los avisos.</p>
            <?php endif; ?>

            <?php if (!$editando && $puede && $disponible && $lleno) : ?>
                <p class="admin-avisos__tope">Ya hay <?php echo (int) $limites['avisos']; ?> avisos, el máximo. Para agregar otro, elimina uno o reutilízalo: ábrelo desde la lista y cambia su texto.</p>
            <?php elseif (!$editando && $editorActivo) : ?>
                <div class="admin-avisos__plantillas">
                    <span class="admin-avisos__plantillas-rotulo">Empezar con un ejemplo</span>
                    <?php foreach ($plantillas as $clave => $plantilla) : ?>
                        <a class="admin-avisos__plantilla" href="/admin/configuracion/avisos?plantilla=<?php echo $h($clave); ?>#editor" data-aviso-plantilla><?php echo $h($plantilla['nombre']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($puede || $editando) : ?>
            <form class="admin-aviso-form" method="POST" action="/admin/configuracion/avisos" data-aviso-form
                  data-limite-titulo="<?php echo (int) $limites['titulo']; ?>" data-limite-contenido="<?php echo (int) $limites['contenido']; ?>"
                  <?php echo !empty($avisoConCambios) ? 'data-aviso-sin-guardar' : ''; ?>>
                <fieldset class="admin-aviso-form__campos" <?php echo $editorActivo ? '' : 'disabled'; ?>>
                    <legend class="admin-sr-only"><?php echo $editorTitulo; ?></legend>
                    <input type="hidden" name="admin_csrf" value="<?php echo $h($csrf); ?>">
                    <?php if ($editando) : ?>
                        <input type="hidden" name="aviso_id" value="<?php echo $idActual; ?>">
                    <?php endif; ?>

                    <?php /* Una sola rejilla y la vista previa justo después del
                             contenido: apilado (móvil, portátil con el sidebar
                             abierto) queda bajo el texto que se está escribiendo,
                             no al fondo del formulario; con el ancho de dos
                             columnas pasa a la derecha y acompaña al escribir. */ ?>
                    <div class="admin-aviso-form__rejilla">
                        <div class="admin-field<?php echo $error('titulo') !== '' ? ' has-error' : ''; ?>">
                            <label class="admin-field__label admin-field__label--split" for="aviso-titulo">
                                <span>Título</span>
                                <span class="admin-aviso-form__contador" data-contador="aviso-titulo" aria-hidden="true"><?php echo mb_strlen($titulo); ?> / <?php echo (int) $limites['titulo']; ?></span>
                            </label>
                            <input type="text" id="aviso-titulo" name="titulo" maxlength="<?php echo (int) $limites['titulo']; ?>" required
                                   value="<?php echo $h($titulo); ?>" placeholder="Por ejemplo: Alergias e intolerancias" autocomplete="off"
                                   aria-describedby="aviso-titulo-error"<?php echo $error('titulo') !== '' ? ' aria-invalid="true"' : ''; ?> data-aviso-titulo>
                            <span class="admin-field__error" id="aviso-titulo-error" aria-live="polite"><?php echo $h($error('titulo')); ?></span>
                        </div>

                        <div class="admin-field<?php echo $error('contenido') !== '' ? ' has-error' : ''; ?>">
                            <label class="admin-field__label admin-field__label--split" for="aviso-contenido">
                                <span>Contenido</span>
                                <span class="admin-aviso-form__contador" data-contador="aviso-contenido" aria-hidden="true"><?php echo mb_strlen($contenido); ?> / <?php echo (int) $limites['contenido']; ?></span>
                            </label>
                            <div class="admin-aviso-form__escritorio">
                                <?php /* La barra nace oculta: sin JS sus botones no harían
                                         nada, y la ayuda de abajo ya explica las marcas.
                                         Es un toolbar ARIA: una sola parada de Tab
                                         (tabindex itinerante, flechas entre botones) y
                                         cada botón con aria-pressed, que avisos.js
                                         mantiene con el formato que hay bajo el cursor. */ ?>
                                <div class="admin-aviso-form__barra" role="toolbar" aria-label="Formato del contenido" aria-controls="aviso-contenido" data-aviso-barra hidden>
                                    <button type="button" class="admin-aviso-form__formato" data-formato="h2" aria-pressed="false" tabindex="0" aria-keyshortcuts="Control+Shift+2 Meta+Shift+2" title="Subtítulo (Ctrl + Mayús + 2)" data-atajo="Ctrl + Mayús + 2">
                                        <span class="admin-aviso-form__formato-glifo" aria-hidden="true">H2</span>
                                        <span>Subtítulo</span>
                                    </button>
                                    <button type="button" class="admin-aviso-form__formato" data-formato="negrita" aria-pressed="false" tabindex="-1" aria-keyshortcuts="Control+B Meta+B" title="Negritas (Ctrl + B)" data-atajo="Ctrl + B">
                                        <span class="admin-aviso-form__formato-glifo admin-aviso-form__formato-glifo--b" aria-hidden="true">B</span>
                                        <span>Negritas</span>
                                    </button>
                                    <span class="admin-aviso-form__atajos" aria-hidden="true"><kbd data-tecla-mod>Ctrl</kbd> + <kbd>Enter</kbd> guarda</span>
                                </div>
                                <textarea id="aviso-contenido" name="contenido" rows="9" maxlength="<?php echo (int) $limites['contenido']; ?>" required
                                          placeholder="Escribe el aviso. Un renglón en blanco empieza otro párrafo."
                                          aria-describedby="aviso-contenido-ayuda aviso-contenido-error"<?php echo $error('contenido') !== '' ? ' aria-invalid="true"' : ''; ?> data-aviso-contenido><?php echo $h($contenido); ?></textarea>
                            </div>
                            <p class="admin-field__hint admin-aviso-form__ayuda" id="aviso-contenido-ayuda">
                                <code>##</code> al inicio de un renglón lo vuelve subtítulo · <code>**texto**</code> va en negritas · un renglón en blanco empieza otro párrafo.
                            </p>
                            <span class="admin-field__error" id="aviso-contenido-error" aria-live="polite"><?php echo $h($error('contenido')); ?></span>
                        </div>

                        <?php /* La vista previa: la misma jerarquía del bloque del PDF
                                 (rótulo, título, filete corto con rombo, cuerpo), con
                                 la tinta del panel: sin serif ni color de marca. El
                                 PDF real se abre desde la lista. */ ?>
                        <aside class="admin-aviso-preview" aria-labelledby="aviso-preview-rotulo">
                            <p class="admin-aviso-preview__panel" id="aviso-preview-rotulo">Así se verá en la carta</p>
                            <div class="admin-aviso-preview__hoja<?php echo empty($formulario['activo']) ? ' is-pausado' : ''; ?>" data-aviso-preview>
                                <p class="admin-aviso-preview__eyebrow">Aviso</p>
                                <p class="admin-aviso-preview__titulo<?php echo $titulo === '' ? ' is-vacio' : ''; ?>" data-aviso-preview-titulo><?php echo $titulo !== '' ? $h($titulo) : 'Título del aviso'; ?></p>
                                <span class="admin-aviso-preview__filete" aria-hidden="true"><span></span></span>
                                <div class="admin-aviso-preview__cuerpo<?php echo $previaHtml === '' ? ' is-vacio' : ''; ?>" data-aviso-preview-cuerpo>
                                    <?php echo $previaHtml !== '' ? $previaHtml : '<p class="admin-aviso-preview__p">El contenido aparece aquí mientras escribes.</p>'; ?>
                                </div>
                            </div>
                            <p class="admin-aviso-preview__donde" data-aviso-preview-donde>
                                <?php
                                $donde = $cartasTexto($formulario['cartas'] ?? []);
                                echo $h(($donde !== '' ? $donde : 'Ninguna carta') . ' · ' . mb_strtolower($posiciones[$formulario['posicion'] ?? 'inicio']['titulo'] ?? 'Al inicio'))
                                    . (empty($formulario['activo']) ? ' · <strong>pausado</strong>' : '');
                                ?>
                            </p>
                        </aside>

                        <fieldset class="admin-field admin-pills admin-pills--columnas admin-aviso-form__grupo<?php echo $error('cartas') !== '' ? ' has-error' : ''; ?>" aria-describedby="aviso-cartas-error" data-aviso-cartas>
                            <legend class="admin-field__label">Se imprime en</legend>
                            <div class="admin-pills__group">
                                <?php foreach ($cartas as $clave => $carta) : ?>
                                    <label class="admin-pill admin-pill--casilla">
                                        <input type="checkbox" name="cartas[]" value="<?php echo $h($clave); ?>" <?php echo !empty($formulario['cartas'][$clave]) ? 'checked' : ''; ?>>
                                        <span class="admin-pill__body">
                                            <span class="admin-pill__title"><?php echo $h($carta['titulo']); ?></span>
                                            <span class="admin-pill__hint"><?php echo $h($carta['ayuda']); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <span class="admin-field__error" id="aviso-cartas-error" aria-live="polite"><?php echo $h($error('cartas')); ?></span>
                        </fieldset>

                        <fieldset class="admin-field admin-pills admin-pills--columnas admin-aviso-form__grupo<?php echo $error('posicion') !== '' ? ' has-error' : ''; ?>">
                            <legend class="admin-field__label">Lugar en la carta</legend>
                            <div class="admin-pills__group">
                                <?php foreach ($posiciones as $clave => $posicion) : ?>
                                    <label class="admin-pill">
                                        <input type="radio" name="posicion" value="<?php echo $h($clave); ?>" required <?php echo ($formulario['posicion'] ?? 'inicio') === $clave ? 'checked' : ''; ?>>
                                        <span class="admin-pill__body">
                                            <span class="admin-pill__title"><?php echo $h($posicion['titulo']); ?></span>
                                            <span class="admin-pill__hint"><?php echo $h($posicion['ayuda']); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <span class="admin-field__error" aria-live="polite"><?php echo $h($error('posicion')); ?></span>
                        </fieldset>

                        <label class="admin-switch admin-aviso-form__activo">
                            <input type="checkbox" name="activo" value="1" <?php echo !empty($formulario['activo']) ? 'checked' : ''; ?> data-aviso-activo>
                            <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                            <span class="admin-switch__label">Se imprime en la carta</span>
                        </label>
                    </div>
                </fieldset>

                <?php if ($editorActivo) : ?>
                    <div class="admin-config-form-actions">
                        <button type="submit" class="admin-btn admin-btn--primary" data-aviso-guardar>
                            <?php echo admin_icon('check', 16, 'admin-btn__icon'); ?>
                            <?php echo $editando ? 'Guardar cambios' : 'Crear aviso'; ?>
                        </button>
                        <a class="admin-btn admin-btn--ghost" href="<?php echo $editando ? $h($urlEditar($idActual)) : '/admin/configuracion/avisos#editor'; ?>" data-aviso-descartar>Descartar cambios</a>
                    </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </section>
    </div>
</section>
