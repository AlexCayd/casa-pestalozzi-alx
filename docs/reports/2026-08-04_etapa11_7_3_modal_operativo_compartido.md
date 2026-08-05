# Etapa 11.7.3 — Corrección visual del modal operativo compartido

Fecha: 2026-08-04  
Estado: corrección implementada; auditoría de conformidad y Etapa 12 no iniciadas.

## 1. Causa raíz

El aviso de reserva próxima y el aviso de ausencia sí compartían `showOpenTicketNotice`, pero el contenido se renderizaba como una tarjeta angosta dentro de un overlay anidado. En escritorio el panel base conservaba `max-width: 400px`, y cada aviso tenía `max-width: 300px` o `360px`. Además, el `overflow-y: auto` estaba en el panel completo: encabezado, texto y acciones competían por la misma altura. El texto operativo largo dejaba los botones fuera del primer viewport y obligaba a desplazar el modal completo.

## 2. Diseño final aplicado

- Se conserva un solo componente compartido y una sola función `showOpenTicketNotice`.
- El aviso ahora tiene estructura semántica equivalente a `header / body / footer`.
- El shell operativo usa `grid-template-rows: auto minmax(0, 1fr) auto`.
- El `body` es la única zona con `overflow-y: auto`; las acciones quedan fuera del scroll.
- En escritorio el panel usa `width: min(46rem, calc(100vw - 3rem))` y `max-height: min(85dvh, 48rem)`.
- En móvil usa margen de 12 px, `max-height: calc(100dvh - 1.5rem)`, padding seguro inferior y acciones a ancho completo apiladas.
- Al abrir se reinicia `scrollTop` del body a cero.
- La variante de ausencia conserva sus estilos de advertencia en POS y en el bundle administrativo.
- No se modificaron endpoints, contratos, estados, reglas de negocio, esquema, colores generales ni textos operativos en esta etapa.

## 3. Variantes cubiertas

### Reserva próxima

La prueba manual seleccionó Mesa 6, confirmó la apertura sobre una reserva dentro de 30–60 minutos y mostró el aviso `Hay una reservación próxima`. El texto, el detalle de mesas y las acciones `Volver a la selección` / `Abrir ticket de todas formas` permanecieron visibles. Al confirmar se observó un único ticket abierto para Mesa 6; no se repitió la mutación.

### Ausencia

La variante comparte el mismo shell, `header / body / footer`, descripción accesible y apilamiento móvil. La prueba manual de la mutación real no pudo ejecutarse con el dataset operativo actual: en 2026-08-04 solo se expuso la reservación futura de las 21:30; el fixture `POS Tolerancia` de 2026-11-30 permaneció futuro y con `Iniciar servicio` deshabilitado; `POS No Show` ya era un estado final y no se muestra como candidata. No se forzó ni se fabricó una mutación fuera del flujo autorizado.

## 4. Accesibilidad

- `role="alertdialog"` y `aria-modal="true"` se mantienen.
- `aria-labelledby="mesa-modal-title"` referencia el encabezado `h2`.
- `aria-describedby="mesa-modal-description"` referencia el cuerpo desplazable.
- El foco inicial cayó en la acción primaria.
- Se verificó que el fondo queda bloqueado mientras el modal está abierto y que al cancelar se elimina el diálogo sin mutación.
- La implementación conserva el cierre con Escape, la trampa de Tab/Shift+Tab y la restauración de foco existentes.

## 5. Validación visual manual

URL local: `http://127.0.0.1:8000/punto-de-venta`.

| Variante | Resultado observado |
| --- | --- |
| Escritorio 1280×720 | Panel 736×292; diálogo 686×242; body 147 px y acciones 59 px. El panel y las acciones quedan completamente visibles. |
| Reflujo equivalente a 200%: 640×360 | Panel 592×306; diálogo 542×256; body 161 px con scroll; `scrollWidth` igual a 640, sin overflow horizontal. |
| Móvil 390×844 | Panel 366×399; diálogo 342×358; acciones 342×111; botones a ancho completo y `flex-direction: column-reverse`; `scrollWidth` igual a 390. |

El Browser in-app no expone una capacidad independiente de zoom; por eso la comprobación de 200% se hizo con el viewport CSS equivalente de 640×360 y se dejó el viewport restaurado al finalizar.

## 6. Archivos modificados por esta etapa

- `src/js/modules/punto-de-venta.js`
- `src/scss/punto-de-venta/_punto-de-venta.scss`
- `src/scss/admin/modules/map.scss`
- `tests/js/modal-layout.test.js`
- `package.json`
- Salidas compiladas correspondientes en `assets/css/app.css`, `public/build/css/app.css`, `public/build/css/admin/map.css` y `public/build/js/admin/map.js` (incluidos sus sourcemaps cuando aplica).

El worktree ya contenía cambios de Etapa 11.7.2; no se revirtieron ni se mezclaron con esta corrección.

## 7. Pruebas automatizadas y regresión

Todas terminaron con código 0:

- `php scripts/run-tests.php`
- `npm.cmd test`
- `npm.cmd run test:js`
- `node --check src/js/modules/punto-de-venta.js`
- `git diff --check`

El nuevo `modal-layout.test.js` valida el componente único, estructura semántica, ARIA, grid, scroll exclusivo del body, sizing de escritorio/móvil y acciones apiladas.

## 8. Build reproducible

Se ejecutó `npm.cmd run build` dos veces consecutivas; ambas finalizaron correctamente. Solo permanecen los avisos ya existentes de la API legacy de Dart Sass y `fs.Stats` de Node.

## 9. Riesgos pendientes

- Falta repetir la mutación real de `Registrar ausencia` con una reserva local que el backend exponga como `puede_registrar_ausencia: true`; el bloqueo es de datos de prueba, no del shell visual.
- El ticket de prueba de Mesa 6 quedó abierto en el POS local para comprobar que la confirmación produjo una sola mutación. No se cerró para evitar introducir una operación de pago no solicitada.
- No se creó commit, conforme a la instrucción de la etapa.

## 10. Decisión

La corrección visual del modal compartido queda implementada y verificada para reserva próxima, viewport estrecho equivalente a 200%, móvil, scroll interno, acciones visibles, accesibilidad y regresión/build. No se inicia todavía la auditoría de conformidad ni la Etapa 12: primero debe repetirse el caso manual de ausencia con un fixture elegible y luego tomarse la decisión de cierre de la etapa.
