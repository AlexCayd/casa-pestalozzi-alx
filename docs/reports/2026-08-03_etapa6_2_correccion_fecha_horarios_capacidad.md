# Etapa 6.2 — Corrección canónica de fecha, horarios y capacidad

**Fecha:** 2026-08-03  
**Repositorio:** `C:/xampp/htdocs/casa-pestalozzi`  
**Estado:** corregida y validada por contrato, pruebas y HTTP local; validación visual integrada pendiente por inaccesibilidad de Apache/XAMPP desde el navegador

## 1. Resumen ejecutivo

La auditoría encontró una propagación incompleta del contexto de consulta. El fallback de la landing referenciaba `timeInput` fuera de su alcance y no enviaba consistentemente personas/hora; además, el estado del frontend no comprobaba de forma explícita que una respuesta perteneciera a la fecha vigente. En administración, el bundle publicado podía quedar desfasado respecto del formulario fuente, el cambio de fecha no limpiaba de manera explícita la hora y la consulta no pedía la hora puntual ni devolvía el desglose de capacidad por horario.

La corrección conserva el núcleo de Etapa 5, liga cada consulta a `fecha + personas + hora`, descarta respuestas de otra fecha, limpia la hora al cambiar de día, devuelve fecha/hora en los contratos puntuales y calcula capacidad administrativa a partir de la ocupación de la misma fecha. Se recompilaron los bundles relacionados y se versionaron landing y administración.

Resultado: **sí, con condiciones**. El flujo de fecha, horarios, ocupación y capacidad quedó comprobable por contrato y pruebas; falta la aprobación visual interactiva en un navegador que pueda alcanzar Apache/XAMPP.

## 2. Fuente de verdad

Se revisaron completamente:

- `reservaciones_fuente_de_verdad.md`;
- `docs/reports/2026-08-03_etapa5_nucleo_horarios_ocupacion_asignacion_disponibilidad.md`;
- `docs/reports/2026-08-03_etapa6_reconexion_creacion_publica_otp.md`;
- `docs/reports/2026-08-03_etapa6_1_correccion_consulta_horarios_landing.md`.

Se mantuvieron las reglas canónicas: excepciones por fecha antes que horario semanal, anticipación mínima sólo para hoy, último inicio antes del cierre, ocupación por fecha, tickets actuales únicamente en el contexto del día actual y revalidación transaccional al crear.

## 3. Reproducción

La evidencia de código y de HTTP local quedó así:

| Superficie | Fecha seleccionada | Fecha enviada | Fecha usada por backend | Resultado |
|---|---|---|---|---|
| Landing | `2026-08-03` | `fecha=2026-08-03&personas=2` | `2026-08-03` | HTTP 200, horarios con anticipación mínima |
| Landing puntual | `2026-08-04`, `14:00` | `fecha=2026-08-04&personas=2&hora=14:00` | `2026-08-04` | HTTP 200, respuesta incluye fecha y hora |
| Administración | fecha del input oculto | `fecha`, `personas`, `reservacion_id` y `hora` | `DisponibilidadReservacionService` recibe la misma fecha | horarios y desglose administrativo de capacidad |

No se encontró en esta ruta una sustitución SQL directa por `hoy`; el defecto observable se reforzó por contratos débiles, bundle administrativo desactualizable y estado frontend que no demostraba fecha/hora en cada respuesta.

## 4. Flujo de fecha anterior

```text
Landing:
fechaHidden → datechange → reloadAvailability → timePicker.loadForDate
          → fetch incompleto/fallback → endpoint → servicios → render

Administración:
fechaHidden → datechange → loadSchedules → timePicker.loadForDate
          → endpoint administrativo → servicios → selector/capacidad
```

Los puntos problemáticos fueron el fallback público (`timeInput` fuera de alcance y sólo `fecha`), la ausencia de una comprobación de `respuesta.fecha`, la conservación implícita de la hora al cambiar de día y la falta de invalidación del resumen administrativo mientras llegaba la nueva respuesta. El bundle administrativo también no tenía un versionado incrementado para garantizar que el navegador recibiera el código actualizado.

## 5. Causa raíz

- `src/js/modules/form.js`: el `getQueryParams` del fallback usaba una variable no disponible y no componía el contrato completo de consulta.
- `src/js/components/reservation-time-picker.js`: procesaba la respuesta vigente por secuencia, pero no rechazaba una respuesta cuyo `fecha` fuera distinta de la fecha solicitada.
- `src/js/admin/reservations/form.js`: el cambio de fecha delegaba la limpieza al siguiente render y no enviaba la hora puntual para recalcular el resumen; el resumen anterior podía permanecer visible.
- `services/DisponibilidadReservacionService.php`: la fachada administrativa no aceptaba hora puntual y `resumenHorario` no conservaba el desglose real/proyectado de `OcupacionMesasService::resumenCapacidad`.
- `controllers/AdminReservacionController.php` y `views/home/index.php`: las versiones de assets no cambiaban con esta corrección.

## 6. Flujo canónico nuevo

```text
fecha seleccionada
  → fecha YYYY-MM-DD normalizada
  → horario semanal o excepción de esa fecha
  → intervalo fecha + hora
  → holds/reservaciones de esa fecha
  → tickets sólo según contexto del día actual
  → mesas y asignación para esa fecha
  → capacidad real/proyectada/estimada
  → respuesta con fecha (y hora si es puntual)
```

## 7. Landing

Se corrigió el fallback para enviar `fecha`, `personas` y `hora` sólo cuando existe una hora concreta. El selector compartido ahora rechaza respuestas con `fecha` distinta. `reloadAvailability` conserva la clave completa `fecha|personas|hora`, limpia la hora al cambiar de día y elige nuevamente la disponibilidad con la fecha vigente. La selección de una hora dispara una consulta puntual con la misma fecha y comensales.

## 8. Administración

El formulario administrativo normaliza la fecha recibida por el listener, limpia la hora y el resumen de capacidad antes de consultar el nuevo día, y verifica `payload.fecha` antes de renderizar detalles. La consulta administrativa incluye `hora` cuando se selecciona una hora y se vuelve a ejecutar al cambiar hora o comensales. El cache-busting cambió `reservation-form-v6` a `reservation-form-v7`.

## 9. Backend

`DisponibilidadReservacionService::consultarAdministrativa()` ahora acepta hora puntual y ambos controladores pasan el valor explícito. La respuesta puntual devuelve `fecha` y `hora`; la respuesta administrativa conserva `fecha` y añade capacidad por horario. `resumenHorario()` y los detalles administrativos usan `OcupacionMesasService::resumenCapacidad()` para separar capacidad realmente libre, proyectada y estimada. La fachada normaliza el `fecha` antes de usarla para horario, ocupación o asignación.

La creación continúa validando fecha, hora y comensales antes del lock y vuelve a validar el horario y la capacidad dentro de la transacción; no se confía en el selector.

## 10. Caché y concurrencia frontend

La clave de consulta comprobable es `fecha + personas + hora`; por tanto, dos lunes con excepción distinta no comparten resultado. `availabilityCacheKey()` y las claves de `reloadAvailability` incluyen la fecha completa. Se mantienen `AbortController`, identificador secuencial y descarte de respuestas obsoletas. Una respuesta con fecha o comensales incompatibles termina el loading como error recuperable y no reactiva una hora antigua.

## 11. Pruebas por día

La suite `tests/php/etapa6_2_fecha_horarios_capacidad.php` congeló el reloj en `2026-11-01 12:00:00` y produjo estas fechas libres durante la ejecución:

| Fecha | Regla aplicada | Resultado esperado |
|---|---|---|
| `2026-11-16` lunes | semanal `09:00–18:00` | candidatos desde `09:00` |
| `2026-11-03` martes | semanal `12:00–22:00` | candidatos desde `12:00` |
| `2026-11-04` miércoles | cerrado | sin horarios |
| `2026-11-23` lunes | excepción `14:00–20:00` | sólo horario especial |
| `2026-11-01` hoy | semanal `10:00–20:00` | filtrado desde `13:00` por 40 minutos |

La comparación confirmó listas distintas para lunes y martes, cierre efectivo para miércoles y prioridad de la excepción.

## 12. Pruebas de capacidad por fecha

| Caso | Fecha/hora | Fixture | Resultado |
|---|---|---|---|
| Fecha A | `2026-11-16 13:00` | reservación confirmada sobre todas las mesas | no disponible |
| Fecha B | `2026-11-03 13:00` | mesas libres, un hold separado | disponible |
| Ticket actual | `2026-11-01 12:00` | ticket abierto del día actual | mesa bloqueada |
| Mismo ticket, futuro | `2026-11-03 12:00` | ticket actual reutilizado como referencia | no bloquea la fecha futura |
| Excepción administrativa | `2026-11-23` | horario especial | respuesta y capacidad usan esa fecha |

La suite verificó además que el hold de la fecha B no aparece como ocupación en hoy.

## 13. Revalidación de creación

Se envió una creación administrativa para el miércoles cerrado conservando `13:00`, una hora válida para otro día. El backend rechazó la operación con código de horario no disponible y no creó una fila. La revalidación final permanece dentro de `ReservacionService::crearReservacion()` después de adquirir lock y comenzar la transacción.

## 14. Pruebas JavaScript

`node tests/js/reservation-form-state.test.js` pasó. La suite cubre fecha inicial, cambio de fecha, limpieza de hora, clave completa de consulta, respuesta de fecha distinta, respuesta obsoleta, capacidades por contexto, fecha futura sin filtro de hora actual y conservación de nombre/contacto.

Resultado: **PASS**.

## 15. Pruebas PHP

- `php tests/php/etapa6_2_fecha_horarios_capacidad.php`: **PASS, 20/20**.
- `php tests/php/etapa5_nucleo.php`: **PASS, 58/58**.
- `php tests/php/etapa6_publica.php`: **PASS, 46/46**.
- `php tests/php/etapa6_concurrencia.php`: **PASS, 1 retención + 1 duplicado**.
- `php tests/php/pos_reservacion_contrato.php`: **PASS**.
- `php tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`: **PASS**.

El comando POS integrado sin `--db` no es ejecutable por el contrato del propio runner; se ejecutó con la base temporal indicada por su mensaje de uso.

## 16. Validación visual

Se intentó abrir `http://localhost/casa-pestalozzi/public/` en el navegador integrado. Apache/XAMPP no estaba escuchando y el navegador devolvió `ERR_CONNECTION_REFUSED`. Se levantó un servidor PHP temporal propio y se verificó HTTP local con `Invoke-WebRequest`, pero el navegador integrado tampoco puede alcanzar servidores locales aislados por su política de red.

Por ello no se afirma aprobación visual de landing, administración, consola ni Network interactivo. La evidencia HTTP sí confirmó:

- `GET /api/reservaciones/disponibilidad?fecha=2026-08-03&personas=2`: HTTP 200, JSON, fecha `2026-08-03`;
- la consulta puntual para `2026-08-04` devolvió HTTP 200 y fecha `2026-08-04`;
- ambas solicitudes terminaron sin loading indefinido.

## 17. Assets

Se recompilaron las tareas relacionadas:

- `js` para landing: `public/build/js/bundle.min.js` y `assets/js/bundle.min.js`, con mapas;
- `adminReservationFormJs`;
- `adminReservationOperationJs`;
- `adminConfigurationJs`;
- `adminMapJs`.

La landing cambió a `reservations-public-redesign-v18`; administración cambió a `reservation-form-v7`.

## 18. Regresiones

Las pruebas de holds, OTP, creación pública, expiración e idempotencia continuaron pasando en Etapa 6. La concurrencia conservó exactamente una retención y un duplicado. El contrato POS y la suite integrada pasaron. No se modificaron esquema, DDL, mapa, POS, estados, modificación pública, cancelación pública ni `pos-reservacion.v1`.

## 19. Riesgos pendientes

1. **Media:** la aprobación visual real sigue pendiente hasta disponer de Apache/XAMPP accesible desde el navegador integrado.
2. **Media:** el proveedor real de correo/WhatsApp permanece fuera de alcance.
3. **Baja:** la consulta evalúa cada candidato del horario; debe medirse con tráfico real si crece el catálogo.
4. **Baja:** el fallback legacy permanece como compatibilidad, aunque ahora comparte el contrato de fecha/personas/hora.

No se inicia modificación ni cancelación pública automáticamente.

## 20. Decisión

**¿Landing y administración utilizan correctamente la fecha seleccionada para horarios y capacidad?**

**Sí, con condiciones.** El backend y los clientes ahora transportan y validan la fecha completa, la capacidad se deriva de la ocupación de esa misma fecha y las respuestas obsoletas no pueden restaurar horarios antiguos. La condición pendiente es ejecutar la validación visual en un navegador con acceso real a Apache/XAMPP.
