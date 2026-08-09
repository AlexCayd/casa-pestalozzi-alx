# Corrección POS: preservar walk-in durante refresh del modal

Fecha: 2026-08-09
Hallazgo corregido: `POS60-AUD-001`
Alcance: frontend POS y bundle servido; no se modificaron PHP, DDL, capacidad, asignabilidad, tolerancia ni la proyección del mapa administrativo.

## Baseline y causa raíz

En 60–30 minutos antes de una reservación confirmada, el backend mantiene:

```text
disponible_para_ticket = true
requiere_advertencia_ticket = true
bloqueo_walk_in = false
```

El click inicial ya llamaba a `showReservationModal(reserva, { allowWalkIn: true, mesa })`. El defecto era que `activeReservationModal` guardaba sólo `id`, `mesaId` y `reserva`. En el siguiente `fetchData()`, `actualizarModalReservacionActiva()` reconstruía con `buildModalContent(..., null)` y `bindModalActions(..., null)`. El botón walk-in y su handler desaparecían antes de `requestOpenTicket()`.

## Corrección aplicada

Se añadió `resolverOpcionesModalReservacion()` en `src/js/modules/punto-de-venta.js`. El resolver:

- conserva `allowWalkIn` como contexto del modal;
- revalida en cada refresh `estado=confirmada`;
- consume los hechos backend `disponible_para_ticket`, `requiere_advertencia_ticket`, `ticket_abierto` y `ausencia_pendiente`;
- no calcula minutos ni ventanas en JavaScript;
- fuerza `allowWalkIn=false` si algún hecho deja de autorizar la acción.

`activeReservationModal.options` se entrega nuevamente a `buildModalContent()` y `bindModalActions()` después de cada refresh. El `innerHTML` reemplaza los controles anteriores y se enlaza una sola vez el control actual; no se acumulan listeners.

## Estado antes y después del polling

### Antes

```text
modal inicial: Abrir ticket de todas formas
refresh:       Iniciar servicio [disabled]
```

### Después, con hechos backend aún en warning

```text
modal inicial: Abrir ticket de todas formas
refresh:       Abrir ticket de todas formas
handler:       presente en el control actual
```

Si el backend cruza a bloqueo, reporta ticket abierto o marca ausencia pendiente, el resolver devuelve `allowWalkIn=false` y el modal deja de ofrecer walk-in. El permiso no queda congelado.

## Prueba manual con polling real

Entorno: `http://127.0.0.1:8080/punto-de-venta`, sesión local de personal POS.

1. Mesa 7 tenía una reservación a las 03:30 y el reloj operativo mostraba 02:52; el pin estaba en `estado_visual=libre`, `modificador=reservacion_advertencia`, habilitado (`data-disabled=0`).
2. El click abrió `Reservación cercana` con el botón visible y habilitado `Abrir ticket de todas formas`.
3. Se esperaron 32 segundos, superando un ciclo de polling de 30 segundos.
4. Después del refresh el modal seguía mostrando `Reservación cercana`, `El walk-in todavía está permitido...` y `Abrir ticket de todas formas`.

Esto reproduce el punto que fallaba en `POS60-AUD-001` y confirma que el estado ya no se pierde durante el polling real.

## Request #1 y Request #2

Se pulsó el botón después del polling. El cliente no recibió un JSON interpretable y entró en el shell `No fue posible abrir el ticket`; no llegó a mostrar el aviso canónico `REQUIERE_CONFIRMACION`. No se pudo registrar el HTTP exacto ni el cuerpo crudo con las capacidades restringidas del navegador. La Mesa 7 permaneció en warning y no se creó ticket.

Por código, el contrato sigue siendo:

```text
POST #1 /api/abrir-ticket
confirmar_reservacion_proxima = 0
=> REQUIERE_CONFIRMACION, rollback, sin ticket

POST #2 /api/abrir-ticket
confirmar_reservacion_proxima = 1
=> revalidación backend, ticket y commit si continúa permitido
```

La respuesta no interpretable del entorno local es un bloqueo de la verificación HTTP posterior, no una modificación de la política ni de la corrección del modal. No se aplicó ningún workaround para ocultar polling o congelar autorización.

## Pruebas automatizadas

Nueva prueba: `scripts/tests/run-pos-modal-refresh-flow.cjs`.

Cubre:

- refresh conserva la acción en warning;
- tres refresh consecutivos conservan la acción;
- cruce a bloqueo elimina walk-in;
- ticket abierto elimina walk-in;
- ausencia pendiente elimina walk-in;
- opciones sin autorización inicial no se convierten en permiso;
- el render actual conserva un único handler;
- no se duplican ventanas temporales en JavaScript.

También se ejecutó:

```text
npm.cmd run test:php  -> OK
npm.cmd run test:js   -> OK
npm.cmd run build     -> OK
node --check src/js/modules/punto-de-venta.js -> OK
git diff --check      -> OK
```

El build regeneró `public/build/js/admin/map.js` y `public/build/js/admin/map.js.map`. Se verificó que el bundle servido en 8080 contiene el resolver minificado y la reconstrucción con opciones efectivas. Se retiraron del cambio las salidas globales de Gulp ajenas al POS.

## Archivos incluidos

- `src/js/modules/punto-de-venta.js`
- `scripts/tests/run-pos-modal-refresh-flow.cjs`
- `package.json`
- `public/build/js/admin/map.js`
- `public/build/js/admin/map.js.map`
- este reporte

## Commit

```text
fix(pos): preservar walkin durante refresh de reservacion cercana
```

## Working tree

El reporte previo `auditoria_pos_walkin_advertencia_runtime.md` ya estaba staged desde la tarea anterior y se conserva separado. El nuevo commit de corrección no debe incluir cambios PHP ni bundles no relacionados.
