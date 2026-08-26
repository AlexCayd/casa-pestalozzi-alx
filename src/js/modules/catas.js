/* Inscripción a catas — sección #catas de la landing */
(function () {
  "use strict";

  var ENDPOINT = "/api/catas/inscribir";

  var ETIQUETAS_CONTACTO = {
    email: { texto: "Correo electrónico", placeholder: "tu@correo.com", autocomplete: "email" },
    telefono: { texto: "Teléfono", placeholder: "+52 55 1234 5678", autocomplete: "tel" }
  };

  function initCatas() {
    var panel = document.querySelector("[data-cata-panel]");
    var form = document.querySelector("[data-cata-form]");
    if (!panel || !form) return;

    var campoId = form.querySelector("[data-cata-form-id]");
    var titulo = form.querySelector("[data-cata-form-titulo]");
    var personas = form.querySelector("[data-cata-personas]");
    var aviso = form.querySelector("[data-cata-aviso]");
    var enviar = form.querySelector("[data-cata-enviar]");
    var tipoContacto = form.querySelector("[data-cata-contacto-tipo]");
    var campoContacto = form.querySelector("[data-cata-contacto]");
    var etiquetaContacto = form.querySelector("[data-cata-contacto-label]");

    function mensaje(texto, estado) {
      if (!aviso) return;
      aviso.textContent = texto || "";
      aviso.classList.toggle("is-error", estado === "error");
      aviso.classList.toggle("is-ok", estado === "ok");
    }

    function abrir(boton) {
      var max = parseInt(boton.getAttribute("data-cata-max"), 10);

      if (campoId) campoId.value = boton.getAttribute("data-cata-id") || "";
      if (titulo) titulo.textContent = boton.getAttribute("data-cata-titulo") || "Reserva tu lugar";

      // El máximo de personas lo marca el cupo restante de esa cata concreta,
      // no el tope general del formulario.
      if (personas && !isNaN(max) && max > 0) {
        personas.max = String(Math.min(max, 10));
        if (parseInt(personas.value, 10) > max) personas.value = String(max);
      }

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

    // El tipo de contacto cambia la etiqueta y el placeholder: el mismo campo
    // pide un correo o un teléfono según lo elegido.
    function sincronizarContacto() {
      if (!tipoContacto || !campoContacto) return;
      var conf = ETIQUETAS_CONTACTO[tipoContacto.value] || ETIQUETAS_CONTACTO.email;
      if (etiquetaContacto) etiquetaContacto.textContent = conf.texto;
      campoContacto.placeholder = conf.placeholder;
      campoContacto.setAttribute("autocomplete", conf.autocomplete);
    }

    document.querySelectorAll("[data-cata-inscribir]").forEach(function (boton) {
      boton.addEventListener("click", function () { abrir(boton); });
    });

    var cerrarBtn = form.querySelector("[data-cata-cerrar]");
    if (cerrarBtn) cerrarBtn.addEventListener("click", cerrar);

    if (tipoContacto) {
      tipoContacto.addEventListener("change", sincronizarContacto);
      sincronizarContacto();
    }

    form.addEventListener("submit", function (evento) {
      evento.preventDefault();

      if (!campoId || !campoId.value) {
        mensaje("Elige primero una cata de la agenda.", "error");
        return;
      }

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
          // Siempre hay texto de respaldo: un `mensaje` vacío del servidor no
          // debe dejar el aviso en blanco (ver CLAUDE.md).
          var texto = json && json.mensaje;

          if (json && json.ok) {
            mensaje(texto || "Listo, tu lugar quedó apartado.", "ok");
            form.reset();
            sincronizarContacto();
            if (window.AppNotice) {
              window.AppNotice.success(texto || "Inscripción registrada.");
            }
            // La agenda que se ve en pantalla ya no refleja el cupo real.
            window.setTimeout(function () { window.location.reload(); }, 2200);
            return;
          }

          var error = texto || "No pudimos registrar tu inscripción. Inténtalo de nuevo.";
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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCatas);
  } else {
    initCatas();
  }
})();
