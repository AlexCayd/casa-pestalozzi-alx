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
  if (reduce || !window.gsap) { gsap.set("[data-split] .char", { y: 0, opacity: 1 }); return; }

  var tl = gsap.timeline({ delay: 0.25 });
  gsap.set(chars, { yPercent: 115, opacity: 0 });
  tl.to(chars, { yPercent: 0, opacity: 1, duration: 1.1, stagger: 0.045, ease: "power4.out" });
  tl.from(".hero__eyebrow", { opacity: 0, y: 20, duration: 0.8 }, 0.2);
  tl.to($$(".hero__inner [data-reveal]"), { opacity: 1, y: 0, duration: 0.9, stagger: 0.12, ease: "power3.out" }, "-=0.7");

  var bg = $("[data-parallax-bg] img");
  if (bg) gsap.to(bg, { yPercent: -10, ease: "none", scrollTrigger: { trigger: "#hero", start: "top top", end: "bottom top", scrub: true } });
}
