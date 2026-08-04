const assert = require("assert");
const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "../..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const includes = (file, fragment) => assert.ok(
  read(file).includes(fragment),
  `${file} debe incluir: ${fragment}`
);

includes("views/home/index.php", 'class="skip-link" href="#main-content"');
includes("views/home/index.php", '<main id="main-content" tabindex="-1">');
includes("views/home/_nav.php", 'aria-controls="navOverlay" aria-expanded="false"');
includes("views/home/_nav.php", 'aria-hidden="true" inert');
includes("views/home/_nav.php", '<nav class="rail" id="rail" aria-label="Secciones principales">');
assert.strictEqual(read("views/home/_nav.php").includes('<nav class="rail" id="rail" aria-hidden="true" inert>'), false, "el rail no debe iniciar inert");
includes("views/home/_footer.php", 'role="dialog" aria-modal="true" aria-hidden="true" inert');
includes("views/home/_footer.php", 'aria-labelledby="lightbox-title"');
includes("views/home/_reserva.php", '$displayAriaDescribedby = \'dateError\';');
includes("views/home/_reserva.php", '$displayAriaDescribedby = \'hourStatus\';');

includes("views/admin/layout.php", 'class="skip-link" href="#admin-main"');
includes("views/admin/reservations/index.php", '<caption class="admin-visually-hidden">');
includes("views/admin/reservations/index.php", '<th scope="col">');
includes("views/admin/reservations/index.php", 'aria-label="Ver detalle de <?php echo $h($nombre); ?>');
includes("views/admin/reservations/show.php", 'role="alert" aria-live="assertive"');

includes("views/operation/partials/map.php", 'data-map-structured-list');
includes("views/operation/partials/map-legend.php", '<ul class="mapa-leyenda__row"');
includes("views/punto-de-venta/partials/pos-workspace.php", 'role="dialog" aria-modal="true" aria-hidden="true" inert');

includes("src/js/modules/nav.js", "nav.inert = !open;");
includes("src/js/modules/rail.js", "data-rail");
includes("src/js/components/reservation-date-picker.js", "ReservationPopoverCoordinator");
includes("src/js/components/reservation-time-picker.js", "popoverCoordinator");
includes("views/home/_reserva.php", "Confirmar modificación");
includes("views/home/_reserva.php", ">Aceptar</span>");
includes("views/home/_reserva.php", ">Cancelar</button>");
assert.strictEqual(read("views/home/_reserva.php").includes("Guardar cambios"), false, "el editor no debe conservar Guardar cambios");
includes("src/js/modules/reservation-access.js", "request_token: editorOperation.request_token");
includes("src/js/modules/reservation-access.js", "function fechaLegible(value)");
[
  "editing",
  "creating_replacement",
  "reviewing",
  "confirming",
  "success",
  "error"
].forEach((state) => includes("src/js/modules/reservation-access.js", 'setEditorState("' + state + '")'));
includes("src/js/modules/reservation-access.js", "data.propuesta || data.replacement");
assert.strictEqual(
  (read("src/js/modules/reservation-access.js").match(/editor\.addEventListener\("submit"/g) || []).length,
  1,
  "el editor debe tener un solo listener de submit"
);
includes("src/js/modules/punto-de-venta.js", "Hay una reservación próxima");
includes("src/js/admin/reservations/operation.js", "state.assignmentMode && Boolean(reservacion)");
includes("src/js/admin/admin.js", "function initAdminSkipLink()");
includes("src/js/operation/shell.js", "function focusOperationalMain(event)");
includes("src/js/modules/lightbox.js", "if (e.key === \"Enter\" || e.key === \" \")");
includes("src/js/operation/map-visual.js", "renderStructuredList();");
includes("src/js/modules/punto-de-venta.js", "function openModalShell()");
includes("src/js/modules/punto-de-venta.js", "modal.inert = true;");
includes("src/js/admin/reservations/form.js", "if (event.key !== 'Tab') return;");
includes("src/js/admin/reservations/operation.js", "ticketConflictLastFocus");

const relevantViews = [
  "views/home/index.php",
  "views/home/_nav.php",
  "views/home/_footer.php",
  "views/admin/layout.php",
  "views/operation/partials/shell.php",
  "views/punto-de-venta/partials/pos-workspace.php"
];
relevantViews.forEach((file) => {
  assert.strictEqual(
    /tabindex\s*=\s*["'][1-9]/i.test(read(file)),
    false,
    `${file} no debe usar tabindex positivo`
  );
});

console.log("accessibility-contract: PASS (skip/main/headings/dialogs/forms/map/keyboard/no-positive-tabindex)");
