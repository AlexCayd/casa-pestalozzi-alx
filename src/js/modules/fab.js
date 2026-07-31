/* FAB flotante de reserva */
function initFab() {
  var fab = $("#reserveFab");
  var hero = $("#hero");
  if (!fab || !hero) return;
  var io = new IntersectionObserver(function(ent) {
    ent.forEach(function(e) { fab.classList.toggle("show", !e.isIntersecting); });
  }, { threshold: 0.2 });
  io.observe(hero);
}
