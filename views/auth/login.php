<?php
  /**
   * Acceso al panel de administración: usuario + contraseña. El formulario
   * envía a /admin/login.
   */
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
  <link rel="stylesheet" href="<?php echo $h(recursoVersionado('/build/css/operation.css')); ?>">
</head>
<body class="admin-body login-page" data-page="login">

  <main class="login-shell">
    <div class="login-aside" aria-hidden="true">
      <div class="login-aside__overlay"></div>
      <div class="login-aside__content">
        <span class="admin-eyebrow">Panel de administración</span>
        <p class="login-aside__quote">Cocina mediterránea con corazón mexicano.</p>
        <?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
                 Sin enlace: este panel es aria-hidden, y un <a> dentro quedaría
                 oculto al lector pero seguiría en el recorrido de tabulación. */ ?>
        <?php
        $hcpEtiqueta = 'div';
        $hcpNivel = 'span';
        $hcpHref = '';
        $hcpClase = 'login-aside__sign';
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>
      </div>
    </div>

    <section class="login-panel">
      <div class="login-card">
        <?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
                 Nivel <p> por omisión: el <h1> de esta pantalla es el título del
                 formulario. El resto también son valores por omisión
                 —contenedor <header>, enlace a la portada—. */ ?>
        <?php
        $hcpClase = 'login-brand';
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>

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
    </section>
  </main>

  <script>
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
