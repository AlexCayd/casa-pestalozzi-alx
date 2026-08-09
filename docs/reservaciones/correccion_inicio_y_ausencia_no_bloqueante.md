# Corrección de inicio exacto y ausencia pendiente no bloqueante

Fecha: 2026-08-09
Fuente normativa: `docs/reservaciones.md`
Evidencia técnica: `docs/reservaciones/auditoria_reservaciones_horarios_cercanos.md`

## Resultado

Se corrigieron los dos defectos reportados:

1. El inicio exacto ya es rojo. El azul termina inmediatamente antes del inicio y la tolerancia ya no extiende el azul después de la hora reservada.
2. `ausencia_pendiente` libera capacidad y asignación cuando no existe otro conflicto. El gris permanece como modificador visual y no deshabilita la mesa. POS conserva su política independiente: `disponible_para_ticket = false` hasta registrar la ausencia.

No se modificó DDL, duración, capacidad normal, intervalos semiabiertos, asignación automática ni la operación manual de no-show.

## Causa del azul en el inicio

Antes, `ReservacionPoliticaPosService` devolvía `ventana_visual_pos = inicio` cuando `delta = 0`, pero los presenters traducían tanto `inicio` como `tolerancia` a `reservacion-proxima`, que es azul.

Además, la proyección del mapa calculaba la tolerancia después del inicio y la mantenía como ventana azul. La comparación correcta quedó separada de la tolerancia funcional:

```text
delta = hora_reservacion - hora_consulta

advertencia: 30 < delta <= 60
azul:         0 < delta <= 30
rojo:         delta <= 0
              y la reservación sigue influyendo en el intervalo consultado
```

Para una reservación a las `13:00`, el resultado es:

| Consulta | Visual |
|---|---|
| `11:59` | verde |
| `12:00` | verde + borde azul punteado |
| `12:29` | verde + borde azul punteado |
| `12:30` | azul |
| `12:59` | azul |
| `13:00` | rojo |
| `13:00:01` | rojo |
| `13:30` | rojo, si la reservación sigue vigente |
| `14:29:59` | rojo |
| `14:30` | recalcular, porque termina el intervalo configurable de 90 minutos |

La tolerancia continúa determinando inicio de servicio, espera de llegada, ausencia pendiente y acciones POS; ya no determina el color azul posterior al inicio.

## Ausencia pendiente

La condición canónica sigue siendo:

```text
ahora > hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS
AND estado = confirmada
AND no existe ticket propio abierto
```

Después de esa condición:

```text
ausencia_pendiente = true
reservacion_influye_en_disponibilidad = false
```

La consulta de ocupación conserva la condición central de `ReservacionVigenciaService` y ahora vuelve a verificar el hecho canónico antes de convertir una asignación en bloqueo. Por eso la reservación vencida no reduce capacidad ni genera `bloqueada_en_intervalo` para su propia mesa.

`MesaEstadoService` selecciona otra reservación no vencida para definir el estado base cuando existe. La ausencia pendiente se conserva en paralelo como modificador:

| Situación | Estado base | Visual | Asignación | POS ticket |
|---|---|---|---:|---:|
| Sólo ausencia pendiente | verde | verde + gris | disponible | no disponible |
| Ausencia + ticket abierto | rojo | rojo + gris | no disponible | no disponible |
| Ausencia + otra reservación próxima | azul | azul + gris | según la otra reservación | según política POS |
| Ausencia + otra reservación cercana | verde + borde azul | verde + borde azul + gris | según la otra reservación | según política POS |

La incidencia original no se elimina ni cambia automáticamente a `no_show`; permanece disponible para **Registrar ausencia**.

## Separación de relojes

- `hora_consulta` determina la proyección visual, el intervalo consultado, la capacidad futura y la asignabilidad.
- `ahora` determina si la tolerancia realmente venció, `ausencia_pendiente`, no-show y permisos operativos POS.

Por ejemplo, si `ahora = 12:00` y se consulta visualmente `13:30`, la reservación todavía no tiene ausencia pendiente, pero puede proyectarse roja porque sigue vigente en ese intervalo. Si `ahora = 13:16`, la ausencia sí está pendiente; la misma consulta `13:30` debe liberar la mesa y mostrar verde + gris si no hay otro conflicto.

## Cambios realizados

- `services/ReservacionPoliticaPosService.php`: ventana visual previa al inicio, rojo desde `delta = 0`, hecho canónico de influencia y separación de ausencia real frente a proyección.
- `services/ReservacionMapaMesaPresenter.php`: inicio/tolerancia posterior al inicio en rojo; ausencia ya no fuerza rojo.
- `services/PosMesaProjectionPresenter.php`: misma lectura visual de inicio y ausencia, conservando el permiso POS separado.
- `services/ReservacionVigenciaService.php` y `services/OcupacionMesasService.php`: exposición y consumo explícitos de `reservacion_influye_en_disponibilidad`.
- `services/MesaEstadoService.php`: una reservación vencida no domina el estado base; el gris se compone sobre otra reservación o sobre verde.
- `src/js/operation/table-state-adapter.js`: consume `disponible_para_asignacion` sin convertir el modificador gris en disabled.
- `src/js/operation/map-visual.js`: `data-disabled` y `aria-disabled` dependen únicamente del permiso normalizado.

## Pruebas de usabilidad

El caso clave `Reserva A = 13:00, Mesa 4, ausencia pendiente, sin ticket` y nueva consulta a `13:30` verifica:

```text
ausencia_pendiente = true
bloqueada_en_intervalo = false
disponible_para_asignacion = true
disponible_para_ticket = false
estado_visual_mapa = libre
modificadores_visual_mapa contiene ausencia_pendiente
data-disabled = 0 en assignment_edit
aria-disabled = false en assignment_edit
```

Con ticket, el rojo proviene del ticket y la asignación se conserva bloqueada. Con otra reservación próxima, el azul proviene de esa otra reservación y el gris de la incidencia pendiente.

## Commits

1. `452b03c docs(reservaciones): corregir inicio visual y efecto de ausencia`
2. `539ec88 fix(reservaciones): marcar rojo desde inicio exacto`
3. `63adb42 fix(reservaciones): liberar asignacion ante ausencia pendiente`
4. `de1a711 fix(reservaciones): mantener ausencia como modificador no bloqueante`
5. `test(reservaciones): validar inicio exacto y liberacion por ausencia` (commit final de este informe)

## Validación

Se ejecutaron pruebas PHP/JS específicas de frontera, ausencia, composición visual y selección. La validación final incluye:

```text
npm.cmd run test:php
npm.cmd run test:js
npm.cmd run build
git diff --check
php -l sobre los PHP modificados
node --check sobre los JS modificados
```

No se modificó `database/database.sql`.
