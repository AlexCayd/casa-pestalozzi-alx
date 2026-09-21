# Inventario de alertas visuales de reservaciones en mapas

## Alcance y método

Este inventario describe las rutas actuales de POS y del mapa operativo/administrativo. Se revisaron Services, presenters, serializers, controllers, payloads, JavaScript, SCSS, Views y las pruebas contractuales de mapas. No modifica reglas ni presentación.

El baseline de código revisado parte de `e2818e1`. Las ventanas usan `America/Mexico_City` y la configuración presente en `services/Reservations/ReservacionConfig.php`.

## Flujo de datos

### Mapa del Punto de Venta

1. `views/punto-de-venta/index.php` incluye `views/punto-de-venta/partials/pos-workspace.php`, que crea el mapa mediante el parcial compartido `views/operation/partials/map.php`. El POS fija `legendPosition = none`.
2. `PuntoVentaController::api()` llama a `PosReservacionQueryService::paraFecha($fecha, '')`. Al no recibir una hora seleccionada, el lector usa la hora actual del servidor para ese día.
3. `PosReservacionQueryService` serializa reservaciones y tickets; `ReservacionPoliticaPosService` obtiene ventanas y acciones usando `ReservacionVigenciaService`; `MesaEstadoService` produce `estado_visual_pos`, `modificadores_visual_pos`, hechos de ocupación y etiquetas accesibles.
4. `PosMesaProjectionPresenter` traduce esos hechos al estado visual del POS. La respuesta JSON contiene `mesas_estado`, las reservaciones confirmadas y tickets abiertos.
5. `src/js/modules/punto-de-venta.js` combina ese payload con `src/js/operation/table-state-adapter.js`; `src/js/operation/map-visual.js` dibuja los pines y la selección. El clic abre el modal del mapa.

### Mapa operativo/administrativo de Reservaciones

1. `views/operation/reservations/index.php` usa el mismo parcial de mapa, esta vez con leyenda al pie.
2. `ReservacionOperacionController` resuelve la fecha y una hora válida del horario efectivo, y pasa ambas a `PosReservacionQueryService::paraFecha()`.
3. El lector compartido devuelve `mesas_estado` y reservaciones serializadas para la hora consultada. `ReservacionMapaAdministrativaService::proyectar()` añade flags para las listas operativa y administrativa, incluida `en_proyeccion_mapa`.
4. `ReservacionMapaMesaPresenter` prepara `estado_visual_mapa`, modificadores, precedencia y etiqueta. El controller devuelve esos estados junto con `reservaciones`, `reservaciones_operativas` y `reservaciones_admin`.
5. `src/js/admin/reservations/operation.js` valida el payload y lo adapta con `MesaEstadoAdapter`; `MapaVisual` dibuja el mapa. `ReservationOperationPolicy` prepara el estado del modal de mesas, mientras el panel de reservación ofrece las acciones del caso.

En ambas superficies, el contrato de mesa separa presentación de asignabilidad. El color o modificador del pin no autoriza por sí mismo una asignación ni la apertura de un ticket.

## Estados visuales verificados

| Hecho | POS | Mapa operativo/administrativo |
|---|---|---|
| Mesa libre y utilizable | `libre`, con estilo verde, si no prevalece ticket ni ventana de reservación. | `libre`, con estilo verde; también puede conservar verde durante la ventana de advertencia aunque `bloqueada_en_intervalo` sea verdadero. |
| Ticket abierto | `ocupada` cuando el ticket sigue físicamente abierto; el estado puede conservar modificadores de reservación. | `ocupada` si el ticket bloquea la hora consultada. Si la proyección temporal ya lo libera, el mapa puede mostrar libre aunque el ticket siga abierto físicamente. |
| Reservación a más de 60 minutos | La ventana es `futura`; no agrega una alerta temporal al pin. | `ventana_mapa = futura`; no agrega una alerta temporal al pin. |
| Reservación entre 30 y 60 minutos | La base queda `libre` y se agrega `reservacion_advertencia`; el pin conserva fondo verde y usa borde discontinuo azul. | La base queda `libre` con `reservacion_advertencia`; conserva verde y borde discontinuo azul. |
| Reservación dentro de los 30 minutos previos | La base es `reservacion-proxima` y se agrega `reservacion_inminente`; el walk-in queda bloqueado. | La base es `reservacion-proxima` y se agrega `reservacion_inminente`; la mesa queda bloqueada para el intervalo consultado. |
| Inicio de la reservación | La base visual POS sigue siendo `reservacion-proxima`, con `reservacion_bloqueante`; si la asignación y los conflictos lo permiten, puede iniciarse el servicio. | Cuando la hora seleccionada cae dentro del intervalo planificado y la reservación aún influye, el presenter usa `ocupada` (rojo) con `reservacion_bloqueante`. |
| Tolerancia de llegada | `ventana_visual_pos = tolerancia`; mantiene la base de reservación y agrega `reservacion_tolerancia`. Es posible iniciar servicio durante esta ventana si el backend lo permite. | El cálculo de la proyección actual usa la hora seleccionada. Dentro del intervalo planificado devuelve `inicio`; no emite una ventana visual separada `tolerancia` en esta ruta, aunque el presenter tiene una rama para ese valor. |
| Tolerancia vencida / posible no-show | No es un estado base del pin. Se agrega `ausencia_pendiente` y el texto accesible indica que hay que registrar la ausencia. | Tampoco fuerza un color base propio. Se agrega `ausencia_pendiente` y la etiqueta de mesa pide registrar la ausencia; puede coexistir con verde, azul o rojo según los otros hechos. |
| Estado final `no_show` | Deja de entrar en el conjunto visual de reservaciones confirmadas; al completar la acción, el cliente limpia localmente los modificadores de reservación y libera el pin. | Los estados visuales se construyen para reservaciones confirmadas; el estado final no tiene un color independiente de mesa. |
| Mesa no utilizable | `no-utilizable`, neutro, para elementos que no sean ticketables ni caja; barras y algunos elementos especiales siguen siendo operables en POS. | `no-utilizable`, neutro, si la mesa no está activa, no es reservable o no es de tipo `mesa`. |
| Bloqueo de intervalo sin reservación principal | El presenter POS no tiene una rama visual propia para `bloqueada_en_intervalo`; la etiqueta/estado depende de los hechos POS que sí recibe. | Si no hay reservación asociada y `bloqueada_en_intervalo` es verdadero, queda `ocupada`; la etiqueta identifica ticket, reservación, hold u otro bloqueo cuando se conoce la causa. |
| Reservación fuera del horario efectivo | No tiene una clase base de mesa. La tarjeta del cajón recibe `fuera_horario_operacion` y muestra contexto textual. | La reservación se conserva en la lista administrativa con `fuera_horario_operacion` y `en_proyeccion_mapa = false`. Hay una discrepancia documentada abajo: el estado de mesa se calcula antes de aplicar ese flag a la lista. |
| Selección | Es una capa de interacción para seleccionar mesas al abrir un ticket múltiple. El pin mantiene el estado de ticket cuando corresponde y la selección se muestra como indicador amarillo. | En modo de asignación la selección válida puede sustituir el estado base por `seleccionada`; las mesas no utilizables no se seleccionan. La asignación actual y los conflictos conservan modificadores propios. |

No se encontró un estado base separado llamado “reservación vencida”, “tolerancia vencida” o “posible no-show”. El código los presenta mediante `ausencia_pendiente`, texto accesible y una acción de dominio. El `no_show` final tampoco tiene un estado de pin dedicado.

## Reglas temporales

Las cifras salen de `services/Reservations/ReservacionConfig.php` y las condiciones de `ReservacionVigenciaService` y `ReservacionPoliticaPosService`:

| Tiempo | Regla actual |
|---|---|
| Más de 60 minutos antes | Reservación futura, sin aviso visual temporal en el pin. |
| Más de 30 y hasta 60 minutos antes | Ventana de advertencia. En POS se puede presentar confirmación explícita antes de abrir un walk-in cuando el ticket sigue permitido. |
| Desde 30 minutos antes hasta el inicio | Ventana `bloqueo`; no se admite walk-in. El inicio de servicio puede habilitarse si existe asignación y no hay conflicto impeditivo. |
| Inicio hasta 15 minutos después | `tolerancia`; el inicio de servicio aún puede habilitarse. En POS, la ventana visual es `tolerancia`. |
| Más de 15 minutos después | Si continúa confirmada y no existe ticket abierto, pasa a `ausencia_pendiente` y puede registrarse no-show. En el límite exacto de 15 minutos todavía se considera dentro de tolerancia. |
| Intervalo planificado | La duración configurada de una reservación es 90 minutos. La proyección administrativa compara el intervalo de la reservación con la hora/intervalo seleccionado; su color puede diferir de la lectura POS del momento actual. |

La advertencia usa `segundos > 30 minutos && segundos <= 60 minutos`; el bloqueo previo cubre desde 0 hasta 30 minutos. La tolerancia vencida usa estrictamente `ahora > inicio + 15 minutos`.

El mapa POS toma la hora actual al llamar `paraFecha($fecha, '')`. El mapa administrativo resuelve una hora del horario operativo y proyecta la mesa contra esa hora seleccionada. El tiempo de `ausencia_pendiente` sigue dependiendo del reloj actual aunque la hora del mapa sea seleccionada.

## Prioridad y composición visual

### Presentación del servidor

1. `no-utilizable` domina cualquier ticket o reservación.
2. POS da prioridad al ticket abierto/ocupación física; después considera la ventana de reservación.
3. El mapa administrativo da prioridad al ticket que bloquea la hora consultada; después considera `inicio`, `tolerancia`, bloqueo y advertencia de reservación.
4. Si no hay reservación visual asociada, el mapa administrativo convierte un bloqueo del intervalo en `ocupada`; si no hay bloqueo, queda `libre`.
5. Los modificadores se combinan con el estado base: proximidad, ausencia pendiente, varias mesas o asignación actual no sustituyen por sí solos la disponibilidad del dominio.

### Selección en el cliente

La selección se aplica después del payload y tiene reglas del consumidor. En POS, la ocupación por ticket conserva el fondo rojo y la selección queda como indicador secundario. En el mapa administrativo, una selección válida de asignación puede mostrarse como `seleccionada`; la capa cliente rechaza siempre una mesa no utilizable.

### Colores y leyenda

El SCSS compartido usa verde para libre, rojo para ocupada, azul para reservación próxima, amarillo/oro para selección y neutro para no utilizable. `reservacion_advertencia` mantiene el estado base y añade borde discontinuo azul; `ausencia_pendiente` añade un contorno neutro. Los estados de tolerancia y de reservación vencida no tienen un color base independiente.

La leyenda visible del mapa administrativo enumera verde, rojo, amarillo y neutro; no incluye una entrada propia para azul, advertencia discontinua ni ausencia pendiente. El POS oculta la leyenda por configuración de su View.

## Acciones relacionadas

| Estado/condición | Acción disponible verificada |
|---|---|
| Mesa libre en POS | Abrir ticket; desde el modal también puede iniciarse selección múltiple para unir mesas. |
| Reservación en advertencia de 30–60 minutos | El walk-in requiere confirmación explícita cuando el backend lo permite; la operación administrativa puede mostrar una alerta al seleccionar la mesa para asignación. |
| Reservación en ventana de inicio/tolerancia | Iniciar servicio si la reservación tiene mesas asignadas y pasa las validaciones de disponibilidad. |
| Ausencia pendiente | Registrar ausencia/no-show con confirmación; al completarla, las mesas dejan de quedar comprometidas por esa reservación. |
| Ticket abierto | Abrir/consultar el ticket en POS; en el detalle administrativo puede aparecer “Ver ticket”. |
| Asignación administrativa | Seleccionar candidatas, guardar/reasignar mesas y, cuando la política lo permite, liberar la asignación. |
| Fuera del horario efectivo | Abrir el detalle administrativo para seguimiento; no hay un estado visual propio de mesa para esta condición. |

`PosReservacionQueryService::adjuntarAdvertencias()` también agrega `reservaciones_proximas` a un ticket si comparte mesa asignada con una reservación que está en ventana de advertencia; el POS muestra el aviso en el contexto del ticket, aparte del color del pin.

## Reglas compartidas, exclusivas y duplicación

### Compartidas

- `PosReservacionQueryService`, `PosReservacionSerializer` y `MesaEstadoService` alimentan las dos superficies.
- Los contratos de mesa exponen por separado estado visual, modificadores, ocupación, causas de bloqueo y disponibilidad para asignación/ticket.
- `map-visual.js`, `table-state-adapter.js` y `src/scss/operation/_map-shell.scss` son compartidos.

### Exclusivas

- POS usa hora actual, presenta acciones de ticket/walk-in y contempla elementos operativos como barra y caja.
- Reservaciones usa una hora seleccionada, proyecta disponibilidad por intervalo y añade selección de asignación, lista administrativa y contexto fuera de horario.
- POS oculta la leyenda; Reservaciones la coloca al pie del mapa.

### Duplicación técnica y diferencias de significado

`PosMesaProjectionPresenter` y `ReservacionMapaMesaPresenter` son dos mapeos separados desde hechos comunes hacia clases visuales. La duplicación es deliberada por superficie, pero hace que conceptos parecidos no siempre tengan el mismo estado base:

- una reservación iniciada se mantiene azul como `reservacion-proxima` en POS, y en la hora seleccionada del mapa administrativo se muestra roja como `ocupada`;
- POS marca rojo por ticket físicamente abierto; Reservaciones puede mostrar verde si la proyección de la hora seleccionada ya considera liberado ese ticket;
- en ambos mapas la tolerancia/no-show puede conservar otro color base y agregarse como modificador, pero sus ventanas de cálculo no son iguales porque POS usa el reloj y Reservaciones la hora consultada.

Estas diferencias corresponden al contexto de cada superficie. No se registran como defectos por sí mismas.

### Inconsistencia verificada: reservación fuera de horario y pin administrativo

`ReservacionMapaAdministrativaService::proyectar()` pone `en_proyeccion_mapa = false` cuando una reservación confirmada queda fuera del horario efectivo. Sin embargo, `ReservacionOperacionController` recibe `mesas_estado` de `PosReservacionQueryService` antes de aplicar esa proyección, y `MesaEstadoService` ya construyó el estado por mesa con las reservaciones que se traslapan con la hora consultada. El filtro usado para `mesas_estado` considera estado confirmado e intervalo, pero no `fuera_horario_operacion`.

Por eso, una reservación fuera de horario cuyo intervalo planificado se traslape con la hora seleccionada puede aparecer en la lista administrativa con `en_proyeccion_mapa = false` y a la vez seguir influyendo en el estado/modificadores del pin. El contrato de lista y el pin pueden comunicar cosas distintas. El problema queda registrado en `docs/correcciones_pendientes_arquitectura.md`; no se corrigió durante este inventario.

## Fuentes técnicas por regla

| Regla/capa | Fuente actual |
|---|---|
| Umbrales, zona horaria y duración | `services/Reservations/ReservacionConfig.php` |
| Vigencia, inicio, tolerancia y elegibilidad de no-show | `services/Reservations/ReservacionVigenciaService.php` |
| Ventanas POS, prioridad de acción y proyección por hora seleccionada | `services/ReservacionPoliticaPosService.php` |
| Lectura común, intervalos y payload de mesa | `services/PosReservacionQueryService.php`, `services/PosReservacionSerializer.php`, `services/Tables/MesaEstadoService.php` |
| Estado visual POS | `services/PosMesaProjectionPresenter.php` |
| Estado visual administrativo | `services/Reservations/ReservacionMapaMesaPresenter.php`, `services/Reservations/ReservacionMapaAdministrativaService.php` |
| API POS | `controllers/PuntoVentaController.php::api()` |
| API mapa operativo | `controllers/ReservacionOperacionController.php` |
| Adaptación y dibujo de pines | `src/js/operation/table-state-adapter.js`, `src/js/operation/map-visual.js` |
| Acciones POS | `src/js/modules/punto-de-venta.js` |
| Ventana, mesa y acciones administrativas | `src/js/admin/reservations/operation.js`, `src/js/operation/reservation-operation-policy.js` |
| Colores, estados y modificadores | `src/scss/operation/_map-shell.scss` |
| Leyenda y montaje de la superficie | `views/operation/partials/map-legend.php`, `views/operation/partials/map.php`, `views/punto-de-venta/partials/pos-workspace.php`, `views/operation/reservations/index.php` |

Las pruebas contractuales revisadas incluyen `scripts/tests/run-reservaciones-mapa-presenter.php`, `run-reservaciones-mesa-facts.php`, `run-reservaciones-mapa-intervalo.php`, `run-reservaciones-pos-absence-visual.php`, `run-reservaciones-paridad.php` y `run-pos-visual-contract.php`.
