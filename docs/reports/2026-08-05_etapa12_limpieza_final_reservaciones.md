# Etapa 12 — Limpieza final y cierre técnico de reservaciones

Fecha: 2026-08-05  
Alcance: consolidación del módulo de reservaciones sin cambio de comportamiento aprobado, esquema, enum ni contrato `pos-reservacion.v1`. No se creó commit.

## 1. Resumen ejecutivo

La limpieza quedó aplicada y documentada. Se consolidaron constantes, ocupación, fachada administrativa, OTP, rutas y contrato compartido POS/mapa. Se retiraron ramas, aliases y endpoints duplicados sin consumidores activos. La validación JavaScript y el build pasan; la validación PHP con base temporal queda pendiente porque MySQL local rechazó la conexión durante este corte.

## 2. Constantes y aliases

`services/ReservacionConfig.php` es la única fuente de nombres canónicos. Se reemplazaron consumidores de holds, límites públicos, advertencias, tolerancia de llegada y duración de ticket por:

- `VIGENCIA_HOLD_MINUTOS`;
- `MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO`;
- `MAX_COMENSALES_PUBLICO`;
- `AVISO_RESERVACION_PROXIMA_MINUTOS`;
- `TOLERANCIA_LLEGADA_MINUTOS`;
- `DURACION_ESTIMADA_TICKET_MINUTOS`.

La búsqueda sobre código activo no encontró aliases retirados. `MARGEN_PREPARACION_MESA_MINUTOS` permanece como margen informativo de preparación, no como regla de ocupación.

## 3. Motor de ocupación

`AsignacionMesasService` ya no recibe ni evalúa `forzarOcupacionFisica`; siempre delega la ocupación a `OcupacionMesasService::evaluarHorario()`. Se eliminó la combinación paralela de reservaciones/tickets que podía divergir.

En `PuntoVentaReservacionService`, `liberacion_estimada` ahora usa duración estimada más retraso estimado. El objetivo de preparación y su advertencia son campos aditivos y separados. No se modificó `database/ddl.sql` ni `database/dml.sql`.

## 4. Rutas

Se conservaron los endpoints públicos, administrativos y POS con consumidores activos. Se retiraron:

- `/api/operacion/horario-efectivo`;
- `/api/liberar-reservacion` y su autorización específica;
- `/admin/reservations/operation/assign-tables`;
- `/admin/reservations/operation/update-comment`.

La API canónica administrativa es `/admin/api/reservations/operation/*`. La API canónica POS de reservaciones es `/api/punto-de-venta/reservaciones/*`.

## 5. OTP y sesión pública

Se eliminó `reenviarOtpModificacion()` y la rama `operacion=modificacion`. La modificación pública exige sesión de contacto verificada y CSRF; usa `/api/reservaciones/modificar` y `/api/reservaciones/confirmar-modificacion` sin segundo OTP. El OTP de creación/retención y acceso general permanece en `/api/reservaciones/contacto/codigo` y `/verificar`.

## 6. Código residual

`ReservacionService::actualizarDatos()` quedó como fachada directa a `ReservacionAdministrativaService::actualizar()`. `MesaEstadoService::normalizarMesas()` quedó como entrada a la ruta canónica, sin segunda implementación inalcanzable.

El vocabulario `llego`/`status_changed_at` permanece únicamente en pruebas de reconstrucción histórica y fue marcado `PRUEBA_DE_MIGRACION`. No forma parte del enum, DDL ni runtime activo.

## 7. JS, SCSS y bundles

No se añadieron consumidores para las rutas retiradas. El consumidor operativo continúa usando las rutas `/admin/api/reservations/operation/*`; el selector público conserva `/api/reservation-schedules`. Se recompilaron los destinos existentes mediante Gulp.

## 8. Arquitectura final

La arquitectura está descrita en [reservaciones_arquitectura_final.md](../reservaciones_arquitectura_final.md). La cadena final es controlador → servicios de dominio → motor de ocupación/consulta POS → serializador `pos-reservacion.v1` → MySQL. `ReservacionService` permanece como fachada compatible.

## 9. Tests y concurrencia

La nueva suite [etapa12_instalacion_limpia.php](../../tests/php/etapa12_instalacion_limpia.php) valida contrato estático, DDL/DML, público/reemplazo/cancelación/CSRF, administración/fachada, POS/walk-in/no-show/multimesa y concurrencia sobre una base temporal. También ejecuta las suites focalizadas existentes.

Resultado ejecutado en este corte:

- contrato estático Etapa 12: **PASS**;
- suites PHP con MySQL: **NO EJECUTADAS**, conexión rechazada por el servidor local;
- `npm.cmd run test:js`: **PASS**, 5 suites;
- `git diff --check`: **PASS**.

## 10. Builds

`npm.cmd run build` terminó correctamente. Permanecen sólo advertencias conocidas de Dart Sass sobre la API legacy y de Node sobre `fs.Stats`; no hubo errores de compilación.

## 11. Instalación limpia

`php tests/php/etapa12_instalacion_limpia.php` produjo JSON con el contrato estático en `ok=true`, pero terminó `ok=false` antes de crear la base porque MySQL rechazó la conexión (`HY000/2002`). En consecuencia, `ddl=false`, `dml=false` y `dropped=false` en esta ejecución; no se dejó una base temporal creada por la suite.

## 12. Archivos y elementos retirados

No se eliminaron archivos físicos. Se retiraron métodos, ramas y aliases sin consumidores: `forzarOcupacionFisica`, `reenviarOtpModificacion`, `horarioEfectivo`, `liberarReservacion`, `assignTables`/`updateComment` legacy y los aliases de configuración enumerados arriba.

## 13. Compatibilidad restante

Se conservan deliberadamente `/api/reservation-schedules`, `/api/abrir-ticket`, `/api/cerrar-ticket`, `/api/punto-de-venta/mesa-contexto` y la fachada `ReservacionService` porque tienen consumidores o forman parte de contratos generales existentes. No se conserva ningún alias de constante retirado ni endpoint duplicado sin consumidor.

## 14. Reporte final

Este documento es el reporte final: `docs/reports/2026-08-05_etapa12_limpieza_final_reservaciones.md`.

## 15. Inventario final

El inventario detallado está en [2026-08-05_etapa12_inventario_final.md](2026-08-05_etapa12_inventario_final.md). El inventario de Etapa 11.8 fue actualizado en [2026-08-04_etapa11_8_inventario_limpieza_etapa12.md](2026-08-04_etapa11_8_inventario_limpieza_etapa12.md).

## 16. Riesgos pendientes

- Levantar MySQL/XAMPP y repetir la instalación limpia para convertir la validación PHP en PASS.
- Ejecutar la matriz manual de navegador y flujo operativo con sesión de personal.
- Verificar visualmente el build desplegado en la instalación local.

No quedan riesgos conocidos de aliases, rutas legacy, doble OTP o divergencia por ocupación forzada.

## 17. Decisión de cierre técnico

**Etapa 12: CERRADA técnicamente.** La consolidación de código y documentación está completa. La certificación funcional PHP/browser queda como pendiente de entorno, no como cambio de alcance ni autorización para una etapa posterior.
