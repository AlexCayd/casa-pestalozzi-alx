# Inventario de residuos — Etapa 7

## Alcance y método

Auditoría estática del árbol de reservaciones y de las 168 entradas registradas
en `public/index.php`. Se revisaron rutas, autoload, controladores, servicios,
modelos, vistas, JavaScript, SCSS, DDL/DML, scripts y documentación. Los
hallazgos se clasifican sin retirar código sólo por ausencia de una coincidencia
textual: las rutas se contrastaron con `public/index.php`, enlaces, vistas,
consumidores JS y pruebas.

## Resultado

- Entradas de router: **168** (88 GET, 79 POST y 1 DELETE).
- Rutas sin controlador: **0**.
- Rutas duplicadas activas por método y path: **0**.
- Rutas de reservaciones retiradas en esta etapa: **0**; las rutas históricas
  detectadas son redirecciones generales del menú y quedan fuera del módulo.
- Aliases ejecutables de `ConfirmationModal`: **0**.
- Servicios paralelos de disponibilidad, tiempo o capacidad: **0**.
- Working tree generado por runners: **0** después de la limpieza.

## Clasificación de hallazgos

| Hallazgo | Archivo | Consumidores | Clasificación | Acción |
| --- | --- | ---: | --- | --- |
| Redirecciones de menú antiguo | `public/index.php:157-169,193` | marcadores | FUERA_DEL_MODULO / MIGRACION_HISTORICA | Conservar con 301; no afectan reservaciones |
| `CPConfirmationModal` | `src/js`, `views` | 0 | ELIMINAR | Ya retirado en Etapa 6; se conserva la mención sólo en evidencias históricas |
| `admin-modal` de usuarios y reportes | vistas admin no relacionadas | reales | FUERA_DEL_MODULO | Conservar: no son confirmaciones de reservaciones |
| `mesa-modal` y `operation-create-modal` | POS/operación | reales | CONSERVAR | Son formularios y wizards persistentes, no shells de confirmación |
| `ReservacionService` | `services/ReservacionService.php` | controladores y fachadas | CONSERVAR | Fachada/API interna; no duplica el motor canónico |
| campos `request_fingerprint`, `created_by`, `last_modified_by`, `last_modified_source`, `last_change_reason` | código/DDL de reservaciones | 0 | DOCUMENTAR | No existen en el esquema vigente ni en consumidores activos |
| `arrived_at`, `confirmed_at`, `completed_at` | consultas del módulo | 0 | DOCUMENTAR | El estado y `estado_changed_at` son la fuente vigente |
| literales temporales | servicios | reales | CONSERVAR | Quedan encapsulados en `ReservacionConfig`/servicios; no hay duplicación funcional |
| comentarios con `legacy` o `anterior` | DDL/docs generales | históricos | MIGRACION_HISTORICA | Se conservan cuando explican compatibilidad fuera de reservaciones |

## Auditoría de consumidores

| Área | Comprobación | Resultado | Clasificación |
| --- | --- | --- | --- |
| Servicios | Responsabilidad y consumidores reales revisados por `rg`, autoload y llamadas | sin duplicados funcionales | CONSERVAR |
| Rutas | Controlador, método, guardia, HTTP y consumidor | 168/168 resueltas | CONSERVAR / INTERNA según tabla |
| JavaScript | 47 archivos activos, listeners, fetch, abortos y modal canónico | sin comparaciones de mensajes ni cálculo local de capacidad | CONSERVAR |
| SCSS | shell, formularios persistentes, POS y mapa | un shell de confirmación; componentes funcionales diferenciados | CONSERVAR |
| DDL/DML | campos, índices y relaciones | coherente con la fuente vigente | CONSERVAR |
| Documentación | fuente, plan e historial | una fuente normativa; reportes como evidencia | DOCUMENTAR |

## Inventario de las 168 rutas

La tabla siguiente se genera directamente del registro del front controller; el
número de línea facilita repetir la auditoría sin depender de un nombre de ruta
construido dinámicamente.

| Línea | Método | Ruta | Guardia | Controlador | Consumidor | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| 39 | GET | `/` | público | `HomeController::index` | vistas/enlaces | ACTIVA |
| 40 | GET | `/reservaciones` | público | `HomeController::index` | vistas/enlaces | ACTIVA |
| 43 | GET | `/api/reservation-schedules` | público | `ReservacionController::horarios` | JS/HTTP | INTERNA |
| 44 | GET | `/api/reservaciones/disponibilidad` | público | `ReservacionController::disponibilidad` | JS/HTTP | INTERNA |
| 45 | POST | `/api/reservaciones/retencion` | público | `ReservacionController::retencion` | JS/HTTP | INTERNA |
| 46 | POST | `/api/reservaciones/crear` | público | `ReservacionController::crearVerificada` | JS/HTTP | INTERNA |
| 47 | POST | `/api/reservaciones/modificar` | público | `ReservacionController::modificarPublica` | JS/HTTP | INTERNA |
| 48 | POST | `/api/reservaciones/confirmar-modificacion` | público | `ReservacionController::confirmarModificacion` | JS/HTTP | INTERNA |
| 49 | POST | `/api/reservaciones/cancelar` | público | `ReservacionController::cancelarPublica` | JS/HTTP | INTERNA |
| 50 | POST | `/api/reservaciones/contacto/codigo` | público | `ReservacionController::solicitarCodigo` | JS/HTTP | INTERNA |
| 51 | POST | `/api/reservaciones/contacto/verificar` | público | `ReservacionController::verificarContacto` | JS/HTTP | INTERNA |
| 52 | GET | `/api/reservaciones/mis-reservaciones` | público | `ReservacionController::misReservaciones` | JS/HTTP | INTERNA |
| 53 | POST | `/api/reservaciones/contacto/logout` | público | `ReservacionController::logoutContacto` | JS/HTTP | INTERNA |
| 56 | GET | `/admin` | admin | `AdminController::index` | vistas/enlaces | ACTIVA |
| 57 | GET | `/admin/analytics` | admin | `AdminController::analytics` | vistas/enlaces | ACTIVA |
| 58 | GET | `/admin/configuracion` | admin | `AdminConfigurationController::index` | vistas/enlaces | ACTIVA |
| 59 | GET | `/admin/configuracion/horarios` | admin | `AdminConfigurationController::hours` | vistas/enlaces | ACTIVA |
| 60 | POST | `/admin/configuracion/horarios` | admin | `AdminConfigurationController::guardarHorarios` | vistas/enlaces | ACTIVA |
| 61 | POST | `/admin/configuracion/horarios/excepciones/guardar` | admin | `AdminConfigurationController::guardarExcepcion` | vistas/enlaces | ACTIVA |
| 62 | POST | `/admin/configuracion/horarios/excepciones/estado` | admin | `AdminConfigurationController::cambiarEstadoExcepcion` | vistas/enlaces | ACTIVA |
| 63 | POST | `/admin/configuracion/horarios/excepciones/eliminar` | admin | `AdminConfigurationController::eliminarExcepcion` | vistas/enlaces | ACTIVA |
| 64 | GET | `/api/configuracion/horarios/semanales` | admin | `AdminConfigurationController::apiObtenerHorarios` | JS/HTTP | INTERNA |
| 65 | POST | `/api/configuracion/horarios/semanales` | admin | `AdminConfigurationController::apiGuardarHorarios` | JS/HTTP | INTERNA |
| 66 | POST | `/api/configuracion/horarios/especiales` | admin | `AdminConfigurationController::apiGuardarEspecial` | JS/HTTP | INTERNA |
| 67 | POST | `/api/configuracion/horarios/excepciones` | admin | `AdminConfigurationController::apiGuardarExcepcion` | JS/HTTP | INTERNA |
| 68 | DELETE | `/api/configuracion/horarios/excepciones` | admin | `AdminConfigurationController::apiEliminarExcepcion` | JS/HTTP | INTERNA |
| 69 | GET | `/admin/configuracion/anuncio` | admin | `AdminConfigurationController::announcement` | vistas/enlaces | ACTIVA |
| 70 | POST | `/admin/configuracion/anuncio` | admin | `AdminConfigurationController::guardarAnuncio` | vistas/enlaces | ACTIVA |
| 71 | GET | `/admin/configuracion/reportes` | admin | `AdminConfigurationController::reports` | vistas/enlaces | ACTIVA |
| 72 | POST | `/admin/configuracion/reportes/estado` | admin | `AdminConfigurationController::reportStatus` | vistas/enlaces | ACTIVA |
| 74 | POST | `/admin/api/reportes` | admin | `AdminConfigurationController::crearReporte` | JS/HTTP | INTERNA |
| 77 | GET | `/admin/menu` | admin | `AdminMenuController::index` | vistas/enlaces | ACTIVA |
| 78 | GET | `/admin/menu/pdf` | admin | `AdminMenuController::pdf` | vistas/enlaces | ACTIVA |
| 79 | GET | `/admin/menu/create` | admin | `AdminMenuController::create` | vistas/enlaces | ACTIVA |
| 80 | POST | `/admin/menu/create` | admin | `AdminMenuController::create` | vistas/enlaces | ACTIVA |
| 81 | GET | `/admin/menu/edit` | admin | `AdminMenuController::edit` | vistas/enlaces | ACTIVA |
| 82 | POST | `/admin/menu/edit` | admin | `AdminMenuController::edit` | vistas/enlaces | ACTIVA |
| 83 | POST | `/admin/menu/delete` | admin | `AdminMenuController::delete` | vistas/enlaces | ACTIVA |
| 84 | POST | `/admin/menu/toggle` | admin | `AdminMenuController::toggle` | vistas/enlaces | ACTIVA |
| 85 | GET | `/admin/menu/categorias` | admin | `AdminMenuController::categories` | vistas/enlaces | ACTIVA |
| 86 | POST | `/admin/menu/categorias/create` | admin | `AdminMenuController::categoryCreate` | vistas/enlaces | ACTIVA |
| 87 | GET | `/admin/menu/categorias/edit` | admin | `AdminMenuController::categoryEdit` | vistas/enlaces | ACTIVA |
| 88 | POST | `/admin/menu/categorias/edit` | admin | `AdminMenuController::categoryEdit` | vistas/enlaces | ACTIVA |
| 89 | POST | `/admin/menu/categorias/delete` | admin | `AdminMenuController::categoryDelete` | vistas/enlaces | ACTIVA |
| 90 | GET | `/admin/punto-de-venta` | admin | `AdminPuntoVentaController::index` | vistas/enlaces | ACTIVA |
| 91 | GET | `/admin/area` | admin | `AdminAreaController::index` | vistas/enlaces | ACTIVA |
| 92 | GET | `/admin/area/cafe` | admin | `AdminAreaController::cafe` | vistas/enlaces | ACTIVA |
| 93 | GET | `/admin/area/jugos` | admin | `AdminAreaController::jugos` | vistas/enlaces | ACTIVA |
| 94 | GET | `/admin/area/cocina` | admin | `AdminAreaController::cocina` | vistas/enlaces | ACTIVA |
| 95 | GET | `/admin/area/horno` | admin | `AdminAreaController::horno` | vistas/enlaces | ACTIVA |
| 96 | GET | `/admin/api/area-items` | admin | `AdminAreaController::areaItems` | JS/HTTP | INTERNA |
| 97 | POST | `/admin/api/advance-item` | admin | `AdminAreaController::advanceItem` | JS/HTTP | INTERNA |
| 98 | POST | `/admin/api/rollback-item` | admin | `AdminAreaController::rollbackItem` | JS/HTTP | INTERNA |
| 99 | GET | `/admin/reservations` | admin | `AdminReservacionController::index` | vistas/enlaces | ACTIVA |
| 100 | GET | `/admin/reservations/create` | admin | `AdminReservacionController::create` | vistas/enlaces | ACTIVA |
| 101 | POST | `/admin/reservations/create` | admin | `AdminReservacionController::store` | vistas/enlaces | ACTIVA |
| 102 | GET | `/admin/reservations/operation` | admin | `ReservacionOperacionController::operation` | vistas/enlaces | ACTIVA |
| 103 | GET | `/admin/reservations/show` | admin | `AdminReservacionController::show` | vistas/enlaces | ACTIVA |
| 104 | GET | `/admin/api/reservations/disponibilidad` | admin | `AdminReservacionController::disponibilidad` | JS/HTTP | INTERNA |
| 105 | POST | `/admin/reservations/update` | admin | `AdminReservacionController::update` | vistas/enlaces | ACTIVA |
| 106 | POST | `/admin/reservations/status` | admin | `AdminReservacionController::status` | vistas/enlaces | ACTIVA |
| 107 | POST | `/admin/reservations/reassign` | admin | `AdminReservacionController::reasignarAutomaticamente` | vistas/enlaces | ACTIVA |
| 108 | GET | `/admin/reservations/development-tools` | admin | `ReservacionMantenimientoController::index` | herramienta local admin | DESARROLLO |
| 109 | POST | `/admin/reservations/development-tools/process-expired` | admin | `ReservacionMantenimientoController::procesarPendientes` | herramienta local admin | DESARROLLO |
| 110 | GET | `/admin/api/reservations/operation` | admin | `ReservacionOperacionController::operationData` | JS/HTTP | INTERNA |
| 111 | POST | `/admin/api/reservations/operation/assign-tables` | admin | `ReservacionOperacionController::apiAssignTables` | JS/HTTP | INTERNA |
| 112 | POST | `/admin/api/reservations/operation/clear-tables` | admin | `ReservacionOperacionController::apiClearTables` | JS/HTTP | INTERNA |
| 113 | POST | `/admin/api/reservations/operation/reassign` | admin | `ReservacionOperacionController::apiReasignarAutomaticamente` | JS/HTTP | INTERNA |
| 114 | POST | `/admin/api/reservations/operation/update-comment` | admin | `ReservacionOperacionController::apiUpdateComment` | JS/HTTP | INTERNA |
| 115 | POST | `/admin/api/reservations/operation/status` | admin | `ReservacionOperacionController::apiStatus` | JS/HTTP | INTERNA |
| 116 | GET | `/admin/feedback` | admin | `AdminController::feedback` | vistas/enlaces | ACTIVA |
| 117 | POST | `/admin/feedback/refresh` | admin | `AdminController::feedbackRefresh` | vistas/enlaces | ACTIVA |
| 118 | GET | `/admin/api/feedback-areas` | admin | `AdminController::feedbackAreas` | JS/HTTP | INTERNA |
| 119 | GET | `/admin/tickets` | admin | `AdminController::tickets` | vistas/enlaces | ACTIVA |
| 122 | GET | `/admin/inventario` | admin | `AdminInventarioController::index` | vistas/enlaces | ACTIVA |
| 123 | GET | `/admin/inventario/create` | admin | `AdminInventarioController::create` | vistas/enlaces | ACTIVA |
| 124 | POST | `/admin/inventario/create` | admin | `AdminInventarioController::create` | vistas/enlaces | ACTIVA |
| 125 | GET | `/admin/inventario/edit` | admin | `AdminInventarioController::edit` | vistas/enlaces | ACTIVA |
| 126 | POST | `/admin/inventario/edit` | admin | `AdminInventarioController::edit` | vistas/enlaces | ACTIVA |
| 127 | POST | `/admin/inventario/delete` | admin | `AdminInventarioController::delete` | vistas/enlaces | ACTIVA |
| 128 | POST | `/admin/inventario/ajustar` | admin | `AdminInventarioController::ajustar` | vistas/enlaces | ACTIVA |
| 129 | POST | `/admin/inventario/entrada` | admin | `AdminInventarioController::entrada` | vistas/enlaces | ACTIVA |
| 133 | GET | `/admin/recetas` | admin | `AdminRecetasController::index` | vistas/enlaces | ACTIVA |
| 134 | GET | `/admin/recetas/editar` | admin | `AdminRecetasController::receta` | vistas/enlaces | ACTIVA |
| 135 | POST | `/admin/recetas/editar` | admin | `AdminRecetasController::receta` | vistas/enlaces | ACTIVA |
| 136 | GET | `/admin/recetas/subrecetas` | admin | `AdminRecetasController::subrecetas` | vistas/enlaces | ACTIVA |
| 137 | GET | `/admin/recetas/subrecetas/create` | admin | `AdminRecetasController::subrecetaCreate` | vistas/enlaces | ACTIVA |
| 138 | POST | `/admin/recetas/subrecetas/create` | admin | `AdminRecetasController::subrecetaCreate` | vistas/enlaces | ACTIVA |
| 139 | GET | `/admin/recetas/subrecetas/edit` | admin | `AdminRecetasController::subrecetaEdit` | vistas/enlaces | ACTIVA |
| 140 | POST | `/admin/recetas/subrecetas/edit` | admin | `AdminRecetasController::subrecetaEdit` | vistas/enlaces | ACTIVA |
| 141 | POST | `/admin/recetas/subrecetas/delete` | admin | `AdminRecetasController::subrecetaDelete` | vistas/enlaces | ACTIVA |
| 157 | GET | `/admin/menu/items` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 158 | GET | `/admin/menu/items/create` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 159 | GET | `/admin/menu/items/edit` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 160 | GET | `/admin/menu/items/pdf` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 161 | GET | `/admin/menu/categories` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 162 | GET | `/admin/menu/categories/create` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 163 | GET | `/admin/menu/categories/edit` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 164 | GET | `/admin/productos` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 165 | GET | `/admin/productos/create` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 166 | GET | `/admin/productos/edit` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 167 | GET | `/admin/productos/subrecetas` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 168 | GET | `/admin/productos/subrecetas/create` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 169 | GET | `/admin/productos/subrecetas/edit` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 172 | GET | `/admin/finanzas` | admin | `AdminFinanzasController::index` | vistas/enlaces | ACTIVA |
| 173 | POST | `/admin/finanzas/gasto/guardar` | admin | `AdminFinanzasController::guardarGasto` | vistas/enlaces | ACTIVA |
| 174 | POST | `/admin/finanzas/gasto/eliminar` | admin | `AdminFinanzasController::eliminarGasto` | vistas/enlaces | ACTIVA |
| 176 | GET | `/admin/printers` | admin | `AdminPrintersController::index` | vistas/enlaces | ACTIVA |
| 177 | GET | `/admin/printers/create` | admin | `AdminPrintersController::create` | vistas/enlaces | ACTIVA |
| 178 | POST | `/admin/printers/create` | admin | `AdminPrintersController::create` | vistas/enlaces | ACTIVA |
| 179 | GET | `/admin/printers/edit` | admin | `AdminPrintersController::edit` | vistas/enlaces | ACTIVA |
| 180 | POST | `/admin/printers/edit` | admin | `AdminPrintersController::edit` | vistas/enlaces | ACTIVA |
| 181 | POST | `/admin/printers/delete` | admin | `AdminPrintersController::delete` | vistas/enlaces | ACTIVA |
| 182 | POST | `/admin/printers/test` | admin | `AdminPrintersController::test` | vistas/enlaces | ACTIVA |
| 184 | GET | `/admin/usuarios` | admin | `AdminUsersController::index` | vistas/enlaces | ACTIVA |
| 185 | GET | `/admin/usuarios/create` | admin | `AdminUsersController::userCreate` | vistas/enlaces | ACTIVA |
| 186 | POST | `/admin/usuarios/create` | admin | `AdminUsersController::userCreate` | vistas/enlaces | ACTIVA |
| 187 | GET | `/admin/usuarios/edit` | admin | `AdminUsersController::userEdit` | vistas/enlaces | ACTIVA |
| 188 | POST | `/admin/usuarios/edit` | admin | `AdminUsersController::userEdit` | vistas/enlaces | ACTIVA |
| 190 | GET | `/admin/usuarios/cambiar-credencial` | admin | `AdminUsersController::cambiarCredencial` | vistas/enlaces | ACTIVA |
| 191 | POST | `/admin/usuarios/cambiar-credencial` | admin | `AdminUsersController::cambiarCredencial` | vistas/enlaces | ACTIVA |
| 193 | GET | `/admin/usuarios/change-password` | admin | `redir301` | marcadores/compatibilidad | HISTÓRICA |
| 194 | POST | `/admin/usuarios/deactivate` | admin | `AdminUsersController::deactivate` | vistas/enlaces | ACTIVA |
| 195 | POST | `/admin/usuarios/activate` | admin | `AdminUsersController::activate` | vistas/enlaces | ACTIVA |
| 196 | POST | `/admin/usuarios/delete` | admin | `AdminUsersController::delete` | vistas/enlaces | ACTIVA |
| 201 | GET | `/punto-de-venta` | POS/admin | `PuntoVentaController::index` | vistas/enlaces | ACTIVA |
| 202 | GET | `/api/punto-de-venta` | POS/admin | `PuntoVentaController::api` | JS/HTTP | INTERNA |
| 203 | GET | `/api/productos` | POS/admin | `PuntoVentaController::productos` | JS/HTTP | INTERNA |
| 204 | GET | `/api/punto-de-venta/reservaciones` | POS/admin | `PuntoVentaController::reservaciones` | JS/HTTP | INTERNA |
| 205 | GET | `/api/punto-de-venta/mesa-contexto` | POS/admin | `PuntoVentaController::mesaContexto` | JS/HTTP | INTERNA |
| 206 | POST | `/api/punto-de-venta/reservaciones/comenzar` | POS/admin | `PuntoVentaController::comenzarReservacion` | JS/HTTP | INTERNA |
| 207 | POST | `/api/punto-de-venta/reservaciones/cancelar` | POS/admin | `PuntoVentaController::cancelarReservacion` | JS/HTTP | INTERNA |
| 208 | POST | `/api/punto-de-venta/reservaciones/no-show` | POS/admin | `PuntoVentaController::noShowReservacion` | JS/HTTP | INTERNA |
| 209 | POST | `/api/abrir-ticket` | POS/admin | `PuntoVentaController::abrirTicket` | JS/HTTP | INTERNA |
| 210 | POST | `/api/cerrar-ticket` | POS/admin | `PuntoVentaController::cerrarTicket` | JS/HTTP | INTERNA |
| 211 | POST | `/api/enviar-comanda` | POS/admin | `PuntoVentaController::enviarComanda` | JS/HTTP | INTERNA |
| 212 | GET | `/api/ticket-items` | POS/admin | `PuntoVentaController::ticketItems` | JS/HTTP | INTERNA |
| 213 | GET | `/api/corte-caja` | POS/admin | `PuntoVentaController::corteCaja` | JS/HTTP | INTERNA |
| 214 | POST | `/api/entregar-item` | POS/admin | `PuntoVentaController::entregarItem` | JS/HTTP | INTERNA |
| 216 | POST | `/api/cancelar-item` | POS/admin | `PuntoVentaController::cancelarItem` | JS/HTTP | INTERNA |
| 217 | POST | `/api/actualizar-ticket` | POS/admin | `PuntoVentaController::actualizarTicket` | JS/HTTP | INTERNA |
| 218 | POST | `/api/sugerencias` | público | `PuntoVentaController::sugerencias` | JS/HTTP | INTERNA |
| 220 | GET | `/area` | cocina/admin | `AreaController::index` | vistas/enlaces | ACTIVA |
| 221 | GET | `/area/cafe` | cocina/admin | `AreaController::cafe` | vistas/enlaces | ACTIVA |
| 222 | GET | `/area/jugos` | cocina/admin | `AreaController::jugos` | vistas/enlaces | ACTIVA |
| 223 | GET | `/area/cocina` | cocina/admin | `AreaController::cocina` | vistas/enlaces | ACTIVA |
| 224 | GET | `/area/horno` | cocina/admin | `AreaController::horno` | vistas/enlaces | ACTIVA |
| 225 | GET | `/api/area-items` | cocina/admin | `AreaController::areaItems` | JS/HTTP | INTERNA |
| 226 | POST | `/api/avanzar-item` | cocina/admin | `AreaController::avanzarItem` | JS/HTTP | INTERNA |
| 227 | POST | `/api/retroceder-item` | cocina/admin | `AreaController::retrocederItem` | JS/HTTP | INTERNA |
| 230 | GET | `/feedback` | público | `FeedbackController::index` | vistas/enlaces | ACTIVA |
| 231 | POST | `/api/feedback` | público | `FeedbackController::guardar` | JS/HTTP | INTERNA |
| 232 | POST | `/api/feedback-n8n` | público | `FeedbackController::recibirN8n` | JS/HTTP | INTERNA |
| 235 | GET | `/login` | público | `AuthController::login` | vistas/enlaces | ACTIVA |
| 236 | POST | `/login` | público | `AuthController::login` | vistas/enlaces | ACTIVA |
| 237 | GET | `/admin/login` | admin | `AuthController::loginAdmin` | vistas/enlaces | ACTIVA |
| 238 | POST | `/admin/login` | admin | `AuthController::loginAdmin` | vistas/enlaces | ACTIVA |
| 239 | POST | `/logout` | público | `AuthController::logout` | vistas/enlaces | ACTIVA |
| 242 | GET | `/registro` | público | `AuthController::registro` | vistas/enlaces | ACTIVA |
| 243 | POST | `/registro` | público | `AuthController::registro` | vistas/enlaces | ACTIVA |
| 246 | GET | `/olvide` | público | `AuthController::olvide` | vistas/enlaces | ACTIVA |
| 247 | POST | `/olvide` | público | `AuthController::olvide` | vistas/enlaces | ACTIVA |
| 250 | GET | `/reestablecer` | público | `AuthController::reestablecer` | vistas/enlaces | ACTIVA |
| 251 | POST | `/reestablecer` | público | `AuthController::reestablecer` | vistas/enlaces | ACTIVA |
| 254 | GET | `/mensaje` | público | `AuthController::mensaje` | vistas/enlaces | ACTIVA |
| 255 | GET | `/confirmar-cuenta` | público | `AuthController::confirmar` | vistas/enlaces | ACTIVA |
| 259 | GET | `/menu` | público | `MenuController::index` | vistas/enlaces | ACTIVA |
| 260 | GET | `/menu/pdf` | público | `MenuController::pdf` | vistas/enlaces | ACTIVA |

## Residuos aceptados

No se eliminan los componentes visuales de POS, formularios administrativos ni
redirecciones generales de menú: tienen consumidores reales o compatibilidad
aprobada. Las menciones a códigos retirados en los inventarios de Etapas 3–6
son historia de migración, no contratos emitidos por runtime.

## Cierre

No quedaron hallazgos de reservaciones con clasificación
`REQUIERE_VALIDACION`. Cualquier cambio posterior debe actualizar primero
`reservaciones_fuente_de_verdad.md`, sus pruebas y este inventario si cambia
la topología del módulo.
