/* Rejilla de ocasiones de catering — sección #catering de la landing */
(function () {
  "use strict";

  /**
   * Previsualización del mensaje de WhatsApp de la rejilla de ocasiones.
   *
   * Los enlaces ya llevan su href completo desde el servidor: esto no habilita
   * nada, sólo enseña de antemano lo que se va a enviar. Por eso escucha
   * también focus/blur —quien recorre con tabulador ve lo mismo que quien pasa
   * el ratón— y por eso no toca el clic: sin JS la rejilla funciona igual.
   *
   * Este archivo fue modules/catering.js y llevaba además el formulario de
   * cotización. Ese flujo se fue entero a WhatsApp, así que lo único que
   * quedaba en pie era la previsualización — y con ella se quedó el nombre.
   */
  function initEventos() {
    var raiz = document.querySelector("[data-eventos]");
    if (!raiz) return;

    var salida = raiz.querySelector("[data-eventos-mensaje]");
    if (!salida) return;

    var guia = salida.innerHTML;

    function mostrar(el) {
      var texto = el && el.getAttribute("data-evento-mensaje");
      if (!texto) return;
      salida.textContent = "“" + texto + "”";
      salida.classList.add("is-activo");
    }

    function limpiar() {
      salida.innerHTML = guia;
      salida.classList.remove("is-activo");
    }

    raiz.addEventListener("mouseover", function (e) {
      var item = e.target.closest("[data-evento-mensaje]");
      if (item) mostrar(item);
    });
    raiz.addEventListener("mouseleave", limpiar);
    raiz.addEventListener("focusin", function (e) {
      var item = e.target.closest("[data-evento-mensaje]");
      if (item) mostrar(item);
    });
    raiz.addEventListener("focusout", limpiar);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initEventos);
  } else {
    initEventos();
  }
})();
