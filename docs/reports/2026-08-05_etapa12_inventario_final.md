# Inventario final — Etapa 12

## Alcance

Inventario de los elementos consolidados o retirados durante la limpieza final de reservaciones. No incluye cambios de esquema ni commit.

## Fuentes canónicas

| Área | Fuente |
|---|---|
| Reglas y constantes | `reservaciones_fuente_de_verdad.md`, `services/ReservacionConfig.php` |
| Disponibilidad | `services/DisponibilidadReservacionService.php` |
| Ocupación y asignación | `services/OcupacionMesasService.php`, `services/AsignacionMesasService.php` |
| Vigencia/estados | `services/ReservacionVigenciaService.php` |
| Público | `services/ReservacionPublicaService.php`, `controllers/ReservacionController.php` |
| Administración | `services/ReservacionAdministrativaService.php`, `controllers/ReservacionOperacionController.php` |
| Fachada | `services/ReservacionService.php` |
| POS/mapa | `services/PosReservacionQueryService.php`, `services/PosReservacionSerializer.php`, `services/MesaEstadoService.php` |
| POS transaccional | `services/PuntoVentaReservacionService.php`, `controllers/PuntoVentaController.php` |
| Rutas | `public/index.php` |

## Alias y ramas retiradas

| Elemento | Clasificación | Evidencia |
|---|---|---|
| `RESERVATION_HOLD_MINUTES` | Retirado | Sustituido por `VIGENCIA_HOLD_MINUTOS`. |
| `MAX_ACTIVE_RESERVATIONS` | Retirado | Sustituido por `MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO`. |
| `MAX_PUBLIC_GUESTS` | Retirado | Sustituido por `MAX_COMENSALES_PUBLICO`. |
| `MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA` | Retirado | Sustituido por `AVISO_RESERVACION_PROXIMA_MINUTOS`. |
| `TOLERANCIA_RESERVACION_MINUTOS` | Retirado | Sustituido por `TOLERANCIA_LLEGADA_MINUTOS`. |
| `DURACION_SERVICIO_ESTIMADA_MINUTOS` | Retirado | Sustituido por `DURACION_ESTIMADA_TICKET_MINUTOS`. |
| aliases de combinaciones públicas | Retirados | La configuración canónica conserva una única lista. |
| `forzarOcupacionFisica` | Retirado | Se eliminó parámetro, rama y combinador paralelo. |
| `reenviarOtpModificacion` | Retirado | Modificación basada en sesión verificada. |

## Rutas retiradas

| Ruta | Motivo |
|---|---|
| `/api/operacion/horario-efectivo` | Sin consumidor activo; duplicaba resolución de horario. |
| `/api/liberar-reservacion` | Sin consumidor activo; sustituida por el contrato POS canónico. |
| `/admin/reservations/operation/assign-tables` | Alias sin consumidor; queda `/admin/api/reservations/operation/assign-tables`. |
| `/admin/reservations/operation/update-comment` | Alias sin consumidor; queda `/admin/api/reservations/operation/update-comment`. |

## Rutas conservadas por compatibilidad

| Ruta/contrato | Razón |
|---|---|
| `/api/reservation-schedules` | Consumida por el selector público y vistas compartidas. |
| `/api/abrir-ticket`, `/api/cerrar-ticket` | Contrato general POS y walk-in. |
| `/api/punto-de-venta/mesa-contexto` | Consulta informativa activa del contexto de mesa. |
| `ReservacionService` | Fachada usada por consumidores administrativos existentes. |

## Búsqueda residual

La búsqueda en código activo, excluyendo documentación histórica, vendor, node_modules, bundles y assets generados, quedó sin coincidencias para:

```text
RESERVATION_HOLD_MINUTES
MAX_ACTIVE_RESERVATIONS
MAX_PUBLIC_GUESTS
MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA
TOLERANCIA_RESERVACION_MINUTOS
DURACION_SERVICIO_ESTIMADA_MINUTOS
PAREJAS_MESAS_PUBLICAS_AUTORIZADAS
TRIOS_MESAS_PUBLICAS_AUTORIZADOS
COMBINACIONES_PUBLICAS_AUTORIZADAS
forzarOcupacionFisica
reenviarOtpModificacion
operacion=modificacion
/api/operacion/horario-efectivo
/api/liberar-reservacion
```

Las coincidencias de `llego` y `status_changed_at` quedan limitadas a migradores y pruebas históricas con comentarios `PRUEBA_DE_MIGRACION`.

## Bundles y fuentes

| Fuente | Destino | Estado |
|---|---|---|
| `src/js` público | `public/build/js/bundle.min.js`, `assets/js/bundle.min.js` | Build ejecutado |
| `src/js/admin` | bundles administrativos | Build ejecutado |
| operación/reservaciones | `public/build/js/admin/reservation-operation.js` | Build ejecutado |
| SCSS operación/POS | CSS de `public/build/css` | Build ejecutado |

## Validación

- PHP lint de archivos modificados: PASS.
- `git diff --check`: PASS.
- `npm.cmd run test:js`: PASS, 5 suites.
- `npm.cmd run build`: PASS, con warnings legacy conocidos.
- `tests/php/etapa12_instalacion_limpia.php`: contrato estático PASS; ejecución de DB bloqueada por MySQL apagado.

## Estado final

El inventario no identifica aliases o rutas que requieran una nueva consolidación. Los pendientes son exclusivamente de entorno: levantar MySQL/XAMPP y ejecutar la certificación PHP/browser.
