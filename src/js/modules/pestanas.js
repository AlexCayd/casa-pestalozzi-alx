/* MARCA — pestañas sobre contenido impreso en el servidor
 *
 * Lo usan la carta de maridaje de la portada (maridaje.js) y la vitrina de la
 * panadería (panaderia/panaderia.js). El servidor imprime todas las secciones,
 * una debajo de otra y con su título, así que sin JS se leen enteras. Aquí se
 * convierten en pestañas: se enseña la fila de pestañas (que nace `hidden`),
 * la raíz recibe .is-pestanas para ocultar los títulos y queda visible un
 * panel. Patrón de pestañas de WAI-ARIA: una parada de tabulador para el
 * grupo, flechas, Inicio y Fin.
 *
 * `lista` es la fila con los [role="tab"]; cada pestaña apunta a su panel por
 * aria-controls. Con menos de dos pestañas no hay nada que elegir y la sección
 * se queda como la imprimió el servidor.
 */
function initPestanasImpresas(raiz, lista) {
  if (!raiz || !lista) return;
  var pestanas = Array.prototype.slice.call(lista.querySelectorAll('[role="tab"]'));
  var paneles = pestanas.map(function(p) { return document.getElementById(p.getAttribute("aria-controls")); });
  if (pestanas.length < 2) return;

  lista.hidden = false;
  raiz.classList.add("is-pestanas");

  function elegir(i, enfocar) {
    pestanas.forEach(function(p, k) {
      var activa = k === i;
      p.setAttribute("aria-selected", activa ? "true" : "false");
      p.tabIndex = activa ? 0 : -1;
      if (paneles[k]) paneles[k].hidden = !activa;
    });
    if (enfocar) pestanas[i].focus();
  }

  pestanas.forEach(function(p, i) {
    p.addEventListener("click", function() { elegir(i); });
  });

  lista.addEventListener("keydown", function(e) {
    var n = pestanas.length;
    var i = pestanas.indexOf(document.activeElement);
    if (i < 0) return;
    if (e.key === "ArrowRight") i = (i + 1) % n;
    else if (e.key === "ArrowLeft") i = (i - 1 + n) % n;
    else if (e.key === "Home") i = 0;
    else if (e.key === "End") i = n - 1;
    else return;
    e.preventDefault();
    elegir(i, true);
  });

  elegir(0);
}
