<?php
  /* Lo comparten el login y el segundo paso: las dos pantallas llevan el
     nombre de la casa que viaja a la de destino. */
?>
  <script>
    /*
     * La llegada, primer tiempo: el nombre de la casa viaja de aquí al centro
     * de la pantalla de destino (views/components/llegada.php), que lleva su
     * marca con el mismo `view-transition-name`. Se nombra SÓLO al salir hacia
     * otra pantalla: un NIP equivocado vuelve a este mismo login, y ahí el
     * nombre no tiene adónde ir; tampoco al pasar de la contraseña al código
     * (/login/verificacion), que es la misma puerta. Sin `activation` (navegadores sin la API de
     * navegación) no se adivina el destino: la marca del velo entra fundiéndose.
     */
    (function () {
      var marcas = document.querySelectorAll('[data-login-marca]');

      function limpiar() {
        for (var i = 0; i < marcas.length; i++) marcas[i].style.viewTransitionName = '';
      }

      window.addEventListener('pageswap', function (e) {
        var entrada = e.activation && e.activation.entry;
        if (!e.viewTransition || !entrada || !entrada.url) return;
        var destino;
        try { destino = new URL(entrada.url, location.href); } catch (error) { return; }
        if (destino.origin !== location.origin || /^\/(admin\/)?login(\/verificacion)?\/?$/.test(destino.pathname)) return;

        for (var i = 0; i < marcas.length; i++) {
          // La que esté a la vista: la de la columna de la casa o la de la
          // tarjeta, según el ancho.
          if (marcas[i].getClientRects().length) {
            marcas[i].style.viewTransitionName = 'cp-marca';
            break;
          }
        }
        e.viewTransition.finished.then(limpiar, limpiar);
      });

      window.addEventListener('pageshow', limpiar);
    })();
  </script>
