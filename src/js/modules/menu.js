/* La carta: pestañas por sección, lista de platillos y la foto grande
 *
 * Los datos llegan de /menu (Services\Menu\Carta::publica):
 *   [{ id, label, img, dishes: [{ n, d, p, img }] }]
 * `img` del platillo es su propia foto; si no tiene, se usa la de su categoría,
 * que es la foto por defecto que se sube en el panel.
 *
 * La foto NO va junto al nombre del platillo: hubo una miniatura por fila y
 * convertía la carta en un catálogo de tienda. La foto es UNA, la grande de la
 * sección, y cambia con el platillo que se señala; pulsar un platillo la abre
 * en el visor.
 */
var currentCat = 0;

// Los nombres y descripciones los escribe el panel y entran por innerHTML.
function escCarta(t) {
  return String(t == null ? "" : t).replace(/[&<>"']/g, function(c) {
    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
  });
}

function precioCarta(p) {
  var n = Number(p) || 0;
  return "$" + (n % 1 ? n.toFixed(2) : String(n));
}

function initMenu() {
  var tabs = $("#menuTabs"), list = $("#menuList");
  if (!tabs || !list) return;
  var pcCat = $("#pcCat"), pcName = $("#pcName"), pcPrecio = $("#pcPrecio"), pcCount = $("#pcCount");
  // El marco es el cartón; el hueco es donde va la fotografía (ver _menu.php).
  var hueco = $("#previewHueco");

  list.innerHTML = '<p class="menu__estado">Cargando la carta…</p>';
  fetch("/menu")
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!Array.isArray(data) || !data.length) {
        list.innerHTML = '<p class="menu__estado">Estamos actualizando la carta. Mientras tanto, puedes abrirla en PDF.</p>';
        return;
      }
      var imgCache = {};

      function fotoDe(cat, dish) {
        return (dish && dish.img) || cat.img || "";
      }

      // La cartela y el visor sólo nombran al platillo cuando la foto ES suya.
      // Con la de su categoría ponían «Enmoladas · $240» sobre una taza de café:
      // en una carta eso es decir cómo se ve un plato que no es ése.
      function preview(cat, dish) {
        if (!hueco) return;
        var propia = !!(dish && dish.img);
        pcCat.textContent = propia ? cat.label : "La carta";
        pcName.textContent = propia ? dish.n : cat.label;
        pcPrecio.textContent = propia ? precioCarta(dish.p) : "";

        var src = fotoDe(cat, dish);
        if (!src) return;
        var img = imgCache[src];
        if (!img) {
          img = document.createElement("img");
          img.src = src;
          img.alt = "";
          hueco.insertBefore(img, hueco.firstChild);
          imgCache[src] = img;
        }
        $$(".menu__preview-hueco img").forEach(function(im) { im.classList.toggle("show", im === img); });
        hueco.setAttribute("data-zoom-src", src);
        hueco.setAttribute("data-zoom-name", propia ? dish.n : cat.label);
        hueco.setAttribute("data-zoom-cat", cat.label);
      }

      // Con foto propia, el nombre es un botón: es la parada de teclado que abre
      // el visor (la fila entera responde al clic, pero una fila no se enfoca).
      // Sin foto propia el nombre es texto: no hay nada que ampliar que sea suyo.
      function filaHTML(cat, d) {
        var nombre = d.img
          ? '<button type="button" class="dish__foto" aria-label="' + escCarta(d.n) + ': ver foto">' + escCarta(d.n) + '</button>'
          : escCarta(d.n);
        return '<div class="dish__cuerpo">' +
            '<div class="dish__linea">' +
              '<h4 class="dish__nombre">' + nombre + '</h4>' +
              '<span class="dish__puntos" aria-hidden="true"></span>' +
              '<span class="dish__precio">' + precioCarta(d.p) + '</span>' +
            '</div>' +
            (d.d ? '<p class="dish__desc">' + escCarta(d.d) + '</p>' : '') +
          '</div>';
      }

      function renderList(catIndex) {
        var cat = data[catIndex];
        list.innerHTML = "";
        list.setAttribute("aria-labelledby", "menu-tab-" + catIndex);
        pcCount.textContent = cat.dishes.length + (cat.dishes.length === 1 ? " platillo" : " platillos");
        cat.dishes.forEach(function(d) {
          var row = document.createElement("div");
          row.className = "dish";
          row.innerHTML = filaHTML(cat, d);
          if (d.img) {
            row.classList.add("dish--foto");
            row.setAttribute("data-cursor", "Ver foto");
          }
          function activar() {
            $$(".dish").forEach(function(x) { x.classList.toggle("active", x === row); });
            preview(cat, d);
          }
          row.addEventListener("mouseenter", activar);
          row.addEventListener("focusin", activar);
          // Pulsar un platillo CON FOTO PROPIA la abre en el visor: en un
          // teléfono no hay «pasar el cursor», y es la única forma de verla.
          // Sin foto propia no se abre nada: el visor enseñaba, a pantalla
          // completa y con el nombre del platillo, la foto de su categoría.
          row.addEventListener("click", function() {
            activar();
            if (d.img && window.__openZoom && hueco) window.__openZoom(hueco, row.querySelector(".dish__foto"));
          });
          list.appendChild(row);
        });
        // Entran sólo las primeras filas, que son las que están a la vista, y
        // en poco tiempo: cambiar de pestaña es una consulta, no una escena, y
        // con 26 platillos el último llegaba casi dos segundos tarde.
        if (!reduce && window.gsap) {
          gsap.fromTo(Array.prototype.slice.call(list.children, 0, 8),
            { opacity: 0, y: 14 },
            { opacity: 1, y: 0, duration: 0.32, stagger: 0.035, ease: "power2.out", clearProps: "opacity,transform" });
        }
        preview(cat, cat.dishes[0]);
        if (list.firstChild) list.firstChild.classList.add("active");
      }

      var botones = [];
      function selectCat(i, enfocar) {
        currentCat = i;
        botones.forEach(function(b, k) {
          var activa = k === i;
          b.classList.toggle("active", activa);
          b.setAttribute("aria-selected", activa ? "true" : "false");
          b.tabIndex = activa ? 0 : -1;
        });
        if (enfocar) botones[i].focus();
        renderList(i);
        if (window.ScrollTrigger) ScrollTrigger.refresh();
      }

      data.forEach(function(cat, i) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "menu__tab" + (i === 0 ? " active" : "");
        b.id = "menu-tab-" + i;
        b.setAttribute("role", "tab");
        b.setAttribute("aria-controls", "menuList");
        b.setAttribute("aria-selected", i === 0 ? "true" : "false");
        b.tabIndex = i === 0 ? 0 : -1;
        // El contador se ve como «26» y se lee como «26 platillos»: un
        // aria-label sobre un <span> no lo anuncia ningún lector, y sin el
        // espacio el nombre de la pestaña salía «Desayunos26».
        b.innerHTML = escCarta(cat.label) + ' <span class="count" aria-hidden="true">' + String(cat.dishes.length).padStart(2, "0") + '</span>' +
          '<span class="sr-only">, ' + cat.dishes.length + (cat.dishes.length === 1 ? ' platillo' : ' platillos') + '</span>';
        b.addEventListener("click", function() { selectCat(i); });
        botones.push(b);
        tabs.appendChild(b);
      });

      // Flechas, Inicio y Fin dentro de las pestañas, como pide el patrón de
      // pestañas de WAI-ARIA: una sola parada de tabulador para todo el grupo.
      tabs.addEventListener("keydown", function(e) {
        var n = botones.length, i = currentCat;
        if (e.key === "ArrowRight") i = (i + 1) % n;
        else if (e.key === "ArrowLeft") i = (i - 1 + n) % n;
        else if (e.key === "Home") i = 0;
        else if (e.key === "End") i = n - 1;
        else return;
        e.preventDefault();
        selectCat(i, true);
        botones[i].scrollIntoView({ block: "nearest", inline: "nearest" });
      });

      renderList(0);
    })
    .catch(function() {
      list.innerHTML = '<p class="menu__estado">No pudimos cargar la carta. Puedes abrirla en PDF.</p>';
    });
}
