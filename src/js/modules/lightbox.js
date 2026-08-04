/* Lightbox universal — cualquier [data-zoom] */
function injectBadges() {
  $$("[data-zoom]").forEach(function(el) {
    if (el.classList.contains("dish__thumb")) return;
    if (el.classList.contains("gcard")) return;
    if (el.querySelector(":scope > .zoom-badge")) return;
    var b = document.createElement("span");
    b.className = "zoom-badge";
    b.textContent = "⤢ Ampliar";
    el.appendChild(b);
    if (!el.hasAttribute("data-cursor")) el.setAttribute("data-cursor", "Ampliar");
    if (!/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) {
      el.setAttribute("tabindex", "0");
      el.setAttribute("role", "button");
    }
    el.setAttribute("aria-haspopup", "dialog");
    if (!el.hasAttribute("aria-label")) {
      var image = el.tagName === "IMG" ? el : el.querySelector("img");
      el.setAttribute("aria-label", "Ampliar " + ((el.getAttribute("data-zoom-name") || (image && image.alt) || "imagen")));
    }
  });
}

function getZoomList() {
  var list = [];
  $$("[data-zoom]").forEach(function(el) {
    var img = el.tagName === "IMG" ? el : el.querySelector("img");
    var src = el.getAttribute("data-zoom-src") || (img && (img.currentSrc || img.getAttribute("src")));
    if (!src) return;
    list.push({ src: src, n: el.getAttribute("data-zoom-name") || (img && img.alt) || "", t: el.getAttribute("data-zoom-cat") || "", el: el });
  });
  return list;
}

function initLightbox() {
  injectBadges();
  var lb = $("#lightbox"), lbImg = $("#lbImg"), lbN = $("#lbN"), lbT = $("#lbT"), lbCur = $("#lbCur"), lbTotal = $("#lbTotal");
  var list = [], idx = 0;
  var lastFocus = null;

  function focusables() {
    return $$('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])', lb);
  }

  function render() {
    var g = list[idx]; if (!g) return;
    lbImg.src = g.src; lbImg.alt = g.n; lbN.textContent = g.n; lbT.textContent = g.t;
    lbCur.textContent = idx + 1; lbTotal.textContent = list.length;
    if (!reduce && window.gsap) gsap.fromTo(lbImg, { opacity: 0, scale: 0.97 }, { opacity: 1, scale: 1, duration: 0.45, ease: "power2.out" });
  }

  function groupId(el) { var s = el.closest("section, header"); return s ? s.id : ""; }

  function open(el) {
    lastFocus = el;
    var g = groupId(el);
    list = getZoomList().filter(function(z) { return groupId(z.el) === g; });
    if (!list.length) list = getZoomList();
    idx = Math.max(0, list.findIndex(function(z) { return z.el === el; }));
    render();
    lb.classList.add("open"); lb.setAttribute("aria-hidden", "false"); lb.setAttribute("aria-modal", "true"); lb.inert = false;
    document.body.style.overflow = "hidden";
    if (lenis) lenis.stop();
    window.requestAnimationFrame(function() {
      var closeButton = $("#lbClose");
      if (closeButton) closeButton.focus();
    });
  }

  function close() {
    lb.classList.remove("open"); lb.setAttribute("aria-hidden", "true"); lb.inert = true;
    document.body.style.overflow = "";
    if (lenis) lenis.start();
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    lastFocus = null;
  }

  function nav(d) { if (!list.length) return; idx = (idx + d + list.length) % list.length; render(); }

  document.addEventListener("click", function(e) {
    var z = e.target.closest("[data-zoom]");
    if (!z || suppressClick) return;
    e.preventDefault();
    open(z);
  });

  document.addEventListener("keydown", function(e) {
    var zoomTarget = e.target.closest && e.target.closest("[data-zoom]");
    if (!zoomTarget || lb.classList.contains("open")) return;
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      open(zoomTarget);
    }
  });

  $("#lbClose").addEventListener("click", function(e) { e.stopPropagation(); close(); });
  $("#lbPrev").addEventListener("click", function(e) { e.stopPropagation(); nav(-1); });
  $("#lbNext").addEventListener("click", function(e) { e.stopPropagation(); nav(1); });
  lb.addEventListener("click", function(e) { if (!e.target.closest(".lightbox__img, button, .lightbox__cap")) close(); });

  document.addEventListener("keydown", function(e) {
    if (!lb.classList.contains("open")) return;
    if (e.key === "Escape") { e.preventDefault(); close(); return; }
    if (e.key === "ArrowRight") { e.preventDefault(); nav(1); return; }
    if (e.key === "ArrowLeft") { e.preventDefault(); nav(-1); return; }
    if (e.key !== "Tab") return;
    var items = focusables();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  var sx = 0;
  lb.addEventListener("touchstart", function(e) { sx = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener("touchend", function(e) { var dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) nav(dx < 0 ? 1 : -1); });

  window.__openZoom = open;
}
