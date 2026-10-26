<?php
  /**
   * Segundo paso del acceso de los roles de sistema: el código de 6 dígitos
   * que llega al correo (Services\Security\DosFactores). Es la misma puerta
   * que el login —misma casa a la izquierda, misma tarjeta, mismas piezas—,
   * así que comparte su hoja (auth/_login.scss) y sus dos parciales.
   *
   * El código es UN solo <input> real (autocompletar de iOS/Android, pegar,
   * lector de pantalla, envío sin JS); las seis celdas son su dibujo, y sólo
   * aparecen cuando el JS de abajo marca [data-listo]. Sin JS queda el campo
   * tal cual, en mono y espaciado.
   *
   * Variables: $alertas, $avisos (listas de textos), $correo (enmascarado o
   * ''), $codigoVisible (sólo pruebas), $vence y $espera (segundos; $espera -1
   * = sin reenvíos) y $errorCodigo.
   */
  require_once __DIR__ . '/../admin/partials/_icons.php';

  $alertas = $alertas ?? [];
  $avisos = $avisos ?? [];
  $correo = (string) ($correo ?? '');
  $codigoVisible = $codigoVisible ?? null;
  $vence = (int) ($vence ?? 0);
  $espera = (int) ($espera ?? 0);
  $errorCodigo = !empty($errorCodigo);
  $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
  $reloj = static fn (int $s): string => intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verificación · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="preload" href="/build/fonts/satoshi-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/expose-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/KudosKapsOneNF.otf" as="font" type="font/otf" crossorigin>
  <link rel="stylesheet" href="<?php echo $h(recursoVersionado('/build/css/operation.css')); ?>">
</head>
<body class="admin-body login-page" data-page="login-verificacion">

  <main class="login-shell">
    <?php include __DIR__ . '/_casa.php'; ?>

    <section class="login-panel" aria-label="Verificación en dos pasos">
      <div class="login-card login-card--verif">
        <?php
        $hcpEtiqueta = 'div';
        $hcpNivel = 'span';
        $hcpHref = '';
        $hcpDosLineas = true;
        $hcpClase = 'login-marca login-marca--tarjeta';
        $hcpAtributos = ['data-login-marca' => '', 'aria-hidden' => 'true'];
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>

        <?php /* El sello ocupa el sitio de las pestañas del login: aquí no hay
                 método que elegir, y el sobre dice adónde mirar antes de leer. */ ?>
        <span class="login-verif__sello" aria-hidden="true"><?php echo admin_icon('correo', 22); ?></span>

        <span class="login-rotulo">Verificación en dos pasos</span>
        <h1 class="login-title">Revisa tu correo</h1>
        <p class="login-sub">
          <?php if ($correo !== '') : ?>
            Enviamos un código de 6 dígitos a <strong class="login-verif__correo"><?php echo $h($correo); ?></strong>.
          <?php else : ?>
            Escribe el código de 6 dígitos para terminar de entrar.
          <?php endif; ?>
        </p>

        <?php foreach ($avisos as $aviso) : ?>
          <p class="login-alert login-alert--aviso" role="status"><?php echo admin_icon('info', 18, 'login-alert__icono'); ?><span><?php echo $h($aviso); ?></span></p>
        <?php endforeach; ?>
        <?php foreach ($alertas as $alerta) : ?>
          <p class="login-alert" role="alert"><?php echo admin_icon('alerta', 18, 'login-alert__icono'); ?><span><?php echo $h($alerta); ?></span></p>
        <?php endforeach; ?>

        <?php if ($codigoVisible !== null) : ?>
          <?php /* ═══ TEMPORAL — RETIRAR EN EL DEPLOY ═══════════════════════
                   Mientras no hay correos de prueba el código se pinta aquí.
                   Se retira este bloque y DosFactores::mostrarCodigo(); de
                   todos modos nunca sale con APP_ENV=production. */ ?>
          <div class="login-prueba" role="note" aria-labelledby="login-prueba-rotulo">
            <span class="login-prueba__rotulo" id="login-prueba-rotulo">Entorno de pruebas</span>
            <button type="button" class="login-prueba__usar" data-otp-usar="<?php echo $h($codigoVisible); ?>">Usar código</button>
            <span class="login-prueba__codigo" aria-label="Código <?php echo $h(implode(' ', str_split((string) $codigoVisible))); ?>">
              <?php echo $h(substr((string) $codigoVisible, 0, 3)); ?><span class="login-prueba__hueco"></span><?php echo $h(substr((string) $codigoVisible, 3)); ?>
            </span>
            <p class="login-prueba__nota">En producción este recuadro no existe: el código llega sólo por correo.</p>
          </div>
        <?php endif; ?>

        <form class="login-form login-form--otp" action="/login/verificacion" method="post" novalidate id="login-otp-form"<?php echo $errorCodigo ? ' data-error' : ''; ?>>
          <div class="login-field">
            <label class="login-field__label" for="login-otp">Código de verificación</label>
            <div class="login-otp" data-otp>
              <input type="text" id="login-otp" name="codigo" class="login-otp__input"
                     inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6"
                     spellcheck="false" autofocus required aria-describedby="login-otp-vence">
              <div class="login-otp__celdas" aria-hidden="true">
                <?php for ($i = 0; $i < 6; $i++) : ?><span class="login-otp__celda"></span><?php endfor; ?>
              </div>
            </div>
            <?php /* Sin aria-live: anunciar cada segundo sería ruido. El JS sólo
                     avisa al lector cuando vence. */ ?>
            <p class="login-otp__vence" id="login-otp-vence" data-otp-vence="<?php echo $vence; ?>">
              <?php echo $vence > 0 ? 'Vence en ' . $reloj($vence) : 'El código venció. Pide uno nuevo.'; ?>
            </p>
          </div>

          <button type="submit" class="admin-btn admin-btn--primary login-submit" id="login-otp-submit">
            <span class="login-submit__texto" data-login-texto>Verificar</span>
            <?php echo admin_icon('flecha-derecha', 18, 'login-submit__icono'); ?>
            <span class="login-submit__giro" aria-hidden="true"></span>
          </button>
        </form>

        <div class="login-verif__pie">
          <form action="/login/verificacion/reenviar" method="post">
            <button type="submit" class="login-enlace" data-otp-reenviar data-espera="<?php echo $espera; ?>"<?php echo $espera < 0 ? ' disabled' : ''; ?>>
              <?php if ($espera < 0) : ?>
                Sin reenvíos disponibles
              <?php else : ?>
                Reenviar código<span class="login-enlace__espera" data-otp-espera><?php echo $espera > 0 ? ' en ' . $reloj($espera) : ''; ?></span>
              <?php endif; ?>
            </button>
          </form>
          <a class="login-enlace" href="/login?tab=admin">Usar otra cuenta</a>
        </div>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var LARGO  = 6;
      var form   = document.getElementById('login-otp-form');
      var input  = document.getElementById('login-otp');
      var caja   = form.querySelector('[data-otp]');
      var celdas = form.querySelectorAll('.login-otp__celda');
      var submit = document.getElementById('login-otp-submit');
      var texto  = form.querySelector('[data-login-texto]');
      var enviando = false;
      var envioPendiente = null;

      // A partir de aquí manda el dibujo de celdas (ver el docblock).
      caja.setAttribute('data-listo', '');

      function pintar() {
        var valor = input.value;
        var enfocado = document.activeElement === input;
        var activa = Math.min(valor.length, LARGO - 1);
        for (var i = 0; i < celdas.length; i++) {
          celdas[i].textContent = valor.charAt(i);
          celdas[i].classList.toggle('is-llena', i < valor.length);
          celdas[i].classList.toggle('is-activa', enfocado && !enviando && i === activa);
        }
        submit.disabled = valor.length < LARGO;
      }

      function enviar() {
        if (enviando || input.value.length < LARGO) return;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          marcarEnvio();
          form.submit();
        }
      }

      function marcarEnvio() {
        enviando = true;
        form.classList.add('is-enviando');
        form.setAttribute('aria-busy', 'true');
        if (texto) texto.textContent = 'Verificando…';
        pintar();
      }

      /*
       * Con el sexto dígito se envía solo, como el NIP: quien pega el código
       * del correo no tiene que buscar el botón. La pausa deja ver la última
       * celda llena antes de que cambie la pantalla.
       */
      function alEscribir() {
        if (enviando) return;
        input.value = input.value.replace(/\D/g, '').slice(0, LARGO);
        form.removeAttribute('data-error');
        pintar();
        window.clearTimeout(envioPendiente);
        if (input.value.length === LARGO) envioPendiente = window.setTimeout(enviar, 160);
      }

      input.addEventListener('input', alEscribir);
      input.addEventListener('focus', pintar);
      input.addEventListener('blur', pintar);

      form.addEventListener('submit', function (e) {
        if (enviando || input.value.length < LARGO) {
          e.preventDefault();
          return;
        }
        window.clearTimeout(envioPendiente);
        marcarEnvio();
      });

      // TEMPORAL (pruebas): rellena el campo con el código del recuadro.
      var usar = document.querySelector('[data-otp-usar]');
      if (usar) {
        usar.addEventListener('click', function () {
          input.value = usar.getAttribute('data-otp-usar') || '';
          input.focus();
          alEscribir();
        });
      }

      // ── Relojes: vigencia del código y espera del reenvío ──────
      // Contra Date.now() y no restando de uno en uno: una pestaña en segundo
      // plano estira los intervalos y la cuenta se atrasaría.
      var inicio = Date.now();
      var vence = document.querySelector('[data-otp-vence]');
      var reenviar = document.querySelector('[data-otp-reenviar]');
      var esperaTexto = document.querySelector('[data-otp-espera]');
      var segVence = parseInt(vence.getAttribute('data-otp-vence'), 10) || 0;
      var segEspera = parseInt(reenviar.getAttribute('data-espera'), 10) || 0;

      function reloj(s) {
        var seg = s % 60;
        return Math.floor(s / 60) + ':' + (seg < 10 ? '0' : '') + seg;
      }

      function tic() {
        var pasados = Math.floor((Date.now() - inicio) / 1000);
        var quedan = Math.max(0, segVence - pasados);
        vence.classList.toggle('is-pronto', quedan > 0 && quedan <= 60);
        if (quedan > 0) {
          vence.textContent = 'Vence en ' + reloj(quedan);
        } else if (!vence.classList.contains('is-vencido')) {
          vence.classList.add('is-vencido');
          vence.setAttribute('role', 'alert');
          vence.textContent = 'El código venció. Pide uno nuevo.';
        }

        if (segEspera > 0 && esperaTexto) {
          var falta = Math.max(0, segEspera - pasados);
          esperaTexto.textContent = falta > 0 ? ' en ' + reloj(falta) : '';
          reenviar.disabled = falta > 0;
        }
      }
      tic();
      var reloj1 = window.setInterval(tic, 1000);

      /*
       * Volver con el botón atrás saca la página de la bfcache tal como se
       * fue: con «Verificando…» y el código escrito. Se limpia al irse y al
       * volver; los relojes de la copia guardada ya no valen, así que se
       * recarga para pedir al servidor los de verdad.
       */
      window.addEventListener('pagehide', function () { input.value = ''; });
      window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
          window.clearInterval(reloj1);
          location.reload();
        }
      });

      pintar();
    })();
  </script>
  <?php include __DIR__ . '/_salida-marca.php'; ?>
</body>
</html>
