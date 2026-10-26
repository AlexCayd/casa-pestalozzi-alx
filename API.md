# API de Casa Pestalozzi

Referencia técnica de endpoints JSON. El registro ejecutable está en
`public/index.php` y la autorización vigente en
`services/Security/Permisos.php`/`PermisosUsuario.php`, subordinados al
[contrato rector](docs/arquitectura.md). Las tablas de rutas de este inventario
son una ayuda de lectura; no sustituyen el catálogo ni acreditan módulos pendientes.

## Dónde viven

No hay una carpeta de API. Las rutas viven en **dos archivos**:

| Qué | Dónde |
|---|---|
| **Registro de rutas** (URL → `Controller::metodo`) | `public/index.php` |
| **Quién puede llamarlas** | `services/Security/Permisos.php` → `clasificarDirecto()` y catálogo de módulos; constantes auxiliares según la ruta |
| Lógica de cada endpoint | `controllers/*Controller.php` → `services/` |

`Router.php` compara la URL **exacta** y sólo conoce GET, POST y DELETE: no hay
parámetros de ruta, los ids viajan por query string o en el cuerpo JSON. Una
ruta que no esté registrada responde 404 (`ErrorController::noEncontrado`).

El número de registros cambia con cada módulo. Revisar las rutas exactas y sus
verbos en el router al extender esta referencia; no deducir acceso de su prefijo.

## Cómo se protegen

`Auth::proteger()` corre antes del ruteo y usa `Permisos::clasificarDirecto()`
con el perfil actual de permisos usuario/unidad/módulo:

- Las APIs públicas están clasificadas expresamente; una API privada desconocida
  se deniega y no queda pública por omisión.
- Una ruta administrativa necesita el módulo y nivel de su caso de uso; un nombre
  de rol no concede acceso. Las rutas compartidas conservan sus controles propios.
- GET/HEAD solicitan lectura; mutaciones exigen edición o el permiso operativo
  correspondiente. Una sesión NIP no adquiere permisos administrativos.
- Las integraciones n8n mantienen sus secretos de header y los endpoints públicos
  de gestión mantienen sus tokens; no se les exige una sesión administrativa.
- El servicio vuelve a verificar ámbito, permisos y pertenencia del recurso. Los
  módulos futuros no se habilitan por agregar su ruta o su tabla.

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
| POST | `/api/integraciones/n8n/reservaciones/recordatorios/preparar` | `N8nReservationsController::prepararRecordatorios` | secreto: `X-N8N-Callback-Secret` |
| POST | `/api/integraciones/n8n/reservaciones/recordatorios/reclamar` | `N8nReservationsController::reclamarRecordatorio` | secreto: `X-N8N-Callback-Secret` |
| POST | `/api/integraciones/n8n/reservaciones/notificacion-resultado` | `N8nReservationsController::notificacionResultado` | secreto: `X-N8N-Callback-Secret` |
| POST | `/api/feedback-n8n` | `FeedbackController::recibirN8n` | secreto: `X-N8N-Secret` (**opcional**, ver nota) |

- Las tres de reservaciones **rechazan** la llamada si el secreto
  (`NotificationConfig::n8nCallbackSecret()`) está vacío.
- ⚠️ `/api/feedback-n8n` **sólo exige el secreto si `N8N_SECRET` está
  configurado** (`AreasMejora::secret()`). Con la variable vacía, cualquiera
  puede escribir ahí. Conviene confirmar que en producción esté definida.

### Saliente: Asistente IA

`/admin/ia` usa `GET /admin/api/consultas`, con intenciones estructuradas del
motor local `Services\Analytics\ConsultasRapidas`. No llama a un LLM ni
al webhook n8n descartado. Ver [configuración](docs/config.md#asistente-ia).

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

## Mantenimiento del inventario

Al agregar rutas, revisar registro exacto, clasificador de permisos directos,
servicio y consumidores. Las APIs privadas desconocidas se deniegan; un prefijo
no concede acceso. Las tablas anteriores no son una auditoría de código para
eliminación y no autorizan retirar endpoints.

Las integraciones de reservaciones usan los secretos separados documentados en
[notificaciones](docs/reservaciones/notificaciones.md). Otras integraciones
conservan límites concretos: feedback entrante exige N8N_SECRET solo si está
configurado; el disparador de áreas de mejora no añade autenticación propia.
Revisar esa deuda por separado antes de un despliegue expuesto. No se declara
implementado el antiguo plan JWT; su evaluación pendiente está en
[backlog](docs/backlog.md).
