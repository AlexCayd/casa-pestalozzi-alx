/* Contadores animados */
function initCounters() {
  $$("[data-count]").forEach(function(el) {
    var target = parseFloat(el.getAttribute("data-count"));
    var suffix = el.getAttribute("data-suffix") || "";
    ScrollTrigger.create({
      trigger: el, start: "top 90%", once: true,
      onEnter: function() {
        if (reduce) { el.textContent = target + suffix; return; }
        var o = { v: 0 };
        gsap.to(o, { v: target, duration: 1.6, ease: "power2.out", onUpdate: function() { el.textContent = Math.round(o.v) + suffix; } });
      }
    });
  });
}
