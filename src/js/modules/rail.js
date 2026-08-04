/* Rail de secciones (estado activo) */
function initRail() {
  var rail = $("#rail");
  if (!rail || rail.dataset.railInitialized === "true") return;
  rail.dataset.railInitialized = "true";

  var links = Array.from(rail.querySelectorAll("a[href^='#'][data-rail]"));
  if (!links.length) return;
  var map = {};
  links.forEach(function(l) { map[l.getAttribute("data-rail")] = l; });

  function setActive(id) {
    links.forEach(function(link) {
      var active = link.getAttribute("data-rail") === id;
      link.classList.toggle("active", active);
      if (active) link.setAttribute("aria-current", "location");
      else link.removeAttribute("aria-current");
    });
  }

  links.forEach(function(link) {
    link.addEventListener("click", function(event) {
      var id = link.getAttribute("data-rail");
      var target = id ? document.getElementById(id) : null;
      if (!target) return;
      event.preventDefault();
      setActive(id);
      scrollTo(target);
    });
    link.addEventListener("keydown", function(event) {
      if (event.key !== " ") return;
      event.preventDefault();
      link.click();
    });
  });

  var io = new IntersectionObserver(function(entries) {
    entries.forEach(function(en) {
      if (en.isIntersecting) {
        links.forEach(function(l) { l.classList.remove("active"); });
        var m = map[en.target.id];
        if (m) setActive(en.target.id);
      }
    });
  }, { rootMargin: "-45% 0px -45% 0px" });

  Object.keys(map).forEach(function(id) {
    var s = document.getElementById(id);
    if (s) io.observe(s);
  });

  var initialId = window.location.hash.replace(/^#/, "");
  if (initialId && map[initialId]) setActive(initialId);
}
