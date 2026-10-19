/* Adornos vectoriales de sección (views/components/ornamento.php)
 *
 * Dos cosas por adorno, con un solo observador:
 *   - la primera vez que entra en pantalla se dibuja (orn--espera → orn--dibujando);
 *   - mientras está a la vista, su bucle corre (orn--viva). Fuera de ella se
 *     para: un transform sobre SVG no va al compositor y la página tiene una
 *     docena de ellos.
 *
 * Fuera de la rama de GSAP: no depende de las libs de movimiento. Sin
 * IntersectionObserver, o con movimiento reducido, no se toca nada y el adorno
 * se queda dibujado y quieto, que es como sale del servidor.
 */
function initOrnamentos(raiz) {
  var orns = (raiz || document).querySelectorAll(".orn:not([data-orn-listo])");
  if (!orns.length) return;
  if (reduce || !("IntersectionObserver" in window)) return;

  var io = new IntersectionObserver(function(entradas) {
    entradas.forEach(function(e) {
      var o = e.target;
      if (e.isIntersecting && o.classList.contains("orn--espera")) {
        o.classList.remove("orn--espera");
        o.classList.add("orn--dibujando");
      }
      o.classList.toggle("orn--viva", e.isIntersecting);
    });
  }, { rootMargin: "0px 0px -10% 0px" });

  Array.prototype.forEach.call(orns, function(o) {
    o.setAttribute("data-orn-listo", "");
    o.classList.add("orn--espera");
    io.observe(o);
  });
}
