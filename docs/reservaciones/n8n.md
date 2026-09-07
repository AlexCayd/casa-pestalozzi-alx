# Comunicaciones de reservaciones con n8n

Referencia canónica de arquitectura, operación y mantenimiento. Los documentos de arquitectura anteriores son enlaces de compatibilidad.

## Objetivo y alcance

PHP/MySQL → OperationalNotificationProvider → n8n → SMTP / WhatsApp Business Cloud API → Meta (canal teléfono).
Sólo se soportan `reservation.confirmed`, `reservation.schedule_change` y `reservation.reminder_next_day`, con `schema_version = 1`. OTP pertenece a otro contrato. Esta etapa utiliza el número TEST de Meta; no incorpora el número oficial, chatbot, mensajes de marketing, respuestas automáticas ni receipts de Meta.

## Responsabilidades por capa

- PHP decide elegibilidad, reglas temporales, deduplicación, intentos, permisos y tokens. Prepara y confirma antes de transportar.
- MySQL mantiene reservaciones, índices UNIQUE, hashes, expiración y estados de transporte independientes del dominio.
- `OperationalNotificationProvider` conserva un contrato único; `development` simula aceptación sin enviar, `n8n` usa `N8nNotificationClient`.
- n8n valida el contrato, enruta, ejecuta SMTP o el nodo WhatsApp y reporta el resultado. No consulta MySQL ni decide capacidad, mesas, estados o permisos.
- Meta autentica el transporte WhatsApp y valida destinatario y template.

Nunca llamar n8n con transacciones MySQL o locks de reservaciones activos. Un error externo no cancela una reservación ni revierte su confirmación o cambio de horario. Los llamadores deben invocar la preparación sólo después de liberar sus transacciones y locks.

## Eventos y puntos de disparo

| Evento | Disparo | Persistencia |
|---|---|---|
| `reservation.confirmed` | Reservación confirmada con email o teléfono válido según ContactoService | `reservacion_recordatorios`, tipo `confirmacion` |
| `reservation.schedule_change` | Afectación notificable según HorarioOperacionImpactoService | `horario_impacto_reservaciones` |
| `reservation.reminder_next_day` | Mañana, confirmada, contacto válido, hora alcanzada y sin afectación activa | `reservacion_recordatorios`, tipo `dia_anterior` |

Confirmaciones: `ReservacionController::verificarContacto()` despacha después de `ReservacionPublicaService::confirmarRetencion()`; `confirmarModificacion()` después de `confirmarReemplazo()`; `ReservationManagementAccessController::modificar()` después de `crearReemplazoConAccesoTemporal()`. Usan `reservation.id` retornado, nunca el id de la reservación reemplazada.

`ReservacionController::crearVerificada()` también despacha después de `crearConfirmada()` cuando ya existe sesión verificada.

En administración, `ReservacionService::crearAdministrativa()` usa el `id` retornado después del commit y finally de `ReservacionAdministrativaService::crear()`. `ReservacionService::confirmar()` y `ejecutarAccionOperativa()` convergen en `cambiarEstado()`, cuyo punto post-commit cubre cualquier transición permitida a confirmada. Actualmente la transición operativa no permite saltarse el OTP de una retención pública. Los servicios transaccionales públicos internos no envían por sí mismos: otros futuros llamadores deben despachar al regresar, después de liberar locks.

`ReservationConfirmationService::preparar()` reconsulta y bloquea la reservación, valida contacto, verifica `confirmacion|<reservation_id>`, genera acceso, inserta pending y hace commit. `ReservationNotificationDispatcher::dispatchConfirmation()` llama al provider y persiste accepted/failed después. Los retries sobre la misma reservación no crean otro intento, incluso si falló. Un reemplazo tiene id nuevo y su propia confirmación. El UNIQUE de `dedup_key` respalda la idempotencia concurrente; no se promete exactly-once en el proveedor externo ni se debe reejecutar manualmente un envío ya aceptado.

## Recordatorio exclusivamente D-1

Se conserva `$fechaObjetivo = $ahora->modify('+1 day')`, deduplicación `dia_anterior|<raíz>|<fecha>` y Schedule Trigger cada cinco minutos. Sólo se configuran `recordatorio_dia_anterior_activo` y `hora_recordatorio` en `/admin/configuracion/reservaciones`. Defaults: apagado y 18:00. No existen días configurables ni recordatorios múltiples. Desde la hora configurada se preparan candidatos, sin limitar la recuperación a una ventana de cinco minutos.

Una afectación activa tiene prioridad sobre el recordatorio. La reconciliación se ejecuta antes de comprobar activo/hora; convierte pending/accepted de al menos cinco minutos en failed e invalida el acceso, también para confirmaciones con recordatorios apagados. Requiere el workflow programado activo. No reenvía automáticamente.

## Cambios de horario

Las reglas de intentos, cooldown, buzón, resolución manual y reconciliación siguen en [Afectaciones por cambios de horario](afectaciones_reservaciones_por_cambios_horario.md). Esta integración sólo cambia el transporte de teléfono; no altera esas reglas.

## Persistencia y actualización

`reservacion_recordatorios` conserva su nombre histórico para compatibilidad; admite recordatorios y confirmaciones. Contiene referencias a reservaciones, dedup, hash, expiración e información de transporte, sin duplicar nombre, contacto, fecha ni hora. `horario_impacto_reservaciones` conserva los intentos y acceso para afectaciones.

El esquema de instalaciones nuevas es `database/ddl.sql`. Antes de activar en una instalación existente, ejecutar:

```sql
ALTER TABLE reservacion_recordatorios
MODIFY COLUMN tipo
ENUM('dia_anterior', 'confirmacion')
NOT NULL DEFAULT 'dia_anterior';
```

No hay una migración nueva ni una carpeta artificial de migraciones. Respaldar y aplicar el ALTER en cada instalación que todavía tenga el ENUM anterior. No se modifica el estado de las reservaciones.

## Payload: schema_version 1

El webhook recibe un objeto con `schema_version: 1`, `event` (uno de los tres) y `notifications` (array no vacío). Cada notificación contiene exclusivamente:

| Campo | Forma |
|---|---|
| `source_id` | entero positivo, id del intento en la tabla del evento |
| `reservation_id` | entero positivo |
| `attempt` | 1 para confirmación/recordatorio; intento canónico para afectaciones |
| `contact_type` | `email` o `telefono` |
| `contact` | contacto normalizado por ContactoService |
| `name` | nombre del cliente |
| `reservation_date` | YYYY-MM-DD |
| `reservation_time` | HH:mm |
| `guests` | entero positivo |
| `management_url` | URL absoluta de gestión con acceso temporal |
| `access_expires_at` | fecha ISO 8601 con zona |

No salen notas, comentarios administrativos, mesas, tickets, capacidad, datos POS, OTP ni request_token. No registrar payloads completos. La respuesta 202 exige `{ "ok": true, "accepted": true }`; 403 indica secreto inválido y 422 contrato inválido. El endpoint programado devuelve `ok`, `due`, `event` cuando corresponde y `notifications`, sin sobre webhook.

## Acceso temporal

`/reservaciones/gestionar?access=<token>` intercambia un token aleatorio por sesión limitada a fuente, intento y reservación. Sólo SHA-256 se persiste en PHP/MySQL; el plano existe en memoria para transporte. Fuentes: `schedule_change`, `reminder_next_day` (tipo dia_anterior) y `confirmation` (tipo confirmacion). La sesión no representa autenticación general por contacto.

Confirmaciones y recordatorios expiran al inicio de la reservación más la tolerancia canónica de cancelación. La política temporal de `ReservacionPublicaService` decide modificación y cancelación; hasta 12 personas se permiten ambas cuando corresponde y, para más de 12, modificar es false y cancelar depende de esa misma regla pública. Se revalidan estado, fuente y vigencia en la transacción de cada acción, con CSRF. Una modificación/cancelación invalida su fuente; failed también invalida. Afectaciones conservan su vigencia y resolución propias.

## Estados y callbacks

`reservaciones.estado` es independiente de `notification_delivery_status`:

- pending: intento preparado y confirmado en MySQL.
- accepted: n8n aceptó trabajo mediante HTTP 202 (development lo simula).
- delivered: el nodo de transporte terminó exitosamente y PHP registró su callback. **No significa lectura ni entrega verificada por un webhook de Meta.**
- failed: rechazo, fallo de transporte o timeout reconciliado; acceso invalidado.

Callback: POST `/api/integraciones/n8n/reservaciones/notificacion-resultado`, con X-N8N-Secret y `{ "event": "reservation.confirmed", "source_id": 1, "attempt": 1, "status": "delivered" }`. Sólo delivered/failed; source_id positivo y attempt=1 para confirmaciones. El evento debe corresponder al tipo de la fila. Un source inexistente o de otro tipo devuelve 404; un contrato inválido 422. Estados terminales se conservan frente a callbacks repetidos o tardíos; la aceptación HTTP tardía no sobrescribe un callback rápido. Failed de confirmación no abre buzón ni afecta el dominio.

## Workflow único

`n8n/reservaciones-comunicaciones.json`, nombre `Reservaciones - comunicaciones`:

1. Webhook POST `/webhook/reservaciones`.
2. Validar X-N8N-Secret y contrato; responder temprano 202/403/422.
3. Schedule Trigger cada cinco minutos llama POST `/api/integraciones/n8n/reservaciones/recordatorios/preparar` con el mismo secreto.
4. Normalizar lote y Switch event con tres salidas.
5. Construir mensaje por elemento y preservar asociación del callback.
6. Elegir canal: email → SMTP; telefono → WhatsApp Business Cloud, Send Template.
7. Éxito/error construyen callback por elemento; Registrar resultado reintenta el callback hasta tres veces.

El nodo de envío no reintenta automáticamente. Desactivar guardado de ejecuciones exitosas, fallidas, manuales y progreso evita conservar contactos y enlaces de acceso en historial; revisar también logs del despliegue. Nunca exportar credentials, pinData, tokens, contactos o ejecuciones. La selección de credenciales es manual.

## Meta y credencial n8n

Crear/configurar una Meta Developer App con producto WhatsApp y su WhatsApp Business Account (WABA). En API Setup usar el número TEST y agregar/verificar destinatarios de prueba. Obtener Access Token y Business Account ID; guardarlos exclusivamente en una credencial **WhatsApp Business Cloud API** en n8n. El Phone Number ID del número TEST es distinto del WABA ID. Los tokens temporales pueden expirar y necesitan renovación manual.

Referencia: [credencial oficial n8n](https://docs.n8n.io/integrations/builtin/credentials/whatsapp/), [nodo WhatsApp](https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.whatsapp/). El formato del export usa `template = nombre|idioma` y componentes body del [nodo nativo](https://github.com/n8n-io/n8n/blob/master/packages/nodes-base/nodes/WhatsApp/MessagesDescription.ts).

## Templates Utility

Crear manualmente y obtener aprobación/disponibilidad en la WABA de los siguientes templates; seleccionar el idioma exacto aprobado. El export espera variables posicionales en el body, sin botones dinámicos.

| Nombre | Variables en orden |
|---|---|
| `reservation_confirmation` | name, reservation_date, reservation_time, guests, management_url |
| `reservation_reminder` | name, reservation_date, reservation_time, guests, management_url |
| `reservation_schedule_change` | name, reservation_date, reservation_time, management_url |

Textos propuestos (categoría Utility):

```text
reservation_confirmation
Hola {{1}}, tu reservación en Casa Pestalozzi está confirmada.
Fecha: {{2}}. Hora: {{3}}. Personas: {{4}}.
Puedes gestionar tu reservación aquí: {{5}}

reservation_reminder
Hola {{1}}, te recordamos que tu reservación en Casa Pestalozzi es mañana.
Fecha: {{2}}. Hora: {{3}}. Personas: {{4}}.
Puedes gestionar tu reservación aquí: {{5}}

reservation_schedule_change
Hola {{1}}, un cambio en nuestro horario afecta tu reservación.
Fecha: {{2}}. Hora: {{3}}.
Puedes elegir otro horario o cancelar tu reservación aquí: {{4}}
```

La disponibilidad de estos templates en el entorno TEST debe verificarse en Meta; no sustituir silenciosamente por hello_world, que no tiene el mismo contrato. La creación/aprobación puede requerir pasos adicionales de la cuenta.

## Configuración PHP

En `includes/.env`, sin secretos Meta:

```dotenv
RESERVATION_NOTIFICATION_PROVIDER=development
N8N_WEBHOOK_RESERVATIONS_URL=http://localhost:5678/webhook/reservaciones
N8N_SECRET=<secreto-independiente>
RESERVATION_PUBLIC_BASE_URL=<raíz-pública-absoluta>
```

Para pruebas reales, cambiar sólo el provider a `n8n` después de configurar el transporte. No se modifican `N8N_WEBHOOK_SUGERENCIAS_URL` ni `N8N_WEBHOOK_AREAS_MEJORA_URL`.

## Configuración n8n e importación

Variables del proceso:

```dotenv
N8N_SECRET=<mismo-secreto-que-PHP>
RESERVATION_APP_BASE_URL=<raíz-PHP-alcanzable-desde-n8n-sin-slash-final>
RESERVATION_EMAIL_FROM=<remitente-SMTP>
RESERVATION_WHATSAPP_PHONE_NUMBER_ID=<id-del-número-TEST>
RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE=es_MX
```

El idioma es un ejemplo: debe coincidir exactamente con los templates de la WABA. La instalación debe permitir `$env` a estos nodos; si su política lo bloquea, configurar referencias equivalentes seguras en la instancia, sin exportar secretos. Asignar SMTP a Enviar email y WhatsApp Business Cloud API a Enviar WhatsApp. Usar HTTPS accesible para gestión y comunicación remota; localhost sólo funciona entre procesos del mismo host. Desde contenedor usar una dirección de PHP accesible desde ese contenedor.

## Activación

Aplicar primero el ALTER si corresponde. Configurar Meta → configurar credencial n8n → importar workflow → asignar credenciales → probar nodo WhatsApp con destinatario TEST autorizado → probar callbacks delivered y failed → activar workflow → cambiar provider PHP a n8n → activar recordatorios en administración sólo si se desean. El workflow debe permanecer activo aunque D-1 esté apagado para reconciliar confirmaciones.

## Pruebas y revisión funcional

Ejecutar `npm test`, `npm run test:runtime`, `npm run build`, `php -l` sobre PHP modificado, `node --check` sobre JS modificado y `git diff --check`. Las suites de comunicaciones están en `scripts/tests/run-reservaciones-comunicaciones.php` y `scripts/tests/run-reservaciones-comunicaciones-db.php`; usan proveedores simulados sin mensajes externos. La suite DB requiere MySQL local y ENUM actualizado, crea fixtures y los elimina en finally.

Smoke real pendiente de credenciales: (A) landing → OTP → confirmar → mensaje TEST → callback, repetir petición y comprobar una sola fila; (B) creación administrativa confirmada después de commit; (C) reserva de mañana, activo y hora alcanzada → scheduler; (D) afectación controlada → template de horario → gestionar; (E) provocar fallo de transporte y comprobar failed, acceso inválido y reservación todavía confirmada. Hacerlo sólo con destinatarios TEST y datos controlados; no usar clientes ni cambiar horarios reales para probar. Verificar email también.

## Troubleshooting

| Síntoma | Comprobación / acción |
|---|---|
| 403 N8N_SECRET | Comparar secreto de PHP/n8n y header, sin imprimirlo en logs |
| Normalizar produce 0 | Revisar primero `Validar secreto y contrato`: `authorized` y `valid` deben ser `true`; si `authorized` es `false`, alinear `X-N8N-Secret` con `$env.N8N_SECRET` sin desactivar la validación |
| 422 contrato inválido | schema_version=1, evento permitido, fuente, intento y campos mínimos |
| Timeout PHP → n8n | URL, red, workflow activo y respuesta temprana; reconciliar, no reenviar a ciegas |
| Token Meta expirado | Renovar en credencial n8n y probar con destinatario autorizado |
| Destinatario no autorizado TEST | Agregar/verificar destinatario en API Setup |
| Template no encontrado | WABA, nombre canónico, idioma exacto y número TEST |
| Template no aprobado | Revisar estado en Meta; esperar aprobación antes de activar |
| Callback fallido | URL PHP accesible, secreto y source/attempt; timeout de cinco minutos invalida acceso |
| Provider incorrecto | development simula; seleccionar n8n únicamente al completar pruebas |
| Landing muestra confirmación sin fila `confirmacion` | Verificar que la petición OTP lleve `request_token` y que la respuesta sea `RESERVACION_CONFIRMADA` con `reservation.id`; `CONTACTO_VERIFICADO` sólo valida el contacto. Recargar el bundle versionado y comprobar el ENUM runtime |
| No llega recordatorio | Activo, hora y zona, mañana, confirmada, contacto, afectación y dedup raíz/fecha |
| No sale segunda confirmación | Es intencional: dedup por id incluye intentos fallidos |

## Paso futuro a producción

El número oficial no forma parte de esta etapa. Su incorporación requiere configurar/verificar la cuenta y número de producción, revisar credencial y permisos Meta, cambiar Phone Number ID, validar los tres templates e idioma y repetir pruebas de ambos canales y callbacks. No requiere cambiar reglas PHP ni introducir contacto_tipo=whatsapp. Receipts, marketing y conversación bidireccional son trabajos futuros independientes.
