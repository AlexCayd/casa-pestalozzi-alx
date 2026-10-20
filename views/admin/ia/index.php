<?php
/**
 * Asistente IA: conversación con los datos del restaurante.
 *
 * Responde el motor de consultas rápidas (ver AdminIaController), no un modelo
 * de lenguaje. El marcado es el armazón; las conversaciones las pinta
 * src/js/admin/ia/ia.js, que las guarda en el navegador.
 *
 * No hay texto libre: el compositor ES la pregunta armada —«Ventas de [todo el
 * restaurante ▾] · [Hoy ▾]»—, con sus variables como huecos que se eligen de
 * listas. Se cambia de pregunta desde el catálogo (conversación nueva) o desde
 * el botón de la pregunta, en la fila de abajo del compositor.
 *
 * La pantalla tiene DOS modos, y el compositor es la misma pieza en los dos:
 *
 *   - `is-vacia` (conversación nueva): saludo, compositor y catálogo, centrados
 *     en un solo bloque que se desplaza entero si no cabe.
 *   - conversación: el hilo con scroll propio arriba y el compositor fijo
 *     abajo.
 *
 * Todo lo que se ve en la primera pintura sale de aquí —saludo, la primera
 * pregunta armada y el catálogo— para que no parpadee mientras carga el JS.
 */
require_once __DIR__ . '/../partials/_icons.php';

$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

$iaApi = (string) ($iaApi ?? '/admin/api/consultas');
$iaNombre = trim((string) ($iaNombre ?? ''));
$iaSaludo = (string) ($iaSaludo ?? 'Hola');
$iaMaxConversaciones = max(1, (int) ($iaMaxConversaciones ?? 10));
$iaCatalogo = is_array($iaCatalogo ?? null) ? $iaCatalogo : [];
$iaHola = $iaSaludo . ($iaNombre !== '' ? ', ' . $iaNombre : '');

// La pregunta con la que nace el compositor: la primera del catálogo. ia.js la
// vuelve a pintar igual (o la de la conversación que retome).
$iaInicial = $iaCatalogo[0]['consultas'][0] ?? null;
?>
<section
    class="ia"
    data-ia
    data-ia-api="<?php echo $h($iaApi); ?>"
    data-ia-max-conversaciones="<?php echo $iaMaxConversaciones; ?>"
    aria-label="Asistente IA"
>
    <?php /* Se aplica ANTES de pintar el historial: si se dejó plegado, abrir
             la pantalla con la columna visible y cerrarla al cargar el JS
             sería un salto de 272 px en la primera impresión. Mismo patrón que
             el sidebar del panel en layout.php. */ ?>
    <script>
        (function (seccion) {
            try {
                if (window.localStorage.getItem('cp-admin-ia-historial-oculto') === '1') {
                    seccion.classList.add('is-historial-oculto');
                }
            } catch (error) {}
        })(document.currentScript.parentNode);
    </script>

    <aside class="ia-historial" id="ia-historial" data-ia-historial aria-labelledby="ia-historial-titulo">
        <div class="ia-historial__cabecera">
            <?php /* Rótulo y no <h2>: el <h1> de la página vive en la cabecera
                     del chat, que va DESPUÉS en el documento, y un h2 aquí
                     dejaría la jerarquía de títulos empezando por abajo. */ ?>
            <p class="ia-historial__rotulo" id="ia-historial-titulo">Conversaciones</p>
            <button
                class="ia-icono-btn ia-historial__cerrar"
                type="button"
                data-ia-historial-cerrar
                aria-label="Cerrar el historial"
            ><?php echo admin_icon('cerrar', 18); ?></button>
        </div>

        <button class="ia-nueva" type="button" data-ia-nueva>
            <?php echo admin_icon('mas', 16); ?>
            <span>Nueva conversación</span>
        </button>

        <nav class="ia-historial__lista" data-ia-lista data-scrollable aria-labelledby="ia-historial-titulo">
            <div class="ia-historial__vacio" data-ia-lista-vacia>
                <span class="ia-historial__vacio-icono" aria-hidden="true"><?php echo admin_icon('reloj', 18); ?></span>
                <p class="ia-historial__vacio-titulo">Aún no hay conversaciones</p>
                <p class="ia-historial__vacio-texto">Cada pregunta abre una y se queda aquí para retomarla otro día.</p>
            </div>
        </nav>

        <?php /* El tope se dice donde se ve la lista: al abrir una más, la más
                 antigua desaparece, y sin esta línea parecería un fallo. */ ?>
        <p class="ia-historial__pie" data-ia-almacen>Se guardan las <?php echo $iaMaxConversaciones; ?> más recientes, sólo en este navegador.</p>
    </aside>

    <?php /* Velo del cajón en tablet y móvil. No es un botón: el teclado ya
             tiene Escape y el botón de cerrar, y un segundo control invisible
             sólo añadiría una parada muda al recorrido de tabulación. */ ?>
    <div class="ia-telon" data-ia-telon aria-hidden="true"></div>

    <div class="ia-chat is-vacia" data-ia-chat>
        <header class="ia-chat__cabecera">
            <button
                class="ia-icono-btn"
                type="button"
                data-ia-historial-toggle
                aria-controls="ia-historial"
                aria-expanded="true"
                aria-label="Historial de conversaciones"
                title="Historial de conversaciones"
            ><?php echo admin_icon('panel-lateral', 20); ?></button>

            <?php /* El nombre del módulo ya lo dice la barra del panel, justo
                     encima: aquí sólo va el de la conversación. En una nueva
                     el <h1> sigue existiendo para el lector de pantalla, pero
                     no se ve: el protagonista es el saludo. */ ?>
            <div class="ia-chat__titulo">
                <h1 class="ia-chat__h1" data-ia-titulo>Nueva conversación</h1>
            </div>

            <button
                class="ia-icono-btn ia-chat__nueva"
                type="button"
                data-ia-nueva
                aria-label="Nueva conversación"
                title="Nueva conversación"
            ><?php echo admin_icon('mas', 20); ?></button>
        </header>

        <div class="ia-chat__escenario" data-ia-escenario data-scrollable>
            <div class="ia-chat__cuerpo" data-ia-cuerpo>
                <div
                    class="ia-hilo"
                    data-ia-hilo
                    data-scrollable
                    tabindex="0"
                    role="region"
                    aria-label="Mensajes de la conversación"
                >
                    <div class="ia-hilo__interior">
                        <ol class="ia-mensajes" data-ia-mensajes aria-label="Mensajes"></ol>
                    </div>
                </div>

                <?php /* Fuera del hilo para no desplazarse con él. Arranca fuera del
                         recorrido de teclado y del árbol de accesibilidad: el JS lo
                         devuelve a los dos sólo mientras está a la vista. */ ?>
                <button class="ia-ir-final" type="button" data-ia-ir-final tabindex="-1" aria-hidden="true">
                    <?php echo admin_icon('flecha-abajo', 16); ?>
                    <span>Ir al final</span>
                </button>
            </div>

            <div class="ia-saludo" data-ia-saludo>
                <p class="ia-saludo__hola">
                    <span class="ia-marca ia-marca--chica" aria-hidden="true"><?php echo admin_icon('ia', 16); ?></span>
                    <span><?php echo $h($iaHola); ?></span>
                </p>
                <h2 class="ia-saludo__titulo">¿Qué quieres saber hoy?</h2>
            </div>

            <form class="ia-compositor" data-ia-compositor novalidate aria-labelledby="ia-constructor-rotulo">
                <div class="ia-compositor__caja" data-ia-caja>
                    <?php /* El constructor de preguntas: la frase con sus
                             variables como huecos. Elegir un valor no consulta
                             nada; se consulta al enviar. Lo repinta ia.js con
                             el mismo marcado (ver pintarConstructor). */ ?>
                    <div class="ia-constructor" data-ia-constructor>
                        <p class="ia-constructor__rotulo" id="ia-constructor-rotulo">Tu pregunta</p>
                        <div
                            class="ia-constructor__frase admin-consulta__plantilla"
                            data-ia-constructor-frase
                            role="group"
                            aria-labelledby="ia-constructor-rotulo"
                            aria-describedby="ia-constructor-ayuda"
                        ><?php
                            if ($iaInicial === null) {
                                echo '<span class="ia-constructor__texto">No se pudo cargar la lista de preguntas. Recarga la página.</span>';
                            } else {
                                // El «·» va envuelto con la parte que le sigue,
                                // como en ia.js: ningún renglón termina en él.
                                $iaPar = false;
                                foreach ($iaInicial['borrador']['partes'] as $i => $parte) {
                                    $cerrarPar = $iaPar;
                                    $iaPar = false;
                                    echo $i > 0 ? ' ' : '';
                                    if (($parte['tipo'] ?? '') === 'hueco') {
                                        $vacio = ($parte['campo'] ?? '') === 'entidad' && ($parte['valor'] ?? '') === '';
                                        echo '<button type="button" class="ia-hueco admin-consulta__hueco' . ($vacio ? ' is-vacio' : '') . '"'
                                            . ' data-ia-hueco="' . (int) $i . '" data-ia-hueco-campo="' . $h($parte['campo']) . '"'
                                            . ' aria-haspopup="' . (!empty($parte['catalogo']) ? 'dialog' : 'listbox') . '" aria-expanded="false"'
                                            . ' aria-label="' . $h($parte['etiqueta'] . ', ' . mb_strtolower((string) $parte['rotulo'])) . '">'
                                            . '<span class="admin-consulta__hueco-valor">' . $h($parte['etiqueta']) . '</span>'
                                            . '<span class="admin-consulta__hueco-chevron" aria-hidden="true">' . admin_icon('chevron-abajo', 14) . '</span>'
                                            . '</button>';
                                    } elseif (($parte['texto'] ?? '') === '·') {
                                        echo '<span class="ia-constructor__par"><span class="admin-consulta__plantilla-sep" aria-hidden="true">·</span>';
                                        $iaPar = true;
                                        continue;
                                    } else {
                                        echo '<span class="ia-constructor__texto">' . $h($parte['texto'] ?? '') . '</span>';
                                    }
                                    echo $cerrarPar ? '</span>' : '';
                                }
                                echo $iaPar ? '</span>' : '';
                            }
                        ?></div>
                        <?php /* Qué contesta la pregunta armada, en una línea:
                                 «Horas pico» no dice por sí sola qué cuenta. Sólo
                                 en la conversación nueva (CSS); el lector la oye
                                 siempre como descripción del grupo. */ ?>
                        <p class="ia-constructor__ayuda" id="ia-constructor-ayuda" data-ia-constructor-ayuda><?php echo $h($iaInicial['descripcion'] ?? ''); ?></p>
                    </div>

                    <div class="ia-compositor__barra">
                        <?php /* La pregunta base: dice cuál es la armada y abre la
                                 lista para cambiarla (también Ctrl/⌘+K). */ ?>
                        <button
                            class="ia-bases"
                            type="button"
                            data-ia-bases
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-keyshortcuts="Control+K Meta+K"
                            aria-label="Pregunta: <?php echo $h($iaInicial['titulo'] ?? ''); ?>. Elegir otra"
                            <?php echo $iaInicial === null ? 'hidden' : ''; ?>
                        >
                            <span class="ia-bases__icono" data-ia-bases-icono aria-hidden="true"><?php echo admin_icon((string) ($iaInicial['icono'] ?? 'plano'), 15); ?></span>
                            <span class="ia-bases__texto" data-ia-bases-texto><?php echo $h($iaInicial['titulo'] ?? 'Preguntas'); ?></span>
                            <span class="ia-bases__chevron" aria-hidden="true"><?php echo admin_icon('chevron-abajo', 14); ?></span>
                        </button>
                        <?php /* Sin pista de teclas: el cliente la retiró de la caja.
                                 Los atajos siguen funcionando y los anuncian los
                                 aria-keyshortcuts de los botones. */ ?>
                        <button class="ia-enviar" type="submit" data-ia-enviar aria-label="Enviar pregunta" aria-keyshortcuts="Control+Enter Meta+Enter"<?php echo $iaInicial === null ? ' disabled' : ''; ?>>
                            <?php echo admin_icon('enviar', 20); ?>
                        </button>
                        <button class="ia-detener" type="button" data-ia-detener aria-label="Detener la consulta" hidden>
                            <?php echo admin_icon('detener', 18); ?>
                        </button>
                    </div>
                </div>
                <?php /* Sólo en conversación: en la vacía el catálogo de abajo ya
                         dice, con ejemplos, de qué sabe. */ ?>
                <p class="ia-compositor__nota">Responde con los datos del panel al momento: ventas, equipo e inventario.</p>
                <noscript>
                    <p class="ia-compositor__nota ia-compositor__nota--aviso">El asistente necesita JavaScript para funcionar.</p>
                </noscript>
            </form>

            <?php if ($iaCatalogo !== []) : ?>
                <?php /* El catálogo COMPLETO de lo que el motor responde
                         (ConsultasRapidas::capacidades()), por familia de
                         pregunta. Elegir una píldora la ARMA en el compositor de
                         arriba con valores reales, no consulta; la armada queda
                         marcada (aria-pressed). Cada una lleva su borrador (la
                         plantilla con los valores del ejemplo y la consulta
                         estructurada). Sin fila de periodos: el periodo ya es
                         un hueco de la pregunta, con su lista. */ ?>
                <section class="ia-catalogo" data-ia-catalogo aria-labelledby="ia-catalogo-titulo" aria-describedby="ia-catalogo-ayuda">
                    <h2 class="ia-catalogo__titulo" id="ia-catalogo-titulo">Lo que puedes preguntar</h2>
                    <p class="ia-catalogo__ayuda" id="ia-catalogo-ayuda">Elige una y se arma arriba: cambia sus valores y envíala. Cada cifra llega comparada con el periodo anterior.</p>

                    <div class="ia-catalogo__temas">
                        <?php foreach ($iaCatalogo as $indice => $grupo) : ?>
                            <?php
                            $temaId = 'ia-tema-' . (int) $indice;
                            $consultas = is_array($grupo['consultas'] ?? null) ? $grupo['consultas'] : [];
                            ?>
                            <div class="ia-tema" role="group" aria-labelledby="<?php echo $h($temaId); ?>">
                                <h3 class="ia-tema__titulo" id="<?php echo $h($temaId); ?>"><?php echo $h($grupo['tema'] ?? ''); ?></h3>
                                <ul class="ia-tema__lista">
                                    <?php foreach ($consultas as $consulta) : ?>
                                        <?php $descId = 'ia-base-' . $h($consulta['intencion']); ?>
                                        <li>
                                            <button
                                                class="ia-base"
                                                type="button"
                                                aria-pressed="<?php echo $iaInicial !== null && $consulta['intencion'] === $iaInicial['intencion'] ? 'true' : 'false'; ?>"
                                                data-ia-base="<?php echo $h($consulta['intencion']); ?>"
                                                data-ia-borrador="<?php echo $h(json_encode($consulta['borrador'], JSON_UNESCAPED_UNICODE)); ?>"
                                                data-ia-borradores="<?php echo $h(json_encode($consulta['borradores'] ?? [$consulta['borrador']], JSON_UNESCAPED_UNICODE)); ?>"
                                                data-ia-tema="<?php echo $h($grupo['tema'] ?? ''); ?>"
                                                aria-describedby="<?php echo $descId; ?>"
                                            >
                                                <span class="ia-base__icono" aria-hidden="true"><?php echo admin_icon((string) $consulta['icono'], 16); ?></span>
                                                <span class="ia-base__titulo"><?php echo $h($consulta['titulo']); ?></span>
                                            </button>
                                            <span class="admin-visually-hidden" id="<?php echo $descId; ?>"><?php echo $h($consulta['descripcion']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Las opciones del hueco de periodo del compositor, las mismas del
             motor (valor, etiqueta, escala y consulta). JSON_HEX_* porque va
             dentro de un <script>: ningún texto puede cerrarlo. */ ?>
    <script type="application/json" data-ia-periodos><?php
        echo json_encode($iaOpcionesPeriodo ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>

    <?php /* Si la conversación que se retoma es de hoy (ia.js la reabre), la
             pantalla tiene que nacer en modo conversación: los scripts del
             panel van con `defer` y, sin esto, la primera pintura enseñaba el
             saludo centrado y un instante después el compositor saltaba al pie.
             Repite a propósito la única regla de ia.js que decide el modo:
             activa guardada y tocada hoy. */ ?>
    <script>
        (function (chat) {
            try {
                var activa = window.localStorage.getItem('cp-admin-ia-activa');
                if (!activa || !chat) return;
                var datos = JSON.parse(window.localStorage.getItem('cp-admin-ia-conversaciones-v1') || 'null');
                var lista = datos && Array.isArray(datos.conversaciones) ? datos.conversaciones : [];
                var hoy = new Date();
                var inicio = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate()).getTime();
                for (var i = 0; i < lista.length; i++) {
                    var c = lista[i];
                    if (c && c.id === activa && Number(c.actualizada) >= inicio && Array.isArray(c.mensajes) && c.mensajes.length) {
                        chat.classList.remove('is-vacia');
                        return;
                    }
                }
            } catch (error) {}
        })(document.querySelector('[data-ia-chat]'));
    </script>

    <?php /* Una sola región viva y aparte de la lista: si la lista misma
             fuera `aria-live`, el lector de pantalla repetiría también la
             pregunta que se acaba de enviar y cada reconstrucción del hilo al
             cambiar de conversación. Aquí sólo entra lo que dice el asistente.

             Va FUERA de .ia-chat porque el JS vuelve inerte esa columna
             mientras el cajón del historial la tapa, y lo inerte sale del árbol
             de accesibilidad: una respuesta que llegara con el cajón abierto, o
             el «Conversación borrada» de un borrado hecho desde él, no se
             anunciarían. Es `position: absolute`, así que no ocupa celda en la
             rejilla de .ia. */ ?>
    <div class="admin-visually-hidden" aria-live="polite" aria-atomic="true" data-ia-anuncio></div>
</section>
