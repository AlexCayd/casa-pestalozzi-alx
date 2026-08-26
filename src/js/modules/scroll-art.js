/* Dirección de escena — la capa GSAP que no es del hero.
   ------------------------------------------------------------------
   Siete trabajos, todos colgados de ScrollTrigger:

     · La marca fija, el botón de menú, el cursor y el rail se resuelven por
       negativo contra la banda que pasa por debajo. Es lo único que no cuelga de
       ScrollTrigger, y lo único que corre también con movimiento reducido.
     · Los títulos de sección entran por líneas, no en bloque.
     · Las portadas se abren con un acercamiento en lugar de aparecer.
     · La insegna acelera con el scroll y cambia de sentido al subir.
     · El arco se abre de abajo arriba: la forma firma de la casa deja de ser
       sólo un recorte y pasa a actuar.
     · La regla del eyebrow se traza sola al entrar cada sección.
     · La viñeta respira al cruzar de un tono a otro.

   Todo depende de gsap + ScrollTrigger; sin ellos boot() ni siquiera llama
   aquí y el CSS deja las secciones visibles y la insegna corriendo sola.

   La excepción son tonoBajoLaMarca() y tonoBajoElRail(), que viven en este
   archivo pero NO se llaman desde initScrollArt(): las llama boot() por su
   cuenta. El negativo no es decoración —es lo único que mantiene legible el
   nombre del restaurante y el índice de secciones— y atarlo a que las libs de
   movimiento hayan cargado dejaba la marca en crema fija sobre las secciones
   claras cuando no llegaban. */

function initScrollArt() {
  if (reduce) return;
  titulosPorLinea();
  portadasConAcercamiento();
  insegnaConScroll();
  arcosQueAbren();
  trazoDeEyebrows();
  respiracionDeVineta();
}

// ── El tono que hay bajo la marca ────────────────────────────
//
// La marca fija, el botón de menú y el cursor viven fuera de todo [data-tono]:
// son position:fixed y cuelgan del <body>, así que heredan la capa 3 de :root y
// no tienen forma de saber sobre qué fondo están pasando. Antes se resolvía con
// un único bit (.fuera-del-hero → café), pero la landing alterna claras y
// oscuras: el café se perdía sobre verde y sobre café, y de ahí la sombra que
// había que ponerle debajo para rescatarlo.
//
// Aquí se publica el tono real en body[data-tono-actual] y el CSS elige tinta
// clara u oscura. El rail se resolvía por mix-blend-mode y venía con el mismo
// problema que la marca —sobre café daba negro sobre marrón—; ahora consume este
// mismo mecanismo con su propia línea.
//
// Se resolvía con un IntersectionObserver sobre la franja superior, es decir
// por FLANCOS: sólo escribía cuando una banda ENTRABA en la franja. Eso dejaba
// tres huecos, y los tres se veían:
//
//   · con dos bandas cruzando la franja a la vez ganaba la última en orden de
//     documento, aunque sólo asomara por el borde de abajo;
//   · el valor inicial se fijaba antes de que asentaran las alturas —imágenes
//     lazy, la carta y la galería las pinta el JS— y ya nada lo corregía hasta
//     el siguiente flanco;
//   · sin GSAP no se llamaba nunca (ver la cabecera del archivo).
//
// Ahora se resuelve por GEOMETRÍA: en cada evaluación se busca qué banda cubre
// la línea de la marca. Es determinista en cualquier instante y no depende de
// haber presenciado el cruce.
// Dos consumidores, dos líneas: la marca vive en los 48px de arriba y el rail en
// el centro del viewport. A mitad de un cruce están sobre bandas distintas, así
// que cada uno publica su propio atributo — el negativo de uno no sirve al otro.
var TONO_LINEA = 48; // la línea media de la marca fija, medida desde arriba

function tonoBajoLaMarca() {
  publicarTonoDeBanda(function() { return TONO_LINEA; }, "tonoActual");
}

function tonoBajoElRail() {
  publicarTonoDeBanda(function() { return innerHeight / 2; }, "tonoRail");
}

// `lineaFn` se evalúa en cada pasada y no se cachea: la del rail depende del
// alto de la ventana, que cambia al girar una tablet o al abrirse la barra de
// direcciones del móvil.
function publicarTonoDeBanda(lineaFn, clave) {
  // Sólo las bandas de primer nivel del documento. El filtro es por PADRE y no
  // por `closest("main")` porque hay tonos anidados —la lámina del mapa va en
  // crema dentro de una sección verde— y esos no son el fondo bajo la marca,
  // son una pieza dentro de la banda. El overlay del nav y el lightbox también
  // llevan tono y quedan fuera por lo mismo: son capas por encima.
  var main = $("main");
  var secciones = $$("[data-tono]").filter(function(el) {
    var padre = el.parentElement;
    // ScrollTrigger envuelve lo que fija en un .pin-spacer y con ello la banda
    // deja de ser hija de <main>. Sin esta línea, fijar una sección la sacaría
    // del negativo sin que nada lo avisara.
    if (padre && padre.classList.contains("pin-spacer")) padre = padre.parentElement;
    return (main && padre === main) || el.tagName === "FOOTER";
  });

  // Franjas anidadas que SÍ mandan sobre su banda: un bloque de fotografía a
  // sangre dentro de una sección clara. El mosaico de Panadería es el caso —
  // sección crema, marca en café, y debajo fotos oscuras—. Declararlo es lo
  // mismo que hace [data-tono="foto"] con el contenido: decir "aquí abajo hay
  // una foto", en vez de ponerle una sombra de rescate a la marca.
  var franjas = $$("[data-tono-franja]");

  if (!secciones.length) return;

  // Qué tono cubre la línea de la marca AHORA. Las franjas van primero: son la
  // excepción declarada y ganan a la banda que las contiene.
  function resolver() {
    var linea = lineaFn();
    var i;
    for (i = 0; i < franjas.length; i++) {
      var f = franjas[i].getBoundingClientRect();
      if (f.top <= linea && f.bottom > linea) {
        return franjas[i].getAttribute("data-tono-franja") || "";
      }
    }
    for (i = 0; i < secciones.length; i++) {
      var r = secciones[i].getBoundingClientRect();
      if (r.top <= linea && r.bottom > linea) {
        return secciones[i].getAttribute("data-tono") || "";
      }
    }
    return null; // hueco entre bandas: se conserva lo último válido
  }

  var pendiente = false;

  function aplicar() {
    pendiente = false;
    var tono = resolver();
    // Sólo se escribe cuando cambia: el atributo dispara una transición de
    // color de medio segundo y reescribirlo en cada fotograma la reiniciaría.
    if (tono !== null && tono !== body.dataset[clave]) {
      body.dataset[clave] = tono;
    }
  }

  function pedir() {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(aplicar);
  }

  // Síncrono en el arranque: el primer pintado ya sale con la tinta correcta.
  aplicar();

  // Lenis desplaza la ventana de verdad (wrapper = window), así que el `scroll`
  // nativo cubre tanto el suave como el normal.
  window.addEventListener("scroll", pedir, { passive: true });
  window.addEventListener("resize", pedir);
  // Las imágenes lazy cambian el alto de media página al llegar.
  window.addEventListener("load", pedir);
  if (window.ScrollTrigger) ScrollTrigger.addEventListener("refresh", pedir);
}

// ── Títulos por línea ────────────────────────────────────────
//
// Se corta por los <br> que trae el marcado, si los trae, en vez de medir el
// salto real: medir líneas obliga a recalcular en cada resize.
//
// Los títulos de sección pasaron a una sola línea y ya no llevan <br>, y eso
// está contemplado: `lineas` arranca con un cubo, así que un título sin saltos
// devuelve UN interior y la línea de tiempo lo anima igual — entra en bloque en
// vez de escalonado. El corte a mano sobrevive sólo donde sigue habiendo <br>.
//
// Los nodos se MUEVEN, no se copian por innerHTML: dentro de casi todos los
// títulos vive un <em class="accent-italic"> y reconstruirlos como texto plano
// se llevaría la cursiva por delante.
function partirEnLineas(el) {
  if (el.dataset.partido === "1") return [];
  var lineas = [[]];
  Array.prototype.slice.call(el.childNodes).forEach(function(nodo) {
    if (nodo.nodeName === "BR") lineas.push([]);
    else lineas[lineas.length - 1].push(nodo);
  });
  if (lineas.length < 1) return [];

  el.innerHTML = "";
  var interiores = [];
  lineas.forEach(function(nodos) {
    var utiles = nodos.filter(function(n) {
      return n.nodeType !== 3 || (n.textContent || "").trim() !== "";
    });
    if (!utiles.length) return;

    var caja = document.createElement("span");
    caja.className = "linea";
    var dentro = document.createElement("span");
    dentro.className = "linea__in";
    nodos.forEach(function(n) { dentro.appendChild(n); });
    caja.appendChild(dentro);
    el.appendChild(caja);
    interiores.push(dentro);
  });

  el.dataset.partido = "1";
  return interiores;
}

function titulosPorLinea() {
  $$("[data-lineas]").forEach(function(titulo) {
    var interiores = partirEnLineas(titulo);
    if (!interiores.length) return;
    // El título deja de depender de [data-reveal]: su opacidad la lleva ahora
    // esta línea de tiempo, y mantener las dos dejaba el bloque a medio opacar.
    titulo.removeAttribute("data-reveal");
    gsap.set(titulo, { opacity: 1, y: 0 });
    gsap.fromTo(interiores,
      { yPercent: 108 },
      {
        yPercent: 0,
        duration: 1.15,
        stagger: 0.09,
        ease: "power4.out",
        scrollTrigger: { trigger: titulo, start: "top 86%" },
      });
  });
}

// ── Portadas ─────────────────────────────────────────────────
//
// El acercamiento va sobre el <img> y no sobre el marco: los marcos con arco
// recortan por border-radius y cualquier transform sobre ellos delataría la
// esquina cuadrada de la caja.
function portadasConAcercamiento() {
  $$("[data-portada]").forEach(function(marco) {
    var img = marco.querySelector("img");
    if (!img) return;
    gsap.fromTo(img,
      { scale: 1.22 },
      {
        scale: 1,
        duration: 1.6,
        ease: "power3.out",
        scrollTrigger: { trigger: marco, start: "top 88%" },
      });
  });
}

// ── Insegna ──────────────────────────────────────────────────
//
// La banda ya corre sola por CSS; cuando GSAP toma el mando se marca
// .is-scrubbed para apagar la animación y no sumar dos transformaciones sobre
// el mismo elemento.
function insegnaConScroll() {
  $$("[data-insegna]").forEach(function(banda) {
    var pista = banda.querySelector("[data-insegna-pista]");
    if (!pista) return;
    banda.classList.add("is-scrubbed");

    var giro = gsap.to(pista, {
      xPercent: -50,
      duration: 38,
      ease: "none",
      repeat: -1,
    });

    ScrollTrigger.create({
      trigger: banda,
      start: "top bottom",
      end: "bottom top",
      onUpdate: function(self) {
        var v = self.getVelocity();
        // El sentido lo marca el scroll; la velocidad se topa para que un
        // golpe de rueda no dispare el rótulo fuera de pantalla.
        giro.timeScale(Math.sign(v || 1) * Math.min(1 + Math.abs(v) / 700, 7));
      },
    });

    // Sin movimiento el rótulo se queda quieto: acelerar sobre el scroll de
    // otro es exactamente el efecto que marea.
    ScrollTrigger.addEventListener("scrollEnd", function() { giro.timeScale(1); });
  });
}

// ── El arco se abre ──────────────────────────────────────────
//
// El medio punto es la forma firma de la casa, pero hasta ahora sólo recortaba:
// la portada aparecía ya abierta y el arco era un marco, no un gesto. Aquí el
// hueco crece de abajo arriba, como quien levanta la persiana de la trattoria.
//
// Va con clip-path sobre el MARCO y no con height, porque el marco ya lleva su
// border-radius y animar la altura deformaría el medio punto en cada
// fotograma. El acercamiento del <img> (portadasConAcercamiento) sigue
// corriendo por debajo: uno abre el hueco y el otro empuja la imagen.
function arcosQueAbren() {
  $$(".arco, .arco--rebajado").forEach(function(marco) {
    gsap.fromTo(marco,
      { clipPath: "inset(100% 0% 0% 0%)" },
      {
        clipPath: "inset(0% 0% 0% 0%)",
        duration: 1.4,
        ease: "power3.inOut",
        scrollTrigger: { trigger: marco, start: "top 85%" },
        // El clip se retira al acabar: dejarlo puesto crea un contexto de
        // recorte permanente que se come cualquier sombra que el marco quiera
        // proyectar más adelante.
        onComplete: function() { gsap.set(marco, { clipPath: "none" }); },
      });
  });
}

// ── El trazo del eyebrow ─────────────────────────────────────
//
// La rayita del ::before de .eyebrow se dibuja al entrar la sección. Es el
// detalle de mayor frecuencia de la landing —aparece en las ocho secciones— y
// es lo que hace que el conjunto se lea como trazado a mano y no compuesto.
//
// El ancho del pseudoelemento no se puede animar desde GSAP, así que se anima
// una custom property que el CSS consume como factor de escala.
function trazoDeEyebrows() {
  $$(".eyebrow").forEach(function(eyebrow) {
    if (eyebrow.closest("#hero")) return; // la intro del hero ya lo trae

    gsap.fromTo(eyebrow,
      { "--trazo": 0 },
      {
        "--trazo": 1,
        duration: 0.9,
        ease: "power2.out",
        scrollTrigger: { trigger: eyebrow, start: "top 90%" },
      });
  });
}

// ── La viñeta respira ────────────────────────────────────────
//
// El corte entre tonos es deliberado y no se toca: la viñeta no reacciona a la
// sección, reacciona a la VELOCIDAD. Al recorrer rápido se cierra y la página
// se estrecha; al parar a leer se abre del todo. Es el pulso de sala oscura que
// el hero ya tenía y el resto de la landing no.
//
// Un único ScrollTrigger sobre el documento, no uno por sección: todos
// escribirían la misma custom property del <body> y el último en actualizar
// ganaría, que es justo como se fabrica un parpadeo.
//
// _reset.scss usa --vineta-boost para acercar el arranque del degradado, así
// que cada tono conserva su intensidad propia (--vineta) y sólo cambia cuánto
// abarca.
function respiracionDeVineta() {
  var boost = { v: 1 };
  var aplicar = function() {
    body.style.setProperty("--vineta-boost", boost.v.toFixed(3));
  };

  ScrollTrigger.create({
    trigger: document.documentElement,
    start: "top top",
    end: "bottom bottom",
    onUpdate: function(self) {
      // Topado igual que la insegna: un golpe de rueda no debe cerrar el plano
      // de golpe.
      var objetivo = 1 + Math.min(Math.abs(self.getVelocity()) / 4200, 0.32);
      gsap.to(boost, {
        v: objetivo,
        duration: 0.35,
        ease: "power2.out",
        overwrite: true,
        onUpdate: aplicar,
      });
    },
  });

  ScrollTrigger.addEventListener("scrollEnd", function() {
    gsap.to(boost, {
      v: 1,
      duration: 0.9,
      ease: "power2.out",
      overwrite: true,
      onUpdate: aplicar,
    });
  });
}
