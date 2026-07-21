<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acceso administrador · Casa Pestalozzi</title>
  <meta name="robots" content="noindex, nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="login-page" data-page="login-admin">

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
        <span class="login-eyebrow eyebrow no-rule">Acceso administrador</span>
        <h1 class="login-title">Inicia sesión</h1>
        <p class="login-sub">Ingresa con tu usuario y contraseña para entrar al panel de administración.</p>

        <?php foreach (($alertas['error'] ?? []) as $error) : ?>
          <p class="login-alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endforeach; ?>

        <form class="login-form" action="/admin/login" method="post" novalidate>
          <div class="login-field">
            <label class="login-field__label" for="login-username">Usuario</label>
            <input type="text" id="login-username" name="username"
                   autocomplete="username" autocapitalize="off" spellcheck="false"
                   value="<?php echo htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   autofocus>
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

          <button type="submit" class="btn-line login-submit">
            <span>Entrar</span>
            <span class="arrow">↗</span>
          </button>
        </form>

        <p class="login-foot">¿Eres mesero o cajero? <a class="login-link" href="/login">Entra con tu NIP</a></p>
      </div>
    </section>
  </main>

  <script>
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
