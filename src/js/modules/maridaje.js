/* La carta de maridaje de Catas (views/home/_catas.php)
 *
 * El servidor la imprime entera, una sección debajo de otra con su título: así
 * se lee sin JS. Las pestañas las pone el componente de marca
 * initPestanasImpresas() (pestanas.js), que usa también la vitrina de la
 * panadería.
 */
function initMaridaje() {
  var raiz = $("[data-maridaje]");
  if (!raiz) return;
  initPestanasImpresas(raiz, raiz.querySelector(".maridaje__pestanas"));
}
