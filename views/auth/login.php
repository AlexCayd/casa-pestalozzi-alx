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
        <span class="eyebrow">Panel de Administración</span>
        <p class="login-aside__quote">Cocina mediterránea con corazón mexicano.</p>
        <span class="login-aside__sign">Casa Pestalozzi</span>
      </div>
    </div>

    <section class="login-panel">
      <div class="login-card">
        <a class="login-brand" href="/">Casa Pestalozzi</a>
        <span class="login-eyebrow eyebrow no-rule">Acceso al panel</span>
        <h1 class="login-title">Bienvenido de vuelta</h1>
        <p class="login-sub">Ingresa tus credenciales para administrar el restaurante.</p>

        <form class="login-form" action="/admin" method="get" novalidate>
          <label class="login-field">
            <span class="login-field__label">Usuario</span>
            <input type="text" name="username" autocomplete="username" placeholder="tu usuario" required>
          </label>

          <label class="login-field">
            <span class="login-field__label">Contraseña</span>
            <span class="login-field__control">
              <input type="password" id="login-password" name="password" autocomplete="current-password" placeholder="••••••••" required>
              <button type="button" class="login-eye" data-login-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </span>
          </label>

          <div class="login-row">
            <label class="login-check">
              <input type="checkbox" name="remember"> <span>Recordarme</span>
            </label>
            <a class="login-link" href="/olvide">¿Olvidaste tu contraseña?</a>
          </div>

          <button type="submit" class="btn-line login-submit" data-magnetic>
            <span>Entrar al panel</span>
            <span class="arrow">↗</span>
          </button>
        </form>

        <p class="login-foot">¿Problemas para entrar? Contacta al administrador.</p>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var toggle = document.querySelector('[data-login-toggle]');
      var input = document.getElementById('login-password');
      if (toggle && input) {
        toggle.addEventListener('click', function () {
          var show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          toggle.classList.toggle('is-on', show);
          var label = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
          toggle.setAttribute('aria-label', label);
          toggle.setAttribute('title', label);
        });
      }
    })();
  </script>
</body>
</html>
