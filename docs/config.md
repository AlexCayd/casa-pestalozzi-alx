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
| `IA_N8N_WEBHOOK_URL` | Webhook del flujo n8n del Asistente IA (`/admin/ia`). Vacía por omisión: el módulo se ve, avisa que no está conectado y no hace peticiones. La llama el navegador, no PHP (ver [Asistente IA](#asistente-ia)). |
| `SITIO_DIRECCION`, `SITIO_DIRECCION_CORTA`, `SITIO_CORREO`, `SITIO_MAPS_URL`, `SITIO_INSTAGRAM`, `SITIO_WHATSAPP_EVENTOS` | Datos públicos del sitio y contacto para eventos. |
| `RESERVAS_TELEFONO_VISIBLE`, `RESERVAS_TELEFONO_TEL` | Teléfono público del restaurante, tal como se lee y para `tel:`. |
| `RESERVAS_WHATSAPP` | Respaldo del WhatsApp de reservaciones y catas: sólo se usa si el panel no tiene número guardado. |

No reutilizar `N8N_SECRET` como secreto de transporte de reservaciones. Nunca
versionar credenciales reales. La [operación n8n](../n8n/README.md) documenta sus
credenciales y configuración de workflows.

### Asistente IA

`/admin/ia` es, por ahora, sólo la vista. Con `IA_N8N_WEBHOOK_URL` vacía —o con
algo que no sea una URL `http(s)` válida sin credenciales incrustadas— el panel
no hace ninguna petición: la cabecera dice «Vista previa · El asistente aún no
está conectado» y cada pregunta recibe un mensaje de estado, nunca una
respuesta inventada.

Con la URL configurada, el **navegador** del administrador llama al webhook; no
hay proxy en PHP. El único punto de contacto es `enviarMensaje()` en
`src/js/admin/ia/ia.js`:

```text
POST <IA_N8N_WEBHOOK_URL>
Content-Type: application/json

{
  "conversation_id": "c-3f9a51d07b2e4c8a",
  "message": "¿Cuántos capuchinos se vendieron ayer?",
  "history": [
    { "role": "user", "content": "…" },
    { "role": "assistant", "content": "…" }
  ]
}
```

- `conversation_id` es estable durante toda la conversación y aleatorio: no
  identifica al usuario ni viaja la sesión (`credentials: 'omit'`).
- `history` son los turnos anteriores a `message`, los 20 más recientes. Los
  avisos que escribe el propio panel (conexión pendiente, error, detenida) no
  se envían.
- Respuesta esperada (2xx): `{ "reply": "<markdown>" }`. También se aceptan una
  lista con ese objeto (lo que devuelve «Respond to Webhook» con todos los
  ítems), la clave `output` del nodo AI Agent y un cuerpo `text/plain`.
- El markdown se pinta escapando primero el HTML: párrafos, negritas,
  cursivas, listas, código, citas, encabezados, tablas simples y enlaces sólo
  `https://` o a rutas del panel. Las cifras salen en mono tabular.
- La espera se corta a los 90 s; el botón Detener aborta la petición. Un error
  HTTP, de red o de formato se muestra en el hilo con «Reintentar».

Que llame el navegador tiene dos consecuencias. n8n debe permitir **CORS** para
el origen del panel (opción «Allowed Origins» del nodo Webhook) y contestar el
`OPTIONS` previo que dispara el JSON. Y la URL queda impresa en el HTML de
`/admin/ia`, que sólo abre un admin pero no es un secreto: si el flujo tiene que
autenticarse, el paso siguiente es un proxy bajo `/admin/api/ia` —protegido por
el prefijo— que añada el secreto del lado del servidor.

Las conversaciones se guardan en `localStorage` del navegador
(`cp-admin-ia-conversaciones-v1`), no en la base: no se comparten entre equipos
ni entran en ningún respaldo, y «Borrar» las elimina sólo de ese navegador.

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

- `/admin/configuracion/pos`: asignación de mesero al abrir mesa, el
  interruptor global `impresion_activa` y el **máximo de tickets por barra**
  (`max_tickets_barra`, 1 a 12, por defecto 4). Llevar no tiene tope.
- `/admin/configuracion/reservaciones`: activación y hora de preparación del
  recordatorio del día anterior; el **máximo de comensales que acepta la
  landing** (`max_comensales_publico`, 7 a 16, por defecto 12; por encima la
  landing manda al WhatsApp), y el **WhatsApp de reservaciones**
  (`whatsapp_reservas`, 10 a 15 dígitos con lada de país), que usan también las
  catas. Si está vacío se usa `RESERVAS_WHATSAPP` y después el teléfono
  público. Al bajar el máximo, la pantalla avisa cuántas reservas futuras
  quedan por encima; no se tocan.
- Horario efectivo y excepciones: sección de configuración de horarios.
- `/admin/configuracion/paleta`: los **cuatro colores de marca de la landing**
  (verde musgo, oro, beige y crema, los del manual de identidad; tabla
  `configuracion_paleta`, fila única). Son la capa 1 de
  `src/scss/layout/_reset.scss`: lo guardado se emite como
  `<style id="paleta-landing">` en `:root` justo después de `app.css`
  (`PaletaLanding::estiloEnLinea()`), así que escalas, roles y tonos de sección
  se derivan solos. Lo aplican la landing, el feedback, el 404, la gestión de
  reservación y la carta en PDF (`PaletaLanding::paraPdf()`); el panel y las
  pantallas de piso no cambian. Un color igual al de marca se guarda como
  `NULL`, para que siga al manual si la capa 1 cambia. Si alguna comprobación
  de contraste (el texto más tenue de cada sección a 4.5:1, y los botones)
  queda por debajo, el guardado pide confirmación explícita. Para una base
  existente:

  ```sql
  CREATE TABLE IF NOT EXISTS configuracion_paleta (
    id TINYINT UNSIGNED NOT NULL, verde CHAR(7) NULL, oro CHAR(7) NULL,
    beige CHAR(7) NULL, crema CHAR(7) NULL, updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_configuracion_paleta_usuario FOREIGN KEY (updated_by)
      REFERENCES usuarios(id) ON DELETE SET NULL
  );
  ```

  Si ya existía con la columna `cafe` (la paleta anterior al manual), se
  renombra y se descarta lo guardado en ella, porque un café personalizado no
  es un oro:

  ```sql
  ALTER TABLE configuracion_paleta CHANGE cafe oro CHAR(7) NULL;
  UPDATE configuracion_paleta SET oro = NULL;
  ```

- **Variantes por platillo** (Menú → icono de deslizadores de cada platillo,
  `/admin/menu/variantes?id=`): grupos de opciones que mueven el precio y
  ajustan la receta; el inventario se descuenta con la receta que resulta.
  Reglas en CLAUDE.md («Variantes por platillo»). Para una base existente:

  ```sql
  ALTER TABLE ticket_items
    ADD COLUMN variante_ids VARCHAR(120) NULL DEFAULT NULL AFTER nota,
    ADD COLUMN variante     VARCHAR(160) NULL DEFAULT NULL AFTER variante_ids;
  -- y los CREATE TABLE de producto_variantes y variante_ajustes de database/ddl.sql
  ```

  Sin las tablas el POS sigue abriendo (sin opciones); sin las columnas de
  `ticket_items` el envío de comanda falla, así que el `ALTER` va antes de
  desplegar el código.

- **Foto por platillo** (Menú → editar platillo, campo «Foto del platillo»):
  cada platillo puede llevar su propia foto, que se sube con el mismo
  `ImagenUploader` que la de la categoría (WebP de 1200 px como máximo, en
  `public/build/images/`). Sin foto propia, la carta usa la de su categoría:
  `GET /menu` entrega las dos (`img` de la categoría e `img` de cada platillo,
  `null` si no tiene) y la landing elige; el fallback no se resuelve en el
  servidor. Reemplazar o quitar la foto borra el archivo anterior al guardar,
  no antes: si el formulario no pasa la validación, la foto vigente sigue
  intacta y la recién subida se conserva para el siguiente intento. Para una
  base existente:

  ```sql
  ALTER TABLE productos
    ADD COLUMN img VARCHAR(255) NULL DEFAULT NULL AFTER descripcion;
  ```

  Sin la columna fallan el guardado del platillo y `GET /menu` (la carta de la
  landing), así que el `ALTER` va antes de desplegar el código.

Las columnas son tolerantes: en una base sin migrar se leen los valores por
defecto. Los `ALTER` están en
[Reservaciones](reservaciones/reservaciones.md#migracion-de-bases-existentes).

Consulta las reglas de [operación POS e impresión](operacion.md),
[reservaciones](reservaciones/reservaciones.md) y
[notificaciones](reservaciones/notificaciones.md).
