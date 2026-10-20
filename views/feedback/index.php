<?php
/**
 * Encuesta de salida del comensal.
 *
 * Es la ÚNICA pantalla de tablet que pertenece a la marca del restaurante y no
 * al sistema administrativo: la abre un cliente al terminar de comer (el POS la
 * lanza en otra pestaña al cerrar la cuenta y el mesero le pasa la tablet). Por
 * eso conserva la paleta de la landing —crema, verde y oro, `data-tono="crema"`—
 * y sigue dentro de `app.css`. Lo que comparte con el sistema administrativo es
 * la VOZ y los PRINCIPIOS: Satoshi para el texto, Expose para el titular,
 * rótulos en versalitas, botones de relleno sólido y estados explícitos de
 * carga, éxito y error (ver _feedback.scss).
 *
 * Funciona sin JavaScript. Con JS (la clase `fb-js` que pone el guardia de
 * abajo antes del primer pintado) va de pregunta en pregunta y envía por
 * fetch; sin él —JS apagado, o el bundle que no llega o revienta— las cuatro
 * preguntas y el comentario se ven seguidos y el formulario se envía solo a
 * /api/feedback, que en ese caso redirige de vuelta aquí
 * (FeedbackController::guardar). Antes, sin JS, la tarjeta quedaba vacía.
 *
 * Las caras son radios DE VERDAD dentro de un `role="radiogroup"`: flechas
 * dentro del grupo, `required` nativo y el valor en el envío sin una línea de
 * JS. Se dibujan UNA vez, aquí; el resumen del paso 6 las clona. El color de
 * cada cara no está escrito en ningún sitio de este archivo: el SVG va en
 * `currentColor` y la paleta funcional la pone el CSS.
 */

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

/**
 * Una escala de cinco caras para un campo de la encuesta.
 *
 * Line-art en la línea de la casa (trazo redondo, sin rellenos en reposo). Cada
 * rasgo va en su propio elemento con clase porque el CSS los anima por
 * separado: el rostro se inclina, los ojos parpadean y la boca se acentúa
 * —sonríe más, o se tuerce más— al apuntar y al elegir. Las caras 4 y 5 traen
 * además unos ojos «felices» (dos arcos) que sustituyen a los redondos cuando
 * se eligen: es el cambio de expresión, y por eso sólo existe en las que lo
 * tienen.
 */
function renderEscala(string $campo): string {
    $etiquetas = ['Muy malo', 'Malo', 'Regular', 'Bueno', 'Excelente'];

    $ojos = static fn (string $extra = ''): string =>
        '<g class="fb-cara__ojos' . $extra . '">'
        . '<ellipse class="fb-cara__ojo" cx="23.5" cy="27" rx="2.5" ry="3.1"/>'
        . '<ellipse class="fb-cara__ojo" cx="40.5" cy="27" rx="2.5" ry="3.1"/>'
        . '</g>';
    $ojosFelices = '<g class="fb-cara__ojos-felices">'
        . '<path d="M20.5 28.5 Q23.5 24 26.5 28.5"/><path d="M37.5 28.5 Q40.5 24 43.5 28.5"/>'
        . '</g>';

    // La boca es lo que más cambia entre caras; las cejas sólo aparecen en los
    // extremos, que es lo que los hace legibles de un vistazo a distancia de
    // brazo. En «Muy malo» son de pena (el extremo interior alzado), no de
    // enfado: la cara le pone gesto a lo que siente el comensal, no lo acusa.
    $rasgos = [
        '<g class="fb-cara__cejas"><path d="M18 22.5 L27 19.5"/><path d="M46 22.5 L37 19.5"/></g>'
            . $ojos() . '<path class="fb-cara__boca" d="M22 46.5 Q32 37 42 46.5"/>',
        $ojos() . '<path class="fb-cara__boca" d="M23.5 45 Q32 40.5 40.5 45"/>',
        $ojos() . '<path class="fb-cara__boca" d="M23.5 43 L40.5 43"/>',
        $ojos(' fb-cara__ojos--cambian') . $ojosFelices
            . '<path class="fb-cara__boca" d="M23 40 Q32 48.5 41 40"/>',
        '<g class="fb-cara__cejas"><path d="M19 20 Q23.5 16.8 28 20"/><path d="M36 20 Q40.5 16.8 45 20"/></g>'
            . $ojos(' fb-cara__ojos--cambian') . $ojosFelices
            . '<path class="fb-cara__boca" d="M21.5 38 Q32 54 42.5 38 Z"/>',
    ];

    $campoEsc = htmlspecialchars($campo, ENT_QUOTES, 'UTF-8');
    $html = '';

    foreach ($etiquetas as $i => $etiqueta) {
        $valor = $i + 1;
        $etiquetaEsc = htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8');
        // El <label> envuelve al radio: el nombre accesible es la etiqueta, el
        // toque en cualquier punto de la cara lo marca y no hace falta un id
        // (el resumen clona este marcado, y un id clonado sería un duplicado).
        $html .= '<label class="fb-face fb-face--' . $valor . '" style="--i:' . $i . '">'
              . '<input class="fb-face__input" type="radio" name="' . $campoEsc . '" value="' . $valor . '" required>'
              . '<span class="fb-face__disc" aria-hidden="true">'
              . '<svg class="fb-cara" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.6"'
              . ' stroke-linecap="round" stroke-linejoin="round" focusable="false">'
              . '<circle class="fb-cara__aro" cx="32" cy="32" r="29"/>'
              . '<g class="fb-cara__rostro">' . $rasgos[$i] . '</g>'
              . '</svg></span>'
              . '<span class="fb-face__label">' . $etiquetaEsc . '</span>'
              . '</label>';
    }

    return $html;
}

/**
 * La tarjeta final. Se pinta en tres casos —reseña ya enviada antes, envío sin
 * JS que vuelve aquí, y oculta para que el JS la muestre al terminar— y los
 * tres tienen que decir lo mismo con la misma forma.
 */
function renderFin(string $mensaje, bool $oculta): string {
    return '<section class="fb-card fb-fin" id="fb-success" tabindex="-1" aria-labelledby="fb-fin-titulo"'
        . ($oculta ? ' hidden' : '') . '>'
        . '<span class="fb-fin__sello" aria-hidden="true">'
        . '<svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2.4"'
        . ' stroke-linecap="round" stroke-linejoin="round" focusable="false">'
        . '<circle class="fb-fin__aro" cx="28" cy="28" r="25" pathLength="1"/>'
        . '<path class="fb-fin__check" d="M18 28.5 L25 35.5 L38.5 21.5" pathLength="1"/>'
        . '</svg></span>'
        . '<h1 class="fb-fin__titulo" id="fb-fin-titulo" lang="it">Grazie mille</h1>'
        . '<p class="fb-fin__texto">' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p>'
        // El POS abre la encuesta en otra pestaña de SU tablet: el final útil
        // es devolverla, no un enlace a la portada que dejaría la tablet del
        // mesero en la landing.
        . '<p class="fb-fin__nota">'
        . '<svg class="fb-fin__nota-icono" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<rect x="4" y="1.75" width="12" height="16.5" rx="2.2"/><path d="M8.5 15h3"/></svg>'
        . '<span>Ya puedes devolver la tablet a tu mesero.</span></p>'
        . '</section>';
}

$yaRespondio = isset($yaRespondio) && $yaRespondio;
$enviada     = isset($enviada) && $enviada;
$errorEnvio  = isset($errorEnvio) && $errorEnvio;
$tokenValue  = isset($token) && $token ? $h($token) : '';

$mensajeGracias = 'Tu opinión ya está con nosotros. Fue un placer atenderte.';

// Los cuatro ejes de la encuesta: campo, rótulo italiano (la voz de la casa),
// la PREGUNTA que se le hace al comensal y el nombre corto del eje, que es lo
// que se lee en el resumen. Antes el titular era el nombre del eje («Calidad y
// sabor»): decía qué se medía, no qué se preguntaba.
$pasos = [
    ['calidad_sabor',      'Il sapore',    '¿Cómo estuvo la comida?',          'Calidad y sabor'],
    ['atencion_mesero',    'Il servizio',  '¿Cómo te atendimos?',              'Atención del personal'],
    ['tiempo_espera',      'Il tempo',     '¿Qué tal los tiempos de espera?',  'Tiempo de espera'],
    ['experiencia_global', "L'esperienza", 'En general, ¿cómo fue tu visita?', 'Experiencia global'],
];
$totalPasos = count($pasos) + 2; // + comentario + resumen

$iconoSigue = '<svg class="fb-btn__icono fb-btn__icono--sigue" viewBox="0 0 18 12" fill="none" stroke="currentColor"'
    . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M1 6h15"/><path d="M11 1l5 5-5 5"/></svg>';
$iconoVuelve = '<svg class="fb-btn__icono fb-btn__icono--vuelve" viewBox="0 0 18 12" fill="none" stroke="currentColor"'
    . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M17 6H2"/><path d="M7 1L2 6l5 5"/></svg>';
$iconoEnvia = '<svg class="fb-btn__icono fb-btn__icono--envia" viewBox="0 0 18 14" fill="none" stroke="currentColor"'
    . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M1.5 7.5l5 5L16.5 1.5"/></svg>';
$iconoAlerta = '<svg class="fb-alerta__icono" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
    . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<circle cx="10" cy="10" r="8.25"/><path d="M10 5.75v5"/><path d="M10 14.2v.05"/></svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Tu opinión · Casa Pestalozzi</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
  <link rel="apple-touch-icon" href="/build/images/logo.svg">
  <?php /* Guardia anti-FOUC, la única pieza de JS que vive en la vista: tiene
           que correr ANTES del primer pintado. Con `fb-js` la encuesta se
           muestra de pregunta en pregunta; sin ella, seguida. Si al terminar
           de cargar la página feedback.js no ha dicho `fb-listo` —el bundle
           no llegó o reventó al evaluarse— la clase se retira y el formulario
           vuelve a ser uno normal que se envía solo, en vez de quedarse
           atascado en la primera pregunta con unos botones que no responden.
           `load` llega siempre DESPUÉS de los scripts `defer`, así que no hay
           carrera con un bundle lento. */ ?>
  <script>
    (function (raiz) {
      raiz.className += ' fb-js';
      window.addEventListener('load', function () {
        if (!/(^|\s)fb-listo(\s|$)/.test(raiz.className)) {
          raiz.className = raiz.className.replace(/(^|\s)fb-js(?=\s|$)/g, '');
        }
      });
    })(document.documentElement);
  </script>
  <?php /* Las dos caras del sistema administrativo, que son las que habla esta
           pantalla: Satoshi (texto) y Expose (el titular de cada pregunta). Las
           @font-face viven en _feedback.scss y ninguna otra página de app.css las
           nombra, así que la landing no las descarga. El isologo es trazo, no
           fuente: KudosKaps ya no se precarga aquí, ni The Seasons. */ ?>
  <?php echo precargaFuente('/build/fonts/satoshi-variable.woff2'); ?>
  <?php echo precargaFuente('/build/fonts/expose-variable.woff2'); ?>
  <link rel="stylesheet" href="<?php echo s(recursoVersionado('/build/css/app.css')); ?>">
  <?php /* Paleta editable desde Configuración: va DESPUÉS de app.css para ganarle por orden. */ echo \Services\Configuration\PaletaLanding::estiloEnLinea(); ?>
</head>
<?php /* Sin data-modo="oscuro": esta pantalla es de la marca, no del piso. */ ?>
<body class="fb-page" data-tono="crema" data-page="feedback">

  <?php /* <main> es el contenedor y no la tarjeta: así existe también en los
           dos estados finales, que no pintan la encuesta. */ ?>
  <main class="fb-shell">

    <header class="fb-cabecera">
      <?php include __DIR__ . '/_isologo.php'; ?>
    </header>

    <?php if ($enviada) : ?>

      <?php /* Vuelta del envío sin JavaScript (FeedbackController::guardar).
               Va ANTES que $yaRespondio: con token, la reseña recién guardada ya
               marcó el token como usado, y el comensal que acaba de enviarla
               leería «ya se había enviado». */ ?>
      <?php echo renderFin($mensajeGracias, false); ?>

    <?php elseif ($yaRespondio) : ?>

      <?php echo renderFin('Esta reseña ya se había enviado. Gracias por tomarte el tiempo.', false); ?>

    <?php else : ?>

      <section class="fb-card fb-encuesta" id="fb-form-wrap" aria-labelledby="fb-titulo">

        <div class="fb-progreso">
          <h1 class="fb-progreso__rotulo" id="fb-titulo">Tu opinión</h1>
          <?php /* El contador visible es para la vista; el lector de pantalla
                   recibe lo mismo de aria-valuetext, así que va oculto para él. */ ?>
          <span class="fb-progreso__cuenta" id="fb-progress-label" aria-hidden="true">1 de <?php echo $totalPasos; ?></span>
          <div class="fb-progreso__barra" id="fb-progress" role="progressbar"
               aria-label="Avance de la encuesta"
               aria-valuemin="1" aria-valuemax="<?php echo $totalPasos; ?>" aria-valuenow="1"
               aria-valuetext="Paso 1 de <?php echo $totalPasos; ?>">
            <?php for ($i = 0; $i < $totalPasos; $i++) : ?>
              <span class="fb-progreso__seg<?php echo $i === 0 ? ' is-actual' : ''; ?>"></span>
            <?php endfor; ?>
          </div>
        </div>

        <?php /* Sin `novalidate`: sin JS manda la validación nativa (cada grupo
                 es `required`). feedback.js la apaga al arrancar porque
                 valida pregunta a pregunta y explica qué falta en su sitio. */ ?>
        <form class="fb-form" id="fb-form" method="post" action="/api/feedback" data-paso="0" data-posicion="primero">
          <input type="hidden" name="token" id="fb-token" value="<?php echo $tokenValue; ?>">

          <?php foreach ($pasos as $i => [$campo, $italiano, $pregunta, $nombre]) : ?>
            <?php $tituloId = 'fb-q-' . $campo; ?>
            <section class="fb-step<?php echo $i === 0 ? ' fb-step--active' : ''; ?>" data-step="<?php echo $i; ?>"
                     aria-labelledby="<?php echo $h($tituloId); ?>">
              <p class="fb-step__rotulo" lang="it"><?php echo $h($italiano); ?></p>
              <?php /* tabindex="-1": al cambiar de paso el foco viene aquí para que
                       el lector de pantalla anuncie la pregunta nueva. */ ?>
              <h2 class="fb-step__titulo" id="<?php echo $h($tituloId); ?>" tabindex="-1"><?php echo $h($pregunta); ?></h2>
              <div class="fb-escala" role="radiogroup" aria-required="true"
                   aria-labelledby="<?php echo $h($tituloId); ?>"
                   aria-describedby="fb-aviso-<?php echo $i; ?>"
                   data-campo="<?php echo $h($campo); ?>" data-nombre="<?php echo $h($nombre); ?>">
                <?php echo renderEscala($campo); ?>
              </div>
              <p class="fb-alerta fb-step__aviso" id="fb-aviso-<?php echo $i; ?>" aria-live="polite">
                <?php echo $iconoAlerta; ?><span class="fb-alerta__texto"></span>
              </p>
            </section>
          <?php endforeach; ?>

          <section class="fb-step" data-step="4" aria-labelledby="fb-q-comentario">
            <p class="fb-step__rotulo" lang="it">Le parole</p>
            <h2 class="fb-step__titulo" id="fb-q-comentario" tabindex="-1">¿Qué podríamos mejorar?</h2>
            <p class="fb-step__ayuda" id="fb-comentario-ayuda">Opcional. Lo leemos todo.</p>
            <textarea class="fb-textarea" id="fb-comentario" name="comentario" rows="4"
                      aria-labelledby="fb-q-comentario" aria-describedby="fb-comentario-ayuda"
                      placeholder="Escribe con calma: un platillo, un detalle, lo que sea."></textarea>
          </section>

          <section class="fb-step fb-step--resumen" data-step="5" aria-labelledby="fb-q-resumen">
            <p class="fb-step__rotulo" lang="it">Il riepilogo</p>
            <h2 class="fb-step__titulo" id="fb-q-resumen" tabindex="-1">Revisa tu reseña</h2>
            <p class="fb-step__ayuda">Toca otra cara para cambiar una respuesta.</p>
            <div class="fb-resumen" id="fb-resumen"></div>
          </section>

          <?php /* El error de envío vive junto al botón que lo provocó, no en un
                   aviso flotante en la esquina: el comensal está mirando el botón. */ ?>
          <p class="fb-alerta fb-envio-error<?php echo $errorEnvio ? ' is-visible' : ''; ?>" id="fb-error" role="alert">
            <?php echo $iconoAlerta; ?><span class="fb-alerta__texto" id="fb-error-texto"><?php
              echo $errorEnvio ? 'No pudimos guardar tu reseña. Inténtalo de nuevo, por favor.' : '';
            ?></span>
          </p>

          <div class="fb-nav">
            <button type="button" class="fb-btn fb-btn--secundario fb-nav__prev" id="fb-prev">
              <?php echo $iconoVuelve; ?><span class="fb-btn__texto">Anterior</span>
            </button>
            <button type="button" class="fb-btn fb-btn--primario fb-nav__next" id="fb-next">
              <span id="fb-next-label">Siguiente</span><?php echo $iconoSigue; ?>
            </button>
            <button type="submit" class="fb-btn fb-btn--primario fb-nav__enviar" id="fb-submit">
              <span class="fb-btn__giro" aria-hidden="true"></span>
              <span id="fb-submit-label">Enviar reseña</span><?php echo $iconoEnvia; ?>
            </button>
          </div>
        </form>
      </section>

      <?php echo renderFin($mensajeGracias, true); ?>

    <?php endif; ?>

  </main>

  <?php /* El comportamiento vive en src/js/modules/feedback.js, dentro del
           bundle público. GSAP ya no se carga aquí: el cambio de paso lo anima
           la Web Animations API y el resto es CSS, así que la pantalla se
           ahorra la librería entera. */ ?>
  <script src="<?php echo s(recursoVersionado('/build/js/bundle.min.js')); ?>" defer></script>
</body>
</html>
