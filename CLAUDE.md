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
  restaurante (`ReservacionConfig::whatsappUrl($mensaje)`); catering, al de
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

`Services\AnuncioConfig::TIPOS[*]['acento']` era la otra excepción y dejó de
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

El KDS **no tiene diseño propio**: sus columnas son tarjetas del sistema
(`--admin-surface` + `--admin-border` + `--admin-radius-md`), su tipografía es
la de `--operational-*` y sus botones de avance van en relleno sólido de
`--admin-estado-preparacion` / `--admin-estado-listo`, que son los mismos
tokens con los que el POS pinta esas comandas. `areas_produccion.color` llega
por `--area-accent` desde la vista y sólo tiñe la franja superior de las tres
columnas: dentro, el color está reservado al estado del platillo. El filete
izquierdo de una comanda es su ANTIGÜEDAD (neutro → ámbar → rojo), no la
estación, que dentro de una columna ya se sabe.

`[data-confirm-logout]` lo emite el header, pero el manejador vive en el JS de
cada pantalla: `punto-de-venta.js` para el POS y `modules/area.js` para las dos
de área. Una pantalla nueva que use el header y no ate el suyo cierra sesión al
primer toque, sin preguntar.

**El selector de periodo** (`views/admin/partials/_range-picker.php` +
`core/range-picker.js` + `Services\RangoPeriodo`) lo comparten analíticas,
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

Vocabulario de clases admin: `admin-page`, `admin-card`, `admin-panel`,
`admin-btn--{primary,secondary,ghost,tinted}`, `admin-table`,
`admin-badge--{success,warning,danger,neutral,info}` (combinables con
`--outline`, que vacía el fondo y deja color en tinta y filete),
`admin-badge--cat-0…9`, `admin-pagination`, `admin-field` +
`__label/__hint/__error`, `admin-switch`, `admin-tabs__tab`, `admin-modal`.

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

Tres cosas que no se deducen del archivo:

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
sólo lo que termina algo; tinte al 24 % con borde sólido, y hover a relleno
macizo), `--release` (naranja: liberar una mesa por ausencia no destruye la
cuenta, avisa), `--pending` (neutro, acompaña a `--primary` cuando aún no se
puede pulsar), `--ghost`, `--secondary`, `--outline`. `--release` y `--pending`
llegaron a existir sólo en el JS: sin regla en el SCSS salían transparentes,
texto suelto donde debía haber un botón. Al añadir una variante, comprobar que
ningún bloque posterior del mismo archivo —o de `_pago-modal.scss`, que carga
después— repite el selector: ahí ya se perdieron dos veces el color del
destructivo y el estado deshabilitado.

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
  `GET /admin/api/inventario/uso` (`Services\Inventario::recetasQueUsan()`, la
  única consulta INVERSA del módulo: todo lo demás va producto → ingredientes).
- `requireText` — deja el botón principal deshabilitado hasta que se teclea ese
  texto. Compara sin acentos ni mayúsculas: se busca que el usuario LEA lo que
  borra, no que reproduzca la ortografía.

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

## Notas de trabajo

- Escribe en español: comentarios, mensajes de UI, mensajes de commit.
- Los comentarios explican **por qué**, no qué hace la línea.
- `.claude/` y `docs/` están en `.gitignore`.
