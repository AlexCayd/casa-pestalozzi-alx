/* Cotización de catering — sección #catering de la landing */
(function () {
  "use strict";

  var ENDPOINT = "/api/catering/cotizar";

  var ETIQUETAS_CONTACTO = {
    email: { texto: "Correo electrónico", placeholder: "tu@correo.com", autocomplete: "email" },
    telefono: { texto: "Teléfono", placeholder: "+52 55 1234 5678", autocomplete: "tel" }
  };

  function initCatering() {
    var panel = document.querySelector("[data-catering-panel]");
    var form = document.querySelector("[data-catering-form]");
    if (!panel || !form) return;

    var aviso = form.querySelector("[data-catering-aviso]");
    var enviar = form.querySelector("[data-catering-enviar]");
    var tipoContacto = form.querySelector("[data-catering-contacto-tipo]");
    var campoContacto = form.querySelector("[data-catering-contacto]");
    var etiquetaContacto = form.querySelector("[data-catering-contacto-label]");

    function mensaje(texto, estado) {
      if (!aviso) return;
      aviso.textContent = texto || "";
      aviso.classList.toggle("is-error", estado === "error");
      aviso.classList.toggle("is-ok", estado === "ok");
    }

    function abrir() {
      mensaje("", null);
      panel.hidden = false;
      panel.scrollIntoView({ behavior: "smooth", block: "center" });

      var primero = form.querySelector('input[name="nombre"]');
      if (primero) window.setTimeout(function () { primero.focus(); }, 350);
    }

    function cerrar() {
      panel.hidden = true;
      mensaje("", null);
    }

    function sincronizarContacto() {
      if (!tipoContacto || !campoContacto) return;
      var conf = ETIQUETAS_CONTACTO[tipoContacto.value] || ETIQUETAS_CONTACTO.email;
      if (etiquetaContacto) etiquetaContacto.textContent = conf.texto;
      campoContacto.placeholder = conf.placeholder;
      campoContacto.setAttribute("autocomplete", conf.autocomplete);
    }

    // Hay dos disparadores: el del encabezado y el del bloque de testimonios.
    document.querySelectorAll("[data-catering-abrir]").forEach(function (boton) {
      boton.addEventListener("click", abrir);
    });

    var cerrarBtn = form.querySelector("[data-catering-cerrar]");
    if (cerrarBtn) cerrarBtn.addEventListener("click", cerrar);

    if (tipoContacto) {
      tipoContacto.addEventListener("change", sincronizarContacto);
      sincronizarContacto();
    }

    form.addEventListener("submit", function (evento) {
      evento.preventDefault();

      var datos = {};
      new FormData(form).forEach(function (valor, clave) { datos[clave] = valor; });

      if (enviar) enviar.disabled = true;
      mensaje("Enviando…", null);

      fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos)
      })
        .then(function (respuesta) {
          return respuesta.json().catch(function () { return {}; });
        })
        .then(function (json) {
          var texto = json && json.mensaje;

          if (json && json.ok) {
            var exito = texto || "Recibimos tu solicitud. Te contactamos pronto.";
            mensaje(exito, "ok");
            form.reset();
            sincronizarContacto();
            if (window.AppNotice) window.AppNotice.success(exito);
            return;
          }

          var error = texto || "No pudimos enviar tu solicitud. Inténtalo de nuevo.";
          mensaje(error, "error");
          if (window.AppNotice) window.AppNotice.error(error);
        })
        .catch(function () {
          var error = "No hay conexión con el servidor. Inténtalo en un momento.";
          mensaje(error, "error");
          if (window.AppNotice) window.AppNotice.error(error);
        })
        .then(function () {
          if (enviar) enviar.disabled = false;
        });
    });
  }

  /**
   * Previsualización del mensaje de WhatsApp de la rejilla de eventos.
   *
   * Los enlaces ya llevan su href completo desde el servidor: esto no habilita
   * nada, sólo enseña de antemano lo que se va a enviar. Por eso escucha
   * también focus/blur —quien recorre con tabulador ve lo mismo que quien pasa
   * el ratón— y por eso no toca el clic: sin JS la rejilla funciona igual.
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

  function init() {
    initCatering();
    initEventos();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
