const { src, dest, watch, parallel, series } = require("gulp");
const fs = require("fs");

// CSS
const sass = require("gulp-sass")(require("sass"));
const sourcemaps = require("gulp-sourcemaps");

// Imagenes
const cache = require("gulp-cache");
const imagemin = require("gulp-imagemin");
const webp = require("gulp-webp");
const avif = require("gulp-avif");

// Javascript
const terser = require("gulp-terser-js");
const concat = require("gulp-concat");
const rename = require("gulp-rename");

const paths = {
  scss: "src/scss/app.scss",
  adminScss: "src/scss/admin/shared/app-admin.scss",
  adminModulesScss: "src/scss/admin/modules/*.scss",
  // POS, tableros de área y login. Salen del bundle público —cargaban la
  // landing entera para llegar a su propio SCSS— y estrenan salida con el
  // sistema administrativo dentro.
  operationAppScss: "src/scss/operation/app-operation.scss",
  js: [
    "src/js/**/*.js",
    "!src/js/admin/**/*.js",
    "!src/js/operation/**/*.js",
    "!src/js/modules/punto-de-venta.js",
    // El catálogo de iconos vuelve a entrar, y DESPUÉS de las negaciones
    // (gulp evalúa los globs en orden). La landing no tenía catálogo, así que
    // el aviso (toast.js) y el diálogo de confirmación caían a glifos de
    // texto. En el POS llega dos veces —aquí y en map.js—, y es inocuo: las
    // dos copias publican el mismo objeto.
    "src/js/admin/core/icons.js",
  ],
  adminJs: [
    // El panel admin sólo carga /build/js/admin.js: nunca ve bundle.min.js, así
    // que sus confirmaciones y avisos deben viajar aquí.
    "src/js/components/confirmation-modal.js",
    "src/js/components/toast.js",
    // El catálogo de iconos va PRIMERO: define window.AdminIcons, que consumen
    // buzon.js y los bundles de módulo (analytics, reservaciones). El bundle es
    // un concat en scope global, así que el orden es lo que resuelve la
    // dependencia — igual que con sankey.js y finanzas.js.
    "src/js/admin/core/icons.js",
    "src/js/admin/admin.js",
    "src/js/admin/core/contextos.js",
    "src/js/admin/core/theme.js",
    // La llegada tras el login. Vive en components/ porque también la necesitan
    // el POS y los tableros, que la reciben por bundle.min.js (glob general);
    // aquí hay que nombrarla. Sin su marcado sale en la primera línea.
    "src/js/components/llegada.js",
    "src/js/admin/core/motion.js",
    "src/js/admin/core/reactive-filters.js",
    "src/js/admin/core/select.js",
    "src/js/admin/core/table-sort.js",
    "src/js/admin/core/table-rows.js",
    "src/js/admin/buzon.js",
    // El selector de periodo lo comparten analíticas, finanzas e inventario, así
    // que vive aquí y no en el bundle de analíticas.
    "src/js/admin/core/range-picker.js",
    // El histórico de precios lo abren las fichas de Menú y de Inventario: dos
    // módulos distintos, así que no cabe en el bundle de ninguno de los dos.
    "src/js/admin/core/historial-precios.js",
    // Campo de foto del platillo (Menú → alta/edición). Menú no tiene bundle
    // propio, así que va aquí como historial-precios; sin el campo en la
    // página no hace nada.
    "src/js/admin/core/foto-platillo.js",
    // Consultas rápidas: la comparten Tickets, Análisis, Menú e Inventario.
    "src/js/admin/core/consultas-rapidas.js",
  ],
  adminAnalyticsJs: [
    // mock-data.js se retiró: los datos reales llegan desde PHP como
    // window.AdminAnalyticsMock (ver AdminController::construirAnalytics).
    "src/js/admin/analytics/charts.js",
    "src/js/admin/analytics/nivel1.js",
    "src/js/admin/analytics/analytics-page.js",
    "src/js/admin/analytics/analytics.js",
  ],
  adminMapJs: [
    // toast.js no va aquí: el POS ya lo recibe dentro de bundle.min.js, que
    // carga antes que map.js.
    // icons.js va PRIMERO por la misma razón que en adminJs: define
    // window.AdminIcons, que el renderer del mapa usa para la línea de estado.
    "src/js/admin/core/icons.js",
    "src/js/components/confirmation-modal.js",
    "src/js/admin/core/select.js",
    "src/js/components/reservation-date-picker.js",
    "src/js/operation/shell.js",
    "src/js/operation/map-contract.js",
    "src/js/operation/table-state-adapter.js",
    "src/js/operation/map-visual.js",
    "src/js/operation/map-help.js",
    // Reservaciones dentro del POS. La vista compartida va antes que quienes
    // la usan (barra de contexto, cajón y ficha), y todos antes del POS.
    "src/js/operation/pos-reserva-vista.js",
    "src/js/operation/pos-proyeccion.js",
    "src/js/operation/pos-contexto.js",
    "src/js/operation/pos-cajon.js",
    "src/js/operation/pos-ficha.js",
    "src/js/operation/pos-alta.js",
    "src/js/modules/punto-de-venta.js",
  ],
  adminAreaJs: "src/js/admin/area/area.js",
  adminRecetasJs: "src/js/admin/recetas/recipe-builder.js",
  adminInventarioJs: "src/js/admin/inventario/inventario.js",
  adminReservationListJs: "src/js/admin/reservations/lista.js",
  // Casos del panel estratégico. ConfirmationModal y AppNotice ya llegan en
  // admin.js, que el layout carga antes que cualquier bundle de módulo.
  adminReservationEstrategiaJs: "src/js/admin/reservations/estrategia.js",
  adminUsersJs: "src/js/admin/users/users-form.js",
  adminUsersDirectosJs: "src/js/admin/users-directos.js",
  adminCatasJs: "src/js/admin/catas/catas.js",
  adminReservationFormJs: [
    "src/js/components/confirmation-modal.js",
    "src/js/components/reservation-form-state.js",
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/reservations/form.js",
  ],
  adminConfigurationJs: [
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/configuration/configuration.js",
  ],
  // Asistente IA. Un solo archivo: ConfirmationModal, AppNotice y el catálogo
  // de iconos (que trae la marca `ia`) ya llegan en admin.js, que el layout
  // carga antes que cualquier bundle de módulo.
  adminIaJs: ["src/js/admin/ia/ia.js"],
  // Editor de variantes de platillo (Menú → Variantes).
  adminVariantesJs: ["src/js/admin/menu/variantes.js"],
  // Tickets: la franja de filtros cambia la lista sin recargar. Sin él la
  // franja es un formulario GET y funciona igual.
  adminTicketsJs: ["src/js/admin/tickets/tickets.js"],
  // Estaciones de impresión: «Imprimir prueba» con sus estados y los campos
  // por tipo de conexión del formulario (antes un <script> en la vista).
  // AppNotice ya llega en admin.js.
  adminImpresorasJs: ["src/js/admin/impresoras/impresoras.js"],
  imagenes: "src/img/**/*",
  // sankey.js va PRIMERO: define window.AdminSankey, que finanzas.js consume
  // al arrancar. Es un concat en scope global, no un módulo, así que el orden
  // es el que resuelve la dependencia.
  adminFinanzasJs: [
    "src/js/admin/finanzas/sankey.js",
    "src/js/admin/finanzas/finanzas.js",
  ],
  // Chart.js, para la dona del reparto del gasto. El plugin
  // chartjs-chart-sankey se retiró: pintaba las etiquetas de nodo dentro del
  // canvas sin medirlas y las de la última columna se cortaban contra el
  // borde. La descomposición del ingreso la dibuja ahora sankey.js en SVG.
  chartJs: ["node_modules/chart.js/dist/chart.umd.min.js"],
  // GSAP + ScrollTrigger + Lenis, la capa de movimiento de la landing y del
  // panel. Antes llegaban por CDN en tres peticiones bloqueantes; vendorizarlas
  // deja el sitio funcionando sin red y fija la versión en package.json.
  // Se piden las compilaciones UMD (exponen window.gsap / ScrollTrigger /
  // Lenis): el bundle es un concat de ES5 en scope global, no un módulo.
  // ScrollTrigger se registra sobre el gsap global, así que el orden importa.
  vendorJs: [
    "node_modules/gsap/dist/gsap.min.js",
    "node_modules/gsap/dist/ScrollTrigger.min.js",
    "node_modules/lenis/dist/lenis.min.js",
  ],
  // Sólo el subconjunto 'latin': cubre entero el español (acentos y eñe) y
  // pesa la mitad que arrastrar también latin-ext.
  //
  // La landing ya no tiene caras aquí: habla sólo con las del manual de marca
  // (The Seasons y TT Wellingtons), que son comerciales y se copian a mano a
  // assets/fonts/marca/ — de ahí las publica copyFonts. Ver
  // src/scss/layout/_caras-de-marca.scss.
  //
  // Geist Mono es la cara de las CIFRAS del sistema administrativo (redonda e
  // itálica: el estilo no es un eje de la variable). La sans del panel ya no
  // es Geist sino Satoshi, y los titulares van en Expose: las dos son de
  // Fontshare, viven en assets/fonts/ y las publica copyFonts.
  vendorFonts: [
    "node_modules/@fontsource-variable/geist-mono/files/geist-mono-latin-wght-normal.woff2",
    "node_modules/@fontsource-variable/geist-mono/files/geist-mono-latin-wght-italic.woff2",
  ],
};

// En Windows, antivirus/indexadores pueden conservar brevemente los mapas
// generados. El build es finito e idempotente: elimina sólo sus dos salidas
// temporales antes de reemplazarlas, evitando que un handle viejo rompa toda
// la compilación.
function limpiarSalidaMapa() {
  [
    "public/build/js/admin/map.js",
    "public/build/js/admin/map.js.map",
  ].forEach((archivo) => fs.rmSync(archivo, { force: true }));
}

function limpiarSalidaFormularioReservacion() {
  [
    "public/build/js/admin/reservation-form.js",
    "public/build/js/admin/reservation-form.js.map",
  ].forEach((archivo) => fs.rmSync(archivo, { force: true }));
}

// Comprimido: es la hoja de la landing, la que paga el visitante en el
// teléfono, y en «expanded» arrastraba sangrías y comentarios /* */ de todos
// los parciales. El mapa de fuentes sigue saliendo para depurar.
function css() {
  return src(paths.scss)
    .pipe(sourcemaps.init())
    .pipe(sass({ outputStyle: "compressed" }))
    .pipe(sourcemaps.write("."))
    .pipe(dest("public/build/css")) // auth views
    .pipe(dest("assets/css")); // home view + index.html estatico
}

function adminCss() {
  return src(paths.adminScss)
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(rename("admin.css"))
    .pipe(dest("public/build/css"));
}

function adminModuleCss() {
  return src(paths.adminModulesScss)
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(dest("public/build/css/admin"));
}

function javascript() {
  return src(paths.js)
    .pipe(sourcemaps.init())
    .pipe(concat("bundle.js"))
    .pipe(terser())
    .pipe(rename({ suffix: ".min" }))
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js")) // auth views
    .pipe(dest("./assets/js")); // home view + index.html estatico
}

function adminJavascript() {
  return src(paths.adminJs)
    .pipe(sourcemaps.init())
    .pipe(concat("admin.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js"));
}

function adminAnalyticsJavascript() {
  return src(paths.adminAnalyticsJs)
    .pipe(sourcemaps.init())
    .pipe(concat("analytics.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminMapJavascript() {
  limpiarSalidaMapa();
  return src(paths.adminMapJs)
    .pipe(sourcemaps.init())
    .pipe(concat("map.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminAreaJavascript() {
  return src(paths.adminAreaJs)
    .pipe(sourcemaps.init())
    .pipe(concat("area.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminFinanzasJavascript() {
  return src(paths.adminFinanzasJs)
    .pipe(sourcemaps.init())
    .pipe(concat("finanzas.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminRecetasJavascript() {
  return src(paths.adminRecetasJs)
    .pipe(sourcemaps.init())
    .pipe(concat("recipe-builder.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminInventarioJavascript() {
  return src(paths.adminInventarioJs)
    .pipe(sourcemaps.init())
    .pipe(concat("inventario.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminReservationListJavascript() {
  return src(paths.adminReservationListJs)
    .pipe(sourcemaps.init())
    .pipe(concat("reservation-list.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminReservationEstrategiaJavascript() {
  return src(paths.adminReservationEstrategiaJs)
    .pipe(sourcemaps.init())
    .pipe(concat("reservation-estrategia.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminUsersJavascript() {
  return src(paths.adminUsersJs)
    .pipe(sourcemaps.init())
    .pipe(concat("users.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminUsersDirectosJavascript() {
  return src(paths.adminUsersDirectosJs)
    .pipe(sourcemaps.init())
    .pipe(concat("users-directos.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminCatasJavascript() {
  return src(paths.adminCatasJs)
    .pipe(sourcemaps.init())
    .pipe(concat("catas.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function operationAppCss() {
  return src(paths.operationAppScss)
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(rename("operation.css"))
    .pipe(dest("public/build/css"));
}

function adminReservationFormJavascript() {
  limpiarSalidaFormularioReservacion();
  return src(paths.adminReservationFormJs)
    .pipe(sourcemaps.init())
    .pipe(concat("reservation-form.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminConfigurationJavascript() {
  return src(paths.adminConfigurationJs)
    .pipe(sourcemaps.init())
    .pipe(concat("configuration.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminIaJavascript() {
  return src(paths.adminIaJs)
    .pipe(sourcemaps.init())
    .pipe(concat("ia.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminVariantesJavascript() {
  return src(paths.adminVariantesJs)
    .pipe(sourcemaps.init())
    .pipe(concat("variantes.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminTicketsJavascript() {
  return src(paths.adminTicketsJs)
    .pipe(sourcemaps.init())
    .pipe(concat("tickets.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function adminImpresorasJavascript() {
  return src(paths.adminImpresorasJs)
    .pipe(sourcemaps.init())
    .pipe(concat("impresoras.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function copyChartJs() {
  return src(paths.chartJs).pipe(dest("public/build/js/vendor"));
}

function copyVendorJs() {
  return src(paths.vendorJs).pipe(dest("public/build/js/vendor"));
}

// Las .woff2 de node_modules aterrizan en assets/fonts/ igual que las caras
// propias del proyecto: es el directorio del que parte copyFonts() y el que
// resuelven las rutas ../fonts/ de _fonts.scss en las DOS copias del CSS
// (public/build/css y assets/css). Dejarlas sólo en public/build/fonts
// rompería la copia de assets.
function copyVendorFonts() {
  return src(paths.vendorFonts)
    .pipe(dest("assets/fonts"))
    .pipe(dest("public/build/fonts"));
}

function imagenes() {
  return src(paths.imagenes)
    .pipe(cache(imagemin({ optimizationLevel: 3 })))
    .pipe(dest("public/build/img"));
}

function versionWebp() {
  const opciones = {
    quality: 50,
  };

  return src("src/img/**/*.{png,jpg}")
    .pipe(webp(opciones))
    .pipe(dest("public/build/img"));
}

function versionAvif() {
  const opciones = {
    quality: 50,
  };

  return src("src/img/**/*.{png,jpg}")
    .pipe(avif(opciones))
    .pipe(dest("public/build/img"));
}

// Copia fuentes a public/build/fonts/ (necesario para rutas CSS desde public/)
function copyFonts() {
  return src("assets/fonts/**/*").pipe(dest("public/build/fonts"));
}

// Copia imagenes a public/build/images/ (necesario cuando public/ es el webroot)
function copyImages() {
  return src("assets/images/**/*").pipe(dest("public/build/images"));
}

// El menú preservado se publica sin recomprimir sus archivos verificados.
function copyMenuImages() {
  return src("src/images/menu-preservado/*.webp").pipe(dest("public/build/images"));
}

// Variantes de 720 px de las fotos grandes, para el `srcset` de la landing
// (srcsetImagen() en includes/funciones.php, que sólo lo emite si la variante
// existe). Las fotos vienen a ~1170 px: bien para un escritorio retina, pero un
// teléfono descargaba el doble de lo que pinta. 720 cubre una foto a media
// columna en un teléfono de densidad 3 y a ancho completo en uno de densidad 2.
//
// Sólo regenera lo que cambió (fecha del original contra la de la variante) y
// no toca las fotos que ya miden poco más que eso. Van directo a
// public/build/images porque son producto del build, no fuentes.
const ANCHO_MOVIL = 720;
function imagenesResponsivas() {
  const sharp = require("sharp");
  const path = require("path");
  const origen = "assets/images";
  const fotos = fs.readdirSync(origen).filter((f) => /\.webp$/i.test(f) && !/-\d+\.webp$/i.test(f));

  return Promise.all(fotos.map(async (foto) => {
    const desde = path.join(origen, foto);
    const hacia = path.join("public/build/images", foto.replace(/\.webp$/i, "-" + ANCHO_MOVIL + ".webp"));
    const meta = await sharp(desde).metadata();
    if (!meta.width || meta.width <= ANCHO_MOVIL * 1.15) return;
    if (fs.existsSync(hacia) && fs.statSync(hacia).mtimeMs >= fs.statSync(desde).mtimeMs) return;
    await sharp(desde).resize({ width: ANCHO_MOVIL }).webp({ quality: 74 }).toFile(hacia);
  }));
}

function devWatch(done) {
  watch(paths.scss, css);
  // admin/shared es la base de los DOS bundles administrativos.
  watch("src/scss/admin/shared/**/*.scss", parallel(adminCss, operationAppCss));
  watch("src/scss/admin/modules/**/*.scss", adminModuleCss);
  // shared/ es lo que comparten el árbol público y el del piso: el wordmark de
  // marca entra en app.css y en operation.css a la vez.
  watch("src/scss/shared/**/*.scss", parallel(css, operationAppCss));
  // El árbol de operación sólo alimenta el bundle del piso (operation.css):
  // la pantalla de Operación de reservaciones, que tenía hoja propia, se retiró.
  watch("src/scss/operation/**/*.scss", operationAppCss);
  watch(
    [
      "src/scss/punto-de-venta/**/*.scss",
      "src/scss/area/**/*.scss",
      "src/scss/auth/**/*.scss",
    ],
    operationAppCss,
  );
  watch(paths.js, javascript);
  watch(
    [
      "src/js/admin/admin.js",
      "src/js/admin/core/**/*.js",
      "src/js/components/confirmation-modal.js",
      "src/js/components/toast.js",
    ],
    adminJavascript,
  );
  watch("src/js/admin/analytics/**/*.js", adminAnalyticsJavascript);
  watch(
    [
      "src/js/modules/punto-de-venta.js",
      "src/js/operation/*.js",
      "src/js/admin/core/select.js",
    ],
    adminMapJavascript,
  );
  watch("src/js/admin/area/**/*.js", adminAreaJavascript);
  watch("src/js/admin/finanzas/**/*.js", adminFinanzasJavascript);
  watch("src/js/admin/recetas/**/*.js", adminRecetasJavascript);
  watch("src/js/admin/inventario/**/*.js", adminInventarioJavascript);
  watch("src/js/admin/reservations/lista.js", adminReservationListJavascript);
  watch("src/js/admin/reservations/estrategia.js", adminReservationEstrategiaJavascript);
  watch("src/js/admin/users/**/*.js", adminUsersJavascript);
  watch(paths.adminUsersDirectosJs, adminUsersDirectosJavascript);
  watch("src/js/admin/catas/**/*.js", adminCatasJavascript);
  watch("src/js/admin/reservations/form.js", adminReservationFormJavascript);
  watch(
    "src/js/components/reservation-*-picker.js",
    parallel(adminMapJavascript, adminReservationFormJavascript),
  );
  watch(
    [
      "src/js/admin/configuration/**/*.js",
      "src/js/components/reservation-*-picker.js",
    ],
    adminConfigurationJavascript,
  );
  watch("src/js/admin/ia/**/*.js", adminIaJavascript);
  watch("src/js/admin/menu/**/*.js", adminVariantesJavascript);
  watch("src/js/admin/tickets/**/*.js", adminTicketsJavascript);
  watch("src/js/admin/impresoras/**/*.js", adminImpresorasJavascript);
  watch(paths.chartJs, copyChartJs);
  watch(paths.vendorJs, copyVendorJs);
  watch(paths.vendorFonts, copyVendorFonts);
  watch(paths.imagenes, imagenes);
  watch(paths.imagenes, versionWebp);
  watch(paths.imagenes, versionAvif);
  watch("assets/fonts/**/*", copyFonts);
  watch("assets/images/**/*", copyImages);
  done();
}

exports.css = css;
exports.adminCss = adminCss;
exports.adminModuleCss = adminModuleCss;
exports.operationAppCss = operationAppCss;
exports.js = javascript;
exports.adminJs = adminJavascript;
exports.adminAnalyticsJs = adminAnalyticsJavascript;
exports.adminMapJs = adminMapJavascript;
exports.adminAreaJs = adminAreaJavascript;
exports.adminFinanzasJs = adminFinanzasJavascript;
exports.adminRecetasJs = adminRecetasJavascript;
exports.adminInventarioJs = adminInventarioJavascript;
exports.adminReservationListJs = adminReservationListJavascript;
exports.adminReservationEstrategiaJs = adminReservationEstrategiaJavascript;
exports.adminUsersJs = adminUsersJavascript;
exports.adminUsersDirectosJs = adminUsersDirectosJavascript;
exports.adminCatasJs = adminCatasJavascript;
exports.adminReservationFormJs = adminReservationFormJavascript;
exports.adminConfigurationJs = adminConfigurationJavascript;
exports.adminIaJs = adminIaJavascript;
exports.adminVariantesJs = adminVariantesJavascript;
exports.adminTicketsJs = adminTicketsJavascript;
exports.adminImpresorasJs = adminImpresorasJavascript;
exports.copyChartJs = copyChartJs;
exports.copyVendorJs = copyVendorJs;
exports.copyVendorFonts = copyVendorFonts;
exports.imagenes = imagenes;
exports.versionWebp = versionWebp;
exports.versionAvif = versionAvif;
exports.copyFonts = copyFonts;
exports.copyImages = copyImages;
exports.imagenesResponsivas = imagenesResponsivas;
exports.dev = parallel(
  css,
  adminCss,
  adminModuleCss,
  operationAppCss,
  imagenes,
  versionWebp,
  versionAvif,
  javascript,
  adminJavascript,
  // Estaba sólo en `build`: un `gulp dev` limpio nunca generaba map.js, así que
  // el POS arrancaba con el bundle de la sesión anterior —o sin ninguno— hasta
  // que alguien tocaba punto-de-venta.js y saltaba el watch.
  adminMapJavascript,
  adminAnalyticsJavascript,
  adminAreaJavascript,
  adminFinanzasJavascript,
  adminRecetasJavascript,
  adminInventarioJavascript,
  adminReservationListJavascript,
  adminReservationEstrategiaJavascript,
  adminUsersJavascript,
  adminUsersDirectosJavascript,
  adminCatasJavascript,
  adminReservationFormJavascript,
  adminConfigurationJavascript,
  adminIaJavascript,
  adminVariantesJavascript,
  adminTicketsJavascript,
  adminImpresorasJavascript,
  copyChartJs,
  copyVendorJs,
  copyVendorFonts,
  copyFonts,
  copyImages,
  copyMenuImages,
  devWatch,
);

// Build finito. Las tareas de reservaciones se ejecutan en serie para evitar
// que dos streams de minificacion compartan contenido al escribir bundles.
exports.build = series(
  css,
  adminCss,
  adminModuleCss,
  operationAppCss,
  imagenes,
  versionWebp,
  versionAvif,
  javascript,
  adminJavascript,
  adminMapJavascript,
  adminAreaJavascript,
  adminAnalyticsJavascript,
  adminFinanzasJavascript,
  adminRecetasJavascript,
  adminInventarioJavascript,
  adminReservationListJavascript,
  adminReservationEstrategiaJavascript,
  adminUsersJavascript,
  adminUsersDirectosJavascript,
  adminCatasJavascript,
  adminReservationFormJavascript,
  adminConfigurationJavascript,
  adminIaJavascript,
  adminVariantesJavascript,
  adminTicketsJavascript,
  adminImpresorasJavascript,
  // Sin esto un build limpio no publica chart.umd, que finanzas y analíticas
  // cargan desde /build/js/vendor.
  copyChartJs,
  // Idem: sin esto la landing se queda sin GSAP/ScrollTrigger/Lenis y el panel
  // sin Geist. copyVendorFonts va antes que copyFonts porque siembra
  // assets/fonts/, de donde parte la otra.
  copyVendorJs,
  copyVendorFonts,
  copyFonts,
  copyImages,
  copyMenuImages,
  imagenesResponsivas,
);

exports.menuImages = copyMenuImages;
