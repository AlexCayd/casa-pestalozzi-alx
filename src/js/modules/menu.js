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
      var fila = null;
      function selectCat(i, enfocar) {
        currentCat = i;
        botones.forEach(function(b, k) {
          var activa = k === i;
          b.classList.toggle("active", activa);
          b.setAttribute("aria-selected", activa ? "true" : "false");
          b.tabIndex = activa ? 0 : -1;
        });
        // El foco no desplaza la fila por su cuenta (lo haría de golpe y sin
        // contar el desvanecido): la pestaña la trae a la vista la fila, con
        // margen para que no quede debajo de la flecha. Vale igual para el
        // teclado que para el clic en una pestaña que asomaba cortada.
        if (enfocar) botones[i].focus({ preventScroll: true });
        if (fila) fila.mostrar(botones[i]);
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
      });

      fila = filaDesplazable(tabs, $("#menuPestanas"));
      renderList(0);
    })
    .catch(function() {
      list.innerHTML = '<p class="menu__estado">No pudimos cargar la carta. Puedes abrirla en PDF.</p>';
    });
}

/* La fila de pestañas que desborda
   ------------------------------------------------------------------
   Nueve secciones no caben en ninguna pantalla y la fila se desplaza en
   horizontal. Aquí vive todo lo que hace que eso se note y sea cómodo:

   · Publica en el contenedor de qué lado queda carta (.is-desborda-ini /
     .is-desborda-fin): el CSS desvanece ese borde y enseña su flecha.
   · Las flechas desplazan una «página» que termina en el borde de una
     pestaña, nunca a media palabra.
   · Con ratón, la fila se arrastra (con inercia al soltar) y la rueda
     vertical la recorre, pero sólo mientras le quede recorrido en ese
     sentido: al llegar al extremo la rueda vuelve a ser de la página. Y si el
     gesto de rueda empezó fuera de la fila (bajando por la página), sigue
     siendo de la página aunque la fila pase por debajo del puntero: es el
     mismo «enganche» que hacen los navegadores con los scrolls anidados, y
     sin él la página se frenaba en seco a mitad de la bajada.
   · La primera vez que la fila entra en pantalla con carta escondida, la
     flecha da un empujoncito. Una sola vez y nunca con movimiento reducido.

   Lenis cancela la rueda en toda la página salvo dentro de
   [data-lenis-prevent]. La marca se pone y se quita EN CADA evento, antes de
   que le llegue a Lenis (este listener está en la fila; el suyo, en window):
   sólo cuando la fila se queda el gesto. Escrita a mano y fija, la página se
   plantaría encima de la carta aunque la fila ya no tuviera adónde ir.

   Devuelve { mostrar(pestaña) } para traer a la vista la pestaña elegida. */
function filaDesplazable(fila, contenedor) {
  if (!fila || !contenedor) return { mostrar: function() {} };
  var flechas = $$("[data-desliza]", contenedor);
  var tocada = false;

  // El hueco del desvanecido, leído del CSS y no repetido aquí: la fila lo
  // usa como scroll-padding, y ése sí computa a píxeles.
  function fundido() {
    var v = parseFloat(getComputedStyle(fila).scrollPaddingLeft);
    return v > 0 ? v : 56;
  }
  function maximo() { return Math.max(0, fila.scrollWidth - fila.clientWidth); }
  function conMovimiento() { return !reduce && !body.classList.contains("no-anim"); }

  // ── De qué lado queda carta ──
  var pendiente = false;
  function estado() {
    pendiente = false;
    var max = maximo();
    // Un píxel de tolerancia: con zoom del navegador scrollLeft se queda en
    // x.5 y la flecha del final no se retiraba nunca.
    contenedor.classList.toggle("is-desborda-ini", fila.scrollLeft > 1);
    contenedor.classList.toggle("is-desborda-fin", fila.scrollLeft < max - 1);
  }
  function programar() {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(estado);
  }
  fila.addEventListener("scroll", programar, { passive: true });
  // La fila cambia de ancho sin que cambie la ventana: llegan las fuentes,
  // cambia la columna. El observer lo ve todo.
  if (window.ResizeObserver) new ResizeObserver(programar).observe(fila);
  else window.addEventListener("resize", programar);
  estado();

  // ── Un solo dueño del desplazamiento por código ──
  // Inercia y rueda escriben scrollLeft fotograma a fotograma; si las dos
  // corrieran a la vez se pelearían por el mismo valor.
  var bucle = null, objetivo = null;
  function parar() {
    if (bucle) cancelAnimationFrame(bucle);
    bucle = null;
    objetivo = null;
  }

  function ir(x) {
    parar();
    fila.scrollTo({ left: Math.max(0, Math.min(maximo(), x)), behavior: conMovimiento() ? "smooth" : "auto" });
  }

  // Una «página» hacia un lado, cortada en el borde de una pestaña: hacia
  // delante, la primera que asoma bajo el desvanecido derecho pasa a ser la
  // primera entera; hacia atrás, la última que asoma por la izquierda pasa a
  // ser la última entera.
  function pagina(sentido) {
    var f = fundido(), ini = fila.scrollLeft, fin = ini + fila.clientWidth;
    var pestanas = $$(".menu__tab", fila), destino = null, k, p;
    if (sentido > 0) {
      for (k = 0; k < pestanas.length; k++) {
        p = pestanas[k];
        if (p.offsetLeft + p.offsetWidth > fin - f + 1) { destino = p.offsetLeft - f; break; }
      }
      if (destino === null || destino <= ini + 1) destino = ini + fila.clientWidth * 0.8;
    } else {
      for (k = pestanas.length - 1; k >= 0; k--) {
        p = pestanas[k];
        if (p.offsetLeft < ini + f - 1) { destino = p.offsetLeft + p.offsetWidth - fila.clientWidth + f; break; }
      }
      if (destino === null || destino >= ini - 1) destino = ini - fila.clientWidth * 0.8;
    }
    ir(destino);
  }

  flechas.forEach(function(b) {
    // Sin robar el foco: si estaba en una pestaña, tiene que seguir ahí.
    b.addEventListener("pointerdown", function(e) { e.preventDefault(); });
    b.addEventListener("click", function() {
      tocada = true;
      pagina(parseInt(b.getAttribute("data-desliza"), 10) || 1);
    });
  });

  // ── Arrastre con ratón ──
  // Sólo ratón: el dedo ya desplaza la fila de forma nativa, y un arrastre
  // propio pelearía con el del navegador.
  var arrastre = null, trasArrastre = false;
  fila.addEventListener("pointerdown", function(e) {
    if (e.pointerType !== "mouse" || e.button !== 0 || maximo() <= 0) return;
    parar();
    arrastre = { id: e.pointerId, x: e.clientX, inicio: fila.scrollLeft, movido: false, v: 0, t: e.timeStamp, ultimoX: e.clientX };
  });
  fila.addEventListener("pointermove", function(e) {
    if (!arrastre || e.pointerId !== arrastre.id) return;
    var dx = e.clientX - arrastre.x;
    if (!arrastre.movido) {
      // Umbral: un clic con el pulso tembloroso sigue siendo un clic.
      if (Math.abs(dx) < 6) return;
      arrastre.movido = true;
      tocada = true;
      fila.classList.add("is-arrastrando");
      try { fila.setPointerCapture(e.pointerId); } catch (err) { /* ya soltado */ }
    }
    var dt = Math.max(1, e.timeStamp - arrastre.t);
    arrastre.v = (e.clientX - arrastre.ultimoX) / dt;
    arrastre.t = e.timeStamp;
    arrastre.ultimoX = e.clientX;
    fila.scrollLeft = arrastre.inicio - dx;
  });
  function soltar(e) {
    if (!arrastre || e.pointerId !== arrastre.id) return;
    var a = arrastre;
    arrastre = null;
    if (!a.movido) return;
    fila.classList.remove("is-arrastrando");
    // El clic que el navegador dispara al soltar no debe elegir la pestaña
    // sobre la que terminó el gesto.
    trasArrastre = true;
    setTimeout(function() { trasArrastre = false; }, 0);
    // Un gesto que se detuvo antes de soltar no lanza la fila.
    if (conMovimiento() && e.timeStamp - a.t < 80) inercia(-a.v);
  }
  fila.addEventListener("pointerup", soltar);
  fila.addEventListener("pointercancel", soltar);
  fila.addEventListener("click", function(e) {
    if (!trasArrastre) return;
    e.stopPropagation();
    e.preventDefault();
    trasArrastre = false;
  }, true);

  // La fila sigue un poco tras soltarla y frena sola. La constante se
  // normaliza por tiempo, como la del cursor: a 60 y a 144 Hz frena igual.
  function inercia(v) {
    parar();
    if (Math.abs(v) < 0.05) return;
    var anterior = 0;
    function paso(ahora) {
      var dt = anterior ? Math.min(48, ahora - anterior) : 16.67;
      anterior = ahora;
      fila.scrollLeft += v * dt;
      v *= Math.pow(0.92, dt / 16.67);
      if (Math.abs(v) < 0.02 || fila.scrollLeft <= 0 || fila.scrollLeft >= maximo() - 0.5) { bucle = null; return; }
      bucle = requestAnimationFrame(paso);
    }
    bucle = requestAnimationFrame(paso);
  }

  // ── La rueda ──
  // Un gesto de rueda es una ráfaga de eventos sin pausas de más de 250 ms. Se
  // engancha a donde EMPEZÓ: si empezó fuera de la fila, es de la página.
  var ultimaRueda = -1e9, gestoEnFila = false;
  window.addEventListener("wheel", function(e) {
    if (e.timeStamp - ultimaRueda > 250) gestoEnFila = fila.contains(e.target);
    ultimaRueda = e.timeStamp;
  }, { passive: true, capture: true });

  fila.addEventListener("wheel", function(e) {
    var max = maximo();
    var dx = e.deltaX, dy = e.deltaY;
    if (e.deltaMode === 1) { dx *= 16; dy *= 16; }
    else if (e.deltaMode === 2) { dx *= fila.clientWidth; dy *= fila.clientWidth; }

    // El gesto horizontal (trackpad, rueda inclinable) lo resuelve el
    // navegador de forma nativa: sólo hay que impedir que Lenis lo cancele.
    if (max > 0 && Math.abs(dx) > Math.abs(dy)) {
      fila.setAttribute("data-lenis-prevent", "");
      return;
    }

    var puede = max > 0 && gestoEnFila && !e.shiftKey && !e.ctrlKey &&
      ((dy > 0 && fila.scrollLeft < max - 1) || (dy < 0 && fila.scrollLeft > 1));
    if (!puede) {
      fila.removeAttribute("data-lenis-prevent");
      return;
    }
    fila.setAttribute("data-lenis-prevent", "");
    e.preventDefault();
    tocada = true;

    // Rueda de ratón (saltos de 100 px) o trackpad (pasos finos): los dos
    // acaban en el mismo seguimiento suave hacia un objetivo que se acumula.
    var base = objetivo === null ? fila.scrollLeft : objetivo;
    var destino = Math.max(0, Math.min(max, base + dy));
    if (!conMovimiento()) { parar(); fila.scrollLeft = destino; return; }
    if (objetivo === null) parar();
    objetivo = destino;
    if (bucle) return;
    var anterior = 0;
    function paso(ahora) {
      if (objetivo === null) { bucle = null; return; }
      var dt = anterior ? Math.min(48, ahora - anterior) : 16.67;
      anterior = ahora;
      var antes = fila.scrollLeft, resto = objetivo - antes;
      fila.scrollLeft = antes + resto * (1 - Math.pow(1 - 0.24, dt / 16.67));
      // scrollLeft se redondea al píxel: cerca del objetivo el paso es menor
      // que medio píxel y la fila dejaba de avanzar con el bucle todavía vivo,
      // a dos píxeles del final. Si el paso ya no mueve nada, se llega de una.
      if (Math.abs(resto) < 0.5 || Math.abs(fila.scrollLeft - antes) < 0.5) {
        fila.scrollLeft = objetivo; objetivo = null; bucle = null; return;
      }
      bucle = requestAnimationFrame(paso);
    }
    bucle = requestAnimationFrame(paso);
  }, { passive: false });

  // ── Traer a la vista la pestaña elegida ──
  // Con el margen del desvanecido a cada lado, para que no quede debajo de la
  // flecha; y sólo si hace falta. Si el destino cae a menos de un desvanecido
  // del extremo, se va al extremo: con margen quedaría una franja borrosa sin
  // nada detrás.
  function mostrar(pestana) {
    if (!pestana || maximo() <= 0) return;
    var f = fundido(), ini = fila.scrollLeft, ancho = fila.clientWidth;
    var izq = pestana.offsetLeft, der = izq + pestana.offsetWidth;
    if (izq < ini + f) ir(izq - f < f ? 0 : izq - f);
    else if (der > ini + ancho - f) ir(der + f > fila.scrollWidth - f ? maximo() : der - ancho + f);
  }

  // ── El empujoncito ──
  // La primera vez que la fila entra en pantalla con carta escondida a la
  // derecha y nadie la ha tocado todavía, la flecha avanza dos veces.
  var sig = $(".menu__desliza--sig", contenedor);
  var icono = sig && sig.querySelector("svg");
  if (icono && icono.animate && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(function(entradas) {
      if (!entradas[0].isIntersecting) return;
      io.disconnect();
      setTimeout(function() {
        if (tocada || !conMovimiento() || !contenedor.classList.contains("is-desborda-fin")) return;
        icono.animate([
          { transform: "translateX(0)" },
          { transform: "translateX(5px)", offset: 0.18 },
          { transform: "translateX(0)", offset: 0.36 },
          { transform: "translateX(5px)", offset: 0.54 },
          { transform: "translateX(0)", offset: 0.72 },
          { transform: "translateX(0)" }
        ], { duration: 1300, easing: "ease-in-out" });
      }, 700);
    }, { threshold: 0.9 });
    io.observe(contenedor);
  }

  return { mostrar: mostrar };
}
