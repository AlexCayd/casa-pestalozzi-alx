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
    "src/js/admin/reservations/operation.js",
  ],
  adminConfigurationJs: [
    "src/js/components/reservation-date-picker.js",
    "src/js/components/reservation-time-picker.js",
    "src/js/admin/configuration/configuration.js",
  ],
  imagenes: "src/img/**/*",
  adminFinanzasJs: "src/js/admin/finanzas/finanzas.js",
  // Chart.js y el plugin de Sankey viajan juntos a /vendor: el plugin se
  // registra sobre el Chart global, así que el orden de carga en la vista
  // (primero chart.umd, luego el sankey) no es opcional.
  chartJs: [
    "node_modules/chart.js/dist/chart.umd.min.js",
    "node_modules/chartjs-chart-sankey/dist/chartjs-chart-sankey.min.js",
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
  watch("src/scss/admin/shared/**/*.scss", adminCss);
  watch("src/scss/admin/modules/**/*.scss", adminModuleCss);
  watch("src/scss/operation/**/*.scss", operationCss);
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
exports.adminReservationFormJs = adminReservationFormJavascript;
exports.adminReservationOperationJs = adminReservationOperationJavascript;
exports.adminConfigurationJs = adminConfigurationJavascript;
exports.copyChartJs = copyChartJs;
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
  imagenes,
  versionWebp,
  versionAvif,
  javascript,
  adminJavascript,
  adminAnalyticsJavascript,
  adminAreaJavascript,
  adminFinanzasJavascript,
  adminRecetasJavascript,
  adminInventarioJavascript,
  adminReservationListJavascript,
  adminUsersJavascript,
  adminReservationFormJavascript,
  adminReservationOperationJavascript,
  adminConfigurationJavascript,
  copyChartJs,
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
  adminReservationFormJavascript,
  adminReservationOperationJavascript,
  adminConfigurationJavascript,
  // Sin esto un build limpio no publica chart.umd ni el plugin de Sankey, que
  // finanzas y analíticas cargan desde /build/js/vendor.
  copyChartJs,
  copyFonts,
  copyImages,
);
