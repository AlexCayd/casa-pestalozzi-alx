# Casa Pestalozzi

Sistema de gestión para el restaurante: landing público con carta y reservaciones,
punto de venta para meseros, tableros de producción por área, y panel de
administración (menú, inventario, recetas, analíticas, usuarios, configuración).

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
  **antes** del ruteo y cierra `/admin/*` a `rol === 'admin'`. Las APIs se protegen por
  allowlist (`APIS_STAFF` / `APIS_ADMIN`): **una ruta nueva que no esté en la lista
  queda pública**.

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
Credenciales de demo en `database/CREDENCIALES.md`.

**No hay migraciones incrementales y no se deben crear.** Un cambio de esquema se
escribe en `ddl.sql` (y su siembra en el DML correspondiente); para aplicarlo se
vuelve a correr `ddl.sql` y luego `dml_operativo.sql`. `dml_pruebas.sql` es opcional
y sólo se carga en entornos de desarrollo o QA. El DDL empieza con los
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
- `usuarios` tiene dos vías de acceso: `password_hash` (admins, usuario+contraseña)
  y `nip_hash` (personal de piso, NIP de **4 dígitos**). Ambos bcrypt. Como el NIP
  está hasheado, buscar por NIP recorre las filas con `password_verify` — es
  intencional, no un descuido.

## Design system

Todo el color vive en **custom properties de CSS**; las variables Sass son solo
estructurales (breakpoints y anchos, en `src/scss/admin/shared/abstracts/_variables.scss`).

Dos archivos de tokens, y son la única fuente de verdad:

- `src/scss/layout/_reset.scss` → `:root`, para landing / POS / áreas.
- `src/scss/admin/shared/base/_globals.scss` → `.admin-body`, con temas claro y
  oscuro bajo `[data-admin-theme]` en `<html>` (oscuro por defecto, fijado antes
  del primer paint por un script inline en `views/admin/layout.php`).

### Paleta

**La base es negro + dorado** (`--gold #cca352`, `--ink #0b0c0d` y sus variantes;
`--admin-gold`, `--admin-bg`, `--admin-surface` en el panel). Eso no cambia.

**Donde se use cualquier otro color, usar esta paleta** — nunca un hex suelto:

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

Estados de platillo derivados (`--estado-*` / `--admin-estado-*`) para que el POS y
los tableros de área nunca discrepen de color: preparación → ámbar, listo → verde,
entregado → azul, cancelado → gris.

Excepción deliberada: `areas_produccion.color` guarda un hex por área en BD
(café, jugos, cocina, horno). Es dato del negocio, no token de diseño.

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
- El panel corre Lenis (smooth scroll) desde `views/admin/layout.php`, y Lenis
  **cancela el `wheel`** salvo dentro de `[data-lenis-prevent]`. Todo contenedor
  con scroll propio necesita ese atributo o la rueda no lo mueve: hay que
  arrastrar la barra. `src/js/admin/core/motion.js` lo aplica por selector
  (`SCROLLABLES`) — al crear un contenedor con scroll, agrégalo a esa lista o
  pon el atributo en el marcado.
- Los parciales PHP incluidos varias veces por página (`views/components/`) deben
  cerrar con `unset()` de sus parámetros: no se reinicializan entre includes y el
  segundo hereda lo que dejó el primero.

### Gráficas

Chart.js v4, vendorizado en `public/build/js/vendor/chart.umd.min.js`.
La paleta vive en `themePalette()` (`src/js/admin/analytics/charts.js`) y se
re-renderiza con el evento `admin:themechange`. Está **validada para daltonismo**:
al cambiarla, correr el validador del skill `dataviz` y no bajar la separación ΔE.
Los colores categóricos solo se usan donde la identidad de la serie *es* el dato
(las donas); las gráficas de magnitud van a un solo tono.

## Notas de trabajo

- Escribe en español: comentarios, mensajes de UI, mensajes de commit.
- Los comentarios explican **por qué**, no qué hace la línea.
- `.claude/` y `docs/` están en `.gitignore`.
