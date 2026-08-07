# Reporte de corrección y merge: paridad entre capacidad y mapa

Fecha: 2026-08-07
Rama final: `main`
Merge commit: `d8408fe` (`Merge branch 'modulo-reservaciones' into main`)

## Alcance

Se integró la corrección definitiva para que la capacidad de reservaciones y el mapa administrativo compartan el mismo hecho canónico: `mesa_ids_bloqueadas` y `causas_bloqueo_por_mesa`, calculados por `OcupacionMesasService`.

La capacidad consume los ids bloqueados canónicos; el mapa administrativo los presenta como `ocupada`/rojo. Una mesa no reservable conserva el estado neutral `no-utilizable`. Las señales de proximidad, tolerancia, ausencia y ticket siguen disponibles para POS y dominio, pero no se convierten en modificadores visuales del mapa administrativo.

La duración productiva usa `ReservacionConfig::DURACION_RESERVACION_MINUTOS`. La duración estimada de ticket usa `DURACION_ESTIMADA_TICKET_MINUTOS` de forma independiente. La detección de traslape es semiabierta: `[inicio, fin)`, por lo que una consulta que comienza exactamente al terminar la reserva queda libre.

## Cambios principales

- `OcupacionMesasService`: contrato canónico de ids y causas bloqueadas, con traslape reutilizable y duración configurable.
- `CapacidadReservacionesService`: capacidad libre/bloqueada derivada del contrato canónico, incluyendo reservas multimesa.
- `MesaEstadoService` y `ReservacionMapaMesaPresenter`: proyección cerrada del mapa, sin cálculo temporal en JavaScript ni fallback de aliases.
- `ReservacionOperacionController` y `src/js/admin/reservations/operation.js`: contexto atómico de fecha/hora, validación de la respuesta y protección ante respuestas fuera de orden.
- `table-state-adapter.js` y estilos: selección amarilla sólo por opt-in administrativo; la presentación POS conserva su precedencia y sus estados.
- Leyenda administrativa reducida a cuatro estados: disponible, no disponible, selección actual y no utilizable.
- `/docs/reservaciones/` agregado a `.gitignore`. Los reportes de auditoría previos permanecen físicamente en el árbol, ignorados; este reporte se agrega explícitamente con `git add -f`.

## Evidencia funcional

La matriz de pruebas cubre:

| Caso | Capacidad | Mapa administrativo |
|---|---|---|
| Reserva 15:00–16:30, consulta 14:00–15:30 | Bloquea | Rojo/`ocupada` |
| Consulta que inicia exactamente 16:30 | Libre | Verde/`libre` |
| Reserva multimesa `[7, 8]` | Descuenta ambas | Ambas rojas |
| Demanda sin mesas asignadas | No inventa una mesa bloqueada | No pinta rojo |
| Ticket que bloquea el intervalo | Bloquea | Rojo |
| Ticket proyectado como liberado | No bloquea | Verde |
| Duración alternativa de 120 minutos | Capacidad y mapa coinciden | Capacidad y mapa coinciden |
| Cambio de hora en el mapa | La respuesta sólo se aplica si coincide con fecha/hora solicitadas | No se muestra una proyección stale |

## Decisiones del merge

Antes del merge se actualizó `main` a `origin/main` en `a174440`. El merge se hizo desde `main` con `modulo-reservaciones` como rama integrada.

| Área | Decisión | Motivo |
|---|---|---|
| `assets/css/app.css.map`, `assets/js/bundle.min.js.map`, `public/build/css/app.css.map`, `public/build/js/bundle.min.js.map` | Se conservó `main`/remoto | Son mapas globales generados y no contienen lógica ejecutable de la corrección. |
| `public/build/js/admin/map.js` y sus mapas | Se regeneraron desde los fuentes fusionados | Así se conservaron los cambios de POS remotos y la corrección de mapa/reservaciones. |
| `public/build/js/admin/reservation-form.js*` y `reservation-operation.js*` | Se regeneraron desde los fuentes fusionados | Evita que los bundles queden detrás de la lógica fuente. |
| Hunk de confirmación para abrir ticket en `PuntoVentaReservacionService` y POS | Se dejó la versión remota `REQUIERE_CONFIRMACION` | El usuario indicó conservar remoto ante cambios de validación de tickets; se actualizó también la aserción contractual. |
| Cobro/cierre en `PuntoVentaController::cerrarTicket` | Se conservó el resultado remoto | No hubo conflicto efectivo en ese bloque; la validación remota de cierre/pago permanece. |
| Fuentes de reservaciones, capacidad y mapa | Se conservaron las modificaciones locales y se integraron con los cambios remotos no conflictivos | Son el alcance principal de la corrección. |
| POS restante | Se conservaron las modificaciones integradas; sólo se priorizó el contrato remoto de confirmación de ticket | Mantiene configuración, flujo y presentación POS sin trasladar aliases del mapa administrativo. |
| `database/ddl.sql` y demás cambios de base presentes en `origin/main` | Se conservaron al actualizar `main` | No fueron introducidos por esta corrección; el alcance de la corrección no agrega DDL. |

## Pruebas y build

Ejecutado con resultado satisfactorio:

- `npm.cmd test` — suites PHP contractuales y JS contractual OK.
- `npm.cmd run audit:reservaciones` — auditoría contractual OK.
- `php -l` sobre los 18 PHP modificados/añadidos — OK.
- `node --check` sobre fuentes y bundles POS/reservaciones — OK.
- `npm.cmd exec -- gulp adminMapJs adminReservationFormJs adminReservationOperationJs` — OK.
- Build completo ejecutado durante la corrección antes del merge — OK, con avisos deprecados de la API legacy de Sass.

La salida de la prueba de catálogo conserva dos mensajes de stderr diseñados por esa prueba para verificar violaciones y códigos no catalogados; el proceso terminó con código 0.

## Commits relevantes

- `a97f95a` — `refactor(reservaciones): alinear mapa con ocupacion canonica`
- `f32de95` — `fix(reservaciones): sincronizar proyeccion del mapa por horario`
- `834e88e` — `fix(reservaciones): reflejar capacidad en estados del mapa`
- `3c2f2cf` — `test(reservaciones): validar paridad configurable entre capacidad y mapa`
- `d8408fe` — merge final en `main`

El árbol de trabajo quedó limpio después del commit del reporte; sólo permanecen archivos ignorados locales, incluidos los reportes de auditoría previos.
