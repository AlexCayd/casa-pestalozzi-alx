# Configuración

## Variables de entorno

`.env` configura infraestructura y entorno; no debe contener reglas de negocio.
Define los datos de conexión (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) y
protege los secretos fuera del repositorio.

| Variable | Uso |
| --- | --- |
| `APP_ENV` | `development`, `test` o `production`; si falta se usa `production`, cualquier otro valor se rechaza. |
| `APP_TIMEZONE` | Zona de la aplicación; por omisión `America/Mexico_City`. Mantenerla alineada con MySQL y los procesos externos. |
| `SESSION_SAVE_PATH` | Ruta opcional para los archivos de sesión de PHP. |
| `NIP_LOOKUP_SECRET` | Secreto para localizar NIP mediante HMAC; no es una contraseña de usuario. |
| `N8N_BASE_URL` | Base de la instancia n8n que recibe webhooks desde PHP. |
| `N8N_RESERVATIONS_WEBHOOK_SECRET` | Autentica los webhooks PHP → n8n. |
| `N8N_RESERVATIONS_CALLBACK_SECRET` | Autentica callbacks n8n → PHP; debe diferir del secreto anterior. |
| `RESERVATION_PUBLIC_BASE_URL` | Base pública HTTPS que se incluye en enlaces de reservación. |
| `N8N_WEBHOOK_SUGERENCIAS_URL`, `N8N_WEBHOOK_AREAS_MEJORA_URL`, `N8N_SECRET` | Webhooks y autenticación que conservan sugerencias, analíticas/áreas y feedback. |
| `SITIO_DIRECCION`, `SITIO_DIRECCION_CORTA`, `SITIO_CORREO`, `SITIO_MAPS_URL`, `SITIO_INSTAGRAM`, `SITIO_WHATSAPP_EVENTOS` | Datos públicos del sitio y contacto para eventos. |

No reutilizar `N8N_SECRET` como secreto de transporte de reservaciones. Nunca
versionar credenciales reales. La [operación n8n](../n8n/README.md) documenta sus
credenciales y configuración de workflows.

## Comportamiento por entorno

| | development | test | production |
| --- | --- | --- | --- |
| Reglas y persistencia PHP | Sí | Sí | Sí |
| Proveedores externos | Simulados | Transporte TEST configurado | Transporte real |
| Código/enlace de prueba visible | Sólo campos de desarrollo explícitos | No | No |
| Reloj fijo `RESERVATION_TEST_NOW` | No | Sí | No |

`development` evita llamadas a proveedores. `test` sirve para integración
controlada y conserva ocultos los datos de depuración al cliente. `production`
no expone códigos ni enlaces de prueba ni acepta un reloj fijo.

## Configuración administrativa

Los parámetros funcionales se guardan en la base de datos, no en `.env`:

- `/admin/configuracion/pos`: asignación de mesero al abrir mesa y el
  interruptor global `impresion_activa`.
- `/admin/configuracion/reservaciones`: activación y hora de preparación del
  recordatorio del día anterior.
- Horario efectivo y excepciones: sección de configuración de horarios.
- `/admin/configuracion/paleta`: los **cuatro colores de marca de la landing**
  (verde, café, beige y crema; tabla `configuracion_paleta`, fila única). Son
  la capa 1 de `src/scss/layout/_reset.scss`: lo guardado se emite como
  `<style id="paleta-landing">` en `:root` justo después de `app.css`
  (`PaletaLanding::estiloEnLinea()`), así que escalas, roles y tonos de sección
  se derivan solos. Lo aplican la landing, el 404, la gestión de reservación y
  las dos cartas en PDF (`PaletaLanding::paraPdf()`); el panel no cambia, y el
  oro no es editable porque la landing no lo usa. Un color igual al de marca se
  guarda como `NULL`, para que siga al manual si la capa 1 cambia. Si alguna
  comprobación de contraste (el texto más tenue de cada sección a 4.5:1) queda
  por debajo, el guardado pide confirmación explícita. Para una base existente:
  `database/migrations/002_paleta_landing.sql`.

Consulta las reglas de [operación POS e impresión](operacion.md),
[reservaciones](reservaciones/reservaciones.md) y
[notificaciones](reservaciones/notificaciones.md).
