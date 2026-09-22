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
- **Autenticación**: `classes/Auth.php`. `Auth::proteger()` corre en `public/index.php`
  **antes** del ruteo y cierra `/admin/*` a `rol === 'admin'` **por prefijo**: una
  ruta nueva bajo `/admin/` queda protegida sola, incluidas las `/admin/api/*`.
  Las APIs que viven fuera de `/admin/` se abren a meseros o cocineros por
  allowlist (`APIS_POS`, `APIS_AREA`, `APIS_PISO`, `APIS_ADMIN`): **una ruta
  `/api/...` que no esté en ninguna lista queda pública**.

### Rutas y convenciones

- Páginas: renderizan HTML. Mutaciones de admin: POST-redirect-GET.
- JSON: prefijo `/api/...` o `/admin/api/...`, responden `echo json_encode(...)`.
- Los controladores de POS/áreas comparten helpers (`entradaJson()`, `responder()`).

### Build

```
npx gulp            # todo
npx gulp js         # solo JS
npx gulp css        # solo SCSS
```

Mapa de salidas (`gulpfile.js`) — **editar `src/`, nunca `public/build/`**:

| Fuente | Salida | Quién la carga |
|---|---|---|
| `src/scss/app.scss` | `public/build/css/app.css` + `assets/css/` | landing y **feedback** |
| `src/scss/admin/shared/app-admin.scss` | `public/build/css/admin.css` | panel y operación de reservaciones |
| `src/scss/admin/modules/*.scss` | `public/build/css/admin/*.css` | cada módulo del panel |
| `src/scss/operation/app-operation.scss` | `public/build/css/operation.css` | **POS, áreas y login** |
| `src/scss/operation/reservations.scss` | `public/build/css/operation/reservations.css` | operación, junto a `admin.css` |
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

Tres bundles administrativos y no uno: `admin.css` (panel), `operation.css`
(piso) y `operation/reservations.css` (parcial que se carga **junto a**
`admin.css`, por eso sus tokens no están declarados dentro). Los tres salen de
`admin/shared/`, así que el watch de esa carpeta regenera `admin.css` **y**
`operation.css`.

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

**No hay migraciones.** `database/` son esos tres archivos y nada más:
`database/migrations/` se retiró junto con los dos últimos parches que
quedaban, ya recogidos en el DDL (`catas.disponible`, la baja de
`cata_inscripciones` y `catering_solicitudes`) y en el deploy (los colores de
`areas_produccion` en la paleta funcional). Un cambio de esquema se escribe en
`ddl.sql` y el entorno se rehace: es lo mismo que ya pedía el punto de arriba
—los ids explícitos del bloque 1 no soportan una segunda pasada— y mantener a
la vez un DDL completo y una cadena de parches sólo servía para que las dos
versiones del esquema se separaran sin que nadie se enterara.

Consecuencia asumida: una instalación con datos que haya que conservar no tiene
camino de actualización escrito; hay que redactar el `ALTER` a mano contra el
diff del DDL. Para producción real, ese es el momento de reintroducir
migraciones como decisión explícita, no de improvisar un directorio.

El DDL empieza con los `DROP TABLE` en orden inverso de dependencias justo por
esto: si agregas una tabla, agrega también su `DROP` en el lugar que le toca.

Cosas que cargan peso y no son obvias:

- `productos` es la **única** fuente de platillos. La tabla `menu` es legado de
  solo lectura; no escribir ahí.
- `productos.nombre` es UNIQUE por dependencia funcional: el descuento de
  inventario, el COGS y el motor de sugerencias unen por nombre, no por id.
- `categorias.carta` (`comida` | `maridaje`) parte el catálogo en las **dos
  piezas impresas**: la carta de comida y la de maridaje (barra). Vive en la
  categoría y no en el producto porque la carta es una decisión de SECCIÓN —
  «Cocktails» entero es maridaje—, y se administra desde el **mismo módulo de
  Menú**: pastillas en el alta rápida del tab «+» y en la edición de la
  categoría, más una columna en el listado. Tres consecuencias:
  `Carta::publica($carta)` filtra por ella y **por defecto devuelve comida**,
  así que la sección de menú de la landing y `/menu/pdf` siguen siendo lo que
  eran; `/maridaje/pdf` (admin: `/admin/menu/pdf/maridaje`) es la otra pieza,
  y sale de la MISMA plantilla —`items-pdf.php`, que recibe rótulo, bajada,
  título y aviso de vacío y no sabe cuál imprime—; y `Carta::paraPos()` **no**
  la mira, porque el mesero cobra la copa y el plato en el mismo ticket.
  ⚠️ El interruptor de visibilidad del listado de categorías reenvía la fila
  por campos ocultos: si añades una columna a `categorias`, tiene que viajar
  ahí también o apagar una categoría la devuelve a su valor por defecto.
- `ticket_items.estado`: `enviado → en_preparacion → listo → entregado`, más
  `cancelado`. Producción avanza hasta `listo`; `entregado` lo marca el mesero.
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
- `usuarios` tiene dos vías de acceso: `password_hash` (admins, usuario+contraseña)
  y `nip_hash` + `nip_lookup` (personal de piso, NIP de **4 dígitos**). El lookup
  es un HMAC con `NIP_LOOKUP_SECRET` para resolver la fila directamente;
  `password_verify` sólo confirma la credencial encontrada. La generación,
  rotación y migración están documentadas en `docs/usuarios/usuarios.md`.

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

**La base es el manual de marca: verde, café, beige y crema.** Son los cuatro
únicos hex de marca del proyecto y viven en la *capa 1* de `_reset.scss`:

| Token | Hex |
|---|---|
| `--brand-verde` | `#225036` |
| `--brand-cafe` | `#4a2f21` |
| `--brand-beige` | `#e3d5bb` |
| `--brand-crema` | `#F5F1E8` |

El dorado (`--brand-oro`, `#D2AB67`) sigue existiendo pero **sólo lo consumen
las pantallas de piso** (`[data-modo="oscuro"]`: POS, áreas, login, feedback).
Salió de la landing porque sobre crema no contrasta con nada y dejaba planas
todas las secciones claras; ahí el acento es el café. `--brand-vino` ya no
existe: `[data-tono="vino"]` se conserva como alias que resuelve a café.

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
Al añadir una regla con color o tipografía a uno de esos componentes, el token
nuevo se declara en los DOS mixins; en uno solo, el otro lado vuelve a romperse
sin ruido.

Ojo a `-danger` frente a `-danger-text`: el primero es el rojo **pleno** de un
relleno y el segundo el rojo **legible como texto** sobre el fondo del modo
activo. Usar el segundo como relleno es lo que deja un botón destructivo
apagado.

**El color de la paleta funcional casi nunca toca el cromo del panel** — es de
estado, badges, series de gráfica y `--admin-estado-*`. Es la regla que hace que
el conjunto se lea como un sistema y no como un arcoíris: si los bordes y los
rellenos usaran color, un badge ámbar dejaría de significar «advertencia».

La excepción, y es una lista cerrada: **los disparadores de acción**. Los tres
botones del topbar (reportar, notificaciones, salir) y los secundarios de una
barra de acciones de módulo («Categorías» y «Generar PDF» en Menú, «Subrecetas»
e «Inventario» en Recetas) llevan tono. Eran cajas idénticas donde sólo cambiaba
la palabra y había que leerlas una por una para elegir. Tres condiciones:

- El color es un **tinte derivado** (`color-mix` sobre `--admin-surface`, 11-12%
  en reposo y 20-22% en hover). El relleno sólido queda para
  `.admin-btn--primary`, el conmutador de tema y **los dos avisos del topbar**,
  que es lo que mantiene una sola acción principal por pantalla.
- No toca bordes de tarjeta, filas de tabla ni tipografía.
- Se consume por la clase `.admin-btn--tinted` más un `--tinted-{color}`
  (`_buttons.scss`), o por el patrón `--tono` del topbar (`_topbar.scss`).

**Los dos avisos del topbar sí van en relleno sólido**, y son la única
ampliación de esa lista: notificaciones en `--admin-c-azul` y reportar un
problema en `--admin-c-ambar`, con la tinta encima declarada por botón
(`--tono-tinta`: papel sobre el azul, `--admin-ink` sobre el ámbar, que tiene
luminancia 0.52 y no admite texto claro). El tinte al 12% no resolvía lo que
tenía que resolver: sobre el fondo casi negro del tema oscuro es
indistinguible de gris, y los dos círculos volvían a leerse como «dos botones
iguales». **Salir se queda en tinte a propósito** — terminar la sesión no debe
ser lo más ruidoso del panel—, y por eso son dos y no cuatro.

Dos trampas de esa zona, las dos por cascada:

- Las reglas de caja de `.admin-topbar__support`, `.admin-topbar__logout`
  (`_topbar.scss`) y `.admin-topbar__inbox` (`_buzon.scss`) van **después** del
  bloque de `--tono` y tienen su misma especificidad. No pueden declarar
  `background`, `color` ni el atajo `border` —que reescribe `border-color` a
  `currentcolor`—: sólo `border-width` + `border-style`. Es lo que dejaba el
  botón de reportar en gris mientras el bloque de color parecía aplicarse.
- El contador del buzón (`.admin-topbar__inbox-badge`) vive **encima** de un
  relleno sólido, así que va invertido —papel con la cifra en tinta— y su aro
  toma `--tono`: un aro del color de la página se perdería contra el fondo del
  tema oscuro, y como el aro es `--tono`, la variante de prioridad alta lo
  cambia sola. Los estados del buzón (`is-empty` / `has-items` /
  `has-followup`) ya no tocan el relleno: lo que dice «hay algo» es el
  contador, y `has-high-priority` es lo único que cambia de familia entera.

Lo que **no** es excepción, porque la regla siempre lo permitió: el badge de
categoría de Menú (`.admin-badge--cat-0…9`, derivado de `categoria_id % 10`) y
los tonos de serie de las tarjetas KPI (`.admin-stat-card--serie-*`). Ahí la
identidad del dato *es* el color, como en las series de una dona. En las
tarjetas, además, el tono de serie es un **filete lateral y el rótulo**, nunca
el fondo ni la cifra: la cifra va en tinta normal para poder compararse en
vertical, y el fondo queda libre para el tono de ESTADO (`--good`, `--bad`),
que gana porque «la utilidad es negativa» importa más que «esto es la utilidad».

**Ningún archivo fuera de esos dos contiene un hex ni un `rgba()` literal.** Ni
el SCSS, ni el JS, ni las vistas. Cambiar la marca es editar la capa 1 y nada
más; si hace falta escribir un color en otro sitio, es que falta un rol.
Comprobación: `grep -rE '#[0-9a-fA-F]{3,8}|rgba\([0-9]' src/scss src/js` sólo
debe encontrar `_reset.scss` y `_globals.scss`.

#### Las tres capas

1. **MARCA** — los cuatro colores de arriba.
2. **ESCALAS** — variantes derivadas con `color-mix` (`--verde-deep`,
   `--oro-soft`, `--crema-lift`…). Nunca un hex nuevo.
3. **ROLES** — lo que el CSS consume: `--bg`, `--bg-alt`, `--surface`,
   `--surface-2`, `--txt`, `--txt-strong`, `--txt-mute`, `--txt-faint`,
   `--txt-inverse`, `--line`, `--line-soft`, `--line-strong`, `--accent`,
   `--accent-text`, `--on-accent`, `--focus`, `--scrim`, `--sombra`,
   `--vineta`.

Distinciones que importan:

- `--accent` es el acento de marca para **rellenos, bordes y adornos** (café en
  la landing, oro en las pantallas de piso); `--accent-text` es su versión
  **legible como texto** sobre el fondo del modo activo. `--on-accent` es la
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
`--beige: var(--txt-strong)` sólo en `:root` computa allí a café profundo y
hereda ese valor ya resuelto — redeclarar `--txt-strong` en una sección no lo
cambia. Al añadir un tono nuevo hay que incluir el mixin o todo lo que consuma
alias dentro saldrá con la tinta de `:root`.

#### Modos y tonos

La capa 3 se redeclara por ámbito; nunca se duplican reglas por tema:

- **Landing en claro** (por defecto): crema de fondo, verde de texto, vino como
  acento profundo, dorado en los CTA.
- `[data-modo="oscuro"]` — ya **no lo usa ninguna pantalla**. POS, áreas y login
  se fueron al sistema administrativo (`operation.css`, oscuro forzado con
  `[data-admin-theme="dark"]` y sin conmutador: son turnos completos en tablet).
  El feedback se fue al otro lado: es la ÚNICA pantalla de tablet que pertenece
  a la marca, porque la abre un comensal al terminar de comer, así que va en
  `[data-tono="crema"]` con Bodoni y el café de acento como la landing. El
  bloque se conserva por si vuelve a hacer falta un modo oscuro público.
- `[data-tono="crema|vino|verde|foto"]` — ritmo de secciones de la landing.
  Cada `<section>` lo declara y **todo lo que vive dentro se readapta solo**.
  `foto` es para el contenido que descansa sobre una imagen con velo (el hero):
  no cambia el fondo, sólo fuerza el texto en claro.
- `[data-admin-theme="light|dark"]` en el panel, sin cambios de estructura.

#### Las dos transiciones del panel

Las dos usan View Transitions y **conviven en `_finishes.scss`, bloques 4b y
4c**. Si tocas una, comprueba la otra: es el error fácil de esta zona.

- **Cambio de tema** (`core/theme.js`): un `clip-path` circular de 480 ms que se
  abre desde el conmutador. Va marcada con el tipo `'tema'`
  (`startViewTransition({update, types})`, con repliegue a la firma de callback
  para Chrome 111-124), y las reglas que apagan el fundido de raíz se acotan a
  ese tipo. Sin acotarlas —como estaban— matarían también la de navegación.
- **Cambio de módulo**: `@view-transition { navigation: auto }` más un keyframe
  de entrada en `.admin-content` para los navegadores sin soporte. **Ningún
  elemento lleva `view-transition-name`**, y no es un olvido: nombrar el topbar
  o el sidebar les daría grupo propio en TODAS las transiciones del documento
  —la del tema incluida— y sus instantáneas taparían el círculo mientras se
  abre. Sin nombres, la raíz funde la página entera y el cromo, que es idéntico
  entre módulos, funde píxeles iguales: no se nota.

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
serlo: los cuatro tipos van en el café de marca. Cada uno llevaba un color de la
paleta funcional y sobre la portada el rótulo cantaba — un anuncio no es una
alerta, y lo que distingue a un tipo de otro es su icono y su etiqueta. En el
mismo archivo, los cuatro tipos son ya `PRESENTACION_DISCRETA`: **ningún aviso
bloquea la landing**. `PRESENTACION_MODAL` sigue declarada, sin consumidores, por
si algún día vuelve como decisión explícita.

### Tipografía del panel

**Geist + Geist Mono, y ninguna serif.** No comparte una sola cara con la
landing: el panel es otro producto. Locales igual que las de la landing
(`gulp copyVendorFonts` desde `@fontsource-variable/geist*`), cero Google Fonts.

| Token | Familia | Para qué |
|---|---|---|
| `--admin-font-sans` | Geist Variable (100-900) | todo el texto |
| `--admin-font-mono` | Geist Mono Variable | **cifras**, rótulos, código |
| `--admin-logo` | KudosKaps | sólo el wordmark |

**La jerarquía la hace el par mono/sans, no un cambio de familia**: el rótulo va
en mono, versalitas y `letter-spacing: .14em`, y el titular debajo en sans con el
tracking cerrado (`-0.03em`). Es lo contrario de lo que pide Bodoni en la
landing —un didone se empasta al apretarlo— y por eso las dos escalas no se
pueden copiar la una de la otra.

Toda cifra que se compare en vertical va en mono con `tabular-nums`: columnas
numéricas de tabla, KPIs, importes, timestamps. Se marcan con `.admin-num`. Una
tabla entera en mono, en cambio, se lee como un volcado de terminal — el texto
se queda en sans.

Se envían las **cuatro** caras (redonda e itálica de cada familia) aunque el
fichero sea variable: `font-style` no es un eje, y hay una regla que pide
itálica. La casa no admite caras sintéticas.

`--admin-font-body`, `--admin-font-heading`, `--admin-font-accent` y
`--admin-font-display` se conservan como alias de `--admin-font-sans`.
`--admin-font-display` es el caso raro: **nadie lo definió nunca** y se consumía
con un fallback `"Fraunces", serif` que era lo que de verdad se pintaba.

### Tipografía

Todas las caras son **locales** (`src/scss/layout/_fonts.scss` → `assets/fonts/`,
que `copyFonts` propaga a `public/build/fonts/`). Cero Google Fonts en la
landing. Se consumen sólo por token, nunca nombrando la familia:

| Token | Familia | Para qué |
|---|---|---|
| `--serif` | **Bodoni Moda** (variable, `wght` + `opsz`) | `h1`-`h4`, `.display`, **y sus cursivas de acento** |
| `--crimson` | Crimson Text | cursivas de acento **fuera de headings**: `.lead`, `.hero__firma`, `.insegna__es`, notas al pie de sección |
| `--sans` | Montserrat | cuerpo, versalitas, botones, **rótulos de sección** |
| `--logo` | KudosKaps | `.brand-mark`, `.hero__title` y `.foot__brand .bm` |

⚠️ **KudosKaps tiene UNA sola cara, de peso 400.** Cualquier elemento que la
consuma tiene que declarar `font-weight: 400` explícitamente. Si no, hereda el
`600` de `h1..h4` —que gana por incomparecencia cuando la clase no declara
peso— y el navegador fabrica una negrita falsa dilatando los trazos: sobre una
display de alto contraste eso empasta los remates y el wordmark deja de
reconocerse. Le pasó al `h1` del hero y le pasa al PDF del menú
(`views/admin/menu/items-pdf.php`). Va con `font-display: block` y precargada
desde `views/home/index.php`: en un wordmark el salto de cara se ve más que la
espera, y el respaldo de `--logo` es precisamente Bodoni.

**Un heading es una sola voz.** Cuando un `h2` mezcla redonda y cursiva
("Catering que *marca la diferencia*") las dos van en Bodoni y se separan por
PESO, no por familia: 600 la redonda y 400 itálica la cursiva (`h1..h4 em`,
`h1..h4 .accent-italic`, `.display em`). Antes la segunda saltaba a Crimson y el
título se leía como dos títulos pegados. `.accent-italic` fuera de un heading sí
sigue en Crimson. Los títulos de sección van en **una sola línea**: ya no llevan
`<br>` y rompen por ancho de caja — `partirEnLineas()` lo contempla y anima el
título en bloque en vez de escalonado.

**Los rótulos de sección son italianos.** El `.eyebrow` numerado dice sólo la
voz italiana ("01 — La nostra storia"), en las versalitas sans de `.eyebrow`. La
coletilla `.eyebrow__it` en Crimson cursiva salió de la landing; la regla se
conserva porque la consume el marcado del panel.

Bodoni es la voz italiana de la casa: el didone de Parma. Sustituyó a Playfair
Display, que queda detrás como respaldo. Dos cosas que no son opcionales:

- **Ninguna cara sintética.** Bodoni trae itálica real y Crimson también; las
  dos las siembra `gulp copyVendorFonts` desde `node_modules` (paquetes
  `@fontsource*`, versión fijada en `package.json`). Antes se pedía
  `font-style: italic` a una Crimson que sólo tenía redonda, y el navegador la
  inclinaba por su cuenta — justo en el gesto donde la página dice "italiano".
  Si hace falta un peso o un estilo nuevo, se añade el `.woff2`, no se deja que
  el navegador lo invente. Los pesos de heading (400–900) sí son reales: el
  fichero de Bodoni es variable y declara `font-weight: 400 900`.
- **El tracking de Bodoni no es el de Playfair.** Un didone tiene astas
  finísimas: apretado se empasta. De ahí que `h1..h4` vayan a `letter-spacing: 0`
  y con `font-optical-sizing: auto`, que es lo que ajusta el grosor de los
  remates entre cuerpo de texto y cuerpo de cartel.

### Componentes

No hay `components/ui`. El equivalente es SCSS compartido más partials PHP:

- `src/scss/admin/shared/components/` — `_forms`, `_cards`, `_tables`, `_buttons`,
  `_badges`, `_modal`, `_select`, `_reservation-picker`.
- `views/components/reservations/date-picker.php` y `time-picker.php` —
  parametrizados por variables locales antes del `include`.
- `views/admin/partials/` y `views/operation/partials/`.

**El shell operativo** (`views/operation/partials/shell.php` + `header.php`) lo
comparten las **cuatro** pantallas de piso: mapa de mesas, operación de
reservaciones, tablero de área (KDS) y selector de estación. Cada consumidor
sólo entrega sus slots (`$operationalContentHtml`) y sus banderas; el header no
cambia de geometría entre módulos. Las que hay que conocer:
`$operationalView` (`'map'` saca el título centrado), `$operationalUserMenu`
(en falso: chip informativo + salida de un toque, que es lo que quiere una
tablet), `$operationalShowLastUpdate`, `$operationalHeaderDrawerToggle` (en
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
  Reservaciones no lo usa y tiene el suyo, `[hoy, hoy+30]`, porque es el único
  módulo que mira hacia delante. Si un tablero sale vacío con datos en la base,
  ése es el primer sitio donde mirar.

**El listado de reservaciones son TARJETAS, no una tabla**, y es el único
módulo del panel que no usa `.admin-table`. Tenía seis columnas con
`min-width: 960px`, así que en cualquier portátil el estado y las acciones
—lo único accionable de la fila— quedaban fuera de vista tras un scroll
horizontal: había que arrastrar para saber si una reserva estaba confirmada. El
scroll no era un descuido de padding, era estructural.

Una reservación tampoco es una fila de datos comparables columna a columna: es
una ficha que se lee entera —quién viene, cuándo, con cuántos, en qué mesa— y
sobre la que se actúa. En tarjeta el orden de lectura es la pregunta real del
anfitrión, y nada se sale de la caja. Se conservan la agrupación por día (con
cabecera `sticky`) y el orden cronológico; al no haber tabla, **`table-sort.js`
no aplica aquí**.

El filete izquierdo de la tarjeta es el ESTADO, y los dos estados terminales
—cancelada y no-show— van en la línea neutra atenuada, no en rojo: una reserva
cancelada no es una alerta que atender. Lo que sí gana al estado es
`is-late`: la tolerancia vencida es lo único de esa pantalla que le dice al
anfitrión que tiene algo que resolver ahora.

⚠️ `.reservations-table__status--*` **sobrevive** al cambio y no es CSS muerto:
lo consumen el detalle (`show.php`), la operación de reservaciones
(`admin/reservations/operation.js`) y la tarjeta del cajón operativo
(`operation/reservation-card.js`). El listado ya no; usa `.admin-badge`, que es
el vocabulario del panel. Lo que sí se retiró de ese badge es el
`max-width: 104px`: con `nowrap` no recortaba con puntos suspensivos sino a
hueso, y "Pendiente de verificación" salía cortado a media palabra.

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
  Lo usan Menú (10/página, reactivo) y Tickets (20/página, enlaces normales).
  **Al paginar, las métricas de cabecera necesitan consulta propia**: si se
  calculan recorriendo las filas traídas pasan a describir la página, no el
  total. Es lo que le pasaba a Tickets.

En la landing, `.btn-line` es el único vocabulario de CTA: `--solid` para el
principal de una sección (relleno de acento con `--on-accent` encima),
`--secondary` para el secundario y `--pdf` para el fantasma que acompaña a un
principal sin competir con él. El texto del botón base va en `--txt-strong`,
nunca en un alias: es lo que lo mantiene legible al cruzar de tono.

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
El `view-transition-name` del morfo se pone justo antes y se retira al terminar
—dos elementos no pueden compartirlo a la vez— y mientras corre no se lanza el
tween de GSAP, que produciría dos aperturas superpuestas.

`select.js` tampoco usa el `<select>` nativo visible. Excluye las vistas
operativas (`.mapa-page`, `.area-page`, `.admin-reservation-operation`), pero un
select puede pedirlo dentro de ellas con `[data-enhance]`.

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
  bloque `body`) se declara en `html` **y** en `body`, y no por duplicar: como
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
`_globals.scss`; `charts.js`, `nivel1.js` y `finanzas.js` la leen con
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

Y como el ancho lo mide en runtime (`clientWidth`), lleva un `ResizeObserver`
además del `resize` con debounce: **plegar el sidebar cambia el ancho sin que la
ventana cambie de tamaño**, y sin el observer el diagrama se quedaba dibujado
contra la medida vieja hasta la siguiente recarga.

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
- `.claude/` y `docs/` están en `.gitignore`.
