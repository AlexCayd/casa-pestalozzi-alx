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
  var hintNip = form.querySelector("[data-hint-nip]");
  var accessList = form.querySelector("[data-role-access-list]");
  var roles = Array.prototype.slice.call(form.querySelectorAll("[data-user-role]"));

  // Un NIP que ya venía escrito (reenvío tras un error de validación) es del
  // usuario, no nuestro: no se pisa.
  var nipEditadoAMano = Boolean(nip && nip.value);

  /*
   * Quien ya tiene NIP no recibe sugerencia del cumpleaños.
   *
   * Sin esto, abrir la ficha de alguien con NIP 2345 y cumpleaños el 23/11
   * rellenaba el campo con 2311: se leía como si ése fuera su NIP —no lo era—
   * y, al guardar sin tocar nada, el campo viajaba lleno y se lo cambiaba de
   * verdad, dejándole fuera del sistema. El servidor ya se guarda de esto
   * (Usuario::generarNipDesdeNacimiento sale si el usuario tiene nip_hash),
   * pero su guardia no sirve de nada si el campo llega relleno desde aquí.
   */
  var yaTieneNip = form.getAttribute("data-user-has-nip") === "1";

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

    if (iso && nip && !nipEditadoAMano && !yaTieneNip && rolActual() !== "admin") {
      nip.value = nipDesdeFecha(iso);
      // Es el caso que más choca: dos personas nacidas el mismo día y mes
      // reciben el mismo NIP sugerido sin que nadie lo teclee.
      comprobarNip();
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

  // ── NIP repetido: no se puede guardar ────────────────────────
  //
  // El servidor lo rechaza igual —y es él quien manda, porque valida dentro de
  // la transacción—, pero el aviso llegaba tras enviar y metido en la lista de
  // errores de la cabecera: se corregía la fecha, el nombre y el rol antes de
  // caer en que el problema era el NIP. Aquí el formulario ni siquiera deja
  // enviar mientras el NIP esté ocupado.
  var MENSAJE_OCUPADO = "Ese NIP ya está asignado a otro usuario. Elige uno distinto.";
  var nipStatus = form.querySelector("[data-user-nip-status]");
  var usuarioId = form.getAttribute("data-user-id") || "0";
  var consultaNip = null;
  var peticionNip = 0;

  // Veredicto del último NIP consultado. `disponible: null` = todavía no se
  // sabe, que no es lo mismo que "libre": al enviar hay que resolverlo antes.
  var veredicto = { valor: "", disponible: null };

  function mostrarEstadoNip(texto, estado) {
    if (nipStatus) {
      nipStatus.hidden = !texto;
      nipStatus.textContent = texto || "";
      nipStatus.className = "admin-users-form__nip-status" +
        (estado ? " admin-users-form__nip-status--" + estado : "");
    }
    if (nip) {
      nip.setAttribute("aria-invalid", estado === "ocupado" ? "true" : "false");
      // Lo que de verdad impide guardar: con un customValidity no vacío el
      // navegador se niega a enviar el formulario y señala el campo.
      nip.setCustomValidity(estado === "ocupado" ? MENSAJE_OCUPADO : "");
    }
  }

  /** Consulta el NIP actual. Devuelve una promesa con true/false/null. */
  function comprobarNip() {
    if (!nip || nip.disabled) {
      return Promise.resolve(null);
    }

    var valor = nip.value.trim();
    if (!/^\d{4}$/.test(valor)) {
      // Un NIP a medio escribir no es un error todavía: del formato se encarga
      // el patrón del campo.
      veredicto = { valor: valor, disponible: null };
      mostrarEstadoNip("", "");
      return Promise.resolve(null);
    }

    var propia = ++peticionNip;
    var url = "/admin/api/usuarios/nip-disponible?nip=" + encodeURIComponent(valor) +
      "&id=" + encodeURIComponent(usuarioId);

    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (respuesta) {
        return respuesta.ok ? respuesta.json() : null;
      })
      .then(function (datos) {
        // Llegó tarde: ya se tecleó otro NIP y su respuesta manda.
        if (propia !== peticionNip || !datos || !datos.ok) {
          return null;
        }
        veredicto = { valor: valor, disponible: Boolean(datos.disponible) };
        if (datos.disponible) {
          mostrarEstadoNip("NIP disponible.", "libre");
        } else {
          mostrarEstadoNip(MENSAJE_OCUPADO, "ocupado");
        }
        return veredicto.disponible;
      })
      .catch(function () {
        // Sin red no se bloquea el guardado: inventar un "ocupado" dejaría al
        // administrador sin poder trabajar, y el POST lo comprueba igual.
        veredicto = { valor: valor, disponible: null };
        mostrarEstadoNip("", "");
        return null;
      });
  }

  if (nip) {
    nip.addEventListener("input", function () {
      nipEditadoAMano = nip.value !== "";
      mostrarEstadoNip("", "");

      if (consultaNip) {
        window.clearTimeout(consultaNip);
      }
      // Cada comprobación recorre los hashes con password_verify: no conviene
      // lanzarla en cada pulsación.
      consultaNip = window.setTimeout(comprobarNip, 350);
    });

    /*
     * Último filtro antes de enviar.
     *
     * El customValidity ya frena el caso conocido, pero se puede enviar antes
     * de que la consulta en vuelo conteste —escribir el cuarto dígito y pulsar
     * "Guardar" de inmediato—. Si el veredicto no corresponde al valor actual,
     * se detiene el envío, se resuelve y sólo entonces se reenvía.
     */
    form.addEventListener("submit", function (evento) {
      if (nip.disabled) {
        return;
      }

      var valor = nip.value.trim();
      if (!/^\d{4}$/.test(valor) || veredicto.valor === valor) {
        return;
      }

      evento.preventDefault();
      if (consultaNip) {
        window.clearTimeout(consultaNip);
      }

      comprobarNip().then(function (disponible) {
        if (disponible === false) {
          nip.focus();
          nip.reportValidity();
          return;
        }
        // Libre, o sin respuesta del servidor: que decida el POST.
        if (typeof form.requestSubmit === "function") {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
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
    // La fecha de nacimiento se sigue pidiendo al admin, pero deja de hablar de
    // un NIP que su rol no usa.
    if (hintNip) {
      hintNip.hidden = esAdmin;
    }
    if (nip) {
      nip.disabled = esAdmin;
      if (esAdmin) {
        nip.value = "";
        mostrarEstadoNip("", "");
      } else if (!nipEditadoAMano && !yaTieneNip && hidden && hidden.value) {
        nip.value = nipDesdeFecha(hidden.value);
        comprobarNip();
      }
    }

    if (passwordSection) {
      // Al editar la contraseña es opcional (vacío = sin cambio), así que la
      // sección se muestra pero nunca marca required.
      var opcional = passwordSection.hasAttribute("data-user-password-optional");
      passwordSection.hidden = !esAdmin;
      // Los required tienen que caer con la sección: el navegador se niega a
      // enviar un formulario con un campo requerido oculto, y ni siquiera
      // puede enfocarlo para decir dónde está el problema.
      passwordSection.querySelectorAll("input").forEach(function (input) {
        input.disabled = !esAdmin;
        input.required = esAdmin && !opcional;
      });
    }

    pintarAcceso();
  }

  // ── Áreas a las que da acceso el rol ─────────────────────────
  // Los datos los publica la vista desde Auth::areasPorRol(), que deriva de la
  // misma guardia que aplica proteger(): no se describen permisos de memoria.
  function pintarAcceso() {
    if (!accessList) {
      return;
    }

    var areas = (window.AdminUserRoleAccess || {})[rolActual()] || [];
    accessList.innerHTML = areas
      .map(function (area) {
        return (
          '<li class="admin-role-access__item">' +
          '<strong>' +
          escapar(area.titulo) +
          "</strong>" +
          "<span>" +
          escapar(area.detalle) +
          "</span>" +
          "</li>"
        );
      })
      .join("");
  }

  function escapar(valor) {
    var div = document.createElement("div");
    div.textContent = String(valor == null ? "" : valor);
    return div.innerHTML;
  }

  roles.forEach(function (input) {
    input.addEventListener("change", aplicarRol);
  });

  aplicarRol();
})();
