/* Hero — split-text + intro + parallax */
function splitTitle() {
  var el = $("[data-split]");
  if (!el) return [];
  var lines = [[]];
  el.childNodes.forEach(function(node) {
    if (node.nodeName === "BR") lines.push([]);
    else (node.textContent || "").split("").forEach(function(ch) { lines[lines.length - 1].push(ch); });
  });
  el.innerHTML = "";
  var chars = [];
  lines.forEach(function(ln) {
    if (!ln.length) return;
    var word = document.createElement("span");
    word.className = "word";
    ln.forEach(function(ch) {
      var s = document.createElement("span");
      s.className = "char";
      s.textContent = ch;
      word.appendChild(s);
      chars.push(s);
    });
    el.appendChild(word);
  });
  return chars;
}

function heroIntro() {
  var chars = splitTitle();
  // Sin GSAP no se puede usar GSAP para dejar el título visible: la rama que
  // atiende ese caso llamaba a gsap.set() y habría reventado con un
  // ReferenceError. Hoy no se llega porque boot() protege la llamada desde
  // fuera, pero la guarda tiene que sostenerse sola.
  if (reduce || !window.gsap) {
    chars.forEach(function(s) { s.style.transform = "none"; s.style.opacity = 1; });
    return;
  }

  // El hero dejó de tener un .hero__inner: ahora son dos bandas hermanas
  // (centro / pie), así que la intro busca dentro de #hero.
  var entra = $$("#hero [data-reveal]");

  var tl = gsap.timeline({ delay: 0.25 });
  gsap.set(chars, { yPercent: 115, opacity: 0 });
  tl.to(chars, { yPercent: 0, opacity: 1, duration: 1.1, stagger: 0.045, ease: "power4.out" });
  tl.to(entra, { opacity: 1, y: 0, duration: 0.9, stagger: 0.12, ease: "power3.out" }, "-=0.7");

  // El velo arranca cerrado y se abre con el título: es lo que convierte la
  // entrada en un fundido de cartel en vez de una foto que ya estaba ahí.
  tl.from(".hero__velo", { opacity: 1.6, duration: 1.6, ease: "power2.out" }, 0);

  var bg = $("[data-parallax-bg] img");
  if (bg) gsap.to(bg, { yPercent: -10, ease: "none", scrollTrigger: { trigger: "#hero", start: "top top", end: "bottom top", scrub: true } });

  // Salida del hero: las bandas se van hacia arriba y se desvanecen mientras
  // la portada se queda. Sin esto el texto blanco cruzaba el borde de la
  // siguiente sección clara y durante medio segundo no se leía nada.
  gsap.to(".hero__banda", {
    yPercent: -22,
    opacity: 0,
    ease: "none",
    stagger: 0.04,
    scrollTrigger: { trigger: "#hero", start: "40% top", end: "bottom top", scrub: true },
  });
}
