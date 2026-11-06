# Configuración

## Variables de entorno

El segundo factor administrativo solo muestra códigos en `development` o `test`
explícitos; vacío, ausente, desconocido y producción los ocultan. Configurar
correo y transporte para cada administrador antes de producción; consultar
[credenciales](usuarios/credenciales.md#segundo-factor-administrativo).
`JWT_N8N_SALIDA_SECRET` corresponde al helper de firma saliente y debe ser distinto
de `JWT_N8N_ENTRADA_SECRET`; los transportes salientes actuales de reservaciones
conservan `N8N_RESERVATIONS_WEBHOOK_SECRET`, sin migración implícita a JWT.

`.env` configura infraestructura y entorno; no debe contener reglas de negocio.
Define los datos de conexión (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) y
protege los secretos fuera del repositorio.

| Variable | Uso |
| --- | --- |
| `APP_ENV` | `development`, `test` o `production`; si falta se usa `production`, cualquier otro valor se rechaza. |
| `DB_PORT` | Puerto MySQL de la conexión local; por omisión 3306 en las herramientas de instalación. |
| `DEV_SUPERADMIN_PASSWORD` | Contraseña aportada localmente al bootstrap CLI de desarrollo identificado; su valor no se versiona. |
| `APP_TIMEZONE` | Zona de la aplicación; por omisión `America/Mexico_City`. Mantenerla alineada con MySQL y los procesos externos. |
| `SESSION_SAVE_PATH` | Ruta opcional para los archivos de sesión de PHP. |
| `NIP_LOOKUP_SECRET` | Secreto para localizar NIP mediante HMAC; no es una contraseña de usuario. |
| `N8N_BASE_URL` | Base de la instancia n8n que recibe webhooks desde PHP. |
| `N8N_RESERVATIONS_WEBHOOK_SECRET` | Autentica los webhooks PHP → n8n. |
| `JWT_N8N_ENTRADA_SECRET` | Llave HS256 (≥ 32 bytes aleatorios) con la que n8n firma el JWT de toda llamada a `/api/integraciones/*`. Obligatoria: sin ella esas rutas responden 500. Distinta de cualquier otro secreto. |
| `RESERVATION_PUBLIC_BASE_URL` | Base pública HTTPS que se incluye en enlaces de reservación. |
| `N8N_WEBHOOK_SUGERENCIAS_URL`, `N8N_WEBHOOK_AREAS_MEJORA_URL`, `N8N_SECRET` | Webhooks y autenticación que conservan sugerencias, analíticas/áreas y feedback. |
| `SITIO_DIRECCION`, `SITIO_DIRECCION_CORTA`, `SITIO_CORREO`, `SITIO_MAPS_URL`, `SITIO_INSTAGRAM`, `SITIO_WHATSAPP_EVENTOS` | Datos públicos del sitio y contacto para eventos. Son los del **restaurante**. |
| `PANADERIA_DIRECCION`, `PANADERIA_DIRECCION_CORTA`, `PANADERIA_TELEFONO`, `PANADERIA_MAPS_URL`, `PANADERIA_WHATSAPP` | Datos de la **panadería** (`/panaderia`, `Services\Panaderia\PanaderiaConfig`). Respaldo: Calle San Borja 1026, 55 1229 9648, una búsqueda de Google Maps por nombre y dirección, y el WhatsApp igual al teléfono. Cuando haya enlace corto de su ficha de Maps, va en `PANADERIA_MAPS_URL`. |
| `RESERVAS_TELEFONO_VISIBLE`, `RESERVAS_TELEFONO_TEL` | Teléfono público del restaurante, tal como se lee y para `tel:`. |
| `RESERVAS_WHATSAPP` | Respaldo del WhatsApp de reservaciones y catas: sólo se usa si el panel no tiene número guardado. |

No reutilizar `N8N_SECRET` como secreto de transporte de reservaciones. Nunca
versionar credenciales reales. La [operación n8n](../n8n/README.md) documenta sus
credenciales y configuración de workflows.

### Asistente IA

`/admin/ia` no lleva configuración propia. Responde con el **motor de consultas
rápidas** (`Services\Analytics\ConsultasRapidas`), no con un modelo de
lenguaje: cada pregunta es un `GET /admin/api/consultas` estructurado
(`?intencion=&id=&opcion=&periodo=`) contra la base. La integración con n8n que
se previó al principio (`IA_N8N_WEBHOOK_URL`) **se descartó**: esa variable ya
no se lee.

**No hay texto libre.** El compositor es una pregunta ARMADA: una de las
preguntas base del motor con sus variables como huecos que se eligen de listas
(«Ventas de [todo el restaurante ▾] · [Hoy ▾]»). Nace con un ejemplo real elegido por el servidor; se cambia de pregunta desde el catálogo de la conversación nueva, el
botón de la pregunta (fila de abajo del compositor) o Ctrl/⌘+K; y tras enviar se
queda con la que se contestó, lista para cambiarle un valor. Teclado: Tab
recorre los huecos, Enter, Espacio o ↓ abren su lista, y Ctrl/⌘+Enter envía.

Las preguntas base (`ConsultasRapidas::capacidades()`), por familia:

| Familia | Pregunta | Qué cuenta |
| --- | --- | --- |
| Ventas | Ventas totales | importe, tickets y ticket promedio |
| | Ventas de un platillo | unidades, importe y tickets, con desglose por opción |
| | Ventas por categoría | lo vendido de una categoría y sus platillos más pedidos |
| | Lo más vendido | los cinco platillos con más unidades |
| | Ticket promedio y comensales | promedio por cuenta y por comensal, comensales por mesa |
| | Platillos sin venta | platillos activos de categorías activas que nadie pidió |
| Equipo y servicio | Ventas por mesero | importe, tickets y propinas por mesero |
| | Horas pico | tickets por hora de **apertura** (llegada) |
| | Cancelaciones | renglones cancelados en cuentas cerradas |
| Inventario | Existencia de un ingrediente | stock, mínimo, valor y días de cobertura |
| | Consumo de un ingrediente | lo descontado por ventas (movimientos `venta` − `cancelacion`), merma y platillos que lo usaron |
| | Lo que está por agotarse | ingredientes bajo su mínimo |

Las ventas se cuentan como en el resto del panel: tickets cerrados, sin
renglones cancelados, por `COALESCE(hora_cierre, hora_apertura)`. Cada cifra
llega comparada con el periodo anterior de la misma duración; la flecha dice
hacia dónde y el color qué significa (más cancelaciones o más platillos sin
venta es peor; más consumo no es ni mejor ni peor). El consumo NO vuelve a
explotar recetas: suma los movimientos que dejó `Almacenes::consumirVenta()`
—la receta explotada al mandar la comanda—, así cuadra con la existencia
aunque la receta cambie después; por eso su periodo va por la fecha del
movimiento y no por la del cobro.

- La respuesta se pinta con la tarjeta del motor (`window.ConsultasRapidas.montar`,
  en `admin.js`): cambiar un hueco rehace esa respuesta en su sitio; un renglón
  abre una pregunta nueva.
- Lo que el motor no puede contestar (un platillo o ingrediente que ya no existe)
  se dice, con el catálogo debajo.
- La espera se corta a los 20 s; Detener aparece si pasa de 1.2 s. Un error de
  red, de sesión o de formato se muestra en el hilo con «Repetir».

Las conversaciones se guardan en `localStorage` del navegador
(`cp-admin-ia-conversaciones-v1`), no en la base: no se comparten entre equipos
ni entran en ningún respaldo, y «Borrar» las elimina sólo de ese navegador. Se
guardan **las 10 más recientes** (`AdminIaController::MAX_CONVERSACIONES`): al
abrir una más se borra la más antigua, lo guardado de antes se recorta al
cargar, y el pie del historial lo dice. Las preguntas de texto guardadas antes
de retirar el texto libre se pueden seguir repitiendo: van al intérprete del
motor (`?q=`), que se conserva para eso.

#### Panadería y Global

La misma pantalla vive en `/admin/panaderia/ia` y `/admin/global/ia`, cada una
con su API bajo el mismo prefijo (`/admin/panaderia/api/consultas`,
`…/catalogo`, `…/capacidades`): el prefijo es lo que fija el ámbito, y la
guardia y el motor lo vuelven a resolver desde la ruta. Responde
`Services\Analytics\ConsultasRapidasAmbito`, con la misma lógica (intención +
huecos → SQL preparado y acotado por unidad) y la misma forma de respuesta que
Restaurante; cambia el catálogo. Las abre el módulo **Asistente IA** del ámbito
(`ia` en ver); cada familia acepta además su módulo propio, como en Restaurante
(almacén con Inventario, menú con Menú). No hay texto libre: `?q=` responde 400.

| Ámbito | Familia | Pregunta | Qué cuenta |
| --- | --- | --- | --- |
| Panadería | Ventas en caja | Ventas en caja, de un producto, por categoría, lo más vendido, ticket promedio, productos sin venta, horas de más venta, cancelaciones en caja | tickets de caja de su unidad, contados como en Restaurante; sin meseros ni comensales |
| | Almacenes | Existencia, lo que está por agotarse, consumo, entradas, mermas, transferencias en curso | sólo sus almacenes |
| | Menú | Productos sin receta | ofertas activas sin receta activa |
| Global | Almacenes de la organización | Existencia, lo que está por agotarse, consumo, entradas, mermas | **consolidado** de todos los almacenes, desglosado por negocio |
| | Entre negocios | Transferencias en curso, productos de la casa | transferencias abiertas de la organización; estándares, dónde se ofrecen y su receta publicada |

- **Ventas en caja de Panadería**: sus preguntas leen los tickets que cierra
  la caja de mostrador ([caja](modulos/caja-panaderia.md)); las anulaciones
  quedan fuera porque el ticket anulado deja de estar `cerrado`. El cajero
  queda en `mesero_id`, así que «ventas por cajero» ya tiene de dónde leer.
- **Global no vende**: no tiene preguntas de ventas ni suma las de los
  negocios (consolidado financiero pendiente, arquitectura §3). Su lectura de
  almacenes es la misma que Almacenes concede con permiso de ver, y no abre la
  escritura de ningún almacén ajeno.
- **Entradas** cuenta sólo movimientos `entrada`: una transferencia interna es
  salida de un almacén y entrada de otro, y contarla sumaría dos veces lo mismo
  en el consolidado. Las transferencias tienen su propia pregunta.
- Una **transferencia abierta** es la que está solicitada, autorizada, enviada
  o con incidencia.
- El historial del navegador va **por ámbito**
  (`cp-admin-ia-panaderia-conversaciones-v1`, `cp-admin-ia-global-…`);
  Restaurante conserva sus claves. Plegar el historial vale para los tres.
- Prueba: `php scripts/tests/run-consultas-ambito-db.php`, contra la base de
  desarrollo en una transacción que se revierte.

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
- `/admin/configuracion/areas`: el **color de cada estación de producción**
  del **Restaurante** (Barra de Café, Barra de Jugos, Cocina y Horno
  Napolitano), en la columna `areas_produccion.color`: no hace falta migración.
  Con el esquema multinegocio sólo lee y guarda las de la unidad
  `restaurante` (el slug sólo es único dentro de su negocio). Se elige
  sólo entre los diez colores de la paleta funcional y dos estaciones no pueden
  repetirlo (`Services\Configuration\AreasProduccion::validar()`); se guarda el
  hex. Lo leen el tablero KDS de piso (`/area/*`), las tarjetas de Producción
  del panel (`/admin/area`, que enlazan a ese tablero; `/admin/area/<slug>`
  redirige ahí), el selector de estación, Impresoras y el encabezado de área
  al confirmar una comanda en el POS. Un tablero abierto lo toma al recargarse.
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
  queda por debajo, el guardado pide confirmación explícita.
- `/admin/configuracion/avisos`: los **avisos del restaurante en las cartas
  PDF** (tabla `configuracion_avisos`, varias filas por unidad; hasta 12). Cada
  aviso lleva título (80 caracteres), contenido (1 500), en qué carta se
  imprime (Menú, Maridaje o las dos), orden y si está activo. Todos se
  imprimen al final de la carta, entre la última sección y la despedida;
  ninguno va entre la portada y la primera sección, y no hay lugar que
  elegir. Van en dos columnas, como los platillos, en el orden de la lista
  (izquierda, derecha); si sobra uno, va solo y centrado a lo ancho. El contenido se guarda como marcado ligero, nunca HTML: `## ` al
  inicio de un renglón es subtítulo, `**texto**` va en negritas y un renglón en
  blanco abre párrafo; todo lo demás se escapa
  (`Services\Configuration\AvisosCarta::html()`, la única traducción, que usan
  el PDF y la vista previa). Lo imprimen `/menu/pdf`, `/maridaje/pdf` y los PDF
  administrativos de Restaurante; la Panadería no. Sin la tabla, la carta sale
  igual y sin avisos. Permiso: el módulo `configuration` (ver para consultar,
  editar para cambiar; las escrituras lo releen en su transacción). El editor
  tiene barra de formato con Ctrl/⌘+B (negritas), Ctrl/⌘+Mayús+2 (subtítulo)
  y Ctrl/⌘+Enter (guardar), vista previa en vivo y aviso de cambios sin
  guardar; sin JS funciona como formulario. La tabla está en `database/ddl.sql`.

- **Variantes por platillo**: opciones de precio y receta de la oferta local;
  administración en Menú desde «Editar platillo», que las resume y abre el
  editor en un panel (el listado ya no tiene botón propio: «N opciones» junto
  al nombre abre `edit?id=…#variantes`; la página `/admin/menu/variantes`
  sigue existiendo). Cada
  opción lleva un icono (`producto_variantes.icono`): uno de la familia `op-*`
  (`Variantes::ICONOS`) o NULL, automático, que se deduce del nombre de la
  opción y de su grupo (`Variantes::ICONO_REGLAS`, las mismas reglas en el
  editor y en el servidor); sin regla que case, el POS pinta la inicial, y si
  ninguna opción del grupo tiene icono, teselas de sólo texto. En el POS cada
  grupo es un paso. Identidad de oferta, componentes y snapshots se rigen
  por [Arquitectura](arquitectura.md#6-catalogo-productos-cartas-ofertas-y-menu-preservado).
- **Foto por platillo**: ImagenUploader valida y publica la imagen; sin foto propia
  la carta utiliza la de categoría. No retirar imágenes preservadas del menú.
- **Usuarios y permisos**: asignación directa por usuario, unidad y módulo, con
  facultades y techos de delegación. Los roles internos no conceden acceso ni
  existe gestión funcional de plantillas; consultar [Usuarios](usuarios/usuarios.md).
- **Especiales de temporada**: ofertas de la carta de temporada, con disponibilidad
  e imagen propia o fallback de categoría. La identidad de carta y negocio se
  resuelve en el modelo vigente, no en un ENUM histórico.

La instalación usa el [procedimiento único](desarrollo/instalacion.md) y el DDL
versionado. No aplicar recetas ALTER de revisiones anteriores a una base activa.

Consulta las reglas de [operación POS e impresión](operacion.md),
[reservaciones](reservaciones/reservaciones.md) y
[notificaciones](reservaciones/notificaciones.md).

## Instalación y credenciales de desarrollo

La configuración local se prepara según la [guía de instalación](desarrollo/instalacion.md).
`includes/.env` nunca se versiona. El bootstrap no corre por una petición HTTP,
no acepta un entorno ambiguo ni activa automáticamente `admin_demo` en producción.
La contraseña y `NIP_LOOKUP_SECRET` se aportan localmente; no se publican sus valores.

## API compartida de consultas rápidas

window.ConsultasRapidas ofrece configurar (la API del ámbito de la página),
consultar, montar, capacidades, catalogo, elegir,
consultaDeOpcion, teclaDeHueco, selectorAbierto y cerrarSelector. montar devuelve
consultar/pintar/limpiar/destruir/ultima y emite consulta-rapida:cambio y
consulta-rapida:respuesta. Sus opciones incluyen ambito, alCambiar, alPintar,
alOcupado, alLimpiar, anunciar, enlace y nivelTitulo.

La respuesta conserva interpretacion, plantilla y respuesta; valor numérico mide
barra y valor_texto presenta el dato. tendencia expresa dirección y lectura
bien/mal/igual expresa significado. Un recurso inexistente devuelve entendida
false/sin_resultado con rescate; una intención desconocida es error 400.

El selector de huecos es único, fijo y portado a body. Con búsqueda usa dialog,
combobox y aria-activedescendant; sin búsqueda, foco al listbox. Escape devuelve
foco al hueco, Tab sigue desde él y el repintado conserva campo enfocado.
El compositor conserva ediciones hechas durante la espera; los ejemplos vienen
del catálogo real y hasta ocho combinaciones por pregunta.

El intérprete de preguntas guardadas reconoce hora/franja/día: mañana hasta
12:59, tarde hasta 18:59 y noche desde 19:00. Un día de semana sin periodo usa
ocho semanas. Las consultas de producto/categoría/top filtran por hora del
pedido; el total no se separa por hora. Los huecos estructurados no añaden ese
filtro. Ver run-consultas-por-hora.php y run-consultas-rapidas-db.php.
