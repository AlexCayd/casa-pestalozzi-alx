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
  var contactLabel = root.querySelector("[data-manage-contact-label]");
  var contactMasked = root.querySelector("[data-contact-masked]");
  var otpInput = root.querySelector("[data-otp-input]");
  var otpError = root.querySelector("[data-contact-otp-error]");
  var preview = root.querySelector("[data-otp-preview]");
  var message = root.querySelector("[data-contact-message]");
  var portal = root.querySelector("[data-reservation-portal]");
  var list = root.querySelector("[data-reservation-list]");
  var summary = root.querySelector("[data-reservation-summary]");
  var limit = root.querySelector("[data-reservation-limit]");
  var csrfToken = document.querySelector("[data-reservation-csrf]");
  var accessTitle = root.querySelector("[data-contact-access-title]");
  var accessDescription = root.querySelector("[data-contact-access-description]");
  var currentIdentity = null;
  var editorTemplate = document.querySelector("[data-reservation-editor-template]");
  var contactValues = { email: "", telefono: "" };
  var activeContactType = "email";

  function setAccessCopy(verified) {
    if (accessTitle) accessTitle.textContent = verified
      ? "Contacto verificado"
      : "Verifica tu contacto";
    if (accessDescription) accessDescription.textContent = verified
      ? "Puedes consultar tus reservaciones o crear una nueva sin volver a verificar este contacto durante esta sesión."
      : "Te enviaremos un código temporal para consultar y gestionar tus reservaciones";
  }

  function clearReservationStorage() {
    var knownKeys = [
      "reservation_client",
      "reservation_contact",
      "reservation_verified_contact",
      "reservation_otp",
      "reservation_hold",
      "reservation_session",
      "reservation_flash"
    ];
    [window.sessionStorage, window.localStorage].forEach(function(storage) {
      try {
        knownKeys.forEach(function(key) { storage.removeItem(key); });
        for (var index = storage.length - 1; index >= 0; index--) {
          var key = storage.key(index) || "";
          if (/^(?:cp[-_]?reservation|reservation[-_](?:client|contact|otp|hold|session|flash))/i.test(key)) {
            storage.removeItem(key);
          }
        }
      } catch (error) {
        // El almacenamiento puede estar bloqueado; la sesión PHP sigue siendo la autoridad.
      }
    });
  }

  function clearReservationRuntime() {
    currentIdentity = null;
    contactValues = { email: "", telefono: "" };
    activeContactType = "email";
    window.CP_RESERVATION_SESSION = false;
    window.CP_RESERVATION_CONTACT = null;
    if (contactInput) contactInput.value = "";
    if (otpInput) otpInput.value = "";
    resetPreview();
  }

  function publishVerifiedSession(data) {
    var type = String(data.verified_contact_type || "");
    var contact = String(data.verified_contact || "");
    window.CP_RESERVATION_SESSION = true;
    window.CP_RESERVATION_CONTACT = type && contact
      ? { tipo: type, contacto: contact }
      : null;
    window.dispatchEvent(new CustomEvent("reservation:sessionchange", {
      detail: { verified: true, tipo: type, contacto: contact, source: "manage" }
    }));
  }

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
    tab.addEventListener("keydown", function(event) {
      if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
      event.preventDefault();
      var index = tabs.indexOf(tab);
      var next = event.key === "ArrowRight"
        ? (index + 1) % tabs.length
        : (index - 1 + tabs.length) % tabs.length;
      setTab(tabs[next].dataset.reservationTab);
      tabs[next].focus();
    });
  });

  function selectedType() {
    var checked = requestForm.querySelector("input[name='tipo']:checked");
    return checked ? checked.value : "email";
  }

  function syncContactInput() {
    var nextType = selectedType();
    if (activeContactType !== nextType) {
      contactValues[activeContactType] = contactInput.value;
      contactInput.value = contactValues[nextType] || "";
      activeContactType = nextType;
    }
    var phone = nextType === "telefono";
    contactInput.type = phone ? "tel" : "email";
    contactInput.autocomplete = phone ? "tel" : "email";
    contactInput.placeholder = phone ? "+52 55 1234 5678" : "cliente@ejemplo.com";
    if (contactLabel) contactLabel.textContent = phone ? "Teléfono" : "Correo electrónico";
    contactHelp.textContent = phone
      ? "Incluye el código de país +52."
      : "Te enviaremos un código temporal para consultar tus reservaciones.";
  }

  function maskContact(value, type) {
    value = String(value || "").trim();
    if (type === "telefono") {
      var digits = value.replace(/\D/g, "");
      return digits.length > 4 ? "•••• " + digits.slice(-4) : value;
    }
    var parts = value.split("@");
    return parts.length === 2
      ? (parts[0].slice(0, 2) || "•") + "•••@" + parts[1]
      : value;
  }

  requestForm.querySelectorAll("input[name='tipo']").forEach(function(input) {
    input.addEventListener("change", syncContactInput);
  });
  syncContactInput();

  function setMessage(text, error) {
    message.textContent = text || "";
    message.classList.toggle("is-error", Boolean(error));
  }

  function setOtpError(text) {
    if (!otpError || !otpInput) return;
    var hasError = Boolean(text);
    otpError.replaceChildren();
    if (hasError) {
      var icon = document.createElement("span");
      icon.className = "reservation-field__error-icon";
      icon.setAttribute("aria-hidden", "true");
      icon.textContent = "!";
      otpError.append(icon, document.createTextNode(String(text)));
    }
    otpError.classList.toggle("show", hasError);
    otpInput.setAttribute("aria-invalid", hasError ? "true" : "false");
    var field = otpInput.closest(".field");
    if (field) field.classList.toggle("is-invalid", hasError);
  }

  function shakeOtp() {
    var target = verifyForm;
    if (!target) return;
    target.classList.remove("reservation-step--error-shake");
    void target.offsetWidth;
    target.classList.add("reservation-step--error-shake");
    window.setTimeout(function() {
      target.classList.remove("reservation-step--error-shake");
    }, 240);
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
    if (!currentIdentity.contacto) {
      setMessage("Escribe el correo o teléfono que deseas verificar.", true);
      contactInput.focus();
      return;
    }

    jsonRequest("/api/reservaciones/contacto/codigo", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(currentIdentity)
    }).then(function(data) {
      if (!data.ok) {
        setMessage(data.mensaje || "No fue posible solicitar el código.", true);
        return;
      }

      verifyForm.hidden = false;
      requestForm.querySelectorAll("input, button").forEach(function(control) {
        control.disabled = true;
      });
      if (contactMasked) contactMasked.textContent = maskContact(currentIdentity.contacto, currentIdentity.tipo);
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
    if (!/^\d{6}$/.test(code)) {
      setOtpError("Escribe el código de seis dígitos.");
      setMessage("Revisa el código antes de continuar.", true);
      otpInput.focus();
      shakeOtp();
      return;
    }
    setOtpError("");

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
        setOtpError(data.mensaje || "El código no es válido.");
        setMessage(data.mensaje || "No fue posible verificar el código.", true);
        otpInput.focus();
        return;
      }
      setOtpError("");
      loadReservations();
    }).catch(function() {
      setOtpError("No fue posible verificar el código.");
      setMessage("No fue posible verificar el código.", true);
    });
  });

  otpInput.addEventListener("input", function() {
    setOtpError("");
  });

  root.querySelector("[data-contact-restart]").addEventListener("click", function() {
    currentIdentity = null;
    requestForm.querySelectorAll("input, button").forEach(function(control) {
      control.disabled = false;
    });
    verifyForm.hidden = true;
    otpInput.value = "";
    setOtpError("");
    resetPreview();
    setMessage("");
    contactInput.focus();
  });

  root.querySelector("[data-contact-resend]").addEventListener("click", function() {
    if (!currentIdentity) return;
    setMessage("");
    jsonRequest("/api/reservaciones/contacto/codigo", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(currentIdentity)
    }).then(function(data) {
      if (!data.ok) {
        setMessage(data.mensaje || "No fue posible reenviar el código.", true);
        return;
      }
      renderPreview(data.preview_code || "", data.expires_at || "");
      setMessage(data.mensaje || "Enviamos un código nuevo.");
    }).catch(function() {
      setMessage("No fue posible reenviar el código.", true);
    });
  });

  function linkPickerIds(editor, reservationId) {
    var suffix = "-reservation-" + reservationId;
    var nameInput = editor.elements.nombre;
    var peopleInput = editor.elements.personas;
    var notesInput = editor.elements.notas;
    nameInput.id = "reservationEditorName" + suffix;
    peopleInput.id = "reservationEditorPeople" + suffix;
    notesInput.id = "reservationEditorNotes" + suffix;
    editor.querySelector("[data-editor-name-label]").setAttribute("for", nameInput.id);
    editor.querySelector("[data-editor-people-label]").setAttribute("for", peopleInput.id);
    editor.querySelector("[data-editor-notes-label]").setAttribute("for", notesInput.id);

    var dateRoot = editor.querySelector("[data-reservation-date-picker]");
    var dateDisplay = dateRoot.querySelector("[data-date-display]");
    var dateInput = dateRoot.querySelector("[data-date-input]");
    var calendar = dateRoot.querySelector("[data-date-calendar]");
    dateRoot.id += suffix;
    dateDisplay.id += suffix;
    dateInput.id += suffix;
    calendar.id += suffix;
    dateDisplay.setAttribute("aria-controls", calendar.id);
    editor.querySelector("[data-editor-date-label]").setAttribute("for", dateDisplay.id);

    var timeRoot = editor.querySelector("[data-reservation-time-picker]");
    var timeDisplay = timeRoot.querySelector("[data-time-display]");
    var timeInput = timeRoot.querySelector("[data-time-input]");
    var dropdown = timeRoot.querySelector("[data-time-dropdown]");
    timeRoot.id += suffix;
    timeDisplay.id += suffix;
    timeInput.id += suffix;
    dropdown.id += suffix;
    timeDisplay.setAttribute("aria-controls", dropdown.id);
    editor.querySelector("[data-editor-time-label]").setAttribute("for", timeDisplay.id);
  }

  function initializeEditorGuestPicker(editor, reservation) {
    var peopleInput = editor.elements.personas;
    var extra = editor.querySelector("[data-editor-guests-extra]");
    var valueLabel = editor.querySelector("[data-editor-guests-value]");
    var minus = editor.querySelector("[data-editor-guests-minus]");
    var plus = editor.querySelector("[data-editor-guests-plus]");
    var maxGuests = parseInt(peopleInput.getAttribute("max"), 10) || 12;

    function setGuests(value) {
      value = Math.max(1, Math.min(maxGuests, parseInt(value, 10) || 2));
      peopleInput.value = value;
      if (valueLabel) valueLabel.textContent = String(value);
      if (extra) {
        extra.hidden = false;
        extra.classList.add("show");
      }
      if (minus) minus.disabled = value <= 1;
      if (plus) plus.disabled = value >= maxGuests;
    }

    minus.addEventListener("click", function() {
      setGuests(parseInt(peopleInput.value, 10) - 1);
      peopleInput.dispatchEvent(new Event("change", { bubbles: true }));
    });
    plus.addEventListener("click", function() {
      setGuests(parseInt(peopleInput.value, 10) + 1);
      peopleInput.dispatchEvent(new Event("change", { bubbles: true }));
    });

    setGuests(reservation.comensales || 2);
    return setGuests;
  }

  function initializeEditorPickers(editor, reservation) {
    linkPickerIds(editor, reservation.id);
    var dateRoot = editor.querySelector("[data-reservation-date-picker]");
    var dateInput = dateRoot.querySelector("[data-date-input]");
    var timeRoot = editor.querySelector("[data-reservation-time-picker]");
    var timeInput = timeRoot.querySelector("[data-time-input]");
    var peopleInput = editor.elements.personas;
    var datePicker = window.createReservationDatePicker({
      root: dateRoot,
      initialValue: reservation.fecha || ""
    });
    var timePicker = window.createReservationTimePicker({
      root: timeRoot,
      status: editor.querySelector("[data-editor-time-status]"),
      endpoint: timeRoot.getAttribute("data-schedules-endpoint"),
      getQueryParams: function() {
        return { personas: parseInt(peopleInput.value, 10) || reservation.comensales || 2 };
      },
      initialDate: reservation.fecha || "",
      initialTime: String(reservation.hora || "").slice(0, 5),
      invalidateUnavailable: true
    });

    dateInput.addEventListener("reservation:datechange", function(event) {
      timePicker.loadForDate((event.detail && event.detail.fecha) || dateInput.value, timeInput.value);
    });
    peopleInput.addEventListener("change", function() {
      if (dateInput.value) timePicker.loadForDate(dateInput.value, timeInput.value);
    });
    return { date: datePicker, time: timePicker };
  }

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

        var editor = editorTemplate
          ? editorTemplate.content.firstElementChild.cloneNode(true)
          : document.createElement("form");
        editor.hidden = true;
        if (!editorTemplate) {
          editor.className = "reservation-card__editor";
          editor.innerHTML = "<p>No fue posible cargar el formulario de modificación.</p>";
        }
        editor.elements.nombre.value = reservation.nombre || "";
        editor.elements.personas.value = reservation.comensales || 2;
        editor.elements.notas.value = reservation.nota || "";
        var editorGuestPicker = initializeEditorGuestPicker(editor, reservation);
        var editorPickers = initializeEditorPickers(editor, reservation);
        var editorCancel = editor.querySelector("[data-editor-cancel]");

        function restoreEditor() {
          editor.elements.nombre.value = reservation.nombre || "";
          editor.elements.personas.value = reservation.comensales || 2;
          editor.elements.notas.value = reservation.nota || "";
          editorGuestPicker(reservation.comensales || 2);
          if (editorPickers.date) {
            editorPickers.date.setValue(reservation.fecha || "", true);
          }
          if (editorPickers.time) {
            editorPickers.time.loadForDate(
              reservation.fecha || "",
              String(reservation.hora || "").slice(0, 5)
            );
          }
          var editorMessage = editor.querySelector("[data-editor-message]");
          if (editorMessage) editorMessage.textContent = "";
        }

        function closeEditor() {
          restoreEditor();
          editor.hidden = true;
          modify.focus();
        }

        modify.addEventListener("click", function() {
          if (editor.hidden) {
            editor.hidden = false;
            editor.elements.nombre.focus();
            if (editorPickers.time && editor.elements.fecha.value) {
              editorPickers.time.loadForDate(editor.elements.fecha.value, editor.elements.hora.value);
            }
          } else {
            closeEditor();
          }
        });
        if (editorCancel) {
          editorCancel.addEventListener("click", function() {
            closeEditor();
          });
        }
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
        cancel.textContent = "Cancelar reservación";
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

    var active = parseInt(data.active_reservations_count, 10) || 0;
    var maximum = parseInt(data.max_active_reservations, 10) || 5;
    var remaining = Math.max(0, maximum - active);
    summary.textContent = active
      ? "Tienes " + active + " reservaciones activas; puedes tener hasta " + maximum + "."
      : "Aún no tienes reservaciones activas; puedes tener hasta " + maximum + ".";
    limit.textContent = data.can_create_reservation
      ? "Todavía puedes agregar " + remaining + (remaining === 1 ? " reservación." : " reservaciones.")
      : "Ya tienes las " + maximum + " reservaciones activas permitidas.";
    limit.classList.toggle("is-limit", !data.can_create_reservation);
  }

  function showAccess() {
    access.hidden = false;
    portal.hidden = true;
    setAccessCopy(false);
    requestForm.hidden = false;
    requestForm.querySelectorAll("input, button").forEach(function(control) {
      control.disabled = false;
    });
    verifyForm.hidden = true;
    clearReservationRuntime();
    setMessage("");
    window.dispatchEvent(new CustomEvent("reservation:sessionchange", {
      detail: { verified: false, source: "manage" }
    }));
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
        publishVerifiedSession(data);
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
    var token = csrfToken ? csrfToken.getAttribute("data-reservation-csrf") : "";
    jsonRequest("/api/reservaciones/contacto/logout", {
      method: "POST",
      body: JSON.stringify({ csrf_token: token })
    })
      .then(function(data) {
        if (!data.ok) {
          throw new Error(data.mensaje || "No fue posible salir de la gestión.");
        }
        clearReservationStorage();
        clearReservationRuntime();
        window.location.replace("/reservaciones");
      })
      .catch(function(error) {
        showAccess();
        setMessage(error && error.message
          ? error.message
          : "No fue posible salir de la gestión de reservaciones.", true);
      })
      .finally(function() {
        logoutButton.disabled = false;
      });
  });

  // Una recarga puede conservar la sesión verificada; el servidor decide si
  // sigue vigente y renueva su expiración por actividad.
  window.addEventListener("pageshow", function(event) {
    if (event.persisted) window.location.reload();
  });
  loadReservations();

  window.addEventListener("reservation:sessionchange", function(event) {
    if (event.detail && event.detail.source === "manage") return;
    if (event.detail && event.detail.verified) {
      loadReservations();
    } else {
      showAccess();
    }
  });
}
