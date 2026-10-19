<?php
/**
 * Asistente IA: conversación con los datos del restaurante.
 *
 * El marcado es el armazón; las conversaciones las pinta src/js/admin/ia/ia.js,
 * que las guarda en el navegador. Lo que sí sale de aquí es todo lo que tiene
 * que verse en la primera pintura —el saludo y las sugerencias del estado
 * vacío— para que la pantalla no parpadee mientras carga el JS.
 *
 * `data-ia-endpoint` es la URL del flujo de n8n, o vacío. Vacío significa que
 * el front NO hace red y lo dice: aviso en la cabecera y, al preguntar, un
 * mensaje de estado en lugar de una respuesta inventada.
 */
require_once __DIR__ . '/../partials/_icons.php';

$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

$iaEndpoint = (string) ($iaEndpoint ?? '');
$iaConectado = $iaEndpoint !== '';
$iaNombre = trim((string) ($iaNombre ?? ''));
$iaSaludo = (string) ($iaSaludo ?? 'Hola');
$iaMaxCaracteres = max(1, (int) ($iaMaxCaracteres ?? 4000));
$iaSugerencias = is_array($iaSugerencias ?? null) ? $iaSugerencias : [];
$iaTituloSaludo = $iaSaludo . ($iaNombre !== '' ? ', ' . $iaNombre : '') . '.';
?>
<section
    class="ia"
    data-ia
    data-ia-endpoint="<?php echo $h($iaEndpoint); ?>"
    data-ia-max="<?php echo $iaMaxCaracteres; ?>"
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
            <p class="ia-historial__vacio" data-ia-lista-vacia>Aún no hay conversaciones. La primera pregunta abre una.</p>
        </nav>

        <p class="ia-historial__pie" data-ia-almacen>Se guardan sólo en este navegador.</p>
    </aside>

    <?php /* Velo del cajón en tablet y móvil. No es un botón: el teclado ya
             tiene Escape y el botón de cerrar, y un segundo control invisible
             sólo añadiría una parada muda al recorrido de tabulación. */ ?>
    <div class="ia-telon" data-ia-telon aria-hidden="true"></div>

    <div class="ia-chat">
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

            <div class="ia-chat__titulo">
                <p class="admin-eyebrow">Asistente IA</p>
                <h1 class="ia-chat__h1" data-ia-titulo>Nueva conversación</h1>
            </div>

            <?php if (!$iaConectado) : ?>
                <?php /* La integración con n8n está pendiente y la pantalla lo
                         dice donde se ve, no en una nota al pie: una vista que
                         parece funcionar y no responde es peor que una que
                         avisa. En móvil se queda la palabra corta. */ ?>
                <p class="ia-estado" title="Vista previa · El asistente aún no está conectado">
                    <span class="ia-estado__punto" aria-hidden="true"></span>
                    <span>Vista previa</span><span class="ia-estado__largo"> · El asistente aún no está conectado</span>
                </p>
            <?php endif; ?>

            <button
                class="ia-icono-btn ia-chat__nueva"
                type="button"
                data-ia-nueva
                aria-label="Nueva conversación"
                title="Nueva conversación"
            ><?php echo admin_icon('mas', 20); ?></button>
        </header>

        <div class="ia-chat__cuerpo">
            <div
                class="ia-hilo"
                data-ia-hilo
                data-scrollable
                tabindex="0"
                role="region"
                aria-label="Mensajes de la conversación"
            >
                <div class="ia-hilo__interior">
                    <div class="ia-vacio" data-ia-vacio>
                        <div class="ia-vacio__saludo">
                            <span class="ia-marca ia-marca--grande" aria-hidden="true"><?php echo admin_icon('ia', 24); ?></span>
                            <h2 class="ia-vacio__titulo"><?php echo $h($iaTituloSaludo); ?></h2>
                            <p class="ia-vacio__bajada">
                                Pregunta con tus palabras por las ventas, el inventario, las reservaciones o el menú.
                                <?php if (!$iaConectado) : ?>
                                    Por ahora es una vista previa: todavía no consulta los datos.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="ia-sugerencias">
                            <?php foreach ($iaSugerencias as $indice => $grupo) : ?>
                                <?php
                                $temaId = 'ia-tema-' . (int) $indice;
                                $preguntas = is_array($grupo['preguntas'] ?? null) ? $grupo['preguntas'] : [];
                                ?>
                                <section class="ia-tema" aria-labelledby="<?php echo $h($temaId); ?>">
                                    <h3 class="ia-tema__titulo" id="<?php echo $h($temaId); ?>">
                                        <?php echo admin_icon((string) ($grupo['icono'] ?? ''), 14); ?>
                                        <span><?php echo $h($grupo['tema'] ?? ''); ?></span>
                                    </h3>
                                    <ul class="ia-tema__lista">
                                        <?php foreach ($preguntas as $pregunta) : ?>
                                            <li>
                                                <button
                                                    class="ia-sugerencia"
                                                    type="button"
                                                    data-ia-sugerencia="<?php echo $h($pregunta); ?>"
                                                >
                                                    <span><?php echo $h($pregunta); ?></span>
                                                    <?php echo admin_icon('enviar', 16, 'ia-sugerencia__flecha'); ?>
                                                </button>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <ol class="ia-mensajes" data-ia-mensajes aria-label="Mensajes" hidden></ol>
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

        <form class="ia-compositor" data-ia-compositor novalidate>
            <div class="ia-compositor__caja">
                <label class="admin-visually-hidden" for="ia-campo">Escribe tu pregunta para el asistente</label>
                <textarea
                    id="ia-campo"
                    class="ia-compositor__campo"
                    data-ia-campo
                    data-scrollable
                    rows="1"
                    maxlength="<?php echo $iaMaxCaracteres; ?>"
                    placeholder="Pregunta sobre ventas, inventario, reservaciones o el menú…"
                    aria-describedby="ia-pista"
                    aria-keyshortcuts="/ Control+K Meta+K"
                    autocomplete="off"
                    enterkeyhint="enter"
                ></textarea>
                <?php /* `enter` y no `send`: la tecla sólo se ve en el teclado de
                         pantalla, y ahí Enter hace salto de línea (ia.js). Con
                         `send` el teclado rotulaba «Enviar» una tecla que no
                         envía. */ ?>

                <div class="ia-compositor__barra">
                    <p class="ia-compositor__pista" id="ia-pista" data-ia-pista>
                        <kbd>Enter</kbd> envía · <kbd>Shift</kbd> + <kbd>Enter</kbd>, nueva línea
                    </p>
                    <span class="ia-compositor__contador" data-ia-contador hidden></span>
                    <button class="ia-enviar" type="submit" data-ia-enviar aria-label="Enviar pregunta" disabled>
                        <?php echo admin_icon('enviar', 20); ?>
                    </button>
                    <button class="ia-detener" type="button" data-ia-detener aria-label="Detener la respuesta" hidden>
                        <?php echo admin_icon('detener', 18); ?>
                    </button>
                </div>
            </div>
            <?php /* Mientras no hay conexión la frase de siempre sería falsa: no
                     sale nada de ningún dato. */ ?>
            <p class="ia-compositor__nota">
                <?php if ($iaConectado) : ?>
                    Las respuestas salen de los datos del restaurante. Verifica las cifras importantes antes de decidir.
                <?php else : ?>
                    Vista previa: las preguntas se guardan, pero todavía no se consultan los datos.
                <?php endif; ?>
            </p>
            <noscript>
                <p class="ia-compositor__nota ia-compositor__nota--aviso">El asistente necesita JavaScript para funcionar.</p>
            </noscript>
        </form>
    </div>

    <?php /* Una sola región viva y aparte de la lista: si la lista misma
             fuera `aria-live`, el lector de pantalla repetiría también la
             pregunta que se acaba de escribir y cada reconstrucción del hilo al
             cambiar de conversación. Aquí sólo entra lo que dice el asistente.

             Va FUERA de .ia-chat porque el JS vuelve inerte esa columna
             mientras el cajón del historial la tapa, y lo inerte sale del árbol
             de accesibilidad: una respuesta que llegara con el cajón abierto, o
             el «Conversación borrada» de un borrado hecho desde él, no se
             anunciarían. Es `position: absolute`, así que no ocupa celda en la
             rejilla de .ia. */ ?>
    <div class="admin-visually-hidden" aria-live="polite" aria-atomic="true" data-ia-anuncio></div>
</section>
