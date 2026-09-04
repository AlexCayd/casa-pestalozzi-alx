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
  operationScss: "src/scss/operation/reservations.scss",
  // POS, tableros de área y login. Salen del bundle público —cargaban la
  // landing entera para llegar a su propio SCSS— y estrenan salida con el
  // sistema administrativo dentro.
  operationAppScss: "src/scss/operation/app-operation.scss",
  js: [
    "src/js/**/*.js",
    "!src/js/admin/**/*.js",
    "!src/js/operation/**/*.js",
    "!src/js/modules/punto-de-venta.js",
  ],
  adminJs: [
    // El panel admin sólo carga /build/js/admin.js: nunca ve bundle.min.js, así
    // que sus confirmaciones y avisos deben viajar aquí.
    "src/js/components/confirmation-modal.js",
    "src/js/components/toast.js",
    "src/js/admin/admin.js",
    "src/js/admin/core/theme.js",
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
    "src/js/components/confirmation-modal.js",
    "src/js/admin/core/select.js",
    "src/js/components/reservation-date-picker.js",
    "src/js/operation/shell.js",
    "src/js/operation/table-state-adapter.js",
    "src/js/operation/map-visual.js",
    "src/js/operation/reservation-card.js",
    "src/js/modules/punto-de-venta.js",
  ],
  adminAreaJs: "src/js/admin/area/area.js",
  adminRecetasJs: "src/js/admin/recetas/recipe-builder.js",
  adminInventarioJs: "src/js/admin/inventario/inventario.js",
  adminReservationListJs: "src/js/admin/reservations/lista.js",
  adminUsersJs: "src/js/admin/users/users-form.js",
  adminCatasJs: "src/js/admin/catas/catas.js",
  adminReservationFormJs: [
    "src/js/components/confirmation-modal.js",
    "src/js/components/reservation-form-state.js",
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/reservations/form.js",
  ],
  adminReservationOperationJs: [
    "src/js/components/confirmation-modal.js",
    "src/js/components/reservation-form-state.js",
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/reservations/form.js",
    "src/js/operation/shell.js",
    "src/js/operation/table-state-adapter.js",
    "src/js/operation/map-visual.js",
    "src/js/operation/reservation-card.js",
    "src/js/operation/reservation-operation-policy.js",
    "src/js/admin/reservations/operation.js",
  ],
  adminConfigurationJs: [
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/configuration/configuration.js",
  ],
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
  // De Bodoni se toma la variante 'standard', la que trae los dos ejes (wght y
  // opsz). El eje óptico es el que permite que UNA familia sirva de display y
  // de cursiva de acento: un didone a cuerpo de texto necesita serifas más
  // robustas que a cuerpo de titular.
  //
  // La itálica de Crimson no es un extra: .eyebrow__it, .lead y .display em la
  // piden desde siempre y, sin fichero, el navegador venía inclinando la
  // redonda por su cuenta.
  //
  // Geist y Geist Mono son la voz del sistema ADMINISTRATIVO, que no comparte
  // tipografía con la landing: el panel se diseña para poder despegarse como
  // producto propio. Llegaban por Google Fonts (Fraunces + Space Grotesk) en
  // dos peticiones bloqueantes; vendorizarlas deja el panel funcionando sin red
  // y fija la versión aquí, igual que las caras del sitio público.
  //
  // Van las CUATRO caras y no sólo las redondas: los dos ficheros son
  // variables (eje wght 100-900), pero el estilo no es un eje — hay una regla
  // que pide `font-style: italic` (modules/analytics.scss) y sin fichero el
  // navegador inclinaría la redonda por su cuenta. La casa no admite caras
  // sintéticas (ver CLAUDE.md).
  vendorFonts: [
    "node_modules/@fontsource-variable/bodoni-moda/files/bodoni-moda-latin-standard-normal.woff2",
    "node_modules/@fontsource-variable/bodoni-moda/files/bodoni-moda-latin-standard-italic.woff2",
    "node_modules/@fontsource/crimson-text/files/crimson-text-latin-400-normal.woff2",
    "node_modules/@fontsource/crimson-text/files/crimson-text-latin-400-italic.woff2",
    "node_modules/@fontsource-variable/geist/files/geist-latin-wght-normal.woff2",
    "node_modules/@fontsource-variable/geist/files/geist-latin-wght-italic.woff2",
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

function css() {
  return src(paths.scss)
    .pipe(sourcemaps.init())
    .pipe(sass({ outputStyle: "expanded" }))
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

function adminUsersJavascript() {
  return src(paths.adminUsersJs)
    .pipe(sourcemaps.init())
    .pipe(concat("users.js"))
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

function adminReservationOperationJavascript() {
  return src(paths.adminReservationOperationJs)
    .pipe(sourcemaps.init())
    .pipe(concat("reservation-operation.js"))
    .pipe(terser())
    .pipe(sourcemaps.write("."))
    .pipe(dest("./public/build/js/admin"));
}

function operationCss() {
  return src(paths.operationScss)
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(dest("public/build/css/operation"));
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

function devWatch(done) {
  watch(paths.scss, css);
  // admin/shared es la base de los DOS bundles administrativos.
  watch("src/scss/admin/shared/**/*.scss", parallel(adminCss, operationAppCss));
  watch("src/scss/admin/modules/**/*.scss", adminModuleCss);
  // El árbol de operación alimenta DOS salidas: reservations.css y el bundle
  // nuevo del piso, que además cuelga de admin/shared.
  watch("src/scss/operation/**/*.scss", parallel(operationCss, operationAppCss));
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
  watch("src/js/admin/users/**/*.js", adminUsersJavascript);
  watch("src/js/admin/catas/**/*.js", adminCatasJavascript);
  watch("src/js/admin/reservations/form.js", adminReservationFormJavascript);
  watch(
    ["src/js/admin/reservations/operation.js", "src/js/operation/*.js"],
    adminReservationOperationJavascript,
  );
  watch(
    "src/js/components/reservation-*-picker.js",
    parallel(
      adminMapJavascript,
      adminReservationFormJavascript,
      adminReservationOperationJavascript,
    ),
  );
  watch(
    [
      "src/js/admin/configuration/**/*.js",
      "src/js/components/reservation-*-picker.js",
    ],
    adminConfigurationJavascript,
  );
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
exports.operationCss = operationCss;
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
exports.adminUsersJs = adminUsersJavascript;
exports.adminCatasJs = adminCatasJavascript;
exports.adminReservationFormJs = adminReservationFormJavascript;
exports.adminReservationOperationJs = adminReservationOperationJavascript;
exports.adminConfigurationJs = adminConfigurationJavascript;
exports.copyChartJs = copyChartJs;
exports.copyVendorJs = copyVendorJs;
exports.copyVendorFonts = copyVendorFonts;
exports.imagenes = imagenes;
exports.versionWebp = versionWebp;
exports.versionAvif = versionAvif;
exports.copyFonts = copyFonts;
exports.copyImages = copyImages;
exports.dev = parallel(
  css,
  adminCss,
  adminModuleCss,
  operationCss,
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
  adminUsersJavascript,
  adminCatasJavascript,
  adminReservationFormJavascript,
  adminReservationOperationJavascript,
  adminConfigurationJavascript,
  copyChartJs,
  copyVendorJs,
  copyVendorFonts,
  copyFonts,
  copyImages,
  devWatch,
);

// Build finito. Las tareas de reservaciones se ejecutan en serie para evitar
// que dos streams de minificacion compartan contenido al escribir bundles.
exports.build = series(
  css,
  adminCss,
  adminModuleCss,
  operationCss,
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
  adminUsersJavascript,
  adminCatasJavascript,
  adminReservationFormJavascript,
  adminReservationOperationJavascript,
  adminConfigurationJavascript,
  // Sin esto un build limpio no publica chart.umd, que finanzas y analíticas
  // cargan desde /build/js/vendor.
  copyChartJs,
  // Idem: sin esto la landing se queda sin GSAP/ScrollTrigger/Lenis y sin las
  // caras de Bodoni y la itálica de Crimson. copyVendorFonts va antes que
  // copyFonts porque siembra assets/fonts/, de donde parte la otra.
  copyVendorJs,
  copyVendorFonts,
  copyFonts,
  copyImages,
);
