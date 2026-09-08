# Rendimiento del envío de comanda

## Alcance

Este documento describe las dos correcciones **pendientes** del envío de comanda
desde el punto de venta (`POST /api/enviar-comanda`). La tercera —la que
provocaba el problema visible— ya está aplicada y se resume aquí sólo para
explicar qué queda sin resolver y por qué.

El diagnóstico completo, con mediciones reproducidas en el navegador, está en la
sección final.

## Estado actual

Aplicado en `classes/TicketPrinter.php`: la conexión a la impresora tiene un
límite de 2 segundos (`TicketPrinter::TIMEOUT_CONEXION`). Antes,
`NetworkPrintConnector` se construía sin timeout, `fsockopen()` caía en
`default_socket_timeout` (60 s) y el sistema se rendía a los 21 s por impresora.

Medido contra las cuatro impresoras de comanda apagadas:

| Escenario | Antes | Ahora |
|---|---|---|
| `POST /api/enviar-comanda`, una área | 21 249 ms | 2 216 ms |
| `TicketPrinter::imprimirComanda`, dos áreas | ~42 000 ms | 4 025 ms |

Eso quita el bloqueo de decenas de segundos, que es lo que hacía que el mesero
volviera a tocar el botón. **No quita la causa de fondo**: la impresión sigue
ocurriendo dentro de la petición, y el botón sigue sin defensa contra un doble
toque. De ahí las dos correcciones que siguen.

## Corrección 2 · Sacar la impresión del camino crítico

### El problema

`PuntoVentaController::enviarComanda()` hace, en este orden:

1. valida el ticket;
2. inserta los `ticket_items` y descuenta inventario;
3. **imprime las comandas** —una impresora por área, en serie—;
4. responde `{ok: true}`.

El paso 3 es un efecto secundario: el pedido ya está guardado y el tablero de
producción ya podría verlo. Aun así, el mesero espera a que el papel salga —o a
que la impresora se rinda— antes de recibir respuesta.

Con el timeout aplicado el costo es de 2 s por área inalcanzable, así que un
pedido con café, jugos y cocina todavía puede tardar 6 s. Sigue siendo tiempo
que el POS pasa bloqueado por algo que no afecta al pedido.

El efecto se amplifica porque `enviarComanda()` escribe y por lo tanto **no**
puede llamar a `Auth::liberarSesion()`: PHP mantiene el candado del archivo de
sesión hasta que el script termina, así que todas las demás peticiones del mismo
navegador se forman detrás. En la reproducción, dos `GET /api/ticket-items`
inocentes tardaron 21 s cada uno por estar encolados tras el envío.

### La corrección

Responder en cuanto los `INSERT` estén hechos y ejecutar la impresión después de
cerrar la respuesta. Dos caminos, según el despliegue:

- **PHP-FPM**: `fastcgi_finish_request()` justo después del `echo json_encode`.
  El cliente recibe la respuesta y el proceso sigue imprimiendo. Es el cambio
  más pequeño, pero sólo funciona bajo FPM: con el servidor embebido de
  desarrollo la función no existe y hay que degradar a la vía síncrona.
- **Cola de impresión**: persistir el trabajo (los `$itemsComanda` y su `$meta`)
  y que un proceso aparte lo consuma. Es más trabajo, pero es lo único que
  sobrevive a un reinicio y lo único que permite reintentar una comanda que no
  salió. `reportes_sistema` y `buzon_notificaciones` ya existen y están vacías;
  conviene decidir si una de las dos es el sitio o si la cola merece su propia
  tabla en `ddl.sql`.

Sea cual sea el camino, hay que resolver qué pasa con `print_ok`: hoy viaja en
la respuesta del endpoint, y si la impresión deja de ser síncrona ya no se puede
saber en ese momento. Lo razonable es que el POS deje de recibirlo y que un
fallo de impresora se vea donde ya se ve el estado de las impresoras, en el
módulo de administración.

### Cómo verificarlo

Con las impresoras apagadas, `POST /api/enviar-comanda` debe responder en
milisegundos, no en segundos, y los `ticket_items` deben quedar insertados
igual. La comanda fallida debe seguir apareciendo en `error_log`.

## Corrección 3 · Guardia contra el doble envío

### El problema

`apiEnviarComanda()` (`src/js/modules/punto-de-venta.js`) no tiene bandera de
petición en vuelo, no deshabilita `#mmodal-enviar` y sólo vacía `commandaItems`
dentro del `.then()`. Mientras el POST viaja, el botón conserva el mismo texto,
sigue habilitado y no hay ningún indicador de carga en la columna del pedido.

Medido en el navegador, medio segundo después de un clic único:

```json
{ "botonDeshabilitado": false, "textoBoton": "Confirmar y enviar (1)", "hayIndicadorDeCarga": false }
```

Para el mesero no pasó nada, así que vuelve a tocar. El segundo toque envía **el
carrito completo otra vez** y el pedido entra duplicado a producción. Reproducido:
dos toques → dos filas en `ticket_items` (658 y 659) → el mismo platillo dos
veces en `GET /api/area-items`.

Que el envío tarde poco lo hace menos probable, pero no lo impide: basta un
toque doble en la tablet, una red lenta o una impresora que tarde en rendirse.

### La corrección

El patrón ya existe en ese mismo archivo y sólo hay que aplicarlo aquí:
`apiAbrirTicket()` usa la bandera `ticketRequestInFlight` junto con
`setActionBusy(button, true)`, y `apiCerrarTicketDividido()` deshabilita
`#split-confirm` mientras espera.

Para el envío de comanda:

- una bandera propia (`comandaEnVuelo`) que descarte el segundo clic —propia y
  no `ticketRequestInFlight`, que gobierna la apertura de tickets y tiene otro
  ciclo de vida—;
- `setActionBusy(enviarBtn, true, 'Enviando…')` al empezar, que además da la
  señal visual que hoy no existe;
- liberar las dos cosas en un `.finally()`, para que un fallo de red no deje el
  botón muerto y al mesero sin poder reenviar.

### Idempotencia en el servidor

La guardia del botón evita el doble toque, pero no evita que la misma tanda se
inserte dos veces por otra vía: un reintento del navegador, una recarga a
destiempo o un cliente que no sea el POS. `enviarComanda()` acepta hoy cualquier
lote que le llegue.

Conviene que el POS genere una clave de idempotencia por tanda —un UUID creado
al construir el `payload`, no al enviarlo— y que el endpoint la rechace si ya la
vio. La forma más barata es una columna con índice único en `ticket_items`, o
una tabla de claves consumidas con su fecha; hay que decidirlo contra `ddl.sql`,
recordando que en este proyecto un cambio de esquema se escribe ahí y el entorno
se rehace.

### Cómo verificarlo

Dos clics seguidos sobre "Confirmar y enviar" deben producir **una** petición y
**una** fila por platillo en `ticket_items`. Con la idempotencia, dos POST
idénticos enviados a mano —con la misma clave— deben dejar también una sola.

## Anexo · El diagnóstico original

Síntoma reportado: el pedido tardaba varios segundos en salir del POS y llegaba
duplicado al tablero de producción, habiéndose pedido una sola vez.

Cadena completa:

1. `TicketPrinter::conectar()` construía `NetworkPrintConnector` sin timeout →
   `fsockopen()` con `default_socket_timeout` (60 s). Contra un host que no
   responde, Windows se rendía a los 21 s (`WSAETIMEDOUT`, 10060).
2. `imprimirComanda()` recorre las áreas **en serie**, y todo ello ocurre dentro
   de la petición, antes de responder.
3. El botón de enviar no se bloqueaba ni daba señal, así que el mesero volvía a
   tocarlo y reenviaba el carrito completo.
4. El candado de sesión de PHP encolaba detrás cualquier otra petición del POS,
   de modo que la pantalla entera parecía congelada.

Conviene saber que `deploy.sql` siembra las cinco impresoras con `activo = 1`
apuntando a `192.168.1.5x`. Cualquier instalación nueva arranca con impresoras
configuradas y, si no existen en esa red, con este comportamiento.

Traza de red de la reproducción, con dos toques sobre el botón:

```
POST /api/enviar-comanda             -> 200 (21 249 ms)
POST /api/enviar-comanda             -> 200 (33 993 ms)
GET  /api/ticket-items?ticket_id=122 -> 200 (21 220 ms)
GET  /api/ticket-items?ticket_id=122 -> 200 (21 229 ms)
```
