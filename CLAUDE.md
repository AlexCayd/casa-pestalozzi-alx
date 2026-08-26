# Casa Pestalozzi

Sistema de gestión para el restaurante: landing público con carta y reservaciones,
punto de venta para meseros, tableros de producción por área, y panel de
administración (menú, inventario, recetas, analíticas, catas, catering, usuarios,
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

| Fuente | Salida |
|---|---|
| `src/scss/app.scss` | `public/build/css/app.css` + `assets/css/` |
| `src/scss/admin/shared/app-admin.scss` | `public/build/css/admin.css` |
| `src/scss/admin/modules/*.scss` | `public/build/css/admin/*.css` |
| `paths.adminMapJs` (POS: `punto-de-venta.js` + shell/mapa + `core/select.js`) | `public/build/js/admin/map.js` |
| `src/js/admin/area/area.js` | `public/build/js/admin/area.js` |
| resto de `src/js/` | `public/build/js/bundle.min.js` |

`src/js/modules/punto-de-venta.js` está **excluido** del bundle general a propósito;
llega al POS dentro de `map.js`, que empaqueta varios archivos más (ver
`paths.adminMapJs`). El panel admin carga **solo** `admin.js`, nunca
`bundle.min.js`: lo que deba estar disponible en ambos va en las dos listas.

> `npm test` está roto: apunta a `scripts/` y `tests/`, que no existen en el repo.
> Verificar con `php -l` y recorridos manuales.

## Base de datos

`database/ddl.sql` (estructura, DROP+CREATE completo),
`database/dml_operativo.sql` (datos mínimos de operación) y
`database/dml_pruebas.sql` (datos ficticios para desarrollo y QA).
Credenciales de demo en `docs/usuarios/credenciales.md`.

El esquema nuevo se escribe en `ddl.sql`; las instalaciones existentes usan la
migración explícita de acceso en `database/migrations/` y la rutina controlada de
rotación de credenciales. `dml_pruebas.sql` es opcional y sólo se carga en
entornos de desarrollo o QA. El DDL empieza con los
`DROP TABLE` en orden inverso de dependencias justo para eso: si agregas una tabla,
agrega también su `DROP` en el lugar que le toca.

Cosas que cargan peso y no son obvias:

- `productos` es la **única** fuente de platillos. La tabla `menu` es legado de
  solo lectura; no escribir ahí.
- `productos.nombre` es UNIQUE por dependencia funcional: el descuento de
  inventario, el COGS y el motor de sugerencias unen por nombre, no por id.
- `ticket_items.estado`: `enviado → en_preparacion → listo → entregado`, más
  `cancelado`. Producción avanza hasta `listo`; `entregado` lo marca el mesero.
- `ticket_mesas` es la fuente canónica de ocupación física, no `tickets`.
- `catas.estado`: la landing sólo publica `publicada` y `agotada`. El paso a
  `agotada` lo hace solo `CataService::sincronizarCupo()`, y va en las dos
  direcciones: cancelar una inscripción reabre la cata. Es un estado y no un
  cálculo porque el admin puede cerrar inscripciones antes de tiempo.
- El cupo de una cata se cuenta en **personas**, no en inscripciones: una
  inscripción de cuatro ocupa cuatro lugares. Sólo suman los estados de
  `CataInscripcion::ESTADOS_QUE_OCUPAN`.
- Los dos formularios públicos de catas y catering son anónimos: no pasan por
  OTP como reservaciones. El abuso lo frenan el token CSRF de la sesión pública,
  un campo trampa y un tope de envíos por contacto que **suma las dos tablas**
  (quien inunda una suele probar la otra).
- `usuarios` tiene dos vías de acceso: `password_hash` (admins, usuario+contraseña)
  y `nip_hash` + `nip_lookup` (personal de piso, NIP de **4 dígitos**). El lookup
  es un HMAC con `NIP_LOOKUP_SECRET` para resolver la fila directamente;
  `password_verify` sólo confirma la credencial encontrada. La generación,
  rotación y migración están documentadas en `docs/usuarios/usuarios.md`.

## Design system

Todo el color vive en **custom properties de CSS**; las variables Sass son solo
estructurales (breakpoints y anchos, en `src/scss/admin/shared/abstracts/_variables.scss`).

Dos archivos de tokens, y son la única fuente de verdad:

- `src/scss/layout/_reset.scss` → `:root`, para landing / POS / áreas.
- `src/scss/admin/shared/base/_globals.scss` → `.admin-body`, con temas claro y
  oscuro bajo `[data-admin-theme]` en `<html>` (oscuro por defecto, fijado antes
  del primer paint por un script inline en `views/admin/layout.php`).

### Paleta

**La base es el manual de marca: verde, café, beige y crema.** Son los cuatro
únicos hex de marca del proyecto y viven en la *capa 1* de los dos archivos de
tokens:

| Token público | Token admin | Hex |
|---|---|---|
| `--brand-verde` | `--admin-brand-verde` | `#225036` |
| `--brand-cafe` | `--admin-brand-cafe` | `#4a2f21` |
| `--brand-beige` | `--admin-brand-beige` | `#e3d5bb` |
| `--brand-crema` | `--admin-brand-crema` | `#F5F1E8` |

El dorado (`--brand-oro`, `#D2AB67`) sigue existiendo pero **sólo lo consumen
las pantallas de piso** (`[data-modo="oscuro"]`: POS, áreas, login, feedback).
Salió de la landing porque sobre crema no contrasta con nada y dejaba planas
todas las secciones claras; ahí el acento es el café. `--brand-vino` ya no
existe: `[data-tono="vino"]` se conserva como alias que resuelve a café.

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
- `[data-modo="oscuro"]` — POS, tableros de área, login y feedback. Siguen en
  oscuro (turnos completos en tablet), pero re-teñidos a verde de marca en vez
  de negro neutro. El atributo va en el `<body>` de esas vistas.
- `[data-tono="crema|vino|verde|foto"]` — ritmo de secciones de la landing.
  Cada `<section>` lo declara y **todo lo que vive dentro se readapta solo**.
  `foto` es para el contenido que descansa sobre una imagen con velo (el hero):
  no cambia el fondo, sólo fuerza el texto en claro.
- `[data-admin-theme="light|dark"]` en el panel, sin cambios de estructura.

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

Vocabulario de clases admin: `admin-page`, `admin-card`, `admin-panel`,
`admin-btn--{primary,secondary,ghost}`, `admin-table`, `admin-badge--{success,
warning,danger,neutral,info}`, `admin-field` + `__label/__hint/__error`,
`admin-switch`, `admin-tabs__tab`, `admin-modal`.

En la landing, `.btn-line` es el único vocabulario de CTA: `--solid` para el
principal de una sección (relleno de acento con `--on-accent` encima),
`--secondary` para el secundario y `--pdf` para el fantasma que acompaña a un
principal sin competir con él. El texto del botón base va en `--txt-strong`,
nunca en un alias: es lo que lo mantiene legible al cruzar de tono.

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
- Lenis **cancela el `wheel`** salvo dentro de `[data-lenis-prevent]`. Todo
  contenedor con scroll propio necesita ese atributo o la rueda no lo mueve:
  hay que arrastrar la barra. En el panel lo aplica por selector
  `src/js/admin/core/motion.js` (`SCROLLABLES`) — agrégalo a esa lista; en la
  landing no hay esa capa y el atributo va escrito en el marcado (la pista de
  la galería, la rejilla de horas, el cuerpo del aviso de privacidad).
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
