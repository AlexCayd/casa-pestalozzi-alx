/* ============================================================
   CASA PESTALOZZI — Rediseño · interacciones (GSAP + Lenis)
   ============================================================ */
(function () {
  "use strict";
  const $ = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const body = document.body;
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const isTouch = window.matchMedia("(pointer: coarse)").matches;
  const T = Object.assign({ hero: "cinema", accent: "oro", cursor: true, smooth: true, anim: true }, window.CP_TWEAKS || {});
  let suppressClick = false; // set true briefly after a gallery drag so it doesn't open the lightbox

  document.getElementById("year").textContent = new Date().getFullYear();
  if (window.gsap && window.ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

  /* ---------------- Lenis smooth scroll ---------------- */
  let lenis = null;
  function startLenis() {
    if (lenis || isTouch || reduce || !window.Lenis) return;
    lenis = new Lenis({ duration: 1.15, easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)), smoothWheel: true });
    lenis.on("scroll", () => { if (window.ScrollTrigger) ScrollTrigger.update(); });
    gsap.ticker.add(lenisRaf);
    gsap.ticker.lagSmoothing(0);
  }
  function lenisRaf(time) { if (lenis) lenis.raf(time * 1000); }
  function stopLenis() { if (!lenis) return; gsap.ticker.remove(lenisRaf); lenis.destroy(); lenis = null; }
  function scrollTo(target) {
    const el = typeof target === "string" ? $(target) : target;
    if (!el) return;
    if (lenis) lenis.scrollTo(el, { offset: 0, duration: 1.3 });
    else el.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
  }

  /* ---------------- Custom cursor ---------------- */
  function initCursor() {
    if (isTouch) return;
    const dot = $(".cursor-dot"), ring = $(".cursor-ring"), label = $(".clabel");
    let mx = innerWidth / 2, my = innerHeight / 2, rx = mx, ry = my;
    window.addEventListener("mousemove", (e) => { mx = e.clientX; my = e.clientY; dot.style.transform = `translate(${mx}px,${my}px) translate(-50%,-50%)`; });
    function raf() { rx += (mx - rx) * 0.18; ry += (my - ry) * 0.18; ring.style.transform = `translate(${rx}px,${ry}px) translate(-50%,-50%)`; requestAnimationFrame(raf); }
    raf();
    const hoverSel = "a, button, .dish, .gcard, [data-magnetic], [data-zoom], [data-cursor], input, select, textarea, .pill, .tw-opt, .tw-swatch, .tw-switch";
    document.addEventListener("mouseover", (e) => {
      const t = e.target.closest(hoverSel);
      if (!t) return;
      ring.classList.add("hover");
      const cl = t.getAttribute("data-cursor");
      if (cl) { ring.classList.add("labeled"); label.textContent = cl; }
    });
    document.addEventListener("mouseout", (e) => {
      const t = e.target.closest(hoverSel);
      if (!t) return;
      ring.classList.remove("hover", "labeled"); label.textContent = "";
    });
  }
  function setCursorEnabled(on) {
    body.classList.toggle("no-cursor", !on);
    $(".cursor-dot").style.display = on && !isTouch ? "" : "none";
    $(".cursor-ring").style.display = on && !isTouch ? "" : "none";
    document.documentElement.style.setProperty("cursor", on && !isTouch ? "none" : "auto");
    body.style.cursor = on && !isTouch ? "none" : "auto";
  }

  /* ---------------- Magnetic buttons ---------------- */
  function initMagnetic() {
    if (isTouch || reduce) return;
    $$("[data-magnetic]").forEach((el) => {
      el.addEventListener("mousemove", (e) => {
        const r = el.getBoundingClientRect();
        const x = e.clientX - r.left - r.width / 2;
        const y = e.clientY - r.top - r.height / 2;
        gsap.to(el, { x: x * 0.35, y: y * 0.35, duration: 0.5, ease: "power3.out" });
      });
      el.addEventListener("mouseleave", () => gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: "elastic.out(1,0.4)" }));
    });
  }

  /* ---------------- Nav overlay ---------------- */
  function initNav() {
    const toggle = $("#navToggle");
    toggle.addEventListener("click", () => body.classList.toggle("nav-open"));
    $$("[data-nav]").forEach((a) => a.addEventListener("click", (e) => {
      e.preventDefault();
      const id = a.getAttribute("href");
      body.classList.remove("nav-open");
      setTimeout(() => scrollTo(id), 200);
    }));
    // brand + rail + footer + in-page anchors
    $$('a[href^="#"]').forEach((a) => {
      if (a.hasAttribute("data-nav")) return;
      a.addEventListener("click", (e) => {
        const id = a.getAttribute("href");
        if (id.length < 2 || !$(id)) return;
        e.preventDefault();
        scrollTo(id);
      });
    });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") body.classList.remove("nav-open"); });
  }

  /* ---------------- Section rail (active state) ---------------- */
  function initRail() {
    const links = $$("#rail a");
    const map = {};
    links.forEach((l) => (map[l.getAttribute("data-rail")] = l));
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          links.forEach((l) => l.classList.remove("active"));
          const m = map[en.target.id];
          if (m) m.classList.add("active");
        }
      });
    }, { rootMargin: "-45% 0px -45% 0px" });
    Object.keys(map).forEach((id) => { const s = document.getElementById(id); if (s) io.observe(s); });
  }

  /* ---------------- Reserve FAB ---------------- */
  function initFab() {
    const fab = $("#reserveFab");
    const hero = $("#hero");
    const io = new IntersectionObserver((ent) => {
      ent.forEach((e) => fab.classList.toggle("show", !e.isIntersecting));
    }, { threshold: 0.2 });
    io.observe(hero);
  }

  /* ---------------- Hero split-text ---------------- */
  function splitTitle() {
    const el = $("[data-split]");
    if (!el) return [];
    // Build lines from child nodes so it survives <br> with injected attributes
    const lines = [[]];
    el.childNodes.forEach((node) => {
      if (node.nodeName === "BR") lines.push([]);
      else (node.textContent || "").split("").forEach((ch) => lines[lines.length - 1].push(ch));
    });
    el.innerHTML = "";
    const chars = [];
    lines.forEach((ln) => {
      if (!ln.length) return;
      const word = document.createElement("span");
      word.className = "word";
      ln.forEach((ch) => {
        const s = document.createElement("span");
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
    const chars = splitTitle();
    if (reduce || !window.gsap) { gsap.set("[data-split] .char", { y: 0, opacity: 1 }); return; }
    const tl = gsap.timeline({ delay: 0.25 });
    gsap.set(chars, { yPercent: 115, opacity: 0 });
    tl.to(chars, { yPercent: 0, opacity: 1, duration: 1.1, stagger: 0.045, ease: "power4.out" });
    tl.from(".hero__eyebrow", { opacity: 0, y: 20, duration: 0.8 }, 0.2);
    tl.to($$(".hero__inner [data-reveal]"), { opacity: 1, y: 0, duration: 0.9, stagger: 0.12, ease: "power3.out" }, "-=0.7");
    // hero bg parallax
    const bg = $("[data-parallax-bg] img");
    if (bg) gsap.to(bg, { yPercent: 18, ease: "none", scrollTrigger: { trigger: "#hero", start: "top top", end: "bottom top", scrub: true } });
  }

  /* ---------------- Reveal animations ---------------- */
  function initReveals() {
    if (reduce) { body.classList.add("no-anim"); return; }
    $$("[data-reveal]").forEach((el) => {
      if (el.closest(".hero__inner")) return; // handled by hero intro
      gsap.to(el, {
        opacity: 1, y: 0, duration: 1, ease: "power3.out",
        scrollTrigger: { trigger: el, start: "top 88%" }
      });
    });
    // parallax images
    $$("[data-parallax-img] img, [data-parallax-img]").forEach((wrap) => {
      const img = wrap.tagName === "IMG" ? wrap : wrap.querySelector("img");
      if (!img) return;
      gsap.fromTo(img, { yPercent: -8 }, {
        yPercent: 8, ease: "none",
        scrollTrigger: { trigger: wrap, start: "top bottom", end: "bottom top", scrub: true }
      });
    });
  }

  /* ---------------- Counters ---------------- */
  function initCounters() {
    $$("[data-count]").forEach((el) => {
      const target = parseFloat(el.getAttribute("data-count"));
      const suffix = el.getAttribute("data-suffix") || "";
      ScrollTrigger.create({
        trigger: el, start: "top 90%", once: true,
        onEnter: () => {
          if (reduce) { el.textContent = target + suffix; return; }
          const o = { v: 0 };
          gsap.to(o, { v: target, duration: 1.6, ease: "power2.out", onUpdate: () => { el.textContent = Math.round(o.v) + suffix; } });
        }
      });
    });
  }

  /* ---------------- MENU ---------------- */
  let currentCat = 0;
  function initMenu() {
    const data = window.CP_MENU || [];
    const tabs = $("#menuTabs"), list = $("#menuList");
    const pcCat = $("#pcCat"), pcName = $("#pcName"), pcCount = $("#pcCount"), frame = $("#previewFrame");
    let totalCount = data.reduce((a, c) => a + c.dishes.length, 0);

    data.forEach((cat, i) => {
      const b = document.createElement("button");
      b.className = "menu__tab" + (i === 0 ? " active" : "");
      b.innerHTML = `${cat.label}<span class="count">${String(cat.dishes.length).padStart(2, "0")}</span>`;
      b.addEventListener("click", () => selectCat(i));
      tabs.appendChild(b);
    });

    // preload preview images, create img nodes
    const imgCache = {};
    function preview(cat, dish) {
      pcCat.textContent = cat.label;
      pcName.textContent = dish ? dish.n : cat.kicker;
      if (!dish) return;
      let img = imgCache[dish.img];
      if (!img) {
        img = document.createElement("img");
        img.src = dish.img; img.alt = dish.n;
        frame.insertBefore(img, frame.firstChild);
        imgCache[dish.img] = img;
      }
      $$(".menu__preview-frame img").forEach((im) => im.classList.remove("show"));
      img.classList.add("show");
      // make the preview frame itself zoomable to the current dish
      frame.setAttribute("data-zoom-src", dish.img);
      frame.setAttribute("data-zoom-name", dish.n);
      frame.setAttribute("data-zoom-cat", cat.label);
    }

    function renderList(catIndex) {
      const cat = data[catIndex];
      list.innerHTML = "";
      pcCount.textContent = `${cat.dishes.length} platillos`;
      cat.dishes.forEach((d, j) => {
        const row = document.createElement("div");
        row.className = "dish";
        const tags = (d.tags || []).map((t) => `<span class="dish__tag">${t}</span>`).join("");
        row.innerHTML = `
          <div class="dish__thumb" data-zoom data-zoom-cat="${cat.label}"><img src="${d.img}" alt="${d.n}" loading="lazy" /></div>
          <span class="dish__idx">${String(j + 1).padStart(2, "0")}</span>
          <div class="dish__main">
            <h4>${d.n}</h4>
            ${d.d ? `<p>${d.d}</p>` : ""}
            ${tags ? `<div class="dish__tags">${tags}</div>` : ""}
          </div>
          <span class="dish__price">$${d.p}<small> mxn</small></span>`;
        row.addEventListener("mouseenter", () => { $$(".dish").forEach((x) => x.classList.remove("active")); row.classList.add("active"); preview(cat, d); });
        list.appendChild(row);
      });
      // entrance
      if (!reduce && window.gsap) gsap.fromTo(list.children, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.6, stagger: 0.05, ease: "power3.out" });
      preview(cat, cat.dishes[0]);
      if (list.firstChild) list.firstChild.classList.add("active");
    }

    function selectCat(i) {
      currentCat = i;
      $$(".menu__tab").forEach((t, k) => t.classList.toggle("active", k === i));
      renderList(i);
      if (window.ScrollTrigger) ScrollTrigger.refresh();
    }

    renderList(0);
  }

  /* ---------------- SIGNATURE GALLERY + LIGHTBOX ---------------- */
  const GALLERY = [
    { img: "assets/images/mejor-2.webp", n: "Tagliatelle al Limón", t: "Pasta" },
    { img: "assets/images/mejor-5.webp", n: "Camarones a la Brasa", t: "Mariscos" },
    { img: "assets/images/pizza-3.webp", n: "Pizza al Horno de Piedra", t: "Pizzas" },
    { img: "assets/images/mejor-3.webp", n: "Tostas de la Casa", t: "Para Picar" },
    { img: "assets/images/mejor-4.webp", n: "Espresso Martini", t: "Coctelería" },
    { img: "assets/images/mejor-6.webp", n: "Rib Eye 400 gr", t: "Cortes" },
    { img: "assets/images/mejor-1.webp", n: "Aceitunas Temperadas", t: "Aperitivo" }
  ];
  function initGallery() {
    const track = $("#galleryTrack");
    GALLERY.forEach((g) => {
      const c = document.createElement("div");
      c.className = "gcard";
      c.setAttribute("data-cursor", "Ampliar");
      c.setAttribute("data-zoom", "");
      c.setAttribute("data-zoom-name", g.n);
      c.setAttribute("data-zoom-cat", g.t);
      c.innerHTML = `<img src="${g.img}" alt="${g.n}" draggable="false" /><div class="gcard__cap"><div class="t">${g.t}</div><div class="n">${g.n}</div></div>`;
      track.appendChild(c);
    });

    // drag to scroll
    let isDown = false, startX = 0, scrollStart = 0, moved = 0, pos = 0;
    const max = () => Math.max(0, track.scrollWidth - track.clientWidth + 8);
    function setPos(p) { pos = Math.max(-max(), Math.min(0, p)); track.style.transform = `translateX(${pos}px)`; }
    track.addEventListener("pointerdown", (e) => { isDown = true; moved = 0; startX = e.clientX; scrollStart = pos; track.setPointerCapture(e.pointerId); });
    track.addEventListener("pointermove", (e) => {
      if (!isDown) return;
      const dx = e.clientX - startX; moved += Math.abs(e.movementX || 0);
      if (moved > 6) suppressClick = true;
      setPos(scrollStart + dx);
    });
    const up = () => { isDown = false; setTimeout(() => { suppressClick = false; }, 60); };
    track.addEventListener("pointerup", up);
    track.addEventListener("pointercancel", up);
    track.addEventListener("pointerleave", up);
    track.addEventListener("wheel", (e) => { if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) { e.preventDefault(); setPos(pos - e.deltaX); } }, { passive: false });
    if (!reduce && window.gsap) {
      gsap.to(track, { x: -80, ease: "none", scrollTrigger: { trigger: "#firma", start: "top bottom", end: "bottom top", scrub: 1 } });
    }
  }

  /* ---------------- UNIVERSAL LIGHTBOX (any [data-zoom]) ---------------- */
  function injectBadges() {
    $$("[data-zoom]").forEach((el) => {
      if (el.classList.contains("dish__thumb")) return;
      if (el.querySelector(":scope > .zoom-badge")) return;
      const b = document.createElement("span");
      b.className = "zoom-badge";
      b.textContent = "⤢ Ampliar";
      el.appendChild(b);
      if (!el.hasAttribute("data-cursor")) el.setAttribute("data-cursor", "Ampliar");
    });
  }
  function getZoomList() {
    const list = [];
    $$("[data-zoom]").forEach((el) => {
      const img = el.tagName === "IMG" ? el : el.querySelector("img");
      const src = el.getAttribute("data-zoom-src") || (img && (img.currentSrc || img.getAttribute("src")));
      if (!src) return;
      list.push({ src, n: el.getAttribute("data-zoom-name") || (img && img.alt) || "", t: el.getAttribute("data-zoom-cat") || "", el });
    });
    return list;
  }
  function initLightbox() {
    injectBadges();
    const lb = $("#lightbox"), lbImg = $("#lbImg"), lbN = $("#lbN"), lbT = $("#lbT"), lbCur = $("#lbCur"), lbTotal = $("#lbTotal");
    let list = [], idx = 0;
    function render() {
      const g = list[idx]; if (!g) return;
      lbImg.src = g.src; lbImg.alt = g.n; lbN.textContent = g.n; lbT.textContent = g.t;
      lbCur.textContent = idx + 1; lbTotal.textContent = list.length;
      if (!reduce && window.gsap) gsap.fromTo(lbImg, { opacity: 0, scale: 0.97 }, { opacity: 1, scale: 1, duration: 0.45, ease: "power2.out" });
    }
    function groupId(el) { const s = el.closest("section, header"); return s ? s.id : ""; }
    function open(el) {
      const g = groupId(el);
      list = getZoomList().filter((z) => groupId(z.el) === g);
      if (!list.length) list = getZoomList();
      idx = Math.max(0, list.findIndex((z) => z.el === el));
      render();
      lb.classList.add("open"); lb.setAttribute("aria-hidden", "false");
      document.body.style.overflow = "hidden";
      if (lenis) lenis.stop();
    }
    function close() {
      lb.classList.remove("open"); lb.setAttribute("aria-hidden", "true");
      document.body.style.overflow = "";
      if (lenis) lenis.start();
    }
    function nav(d) { if (!list.length) return; idx = (idx + d + list.length) % list.length; render(); }
    document.addEventListener("click", (e) => {
      const z = e.target.closest("[data-zoom]");
      if (!z || suppressClick) return;
      e.preventDefault();
      open(z);
    });
    $("#lbClose").addEventListener("click", (e) => { e.stopPropagation(); close(); });
    $("#lbPrev").addEventListener("click", (e) => { e.stopPropagation(); nav(-1); });
    $("#lbNext").addEventListener("click", (e) => { e.stopPropagation(); nav(1); });
    lb.addEventListener("click", (e) => { if (!e.target.closest(".lightbox__img, button, .lightbox__cap")) close(); });
    document.addEventListener("keydown", (e) => { if (!lb.classList.contains("open")) return; if (e.key === "Escape") close(); if (e.key === "ArrowRight") nav(1); if (e.key === "ArrowLeft") nav(-1); });
    let sx = 0;
    lb.addEventListener("touchstart", (e) => { sx = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener("touchend", (e) => { const dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) nav(dx < 0 ? 1 : -1); });
    window.__openZoom = open;
  }

  /* ---------------- Hours: highlight today ---------------- */
  function initHours() {
    const today = new Date().getDay();
    const row = $(`.reserva__hours .row[data-day="${today}"]`);
    if (row) { row.classList.add("today"); const v = row.querySelector("span:last-child"); if (v) v.innerHTML = `<b>${v.textContent}</b> · Hoy`; }
  }

  /* ---------------- Reservation form ---------------- */
  function initForm() {
    const form = $("#reservaForm");
    const pills = $$("#guestPills .pill");
    let guests = 2;
    pills.forEach((p) => p.addEventListener("click", () => { pills.forEach((x) => x.classList.remove("sel")); p.classList.add("sel"); guests = p.getAttribute("data-g"); }));
    const dateInput = form.querySelector('input[name="fecha"]');
    if (dateInput) { const t = new Date(); dateInput.min = t.toISOString().split("T")[0]; }
    const msg = $("#formMsg");
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      const nombre = form.nombre.value.trim();
      const tel = form.tel.value.trim();
      const fecha = form.fecha.value;
      const hora = form.hora.value;
      if (!nombre || !tel || !fecha || !hora) {
        msg.textContent = "Completa nombre, teléfono, fecha y hora.";
        msg.classList.add("show");
        if (!reduce) gsap.fromTo(form, { x: -6 }, { x: 0, duration: 0.4, ease: "elastic.out(1,0.3)" });
        return;
      }
      msg.classList.remove("show");
      const fd = new Date(fecha + "T00:00:00");
      const fmt = fd.toLocaleDateString("es-MX", { weekday: "long", day: "numeric", month: "long" });
      $("#confirmText").innerHTML = `Gracias, <b style="color:var(--accent);font-weight:400">${nombre}</b>. Mesa para <b style="color:var(--accent);font-weight:400">${guests}</b> el <b style="color:var(--accent);font-weight:400">${fmt}</b> a las <b style="color:var(--accent);font-weight:400">${hora}</b>. Te esperamos.`;
      form.style.display = "none";
      const confirm = $("#reservaConfirm");
      confirm.classList.add("show");
      if (window.ScrollTrigger) ScrollTrigger.refresh();
    });
  }

  /* ---------------- Accent swap ---------------- */
  const ACCENTS = {
    oro: ["#cca352", "#e0c184"],
    terracota: ["#c06a36", "#dca072"],
    salvia: ["#88a37b", "#a9c19d"]
  };
  function applyAccent(key) {
    const a = ACCENTS[key] || ACCENTS.oro;
    document.documentElement.style.setProperty("--accent", a[0]);
    document.documentElement.style.setProperty("--accent-soft", a[1]);
  }

  /* ---------------- TWEAKS PANEL (host protocol) ---------------- */
  function initTweaks() {
    const panel = $("#tweaks");
    function setHero(v) { body.setAttribute("data-hero", v); $$('[data-tw="hero"] .tw-opt').forEach((b) => b.classList.toggle("sel", b.dataset.val === v)); if (window.ScrollTrigger) ScrollTrigger.refresh(); }
    function setAccentTw(v) { applyAccent(v); $$('[data-tw="accent"] .tw-swatch').forEach((b) => b.classList.toggle("sel", b.dataset.val === v)); }
    function setSwitch(name, on) {
      const sw = $(`.tw-switch[data-tw="${name}"]`);
      if (sw) sw.classList.toggle("on", !!on);
      if (name === "cursor") setCursorEnabled(on);
      if (name === "smooth") { on ? startLenis() : stopLenis(); }
      if (name === "anim") { body.classList.toggle("no-anim", !on); }
    }
    // init from defaults
    setHero(T.hero); setAccentTw(T.accent);
    setSwitch("cursor", T.cursor); setSwitch("smooth", T.smooth); setSwitch("anim", T.anim);

    // wire controls
    $$('[data-tw="hero"] .tw-opt').forEach((b) => b.addEventListener("click", () => { setHero(b.dataset.val); persist({ hero: b.dataset.val }); }));
    $$('[data-tw="accent"] .tw-swatch').forEach((b) => b.addEventListener("click", () => { setAccentTw(b.dataset.val); persist({ accent: b.dataset.val }); }));
    ["cursor", "smooth", "anim"].forEach((name) => {
      const sw = $(`.tw-switch[data-tw="${name}"]`);
      if (sw) sw.addEventListener("click", () => { const on = !sw.classList.contains("on"); setSwitch(name, on); persist({ [name]: on }); });
    });
    $("#twClose").addEventListener("click", () => { panel.classList.remove("open"); try { window.parent.postMessage({ type: "__edit_mode_dismissed" }, "*"); } catch (e) {} });

    function persist(obj) { try { window.parent.postMessage({ type: "__edit_mode_set_keys", edits: obj }, "*"); } catch (e) {} }

    // host listener BEFORE announcing
    window.addEventListener("message", (e) => {
      const d = e.data || {};
      if (d.type === "__activate_edit_mode") panel.classList.add("open");
      else if (d.type === "__deactivate_edit_mode") panel.classList.remove("open");
    });
    try { window.parent.postMessage({ type: "__edit_mode_available" }, "*"); } catch (e) {}
  }

  /* ---------------- Boot ---------------- */
  function boot() {
    applyAccent(T.accent);
    body.setAttribute("data-hero", T.hero);
    setCursorEnabled(T.cursor);
    if (!T.anim) body.classList.add("no-anim");

    initCursor();
    initMagnetic();
    initNav();
    initRail();
    initFab();
    initMenu();
    initGallery();
    initLightbox();
    initHours();
    initForm();
    initTweaks();

    if (window.gsap && window.ScrollTrigger) {
      heroIntro();
      initReveals();
      initCounters();
    } else {
      $$("[data-reveal]").forEach((el) => { el.style.opacity = 1; el.style.transform = "none"; });
    }

    if (T.smooth) startLenis();
    setTimeout(() => { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, 600);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
