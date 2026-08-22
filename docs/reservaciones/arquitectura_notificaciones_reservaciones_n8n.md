# Arquitectura de comunicaciones de reservaciones con n8n

## Alcance y autoridades

Este documento es el contrato normativo de las comunicaciones operativas de
reservaciones. La aplicación PHP y MySQL conserva toda la autoridad de dominio;
n8n sólo acepta trabajo, selecciona el canal configurado y reporta el resultado
del transporte.

La integración inicial admite dos eventos con `schema_version = 1`:

- `reservation.schedule_change`, para una reservación afectada por un cambio
  del horario de operación;
- `reservation.reminder_next_day`, para el recordatorio del día anterior.

El OTP de verificación de contacto mantiene un contrato y un proveedor
separados. No forma parte del workflow descrito aquí.

## Frontera de dominio

PHP decide y persiste:

- la elegibilidad de la reservación y del contacto;
- las reglas de modificación y cancelación;
- capacidad, disponibilidad, agrupación y asignación de mesas;
- estados canónicos de reservación;
- impactos de horario y su resolución;
- configuración, deduplicación, intentos y vigencia del acceso;
- generación e invalidación de tokens;
- estado del transporte y reconciliación de fallos.

n8n no puede cambiar una reservación, resolver un impacto, calcular capacidad,
crear intentos ni decidir si un acceso sigue vigente. Tampoco recibe notas,
mesas, capacidad, tickets, datos POS u OTP.

Los estados `pending`, `accepted`, `delivered` y `failed` pertenecen al
transporte. Nunca se agregan a `reservaciones.estado`. En una afectación,
`estado = notificacion_preparada` y
`notification_delivery_status = delivered` significa que el aviso llegó, pero
la persona todavía no resolvió el caso.

## Componentes persistentes

### Configuración

`configuracion_reservaciones` es una tabla de fila única (`id = 1`) con:

- `recordatorio_dia_anterior_activo`, desactivado por omisión;
- `hora_recordatorio`, `18:00:00` por omisión;
- usuario y fecha de actualización.

Aplicar la migración no habilita ni envía recordatorios.

### Afectaciones de horario

`horario_impacto_reservaciones` conserva el estado de dominio existente y añade
`notification_delivery_status` y `notification_delivery_updated_at`. Sus
intentos siguen regidos por los límites y cooldown canónicos de afectaciones.

La creación de impactos y el guardado de contacto se confirman antes de llamar
al proveedor. Mientras el dispatcher no acepte el aviso, el caso permanece
accionable. Una falla externa nunca revierte el cambio de horario ni la
reservación.

### Recordatorios del día anterior

`reservacion_recordatorios` registra una preparación por tipo, reservación raíz
y fecha de la reservación. No duplica nombre, correo ni teléfono. Su clave de
deduplicación tiene la forma:

```text
dia_anterior|<reservacion_raiz_id>|<YYYY-MM-DD>
```

La raíz se obtiene siguiendo `reemplaza_reservacion_id` hasta la reservación
original, con detección de ciclos y un límite defensivo de profundidad. Cambiar
sólo la hora no crea otro recordatorio; mover la reservación a otra fecha sí
permite preparar el correspondiente a ese nuevo día.

## Acceso temporal de gestión

Un servicio compartido genera 32 bytes aleatorios en hexadecimal y persiste
únicamente `sha256(token)`. La URL canónica es:

```text
RESERVATION_PUBLIC_BASE_URL/reservaciones/gestionar?access=<token>
```

El token plano sólo vive en memoria durante la preparación y entrega. No se
registra en logs, respuestas administrativas ordinarias ni archivos
versionados.

La sesión temporal contiene exclusivamente:

- `source_type`;
- `source_id`;
- `reservation_id`;
- `expires_at`;
- `csrf_token`.

Los tipos de fuente son `schedule_change` y `reminder_next_day`. El token se
resuelve primero contra `horario_impacto_reservaciones` y después contra
`reservacion_recordatorios`; los IDs enviados por el navegador nunca son
autoridad.

Cada apertura y cada acción revalida formato, hash, fuente, reservación,
expiración, invalidación, estado canónico y capacidades. El acceso puede seguir
siendo válido cuando modificar ya no está permitido pero cancelar sí. Para un
recordatorio de más de 12 personas, la modificación pública siempre queda
deshabilitada aunque la cancelación continúe disponible.

Modificar reutiliza el reemplazo transaccional canónico. Cancelar reutiliza la
cancelación pública canónica. Un éxito invalida el acceso y limpia la sesión;
un error recuperable conserva ambos. Sólo `schedule_change` resuelve y
reconcilia la afectación y cierra su elemento del buzón.

## Recordatorio programado

El Schedule Trigger consulta cada cinco minutos el endpoint PHP de preparación.
PHP usa `ReservacionConfig::ahora()` y su zona horaria. No calcula 24 horas
exactas:

- configuración desactivada: `due = false`;
- hora actual anterior a la configurada: `due = false`;
- desde la hora configurada: consulta reservaciones de mañana todavía no
  preparadas.

No existe una ventana de sólo cinco minutos. Así se recuperan caídas temporales
y reservaciones creadas tarde.

Son elegibles las reservaciones de mañana en estado `confirmada`, con contacto
`email` o `telefono` válido. Se excluyen otros estados y cualquier reservación
con una afectación de horario activa. La prioridad siempre es:

```text
reservation.schedule_change > reservation.reminder_next_day
```

Cada candidato se prepara en una transacción breve: lock, revalidación, dedup,
token y fila de recordatorio. La llamada externa ocurre después del commit. La
vigencia llega hasta la fecha/hora de la reservación más la tolerancia pública
de cancelación; cada acción aplica además su propio límite.

## Proveedor operativo

`OperationalNotificationProvider` expone un único envío de eventos de
reservaciones. La implementación `development` no hace transporte real. La
implementación `n8n` usa un cliente HTTP centralizado con JSON,
`X-N8N-Secret`, límites de conexión/lectura y logs redactados.

La selección depende exclusivamente de:

```text
RESERVATION_NOTIFICATION_PROVIDER=development|n8n
```

No se infiere de `APP_ENV`. Para n8n también son obligatorios
`N8N_WEBHOOK_RESERVATIONS_URL` y `N8N_SECRET`. Una configuración desconocida o
incompleta falla de forma segura.

HTTP `202` significa únicamente `accepted`; la entrega se confirma por callback.
El payload común contiene sólo:

- `source_id`, `reservation_id` y `attempt`;
- `contact_type` y `contact`;
- `name`, `reservation_date`, `reservation_time` y `guests`;
- `management_url` y `access_expires_at`.

## Workflow único

El archivo versionado es `n8n/reservaciones-comunicaciones.json` y el workflow
se llama `Reservaciones - comunicaciones`. Contiene:

- un Webhook Trigger `POST /webhook/reservaciones`;
- un Schedule Trigger cada cinco minutos;
- validación y normalización de ambos orígenes;
- una respuesta temprana HTTP `202` para el webhook;
- un único pipeline de notificaciones;
- selección por evento y por `contact_type`;
- construcción de los mensajes de cambio de horario y recordatorio;
- entrega por email o teléfono/WhatsApp;
- callback PHP `delivered` o `failed`.

El proceso de n8n necesita `RESERVATION_APP_BASE_URL`, `N8N_SECRET` y los
remitentes configurados para los nodos de email y teléfono. Estas variables no
sustituyen las credenciales SMTP/Twilio que deben seleccionarse en la interfaz.

El workflow no incluye IDs de credenciales inventados. Las credenciales se
seleccionan en la instancia. El export no contiene `pinData`, ejecuciones,
tokens, PII real ni credenciales.

## Callback e idempotencia

El callback autenticado con `X-N8N-Secret` acepta únicamente `delivered` o
`failed`.

Para `schedule_change`, se identifica `source_id + attempt`. Un callback de un
intento anterior se ignora. `delivered` actualiza sólo el transporte. `failed`
actualiza el transporte, invalida el acceso y vuelve accionable el caso en el
buzón; nunca cancela la reservación.

Para `reminder_next_day`, `source_id` identifica la fila deduplicada.
`delivered` conserva el acceso; `failed` lo invalida. Nunca se crea una
afectación ni un aviso administrativo por este evento.

Callbacks repetidos son idempotentes. Estados `pending` o `accepted` sin
callback durante más de cinco minutos se reconcilian a `failed` e invalidan el
acceso. No existe reenvío automático: las afectaciones conservan su acción
manual cuando las reglas lo permiten y los recordatorios ordinarios permanecen
deduplicados.

## Seguridad operacional y privacidad

Las rutas de preparación y callback no requieren una sesión de staff, pero sí
`X-N8N-Secret` validado con `hash_equals`; sus respuestas usan `no-store`.
Las acciones públicas usan CSRF y autorización ligada a una sola reservación.

No se mantienen transacciones abiertas durante llamadas externas. Fallos,
timeouts, DNS, respuestas 500 o JSON inválido no revierten datos de dominio ya
confirmados. Los logs nunca incluyen contacto, URL de gestión, token ni el body
completo.

La activación es deliberadamente manual: importar y revisar el workflow,
seleccionar credenciales, igualar el secreto en ambos lados, configurar la URL,
activar el workflow, cambiar el provider y sólo entonces habilitar el
recordatorio desde administración.
