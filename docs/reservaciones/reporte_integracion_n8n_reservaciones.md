# Reporte de integración de comunicaciones de reservaciones con n8n

## Identificación y estado

- Fecha y hora de cierre técnico: `2026-08-22 02:03:59 -06:00` (`America/Mexico_City`).
- Rama: `audit/reservaciones-refresh-proyeccion-publica`.
- HEAD inicial: `571f4c4386b948b1877815adcf5a0a98ce185848`.
- HEAD final funcional auditado, antes de agregar este reporte: `16f700153cd1b9398e451703d8d1af8061c5a893`.
- Estado general: **PARCIAL** únicamente por la configuración externa pendiente de n8n.
- Estado de credenciales externas: **PENDIENTE_CONFIGURACION_CREDENCIAL**.
- Estado del workflow: **WORKFLOW_CONSTRUIDO_NO_ACTIVADO**.

El código, la migración, las interfaces, el contrato del workflow y las pruebas
locales están terminados. No se declara `WORKFLOW_VALIDADO` porque la instancia
local de n8n exige iniciar sesión y no fue posible importar, conectar
credenciales ni activar el workflow desde la sesión disponible.

## Objetivo

Consolidar las comunicaciones operativas de reservaciones en un único pipeline
versionado para cambios de horario y recordatorios del día anterior, con
configuración administrativa segura, acceso temporal para el cliente,
modificación/cancelación mediante reglas canónicas, callbacks idempotentes y
separación estricta entre el estado del dominio y el estado del transporte.

## Estado inicial encontrado

- La rama local coincidía con su referencia remota y el árbol de trabajo estaba
  limpio en el HEAD inicial.
- Existía el flujo de afectaciones por cambio de horario, pero estaba acoplado a
  un acceso específico y no tenía un estado de transporte completo.
- No existían `configuracion_reservaciones`, `reservacion_recordatorios` ni la
  arquitectura normativa solicitada.
- No existía un workflow único importable para los dos eventos.
- n8n respondía `200` en `http://localhost:5678/healthz`, pero su interfaz
  redirigía al inicio de sesión.
- El shim global de `npm` apuntaba a un `npm-cli.js` inexistente. Las tareas se
  ejecutaron directamente con el runtime Node incluido en Codex.

## Decisiones arquitectónicas

- PHP conserva la autoridad sobre elegibilidad, capacidad, reemplazo,
  cancelación, deduplicación, tokens y estados persistidos.
- n8n recibe lotes ya preparados, construye el mensaje, selecciona el canal,
  entrega y devuelve un resultado mínimo.
- Las llamadas externas ocurren después del commit de base de datos y después de
  liberar locks.
- El estado de dominio `notificacion_preparada` no se sustituye por estados de
  transporte. El transporte usa `pending`, `accepted`, `delivered` y `failed`.
- Un fallo externo no revierte un cambio de horario ni cancela una reservación.
- Los recordatorios quedan desactivados por omisión y no se habilitan mediante la
  migración.
- Los tokens se generan con entropía criptográfica y sólo se almacena SHA-256.
  El token en claro existe únicamente al construir el aviso.

## Base de datos

Migración creada y aplicada en la base local:

`database/migrations/2026_08_22_reservaciones_comunicaciones_n8n.sql`

El DDL consolidado también se actualizó en `database/ddl.sql`.

### `configuracion_reservaciones`

- Fila única `id = 1`.
- `recordatorio_dia_anterior_activo`, `TINYINT(1)`, default `0`.
- `hora_recordatorio`, `TIME`, default `18:00:00`.
- `updated_by` y `updated_at` para auditoría.
- La prueba restauró la configuración y confirmó al cierre:
  `recordatorio_dia_anterior_activo = 0`, `hora_recordatorio = 18:00`.

### `reservacion_recordatorios`

- Referencias a reservación actual y reservación raíz.
- Tipo `dia_anterior`.
- `dedup_key` único con forma `dia_anterior|<raíz>|<fecha>`.
- `access_token_hash`, expiración e invalidación del acceso.
- Estado y fecha de actualización del transporte.
- No duplica nombre, correo ni teléfono.

### `horario_impacto_reservaciones`

Se agregaron:

- `notification_delivery_status`;
- `notification_delivery_updated_at`;
- índice por estado de transporte.

## Backend

### Servicios creados

- `ReservacionNotificacionConfigService`.
- `ReservationAccessTokenService`.
- `ReservationManagementAccessService` y
  `ReservationManagementAccessSession`.
- `ReservationReminderService`.
- `ReservationNotificationDispatcher`.
- `ReservationNotificationResultService`.
- `OperationalNotificationProvider`.
- `OperationalNotificationProviderFactory`.
- `DevelopmentOperationalNotificationProvider`.
- `N8nOperationalNotificationProvider`.
- `N8nNotificationClient`.

### Servicios modificados

- `HorarioOperacionImpactoService`: preparación, despacho poscommit,
  reconciliación y reenvío manual.
- `HorarioOperacionService`: despacho de impactos después de cada transacción.
- `ReservacionPublicaService`: modificación y cancelación temporal reutilizando
  capacidad, reemplazo, asignación de mesas y cancelación canónicos.
- `BuzonNotificacionesService`: reapertura accionable ante fallo de transporte.
- `ScheduleChangeAccessService` y `ScheduleChangeAccessSession`: wrappers de
  compatibilidad sobre el acceso genérico.
- `ReservacionErrorCatalog`: códigos de configuración, acceso, transporte y
  callback.

### Modelos

- Nuevos: `ConfiguracionReservaciones`, `ReservacionRecordatorio`.
- Modificado: `HorarioImpactoReservacion` con los campos de transporte.

### Controllers

- Nuevos: `ReservationManagementAccessController`,
  `N8nReservationsController`.
- Modificados: `ScheduleChangeAccessController`,
  `AdminConfigurationController`, `AdminBuzonController`.

### Rutas nuevas

- `GET /reservaciones/gestionar`.
- `GET|POST /api/reservaciones/gestionar/disponibilidad`.
- `POST /api/reservaciones/gestionar/modificar`.
- `POST /api/reservaciones/gestionar/cancelar`.
- `POST /api/integraciones/n8n/reservaciones/recordatorios/preparar`.
- `POST /api/integraciones/n8n/reservaciones/notificacion-resultado`.
- `GET|POST /admin/configuracion/reservaciones`.

### Rutas legacy preservadas

- `GET /reservaciones/cambio-horario`.
- `GET|POST /api/reservaciones/cambio-horario/disponibilidad`.
- `POST /api/reservaciones/cambio-horario/modificar`.
- `POST /api/reservaciones/cambio-horario/cancelar`.

Estas rutas delegan al flujo genérico; no mantienen una segunda implementación.

## Administración

- Nueva tarjeta “Reservaciones” en configuración.
- Switch para activar el recordatorio del día anterior.
- Hora configurable y deshabilitada visualmente cuando el switch está apagado.
- Validación de hora, CSRF, PRG y mensajes de éxito/error.
- El buzón distingue “Aviso preparado”, “Esperando respuesta”, “Aviso enviado.”
  y el fallo accionable “No pudimos enviar el aviso.”.

La validación visual cubrió tarjeta, formulario, éxito y error en cuatro tamaños,
16 casos sin desbordamientos. También confirmó la sincronización accesible del
switch y del campo de hora.

## Experiencia pública y acceso temporal

`/reservaciones/gestionar?access=<token>` resuelve el token contra afectaciones
de horario o recordatorios. La sesión guarda sólo el contexto autorizado y CSRF;
los IDs enviados por el navegador nunca son autoridad.

- `schedule_change` presenta “Cambio de horario” y “Elige un nuevo horario”.
- `reminder_next_day` presenta “Tu reservación es mañana” y “Gestiona tu
  reservación”.
- Modificar vuelve a evaluar la capacidad canónica y crea el reemplazo con su
  nueva asignación.
- Cancelar usa la cancelación pública canónica; repetir la misma cancelación es
  idempotente.
- Para más de 12 personas, modificar queda bloqueado y cancelar continúa
  disponible.
- Un éxito invalida el acceso y limpia la sesión; un error recuperable conserva
  ambos.
- Sólo `schedule_change` resuelve la afectación y cierra su elemento del buzón.

El modal de cancelación usa diálogo accesible, bloqueo de fondo, foco inicial,
trampa de foco, cierre con Escape, restauración del foco y prevención de doble
envío. El mensaje final es “Reservación cancelada / Tu reservación ha sido
cancelada.”.

La matriz visual pública cubrió 10 estados por 4 viewports, 40 casos sin
desbordamientos: ambos orígenes, grupo mayor a 12, permisos parciales, permisos
completos, expiración, éxitos de modificación/cancelación y modal.

## Flujos de comunicación

### `reservation.schedule_change`

1. El cambio de horario y sus afectaciones se confirman.
2. PHP prepara intento, token temporal y payload después del commit.
3. El provider envía el lote al webhook de n8n.
4. n8n responde temprano y entrega por el canal elegido.
5. El callback actualiza sólo el transporte del intento vigente.
6. `failed` invalida el acceso y devuelve el caso al estado accionable del
   buzón; nunca cancela la reservación.

Mensaje final:

- Asunto: “Necesitamos ajustar tu reservación”.
- Introducción: “Un cambio de horario operativo afecta tu reservación.”.
- Acción: “Elige un nuevo horario o cancela tu reservación aquí:”.

### `reservation.reminder_next_day`

1. El trigger consulta a PHP cada cinco minutos.
2. PHP sólo prepara lotes cuando la función está activa, ya llegó la hora y la
   reservación elegible ocurre al día siguiente.
3. Se excluyen canceladas, casos sin contacto y reservaciones con una afectación
   de horario activa.
4. La deduplicación se hace por raíz y fecha; un reemplazo de la misma raíz no
   duplica el recordatorio.
5. `failed` invalida el acceso; el recordatorio no crea buzón administrativo.

Mensaje final:

- Asunto: “Tu reservación es mañana”.
- Introducción: “Te esperamos mañana en Casa Pestalozzi.”.
- Acción: “Puedes revisar, modificar o cancelar tu reservación aquí:”.

## Provider y workflow n8n

- Workflow: `Reservaciones - comunicaciones`.
- Archivo: `n8n/reservaciones-comunicaciones.json`.
- Webhook configurado: `POST /webhook/reservaciones`.
- URL de aplicación: `N8N_WEBHOOK_RESERVATIONS_URL`.
- Autenticación: header `X-N8N-Secret` comparado con `N8N_SECRET`.
- Contrato: `schema_version = 1`, evento permitido y lote no vacío.
- Respuesta temprana: `403` para secreto incorrecto, `422` para contrato
  inválido y `202` para lote aceptado.
- Cadencia del schedule: cada cinco minutos.
- El JSON contiene 16 nodos y no contiene bloques `credentials` ni `pinData`.

### Nodos

1. Webhook reservaciones.
2. Validar secreto y contrato.
3. Responder temprano.
4. Cada cinco minutos.
5. Preparar recordatorios.
6. Normalizar notificaciones.
7. Switch event.
8. Construir mensaje.
9. Elegir canal.
10. Enviar email.
11. Enviar mensaje telefónico.
12. Email entregado.
13. Email fallido.
14. Teléfono entregado.
15. Teléfono fallido.
16. Registrar resultado.

### Credenciales y variables

Credenciales n8n pendientes:

- una credencial SMTP para el nodo `Enviar email`;
- una credencial Twilio para el nodo `Enviar mensaje telefónico`.

Variables del proceso n8n:

- `N8N_SECRET`;
- `RESERVATION_APP_BASE_URL`;
- `RESERVATION_EMAIL_FROM`;
- `RESERVATION_PHONE_FROM`.

Variables de la aplicación:

- `RESERVATION_NOTIFICATION_PROVIDER=development|n8n`;
- `N8N_WEBHOOK_RESERVATIONS_URL`;
- `N8N_SECRET`;
- `RESERVATION_PUBLIC_BASE_URL` para generar enlaces públicos absolutos.

El archivo real `includes/.env` no fue modificado; sólo se amplió
`includes/.env.example` con valores de ejemplo no secretos.

## Callback, idempotencia y fallos

El callback PHP acepta únicamente `delivered` o `failed` para un evento y fuente
válidos. Para cambios de horario también exige que `attempt` sea el vigente;
callbacks antiguos se ignoran. Repetir un callback no produce una segunda
transición.

Los intentos `accepted` sin callback durante más de cinco minutos se reconcilian
a `failed` e invalidan su acceso. No hay reenvío automático oculto. El cliente
HTTP trata timeout, conexión, HTTP no exitoso y JSON inválido como fallos
redactados, sin registrar payloads ni contactos.

## Privacidad y herramientas de desarrollo

- `reservacion_recordatorios` no almacena PII duplicada.
- No se persiste ningún token de acceso en claro.
- El workflow versionado no contiene contactos, credenciales ni datos fijados.
- Los logs de transporte no incluyen payload, contacto, URL de gestión ni token.
- Los callbacks llevan sólo evento, `source_id`, intento y resultado.
- El provider de desarrollo se conserva para entornos `development/testing` y
  no realiza entrega externa.
- El enlace de prueba del buzón permanece como herramienta de desarrollo y no
  modifica intentos ni estados de entrega.

## Pruebas y resultados

### Suite estática

La suite declarada en `package.json` se ejecutó directamente porque el shim de
`npm` local está roto:

- 25 scripts PHP: todos pasaron.
- 4 contratos JavaScript y 2 verificaciones `node --check`: todos pasaron.
- Total: **31/31**.

Incluye contratos de privacidad, permisos, capacidad, mapa, POS, buzón,
afectaciones, UX, configuración, provider n8n, HTTP 202/timeout/500/JSON inválido,
secreto, callbacks, rutas, esquema y seguridad del workflow.

### Runtime con base de datos

Se ejecutaron correctamente:

```text
php scripts/tests/run-reservaciones-reasignacion-db.php
php scripts/tests/run-punto-venta-cierre-db.php
php scripts/tests/run-reservaciones-comunicaciones-db.php
```

El runtime nuevo confirmó configuración persistida/recargada, respeto a la hora,
cuatro recordatorios preparados, deduplicación por raíz, exclusiones,
modificación con capacidad y asignación canónicas, cancelación exitosa e
idempotente, regla mayor a 12, expiración, aceptación, callbacks entregado/fallido
e idempotencia. Los fixtures se eliminaron (`0` reservaciones de comunicaciones
al cierre).

### Build y verificaciones técnicas

- Build de Gulp ejecutado directamente con el Node incluido: pasó. Un primer
  intento devolvió de forma intermitente `premature close`; la repetición
  inmediata completó todas las tareas.
- Advertencias no bloqueantes: API JS legacy de Dart Sass y `fs.Stats` de Node.
- Lint PHP final: **35/35** archivos modificados/nuevos.
- `git diff --check`: pasó.
- JSON n8n: parseado correctamente, nombre y 16 nodos confirmados.
- Auditoría de privacidad: sin credenciales embebidas, `pinData`, contactos
  literales, token en claro en esquema ni logs con valores sensibles.

### Validación visual

Viewports usados: `1440x900`, `1280x800`, `1024x768` y `390x844`.

- Público: **40/40** escenarios.
- Administración: **16/16** escenarios.
- Total: **56/56**, sin overflow horizontal.
- Teclado/modal: foco, Escape, bloqueo de fondo, restauración de foco y switch
  verificados.

## Commits creados

- `0d539feb96120ec73d39f6a78da2730dc1d6b259` — Implementa gestión y
  comunicaciones de reservaciones.
- `9ee664d124ce659db1e98d435fa031fb67b2ba56` — Versiona workflow único de
  reservaciones en n8n.
- `16f700153cd1b9398e451703d8d1af8061c5a893` — Amplía pruebas de
  comunicaciones de reservaciones.
- Commit documental final: contiene exclusivamente este reporte; su SHA no puede
  autocontenerse y se registra en la entrega final junto con el HEAD definitivo.

No se hizo push.

## Pendientes manuales reales

1. **PENDIENTE_CONFIGURACION_CREDENCIAL**: crear/seleccionar SMTP y Twilio en
   n8n.
2. **WORKFLOW_CONSTRUIDO_NO_ACTIVADO**: iniciar sesión, importar el JSON y activar
   el workflow.
3. Verificar entrega real con cuentas de prueba controladas y observar callbacks
   antes de habilitar recordatorios.

No quedan pendientes de implementación local conocidos.

## Activación segura

1. Iniciar sesión en la instancia n8n de `http://localhost:5678`.
2. Importar `n8n/reservaciones-comunicaciones.json` y mantenerlo inactivo.
3. Asignar la credencial SMTP a `Enviar email` y la credencial Twilio a `Enviar
   mensaje telefónico`.
4. Configurar en el proceso n8n `N8N_SECRET`, `RESERVATION_APP_BASE_URL`,
   `RESERVATION_EMAIL_FROM` y `RESERVATION_PHONE_FROM`.
5. Configurar en la aplicación el mismo `N8N_SECRET`,
   `N8N_WEBHOOK_RESERVATIONS_URL`, `RESERVATION_PUBLIC_BASE_URL` y mantener
   `RESERVATION_NOTIFICATION_PROVIDER=development` durante la prueba manual.
6. Ejecutar manualmente el workflow con datos de prueba controlados y comprobar
   respuesta, entrega y callback `delivered`/`failed`.
7. Activar el workflow y cambiar la aplicación a
   `RESERVATION_NOTIFICATION_PROVIDER=n8n`.
8. Probar primero un aviso de cambio de horario de bajo riesgo y confirmar el
   estado de transporte en el buzón.
9. Sólo después, habilitar “Recordatorio del día anterior” desde
   `/admin/configuracion/reservaciones` y elegir su hora.

La activación nunca debe empezar habilitando el recordatorio en la base de datos.
