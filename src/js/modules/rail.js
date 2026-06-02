/* Rail de secciones (estado activo) */
function initRail() {
  var links = $$("#rail a");
  var map = {};
  links.forEach(function(l) { map[l.getAttribute("data-rail")] = l; });

  var io = new IntersectionObserver(function(entries) {
    entries.forEach(function(en) {
      if (en.isIntersecting) {
        links.forEach(function(l) { l.classList.remove("active"); });
        var m = map[en.target.id];
        if (m) m.classList.add("active");
      }
    });
  }, { rootMargin: "-45% 0px -45% 0px" });

  Object.keys(map).forEach(function(id) {
    var s = document.getElementById(id);
    if (s) io.observe(s);
  });
}
