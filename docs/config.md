# Configuración

## Variables de entorno

`includes/.env` o las variables del proceso configuran infraestructura y entorno; no deben contener reglas de negocio.
Define los datos de conexión (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) y
protege los secretos fuera del repositorio.

| Variable | Uso |
| --- | --- |
| `APP_ENV` | `development`, `test` o `production`; si falta se usa `production`, cualquier otro valor se rechaza. |
| `APP_TIMEZONE` | Zona de la aplicación; por omisión `America/Mexico_City`. Mantenerla alineada con MySQL y los procesos externos. |
| `SESSION_SAVE_PATH` | Ruta opcional para los archivos de sesión de PHP. |
| `NIP_LOOKUP_SECRET` | Secreto para localizar NIP mediante HMAC; no es una contraseña de usuario. |
| `CONTACT_ENCRYPTION_KEY` | Clave base64 canónica de 32 bytes para AES-256-GCM de contactos. |
| `CONTACT_LOOKUP_KEY` | Clave base64 canónica, distinta y de 32 bytes, para HMAC de búsqueda de contactos. |
| `N8N_BASE_URL` | Base de la instancia n8n que recibe webhooks desde PHP. |
| `N8N_RESERVATIONS_WEBHOOK_SECRET` | Autentica los webhooks PHP → n8n. |
| `N8N_RESERVATIONS_CALLBACK_SECRET` | Autentica callbacks n8n → PHP; debe diferir del secreto anterior. |
| `RESERVATION_PUBLIC_BASE_URL` | Base pública HTTPS que se incluye en enlaces de reservación. |
| `N8N_WEBHOOK_SUGERENCIAS_URL`, `N8N_WEBHOOK_AREAS_MEJORA_URL`, `N8N_SECRET` | Webhooks y autenticación que conservan sugerencias, analíticas/áreas y feedback. |
| `SITIO_DIRECCION`, `SITIO_DIRECCION_CORTA`, `SITIO_CORREO`, `SITIO_MAPS_URL`, `SITIO_INSTAGRAM`, `SITIO_WHATSAPP_EVENTOS` | Datos públicos del sitio y contacto para eventos. |

No reutilizar `N8N_SECRET` como secreto de transporte de reservaciones. Nunca
versionar credenciales reales. La [operación n8n](../n8n/README.md) documenta sus
credenciales y configuración de workflows.

`CONTACT_ENCRYPTION_KEY` y `CONTACT_LOOKUP_KEY` son obligatorias cuando se lee o
escribe una reservación con correo o teléfono. Cada valor debe ser Base64 canónica
de 32 bytes aleatorios; las dos claves deben ser distintas. No hay valor
predeterminado ni fallback a texto claro. Genéralas con un generador criptográfico
seguro, codifica cada una en Base64 y guárdalas en el almacén de secretos del
entorno. No reutilices claves entre ambientes. No reemplaces una clave activa sin
una estrategia de rotación compatible con los datos existentes.

## Base nueva de desarrollo

En una instalación nueva, configura `APP_ENV=development`, las credenciales MySQL
y las dos claves de contacto antes de iniciar PHP. Crea una base vacía y, desde
el cliente MySQL, ejecuta `database/ddl.sql` y luego `database/deploy.sql`. Carga
los datos ficticios una sola vez con el seeder, indicando explícitamente el nombre
de la base:

```powershell
php scripts/seed-development.php --database=NOMBRE
```

El seeder requiere claves válidas, admite sólo `development` o `test` y rechaza
una base que ya tenga reservaciones o tickets. No cargues `database/development.sql`
directamente mediante `SOURCE` ni importación SQL: los contactos sintéticos están
en comentarios de metadata y se cifran en memoria durante la carga. Para empezar
de nuevo, reconstruye una base vacía con DDL y deploy y ejecuta el seeder otra vez.
No se proporciona carga de bloques históricos ni actualización de bases existentes.

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

Consulta las reglas de [operación POS e impresión](operacion.md),
[reservaciones](reservaciones/reservaciones.md) y
[notificaciones](reservaciones/notificaciones.md).
