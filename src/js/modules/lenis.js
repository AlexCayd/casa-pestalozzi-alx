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

function fixedHeaderOffset() {
  var bottom = 0;
  [$(".brand-mark"), $("#navToggle")].forEach(function(el) {
    if (!el) return;
    var rect = el.getBoundingClientRect();
    if (rect.bottom > 0 && rect.top < window.innerHeight) bottom = Math.max(bottom, rect.bottom);
  });
  return Math.ceil(bottom + 16);
}

function scrollTo(target) {
  var el = typeof target === "string" ? $(target) : target;
  if (!el) return;
  if (el.id && window.location.hash !== "#" + el.id) {
    if (window.history && typeof window.history.pushState === "function") {
      window.history.pushState(null, "", "#" + el.id);
    } else {
      window.location.hash = el.id;
    }
  }
  var offset = fixedHeaderOffset();
  if (lenis) {
    lenis.scrollTo(el, { offset: -offset, duration: 1.3 });
  } else {
    window.scrollTo({
      top: Math.max(0, el.getBoundingClientRect().top + window.pageYOffset - offset),
      behavior: reduce ? "auto" : "smooth"
    });
  }
}
