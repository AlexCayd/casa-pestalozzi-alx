# Reporte UX/UI — simplificación del mapa operativo de reservaciones

**Proyecto:** Casa Pestalozzi
**Superficie:** mapa operativo de reservaciones para admin y waiter
**Fecha:** 2026-08-17
**Commit propuesto:** `refactor(ui): simplificar mapa operativo de reservaciones`

## Resultado

La superficie quedó más estable y directa sin modificar reglas de negocio, algoritmos del mapa, colores semánticos, permisos ni contratos backend.

## Problemas y causas

- El modo “Cambiar mesas” aplicaba un `clamp()` calculado con alturas del shell y luego convertía varios ancestros a `height: auto` y `overflow: visible`. Esto hacía que el workspace se encogiera o dejara espacio muerto alrededor de la barra.
- Reservaciones incluía el toggle compartido de maximizar/restaurar y un botón visible de actualizar, aunque la superficie ya cuenta con actualización automática.
- La disponibilidad combinaba el valor real con un total redundante y un icono decorativo.
- La regla base de inputs del panel administrativo sobrescribía el padding del buscador después de la regla del drawer; el icono quedaba dentro de la zona del placeholder.
- Algunas acciones secundarias usaban apariencia ghost y Cancelar podía parecer texto de estado. El detalle también reservaba más alto del necesario.

## Cambios aplicados

### Layout y asignación

- El workspace de asignación usa `height: auto`, `flex: 1 1 0`, `min-height: 0` y overflow contenido.
- La barra queda como el siguiente elemento flex y consume sólo su alto natural; no usa alturas calculadas con viewport.
- En desktop se conserva el shell a la altura de la ventana; en móvil el modo de asignación permite flujo vertical y scroll natural.
- La barra se compactó, conserva identidad, personas, capacidad, diferencia, mesas, Cancelar y Guardar asignación, y mantiene Guardar como acción primaria.

### Controles retirados de reservaciones

- Se eliminó el include de `map-toggle.php` únicamente de `views/operation/reservations/index.php`.
- Se eliminó el botón visible `data-operation-load` únicamente del filtro de reservaciones.
- El POS conserva el componente y el listener compartidos de `data-operational-map-toggle`.
- Se retiraron del JS los listeners/selectores asociados al refresh visible y al filtro de asignación que ya no se renderiza.

### Disponibilidad reactiva

- La UI muestra `capacidad_real_disponible` como “N lugares disponibles”, sin total paralelo ni icono.
- Los secundarios sólo aparecen cuando aportan contexto: demanda sin mesa, capacidad proyectada o capacidad comprometida.
- No se añadió cálculo de capacidad en frontend.
- Fecha y hora siguen llamando `loadDay`; creación usa `loadDay` con la reservación creada; asignar, liberar, reasignar, guardar comentario, cancelar, no-show e iniciar servicio llaman `refreshDay` tras una respuesta exitosa.
- Se mantienen el intervalo temporal y los refresh por visibilidad/páginashow existentes.

### Drawer y detalle

- El buscador del drawer reserva 42 px internos para el icono, alinea el icono al centro y conserva el ancho completo responsive.
- La jerarquía del detalle queda en encabezado/estado, hora/personas, mesas, nota del cliente, comentario bajo demanda y acciones.
- “Cambiar mesas”, “Agregar nota” y “Liberar mesas” usan botón secundario compacto visible.
- “Iniciar servicio”, “Guardar asignación” y “Crear reservación” conservan el estilo primario.
- Cancelar y Registrar ausencia usan borde/fondo de peligro explícitos, sin confundirse con una etiqueta.
- Las acciones secundarias del detalle pueden compartir fila cuando el ancho lo permite para reducir scroll; el panel seleccionado ya no fuerza `min-height: 100%`.

## Archivos principales

- `controllers/ReservacionOperacionController.php` — cachebuster v28 para CSS/JS de operación.
- `views/operation/reservations/index.php` y `_filters.php` — superficie y controles visibles.
- `src/js/admin/reservations/operation.js` — limpieza de listeners y jerarquía de acciones.
- `src/scss/operation/_layout.scss` — reparto estable del alto.
- `src/scss/operation/_assignment-mode.scss` — barra compacta.
- `src/scss/operation/_toolbar.scss` — disponibilidad y cascada de inputs.
- `src/scss/operation/_drawer.scss` — buscador e icono.
- `src/scss/operation/_reservation-detail.scss` — detalle, espaciado y affordances.
- `scripts/tests/run-reservaciones-ux-map-contract.php` — contrato estático UX/UI.

## Pruebas y build

- `npm.cmd test` — OK, incluida la nueva prueba UX/UI.
- `npm.cmd run build` — OK; Sass sólo reporta las advertencias legacy existentes.
- `npx.cmd gulp operationCss` — OK después del último ajuste visual.
- `php -l` para las dos vistas y el contrato nuevo — OK.
- `node --check src/js/admin/reservations/operation.js` — OK.

El contrato estático comprueba que reservaciones no renderiza refresh/maximizar, que el POS conserva el componente compartido, que la disponibilidad usa la fuente autoritativa, que las mutaciones vuelven a consultar y que la estructura flex no reintroduce el `clamp()` de asignación.

## Validación visual

- En navegador local se comprobó la vista de operación con datos reales de prueba, la ausencia de los controles retirados, disponibilidad “40 lugares disponibles”/“44 lugares disponibles”, drawer, búsqueda, detalle y entrada/salida de asignación.
- En una pasada de 1280×720, el modo de asignación dejó una barra de 66.6 px y un mapa de 430 px útiles, sin hueco vertical adicional. La salida devolvió el panel persistente y el alto normal del mapa.
- El detalle compacto mantuvo visibles Cambiar mesas, Agregar nota, Liberar mesas y Cancelar reservación. El buscador calculó padding izquierdo de 42 px y el texto quedó fuera del área del icono.
- Se conserva la matriz responsive de las pasadas anteriores en 1920×1080, 1536×864, 1366×768, tablet y móvil; los nuevos cambios no alteran breakpoints del POS ni la geometría semántica del mapa.

## Fuera de alcance

No se modificaron modelos, controladores de negocio, servicios de capacidad/asignación, algoritmos de estados del mapa, contratos de API, permisos, privacidad, tickets, tolerancias, OTP, POS ni flujos de n8n. No se ejecutaron mutaciones reales durante la revisión visual y no se hizo push.
