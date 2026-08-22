# Flujos de n8n

Este directorio contiene copias importables y versionadas de los workflows de
Casa Pestalozzi. n8n conserva la versión en ejecución en su propia base local;
por eso cualquier edición hecha en su interfaz debe volver a exportarse al
repositorio.

| Archivo | Workflow | Responsabilidad |
|---|---|---|
| `reservaciones-comunicaciones.json` | Reservaciones - comunicaciones | Recibe cambios de horario y prepara recordatorios del día anterior; enruta email/teléfono y reporta `delivered` o `failed`. |
| `sugerencias.json` | Sugerencias | Genera sugerencias del POS. |
| `areas-de-mejora.json` | Areas de mejora | Procesa feedback para el panel administrativo. |

## Reservaciones - comunicaciones

Es un único workflow con dos entradas:

- `POST /webhook/reservaciones`: valida `X-N8N-Secret`, responde `202` temprano
  y procesa `reservation.schedule_change`.
- `Schedule Trigger`, cada cinco minutos: llama al endpoint interno que prepara
  los recordatorios habilitados y vencidos para ejecución.

Ambas entradas convergen en `Switch event`, construyen un mensaje con el enlace
temporal generado por PHP y eligen el canal por `contact_type`. Al finalizar,
el workflow informa a PHP uno de estos estados:

```json
{
  "event": "reservation.schedule_change",
  "source_id": 1,
  "attempt": 1,
  "status": "delivered"
}
```

El payload mostrado sólo documenta forma y valores técnicos; no debe guardarse
como `pinData` ni sustituirse con contactos de clientes.

Endpoints internos de la aplicación:

- `POST /api/integraciones/n8n/reservaciones/recordatorios/preparar`
- `POST /api/integraciones/n8n/reservaciones/notificacion-resultado`

Los dos exigen `X-N8N-Secret`. El primero sólo entrega PII y el token en memoria
como parte del lote a procesar; la base de datos conserva exclusivamente el
hash del token.

## Variables de la aplicación PHP

En `includes/.env`:

```dotenv
RESERVATION_NOTIFICATION_PROVIDER=development
N8N_WEBHOOK_RESERVATIONS_URL=http://localhost:5678/webhook/reservaciones
N8N_SECRET=replace-with-an-independent-long-random-secret
```

`development` es el modo seguro predeterminado: valida el contrato y simula la
aceptación sin enviar nada fuera del equipo. Para usar el transporte real hay
que establecer `RESERVATION_NOTIFICATION_PROVIDER=n8n` después de completar la
configuración descrita abajo.

## Variables del proceso de n8n

El proceso o contenedor de n8n necesita:

```dotenv
N8N_SECRET=the-same-independent-secret
RESERVATION_APP_BASE_URL=http://host-visible-from-n8n
RESERVATION_EMAIL_FROM=verified-sender
RESERVATION_PHONE_FROM=provider-sender
```

`RESERVATION_APP_BASE_URL` debe apuntar a la raíz que n8n puede alcanzar, sin
slash final. Los remitentes son identificadores del proveedor, no datos de un
cliente.

## Credenciales pendientes

El JSON no contiene ids, nombres ni secretos de credenciales. Después de
importar se deben asignar exactamente:

1. Una credencial SMTP al nodo `Enviar email`.
2. Una credencial Twilio al nodo `Enviar mensaje telefónico`.

Si se adopta otro proveedor para teléfono, se reemplaza sólo ese nodo sin
cambiar el contrato con PHP. No se debe activar el workflow ni seleccionar el
provider `n8n` en la aplicación hasta probar ambos canales y ambos callbacks.

## Importar y activar

1. En n8n abre **Workflows → Import from File**.
2. Selecciona `n8n/reservaciones-comunicaciones.json`.
3. Configura las variables del proceso y asigna las dos credenciales.
4. Ejecuta una prueba controlada de email y otra de teléfono.
5. Comprueba el callback `delivered` y fuerza un error para comprobar `failed`.
6. Activa el workflow.
7. Cambia la aplicación a `RESERVATION_NOTIFICATION_PROVIDER=n8n`.

El archivo versionado se entrega inactivo deliberadamente. Una importación sin
credenciales puede validarse estructuralmente, pero no demuestra entrega real.

## Exportar

Después de editar un workflow en n8n:

```bash
node n8n/exportar.js
```

El script abre `%USERPROFILE%/.n8n/database.sqlite` en modo sólo lectura y
reescribe los JSON en el formato de **Import from File**. Después de exportar,
revisa que `reservaciones-comunicaciones.json` no contenga `credentials`,
`pinData`, contactos, tokens ni cuerpos de ejecuciones.

## Contratos existentes

El POS sigue usando `N8N_WEBHOOK_SUGERENCIAS_URL` y el panel de feedback usa
`N8N_WEBHOOK_AREAS_MEJORA_URL`. El workflow de reservaciones no modifica esos
contratos ni consulta directamente MySQL.
