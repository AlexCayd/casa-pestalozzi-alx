/* Hero WebGL — la portada como material, no como fotografía.
   ------------------------------------------------------------------
   Un único plano a pantalla completa con la foto del hero como textura y un
   shader que hace dos cosas que el CSS no puede:

     1. Un flujo lento de vapor que sube por la imagen. Es lo que da la
        sensación de cocina caliente detrás del cristal.
     2. El viraje a la paleta de la casa. La foto original tira a dorado —una
        copa de vino blanco a contraluz—, que es justo el color que salió de
        la marca. El shader la lleva a café en las sombras y beige en las
        luces, así que la portada deja de pelearse con el resto de la página.

   Hubo una tercera: refracción alrededor del puntero, con la imagen separada
   en canales de color como al mirar a través de una copa. Se retiró — la
   fachada se deformaba al pasar el ratón y el efecto llamaba más la atención
   que el nombre del restaurante. Con ella se fueron el uniform uMouse y el
   listener de mousemove.

   Todo es degradable: sin WebGL, con movimiento reducido o en un puntero
   grueso el <img> del marcado queda visible y no se monta nada. La clase
   .is-webgl del hero es la que decide cuál de los dos se ve, y sólo se pone
   cuando la textura ya está en la GPU. */

var heroGL = null;

var HERO_VERT = [
  "varying vec2 vUv;",
  "void main() {",
  "  vUv = uv;",
  "  gl_Position = vec4(position.xy, 0.0, 1.0);",
  "}",
].join("\n");

var HERO_FRAG = [
  "precision highp float;",
  "varying vec2 vUv;",
  "uniform sampler2D uTex;",
  "uniform vec2 uRes;",
  "uniform vec2 uTexRes;",
  "uniform float uTime;",
  "uniform float uScroll;",
  "uniform vec3 uCafe;",
  "uniform vec3 uBeige;",

  "float hash(vec2 p) { return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }",

  "float noise(vec2 p) {",
  "  vec2 i = floor(p);",
  "  vec2 f = fract(p);",
  "  vec2 u = f * f * (3.0 - 2.0 * f);",
  "  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),",
  "             mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);",
  "}",

  // Equivalente a object-fit: cover. Sin esto la foto se estira en cuanto la
  // ventana deja de tener la proporción del archivo.
  "vec2 coverUv(vec2 uv, float zoom) {",
  "  float rS = uRes.x / uRes.y;",
  "  float rI = uTexRes.x / uTexRes.y;",
  "  vec2 s = rS > rI ? vec2(1.0, rI / rS) : vec2(rS / rI, 1.0);",
  "  return (uv - 0.5) * s / zoom + 0.5;",
  "}",

  "void main() {",
  "  float aspect = uRes.x / uRes.y;",
  "  vec2 uv = vUv;",

  // El vapor sube: la coordenada Y del ruido se desplaza con el tiempo y la X
  // apenas, así que el patrón asciende en lugar de derivar de lado.
  "  float t = uTime * 0.055;",
  "  vec2 flujo = vec2(",
  "    noise(uv * 3.2 + vec2(t, -uTime * 0.11)),",
  "    noise(uv * 2.6 + vec2(43.0 - t, -uTime * 0.14))",
  "  ) - 0.5;",

  // La amplitud ya sólo depende del scroll: sin el término del puntero el
  // vapor es un movimiento de fondo constante y no una reacción al ratón.
  "  float amp = 0.0055 + uScroll * 0.012;",
  "  vec2 despl = flujo * amp;",

  // Al bajar, la portada se acerca un poco: acompaña al parallax del texto sin
  // que haya que mover el elemento en el DOM.
  "  vec2 cuv = coverUv(uv + despl, 1.0 + uScroll * 0.10);",

  "  vec3 col = texture2D(uTex, cuv).rgb;",

  // Viraje a la marca. Se construye un duotono café→beige con la luminancia y
  // se mezcla con el original: mezclarlo del todo mataba las caras y los
  // platos, que es lo único que interesa de la foto.
  //
  // El segundo velo iba en verde y era el que teñía la portada entera: sobre
  // una fachada ya oscura daba un verde botella que no dejaba salir el beige
  // del título. Va en café, el mismo con el que la casa cierra sus fondos.
  "  float lum = dot(col, vec3(0.299, 0.587, 0.114));",
  "  vec3 duo = mix(uCafe, uBeige, smoothstep(0.04, 0.88, lum));",
  "  col = mix(col, duo, 0.34);",
  "  col = mix(col, uCafe, 0.14 * (1.0 - lum));",

  // Viñeta propia del lienzo. La de la página va en multiply sobre todo el
  // documento y sobre una foto oscura no se notaba.
  "  float v = smoothstep(1.25, 0.30, length((uv - 0.5) * vec2(aspect, 1.0)));",
  "  col *= mix(0.48, 1.06, v);",

  "  gl_FragColor = vec4(col, 1.0);",
  "}",
].join("\n");

// Los tres colores de marca son hex literales en la capa 1 de _reset.scss, así
// que getComputedStyle los devuelve resueltos. Las escalas y los roles NO se
// pueden leer así: son color-mix() y el navegador entrega la función sin
// evaluar (misma trampa que las gráficas del panel, ver CLAUDE.md).
function leerColorMarca(nombre, respaldo) {
  var crudo = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
  var hex = /^#([0-9a-f]{6})$/i.exec(crudo);
  if (!hex) return respaldo;
  var n = parseInt(hex[1], 16);
  return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
}

function initHeroWebgl() {
  var host = $("[data-hero-canvas]");
  var hero = $("#hero");
  var foto = $(".hero__bg img");
  if (!host || !hero || !foto || !window.THREE) return;
  if (reduce || isTouch) return;

  var renderer;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: false, alpha: false, powerPreference: "high-performance" });
  } catch (e) {
    return; // sin contexto WebGL nos quedamos con la fotografía
  }
  if (!renderer.getContext()) return;

  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
  renderer.setSize(host.clientWidth, host.clientHeight);
  host.appendChild(renderer.domElement);

  var escena = new THREE.Scene();
  // Cámara ortográfica trivial: el vertex shader ya escribe en espacio de
  // recorte, así que la cámara sólo existe porque render() la exige.
  var camara = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);

  var uniforms = {
    uTex: { value: null },
    uRes: { value: new THREE.Vector2(host.clientWidth, host.clientHeight) },
    uTexRes: { value: new THREE.Vector2(1, 1) },
    uTime: { value: 0 },
    uScroll: { value: 0 },
    uCafe: { value: new THREE.Vector3().fromArray(leerColorMarca("--brand-cafe", [0.29, 0.18, 0.13])) },
    uBeige: { value: new THREE.Vector3().fromArray(leerColorMarca("--brand-beige", [0.89, 0.84, 0.73])) },
  };

  var malla = new THREE.Mesh(
    new THREE.PlaneGeometry(2, 2),
    new THREE.ShaderMaterial({ vertexShader: HERO_VERT, fragmentShader: HERO_FRAG, uniforms: uniforms })
  );
  escena.add(malla);

  var visible = true;
  var reloj = 0;

  new THREE.TextureLoader().load(foto.currentSrc || foto.src, function (tex) {
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    tex.generateMipmaps = false;
    tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
    uniforms.uTex.value = tex;
    uniforms.uTexRes.value.set(tex.image.width, tex.image.height);
    hero.classList.add("is-webgl");
  });

  function medir() {
    var w = host.clientWidth;
    var h = host.clientHeight;
    if (!w || !h) return;
    renderer.setSize(w, h, false);
    uniforms.uRes.value.set(w, h);
  }

  function pintar(tiempoMs) {
    if (!visible || !uniforms.uTex.value) return;
    reloj = tiempoMs;
    uniforms.uTime.value = tiempoMs;
    renderer.render(escena, camara);
  }

  window.addEventListener("resize", medir);

  // Fuera de pantalla no se pinta: el hero mide una pantalla exacta, así que
  // en cuanto el visitante entra en Nosotros el bucle sobra.
  if (window.IntersectionObserver) {
    new IntersectionObserver(function (entradas) {
      visible = entradas[0].isIntersecting;
    }, { threshold: 0 }).observe(hero);
  }

  if (window.gsap && window.ScrollTrigger) {
    gsap.ticker.add(pintar);
    ScrollTrigger.create({
      trigger: hero,
      start: "top top",
      end: "bottom top",
      onUpdate: function (self) { uniforms.uScroll.value = self.progress; },
    });
  } else {
    (function bucle(t) { pintar(t / 1000); requestAnimationFrame(bucle); })(0);
  }

  heroGL = { renderer: renderer, uniforms: uniforms, medir: medir, reloj: reloj };
}
