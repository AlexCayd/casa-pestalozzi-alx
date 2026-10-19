/* Navegación: menú a pantalla completa y anclas internas */
function initNav() {
  var toggle = $("#navToggle");
  var overlay = $("#navOverlay");
  var ilustracion = overlay ? overlay.querySelector(".orn--nav") : null;

  // Abrir y cerrar en un solo sitio, para que el estado accesible no se separe
  // del visual: antes el botón decía «Abrir menú» también con el menú abierto
  // y el foco se quedaba detrás del panel.
  // `conTeclado`: el anillo de foco sólo se enseña si el menú se abrió con el
  // teclado; abierto con el ratón, el primer destino recibe el foco sin
  // pintarse como si alguien hubiera tabulado hasta él.
  // Con el menú abierto la página de detrás no se mueve ni se alcanza: Lenis se
  // detiene (desplaza por código y un `overflow` no lo frena) y todo lo que no
  // es el panel ni su botón queda inerte. Si no, la rueda seguía recorriendo la
  // portada bajo el panel y el tabulador salía del menú a enlaces invisibles.
  var estaAbierto = false;
  var soltarFondo = null;
  var overflowPrevio = "";

  function bloquearFondo(activo) {
    if (activo === estaAbierto) return;
    estaAbierto = activo;
    if (activo) {
      overflowPrevio = body.style.overflow;
      body.style.overflow = "hidden";
      if (lenis) lenis.stop();
      soltarFondo = inertarFondo([overlay, toggle]);
    } else {
      body.style.overflow = overflowPrevio;
      if (lenis) lenis.start();
      if (soltarFondo) soltarFondo();
      soltarFondo = null;
    }
  }

  function abrir(estado, conTeclado) {
    var abierto = body.classList.toggle("nav-open", estado);
    toggle.setAttribute("aria-expanded", abierto ? "true" : "false");
    toggle.setAttribute("aria-label", abierto ? "Cerrar menú" : "Abrir menú");
    bloquearFondo(abierto);
    // El bucle de la ilustración (lámpara, olivo, destellos) sólo corre con el
    // menú a la vista; el dibujo de entrada lo hace el CSS con body.nav-open.
    if (ilustracion) ilustracion.classList.toggle("orn--viva", abierto);
    if (abierto) {
      // Al primer destino, cuando el círculo ya cubre la pantalla: antes, el
      // panel todavía es `visibility: hidden` y el foco no entraría.
      setTimeout(function() {
        var primero = overlay && overlay.querySelector(".nav-overlay__links a");
        if (primero && body.classList.contains("nav-open")) primero.focus({ preventScroll: true, focusVisible: !!conTeclado });
      }, 60);
    }
    return abierto;
  }

  // Un clic que llega del teclado (Intro o Espacio sobre el botón) trae
  // detail 0; uno del ratón, 1 o más.
  toggle.addEventListener("click", function(e) { abrir(undefined, e.detail === 0); });

  $$("[data-nav]").forEach(function(a) {
    a.addEventListener("click", function(e) {
      e.preventDefault();
      var id = a.getAttribute("href");
      abrir(false);
      setTimeout(function() { irASeccion(id); }, 200);
    });
  });

  // brand + rail + footer + anchors in-page
  $$('a[href^="#"]').forEach(function(a) {
    if (a.hasAttribute("data-nav")) return;
    a.addEventListener("click", function(e) {
      var id = a.getAttribute("href");
      if (id.length < 2 || !$(id)) return;
      e.preventDefault();
      irASeccion(id);
    });
  });

  document.addEventListener("keydown", function(e) {
    if (e.key !== "Escape" || !body.classList.contains("nav-open")) return;
    abrir(false);
    toggle.focus();
  });
}
