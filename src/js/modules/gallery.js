/* Galería "Lo mejor de la casa" — drag to scroll */
var GALLERY = [
  { img: "/build/images/mejor-2.webp", n: "Tagliatelle al Limón", t: "Pasta" },
  { img: "/build/images/mejor-5.webp", n: "Camarones a la Brasa", t: "Mariscos" },
  { img: "/build/images/pizza-3.webp", n: "Pizza al Horno de Piedra", t: "Pizzas" },
  { img: "/build/images/mejor-3.webp", n: "Tostas de la Casa", t: "Para Picar" },
  { img: "/build/images/mejor-4.webp", n: "Espresso Martini", t: "Coctelería" },
  { img: "/build/images/mejor-6.webp", n: "Rib Eye 400 gr", t: "Cortes" },
  { img: "/build/images/mejor-1.webp", n: "Aceitunas Temperadas", t: "Aperitivo" }
];

function initGallery() {
  var track = $("#galleryTrack");
  GALLERY.forEach(function(g) {
    var c = document.createElement("div");
    c.className = "gcard";
    c.setAttribute("data-cursor", "Arrastrar");
    c.setAttribute("data-zoom", "");
    c.setAttribute("data-zoom-name", g.n);
    c.setAttribute("data-zoom-cat", g.t);
    c.innerHTML = '<img src="' + g.img + '" alt="' + g.n + '" loading="lazy" draggable="false" /><div class="gcard__cap"><div class="t">' + g.t + '</div><div class="n">' + g.n + '</div></div>';

    // Direct click handler — bypasses event delegation issues with pointer capture
    (function(card) {
      card.addEventListener("click", function(e) {
        e.stopPropagation();
        if (!suppressClick && window.__openZoom) window.__openZoom(card);
      });
    })(c);

    track.appendChild(c);
  });

  var isDown = false, startX = 0, scrollStart = 0, moved = 0, pos = 0;
  var max = function() { return Math.max(0, track.scrollWidth - track.clientWidth + 8); };
  function setPos(p) { pos = Math.max(-max(), Math.min(0, p)); track.style.transform = "translateX(" + pos + "px)"; }

  track.addEventListener("pointerdown", function(e) { isDown = true; moved = 0; startX = e.clientX; scrollStart = pos; track.setPointerCapture(e.pointerId); });
  track.addEventListener("pointermove", function(e) {
    if (!isDown) return;
    var dx = e.clientX - startX;
    moved += Math.abs(e.movementX || 0);
    if (moved > 15) suppressClick = true;
    setPos(scrollStart + dx);
  });
  var up = function() { isDown = false; setTimeout(function() { suppressClick = false; }, 60); };
  track.addEventListener("pointerup", up);
  track.addEventListener("pointercancel", up);
  track.addEventListener("pointerleave", up);
  track.addEventListener("wheel", function(e) {
    if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) { e.preventDefault(); setPos(pos - e.deltaX); }
  }, { passive: false });
}
