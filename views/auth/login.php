<?php
  /**
   * Acceso unificado del personal. Dos pestañas, dos formularios, dos
   * endpoints (/login y /admin/login): la semántica de cada acceso es
   * distinta y los gestores de contraseñas necesitan formularios separados
   * para no mezclar el NIP con la contraseña.
   *
   * $tabActiva la fija el controlador que atendió la petición, así que un POST
   * fallido ya llega renderizado en su pestaña: el JS de abajo solo maneja el
   * cambio manual.
   */
  $tabActiva    = $tabActiva ?? 'nip';
  $alertas      = $alertas ?? [];
  $alertasAdmin = $alertasAdmin ?? [];
  $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

  // El icono acompaña al color de la banda: el tipo de aviso se reconoce antes
  // de leer el texto.
  $iconoAlerta = static function (string $tipo): string {
      $paths = $tipo === 'aviso'
          ? '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01"/><path d="M11 12h1v4h1"/>'
          : '<circle cx="12" cy="12" r="9"/><path d="M12 7v5"/><path d="M12 16h.01"/>';

      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
          . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
  };
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acceso · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="robots" content="noindex, nofollow" />
  <?php /* Geist locales: el piso funciona sin red. */ ?>
  <link rel="preload" href="/build/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/build/css/operation.css?v=consola-bn-v1">
</head>
<body class="admin-body login-page" data-page="login">

  <main class="login-shell">
    <div class="login-aside" aria-hidden="true">
      <div class="login-aside__overlay"></div>
      <div class="login-aside__content">
        <span class="admin-eyebrow">Acceso del Personal</span>
        <p class="login-aside__quote">Cocina mediterránea con corazón mexicano.</p>
        <span class="login-aside__sign">Casa Pestalozzi</span>
      </div>
    </div>

    <section class="login-panel">
      <div class="login-card">
        <a class="login-brand" href="/">Casa Pestalozzi</a>

        <div class="login-tabs" role="tablist" aria-label="Tipo de acceso">
          <button type="button"
                  class="login-tab<?php echo $tabActiva === 'nip' ? ' is-active' : ''; ?>"
                  role="tab" id="login-tab-btn-nip"
                  aria-controls="login-tab-nip"
                  aria-selected="<?php echo $tabActiva === 'nip' ? 'true' : 'false'; ?>"
                  data-login-tab="nip">
            NIP
          </button>
          <button type="button"
                  class="login-tab<?php echo $tabActiva === 'admin' ? ' is-active' : ''; ?>"
                  role="tab" id="login-tab-btn-admin"
                  aria-controls="login-tab-admin"
                  aria-selected="<?php echo $tabActiva === 'admin' ? 'true' : 'false'; ?>"
                  data-login-tab="admin">
            Contraseña
          </button>
        </div>

        <!-- ── Personal de piso: NIP de 4 dígitos ─────────────── -->
        <div class="login-tabpanel" id="login-tab-nip" role="tabpanel"
             aria-labelledby="login-tab-btn-nip"
             <?php echo $tabActiva === 'nip' ? '' : 'hidden'; ?>>
          <?php /* Sin eyebrow: la pestaña activa ya dice de qué acceso se trata. */ ?>
          <h1 class="login-title">Ingresa tu NIP</h1>
          <p class="login-sub">Acceso rápido para meseros y cocineros: tu NIP te lleva a tu área de trabajo.</p>

          <?php foreach (($alertas['error'] ?? []) as $error) : ?>
            <p class="login-alert" role="alert"><?php echo $iconoAlerta('error'); ?><span><?php echo $h($error); ?></span></p>
          <?php endforeach; ?>

          <form class="login-form login-form--nip" action="/login" method="post" novalidate id="login-nip-form">
            <input type="password" name="nip" id="login-nip" class="login-nip-hidden"
                   inputmode="numeric" autocomplete="one-time-code" pattern="\d{4}" maxlength="4"
                   aria-label="NIP de acceso">

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

            <button type="submit" class="admin-btn admin-btn--primary login-submit" id="login-nip-submit" disabled>
              <span>Entrar</span>
            </button>
          </form>

          <p class="login-foot">¿No tienes NIP u olvidaste el tuyo? Pide al administrador que te lo asigne.</p>
        </div>

        <!-- ── Administración: usuario + contraseña ───────────── -->
        <div class="login-tabpanel" id="login-tab-admin" role="tabpanel"
             aria-labelledby="login-tab-btn-admin"
             <?php echo $tabActiva === 'admin' ? '' : 'hidden'; ?>>
          <h1 class="login-title">Inicia sesión</h1>
          <p class="login-sub">Ingresa con tu usuario y contraseña para entrar al panel de administración.</p>

          <?php foreach (($alertasAdmin['aviso'] ?? []) as $aviso) : ?>
            <p class="login-alert login-alert--aviso" role="status"><?php echo $iconoAlerta('aviso'); ?><span><?php echo $h($aviso); ?></span></p>
          <?php endforeach; ?>
          <?php foreach (($alertasAdmin['error'] ?? []) as $error) : ?>
            <p class="login-alert" role="alert"><?php echo $iconoAlerta('error'); ?><span><?php echo $h($error); ?></span></p>
          <?php endforeach; ?>

          <form class="login-form" action="/admin/login" method="post" novalidate>
            <div class="login-field">
              <label class="login-field__label" for="login-username">Usuario</label>
              <input type="text" id="login-username" name="username"
                     autocomplete="username" autocapitalize="off" spellcheck="false"
                     value="<?php echo $h($_POST['username'] ?? ''); ?>">
            </div>

            <div class="login-field">
              <label class="login-field__label" for="login-password">Contraseña</label>
              <span class="login-field__control">
                <input type="password" id="login-password" name="password" autocomplete="current-password">
                <button type="button" class="login-eye" id="login-eye" aria-label="Mostrar contraseña">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </span>
            </div>

            <button type="submit" class="admin-btn admin-btn--primary login-submit">
              <span>Entrar</span>
            </button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var TAB_KEY = 'cp-login-tab';
      var tabs    = document.querySelectorAll('[data-login-tab]');
      var vieneDePost = <?php echo $_SERVER['REQUEST_METHOD'] === 'POST' ? 'true' : 'false'; ?>;

      function activar(clave, enfocar) {
        for (var i = 0; i < tabs.length; i++) {
          var boton  = tabs[i];
          var activa = boton.dataset.loginTab === clave;
          var panel  = document.getElementById('login-tab-' + boton.dataset.loginTab);

          boton.classList.toggle('is-active', activa);
          boton.setAttribute('aria-selected', activa ? 'true' : 'false');
          if (panel) panel.hidden = !activa;
        }

        try { sessionStorage.setItem(TAB_KEY, clave); } catch (e) {}

        if (enfocar) {
          var foco = clave === 'nip'
            ? document.getElementById('login-nip')
            : document.getElementById('login-username');
          if (foco) foco.focus();
        }
      }

      for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function () {
          activar(this.dataset.loginTab, true);
        });
      }

      // La pestaña recordada solo aplica en visitas limpias: si el servidor
      // acaba de renderizar el resultado de un POST, o el enlace traía ?tab=,
      // ese resultado manda.
      if (!vieneDePost && !location.search) {
        try {
          var guardada = sessionStorage.getItem(TAB_KEY);
          if (guardada === 'admin' || guardada === 'nip') activar(guardada, false);
        } catch (e) {}
      }
    })();

    // ── Teclado numérico del NIP ────────────────────────────
    (function () {
      var LARGO  = 4;
      var form   = document.getElementById('login-nip-form');
      var input  = document.getElementById('login-nip');
      var dots   = document.querySelectorAll('#login-nip-dots .login-nip-dot');
      var pad    = document.getElementById('login-pad');
      var submit = document.getElementById('login-nip-submit');
      var panel  = document.getElementById('login-tab-nip');

      function pintar() {
        var n = input.value.length;
        for (var i = 0; i < dots.length; i++) {
          dots[i].classList.toggle('is-on', i < n);
        }
        submit.disabled = n < LARGO;
      }

      pad.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.key !== undefined && input.value.length < LARGO) {
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
        input.value = input.value.replace(/\D/g, '').slice(0, LARGO);
        pintar();
      });

      form.addEventListener('submit', function (e) {
        if (input.value.length < LARGO) e.preventDefault();
      });

      // Devolver el foco al campo oculto mantiene vivo el teclado físico, pero
      // solo con la pestaña del NIP visible: si no, le robaría el foco a los
      // campos de la pestaña de administrador.
      document.addEventListener('click', function (e) {
        if (panel.hidden) return;
        if (e.target.closest('[data-login-tab]')) return;
        input.focus();
      });

      pintar();
      if (!panel.hidden) input.focus();
    })();

    // ── Mostrar/ocultar contraseña ──────────────────────────
    (function () {
      var input = document.getElementById('login-password');
      var eye = document.getElementById('login-eye');

      eye.addEventListener('click', function () {
        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        eye.classList.toggle('is-on', !visible);
        eye.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        input.focus();
      });
    })();
  </script>
</body>
</html>
