# API de Casa Pestalozzi

Inventario de los endpoints JSON del sistema, levantado de `public/index.php`
(octubre de 2026).

## Dónde viven

No hay una carpeta de API. Las rutas viven en **dos archivos**:

| Qué | Dónde |
|---|---|
| **Registro de rutas** (URL → `Controller::metodo`) | `public/index.php` |
| **Quién puede llamarlas** | `services/Security/Permisos.php` → constantes `APIS`, `RUTAS`, `COMPARTIDAS`, `PUBLICAS` |
| Lógica de cada endpoint | `controllers/*Controller.php` → `services/` |

`Router.php` compara la URL **exacta** y sólo conoce GET, POST y DELETE: no hay
parámetros de ruta, los ids viajan por query string o en el cuerpo JSON. Una
ruta que no esté registrada responde 404 (`ErrorController::noEncontrado`).

En total hay **80 registros de API** (58 bajo `/api/`, 22 bajo `/admin/api/`);
algunas URLs se registran dos veces, una por verbo.

## Cómo se protegen

`Auth::proteger()` corre **antes** del ruteo y la decisión la toma
`Permisos::decidir()`:

- **`/admin/api/*`**: se protege por **prefijo** con `Permisos::RUTAS`. Un
  prefijo que no esté en esa lista sólo lo abren los roles de sistema
  (`super_admin`, `admin`).
- **`/api/*`**: se protege por **ruta exacta** con `Permisos::APIS`.
  ⚠️ **Una `/api/...` que no esté en `APIS` queda pública.** Al añadir un
  endpoint del personal hay que anotarlo ahí.
- **Ver = GET/HEAD, editar = todo lo demás.** Un rol con el módulo sólo en
  «ver» recibe 403 en un POST.
- Las integraciones con n8n no usan sesión: se autentican con un secreto en
  un header.

Nomenclatura de la columna **Acceso**:

- `público`: sin sesión.
- `módulo: x`: sesión con el módulo `x` (basta uno si hay varios).
- `cualquier usuario del panel`: rutas compartidas (`COMPARTIDAS`).
- `secreto: Header`: secreto compartido en ese header HTTP.

---

## 1. Públicas: reservaciones de la landing

Controlador: `ReservacionController`. Las consume `src/js/` de la landing.

| Método | Ruta | Acción | Acceso |
|---|---|---|---|
| GET | `/api/reservaciones/horarios` | `horarios` | público |
| GET | `/api/reservaciones/disponibilidad` | `disponibilidad` | público |
| POST | `/api/reservaciones/retencion` | `retencion`: aparta temporalmente el lugar | público |
| POST | `/api/reservaciones/crear` | `crearVerificada` | público (contacto verificado) |
| POST | `/api/reservaciones/modificar` | `modificarPublica` | público |
| POST | `/api/reservaciones/confirmar-modificacion` | `confirmarModificacion` | público |
| POST | `/api/reservaciones/cancelar` | `cancelarPublica` | público |
| POST | `/api/reservaciones/contacto/codigo` | `solicitarCodigo`: envía el código de verificación | público |
| POST | `/api/reservaciones/contacto/estado` | `estadoCodigo` | público |
| POST | `/api/reservaciones/contacto/verificar` | `verificarContacto` | público |
| GET | `/api/reservaciones/mis-reservaciones` | `misReservaciones` | público (sesión de contacto verificado) |
| POST | `/api/reservaciones/contacto/logout` | `logoutContacto` | público |

### Gestión por enlace con token

Las usa `views/reservaciones/gestionar.php` (la página
`/reservaciones/gestionar?token=…` que llega por correo o WhatsApp).
Controlador: `ReservationManagementAccessController`.

| Método | Ruta | Acción | Acceso |
|---|---|---|---|
| GET, POST | `/api/reservaciones/gestionar/disponibilidad` | `disponibilidad` | sesión de acceso temporal |
| POST | `/api/reservaciones/gestionar/modificar` | `modificar` | sesión de acceso temporal |
| POST | `/api/reservaciones/gestionar/cancelar` | `cancelar` | sesión de acceso temporal |
| GET, POST | `/api/reservaciones/cambio-horario/disponibilidad` | alias de `gestionar/disponibilidad` | sesión de acceso temporal |
| POST | `/api/reservaciones/cambio-horario/modificar` | alias de `gestionar/modificar` | sesión de acceso temporal |
| POST | `/api/reservaciones/cambio-horario/cancelar` | alias de `gestionar/cancelar` | sesión de acceso temporal |

Al abrir el enlace, la página canjea el token por una sesión de acceso
temporal (`ReservationManagementAccessSession`, con CSRF propio); los
endpoints exigen esa sesión, no el token. Las tres de `cambio-horario`
(`ScheduleChangeAccessController`) sólo delegan en
las de `gestionar`; se conservan por los enlaces ya enviados a clientes.

## 2. Integraciones (máquina a máquina)

| Método | Ruta | Controlador::acción | Acceso |
|---|---|---|---|
| POST | `/api/integraciones/n8n/reservaciones/recordatorios/preparar` | `N8nReservationsController::prepararRecordatorios` | JWT, `aud: reservaciones.recordatorios.preparar` |
| POST | `/api/integraciones/n8n/reservaciones/recordatorios/reclamar` | `N8nReservationsController::reclamarRecordatorio` | JWT, `aud: reservaciones.recordatorios.reclamar` |
| POST | `/api/integraciones/n8n/reservaciones/notificacion-resultado` | `N8nReservationsController::notificacionResultado` | JWT, `aud: reservaciones.notificacion-resultado` |
| POST | `/api/integraciones/n8n/feedback/areas-mejora` | `FeedbackController::recibirN8n` | JWT, `aud: feedback.areas-mejora` |

- Las cuatro exigen `Authorization: Bearer <jwt>` firmado por n8n con
  `JWT_N8N_ENTRADA_SECRET` (§9.2). Lo verifica `Auth::proteger()` antes del
  ruteo; ya no se acepta `X-N8N-Callback-Secret` ni `X-N8N-Secret`.
- Toda ruta bajo `/api/integraciones/` deniega por defecto: una que no esté
  en `Permisos::INTEGRACIONES` responde 401 con cualquier token.
- `/api/feedback-n8n` (secreto opcional) se retiró; áreas de mejora vive en
  la ruta de arriba.

### Saliente: Asistente IA

`/admin/ia` no tiene endpoint propio: el **navegador** hace `POST` directo a
`IA_N8N_WEBHOOK_URL`. El contrato está en `docs/config.md` («Asistente IA»).

## 3. Feedback del comensal

| Método | Ruta | Controlador::acción | Acceso |
|---|---|---|---|
| POST | `/api/feedback` | `FeedbackController::guardar` | público (tablet de `/feedback`) |

## 4. Punto de venta (piso)

Controlador: `PuntoVentaController`, salvo donde se indica
`PosReservacionController`. Las consume `src/js/modules/punto-de-venta.js`
(empaquetado en `public/build/js/admin/map.js`).

| Método | Ruta | Acción | Acceso |
|---|---|---|---|
| GET | `/api/punto-de-venta` | `api`: estado del mapa (mesas, tickets, alertas de impresión) | módulo: pdv |
| GET | `/api/productos` | `productos`: catálogo para el POS | módulo: pdv o area |
| GET | `/api/ticket-items` | `ticketItems` | módulo: pdv o area |
| POST | `/api/abrir-ticket` | `abrirTicket` | módulo: pdv |
| POST | `/api/enviar-comanda` | `enviarComanda` (resuelve precio de variantes en servidor) | módulo: pdv |
| POST | `/api/entregar-item` | `entregarItem` | módulo: pdv |
| POST | `/api/cancelar-item` | `cancelarItem` | módulo: pdv |
| POST | `/api/actualizar-ticket` | `actualizarTicket` | módulo: pdv |
| POST | `/api/cerrar-ticket` | `cerrarTicket` | módulo: pdv |
| POST | `/api/cancelar-mesa` | `cancelarMesa` | módulo: pdv |
| GET | `/api/corte-caja` | `corteCaja` (sólo lectura) | módulo: pdv |
| POST | `/api/sugerencias` | `sugerencias` | módulo: pdv |
| POST | `/api/impresion/alertas/atender` | `atenderAlertasImpresion` | módulo: pdv |
| GET | `/api/punto-de-venta/mesa-contexto` | `mesaContexto` | módulo: pdv |

### Reservaciones desde el POS

| Método | Ruta | Controlador::acción | Acceso |
|---|---|---|---|
| GET | `/api/punto-de-venta/reservaciones` | `PuntoVentaController::reservaciones` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/comenzar` | `PuntoVentaController::comenzarReservacion` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/cancelar` | `PuntoVentaController::cancelarReservacion` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/no-show` | `PuntoVentaController::noShowReservacion` | módulo: pdv |
| GET | `/api/punto-de-venta/reservaciones/disponibilidad` | `PosReservacionController::disponibilidad` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/crear` | `PosReservacionController::crear` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/asignar-mesas` | `PosReservacionController::asignarMesas` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/reasignar` | `PosReservacionController::reasignar` | módulo: pdv |
| POST | `/api/punto-de-venta/reservaciones/comentario` | `PosReservacionController::comentario` | módulo: pdv (+ `reservations` en editar para el comentario interno) |
| POST | `/api/punto-de-venta/reservaciones/reubicaciones/revisar` | `PosReservacionController::revisarReubicacion` | módulo: pdv |

## 5. Tableros de producción (KDS)

Controlador: `AreaController`. Las consume `src/js/modules/area.js`.

| Método | Ruta | Acción | Acceso |
|---|---|---|---|
| GET | `/api/area-items` | `areaItems` | módulo: area |
| POST | `/api/avanzar-item` | `avanzarItem` | módulo: area |
| POST | `/api/retroceder-item` | `retrocederItem` | módulo: area |

## 6. Configuración de horarios (fuera de `/admin/`)

Controlador: `AdminConfigurationController`. Viven bajo `/api/` por historia,
pero son del panel: están en `Permisos::APIS` con el módulo `configuration`.

| Método | Ruta | Acción | Acceso |
|---|---|---|---|
| GET | `/api/configuracion/horarios/semanales` | `apiObtenerHorarios` | módulo: configuration (ver) |
| POST | `/api/configuracion/horarios/semanales` | `apiGuardarHorarios` | módulo: configuration (editar) |
| POST | `/api/configuracion/horarios/especiales` | `apiGuardarEspecial` | módulo: configuration (editar) |
| POST | `/api/configuracion/horarios/excepciones` | `apiGuardarExcepcion` | módulo: configuration (editar) |
| DELETE | `/api/configuracion/horarios/excepciones` | `apiEliminarExcepcion` | módulo: configuration (editar) |
| POST | `/api/configuracion/horarios/excepciones/estado` | `apiCambiarEstadoExcepcion` | módulo: configuration (editar) |

## 7. Panel administrativo (`/admin/api/*`)

| Método | Ruta | Controlador::acción | Acceso |
|---|---|---|---|
| GET | `/admin/api/analytics/venta-por-hora` | `AdminController::ventaPorHora` | módulo: analytics |
| GET | `/admin/api/consultas` | `AdminConsultasController::consultar` (consultas rápidas) | módulo: analytics, ia, menu, inventario o tickets |
| POST | `/admin/api/menu/variantes` | `AdminMenuController::guardarVariantes` | módulo: menu |
| GET | `/admin/api/inventario/uso` | `AdminInventarioController::uso` (recetas que usan un ingrediente) | módulo: inventario |
| GET | `/admin/api/historial-precios` | `AdminInventarioController::historialPrecios` | módulo: inventario, menu o productos |
| GET | `/admin/api/area-items` | `AdminAreaController::areaItems` | módulo: area |
| POST | `/admin/api/advance-item` | `AdminAreaController::advanceItem` | módulo: area |
| POST | `/admin/api/rollback-item` | `AdminAreaController::rollbackItem` | módulo: area |
| GET | `/admin/api/reservaciones/disponibilidad` | `AdminReservacionController::disponibilidad` | módulo: reservations |
| POST | `/admin/api/reservaciones/estado` | `AdminReservacionController::apiEstado` | módulo: reservations |
| POST | `/admin/api/reservaciones/reubicaciones/revisar` | `AdminReservacionEstrategiaController::revisarReubicacion` | módulo: reservations |
| GET | `/admin/api/feedback-areas` | `AdminController::feedbackAreas` | módulo: feedback |
| GET | `/admin/api/horarios-impactos` | `AdminHorarioImpactoController::show` | módulo: configuration |
| POST | `/admin/api/horarios-impactos/preparar` | `AdminHorarioImpactoController::notify` | módulo: configuration |
| POST | `/admin/api/horarios-impactos/contacto` | `AdminHorarioImpactoController::addContact` | módulo: configuration |
| POST | `/admin/api/horarios-impactos/atender-manual` | `AdminHorarioImpactoController::attendManual` | módulo: configuration |
| POST | `/admin/api/horarios-impactos/acceso-prueba` | `AdminHorarioImpactoController::testLink` | módulo: configuration |
| GET | `/admin/api/buzon` | `AdminBuzonController::listar` | cualquier usuario del panel |
| GET | `/admin/api/buzon/resumen` | `AdminBuzonController::resumen` | cualquier usuario del panel |
| POST | `/admin/api/buzon/sincronizar` | `AdminBuzonController::sincronizar` | cualquier usuario del panel |
| POST | `/admin/api/buzon/leida` | `AdminBuzonController::marcarLeida` | cualquier usuario del panel |
| POST | `/admin/api/reportes` | `AdminConfigurationController::crearReporte` («Reportar un problema») | cualquier usuario del panel |

## 8. Otras salidas de datos (no llevan prefijo de API)

No son `/api/`, pero devuelven datos y no una página del sistema:

| Método | Ruta | Controlador::acción | Qué devuelve | Acceso |
|---|---|---|---|---|
| GET | `/menu` | `MenuController::index` | JSON de la carta pública (`Carta::publica`), lo pide `src/js/modules/menu.js` | público |
| GET | `/menu/pdf` | `MenuController::pdf` | PDF de la carta de comida | público |
| GET | `/maridaje/pdf` | `MenuController::pdfMaridaje` | PDF de la carta de maridaje | público |
| GET | `/admin/menu/pdf` | `AdminMenuController::pdf` | PDF de la carta (sin enlace en el panel) | módulo: menu |
| GET | `/admin/menu/pdf/maridaje` | `AdminMenuController::pdfMaridaje` | PDF de maridaje (sin enlace en el panel) | módulo: menu |
| GET | `/robots.txt` | `SeoController::robots` | texto plano | público |
| GET | `/sitemap.xml` | `SeoController::sitemap` | XML | público |

El resto de las rutas de `public/index.php` son páginas HTML o formularios del
panel con POST-redirect-GET (`/admin/menu/edit`, `/admin/inventario/entrada`,
etc.) y no se documentan aquí.

---

## Observaciones del barrido

- **Endpoints sin consumidor detectado.** Una búsqueda de texto en `src/js/`
  y `views/` no encontró quién llame a estos. Pueden armarse por
  concatenación, usarse desde pruebas o ser restos; conviene revisarlos antes
  de darlos por muertos:
  - `GET /api/productos`
  - `GET /api/punto-de-venta/reservaciones`
  - `GET /api/punto-de-venta/mesa-contexto`
  - `POST /api/punto-de-venta/reservaciones/reasignar`
  - `POST /api/actualizar-ticket`
  - `POST /api/configuracion/horarios/especiales`
  - `POST /api/reservaciones/cambio-horario/*` (alias heredados, ver §1)
- **Dos API de área en paralelo**: `/api/area-items`, `/api/avanzar-item`,
  `/api/retroceder-item` (piso) y `/admin/api/area-items`,
  `/admin/api/advance-item`, `/admin/api/rollback-item` (panel) hacen lo
  mismo con nombres distintos.
- **Horarios partidos en dos prefijos**: parte de la configuración de horarios
  está en `/api/configuracion/...` y otra parte en `/admin/configuracion/...`.
  Funciona porque `/api/configuracion/*` está en `Permisos::APIS`; si se añade
  una ruta nueva ahí y no se anota, queda **pública**.
- ~~`/api/feedback-n8n` sin secreto obligatorio~~: resuelto, ahora exige JWT
  bajo `/api/integraciones/` (ver §2).
- Al agregar un endpoint: regístralo en `public/index.php`, añádelo a
  `Permisos::APIS` (si es `/api/`) o comprueba que su prefijo esté en
  `Permisos::RUTAS` (si es `/admin/api/`), y actualiza este archivo.
  `scripts/tests/run-roles-permisos.php` delata las rutas `/admin/*` sin
  módulo.

---

## 9. Plan de protección con JWT

### 9.1 Qué se protege con JWT y qué no

**El criterio:** JWT sirve cuando quien llama es **otra máquina** que puede
guardar un secreto. Ahí sustituye al header con el secreto en claro por una
firma que caduca y que sólo vale para un endpoint. Cuando quien llama es un
navegador, cualquier token que reciba también lo tiene quien abra las
herramientas de desarrollo, así que JWT no protege nada que la sesión no
proteja ya.

Por eso el plan cubre **todo el tráfico con n8n, en los dos sentidos**, y nada
más. Hoy hay nueve puntos de contacto, y cuatro de ellos tienen un hueco:

#### Entrantes: n8n llama a PHP

| # | Endpoint | Autenticación hoy | Prioridad |
|---|---|---|---|
| E1 | `POST /api/feedback-n8n` | `X-N8N-Secret`, **opcional**: sin `N8N_SECRET` queda abierto | **Crítica** |
| E2 | `POST /api/integraciones/n8n/reservaciones/recordatorios/preparar` | `X-N8N-Callback-Secret` (obligatorio) | Alta (pedido) |
| E3 | `POST /api/integraciones/n8n/reservaciones/recordatorios/reclamar` | `X-N8N-Callback-Secret` (obligatorio) | Alta (pedido) |
| E4 | `POST /api/integraciones/n8n/reservaciones/notificacion-resultado` | `X-N8N-Callback-Secret` (obligatorio) | Alta (pedido) |

#### Salientes: PHP (o el navegador) llama a n8n

| # | Webhook de n8n | Quién lo llama | Autenticación hoy | Prioridad |
|---|---|---|---|---|
| S1 | Áreas de mejora (`N8N_WEBHOOK_AREAS_MEJORA_URL`) | `AdminController::llamarWebhook` (botón «Actualizar» de Feedback) | **ninguna** | **Crítica** |
| S2 | Asistente IA (`IA_N8N_WEBHOOK_URL`) | el **navegador**, desde `src/js/admin/ia/ia.js` | **ninguna**, y la URL está impresa en el HTML de `/admin/ia` | **Crítica** |
| S3 | Sugerencias (`N8N_WEBHOOK_SUGERENCIAS_URL`) | `Services\Analytics\Sugerencias::peticion` (vía `POST /api/sugerencias` del POS) | `X-N8N-Secret`, opcional | Alta |
| S4 | Confirmación de reservación | `ReservationConfirmationService` → `N8nClient` | `X-N8N-Secret` (obligatorio) | Media |
| S5 | Cambio de horario | `ScheduleChangeNotificationService` → `N8nClient` | `X-N8N-Secret` (obligatorio) | Media |

Hay un hallazgo más: **`N8N_SECRET` se usa en los dos sentidos**. S3 lo envía
a n8n y E1 lo acepta de n8n, así que quien lo vea en un lado puede escribir en
el otro. `docs/config.md` ya pide no reutilizar secretos; JWT con una llave por
sentido lo resuelve.

S1 y S2 son de prioridad crítica porque cualquiera que conozca la URL puede
disparar esos flujos. Si esos flujos llaman a un modelo de lenguaje, cada
disparo cuesta dinero. S2 además lee datos de ventas.

#### Lo que **no** lleva JWT

| Grupo | Por qué no |
|---|---|
| Reservaciones públicas (§1) y `POST /api/feedback` (§3) | Las llama un navegador anónimo. Ya las protegen CSRF, la verificación del contacto por código y los topes. Un JWT entregado al navegador no añade nada. |
| Gestión con enlace (`/api/reservaciones/gestionar/*`, `cambio-horario/*`) | Ya canjean un token de un solo uso por una sesión temporal con CSRF. |
| POS, KDS, horarios y panel (§4–§7) | Usan sesión, y `Auth` **relee el rol en cada petición**: un permiso retirado o una cuenta desactivada deja de valer en la siguiente acción. Con JWT esa revocación sólo llegaría al caducar el token, lo que sería un retroceso. |

### 9.2 Diseño

- **Algoritmo HS256, fijo.** El verificador sólo acepta `HS256` y rechaza
  `none` o cualquier otro valor del header `alg`. Una llave por sentido, con
  al menos 32 bytes aleatorios:
  - `JWT_N8N_ENTRADA_SECRET`: n8n firma y PHP verifica (E1–E4).
  - `JWT_N8N_SALIDA_SECRET`: PHP firma y n8n verifica (S1–S5).
- **Transporte:** `Authorization: Bearer <jwt>`.
- **Claims obligatorios:**

  | Claim | Valor |
  |---|---|
  | `iss` | `n8n` en las entrantes; `casa-pestalozzi` en las salientes |
  | `aud` | el identificador del endpoint, p. ej. `reservaciones.recordatorios.preparar` o `n8n.areas-mejora`. Un token firmado para un endpoint no sirve en otro. |
  | `iat`, `exp` | `exp − iat` ≤ 300 s; se rechaza un token de vida más larga aunque esté bien firmado |
  | `jti` | aleatorio; por ahora sólo se registra en el log (ver §9.4) |

  Se tolera un desfase de reloj de 30 s (`leeway`).
- **Librería:** `firebase/php-jwt` ^6.10 en vez de una implementación propia.
  El resto del stack es hecho a mano, pero la verificación de una firma es
  justo el código donde un error no se nota hasta que alguien lo explota.
  Se usa siempre con `new Key($secreto, 'HS256')`, que fija el algoritmo.
- **Errores:** `401` con `WWW-Authenticate: Bearer error="invalid_token"` y
  cuerpo `{"ok": false, "codigo": "N8N_JWT_INVALIDO"}`. El motivo concreto
  (caducado, firma, audiencia) va al `error_log` sin el token, nunca a la
  respuesta.
- **Denegar por defecto.** Toda ruta que empiece por `/api/integraciones/`
  exige JWT aunque nadie la haya anotado. Es lo contrario de la regla general
  de `/api/`, que deja pública la ruta no anotada.
- **Transición** con `JWT_N8N_MODO`:
  - `mixto`: se acepta el JWT **o** el header heredado, y PHP envía los dos.
  - `jwt` (valor por omisión): sólo JWT. Si falta la llave que se necesita,
    se lanza un `RuntimeException`, igual que hace `NipService` sin
    `NIP_LOOKUP_SECRET`. Así un despliegue mal configurado falla de forma
    visible y no queda abierto en silencio.

### 9.3 Fases de implementación

Cada fase deja el sistema funcionando y se puede encargar por separado
(«Implementa la fase 2 de API.md §9»). Las fases 1 a 5 son código y Claude
Code puede hacerlas. La fase 6 se hace en la instancia de n8n, a mano.

#### Fase 1: servicio de firma y verificación

1. `composer require firebase/php-jwt:^6.10`.
2. Crear `services/Security/JwtIntegracion.php` con:
   - `verificar(string $token, string $aud, ?string $secreto = null): array`
     → `['ok' => bool, 'motivo' => string, 'claims' => array]`. Exige `iss`,
     `aud`, `iat`, `exp` y `jti`, y aplica el límite de 300 s. El secreto se
     puede inyectar para probar sin `.env`.
   - `firmar(string $aud, int $ttl = 120, ?string $secreto = null): string`.
   - `tokenDeLaPeticion(): ?string`. Lee `HTTP_AUTHORIZATION` y, si falta,
     `REDIRECT_HTTP_AUTHORIZATION` (Apache la renombra al reescribir la URL).
   - `cabecerasSalida(string $aud): array`. Devuelve los headers que hay que
     añadir a una llamada a n8n: el `Bearer` y, en modo `mixto`, también el
     header heredado.
   - `modo(): string`.
3. Variables nuevas en la tabla de `docs/config.md` (el repo no tiene
   `.env.example`).
4. Pruebas en `scripts/tests/run-jwt-integraciones.php`, añadidas a
   `test:php`. Deben cubrir: token válido; caducado; con `nbf` futuro;
   audiencia equivocada; firma alterada en un byte; `alg: none`; `alg: HS512`;
   vida mayor de 300 s; sin `jti`; header ausente; un `Bearer` mal formado.

**Hecho cuando:** `npm run test:php` pasa y nada en el código de rutas cambió.

#### Fase 2: guardia central para las entrantes (E1–E4)

1. `public/.htaccess`: pasar el header `Authorization` a PHP, que Apache
   descarta en muchas configuraciones con FastCGI:
   ```apache
   CGIPassAuth On
   RewriteCond %{HTTP:Authorization} .
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
   Si producción usa nginx, se necesita lo equivalente:
   `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.
2. `Permisos`: nueva constante `INTEGRACIONES` (ruta exacta → `aud`) con las
   cuatro rutas E1–E4, y un tipo nuevo en `clasificar()`:
   `['tipo' => 'integracion', 'audiencia' => …]`. Cualquier
   `/api/integraciones/*` que no esté en el mapa devuelve
   `'audiencia' => null`, que se traduce en un 401. `decidir()` no cambia y
   sigue sin tocar la base ni la sesión.
3. `Auth::proteger()`: si el tipo es `integracion`, verificar el JWT (o el
   header heredado en modo `mixto`) **antes** de mirar la sesión, y responder
   con `negarJson(401, …)`. Las integraciones nunca usan sesión.
4. Mover E1 bajo el prefijo que deniega por defecto: registrar
   `POST /api/integraciones/n8n/feedback/areas-mejora` →
   `FeedbackController::recibirN8n`. Mientras dure la transición,
   `/api/feedback-n8n` sigue registrada, pero entra en `INTEGRACIONES`, así
   que también exige JWT.
5. Quitar de los controladores las comprobaciones que pasan a la guardia
   (`N8nReservationsController::secretValido` y el bloque de `N8N_SECRET` en
   `FeedbackController::recibirN8n`): una regla, un solo sitio.
6. `run-roles-permisos.php`: comprobar que toda ruta `/api/integraciones/*`
   registrada en `public/index.php` aparece en `INTEGRACIONES`, y que una ruta
   inventada bajo ese prefijo se clasifica como integración sin audiencia.

**Hecho cuando:** con `JWT_N8N_MODO=mixto`, las llamadas de n8n que funcionan
hoy siguen funcionando, y una llamada sin ningún header a E1 responde 401
aunque `N8N_SECRET` esté vacío.

#### Fase 3: firmar las salientes de servidor (S1, S3, S4, S5)

1. `N8nClient::post`: añadir `JwtIntegracion::cabecerasSalida($aud)` con
   `aud = 'n8n.' . <slug del webhook>`. Hoy envía `X-N8N-Secret` con
   `N8N_RESERVATIONS_WEBHOOK_SECRET`; en modo `jwt` deja de enviarlo.
2. `Sugerencias::peticion` (S3) y `AdminController::llamarWebhook` (S1)
   pasan a usar el mismo helper. S1 recibe autenticación por primera vez.
3. Exportaciones de `n8n/*.json`: en los Webhook de confirmación y cambio de
   horario, `authentication` pasa de `headerAuth` a `jwtAuth`. Ajustar las dos
   pruebas que hoy fijan `headerAuth`:
   `scripts/tests/run-reservaciones-notification-workflows.cjs:340` y
   `scripts/tests/run-reservaciones-comunicaciones.php:225`.

**Hecho cuando:** las pruebas pasan y, en modo `mixto`, cada llamada saliente
lleva los dos headers.

#### Fase 4: Asistente IA a través de un proxy (S2)

Un JWT de vida corta entregado al navegador seguiría expuesto a cualquier XSS
del panel durante su vida, y además obligaría a abrir CORS en n8n para el
header `Authorization`. Por eso la propuesta es un proxy, la salida que
`docs/config.md` ya dejó anotada:

1. `POST /admin/api/ia/mensaje` → `AdminIaController::mensaje`. Añadir
   `'/admin/api/ia' => ['ia']` a `Permisos::RUTAS`; sin esa línea, sólo los
   roles de sistema podrían usarlo.
2. El controlador reenvía el mismo cuerpo
   (`conversation_id`, `message`, `history`) a `IA_N8N_WEBHOOK_URL` con
   `cabecerasSalida('n8n.ia')`, con un tiempo de espera de 90 s, y devuelve
   la respuesta tal cual. **No** añade el id del usuario al token ni al
   cuerpo: el contrato actual promete que la conversación no identifica a
   nadie.
3. `src/js/admin/ia/ia.js`: `enviarMensaje()` apunta al proxy con
   `credentials: 'same-origin'` y el mismo CSRF que usen los otros POST de
   `/admin/api/` (comprobar cuál antes de escribirlo). La vista deja de
   imprimir la URL de n8n.
4. Actualizar «Asistente IA» en `docs/config.md`: ya no hace falta CORS en
   n8n.

Contrapartida asumida: cada pregunta ocupa un proceso de PHP hasta 90 s. Para
un módulo que sólo usan administradores, es aceptable.

#### Fase 5: documentación

- `docs/config.md`: las variables nuevas, y marcar los secretos heredados
  como transitorios.
- `n8n/README.md`: actualizar la tabla «Sentido / Header» y el paso de
  credenciales.
- `CLAUDE.md`: añadir una tercera regla junto a las dos «que no perdonan»:
  *toda ruta bajo `/api/integraciones/` exige JWT y deniega por defecto; una
  integración nueva va bajo ese prefijo*.
- Este archivo: actualizar la columna **Acceso** de §2 y de E1.

#### Fase 6: n8n y corte (manual, fuera del repositorio)

1. Desplegar PHP con `JWT_N8N_MODO=mixto`.
2. En n8n, crear dos credenciales **JWT Auth** (*passphrase*, HS256), una por
   llave.
   - **Webhooks S1–S5:** cambiar *Authentication* a *JWT Auth* con la llave
     de salida. Esa credencial valida la firma y la caducidad. Si además se
     quiere comprobar `aud` dentro de n8n, poner detrás un nodo **JWT**
     (*Verify*) y un **IF**.
   - **HTTP Request de E1–E4:** poner antes un nodo **JWT** (*Sign*) con la
     llave de entrada, `iss: n8n`, el `aud` del endpoint y una vida de
     120 s, y enviar `Authorization: Bearer {{ $json.token }}`.
   - Confirmar en la versión de n8n instalada que el Webhook ofrece *JWT
     Auth* y que existe el nodo JWT; los dos llegaron en la serie 1.x.
3. Cambiar la URL del flujo de áreas de mejora a
   `/api/integraciones/n8n/feedback/areas-mejora`.
4. Revisar las ejecuciones de cada flujo en los dos sentidos y pasar a
   `JWT_N8N_MODO=jwt`.
5. Limpieza (ya en código): borrar la ruta `/api/feedback-n8n`, las
   variables `N8N_SECRET`, `N8N_RESERVATIONS_WEBHOOK_SECRET` y
   `N8N_RESERVATIONS_CALLBACK_SECRET`, y las credenciales *Header Auth* de
   n8n.

### 9.4 Fuera de alcance, por ahora

- **Que un token no se pueda reutilizar** (`jti` guardado en una tabla con
  caducidad). Con una vida de 120 s y una audiencia por endpoint, un token
  capturado sólo sirve en ese endpoint y durante esos dos minutos. Antes de
  darlo por suficiente, hay que confirmar que E2–E4 den el mismo resultado si
  se llaman dos veces con el mismo cuerpo; no lo comprobé. Si alguno no lo
  hace, éste es el primer refuerzo que conviene añadir.
- **Atar la firma al cuerpo** (un claim con el hash del cuerpo). En n8n el
  JSON que se firma y el que se envía se arman en nodos distintos, y hacer que
  coincidan byte a byte es frágil. Se protege con HTTPS.
- **Llaves asimétricas (RS256).** Sólo hay dos partes y las dos son de
  confianza; con HS256 y una llave por sentido basta.
