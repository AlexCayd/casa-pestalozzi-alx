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
   *
   * La pantalla es del sistema ADMINISTRATIVO (operation.css, oscuro forzado,
   * puente de tokens públicos): Expose en el titular, mono en los rótulos,
   * Satoshi en el cuerpo y KudosKaps sólo en el nombre de la casa. Ese nombre
   * es el protagonista y no por adorno: al entrar viaja hasta la marca de la
   * pantalla de destino (views/components/llegada.php), así que tiene que
   * estar aquí, grande y apilado como allí.
   */
  require_once __DIR__ . '/../admin/partials/_icons.php';

  $tabActiva    = $tabActiva ?? 'nip';
  $alertas      = $alertas ?? [];
  $alertasAdmin = $alertasAdmin ?? [];
  $errorNip     = !empty($alertas['error']);
  $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

  // El icono acompaña al color del aviso: el tipo se reconoce antes de leer
  // el texto. Del catálogo, no dibujado aquí.
  $iconoAlerta = static fn (string $tipo): string
      => admin_icon($tipo === 'aviso' ? 'info' : 'alerta', 18, 'login-alert__icono');
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
  <?php /* Fuentes locales: el piso funciona sin red. Las cuatro se ven en la
           primera pintura —el nombre, el titular, los rótulos y el cuerpo—,
           y KudosKaps además tiene que estar en caché para la llegada. */ ?>
  <link rel="preload" href="/build/fonts/satoshi-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/expose-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/KudosKapsOneNF.otf" as="font" type="font/otf" crossorigin>
  <link rel="stylesheet" href="<?php echo $h(recursoVersionado('/build/css/operation.css')); ?>">
</head>
<body class="admin-body login-page" data-page="login">

  <main class="login-shell">
    <?php include __DIR__ . '/_casa.php'; ?>

    <section class="login-panel" aria-label="Iniciar sesión">
      <div class="login-card">
        <?php /* El mismo nombre para cuando la columna de la casa no cabe
                 (≤ 860 px: tablet en vertical y teléfono). Uno u otro, nunca
                 los dos: el CSS oculta el que sobra. */ ?>
        <?php
        $hcpEtiqueta = 'div';
        $hcpNivel = 'span';
        $hcpHref = '';
        $hcpDosLineas = true;
        $hcpClase = 'login-marca login-marca--tarjeta';
        $hcpAtributos = ['data-login-marca' => '', 'aria-hidden' => 'true'];
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>

        <div class="login-tabs" role="tablist" aria-label="Tipo de acceso"
             data-active="<?php echo $tabActiva === 'admin' ? 'admin' : 'nip'; ?>">
          <?php /* La píldora es el relleno de la pestaña activa como pieza que
                   se desliza; es decoración pura —el estado lo dice
                   aria-selected en cada botón—, y el data-active de arriba lo
                   emite PHP para que su posición esté pintada antes de que
                   corra una línea de JS. */ ?>
          <span class="login-tabs__pill" aria-hidden="true"></span>
          <?php /* tabindex itinerante (patrón de pestañas ARIA): sólo la activa
                   entra en el Tab; entre ellas se pasa con las flechas. */ ?>
          <button type="button"
                  class="login-tab<?php echo $tabActiva === 'nip' ? ' is-active' : ''; ?>"
                  role="tab" id="login-tab-btn-nip"
                  aria-controls="login-tab-nip"
                  aria-selected="<?php echo $tabActiva === 'nip' ? 'true' : 'false'; ?>"
                  tabindex="<?php echo $tabActiva === 'nip' ? '0' : '-1'; ?>"
                  data-login-tab="nip">
            NIP
          </button>
          <button type="button"
                  class="login-tab<?php echo $tabActiva === 'admin' ? ' is-active' : ''; ?>"
                  role="tab" id="login-tab-btn-admin"
                  aria-controls="login-tab-admin"
                  aria-selected="<?php echo $tabActiva === 'admin' ? 'true' : 'false'; ?>"
                  tabindex="<?php echo $tabActiva === 'admin' ? '0' : '-1'; ?>"
                  data-login-tab="admin">
            Contraseña
          </button>
        </div>

        <?php /* Los dos paneles se apilan en la misma celda para que la tarjeta
                 no cambie de altura al conmutar y el fundido cruzado sea
                 posible (ver .login-card__panels). */ ?>
        <div class="login-card__panels">

        <!-- ── Personal de piso: NIP de 4 dígitos ─────────────── -->
        <div class="login-tabpanel<?php echo $tabActiva === 'nip' ? ' is-active' : ''; ?>" id="login-tab-nip" role="tabpanel"
             aria-labelledby="login-tab-btn-nip"
             <?php echo $tabActiva === 'nip' ? '' : 'hidden'; ?>>
          <?php /* Rótulo (quién) y titular (qué hacer): la pestaña dice el
                   MÉTODO, el rótulo dice a quién es esta puerta. */ ?>
          <span class="login-rotulo">Personal de piso</span>
          <h1 class="login-title">Ingresa tu NIP</h1>
          <?php /* Con un error en pantalla el subtítulo sobra —el aviso es lo
                   que hay que leer— y cederle su sitio es lo que deja caber el
                   aviso sin que el teclado empuje la tarjeta: la pestaña del
                   NIP no puede desplazarse. */ ?>
          <?php if (!$errorNip) : ?>
          <p class="login-sub">Cuatro dígitos y entras directo a tu área de trabajo.</p>
          <?php endif; ?>

          <?php foreach (($alertas['error'] ?? []) as $error) : ?>
            <p class="login-alert" role="alert"><?php echo $iconoAlerta('error'); ?><span><?php echo $h($error); ?></span></p>
          <?php endforeach; ?>

          <form class="login-form login-form--nip" action="/login" method="post" novalidate id="login-nip-form"<?php echo $errorNip ? ' data-error' : ''; ?>>
            <input type="hidden" name="login_csrf" value="<?php echo htmlspecialchars(\Services\Security\AdminCsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="password" name="nip" id="login-nip" class="login-nip-hidden"
                   inputmode="numeric" autocomplete="one-time-code" pattern="\d{4}" maxlength="4"
                   aria-label="NIP de acceso" aria-describedby="login-nip-estado">

            <?php /* Los puntos no se anuncian (aria-hidden): para el lector, el
                   progreso lo dice esta región viva, «2 de 4 dígitos». */ ?>
            <p class="login-sr" id="login-nip-estado" aria-live="polite"></p>

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
              <?php /* SVG y no el glifo ⌫: lo dibuja la fuente del sistema, así
                       que no hereda currentColor, cambia de forma entre Windows,
                       Android e iOS —y la tablet del piso no es siempre la
                       misma— y se sale de la caja tipográfica de la tecla. */ ?>
              <button type="button" class="login-pad__key login-pad__key--aux" data-action="back" aria-label="Borrar último dígito">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6H9.5a2 2 0 0 0-1.5.7L3 12l5 5.3a2 2 0 0 0 1.5.7H20a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1Z"/><path d="M12.5 9.5 17 14"/><path d="M17 9.5 12.5 14"/></svg>
              </button>
            </div>

            <button type="submit" class="admin-btn admin-btn--primary login-submit" id="login-nip-submit" disabled>
              <span class="login-submit__texto" data-login-texto>Entrar</span>
              <?php echo admin_icon('flecha-derecha', 18, 'login-submit__icono'); ?>
              <span class="login-submit__giro" aria-hidden="true"></span>
            </button>
          </form>

          <p class="login-foot">¿Sin NIP o lo olvidaste? Pídele al administrador que te asigne uno.</p>
        </div>

        <!-- ── Administración: usuario + contraseña ───────────── -->
        <div class="login-tabpanel<?php echo $tabActiva === 'admin' ? ' is-active' : ''; ?>" id="login-tab-admin" role="tabpanel"
             aria-labelledby="login-tab-btn-admin"
             <?php echo $tabActiva === 'admin' ? '' : 'hidden'; ?>>
          <span class="login-rotulo">Administración</span>
          <h1 class="login-title">Inicia sesión</h1>
          <p class="login-sub">Con tu usuario y contraseña del panel.</p>

          <?php foreach (($alertasAdmin['aviso'] ?? []) as $aviso) : ?>
            <p class="login-alert login-alert--aviso" role="status"><?php echo $iconoAlerta('aviso'); ?><span><?php echo $h($aviso); ?></span></p>
          <?php endforeach; ?>
          <?php foreach (($alertasAdmin['error'] ?? []) as $error) : ?>
            <p class="login-alert" role="alert"><?php echo $iconoAlerta('error'); ?><span><?php echo $h($error); ?></span></p>
          <?php endforeach; ?>

          <form class="login-form" action="/admin/login" method="post" novalidate id="login-admin-form">
            <input type="hidden" name="login_csrf" value="<?php echo htmlspecialchars(\Services\Security\AdminCsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
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
                <button type="button" class="login-eye" id="login-eye" aria-label="Mostrar contraseña" aria-pressed="false">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </span>
            </div>

            <button type="submit" class="admin-btn admin-btn--primary login-submit">
              <span class="login-submit__texto" data-login-texto>Entrar</span>
              <?php echo admin_icon('flecha-derecha', 18, 'login-submit__icono'); ?>
              <span class="login-submit__giro" aria-hidden="true"></span>
            </button>
          </form>
        </div>

        </div><?php /* fin .login-card__panels */ ?>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var TAB_KEY = 'cp-login-tab';
      var tabs    = document.querySelectorAll('[data-login-tab]');
      var tablist = document.querySelector('.login-tabs');
      var vieneDePost = <?php echo $_SERVER['REQUEST_METHOD'] === 'POST' ? 'true' : 'false'; ?>;

      function activar(clave, enfocar) {
        for (var i = 0; i < tabs.length; i++) {
          var boton  = tabs[i];
          var activa = boton.dataset.loginTab === clave;
          var panel  = document.getElementById('login-tab-' + boton.dataset.loginTab);

          boton.classList.toggle('is-active', activa);
          boton.setAttribute('aria-selected', activa ? 'true' : 'false');
          boton.setAttribute('tabindex', activa ? '0' : '-1');

          if (!panel) continue;
          /*
           * El [hidden] sólo describía el estado ANTES de que corriera esto; a
           * partir de aquí manda la clase, y no se repone nunca.
           * `.login-tabpanel[hidden]` ya es `display: block` (apilados en la
           * misma celda); de sacarlo del foco y del lector se encarga la
           * `visibility` del CSS.
           */
          panel.hidden = false;
          panel.classList.toggle('is-active', activa);
        }

        // Mueve la píldora del conmutador.
        if (tablist) tablist.dataset.active = clave;

        try { sessionStorage.setItem(TAB_KEY, clave); } catch (e) {}

        if (enfocar) {
          var foco = clave === 'nip'
            ? document.getElementById('login-nip')
            : document.getElementById('login-username');
          if (foco) foco.focus();
        }
      }

      for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function (e) {
          /*
           * El foco sólo se mueve al campo si la pestaña se activó con teclado
           * (detail === 0). Con el dedo, enfocar el campo oculto del NIP le
           * abría a la tablet su teclado del sistema encima del nuestro; y el
           * de usuario, a quien sólo quería mirar la otra pestaña.
           */
          activar(this.dataset.loginTab, e.detail === 0);
        });
      }

      // Flechas, Inicio y Fin dentro del grupo: el patrón de pestañas ARIA.
      if (tablist) {
        tablist.addEventListener('keydown', function (e) {
          var claves = ['nip', 'admin'];
          var actual = tablist.dataset.active === 'admin' ? 1 : 0;
          var siguiente = null;
          if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') siguiente = 1 - actual;
          else if (e.key === 'Home') siguiente = 0;
          else if (e.key === 'End') siguiente = 1;
          if (siguiente === null) return;
          e.preventDefault();
          activar(claves[siguiente], false);
          document.getElementById('login-tab-btn-' + claves[siguiente]).focus();
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

    // ── Estado «entrando…» de los dos formularios ───────────
    // El login es un POST normal: entre el envío y la respuesta la pantalla no
    // decía nada, y en la tablet se volvía a pulsar. El texto cambia y el botón
    // gira; no se deshabilita nada ANTES del envío, que lo cancelaría.
    function loginMarcarEnvio(form) {
      form.classList.add('is-enviando');
      form.setAttribute('aria-busy', 'true');
      var texto = form.querySelector('[data-login-texto]');
      if (texto) texto.textContent = 'Entrando…';
    }

    function loginRestaurar(form) {
      form.classList.remove('is-enviando');
      form.removeAttribute('aria-busy');
      var texto = form.querySelector('[data-login-texto]');
      if (texto) texto.textContent = 'Entrar';
    }

    // ── Teclado numérico del NIP ────────────────────────────
    (function () {
      var LARGO  = 4;
      var form   = document.getElementById('login-nip-form');
      var input  = document.getElementById('login-nip');
      var dots   = document.querySelectorAll('#login-nip-dots .login-nip-dot');
      var pad    = document.getElementById('login-pad');
      var submit = document.getElementById('login-nip-submit');
      var panel  = document.getElementById('login-tab-nip');
      var estado = document.getElementById('login-nip-estado');
      var enviando = false;
      var envioPendiente = null;

      function pintar(anunciar) {
        var n = input.value.length;
        for (var i = 0; i < dots.length; i++) {
          dots[i].classList.toggle('is-on', i < n);
        }
        submit.disabled = n < LARGO;
        if (anunciar && estado) {
          estado.textContent = n === 0 ? 'NIP borrado' : n + ' de ' + LARGO + ' dígitos';
        }
      }

      function enviar() {
        if (enviando || input.value.length < LARGO) return;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          // Sin requestSubmit el evento `submit` no se dispara: se marca aquí.
          enviando = true;
          loginMarcarEnvio(form);
          form.submit();
        }
      }

      /*
       * Con el cuarto dígito se entra solo. Es el gesto de cualquier teclado de
       * PIN, y en el piso se repite decenas de veces por turno: un toque menos
       * cada vez. La pausa de 160 ms deja ver el cuarto punto lleno antes de
       * que la pantalla cambie; un NIP equivocado vuelve con su aviso.
       */
      function teclear(digito, accion) {
        if (enviando) return;
        if (digito !== undefined && digito !== null) {
          if (input.value.length < LARGO) input.value += digito;
        } else if (accion === 'back') {
          input.value = input.value.slice(0, -1);
        } else if (accion === 'clear') {
          input.value = '';
        }
        pintar(true);
        window.clearTimeout(envioPendiente);
        if (input.value.length === LARGO) envioPendiente = window.setTimeout(enviar, 160);
      }

      // Acuse de la tecla física sobre la tecla dibujada.
      function pulsar(selector) {
        var tecla = pad.querySelector(selector);
        if (!tecla) return;
        tecla.classList.add('is-pulsada');
        window.setTimeout(function () { tecla.classList.remove('is-pulsada'); }, 140);
      }

      /*
       * Ya no se devuelve el foco al campo oculto en cada toque. En una tablet,
       * enfocar un campo `inputmode=numeric` dentro de un gesto ABRE el teclado
       * del sistema, que tapaba justo el teclado de la pantalla. El teclado
       * físico se atiende abajo, esté donde esté el foco.
       */
      pad.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        teclear(btn.dataset.key, btn.dataset.action);
      });

      // Quien escribe en el campo (lector de pantalla, gestor de contraseñas).
      input.addEventListener('input', function () {
        if (enviando) return;
        input.value = input.value.replace(/\D/g, '').slice(0, LARGO);
        pintar(true);
        window.clearTimeout(envioPendiente);
        if (input.value.length === LARGO) envioPendiente = window.setTimeout(enviar, 160);
      });

      // Teclado físico con el foco en cualquier sitio de la pestaña del NIP
      // que no sea un campo de texto: cifras, retroceso, Supr/Esc y Entrar.
      document.addEventListener('keydown', function (e) {
        if (!panel.classList.contains('is-active') || e.ctrlKey || e.metaKey || e.altKey) return;
        var destino = e.target;
        if (destino === input) {
          if (e.key === 'Escape') { teclear(null, 'clear'); }
          return; // las cifras las recoge el evento `input`
        }
        if (destino && destino.closest && destino.closest('input, textarea, select, [contenteditable="true"]')) return;

        if (/^[0-9]$/.test(e.key)) {
          e.preventDefault();
          pulsar('[data-key="' + e.key + '"]');
          teclear(e.key);
        } else if (e.key === 'Backspace') {
          e.preventDefault();
          pulsar('[data-action="back"]');
          teclear(null, 'back');
        } else if (e.key === 'Escape' || e.key === 'Delete') {
          pulsar('[data-action="clear"]');
          teclear(null, 'clear');
        } else if (e.key === 'Enter' && input.value.length === LARGO
          && !(destino && destino.closest && destino.closest('button'))) {
          e.preventDefault();
          enviar();
        }
      });

      form.addEventListener('submit', function (e) {
        if (enviando || input.value.length < LARGO) {
          e.preventDefault();
          return;
        }
        enviando = true;
        window.clearTimeout(envioPendiente);
        loginMarcarEnvio(form);
      });

      /*
       * Volver con el botón atrás saca la página de la bfcache TAL COMO SE
       * FUE: con «Entrando…» girando y el NIP escrito en el campo. Se limpia
       * al irse (para que la copia guardada no lo lleve) y al volver.
       */
      function reiniciar() {
        enviando = false;
        window.clearTimeout(envioPendiente);
        input.value = '';
        loginRestaurar(form);
        pintar(false);
      }
      window.addEventListener('pagehide', function () { input.value = ''; });
      window.addEventListener('pageshow', function (e) { if (e.persisted) reiniciar(); });

      pintar(false);
    })();

    // ── Contraseña: mostrar/ocultar y envío ─────────────────
    (function () {
      var form  = document.getElementById('login-admin-form');
      var input = document.getElementById('login-password');
      var eye   = document.getElementById('login-eye');
      var user  = document.getElementById('login-username');

      eye.addEventListener('click', function () {
        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        eye.classList.toggle('is-on', !visible);
        eye.setAttribute('aria-pressed', visible ? 'false' : 'true');
        eye.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        input.focus();
      });

      // Con un campo vacío el servidor responde con su aviso; el «Entrando…»
      // sólo cuando de verdad va a intentarlo.
      form.addEventListener('submit', function () {
        if (user.value.trim() !== '' && input.value !== '') loginMarcarEnvio(form);
      });

      window.addEventListener('pagehide', function () { input.value = ''; });
      window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        loginRestaurar(form);
        input.type = 'password';
        eye.classList.remove('is-on');
        eye.setAttribute('aria-pressed', 'false');
        eye.setAttribute('aria-label', 'Mostrar contraseña');
      });
    })();
  </script>
  <?php include __DIR__ . '/_salida-marca.php'; ?>
</body>
</html>
