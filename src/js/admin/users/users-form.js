/**
 * Interacciones del módulo de usuarios.
 *
 * El navegador sólo refleja el rol elegido y confirma acciones; nunca genera,
 * valida disponibilidad ni conserva credenciales de piso.
 */
(function () {
  "use strict";

  function initUserForm() {
    var form = document.querySelector("[data-users-form]");
    if (!form) return;

    var passwordSection = form.querySelector("[data-user-password-section]");
    var roleHint = form.querySelector("[data-role-credential-hint]");
    var accessList = form.querySelector("[data-role-access-list]");
    var nipSection = form.querySelector("[data-role-nip-section]");
    var nipState = form.querySelector("[data-role-nip-state]");
    var nipHint = form.querySelector("[data-role-nip-hint]");
    var nipPendingHint = form.querySelector("[data-role-nip-pending]");
    var roles = Array.prototype.slice.call(form.querySelectorAll("[data-user-role]"));
    var originalRole = form.getAttribute("data-original-role") || "";
    var mode = form.getAttribute("data-form-mode") || "crear";
    var hasPersistedNip = form.getAttribute("data-has-persisted-nip") === "1";

    function role() {
      var selected = roles.filter(function (input) { return input.checked; })[0];
      return selected ? selected.value : "";
    }

    function escapeHtml(value) {
      var div = document.createElement("div");
      div.textContent = String(value == null ? "" : value);
      return div.innerHTML;
    }

    function renderAccess() {
      if (!accessList) return;
      var areas = (window.AdminUserRoleAccess || {})[role()] || [];
      accessList.innerHTML = areas.map(function (area) {
        return '<li class="admin-role-access__item"><strong>' +
          escapeHtml(area.titulo) + '</strong><span>' + escapeHtml(area.detalle) + '</span></li>';
      }).join("");
    }

    function applyRole() {
      var selectedRole = role();
      var isAdmin = selectedRole === "admin";
      var isStaff = selectedRole === "waiter" || selectedRole === "cook";
      var isPromotion = isStaff && mode === "editar" && originalRole === "admin";
      var showConfiguredNip = isStaff && hasPersistedNip;
      var showPendingNip = isStaff && mode === "editar" && !hasPersistedNip;

      if (roleHint) {
        if (isAdmin) {
          roleHint.textContent = "El administrador entra con usuario y contraseña.";
        } else if (mode === "editar" && showPendingNip) {
          roleHint.textContent = "Se generará un NIP automáticamente al guardar los cambios.";
        } else if (mode === "editar" && showConfiguredNip) {
          roleHint.textContent = "El NIP actual no puede consultarse; regénéralo sólo si se extravió.";
        } else {
          roleHint.textContent = "El NIP se genera automáticamente al crear el usuario.";
        }
      }

      if (passwordSection) {
        var optional = passwordSection.hasAttribute("data-user-password-optional");
        passwordSection.hidden = !isAdmin;
        passwordSection.querySelectorAll("input").forEach(function (input) {
          input.disabled = !isAdmin;
          input.required = isAdmin && (!optional || isPromotion);
        });
      }

      if (nipSection) {
        nipSection.hidden = !(showConfiguredNip || showPendingNip);
        if (nipState) nipState.hidden = !showConfiguredNip;
        if (nipHint) nipHint.hidden = !showConfiguredNip;
        if (nipPendingHint) nipPendingHint.hidden = !showPendingNip;
        var regenerate = nipSection.querySelector("[data-user-regenerate]");
        if (regenerate) regenerate.hidden = !showConfiguredNip;
      }

      renderAccess();
    }

    roles.forEach(function (input) { input.addEventListener("change", applyRole); });
    applyRole();
  }

  function associatedForm(button) {
    if (!button) return null;
    if (button.form) return button.form;
    var formId = button.getAttribute("form");
    if (formId) return document.getElementById(formId);
    return button.closest("form");
  }

  function initRegenerateConfirmation() {
    document.querySelectorAll("[data-user-regenerate]").forEach(function (button) {
      button.addEventListener("click", function (event) {
        var form = associatedForm(button);
        if (!form || form.getAttribute("data-regenerate-confirmed") === "1") return;

        event.preventDefault();
        var submit = function () {
          form.setAttribute("data-regenerate-confirmed", "1");
          form.submit();
        };

        if (!window.ConfirmationModal) {
          submit();
          return;
        }

        window.ConfirmationModal.get().open({
          variant: "danger",
          eyebrow: "Acceso de piso",
          title: "Regenerar NIP",
          description: "El NIP actual dejará de funcionar inmediatamente.",
          consequence: "Se generará un código nuevo y sólo podrás consultarlo una vez. Asegúrate de entregárselo a la persona antes de cerrar la confirmación.",
          secondaryLabel: "Cancelar",
          primaryLabel: "Regenerar NIP",
          returnFocus: button,
          onPrimary: submit
        });
      });
    });
  }

  function copyWithFallback(value) {
    return new Promise(function (resolve, reject) {
      var textarea = document.createElement("textarea");
      textarea.value = value;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "fixed";
      textarea.style.top = "-1000px";
      textarea.style.opacity = "0";
      document.body.appendChild(textarea);
      textarea.select();
      textarea.setSelectionRange(0, textarea.value.length);

      var copied = false;
      try {
        copied = document.execCommand("copy");
      } catch (error) {
        copied = false;
      }
      textarea.remove();
      if (copied) resolve();
      else reject(new Error("Clipboard API unavailable"));
    });
  }

  function copyNip(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      return navigator.clipboard.writeText(value).catch(function () {
        return copyWithFallback(value);
      });
    }
    return copyWithFallback(value);
  }

  function initNipDelivery() {
    var trigger = document.querySelector("[data-user-nip-delivery]");
    if (!trigger || !window.ConfirmationModal) return;

    var value = trigger.getAttribute("data-nip") || "";
    if (!/^\d{4}$/.test(value)) return;

    var afterUrl = trigger.getAttribute("data-after-url") || "";
    var returnFocus = document.querySelector("[data-user-regenerate]");
    var controller = window.ConfirmationModal.get();
    var statusTimer = null;
    var copyButtonTimer = null;
    var customContent = document.createElement("div");
    customContent.className = "admin-user-nip-modal__code";
    customContent.innerHTML =
      '<span class="admin-user-nip-modal__label">Código temporal</span>' +
      '<strong aria-label="NIP de cuatro dígitos">' + value + '</strong>';

    function showCopyStatus(message, isError) {
      controller.setStatus(message, isError);
      if (statusTimer) window.clearTimeout(statusTimer);
      if (copyButtonTimer) window.clearTimeout(copyButtonTimer);
      var copyButton = controller.element.querySelector("[data-confirmation-secondary]");
      if (copyButton) copyButton.textContent = isError ? "Copiar NIP" : "Copiado";
      if (!isError) {
        statusTimer = window.setTimeout(function () {
          controller.setStatus("", false);
          if (copyButton) copyButton.textContent = "Copiar NIP";
        }, 1400);
        copyButtonTimer = statusTimer;
      }
    }

    function cleanup() {
      if (statusTimer) window.clearTimeout(statusTimer);
      if (copyButtonTimer) window.clearTimeout(copyButtonTimer);
      trigger.remove();
      customContent.replaceChildren();
    }

    controller.open({
      variant: "default",
      extraClass: "confirmation-modal--user-nip",
      eyebrow: "Entrega de acceso",
      title: "NIP generado",
      description: "Este código sólo se mostrará una vez. Entrégalo a la persona antes de continuar.",
      customContent: customContent,
      secondaryLabel: "Copiar NIP",
      primaryLabel: "Aceptar",
      secondaryCloses: false,
      closeBehavior: "non_cancelable",
      closeHidden: true,
      initialFocus: "secondary",
      returnFocus: returnFocus,
      onSecondary: function () {
        copyNip(value).then(function () {
          showCopyStatus("Copiado", false);
        }).catch(function () {
          showCopyStatus("No se pudo copiar el NIP. Inténtalo nuevamente.", true);
        });
      },
      onPrimary: function () {
        cleanup();
        controller.close(true);
        if (afterUrl) window.location.assign(afterUrl);
      }
    });
  }

  function boot() {
    initUserForm();
    initRegenerateConfirmation();
    initNipDelivery();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
