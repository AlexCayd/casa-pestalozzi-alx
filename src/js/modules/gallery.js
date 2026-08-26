/* Galería "Lo mejor de la casa" — la vetrina que avanza con el scroll
   ------------------------------------------------------------------
   La tira ya no se arrastra ni tiene scroll propio: se desplaza con GSAP sobre
   `x` mientras la sección cruza la pantalla. Es un solo dueño del eje, que es
   lo que antes no había —el arrastre escribía scrollLeft y una ScrollTrigger de
   scroll-art.js escribía el mismo valor por su cuenta— y lo que convertía el
   gesto en una pelea.

   Sin arrastre, la tarjeta sólo hace una cosa al tocarla: ampliar. */
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
    c.setAttribute("data-cursor", "Ampliar");
    c.setAttribute("data-zoom", "");
    c.setAttribute("data-zoom-name", g.n);
    c.setAttribute("data-zoom-cat", g.t);
    // La foto y todo lo que va encima viven dentro del hueco; la tarjeta en sí
    // es el cartón del marco (ver .gcard en _galeria.scss).
    c.innerHTML = '<div class="gcard__hueco"><img src="' + g.img + '" alt="' + g.n + '" loading="lazy" draggable="false" /><div class="gcard__cap"><div class="t">' + g.t + '</div><div class="n">' + g.n + '</div></div></div>';

    // El clic va directo en la tarjeta y no por delegación: el lightbox también
    // escucha en document, y con el arrastre había un pointer capture de por
    // medio que se comía el evento.
    (function(card) {
      card.addEventListener("click", function(e) {
        e.stopPropagation();
        if (window.__openZoom) window.__openZoom(card);
      });
    })(c);

    track.appendChild(c);
  });

  vetrinaConScroll(track);
}

/* La sección se FIJA y la tira recorre su ancho entero.
   ------------------------------------------------------------------
   El recorrido se mide en cada refresh y no se cachea: son imágenes con
   `loading="lazy"` y su ancho real no existe hasta que cargan.

   Dos intentos previos, y los dos fallaban por lo mismo —la sección se iba
   mientras la tira todavía corría—:

     · de `top bottom` a `bottom top` consumiendo el tramo central: la tira
       arrancaba en cuanto la sección asomaba por abajo, así que al llegar de
       verdad ya se había comido media galería;
     · arrancando en `top 30%` con un tramo fijo de algo más de una ventana:
       mejor principio, pero al terminar el recorrido la banda ya estaba
       saliendo de pantalla y las últimas tarjetas pasaban de largo.

   Con `pin` la sección se queda quieta y CADA píxel de scroll es recorrido de
   la tira: se ven todas, enteras, sin carrera contra la salida de la sección.
   `end` es exactamente lo que sobra de la tira, así que no hay scroll muerto
   ni al principio ni al final.

   El pin-spacer que ScrollTrigger monta alrededor de la sección la saca de ser
   hija directa de <main>, y de eso vive el filtro de bandas de
   tonoBajoLaMarca(). Está contemplado allí —si el padre es .pin-spacer se mira
   el abuelo—, así que el negativo de la marca sigue cambiando al cruzarla. */
function vetrinaConScroll(track) {
  // Sin GSAP, o con movimiento reducido, no hay nada que mueva la tira — y sin
  // arrastre las tarjetas de la derecha quedarían fuera de alcance, recortadas
  // por el `overflow: hidden` de la sección. La clase le devuelve scroll
  // horizontal propio: se recorre con la barra, con el trackpad y con el
  // teclado, sin animación ninguna.
  if (reduce || !window.gsap || !window.ScrollTrigger) {
    track.classList.add("gallery-track--estatico");
    track.setAttribute("data-lenis-prevent", "");
    return;
  }

  var seccion = track.closest("section") || track.parentNode;
  var recorrido = function() {
    return Math.min(0, track.clientWidth - track.scrollWidth);
  };

  gsap.set(track, { x: 0 });

  ScrollTrigger.create({
    trigger: seccion,
    // La sección se ha colocado del todo: a partir de aquí se queda.
    start: "top top",
    pin: true,
    // Sin él, el fijado entra un fotograma tarde y la sección da un salto al
    // engancharse.
    anticipatePin: 1,
    // Lo que sobra de tira, ni un píxel más: el tramo de scroll y el recorrido
    // son la misma distancia. Función, para que invalidateOnRefresh la remida
    // cuando cargan las imágenes lazy o cambia el ancho de la ventana.
    end: function() { return "+=" + Math.abs(recorrido()); },
    scrub: 1,
    invalidateOnRefresh: true,
    onUpdate: function(self) {
      gsap.set(track, { x: recorrido() * self.progress });
    },
  });
}
