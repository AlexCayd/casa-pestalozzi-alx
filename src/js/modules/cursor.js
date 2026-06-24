/* Cursor personalizado */
function initCursor() {
  if (isTouch) return;
  var dot = $(".cursor-dot"), ring = $(".cursor-ring"), label = $(".clabel");
  var mx = innerWidth / 2, my = innerHeight / 2, rx = mx, ry = my;

  window.addEventListener("mousemove", function(e) {
    mx = e.clientX; my = e.clientY;
    dot.style.transform = "translate(" + mx + "px," + my + "px) translate(-50%,-50%)";
  });

  function raf() {
    rx += (mx - rx) * 0.18;
    ry += (my - ry) * 0.18;
    ring.style.transform = "translate(" + rx + "px," + ry + "px) translate(-50%,-50%)";
    requestAnimationFrame(raf);
  }
  raf();

  var hoverSel = "a, button, .dish, .gcard, [data-magnetic], [data-zoom], [data-cursor], input, select, textarea, .pill, .tw-opt, .tw-swatch, .tw-switch";

  document.addEventListener("mouseover", function(e) {
    var t = e.target.closest(hoverSel);
    if (!t) return;
    ring.classList.add("hover");
    var cl = t.getAttribute("data-cursor");
    if (cl) { ring.classList.add("labeled"); label.textContent = cl; }
  });

  document.addEventListener("mouseout", function(e) {
    var t = e.target.closest(hoverSel);
    if (!t) return;
    ring.classList.remove("hover", "labeled");
    label.textContent = "";
  });
}

function setCursorEnabled(on) {
  body.classList.toggle("no-cursor", !on);
  var dot  = document.querySelector(".cursor-dot");
  var ring = document.querySelector(".cursor-ring");
  if (dot)  dot.style.display  = on && !isTouch ? "" : "none";
  if (ring) ring.style.display = on && !isTouch ? "" : "none";
  document.documentElement.style.setProperty("cursor", on && !isTouch ? "none" : "auto");
  body.style.cursor = on && !isTouch ? "none" : "auto";
}
