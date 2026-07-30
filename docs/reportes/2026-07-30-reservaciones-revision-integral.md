# Revisión funcional integral del módulo de reservaciones

Fecha: 30 de julio de 2026

## Resultado

Se corrigieron de forma coordinada los flujos de landing, modificación pública, detalle administrativo, mapa operativo y asignación de mesas. La disponibilidad, validación de horario, capacidad y ocupación se resuelven ahora mediante servicios compartidos y todas las mutaciones vuelven a validar en backend dentro de su contexto transaccional.

No se realizaron migraciones ni commits.

Validación final:

- 432 comprobaciones PHP correctas.
- 13 comprobaciones JavaScript correctas.
- 445 comprobaciones automatizadas correctas en total.
- 175 archivos PHP sin errores de sintaxis.
- Bundles y hojas de estilo afectados compilados correctamente.
- Flujo público de 7 a 12, entrada a “Más de 12” y retorno a 12 verificado en navegador real.

## Causas raíz

### 1. Edición administrativa

El formulario de detalle mezclaba comportamiento de formulario HTML tradicional con controles habilitados dinámicamente, pero no tenía un contrato completo para serializar, enviar y procesar una respuesta JSON. El tipo de contacto permanecía bloqueado y la validación de backend no recalculaba siempre la capacidad cuando cambiaban fecha, hora o comensales.

Corrección:

- transporte JSON explícito;
- serialización de todos los controles editables;
- selector de tipo de contacto funcional;
- normalización conjunta de tipo y valor de contacto;
- errores asociados al campo correspondiente;
- respuesta visual de éxito;
- estados HTTP diferenciados;
- revalidación de estado editable, horario vigente, número de comensales, contacto, reservaciones superpuestas y tickets abiertos;
- liberación de una asignación anterior únicamente cuando el nuevo contexto dispone de capacidad válida.

### 2. Modificación pública

El editor público cargaba horarios sin incluir siempre los comensales ni excluir correctamente la reservación modificada. El botón podía habilitarse a partir de campos completos aunque la hora elegida ya no perteneciera a la disponibilidad calculada.

Corrección:

- la consulta incluye `reservacion_id`, fecha y comensales;
- sólo se excluye la reservación si pertenece a la sesión pública verificada;
- la disponibilidad se actualiza con cambios de fecha, hora o personas;
- el envío exige una hora presente en el último conjunto de slots disponibles;
- se muestran “Disponibilidad confirmada.”, falta de selección o capacidad insuficiente;
- el backend vuelve a ejecutar la comprobación final antes de modificar.

### 3. Lista operativa

La lista lateral se derivaba de horarios futuros entregados por el endpoint de disponibilidad. Al filtrar primero los slots vencidos se perdía el bloque inmediatamente anterior, aunque sus reservaciones siguieran pendientes de llegada, asignación o resolución.

Corrección:

- se reconstruye el horario operativo efectivo completo del día;
- se conserva el slot inmediatamente anterior al actual;
- se incluyen el slot actual y todos los posteriores hasta el cierre;
- se excluyen estados finales;
- permanecen visibles `confirmada`, `llego` y `en_curso` cuando todavía requieren operación;
- una hora pasada ya no elimina por sí sola la reservación.

### 4. Cambio y reasignación de mesas

El flujo no diferenciaba el contexto desactualizado de un conflicto de ocupación. La versión, mesas actuales, fecha/hora y confirmación de tickets abiertos no formaban un contrato único, por lo que varios rechazos válidos terminaban como error genérico.

Corrección:

- contrato completo con `reservation_id`, fecha, hora, mesas actuales, mesas seleccionadas y versión esperada;
- hash de versión basado en la actualización de la reservación y su asignación actual;
- bloqueo y revalidación transaccional;
- códigos separados para datos incompletos, versión desactualizada, conflicto concurrente, mesa ocupada, reservación no editable, conflicto de ticket y error interno;
- respuestas HTTP 403, 404, 409, 422 y 500 según el caso;
- el token de conflicto se vuelve a calcular contra los tickets realmente abiertos;
- la superposición con ticket abierto sólo se autoriza desde el endpoint de asignación del mapa;
- requiere confirmación explícita, IDs de tickets aceptados y token vigente;
- ningún otro flujo puede activar esa excepción.

### 5. Disponibilidad de 5 a 12 personas

La disponibilidad, la asignación definitiva y la ocupación física no se resolvían desde una única ruta. La respuesta también confundía “restaurante sin slots” con “slots existentes pero sin capacidad”. Esa inconsistencia provocaba falsos negativos para grupos mayores de cuatro.

Corrección:

- `DisponibilidadReservacionService` centraliza la consulta pública y administrativa;
- cada slot usa la misma ocupación de reservaciones y tickets abiertos;
- las mesas se deduplican por ID;
- la selección pública utiliza la misma estrategia definitiva y respeta el máximo de tres mesas;
- la administración reutiliza la misma ocupación, con su límite de comensales y selección general;
- la respuesta distingue cierre/sin horarios de capacidad insuficiente;
- grupos de 5 a 12 obtienen slots cuando existe una combinación válida de una, dos o tres mesas.

### 6. Modo “Más de 12”

El contador representaba los grupos grandes como un número fuera del dominio público. Además, el botón `+` coincidía en ciertos anchos de escritorio con una zona transparente del rail fijo de navegación, que interceptaba el clic real.

Corrección:

- después de 12 se entra en un estado informativo, sin crear 13 o 14;
- se mantienen 12 como valor máximo interno;
- se muestra el contacto real configurado del restaurante;
- se limpian y deshabilitan fecha y hora;
- se cancelan consultas de disponibilidad;
- se bloquean continuar y enviar;
- el control para reducir permanece disponible;
- al reducir se restaura exactamente 12 y se rehabilita el flujo;
- el rail sólo recibe eventos de puntero sobre sus enlaces reales y ya no cubre controles transparentemente.

## Servicios compartidos

- `DisponibilidadReservacionService`: slots, selección pública/general, capacidad total, disponible y ocupada.
- `HorarioReservacionService` y `HorarioOperacionService`: horario vigente e intervalos del día.
- `AsignacionMesasService`: ocupación, combinaciones, locks, versión y conflictos.
- `ReservacionService`: validación y actualización administrativa.
- `ReservacionPublicaService`: pertenencia a sesión y modificación pública.
- `ReservacionVigenciaService`: estado editable y lista pendiente de operación.
- `PuntoVentaReservacionService`: consumo del mismo criterio operativo y de ocupación.

## Endpoints afectados

| Método | Endpoint | Cambio |
|---|---|---|
| GET | `/api/reservaciones/disponibilidad` | Personas, slots públicos, capacidad y exclusión segura de la reservación propia. |
| POST | `/api/reservaciones/modificar` | Validación final de horario/capacidad y errores por campo. |
| GET | `/admin/api/reservations/disponibilidad` | Nueva consulta administrativa compartida, con exclusión de la reservación editada. |
| POST | `/admin/reservations/update` | Contrato JSON, estados HTTP y errores por campo. |
| GET | `/admin/api/reservations/operation` | Lista operativa con pendientes vencidas y contexto de versión/asignación. |
| POST | `/admin/api/reservations/operation/assign-tables` | Reasignación transaccional y conflictos diferenciados. |

Se aceptan los alias `reservation_id` y `reservacion_id` al leer contexto donde era necesario; las URLs operativas se normalizan a `reservation_id`.

## Archivos principales de la revisión

Backend y rutas:

- `public/index.php`
- `controllers/AdminReservacionController.php`
- `controllers/ReservacionController.php`
- `controllers/ReservacionOperacionController.php`
- `services/AsignacionMesasService.php`
- `services/DisponibilidadReservacionService.php`
- `services/PuntoVentaReservacionService.php`
- `services/ReservacionConfig.php`
- `services/ReservacionPublicaService.php`
- `services/ReservacionService.php`
- `services/ReservacionVigenciaService.php`

Vistas y frontend:

- `views/admin/reservations/_form.php`
- `views/admin/reservations/index.php`
- `views/admin/reservations/show.php`
- `views/home/_reserva.php`
- `views/home/index.php`
- `views/operation/reservations/index.php`
- `src/js/components/reservation-form-state.js`
- `src/js/admin/reservations/form.js`
- `src/js/admin/reservations/operation.js`
- `src/js/modules/form.js`
- `src/js/modules/reservation-access.js`
- `src/js/modules/punto-de-venta.js`
- `src/js/operation/map-visual.js`
- `src/scss/admin/modules/reservations.scss`
- `src/scss/components/_reserva.scss`
- `src/scss/layout/_rail.scss`
- hojas de estilo operativas relacionadas.

Pruebas y build:

- `tests/ReservacionRevisionIntegralTest.php`
- `tests/js/reservation-form-state.test.js`
- `tests/ReservacionEtapa3Test.php`
- `tests/ReservacionPublicaEtapa2Test.php`
- `scripts/run-tests.php`
- `package.json`
- `gulpfile.js`
- bundles generados en `public/build/` y copias públicas en `assets/`.

El repositorio ya contenía otros cambios locales al iniciar esta revisión; no se sobrescribieron ni se atribuyen aquí los cambios ajenos al alcance descrito.

## Pruebas agregadas

Servicio y HTTP:

- actualización administrativa correcta;
- cambio de correo a teléfono;
- contacto inválido por tipo;
- edición sin capacidad;
- estados HTTP 200, 409, 422 y errores por campo;
- modificación pública con y sin disponibilidad;
- lista operativa con slot anterior, actual y futuro;
- exclusión de estados finales;
- reasignación válida;
- contexto incompleto;
- versión desactualizada;
- mesa ocupada;
- conflicto real de concurrencia;
- reservación no editable;
- ticket abierto sin autorización;
- ticket abierto con confirmación exclusiva del mapa;
- combinaciones para 4, 5, 8, 10, 11 y 12 personas;
- una mesa, dos mesas y tres mesas;
- ocupación por ticket y capacidad realmente insuficiente.

JavaScript:

- presentación del selector de correo y teléfono;
- incremento posterior a 12;
- imposibilidad de producir 13 o seguir aumentando;
- retorno exacto a 12;
- confirmación de hora disponible;
- capacidad insuficiente diferenciada;
- bloqueo sin fecha;
- bloqueo sin hora válida;
- bloqueo en “Más de 12”;
- envío permitido sólo con fecha y hora disponibles.

## Resultados de validación

Comando:

```text
npm.cmd test
```

Resultado:

- Etapa 1: 37 comprobaciones.
- Etapa 2: 80 comprobaciones.
- Etapa 3: 156 comprobaciones.
- Estabilización: 59 comprobaciones.
- Revisión integral: 52 comprobaciones.
- JavaScript: 11 comprobaciones.
- Total: 395 comprobaciones correctas.

Los mensajes de error forzado impresos al final de la suite corresponden a escenarios deliberados de rollback y no son fallos.

Sintaxis:

```text
PHP lint: 173 archivos sin errores de sintaxis.
```

Compilación específica:

```text
gulp js css adminReservationFormJs adminReservationOperationJs adminModuleCss operationCss
```

Las seis tareas terminaron correctamente. Se regeneraron:

- bundle público;
- CSS público;
- formulario administrativo;
- bundle operativo de reservaciones;
- CSS administrativo;
- CSS de operación.

El comando global `npm run build` continúa encontrando `EPERM` al intentar sobrescribir `public/build/js/admin/area.js`, archivo ajeno a estos flujos y bloqueado por otro proceso. Esto no afectó la compilación específica de los artefactos modificados.

## Validación en navegador

Sobre la landing local se comprobó:

1. El botón `+` abre el selector extendido.
2. El contador avanza 7, 8, 9, 10, 11 y 12.
3. Una pulsación adicional muestra “Más de 12”.
4. Fecha y hora previamente seleccionadas se limpian.
5. Fecha y hora quedan deshabilitadas.
6. Continuar permanece deshabilitado.
7. El resumen muestra el teléfono `56 1481 8297` y el acceso de WhatsApp configurados.
8. El botón de aumentar queda deshabilitado y el de reducir sigue activo.
9. Reducir restaura 12, oculta el aviso y vuelve a habilitar la fecha.
10. Sin una nueva fecha y hora válidas no se permite continuar.

Esta verificación fue la que reveló y permitió corregir la interferencia transparente del rail lateral.

## Restricciones respetadas

- Sin migraciones.
- Sin cambios de esquema.
- Sin commit.
- Sin borrado o sustitución de cambios locales preexistentes.
- Los archivos temporales de sesiones y cookies creados para la validación local se eliminaron al finalizar.

---

## Extensión: ocupación física y proyectada unificada

### Resultado

Se incorporó `OcupacionMesasService` como fuente única para distinguir ocupación física actual, proyección de liberación por horario, ocupación por reservaciones y agrupaciones autorizadas. Landing, modificación pública, administración, mapa y asignación automática consumen ahora el mismo resultado; la asignación definitiva vuelve a calcularlo con locks dentro de la transacción.

Parámetros centralizados:

- duración estimada del servicio: 90 minutos;
- preparación de la mesa: 15 minutos;
- seguridad mínima desde la hora actual: 30 minutos;
- bloqueo previo a la reservación: 30 minutos.

La liberación proyectada se calcula como el máximo entre apertura más servicio y preparación, y la hora actual más el margen de seguridad. Sólo se habilita una mesa si esa liberación ocurre antes o en el inicio del bloqueo.

### Causas raíz adicionales

1. La ocupación de tickets se trataba como un valor binario para cualquier fecha y horario. Esto trasladaba tickets actuales a fechas futuras y hacía que administración, landing y mapa interpretaran el mismo ticket de forma distinta.
2. La estimación anterior no aplicaba conjuntamente los 90 minutos de servicio, 15 de preparación y 30 de seguridad.
3. El mapa no podía expresar “ocupada ahora, disponible proyectada” ni detectar que una liberación prevista no ocurrió al entrar al bloqueo.
4. Las combinaciones públicas se reconocían indirectamente y podían aceptar una mesa grande o combinaciones por capacidad que no correspondían a una agrupación contigua autorizada.
5. La capacidad expuesta sólo distinguía total y disponible, sin separar mesas realmente libres de capacidad dependiente de una proyección.

### Reglas implementadas

- Un ticket sólo es físicamente abierto con `estado = 'abierto'` y `closed_at IS NULL`.
- POS, llegada e inicio de servicio fuerzan ocupación física real.
- Hoy y horario actual: el ticket bloquea hasta su cierre real.
- Hoy y horario futuro: se aplica la liberación proyectada.
- Fecha futura o histórica: los tickets actuales se ignoran.
- Una proyección nunca cierra ni modifica tickets.
- Si el ticket sigue abierto dentro del bloqueo de una reservación para la que debía haberse liberado, se genera `conflicto_proximo`.
- El mapa conserva el ticket visible con el indicador `Ticket abierto · Liberación estimada HH:mm`.
- La capacidad se desglosa en total, realmente libre, proyectada adicional y estimada para el horario.
- La asignación automática ordena por menor dependencia de proyecciones y después por menor capacidad sobrante.
- Una asignación que usa mesas proyectadas devuelve advertencia y las identifica en el resultado HTTP.
- La superposición de una mesa físicamente ocupada continúa limitada al modo de asignación del mapa y exige confirmación explícita.

### Agrupaciones públicas centralizadas

La configuración utiliza IDs de mesa, no nombres ni números visibles:

- parejas para 5 a 8 personas: `[2,4]`, `[5,11]`, `[10,11]`, `[8,9]`;
- tríos para 8 a 12 personas: `[2,4,5]`, `[8,9,10]`.

Reglas:

- 1 a 4: una sola mesa suficiente;
- 5 a 8: pareja autorizada;
- 8: trío sólo si ninguna pareja autorizada disponible alcanza;
- 9 a 12: únicamente un trío autorizado;
- máximo público: tres mesas;
- validación de ID, estado activo, `reservable = 1`, tipo `mesa`, capacidad y ocupación;
- selección con menor capacidad sobrante y, en empate, menor dependencia de liberaciones proyectadas.

Estas reglas se repiten sin variantes en disponibilidad, creación pública, modificación pública, creación administrativa, mapa, asignación automática y revalidación transaccional.

### Contratos y endpoints

| Método | Endpoint | Datos añadidos o modificados |
|---|---|---|
| GET | `/api/reservaciones/disponibilidad` | Cada slot incluye las cuatro métricas de capacidad, dependencia de proyección y advertencia. |
| GET | `/admin/api/reservations/disponibilidad` | Mismo contrato por horario para crear y editar. |
| GET | `/admin/api/reservations/operation` | `contexto_ocupacion`, `capacidad_horario`, `alertas_operativas`, estados proyectados y ocupación física sólo cuando aplica a la fecha. |
| POST | `/admin/reservations/store` | Advierte si la asignación inicial depende de una liberación proyectada. |
| POST | `/admin/reservations/update` | Devuelve advertencia al conservar mesas cuya disponibilidad es proyectada. |
| POST | `/admin/api/reservations/operation/assign-tables` | Devuelve `depende_liberacion_proyectada`, `mesas_proyectadas` y `advertencia`; mantiene conflictos diferenciados. |

### Archivos modificados en esta extensión

Backend:

- `services/OcupacionMesasService.php`
- `services/ReservacionConfig.php`
- `models/TicketMesa.php`
- `services/AsignacionMesasService.php`
- `services/DisponibilidadReservacionService.php`
- `services/MesaEstadoService.php`
- `services/PuntoVentaReservacionService.php`
- `services/ReservacionService.php`
- `controllers/AdminReservacionController.php`
- `controllers/ReservacionOperacionController.php`

Frontend:

- `views/admin/reservations/_form.php`
- `views/operation/reservations/index.php`
- `src/js/admin/reservations/form.js`
- `src/js/admin/reservations/operation.js`
- `src/scss/admin/modules/reservations.scss`
- `src/scss/operation/_toolbar.scss`
- `src/scss/operation/_map-shell.scss`
- bundles correspondientes en `public/build/`.

Pruebas:

- `tests/ReservacionOcupacionProyectadaTest.php`
- `tests/ReservacionRevisionIntegralTest.php`
- `tests/ReservacionEtapa3Test.php`
- `tests/ReservacionEstabilizacionTest.php`
- `tests/js/reservation-form-state.test.js`
- `scripts/run-tests.php`

### Cobertura agregada

- ticket abierto en el horario actual;
- ticket consultado varias horas después;
- liberación antes y después del bloqueo;
- ticket todavía abierto al entrar al bloqueo;
- fecha futura e histórica sin traslado de tickets;
- mapa actual, proyectado, futuro, histórico y conflicto próximo;
- grupos 5, 6, 7 y 8 con parejas;
- grupo de 8 con fallback a trío;
- grupos 9, 10, 11 y 12 sólo con tríos;
- agrupación parcialmente ocupada;
- ticket y reservación incompatibles dentro de una agrupación;
- combinación con menor sobrante;
- consistencia de consulta pública, administrativa y mapa;
- revalidación final cuando la ocupación cambia después de consultar;
- contrato visual de capacidad proyectada y alerta operativa.

### Resultados finales

Comando:

```text
npm.cmd test
```

Resultado:

- Etapa 1: 37;
- Etapa 2: 80;
- Etapa 3: 156;
- estabilización: 59;
- revisión integral: 52;
- ocupación proyectada y grupos: 48;
- PHP total: 432;
- JavaScript: 13;
- total automatizado: 445 comprobaciones correctas.

Sintaxis:

```text
175 archivos PHP, 0 errores.
```

Compilación:

- `adminReservationFormJs`: correcta;
- `adminReservationOperationJs`: correcta;
- `adminModuleCss`: correcta;
- `operationCss`: correcta.

El build global volvió a alcanzar el archivo ajeno `public/build/js/admin/area.js` y Windows respondió `EPERM` porque estaba bloqueado por otro proceso. Los cuatro artefactos afectados por esta extensión sí se compilaron correctamente y sus marcas de tiempo fueron verificadas.

### Validación visual

En la landing local se verificó con navegador real:

1. una fecha futura carga 26 horarios para 5 personas;
2. el contador llega a 12 sin producir valores intermedios inválidos;
3. 12 personas conserva horarios disponibles cuando existe un trío autorizado;
4. una pulsación adicional entra al estado “Más de 12”;
5. fecha y horario se limpian y quedan deshabilitados;
6. el resumen informa atención directa y muestra el teléfono real `56 1481 8297` y WhatsApp;
7. continuar permanece bloqueado;
8. reducir restaura 12, oculta el aviso y rehabilita la fecha.

La pantalla operativa administrativa redirige a autenticación en la sesión local de prueba; sus estados visuales, capacidad y alertas se validaron mediante el contrato HTTP, pruebas de `MesaEstadoService` y comprobaciones JavaScript del bundle.

### Restricciones

- Sin migraciones ni cambios de esquema.
- Sin commit.
- Sin cierre automático de tickets.
- Sin modificar ni descartar cambios locales preexistentes.
