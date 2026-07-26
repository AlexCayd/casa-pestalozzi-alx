/* ============================================================
   Acceso público a reservaciones por contacto verificado.
   La UI nunca decide si un OTP puede mostrarse: solo renderiza
   preview_code cuando el servidor lo incluyó de forma explícita.
   ============================================================ */

function initReservationAccess() {
  var root = document.querySelector("[data-reservation-panel='manage']");
  if (!root) return;

  var tabs = Array.from(document.querySelectorAll("[data-reservation-tab]"));
  var panels = Array.from(document.querySelectorAll("[data-reservation-panel]"));
  var access = root.querySelector("[data-contact-access]");
  var requestForm = root.querySelector("[data-contact-request-form]");
  var verifyForm = root.querySelector("[data-contact-verify-form]");
  var contactInput = root.querySelector("[data-contact-input]");
  var contactHelp = root.querySelector("[data-contact-help]");
  var otpInput = root.querySelector("[data-otp-input]");
  var preview = root.querySelector("[data-otp-preview]");
  var message = root.querySelector("[data-contact-message]");
  var portal = root.querySelector("[data-reservation-portal]");
  var list = root.querySelector("[data-reservation-list]");
  var summary = root.querySelector("[data-reservation-summary]");
  var limit = root.querySelector("[data-reservation-limit]");
  var currentIdentity = null;

  function setTab(name) {
    tabs.forEach(function(tab) {
      var active = tab.dataset.reservationTab === name;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });
    panels.forEach(function(panel) {
      panel.hidden = panel.dataset.reservationPanel !== name;
    });
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  tabs.forEach(function(tab) {
    tab.addEventListener("click", function() {
      setTab(tab.dataset.reservationTab);
    });
  });

  function selectedType() {
    var checked = requestForm.querySelector("input[name='tipo']:checked");
    return checked ? checked.value : "email";
  }

  function syncContactInput() {
    var phone = selectedType() === "telefono";
    contactInput.type = phone ? "tel" : "email";
    contactInput.autocomplete = phone ? "tel" : "email";
    contactInput.placeholder = phone ? "+52 55 1234 5678" : "cliente@ejemplo.com";
    contactHelp.textContent = phone
      ? "Incluye +52 y los 10 dígitos. No añadiremos el país automáticamente."
      : "Usaremos el correo en minúsculas y sin espacios externos.";
  }

  requestForm.querySelectorAll("input[name='tipo']").forEach(function(input) {
    input.addEventListener("change", syncContactInput);
  });
  syncContactInput();

  function setMessage(text, error) {
    message.textContent = text || "";
    message.classList.toggle("is-error", Boolean(error));
  }

  function jsonRequest(url, options) {
    return fetch(url, Object.assign({ credentials: "same-origin" }, options || {}))
      .then(function(response) {
        return response.json().catch(function() {
          return { ok: false, mensaje: "La respuesta del servidor no es válida." };
        }).then(function(data) {
          data.httpStatus = response.status;
          return data;
        });
      });
  }

  function resetPreview() {
    preview.hidden = true;
    preview.replaceChildren();
  }

  function renderPreview(code, expiresAt) {
    resetPreview();
    if (!code) return;

    var title = document.createElement("strong");
    title.textContent = "Modo de desarrollo";
    var text = document.createElement("span");
    text.textContent = "Código de prueba: " + code;
    var expires = document.createElement("small");
    expires.textContent = expiresAt ? "Vence en aproximadamente 5 minutos." : "Código temporal.";
    var useButton = document.createElement("button");
    useButton.type = "button";
    useButton.className = "reservation-access__link";
    useButton.textContent = "Usar código de prueba";
    useButton.addEventListener("click", function() {
      otpInput.value = code;
      otpInput.focus();
    });

    preview.append(title, text, expires, useButton);
    preview.hidden = false;
  }

  requestForm.addEventListener("submit", function(event) {
    event.preventDefault();
    setMessage("");
    resetPreview();

    currentIdentity = {
      tipo: selectedType(),
      contacto: contactInput.value.trim()
    };

    jsonRequest("/api/reservaciones/contacto/codigo", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(currentIdentity)
    }).then(function(data) {
      if (!data.ok) {
        setMessage(data.mensaje || "No fue posible solicitar el código.", true);
        return;
      }

      requestForm.hidden = true;
      verifyForm.hidden = false;
      otpInput.value = "";
      renderPreview(data.preview_code || "", data.expires_at || "");
      setMessage(data.mensaje || "Código solicitado.");
      otpInput.focus();
    }).catch(function() {
      setMessage("No fue posible solicitar el código.", true);
    });
  });

  verifyForm.addEventListener("submit", function(event) {
    event.preventDefault();
    if (!currentIdentity) {
      setMessage("Vuelve a capturar tu contacto.", true);
      return;
    }

    var code = otpInput.value.replace(/\D/g, "").slice(0, 6);
    otpInput.value = code;
    setMessage("");

    jsonRequest("/api/reservaciones/contacto/verificar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        tipo: currentIdentity.tipo,
        contacto: currentIdentity.contacto,
        codigo: code
      })
    }).then(function(data) {
      if (!data.ok) {
        setMessage(data.mensaje || "No fue posible verificar el código.", true);
        return;
      }
      loadReservations();
    }).catch(function() {
      setMessage("No fue posible verificar el código.", true);
    });
  });

  root.querySelector("[data-contact-restart]").addEventListener("click", function() {
    currentIdentity = null;
    requestForm.hidden = false;
    verifyForm.hidden = true;
    otpInput.value = "";
    resetPreview();
    setMessage("");
    contactInput.focus();
  });

  function reservationCard(reservation) {
    var card = document.createElement("article");
    card.className = "reservation-card";

    var kicker = document.createElement("span");
    kicker.className = "reservation-card__kicker";
    kicker.textContent = "Reservación";

    var date = document.createElement("h4");
    var parsed = new Date(reservation.fecha + "T12:00:00");
    var dateLabel = Number.isNaN(parsed.getTime())
      ? reservation.fecha
      : parsed.toLocaleDateString("es-MX", { weekday: "long", day: "numeric", month: "long" });
    date.textContent = dateLabel + " · " + reservation.hora;

    var details = document.createElement("p");
    details.textContent = reservation.comensales + (reservation.comensales === 1 ? " persona" : " personas");

    var status = document.createElement("span");
    status.className = "reservation-card__status";
    status.textContent = reservation.estado_label;

    card.append(kicker, date, details, status);

    if (reservation.can_modify || reservation.can_cancel) {
      var actions = document.createElement("div");
      actions.className = "reservation-card__actions";

      if (reservation.can_modify) {
        var modify = document.createElement("button");
        modify.type = "button";
        modify.className = "reservation-access__link";
        modify.textContent = "Modificar";

        var editor = document.createElement("form");
        editor.className = "reservation-card__editor";
        editor.hidden = true;
        editor.innerHTML = [
          '<label>Nombre<input name="nombre" type="text" required></label>',
          '<label>Fecha<input name="fecha" type="date" required></label>',
          '<label>Hora<input name="hora" type="time" step="60" required></label>',
          '<label>Personas<input name="personas" type="number" min="1" max="12" required></label>',
          '<label>Notas<textarea name="notas" maxlength="500"></textarea></label>',
          '<button type="submit" class="btn-line"><span>Guardar cambios</span></button>',
          '<p class="reservation-access__message" data-editor-message></p>'
        ].join("");
        editor.elements.nombre.value = reservation.nombre || "";
        editor.elements.fecha.value = reservation.fecha || "";
        editor.elements.hora.value = reservation.hora || "";
        editor.elements.personas.value = reservation.comensales || 2;
        editor.elements.notas.value = reservation.nota || "";

        modify.addEventListener("click", function() {
          editor.hidden = !editor.hidden;
          if (!editor.hidden) editor.elements.nombre.focus();
        });
        editor.addEventListener("submit", function(event) {
          event.preventDefault();
          var editorMessage = editor.querySelector("[data-editor-message]");
          editorMessage.textContent = "";
          jsonRequest("/api/reservaciones/modificar", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              reservacion_id: reservation.id,
              nombre: editor.elements.nombre.value.trim(),
              fecha: editor.elements.fecha.value,
              hora: editor.elements.hora.value,
              personas: parseInt(editor.elements.personas.value, 10),
              notas: editor.elements.notas.value.trim()
            })
          }).then(function(data) {
            if (!data.ok) {
              editorMessage.textContent = data.mensaje
                || "No fue posible modificar; tu reservación original se conserva.";
              return;
            }
            setMessage(data.mensaje || "Reservación modificada.");
            loadReservations();
          }).catch(function() {
            editorMessage.textContent = "No fue posible modificar; tu reservación original se conserva.";
          });
        });
        actions.append(modify);
        card.append(editor);
      }

      if (reservation.can_cancel) {
        var cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "reservation-access__link reservation-access__link--danger";
        cancel.textContent = "Cancelar";
        cancel.addEventListener("click", function() {
          if (!window.confirm("¿Deseas cancelar esta reservación?")) return;
          jsonRequest("/api/reservaciones/cancelar", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ reservacion_id: reservation.id })
          }).then(function(data) {
            if (!data.ok) {
              setMessage(data.mensaje || "No fue posible cancelar la reservación.", true);
              return;
            }
            setMessage(data.mensaje || "La reservación fue cancelada.");
            loadReservations();
          }).catch(function() {
            setMessage("No fue posible cancelar la reservación.", true);
          });
        });
        actions.append(cancel);
      }
      card.append(actions);
    }
    return card;
  }

  function renderReservations(data) {
    list.replaceChildren();
    var reservations = Array.isArray(data.reservations) ? data.reservations : [];

    if (reservations.length === 0) {
      var empty = document.createElement("p");
      empty.className = "reservation-portal__empty";
      empty.textContent = "No tienes reservaciones activas próximas con este contacto.";
      list.append(empty);
    } else {
      reservations.forEach(function(reservation) {
        list.append(reservationCard(reservation));
      });
    }

    summary.textContent = data.active_reservations_count + " de "
      + data.max_active_reservations + " reservaciones activas.";
    limit.textContent = data.can_create_reservation
      ? "Puedes crear una nueva reservación."
      : "Alcanzaste el límite de cinco reservaciones activas.";
    limit.classList.toggle("is-limit", !data.can_create_reservation);
  }

  function showAccess() {
    access.hidden = false;
    portal.hidden = true;
    requestForm.hidden = false;
    verifyForm.hidden = true;
    currentIdentity = null;
    resetPreview();
  }

  function loadReservations() {
    jsonRequest("/api/reservaciones/mis-reservaciones", { method: "GET" })
      .then(function(data) {
        if (!data.ok) {
          showAccess();
          if (data.httpStatus !== 401) {
            setMessage(data.mensaje || "No fue posible consultar tus reservaciones.", true);
          }
          return;
        }
        access.hidden = true;
        portal.hidden = false;
        renderReservations(data);
      })
      .catch(function() {
        showAccess();
        setMessage("No fue posible consultar tus reservaciones.", true);
      });
  }

  var logoutButton = root.querySelector("[data-contact-logout]");
  logoutButton.addEventListener("click", function(event) {
    event.preventDefault();
    logoutButton.disabled = true;
    jsonRequest("/api/reservaciones/contacto/logout", { method: "POST" })
      .then(function() {
        showAccess();
        setMessage("Sesión cerrada.");
        window.CP_RESERVATION_SESSION = false;
        window.dispatchEvent(new CustomEvent("reservation:sessionchange", {
          detail: { verified: false }
        }));
      })
      .catch(function() {
        // El cierre local de la interfaz es seguro e idempotente; el siguiente
        // GET volverá a exigir OTP si el servidor no respondió.
        showAccess();
        setMessage("Sesión cerrada.");
      })
      .finally(function() {
        logoutButton.disabled = false;
      });
  });

  // Una recarga puede conservar la sesión verificada; el servidor decide si
  // sigue vigente y renueva su expiración por actividad.
  loadReservations();

  window.addEventListener("reservation:sessionchange", function(event) {
    if (event.detail && event.detail.verified) {
      loadReservations();
    } else {
      showAccess();
    }
  });
}
