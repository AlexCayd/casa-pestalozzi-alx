# Etapa 11.8 — Inventario de rutas, consumidores y limpieza para Etapa 12

Fecha de corte: 2026-08-04. Este inventario acompaña la auditoría de conformidad. No elimina rutas, archivos, aliases, fixtures ni código residual.

## Cadena de autoridad usada

1. `reservaciones_fuente_de_verdad.md`.
2. `database/ddl.sql` y `database/dml.sql` como esquema realmente instalable.
3. Contratos `pos-reservacion.v1` y payloads de servicios.
4. Servicios de dominio y modelos.
5. Pruebas.
6. Reportes históricos.
7. Vistas, JavaScript y bundles compilados.

La referencia indicada en algunos documentos como `database/database.sql` no existe en este checkout. La instalación limpia usa `database/ddl.sql` seguido de `database/dml.sql`.

## Inventario de rutas públicas

| Método y ruta | Auth | CSRF | Entrada principal | Salida/consumidor | Estado |
|---|---|---|---|---|---|
| `GET /api/reservation-schedules` | Pública | No | `fecha`/parámetros de horario | JSON de horarios; `ReservacionController::horarios` | Alias compatible; revisar retiro |
| `GET /api/reservaciones/disponibilidad` | Pública | No | fecha, hora, personas | JSON binario público sin capacidad interna | Canónica |
| `GET /api/operacion/horario-efectivo` | Pública | No | fecha/operación | JSON de horario efectivo | Alias de diagnóstico/compatibilidad |
| `POST /api/reservaciones/retencion` | Pública | Sí, `csrf_token` | nombre, contacto, fecha, hora, personas, nota, `request_token` | hold, token y datos públicos de OTP | Canónica; transaccional |
| `POST /api/reservaciones/crear` | Sesión pública para confirmada; retención si no coincide | Condicional: sólo si hay `request_token` | mismos datos de creación | reserva confirmada o retención | Divergencia F-03 |
| `POST /api/reservaciones/modificar` | Sesión pública verificada | Sí | reserva original, fecha/hora/personas/nota, token | original + reemplazo pendiente + hold | Canónica |
| `POST /api/reservaciones/confirmar-modificacion` | Sesión pública verificada | Sí | `request_token` | cambio confirmado o error operativo | F-01: falta revalidación final |
| `POST /api/reservaciones/cancelar` | Sesión pública verificada | Sí | id y motivo opcional | cancelación lógica/idempotencia | Canónica |
| `POST /api/reservaciones/contacto/codigo` | Pública | Sí | tipo/contacto o token de hold | solicitud/reenvío OTP | Alias de modificación devuelve compatibilidad; P-01 |
| `POST /api/reservaciones/contacto/verificar` | Pública | Sí | tipo, contacto, código o token de hold | sesión pública/confirmación de hold | Canónica |
| `GET /api/reservaciones/mis-reservaciones` | Sesión pública verificada | No | sesión | reservas públicas y cambios pendientes | D-01: condición de visibilidad |
| `POST /api/reservaciones/contacto/logout` | Sesión pública | Sí | `csrf_token` | cierre de sesión pública | Canónica |

La entrada HTTP se normaliza en `controllers/ReservacionController.php:403-435`; las mutaciones públicas usan JSON o `$_POST` según `Content-Type`.

## Inventario de rutas administrativas

| Método y ruta | Auth/CSRF | Entrada/salida | Consumidor | Estado |
|---|---|---|---|---|
| `GET /admin/reservations`, `/create`, `/show`, `/operation` | admin por `Auth::proteger` | query/HTML | vistas admin, operación y mapa | Canónicas de lectura |
| `GET /admin/api/reservations/disponibilidad` | admin | filtros de fecha/hora/personas → JSON | formulario admin | Canónica |
| `POST /admin/reservations/create` | admin + `AdminCsrfService` | formulario completo → redirect/HTML | `ReservacionAdministrativaService` | Canónica |
| `POST /admin/reservations/update` | admin + `AdminCsrfService` | id, fecha, hora, personas, campos administrativos | fachada administrativa | Canónica |
| `POST /admin/reservations/status` | admin + `AdminCsrfService` | id, estado, motivo | transición administrativa | Canónica |
| `POST /admin/reservations/reassign` | admin + `AdminCsrfService` | id, confirmaciones | asignación automática | Canónica |
| `GET /admin/api/reservations/operation` | admin | fecha/hora/filtros → `pos-reservacion.v1` operativo | `ReservacionOperacionController` | Canónica compartida |
| `POST /admin/api/reservations/operation/assign-tables` | admin + `AdminCsrfService` | id, mesas, snapshot, versión, tickets aceptados | `ReservacionMapaAdministrativaService` | Canónica con versión |
| `POST /admin/api/reservations/operation/clear-tables` | admin + `AdminCsrfService` | id, snapshot, confirmación | liberación manual | Canónica con versión |
| `POST /admin/api/reservations/operation/reassign` | admin + `AdminCsrfService` | id y confirmaciones | reasignación automática | Canónica |
| `POST /admin/api/reservations/operation/update-comment` | admin + `AdminCsrfService` | id, comentario | comentario administrativo | Canónica |
| `POST /admin/api/reservations/operation/status` | admin + `AdminCsrfService` | id, estado, motivo | estado operativo | Canónica |
| `POST /admin/reservations/operation/assign-tables` | admin + validación de operación | formulario legado de mesas | consumidores HTML antiguos | Compatibilidad |
| `POST /admin/reservations/operation/update-comment` | admin + validación de operación | formulario legado | consumidores HTML antiguos | Compatibilidad |
| `GET /admin/reservations/development-tools` | admin + `APP_ENV=development` | ninguno | vista de mantenimiento | Herramienta temporal |
| `POST /admin/reservations/development-tools/process-expired` | admin + ambiente; método POST | confirmación | materializa pendientes vencidas | Compatible con no eliminación |
| `POST /admin/reservations/development-tools/cleanup-preview` | admin + ambiente; método POST | fechas, prefijo, estados | preview destructivo | F-04; no tiene `AdminCsrfService` explícito |
| `POST /admin/reservations/development-tools/cleanup` | admin + ambiente; método POST | filtros + confirmaciones | DELETE físico de reservaciones | F-04; no tiene `AdminCsrfService` explícito |

La guardia global está en `public/index.php:35-36` y `classes/Auth.php:103-143`. Las rutas administrativas de reserva ordinarias usan `AdminCsrfService` según `controllers/AdminReservacionController.php:127-138, 269-273, 397-424` y `controllers/ReservacionOperacionController.php:343-466`.

## Inventario de rutas POS y heredadas

| Método y ruta | Auth | CSRF | Entrada/salida | Consumidor | Estado |
|---|---|---|---|---|---|
| `GET /api/punto-de-venta`, `/api/punto-de-venta/reservaciones` | personal autenticado | No aplica | fecha → mapa/lista y `pos-reservacion.v1` | POS | Canónica de lectura |
| `GET /api/punto-de-venta/mesa-contexto` | personal autenticado | No aplica | `mesa_id` → ticket, reserva actual/próxima, advertencia | modal POS | Canónica informativa; usa T-02 |
| `POST /api/punto-de-venta/reservaciones/comenzar` | personal autenticado | No | id, mesero → ticket y mesas | POS | F-05 de superficie; dominio canónico |
| `POST /api/punto-de-venta/reservaciones/cancelar` | personal autenticado | No | id, motivo → cancelación | POS | F-05 de superficie; dominio canónico |
| `POST /api/punto-de-venta/reservaciones/no-show` | personal autenticado | No | id, motivo, `override` heredado → no-show | POS | F-05; `override` no habilita excepción |
| `POST /api/abrir-ticket` | personal autenticado | No | id de reserva o mesas/comensales walk-in | shell POS legado → `comenzar`/`abrirWalkIn` | F-02 para walk-in; F-05 CSRF |
| `POST /api/liberar-reservacion` | personal autenticado | No | id, motivo | shell POS legado | Compatibilidad; mutación lógica |
| `POST /api/cerrar-ticket` | personal autenticado | No | ticket, pago, propina, pagos | POS | Compatibilidad; cierre transaccional |
| `GET /api/ticket-items`, `POST /api/enviar-comanda`, `POST /api/actualizar-ticket` | personal autenticado | No | ticket/items | POS general | Fuera del dominio de reserva, misma superficie CSRF |

`classes/Auth.php:19-38` lista las APIs del personal y comprueba sesión/rol, pero `controllers/PuntoVentaController.php` no invoca `AdminCsrfService` para las mutaciones POS. La condición queda registrada como F-05; no se modificó el controlador.

## Grafo de consumidores canónicos

```text
ReservacionConfig
  ├─ HorarioReservacionService ─┐
  ├─ ReservacionVigenciaService ├─ DisponibilidadReservacionService
  └─ estados/labels/constants   │        ├─ landing pública
                                │        ├─ admin
                                │        └─ POS/mapas

OcupacionMesasService ──────────┼─ AsignacionMesasService
  ├─ reservas + holds            │    ├─ ReservacionPublicaService
  ├─ tickets/ticket_mesas        │    ├─ ReservacionAdministrativaService
  └─ proyección futura           │    └─ PuntoVentaReservacionService

PosReservacionQueryService ─ PosReservacionSerializer ─ pos-reservacion.v1
MesaEstadoService ───────────── POS y mapa administrativo
ReservacionMapaAdministrativaService ─ snapshot/version/conflicto
```

No se identificó un segundo lector completo de mapa activo. Sí existe la rama de compatibilidad de ocupación en `AsignacionMesasService.php:76-105` y la consulta informativa independiente de `contextoMesa()`, ambas listadas como deuda técnica.

## Resultado posterior a Etapa 12

Este anexo actualiza el inventario anterior sin reescribir su fotografía histórica.

| Deuda | Resultado Etapa 12 |
|---|---|
| T-01 aliases/constants | **RESUELTA**. Los consumidores activos usan los nombres canónicos de `ReservacionConfig`. |
| T-02 margen POS | **RESUELTA**. `liberacion_estimada` usa duración + retraso; el margen queda sólo como objetivo informativo. |
| T-03 ocupación forzada | **RESUELTA**. Se retiró el parámetro y la rama sin consumidores. |
| T-04 rutas legacy | **RESUELTA**. Se retiraron horario efectivo legacy, liberar reservación y duplicados administrativos. |
| P-01 segundo OTP | **RESUELTA**. Se eliminó `reenviarOtpModificacion`; la modificación usa sesión verificada. |
| R-01 código inalcanzable | **RESUELTA**. La fachada delega y no conserva una implementación duplicada. |
| R-02 código inalcanzable | **RESUELTA**. El normalizador retorna exclusivamente la ruta canónica. |
| R-03 vocabulario histórico | **CLASIFICADA**. Permanece sólo en pruebas de migración etiquetadas `PRUEBA_DE_MIGRACION`. |
| N-01 validación de navegador | **NO VERIFICABLE en este corte**. Requiere servidor local/MySQL y sesión operativa. |

La decisión pasa a **Etapa 12 ejecutada y cerrada técnicamente**, con validación funcional de base pendiente únicamente cuando MySQL/XAMPP esté disponible.

## Bundles y fuentes

`gulpfile.js` define estos consumidores compilados:

| Fuente | Bundle/destino | Estado |
|---|---|---|
| `src/js/**/*.js` público | `public/build/js/bundle.min.js`, `assets/js/bundle.min.js` | Compilación PASS |
| módulos admin/mapa/POS | `public/build/js/admin/map.js` | Compilación PASS |
| formulario admin | `public/build/js/admin/reservation-form.js` | Compilación PASS |
| operación admin | `public/build/js/admin/reservation-operation.js` | Compilación PASS |
| `src/scss/operation/reservations.scss` | `public/build/css/operation/reservations.css` | Compilación PASS |

No se observaron cambios de fuente/bundle después de dos builds; `git status --short` quedó limpio antes de agregar estos tres reportes.

## Búsqueda global y residuales

La búsqueda se ejecutó excluyendo `vendor`, `node_modules`, bundles minificados, assets generados, backups y reportes históricos cuando se evaluó código activo.

| Término | Resultado de interés |
|---|---|
| `reemplazada` | Presente en fuente, servicios, estados, payloads y pruebas; conforme y esperado |
| `Versión anterior` | Sólo fuente de verdad y comentario no relacionado de `MenuPdf`; no aparece en UI activa de reservas |
| `request_token` | Consumido ampliamente por creación/reemplazo/OTP/idempotencia; único en DDL |
| `horarios_mapa` / `horarios_reservables` | Separación explícita en controllers y servicios; conforme |
| `request_fingerprint` | Sólo fuente y prueba histórica de campos retirados; no código activo/DDL |
| `created_by`, `last_modified_by`, `last_modified_source`, `last_change_reason` | Sólo fuente/prueba histórica; no DDL activo |
| `llego` | Sólo migradores, fixtures y aserciones históricas; no enum ni transición activa |
| `arrived_at`, `confirmed_at`, `completed_at` | Sólo lista histórica de campos retirados; no DDL activo |
| `Guardar cambios` | En formularios admin generales; ausencia verificada en editor público por `accessibility-contract.test.js` |
| `override` | Parámetro heredado de no-show; el servicio ya no habilita excepciones |

### Residual R-01

`ReservacionService::actualizarDatos()` delega inmediatamente a `ReservacionAdministrativaService::actualizar()` y conserva debajo una implementación completa inalcanzable. No se eliminó porque la auditoría no autoriza limpieza funcional.

### Residual R-02

`MesaEstadoService::normalizarMesas()` delega inmediatamente a `normalizarMesasCanonicas()` y conserva debajo una rama histórica inalcanzable. Los consumidores actuales usan el retorno canónico.

### Residual R-03

Los migradores y pruebas antiguas contienen vocabulario de reconstrucción (`llego`, `status_changed_at`) para explicar conversión histórica. No es evidencia de columnas o estados activos, pero debe etiquetarse para que búsquedas futuras no lo confundan con soporte vigente.

## Estado de reportes históricos

- El reporte requerido por nombre para Etapa 8 no existe con ese nombre; el reporte de reconstrucción administrativa sí existe y se trató como evidencia.
- Los reportes de Etapa 7, 7.5, 9.5, 10, 11.5, 11.6 y 11.7 se usaron como evidencia secundaria. No reemplazan la fuente de verdad ni los resultados ejecutados en este corte.
- Las afirmaciones históricas de pruebas visuales, 200%, Network, lector de pantalla y XAMPP se consideran `NO_VERIFICABLE` si no hubo ejecución reproducible en esta auditoría.

## Limpieza candidata para Etapa 12, sin ejecución

Orden recomendado:

1. Resolver F-01 y F-02 mediante una sola revalidación de ocupación y asignación bajo transacción.
2. Resolver F-03 y F-05 con CSRF obligatorio o mecanismo de solicitud equivalente para las mutaciones.
3. Decidir formalmente F-04 y eliminar la interfaz destructiva sólo cuando exista una alternativa segura para fixtures.
4. Resolver D-01, D-02, D-03, D-04 y D-05 en documentación/pruebas antes de cambiar consumidores.
5. Ejecutar la matriz manual N-01 en navegador y XAMPP.
6. Retirar R-01, R-02, R-03 y consolidar T-01/T-03 sólo después de un grafo de consumidores y regresión completa.
7. Revisar aliases de compatibilidad T-04/P-01 y publicar fecha de retiro.

## Decisión

**Etapa 12: NO INICIAR.** Este inventario sirve como preparación y no autoriza eliminación, refactor, cambio de contrato, modificación del esquema ni retiro de rutas en la etapa auditada.
