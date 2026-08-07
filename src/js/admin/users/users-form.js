/**
 * Formulario de alta y edición de usuarios.
 *
 * Resuelve tres cosas que el HTML solo no puede:
 *
 * 1. Fecha en dd/mm/aaaa. Un <input type="date"> se pinta con el formato del
 *    locale del navegador, que aquí sale mm/dd/yyyy. Se captura en un campo de
 *    texto con máscara y se mantiene un hidden en Y-m-d, que es lo que el
 *    backend valida (Usuario::validarFechaNacimiento).
 *
 * 2. NIP sugerido desde el cumpleaños. Es la misma regla del servidor
 *    (Usuario::nipDesdeNacimiento: día + mes), pero visible y editable antes de
 *    guardar en vez de aparecer después sin avisar. En cuanto alguien escribe
 *    un NIP a mano deja de autocompletarse.
 *
 * 3. NIP o contraseña, según el rol. Son excluyentes: los administradores
 *    entran con contraseña y el personal de piso con NIP. El servidor ya aplica
 *    esa regla; aquí solo se deja de pedir lo que no aplica, porque un required
 *    sobre un campo irrelevante bloqueaba el envío sin explicar por qué.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-users-form]");
  if (!form) {
    return;
  }

  var display = form.querySelector("[data-birthdate-display]");
  var hidden = form.querySelector("[data-birthdate-value]");
  var nip = form.querySelector("[data-user-nip]");
  var nipField = form.querySelector("[data-user-nip-field]");
  var passwordSection = form.querySelector("[data-user-password-section]");
  var roles = Array.prototype.slice.call(form.querySelectorAll("[data-user-role]"));

  // Un NIP que ya venía escrito (reenvío tras un error de validación) es del
  // usuario, no nuestro: no se pisa.
  var nipEditadoAMano = Boolean(nip && nip.value);

  // ── Fecha ────────────────────────────────────────────────────
  function aISO(texto) {
    var partes = String(texto || "").match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!partes) {
      return "";
    }

    var dia = Number(partes[1]);
    var mes = Number(partes[2]);
    var anio = Number(partes[3]);
    var fecha = new Date(anio, mes - 1, dia);

    // Round-trip: descarta 31/02 y demás fechas que el constructor "corrige"
    // en silencio.
    if (fecha.getDate() !== dia || fecha.getMonth() !== mes - 1 || fecha.getFullYear() !== anio) {
      return "";
    }
    if (fecha > new Date() || anio < 1900) {
      return "";
    }

    return partes[3] + "-" + partes[2] + "-" + partes[1];
  }

  function aTexto(iso) {
    var partes = String(iso || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return partes ? partes[3] + "/" + partes[2] + "/" + partes[1] : "";
  }

  function enmascarar(valor) {
    var digitos = String(valor || "").replace(/\D/g, "").slice(0, 8);

    if (digitos.length <= 2) {
      return digitos;
    }
    if (digitos.length <= 4) {
      return digitos.slice(0, 2) + "/" + digitos.slice(2);
    }
    return digitos.slice(0, 2) + "/" + digitos.slice(2, 4) + "/" + digitos.slice(4);
  }

  function nipDesdeFecha(iso) {
    var partes = String(iso || "").match(/^\d{4}-(\d{2})-(\d{2})$/);
    // Día + mes, en ese orden: 14/03 → 1403. Como cadena, para conservar el
    // cero inicial de "0303".
    return partes ? partes[2] + partes[1] : "";
  }

  function sincronizarFecha() {
    var iso = aISO(display.value);
    hidden.value = iso;

    if (iso && nip && !nipEditadoAMano && rolActual() !== "admin") {
      nip.value = nipDesdeFecha(iso);
    }
  }

  if (display && hidden) {
    display.value = aTexto(hidden.value);

    display.addEventListener("input", function () {
      display.value = enmascarar(display.value);
      sincronizarFecha();
    });

    display.addEventListener("blur", sincronizarFecha);
  }

  if (nip) {
    nip.addEventListener("input", function () {
      nipEditadoAMano = nip.value !== "";
    });
  }

  // ── Credencial según el rol ──────────────────────────────────
  function rolActual() {
    var marcado = roles.filter(function (input) {
      return input.checked;
    })[0];

    return marcado ? marcado.value : "";
  }

  function aplicarRol() {
    var esAdmin = rolActual() === "admin";

    if (nipField) {
      nipField.hidden = esAdmin;
    }
    if (nip) {
      nip.disabled = esAdmin;
      if (esAdmin) {
        nip.value = "";
      } else if (!nipEditadoAMano && hidden && hidden.value) {
        nip.value = nipDesdeFecha(hidden.value);
      }
    }

    if (passwordSection) {
      passwordSection.hidden = !esAdmin;
      // Los required tienen que caer con la sección: el navegador se niega a
      // enviar un formulario con un campo requerido oculto, y ni siquiera
      // puede enfocarlo para decir dónde está el problema.
      passwordSection.querySelectorAll("input").forEach(function (input) {
        input.disabled = !esAdmin;
        input.required = esAdmin;
      });
    }
  }

  roles.forEach(function (input) {
    input.addEventListener("change", aplicarRol);
  });

  aplicarRol();
})();
