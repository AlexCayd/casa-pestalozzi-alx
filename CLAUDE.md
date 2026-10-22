# Casa Pestalozzi

Sistema de gestión para el restaurante: landing público con carta y reservaciones,
punto de venta para meseros, tableros de producción por área, y panel de
administración (menú, inventario, recetas, analíticas, catas, usuarios,
configuración).

## Stack

PHP 8 MVC **hecho a mano**. No hay framework, ni ORM, ni Tailwind, ni librería de
componentes. Lo que parezca "convención de framework" aquí es código propio.

- **Router**: `Router.php` — mapa plano de URLs exactas a `[Controller::class, 'metodo']`.
  Tres verbos (GET/POST/DELETE), sin parámetros de ruta ni regex: los IDs viajan
  por query string o en el cuerpo JSON. Todas las rutas se registran en
  `public/index.php`.
- **Datos**: MySQL por `mysqli` crudo + ActiveRecord propio (`models/ActiveRecord.php`).
  El SQL se arma por concatenación de strings; usa `escaparString()` / `escaparLike()`
  o sentencias preparadas (`$db->prepare`) para todo lo que venga del usuario.
- **Lógica de negocio**: en `services/`, no en los controladores.
- **Vistas**: plantillas PHP en `views/`. `Router::render()` bufferiza la vista y la
  envuelve en `views/layout.php` (o el layout del módulo).
- **Frontend**: JS vanilla ES5 (`var`, IIFEs, `fetch`, HTML por concatenación) y SCSS.
  Sin bundler más allá de gulp-concat/terser.
- **Autenticación y permisos**: `classes/Auth.php` + `services/Security/Permisos.php`.
  `Auth::proteger()` corre en `public/index.php` **antes** del ruteo; la decisión
  la toma `Permisos::decidir()` (pura, sin base ni sesión) y Auth sólo carga el
  rol y responde. Ver «Roles y permisos» abajo. Dos reglas que no perdonan:
  **una ruta `/admin/*` sin módulo en `Permisos::RUTAS` sólo la abren los roles
  de sistema** (y `run-roles-permisos.php` la delata), y **una ruta `/api/...`
  que no esté en `Permisos::APIS` queda pública**.

### Rutas y convenciones

- Páginas: renderizan HTML. Mutaciones de admin: POST-redirect-GET.
- JSON: prefijo `/api/...` o `/admin/api/...`, responden `echo json_encode(...)`.
- Los controladores de POS/áreas comparten helpers (`entradaJson()`, `responder()`).

### Build

```
npx gulp build      # todo (`npx gulp` a secas no existe)
npx gulp js         # solo JS de la landing
npx gulp css        # solo SCSS de la landing
npx gulp operationAppCss adminMapJs   # el piso: operation.css y el POS
```

Mapa de salidas (`gulpfile.js`) — **editar `src/`, nunca `public/build/`**:

| Fuente | Salida | Quién la carga |
|---|---|---|
| `src/scss/app.scss` | `public/build/css/app.css` + `assets/css/` | landing y **feedback** |
| `src/scss/admin/shared/app-admin.scss` | `public/build/css/admin.css` | panel |
| `src/scss/admin/modules/*.scss` | `public/build/css/admin/*.css` | cada módulo del panel |
| `src/scss/operation/app-operation.scss` | `public/build/css/operation.css` | **POS, áreas y login** |
| `paths.adminMapJs` (POS: `punto-de-venta.js` + shell/mapa + `core/select.js`) | `public/build/js/admin/map.js` | POS |
| `src/js/admin/area/area.js` | `public/build/js/admin/area.js` | módulo de áreas del panel |
| resto de `src/js/` | `public/build/js/bundle.min.js` | landing y feedback |

`src/js/modules/punto-de-venta.js` está **excluido** del bundle general a propósito;
llega al POS dentro de `map.js`, que empaqueta varios archivos más (ver
`paths.adminMapJs`). El panel admin carga **solo** `admin.js`, nunca
`bundle.min.js`: lo que deba estar disponible en ambos va en las dos listas.

Los bundles son concats en **scope global**, no módulos, así que el orden de la
lista es lo que resuelve las dependencias. Dos casos que hay que respetar:
`core/icons.js` va primero en `paths.adminJs` porque define `window.AdminIcons`,
que consumen `buzon.js` y los bundles de módulo; y `sankey.js` va antes que
`finanzas.js` por lo mismo. Y como `views/admin/layout.php` carga `admin.js`
**antes** que cualquier bundle de módulo, los módulos pueden contar con lo que
`admin.js` haya publicado.

Dos bundles administrativos y no uno: `admin.css` (panel) y `operation.css`
(piso). Los dos salen de `admin/shared/`, así que el watch de esa carpeta
regenera ambos. Había un tercero, `operation/reservations.css`, de la pantalla
de Operación de reservaciones; se retiró con ella.

⚠️ `npx gulp adminModuleCss` (y `build`) recompila TODOS los módulos del
panel: si hay SCSS de un módulo a medio editar, su CSS sale en el mismo
cambio. Para tocar sólo el piso, `operationAppCss` y `adminMapJs`.

El POS cargaba `app.css` entero —la landing completa, 470 KB— sólo para llegar a
su propio SCSS. Ya no: `app.css` bajó a ~220 KB y el piso tiene lo suyo.

> Verificar con `php -l`, `node --check` y recorridos manuales.

## Base de datos

**Tres archivos, y el orden es el nombre:**

1. `database/ddl.sql` — estructura, DROP+CREATE completo.
2. `database/deploy.sql` — datos mínimos de operación. Es la **única** fuente del
   catálogo base: áreas, categorías, mesas, productos, `productos_semilla` y el
   horario semanal.
3. `database/development.sql` — opcional, sólo desarrollo y QA. Funde los tres
   fixtures que antes vivían sueltos (`dml_pruebas`, `analiticas-datos-ex`,
   `REVPash-pruebas`) en tres bloques que se acotan por rango para no pisarse:
   analíticas trabaja sobre los tickets 200-299 y los tokens
   `fx-analytics-res-%`; RevPASH sobre los 300-499 y `fx-revpash-res-%`.

   **Los tres bloques se anclan a `CURDATE()` al cargar el dump.** Dos usaban
   fechas absolutas —noviembre-diciembre de 2026 los escenarios de reservación,
   la primera semana de agosto de 2026 el de RevPASH— y eso las condenaba a
   caducar: cargado el dump unos meses después, las reservaciones quedaban a tres
   meses vista y el panel abría vacío con la base llena. Ahora se derivan de un
   solo `SET` por bloque: `@fecha_principal` (hoy + 3 días, y las otras cinco
   fechas cuelgan de ella) y `@REVPASH_ANCLA`. El desplazamiento de RevPASH va en
   **múltiplos de siete días** para no romper lo único que ese fixture necesita:
   que cada día caiga en su día de la semana, que es lo que da forma al mapa de
   calor. `SELECT @SEM_INI, @SEM_FIN;` dice en qué fechas quedó.

   Además hay un bloque **«Reservaciones de hoy»** (tokens `fx-hoy-%`): un día
   de servicio en `CURDATE()`, con nombres de cliente y mesas, para que el POS
   abra con reservas. Es idempotente (borra por token y vuelve a sembrar) y el
   estado sale del reloj al cargar: lo ya pasado entra atendido. Toda reserva
   confirmada del dump tiene mesas y ninguna mesa se reserva dos veces en un
   mismo intervalo de 90 minutos; al añadir fixtures hay que mantenerlo.

Al final hay un **bloque 4, «Variantes por platillo»**: ingredientes 35-71
(leches vegetales, jarabes, licores de barra…), receta para los platillos que
no la tenían (Latte, té, jugos, smoothie, coctelería), la receta base de los
platillos con variantes (mismos valores que el bloque 1), ~30 platillos con
grupos y ajustes, y tickets **900-999** de los últimos siete días con
variantes, para que las consultas rápidas tengan qué responder. Es
reidempotente y se puede correr suelto sobre una base existente.

Después van un **bloque 5, «Especiales de temporada»** (cinco platillos de
muestra de la categoría 15 para la vitrina de la landing, tres con foto propia
y dos con la de su categoría; reidempotente por nombre) y un **bloque 6, «Uso
del asistente»**: dos meses y medio de
operación cerrada para que el Asistente IA conteste sus doce preguntas en
todos sus periodos, comparación incluida. Usa tickets **10000-19999**,
ticket_items **100000-199999** y movimientos_inventario **1000000-1999999**;
ticket_mesas y ticket_pagos caen en cascada con su ticket. **No lleva ninguna
fecha literal**: se ancla a `@ASISTENTE_AHORA := NOW()` y a su fecha, y la
ventana va del día 1 de hace dos meses (o de hace 74 días, lo que quede antes)
hasta la hora de la corrida; de HOY sólo trae lo ya cobrado, así que cada vez
que se corre «hoy», «ayer», «esta semana» y «este mes» vuelven a tener datos.
Siembra de 20 a 48 tickets por día con patrón semanal y la curva de
`horarios_operacion`, unos pocos superventas y ocho platillos que nunca se
venden, variantes como las resuelve `Variantes::resolver`, un ~3 % de
renglones cancelados y un movimiento de `venta` por ingrediente con la misma
cuenta de `aplicarVenta()`, más su `cancelacion` y unas tres mermas por
semana. El azar sale de MD5 de la fecha, así que dos corridas el mismo día
dejan lo mismo. Es reidempotente y se corre suelto (copiar del encabezado del
bloque hasta «Fin del bloque 6»; tarda ~10 s). **No toca
`ingredientes.stock`**, y sube el AUTO_INCREMENT de esas tres tablas al final
de su rango para que lo que cree el POS después no caiga dentro; por eso los
folios nuevos salen con cinco cifras. Va siempre al final, después del 4 y del
5 (siembra ventas sobre el catálogo completo): si se vuelve a correr el 4
(recrea las variantes con ids nuevos) o el 2 (borra los ingredientes 10-99 y,
en cascada, sus movimientos), hay que correr otra vez el 6. Para ver en qué
fechas quedó: `SELECT DATE(MIN(hora_apertura)),
MAX(hora_cierre), COUNT(*) FROM tickets WHERE id BETWEEN 10000 AND 19999;`

Se carga **una vez**, sobre una base recién creada: los bloques 2 y 3 sí se
pueden repetir, pero el 1 usa ids explícitos sin `ON DUPLICATE KEY` y una segunda
pasada choca contra `tickets.PRIMARY`. Para rehacer el entorno, recrear la base.

`development.sql` declara los seis usuarios de desarrollo con id explícito porque
los tickets de los fixtures apuntan a `mesero_id` 2..6 por llave foránea, pero los
deja **sin credencial utilizable**. Para que además puedan entrar hay que correr
después `php scripts/seed-usuarios-prueba.php`, que calcula el HMAC de cada NIP
con `NIP_LOOKUP_SECRET` y los enseña sólo en la salida de esa ejecución. Casa por
`username`, que es UNIQUE, así que actualiza esas filas en vez de duplicarlas.
Credenciales de demo en `docs/usuarios/credenciales.md`.

Fuentes documentales vigentes: `docs/arquitectura.md`, `docs/config.md`,
`docs/operacion.md`, `docs/reservaciones/reservaciones.md`,
`docs/reservaciones/notificaciones.md`, `docs/usuarios/usuarios.md`,
`docs/usuarios/roles-y-permisos.md` (incluye el plan de la integración
multiempresa) y `docs/privacidad/privacidad.md`. Las reglas de mesas y reservaciones tienen una
fuente normativa en `docs/reservaciones/reservaciones.md`.

El esquema vigente está en `database/ddl.sql`. Los datos mínimos de operación
están en `database/deploy.sql`; `database/development.sql` es opcional y se usa
sólo para desarrollo y QA.

`ddl.sql` empieza con los `DROP TABLE` en orden inverso de dependencias. Al
agregar una tabla, añade su `DROP` al bloque correspondiente.

Cosas que cargan peso y no son obvias:

- `productos` es la **única** fuente de platillos. La tabla `menu` es legado de
  solo lectura; no escribir ahí.
- `productos.nombre` es UNIQUE por dependencia funcional: el descuento de
  inventario, el COGS y el motor de sugerencias unen por nombre, no por id.
- `categorias.carta` (`comida` | `maridaje` | `temporada`) parte el catálogo en las **dos
  piezas impresas**: la carta de comida y la de maridaje (barra). Vive en la
  categoría y no en el producto porque la carta es una decisión de SECCIÓN —
  «Cocktails» entero es maridaje—, y se administra desde el **mismo módulo de
  Menú**: pastillas en el alta rápida del tab «+» y en la edición de la
  categoría, más una columna en el listado. El listado además se **filtra por
  carta** (`?carta=comida|maridaje`, selector «Ambas / Comida / Maridaje» sobre
  las pestañas de categoría): acota las pestañas y la tabla, y un valor fuera
  de la lista cae a «ambas». Sustituyó a los dos botones de PDF del encabezado,
  así que desde el panel ya no se abre ningún PDF; las rutas
  `/admin/menu/pdf*` siguen registradas pero sin enlace. Tres consecuencias:
  `Carta::publica($carta)` filtra por ella y **por defecto devuelve comida**,
  así que la sección de menú de la landing y `/menu/pdf` siguen siendo lo que
  eran; `/maridaje/pdf` (admin: `/admin/menu/pdf/maridaje`) es la otra pieza,
  y sale de la MISMA plantilla —`items-pdf.php`, que recibe rótulo, bajada,
  título y aviso de vacío y no sabe cuál imprime—; y `Carta::paraPos()` **no**
  la mira, porque el mesero cobra la copa y el plato en el mismo ticket.
  ⚠️ El interruptor de visibilidad del listado de categorías reenvía la fila
  por campos ocultos: si añades una columna a `categorias`, tiene que viajar
  ahí también o apagar una categoría la devuelve a su valor por defecto.
- **Especiales de temporada** son el tercer valor de `categorias.carta`
  (`temporada`), y no un módulo: la vitrina de la landing
  (`views/home/_firma.php`) pinta los platillos activos de esas categorías
  desde `Carta::especiales()`, impresos en el servidor. `deploy.sql` siembra
  la categoría «Especiales de temporada» (id 15) **sin platillos**; la cocina
  los da de alta en Menú cada semana y los apaga con el interruptor de
  visibilidad. Antes eran un arreglo fijo en `src/js/modules/gallery.js`, que
  ahora sólo engancha el clic y el avance con el scroll; las tarjetas usan el
  marcado y el SCSS de siempre (`.gcard`, `_galeria.scss` sin cambios). Cuatro
  reglas: no salen en la sección de menú ni en ningún PDF (`MenuPdf` cae a
  comida si se le pide `temporada`) y el POS sí los vende; la foto es
  **opcional** —el campo del formulario pasa a llamarse «Foto para la
  landing»— y sin foto propia el especial toma la de su categoría
  (`especiales-temporada.webp`, archivo propio porque reemplazarla borra el
  anterior); sin ninguna de las dos no sale, porque la vitrina sólo pinta
  tarjetas de foto; una semana sin especiales deja la sección con una línea
  `.lead`, para no mover su número ni su enlace del índice; y
  `run-especiales-temporada.php` falla si el ENUM del DDL se separa de
  `CategoriasMenu::CARTAS`.
- `ticket_items.estado`: `enviado → en_preparacion → listo → entregado`, más
  `cancelado`. Producción avanza hasta `listo`; `entregado` lo marca el mesero.
- **Variantes por platillo** (`producto_variantes` + `variante_ajustes`,
  servicio `Services\Menu\Variantes`): grupos de opción de elección única
  («Leche», «Tamaño», «Término») que mueven el precio y ajustan la receta. Un
  grupo con opción `predeterminada` es la receta base —el POS la trae marcada
  y la comanda no la imprime—; un grupo SIN predeterminada es de elección
  obligatoria. El ticket_item conserva `nombre` sin tocar (todas las uniones por
  nombre siguen valiendo) y la variante viaja aparte: `variante_ids` (todas las
  opciones elegidas, ordenadas, para descontar y desglosar) y `variante` (el
  rótulo congelado, sin las predeterminadas, para comanda, KDS, cuenta y
  consultas). Tres reglas que no se deducen del código:
  - **El precio de una opción lo decide el servidor** (`Variantes::resolver`
    en `enviarComanda`), nunca la tablet; y una opción inválida o un grupo
    obligatorio sin elegir rechaza la comanda ENTERA antes de insertar nada
    (`VARIANTE_INVALIDA`).
  - **«Sustituir» es proporcional** (`Inventario::explotarReceta`): reemplaza la
    fracción `cantidad ÷ lo que la receta base lleva de ese componente` de lo
    que el platillo lleve en ese momento, también lo que sume otra opción. Así
    «Almendra» (120 de 120 ml) cambia toda la leche de un «Grande», y
    «Divorciados» (40 de 80 ml de salsa) cambia la mitad. El editor del panel
    (`src/js/admin/menu/variantes.js`) repite la misma explosión para su vista
    previa: si cambia en un sitio, cambia en el otro.
  - **El guardado conserva los ids** (`Variantes::guardar` actualiza en vez de
    borrar y recrear): una tablet que cargó `CP_MENU` antes lleva esos ids en
    su carrito.
  Se editan en Menú → icono de deslizadores de cada platillo
  (`/admin/menu/variantes?id=`). El COGS de Finanzas agrupa por variante.
  **En el POS** la tarjeta del platillo no lleva pastilla «Opciones»: todo
  platillo con grupos abre la hoja al tocarlo, también si todos traen
  predeterminada (nace marcada y con el foco en «Agregar»). El botón de la hoja
  lleva verbo y precio separados (`.mmodal-opts__verbo` / `__precio`), con
  «desde $X» si falta una elección obligatoria y «N × $X» al editar varias
  unidades. No hay botón «Cambiar» en el pedido: la fila
  (`.mmodal-cart-row--editable`) abre la hoja, el botón real es
  `.mmodal-cart-name` (`data-editar-opciones`) y −/+, Nota y el campo de la
  nota quedan fuera del toque por `closest()`. Sólo lo pendiente de enviar se
  edita así.
- `ticket_mesas` es la fuente canónica de ocupación física, no `tickets`.
- `catas` es una tabla de **anuncio**, no de reservas. `catas.disponible`
  significa **una sola cosa: si quedan lugares**. No decide la visibilidad —eso
  lo decide el reloj— así que apagar el interruptor NO retira la cata de la
  portada: la deja anunciada, marcada como «sin cupo» y **bloqueada**, sin
  ningún enlace. Antes ofrecía un segundo CTA («avísame si se libera»); se
  retiró porque invitaba a escribir por algo que no se puede dar, y el sello del
  titular más el atenuado de `.cata--sin-cupo` ya dicen que está cerrada. Nace
  en `1`,
  porque una cata recién programada admite gente. La agenda pública es todo lo
  que no ha ocurrido todavía; lo único que sale de la portada es el pasado.
  Consecuencia asumida: no hay estado de borrador, una cata es pública en cuanto
  se guarda. `idx_catas_agenda` va por `(fecha, hora)` — encabezarlo con el
  booleano dejó de servir cuando la consulta pública dejó de filtrar por él.
- **La landing ya no tiene ningún formulario anónimo.** Los dos que había
  —inscripción a catas y cotización de catering— se fueron a WhatsApp, y con
  ellos las tablas `cata_inscripciones` y `catering_solicitudes`, el cupo, los
  endpoints `/api/catas/*` y `/api/catering/*`, y el tope de envíos por contacto
  que sumaba las dos tablas. Catering no tiene módulo en el panel: su sección de
  la landing es la rejilla de ocasiones de `SitioConfig::OCASIONES_EVENTO`, cada
  una un enlace con su frase ya escrita. Las catas escriben al WhatsApp del
  restaurante (`Services\Reservations\ReservacionConfig::whatsappUrl($mensaje)`); catering, al de
  eventos (`SitioConfig::whatsappEventosUrl()`), que **es otro número**.
- `usuarios` tiene dos vías de acceso: `password_hash` (usuario+contraseña)
  y `nip_hash` + `nip_lookup` (NIP de **4 dígitos**). Cuál usa una cuenta lo
  decide su ROL (ver «Roles y permisos»). El lookup es un HMAC con
  `NIP_LOOKUP_SECRET` para resolver la fila directamente; `password_verify` sólo
  confirma la credencial encontrada. La generación, rotación y migración están
  documentadas en `docs/usuarios/usuarios.md`.
- **Roles y permisos** (`/admin/roles`, módulo `roles`; documentación completa
  y plan multiempresa en `docs/usuarios/roles-y-permisos.md`). `usuarios.rol` es un
  slug con llave foránea a `roles.slug`; `rol_permisos` guarda, por rol, un
  renglón por módulo con `ver` o `editar` (sin renglón, sin acceso). Los módulos
  son las claves de `AdminController::MODULES` —el menú del panel—, y `pdv` y
  `area` además abren las pantallas de piso (`/punto-de-venta`, `/area`).
  Lo que hay que saber:
  - **Ver = GET/HEAD, editar = todo lo demás**, en la guardia central: una ruta
    nueva hereda la regla sola. Un formulario de un rol que sólo consulta vuelve
    a su pantalla con el aviso de `AppNotice` que pinta el layout; una API
    responde 403. Los botones de editar **no** se esconden por módulo (decisión
    de la primera entrega): el servidor es la frontera.
  - **Tres tipos de rol**: `sistema` (`super_admin`, `admin`: todo en editar,
    calculado, ni se editan ni se borran), `predefinido` (`waiter`, `cook`:
    se editan sus permisos, no se borran) y `personalizado`.
  - **La vía de acceso se deduce, no se elige** (`Permisos::acceso()`): con algún
    módulo del panel, contraseña; sólo piso, NIP. Por eso un rol con usuarios no
    puede cambiar de vía (sus cuentas tienen la credencial de la otra) y los
    logins filtran por `Rol::slugsConAcceso()`.
  - **Nadie da lo que no tiene** (`Permisos::puedeOtorgar`). El super admin
    asigna cualquier rol; el admin, cualquiera que no sea de sistema (no crea
    ni edita cuentas de admin ni de super admin); un rol personalizado con
    Usuarios o Roles, sólo roles que caben en los suyos. La cuenta que no se
    puede perder es el **último super admin activo**.
  - **Cada petición protegida relee el rol y el estado** de la cuenta: cambiar
    permisos, reasignar o desactivar vale desde la siguiente acción, sin cerrar
    sesión. Mesero y Cocinero tienen permisos de respaldo
    (`Rol::PERMISOS_POR_DEFECTO`) por si la base no responde.
  - **Quién es «admin» en el POS**: las capacidades administrativas de
    reservaciones (comentario interno, ver contacto, capturarlo en el alta) son
    de quien tiene `reservations` (ver para mirar el contacto, editar para el
    resto), no de un rol con nombre. Las listas de «meseros» (asignar mesero a
    un ticket, desempeño) siguen siendo sólo el rol `waiter`.
  - **Unidades** (`unidades`: Restaurante, Panadería; `usuarios.unidad_id`):
    sólo se asignan a cuentas con rol `admin`, sólo las asigna el super admin y
    **hoy no restringen nada**: son la base de la integración multiempresa.

## Design system

Todo el color vive en **custom properties de CSS**; las variables Sass son solo
estructurales (breakpoints y anchos, en `src/scss/admin/shared/abstracts/_variables.scss`).

**Son DOS sistemas visuales distintos, y es a propósito.** La marca del
restaurante vive en la landing (y por ahora en POS, áreas y feedback); el panel
administrativo tiene identidad propia —blanco y negro— para poder despegarse
como producto sin arrastrar el manual del restaurante. No comparten un solo
color ni una sola tipografía.

Dos archivos de tokens, y son la única fuente de verdad:

- `src/scss/layout/_reset.scss` → `:root`, para landing / POS / áreas.
- `src/scss/admin/shared/base/_globals.scss` → `.admin-body`, con temas claro y
  oscuro bajo `[data-admin-theme]` en `<html>`. **El claro es el valor por
  defecto** (`.admin-body` a secas), fijado antes del primer paint por un script
  inline en `views/admin/layout.php` que en la primera visita consulta
  `prefers-color-scheme` y a partir de ahí obedece a `localStorage`.

### Todo cambio visual pasa por el subagente `ux-ui`

**Obligatorio**, no opcional: antes de dar por terminado cualquier trabajo que cree o
modifique un archivo de `src/scss/`, un JS con animación, scroll o interacción, o el markup
de una vista con implicación visual, hay que invocar el subagente **`ux-ui`**
(`~/.claude/agents/ux-ui.md`, nivel de usuario: sirve a todos los proyectos) con la lista de
archivos tocados y qué debía conseguir la pantalla. Juzga concepto, jerarquía, tipografía,
color, movimiento y accesibilidad, y decide si entra tal cual.

Ahí vive el criterio de diseño; aquí, la mecánica de este proyecto. El agente lee este
archivo primero, así que lo de esta sección manda sobre cualquier preferencia suya — en
particular **que son dos sistemas visuales distintos a propósito**: proponerle a la landing
algo del panel, o al revés, es un error de lectura, no una idea.

### Paleta de la landing

**La base es el manual de identidad** (`Manual de Identidad Casa Pestalozzi
Draft.pdf`, en la raíz): **verde musgo y oro** como principales —50 % y 40 % de
la superficie en el manual—, off-white de papel y el beige de la casa. Son los
cuatro únicos hex de marca del proyecto y viven en la *capa 1* de `_reset.scss`:

| Token | Hex |
|---|---|
| `--brand-verde` | `#214F36` |
| `--brand-oro` | `#D1AB66` |
| `--brand-beige` | `#e3d5bb` |
| `--brand-crema` | `#F5F1E8` |

**El café (`#4a2f21`) salió de la marca**: era un marrón que el manual no tiene
y la landing lo usaba de acento y de fondo oscuro. Sus bandas son ahora la
**banda de oro** (`[data-tono="oro"]`: Chef, la tarjeta de Panadería, Catas) y
el pie y el lightbox pasaron a verde. `cafe` y `vino` se conservan como alias
de `oro` por si queda marcado viejo. Ni `--brand-cafe` ni `--cafe-*` existen:
un consumidor nuevo que los nombre se queda con una declaración inválida.

**El oro NO es tinta sobre papel**: sobre crema da 1.9:1. Es relleno (el botón
principal, la banda de oro), filete (`--line-soft` en los tonos claros, las
líneas sobre verde) y adorno. Por eso en los tonos claros `--accent` es oro
pero `--accent-text` y `--accent-soft` —que casi todos sus consumidores usan
como color de TEXTO— son verde, y `--on-accent` es `--verde-ink` (6.77:1
sobre oro). Sobre verde el oro pleno se queda en 4.35:1: el texto de acento ahí
es `--oro-claro` (oro 70 % con crema, 5.35:1). La banda de oro es un fondo
CLARO (luminancia 0.43): tinta verde, y su acento se invierte a verde con crema
encima, igual que hacían café y beige.

Tipografía del manual: **The Seasons** (títulos en verde, subtítulos en oro) y
**TT Wellingtons** (cuerpo). La landing ya sólo habla con ellas y KudosKaps; los
archivos con licencia web todavía no están en el repo (ver «Tipografía»).

### Paleta del panel

**Dos anclas neutras y nada más.** Toda la neutralidad del panel sale de
mezclarlas; no hay un tercer hex neutro en el archivo.

| Token | Hex | |
|---|---|---|
| `--admin-ink` | `#0b0b0c` | negro cálido, no `#000` |
| `--admin-paper` | `#fbfbfa` | papel, no `#fff` |

De ahí salen los doce pasos `--admin-gris-0` … `--admin-gris-11`, que se
declaran **por tema y siempre en el mismo sentido**: el `-0` es el fondo y el
`-11` la tinta más contrastada. Por eso los roles se escriben una vez y valen
para los dos temas.

**En blanco y negro el acento ES la tinta**: `--admin-accent` vale casi-negro en
claro y casi-blanco en oscuro, y de ahí que coincida con `--admin-text`. Los
rellenos primarios son sólidos y de alto contraste.

Los nombres de la identidad anterior —verde, vino y oro— se conservan como
**alias** en el mixin `alias-admin-heredados`, porque 336 reglas los consumen.
Cada uno apunta al rol que representaba de verdad, comprobado uso por uso y no
por lo que sugiere el nombre: `--admin-terra` **no era el color de peligro**,
era el relleno de `.admin-btn--primary`, así que va al acento; `--admin-gold`
también; `--admin-ink-on-gold` y `--admin-text-inverse` van a `--admin-on-accent`.
El mixin se incluye **dentro de cada bloque de tema**, por la razón de siempre.

#### El puente hacia los nombres públicos

POS, áreas, login y los parciales de operación —más de once mil líneas— se
escribieron contra los roles del sitio público: `--txt`, `--accent`, `--line`,
`--surface`, `--gold`… Unos cuarenta nombres. En vez de reescribirlos, el mixin
**`puente-tokens-publicos`** (en `_globals.scss`) mapea esos cuarenta nombres a
los `--admin-*` y se incluye en un solo bloque:

```scss
.mapa-page, .area-page, .login-page, .operational-page { @include puente-tokens-publicos; }
```

Esas cuatro pantallas llevan además `.admin-body`, que es de donde sacan los
tokens del panel. El grano y la viñeta se apagan ahí: en el panel son textura,
pero sobre el mapa de mesas son dos capas fijas que se cruzan con pines y
arrastres.

Lo que NO entra en el puente: `--pos-*`, `--map-*`, `--operational-*` y
`--area-accent`. Son escalas locales que esas hojas definen para sí —
`--area-accent` lo inyecta la vista en línea, con el color que cada área tiene
en base—, no roles del sistema.

⚠️ Esa autonomía tiene un precio, y ya se pagó: las **caras** del piso se
declaran ahí (`--operational-font-*`, en `operation/_shell.scss`) y se quedaron
apuntando a `"Fraunces"` y `"Space Grotesk"`, que se retiraron de `vendorFonts`
al adoptar Geist. Sin fichero, el navegador cae al respaldo, así que **todo el
cromo operativo llevaba meses pintándose en Georgia y en Segoe UI** — una serif
que nadie eligió y una sans distinta en cada sistema operativo. Ahora las tres
resuelven al sistema administrativo: `--operational-font-brand` a
`--admin-logo`, y `--operational-font-editorial` y `--operational-font-ui` a
`--admin-font-sans`, que son la misma cara a propósito —en el piso no hay voz
editorial que defender y la jerarquía la hacen el peso y el tamaño—. Una lista
de nombres de familia no avisa cuando deja de existir: si se retira una cara de
`gulpfile.js`, hay que buscar quién la nombraba.

⚠️ **El puente va en UNA sola dirección.** Nunca declarar el mapeo inverso
(`--admin-surface: var(--surface)`) en una pantalla que ya recibe
`puente-tokens-publicos`: las dos direcciones juntas forman un **ciclo**, y un
ciclo entre custom properties obliga —por especificación— a que *todas* las
propiedades implicadas computen al valor inválido-garantizado. `.mapa-page`
arrastraba diez de esos alias del POS anterior al puente, y con ellos se caían
`--admin-surface`, `--admin-text`, `--admin-bg`, `--admin-border`,
`--admin-muted`, `--admin-gold` y sus públicos; los seis `--map-table-*` se
mezclan sobre esos roles, así que **las mesas se pintaban todas con el mismo
contorno blanco** —libre, ocupada y reservada— con el CSS de los pines intacto.
No se ve como un error de cascada porque un `var()` inválido hereda o cae al
valor inicial en vez de romper la regla; el síntoma es un token que
`getComputedStyle().getPropertyValue()` devuelve **vacío**. Ése es el primer
sitio donde mirar si una pantalla de piso pierde el color de golpe.

Y el error simétrico, que es el que más veces ha aparecido: **usar un nombre
público a secas dentro del panel**. `--on-accent`, `--txt-inverse`, `--accent`…
sólo existen donde se aplica el puente —las cuatro pantallas de piso—, nunca en
`.admin-body`. Escritos en un componente del panel son una declaración
inválida que cae a `inherit`, y como el caso típico es tinta sobre un relleno
de acento, lo que sale es texto del color del fondo: **negro sobre negro**. Ya
había pasado en los extremos del rango; después se encontró igual en el preset
activo del selector de periodo —la píldora salía maciza y sin etiqueta— y en la
celda fuerte del mapa de calor de analíticas. Dentro del panel siempre
`--admin-on-accent`.

**Y el caso que ese error tiene garantizado: un componente que viven los dos
lados.** `_confirmation-modal.scss` y `_app-notice.scss` salen del árbol público
pero los carga también `admin.css`, así que cada nombre público que escriban se
invalida en el panel. Pasó entero: el velo iba en `--scrim` y el fondo quedaba
**transparente** —se borraba un gasto fijo con el módulo a brillo pleno detrás—,
el relleno del botón destructivo iba en `--feedback-danger` y salía sin fondo, y
el titular pedía `var(--serif, Georgia, serif)` y caía a Georgia, la única serif
que el panel no quiere.

La salida **no** es puentear los nombres públicos hacia `.admin-body`: eso
revive la confusión de arriba y hace que empiecen a aplicarse en silencio otras
declaraciones inválidas repartidas por los componentes. Un componente
compartido recibe **roles propios** —hoy los `--confirmation-*`: `-scrim`,
`-surface`, `-text`, `-accent`, `-accent-soft`, `-accent-text`, `-on-accent`,
`-strong`, `-faint`, `-field-bg`, `-shadow`, `-heading-font`, `-ok`, `-danger`,
`-danger-text`, `-on-danger`— y **cada sistema los declara con su paleta**:
`alias-heredados` en `_reset.scss` y `alias-admin-heredados` en `_globals.scss`.
El aviso (`AppNotice`) suma los suyos, `--app-notice-*`: en la landing salen
del mixin `roles-aviso` (que `alias-heredados` incluye, y también el bloque de
`[data-modo="oscuro"]`, escrito a mano), y en el panel van dentro de
`alias-admin-heredados`, derivados de los roles de estado y no de los hex para
que en el POS hablen el mismo color que el mapa. Al añadir una regla con color o
tipografía a uno de esos componentes, el token nuevo se declara en los DOS
lados; en uno solo, el otro vuelve a romperse sin ruido.

Ojo a `-danger` frente a `-danger-text`: el primero es el rojo **pleno** de un
relleno y el segundo el rojo **legible como texto** sobre el fondo del modo
activo. Usar el segundo como relleno es lo que deja un botón destructivo
apagado.

**El color de la paleta funcional casi nunca toca el cromo del panel** — es de
estado, badges, series de gráfica y `--admin-estado-*`. Es la regla que hace que
el conjunto se lea como un sistema y no como un arcoíris: si los bordes y los
rellenos usaran color, un badge ámbar dejaría de significar «advertencia».

La excepción, y es una lista cerrada: **los disparadores de acción**. Los
botones del topbar y los secundarios de una barra de acciones de módulo
(«Categorías» en Menú, «Subrecetas» e «Inventario» en Recetas) llevan color.
Eran cajas idénticas donde sólo cambiaba la palabra y había que leerlas una por
una para elegir.

**Los disparadores de acción llevan RELLENO SÓLIDO de la paleta funcional**,
nunca un tinte: el tinte al 11-12 % se veía fosforescente en oscuro y el
cliente lo retiró. `.admin-btn--tinted` + `--tinted-{color}` (`_buttons.scss`)
elige la tinta por luminancia: ámbar y turquesa en relleno pleno con
`--admin-ink`; índigo pleno con papel; azul y verde **oscurecidos hacia la
tinta** (78 % y 72 %) hasta que el papel da 5.0:1. Un color nuevo se calcula, no
se estima. La acción principal sigue siendo `.admin-btn--primary`, por
jerarquía y no por color: es el único en la tinta del sistema, con icono, el
último de la fila, con sombra, barrido y magnetismo; los de color van planos.
Pintan con (0,3,0) y dejan las sombras en (0,1,0)/(0,2,0) para que gane el
anillo de foco. No tocan bordes de tarjeta, filas de tabla ni tipografía.

**Borrar es papel sobre rojo pleno** en todo el panel —`.admin-btn--danger` /
`--danger-solid`, `.admin-icon-button--danger` y el diálogo destructivo—, con
la misma caja que su homólogo de editar: el cliente lo pidió así aunque el
contraste quede al borde (4.58:1). La tinta es `--admin-on-danger` (papel en los
dos temas), nunca `--admin-text-inverse`/`--admin-on-accent`, que en oscuro
valen tinta. El «×» que quita un renglón dentro de un editor (un ajuste de
variante) no es un borrado y se queda discreto.

**Topbar.** Los tres botones redondos (notificaciones, reportar y salir)
comparten una caja en `_topbar.scss` y su color entra SÓLO por variables
`--tb-fondo/-tinta/-borde/-fondo-hover/-canto/-halo/-relieve`; las reglas por
botón no declaran propiedades (eso eliminó la trampa de cascada que dejaba el
botón de reportar en gris). Notificaciones: **papel** si no hay nada por
atender, **rojo sólido** (`has-action`) con al menos una tarjeta en «Por
atender», papel con punto azul (`has-followup`) si sólo hay en espera; la
prioridad alta sólo abre el halo. Reportar: ámbar sólido con tinta. Salir:
neutro en reposo y rojo sólo al apuntar, para que el rojo fijo de la barra
signifique únicamente «hay algo por atender». El conmutador de tema conserva el
acento; su respuesta va en el icono, porque GSAP (magnético) reescribe el
`transform` de la caja y absorbe `translate`/`scale`. Microinteracciones con las
propiedades `translate`/`scale` (≤ 240 ms, sólo puntero fino); el vaivén de la
campana (520 ms) sólo cuando SUBE el contador; con movimiento reducido se
quita todo desplazamiento y queda el color. El contador va en papel con la
cifra en tinta, su aro toma el color del botón, y lleva `&[hidden]` porque su
`display: grid` le ganaba al atributo.

**Los números del buzón salen de
`Services\Reservations\ReservacionBuzonBandejaService`**, que cuenta las
TARJETAS que pinta el cajón con el criterio de sus pestañas. Ni el topbar ni
ningún endpoint deben usar `BuzonNotificacionesService::resumen()`, que cuenta
filas y decía «1» con la pestaña vacía (la lista descarta avisos cuya fuente ya
no aplica y agrupa varios por reservación). Los avisos son polimórficos y sin
llave foránea: borrar una reservación los deja huérfanos, y la sincronización
los cierra con `fuente_inexistente` (`cerrarHuerfanasEnTransaccion`).

Lo que **no** es excepción, porque la regla siempre lo permitió: el badge de
categoría de Menú (`.admin-badge--cat-0…9`, derivado de `categoria_id % 10`) y
los tonos de serie de las tarjetas KPI (`.admin-stat-card--serie-*`). Ahí la
identidad del dato *es* el color, como en las series de una dona. En las
tarjetas el tono de serie es un **punto delante del rótulo** (la clave de una
leyenda), nunca el fondo ni la cifra, y el fondo queda libre para el tono de
ESTADO (`--good`, `--bad`), que gana porque «la utilidad es negativa» importa
más que «esto es la utilidad».

**El panel no lleva filetes laterales de color**, en nada: el cliente los
retiró de tarjetas, filas, avisos y del hover de `.admin-table` (que pintaba
`inset 3px` en oro). El estado se dice con una pastilla, un punto, una forma o
un fondo.

**Ningún archivo fuera de esos dos contiene un hex ni un `rgba()` literal.** Ni
el SCSS, ni el JS, ni las vistas. Cambiar la marca es editar la capa 1 y nada
más; si hace falta escribir un color en otro sitio, es que falta un rol.

La capa 1 además se puede **sobrescribir desde el panel** (Configuración →
Paleta de la landing, `Services\Configuration\PaletaLanding`, columnas
`verde`, `oro`, `beige` y `crema` de `configuracion_paleta`): lo guardado se
emite como `:root{--brand-*}` DESPUÉS de `app.css` en cada página de marca.
Dos consecuencias: toda página nueva que cargue `app.css` tiene que llevar
`PaletaLanding::estiloEnLinea()` detrás de ese `<link>`, y `PaletaLanding::MARCA`
es el único espejo en PHP de los cuatro hex — `run-paleta-landing.php` falla si
se separa de `_reset.scss`, igual que si cambian los porcentajes de texto tenue
que mide (`COMPROBACIONES`). Toda mezcla nueva de un rol de texto en los tonos
de la landing es candidata a entrar en esa lista.
Comprobación: `grep -rE '#[0-9a-fA-F]{3,8}|rgba\([0-9]' src/scss src/js` sólo
debe encontrar `_reset.scss` y `_globals.scss`.

#### Las tres capas

1. **MARCA** — los cuatro colores de arriba.
2. **ESCALAS** — variantes derivadas con `color-mix` (`--verde-deep`,
   `--verde-ink` —la tinta de titulares—, `--oro-claro`, `--oro-soft`,
   `--crema-lift`…). Nunca un hex nuevo.
3. **ROLES** — lo que el CSS consume: `--bg`, `--bg-alt`, `--surface`,
   `--surface-2`, `--txt`, `--txt-strong`, `--txt-mute`, `--txt-faint`,
   `--txt-inverse`, `--line`, `--line-soft`, `--line-strong`, `--accent`,
   `--accent-text`, `--on-accent`, `--focus`, `--scrim`, `--sombra`,
   `--vineta`.

Distinciones que importan:

- `--accent` es el acento de marca para **rellenos, bordes y adornos** (oro en
  los tonos claros y sobre verde; verde en la banda de oro); `--accent-text` es
  su versión **legible como texto** sobre el fondo del modo activo (verde en
  los claros, `--oro-claro` sobre verde). `--on-accent` es la
  tinta que va **encima** de un relleno de acento.
- Las tres líneas son una escala, no sinónimos, y sus valores están calculados
  contra el `--bg` de cada tono, no elegidos a ojo:
  `--line-soft` es el separador decorativo, `--line` es el **borde de
  componente** y cumple el 3:1 de WCAG para elementos no textuales en los cinco
  ámbitos, y `--line-strong` es jerarquía y estado activo. Al tocar un `--bg`
  hay que rehacer la cuenta: el mismo porcentaje da ratios distintos sobre
  crema que sobre verde.
- `--txt-faint` es el texto más tenue que la casa admite y está fijado al
  mínimo que cumple AA (4.5:1) en cada ámbito. No es un gris libre: bajarlo
  rompe el contraste, y en `[data-modo="oscuro"]` además arrastra a
  `--estado-cancelado`.
- `--focus` es el anillo de foco de teclado (`:focus-visible` global en
  `_reset.scss`). Sobre `[data-tono="foto"]` lleva un halo extra con `--scrim`
  para no perderse contra la fotografía.
- `--scrim` es el velo sobre una **fotografía**; no es `--bg`. Sobre una imagen
  el texto siempre va en claro, así que el velo es oscuro en todos los modos.
- `--sombra` es la sombra proyectada. `--vineta`, el degradado de borde.

Los nombres antiguos (`--gold`, `--ink`, `--beige`, `--white`…) se conservan
como alias de la capa 3 para no reescribir cientos de reglas. Se declaran con
el mixin `alias-heredados` de `_reset.scss`, **incluido dentro de cada ámbito**
(`:root` y los seis `[data-tono]`), y eso no es opcional: una custom property
que referencia a otra se resuelve en el elemento donde se DECLARA, así que un
`--beige: var(--txt-strong)` sólo en `:root` computa allí al verde de tinta y
hereda ese valor ya resuelto — redeclarar `--txt-strong` en una sección no lo
cambia. Al añadir un tono nuevo hay que incluir el mixin o todo lo que consuma
alias dentro saldrá con la tinta de `:root`.

#### Modos y tonos

La capa 3 se redeclara por ámbito; nunca se duplican reglas por tema:

- **Landing en claro** (por defecto): crema de fondo, verde de texto, oro en
  rellenos y filetes, verde como acento de texto.
- `[data-modo="oscuro"]` — ya **no lo usa ninguna pantalla**. POS, áreas y login
  se fueron al sistema administrativo (`operation.css`, oscuro forzado con
  `[data-admin-theme="dark"]` y sin conmutador: son turnos completos en tablet).
  El feedback se fue al otro lado: es la ÚNICA pantalla de tablet que pertenece
  a la marca —la abre el comensal en la tablet del mesero al cerrar la
  cuenta—, así que conserva la paleta de la landing (`[data-tono="crema"]`,
  crema/verde/oro) y vive en `app.css`. Pero habla con la VOZ y los PRINCIPIOS
  del sistema administrativo: Satoshi para el texto y Expose sólo para el
  titular (sus `@font-face` están en `feedback/_feedback.scss`, y como en
  `app.css` sólo las nombra `.fb-page`, la landing no las descarga), rótulos en
  versalitas, botones de relleno sólido (primario verde de marca, secundario
  lino) y estados explícitos de carga, éxito y error. `.fb-page` redeclara
  `--sans` y `--serif` hacia Satoshi para que lo compartido que vive dentro
  (`AppNotice`) también la hable. El isologo NO usa la fuente (KudosKaps sólo
  tiene versales: escrito en caja mixta salía «Cᴀsᴀ Pᴇsᴛᴀʟᴏᴢᴢɪ»): son los
  trazos de `views/admin/menu/_isologo.svg` más el filete y el medallón del
  hero (`views/feedback/_isologo.php`, con su medallón copiado de `_hero.php`:
  si cambia uno, cambia el otro). Las caras son radios reales y su color sale
  de la paleta funcional, con el trazo hundido al 62 % en `--verde-ink` para
  pasar de 3:1. La encuesta funciona sin JS: el guardia del `<head>` pone
  `fb-js`, `feedback.js` responde con `fb-listo`, y si no llega se ve el
  formulario seguido, que `FeedbackController::guardar` recibe como POST normal
  y redirige con 303. **Una encuesta con token se responde una sola vez**: el
  token se aparta con `UPDATE … WHERE usado = 0` antes de insertar (se libera
  si el INSERT falla), y un token usado o desconocido se rechaza en vez de
  guardarse como anónima. Sin token es la encuesta abierta. El bloque de
  `[data-modo="oscuro"]` se conserva por si vuelve a hacer falta un modo oscuro
  público.
- `[data-tono="crema|beige|oro|verde|foto"]` — ritmo de secciones de la landing
  (`cafe` y `vino` son alias de `oro`).
  Cada `<section>` lo declara y **todo lo que vive dentro se readapta solo**.
  `foto` es para el contenido que descansa sobre una imagen con velo: no
  cambia el fondo, sólo fuerza el texto en claro. Hoy no lo usa ninguna
  sección (el hero dejó de ser una fotografía); se conserva por si vuelve.
- `[data-admin-theme="light|dark"]` en el panel, sin cambios de estructura.

#### Las tres transiciones del sistema

Las tres usan View Transitions. Tema y módulo **conviven en `_finishes.scss`,
bloques 4b y 4c**; la llegada vive en `_transitions.scss`. Si tocas una,
comprueba las otras: es el error fácil de esta zona.

- **Cambio de tema** (`core/theme.js`): un `clip-path` circular de 480 ms que se
  abre desde el conmutador. Va marcada con el tipo `'tema'`
  (`startViewTransition({update, types})`, con repliegue a la firma de callback
  para Chrome 111-124), y las reglas que apagan el fundido de raíz se acotan a
  ese tipo. Sin acotarlas matarían también la de navegación.
- **Cambio de módulo (4c, tipo `modulo`)**: `@view-transition { navigation:
  auto }` y **sólo se mueve el contenido**: el viejo sale en 150 ms y el nuevo
  entra en 300 ms subiendo 10 px, con nombres DISTINTOS de salida y de entrada
  (con el mismo nombre, un origen desplazado haría volar el contenido cientos
  de píxeles). Topbar y sidebar quedan quietos, y el enlace activo viaja de su
  fila a la nueva (`admin-nav-activo`, ≥993 px). Los nombres cuelgan de clases
  que sólo existen mientras dura la navegación —`admin-vt-sale` (`pageswap`,
  en `motion.js`) y `admin-vt-entra` (`pagereveal`, en el `<head>` del layout:
  tiene que ser ahí)—, así que la transición del tema no se entera: nombrar
  el topbar o el sidebar de forma permanente les daría grupo propio en TODAS
  las transiciones y sus instantáneas taparían el círculo del tema. Lo que ya
  está a la vista no se vuelve a revelar al llegar desde otro módulo.
  Los enlaces del sidebar se **precargan** con Speculation Rules (`prefetch`,
  eagerness `moderate`, sin el módulo activo) — prefetch y no prerender, porque
  las páginas ejecutan consultas; el servidor reconoce la precarga por
  `Sec-Purpose: prefetch`.
- **Llegada tras el login (tipo `llegada`)**: `views/components/llegada.php` +
  `src/js/components/llegada.js` (en `bundle.min.js` por el glob general y
  en `paths.adminJs`) + `_transitions.scss`. El controlador marca
  `Auth::anunciarLlegada()` al autenticar y el layout (o el shell del piso) la
  gasta con `Auth::consumirLlegada()` en la PRIMERA página: un prefetch no la
  gasta. El nombre (`cp-marca`, y cada palabra en `cp-marca-casa` /
  `cp-marca-pestalozzi`) va del login al centro, se sostiene con el saludo y
  vuela a la marca de destino mientras el velo —siempre tinta, porque usa las
  anclas y no los roles— se cierra en círculo sobre ella: al nombre apilado del
  sidebar, al monograma si está plegado, a la marca de la barra en el selector
  de estación; en el POS y en el teléfono se apaga en su sitio. ≈1.4 s, se
  salta con cualquier tecla o toque; sin JS o sin tipos de View Transition el
  CSS sostiene el velo 1.3 s y lo funde; con movimiento reducido, un fundido de
  240 ms. Ninguna de las dos marcas del login puede perder `data-login-marca`,
  y si cambia la medida de la marca del sidebar o de la barra del piso hay que
  revisar `.cp-llegada__marca`.

- **Login** (`views/auth/login.php` + `auth/_login.scss`): a la izquierda la
  casa (CASA sobre PESTALOZZI en KudosKaps 400 medido contra su columna,
  `9.5cqi`), a la derecha la puerta (titular en Expose por rol, rótulos y
  etiquetas en mono). El NIP se envía solo con el cuarto dígito y el teclado
  físico funciona esté donde esté el foco. ⚠️ **En tablet nunca enfocar por JS
  un campo `inputmode=numeric` dentro de un toque**: abre el teclado del
  sistema encima del pad, que es lo que pasaba con cada tecla.

⚠️ **Curvas literales** en todo lo que cuelga de `::view-transition-*`: los
tokens (`var(--admin-ease-out)`) viven en `.admin-body` y esos pseudos cuelgan
de `<html>`, así que ahí no resuelven y caían a `ease`.

Y la razón de que el cambio de tema se trabara siempre en el mismo punto:
`theme.js` pone `.admin-theme-switching` en `<html>` mientras dura el
intercambio, y esa clase **congela todas las transiciones**. La instantánea
«nueva» de un View Transition se captura al PRINCIPIO, así que con
`.admin-body` interpolando `background` y `color` en 260 ms —más otras cuarenta
transiciones repartidas por los componentes— el círculo revelaba un tema a
medio camino y el DOM real seguía moviéndose cuando la animación ya había
acabado. Al congelarlas, lo único que se mueve es el `clip-path`, que corre en
el compositor.

Al añadir una sección a la landing hay que darle un `data-tono` distinto al de
su vecina, y sincronizar el número del `eyebrow` con `.nav-overlay__links` y
`.rail` (`views/home/_nav.php`).

#### El hero: «Isologo editorial»

`views/home/_hero.php` + `components/_hero.scss` + `modules/hero.js`. Es la
propuesta 1C del lienzo de heroes: fondo crema, el **isologo del manual como
`<h1>`** (CASA sobre PESTALOZZI en KudosKaps, filete de oro partido por el
medallón CP), la frase en The Seasons («Una mesa italiana *con alma
una bajada de una línea y, a la derecha, la mesa
puesta cenital en SVG. **La columna de texto va centrada** bajo el isologo, que
es simétrico: con el resto alineado a la izquierda la composición se
descolgaba. A los lados de CASA, dos ramas de olivo finas (`.hero__rama`) en el
hueco que la palabra deja sobre PESTALOZZI (2.19 em por lado). Sin rótulo encima del isologo ni pie de voces debajo:
los dos salieron para que el logo sea lo primero y casi lo único que se lee.

- **El isologo se mide contra SU columna** (`container-type: inline-size` en
  `.hero__texto`, `font-size: clamp(2.6rem, 12.6cqi, 7.5rem)`). Lleva dentro
  una frase `.sr-only` («restaurante italiano en la Del Valle…») para que el
  `<h1>` diga qué es la casa a buscadores y lectores de pantalla. PESTALOZZI mide
  7.86 em con su tracking —medido sobre el `.otf`—, así que llena la columna en
  cualquier ancho. La rejilla va 1.4fr/1fr por lo mismo. En pantallas bajas se
  acota también por `svh`.
- **Ningún color en el SVG**: cada relleno pide `--hero-*` (verde, tinta, oro,
  beige, crema, oro claro, oro papel, lino), que `_hero.scss` deriva de la
  capa 1. Así el hero sigue a la paleta editable de Configuración. Al tocar la
  ilustración, nada de hex: un `style="fill:var(--hero-…)"`.
- **La entrada la hace CSS**, no GSAP (líneas del isologo que suben tras un
  `clip-path`, medallón que entra girando, trazos que se dibujan con
  `pathLength="1"`, vapor, tenedor, vino, olivo): el hero se ve completo sin
  las libs de movimiento. Después quedan vivos la corona punteada del medallón
  y **la capa viva** (`.hero-vivo`, al final del SVG): órbitas punteadas sobre
  las esquinas de oro, hojas de albahaca que cruzan el panel, harina que sube
  del pan y destellos. `initMesaPuntero()` publica `--hero-px`/`--hero-py`
  (-1 a 1, sólo con puntero fino) y el CSS desplaza la capa viva más que la
  mesa: dos planos. Movimiento reducido y `.no-anim` dejan todo quieto (y
  retiran lo que sólo existe en movimiento: hojas, harina, chispas), y
  `initMesaViva()` pone `.hero--quieta` —bucles en pausa— mientras el hero no
  está en pantalla: transform sobre SVG no va al compositor.
  En el plano del dibujo (no en `.hero-vivo`) viven además la vela vista desde
  arriba (`.hero-vela`, escalada con un atributo `transform` sobre el pabilo:
  sus `transform-origin` en px usan coordenadas locales), las ondas del vino
  (`.hero-onda`), el destello del cuchillo (`.hero-filo` / `-chispa`), las
  migas (`.hero-miga`) y el vapor del cuenco (`.hero-vapores--cuenco`), con
  periodos que no son múltiplos entre sí. El vapor y la llama se apartan del
  puntero y el vino se escora hacia él con las PROPIEDADES `translate`/`rotate`,
  para componerse con las animaciones de `transform`. La pasta (`.hero-plato`)
  gira con el scroll por un `view-timeline` (`--hero-salida` en `.hero`) y no
  va en `.hero--quieta`, porque no depende del tiempo. **Al añadir un bucle hay
  que sumarlo a las tres listas**: movimiento reducido, `.no-anim` y
  `.hero--quieta`.
- **La marca fija y el índice lateral se retiran mientras el HERO pasa bajo
  ellos** (`body.marca-en-hero`): el nombre saldría dos veces y el índice se
  montaba sobre el panel de la mesa. La clase **ya sale del marcado** en el
  `<body>` de `index.php`; `initMarcaHero()`, fuera de la rama de GSAP, sólo la
  corrige y la quita si no hay `IntersectionObserver`.
- El CTA principal es `.btn-line.btn-line--solid` con la medida propia de
  `.hero__boton`. En ≤600 px la barra desaparece y la rejilla arranca bajo el
  disco del menú (si no, PESTALOZZI pasaba por debajo del botón).
- El botón de menú se mide con `--nav-toggle-top` y `--nav-toggle-size`
  (`:root` de `_nav.scss`), que leen también el origen del círculo del menú y
  la barra del hero: si se mueve el botón, se mueven los tres.

#### El menú de la landing

`views/home/_nav.php` + `layout/_nav.scss` + `modules/nav.js`. Papel crema (no
verde: abrir el menú apagaba una portada clara) con dos columnas: los destinos
y una ficha de lino con la casa **dibujada** (arco, lámpara, olivo, mesa para
dos) donde antes había una foto del comedor.

- **UX writing, y una cosa por renglón**: los destinos dicen en español qué
  hay —«La carta», «Catas y vinos», «Cómo llegar»— sin número delante. La pista
  («Platillos y precios») sólo aparece al apuntar o tabular, y no existe en
  pantallas táctiles: con número, nombre y pista siempre a la vista el panel
  era una tabla. La voz italiana se queda en los rótulos de las secciones.
  Nueve destinos; al reordenar se sincronizan `$navDestinos`, el orden de las
  secciones y el `.rail`.
- **Dos bloques centrados**, uno por mitad: la lista en el centro de la suya y
  la ficha centrada bajo la casa dibujada (en ≤880 px vuelven a la izquierda,
  apilados). La pista va en `position: absolute` a la derecha del nombre: si
  ocupara sitio, el bloque de la lista se mediría con ella y se descentraría.
- **La ficha**, mínima: «Reservar mesa» y tres líneas sin rótulo —el horario
  de HOY (mismo servicio de excepciones que la tabla y el pie, así que nunca
  los contradice), la dirección con enlace al mapa y el teléfono—. Las cartas
  en PDF, el correo y la copia de la marca salieron: están en sus secciones y
  en el pie. Los datos salen de `SitioConfig`, no escritos a mano.
- **Los accesos rápidos del hero** llevan un filete de oro que crece bajo la
  palabra al apuntar o tabular, y «Reservar mesa» se rellena de verde desde
  abajo, como los `.btn-line`.
- **Accesibilidad**: `aria-expanded` y la etiqueta del botón cambian con el
  estado, el foco entra al primer destino (con anillo sólo si se abrió con
  teclado: `e.detail === 0`) y Esc cierra y lo devuelve al botón. El menú
  cerrado lleva `visibility: hidden` con la transición diferida para que se
  vea cerrarse.
- La ilustración comparte las clases de los adornos (`.t`, `.r`, `.v`) pero
  NO su observador (`data-orn-listo`): el panel es fijo y cubre la pantalla,
  así que el observador la daría por visible con el menú cerrado. Se dibuja
  con `body.nav-open` y su bucle lo enciende `nav.js`.

#### Sin adornos después del hero

Hubo un sello vectorial animado por sección (catálogo
`views/components/ornamento.php`, observador `modules/ornamentos.js`) y el
cliente lo retiró: **después del hero la landing va sin adornos**. Los dibujos
de línea que quedan son la mesa del hero y la casa del menú desplegado; las
clases de dibujo de esta última (`.t`, `.r`, `.v`) y sus keyframes viven en
`components/_ornamentos.scss`. Si vuelven los adornos, el formato era un SELLO
montado sobre el corte entre bandas, y su lección sigue valiendo: ⚠️ **nunca
insertar marcado en una etiqueta de apertura con un regex que corte en el
primer `>`** — la de Reservar lleva `<?php ... ?>` dentro de un atributo, el
adorno acabó dentro de `data-reservation-csrf` y toda reservación de la landing
fallaba la validación CSRF.

#### Rendimiento de la landing

- **Fotos responsivas**: `gulp imagenesResponsivas` (con `sharp`, va en
  `build`) genera `nombre-720.webp` de cada foto de `assets/images` más ancha de
  ~830 px, y `srcsetImagen($ruta, $sizes)` (`includes/funciones.php`) añade
  `srcset`/`sizes` SÓLO si la variante existe. Toda `<img>` nueva de la landing
  lo lleva, más `decoding="async"` y `loading="lazy"` bajo el pliegue. En un
  teléfono, las fotos de la portada bajaron de 2.7 MB a 1.7 MB.
- **`app.css` sale comprimido** (`outputStyle: "compressed"`, con mapa): de 288
  a 210 KB. Las hojas del panel siguen en `expanded`.
- **Caché y compresión** en `public/.htaccess` (dentro de `<IfModule>`):
  brotli/deflate para texto; un año `immutable` para todo `/build/` pedido con
  `?v=`, 30 días para fuentes y 7 para fotos y vendor. Por eso app.css y
  bundle.min.js se enlazan SIEMPRE con `recursoVersionado()` (fecha del
  archivo), nunca con un `?v=` escrito a mano: con caché inmutable, una versión
  olvidada dejaría la hoja vieja en el navegador.
- **Nada de `will-change` permanente** en lo que sólo se anima una vez
  (`[data-reveal]`, `.linea__in`): eran decenas de capas de composición toda la
  visita. GSAP promueve el elemento mientras dura el tween. Sí lo conservan lo
  que se mueve siempre (pista de la galería, insegna, fotos con parallax).

#### SEO

- `SitioConfig::url()` (`SITIO_URL`, por defecto `https://casapestalozzi.com`)
  es la ÚNICA fuente de la URL pública: canonical, Open Graph, datos
  estructurados, robots.txt y sitemap. Ojo: el correo es `@casapestalozzi.mx`;
  confirmar el dominio real antes de publicar.
- `/robots.txt` y `/sitemap.xml` son **rutas** (`SeoController`), no archivos
  en `public/`, porque llevan esa URL. robots cierra panel, APIs, POS, áreas,
  login, feedback, las páginas con token de reservación y el JSON `/menu$`; el
  sitemap lista la portada y las dos cartas en PDF, **sin `lastmod`** (no hay
  una fecha honesta que poner).
- La portada lleva título de ~60 caracteres con qué y dónde, descripción de
  ~155 que termina en la acción, `theme-color` leído de la paleta vigente (no
  un hex en la vista), Open Graph con medidas y `alt`, y un JSON-LD `@graph`
  con `Restaurant` (horario, carta, mapa, `acceptsReservations` y
  `ReserveAction` hacia `#reserva`) y `WebSite`. Sólo datos que existen en la
  configuración: nada de coordenadas ni código postal inventados.
- Las páginas con token (`views/reservaciones/gestionar.php`), login, feedback
  y 404 llevan `noindex`.

#### Detalles que ya costaron una vuelta

- **El selector de fecha** (`components/_reservation-picker.scss`, mixin que
  comparten la landing y el POS): el hover del día lleva
  `:not(.selected)`. Sin él la regla suma (0,4,0), le gana a `.selected`
  (0,2,0) y el día elegido perdía su marca bajo el cursor.
- **«Sin cupo»** de las catas: centrarlo contra la caja de la línea (flex con
  `align-items: center`, más un `top` a ojo) dependía del ascendente y el
  descendente de la fuente que tocara, y con las de respaldo se descolgaba. Ahora
  la referencia es la **línea base** —que no depende de métricas— más la unidad
  `cap`, que el navegador mide sobre la fuente que de verdad pinta. Son dos
  cajas con la misma jugada: `.cata__sello` (en la letra del título) es un
  inline-flex cuyo único elemento alineado por base es un puntal vacío de
  `½cap + ½alto` —una caja sin texto pone su base en el canto inferior—, y la
  pastilla cuelga desde arriba con su centro a `½cap` del título sobre la base;
  `.cata__sello-pastilla` repite el puntal por dentro con su propia letra para
  centrar el rótulo. `--sello-alto` va en rem (lo único que las dos cajas leen
  igual), el filete en `box-shadow` (un borde descuadra la cuenta) y el título
  es bloque, para que el sello siga a la última palabra si parte en dos.
- **La Cucina** es más baja que una banda normal (aire vertical reducido,
  retrato 1:1.05 acotado a `min(72vh, 600px)`): con el retrato a 4:5 en media
  columna pasaba de una pantalla para tres claves y una firma.

#### La tabla de horarios está DUPLICADA a mano

`views/home/_reserva.php` («Horario habitual», siete filas) y
`views/home/_footer.php` pintan el mismo horario con clases distintas —`.row` /
`is-exception` frente a `.foot__horario` / `is-excepcion`— y lo único que
comparten es el servicio que resuelve las excepciones
(`HorarioOperacionService::mapearExcepcionesDeLaSemana()`, que devuelve las de
los próximos siete días indexadas por día de la semana) y las variables que
inyecta `HomeController`. **Todo cambio de contenido hay que hacerlo en los
dos**; el docblock del pie dice explícitamente que consume las mismas
excepciones *porque antes se contradecían*.

Una excepción (`excepciones_operacion`) es **una sola fecha**, no un rango, y
tiene dos sabores: `cerrado` y `horario_especial`. En la fila del día se muestra
el horario excepcional **y debajo el habitual**, tachado y en `--txt-faint`, con
el rótulo «Habitual:» delante. Antes la excepción *reemplazaba* el valor, así
que quien miraba el jueves veía «16:00–23:00» sin manera de saber si eso era más
o menos de lo normal. El rótulo va en palabras y no sólo en el tachado porque
`text-decoration: line-through` no se anuncia en voz, y sin él la línea sonaría
a un segundo horario vigente.

#### Paleta funcional

**Donde se use cualquier otro color, usar esta paleta** — nunca un hex suelto.
No adopta los colores de marca a propósito: verde y vino son indistinguibles
bajo deuteranopia.

| Token público | Token admin | Hex | Uso |
|---|---|---|---|
| `--c-ambar` | `--admin-c-ambar` | `#F5B400` | advertencia, "en preparación" |
| `--c-naranja` | `--admin-c-naranja` | `#FC6722` | reservaciones, alerta media |
| `--c-rojo` | `--admin-c-rojo` | `#E51022` | error, cancelado, urgente |
| `--c-azul` | `--admin-c-azul` | `#3A86FF` | información, "entregado" |
| `--c-lima` | `--admin-c-lima` | `#8AC926` | disponible |
| `--c-indigo` | `--admin-c-indigo` | `#4267AC` | serie categórica |
| `--c-rosa` | `--admin-c-rosa` | `#EA075A` | serie categórica |
| `--c-magenta` | `--admin-c-magenta` | `#AA2296` | serie categórica |
| `--c-turquesa` | `--admin-c-turquesa` | `#46BDC6` | serie categórica |
| `--c-verde` | `--admin-c-verde` | `#34A853` | éxito, "listo", ventas |

Familias de retroalimentación derivadas de ella, para que un error o un éxito se
vean igual en todas las pantallas: `--feedback-ok`, `--feedback-warn`,
`--feedback-danger`, `--feedback-info` y sus `-bg`.

Estados de platillo derivados (`--estado-*` / `--admin-estado-*`) para que el POS y
los tableros de área nunca discrepen de color: preparación → ámbar, listo → verde,
entregado → azul, cancelado → gris.

Excepción deliberada: `areas_produccion.color` guarda un hex por área en BD
(café, jugos, cocina, horno). Son datos del negocio, no tokens de diseño — y aun
así salen de la paleta funcional.

`Services\Configuration\AnuncioConfig::TIPOS[*]['acento']` era la otra excepción y dejó de
serlo: los cuatro tipos van en el verde de marca (el acento se lee como texto
y enlace sobre papel, donde el oro no llega a AA). Cada uno llevaba un color de la
paleta funcional y sobre la portada el rótulo cantaba — un anuncio no es una
alerta, y lo que distingue a un tipo de otro es su icono y su etiqueta. En el
mismo archivo, los cuatro tipos son ya `PRESENTACION_DISCRETA`: **ningún aviso
bloquea la landing**. `PRESENTACION_MODAL` sigue declarada, sin consumidores, por
si algún día vuelve como decisión explícita.

### Tipografía del panel

**Satoshi + Expose + Geist Mono, y ninguna serif.** No comparte una sola cara
con la landing: el panel es otro producto. Vale para todo el sistema
administrativo —panel, POS, áreas y login, y también la encuesta de feedback,
que lleva sus propias `@font-face` en `app.css`—, porque el piso resuelve sus caras
a los mismos tokens (`--operational-font-*` → `--admin-font-sans`). Satoshi y
Expose son de Fontshare (licencia ITF FFL, que permite alojarlas;
`assets/fonts/LICENCIA-fontshare-FFL.txt`), van como variables en
`assets/fonts/` y las publica `copyFonts`; Geist Mono sigue llegando de
`@fontsource-variable/geist-mono` por `copyVendorFonts`. Cero Google Fonts.

| Token | Familia | Para qué |
|---|---|---|
| `--admin-font-sans` | Satoshi (variable 300-900, redonda e itálica) | todo el texto |
| `--admin-font-titular` | Expose (variable 400-900) | **sólo** el titular de página: `h1`, `.admin-page__title` y el `h2` de `.admin-page-title-row`; en el piso, el título del KDS |
| `--admin-font-cifra` | = `--admin-font-titular` (Expose 600) | la cifra de un **indicador**: tarjeta KPI (`.admin-stat-card__value`), totales grandes del POS, el veredicto de Finanzas, las respuestas del asistente |
| `--admin-font-mono` | Geist Mono Variable | cifras de **columna**, rótulos, código |
| `--admin-logo` | KudosKaps | sólo el wordmark |

Expose es una display: va en el titular de página y en ningún sitio más. Por
eso tiene token propio y no ocupa `--admin-font-heading`, que también lo
piden encabezados de componente a 15-20 px (buzón, modales) donde una display
no se lee. ⚠️ Se aplica por ROL con (0,2,0), nunca por etiqueta: con
`.admin-body h2` (0,1,1) le ganaba a la clase de cada componente y salía en
los títulos de modal, las tarjetas de Configuración y los encabezados de día
de Grupos grandes, y el titular medía 51 px en una página y 36 en otra según
fuera `h1` o `h2`. El titular va a un solo tamaño, `clamp(28px, 3vw, 36px)`,
en Expose 600 con −0.01em: ya trae el espaciado de cartel. Satoshi va a `letter-spacing: 0` en cuerpo — es geométrica
y apretada se le cierran los contadores.

**La jerarquía de trabajo la hace el par mono/sans**: el rótulo va en mono,
versalitas y `letter-spacing: .14em`, y el titular debajo en Expose. Es lo
contrario de lo que pide la serif de alto contraste de la landing —apretada se
empasta— y por eso las dos escalas no se pueden copiar la una de la otra.

Toda cifra que se compare en vertical va en mono con `tabular-nums`: columnas
numéricas de tabla, importes de un desglose, timestamps. Se marcan con
`.admin-num`. La cifra de un INDICADOR —la que una tarjeta dice sola, a 26-46
px— no es un dato de columna sino un cartel, y va en `--admin-font-cifra`
(Expose 600). Expose no trae `tnum`: donde una cifra se anime contando, se le
reserva el ancho con `min-width`. Una
tabla entera en mono, en cambio, se lee como un volcado de terminal — el texto
se queda en sans.

Se envían redonda e itálica de Satoshi y de Geist Mono aunque los ficheros
sean variables: `font-style` no es un eje, y hay una regla que pide itálica. La
casa no admite caras sintéticas (`font-synthesis-*: none` en `.admin-body`).
Expose no tiene itálica y no debe pedirse.

`--admin-font-body`, `--admin-font-heading`, `--admin-font-accent` y
`--admin-font-display` se conservan como alias de `--admin-font-sans`.
`--admin-font-display` es el caso raro: **nadie lo definió nunca** y se consumía
con un fallback `"Fraunces", serif` que era lo que de verdad se pintaba.

### Tipografía

**La landing habla sólo con las tres caras del manual de identidad**, y se
consumen sólo por token, nunca nombrando la familia:

| Token | Familia | Para qué |
|---|---|---|
| `--serif` | **The Seasons** (400, 700) | `h1`-`h4`, `.display`, subtítulos, firmas y los acentos de los títulos |
| `--sans` | **TT Wellingtons** (300, 400, 700) | cuerpo (400), énfasis (700), párrafo destacado `.lead` (300), versalitas, botones, rótulos |
| `--logo` | KudosKaps | `.brand-mark`, `.hero__isologo` y `.foot__brand .bm` |

Bodoni Moda, Crimson Text, Montserrat y Playfair **salieron de la landing**.
`--crimson` ya no existe en `_reset.scss`: lo que era subtítulo, firma o
acento pasó a The Seasons, y lo que era texto corrido
(`.lead`, mensajes de formulario, notas) pasó al cuerpo — el manual reserva The
Seasons para títulos y subtítulos. El panel conserva su alias
`--crimson: var(--admin-font-sans)` para el login.

⚠️ **Son tipografías COMERCIALES y no están en el repo.** Las declara
`src/scss/layout/_caras-de-marca.scss` (mixins que incluyen `_fonts.scss` con
`../fonts` y la vista previa de Configuración con `../../fonts`) y esperan seis
archivos con licencia **web** en `assets/fonts/marca/`, que `copyFonts` publica
en `public/build/fonts/marca/`:

```
the-seasons-regular.woff2     the-seasons-bold.woff2
tt-wellingtons-light.woff2    tt-wellingtons-regular.woff2    tt-wellingtons-bold.woff2
```

Mientras falten, cada `@font-face` da 404 y el texto cae al respaldo de
SISTEMA del token (Didot / Bodoni 72 / Georgia y Helvetica / Arial): nunca a
otra webfont, que se descargaría además. `local()` va primero, así que una
máquina con la familia instalada ya la ve. Las precargas de la portada, el 404
y el feedback salen por `precargaFuente()` (`includes/funciones.php`), que no
emite nada si el archivo no existe. Tres avisos: las copias incrustadas en el
PDF del manual son subconjuntos con licencia de documento y **no sirven**; el
manual se compuso con la versión de PRUEBA de TT Wellingtons («Trl»), que
tampoco se publica; y si el repositorio es público, los `.woff2` con licencia
no deberían versionarse ahí. El PDF de la carta (`items-pdf.php`, Dompdf) sigue
en Playfair/Montserrat `.ttf` hasta que haya `.ttf` de escritorio de las dos.

**El PDF de la carta** (`views/admin/menu/items-pdf.php`, Dompdf) va en
Playfair/Montserrat `.ttf` mientras no haya `.ttf` de escritorio de las caras
del manual, pero el isologo NO se escribe con la fuente: Dompdf no aplica el
kerning de KudosKaps y el nombre salía desarmado. Va como trazos en
`views/admin/menu/_isologo.svg`, generado desde el `.otf` por
`scripts/isologo-pdf.py` (necesita fontTools; regenerarlo si cambia la cara o
el tracking del hero) y teñido con la paleta por `$logoEscalado`. Cabecera
verde centrada (rótulo, isologo, filete de oro partido por el sello, frase),
secciones centradas con filete y rombo, puntos guía hasta el precio y precios
sin centavos cuando no los hay — el mismo vocabulario que la carta de la web.

`font-synthesis: none` en el `body` de la landing: la casa no admite caras
sintéticas, y un estilo que una familia no trae (cursiva de TT Wellingtons,
negrita de KudosKaps) sale en la cara real más cercana en vez de inventado.
Pesos que no existen se resuelven solos sin sintetizar: 500 cae a la regular y
600 a la negrita.

⚠️ **KudosKaps tiene UNA sola cara, de peso 400.** Cualquier elemento que la
consuma declara `font-weight: 400` explícitamente aunque `font-synthesis` ya
impida la negrita falsa. Le pasó al `h1` del hero (por eso `.hero
.hero__isologo` lleva el bloque delante: con la clase sola, `h1..h4` le ganaba
la familia) y le pasa al PDF del menú (`views/admin/menu/items-pdf.php`). Va con
`font-display: block` y precargada desde `views/home/index.php`: en un wordmark
el salto de cara se ve más que la espera.

**La landing no usa cursivas.** El cliente las retiró: el acento de un título
(«Reserva *tu mesa*», «Catering para *tu evento*») es **color** (`--accent-text`)
más un **subrayado de oro** que se traza con el scroll (`animation-timeline:
view()`, ya dibujado donde no hay soporte o con movimiento reducido). Va en
`h1..h4 em`, `.display em` y `.accent-italic` —el nombre de la clase se quedó
por las vistas que la usan; ya no es itálica—, como fondo y no como
`text-decoration` para poder animarlo y repetirlo por renglón
(`box-decoration-break: clone`). `em`, `i` y `cite` van en redonda en toda la
landing, y un `em` dentro de un párrafo es la negrita de TT Wellingtons —el
recurso que el manual da para enfatizar—: si un `<p>` necesita el acento de
título (la frase del hero), lleva `.accent-italic`. La itálica de The Seasons
ya no se declara; con `font-synthesis: none`, una cursiva que alguien pida
sale en redonda en vez de inventada.

**Un heading es una sola voz.** Los títulos van en The Seasons **regular**, que
es como el manual compone los suyos; la negrita queda para rótulos cortos («la
negrita de The Seasons» en `.eventos__item`, como «Misión» en el manual). Los
títulos de sección van en **una sola línea**: ya no llevan `<br>` y rompen por
ancho de caja — `partirEnLineas()` lo contempla y anima el título en bloque en
vez de escalonado.

**Los rótulos de sección son italianos.** El `.eyebrow` numerado dice sólo la
voz italiana ("01 — La nostra storia"), en las versalitas sans de `.eyebrow`. La
navegación, en cambio, habla en español (ver «El menú de la landing»).

### Componentes

No hay `components/ui`. El equivalente es SCSS compartido más partials PHP:

- `src/scss/admin/shared/components/` — `_forms`, `_cards`, `_tables`, `_buttons`,
  `_badges`, `_modal`, `_select`, `_reservation-picker`.
- `views/components/reservations/date-picker.php` y `time-picker.php` —
  parametrizados por variables locales antes del `include`.
- `views/admin/partials/` y `views/operation/partials/`.

**El shell operativo** (`views/operation/partials/shell.php` + `header.php`) lo
comparten las **tres** pantallas de piso: mapa de mesas (el POS), tablero de
área (KDS) y selector de estación. La cuarta, «Operación de reservaciones», se
retiró: sus direcciones redirigen al POS, que es donde se operan las reservas
(cajón por horario, ficha acoplada, «Cambiar mesas», «Nueva reservación»; la
norma está en `docs/reservaciones/reservaciones.md`). Cada consumidor
sólo entrega sus slots (`$operationalContentHtml`) y sus banderas; el header no
cambia de geometría entre módulos. Las que hay que conocer:
`$operationalView` (`'map'` saca el título centrado), `$operationalUserMenu`
(en falso: salida de un toque, que es lo que quiere una tablet),
`$operationalHeaderCenterHtml` (sólo el POS lo llena: modo Ahora/proyección,
libres, próxima llegada y el aviso «sin mesa» viven en la barra para no
robarle una fila al plano; las reglas van acotadas a
`.operational-header--con-centro`, así que el KDS no cambia),
`$operationalHeaderMenuHtml` (el POS mete ahí, tras el avatar, lo que se
consulta de vez en cuando: ajustes de la vista, «Cómo leer el mapa» y, sólo
admin, el panel; el POS no lleva wordmark), `$operationalShowLastUpdate`, `$operationalHeaderDrawerToggle` (en
falso donde no hay cajón — el KDS) y `$operationalHeaderBack`, que se apaga
para meseros y cocineros porque su destino vive bajo `/admin/` y la guardia de
rol los rebotaría.

El KDS **no tiene diseño propio**, pero sí tiene un código de color cerrado, y
es lo primero que hay que entender antes de tocarlo.

**Un tablero, un color: el de su estación.** `areas_produccion.color` llega por
`--area-accent` desde la vista y corona las **tres** columnas por igual, en un
filete de 3 px. No hay color por estado de columna, ni filete de antigüedad en
la comanda, ni sello azul de «entregado».

Lo único que rompe ese monocromo es **la hora de la cabecera de una comanda**,
que pasa a `--admin-warning-text` a los 5 minutos y a `--admin-danger-text` a
los 10. Es el único dato del tablero que cambia solo con el tiempo y el único
que exige mirar, así que se queda con el único color prestado de la paleta
funcional.

Lo que se probó y se retiró, con su motivo, para que no vuelva por inercia:

- **Un color de estado por columna** (neutro / ámbar / verde en filete y
  contador): el rótulo de la banda ya dice el estado, en palabras y a dos
  centímetros del contador. Repetirlo en color no añadía nada y obligaba a
  meter dos hues más.
- **El filete de antigüedad en el costado de cada comanda**: con el servicio
  acumulado acababa pintado en todas, así que dejaba de señalar la que iba
  tarde, que era su único trabajo.

Todo lo demás del tablero es neutro, **incluidos los dos botones de avance**,
que van en `--admin-accent` / `--admin-on-accent` —el primario del sistema— y
son idénticos en las tres columnas. Estuvieron teñidos con el estado DESTINO
(ámbar el de «Prep», verde el de «Listo»), y eso hacía que el ámbar significara
dos cosas a la vez en la misma pantalla: «esta banda es En preparación» en el
contador de una columna y «manda esto a preparación» en un botón de otra. El
verbo es el mismo en los dos botones, así que el botón es el mismo; a dónde
avanza lo dicen su palabra y la banda en la que está.

Dos consecuencias más, ya aplicadas: «Entregado» **no es azul** (era un color
suelto; la fila va atenuada al 62 %, y eso ya dice que no es trabajo) y la
**nota de un platillo no es ámbar** — se distingue por ser el único elemento
HUNDIDO de la tarjeta, no por hue.

⚠️ Dos avisos sobre `areas_produccion.color`, que es un campo que un admin
edita en el panel y no un token: el de Cocina coincide hoy con `--c-rojo` y el
de Jugos con `--c-ambar`, que son los dos colores que la hora usa para la
antigüedad. Mientras el color de estación viva sólo en el filete superior de
las columnas —arriba, a lo ancho, lejos de la hora de una comanda— no hay
choque; **si alguna vez vuelve a pintar algo dentro de la tarjeta, lo habrá**.
Y al elegir el color de un área nueva conviene no repetir esos dos.

**Y una regla tipográfica igual de corta: mono para las CIFRAS (el contador y
el `×N`), sans para las PALABRAS.** Tres pesos —400, 600 y 700— y ninguno más.
El rótulo de columna estuvo en mono con versalitas, y con la mono apareciendo a
la vez en palabras y en números no había forma de saber qué la convocaba.

Las columnas **no llevan borde**: son bandas de superficie constante
(`--admin-surface-soft`, radio `lg`) separadas por un hueco de 16 px, y la
comanda se despega de ellas con `--admin-surface-strong` más canto de luz y
sombra. Antes columna y comanda eran las dos `--admin-surface` —el mismo color
exacto—, así que el borde de 1 px no era decoración: era lo único que las
distinguía, y por eso había que repetirlo en cada nivel anidado.

`[data-confirm-logout]` lo emite el header, pero el manejador vive en el JS de
cada pantalla: `punto-de-venta.js` para el POS y `modules/area.js` para las dos
de área. Una pantalla nueva que use el header y no ate el suyo cierra sesión al
primer toque, sin preguntar.

**El cajón** (`views/operation/partials/drawer.php` + `operation/_drawer.scss`)
tiene dos cosas medidas:

- Los enlaces de módulo van a `flex: 1 1 0`, con base CERO. Con base por
  contenido el ancho de cada destino lo decidía el largo de su etiqueta —
  "Reservaciones" salía notablemente más ancho que "Mesas"— y los dos mapas, que
  son hermanos y el mesero alterna entre ellos todo el turno, pesaban distinto
  sin que la diferencia significara nada. El `min-width: max-content` sigue
  mandando a la fila siguiente lo que no quepa.
- La fecha se escribe **en cristiano** ("Mié 9 de septiembre"), no en ISO. El
  ISO es el formato del contrato con el backend y viaja en `data-iso`, que es
  lo que lee `shell.js`; el rótulo lo formatean dos tablas escritas a mano —una
  en `pos-workspace.php` para el primer render y otra en `shell.js` para cuando
  el mesero cambia de fecha—. Ni `intl` ni `setlocale` ni
  `toLocaleDateString`: la extensión no está garantizada, `setlocale` devuelve
  inglés en Windows y el idioma del navegador de una tablet nueva tampoco es una
  garantía. Si se toca una tabla, se toca la otra.

⚠️ `.operational-drawer__content` es `overflow: hidden`, así que **cualquier
desplegable `position: absolute` de dentro se recorta**. Al calendario del POS
le pasaba en vertical —la última semana del mes quedaba cortada contra el borde
y no había forma de llegar a esos días— y antes le había pasado en horizontal.
Ahí se resolvió poniéndolo **en flujo** (`position: static`): en el POS el
calendario está abierto todo el tiempo y empujar la lista hacia abajo es más
honesto que flotarle encima; la lista ya tiene scroll propio y absorbe la
diferencia. Si alguna vez tiene que seguir siendo desplegable, la salida es
portarlo al `<body>` con `position: fixed` —lo que hace `core/select.js`—
revisando el `z-index` contra el modal de mesa, que está en 200.

**El selector de periodo** (`views/admin/partials/_range-picker.php` +
`core/range-picker.js` + `Services\Shared\RangoPeriodo`) lo comparten analíticas,
finanzas, inventario y reservaciones. Dos cosas que no son evidentes:

- La **comparación contra el periodo anterior va siempre encendida**. Era un
  interruptor dentro del popover y se retiró: comparar contra el periodo
  inmediatamente anterior de la misma duración no es un extra, es lo que separa
  «vendimos más» de «el periodo era más largo». `RangoPeriodo::comparativo()`
  se calcula sin preguntar, así que los deltas también salen en la primera carga
  sin query string.
- El rango por defecto de `RangoPeriodo` es **retrospectivo** (últimos 30 días).
  Reservaciones mira hacia delante: el histórico tiene su propio `[hoy, hoy+30]`
  y Grupos grandes y Casos usan `RangoPeriodo::resolverProspectivo()`. Si un tablero sale vacío con datos en la base,
  ése es el primer sitio donde mirar.

**La Agenda de reservaciones es una libreta, no una tabla ni una rejilla de
tarjetas** (`views/admin/reservations/index.php` +
`Services\Reservations\ReservacionListadoPresenter` + `reservations.scss`). Es
la primera pestaña del módulo (Agenda · Grupos grandes · Casos; la clave
interna sigue siendo `historico`) y abre en los próximos 30 días. Cada día es
una hoja con cabecera fija («N reservaciones · N personas · N no llegaron»):
el número del día en el margen, la hora escrita una vez por franja en el mismo
margen, y de una línea vertical cuelgan las reservas con una MARCA cuya forma
dice el estado (○ confirmada, ● en mesa, ✓ atendida, ⊘ no llegó, – cancelada,
◐ apartada; ámbar macizo = tolerancia vencida). La etiqueta del estado va
además en palabras: el color nunca dice nada solo. Las terminales se atenúan
con contorno discontinuo y nunca en rojo, y la tolerancia vencida
(`elegible_no_show`) es la única urgencia y la única acción que se resuelve ahí
(«No llegó», también en días pasados; `lista.js` intercepta en captura antes
que el diálogo genérico). En el día de hoy, una raya «Ahora · HH:MM» separa lo
que ya debió llegar de lo que falta. La simplificación siguió a la del POS:
salieron las cinco tarjetas de métricas (tres medían cosas que el modelo de
tres estados ya no produce), «Más filtros» (estado → tira, asignación → Casos,
origen → detalle), los chips, el conmutador lista/agenda y los botones de
crear/abrir en el POS del encabezado (las altas son del POS; no se borró
ninguna ruta).

- Filas en columnas fijas desde 900 px de **contenedor** (`container:
  agenda`), dos renglones por debajo: cada fila es su propia rejilla, y sólo
  con anchos fijos se alinean personas, mesas y estado. La fila entera abre el
  detalle (el `::after` del nombre la cubre); las acciones van por encima con
  `pointer-events`.
- La tira de estados es resumen y filtro a la vez, y deliberadamente NO es
  `.admin-tabs--links`: `syncLinkTabs()` de reactive-filters marcaría «Todas»
  activa junto a la elegida. Sus cifras ignoran el estado elegido (otra
  consulta sólo si hay estado).
- El estado y el periodo viajan en campos ocultos del formulario de búsqueda
  (sin ellos buscar los perdía); el periodo sin `data-reactive-control`, para
  que «Limpiar» no deje el selector diciendo un periodo y la lista enseñando
  otro. La URL canónica (`urlListado`) omite el periodo por defecto.
- Rótulo de mesas y estados en la voz del POS
  (`ReservacionListadoPresenter::mesas()` ≡ `nombresMesas()` de
  `pos-reserva-vista.js`: «Mesas 3 y 4», «Mesas 8 a 11»): si cambia uno, cambia
  el otro. Casos y Grupos grandes lo usan también.
- `reemplazada`, `pendiente_verificacion` y `expirada` no salen en «Todas»;
  `?estado=` las sigue mostrando.
- ⚠️ `_range-picker.php` deja definida una `$dias` (itera los presets y no la
  limpia con `unset`): la vista usa `$diasAgenda`. Cualquier vista que incluya
  el selector no debe llamar `$dias` a nada suyo.
- `.reservations-table__status--*` sigue vivo para `show.php` (las terminales,
  atenuadas); el cajón del POS tiene su propia copia en
  `operation/_reservation-list.scss`.
- Casos: el cuándo es el ancla en el margen, como en la Agenda; la severidad la
  dice la etiqueta del caso principal, no un filete. Las pestañas de tipo con
  cero casos se ocultan, salvo la elegida.

Vocabulario de clases admin: `admin-page`, `admin-card`, `admin-panel`,
`admin-btn--{primary,secondary,ghost,tinted}`, `admin-table`,
`admin-badge--{success,warning,danger,neutral,info}` (combinables con
`--outline`, que vacía el fondo y deja color en tinta y filete),
`admin-badge--cat-0…9`, `admin-pagination`, `admin-field` +
`__label/__hint/__error`, `admin-switch`, `admin-tabs__tab`, `admin-modal`,
`admin-pills` + `admin-pill`.

**`admin-pills` sustituye a un `<select>`, no lo acompaña.** Es para el caso en
que el catálogo entero cabe en pantalla —dos o tres opciones excluyentes, o las
cuatro áreas de producción— y esconderlo tras un desplegable obligaba a abrirlo
para saber qué había. Con veinte opciones, un select sigue siendo lo correcto.
El control es un `<input type="radio">` **real**, recortado con `clip-path` (no
`display:none`, que lo sacaría del recorrido de teclado): así conserva el envío
del formulario, las flechas dentro del grupo, el `required` nativo y el estado
tras un POST fallido sin una línea de JS. El estado marcado se pinta con
`:has()`, con respaldo `@supports not selector(:has(*))` que devuelve el radio
nativo — sin él, en un motor antiguo las opciones se verían todas iguales y el
grupo dejaría de funcionar, no sólo de verse bien.

⚠️ Una regla de módulo del tipo `.admin-menu__form input { min-height: 44px }`
alcanza a esos radios y los convierte en rectángulos gigantes. `menu.scss` ya la
acota con `input:not([type="radio"])`; cualquier hoja nueva que estilice inputs
por elemento tiene que hacer lo mismo.

**Los iconos salen de un catálogo, nunca del marcado.**
`views/admin/partials/_icons.php` expone `admin_icon($nombre, $tamano, $clase)`
y `src/js/admin/core/icons.js` expone `window.AdminIcons.get(nombre, tamaño)`
para los módulos que pintan con concatenación (viaja en `admin.js`, que el
layout carga **antes** que cualquier bundle de módulo). Son dos catálogos porque
los consumidores hablan dos lenguajes, no porque deban divergir: al añadir un
icono que necesiten los dos lados, se añade en los dos.

El `stroke-width` **no** es un parámetro a propósito: lo fija el CSS del
contexto. Dejarlo pasar por la firma reproduciría justo lo que el catálogo
resuelve —el mismo icono con tres grosores según quién lo escribiera—. Un nombre
desconocido devuelve cadena vacía y no un icono de relleno: un hueco se ve en la
primera pasada, un icono equivocado dura años.

Y la regla que ya estaba escrita para el POS vale igual aquí: **ni un emoji ni
un glifo haciendo de icono**. Lo pinta la fuente del sistema, así que no hereda
`currentColor` —una flecha ▲ dentro de un badge rojo se quedaba en la tinta del
navegador—, cambia de forma entre plataformas y a veces ni existe: la estrella
vacía `☆` de Feedback salía como un rectángulo en algunas caras. Al cambiar un
glifo por SVG hay que dar `display: inline-flex` + `gap` a su contenedor: el
espacio que lo separaba del texto era el del carácter y deja de existir.

**Las consultas rápidas** (`core/consultas-rapidas.js`, en `admin.js` +
`_consulta-rapida.scss` + `GET /admin/api/consultas` →
`Services\Analytics\ConsultasRapidas`) son el motor del **Asistente IA**, el
ÚNICO sitio del panel donde se pregunta. Hubo una barra con campo de texto en
Tickets, Análisis, Menú e Inventario; se retiró, y después el cliente retiró
también el texto libre del asistente, así que el JS ya no tiene barra, ni «/»,
ni «Preguntar por «…»». NO es un modelo de lenguaje: cada pregunta es una
INTENCIÓN estructurada (`?intencion=&id=&opcion=&periodo=`) con su plantilla
de huecos. Hay 12 (`ConsultasRapidas::INTENCIONES`, en este orden en
`capacidades()`):

- Ventas: `ventas_total`, `ventas_producto`, `ventas_categoria` («Ventas por
  categoría»; nunca «familia» en pantalla), `top`, `ticket_promedio`,
  `sin_venta`.
- Equipo y servicio: `meseros`, `horas_pico`, `cancelaciones`.
- Inventario: `stock`, `consumo`, `bajo_stock`.

Las ventas se cuentan como en el resto del panel (tickets cerrados, sin
renglones cancelados, por `COALESCE(hora_cierre, hora_apertura)`). Excepciones
deliberadas: `horas_pico` agrupa por hora de APERTURA (llegada), y `consumo`
suma los movimientos `venta` − `cancelacion` de `movimientos_inventario` por
su fecha —la receta que explotó `Inventario::aplicarVenta()` al mandar la
comanda, sin volver a explotarla—, así que cuadra con la existencia aunque la
receta cambie (y en la base de desarrollo sale casi siempre en cero: los
tickets de prueba no generan movimientos). El intérprete de frases
(`responder()`, que entiende «familia» como sinónimo de categoría) se conserva
como contrato del texto canónico y para repetir preguntas de texto guardadas
antes del cambio. Además lee **hora, franja y día de la semana**
(`ConsultasRapidas::horario()`, prueba `run-consultas-por-hora.php`): «a las
9» (de 1 a 7 sin am/pm se lee de la tarde: la casa abre a las 8), mañana hasta
las 12:59, tarde 13-18:59, noche desde las 19:00 y «los sábados» —sin periodo
dicho, un día de la semana mira las últimas 8 semanas (periodo `8s`, fuera de
`PERIODOS`: no es un hueco)—. La hora es la del PEDIDO (`ticket_items.created_at`)
y viaja en el periodo como `hora_sql`, que filtran las ventas de platillo y de
categoría y lo más vendido; el total del restaurante no se separa por hora
(`ventasTotalesOHora()` lo dice en vez de contestar el del periodo entero). Es
sólo del intérprete: las consultas estructuradas de los huecos no llevan hora.

- **La respuesta trae** `interpretacion`, `plantilla` (huecos) y `respuesta`. En
  el desglose, `valor` numérico mide la barra y `valor_texto` es cómo se lee
  (mono sólo si es cifra). La comparación lleva `tendencia` (la flecha) y
  `lectura` (`bien` | `mal` | `igual`, el color): más cancelaciones o más
  platillos sin venta suben y son malas, y el consumo es neutro. Las cifras de
  respuesta van en `--admin-font-cifra`.
- **Un hueco puede fijar su intención** (`intencion`): el de ingrediente de
  `consumo` elige de la misma lista que la existencia, cuyas opciones traen
  `stock`. `consultaDeOpcion(parte, op)` lo aplica.
- **El selector de un hueco** es uno por página, portado a `<body>` y fijo
  (z-index 130, como `.admin-select__list`). Con buscador es un `dialog` con
  `combobox` + `aria-activedescendant`; sin él, el foco va al `listbox`.
  Enter, Espacio o ↓ abren un hueco, y una letra lo abre filtrado
  (`teclaDeHueco`, compartida con el compositor). Escape devuelve el foco al
  hueco, Tab cierra y sigue desde él, y tras repintar el foco vuelve al hueco
  del mismo CAMPO. `ancho` fija el ancho y deja el detalle en varios renglones
  (la lista de preguntas base).
- **No entendido es dato, no error**: hoy sólo ocurre con una cosa que ya no
  existe. Llega con `entendida: false`, respuesta `sin_resultado` y plantilla
  de rescate. Sólo una intención desconocida es 400.
- **API** `window.ConsultasRapidas`: `consultar`, `montar(contenedor,
  opciones)` (devuelve `{consultar, pintar, limpiar, destruir, ultima}`;
  eventos `consulta-rapida:cambio` y `consulta-rapida:respuesta`; opciones
  `ambito`, `alCambiar`, `alPintar`, `alOcupado`, `alLimpiar`, `anunciar`,
  `enlace`, `nivelTitulo`), `capacidades()`, `catalogo()`, `elegir(boton,
  parte, alElegir, filtro)`, `consultaDeOpcion`, `teclaDeHueco`,
  `selectorAbierto()` y `cerrarSelector()`. Contrato comprobado por
  `php scripts/tests/run-consultas-rapidas-db.php` (sólo lee).

El módulo **Asistente IA** (`/admin/ia`) es una conversación sobre ESTE mismo
motor, sin modelo de lenguaje (n8n se descartó) y **sin texto libre**. El
compositor ES una pregunta armada con huecos («Ventas de [todo el restaurante
▾] · [Hoy ▾]») y siempre hay una: nace con la primera del catálogo (el servidor
la pinta en la primera impresión), se cambia desde el catálogo de la
conversación nueva (familias Ventas / Equipo y servicio / Inventario; la
armada lleva `aria-pressed`), desde el botón de la pregunta en la fila de
abajo o con Ctrl/⌘+K, y tras enviar se queda con la pregunta contestada en la
forma del motor, salvo que se tocara mientras se esperaba. **Cada vez que se
arma una pregunta base sale con un ejemplo distinto**: `capacidades()` devuelve
`ejemplos` (hasta 8 combinaciones REALES de cosa y periodo —los platillos y
categorías que más salen, los ingredientes más justos o más consumidos—, con
los periodos de `PERIODOS_DE_EJEMPLO`, que evitan tramos que suelen contestar
en cero), `AdminIaController` hace un borrador por ejemplo (`data-ia-borradores`)
y `ia.js` (`borradorAlAzar`) arma uno al azar, nunca el que ya está armado; la
primera impresión la elige el servidor, también al azar. Teclado (sin pista
escrita en la caja: el cliente la retiró; la anuncian los `aria-keyshortcuts`):
Tab entre huecos, Enter/Espacio/↓ abren, Ctrl/⌘+Enter envía. Elegir una pregunta del
catálogo la hace viajar al compositor con una View Transition de nombre
PASAJERO (`ia-pregunta`, tipo `ia-armar`, que apaga el fundido de raíz); el
repliegue es un destello de fondo. Cada respuesta es un `montar()`: un hueco
la rehace en su sitio y un renglón abre una pregunta nueva. Las
conversaciones viven en `localStorage` y se guardan sólo las **10 más
recientes** (`AdminIaController::MAX_CONVERSACIONES`, que también dice el pie
del historial): al abrir una más se borra la más antigua, y lo guardado de
antes se recorta al cargar. El botón de borrar conversación es papel sobre
rojo sólo al apuntarlo o enfocarlo; en reposo es el glifo neutro, como
«Salir», porque diez rojos fijos en una tablet dirían «hay algo que atender».
Ver `docs/config.md`.

Dos piezas compartidas que conviene conocer antes de escribirlas otra vez:

- **Ordenar una tabla** — `src/js/admin/core/table-sort.js`, que viaja en
  `admin.js`. Basta `data-sortable` en el `<table class="admin-table">` y
  `data-sort-type="text|number"` en cada `<th>` (`data-sort-disabled` excluye).
  Una celda que apile `__cell-main` + `__cell-sub`, o que pinte una fecha en
  `d/m/Y`, o que sea un `<form>`, **necesita `data-sort-value`**: si no, ordena
  por el `textContent` de todo lo que haya dentro. Expone
  `window.AdminTableSort.{init, initTable, reaplicar}` para las tablas que se
  pintan o se reemplazan después (analíticas las genera en JS; menú y usuarios
  las sustituyen con filtros reactivos), y se re-engancha solo en
  `admin:reactive-updated`.
- **Paginar** — `views/admin/partials/_pagination.php` (`$pagPagina`,
  `$pagTotal`, `$pagUrl` como callable, `$pagReactiva`). Ventana deslizante de
  cinco páginas con elipsis; el estilo es `.admin-pagination` en `_tables.scss`.
  Lo usan Menú y Recetas (10/página, reactivo), Tickets (20/página) y los
  cortes de caja de Finanzas (14 días por página, `?cortes=N#cortes`: los
  enlaces reconstruyen `rango` o `desde/hasta` más `comparar`, y el `<tfoot>`
  suma el periodo entero, no la página).
  **Al paginar, las métricas de cabecera necesitan consulta propia**: si se
  calculan recorriendo las filas traídas pasan a describir la página, no el
  total. Es lo que le pasaba a Tickets.
- **Tarjetas-filtro y listas de pendientes** — `partials/_filtros-estado.php`,
  `_tareas-completas.php` y `modules/_pendientes.scss` (incluido con `@use`
  desde la hoja de cada módulo; también trae la fila de tarjetas + buscador,
  `.admin-filtros-fila` / `.admin-filtro-buscar`). La tarjeta es un enlace con
  `aria-current` y su filtro va en la URL: Menú `?pendiente=receta`, Recetas
  `?estado=sin`, Inventario `?pendiente=stock|proveedor` (sólo ingredientes
  activos). Elegirla convierte la lista en una lista de pendientes con su
  acción directa por fila («Crear receta →», «Sumar», «Asignar proveedor →»).
  Las tarjetas de tarea cuentan lo HECHO («30 de 107», con barra), no lo que
  falta; sus cifras salen de consulta propia y el alcance es carta y
  categoría, nunca la búsqueda. El acuse de lista completa es el único
  movimiento de estas listas (y no sale si la lista se vació por buscar). El
  enlace estirado a toda la fila (`.admin-tarea__enlace`) no puede llevar
  `position`, `overflow` ni `transform` al pasar el cursor: cualquiera de los
  tres encoge el área pulsable al botón. El editor de recetas acepta
  `?volver=` limitado a `/admin/menu` y `/admin/recetas`
  (`AdminRecetasController::volverSeguro`) y vuelve allí al guardar, con el
  aviso en `$_SESSION['_admin_receta_guardada']`. ⚠️ `$alertas` es UNA lista
  estática de ActiveRecord compartida por todos los modelos: juntar
  `X::getAlertas()` con `Y::getAlertas()` duplica cada aviso.
- **Tickets** (`/admin/tickets`, `Services\Pos\HistorialTickets`): las cuatro
  tarjetas son el filtro de la lista (`?estado=abierto|cerrado|cancelado`, más
  el selector de periodo) y su cifra sale de `totalFiltrado()`, del mismo
  resumen que la paginación. «Abiertos» ignora el periodo a propósito (es la
  cuenta viva y la que importa encontrar es la de hace dos semanas);
  «Cerrados» enseña la venta, como Finanzas. `tickets.js` sólo sustituye
  `_lista.php` (el controlador lo sirve suelto con `X-Requested-With`), con
  `pushState`, y emite `admin:reactive-updated`.
- **Impresoras**: el color de cada fila es el de su área
  (`AdminPrintersController::areasProduccion()`, de `areas_produccion`), en
  línea como `--prn-area`, y su tinta (`--prn-area-tinta` = `var(--admin-ink)`
  o `var(--admin-paper)`) la elige el servidor por contraste WCAG. Rol y Área
  son una sola columna, «Imprime»: en esa tabla el color significa sólo
  «estación». «Imprimir prueba» es azul sólido y pide JSON
  (`Accept: application/json`) para enseñar el resultado sin salir de la fila.

En la landing, `.btn-line` es el único vocabulario de CTA: `--solid` para el
principal de una sección (relleno de acento con `--on-accent` encima) y
`--secondary` para el secundario. Lleva `.btn-line__icono` (un SVG delante de la
etiqueta); el formato va en la etiqueta («Carta completa en PDF»), no en una
chapa aparte. El texto del botón base va en
`--txt-strong`, nunca en un alias: es lo que lo mantiene legible al cruzar de
tono.

**Las dos cartas en PDF no son botones gemelos.** «Carta completa» vive en Menú
(`--solid`, oro sobre verde, cubiertos) y «Carta de maridaje» en Catas
(`--solid`, verde sobre la banda de oro, copa): las distinguen el color, el
icono, la etiqueta y el sitio. Menú NO enlaza al maridaje: cada carta vive en
su sección.

**Las pestañas de la carta desbordan a la vista.** El contenedor
`.menu__pestanas` lleva los filetes y las flechas (fuera del `tablist`, con
`aria-hidden` y `tabindex=-1`: son atajo de puntero; con teclado el propio
grupo trae la pestaña a la vista). `menu.js` publica `.is-desborda-ini` /
`.is-desborda-fin` y con ellas aparecen la máscara de borde (extremos con
`@property`) y la flecha de ese lado; la flecha da un empujón la primera vez que
la fila entra en pantalla. Arrastre con inercia, rueda vertical que mueve la
fila sólo mientras le queda recorrido (y con enganche de 250 ms si el gesto
empezó fuera), `data-lenis-prevent` puesto y quitado evento a evento, y
`scroll-snap` **sólo** con puntero grueso: con ratón Chrome ajusta cada
asignación de `scrollLeft` al anclaje y la fila da tirones.

**El imán es UNA implementación**, `modules/magnetic.js`, para todo
`[data-magnetic]` (los accesos del hero, «Reservar mesa», el botón ≡, las
cartas en PDF): un muelle propio en un solo rAF que duerme en reposo, sin GSAP.
Mueve la PROPIEDAD `translate`, que no pisa `transform` (la X del menú sigue
funcionando); `data-magnetic="0.2"` cambia la fuerza; la etiqueta
(`[data-magnetic-label]`, que debe ser `inline-block`: `translate` no se aplica
a cajas en línea) va al 45 % con un muelle más blando. Sólo con `(hover:
hover) and (pointer: fine)`, topado a 14 × 9 px, y apagado en caliente con
movimiento reducido o `.no-anim`. Las barras del botón de menú viven en
`.nav-toggle__barras`: el gap de 6 px de la cuenta de la X está ahí.

**La carta** (`_menu.php` + `menu.js` + `_menu.scss`) pinta desde `/menu`
(`Carta::publica`): pestañas ARIA con flechas, nombre en The Seasons, puntos
guía hasta el precio y la descripción en el cuerpo. **La foto NO va junto al
nombre** (hubo miniaturas por fila y se retiraron: la carta parecía un
catálogo): es UNA, la grande de la sección, y muestra la del platillo que se
señala —`productos.img`, o si no tiene la de su categoría, `categorias.img`,
las dos se suben en el panel—. Pulsar un platillo la abre en el visor. En una
columna la foto grande sube encima de la lista, apaisada. Los textos del panel
entran por `innerHTML`, así que pasan por `escCarta()`.

**La carta de maridaje de Catas** (`_catas.php` + `maridaje.js`) se imprime en
el servidor (`$cartaMaridaje` desde `HomeController`): una pestaña por sección
de la barra con TODAS sus bebidas, sin recortar. Sin JS se ven todas las
secciones con su título; `maridaje.js` las convierte en pestañas.

### El modal de mesa del POS

Lo que gobierna sus dos acciones de salida, que es lo que más veces se ha
tocado mal:

- **El título es el nombre de la mesa y nada más.** `mesa.nombre` ya es
  "Mesa 9", así que el `#9` de al lado repetía la cifra y encima con aire de
  ser otra cosa —un folio, un ticket—. El número sólo se emite si el nombre NO
  lo contiene (`nombreIncluyeNumero()`, que compara el número como palabra: con
  `indexOf`, "Mesa 1" daría por dicho el 12).
- **«Cancelar mesa» desaparece con la primera comanda.** `actualizarCancelarMesaEstado(total)`
  lo oculta con `[hidden]` en cuanto `total > 0`, donde `total` cuenta TODAS las
  filas de `ticket_items` —cancelados incluidos, porque un item cancelado ya
  movió inventario y el backend exige lo mismo—. Antes se quedaba en pantalla
  bloqueada para poder explicar al pulsarla por qué no valía; con consumo,
  cancelar deja de ser una opción y una pastilla apagada que nunca va a volver
  a servir es ruido en la esquina más cara del modal. Va con `[hidden]` y no con
  una clase para que salga también del recorrido de tabulación y de
  `modalFocusables()`, que filtra por `:not([hidden])`.
- **«Cerrar ticket» tiene DOS frenos, y el segundo faltaba.**
  `actualizarCierreEstado(pendientes, total)` deshabilita si quedan productos
  sin entregar **o si no se ha enviado nada**. Un ticket recién abierto tiene
  cero pendientes, así que con la cuenta antigua el botón nacía habilitado y
  ofrecía cobrar una mesa en la que no se ha pedido nada — el camino directo al
  ticket vacío que luego hay que descartar a mano. El backend revalida al
  cerrar; esto es la señal en pantalla.

**Llevar —y las barras— son ventanillas, no mesas.** Admiten varios pedidos
abiertos a la vez, cada uno un ticket propio sobre la misma fila de `mesas`; las
barras, hasta `configuracion_pos.max_tickets_barra` (4 por defecto, `BARRA_LLENA`
al pasarse; Llevar no tiene tope). Lo decide el SERVIDOR
(`elementoMultiTicket()` en `abrirWalkIn`), no el cliente: antes bastaba
con mandar `allow_multiple` para abrir un segundo ticket sobre cualquier mesa del
salón. Tampoco se une a otras mesas. En el mapa nunca se pinta ocupada —un pin
rojo decía «no cabe otro», lo contrario de la verdad—; su rótulo cuenta los
pedidos (`subtitulo` de `map-visual.js`). El modal tiene tres pantallas que no
lo cierran entre sí: tablero de pedidos, alta y el ticket normal con una tira
encima (`buildLlevarSwitch`) para saltar a otro pedido o volver al tablero.
Saltar con platillos sin enviar pide confirmación; cobrar o descartar un pedido
vuelve al tablero si quedan otros. El folio del pedido es el id del ticket.

Y tres cosas de su caja que no se deducen del archivo:

- **Vidrio propio.** `.mesa-modal` declara una escala local (`--pos-glass-panel`,
  `--pos-glass-col`, `--pos-glass-blur*`, `--pos-glass-sat`) en vez de consumir
  `--glass-bg-strong`. El rol del sistema está calibrado para un diálogo del
  panel, que tapa una página de lectura; aquí el modal se abre sobre el mapa del
  salón y que el plano se adivine detrás **es información**. Los porcentajes
  (78 % el panel, 64 % las columnas) están medidos contra el peor caso —el modal
  sobre una zona llena de mesas—: más transparencia y los rótulos de los pines
  empiezan a competir con el texto del modal, y en el POS la legibilidad manda
  sobre el efecto. El `saturate` no es adorno: sin él, negro desenfocado sigue
  siendo negro y el cristal no se distingue de un plano opaco.
- **Cursores del piso**: `.mapa-page :where(button, …) { cursor: pointer }`.
  Nunca volver a `.mapa-page button { cursor: … }`: suma (0,1,1) y le gana a
  toda clase de botón, que es por lo que los platillos salían con flecha.
- **La voz tipográfica del POS** vive en `punto-de-venta/_voz.scss`: `rotulo`
  (mono, 11 px, .12em, versalitas), `cifra-columna` (mono tabular),
  `cifra-total` (`--admin-font-cifra`, sólo cifras que se leen solas: total del
  pedido, «A cobrar», cambio, corte) y `control` (sans 600 sin tracking, con
  `.admin-body &` por la trampa (0,1,1)). Expose como titular sólo en
  `.mmodal-title`. El anillo de foco es el de `_finishes`
  (`.admin-body :focus-visible`), que se emite al final de `operation.css`: no
  hace falta declarar uno propio. Los `<p>` del piso con color o margen propio
  van con un ancestro delante por `.admin-body p` (0,1,1): sin eso «Faltan» y
  «Sobran» del reparto salían en gris.
- **Ni un emoji.** Todo icono del POS sale de `SVG_PATHS` + `svgIcon(nombre,
  tamaño)` en `punto-de-venta.js`, y las etiquetas de botón de `btnLabel()`. Un
  emoji lo pinta la fuente del sistema: no hereda `currentColor`, cambia de
  forma entre Windows, Android e iOS —y la tablet del piso no es siempre la
  misma— y se sale de la caja tipográfica. Lo mismo vale para los glifos sueltos
  que hacían de icono (`☰ ◎ ◌ ⚠ ↻ ✎ ✓ → ←`). Al cambiar uno por SVG hay que dar
  `display: inline-flex` + `gap` a su contenedor: el espacio que los separaba
  del texto era el del carácter y ya no existe.
- ⚠ **La regla global de campos gana a cualquier clase.** `_forms.scss` declara
  `.admin-body input:not([type=checkbox]):not([type=radio]):not([type=range])
  :not([type=submit]):not([type=button])`, que suma **(0,6,1)** —cada `:not()`
  aporta la especificidad de su argumento— e impone `padding: 12px 14px`, borde,
  fondo y `min-height`. Un `.mi-clase` sobre un `<input>` del piso no la toca:
  es lo que dejaba la lupa de «Buscar platillo» encima de la primera letra,
  porque el `padding-left` del que dependía nunca llegaba a aplicarse. Igualarla
  exige siete clases, así que se resuelve con `!important` acotado (el filtro de
  búsqueda del cajón de reservaciones ya lo hacía) o, mejor, moviendo borde y
  fondo al CONTENEDOR y dejando el input desnudo dentro de un flex con `gap`.
- ⚠ **Y su prima, que afecta a botones, párrafos y encabezados.**
  `.admin-body button { font: inherit }`, `.admin-body p { color }` y
  `.admin-body h1…h3` (en `_globals.scss` / `_typography.scss`) suman
  **(0,1,1)** y le ganan a cualquier clase sola (0,1,0): un `<button
  class="mi-pildora">` sale a 16 px/400 aunque su regla pida 12 px/600, y un
  `<h2 class="mi-rotulo">` sale como titular de página. Vale para el panel Y
  para el piso, que también es `.admin-body` (pasó en la hoja de opciones del
  POS). La salida de la casa es escribir el componente bajo `.admin-body` o con
  `& &__elemento` dentro del bloque —(0,2,0)—, como hacen `variantes.scss`,
  `_consulta-rapida.scss` y `punto-de-venta/_variantes.scss`. Pendiente
  conocido: `<button class="admin-btn">` sale a 16 px mientras `<a
  class="admin-btn">` sale a 13 px; arreglarlo en `_globals.scss` toca todos
  los módulos.

Vocabulario de `.mmodal-btn`: `--primary` (relleno de acento), `--danger` (rojo:
sólo lo que termina algo), `--release` (naranja: liberar una mesa por ausencia
no destruye la cuenta, avisa), `--pending` (neutro, acompaña a `--primary`
cuando aún no se puede pulsar), `--ghost`, `--secondary`, `--outline`.
`--release` y `--pending` llegaron a existir sólo en el JS: sin regla en el SCSS
salían transparentes, texto suelto donde debía haber un botón.

**`--danger` va en relleno MACIZO desde el reposo**, no en tinte. Estuvo en un
tinte al 24 % con borde, y sobre el cristal oscuro del modal eso es
indistinguible del gris de un botón deshabilitado — «Cerrar ticket» enseña los
dos estados seguidos en la misma esquina, así que la diferencia tenía que ser de
familia y no de intensidad. No compite con `--primary`: en los tres sitios donde
sale, su vecino es un `--ghost`. Y `--pending` no se distingue por color sino
por el borde discontinuo de `&--pending:disabled`: el JS lo emite **siempre**
junto a `disabled`, así que su color era código muerto.

⚠️ Al añadir o cambiar una variante, comprobar que ningún bloque posterior del
mismo archivo —o de `_pago-modal.scss`, que carga después— repite el selector.
Ahí ya se perdieron **tres** veces el color del destructivo y el estado
deshabilitado: llegó a haber dos `&:disabled` y dos `&--release`, y el segundo
de cada par ganaba por orden y devolvía el botón justo al estado que el
comentario de arriba decía haber arreglado. Una variante, una definición.

### Diálogos: nada nativo

**Prohibido `alert()`, `confirm()` y `prompt()` en JS.** Un diálogo del navegador
bloquea la pantalla —en la tablet del POS, el turno entero— y no admite estilo.
Toda comunicación es un componente:

| Necesidad | Componente | Dónde |
|---|---|---|
| Confirmar una acción | `ConfirmationModal.get().open({...})` | `src/js/components/confirmation-modal.js` |
| Aviso transitorio (error de API, validación) | `AppNotice.show({text, variant})` | `src/js/components/toast.js` |

`ConfirmationModal.open()` devuelve una promesa que resuelve con
`{action: 'primary' | 'secondary' | 'close' | ...}`. Usar `get()` (singleton en
`<body>`), no `create()`, salvo que se necesite un root propio: `create()` monta
uno nuevo en cada llamada y nadie los recoge.

Dos opciones para los borrados que arrastran otras filas por `ON DELETE
CASCADE`:

- `customContent` — un nodo que se monta en la ranura `[data-confirmation-custom]`.
  Es donde va lo que hay que ver antes de decidir. Inventario lo usa para listar
  los platillos y subrecetas que se quedarán sin el ingrediente, pedidos a
  `GET /admin/api/inventario/uso` (`Services\Inventory\Inventario::recetasQueUsan()`, la
  única consulta INVERSA del módulo: todo lo demás va producto → ingredientes).
- `requireText` — deja el botón principal deshabilitado hasta que se teclea ese
  texto. Compara sin acentos ni mayúsculas: se busca que el usuario LEA lo que
  borra, no que reproduzca la ortografía.

**En el diálogo genérico, `requireText` se pide por atributo:**
`data-confirm-require="<nombre del elemento>"` en el `<form
data-confirm-delete>`, que `admin.js` reenvía al componente. Va así para que
cada vista decida qué hay que escribir sin tocar JS, y porque **sin el atributo
el diálogo se comporta como siempre**: un borrado nuevo que se olvide de
ponerlo sigue preguntando, sólo sin el freno extra. Lo llevan los nueve
formularios del panel (finanzas, catas, inventario, proveedores, categorías,
menú, impresoras, subrecetas). Inventario, que engancha su propio diálogo, lo
pasa en su `open()`; Usuarios tiene modal dedicado y ya lo pedía.

El diálogo **entra y sale animado**, y eso condiciona el JS: `close()` quita
`.is-open` pero aplaza el `[hidden]` hasta que acaba la transición, porque
`display:none` la cancelaría y la salida no se vería nunca. Un contador de
generación invalida ese ocultado diferido si se reabre antes de que termine —
`open()` llama a `close()` cuando ya hay un diálogo en pantalla, y el
temporizador viejo dejaría `[hidden]` puesto sobre el diálogo nuevo. Si se toca
la duración en el SCSS, el JS la lee de `--confirmation-out`: no hay un segundo
número que sincronizar.

Cuando un módulo quiera un diálogo más rico que el genérico de
`[data-confirm-delete]`, se engancha **en captura sobre el `document`** y detiene
la propagación (lo hace `inventario.js`). Registrar el listener en el propio
formulario no sirve: `admin.js` ya tiene el suyo ahí y en el elemento destino
corren por orden de registro. La ventaja de capturar es que si el JS del módulo
no llega a cargar, el diálogo genérico sigue preguntando: nunca se borra sin
confirmación.

Los avisos siempre llevan texto de respaldo: `aviso(result.mensaje)` con un
`mensaje` vacío del servidor no debe producir una caja en blanco.

El lightbox (`src/js/modules/lightbox.js`) abre, cierra y pasa de foto con la
API de **View Transitions**. `document.startViewTransition` no existe en todos
los navegadores, así que **siempre** va detrás de `conTransicion()` /
`conMorfo()`, que ejecutan el cambio en seco cuando falta la API o cuando hay
movimiento reducido: llamarla a pelo dejaría el visor sin abrirse en el resto.
⚠️ Mientras corre el morfo, `.lightbox--en-vista` **congela el fundido CSS**
del visor: la instantánea «nueva» se toma al principio, con el visor todavía a
opacidad 0, y el morfo volaba hacia un fondo que no existía (por eso «no se
veía»). Es la misma trampa que el cambio de tema del panel. Las capturas de
`zoom-origen` van con `object-fit: cover` para no deformarse entre una
miniatura en arco y una foto apaisada. Las fotos ampliables reciben
`tabindex`, `role="button"` y etiqueta (salvo dentro de un `aria-hidden`), y el
foco vuelve a ellas al cerrar.
El `view-transition-name` del morfo se pone justo antes y se retira al terminar
—dos elementos no pueden compartirlo a la vez— y mientras corre no se lanza el
tween de GSAP, que produciría dos aperturas superpuestas.

Lo que hace que el morfo salga de verdad, y que ya falló:

- Se morfa la **caja que recorta la foto** (`cajaDeMorfo`), nunca el `<img>`:
  el `<img>` va escalado al 122 % por la portada, desplazado por el parallax y
  sin el arco. El nombre se pone en la pieza de ANTES y se pasa a la de
  DESPUÉS dentro del cambio; sólo la transición vigente pone y limpia
  (`soltarNombres`). Con los dos nombrados a la vez no había morfo.
- El visor (`#lbVisor`, `.lightbox__img`) mide lo que la foto y su tamaño sale
  de `--lb-ratio`, no de la resolución: se abre con la imagen que la miniatura
  ya tenía (`currentSrc`) y se cambia a la del `src` al llegar sin mover nada.
  Las vecinas se precargan. Pasar de foto desliza según el sentido (`lb-foto` +
  `html[data-lb-sentido]`); `.lightbox--sola` oculta flechas y contador.
- **Zoom**: `translate(x,y) scale(s)` con origen 0 0 sobre el `<img>`, SIN
  `will-change` (con la capa fijada Chrome no re-rasteriza y la foto ampliada
  se ve borrosa). Doble clic/toque, rueda, pellizco (también de trackpad),
  arrastre con límites que nunca enseñan el fondo, barra − nivel + con
  `aria-disabled` (no `disabled`) y región viva, teclas `+ − 0` y flechas para
  recorrer la foto ampliada. La captura del puntero se pide sólo cuando el
  gesto ya es un arrastre: capturado desde el principio, el clic de un toque
  iría al visor y lo cerraría. Pasar de foto o cerrar restablece el zoom. Las
  fotos originales miden ~800 px: al 400 % se ven blandas.
- **El cursor de la casa** es invisible hasta el primer `mousemove`
  (`body.cursor-vivo`; antes nacía quieto en el centro de la ventana) y se
  oculta con `body.lightbox-abierto`: dentro del visor mandan los cursores del
  sistema (zoom-in, grab, grabbing).

`select.js` tampoco usa el `<select>` nativo visible. Excluye las vistas
operativas (`.mapa-page`, `.area-page`), pero un
select puede pedirlo dentro de ellas con `[data-enhance]`. El disparador es
`role="combobox"` (con `aria-activedescendant`, que en un botón no es válido) y
toma su nombre de la `<label>` o del `aria-label` del select. **Sólo mejora lo
que encuentra al cargar**: un select que se inserte después (una fila desde un
`<template>`, como los proveedores de un ingrediente) hay que mejorarlo con
`AdminSelect.init(fila)`, o sale la lista nativa del sistema. Viaja también en
`map.js`: tras tocarlo, recompilar `adminMapJs`.

Trampas conocidas:

- `.admin-card { overflow: hidden }` recorta cualquier dropdown `position:absolute`
  que viva dentro. O el contenedor hace `overflow: visible`, o el desplegable se
  porta al `<body>` con `position: fixed` (lo que hace `src/js/admin/core/select.js`).
  Al portarlo, revisar el `z-index` contra el del modal que lo contenga.
- Contrato de modal: alternar el atributo `[hidden]` **y** la clase `.is-open`.
- Patrón de scroll de la casa: `overflow-y:auto` + `overscroll-behavior:contain` +
  `scrollbar-width:thin` + los tres `::-webkit-scrollbar`.
- **Lenis se sirve sin hoja de estilos y la necesita.** Vendorizamos el `.js`
  pero no el `lenis.css` del paquete, así que sus reglas están copiadas a mano
  en los DOS ámbitos: `src/scss/layout/_reset.scss` (landing) y
  `src/scss/admin/shared/base/_globals.scss` (panel). Sin ellas la rueda del
  ratón deja de mover la página entera. Ya pasó dos veces; si aparece una
  tercera pantalla con Lenis, copiar el bloque.
- Ligado a lo anterior: `overflow-x: hidden` va en `html`, **nunca en `body`**.
  Sobre el body convierte al elemento en contenedor de scroll propio, que pelea
  con Lenis y anula cualquier `position: sticky` de dentro. Si hace falta
  recortar en horizontal, `overflow-x: clip` en `html`.
- La **barra de scroll del documento** de la landing (`_reset.scss`, tras el
  bloque `body`) es de la casa —pastilla de oro sobre carril crema con filete,
  verde al apuntarla— y va por los pseudoelementos `::-webkit-scrollbar`. Las
  propiedades estándar (`scrollbar-color`/`-width`) sólo donde no existen esos
  pseudoelementos (`@supports not selector(::-webkit-scrollbar)`): Chrome 121+
  entiende las dos vías y, en cuanto ve las estándar, ignora los
  pseudoelementos y pinta una barra plana. Se declara en `html` **y** en
  `body`, y no por duplicar: como
  el `overflow-x` lo declara el body mientras el html sigue en `visible`, el
  viewport hereda su overflow del body y los motores no coinciden en de cuál de
  los dos toman los estilos de la barra resultante. Lleva
  `scrollbar-gutter: stable` porque el lightbox, el anuncio y el aviso de
  privacidad bloquean con `overflow: hidden` y sin el carril reservado la página
  saltaba un canal al abrirlos.
- Lenis **cancela el `wheel`** salvo dentro de `[data-lenis-prevent]`. Todo
  contenedor con scroll propio necesita ese atributo o la rueda no lo mueve:
  hay que arrastrar la barra. En el panel lo aplica por selector
  `src/js/admin/core/motion.js` (`SCROLLABLES`) — agrégalo a esa lista; en la
  landing no hay esa capa y el atributo va escrito en el marcado (la pista de
  la galería, la rejilla de horas, el cuerpo del aviso de privacidad).
- **Y el error simétrico, que es el que más ha costado:** poner
  `data-lenis-prevent` a mano sobre algo que NO desborda en vertical. Un
  `.admin-table-wrap` es `overflow-x: auto` a secas, así que se queda la rueda
  sin tener nada que desplazar y la página se planta encima de la tabla. En el
  panel, **usa `data-scrollable` en el marcado y no `data-lenis-prevent`**:
  `data-scrollable` está en `SCROLLABLES`, así que `marcarScrollables()` mide en
  cada rueda y sólo marca lo que de verdad desborda —y retira la marca cuando
  deja de hacerlo—. `motion.js` respeta a propósito el `data-lenis-prevent`
  escrito en una vista (lo trata como decisión del autor y no lo toca), así que
  escribirlo a mano es renunciar a esa comprobación.
- Los parciales PHP incluidos varias veces por página (`views/components/`,
  `views/home/_insegna.php`, `views/home/_redes.php`) deben cerrar con `unset()`
  de sus parámetros: no se reinicializan entre includes y el segundo hereda lo
  que dejó el primero.
- La marca fija, el botón de menú y el cursor viven fuera de todo `[data-tono]`,
  así que no saben sobre qué fondo pasan. `tonoBajoLaMarca()`
  (`src/js/modules/scroll-art.js`) publica el tono de la banda que cruza la
  franja superior en `body[data-tono-actual]` y el CSS elige tinta clara u
  oscura — nada de sombras de rescate. Sólo mira los `[data-tono]` que son
  **hijos directos** de `<main>` (más el `<footer>`, más lo que quede envuelto
  por un `.pin-spacer` de ScrollTrigger): hay tonos anidados, como la lámina
  crema del mapa dentro de la sección verde, y ésos no son el fondo bajo la
  marca. Resuelve por **geometría** en cada scroll —qué banda cubre la línea de
  la marca— y no por flancos de `IntersectionObserver`: con flancos, dos bandas
  cruzando a la vez las decidía el orden del documento y el valor inicial se
  quedaba fijado antes de que asentaran las alturas. La llama `boot()`
  directamente, **fuera** de la rama de GSAP: el negativo tiene que funcionar
  aunque las libs de movimiento no lleguen.
  La excepción declarada es `[data-tono-franja]`: un bloque de fotografía a
  sangre dentro de una banda clara (el mosaico de Panadería) publica el tono que
  la marca debe usar mientras pasa por encima, y gana a la sección que lo
  contiene. Es lo mismo que hace `[data-tono="foto"]` con el contenido.

### Gráficas

Chart.js v4, vendorizado en `public/build/js/vendor/chart.umd.min.js`.
La paleta vive en los tokens `--admin-chart-*` y `--admin-n1-*` de
`_globals.scss`; `charts.js` y `nivel1.js` la leen con
`readToken()` y se re-renderizan con el evento `admin:themechange`. Los hex que
quedan en esos archivos son sólo respaldo por si la hoja aún no ha pintado.

Están **validados para daltonismo**: al cambiarlos, correr el validador del
skill `dataviz` y no bajar la separación ΔE. Por eso la serie **no** adopta los
colores de marca. Se declaran con valores literales y no con `color-mix`:
`getComputedStyle` devuelve la función sin resolver y Chart.js no sabe leerla.

Los colores categóricos solo se usan donde la identidad de la serie *es* el dato
(las donas); las gráficas de magnitud van a un solo tono.

**El Sankey de finanzas no es Chart.js**: lo dibuja `finanzas/sankey.js` en SVG
a mano, porque `chartjs-chart-sankey` pintaba las etiquetas dentro del canvas
sin medirlas y las de la última columna se cortaban. Tres cosas que hay que
saber antes de tocarle la geometría:

- **La última columna escribe hacia DENTRO** (`alaIzquierda`), que era el arreglo
  por el que se abandonó el plugin. Así que a la derecha sólo hace falta el
  respiro del canto: reservar ahí el ancho de la etiqueta más larga —como se
  hacía— dejaba unos 200 px muertos y el diagrama encogido contra el borde
  izquierdo. Lo que sí hay que comprobar es el **paso entre las dos últimas
  columnas**, para que la etiqueta no invada el nodo de detrás.
- El SVG sale a `width: 100%`, y eso significa que un `viewBox` más ancho que la
  caja **no produce scroll**: el `preserveAspectRatio ... meet` reescala el
  dibujo entero para que quepa y lo centra, dejando dos bandas vacías arriba y
  abajo. Cuando el lienzo tiene que crecer, se le pone el ancho en **píxeles** y
  el scroll horizontal del contenedor hace su trabajo.
- ⚠️ El contenedor declara **los dos ejes** de `overflow`, y no es redundancia:
  por especificación, cuando uno de los dos no es `visible` el otro computa a
  `auto`. Tenía sólo `overflow-x: auto`, así que pedir scroll horizontal
  encendía también el vertical, y el `svg.style.overflow = 'visible'` le daba
  algo que desplazar — una barra vertical sobre un diagrama que cabe entero.

**Finanzas ya no carga Chart.js** (la dona de gastos fijos, 208 KB para un
anillo, es ahora una barra en grises). Su cabecera es un **diagnóstico** armado
por `Services\Finance\DiagnosticoFinanciero`, que recibe agregados y devuelve
textos en «partes» (`t`/`fuerte`/`href`) que la vista escapa: ningún número
del diagnóstico se formatea en la vista, y `DiagnosticoFinanciero::dinero()` es
el formato del módulo (`−$1,234.56`, signo delante y menos tipográfico). Tres
alturas: veredicto en una frase con su causa y el punto de equilibrio; el
estado de resultados en **cascada** (radios `name="fin-paso"`: el detalle lo
decide `:has()`, con respaldo `.is-activo` de `finanzas.js`; comparar con el
periodo anterior es el checkbox `[data-fin-comparar]`, que sólo aparece si ese
periodo tuvo ventas); y **signos vitales** contra referencias de la industria
que viven en las constantes del servicio con su fuente (si cambian, cambia
también el pie de la sección). El Sankey habla el código de color de la
cascada —tinta para el dinero, gris para el consumo, verde o rojo sólo en el
desenlace—, lee `--admin-text`, `--admin-faint`, `--admin-chart-ventas` y
`--admin-chart-bajo`, y `AdminSankey.render()` devuelve `{resaltar(lista|null)}`
y acepta `alFijar` y `opacidadCinta` (elegir un escalón de la cascada aísla su
cadena en el Sankey). Prueba: `php scripts/tests/run-diagnostico-financiero.php`.

Y como el ancho lo mide en runtime (`clientWidth`), lleva un `ResizeObserver`
además del `resize` con debounce: **plegar el sidebar cambia el ancho sin que la
ventana cambie de tamaño**, y sin el observer el diagrama se quedaba dibujado
contra la medida vieja hasta la siguiente recarga.

### Alertas de impresión

Cada comanda (por área) o cuenta que no llega a su impresora queda en
`impresion_alertas` y la ven **todas** las tablets del POS hasta que alguien
la marca como atendida. Sólo con el servicio encendido
(`configuracion_pos.impresion_activa`): apagado, `TicketPrinter` corta antes de
intentar nada y `ImpresionAlertaService::pendientes()` devuelve vacío aunque
queden filas viejas.

- **Quién detecta**: `TicketPrinter` recoge los fallos de la petición
  (`fallos()`) con tres motivos: `sin_impresora` (no hay una activa para ese
  destino), `sin_conexion` (no respondió: el conector de red abre el socket en
  el constructor) y `fallo_envio` (conectó, pero no terminó). **Quién guarda**:
  el controlador, porque es quien sabe de qué mesa y de qué mesero era.
- **Cómo llegan**: en la respuesta de `enviar-comanda` / `cerrar-ticket` (quien
  la causó se entera al instante) y en `alertas_impresion` del refresco del
  mapa (el resto, en ≤30 s). Lo que ya estaba al cargar la pantalla no se
  anuncia: sólo sube el contador del botón ámbar del header.
- No hay chequeo periódico de impresoras, a propósito: cada impresora caída
  costaría hasta 2 s de espera (`TIMEOUT_CONEXION`) por chequeo.
- El `detalle` técnico (IPs, rutas SMB) sólo viaja a un administrador.

### El corte de caja

`GET /api/corte-caja` (inline en `PuntoVentaController::corteCaja`, sin servicio)
y `renderCajaModal()` en `punto-de-venta.js`. Es **sólo lectura**: no persiste
ningún arqueo.

Las cuatro consultas filtran por tickets **cerrados** del día
(`DATE(COALESCE(hora_cierre, hora_apertura)) = CURDATE()`), y el ranking de
«Más pedidos del día» —cinco puestos, por unidades— usa el mismo criterio a
propósito: si contara también las mesas abiertas hablaría de un universo
distinto al de «Ventas del día», que tiene justo encima en el mismo modal.

`AdminFinanzasController::cortes()` **duplica** dos de esas consultas para el
histórico por día del panel, y no incluye ni el top ni las áreas. Si alguna vez
hay que tocar la definición del corte, ése es el momento de sacar un
`services/CorteCajaService.php` en vez de editar los dos sitios.

## Notas de trabajo

- Escribe en español: comentarios, mensajes de UI, mensajes de commit.
- Los comentarios explican **por qué**, no qué hace la línea.
- `.claude/` está en `.gitignore`; `docs/` sí se versiona.
- Pruebas de variantes contra la base de desarrollo, en una transacción que se
  revierte: `php scripts/tests/run-variantes-db.php` (necesita el bloque 4).
- Pruebas: `npm run test:php`, `npm run test:js` y, contra una base aislada
  que se crea y se borra sola, `php scripts/tests/run-notifications-isolated.php
  [suite]` (sin argumento corre su lista). `run-privacidad-contract.php` lee los
  bundles construidos: compilar antes de correrlo.
