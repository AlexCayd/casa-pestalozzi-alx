/* Animaciones de reveal + parallax imágenes */
function initReveals() {
  if (reduce) { body.classList.add("no-anim"); return; }

  $$("[data-reveal]").forEach(function(el) {
    if (el.closest(".hero__inner")) return;
    gsap.to(el, {
      opacity: 1, y: 0, duration: 1, ease: "power3.out",
      scrollTrigger: { trigger: el, start: "top 88%" }
    });
  });

  $$("[data-parallax-img] img, [data-parallax-img]").forEach(function(wrap) {
    var img = wrap.tagName === "IMG" ? wrap : wrap.querySelector("img");
    if (!img) return;
    gsap.fromTo(img, { yPercent: -8 }, {
      yPercent: 8, ease: "none",
      scrollTrigger: { trigger: wrap, start: "top bottom", end: "bottom top", scrub: true }
    });
  });
}
