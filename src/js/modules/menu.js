/* Menú — tabs, lista de platos y preview */
var currentCat = 0;

function initMenu() {
  var tabs = $("#menuTabs"), list = $("#menuList");
  var pcCat = $("#pcCat"), pcName = $("#pcName"), pcCount = $("#pcCount"), frame = $("#previewFrame");

  list.innerHTML = '<p style="padding:2rem;opacity:.5">Cargando menú…</p>';
  // Realizamos peticion al backend por medio de Fetch
  fetch('/menu')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var imgCache = {};
      list.innerHTML = '';

      function preview(cat, dish) {
        pcCat.textContent = cat.label;
        pcName.textContent = dish ? dish.n : (cat.kicker || cat.label);

        /* Descomentar para renderizar imagen individual por platillo:
        if (!dish || !dish.img) return;
        var img = imgCache[dish.img];
        if (!img) {
          img = document.createElement("img");
          img.src = dish.img; img.alt = dish.n;
          frame.insertBefore(img, frame.firstChild);
          imgCache[dish.img] = img;
        }
        $$(".menu__preview-frame img").forEach(function(im) { im.classList.remove("show"); });
        img.classList.add("show");
        frame.setAttribute("data-zoom-src", dish.img);
        frame.setAttribute("data-zoom-name", dish.n);
        frame.setAttribute("data-zoom-cat", cat.label);
        */

        if (!cat.img) return;
        var img = imgCache[cat.img];
        if (!img) {
          img = document.createElement("img");
          img.src = cat.img; img.alt = cat.label;
          frame.insertBefore(img, frame.firstChild);
          imgCache[cat.img] = img;
        }
        $$(".menu__preview-frame img").forEach(function(im) { im.classList.remove("show"); });
        img.classList.add("show");
        frame.setAttribute("data-zoom-src", cat.img);
        frame.setAttribute("data-zoom-name", cat.label);
        frame.setAttribute("data-zoom-cat", cat.label);
      }

      function renderList(catIndex) {
        var cat = data[catIndex];
        list.innerHTML = "";
        pcCount.textContent = cat.dishes.length + " platillos";
        cat.dishes.forEach(function(d, j) {
          var row = document.createElement("div");
          row.className = "dish";
          var tags = (d.tags || []).map(function(t) { return '<span class="dish__tag">' + t + '</span>'; }).join("");

          /* Descomentar para miniatura individual por platillo:
          var thumb = d.img
            ? '<div class="dish__thumb" data-zoom data-zoom-cat="' + cat.label + '"><img src="' + d.img + '" alt="' + d.n + '" loading="lazy" /></div>'
            : '';
          */
          var thumb = '';

          row.innerHTML =
            thumb +
            '<span class="dish__idx">' + String(j + 1).padStart(2, "0") + '</span>' +
            '<div class="dish__main"><h4>' + d.n + '</h4>' +
            (d.d ? '<p>' + d.d + '</p>' : '') +
            (tags ? '<div class="dish__tags">' + tags + '</div>' : '') +
            '</div>' +
            '<span class="dish__price">$' + d.p + '<small> mxn</small></span>';
          row.addEventListener("mouseenter", function() {
            $$(".dish").forEach(function(x) { x.classList.remove("active"); });
            row.classList.add("active");
            preview(cat, d);
          });
          list.appendChild(row);
        });
        if (!reduce && window.gsap) gsap.fromTo(list.children, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.6, stagger: 0.05, ease: "power3.out" });
        preview(cat, cat.dishes[0]);
        if (list.firstChild) list.firstChild.classList.add("active");
      }

      function selectCat(i) {
        currentCat = i;
        $$(".menu__tab").forEach(function(t, k) { t.classList.toggle("active", k === i); });
        renderList(i);
        if (window.ScrollTrigger) ScrollTrigger.refresh();
      }

      data.forEach(function(cat, i) {
        var b = document.createElement("button");
        b.className = "menu__tab" + (i === 0 ? " active" : "");
        b.innerHTML = cat.label + '<span class="count">' + String(cat.dishes.length).padStart(2, "0") + '</span>';
        b.addEventListener("click", function() { selectCat(i); });
        tabs.appendChild(b);
      });

      renderList(0);
    })
    .catch(function() {
      list.innerHTML = '<p style="padding:2rem;opacity:.5">No se pudo cargar el menú.</p>';
    });
}
