# Arquitectura de notificaciones de reservaciones con n8n

## Estado del documento

Documento normativo para la primera integración de notificaciones automáticas del módulo de reservaciones con n8n.

Esta versión define:

- recordatorio del día anterior;
- aviso por cambio de horario que afecta una reservación;
- reutilización de un mismo workflow/webhook de reservaciones;
- acceso temporal para gestionar una reservación;
- modificación y cancelación desde ese acceso;
- configuración administrativa de la hora de envío de recordatorios;
- responsabilidades de PHP, MySQL y n8n.

La revisión de privacidad fina de los payloads y mensajes queda fuera del alcance de esta versión. Para esta etapa se permite incluir nombre del cliente y detalles básicos de la reservación en los mensajes.

---

# 1. Principios de arquitectura

La aplicación PHP y MySQL mantienen la autoridad sobre el dominio.

n8n se utiliza como capa de transporte y orquestación de mensajes. No decide reglas de reservación.

PHP decide:

- qué reservaciones son elegibles;
- qué reservaciones están afectadas por un cambio de horario;
- cuándo corresponde preparar un recordatorio;
- si el cliente puede modificar;
- si el cliente puede cancelar;
- si una reservación de más de 12 personas puede modificarse por autoservicio;
- vigencia del acceso;
- generación e invalidación de tokens;
- capacidad y disponibilidad;
- estados de reservación;
- deduplicación de recordatorios;
- resolución de afectaciones;
- estado del buzón administrativo.

n8n decide únicamente:

- qué plantilla utilizar según el evento;
- qué canal utilizar según `contact_type`;
- cómo entregar el mensaje al proveedor configurado;
- reportar el resultado del transporte.

n8n no debe consultar directamente las tablas del dominio para decidir qué enviar.

---

# 2. Un workflow de comunicaciones de reservaciones

Se utilizará un único workflow de n8n para las comunicaciones operativas de reservaciones.

Nombre sugerido:

```text
Reservaciones — Comunicaciones
```

El workflow tendrá dos entradas:

1. **Webhook Trigger**
   - recibe eventos enviados por PHP;
   - se utiliza para avisos por cambio de horario;
   - también puede reutilizarse para recordatorios generados desde PHP.

2. **Schedule Trigger**
   - se ejecuta periódicamente;
   - consulta a PHP si corresponde preparar el recordatorio del día siguiente;
   - la hora efectiva de envío no vive como regla de negocio en n8n;
   - PHP consulta la configuración administrativa y decide si el envío está pendiente.

Ambas entradas convergen en un mismo pipeline de normalización, selección de plantilla, canal y entrega.

Diagrama:

```text
                         RESERVACIONES — COMUNICACIONES

        ┌─────────────────────┐
        │ Webhook Trigger     │
        │ eventos desde PHP   │
        └──────────┬──────────┘
                   │
                   │
                   │
        ┌──────────▼──────────┐
        │ Normalizar evento   │
        └──────────┬──────────┘
                   │
                   │
                   │
        ┌──────────▼──────────┐
        │ Switch event        │
        └───────┬───────┬─────┘
                │       │
                │       │
    schedule_change     reminder_next_day
                │       │
                ▼       ▼
         plantilla     plantilla
          afectación   recordatorio
                │       │
                └───┬───┘
                    ▼
            Switch contact_type
               /           \
            email        telefono
               \           /
                \         /
                 ▼       ▼
                   SEND
                    │
                    ▼
                 CALLBACK
```

El `Schedule Trigger` entra al mismo pipeline después de pedir a PHP los recordatorios pendientes.

---

# 3. Webhook único de reservaciones

PHP utilizará un único webhook para las comunicaciones de reservaciones:

```env
N8N_WEBHOOK_RESERVATIONS_URL=http://localhost:5678/webhook/reservaciones
```

El evento determina la plantilla y el comportamiento.

Eventos iniciales:

```text
reservation.schedule_change
reservation.reminder_next_day
```

Contrato base:

```json
{
  "schema_version": 1,
  "event": "reservation.schedule_change",
  "notifications": []
}
```

o:

```json
{
  "schema_version": 1,
  "event": "reservation.reminder_next_day",
  "notifications": []
}
```

n8n debe rechazar eventos desconocidos.

---

# 4. Evento `reservation.schedule_change`

## 4.1 Cuándo se genera

Se genera cuando un cambio del horario efectivo del restaurante deja una reservación confirmada fuera del horario operativo.

El cambio de horario:

- no cancela la reservación;
- no cambia automáticamente su fecha u hora;
- no modifica sus mesas;
- no crea un estado nuevo en `reservaciones`;
- crea la afectación canónica mediante `horario_impactos` y `horario_impacto_reservaciones`.

Una llamada externa a n8n nunca debe ejecutarse dentro de la transacción que guarda el horario y sus afectaciones.

Orden obligatorio:

```text
guardar horario
+
crear impacto
+
crear filas afectadas
+
sincronizar buzón
↓
COMMIT
↓
preparar acceso
↓
enviar evento a n8n
```

Si n8n falla, el cambio de horario permanece guardado.

---

# 5. Mensaje por cambio de horario

El mensaje debe ser más visible que un recordatorio ordinario.

Debe explicar claramente que **un cambio en el horario de operación afecta la reservación**.

Debe incluir un emoji al inicio para aumentar la visibilidad del mensaje.

Emoji recomendado:

```text
⚠️
```

No utilizar múltiples emojis ni un tono alarmista.

## 5.1 Mensaje email

Asunto sugerido:

```text
⚠️ Cambio de horario en tu reservación — Casa Pestalozzi
```

Contenido base:

```text
⚠️ Hola {name},

Hicimos un cambio en nuestro horario de operación y este cambio afecta tu reservación:

{reservation_date}
{reservation_time}
{guests} personas

Tu reservación no ha sido cancelada.

Puedes elegir un nuevo horario o cancelar tu reservación desde el siguiente enlace:

{management_url}

Casa Pestalozzi
```

## 5.2 Mensaje teléfono / WhatsApp

```text
⚠️ Casa Pestalozzi

Hola {name}. Hicimos un cambio en nuestro horario de operación y afecta tu reservación del {reservation_date} a las {reservation_time}, para {guests} personas.

Tu reservación no ha sido cancelada.

Puedes modificarla o cancelarla aquí:
{management_url}
```

El mensaje debe destacar:

1. que existe un cambio de horario;
2. que afecta esa reservación;
3. que la reservación sigue confirmada;
4. que existe una acción disponible.

No usar frases que impliquen que la reservación ya fue cancelada.

---

# 6. Evento `reservation.reminder_next_day`

## 6.1 Significado

Es un recordatorio operativo enviado el día anterior a las reservaciones confirmadas del día siguiente.

No debe describirse internamente como “24 horas exactas antes”, porque todas las reservaciones del día siguiente se envían a una hora configurable común.

Nombre normativo:

```text
Recordatorio del día anterior
```

---

# 7. Hora configurable del recordatorio

La hora de envío pertenece a la configuración del restaurante, no al workflow de n8n.

Agregar en el panel administrativo de configuración de reservaciones una opción sencilla, coherente con el diseño actual del admin.

Sección sugerida:

```text
Recordatorios de reservaciones
```

Contenido:

```text
Recordatorio del día anterior

[✓] Enviar recordatorio automático

Hora de envío
[ 18:00 ]

Se enviará un recordatorio a las reservaciones confirmadas del día siguiente que tengan un contacto válido.
```

Reglas:

- utilizar los componentes visuales existentes del admin;
- no crear un panel visual distinto;
- usar el mismo patrón de labels, inputs, switches y ayudas del resto de configuración;
- `enabled` controla si se ejecutan recordatorios;
- la hora se almacena en formato `HH:MM`;
- utilizar la zona horaria configurada por la aplicación;
- no almacenar la regla principal únicamente en n8n;
- el cambio de hora desde admin debe surtir efecto sin editar el workflow.

Configuración lógica sugerida:

```text
reservation_reminder_enabled
reservation_reminder_send_time
```

La implementación puede reutilizar la infraestructura de configuración existente si ya existe una fuente canónica para parámetros de reservaciones.

No crear una segunda fuente de configuración si puede centralizarse en el servicio/configuración actual.

---

# 8. Schedule Trigger de n8n

El Schedule Trigger se ejecutará de forma periódica.

Cadencia sugerida:

```text
cada 5 minutos
```

No significa que se envíen recordatorios cada cinco minutos.

n8n consulta a PHP:

```text
POST /api/integraciones/n8n/reservaciones/recordatorios/preparar
```

Autenticación:

```text
X-N8N-Secret
```

PHP decide si corresponde preparar el envío según:

- `reservation_reminder_enabled`;
- `reservation_reminder_send_time`;
- hora actual de la aplicación;
- zona horaria;
- ejecuciones anteriores;
- fecha objetivo.

Respuesta si todavía no corresponde:

```json
{
  "ok": true,
  "due": false,
  "notifications": []
}
```

Respuesta cuando corresponde:

```json
{
  "ok": true,
  "due": true,
  "event": "reservation.reminder_next_day",
  "notifications": []
}
```

El endpoint debe ser idempotente.

---

# 9. Reservaciones elegibles para recordatorio

Sólo se prepara el recordatorio cuando la reservación:

- corresponde al día siguiente;
- está `confirmada`;
- tiene contacto válido;
- no está cancelada;
- no está completada;
- no está en no-show;
- no está reemplazada;
- no tiene un reemplazo confirmado que haya dejado obsoleta la original.

Una reservación sin contacto no produce mensaje.

Una reservación con afectación activa por cambio de horario no recibe el recordatorio ordinario mientras esa afectación siga pendiente.

Prioridad:

```text
schedule_change
>
reminder_next_day
```

Esto evita mensajes contradictorios.

---

# 10. Mensaje del recordatorio

El recordatorio debe ser breve y preciso.

## 10.1 Email

Asunto:

```text
Tu reservación es mañana — Casa Pestalozzi
```

Contenido:

```text
Hola {name},

Te esperamos mañana en Casa Pestalozzi.

{reservation_date}
{reservation_time}
{guests} personas

Puedes modificar o cancelar tu reservación aquí:

{management_url}

Casa Pestalozzi
```

## 10.2 Teléfono / WhatsApp

```text
Casa Pestalozzi

Hola {name}. Te esperamos mañana a las {reservation_time}, para {guests} personas.

Puedes modificar o cancelar tu reservación aquí:
{management_url}
```

No añadir explicaciones innecesarias.

---

# 11. Payload común de comunicaciones

Ambos eventos utilizan el mismo formato general.

Ejemplo:

```json
{
  "schema_version": 1,
  "event": "reservation.reminder_next_day",
  "notifications": [
    {
      "source_id": 142,
      "reservation_id": 341,
      "contact_type": "telefono",
      "contact": "+525512345678",
      "name": "María",
      "reservation_date": "2026-08-23",
      "reservation_time": "19:30",
      "guests": 4,
      "management_url": "https://example.com/reservaciones/gestionar?access=TOKEN",
      "access_expires_at": "2026-08-23T19:45:00-06:00"
    }
  ]
}
```

Para esta versión se permite incluir:

- nombre;
- tipo de contacto;
- contacto;
- fecha;
- hora;
- número de personas;
- URL temporal de gestión.

No enviar información que no participa en el mensaje, como:

- comentario administrativo;
- mesas;
- capacidad;
- tickets;
- información del POS;
- OTP;
- credenciales internas.

La revisión de privacidad de estos datos se realizará posteriormente.

---

# 12. Acceso temporal de gestión

El acceso temporal debe evolucionar desde “cambio de horario” a una superficie conceptual más general:

```text
Gestionar reservación
```

La misma interfaz puede tener dos contextos:

```text
schedule_change
reminder_next_day
```

El contexto modifica:

- título;
- mensaje introductorio;
- acciones visibles.

El token:

- se genera con entropía criptográfica;
- existe en texto plano sólo durante su entrega;
- se persiste únicamente como hash;
- no se guarda en logs;
- se intercambia por una sesión temporal;
- deja de permanecer en la URL después del intercambio inicial.

El acceso sólo autoriza la reservación asociada.

No debe convertirse en una sesión de contacto que permita acceder a otras reservaciones.

---

# 13. Gestión desde el enlace

La superficie de gestión puede permitir:

```text
Modificar reservación
Cancelar reservación
```

Las acciones dependen del contexto y de las reglas canónicas.

## 13.1 Reservaciones de hasta 12 personas

Para `<= 12`:

```text
Modificar: permitido si ReservacionPublicaService lo permite.
Cancelar: permitido si ReservacionPublicaService lo permite.
```

La modificación reutiliza el flujo canónico de reemplazo y validación.

No crear una lógica paralela de modificación.

## 13.2 Reservaciones de más de 12 personas

Para `> 12`:

```text
Modificar por autoservicio: no permitido.
Cancelar: permitido si la regla pública canónica de cancelación lo permite.
```

La interfaz debe explicar que para modificar un grupo grande debe contactar al restaurante.

El recordatorio puede seguir incluyendo el enlace de gestión para permitir cancelación.

---

# 14. Cancelación desde el acceso temporal

Agregar una acción de cancelación específica de la sesión temporal.

No simular una `ReservationClientSession` de contacto completo.

El backend debe autorizar:

```text
token/sesión temporal
+
reservation_id asociado
+
regla canónica de cancelación
```

La operación debe reutilizar la transición y reglas existentes de cancelación.

Después de una cancelación exitosa:

- la reservación pasa al estado canónico `cancelada`;
- el acceso temporal se invalida;
- cualquier recordatorio asociado deja de estar activo;
- si proviene de un cambio de horario, la afectación se resuelve y su aviso del buzón se cierra.

Pantalla resultante:

```text
RESERVACIÓN CANCELADA

Tu reservación ha sido cancelada.

Esperamos recibirte en otra ocasión.

Volver al inicio →
```

---

# 15. Modificación desde el acceso temporal

La modificación reutiliza el flujo canónico existente de reservación pública.

No actualizar directamente fecha, hora o comensales en la reservación original si el contrato vigente utiliza reemplazos.

La disponibilidad y capacidad se validan nuevamente en backend.

Después de una modificación exitosa:

- se aplica el flujo canónico de reemplazo;
- se invalida el acceso utilizado;
- si el origen era `schedule_change`, se resuelve la afectación correspondiente;
- si el origen era `reminder_next_day`, se finaliza el recordatorio asociado.

---

# 16. Persistencia de recordatorios

No utilizar `horario_impacto_reservaciones` para los recordatorios del día anterior.

Un recordatorio no representa una afectación de horario.

Crear una estructura separada.

Nombre sugerido:

```text
reservacion_recordatorios
```

Campos mínimos sugeridos:

```text
id
reservacion_id
tipo
dedup_key

access_token_hash
access_expires_at
access_invalidated_at

notification_delivery_status
notification_delivery_updated_at

created_at
updated_at
```

`tipo` inicial:

```text
dia_anterior
```

`dedup_key` debe ser único.

Ejemplo:

```text
recordatorio_dia_anterior|341|2026-08-23|19:30
```

La tabla no duplica nombre, correo o teléfono.

La información de contacto continúa perteneciendo a la reservación.

---

# 17. Idempotencia del recordatorio

El Schedule Trigger puede consultar varias veces durante la ventana de envío.

PHP debe garantizar que la misma reservación no produzca varios recordatorios para la misma combinación de fecha/hora.

La deduplicación pertenece a PHP/BD.

Regla:

```text
UNIQUE(dedup_key)
```

Si el recordatorio ya fue preparado, una ejecución posterior no genera otro mensaje.

Cambiar la hora configurada después de que el recordatorio ya fue enviado no debe volver a enviarlo.

---

# 18. Vigencia del acceso del recordatorio

El recordatorio del día anterior no debe utilizar obligatoriamente el TTL corto de 60 minutos del aviso por cambio de horario.

El enlace puede permanecer vigente hasta que la reservación deje de admitir cualquier acción pública.

La vigencia técnica puede establecerse hasta:

```text
hora_reservacion + tolerancia de cancelación pública
```

Cada acción debe revalidarse independientemente.

Ejemplo conceptual:

```text
antes del límite de modificación:
Modificar ✅
Cancelar ✅

después del límite de modificación,
pero antes del límite de cancelación:
Modificar ❌
Cancelar ✅

después del límite de cancelación:
Modificar ❌
Cancelar ❌
```

No duplicar minutos ni reglas en el nuevo servicio.

Consultar las reglas canónicas de `ReservacionPublicaService` y `ReservacionConfig`.

---

# 19. Acceso por cambio de horario

El acceso generado por `reservation.schedule_change` conserva sus reglas específicas.

Debe seguir vinculado a la afectación real.

No crear una afectación ficticia para reutilizar el recordatorio.

La superficie visual puede compartirse, pero las fuentes de autoridad siguen separadas:

```text
schedule_change
→ horario_impacto_reservaciones

reminder_next_day
→ reservacion_recordatorios
```

---

# 20. Resultado del transporte

Los dos eventos utilizan el mismo mecanismo de transporte y callback.

Estados de transporte sugeridos:

```text
pending
accepted
delivered
failed
```

Estos estados no pertenecen a `reservaciones.estado`.

Nunca agregar estados como:

```text
notificada
mensaje_enviado
recordada
```

a la reservación.

El callback debe identificar:

- evento;
- `source_id`;
- reservación;
- intento o versión cuando corresponda.

Debe ignorar callbacks obsoletos.

---

# 21. Callback n8n → PHP

Ruta sugerida:

```text
POST /api/integraciones/n8n/reservaciones/notificacion-resultado
```

Autenticación:

```text
X-N8N-Secret
```

Payload:

```json
{
  "event": "reservation.reminder_next_day",
  "source_id": 142,
  "status": "delivered"
}
```

o:

```json
{
  "event": "reservation.schedule_change",
  "source_id": 85,
  "attempt": 1,
  "status": "failed"
}
```

El callback no debe incluir nuevamente el token, URL de gestión ni datos de contacto.

---

# 22. Reglas ante fallo de transporte

## Cambio de horario

Si el transporte falla:

- no revertir el cambio de horario;
- no cancelar la reservación;
- marcar el seguimiento como accionable;
- permitir recordatorio manual cuando las reglas actuales lo permitan;
- el intento cuenta dentro del máximo de intentos definido para afectaciones.

## Recordatorio del día anterior

Si el transporte falla:

- la reservación permanece confirmada;
- no crear una afectación de horario;
- registrar `failed`;
- no reenviar automáticamente en bucle.

Una política de reintento para recordatorios puede definirse en una etapa posterior.

---

# 23. Orden de prioridad entre comunicaciones

Para evitar mensajes contradictorios:

```text
reservation.schedule_change
>
reservation.reminder_next_day
```

Si una reservación tiene un impacto de horario activo:

- no enviar el recordatorio ordinario del día anterior;
- mantener sólo el mensaje de afectación.

Después de resolver la afectación, el nuevo estado de la reservación determina si corresponde un recordatorio futuro.

---

# 24. Configuración administrativa

La configuración debe ser sencilla.

Ubicación:

```text
Admin
→ Configuración
→ Reservaciones
```

Bloque:

```text
Recordatorios de reservaciones
```

Controles:

```text
Enviar recordatorio automático  [switch]

Hora de envío                    [HH:MM]
```

Ayuda:

```text
Se enviará un recordatorio a las reservaciones confirmadas del día siguiente que tengan un contacto válido.
```

Si el switch está desactivado:

- no se generan recordatorios;
- el Schedule Trigger puede seguir ejecutándose;
- PHP responde `due = false`.

No esconder la hora necesariamente si el patrón del admin mantiene los campos visibles; puede deshabilitarse visualmente según los componentes ya existentes.

---

# 25. Diseño del workflow n8n

Secuencia recomendada:

```text
Webhook Trigger
        │
        └──────────────┐
                       │
Schedule Trigger       │
        │              │
        ▼              │
Consultar PHP          │
recordatorios          │
        │              │
        └───────┬──────┘
                ▼
        Normalizar input
                │
                ▼
          Switch event
          /          \
 schedule_change   reminder_next_day
        │              │
        ▼              ▼
 Construir mensaje   Construir mensaje
        │              │
        └──────┬───────┘
               ▼
       Split notifications
               │
               ▼
       Switch contact_type
          /             \
       email          telefono
          │             │
          ▼             ▼
       proveedor      proveedor
          │             │
          └──────┬──────┘
                 ▼
         Normalizar resultado
                 │
                 ▼
           Callback a PHP
```

---

# 26. Seguridad del webhook

Todas las comunicaciones PHP ↔ n8n deben utilizar:

```text
X-N8N-Secret
```

Validar el secreto antes de procesar.

Utilizar comparación segura.

No colocar el secreto en el JSON del workflow versionado.

Variables:

```env
N8N_WEBHOOK_RESERVATIONS_URL=
N8N_SECRET=
```

El endpoint interno de preparación de recordatorios también exige el mismo secreto o un secreto de integración equivalente.

---

# 27. Variables de configuración sugeridas

Integración:

```env
N8N_WEBHOOK_RESERVATIONS_URL=http://localhost:5678/webhook/reservaciones
N8N_SECRET=replace-with-long-random-secret
```

Los parámetros funcionales del recordatorio no deben quedar únicamente en `.env` si se administrarán desde el panel.

La hora y activación deben persistirse en la configuración administrativa de la aplicación.

---

# 28. Compatibilidad con herramientas de desarrollo

Las herramientas actuales de desarrollo se conservan.

`Copiar enlace de prueba` para afectaciones:

- sigue disponible en `development/testing`;
- no llama a n8n;
- no incrementa intentos reales;
- no cambia estado de transporte;
- no se elimina.

Para los recordatorios puede añadirse en desarrollo una forma equivalente de generar/probar un acceso sin realizar un envío externo, si resulta útil para validación.

---

# 29. Reglas que NO deben modificarse

Esta integración no debe alterar:

- capacidad;
- asignación automática;
- grupos predefinidos de mesas;
- cálculo temporal de tickets;
- estados del mapa;
- POS;
- no-show;
- tolerancias;
- límite público de comensales;
- límite administrativo;
- estados canónicos de reservación;
- privacidad específica del waiter;
- buzón como presentación de afectaciones;
- autoridad de `horario_impacto_reservaciones`.

---

# 30. Criterios de aceptación

La implementación queda correcta cuando:

- existe un único workflow de comunicaciones de reservaciones;
- el workflow admite `schedule_change` y `reminder_next_day`;
- el cambio de horario usa un mensaje destacado con `⚠️`;
- el mensaje explica que el cambio de horario afecta la reservación;
- el recordatorio del día anterior es breve;
- ambos mensajes incluyen nombre, fecha, hora y personas;
- ambos incluyen una URL temporal de gestión;
- la hora del recordatorio puede cambiarse desde el panel admin;
- la configuración usa el diseño existente del admin;
- cambiar la hora no requiere editar n8n;
- el Schedule Trigger es idempotente;
- una reservación afectada no recibe el recordatorio ordinario;
- recordatorios y afectaciones no comparten tabla de dominio;
- el acceso temporal puede modificar y cancelar según reglas vigentes;
- >12 no adquiere autoservicio de modificación;
- cancelar desde un acceso válido usa la transición canónica;
- n8n no decide reglas de dominio;
- fallos de n8n no revierten cambios de horario ni reservaciones;
- los tokens en texto plano no se persisten;
- los estados de transporte no se agregan a `reservaciones.estado`.

---

# 31. Pruebas mínimas

## Recordatorios

- configuración desactivada;
- configuración activada antes de la hora;
- ejecución dentro de la ventana de envío;
- ejecución repetida sin duplicados;
- reservación confirmada con contacto;
- reservación sin contacto;
- reservación cancelada;
- reservación con afectación pendiente;
- reservación >12;
- cambio de hora de configuración;
- modificación desde enlace;
- cancelación desde enlace;
- acceso vencido.

## Cambio de horario

- reservación <=12 con contacto;
- reservación <=12 sin contacto;
- reservación >12;
- mensaje con `⚠️`;
- modificación;
- cancelación;
- fallo n8n;
- callback obsoleto;
- resolución de afectación después de modificar;
- resolución de afectación después de cancelar.

## Integración

- secreto inválido;
- evento desconocido;
- email;
- teléfono;
- batch de múltiples notificaciones;
- callback delivered;
- callback failed;
- n8n caído;
- timeout;
- ningún rollback de dominio por fallo externo.

---

# 32. Resultado esperado

La arquitectura final queda preparada para crecer sin convertir n8n en autoridad del módulo.

```text
PHP / MySQL
    │
    ├── reservaciones
    ├── reglas públicas
    ├── horario_impacto_reservaciones
    ├── reservacion_recordatorios
    ├── configuración de recordatorios
    ├── tokens / sesiones temporales
    └── deduplicación
            │
            ▼
     Webhook reservaciones
            │
            ▼
           n8n
            │
       ┌────┴────┐
       ▼         ▼
     email    teléfono
            │
            ▼
       callback PHP
```

La interfaz temporal compartida se encarga de presentar la acción adecuada según el origen:

```text
Cambio de horario
→ explicar afectación
→ modificar o cancelar

Recordatorio del día anterior
→ recordar visita
→ modificar o cancelar
```

Las reglas reales de cada acción siempre se revalidan en el backend.
