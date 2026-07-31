# Reservaciones — etapa funcional consolidada

## 1. Estado

- Fecha de revisión: 2026-07-30.
- Estado: implementación funcional integrada y validada por servicios, concurrencia y HTTP.
- Revisión visual manual: pendiente. El navegador integrado rechazó la navegación a `http://localhost` por su política de seguridad, por lo que no se declara cerrada la validación visual.
- Compilación: Sass y los bundles de reservaciones/POS compilaron correctamente. La tarea `npm run build` avanzó hasta `adminAreaJavascript` y se detuvo por `EPERM` al intentar sobrescribir `public/build/js/admin/area.js`, un bundle ajeno a esta etapa.
- Migraciones: no se realizaron.
- Commit: no se generó.

## 2. Alcance implementado

Se integraron las reglas de ocupación física, capacidad, creación pública y administrativa, advertencias del POS, reasignación hacia el mapa, exclusión de horarios vencidos y prevención de duplicados. La página administrativa y el modal del mapa siguen usando:

1. `POST /admin/reservations/create`.
2. `AdminReservacionController::store()`.
3. `ReservacionService::crearAdministrativa()`.
4. `AsignacionMesasService`.

El modal sólo cambia el transporte a JSON y la actualización posterior de lista, mapa, detalle y modo de asignación.

## 3. Diagnóstico previo

### 3.1 Ticket abierto mostrado como disponible

El esquema real usa:

- `tickets.estado`;
- `tickets.closed_at` — no existe `cerrado_at`;
- la relación N:M `ticket_mesas`;
- `tickets.hora_apertura`;
- `tickets.reservacion_id`, nulo para walk-ins.

El recorrido diagnosticado fue:

1. `TicketMesa::abiertosParaMapa()` sí devolvía el ticket abierto y todas sus relaciones de `ticket_mesas`.
2. `TicketMesa::ocupacionAbierta()` aplicaba una liberación temporal derivada de `hora_apertura + DURACION_SERVICIO_ESTIMADA_MINUTOS`.
3. Después de esa hora estimada, la mesa desaparecía de la ocupación usada por capacidad y asignación aunque el ticket siguiera abierto.
4. `MesaEstadoService` sí daba prioridad a un ticket que alcanzaba a recibir.
5. `table-state-adapter.js` conservaba `estado_base = ocupada`.
6. `MapaVisual` recibía el estado normalizado correctamente cuando la ocupación no se había perdido antes.

La pérdida, por tanto, no estaba en JavaScript. Estaba en el filtro temporal del modelo usado antes de la asignación/capacidad. Esto podía hacer que:

- `GET /api/reservaciones/disponibilidad` publicara un slot como disponible;
- una asignación pública o administrativa considerara la mesa;
- `ocupacion_por_reservacion` del endpoint operativo no reflejara la ocupación física para el horario evaluado.

El POS consultaba el ticket abierto directamente y por eso podía diferir del cálculo de reservaciones.

La reproducción controlada usó:

- reloj: `2026-11-30 12:00:00`, zona `America/Mexico_City`;
- ticket de prueba abierto desde `2026-12-06 08:00:00`;
- consulta a `2026-12-06 17:00:00`;
- dos pivotes en `ticket_mesas`;
- `estado = abierto`;
- `closed_at = NULL`.

Antes de la corrección, la estimación podía liberar la mesa. Después, ambas mesas continuaron ocupadas hasta el cierre real.

### 3.2 Errores de creación

#### Landing

- Ruta de retención: `POST /api/reservaciones/retencion`.
- Creación verificada: `POST /api/reservaciones/crear`.
- Transporte: JSON o `application/x-www-form-urlencoded`.
- Contacto: obligatorio y normalizado.
- `request_token`: obligatorio para idempotencia.
- OTP: obligatorio en el flujo sin sesión verificada.
- CSRF: estos endpoints de creación no tienen token CSRF en el contrato vigente.

Las pruebas heredadas de landing ya pasaban. La ausencia encontrada era la búsqueda de otra reservación activa del mismo contacto normalizado, fecha y hora cuando se usaban tokens diferentes.

#### Administración

- Ruta: `POST /admin/reservations/create`.
- Transporte HTML: formulario y redirección/respuesta 422.
- Sesión: administrativa.
- CSRF: el formulario vigente no incluye token CSRF.
- `request_token`: identificador de idempotencia.

Se localizaron dos causas:

1. `crearAdministrativa()` declaraba el contacto opcional, pero `crearReservacion()` no propagaba `contacto_requerido = false` a la validación.
2. El ActiveRecord genérico convertía columnas `DATETIME NULL` en cadenas vacías. Al probar una creación real apareció `Incorrect datetime value: '' for column 'hold_expires_at'`.

La persistencia administrativa ahora usa un `INSERT` preparado en `Reservacion::crearAdministrativa()`, mantiene los `NULL` reales y no modifica el esquema.

#### Modal del mapa

- Ruta: la misma `POST /admin/reservations/create`.
- Headers: `Accept: application/json` y `X-Requested-With: XMLHttpRequest`.
- Cuerpo: los mismos campos administrativos.
- Respuesta: JSON 200, 422 o 500.

No existía un segundo servicio. Se conservó esa arquitectura y se añadieron los metadatos JSON para contacto, capacidad y asignación manual.

### 3.3 Capacidad

La revisión encontró una consulta redundante de tickets en `DisponibilidadReservacionService`: `AsignacionMesasService` ya combinaba reservaciones y tickets, pero el servicio volvía a consultar `TicketMesa`.

La capacidad quedó centralizada en `DisponibilidadReservacionService::resumenHorario()`.

## 4. Fuente final de ocupación

La fuente compartida es:

1. `ReservacionMesa::obtenerOcupacionDelDia()` para estados activos y retenciones vigentes.
2. `TicketMesa::abiertosParaMapa()` para ocupación física.
3. `AsignacionMesasService::obtenerOcupacionParaHorario()` para unir ambas fuentes por ID único de mesa.
4. `DisponibilidadReservacionService::resumenHorario()` para filtrar mesas y calcular capacidad/selección.

La definición canónica de ticket abierto es:

```sql
ticket.estado = 'abierto' AND ticket.closed_at IS NULL
```

Todos los controladores y servicios que hacían consultas directas usan ahora `TicketMesa::condicionSqlAbierto()`.

Un ticket abierto:

- ocupa una o varias mesas;
- puede ser walk-in o estar ligado a una reservación;
- no se libera por el estado final de la reservación;
- no se libera por una estimación;
- se libera únicamente cuando deja de cumplir la condición canónica.

La precedencia visual se conserva:

1. no reservable;
2. ticket abierto;
3. reservación en curso;
4. bloqueo previo;
5. reservación próxima;
6. disponible.

## 5. Fórmula final de capacidad

```text
Mesas de dominio =
  activo = 1
  ∩ reservable = 1
  ∩ tipo = 'mesa'
  ∩ capacidad > 0

Mesas ocupadas =
  IDs únicos(
    reservaciones activas
    ∪ retenciones vigentes
    ∪ bloqueos aplicables
    ∪ tickets abiertos
    ∪ walk-ins
  )

Capacidad disponible =
  Σ capacidad(mesa de dominio cuyo ID no está ocupado)
```

La deduplicación se hace por `mesa_id`, por lo que una mesa presente simultáneamente en reservación y ticket se resta una sola vez.

En los fixtures:

- 11 mesas reservables × 4 lugares = 44;
- ticket abierto con mesas 1 y 2 = 36 disponibles;
- al cerrar el ticket vuelven a existir 44;
- los dos pivotes de `ticket_mesas` se conservaron.

### Elementos excluidos

Se excluyen por propiedades de dominio, no por nombre:

- Barra Blanca;
- Barra Roja;
- Barra Roja 2;
- Caja;
- Llevar;
- cualquier tipo distinto de `mesa`;
- filas no reservables, inactivas o con capacidad no positiva.

La prueba marcó deliberadamente como `reservable = 1` los elementos no mesa y la capacidad permaneció en 44.

## 6. Landing pública

Se mantienen:

- nombre y contacto obligatorios;
- tipo válido;
- normalización;
- OTP;
- máximo de 12 personas;
- máximo de tres mesas públicas y combinaciones autorizadas;
- horarios futuros según reloj del servidor;
- capacidad suficiente;
- máximo de reservaciones activas;
- `request_token`;
- asignación obligatoria de mesas.

Se añadió la búsqueda de duplicado dentro de la transacción y bajo los locks de contacto/fecha. Coinciden:

- tipo de contacto;
- contacto ya normalizado y almacenado en `reservaciones.contacto`;
- fecha;
- hora;
- condición compartida de ocupación activa.

Se excluyen estados finales y retenciones vencidas. La respuesta pública no revela ID ni datos de la reservación existente.

Casos probados:

- correo con mayúsculas/minúsculas;
- teléfono `+52 55 1234 5678` y `+52 (55) 1234-5678`;
- token distinto para el mismo horario;
- mismo contacto en otro horario;
- dos procesos simultáneos con tokens distintos.

## 7. Administración y mapa

Reglas finales:

- nombre obligatorio;
- contacto opcional;
- sin OTP;
- confirmación directa;
- hasta `ReservacionConfig::MAX_COMENSALES_ADMIN = 44`;
- asignación automática opcional;
- advertencia y confirmación explícita sin contacto;
- advertencia y confirmación explícita sin capacidad;
- creación sin mesas cuando el operador lo confirma;
- indicador `requiere_asignacion_manual`.

### Contacto opcional

El esquema real define `contacto_tipo` y `contacto` como `NOT NULL` y no contiene `contacto_normalizado`. Sin migración autorizada, la ausencia se representa como:

```text
contacto_tipo = 'email'
contacto = ''
```

No se valida formato, no se genera OTP, no queda pendiente de verificación y no se cuenta por contacto.

El backend exige:

```text
confirmar_sin_contacto=1
```

La interfaz reutiliza el modal administrativo existente y el texto aprobado.

### Capacidad insuficiente

El backend exige:

```text
permitir_capacidad_insuficiente=1
```

Sin el indicador no inserta la reservación. Con él:

- desactiva la asignación automática;
- crea la reservación confirmada;
- no crea pivotes inválidos;
- devuelve `requiresManualAssignment = true`.

El HTML reutiliza `admin-modal`. El mapa reutiliza el DOM y las clases de `operational-global-notice`; no se añadió otro sistema de alertas.

### Más de 12 personas

Se probó una creación administrativa de 13 personas:

- resultado exitoso;
- cuatro mesas de dominio;
- 16 lugares asignados;
- sin barras ni elementos especiales.

## 8. POS

### 31–60 minutos

`PuntoVentaReservacionService::abrirWalkIn()`:

1. bloquea fecha y mesas;
2. vuelve a consultar ticket y reservación próxima;
3. responde `REQUIERE_CONFIRMACION` sin el indicador;
4. sólo continúa con `confirmar_reservacion_proxima=1`.

El frontend reutiliza el modal `mmodal-cancel-confirm-overlay` y ya no usa `alert()` ni `confirm()` para este flujo.

Caso probado a 45 minutos:

- primera respuesta: HTTP 422;
- confirmación explícita: HTTP 200 y ticket creado.

### 30 minutos o menos

El bloqueo se evalúa antes de aceptar la confirmación. A exactamente 30 minutos:

- HTTP 409;
- `codigo = MESA_OCUPADA`;
- `bloqueo.bloqueada = true`;
- mensaje específico de reservación dentro de 30 minutos.

### Reasignar mesas

El botón dejó de estar deshabilitado y ahora navega a:

```text
/admin/reservations/operation
  ?fecha=YYYY-MM-DD
  &hora=HH:MM
  &reservation_id=N
  &mode=assign
```

El backend valida ID, fecha, hora, estado editable y modo. Una URL válida produjo:

```text
data-initial-reservation-id="40"
data-initial-operation-intent="assign"
data-initial-hora="12:45"
```

Una reservación inexistente cargó el mapa normalmente y mostró un aviso contextual.

## 9. Horarios vencidos

`ReservacionService::obtenerHorariosDisponiblesParaFecha()` filtra con el reloj y la zona del servidor. Los formularios público, administrativo y del mapa vuelven a pedir horarios cada 60 segundos mientras están activos.

El backend rechaza la manipulación. Prueba HTTP:

- reloj: `2026-11-30 12:00:00`;
- solicitud: `2026-11-30 11:30`;
- respuesta: HTTP 422;
- `fieldErrors.hora` presente;
- `nextValidTime = 13:00`.

Las consultas históricas del mapa conservan su modo de sólo lectura.

## 10. Estados finales e historial

`ReservacionConfig::condicionSqlOcupacionActiva()` excluye:

- cancelada;
- no_show;
- expirada;
- completada;
- pendiente_verificacion vencida.

Los cambios de estado no borran `reservacion_mesas`. El cierre de ticket no borra `ticket_mesas`. Si una reservación termina pero conserva un ticket abierto, `MesaEstadoService` mantiene la mesa ocupada por la precedencia del ticket.

## 11. Contratos HTTP verificados

Todas las mutaciones exitosas siguientes se ejecutaron contra `casa_pestalozzi_etapa3_test`. Antes del servidor aislado se hicieron dos intentos contra la configuración por defecto; ambos terminaron en 422 antes de insertar. No hubo una mutación exitosa sobre la base principal.

| Flujo | Solicitud | Resultado real |
|---|---|---|
| Horarios | `GET /api/reservation-schedules?fecha=2026-11-30` | 200; sólo slots posteriores a las 12:00 |
| Landing duplicada | `POST /api/reservaciones/retencion`, correo equivalente, token nuevo | 422 `RESERVACION_DUPLICADA` |
| Mapa/admin sin contacto | `POST /admin/reservations/create`, headers JSON, sin indicador | 422 `REQUIERE_CONFIRMACION_SIN_CONTACTO` |
| Mapa/admin sin contacto confirmado | misma solicitud + `confirmar_sin_contacto=1` | 200; `withoutContact=true`, mesa asignada |
| Capacidad insuficiente | 44 personas, capacidad real 20 | 422 `REQUIERE_CONFIRMACION_CAPACIDAD` |
| Capacidad confirmada | indicador + asignación automática desactivada | 200; sin mesas, asignación manual |
| Horario pasado | hoy a las 11:30 | 422; error de hora y siguiente slot |
| POS 45 minutos | abrir walk-in sin indicador | 422 `REQUIERE_CONFIRMACION` |
| POS 45 confirmado | indicador explícito | 200; ticket creado |
| POS 30 minutos | indicador incluido | 409; bloqueo no anulable |
| Reasignación válida | `GET ...reservation_id=40&mode=assign` | intención e ID presentes en HTML |
| Reasignación inválida | ID inexistente | 200; mapa normal + aviso contextual |

Headers usados por el modal:

```http
Accept: application/json
X-Requested-With: XMLHttpRequest
Content-Type: application/x-www-form-urlencoded
```

El POS real envía JSON; el controlador acepta también formulario y ambos transportes delegan al mismo servicio.

## 12. Datos DML y pruebas del 30/11/2026

La suite crea y elimina `casa_pestalozzi_etapa3_test` después de verificar:

```sql
SELECT DATABASE()
```

Casos cubiertos:

- landing válida, OTP e idempotencia;
- landing sin capacidad;
- duplicado normalizado;
- mismo contacto en otro horario;
- límite de 12;
- administración con y sin contacto;
- confirmación sin contacto;
- 13 personas con capacidad;
- creación voluntaria sin mesas;
- capacidad insuficiente confirmada;
- ticket abierto de una y varias mesas;
- walk-in;
- ticket abierto más allá de su estimación;
- ticket cerrado;
- reservación final sin ticket;
- reservación final con ticket abierto;
- horario pasado y futuro;
- advertencia a 45 minutos;
- bloqueo a 30 minutos;
- carreras de último cupo, duplicado, ticket, inicio, cierre, cancelación y no-show.

## 13. Resultados técnicos

### Automatización

Comando:

```text
php scripts/run-tests.php
```

Resultado:

- Etapa 1: 37 comprobaciones.
- Etapa 2: 80 comprobaciones.
- Etapa 3 ampliada: 129 comprobaciones.
- Total: 246 comprobaciones, todas correctas.

Los dos mensajes `e2 forced assignment failure` y `e2 forced otp failure` son fallos deliberados de triggers para comprobar rollback.

### Sintaxis y consistencia

- `php -l` en controladores, modelos, servicios, vistas y pruebas: correcto.
- `node --check` en los JavaScript modificados del módulo: correcto.
- `git diff --check`: correcto; sólo avisos de normalización LF/CRLF.
- Sass de aplicación, administración y operación: correcto.
- Bundles `adminMapJs`, `adminReservationFormJs` y `adminReservationOperationJs`: correctos.
- Búsqueda de condiciones SQL abiertas duplicadas: sólo permanece la definición canónica.
- Búsqueda de sumas de capacidad: la disponibilidad usa la fuente compartida.

### Concurrencia

Se ejecutaron procesos PHP independientes con conexiones MySQL reales y barrera simultánea para:

- último cupo público;
- límite por contacto;
- duplicado público con tokens distintos;
- dos tickets en la misma mesa;
- doble comienzo y doble cierre;
- ticket contra reservación;
- cancelar/no-show contra comenzar;
- cambio de horario contra creación.

El duplicado simultáneo dejó una sola fila, un éxito y un rechazo `RESERVACION_DUPLICADA`.

## 14. Código residual retirado

- liberación de tickets por duración estimada;
- segunda consulta de tickets dentro de disponibilidad;
- condiciones SQL locales y divergentes de ticket abierto;
- parámetro sin uso para excluir una reservación de la ocupación física del ticket;
- placeholder deshabilitado de “Reasignar mesas”;
- confirmación nativa del navegador en la apertura de ticket próxima;
- confirmación nativa del navegador al cancelar desde el modal POS;
- falta de propagación de `contacto_requerido`;
- inserción administrativa mediante ActiveRecord incapaz de preservar `NULL`.

No se eliminaron funciones o estilos sin comprobar primero sus usos en landing, administración, mapa, POS, disponibilidad, asignación y pruebas.

## 15. Archivos principales

Backend:

- `models/Mesa.php`
- `models/Reservacion.php`
- `models/TicketMesa.php`
- `services/AsignacionMesasService.php`
- `services/DisponibilidadReservacionService.php`
- `services/PuntoVentaReservacionService.php`
- `services/ReservacionPublicaService.php`
- `services/ReservacionService.php`
- `controllers/AdminReservacionController.php`
- `controllers/PuntoVentaController.php`
- `controllers/ReservacionOperacionController.php`

Interfaz:

- `views/admin/reservations/_form.php`
- `views/operation/reservations/index.php`
- `src/js/admin/reservations/form.js`
- `src/js/admin/reservations/operation.js`
- `src/js/modules/form.js`
- `src/js/modules/punto-de-venta.js`
- `src/scss/operation/_create-modal.scss`
- `src/scss/operation/_feedback.scss`
- `src/scss/punto-de-venta/_punto-de-venta.scss`
- bundles compilados correspondientes bajo `public/build/`.

Pruebas:

- `tests/ReservacionEtapa3Test.php`
- `tests/ReservacionEtapa3ConcurrencyWorker.php`

## 16. Limitaciones y pendientes reales

1. Falta revisar visualmente desktop y móvil en un navegador local. La conexión disponible bloqueó expresamente `localhost`; no se sustituyó por otro mecanismo.
2. Falta revisar consola y panel de red en navegador. Los contratos se verificaron directamente por HTTP, pero eso no sustituye la inspección visual.
3. `npm run build` no pudo sobrescribir `public/build/js/admin/area.js` por `EPERM`. Las tareas del módulo sí compilaron y ese archivo no pertenece al alcance funcional.
4. El esquema no admite `NULL` para contacto administrativo. Se documentó y probó el valor vacío sin migración.
5. Las rutas de creación actuales no implementan CSRF por formulario. No se añadió una solución parcial sólo para esta etapa; la sesión administrativa, OTP público y `request_token` continúan siendo los controles vigentes.
6. Quedaron artefactos temporales locales de la prueba HTTP (`.http-admin.cookies`, `.http-staff.cookies` y `.php-test-sessions/`) porque el entorno rechazó su eliminación. No forman parte del código ni están versionados.

La revisión final debe completar los puntos 1–3 antes de considerar la etapa visualmente cerrada.
