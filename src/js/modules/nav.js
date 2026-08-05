/* Navegación overlay */
function initNav() {
  var toggle = $("#navToggle");
  toggle.addEventListener("click", function() { body.classList.toggle("nav-open"); });

  $$("[data-nav]").forEach(function(a) {
    a.addEventListener("click", function(e) {
      e.preventDefault();
      var id = a.getAttribute("href");
      body.classList.remove("nav-open");
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
      scrollTo(id);
    });
  });

  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") body.classList.remove("nav-open");
  });
}
