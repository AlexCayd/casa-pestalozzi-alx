# Auditoría técnica: refresh y proyección de reservaciones

Fecha de referencia: 2026-08-18.

Este documento es el informe diagnóstico de la etapa previa. No es una fuente normativa ni implementa las correcciones propuestas.

## 1. Snapshot Git

- Rama local: `audit/reservaciones-refresh-proyeccion`.
- Base funcional observada: `2c30d3f` (`style(reservaciones): compactar detalle operativo`).
- Checkpoint de preservación: `addcb80` (`chore: preservar estado local previo a auditoria`).
- El checkpoint conserva todos los cambios locales encontrados antes de auditar: artefactos compilados, la eliminación local de la migración de acceso, `.agents/`, `.codex/` y `PRODUCT.md`.
- No se hizo `reset`, `clean`, merge a `main` ni force push.
- Se intentó `git push -u origin audit/reservaciones-refresh-proyeccion`, pero la publicación fue detenida por la revisión de seguridad del entorno porque el payload contiene 21 commits locales y archivos ocultos de tooling aún no revisados. La rama remota no debe considerarse creada hasta obtener autorización explícita y repetir el push.

El checkpoint es el estado exacto desde el que se realizó el análisis. Las actualizaciones documentales y el registro de esta auditoría son posteriores y deben permanecer en commits separados.

## 2. Baseline

`npm.cmd test` terminó con código 0. Las pruebas PHP y JavaScript reportaron `OK`; permanecen dos avisos esperados de `ReservacionErrorCatalog` sobre violaciones de commit y códigos no catalogados.

`npm.cmd run build` terminó con código 0. Sólo produjo las advertencias conocidas de Sass por la API heredada y de `fs.Stats` de Node. El build actualizó cuatro artefactos de bundle JavaScript, que se registran por separado del cambio documental.

La reproducción manual en navegador quedó bloqueada porque no había servidor HTTP escuchando en `http://localhost/casa-pestalozzi/public/...` (`ERR_CONNECTION_REFUSED`). La evidencia de los contratos y del flujo se obtuvo mediante inspección de código y experimentos controlados con reloj fijo.

## 3. Bug 1: cambio de fecha, hora y contexto de operación

### Síntoma reproducido

La secuencia problemática es:

1. En el día actual, después del último horario reservable, `horarios` queda vacío y `renderOperationAvailability()` oculta `Nueva reservación` porque hay carga sin horarios o `editable=false`. Ese estado es correcto.
2. Se cambia manualmente a otro día.
3. `requestSelectedDate()` llama a `loadDay()` con `preserveHour: els.hour.value`. El selector todavía contiene la hora del día anterior, aunque `state.horaSeleccionada` se haya limpiado.
4. `loadDay()` envía esa hora como `hora` si existe y, cuando la respuesta la considera válida, vuelve a seleccionarla.

En un día con horarios, seleccionar el último horario y cambiar de fecha produce el mismo resultado: la última hora puede sobrevivir aunque la política del nuevo contexto requiera el primer horario válido. Si la nueva solicitud queda pendiente, el botón permanece oculto durante la carga y la capacidad no se muestra porque el estado se invalidó; una carrera adicional puede dejar la carga bloqueada.

### Causa raíz

La causa determinista está en `src/js/admin/reservations/operation.js`:

- `requestSelectedDate()` preserva el valor del input de hora para un cambio manual de fecha.
- `loadDay()` calcula `requestedHour` a partir de `options.preserveHour` y construye la consulta con esa hora.
- Al recibir datos, la rama de selección usa `requestedHour` si sigue presente en `availableHours`; sólo después cae en `data.hora_sugerida` o `availableHours[0]`.

El backend `ReservacionOperacionController::operationData()` vuelve a resolver y recalcular todo para la hora recibida, incluyendo `mesas_estado` y `capacidad_horario`. Por eso `capacidadHorario` no es una caché separada ni se actualiza por una solicitud independiente: el problema es que puede recibirse un snapshot perfectamente coherente para la hora vieja, o quedar en estado de carga, no una capacidad calculada con una clave oculta.

El fallback backend también merece ajuste en la próxima etapa: `HorarioReservacionService::resolverHorarioMapa()` conserva una hora explícita válida y, sin hora explícita, actualmente busca el bloque más cercano al reloj actual antes de caer al primero. Para una nueva fecha sin hora explícita, la regla requerida es el primer horario válido.

### Carrera asincrónica

Hay protecciones correctas:

- cada carga incrementa `state.requestSequence`;
- la respuesta exitosa verifica secuencia y fecha antes de escribir;
- se valida que la hora respondida coincida con la hora solicitada;
- `projectionContext` y `pendingProjectionContext` se actualizan en el mismo ciclo de la respuesta;
- `setOptions()` del time picker se ejecuta en modo silencioso, por lo que no se encontró una segunda consulta causada por pintar el selector.

Hay una condición defectuosa: el `catch` de cualquier solicitud abortada limpia `state.timeoutId` sin verificar que ese timeout pertenezca a `requestSequence`. Una respuesta abortada vieja puede borrar el timeout de la solicitud nueva. Si la solicitud nueva queda pendiente, `state.cargando` permanece verdadero y `Nueva reservación`, capacidad, mapa y acciones quedan en estado de carga. Las guardas evitan que la respuesta vieja sobrescriba datos, pero no protegen la propiedad del timeout.

### Casos obligatorios

| Caso | Resultado observado o demostrado | Resultado requerido |
| --- | --- | --- |
| A. Día actual sin horarios → mañana | La hora del input puede viajar a mañana; el request nuevo controla el resultado. Una solicitud pendiente puede dejar la UI en carga. | Primer horario de mañana, capacidad de mañana y alta disponible. |
| B. Último horario de A → día B | La hora anterior se envía y se conserva si B la acepta. | Primer horario válido de B. |
| C. A → B → C rápido | Las guardas de secuencia impiden que A o B escriban sobre C; el timeout compartido aún puede quedar mal asignado. | Sólo C puede actualizar estado y sus temporizadores. |
| D. A + 14:00 → refresh | `refreshDay()` solicita de nuevo el snapshot de la fecha y hora actuales; no hay caché separada de capacidad. | Capacidad obtenida/calculada otra vez para A + 14:00. |
| E. Fecha futura sin tickets actuales | `TicketTemporalService` marca los tickets actuales como `aplica_fecha=false`; la capacidad se calcula con reservaciones y holds de la fecha consultada. | Capacidad completa menos conflictos de esa fecha. |

### Solución propuesta

1. Separar explícitamente carga inicial, selección manual de fecha, cambio manual de hora, refresh y edición de una reservación. Sólo edición/refresh deben preservar hora; cambiar de fecha debe invalidar la hora anterior.
2. Hacer que el servidor y el cliente resuelvan el primer horario válido cuando la nueva fecha no trae una hora explícita válida. Si se conserva una hora de edición, debe declararse como intención explícita y validarse contra la fecha.
3. Mantener un único snapshot `fecha + hora` para horarios, mesas, reservaciones, capacidad y acciones. `renderAll()` puede seguir siendo la salida única, pero la carga debe publicar también una identidad de snapshot.
4. Asociar la limpieza del timeout y del `AbortController` a la secuencia que los creó; una solicitud vieja no debe tocar recursos de la nueva.
5. Mantener el control de `Nueva reservación` derivado de la respuesta vigente (`editable`, horarios, carga y error), no de banderas residuales del día anterior.

### Archivos y capas afectadas

- `src/js/admin/reservations/operation.js`: selección de hora, invalidación, secuencia y timeout.
- `src/js/components/reservation-time-picker.js`: verificar el contrato silencioso al pintar opciones; no requiere cambio si la prueba confirma que no emite consultas.
- `controllers/ReservacionOperacionController.php`: resolución de hora y snapshot de respuesta.
- `services/HorarioReservacionService.php`: política de fallback para fecha nueva sin hora explícita.
- `views/admin/reservations/index.php` y el adaptador de fecha/hora: sólo si la prueba de integración encuentra un evento duplicado.

### Pruebas requeridas

- Prueba de integración del cambio manual de fecha desde `sin_horarios` al primer horario del día siguiente.
- Prueba de cambio desde el último horario de A a B que demuestre que no se hereda la hora.
- Prueba de entrada inicial y edición con hora explícita válida, que sí debe conservarla.
- Prueba de respuestas A/B/C fuera de orden y de ownership de timeout por secuencia.
- Prueba de que la identidad de capacidad, mesas y mapa coincide con `fecha + hora`.
- Prueba de que pintar opciones del time picker no dispara una consulta adicional.

## 4. Bug 2: ticket abierto frente a proyección futura

### Reproducción controlada

Se ejecutó el motor con reloj fijo `2026-08-18 10:00`, mesa 1 y ticket abierto a las `09:30`. Con la configuración local de 90 minutos y retraso 0, la liberación estimada es `11:00`.

| Consulta | `ocupada_fisicamente` | Bloquea proyección | Asignable |
| --- | ---: | ---: | ---: |
| 10:30 | `true` | `true` | `false` |
| 10:59 | `true` | `true` | `false` |
| 11:00 | `true` | `false` | `true` si no hay otro conflicto |
| 12:00 | `true` | `false` | `true` si no hay otro conflicto |
| Día siguiente 10:00 | `true` físicamente, ticket ignorado para esa fecha | `false` | `true` si no hay otro conflicto |

La misma prueba con `MesaEstadoService::normalizarMesas()` produjo, para 11:00, `disponible_para_asignacion=true`, `bloqueada_en_intervalo=false`, `ticket_abierto=true`, `ticket_bloquea_consulta=false`, `ocupada_fisicamente=true` y `estado_visual_mapa=libre`. El estado POS conservó `estado_visual_pos=ocupada` y el modificador `ticket_abierto`. Esto demuestra que el backend sí conserva el hecho físico y también expone la decisión proyectada; el desvío está en un consumidor visual/UX, no en la fórmula temporal.

### Traza de datos

1. `TicketTemporalService::proyectar()` calcula contexto, liberación, `bloquea_en_consulta`, `disponible_proyectada` y `ocupada_fisicamente`.
2. `OcupacionMesasService::evaluarHorario()` conserva tickets físicos, pero sólo agrega a `bloqueantes` los que bloquean el intervalo consultado.
3. `MesaEstadoService` serializa por separado `ocupada_fisicamente`, `ticket_abierto`, `ticket_bloquea_consulta`, `bloqueada_en_intervalo` y `disponible_para_asignacion`. Para el mapa, la precedencia proyectada produce `estado_visual_mapa=libre`.
4. `PosReservacionQueryService::paraFecha()` entrega el mismo contrato a la operación, con capacidad y mesas del intervalo consultado.
5. `AsignacionMesasService` automático filtra `ocupacion_bloqueante`; su flujo manual consulta `tickets_bloqueantes` y la evaluación canónica. El backend no elimina la mesa liberada sólo por tener un ticket abierto.
6. En `src/js/admin/reservations/operation.js`, `tableModalState()` usa primero `table.ticket_abierto === true || table.ticket_bloquea_consulta === true` para rotular “Ocupada”. Además, `mesaPuedeSerCandidata()` exige `mesa.ticket_abierto !== true` aunque ya exige `disponible_para_asignacion === true`; `currentAssignmentConflictIds()` repite esa interpretación cruda.

### Causa raíz

El mapa consume `estado_visual_mapa` y `disponible_para_asignacion`, por eso se ve libre. El resumen/modal y el filtro de candidatas del flujo manual mezclan el hecho físico `ticket_abierto` con la decisión temporal del intervalo. La proyección no se pierde en `TicketTemporalService`, `OcupacionMesasService`, `MesaEstadoService`, el serializer ni la asignación backend; se ignora en esos consumidores JavaScript.

El contrato existente `src/js/operation/table-state-adapter.js` ya intenta distinguir `ticket_bloquea_consulta` de `ticket_abierto`, pero `operation.js` aplica condiciones anteriores que vuelven a bloquear por el booleano crudo.

### Solución propuesta

1. En la vista de resumen/modal, usar `bloqueada_en_intervalo`, `ticket_bloquea_consulta` y `disponible_para_asignacion` para el estado del intervalo; conservar `ticket_abierto` sólo como indicación física o advertencia separada.
2. En la candidatura y conflictos de asignación del frontend, dejar que `disponible_para_asignacion` sea la condición de disponibilidad. No calcular la proyección ni duplicar la fórmula en JavaScript.
3. Mantener intacta la distinción visual POS: una mesa puede mostrarse físicamente ocupada en la vista actual y proyectarse libre en el mapa de una reservación futura.
4. Verificar por separado el guard de `canAssignTables()` para el ticket de la reservación en edición; no cambiarlo por analogía sin un caso funcional que demuestre que mezcla otra mesa o intervalo.

### Archivos y capas afectadas

- `src/js/admin/reservations/operation.js`: `tableModalState()`, `mesaPuedeSerCandidata()` y `currentAssignmentConflictIds()`.
- `src/js/operation/table-state-adapter.js`: sólo ajustar si la prueba demuestra una pérdida adicional del contrato.
- `services/TicketTemporalService.php`, `services/OcupacionMesasService.php` y `services/MesaEstadoService.php`: conservar como autoridad y cubrir con pruebas; no cambiar la regla.
- `services/AsignacionMesasService.php`: verificar regresión automática/manual; la evidencia actual indica que ya consume la evaluación canónica.

### Pruebas requeridas

- Prueba de dominio con reloj controlado para 10:30, 10:59, 11:00, 12:00 y el día siguiente.
- Prueba de serialización que exija simultáneamente `ticket_abierto=true`, `ocupada_fisicamente=true`, `ticket_bloquea_consulta=false`, `bloqueada_en_intervalo=false` y `disponible_para_asignacion=true` después de la liberación.
- Prueba de UI del resumen/modal: un ticket físicamente abierto pero liberado no debe rotular la mesa como bloqueada para el intervalo.
- Prueba de asignación manual y automática con la mesa proyectada; debe ser candidata sin cambiar la foto física actual.
- Prueba de conflicto real antes de la liberación, que debe continuar bloqueando.
- Prueba de fecha futura distinta al día actual, sin que un ticket actual reduzca su capacidad.
- Sustituir el contrato estático que sólo exige `mesa.ticket_abierto !== true` por una aserción funcional de la proyección.

## 5. Riesgos de regresión

- Preservar hora sigue siendo válido al editar una reservación existente; no debe eliminarse esa intención junto con el caso de cambio manual de fecha.
- Un reemplazo global de `ticket_abierto` dañaría advertencias físicas, acciones POS y la fotografía actual.
- Cambiar el fallback de `HorarioReservacionService` puede afectar la carga inicial sin parámetros y los enlaces con hora explícita; debe cubrirse con casos separados.
- El arreglo del timeout debe evitar que un abort legítimo marque como error la solicitud nueva.
- El resumen, el mapa y la asignación pueden tener propósitos distintos; deben compartir hechos canónicos, no necesariamente la misma etiqueta visual.

## 6. Documentación consolidada

Fuentes finales:

- `docs/reservaciones/reservaciones.md`: reglas vigentes de contexto temporal, tickets, capacidad y asignación.
- `docs/usuarios/usuarios.md`: roles, acceso, NIP y reglas de credenciales.
- `docs/usuarios/credenciales.md`: datos y procedimiento exclusivos de desarrollo/QA.
- `docs/privacidad/privacidad.md`: PII, visibilidad por rol, OTP, proveedores, retención, anonimización, `pinData` y sesiones.
- Este archivo: detalle técnico de la auditoría, no fuente de comportamiento.

Se eliminaron reportes históricos o duplicados ya absorbidos: reportes de permisos del mapa, privacidad por etapa, acceso/NIP, UX del mapa, toolbar de reservaciones y mantenimiento técnico de reservaciones. No se conservaron como fuentes normativas porque describían etapas, archivos o cambios anteriores en lugar de reglas vigentes.

## 7. Orden recomendado de implementación futura

1. Normalizar el ciclo de fecha/hora y la política de primer horario al cambiar de fecha.
2. Hacer atómica la identidad del snapshot `fecha + hora` y corregir ownership de abort/timeout.
3. Cubrir el motor temporal y la serialización con reloj controlado.
4. Cambiar los consumidores de resumen y asignación frontend para usar la decisión proyectada.
5. Agregar pruebas funcionales de asignación automática/manual y de UI.
6. Ejecutar regresión completa, build y reproducción manual con servidor HTTP disponible.

No se implementó ninguno de estos cambios funcionales en esta etapa.
