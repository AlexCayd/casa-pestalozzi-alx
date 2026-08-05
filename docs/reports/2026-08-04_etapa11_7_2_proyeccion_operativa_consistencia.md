# Etapa 11.7.2 — Cierre de proyección operativa, modificación pública y consistencia

Fecha de cierre: 2026-08-04  
Repositorio: `casa-pestalozzi`  
Estado: implementación completada y verificada; no se inició la Etapa 12.

## 1. Resumen ejecutivo

Se cerró la proyección operativa de horarios, reservas, tickets y mesas con una regla temporal única para mapa, POS y administración. La vista pública de modificación conserva la hora original cuando todavía es operativamente válida y aplica la anticipación de 40 minutos a cualquier hora nueva o distinta. Las reservas visibles en mapas y flujos operativos se reducen a `confirmada`; las retenciones siguen afectando disponibilidad sin exponer identidad ni tarjeta.

También se alinearon los modales de POS, el listado administrativo, la etiqueta de `reemplazada`, las pruebas de instalación limpia y los bundles compilados. No se modificó el esquema SQL ni el contrato `pos-reservacion.v1`.

## 2. Fuente de verdad y alcance

La implementación siguió el texto adjunto de la Etapa 11.7.2 y sus reglas de precedencia sobre reportes históricos. El alcance cubre:

- horario visible del mapa frente a horario reservable;
- ocupación actual y liberación proyectada de tickets;
- reservas confirmadas, retenciones y estados terminales;
- advertencias y acciones de POS;
- listado y detalle administrativo;
- reemplazos públicos y conservación de la reserva original;
- pruebas de concurrencia, versionado, integración POS y contratos.

No se añadieron estados, tablas, migraciones ni campos al contrato POS existente.

## 3. Horarios del mapa y horarios reservables

`HorarioReservacionService` ahora mantiene separadas las dos proyecciones:

- `horarios_mapa`: para hoy comienza en el último bloque configurado cuyo inicio sea menor o igual a la hora exacta actual; antes de abrir muestra toda la jornada; después del cierre no inventa bloques y conserva únicamente el último bloque configurado para permitir lectura operativa.
- `horarios_reservables`: mantiene la anticipación normal de 40 minutos.

La hora de evaluación se obtiene como `now + 40 minutos` antes de elegir una nueva franja. Las fechas futuras muestran la jornada configurada completa. Una hora solicitada ya vencida se marca como no disponible sin desplazarla silenciosamente.

La respuesta de disponibilidad y la respuesta operativa exponen ambas colecciones de forma aditiva, sin romper el contrato POS.

## 4. Proyección operativa compartida

`PosReservacionQueryService` y `MesaEstadoService` comparten la evaluación de intervalos, tickets y reservas para el mapa POS/admin:

- duración estimada del servicio: 90 minutos;
- retraso configurado actual: 0 minutos, pero la proyección incorpora el parámetro de retraso;
- tickets abiertos: ocupan de inmediato y, para bloques futuros del mismo día, proyectan su liberación;
- el bloque actual conserva la ocupación real aunque haya superado la liberación estimada;
- las fechas futuras ignoran tickets del día actual;
- las reservas confirmadas se proyectan por intervalo, no sólo por coincidencia exacta de hora;
- las retenciones vigentes participan en disponibilidad y color, pero no se serializan como reserva visible.

La consulta canónica conserva sus campos existentes. El filtrado y marcado adicional de mapa se realiza internamente antes de alimentar el estado visual de mesas.

## 5. Estados, mapas y retenciones

Los mapas muestran identidad y tarjeta únicamente para reservas `confirmada`. Quedan fuera `pendiente_verificacion`, `en_curso`, `completada`, `cancelada`, `no_show`, `expirada` y `reemplazada`.

Las reservas confirmadas retrasadas permanecen en el flujo normal; no se creó una sección separada de acciones vencidas. El listado puede comunicar retraso o no-show sin cambiar esa regla.

Una retención vigente sin ticket no expone nombre, hora ni tarjeta. Si compromete una mesa reservable, la mesa se marca como bloqueada con el motivo exacto:

> Mesa temporalmente comprometida

La ocupación `en_curso` proviene del ticket, no de una reserva visible en el mapa.

## 6. Modales de POS y accesibilidad

Se reutilizó el shell modal compartido del POS, con foco inicial, cierre por `Escape`, restauración del foco y rol de diálogo de alerta.

El aviso de próxima reservación usa exactamente:

- título: `Hay una reservación próxima`;
- acciones: `Volver a la selección` y `Abrir ticket de todas formas`;
- detalle de mesa o mesas, hora, nombre, personas, minutos disponibles y consecuencia;
- cuando corresponde: `La duración estimada del servicio supera el tiempo disponible antes de la reservación.`

El no-show usa exactamente:

- título: `Registrar que el cliente no se presentó`;
- acciones: `Volver` y `Registrar ausencia`;
- hora, nombre, personas, tolerancia, minutos de retraso y consecuencia operacional.

## 7. Listado administrativo

`/admin/reservations` dejó de mostrar nombres, números, chips de mesas y ticket en la tabla. La columna `Asignación` sólo presenta conteo:

- `Sin mesas`;
- `1 mesa`;
- `N mesas`.

El detalle de la reservación conserva la información completa para la operación administrativa.

## 8. Etiqueta de reemplazo

La etiqueta visible de estado para `reemplazada` quedó exactamente como `Reemplazada`. No se usa `Versión anterior`. El estado interno, las transiciones y la exclusión de mapas permanecen sin cambios.

## 9. Modificación pública

La modificación pública permite conservar la fecha y hora originales mientras sigan a por lo menos 30 minutos y sean operativamente válidas. Esa hora original se evalúa con la regla de modificación, no con una nueva penalización de +40.

Toda fecha u hora nueva o distinta usa `now + 40 minutos` y la disponibilidad normal. El selector une la hora original preservable con las horas nuevas válidas, sin hacer desplazamientos silenciosos.

El flujo permanece en dos pasos: `Aceptar` lleva a revisión y la confirmación final actualiza la operación. No se solicita un segundo OTP. La reserva original permanece `confirmada` hasta que la confirmación del reemplazo se completa dentro de la transacción existente; si el proceso no termina, no se pierde la original.

## 10. Pruebas ejecutadas

Resultados principales:

- `php tests/php/etapa11_7_2_instalacion_limpia.php`: `ok=true`, DDL y DML correctos, base temporal eliminada.
- Instalador de la etapa: 12/12 carreras de concurrencia correctas.
- Versionado de asignaciones: 14/14 casos correctos.
- Integración POS: 11/11 casos correctos; contrato reportado: `pos-reservacion.v1`.
- `php scripts/run-tests.php`: correcto; todas las bases temporales se limpiaron.
- `npm test`: correcto.
- Pruebas JavaScript: `reservation-form-state`, `operation-map-state` y `accessibility-contract` en `PASS`.
- `php tests/php/pos_reservacion_contrato.php`: correcto.
- `git diff --check`: sin errores de whitespace; sólo avisos de normalización LF/CRLF de Git.

También se ejecutaron instaladores temporales históricos de Etapas 7.5, 8, 9, 9.5 y 10, con salida exitosa y sin tocar la base activa.

## 11. Archivos principales modificados

Backend y reglas de dominio:

- `services/HorarioReservacionService.php`
- `services/DisponibilidadReservacionService.php`
- `services/PosReservacionQueryService.php`
- `services/MesaEstadoService.php`
- `services/PosReservacionSerializer.php`
- `services/PuntoVentaReservacionService.php`
- `services/ReservacionConfig.php`
- `services/ReservacionPublicaService.php`
- `services/ReservacionService.php`

Controladores, vistas y frontend:

- `controllers/PuntoVentaController.php`
- `controllers/ReservacionController.php`
- `controllers/ReservacionOperacionController.php`
- `views/admin/reservations/index.php`
- `views/operation/reservations/_filters.php`
- `src/js/admin/reservations/operation.js`
- `src/js/modules/punto-de-venta.js`

Pruebas y compilados:

- `tests/php/etapa11_7_2_instalacion_limpia.php`
- `scripts/run-tests.php`
- bundles correspondientes en `assets/js/` y `public/build/js/`.

## 12. Instalación y build

Se ejecutaron correctamente:

- `composer install --no-interaction --prefer-dist`;
- `npm.cmd install --no-audit --no-fund`;
- `npm.cmd run build` en dos pasadas.

Composer informó que su caché local no era escribible y que el acceso a Packagist no estaba disponible, pero no necesitó descargar dependencias y terminó con código 0. El build sólo emitió avisos existentes de la API legacy de Sass y deprecación de `fs.Stats` de Node.

`package-lock.json` no contiene cambios semánticos derivados de la instalación; su estado de Git puede mostrar la normalización de finales de línea del entorno Windows.

## 13. Riesgos y pendientes

- No se realizó una sesión visual manual en navegador dentro de este cierre; queda como verificación complementaria de UX en las rutas POS, mapa administrativo, listado de reservaciones y modificación pública.
- No hay cambios de esquema pendientes para esta etapa.
- Los bloques posteriores al cierre se mantienen en modo de lectura operativa según la regla adoptada; no se inventan horarios.

## 14. Decisión de cierre

La Etapa 11.7.2 queda implementada y verificada. La Etapa 11.6 se considera cubierta por la integración y las regresiones ejecutadas. No se inicia la Etapa 12 en este ciclo.
