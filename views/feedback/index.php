<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tu opinión · Casa Pestalozzi</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="fb-page">

  <div class="fb-shell">

    <?php if (isset($yaRespondio) && $yaRespondio): ?>

      <!-- ── Ya respondió ───────────────────────────────── -->
      <div class="fb-card fb-card--done">
        <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
        <div class="fb-done-icon">✓</div>
        <h1 class="fb-done-title">¡Gracias por tu reseña!</h1>
        <p class="fb-done-sub">Tu opinión ya fue registrada. Nos alegra que hayas visitado Casa Pestalozzi.</p>
      </div>

    <?php else: ?>

      <!-- ── Formulario por pasos ───────────────────────── -->
      <div class="fb-card" id="fb-form-wrap">

        <header class="fb-header">
          <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
          <p class="fb-header__sub">Cuéntanos cómo fue tu experiencia</p>

          <div class="fb-progress">
            <div class="fb-progress__bar">
              <div class="fb-progress__fill" id="fb-progress-fill"></div>
            </div>
            <span class="fb-progress__label" id="fb-progress-label">1 de 5</span>
          </div>
        </header>

        <form class="fb-form" id="fb-form" novalidate>
          <?php $tokenValue = isset($token) && $token ? htmlspecialchars($token) : ''; ?>
          <input type="hidden" id="fb-token" value="<?php echo $tokenValue; ?>" />

          <!-- Paso 1 -->
          <div class="fb-step" data-step="0">
            <p class="fb-step__label">Calidad y sabor</p>
            <div class="fb-escala" data-campo="calidad_sabor">
              <?php echo renderEscala('calidad_sabor'); ?>
            </div>
          </div>

          <!-- Paso 2 -->
          <div class="fb-step" data-step="1">
            <p class="fb-step__label">Atención del mesero</p>
            <div class="fb-escala" data-campo="atencion_mesero">
              <?php echo renderEscala('atencion_mesero'); ?>
            </div>
          </div>

          <!-- Paso 3 -->
          <div class="fb-step" data-step="2">
            <p class="fb-step__label">Tiempo de espera</p>
            <div class="fb-escala" data-campo="tiempo_espera">
              <?php echo renderEscala('tiempo_espera'); ?>
            </div>
          </div>

          <!-- Paso 4 -->
          <div class="fb-step" data-step="3">
            <p class="fb-step__label">Experiencia global</p>
            <div class="fb-escala" data-campo="experiencia_global">
              <?php echo renderEscala('experiencia_global'); ?>
            </div>
          </div>

          <!-- Paso 5 — pregunta abierta -->
          <div class="fb-step" data-step="4">
            <p class="fb-step__label">¿Qué podríamos mejorar para que tu próxima experiencia sea mejor?</p>
            <textarea class="fb-textarea" id="fb-comentario"
                      placeholder="Tu opinión es muy valiosa para nosotros…" rows="5"></textarea>
          </div>

          <!-- Navegación -->
          <div class="fb-nav">
            <button type="button" class="fb-nav__prev" id="fb-prev">← Anterior</button>
            <button type="button" class="fb-nav__next" id="fb-next">Siguiente →</button>
          </div>

          <p class="fb-error" id="fb-error"></p>

          <button type="submit" class="fb-submit" id="fb-submit">
            <span id="fb-submit-label">Enviar reseña</span>
          </button>
        </form>
      </div>

      <!-- ── Éxito ──────────────────────────────────────── -->
      <div class="fb-card fb-card--done" id="fb-success" style="display:none">
        <a href="/" class="fb-brand-mark">Casa Pestalozzi</a>
        <div class="fb-done-icon">✓</div>
        <h1 class="fb-done-title">¡Gracias por tu reseña!</h1>
        <p class="fb-done-sub">Tu opinión nos ayuda a seguir mejorando. Fue un placer atenderte en Casa Pestalozzi.</p>
      </div>

    <?php endif; ?>

  </div>

  <script>
  (function() {
    var CAMPOS = ['calidad_sabor', 'atencion_mesero', 'tiempo_espera', 'experiencia_global'];
    var TOTAL  = 5;

    var form        = document.getElementById('fb-form');
    var wrap        = document.getElementById('fb-form-wrap');
    var success     = document.getElementById('fb-success');
    var errorEl     = document.getElementById('fb-error');
    var prevBtn     = document.getElementById('fb-prev');
    var nextBtn     = document.getElementById('fb-next');
    var submitBtn   = document.getElementById('fb-submit');
    var submitLbl   = document.getElementById('fb-submit-label');
    var progressFill = document.getElementById('fb-progress-fill');
    var progressLbl  = document.getElementById('fb-progress-label');
    var steps        = document.querySelectorAll('.fb-step');

    if (!form || !steps.length) return;

    var current = 0;

    function showStep(idx) {
      steps.forEach(function(s, i) {
        s.classList.toggle('fb-step--active', i === idx);
      });
      current = idx;
      updateUI();
    }

    function updateUI() {
      // Progreso
      var pct = (current / (TOTAL - 1)) * 100;
      if (progressFill) progressFill.style.width = pct + '%';
      if (progressLbl)  progressLbl.textContent  = (current + 1) + ' de ' + TOTAL;

      // Botones de navegación
      prevBtn.style.display   = current === 0        ? 'none' : '';
      nextBtn.style.display   = current === TOTAL - 1 ? 'none' : '';
      submitBtn.style.display = current === TOTAL - 1 ? ''     : 'none';

      clearError();
    }

    function currentCampo() {
      if (current < CAMPOS.length) return CAMPOS[current];
      return null;
    }

    function isRatingStepValid() {
      var campo = currentCampo();
      if (!campo) return true; // paso de texto libre
      var input = document.getElementById('fb-val-' + campo);
      return input && input.value !== '';
    }

    // Eventos de caras
    document.querySelectorAll('.fb-escala').forEach(function(escala) {
      escala.querySelectorAll('.fb-face').forEach(function(face) {
        face.addEventListener('click', function() {
          escala.querySelectorAll('.fb-face').forEach(function(f) {
            f.classList.remove('fb-face--active');
          });
          face.classList.add('fb-face--active');
          var input = document.getElementById('fb-val-' + escala.dataset.campo);
          if (input) input.value = face.dataset.valor;
          clearError();
        });
      });
    });

    // Siguiente
    nextBtn.addEventListener('click', function() {
      if (!isRatingStepValid()) {
        showError('Selecciona una opción para continuar.');
        return;
      }
      if (current < TOTAL - 1) showStep(current + 1);
    });

    // Anterior
    prevBtn.addEventListener('click', function() {
      if (current > 0) showStep(current - 1);
    });

    // Enviar
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      var payload = { token: document.getElementById('fb-token').value };
      var valido  = true;

      CAMPOS.forEach(function(campo) {
        var input = document.getElementById('fb-val-' + campo);
        var val   = input ? parseInt(input.value, 10) : 0;
        if (!val || val < 1 || val > 5) valido = false;
        payload[campo] = val;
      });

      if (!valido) {
        showError('Por favor responde todas las preguntas.');
        return;
      }

      payload.comentario = (document.getElementById('fb-comentario') || {}).value || '';
      submitBtn.disabled    = true;
      submitLbl.textContent = 'Enviando…';
      clearError();

      fetch('/api/feedback', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.ok) {
          if (wrap)    wrap.style.display    = 'none';
          if (success) success.style.display = '';
        } else {
          showError(res.msg || 'Ocurrió un error. Intenta de nuevo.');
          submitBtn.disabled    = false;
          submitLbl.textContent = 'Enviar reseña';
        }
      })
      .catch(function() {
        showError('Error de conexión. Intenta de nuevo.');
        submitBtn.disabled    = false;
        submitLbl.textContent = 'Enviar reseña';
      });
    });

    function showError(msg) {
      if (errorEl) { errorEl.textContent = msg; errorEl.classList.add('fb-error--visible'); }
    }
    function clearError() {
      if (errorEl) { errorEl.textContent = ''; errorEl.classList.remove('fb-error--visible'); }
    }

    // Inicializar
    showStep(0);
  })();
  </script>

</body>
</html>

<?php
function renderEscala(string $campo): string {
    $labels = ['Muy malo', 'Malo', 'Regular', 'Bueno', 'Excelente'];
    $colors = ['#e53935', '#F57C00', '#F9A825', '#43A047', '#29B6F6'];

    $faces = [
        // 1 - Enojado
        '<path d="M27 33 L41 41" stroke="#1a1a1a" stroke-width="3.5" stroke-linecap="round"/>
         <path d="M73 33 L59 41" stroke="#1a1a1a" stroke-width="3.5" stroke-linecap="round"/>
         <circle cx="36" cy="47" r="5" fill="#1a1a1a"/>
         <circle cx="64" cy="47" r="5" fill="#1a1a1a"/>
         <path d="M33 69 Q50 59 67 69" stroke="#1a1a1a" stroke-width="3.5" fill="none" stroke-linecap="round"/>',

        // 2 - Triste
        '<circle cx="36" cy="44" r="5" fill="#1a1a1a"/>
         <circle cx="64" cy="44" r="5" fill="#1a1a1a"/>
         <path d="M33 67 Q50 59 67 67" stroke="#1a1a1a" stroke-width="3.5" fill="none" stroke-linecap="round"/>',

        // 3 - Neutral
        '<circle cx="36" cy="44" r="5" fill="#1a1a1a"/>
         <circle cx="64" cy="44" r="5" fill="#1a1a1a"/>
         <line x1="34" y1="64" x2="66" y2="64" stroke="#1a1a1a" stroke-width="3.5" stroke-linecap="round"/>',

        // 4 - Feliz
        '<circle cx="36" cy="44" r="5" fill="#1a1a1a"/>
         <circle cx="64" cy="44" r="5" fill="#1a1a1a"/>
         <path d="M34 60 Q50 74 66 60" stroke="#1a1a1a" stroke-width="3.5" fill="none" stroke-linecap="round"/>',

        // 5 - Muy feliz
        '<path d="M29 42 Q36 35 43 42" stroke="#1a1a1a" stroke-width="3.5" fill="none" stroke-linecap="round"/>
         <path d="M57 42 Q64 35 71 42" stroke="#1a1a1a" stroke-width="3.5" fill="none" stroke-linecap="round"/>
         <path d="M32 60 Q50 78 68 60 Q50 70 32 60Z" fill="#1a1a1a"/>',
    ];

    $html = '<input type="hidden" id="fb-val-' . htmlspecialchars($campo) . '" value="" />';
    foreach ($labels as $i => $label) {
        $valor = $i + 1;
        $html .= '<button type="button" class="fb-face fb-face--' . $valor . '" data-valor="' . $valor . '">';
        $html .= '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">';
        $html .= '<circle cx="50" cy="50" r="48" fill="' . $colors[$i] . '"/>';
        $html .= $faces[$i];
        $html .= '</svg>';
        $html .= '<span class="fb-face__label">' . htmlspecialchars($label) . '</span>';
        $html .= '</button>';
    }
    return $html;
}
