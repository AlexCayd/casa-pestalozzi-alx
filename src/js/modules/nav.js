/* Navegación overlay */
function initNav() {
  var toggle = $("#navToggle");
  var nav = $("#navOverlay");
  if (!toggle || !nav) return;
  var lastFocus = null;

  function focusables() {
    return $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', nav);
  }

  function setOpen(open, restoreFocus) {
    body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    toggle.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
    nav.setAttribute("aria-hidden", open ? "false" : "true");
    nav.inert = !open;

    if (open) {
      lastFocus = document.activeElement;
      window.requestAnimationFrame(function() {
        var first = focusables()[0];
        if (first) first.focus();
      });
    } else if (restoreFocus && lastFocus && document.contains(lastFocus)) {
      lastFocus.focus();
      lastFocus = null;
    }
  }

  setOpen(false, false);
  toggle.addEventListener("click", function() {
    setOpen(!body.classList.contains("nav-open"), true);
  });

  $$("[data-nav]").forEach(function(a) {
    a.addEventListener("click", function(e) {
      e.preventDefault();
      var id = a.getAttribute("href");
      setOpen(false, false);
      setTimeout(function() { scrollTo(id); }, 200);
    });
  });

  // brand + rail + footer + anchors in-page
  $$('a[href^="#"]').forEach(function(a) {
    if (a.hasAttribute("data-nav")) return;
    a.addEventListener("click", function(e) {
      var id = a.getAttribute("href");
      if (id.length < 2 || !$(id)) return;
      e.preventDefault();
      if (a.classList.contains("skip-link")) {
        var target = $(id);
        target.focus({ preventScroll: true });
        target.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
        return;
      }
      scrollTo(id);
    });
    if (a.classList.contains("skip-link")) {
      a.addEventListener("keydown", function(e) {
        if (e.key !== "Enter" && e.key !== " ") return;
        var target = $(a.getAttribute("href"));
        if (!target) return;
        e.preventDefault();
        target.focus({ preventScroll: true });
        target.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
      });
    }
  });

  document.addEventListener("keydown", function(e) {
    if (!body.classList.contains("nav-open")) return;
    if (e.key === "Escape") {
      e.preventDefault();
      setOpen(false, true);
      return;
    }
    if (e.key !== "Tab") return;
    var items = focusables();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });
}
