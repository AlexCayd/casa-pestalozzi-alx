/* ┌──────────────────────────────────────────────────────────────┐
   │  PANADERÍA — la vitrina: cambio de familia.                  │
   └──────────────────────────────────────────────────────────────┘

   Las pestañas las pone initPestanasImpresas() (modules/pestanas.js, de la
   marca); aquí va sólo lo que se ve al cambiar de familia, y sólo en la
   panadería: la maridaje de la portada sigue cambiando en seco.

   La escena es la de la vitrina de verdad cuando se cambia de charola, y todo
   viaja en el mismo sentido —el de la pestaña nueva—:
     1. La píldora se estira hacia la pestaña nueva y la alcanza (el borde que
        va delante sale primero; el de atrás lo sigue). CSS, en
        .pan-vitrina__indicador. Es la respuesta inmediata al clic.
     2. Las piezas que se van se retiran hacia el lado contrario, rápido.
     3. La foto nueva se descubre como una persiana en el sentido del viaje,
        encima de la anterior, que se aparta un poco por debajo. Si las dos
        familias comparten foto, la foto no se mueve: no cambió nada.
     4. Las piezas nuevas entran en fila y los puntos guía se trazan hasta el
        precio, como se escribe una pizarra. El precio no espera a los puntos:
        es el dato que se viene a leer.
     5. La altura del bloque se interpola: lo de abajo no salta.
   La primera fila se lee a los ~0.3 s y el último punto llega antes del
   segundo. Es interrumpible: otro clic corta la escena en curso y la
   siguiente arranca de lo que hay en pantalla, sin volver a enseñar la
   familia que se quiso saltar.

   Movimiento reducido, .no-anim o sin GSAP: el cambio es en seco con un
   fundido corto (reducido no es inmóvil), y la píldora salta sin estirarse. */

function initVitrinaPanaderia(raiz) {
  var lista = raiz.querySelector(".pan-vitrina__pestanas");
  var marco = raiz.querySelector(".pan-vitrina__paneles");
  if (!lista || !marco) return;

  var indicador = document.createElement("span");
  indicador.className = "pan-vitrina__indicador";
  indicador.setAttribute("aria-hidden", "true");
  lista.insertBefore(indicador, lista.firstChild);
  lista.classList.add("tiene-indicador");

  // overflow-y: clip recorta sólo en vertical. Donde no existe (Safari < 16),
  // hidden recorta también el deslizamiento lateral de las filas, que es menos
  // malo que dejar las piezas nuevas encima del párrafo de abajo.
  var recorteY = window.CSS && CSS.supports && CSS.supports("overflow-y", "clip") ? "clip" : "hidden";

  var pestanaActiva = null;
  var enCurso = null; // la escena que está corriendo
  var espera = null; // la foto de la familia a la vista, si todavía viene en camino
  var refresco = 0;

  function sinMovimiento() {
    return reduce || body.classList.contains("no-anim") || !window.gsap;
  }

  // ── 1 · La píldora ──────────────────────────────────────────
  // left/right y no transform+width: así cada borde lleva su propio retardo
  // y la píldora se estira y se recoge sin deformar sus extremos redondos.
  // Es un solo elemento absoluto dentro de la fila; el costo de layout es
  // despreciable.
  //
  // Dentro de una fila con scroll horizontal, `left` se cuenta desde el
  // origen del contenido (como offsetLeft) y `right` desde el borde derecho
  // de la caja visible: por eso `der` sale de clientWidth y puede ser
  // negativo cuando la pestaña queda fuera de la vista.
  function colocarIndicador(pestana, viajar) {
    var anterior = pestanaActiva;
    pestanaActiva = pestana;
    var izq = pestana.offsetLeft;
    var der = lista.clientWidth - (izq + pestana.offsetWidth);

    // El estirón sólo con movimiento: la regla global de movimiento reducido
    // anula las duraciones pero no los retardos, y la píldora se quedaba un
    // instante cubriendo todas las pestañas del camino.
    if (viajar && anterior && anterior !== pestana && !sinMovimiento()) {
      indicador.setAttribute("data-dir", izq > anterior.offsetLeft ? "1" : "-1");
    } else {
      indicador.removeAttribute("data-dir");
    }
    // Medir no es viajar: sin transición mientras se coloca.
    if (!viajar) indicador.classList.remove("is-listo");

    indicador.style.top = pestana.offsetTop + "px";
    indicador.style.height = pestana.offsetHeight + "px";
    indicador.style.left = izq + "px";
    indicador.style.right = der + "px";

    if (!viajar) {
      // Leer el ancho obliga a calcular el estilo AHORA, con la posición
      // nueva y sin transición; así is-listo no encuentra una posición
      // anterior desde la que viajar (al cargar, la píldora crecía desde el
      // borde de la fila).
      void indicador.offsetWidth;
      indicador.classList.add("is-listo");
      return;
    }

    // En móvil la fila desborda: la pestaña elegida se trae a la vista sin
    // mover la página (scrollIntoView arrastraría también el scroll vertical).
    var visibleIzq = lista.scrollLeft;
    var visibleDer = visibleIzq + lista.clientWidth;
    if (izq < visibleIzq || izq + pestana.offsetWidth > visibleDer) {
      lista.scrollTo({
        left: Math.max(0, izq - (lista.clientWidth - pestana.offsetWidth) / 2),
        behavior: sinMovimiento() ? "auto" : "smooth",
      });
    }
  }

  function recolocar() {
    if (pestanaActiva) colocarIndicador(pestanaActiva, false);
  }

  function refrescarScroll() {
    clearTimeout(refresco);
    refresco = setTimeout(function() { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, 120);
  }

  // ── Fotos ───────────────────────────────────────────────────
  function cargada(img) {
    return !!img && img.complete && img.naturalWidth > 0;
  }

  function retirar(img) {
    if (img && img.parentNode) img.parentNode.removeChild(img);
  }

  // La copia de la foto que estaba a la vista, debajo de la nueva. Va DESPUÉS
  // de la imagen real para que el visor (lightbox.js, que toma la primera
  // <img> de la figura) siga abriendo la foto de esta familia. Ya está en
  // memoria, así que se pinta en el mismo fotograma.
  function copiaDe(img, figura) {
    if (!cargada(img)) return null;
    var c = document.createElement("img");
    c.className = "pan-vitrina__foto-previa";
    c.alt = "";
    c.setAttribute("aria-hidden", "true");
    c.src = img.currentSrc || img.src;
    figura.appendChild(c);
    return c;
  }

  function persiana(tl, img, previa, dir, en) {
    tl.fromTo(img,
      { clipPath: dir > 0 ? "inset(0% 0% 0% 100%)" : "inset(0% 100% 0% 0%)", scale: 1.06 },
      { clipPath: "inset(0% 0% 0% 0%)", scale: 1, duration: 0.6, ease: "power3.inOut", clearProps: "clipPath,transform" }, en);
    if (previa) {
      tl.fromTo(previa,
        { xPercent: 0, scale: 1 },
        { xPercent: -dir * 6, scale: 1.03, duration: 0.6, ease: "power3.inOut" }, en);
    }
    return tl;
  }

  // La foto de la familia nueva todavía viene en camino (las de los paneles
  // ocultos nacen lazy, y con datos reducidos no se adelantan). La anterior la
  // sostiene un momento —un marco vacío a media escena se lee como un error—,
  // pero no más de 0.9 s: pasado eso, la foto de otra familia bajo este nombre
  // ya sería mentir, y se retira. Cuando llega, corre la persiana, o aparece
  // si ya no queda nada debajo. Una foto rota (error) se enseña como venga.
  // El siguiente cambio de familia cancela la espera.
  function esperarFoto(img, imgAntes, figura, dir, misma) {
    var copia = copiaDe(imgAntes, figura);
    var tope = 0;
    gsap.set(img, { opacity: 0 });

    var registro = {
      img: copia ? imgAntes : null, // la foto que de verdad está a la vista
      cancelar: function() {
        soltar();
        if (copia) gsap.killTweensOf(copia);
        retirar(copia);
        gsap.set(img, { clearProps: "opacity" });
      },
    };

    function soltar() {
      clearTimeout(tope);
      img.removeEventListener("load", llegar);
      img.removeEventListener("error", llegar);
      if (espera === registro) espera = null;
    }

    function llegar() {
      soltar();
      gsap.set(img, { clearProps: "opacity" });
      // La copia sólo sirve de fondo si sigue a la vista: si el tope ya la
      // estaba fundiendo, se quita y la foto aparece sola.
      var debajo = registro.img ? copia : null;
      if (!debajo && copia) { gsap.killTweensOf(copia); retirar(copia); }
      if (misma || img.naturalWidth === 0 || sinMovimiento()) { retirar(copia); return; }
      if (debajo) {
        persiana(gsap.timeline({ onComplete: function() { retirar(debajo); } }), img, debajo, dir, 0);
      } else {
        gsap.fromTo(img, { opacity: 0 }, { opacity: 1, duration: 0.35, ease: "power1.out", clearProps: "opacity" });
      }
    }

    // La misma foto que ya estaba no miente: se sostiene hasta que llegue.
    if (copia && !misma) {
      tope = setTimeout(function() {
        registro.img = null;
        gsap.to(copia, { opacity: 0, duration: 0.3, ease: "power1.out", onComplete: function() { retirar(copia); } });
      }, 900);
    }
    img.addEventListener("load", llegar);
    img.addEventListener("error", llegar);
    espera = registro;
  }

  // ── 2–5 · El cambio de panel ────────────────────────────────
  // Reducido no es inmóvil: sin desplazamiento, pero con un fundido corto
  // para que quede la señal de que la familia cambió. WAAPI y no GSAP: este
  // camino es también el de cuando GSAP no llegó.
  function cambioSeco(desde, hacia) {
    desde.hidden = true;
    hacia.hidden = false;
    if (hacia.animate) hacia.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160, easing: "ease-out" });
    refrescarScroll();
  }

  function transicion(desde, hacia, dir) {
    // Lo que está en pantalla AHORA, también a media escena: la siguiente
    // arranca de ahí y no del estado final de la anterior (si no, la altura
    // saltaba al final de la escena cortada antes de volver a interpolar).
    var alturaAntes = marco.offsetHeight;
    var interrumpida = enCurso;
    var imgAntes = espera ? espera.img
      : interrumpida ? interrumpida.fotoVisible()
      : desde.querySelector(".pan-vitrina__foto img");

    if (interrumpida) interrumpida.terminar();
    if (espera) espera.cancelar();
    if (sinMovimiento()) { cambioSeco(desde, hacia); return; }

    var piezasSale = desde.querySelector(".pan-vitrina__piezas");
    var fotoEntra = hacia.querySelector(".pan-vitrina__foto");
    var imgEntra = fotoEntra ? fotoEntra.querySelector("img") : null;
    var salida = null;
    var entrada = null;
    var previa = null;
    var saliendo = true;

    var escena = {
      // La foto que se ve ahora mismo, para la copia de la escena siguiente.
      fotoVisible: function() {
        return !saliendo && imgEntra && imgEntra.complete ? imgEntra : imgAntes;
      },
      terminar: function() {
        if (salida) salida.progress(1); // → entrar()
        if (entrada) entrada.progress(1); // → cerrar()
      },
    };
    enCurso = escena;

    // Interrumpida: lo que estaba a la vista era una familia a medio llegar o
    // a medio irse. Volver a sacarla durante 0.16 s sería enseñar la que se
    // quiso saltar (con las flechas del teclado, una tras otra): se entra
    // directo.
    if (interrumpida || !piezasSale) {
      entrar();
    } else {
      salida = gsap.to(piezasSale, {
        opacity: 0,
        x: -dir * 16,
        duration: 0.16,
        ease: "power1.in",
        onComplete: entrar,
      });
    }

    function entrar() {
      saliendo = false;
      if (piezasSale) gsap.set(piezasSale, { clearProps: "opacity,transform" });
      desde.hidden = true;
      hacia.hidden = false;

      var alturaDespues = marco.offsetHeight;
      // Las ocho primeras filas son las que caben a la vista; las demás
      // aparecen juntas, sin hacer cola.
      var filas = Array.prototype.slice.call(hacia.querySelectorAll(".pan-pieza"));
      var primeras = filas.slice(0, 8);
      var resto = filas.slice(8);
      var puntos = primeras.map(function(f) { return f.querySelector(".pan-pieza__puntos"); }).filter(Boolean);

      entrada = gsap.timeline({ onComplete: cerrar });

      // 5 · Altura. Recorta sólo en vertical: el desplazamiento lateral de
      // las filas no se corta en el borde.
      if (Math.abs(alturaDespues - alturaAntes) > 1) {
        marco.style.overflowY = recorteY;
        entrada.fromTo(marco,
          { height: alturaAntes },
          { height: alturaDespues, duration: 0.5, ease: "power3.inOut", clearProps: "height" }, 0);
      }

      // 3 · La persiana de la foto, sobre la que estaba a la vista.
      if (imgEntra) {
        var misma = !!imgAntes && imgAntes.src === imgEntra.src;
        if (!imgEntra.complete) {
          esperarFoto(imgEntra, imgAntes, fotoEntra, dir, misma);
        } else if (imgEntra.naturalWidth > 0 && !misma) {
          previa = copiaDe(imgAntes, fotoEntra);
          persiana(entrada, imgEntra, previa, dir, 0.02);
        }
      }

      // 4 · Las piezas, en fila desde el lado del viaje, y sus puntos guía.
      if (primeras.length) {
        entrada.fromTo(primeras,
          { opacity: 0, x: dir * 22 },
          { opacity: 1, x: 0, duration: 0.45, ease: "power3.out", stagger: 0.035, clearProps: "opacity,transform" }, 0.04);
      }
      if (puntos.length) {
        entrada.fromTo(puntos,
          { clipPath: "inset(0% 100% 0% 0%)" },
          { clipPath: "inset(0% 0% 0% 0%)", duration: 0.42, ease: "power2.out", stagger: 0.035, clearProps: "clipPath" }, 0.14);
      }
      if (resto.length) {
        entrada.fromTo(resto,
          { opacity: 0 },
          { opacity: 1, duration: 0.3, ease: "power1.out", clearProps: "opacity" }, 0.28);
      }
    }

    function cerrar() {
      marco.style.overflowY = "";
      retirar(previa);
      previa = null;
      if (enCurso === escena) enCurso = null;
      refrescarScroll();
    }
  }

  // Las fotos de los paneles ocultos nacen lazy y no se piden hasta que se
  // enseñan. Cuando la vitrina se acerca a la vista se piden y se decodifican
  // todas, para que la persiana corra sobre una foto lista y no sobre un
  // primer fotograma en blanco. Con «ahorro de datos» no se adelantan: la
  // escena sabe esperar (esperarFoto).
  function precargarFotos() {
    if (navigator.connection && navigator.connection.saveData) return;
    Array.prototype.forEach.call(marco.querySelectorAll(".pan-vitrina__foto img"), function(img) {
      img.loading = "eager";
      if (img.decode) img.decode().catch(function() {});
    });
  }
  if ("IntersectionObserver" in window) {
    var vigia = new IntersectionObserver(function(entradas) {
      if (!entradas.some(function(e) { return e.isIntersecting; })) return;
      vigia.disconnect();
      precargarFotos();
    }, { rootMargin: "600px 0px" });
    vigia.observe(raiz);
  } else {
    precargarFotos();
  }

  var primera = true;
  initPestanasImpresas(raiz, lista, {
    transicion: transicion,
    alElegir: function(pestana) {
      colocarIndicador(pestana, !primera);
      primera = false;
    },
  });

  // El ancho de las pestañas cambia cuando llegan las fuentes de marca, al
  // girar o redimensionar y con el zoom de texto. Se observa cada pestaña y
  // no sólo la fila: una etiqueta que crece no cambia el tamaño de la fila.
  if ("ResizeObserver" in window) {
    var ro = new ResizeObserver(recolocar);
    ro.observe(lista);
    Array.prototype.forEach.call(lista.querySelectorAll('[role="tab"]'), function(p) { ro.observe(p); });
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(recolocar);
}
