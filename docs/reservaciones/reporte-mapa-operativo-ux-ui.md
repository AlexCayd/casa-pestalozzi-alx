# Reporte — Rediseño UX/UI del mapa operativo

## Diagnóstico

La vista repartía el espacio del mapa entre una columna permanente de detalle, una toolbar con selección, búsqueda y filtros, y una lista estructurada que crecía dentro del flujo. Esto reducía el área útil del mapa y mezclaba contexto operativo con información de consulta.

## Implementación

- El header de reservaciones ya no muestra `Mapa de reservaciones` como título central; se conserva el comportamiento compartido del POS.
- La toolbar conserva sólo actualizar, fecha, hora, capacidad y crear reservación.
- Búsqueda y filtro de asignación viven dentro del drawer existente.
- La capacidad tiene una lectura primaria de lugares disponibles; los datos secundarios sólo aparecen cuando hay demanda sin mesa o capacidad proyectada.
- El detalle se abre como overlay derecho sólo después de seleccionar una reservación. Sin selección, el mapa ocupa todo el espacio.
- La lista estructurada accesible se conserva como bandeja inferior superpuesta, con scroll horizontal y targets táctiles.
- El detalle prioriza nombre, estado, hora, personas, mesas y una sola acción recomendada. Capacidad y diferencia permanecen dentro del modo de asignación.
- Los IDs internos ya no aparecen en el detalle, acciones de ticket, barra de asignación ni confirmaciones operativas.
- La nota del cliente y el comentario interno permanecen separados. El comentario interno ahora muestra lectura compacta y entra en edición sólo al pulsar `Editar`.
- Se conservaron focus management, Escape, backdrop, `aria-hidden`, `inert`, foco visible y la lista estructurada como alternativa accesible.

## Archivos principales

- `views/operation/reservations/index.php`
- `views/operation/reservations/_filters.php`
- `views/operation/partials/header.php`
- `views/operation/partials/drawer.php`
- `views/operation/partials/map.php`
- `src/js/admin/reservations/operation.js`
- `src/scss/operation/_header.scss`
- `src/scss/operation/_toolbar.scss`
- `src/scss/operation/_drawer.scss`
- `src/scss/operation/_layout.scss`
- `src/scss/operation/_map-shell.scss`
- `src/scss/operation/_reservation-detail.scss`

## Verificación

- `npm.cmd test` — OK.
- `npm.cmd run build` — OK; sólo mostró advertencias deprecadas de la API antigua de Sass.
- Lint PHP de las vistas modificadas — OK.
- `node --check` del JavaScript modificado — OK.
- Detector Impeccable (`detect.mjs --json`) — sin hallazgos.
- `git diff --check` — sin errores.

La revisión visual final se hizo estáticamente sobre la arquitectura, estados, breakpoints y contratos de los componentes. La sesión Live de Impeccable no pudo iniciar porque el proyecto todavía no tiene `DESIGN.md`; no se inventó una documentación visual para desbloquearla.

## Alcance y garantías

El cambio fue exclusivamente de UX/UI y presentación. No se modificaron capacidad, asignación, estados, tolerancia, disponibilidad, tickets, permisos, OTP, n8n, privacidad ni reglas POS. No se crearon commits ni se hizo push.
