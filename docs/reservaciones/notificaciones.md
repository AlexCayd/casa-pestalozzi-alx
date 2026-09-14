# Notificaciones de reservaciones

Fuente de verdad funcional del módulo. Prevalece sobre el plan histórico de
migración. La [arquitectura general](../arquitectura.md) define responsabilidades;
[n8n/README](../../n8n/README.md) y su receta describen configuración y operación.
Las [afectaciones de horario](afectaciones_reservaciones_por_cambios_horario.md)
conservan sus reglas de negocio. Evidencia y límites en el
[reporte de implementación](implementacion_notificaciones.md).

## Arquitectura y aislamiento

PHP decide elegibilidad, contacto, OTP, acceso, intentos, deduplicación y estados.
n8n valida contratos y adapta el mensaje a SMTP o Meta. No consulta MySQL,
no modifica reservaciones y no decide mesas, capacidad, horarios ni resolución.

- `services/Integrations/N8nClient.php`: HTTP JSON, URL, autenticación,
  timeout y aceptación contractual; recibe configuración, no conoce reservaciones.
- `services/Reservations/Notifications/`: Contract, ConfirmationService,
  ConfirmationResendPolicy, ReminderService, ScheduleChangeNotificationService
  y NotificationResultService.
- `services/Notifications/NotificationConfig.php`: única lectura de entorno
  para transporte y herramientas de desarrollo.
- `ScheduleChangeNotificationService → HorarioOperacionImpactoService`:
  coordinación unidireccional. Agregar contacto guarda primero; envía post-commit.

No hay Provider, Factory ni Dispatcher activos del módulo. El transporte se
invoca después del COMMIT y de liberar locks. OTP y tokens sólo se persisten
como hashes; su valor plano existe en memoria para construir el mensaje.

| Workflow independiente | Entrada | Resultado |
|---|---|---|
| Reservaciones - Confirmación | POST /webhook/reservaciones/confirmacion | 200 tras aceptación proveedor; 502 fallo; 422 contrato inválido |
| Reservaciones - Recordatorio | Scheduler cada cinco minutos → PHP preparar → reclamar | Callback accepted o failed |
| Reservaciones - Cambio de horario | POST /webhook/reservaciones/cambio-horario | 202 ACK de trabajo, luego callback accepted o failed |

Cada JSON conserva Email, WhatsApp Text y WhatsApp Template propios. Está
prohibido fusionarlos, usar Execute Workflow, subworkflow de transporte o
compartir estado de ejecución. La duplicación es deliberada; contratos y tests
mantienen paridad. Compartir infraestructura/credenciales no implica dependencia
de ejecución entre los tres.

## Confirmación y OTP

PHP genera e invalida el código anterior, persiste sólo hash y confirma la
transacción antes de llamar n8n. El cliente espera hasta 25 segundos (conexión:
3 segundos). n8n responde sólo después del nodo de proveedor:

`{"ok":true,"accepted":true,"channel":"email"}` o canal `whatsapp`.

PHP exige HTTP 200, ambos booleanos true y canal coincidente. Devuelve
`CODIGO_CONFIRMACION_ENVIADO` y metadatos de reenvío. “Código enviado” significa
solicitud aceptada por SMTP/Meta, no entrega ni lectura. No existe callback OTP.

Ante timeout, fallo de conexión/proveedor, 4xx/5xx, JSON inválido, canal distinto
o 202 anticipado, PHP devuelve `OTP_ENVIO_FALLIDO`. Conserva retención y hash:
no deshace la reserva ni inventa éxito. Un timeout puede ocultar una aceptación
real; el sistema cuenta sólo aceptaciones acreditadas por una respuesta válida.

### Política backend

`ConfirmationResendPolicy` concentra máximo **3 aceptaciones por ciclo**
(inicial + 2 reenvíos) y cooldown **60 segundos entre solicitudes**, incluso
si falla el proveedor. El fallo no consume cupo de aceptación.
`ContactoOperacionLock` serializa solicitudes; una fila pending impide que
otra petición emita un código mientras llega el resultado. No hay HTTP en esa
transacción. El cierre accepted/failed se persiste después del transporte.

La reserva cuenta todas las verificaciones de su retención; no cambia por
refresh, cookie o sesión. Acceso a “Gestionar reservación” tiene ciclo fijo de
15 minutos desde el primer intento, o hasta consumir el código. Reenviar no
renueva el ciclo. Consumir o vencer permite un ciclo nuevo, respetando cooldown.
Cada reenvío válido invalida el OTP previo; su caducidad no se amplía por el JS.

Campos públicos derivados de filas persistidas:

| Campo | Significado |
|---|---|
| send_count | Filas del ciclo con accepted_at |
| last_sent_at | Última aceptación acreditada |
| next_resend_at | Fecha de próximo intento temporal; null si bloqueado sin habilitación prevista |
| remaining_resends | max(0, 3 - send_count) |
| retry_after_seconds | Segundos de protección temporal restantes |
| expires_at | Vencimiento del OTP vigente |
| can_send | Permiso actual del backend |
| notification_delivery_status | Resultado del intento más reciente |

Con tres aceptaciones: `LIMITE_REENVIOS_ALCANZADO`. Durante cooldown:
`REENVIO_EN_COOLDOWN`. Una caída de PHP antes de finalizar el registro deja
pending; las filas históricas sin evidencia quedan legacy. Ambos bloquean
el ciclo vivo en vez de inventar rechazo/aceptación.

El frontend muestra MM:SS, 2/1/0 disponibles y bloqueo inmediato de doble clic.
Sólo anuncia transiciones accesibles, no cada segundo. Ante resultado de red
incierto consulta `POST /api/reservaciones/contacto/estado` sin emitir otro OTP.
Tras refresh, volver a capturar el contacto recupera el estado: no reinicia cupo
ni guarda contacto/código en localStorage. La consulta requiere CSRF; si hay
retención, valida request_token e identidad, nunca un ID numérico proporcionado.

## Recordatorios y recuperación

La configuración de activación/hora pertenece a la BD PHP. El scheduler
consulta desde esa hora durante el día anterior, no sólo en un minuto exacto.
Se conserva elegibilidad y exclusión de afectaciones activas. La clave única
`dia_anterior|reservacion_raiz_id|fecha` mantiene una sola fila funcional aun
con reemplazos de reserva y ejecuciones duplicadas.

Antes de enviar, n8n solicita `/api/integraciones/n8n/reservaciones/recordatorios/reclamar`
con source_id, attempt y channel. Sólo un UPDATE atómico puede devolver
`claimed:true`; respuestas falsas, fallidas o perdidas no autorizan transporte.
No hay retry automático del claim ni de los nodos proveedor.

| Evidencia persistida | Acción |
|---|---|
| pending sin claim, menos de cinco minutos | Esperar |
| pending sin claim antiguo | Reconciliar y recuperar la misma fuente con intento nuevo |
| failed y retryable, tras cinco minutos | Recuperar con intento nuevo |
| accepted | Nunca reenviar automáticamente |
| claim sin callback, o fallo de resultado incierto | Revisión operativa; no inferir rechazo |
| Tres intentos técnicos agotados | Sin recuperación automática |

La recuperación rota token/hash y vuelve a comprobar elegibilidad; callbacks
de intentos anteriores son obsoletos. El workflow marca retryable sólo ante
rechazo inequívoco (HTTP 4xx salvo 408, o rechazo SMTP explícito); errores sin
código, timeout y 5xx no prueban que no hubo envío. Esta política prioriza evitar
duplicados sobre reintentar resultados inciertos.

No se promete exactly-once entre dos sistemas sin idempotencia de proveedor.
Para pendientes reclamados, el operador revisa resultado por fuente/intento
sin copiar payloads ni secretos. Con evidencia confirma el callback faltante;
no libera claims ni reenvía a ciegas. La ausencia de evidencia mantiene revisión.
No se creó una herramienta administrativa que autorice ese reenvío incierto.

## Cambio de horario y estados

Intento 1 automático, intento 2 manual; intento mayor a 2 bloqueado.
La persistencia y liberación de locks preceden al HTTP. Agregar contacto no
crea circularidad. Se conservan token temporal y resolución de afectaciones.

`notification_delivery_status` usa sólo:

- `pending`: preparado o esperando resultado; un 202 no acredita proveedor.
- `accepted`: SMTP/Meta aceptó el intento, no entrega ni lectura.
- `failed`: envío no confirmado/rechazado según la evidencia disponible.

El resultado de dispatch horario identifica el ACK con `accepted_by:n8n`;
no cambia pending a accepted. Un callback rápido no puede ser sobrescrito por
ese ACK ni por un fallo posterior del cliente HTTP. Callbacks terminales son
idempotentes; intento obsoleto no cambia el vigente.

Un pending de cambio de horario sin callback durante cinco minutos pasa a
failed y deja seguimiento manual conforme a la política existente. Antes de
reenviar un resultado incierto se revisa evidencia. Accepted no vence por
falta de otro callback. El buzón dice “Proveedor aceptó el envío”.
No existen estados delivered/read porque no hay webhooks que los acrediten.
Ningún estado de transporte confirma, cancela ni resuelve una reservación.

## Contrato, autenticación y entornos

Contrato versión 1: event, source_id, reservation_id, attempt, contact.type/value,
recipient.name, reservation.date/time/guests, data y transport.whatsapp_mode.
Canales canónicos email/whatsapp (telefono se normaliza en PHP).
Datos permitidos: confirmación purpose/confirmation_code/expires_at; los otros
eventos management_url/access_expires_at. Única reserva nula: confirmación
purpose=contact_access. Intento OTP vale 1 por cada fuente de verificación.

n8n arma subject/text, nombre de template y components. PHP no acepta claves
internas como bodyParameters. Templates: reservation_confirmation_code,
reservation_reminder y reservation_schedule_change. En test WhatsApp usa Text;
en production, Template aprobado. Email conserva el mismo contrato funcional.

Los callbacks van a `POST /api/integraciones/n8n/reservaciones/notificacion-resultado`:
event, source_id, attempt, channel, status (accepted/failed). Sólo recordatorio
usa el booleano opcional retryable; por omisión es false.

- PHP → n8n: N8N_RESERVATIONS_WEBHOOK_SECRET, header X-N8N-Secret,
  validado por Header Auth nativo antes del workflow.
- n8n → PHP: N8N_RESERVATIONS_CALLBACK_SECRET, X-N8N-Callback-Secret
  en preparar/reclamar/resultado. Configurar ambas claves iguales falla cerrado.
- SMTP/Meta y ambas Header Auth viven en n8n Credentials, nunca en exports.
- Campos no secretos viven en Configuración (Set) de cada workflow.
  No se usa $env, process.env ni $vars en Code. Mantener bloqueo de entorno.
- N8N_SECRET se conserva sólo para feedback/áreas, no para reservaciones.

APP_ENV admite development, test o production; ausente usa production,
inválido falla explícitamente. Development no llama proveedores: simula
aceptación y expone development_confirmation_code/enlaces de prueba.
Test ejecuta transporte TEST sin códigos públicos y admite RESERVATION_TEST_NOW.
Production usa transporte real, sin herramientas de desarrollo ni reloj fijo.
Ver [configuración PHP](../config.md).

No registrar OTP, contactos, tokens, secretos o payloads completos. Exports sin
credentials/pinData y ejecuciones sin guardado (incluidas fallidas/manuales).
Revisar también proxy y logs del proveedor; no usar Wait nodes con datos sensibles.

## Migración y validación

No ejecutar database/ddl.sql sobre una instalación existente: contiene DROP.
Respaldar, pausar entrada OTP/scheduler, drenar ejecuciones y aplicar UNA VEZ:

1. database/migrations/20260913_otp_resends.sql
2. database/migrations/20260913_notification_states.sql
3. database/migrations/20260913_reminder_recovery.sql

El estado antiguo accepted (ACK) pasa a pending; delivered antiguo pasa a accepted.
Las filas de recordatorio anteriores se consideran reclamadas por prudencia.
Una instalación nueva usa el DDL actualizado y NO vuelve a aplicar las migraciones.
Desplegar PHP y los tres JSON coordinados, asignar claves distintas y completar
Configuración. No hay compatibilidad con callbacks delivered del export viejo.

Pruebas locales reproducibles (sin tocar la BD configurada ni enviar mensajes):

`npm run test:notifications` reúne las verificaciones siguientes y la guardia
no-legacy/documentación. Si npm no está disponible, ejecutar directamente PHP/Node:

```text
php scripts/tests/run-notifications-isolated.php
php scripts/tests/run-notifications-isolated.php --migrations
php scripts/tests/run-reservaciones-confirmacion-transporte.php
php scripts/tests/run-reservaciones-notification-boundary.php
node scripts/tests/run-reservaciones-resend-ui.cjs
node scripts/tests/run-reservaciones-notification-workflows.cjs
```

El runner crea una BD con prefijo reservado, carga fixtures y la elimina al salir.
--migrations parte del DDL del baseline f274eda, prueba traducción histórica y
ejecuta las suites sobre el esquema migrado. --browser abre una vista aislada
development en 127.0.0.1:8087; Enter la cierra y limpia.

La validación local no sustituye E2E real: faltan importar los mismos JSON en
n8n TEST, asignar credenciales, probar SMTP/Meta y autenticación en ambos sentidos,
reiniciar contenedores/host, comprobar persistencia, backup/restore y rollback.
La [receta Compose](../../n8n/deploy/README.md) está preparada, no desplegada.
No declarar producción lista hasta completar y registrar esa evidencia.
