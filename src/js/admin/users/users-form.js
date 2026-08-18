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
    var roles = Array.prototype.slice.call(form.querySelectorAll("[data-user-role]"));
    var originalRole = form.getAttribute("data-original-role") || "";

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
      var isAdmin = role() === "admin";
      var isPromotion = isAdmin && originalRole && originalRole !== "admin";

      if (roleHint) {
        roleHint.textContent = isAdmin
          ? "El administrador entra con usuario y contraseña."
          : "El NIP se genera automáticamente al crear el usuario.";
      }

      if (passwordSection) {
        var optional = passwordSection.hasAttribute("data-user-password-optional");
        passwordSection.hidden = !isAdmin;
        passwordSection.querySelectorAll("input").forEach(function (input) {
          input.disabled = !isAdmin;
          input.required = isAdmin && (!optional || isPromotion);
        });
      }

      renderAccess();
    }

    roles.forEach(function (input) { input.addEventListener("change", applyRole); });
    applyRole();
  }

  function initRegenerateConfirmation() {
    document.querySelectorAll("[data-user-regenerate]").forEach(function (button) {
      button.addEventListener("click", function (event) {
        var form = button.closest("form");
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
          title: "¿Regenerar NIP?",
          description: "El NIP actual dejará de funcionar inmediatamente.",
          consequence: "El nuevo código sólo se mostrará una vez. Entrégalo al usuario ahora.",
          secondaryLabel: "Cancelar",
          primaryLabel: "Regenerar NIP",
          onPrimary: submit
        });
      });
    });
  }

  function initOneTimeNip() {
    document.querySelectorAll("[data-copy-nip]").forEach(function (button) {
      button.addEventListener("click", function () {
        var value = document.querySelector("[data-nip-once-value]");
        if (!value || !navigator.clipboard) return;
        navigator.clipboard.writeText(value.textContent.trim()).then(function () {
          var original = button.textContent;
          button.textContent = "Copiado";
          window.setTimeout(function () { button.textContent = original; }, 1400);
        });
      });
    });
  }

  function boot() {
    initUserForm();
    initRegenerateConfirmation();
    initOneTimeNip();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
