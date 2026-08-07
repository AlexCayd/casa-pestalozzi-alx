/**
 * Filas de tabla que llevan a editar el registro.
 *
 * El destino lo declara cada fila en data-row-href, con la misma URL del botón
 * de editar de esa fila: no hay que sincronizar dos rutas ni duplicar lógica en
 * cada módulo. El listener es único y delegado, así que las tablas que llegan
 * por los filtros reactivos (que sustituyen el HTML del listado) funcionan sin
 * volver a inicializar nada.
 *
 * Las acciones de la última columna (editar, eliminar, cambiar visibilidad)
 * siguen mandando: un clic dentro de ellas nunca dispara la navegación de fila,
 * o eliminar un platillo abriría su formulario de edición.
 */
(function () {
  "use strict";

  var IGNORAR = "a, button, input, select, textarea, label, form," +
    " .admin-table-actions, .admin-menu__row-actions, [data-row-ignore]";

  function destino(evento) {
    var fila = evento.target.closest("tr[data-row-href]");

    if (!fila || evento.target.closest(IGNORAR)) {
      return null;
    }

    return fila.dataset.rowHref || null;
  }

  document.addEventListener("click", function (evento) {
    // Solo clic primario y sin modificadores: ctrl/cmd/shift o el botón central
    // significan "abrir en otra pestaña", y eso lo resuelve el navegador con el
    // enlace de la columna de acciones.
    if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey) {
      return;
    }

    // Un clic que termina sobre una selección de texto es alguien copiando el
    // contenido de la celda, no navegando.
    var seleccion = window.getSelection();
    if (seleccion && String(seleccion) !== "") {
      return;
    }

    var href = destino(evento);
    if (href) {
      window.location.href = href;
    }
  });

  document.addEventListener("keydown", function (evento) {
    if (evento.key !== "Enter") {
      return;
    }

    var fila = evento.target.closest ? evento.target.closest("tr[data-row-href]") : null;
    if (fila && evento.target === fila) {
      window.location.href = fila.dataset.rowHref;
    }
  });

  /* La fila es un destino de teclado por derecho propio. Se hace en JS y no en
     el HTML para que sin JS no queden paradas de tabulación que no llevan a
     ningún sitio. */
  function prepararFilas(raiz) {
    var filas = (raiz || document).querySelectorAll("tr[data-row-href]:not([tabindex])");

    for (var i = 0; i < filas.length; i++) {
      filas[i].setAttribute("tabindex", "0");
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    prepararFilas(document);
  });

  // Los filtros reactivos reemplazan el listado entero: hay que volver a marcar.
  document.addEventListener("admin:reactive-updated", function (evento) {
    prepararFilas((evento.detail && evento.detail.target) || document);
  });
})();
