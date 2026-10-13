# REFACTOR_INVENTARIO.md — Fase 0 (descubrimiento)

> Generado sin modificar el código. Rama de partida: `practicum` @ `ee35ce8`
> (+ cambios sin commit de «visibilidad de módulos», ver §6).

## 1. Stack confirmado

- **PHP 8 MVC hecho a mano** (`Router.php`, sin framework). Punto de entrada único
  `public/index.php`; `Auth::proteger()` corre antes del ruteo.
- **MySQL por `mysqli`** + ActiveRecord propio (`models/ActiveRecord.php`).
- **Frontend**: JS ES5 concatenado y SCSS, compilados por `gulpfile.js` a
  `public/build/` (que **sí** está versionado).
- Composer: `phpmailer`, `phpdotenv`, `intervention/image`, `dompdf`, `escpos-php`.
- npm: gulp + plugins; `chart.js`, `gsap`, `lenis`, `n8n`, cuatro `@fontsource*`.

## 2. Métricas «antes»

| Métrica | Valor |
|---|---|
| Archivos versionados | 701 |
| Líneas (sin `public/build`, locks ni binarios) | 155 887 |
| Rutas registradas en `public/index.php` | 216 (incluye 18 redirecciones 301) |
| Tablas en `database/ddl.sql` | 37 (+ `configuracion_modulos` en el WIP sin commit) |
| Controladores / modelos / servicios | 27 / 29 / 64 |
| `src/js` · `src/scss` · `views` | 64 · 92 · 99 archivos |
| `scripts/` (tests y utilidades) · `n8n/` | 63 · 11 archivos |

## 3. Mapa cotización → código existente

| # | Módulo cotizado | Qué hay hoy | Encaje |
|---|---|---|---|
| 1 | Landing | `HomeController` + `views/home/*` + `bundle.min.js` / `app.css` | Existe |
| 2 | Menús (comida y maridaje, PDF) | `productos` + `categorias` (columna `carta`), `/menu` (JSON), `/menu/pdf`, `/maridaje/pdf` (dompdf), CRUD en `/admin/menu` | Existe. El PDF **se genera** del catálogo; no es un PDF subido. Se conserva así (no se crea la tabla `menus` del esquema de referencia) |
| 3 | Platillos de temporada | Sección «Especiales de temporada» (`_firma.php`) pintada desde un **arreglo fijo** en `src/js/modules/gallery.js` | **No existe como módulo autogestionable** → ver §5, A2 |
| 4 | Catas | `catas` + `AdminCataController` + `CataService` + `_catas.php` (CTA a WhatsApp) | Existe y ya es mínimo |
| 5 | Reservaciones → WhatsApp | Motor de reserva en línea completo: 4 pasos, OTP por contacto, disponibilidad por mesa, asignación de mesas, gestión/cambio por enlace, n8n, buzón, recordatorios. WhatsApp sólo es la salida de respaldo | **No coincide** con lo cotizado → ver §5, A1 |
| 6 | Horarios + excepciones | `horarios_operacion`, `excepciones_operacion`, `HorarioOperacionService`, `/admin/configuracion/horarios`, tablas de `_reserva.php` y `_footer.php` | Existe, pero acoplado a reservaciones (impactos, locks, notificación de cambio) |
| 7 | Panel admin | `Auth` (3 roles + NIP), layout del panel, sidebar | Existe; hay que reducirlo a un rol |

## 4. Inventario por bloque

Decisión: **C** = Conservar · **E** = Eliminar · **A** = Ambiguo (§5).

### 4.1 Rutas, controladores y vistas

| Elemento | Módulo | Decisión | Evidencia |
|---|---|---|---|
| `/`, `HomeController`, `views/home/index.php`, `_nav`, `_hero`, `_nosotros`, `_insegna`, `_chef`, `_panaderia`, `_ubicacion`, `_footer`, `_cursor`, `_redes`, `_privacidad` | 1 | C | Contenido de la landing; el rediseño queda fuera de alcance |
| `_menu.php`, `/menu`, `/menu/pdf`, `/maridaje/pdf`, `MenuController`, `Carta`, `MenuPdf`, `views/admin/menu/items-pdf.php` | 2 | C | Sección de menú y las dos cartas en PDF |
| `/admin/menu*` (CRUD de platillos y categorías), `AdminMenuController`, `CategoriaMenuService`, `views/admin/menu/*` | 2 | C | CRUD de los menús. Quitar el acoplamiento a `HistorialPrecios` (inventario) |
| `_firma.php` + `gallery.js` | 3 | C (tal cual) | Ver A2 |
| `_catas.php`, `/admin/catas*`, `AdminCataController`, `CataService`, `Cata`, `views/admin/catas/*`, `src/js/admin/catas/catas.js`, `catas.scss` | 4 | C | CRUD + agenda pública |
| `/admin/configuracion/horarios*`, `/api/configuracion/horarios/*`, `HorarioOperacionService`, `HorarioOperacion`, `ExcepcionOperacion`, `views/admin/configuration/hours*` | 6 | C | Quitar el acoplamiento con reservaciones si A1 = eliminar el motor |
| `/admin/login`, `/logout`, `AuthController::loginAdmin`, `Auth` | 7 | C | Reducido a un rol |
| `/login` (NIP de piso), `NipService`, `scripts/migrar-credenciales-piso.php`, `seed-usuarios-prueba.php` | — | E | Sólo sirve a meseros y cocineros |
| `/admin` (dashboard), `/admin/analytics`, `AdminController::index/analytics/tickets/feedback*`, `services/Analytics/*`, `views/admin/dashboard.php`, `analytics.php`, `tickets/`, `feedback/`, `RangoPeriodo`, `range-picker.js`, `analytics/*.js`, `analytics.scss` | — | E | Reportes y analítica. `/admin` pasará a redirigir a `/admin/menu` (pegamento) |
| `/punto-de-venta`, `/api/punto-de-venta*`, `/api/abrir-ticket`, `/api/cerrar-ticket`, `/api/enviar-comanda`, `/api/corte-caja`, etc., `PuntoVentaController`, `AdminPuntoVentaController`, `services/Pos/*`, `views/punto-de-venta/*`, `views/admin/punto-de-venta/*`, `punto-de-venta.js`, `map.js`, `src/js/operation/*`, `src/scss/punto-de-venta/*`, `src/scss/operation/*` | — | E | POS, mesas, tickets |
| `/area*`, `/admin/area*`, `/api/area-items`, `/api/avanzar-item`…, `AreaController`, `AdminAreaController`, `views/area/*`, `views/admin/area/*`, `area.js`, `admin/area/area.js`, `area.scss` | — | E | Tableros de cocina (KDS) |
| `/admin/inventario*`, `/admin/recetas*`, `/admin/api/historial-precios`, `AdminInventarioController`, `AdminRecetasController`, `services/Inventory/*`, modelos `Ingrediente`, `Proveedor`, `IngredienteProveedor`, `Subreceta`, `HistorialPrecio`, vistas y JS/SCSS de inventario y recetas | — | E | Inventario, proveedores, recetas, costeo |
| `/admin/finanzas*`, `AdminFinanzasController`, `GastoFijo`, `finanzas/*.js`, `finanzas.scss` | — | E | Finanzas y reportes |
| `/admin/printers*`, `AdminPrintersController`, `Impresora`, `classes/TicketPrinter.php`, `classes/Impresion/*`, `ImpresionAlertaService`, `escpos-php` | — | E | Impresión de comandas |
| `/feedback`, `/api/feedback*`, `FeedbackController`, `views/feedback/*`, `feedback.js`, `src/scss/feedback/*` | — | E | Encuestas de comensales; no cotizado |
| `/admin/usuarios*` (CRUD con roles, NIP, activar/desactivar) | 7 | A | Ver A4 |
| `/admin/configuracion/pos*`, `ConfiguracionPos` | — | E | POS |
| `/admin/configuracion/reportes*`, `/admin/api/reportes`, `ReporteSistemaService`, `ReporteSistema` (botón «Reportar un problema») | — | E | Soporte interno no cotizado |
| `/admin/configuracion/anuncio`, `ConfiguracionAnuncio`, `AnuncioConfig`, `_announcement.php`, `announcement.js` | — | A | Ver A4 |
| `_catering.php` (rejilla estática de enlaces a WhatsApp de eventos, sin backend) | — | A | Ver A4 |
| WIP sin commit: `/admin/configuracion/modulos`, `ModuloVisibilidadService`, `modulo-oculto.php`, `configuracion_modulos` | — | E | Visibilidad de módulos: deja de tener sentido con 4 módulos. Ver A3 |
| `/admin/api/buzon*`, `AdminBuzonController`, `BuzonNotificacion`, `buzon.js`, `_buzon.scss` | — | E (si A1 = WhatsApp) | Bandeja de avisos de reservaciones |
| Reservaciones: `/reservaciones/gestionar`, `/reservaciones/cambio-horario`, `/api/reservaciones/*` (22 rutas), `/admin/reservaciones*` (18), `/admin/api/horarios-impactos*`, `/api/integraciones/n8n/*`, controladores `Reservacion*`, `AdminReservacionController`, `ReservationManagementAccessController`, `ScheduleChangeAccessController`, `N8nReservationsController`, `AdminHorarioImpactoController`; `services/Reservations/*`, `services/Notifications/*`, `services/Security/Reservation*`, `services/Contact/*`, `services/Tables/*`, `services/Integrations/N8nClient.php`; `views/reservaciones/*`, `views/operation/*`, `views/admin/reservations/*`, `views/components/reservations/*`; `form.js`, `reservation-access.js`, `schedule-change-access.js`, `components/reservation-*.js`, `admin/reservations/*.js`; `n8n/`; `phpmailer` | 5 | A | Ver A1 |
| Redirecciones 301 de rutas antiguas (`/admin/productos*`, `/admin/menu/items*`, `/admin/reservations*`) | — | E | Apuntan a módulos eliminados o son legado |
| `ErrorController`, `views/errors/*` | — | C | 404 limpio |

### 4.2 Base de datos (37 tablas)

| Tabla | Decisión | Evidencia |
|---|---|---|
| `categorias` | C | Menú (`carta` comida/maridaje). Revisar `img` |
| `productos` | C | Menú. Quitar `area_id` (FK a `areas_produccion`) |
| `catas` | C | Catas |
| `horarios_operacion`, `excepciones_operacion` | C | Horarios |
| `usuarios` | C | Panel. Quitar `nip_hash`, `nip_lookup`, la restricción `chk_usuarios_admin_sin_nip`, y reducir `rol` a `admin` (o quitarlo) |
| `configuracion_anuncio` | A | A4 |
| `reservaciones` | A | A1. Sólo se conservaría si el formulario siguiera guardando |
| `mesas`, `reservacion_mesas`, `verificaciones_contacto`, `reservacion_recordatorios`, `horario_impactos`, `horario_impacto_reservaciones`, `buzon_notificaciones`, `configuracion_reservaciones` | E (si A1 = WhatsApp) | Motor de reservaciones |
| `tickets`, `ticket_items`, `ticket_mesas`, `ticket_pagos`, `areas_produccion`, `impresoras`, `impresion_alertas`, `configuracion_pos` | E | POS / KDS / impresión |
| `ingredientes`, `subrecetas`, `subreceta_ingredientes`, `producto_componentes`, `movimientos_inventario`, `proveedores`, `ingrediente_proveedores`, `historial_precios`, `gastos_fijos` | E | Inventario / recetas / finanzas |
| `feedback`, `feedback_tokens`, `logs_sugerencias` | E | Feedback y sugerencias del POS |
| `reportes_sistema` | E | Reportes internos |
| `menu` | E | Legado de sólo lectura (CLAUDE.md: «no escribir ahí») |
| `cata_inscripciones`, `catering_solicitudes` | E | Ya sin uso; sólo quedan sus `DROP` |

Resultado esperado si A1 = WhatsApp y A4 sin extras: **6 tablas** (`usuarios`,
`categorias`, `productos`, `catas`, `horarios_operacion`, `excepciones_operacion`).

### 4.3 Dependencias y otros

| Elemento | Decisión | Evidencia |
|---|---|---|
| `dompdf/dompdf` | C | PDFs de carta |
| `intervention/image` + `ImagenUploader` | C | Imágenes de categorías y platillos (verificar en Fase 2) |
| `vlucas/phpdotenv` | C | Configuración |
| `mike42/escpos-php` | E | Impresoras |
| `phpmailer/phpmailer` | E (si A1 = WhatsApp) | OTP y notificaciones de reservaciones |
| `n8n` (npm) y carpeta `n8n/` | E (si A1 = WhatsApp) | Automatizaciones de reservaciones y sugerencias |
| `chart.js` | E | Sólo analítica y finanzas |
| `gsap`, `lenis`, fuentes `@fontsource*` | C | Landing y panel |
| `scripts/tests/*` | E en su mayoría | 55 de 63 prueban POS o reservaciones; se conservan sólo los que cubran lo que queda (p. ej. `run-reservaciones-menu-pdf.php` prueba el PDF de la carta, a pesar del nombre) |
| `scripts/auditar-errores-reservaciones.php`, `limpiar-fixtures-reservaciones.php` | E (si A1 = WhatsApp) | Utilidades de reservaciones |
| `database/ANALITICAS.md`, `database/development.sql` | E | Fixtures de analítica, RevPASH y POS |
| `public/.DS_Store` | E | Basura de macOS |
| `docs/*` | — | Ignorado por git; no forma parte del diff |

## 5. Elementos ambiguos (necesitan decisión)

- **A1 — Reservaciones.** La cotización pide «un formulario que se enlaza a la
  línea de WhatsApp». Lo que existe es un motor de reserva en línea (≈ 40 % del
  repositorio, unas 15 tablas). Llevarlo a «formulario → WhatsApp» significa
  borrar el motor y **reescribir el envío del formulario** para que arme un
  enlace `wa.me` con los datos. Es código nuevo, aunque pequeño, y el
  documento pide consultarlo.
- **A2 — Platillos de temporada.** La sección existe, pero sus siete platillos
  están escritos a mano en `gallery.js`; no hay tabla ni CRUD. Hacerla
  autogestionable es construir un módulo nuevo, y eso queda fuera del alcance de una poda.
- **A3 — Cambios sin commit en `practicum`** (visibilidad de módulos). Hay que
  decidir qué hacer con ellos antes de crear la rama y la etiqueta `pre-refactor`.
- **A4 — Extras del panel y de la landing** que la cotización no menciona:
  el anuncio emergente, la sección de catering (estática), el CRUD de usuarios
  del panel y la sección de feedback.

## 6. Notas

- `AdminController::MODULES` y el sidebar se reducirán a los módulos que queden.
- `Auth::proteger()` se reducirá a «`/admin/*` exige sesión de admin»; las
  allowlists `APIS_POS`, `APIS_AREA`, `APIS_PISO` desaparecen.
- `HorarioOperacionService` depende de `ReservacionConfig` (zona horaria y
  fecha actual), de `HorarioReservacionService` y de
  `ScheduleChangeNotificationService`. Si A1 = WhatsApp, el pegamento consiste en
  quitar la detección de conflictos con reservaciones y llevar la zona
  horaria a un sitio neutral.
