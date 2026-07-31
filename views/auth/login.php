<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acceso · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="login-page" data-page="login">

  <main class="login-shell">
    <div class="login-aside" aria-hidden="true">
      <div class="login-aside__overlay"></div>
      <div class="login-aside__content">
        <span class="eyebrow">Acceso del Personal</span>
        <p class="login-aside__quote">Cocina mediterránea con corazón mexicano.</p>
        <span class="login-aside__sign">Casa Pestalozzi</span>
      </div>
    </div>

    <section class="login-panel">
      <div class="login-card">
        <a class="login-brand" href="/">Casa Pestalozzi</a>
        <span class="login-eyebrow eyebrow no-rule">Acceso del personal</span>
        <h1 class="login-title">Ingresa tu NIP</h1>
        <p class="login-sub">Acceso rápido para meseros y cajeros: tu NIP te lleva al mapa de mesas.</p>

        <?php foreach (($alertas['error'] ?? []) as $error) : ?>
          <p class="login-alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endforeach; ?>

        <form class="login-form login-form--nip" action="/login" method="post" novalidate id="login-nip-form">
          <input type="password" name="nip" id="login-nip" class="login-nip-hidden"
                 inputmode="numeric" autocomplete="one-time-code" pattern="\d{4,6}" maxlength="6"
                 aria-label="NIP de acceso" autofocus>

          <div class="login-nip-dots" id="login-nip-dots" aria-hidden="true">
            <span class="login-nip-dot"></span>
            <span class="login-nip-dot"></span>
            <span class="login-nip-dot"></span>
            <span class="login-nip-dot"></span>
          </div>

          <div class="login-pad" id="login-pad">
            <button type="button" class="login-pad__key" data-key="1">1</button>
            <button type="button" class="login-pad__key" data-key="2">2</button>
            <button type="button" class="login-pad__key" data-key="3">3</button>
            <button type="button" class="login-pad__key" data-key="4">4</button>
            <button type="button" class="login-pad__key" data-key="5">5</button>
            <button type="button" class="login-pad__key" data-key="6">6</button>
            <button type="button" class="login-pad__key" data-key="7">7</button>
            <button type="button" class="login-pad__key" data-key="8">8</button>
            <button type="button" class="login-pad__key" data-key="9">9</button>
            <button type="button" class="login-pad__key login-pad__key--aux" data-action="clear" aria-label="Borrar todo">C</button>
            <button type="button" class="login-pad__key" data-key="0">0</button>
            <button type="button" class="login-pad__key login-pad__key--aux" data-action="back" aria-label="Borrar último dígito">⌫</button>
          </div>

          <button type="submit" class="btn-line login-submit" id="login-nip-submit" disabled>
            <span>Entrar</span>
            <span class="arrow">↗</span>
          </button>
        </form>

        <p class="login-foot">¿No tienes NIP u olvidaste el tuyo? Pide al administrador que te lo asigne.</p>
        <p class="login-foot"><a class="login-link" href="/admin/login">Acceso de administrador</a></p>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var MIN = 4, MAX = 6;
      var form   = document.getElementById('login-nip-form');
      var input  = document.getElementById('login-nip');
      var dots   = document.querySelectorAll('#login-nip-dots .login-nip-dot');
      var pad    = document.getElementById('login-pad');
      var submit = document.getElementById('login-nip-submit');

      function pintar() {
        var n = input.value.length;
        for (var i = 0; i < dots.length; i++) {
          dots[i].classList.toggle('is-on', i < n);
        }
        submit.disabled = n < MIN;
      }

      pad.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.key !== undefined && input.value.length < MAX) {
          input.value += btn.dataset.key;
        } else if (btn.dataset.action === 'back') {
          input.value = input.value.slice(0, -1);
        } else if (btn.dataset.action === 'clear') {
          input.value = '';
        }
        pintar();
        input.focus();
      });

      // Teclado físico: solo dígitos en el campo oculto
      input.addEventListener('input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, MAX);
        pintar();
      });

      form.addEventListener('submit', function (e) {
        if (input.value.length < MIN) e.preventDefault();
      });

      // Mantener el foco en el campo para que el teclado físico siempre funcione
      document.addEventListener('click', function () { input.focus(); });

      pintar();
      input.focus();
    })();
  </script>
</body>
</html>
