# Notificaciones de reservaciones

Fuente normativa del transporte de confirmaciones, recordatorios y cambios de
horario. PHP decide elegibilidad, estado, contacto, token, intento y resolución;
n8n valida el contrato y transporta el mensaje por Email o WhatsApp. n8n no
consulta MySQL ni cambia reservaciones, horarios, mesas, capacidad o seguimiento.

La [arquitectura](../arquitectura.md) define las capas, la
[configuración](../config.md) define los entornos y sus variables, y
[n8n/README](../../n8n/README.md) con su [receta Compose](../../n8n/deploy/README.md)
describe configuración y despliegue del transporte.

## Workflows y frontera

Los flujos de confirmación, recordatorio y cambio de horario son independientes.
Cada uno conserva Email, WhatsApp Text y WhatsApp Template en su propio artefacto;
no usa subworkflow compartido ni transfiere estado de ejecución entre ellos.

- `services/Reservations/` contiene los casos de uso y políticas del dominio.
- `services/Notifications/NotificationConfig.php` concentra la configuración
  transversal de transporte.
- `services/Integrations/N8nClient.php` realiza HTTP y valida la respuesta del
  transporte; no contiene reglas de reservaciones.

Las peticiones de transporte se envían después del commit y de liberar los
locks. Un fallo no revierte la reservación ni confirma una operación que PHP no
haya persistido.

## Confirmación y acceso OTP

PHP genera el código, invalida el anterior y persiste sólo su hash. Tras el
commit llama a n8n; la respuesta final acredita aceptación técnica del proveedor,
no entrega ni lectura. PHP acepta únicamente HTTP 200 con `ok: true`,
`accepted: true` y el canal solicitado (`email` o `whatsapp`). Un 202 anticipado,
timeout, fallo de conexión, respuesta inválida o canal distinto se trata como
`OTP_ENVIO_FALLIDO`; la retención y el hash se conservan.

`ConfirmationResendPolicy` permite hasta tres aceptaciones por ciclo (envío
inicial y dos reenvíos) y exige 60 segundos entre solicitudes, incluso si la
anterior falló. El acceso de contacto tiene un ciclo fijo de 15 minutos desde el
primer intento o hasta consumir el código; reenviar no reinicia el ciclo. Una
respuesta de red incierta consulta el estado, no emite otro código. No se
almacenan contacto ni OTP en `localStorage`.

Los campos públicos de estado (`send_count`, `last_sent_at`, `next_resend_at`,
`remaining_resends`, `retry_after_seconds`, `expires_at`, `can_send` y
`notification_delivery_status`) se derivan de filas persistidas. El cliente no
decide cupo ni cooldown.

## Recordatorios

La activación y hora del recordatorio del día anterior se administran en la
configuración PHP de reservaciones. El proceso consulta desde la hora elegida
durante el día anterior, por lo que una ejecución recuperada no depende de un
único minuto. Una clave por reserva raíz y fecha conserva una sola fuente
funcional entre reemplazos y ejecuciones repetidas.

Antes de enviar, n8n reclama la fuente e intento en
`POST /api/integraciones/n8n/reservaciones/recordatorios/reclamar`. Sólo
`claimed: true` autoriza el transporte. El claim no se reintenta ciegamente.
PHP conserva elegibilidad y deduplicación; un callback obsoleto no reemplaza el
intento vigente.

| Evidencia | Tratamiento |
| --- | --- |
| `pending` sin claim reciente | Esperar. |
| `pending` sin claim antiguo | Recuperar la misma fuente con otro intento tras comprobar elegibilidad. |
| `failed` marcado como reintentable, después del periodo de espera | Recuperar con otro intento. |
| `accepted` | No reenviar automáticamente. |
| Claim sin callback o resultado incierto | Revisar evidencia del proveedor; no inferir rechazo ni reenviar a ciegas. |
| Intentos técnicos agotados | Revisión operativa. |

Sólo el rechazo inequívoco se marca `retryable`; timeout, error sin código y
HTTP 5xx no prueban que el proveedor no aceptó el mensaje. No se promete
exactly-once sin idempotencia del proveedor.

## Cambios de horario y resultado

Una afectación admite `attempt 1` automático y un único `attempt 2` manual. La
preparación genera un nuevo token, guarda únicamente su hash e invalida el
acceso anterior. Contacto válido, tamaño del grupo, estado de afectación y
vigencia se revalidan antes de preparar o reenviar. La política del caso y las
acciones administrativas se describen en
[Reservaciones: cambios de horario y afectaciones](reservaciones.md#cambios-de-horario-y-afectaciones).

`notification_delivery_status` usa sólo:

- `pending`: aviso preparado o esperando resultado; un ACK 202 de n8n no es
  aceptación del proveedor;
- `accepted`: SMTP o Meta aceptó la solicitud, sin confirmar entrega o lectura;
- `failed`: el resultado no quedó aceptado según la evidencia disponible.

Los callbacks terminales son idempotentes y el intento viejo no altera el nuevo.
Un cambio de horario `pending` sin callback durante cinco minutos se reconcilia
como `failed`; esto no prueba rechazo y requiere revisar el caso antes de usar
el segundo intento. Ningún estado de transporte confirma, cancela o resuelve
una reservación. No existen estados `delivered` ni `read`.

## Contrato y autenticación

Contrato versión 1: `event`, `source_id`, `reservation_id`, `attempt`,
`contact.type/value`, `recipient.name`, `reservation.date/time/guests`, `data`
y `transport.whatsapp_mode`. Los canales canónicos son `email` y `whatsapp`;
PHP normaliza teléfonos. Para confirmación, `data` contiene propósito, código y
expiración; otros eventos llevan URL y expiración del acceso. n8n arma el asunto,
el texto, el template aprobado y sus componentes; PHP no acepta cuerpos internos
propios de n8n.

Templates: `reservation_confirmation_code`, `reservation_reminder` y
`reservation_schedule_change`. En entorno de prueba WhatsApp usa Text; en
producción, Template aprobado. Email conserva el mismo contrato funcional.

PHP → n8n usa `N8N_RESERVATIONS_WEBHOOK_SECRET` con header `X-N8N-Secret`.
n8n → PHP usa el secreto independiente `N8N_RESERVATIONS_CALLBACK_SECRET` con
`X-N8N-Callback-Secret`. Configurarlos iguales falla cerrado. Los callbacks se
reciben en `POST /api/integraciones/n8n/reservaciones/notificacion-resultado` y
contienen `event`, `source_id`, `attempt`, `channel` y estado `accepted|failed`;
sólo recordatorios pueden incluir `retryable`.

SMTP, Meta y Header Auth viven en credenciales de n8n; los valores no secretos
del workflow viven en un nodo Configuración. No se usan `$env`, `process.env`
ni `$vars` en Code. Los exports no contienen credenciales, `pinData`, contactos,
OTP, tokens de acceso ni payloads reales. No registrar secretos, códigos,
contactos, tokens ni payloads completos.

## Verificación local

`npm run test:notifications` ejecuta contratos de transporte, fronteras y
workflows; las suites de BD usan un runner aislado. `npm test` ejecuta además
las verificaciones PHP y JavaScript del repositorio. Estos checks locales no
sustituyen las pruebas de infraestructura indicadas en la
[receta de n8n](../../n8n/deploy/README.md).
