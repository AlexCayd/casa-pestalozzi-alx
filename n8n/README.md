# n8n: configuración, despliegue y operación

La receta reproducible está preparada, pero **no se desplegó ni modificó la
instancia existente**. PHP y los tres exports fueron corregidos y probados
localmente; esto no certifica integración real ni preparación para producción.

## Jerarquía y estado de la migración

- [Arquitectura general](../docs/arquitectura.md).
- [Contrato funcional de notificaciones](../docs/reservaciones/notificaciones.md).
- [Afectaciones por cambios de horario](../docs/reservaciones/afectaciones_reservaciones_por_cambios_horario.md).
- Este README y [la receta operativa](deploy/README.md): configuración y operación n8n.
- [Migración de Services](../docs/migracion_services.md): antecedente histórico cuando contradiga el contrato vigente.

La solicitud menciona `docs/reservaciones/afectaciones_cambio_horario.md`, que no
existe en este checkout; el enlace anterior apunta al archivo disponible.

**Condiciones previas a activar la receta:** aplicar las tres migraciones PHP
en una ventana coordinada, importar el conjunto final de JSON, completar cada
Configuración y asignar las credenciales. Probar primero en TEST con destinatarios
autorizados. Los exports ya eliminan `$env`, esperan aceptación del proveedor
para OTP y usan callbacks `accepted|failed`; no relajar el bloqueo de entorno.
Ver [migración y pruebas](../docs/reservaciones/notificaciones.md#migración-y-validación).

## Tres workflows independientes

| Archivo | Workflow | Entrada |
|---|---|---|
| [reservaciones-confirmacion.json](reservaciones-confirmacion.json) | Reservaciones - Confirmación | `POST /webhook/reservaciones/confirmacion` |
| [reservaciones-recordatorio.json](reservaciones-recordatorio.json) | Reservaciones - Recordatorio | Schedule Trigger cada 5 minutos |
| [reservaciones-cambio-horario.json](reservaciones-cambio-horario.json) | Reservaciones - Cambio de horario | `POST /webhook/reservaciones/cambio-horario` |

Cada workflow conserva sus propios nodos Email, WhatsApp Text y WhatsApp
Template, validación y adaptación del mensaje. **Jamás un subworkflow común,
una llamada Execute Workflow ni un workflow monolítico.** No intercambiar estado
de ejecución entre ellos. La instancia y sus credenciales son infraestructura;
compartir una credencial no crea una dependencia de ejecución entre workflows.
El runner de Compose ejecuta Code nodes y tampoco es un subworkflow.

PHP conserva reglas, estado, intentos, deduplicación y generación de OTP/tokens;
n8n valida el contrato y transporta. Las llamadas al proveedor ocurren después
del COMMIT de PHP. Templates esperados: `reservation_confirmation_code`,
`reservation_reminder` y `reservation_schedule_change`.

## Dónde vive cada configuración

| Ámbito | Valores | Persistencia y uso |
|---|---|---|
| Secretos de instancia | `N8N_ENCRYPTION_KEY`; token técnico del runner independiente | Archivos privados externos al repositorio, montados como secrets. La clave sólo se monta en n8n. |
| Credenciales n8n | SMTP, WhatsApp Business Cloud/Meta, Header Auth de entrada y Header Auth hacia PHP | Se crean/asignan en la instancia; quedan cifradas en su BD, nunca en exports. |
| Configuración no secreta del workflow | App base URL, Email From, Phone Number ID, idioma de template | Nodo Edit Fields (Set) llamado **Configuración**, propio de cada workflow. |
| Configuración de proceso n8n | Timezone, URLs de instancia, proxy, bloqueo de entorno, persistencia de ejecuciones | Compose y archivo de parámetros persistente del operador. |
| Configuración PHP | `APP_ENV`, `N8N_BASE_URL`, `RESERVATION_PUBLIC_BASE_URL`, los dos secretos de reservaciones | Configuración privada de PHP; no se inyecta en los nodos n8n. |

En cada nodo **Configuración**, definir los siguientes campos String literales:

| Campo | Contenido que asignará el operador |
|---|---|
| `RESERVATION_APP_BASE_URL` | Base HTTPS de PHP accesible desde n8n, sin `/` final |
| `RESERVATION_EMAIL_FROM` | Remitente autorizado por SMTP |
| `RESERVATION_WHATSAPP_PHONE_NUMBER_ID` | Identificador de número Meta, como texto |
| `RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE` | Idioma aprobado para los templates, por ejemplo `es_MX` |

Son campos del Set, **no variables de entorno**. Conservar los campos de entrada
del webhook al atravesarlo y ejecutarlo antes de cualquier nodo que lo lea.
Los consumidores pueden referenciar
`{{ $('Configuración').first().json.RESERVATION_APP_BASE_URL }}` (y los demás
campos). No usar `$env`, `process.env` ni `$vars`/variables Enterprise. El Set
nunca contiene contraseñas, tokens, headers de autenticación ni la clave de
cifrado. Replicar sus valores no secretos de forma controlada en los tres
workflows. La hora funcional del recordatorio sigue en PHP/BD; alinear también
el timezone de los workflows con `America/Mexico_City`.

## Autenticación y contrato objetivo

| Sentido | Configuración privada PHP | Header | Asignación en n8n |
|---|---|---|---|
| PHP → n8n | `N8N_RESERVATIONS_WEBHOOK_SECRET` | `X-N8N-Secret` | Credencial Header Auth seleccionada en los Webhook de confirmación y cambio de horario |
| n8n → PHP | `N8N_RESERVATIONS_CALLBACK_SECRET` | `X-N8N-Callback-Secret` | Otra credencial Header Auth en los HTTP Request de preparar, reclamar recordatorio y registrar resultados |

Los dos secretos son aleatorios, distintos entre sí y por entorno; también son
distintos de la clave de cifrado y del token del runner. En los HTTP Request
elegir Generic Credential Type → Header Auth; no escribir un header secreto
manual ni validarlo en Code. En los Webhook usar la autenticación nativa
[Header Auth](https://docs.n8n.io/integrations/builtin/credentials/webhook).
`N8N_SECRET` no forma parte del contrato objetivo de reservaciones.

Crear credenciales SMTP y WhatsApp Business Cloud API (token Meta y los campos
de cuenta que solicite la credencial) y asignarlas a **cada** nodo de envío de
los tres workflows. Phone Number ID e idioma siguen en Configuración.

PHP usa `N8N_BASE_URL` como base de la instancia, sin añadir `/webhook` dos
veces. `RESERVATION_PUBLIC_BASE_URL` es la base pública para enlaces de clientes;
puede diferir de la base de API usada por n8n. `APP_ENV=development` omite
transporte externo; `test` y `production` sí lo ejecutan. `APP_ENV` pertenece a
PHP y no es el `NODE_ENV=production` del contenedor.

### Confirmación / OTP

Responder **HTTP 200 después de la aceptación del proveedor**. Cada ejecución
alcanza una sola respuesta: éxito 200, fallo 502 o contrato inválido 422.
El cuerpo de éxito es:

```json
{"ok":true,"accepted":true,"channel":"whatsapp"}
```

```json
{"ok":false,"accepted":false,"error":"TRANSPORT_FAILED"}
```

El cuerpo de fallo anterior usa HTTP 502. Un `200` con `accepted=false` tampoco
sería éxito para PHP. Errores
de autenticación/contrato conservan su `4xx`; caídas, timeout, `5xx` o JSON
inválido nunca equivalen a aceptación. No responder un `202` anticipado ni crear
callback para confirmación. Aceptación SMTP/Meta no demuestra entrega ni lectura.
Coordinar timeouts de proveedor, workflow, cliente PHP y proxy para permitir esa
respuesta síncrona, sin reintentos automáticos de POST desde el proxy.

### Recordatorio y cambio de horario

El recordatorio consulta `POST /api/integraciones/n8n/reservaciones/recordatorios/preparar`
y, antes del transporte, reclama la fuente/intento en `/recordatorios/reclamar`
bajo el mismo prefijo. Sólo `claimed:true` permite enviar; no reintentar ese POST.
El cambio de horario puede acusar recepción con `202`, que sólo acepta trabajo.
Ambos reportan después del proveedor a
`POST /api/integraciones/n8n/reservaciones/notificacion-resultado`, autenticados
con `X-N8N-Callback-Secret`. El callback contiene `event`, `source_id`, `attempt`,
`channel` y `status`, con **`accepted|failed`**. Recordatorio puede añadir
`retryable:true` exclusivamente ante rechazo inequívoco del proveedor.
Nunca llamar `delivered` o `read` a la aceptación del proveedor.

Un fallo de callback no prueba fallo de envío: reconciliar en PHP por fuente e
intento antes de reenviar. Duplicados y callbacks obsoletos deben ser inocuos.
Tras cinco minutos PHP recupera preparados no reclamados y rechazos explícitamente
reintentables, con máximo tres intentos técnicos y la misma fila funcional.
Un claim sin resultado no se reintenta automáticamente: requiere revisar evidencia
del proveedor. `accepted` nunca se recupera reenviando.

## Importación, exportación y aceptación

1. Resolver las condiciones previas anteriores y probar los contratos PHP/JSON.
2. Preparar una instancia TEST aislada con la [receta Compose](deploy/README.md),
   importar sólo los tres workflows y dejarlos sin publicar/inactivos.
3. Completar cada Configuración y asignar las cuatro credenciales de instancia
   (SMTP, Meta y dos Header Auth). Usar proveedores y destinatarios de prueba.
4. Deshabilitar guardado de ejecuciones exitosas, fallidas, manuales y de progreso
   en los ajustes de **cada** workflow; revisar que no anulen los defaults de
   Compose. No fijar datos con pinData ni registrar payloads/OTP/tokens/headers.
5. Probar Email, WhatsApp Text y Template, fallos y timeouts; verificar `200`
   posterior al proveedor y callbacks `accepted|failed`. Header ausente o erróneo
   debe impedir transporte y escritura. Probar reinicio, restore y rollback
   según [la lista de validación](deploy/README.md#validación-y-límites).
6. Publicar únicamente después de esos resultados. La publicación registra
   webhooks productivos y habilita el scheduler: usar siempre `/webhook/` desde
   PHP; `/webhook-test/` es temporal para pruebas del editor.

Los JSON revisados para versionar deben quedar sin `credentials`, `pinData`,
contactos, códigos, tokens, URLs de gestión ni datos de ejecuciones. Tampoco
exportar credenciales, ni siquiera como mecanismo de backup del repositorio.
Los valores específicos de cada entorno del Set se sustituyen por marcadores
neutros en el artefacto compartido; el operador conserva su inventario privado.

`node n8n/exportar.js` es la herramienta existente: lee la SQLite local en
`%USERPROFILE%/.n8n/database.sqlite`, elimina referencias de credenciales y exige
los tres nombres de reservaciones. **No se ejecuta en esta etapa y no es un
backup ni un exportador conectado al nuevo volumen Docker.** No apuntarlo a
datos vivos para probar esta receta; una futura adaptación queda fuera de alcance.

`sugerencias.json` y `areas-de-mejora.json` son otros módulos. Esta receta no los
importa, migra ni cambia sus secretos. Las fuentes oficiales, decisiones de
versión y límites de las validaciones constan en [deploy/README.md](deploy/README.md).
