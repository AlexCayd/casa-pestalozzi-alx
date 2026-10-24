/** Mejora progresiva para la edición de permisos por ámbito. */
(function () {
  "use strict";

  function initDirectUsersForm() {
    var form = document.querySelector("[data-user-direct-form]");
    if (!form) return;

    var scopes = Array.prototype.slice.call(form.querySelectorAll("[data-user-scope]"));
    var label = form.querySelector("[data-user-credential-label]");
    var fields = form.querySelector("[data-user-password-fields]");
    var password = form.querySelector("#direct-password");
    var confirmation = form.querySelector("#direct-password-confirm");
    var currentPassword = form.querySelector("[data-user-current-password]");
    var originalType = form.getAttribute("data-auth-type") || "ninguno";
    var remotePanel = form.getAttribute("data-remote-panel") === "1";
    var isEdit = form.getAttribute("data-edit") === "1";
    var identityEditable = form.getAttribute("data-identity-editable") === "1";
    var isSuper = form.getAttribute("data-super") === "1";

    function credentialType() {
      if (isSuper) return "contrasena";
      var hasFloor = false;
      var hasPanel = false;
      scopes.forEach(function (scope) {
        var active = scope.querySelector("[data-scope-toggle]");
        if (!active || !active.checked) return;
        Array.prototype.forEach.call(scope.querySelectorAll("[data-module-level]"), function (select) {
          if (!select.value) return;
          var row = select.closest("[data-module-row]");
          if (row && row.getAttribute("data-group") === "piso") hasFloor = true;
          else hasPanel = true;
        });
      });
      return hasPanel || remotePanel ? "contrasena" : (hasFloor ? "nip" : "ninguno");
    }

    function sync() {
      var type = credentialType();
      form.setAttribute("data-resolved-auth-type", type);
      if (label) {
        label.textContent = type === "contrasena"
          ? "Contraseña requerida"
          : (type === "nip" ? "Acceso operativo por NIP" : "Sin acceso");
        label.className = "admin-tag admin-role admin-role--" + (type === "contrasena" ? "admin" : (type === "nip" ? "waiter" : "cook"));
      }
      if (fields) {
        fields.hidden = type !== "contrasena" || !identityEditable;
        Array.prototype.forEach.call(fields.querySelectorAll("input"), function (input) { input.disabled = fields.hidden; });
      }
      if (password) {
        var required = type === "contrasena" && (!isEdit || originalType !== "contrasena");
        password.required = required;
        if (confirmation) confirmation.required = required;
      }
      if (currentPassword) {
        var credentialChanged = type !== originalType || (password && password.value !== "");
        currentPassword.hidden = !isEdit || !identityEditable || !credentialChanged;
        var currentInput = currentPassword.querySelector("input");
        if (currentInput) currentInput.required = isEdit && identityEditable && credentialChanged;
      }
    }

    scopes.forEach(function (scope) {
      var membership = scope.querySelector("[data-scope-toggle]");
      function syncScope() {
        if (!membership) return;
        var active = membership.checked;
        var table = scope.querySelector(".admin-table-wrap");
        if (table) table.hidden = !active;
        Array.prototype.forEach.call(scope.querySelectorAll("fieldset:not([data-user-scope])"), function (fieldset) { fieldset.hidden = !active; });
        Array.prototype.forEach.call(scope.querySelectorAll("[data-module-level], input[type=hidden][name*='[permisos]'], input[type=checkbox][name*='[facultades]']"), function (input) {
          input.disabled = !active || input.getAttribute("data-protected") === "1";
        });
      }
      if (membership) membership.addEventListener("change", function () { syncScope(); sync(); });
      Array.prototype.forEach.call(scope.querySelectorAll("[data-module-level]"), function (select) {
        select.addEventListener("change", sync);
      });
      syncScope();
    });
    if (password) password.addEventListener("input", sync);
    sync();
  }

  function initDeleteConfirmation() {
    var modal = document.querySelector("[data-user-delete-modal]");
    if (!modal) return;
    var title = modal.querySelector("[data-modal-title]");
    var description = modal.querySelector("[data-modal-description]");
    var username = modal.querySelector("[data-modal-username]");
    var input = modal.querySelector("[data-modal-input]");
    var confirm = modal.querySelector("[data-modal-confirm]");
    var activeForm = null;
    var activeName = "";
    var shared = false;
    var scopeName = "";

    function close() {
      modal.hidden = true;
      activeForm = null;
      input.value = "";
      confirm.disabled = true;
    }
    Array.prototype.forEach.call(document.querySelectorAll("[data-user-delete]"), function (button) {
      button.addEventListener("click", function () {
        activeForm = button.closest("form");
        activeName = button.getAttribute("data-user-name") || "";
        shared = button.getAttribute("data-delete-scope") === "1";
        scopeName = button.getAttribute("data-scope-name") || "este ámbito";
        username.textContent = activeName;
        title.textContent = shared ? "Revocar acceso" : "Eliminar usuario";
        description.firstChild.textContent = shared
          ? "Se revocará el acceso de esta persona a " + scopeName + ". La identidad y sus otros ámbitos seguirán activos. Confirma su nombre: "
          : "Esta acción eliminará la identidad y todos sus accesos. Confirma el nombre de la persona: ";
        confirm.textContent = shared ? "Revocar acceso" : "Eliminar usuario";
        input.setAttribute("aria-label", "Escribe el nombre para confirmar " + (shared ? "la revocación" : "la eliminación"));
        modal.hidden = false;
        input.focus();
      });
    });
    input.addEventListener("input", function () { confirm.disabled = input.value.trim() !== activeName; });
    confirm.addEventListener("click", function () { if (activeForm && input.value.trim() === activeName) activeForm.submit(); });
    Array.prototype.forEach.call(modal.querySelectorAll("[data-modal-close]"), function (button) { button.addEventListener("click", close); });
    document.addEventListener("keydown", function (event) { if (event.key === "Escape" && !modal.hidden) close(); });
  }

  function initNipDelivery() {
    var trigger = document.querySelector("[data-user-nip-delivery]");
    if (!trigger || !window.ConfirmationModal) return;
    var code = trigger.getAttribute("data-nip") || "";
    if (!/^\d{4}$/.test(code)) return;

    var afterUrl = trigger.getAttribute("data-after-url") || "";
    var modal = window.ConfirmationModal.get();
    var content = document.createElement("div");
    var label = document.createElement("span");
    var value = document.createElement("strong");
    content.className = "admin-user-nip-modal__code";
    label.className = "admin-user-nip-modal__label";
    label.textContent = "NIP de acceso";
    value.textContent = code;
    value.setAttribute("aria-label", "NIP de cuatro dígitos");
    content.appendChild(label);
    content.appendChild(value);

    function finish() {
      trigger.remove();
      content.replaceChildren();
      code = "";
      modal.close(true);
      if (afterUrl) window.location.assign(afterUrl);
    }

    modal.open({
      variant: "default",
      extraClass: "confirmation-modal--user-nip",
      title: "NIP generado",
      description: "Este código sólo se muestra una vez. Entrégalo a la persona antes de continuar.",
      customContent: content,
      secondaryLabel: "Cerrar",
      primaryLabel: "Listo",
      secondaryCloses: false,
      closeBehavior: "non_cancelable",
      closeHidden: true,
      initialFocus: "primary",
      onSecondary: finish,
      onPrimary: finish
    });
  }

  function boot() {
    initDirectUsersForm();
    initDeleteConfirmation();
    initNipDelivery();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
