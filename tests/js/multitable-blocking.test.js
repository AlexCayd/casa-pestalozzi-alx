const assert = require("assert");
const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "../..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const source = read("src/js/modules/punto-de-venta.js");
const serializer = read("services/PosReservacionSerializer.php");
const truth = read("reservaciones_fuente_de_verdad.md");

[
  "mesas_bloqueantes",
  "motivo_bloqueo",
  "mensaje_bloqueo",
  "accion_sugerida",
  "No se puede iniciar el servicio",
  "Esta reservación utiliza varias mesas y todas deben estar disponibles para iniciar.",
  "aria-describedby=\"mmodal-reservation-blocking\"",
  "role=\"alert\"",
  "activeReservationModal",
  "actualizarModalReservacionActiva",
  "dataRequestSequence"
].forEach((fragment) => {
  assert.ok(source.includes(fragment), `el modal/polling debe incluir: ${fragment}`);
});

[
  "public static function bloqueosOperativos(",
  "'puede_iniciar' => $puedeIniciar",
  "'motivo_bloqueo' => $motivoBloqueo",
  "'mesas_bloqueantes' => $mesasBloqueantes",
  "TICKET_ABIERTO",
  "MESA_NO_UTILIZABLE"
].forEach((fragment) => {
  assert.ok(serializer.includes(fragment), `el backend debe incluir: ${fragment}`);
});

assert.ok(
  truth.includes("su inicio es atómico") &&
    truth.includes("No se permite iniciar la reservación parcialmente"),
  "la fuente de verdad debe declarar el inicio atómico multimesa"
);

console.log("multitable-blocking: PASS (contract/modal/polling/accessibility/source-of-truth)");
