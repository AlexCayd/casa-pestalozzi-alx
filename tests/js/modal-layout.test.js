const assert = require("assert");
const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "../..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const source = read("src/js/modules/punto-de-venta.js");
const posStyles = read("src/scss/punto-de-venta/_punto-de-venta.scss");
const adminStyles = read("src/scss/admin/modules/map.scss");

assert.strictEqual(
  (source.match(/function showOpenTicketNotice\(/g) || []).length,
  1,
  "las dos variantes deben usar una sola función compartida"
);
[
  "mmodal-cancel-confirm--operation",
  "mmodal-cancel-confirm__header",
  "mmodal-cancel-confirm__body",
  "mmodal-cancel-confirm__actions",
  "aria-describedby=\"mesa-modal-description\"",
  "noticeBody.scrollTop = 0",
  "Hay una reservación próxima",
  "Registrar que el cliente no se presentó"
].forEach((fragment) => {
  assert.ok(source.includes(fragment), `el shell JS debe incluir: ${fragment}`);
});

[posStyles, adminStyles].forEach((styles, index) => {
  const label = index === 0 ? "POS" : "mapa administrativo";
  assert.ok(styles.includes("grid-template-rows: auto minmax(0, 1fr) auto"), `${label}: grid del shell`);
  assert.ok(styles.includes(".mmodal-cancel-confirm__body"), `${label}: cuerpo del shell`);
  assert.ok(styles.includes("overflow-y: auto"), `${label}: scroll sólo en cuerpo`);
  assert.ok(styles.includes("width: min(46rem, calc(100vw - 3rem))"), `${label}: ancho desktop`);
  assert.ok(styles.includes("max-height: min(85dvh, 48rem)"), `${label}: altura desktop`);
  assert.ok(styles.includes("max-height: calc(100dvh - 1.5rem)"), `${label}: altura móvil`);
  assert.ok(styles.includes("flex-direction: column-reverse"), `${label}: acciones móviles apilables`);
});

console.log("modal-layout: PASS (shell/variants/body-scroll/actions/desktop/mobile/accessibility)");
