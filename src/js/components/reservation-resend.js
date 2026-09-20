/* Política de reenvío presentada desde PHP; nunca se calcula un cupo local. */
(function(global) {
  "use strict";

  function nonNegative(value) {
    if (value === null || value === undefined || value === "") return null;
    var number = Number(value);
    return Number.isFinite(number) && number >= 0 ? number : null;
  }

  function hasPolicy(data) {
    return Boolean(data && nonNegative(data.remaining_resends) !== null);
  }

  function needsVerification(data) {
    return Boolean(data && (
      data.codigo === "CODIGO_CONFIRMACION_ENVIADO"
      || data.codigo === "RETENCION_CREADA"
      || data.codigo === "OTP_ENVIO_FALLIDO"
      || data.codigo === "REENVIO_EN_COOLDOWN"
      || data.codigo === "LIMITE_REENVIOS_ALCANZADO"
      || hasPolicy(data)
    ));
  }

  function message(data) {
    if (data && data.codigo === "OTP_ENVIO_FALLIDO") {
      return "No pudimos enviar el código. Puedes volver a intentarlo cuando el reenvío esté disponible.";
    }
    if (data && data.ok && data.codigo === "CODIGO_CONFIRMACION_ENVIADO") {
      return data.channel === "whatsapp"
        ? "Código enviado por WhatsApp."
        : data.channel === "email" ? "Código enviado por correo electrónico." : "Código enviado.";
    }
    return data && data.mensaje || "Verifica tu contacto con el código vigente.";
  }

  // Recupera sólo con la identidad que ya está en memoria o que el usuario
  // acaba de capturar. La consulta no envía otro OTP ni crea almacenamiento.
  function withRecovery(request, recover) {
    return Promise.resolve().then(request).then(function(data) {
      if (data && (data.codigo || hasPolicy(data))) return data;
      throw new Error("Respuesta de envío no verificable");
    }).catch(function() {
      return recover().then(function(data) {
        if (!needsVerification(data)) throw new Error("Estado no disponible");
        return Object.assign({}, data, {
          codigo: "ESTADO_RECUPERADO",
          mensaje: "Consulta el código vigente y las opciones de reenvío. No pudimos comprobar el resultado del último envío."
        });
      });
    });
  }

  function create(options) {
    var button = options.button;
    var remainingText = options.remaining;
    var announcement = options.announcement;
    var timer = null;
    var revision = 0;
    var active = false;
    var busy = false;
    var blocked = false;
    var policy = null;
    var deadline = null;
    var previousState = "";

    function stopTimer() {
      if (timer !== null) global.clearInterval(timer);
      timer = null;
    }

    function render() {
      var remaining = policy ? nonNegative(policy.remaining_resends) : null;
      var seconds = deadline === null ? null : Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      var state = !active ? "inactive" : busy ? "busy" : remaining === 0 ? "limit"
        : remaining === null || seconds === null ? "unknown" : seconds > 0 ? "cooldown"
        : blocked || (policy && policy.can_send === false && !policy.next_resend_at) ? "blocked" : "ready";
      button.disabled = blocked || (policy && policy.can_send === false && !policy.next_resend_at) || state !== "ready";
      button.setAttribute("aria-busy", busy ? "true" : "false");
      // Base sexagesimal sólo para presentar MM:SS, no es el cooldown.
      var minute = 60;
      button.textContent = state === "busy" ? "Enviando código…"
        : state === "cooldown" ? "Reenviar código en "
          + String(Math.floor(seconds / minute)).padStart(2, "0") + ":"
          + String(seconds % minute).padStart(2, "0")
        : "Reenviar código";
      if (remainingText) remainingText.textContent = !active ? ""
        : remaining === 0 ? "No quedan reenvíos disponibles para este código."
        : remaining === null ? "No pudimos consultar los reenvíos disponibles. Vuelve a capturar tu contacto para consultar su estado."
        : remaining + (remaining === 1 ? " reenvío disponible" : " reenvíos disponibles");
      // Sólo se anuncian transiciones. El contador no pertenece a una región viva.
      if (announcement && state !== previousState) {
        announcement.textContent = state === "ready" ? "Ya puedes reenviar el código."
          : state === "limit" ? "Límite de reenvíos alcanzado. Puedes verificar el código vigente o cambiar de contacto."
          : state === "cooldown" ? "Espera a que termine el contador para reenviar el código."
          : state === "blocked" ? "El reenvío no está disponible. Consulta el estado de tu verificación." : "";
      }
      previousState = state;
      if (seconds === 0 || remaining === 0 || !active) stopTimer();
    }

    function update(data) {
      stopTimer();
      active = true;
      // Una respuesta incompleta no restablece el cupo ni habilita el botón.
      policy = {
        remaining_resends: data && data.remaining_resends,
        retry_after_seconds: data && data.retry_after_seconds,
        send_count: data && data.send_count,
        last_sent_at: data && data.last_sent_at,
        next_resend_at: data && data.next_resend_at,
        can_send: data && data.can_send
      };
      var retry = nonNegative(policy.retry_after_seconds);
      var next = Date.parse(policy.next_resend_at || "");
      deadline = retry !== null ? Date.now() + retry * 1000 : Number.isFinite(next) ? next : null;
      render();
      if (deadline > Date.now() && nonNegative(policy.remaining_resends) !== 0) {
        timer = global.setInterval(render, 1000);
      }
    }

    function reset() {
      revision++;
      stopTimer();
      active = false;
      busy = false;
      blocked = false;
      policy = null;
      deadline = null;
      render();
    }

    function run(request, onResult, onError) {
      render();
      if (button.disabled) return Promise.resolve();
      var currentRevision = revision;
      busy = true;
      render();
      return Promise.resolve().then(request).then(function(data) {
        if (currentRevision !== revision) return;
        update(data);
        if (onResult) onResult(data);
      }).catch(function(error) {
        if (currentRevision !== revision) return;
        update(null);
        if (onError) onError(error);
      }).finally(function() {
        if (currentRevision !== revision) return;
        busy = false;
        render();
      });
    }

    global.addEventListener("pagehide", reset);
    reset();
    return {
      update: update,
      reset: reset,
      run: run,
      isBusy: function() { return busy; },
      setBlocked: function(value) { blocked = Boolean(value); render(); },
      destroy: function() { reset(); global.removeEventListener("pagehide", reset); }
    };
  }

  global.ReservationResend = {
    create: create,
    hasPolicy: hasPolicy,
    needsVerification: needsVerification,
    message: message,
    withRecovery: withRecovery
  };
})(window);
