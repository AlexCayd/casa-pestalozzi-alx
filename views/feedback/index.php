<?php
/**
 * Encuesta de salida del comensal.
 *
 * Es la ÚNICA pantalla de tablet que pertenece a la marca del restaurante y no
 * al sistema administrativo: la abre un cliente al terminar de comer, así que
 * tiene que verse como la landing. Por eso se queda dentro de `app.css` mientras
 * el POS, las áreas y el login se fueron a `operation.css`.
 *
 * Las cinco caras se dibujan UNA sola vez, aquí. Antes la tabla de colores y los
 * cinco `path` estaban triplicados —en este archivo, en el JS de la página y en
 * el SCSS— y los tres discrepaban: el PHP pintaba #e53935 donde el CSS decía
 * --c-rojo (#e51022). Ahora el SVG va en `currentColor` y el color lo pone el
 * CSS desde la paleta funcional, que es la única fuente.
 */

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

/**
 * Una escala de cinco caras.
 *
 * Line-art de trazo, no discos planos: el dibujo hereda `currentColor`, así que
 * la misma marca sirve para el paso y para el resumen sin volver a construirla
 * —el JS clona este nodo en vez de tener su propia copia—.
 */
function renderEscala(string $campo): string {
    $etiquetas = ['Muy malo', 'Malo', 'Regular', 'Bueno', 'Excelente'];

    // Ojos y boca. La boca es lo único que cambia de verdad entre caras; las
    // cejas sólo aparecen en los extremos, que es lo que las hace legibles de
    // un vistazo a distancia de brazo.
    $rasgos = [
        // 1 · Muy malo — cejas caídas hacia dentro, boca invertida
        '<path d="M27 34 L41 42"/><path d="M73 34 L59 42"/>
         <circle cx="36" cy="50" r="3.4" fill="currentColor" stroke="none"/>
         <circle cx="64" cy="50" r="3.4" fill="currentColor" stroke="none"/>
         <path d="M34 70 Q50 58 66 70"/>',
        // 2 · Malo
        '<circle cx="36" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <circle cx="64" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <path d="M35 68 Q50 60 65 68"/>',
        // 3 · Regular
        '<circle cx="36" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <circle cx="64" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <path d="M35 65 L65 65"/>',
        // 4 · Bueno
        '<circle cx="36" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <circle cx="64" cy="46" r="3.4" fill="currentColor" stroke="none"/>
         <path d="M35 61 Q50 73 65 61"/>',
        // 5 · Excelente — cejas alzadas y sonrisa abierta
        '<path d="M29 40 Q36 33 43 40"/><path d="M57 40 Q64 33 71 40"/>
         <circle cx="36" cy="50" r="3.4" fill="currentColor" stroke="none"/>
         <circle cx="64" cy="50" r="3.4" fill="currentColor" stroke="none"/>
         <path d="M33 60 Q50 76 67 60 Z"/>',
    ];

    $html = '<input type="hidden" id="fb-val-' . htmlspecialchars($campo, ENT_QUOTES, 'UTF-8') . '" value="">';

    foreach ($etiquetas as $i => $etiqueta) {
        $valor = $i + 1;
        $html .= '<button type="button" class="fb-face fb-face--' . $valor . '"'
              . ' data-valor="' . $valor . '"'
              . ' aria-pressed="false"'
              . ' aria-label="' . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '">'
              . '<span class="fb-face__disc" aria-hidden="true">'
              . '<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="3.2"'
              . ' stroke-linecap="round" stroke-linejoin="round">'
              . '<circle cx="50" cy="50" r="45" class="fb-face__aro"/>'
              . $rasgos[$i]
              . '</svg></span>'
              . '<span class="fb-face__label">' . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</span>'
              . '</button>';
    }

    return $html;
}

$yaRespondio = isset($yaRespondio) && $yaRespondio;
$tokenValue  = isset($token) && $token ? $h($token) : '';

// Los cuatro ejes de la encuesta. El rótulo numerado es el de la landing y el
// texto italiano es la voz de la casa.
$pasos = [
    ['calidad_sabor',      '01', 'Il sapore',    'Calidad y sabor'],
    ['atencion_mesero',    '02', 'Il servizio',  'Atención del personal'],
    ['tiempo_espera',      '03', 'Il tempo',     'Tiempo de espera'],
    ['experiencia_global', '04', "L'esperienza", 'Experiencia global'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tu opinión · Casa Pestalozzi</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
  <link rel="apple-touch-icon" href="/build/images/logo.svg">
  <?php /* Las mismas caras que precarga la portada: el wordmark y la cursiva de
           acento son lo primero que se ve, y un salto de cara en el wordmark se
           nota más que la espera. */ ?>
  <link rel="preload" href="/build/fonts/KudosKapsOneNF.otf" as="font" type="font/otf" crossorigin>
  <link rel="preload" href="/build/fonts/crimson-text-latin-400-italic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/build/css/app.css?v=feedback-marca-v1">
</head>
<?php /* Sin data-modo="oscuro": esta pantalla es la landing, no el piso. */ ?>
<body class="fb-page" data-tono="crema" data-page="feedback">

  <div class="fb-shell">

    <?php if ($yaRespondio) : ?>

      <section class="fb-card fb-card--done" data-reveal>
        <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
        <span class="fb-done-icon" aria-hidden="true">
          <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6"
               stroke-linecap="round" stroke-linejoin="round">
            <circle cx="24" cy="24" r="21"/><path d="M15 24.5 L21 30.5 L33 18"/>
          </svg>
        </span>
        <h1 class="fb-done-title">Grazie <em class="accent-italic">mille</em></h1>
        <p class="fb-done-sub">
          Tu opinión ya quedó registrada. Nos alegra que hayas venido a Casa Pestalozzi.
        </p>
        <a class="btn-line" href="/"><span>Volver al sitio</span><span class="arrow">↗</span></a>
      </section>

    <?php else : ?>

      <section class="fb-card" id="fb-form-wrap">

        <header class="fb-header">
          <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
          <p class="fb-header__sub">Cuéntanos cómo fue tu experiencia</p>

          <?php /* El contador lo escribe el JS. En el marcado va el valor
                   inicial correcto: decía «1 de 5» con seis pasos. */ ?>
          <div class="fb-progress">
            <div class="fb-progress__bar" role="progressbar"
                 aria-valuemin="1" aria-valuemax="6" aria-valuenow="1"
                 aria-label="Progreso de la encuesta">
              <div class="fb-progress__fill" id="fb-progress-fill"></div>
            </div>
            <span class="fb-progress__label" id="fb-progress-label">1 de 6</span>
          </div>
        </header>

        <form class="fb-form" id="fb-form" novalidate>
          <input type="hidden" id="fb-token" value="<?php echo $tokenValue; ?>">

          <?php foreach ($pasos as $i => [$campo, $num, $italiano, $titulo]) : ?>
            <div class="fb-step" data-step="<?php echo $i; ?>">
              <span class="eyebrow no-rule"><?php echo $h($num . ' — ' . $italiano); ?></span>
              <h2 class="fb-step__label"><?php echo $h($titulo); ?></h2>
              <div class="fb-escala" data-campo="<?php echo $h($campo); ?>" role="group"
                   aria-label="<?php echo $h($titulo); ?>">
                <?php echo renderEscala($campo); ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="fb-step" data-step="4">
            <span class="eyebrow no-rule">05 — Le parole</span>
            <h2 class="fb-step__label">¿Qué podríamos mejorar?</h2>
            <textarea class="fb-textarea" id="fb-comentario" rows="5"
                      placeholder="Escribe lo que quieras. Lo leemos todo."></textarea>
          </div>

          <div class="fb-step" data-step="5">
            <span class="eyebrow no-rule">06 — Il riepilogo</span>
            <h2 class="fb-step__label">Confirma tu reseña</h2>
            <div class="fb-resumen" id="fb-resumen"></div>
          </div>

          <div class="fb-nav">
            <button type="button" class="fb-nav__prev" id="fb-prev">← Anterior</button>
            <button type="button" class="fb-nav__next" id="fb-next">Siguiente →</button>
          </div>

          <button type="submit" class="btn-line btn-line--solid fb-submit" id="fb-submit">
            <span id="fb-submit-label">Enviar reseña</span><span class="arrow">↗</span>
          </button>
        </form>
      </section>

      <section class="fb-card fb-card--done" id="fb-success" hidden>
        <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
        <span class="fb-done-icon" aria-hidden="true">
          <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6"
               stroke-linecap="round" stroke-linejoin="round">
            <circle cx="24" cy="24" r="21"/><path d="M15 24.5 L21 30.5 L33 18"/>
          </svg>
        </span>
        <h1 class="fb-done-title">Grazie <em class="accent-italic">mille</em></h1>
        <p class="fb-done-sub">
          Tu opinión nos ayuda a seguir mejorando. Fue un placer atenderte.
        </p>
        <a class="btn-line" href="/"><span>Volver al sitio</span><span class="arrow">↗</span></a>
      </section>

    <?php endif; ?>

  </div>

  <?php /* El comportamiento vive en src/js/modules/feedback.js, dentro del
           bundle público. Estaba escrito a mano dentro de esta vista, así que no
           podía usar AppNotice ni GSAP — los dos viajan aquí. */ ?>
  <script src="/build/js/vendor/gsap.min.js" defer></script>
  <script src="/build/js/bundle.min.js?v=feedback-marca-v1" defer></script>
</body>
</html>
