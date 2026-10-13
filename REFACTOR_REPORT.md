# REFACTOR_REPORT.md — Casa Pestalozzi, versión simplificada

Rama `version-recortada`, desde la etiqueta `pre-refactor` (`ee35ce8`).
Plan en `REFACTOR.md`; inventario de la Fase 0 en `REFACTOR_INVENTARIO.md`.

Decisiones tomadas con el cliente durante el trabajo, que modifican el alcance
literal de `REFACTOR.md`:

- **Reservaciones:** se conserva el motor de reservación en línea (OTP,
  disponibilidad por mesa, mapa operativo, notificaciones por n8n) en lugar de
  reducirlo a un enlace a WhatsApp. El POS se elimina y el motor se **desacopla**
  de los tickets.
- **«En curso» se retira:** sin POS no hay señal de llegada. Una confirmada se
  resuelve a mano como completada, no-show o cancelada, y ocupa sus mesas
  durante todo su intervalo.
- **Extras conservados:** anuncio de la landing, sección de catering (estática)
  y CRUD de usuarios del panel (sin roles ni NIP).
- **Platillos de temporada:** se dejan como están (arreglo fijo en JS) y se
  anotan como pendiente; no se inventa un módulo.

## 1. Métricas antes / después

| Métrica | Antes | Después |
|---|---|---|
| Archivos versionados | 701 | 559 |
| Líneas (sin `public/build`, locks ni binarios) | 155 887 | 108 887 |
| Diff `pre-refactor...HEAD` | — | 277 archivos, +2 072 / −63 686 |
| Rutas en `public/index.php` | 216 | 108 |
| Tablas | 37 en el DDL (40 en la base local) | 16 |
| Controladores / modelos / servicios | 27 / 29 / 64 | 18 / 17 / 52 |
| Composer | phpmailer, phpdotenv, intervention/image, dompdf, escpos-php | phpdotenv, intervention/image, dompdf |
| npm (dependencias) | 4 fuentes, chart.js, gsap, lenis, n8n | 4 fuentes, gsap, lenis, n8n |
| Pruebas | 63 scripts | 47 scripts; `npm test` y las 9 suites con base aislada pasan |

## 2. Qué se eliminó, por módulo

| Bloque | Qué salió |
|---|---|
| Tableros de producción (KDS) | Controladores, vistas, `area.js`, SCSS, rutas `/area*` y `/admin/area*`; `productos.area_id` y `areas_produccion` |
| Analíticas, tickets y feedback | Dashboard `/admin/analytics`, histórico de tickets, feedback de comensales (pantalla pública y panel), servicio `Analiticas`, flujo n8n de áreas de mejora |
| Finanzas | Módulo completo, Sankey y Chart.js |
| Punto de venta | Pantalla del mesero, sus APIs, `punto-de-venta.js`, bundle `map.js`, SCSS del POS, sugerencias por n8n |
| Impresión | Estaciones de impresión, `TicketPrinter`, `classes/Impresion`, alertas de impresión, configuración del POS, `escpos-php` |
| Reportes internos | Botón «Reportar un problema», su modal y la pantalla de Configuración › Reportes |
| Inventario y recetas | Ingredientes, proveedores, subrecetas, recetas, movimientos, histórico de precios |
| Acceso de piso | Login por NIP, roles de mesero y cocinero, allowlists de APIs de piso, regeneración y entrega de NIP |
| Desacople de reservaciones | Modelos `Ticket`, `TicketItem`, `TicketMesa`; `PuntoVentaReservacionService`, `TicketTemporalService`; lecturas de `tickets` en los servicios de reservaciones, mesas y buzón; 38 códigos del catálogo de errores |
| Legado | Redirecciones 301 de rutas antiguas, layout DevWebCamp y `Router::render()`, plantillas vacías, parciales SCSS de la plantilla original, `.DS_Store`, iconos de módulos retirados |
| Base de datos | `ddl.sql`, `deploy.sql` y `development.sql`, sustituidos por `schema.sql`, `seed.sql` y la migración 001 |
| Dependencias y entorno | PHPMailer (sin uso: las notificaciones salen por n8n), variables del NIP, sugerencias y feedback en `.env.example` |

## 3. Esquema final (16 tablas)

| Módulo | Tabla | Columnas |
|---|---|---|
| Panel | `usuarios` | id, username, nombre, password_hash, activo, created_at, updated_at |
| Menús | `categorias` | id, nombre, carta (comida/maridaje), img, activo |
| Menús | `productos` | id, nombre, descripcion, categoria_id, precio, activo |
| Catas | `catas` | id, titulo, descripcion, fecha, hora, duracion_min, precio, disponible, created_at, updated_at |
| Horarios | `horarios_operacion` | id, dia_semana, abierto, hora_apertura, hora_cierre, updated_by, updated_at |
| Horarios | `excepciones_operacion` | id, fecha, tipo, motivo, hora_apertura, hora_cierre, activo, updated_by, created_at, updated_at |
| Landing | `configuracion_anuncio` | id, mensaje, tipo, activo, fecha_inicio, fecha_fin, texto_enlace, url_enlace, updated_by, updated_at |
| Reservaciones | `reservaciones` | id, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, comentario_admin, motivo_cancelacion, origen, request_token, hold_expires_at, estado, reemplaza_reservacion_id, estado_changed_at, created_at, updated_at |
| Reservaciones | `mesas` | id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable |
| Reservaciones | `reservacion_mesas` | id, reservacion_id, mesa_id, orden, created_at |
| Reservaciones | `verificaciones_contacto` | OTP: código hasheado, intentos, ciclo y estado de entrega |
| Reservaciones | `reservacion_recordatorios` | recordatorio del día anterior y su entrega |
| Reservaciones | `configuracion_reservaciones` | activación y hora del recordatorio |
| Reservaciones | `horario_impactos` | cambios de horario que afectan reservaciones |
| Reservaciones | `horario_impacto_reservaciones` | seguimiento por reservación afectada |
| Panel | `buzon_notificaciones` | avisos operativos del panel |

**Justificación de las 16 tablas** (el techo del plan era 8): nueve pertenecen
al motor de reservaciones que se decidió conservar —mesas, asignación, OTP,
recordatorios, cambios de horario y buzón— y una al anuncio de la landing. Sin
esos dos acuerdos, el esquema quedaría en 6.

## 4. Elementos ambiguos que no se tocaron

- **Fotos sin referencia:** 18 imágenes en `assets/images/` (`catering-*`,
  `comida-3/5/8/10`, `galeria-3/5`, `maridaje-*`, `panaderia-5`, `pizza-1/2`,
  `servicios-*`), con sus copias en `public/build/images/`, más dos subidas con
  nombre de hash. Ningún código, seed ni registro de la base local las usa, pero
  son fotos del restaurante que podrían servir para categorías o temporada.
- **`Services\Pos\*`:** los cuatro servicios que sobreviven
  (`PosReservacionQueryService`, `PosReservacionSerializer`,
  `PosMesaProjectionPresenter`, `ReservacionPoliticaPosService`) son ya el
  contrato del mapa de reservaciones. No se renombraron para no tocar una docena
  de archivos y pruebas.
- **`docs/`:** está versionado aunque `.gitignore` lo excluye, y casi todo es
  anterior al recorte (sigue describiendo el POS). Sólo se retiró
  `docs/usuarios/credenciales.md`.

## 5. Riesgos y pendientes

1. **La base local `casa_pestalozzi` sigue con el esquema viejo.** La migración se
   probó sobre copias. Con el esquema viejo, dar de alta un platillo falla porque
   `productos.area_id` no tiene valor por omisión. Para migrarla:
   `mysql … casa_pestalozzi < database/migrations/001_simplificar_esquema.sql`
   (el respaldo ya está en `backup_pre_refactor.sql`).
2. **Platillos de temporada no son autogestionables** (arreglo en
   `src/js/modules/gallery.js`). La cotización lo pide al 100 %; requiere una
   tabla, un CRUD y que la sección lea del servidor.
3. **Campos con forma de ticket en el contrato del mapa** (`ticket_abierto`,
   `disponible_para_ticket`, `puede_abrir_ticket`, `mesas_proyectadas`…) siempre
   valen falso o vacío. Funciona, pero es ruido que conviene podar en el JS de
   operación junto con sus pruebas.
4. **Cambio de comportamiento en reservaciones:** pasada la tolerancia, una
   confirmada sigue ocupando sus mesas hasta el final de su intervalo, porque ya
   no hay forma de saber si el cliente llegó. Antes las liberaba. El buzón avisa
   para resolverla como completada o no-show.
5. **Revisión de diseño pendiente:** CLAUDE.md exige pasar todo cambio de SCSS
   o de vistas por el subagente `ux-ui`, que no está disponible en esta sesión.
   Los cambios visuales fueron eliminaciones: el botón «Reportar», las pestañas
   del NIP en el login, el rol en el formulario de usuarios, la columna de área
   en Menú y el enlace «Mesas» del cajón operativo.
6. **Error previo al refactor:** `services/Security/ReservationManagementAccessSession.php`
   llama a `ReservacionConfig::ahora()` sin importar la clase; fallaría en
   tiempo de ejecución si se recorre esa rama.
7. **Historial:** `docs/usuarios/credenciales.md` (contraseña de desarrollo de
   `admin_demo`) se borró, pero sigue en los commits anteriores a
   `pre-refactor`. Es una credencial de desarrollo; si esa cuenta existe en
   algún servidor, conviene cambiarle la contraseña.
8. **Usuarios migrados:** los meseros y cocineros quedan desactivados, no
   borrados. Si nadie los necesita, pueden eliminarse desde el panel.
9. **Assets de vendor:** GSAP, ScrollTrigger y Lenis no se versionan; los copia
   `npx gulp build` (paso documentado en el README). Sin ese paso la landing
   anima sin librerías de movimiento.

## 6. Verificación (Fase 5)

**Instalación desde cero** — `schema.sql` + `seed.sql` cargan sin errores (16
tablas, 2 triggers, 107 platillos, un registro de ejemplo por módulo).

**Migración** — el respaldo (40 tablas) se restauró en una base temporal y la
001 se ejecutó **dos veces**. El resultado coincide con `schema.sql` en
columnas, tipos, valores por omisión, índices, llaves foráneas, checks y
triggers. No quedan procedimientos auxiliares, y de los 6 usuarios sólo el
administrador sigue activo.

**Checklist funcional** — automatizado por HTTP contra la base migrada, que
tiene datos reales del entorno anterior: **32/32 OK**, sin avisos de PHP en el
log.

- [x] Panel: sin sesión redirige a `/login` y las APIs devuelven 401; la
  contraseña incorrecta no entra y la correcta sí; tras salir vuelve a pedir
  login.
- [x] Menús: alta de platillo → aparece en `/menu`; ocultarlo lo saca de la
  carta; baja. `/menu/pdf` y `/maridaje/pdf` responden PDF.
- [x] Catas: alta, edición y baja, reflejadas en la landing.
- [x] Horarios: alta de excepción → la landing marca «Cierre especial»; baja.
  La API del horario semanal responde.
- [x] Reservaciones: la disponibilidad pública responde y la landing enlaza al
  WhatsApp del restaurante. Además, sobre la base local: «Completar» rechaza una
  reservación futura y acepta una pasada (idempotente), y el no-show se bloquea
  dentro de la tolerancia.
- [x] Módulos retirados (`/punto-de-venta`, `/area`, `/feedback`,
  `/admin/analytics`, `/admin/inventario`, `/admin/recetas`, `/admin/finanzas`,
  `/admin/printers`, `/admin/tickets`, `/admin/configuracion/pos`,
  `/api/corte-caja`): todos 404.
- [x] Landing sin recursos rotos: las 56 URLs locales (HTML, CSS, imágenes de
  la galería y de la carta) responden 200 una vez corrido `npx gulp build`.
- [ ] **Landing responsiva en móvil y escritorio: sin verificar.** Requiere
  revisión visual manual; el refactor no tocó estilos de la landing salvo quitar
  el feedback del bundle.

**Chequeos automáticos**

- `php -l` sobre todo el proyecto: sin errores.
- Resolución de clases, rutas, métodos estáticos y constantes contra el
  autoload: sin referencias rotas (los únicos avisos son comentarios y `use`
  con sangría).
- `npm test`: pasa.
- `php scripts/tests/run-notifications-isolated.php` (más `menu-pdf` y
  `reasignacion-db`): 9/9 PASS.
