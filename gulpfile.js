const { src, dest, watch, parallel } = require('gulp');

// CSS
const sass = require('gulp-sass')(require('sass'));
const sourcemaps = require('gulp-sourcemaps');

// Imagenes
const cache = require('gulp-cache');
const imagemin = require('gulp-imagemin');
const webp = require('gulp-webp');
const avif = require('gulp-avif');

// Javascript
const terser = require('gulp-terser-js');
const concat = require('gulp-concat');
const rename = require('gulp-rename');

const paths = {
    scss: 'src/scss/app.scss',
    adminScss: 'src/scss/admin/shared/app-admin.scss',
    adminModulesScss: 'src/scss/admin/modules/*.scss',
    js: ['src/js/**/*.js', '!src/js/admin/**/*.js'],
    adminJs: 'src/js/admin/admin.js',
    adminAnalyticsJs: [
        'src/js/admin/analytics/mock-data.js',
        'src/js/admin/analytics/charts.js',
        'src/js/admin/analytics/analytics-page.js',
        'src/js/admin/analytics/analytics.js'
    ],
    imagenes: 'src/img/**/*',
    chartJs: 'node_modules/chart.js/dist/chart.umd.min.js'
};

function css() {
    return src(paths.scss)
        .pipe(sourcemaps.init())
        .pipe(sass({ outputStyle: 'expanded' }))
        .pipe(sourcemaps.write('.'))
        .pipe(dest('public/build/css')) // auth views
        .pipe(dest('assets/css')); // home view + index.html estatico
}

function adminCss() {
    return src(paths.adminScss)
        .pipe(sass({ outputStyle: 'expanded' }))
        .pipe(rename('admin.css'))
        .pipe(dest('public/build/css'));
}

function adminModuleCss() {
    return src(paths.adminModulesScss)
        .pipe(sass({ outputStyle: 'expanded' }))
        .pipe(dest('public/build/css/admin'));
}

function javascript() {
    return src(paths.js)
        .pipe(sourcemaps.init())
        .pipe(concat('bundle.js'))
        .pipe(terser())
        .pipe(sourcemaps.write('.'))
        .pipe(rename({ suffix: '.min' }))
        .pipe(dest('./public/build/js')) // auth views
        .pipe(dest('./assets/js')); // home view + index.html estatico
}

function adminJavascript() {
    return src(paths.adminJs)
        .pipe(sourcemaps.init())
        .pipe(concat('admin.js'))
        .pipe(terser())
        .pipe(sourcemaps.write('.'))
        .pipe(dest('./public/build/js'));
}

function adminAnalyticsJavascript() {
    return src(paths.adminAnalyticsJs)
        .pipe(sourcemaps.init())
        .pipe(concat('analytics.js'))
        .pipe(terser())
        .pipe(sourcemaps.write('.'))
        .pipe(dest('./public/build/js/admin'));
}

function copyChartJs() {
    return src(paths.chartJs)
        .pipe(dest('public/build/js/vendor'));
}

function imagenes() {
    return src(paths.imagenes)
        .pipe(cache(imagemin({ optimizationLevel: 3 })))
        .pipe(dest('public/build/img'));
}

function versionWebp(done) {
    const opciones = {
        quality: 50
    };

    src('src/img/**/*.{png,jpg}')
        .pipe(webp(opciones))
        .pipe(dest('public/build/img'));

    done();
}

function versionAvif(done) {
    const opciones = {
        quality: 50
    };

    src('src/img/**/*.{png,jpg}')
        .pipe(avif(opciones))
        .pipe(dest('public/build/img'));

    done();
}

// Copia fuentes a public/build/fonts/ (necesario para rutas CSS desde public/)
function copyFonts() {
    return src('assets/fonts/**/*')
        .pipe(dest('public/build/fonts'));
}

// Copia imagenes a public/build/images/ (necesario cuando public/ es el webroot)
function copyImages() {
    return src('assets/images/**/*')
        .pipe(dest('public/build/images'));
}

function devWatch(done) {
    watch(paths.scss, css);
    watch('src/scss/admin/shared/**/*.scss', adminCss);
    watch('src/scss/admin/modules/**/*.scss', adminModuleCss);
    watch(paths.js, javascript);
    watch(paths.adminJs, adminJavascript);
    watch('src/js/admin/analytics/**/*.js', adminAnalyticsJavascript);
    watch(paths.chartJs, copyChartJs);
    watch(paths.imagenes, imagenes);
    watch(paths.imagenes, versionWebp);
    watch(paths.imagenes, versionAvif);
    watch('assets/fonts/**/*', copyFonts);
    watch('assets/images/**/*', copyImages);
    done();
}

exports.css = css;
exports.adminCss = adminCss;
exports.adminModuleCss = adminModuleCss;
exports.js = javascript;
exports.adminJs = adminJavascript;
exports.adminAnalyticsJs = adminAnalyticsJavascript;
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
    imagenes,
    versionWebp,
    versionAvif,
    javascript,
    adminJavascript,
    adminAnalyticsJavascript,
    copyChartJs,
    copyFonts,
    copyImages,
    devWatch
);
