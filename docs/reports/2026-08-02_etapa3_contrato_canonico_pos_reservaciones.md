# Etapa 3 — Contrato canónico POS–reservaciones

Fecha: 2026-08-02  
Repositorio: `C:\xampp\htdocs\casa-pestalozzi`

## Alcance

Se implementó la unificación del contrato operativo entre POS y reservaciones con base en `reservaciones_fuente_de_verdad.md`. No se modificó `database/database.sql`, no se reconstruyó la landing ni se creó un motor nuevo de disponibilidad, asignación o mapa.

## Contrato y lector común

- `services/PosReservacionSerializer.php` es el serializador puro del contrato `pos-reservacion.v1`.
- `services/PosReservacionQueryService.php` concentra la lectura de mesas, reservaciones, tickets abiertos, ocupación y estados visuales.
- La respuesta incluye `schema_version`, `server_time`, `timezone`, `reservacion_id`, fechas/horas, contacto, comensales, nota/comentario, `mesa_ids`, objetos `mesas`, `ticket_id`, `ticket_abierto`, `ticket_mesa_ids`, la ventana canónica, capacidades de operación, bloqueo/advertencia/disponibilidad y `motivo`.
- Se conserva `id` como alias de transporte para consumidores existentes; la identidad contractual es `reservacion_id`.
- Las ventanas emitidas son únicamente: `futura`, `30_60`, `0_30`, `tolerancia`, `tolerancia_vencida` y `en_curso`.

## Consumidores migrados

- `/api/punto-de-venta` usa el lector común y ya no mantiene una consulta/serialización paralela.
- `/api/punto-de-venta/reservaciones` delega en el mismo lector y devuelve versión, reloj y zona horaria.
- `/admin/api/reservations/operation` usa las mismas reservaciones, tickets, ocupación y `mesas_estado`; se retiraron sus serializadores duplicados.
- `MesaEstadoService` consume la salida canónica. Mantiene `estado_base` para compatibilidad y expone `estado_visual` con `libre`, `ocupada`, `reservacion-proxima` y `no-utilizable`; las combinaciones se expresan mediante modificadores, incluyendo `seleccion_actual` en el consumidor visual.
- `mesa_ids` de reservación y `ticket_mesa_ids` de ocupación física permanecen separados. Un ticket abierto conserva precedencia física aunque haya vencido su proyección estimada.

## Frontend y acciones

- POS dejó de calcular ventanas, advertencias y bloqueos desde fecha/hora/configuración local; lee `ventana_operativa`, `mesas_estado` y las capacidades del contrato.
- Operación usa `puede_iniciar_servicio` y `puede_registrar_ausencia`; no reconstruye acciones a partir de umbrales.
- Se retiró el botón/transición nueva de `llego`. Los registros históricos y `arrived_at` se leen como legado, pero iniciar servicio no depende de esa marca ni la escribe.
- Las mutaciones de iniciar servicio, no-show, cancelación y apertura de tickets conservan validación transaccional e idempotencia existentes.

## Pruebas y validación

Se agregó el fixture `tests/fixtures/pos_reservacion_contrato.json` y el runner `tests/php/pos_reservacion_contrato.php`. Verificaciones ejecutadas:

- `php tests/php/pos_reservacion_contrato.php` — OK; valida las seis ventanas, identidad canónica, capacidades, ocupación física y modificadores visuales.
- `php -l` sobre servicios, controladores y vista modificados — OK.
- `node --check src/js/modules/punto-de-venta.js` — OK.
- `node --check src/js/admin/reservations/operation.js` — OK.
- `node --check` sobre `public/build/js/admin/map.js` y `public/build/js/admin/reservation-operation.js` — OK; bundles regenerados.
- `git diff --check` — OK; sólo reporta avisos de conversión de fin de línea de Git.

La lectura integrada contra base de datos no pudo ejecutarse en CLI porque el bootstrap de esta sesión no inicializó la conexión (`Call to a member function query() on null`). Tampoco se hizo validación visual interactiva porque no había sesión de navegador disponible.

## Riesgos pendientes

- La base actual conserva estados/columnas históricas como `llego` y `arrived_at`; no se hizo la migración destructiva solicitada para una etapa posterior.
- El lector común mantiene consultas de ocupación por reservación ya existentes; conviene medir rendimiento con datos reales antes de optimizar sin cambiar semántica.
- Debe hacerse una pasada visual en POS y panel con la base y navegador levantados, verificando especialmente tickets abiertos sobre múltiples mesas, advertencias simultáneas y reservas controladoras.

## Recomendación para la siguiente etapa

Validar en entorno integrado el contrato con datos reales y cerrar la migración de esquema histórica de `llego`/`arrived_at` sólo después de confirmar que no quedan consumidores ni procesos de mantenimiento dependientes. No continuar reconstruyendo la base ni crear otro motor de disponibilidad antes de esa validación.
