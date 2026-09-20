# Configuración de notificaciones

La aplicación admite tres entornos. `APP_ENV` debe ser `development`, `test` o
`production`; si no se define, el módulo usa `production`.

## Variables mínimas

```dotenv
APP_ENV=development
APP_TIMEZONE=America/Mexico_City
N8N_BASE_URL=http://localhost:5678
N8N_RESERVATIONS_WEBHOOK_SECRET=
N8N_RESERVATIONS_CALLBACK_SECRET=
RESERVATION_PUBLIC_BASE_URL=http://localhost
```

`APP_TIMEZONE` es la zona canónica de la aplicación y debe mantenerse alineada
con `America/Mexico_City`, la sesión MySQL y `GENERIC_TIMEZONE`/`TZ` de n8n.

Los datos públicos configurables de `SitioConfig` también viven en el ejemplo:
`SITIO_DIRECCION`, `SITIO_DIRECCION_CORTA`, `SITIO_CORREO`, `SITIO_MAPS_URL`,
`SITIO_INSTAGRAM` y `SITIO_WHATSAPP_EVENTOS`. Este último es el WhatsApp de
eventos/catering; `RESERVAS_WHATSAPP` pertenece a reservaciones.

`N8N_BASE_URL` y el secreto de webhook son necesarios para transporte externo
(`test` o `production`). El secreto de callback independiente autentica preparar,
reclamar y registrar resultado desde n8n. Ambos deben ser aleatorios y diferentes;
si se configuran iguales, PHP rechaza callbacks. No usar claves de ejemplo ni
versionar secretos reales. `N8N_SECRET` sigue activo únicamente en feedback/áreas.
La URL pública debe usar HTTPS en producción; no es necesariamente la URL privada
de API accesible desde n8n. Ver [operación n8n](../n8n/README.md) para credenciales
nativas y [Compose](../n8n/deploy/README.md) para persistencia sin comandos set.

## Comportamiento por entorno

| Capacidad | development | test | production |
|---|---:|---:|---:|
| Reglas y persistencia PHP | Sí | Sí | Sí |
| n8n, Email y WhatsApp externos | No | Sí, configuración TEST | Sí |
| Código de confirmación visible | Sí | No | No |
| Enlaces de gestión de prueba | Sí | No | No |
| Callbacks de transporte | No externos | Sí | Sí |
| `RESERVATION_TEST_NOW` | No | Sí | No |

### development

Usar para desarrollo local sin n8n. Se pueden crear y confirmar reservaciones,
probar reglas de horarios, preparar recordatorios y usar herramientas visuales
de prueba. El código se devuelve sólo en el campo explícito
`development_confirmation_code`; no se envían mensajes externos.
La aceptación simulada ejerce la misma política de tres envíos y cooldown.

### test

Usar para integración controlada con n8n. Se prueban los seis recorridos:
confirmación, recordatorio y cambio de horario por Email y WhatsApp. El código y
los enlaces de debug permanecen ocultos. `RESERVATION_TEST_NOW` permite fijar el
reloj para pruebas reproducibles.

### production

Usar para operación real. n8n, SMTP y WhatsApp Business Cloud deben estar
configurados con credenciales de producción. No se muestran códigos ni enlaces
de prueba y no se utiliza un reloj fijo.

## Configuración funcional

La configuración administrable del restaurante no va en `.env`; permanece en
la base de datos, por ejemplo:

```text
recordatorio_dia_anterior_activo
hora_recordatorio
```

En resumen: `.env` define infraestructura y entorno; PHP conserva las reglas y
el estado; n8n orquesta el transporte.

El límite/cooldown se centraliza en `ConfirmationResendPolicy`, no en JS ni en
n8n. Phone Number ID, remitente, idioma y base de API se configuran en Set propios
de los workflows. SMTP, Meta y Header Auth pertenecen a Credentials, no al Set.
No depender de `$env` en Code. `APP_ENV` inválido se rechaza; no se acepta `testing`.
