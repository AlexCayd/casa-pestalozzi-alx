/**
 * Casos de reservaciones: las dos acciones que se resuelven sin salir de la
 * lista —registrar que no llegó y marcar revisada una reubicación—.
 *
 * Las demás acciones de la tarjeta (abrir en el POS, asignar mesas, ver
 * detalle) son enlaces normales y no pasan por aquí.
 *
 * Tras una acción la lista se repinta pidiendo la MISMA URL con
 * X-Requested-With: el controlador devuelve sólo el bloque de resultados. Es
 * lo que deja que el aviso de «hecho» siga en pantalla, que con una recarga
 * completa se perdería; si el repintado falla, se recarga entera, que es lo
 * robusto.
 *
 * Los clics se escuchan delegados en la sección: el bloque se reemplaza tras
 * cada acción y los botones de antes dejan de existir.
 */
(function () {
  "use strict";

  var NO_SHOW_URL = "/admin/api/reservaciones/estado";
  var REVISAR_URL = "/admin/api/reservaciones/reubicaciones/revisar";

  function aviso(texto, variante) {
    if (window.AppNotice && typeof window.AppNotice.show === "function") {
      window.AppNotice.show({ text: texto, variant: variante || "info" });
    }
  }

  function mensajeDe(datos, respaldo) {
    var texto = datos && (datos.mensaje || datos.message);
    // Un mensaje vacío del servidor no debe producir una caja en blanco.
    return texto && String(texto).trim() !== "" ? String(texto) : respaldo;
  }

  function init(seccion) {
    var csrf = seccion.getAttribute("data-admin-csrf") || "";
    var resultados = seccion.querySelector("[data-casos-resultados]");
    if (!resultados) return;

    /*
     * apiEstado lee $_POST, así que el no-show viaja como formulario y no como
     * JSON. La revisión acepta los dos; va igual para no tener dos caminos.
     */
    function enviar(url, campos) {
      var cuerpo = new URLSearchParams();
      Object.keys(campos).forEach(function (clave) {
        cuerpo.set(clave, String(campos[clave]));
      });
      cuerpo.set("admin_csrf", csrf);

      return fetch(url, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-CSRF-Token": csrf
        },
        body: cuerpo
      }).then(function (respuesta) {
        return respuesta.json().catch(function () { return {}; }).then(function (datos) {
          if (!respuesta.ok || datos.ok === false) {
            var error = new Error(mensajeDe(datos, "No fue posible completar la acción."));
            error.datos = datos;
            throw error;
          }
          return datos;
        });
      });
    }

    function repintar() {
      resultados.setAttribute("aria-busy", "true");
      return fetch(window.location.href, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "text/html" }
      }).then(function (respuesta) {
        if (!respuesta.ok) throw new Error("repintado");
        return respuesta.text();
      }).then(function (html) {
        resultados.innerHTML = html;
        resultados.setAttribute("aria-busy", "false");
        // El botón pulsado ya no existe y el foco caería al <body>: se deja en
        // el conteo, que dice cuántos quedan, y el teclado sigue desde la lista.
        var conteo = resultados.querySelector(".estrategia-resultados__conteo");
        if (conteo) conteo.focus({ preventScroll: true });
        document.dispatchEvent(new CustomEvent("admin:reactive-updated", { detail: { target: resultados } }));
      }).catch(function () {
        window.location.reload();
      });
    }

    function registrarNoShow(boton) {
      var id = boton.getAttribute("data-reservation-id");
      var nombre = boton.getAttribute("data-nombre") || "esta reservación";
      var hora = boton.getAttribute("data-hora") || "";

      var ejecutar = function () {
        return enviar(NO_SHOW_URL, {
          reservation_id: id,
          estado: "no_show",
          motivo: "casos_ausencia_pendiente"
        }).then(function () {
          aviso("Se registró que " + nombre + " no llegó.", "success");
          return repintar();
        }).catch(function (error) {
          aviso(error.message, "error");
          throw error;
        });
      };

      if (!window.ConfirmationModal) {
        // Sin el componente no se registra nada: nunca sin confirmar.
        aviso("No se pudo abrir la confirmación. Recarga la página.", "error");
        return;
      }
      window.ConfirmationModal.get().open({
        variant: "warning",
        eyebrow: "Casos",
        title: "Registrar que no llegó",
        description: nombre + (hora ? ", reservación de las " + hora : "") + ". La tolerancia de llegada ya venció.",
        consequence: "La reservación quedará registrada como ausencia y dejará de requerir seguimiento.",
        secondaryLabel: "Cancelar",
        primaryLabel: "Registrar que no llegó",
        onPrimary: ejecutar
      });
    }

    /*
     * Marcar revisada no pide confirmación: no destruye nada —la bitácora
     * conserva la reubicación y quién la revisó— y lo único que hace es quitar
     * la tarjeta de la lista. Preguntar «¿seguro?» por eso sería ruido.
     */
    function marcarRevisada(boton) {
      var nombre = boton.getAttribute("data-nombre") || "La reservación";
      boton.disabled = true;
      boton.setAttribute("aria-busy", "true");
      enviar(REVISAR_URL, { reubicacion_id: boton.getAttribute("data-reubicacion-id") })
        .then(function () {
          aviso("Reubicación de " + nombre + " marcada como revisada.", "success");
          return repintar();
        })
        .catch(function (error) {
          boton.disabled = false;
          boton.removeAttribute("aria-busy");
          aviso(error.message, "error");
        });
    }

    seccion.addEventListener("click", function (evento) {
      var objetivo = evento.target && evento.target.closest
        ? evento.target.closest("[data-caso-no-show], [data-caso-revisar]")
        : null;
      if (!objetivo || objetivo.disabled) return;
      evento.preventDefault();
      if (objetivo.hasAttribute("data-caso-no-show")) {
        registrarNoShow(objetivo);
      } else {
        marcarRevisada(objetivo);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    var secciones = document.querySelectorAll("[data-casos]");
    for (var i = 0; i < secciones.length; i += 1) init(secciones[i]);
  });
})();
