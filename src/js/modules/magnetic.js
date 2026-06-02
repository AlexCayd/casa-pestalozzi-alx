/* Botones magnéticos */
function initMagnetic() {
  if (isTouch || reduce) return;
  $$("[data-magnetic]").forEach(function(el) {
    el.addEventListener("mousemove", function(e) {
      var r = el.getBoundingClientRect();
      var x = e.clientX - r.left - r.width / 2;
      var y = e.clientY - r.top - r.height / 2;
      gsap.to(el, { x: x * 0.35, y: y * 0.35, duration: 0.5, ease: "power3.out" });
    });
    el.addEventListener("mouseleave", function() {
      gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: "elastic.out(1,0.4)" });
    });
  });
}
