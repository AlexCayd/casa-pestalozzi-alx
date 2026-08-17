# Reporte UX/UI — segunda pasada del mapa operativo de reservaciones

**Proyecto:** Casa Pestalozzi  
**Superficie:** operación de reservaciones (`admin` y `waiter`)  
**Enfoque:** `DISTILL → POLISH → VERIFY`  
**Fecha:** 2026-08-17

## Resultado

Se hizo una segunda pasada visual acotada sobre la implementación existente. La estructura de mapa, drawer, detalle, lista accesible y barra de asignación se conserva; se ajustó la jerarquía visual y la coordinación de overlays sin cambiar reglas de negocio.

### Ajustes aplicados

- La capacidad usa una línea primaria compacta: “disponibles de total”. Los secundarios sólo aparecen cuando aportan contexto y los valores cero se ocultan individualmente.
- El primer viewport del detalle prioriza nombre, estado, hora, personas y mesas. Se eliminaron IDs, métricas de asignación duplicadas y el botón duplicado de guardar.
- La acción recomendada conserva la decisión entregada por el backend. Los controles imposibles no se muestran deshabilitados; cancelar y liberar asignación quedaron bajo “Más acciones”.
- Nota del cliente y comentario interno sólo ocupan espacio cuando tienen contenido o una acción válida para el rol.
- La lista estructurada conserva etiquetas ARIA completas, pero muestra una lectura compacta: mesa, estado, contexto opcional y capacidad. En desktop evita quedar debajo del detalle; en viewport estrecho abrirla cierra visualmente el detalle.
- El drawer se mantiene entre 320 y 350 px. El placeholder y la etiqueta accesible de búsqueda son “Buscar por nombre” para waiter y “Nombre o contacto” para admin.

## Restricciones preservadas

No se modificaron controladores, modelos, servicios, endpoints, contratos de proyección, permisos, capacidad, disponibilidad, tolerancias, asignación de mesas, tickets, OTP, privacidad ni flujos de n8n. La interfaz continúa consumiendo los hechos y recomendaciones del backend.

## Verificación

- `npm.cmd test` — OK.
- `npm.cmd run build` — OK. Sólo permanecen advertencias existentes de la API legacy de Sass y `fs.Stats`.
- `node --check` para los módulos JS modificados — OK.
- `php -l` para las vistas PHP modificadas — OK.
- `git diff --check` — OK; sólo avisos de normalización LF/CRLF de Git.
- `detect.mjs --json` de Impeccable — `[]`.

La revisión Live de Impeccable no se ejecutó porque el proyecto no contiene `DESIGN.md`; el modo Live exige ese archivo. No se inventó un sistema de diseño para suplirlo.

