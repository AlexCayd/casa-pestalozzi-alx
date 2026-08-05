/* Smooth scroll — Lenis */
var lenis = null;

function startLenis() {
  if (lenis || isTouch || reduce || !window.Lenis) return;
  lenis = new Lenis({ duration: 1.15, easing: function(t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); }, smoothWheel: true });
  lenis.on("scroll", function() { if (window.ScrollTrigger) ScrollTrigger.update(); });
  gsap.ticker.add(lenisRaf);
  gsap.ticker.lagSmoothing(0);
}

function lenisRaf(time) { if (lenis) lenis.raf(time * 1000); }

function stopLenis() {
  if (!lenis) return;
  gsap.ticker.remove(lenisRaf);
  lenis.destroy();
  lenis = null;
}

function scrollTo(target) {
  var el = typeof target === "string" ? $(target) : target;
  if (!el) return;
  if (lenis) lenis.scrollTo(el, { offset: 0, duration: 1.3 });
  else el.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
}
