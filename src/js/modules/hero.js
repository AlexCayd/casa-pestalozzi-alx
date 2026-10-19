/* Hero — «Isologo editorial»
 *
 * La entrada (isologo, frase, mesa que se dibuja) la hace CSS en _hero.scss:
 * así el hero se ve completo aunque GSAP no llegue. Aquí sólo quedan dos
 * cosas que CSS no puede hacer solo.
 */

/*
 * La marca fija de la esquina (y el índice lateral) se retiran mientras el
 * hero está bajo ellas: con el isologo a la vista, el nombre de la casa salía
 * dos veces en el mismo encuadre. Vuelven cuando el hero termina de pasar por
 * la franja de la marca, es decir, en el corte con Nosotros.
 *
 * Se observa el HERO y no el isologo, que es lo que se hacía: el isologo sale
 * de pantalla mucho antes que el resto de su columna, y la marca reaparecía
 * pintada encima de la frase, de la bajada y del botón de oro mientras éstos
 * seguían subiendo. Con el hero entero la entrega es limpia: la marca vuelve
 * sobre el margen superior de la sección siguiente, en el mismo instante en
 * que tonoBajoLaMarca() cambia de banda. Los 96 px son el pie de la marca
 * fija con margen.
 *
 * Va fuera de la rama de GSAP (lo llama boot() siempre), como tonoBajoLaMarca:
 * es identidad, no adorno de movimiento.
 *
 * `body.marca-en-hero` ya viene puesta desde el marcado (index.php) para que
 * el primer pintado salga sin la marca encima del rótulo; aquí sólo se
 * corrige. Si no hay con qué observar, se quita: mejor la marca de siempre
 * que una página que la pierde para el resto de la visita.
 */
function initMarcaHero() {
  var isologo = $("[data-hero-isologo]");
  var hero = isologo ? isologo.closest(".hero") : null;
  if (!hero || !("IntersectionObserver" in window)) {
    body.classList.remove("marca-en-hero");
    return;
  }
  var io = new IntersectionObserver(function(entradas) {
    entradas.forEach(function(e) {
      body.classList.toggle("marca-en-hero", e.isIntersecting);
    });
  }, { threshold: 0, rootMargin: "-96px 0px 0px 0px" });
  io.observe(hero);
}

/*
 * Los bucles de la mesa (vapor, tenedor, vino, olivo) se detienen mientras el
 * hero no está en pantalla: son transform sobre SVG, que no va al compositor
 * y cuesta hilo principal en cada fotograma aunque nadie lo esté viendo. Las
 * entradas no se tocan; sólo lo que se repite. La regla es .hero--quieta en
 * _hero.scss.
 */
function initMesaViva() {
  var hero = $("#hero");
  if (!hero || !("IntersectionObserver" in window)) return;
  new IntersectionObserver(function(entradas) {
    entradas.forEach(function(e) {
      hero.classList.toggle("hero--quieta", !e.isIntersecting);
    });
  }).observe(hero);
}

/*
 * Salida del hero con el scroll: la mesa sube un poco más despacio que el
 * texto, lo justo para que la página tenga profundidad sin que el plato se
 * despegue de su panel.
 */
function heroIntro() {
  if (reduce || !window.gsap || !window.ScrollTrigger) return;
  var mesa = $("[data-hero-mesa]");
  if (!mesa) return;
  gsap.to(mesa, {
    yPercent: -6,
    ease: "none",
    scrollTrigger: { trigger: "#hero", start: "top top", end: "bottom top", scrub: true },
  });
}

/*
 * Profundidad con el puntero. La capa viva de la mesa (hojas, harina, órbitas,
 * destellos) se desplaza unos píxeles hacia donde apunta el ratón y la mesa,
 * menos, hacia el lado contrario: dos planos. Sólo publica --hero-px y
 * --hero-py (-1 a 1); el movimiento lo hace el CSS con una transición, así que
 * aquí no hay bucle de animación.
 *
 * Sin puntero fino (tablet, teléfono) o con movimiento reducido no se engancha:
 * en una pantalla táctil no hay «apuntar», y el giroscopio no se pide.
 */
function initMesaPuntero() {
  var hero = $("#hero");
  var mesa = $("[data-hero-mesa]");
  if (!hero || !mesa || reduce) return;
  if (!window.matchMedia || !window.matchMedia("(pointer: fine)").matches) return;

  var pendiente = null;
  function aplicar() {
    var p = pendiente;
    pendiente = null;
    if (!p) return;
    var r = mesa.getBoundingClientRect();
    var x = ((p.x - (r.left + r.width / 2)) / (r.width / 2));
    var y = ((p.y - (r.top + r.height / 2)) / (r.height / 2));
    mesa.style.setProperty("--hero-px", Math.max(-1, Math.min(1, x)).toFixed(3));
    mesa.style.setProperty("--hero-py", Math.max(-1, Math.min(1, y)).toFixed(3));
  }

  hero.addEventListener("pointermove", function(e) {
    if (!pendiente) requestAnimationFrame(aplicar);
    pendiente = { x: e.clientX, y: e.clientY };
  });
  hero.addEventListener("pointerleave", function() {
    pendiente = null;
    mesa.style.setProperty("--hero-px", "0");
    mesa.style.setProperty("--hero-py", "0");
  });
}
