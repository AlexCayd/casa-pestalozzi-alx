# Etapa 11.9.1 — Acción pendiente de registrar ausencia

## 1. Resumen

El defecto era doble: una reservación confirmada después de la tolerancia seguía ofreciendo `Iniciar servicio`, y el mapa convertía una mesa físicamente libre en una mesa bloqueada visualmente. La solución deriva una acción operativa aditiva, `REGISTRAR_AUSENCIA`, sin crear estados persistidos nuevos.

## 2. Fuente de verdad

Se agregó la regla en `reservaciones_fuente_de_verdad.md`:

```text
ahora <= fecha_hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS → dentro de tolerancia
ahora > fecha_hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS → ausencia pendiente
```

La disponibilidad física queda separada de la resolución pendiente: fondo verde si la mesa está libre, borde gris para comunicar la acción pendiente y rojo con prioridad cuando existe ticket abierto.

## 3. Backend

`ReservacionVigenciaService` calcula el límite con la zona horaria canónica. En el límite exacto todavía permite iniciar; después del límite devuelve `puede_iniciar_servicio = false`, elegibilidad de no-show y la ventana `tolerancia_vencida`.

`PosReservacionSerializer` agrega de forma aditiva `accion_pendiente`, `puede_marcar_no_show`, `motivo_bloqueo` y `mensaje_bloqueo`. `schema_version` permanece `pos-reservacion.v1`.

`PuntoVentaReservacionService::comenzar()` revalida dentro de la transacción y devuelve `TOLERANCIA_LLEGADA_VENCIDA` antes de insertar ticket, `ticket_mesas` o cambiar la reservación a `en_curso`.

## 4. Estado visual

`MesaEstadoService` conserva `estado_base = disponible` y agrega `accion_pendiente`. `MesaEstadoAdapter` traduce ese estado a fondo verde; `_map-shell.scss` reutiliza el borde gris existente de tolerancia vencida. Ticket abierto mantiene el fondo rojo y la selección mantiene el patrón amarillo con la señal pendiente.

El nombre accesible incluye `disponible, acción pendiente: registrar ausencia`, además del tooltip nativo existente.

## 5. Modal

El resumen vencido muestra nombre, hora, comensales, mesas, tolerancia, retraso y consecuencia. No renderiza `Iniciar servicio`. Sus acciones son `Registrar ausencia` y `Volver`; la confirmación usa el shell compartido con `Volver` y `Registrar ausencia`.

## 6. Multimesa

La reservación y su `mesa_ids` se mantienen como una sola operación. El no-show transaccional bloquea la fila de la reservación, cambia una sola vez a `no_show` y libera la asignación completa para los cálculos operativos. No se abre un walk-in automáticamente.

## 7. Polling

La respuesta canónica del POS deriva la aparición y desaparición del borde. Se conserva `AbortController` y la secuencia de solicitudes; una respuesta obsoleta no puede restaurar el estado pendiente. Tras no-show, la siguiente respuesta elimina el resumen y deja la mesa disponible.

## 8. Accesibilidad

El estado no depende sólo del color: el `aria-label`, el título y la lista estructurada comunican la acción pendiente. El modal usa título, alerta, consecuencia, foco inicial, Escape y retorno de foco. No se deja un botón `Iniciar servicio` oculto o enfocable.

## 9. Pruebas

Se añadieron comprobaciones para límite exacto, un minuto después, bloqueo de inicio, no-show permitido, contrato aditivo, mesa verde con modificador pendiente y selección con señal pendiente. Resultados observados:

- `php tests/php/pos_reservacion_contrato.php`: OK.
- `php tests/php/etapa11_9_instalacion_limpia.php`: OK; DDL, DML, Etapa 11.9 (7/7), Etapa 9.5 reconciliada, Etapa 11.5 y Etapa 11.7.2.
- `php scripts/run-tests.php`: OK; Etapas 5, 11.5 y 11.7.2 en bases temporales, eliminadas al terminar.
- `npm.cmd test`: OK; regresiones PHP y las cinco suites JS.
- `npm.cmd run test:js`: OK; `reservation-form-state`, `operation-map-state`, `accessibility-contract`, `modal-layout` y `multitable-blocking`.
- `git diff --check`: OK.

## 10. Build

Se ejecutaron dos builds consecutivos con `npm.cmd run build`; ambos terminaron correctamente. Sass emitió únicamente las advertencias preexistentes de API JS heredada.

## 11. Archivos modificados

- `reservaciones_fuente_de_verdad.md`: regla funcional previa a la implementación.
- `services/ReservacionConfig.php`, `services/ReservacionVigenciaService.php`: tolerancia y comparación temporal.
- `services/PosReservacionQueryService.php`, `services/PosReservacionSerializer.php`: contrato y permanencia visual de la acción pendiente.
- `services/MesaEstadoService.php`: precedencia física y accesibilidad.
- `services/PuntoVentaReservacionService.php`, `controllers/PuntoVentaController.php`: rechazo transaccional del inicio tardío.
- `src/js/modules/punto-de-venta.js`, `src/js/operation/table-state-adapter.js`, `src/js/operation/reservation-card.js`: mapa, polling, modal y texto accesible.
- `src/scss/operation/_map-shell.scss`, `src/scss/punto-de-venta/_punto-de-venta.scss`: borde gris sobre fondo verde y contenido del modal.
- `tests/php/pos_reservacion_contrato.php`, `tests/js/operation-map-state.test.js`, `tests/js/modal-layout.test.js`: regresiones focalizadas.
- `tests/php/pos_reservacion_integrado.php`: expectativa histórica ajustada al bloqueo de inicio fuera de tolerancia.
- `docs/reports/2026-08-05_etapa11_9_1_accion_pendiente_no_show.md`: cierre y evidencia de la etapa.

## 12. Riesgos pendientes

No quedan riesgos funcionales conocidos dentro del alcance de esta etapa. La validación manual en navegador depende de disponer de una base temporal con fixtures controlados y sesión POS válida.

## 13. Decisión

- ¿La mesa físicamente libre muestra fondo verde? Sí.
- ¿La ausencia pendiente se representa con borde gris? Sí.
- ¿El estado tiene explicación accesible? Sí.
- ¿El modal deja de mostrar Iniciar servicio? Sí.
- ¿El backend rechaza el inicio fuera de tolerancia? Sí.
- ¿Registrar ausencia libera toda la asignación? Sí, de forma atómica en el estado operativo.
- ¿Es seguro iniciar la Etapa 12? Sí, después de confirmar las regresiones y la validación manual listadas en esta etapa.

No se cambió el esquema, el enum, `pos-reservacion.v1` ni se realizó ningún commit. La Etapa 12 no se inicia automáticamente.
