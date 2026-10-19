/* La carta de maridaje de Catas (views/home/_catas.php)
 *
 * El servidor la imprime entera, una sección debajo de otra con su título: así
 * se lee sin JS. Aquí se convierte en pestañas para que ocupe lo que una sola
 * lista: se enseña la fila de pestañas, se ocultan los títulos y queda visible
 * un panel. Patrón de pestañas de WAI-ARIA: una parada de tabulador para el
 * grupo, flechas, Inicio y Fin.
 */
function initMaridaje() {
  var raiz = $("[data-maridaje]");
  if (!raiz) return;
  var lista = raiz.querySelector(".maridaje__pestanas");
  if (!lista) return;
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
