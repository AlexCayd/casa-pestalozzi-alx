# Reporte de implementación · Etapa 3 del Mapa de reservaciones

Fecha de cierre técnico: 29 de julio de 2026
Estado: implementación terminada, sin migraciones y sin commit.

## 1. Alcance realizado

Se unificó la representación operativa de mesas entre el Mapa de reservaciones y el POS. La etapa cubre:

- mapa siempre visible, incluso sin selección o sin reservaciones;
- contrato normalizado de mesas;
- advertencia de reservación próxima;
- bloqueo previo;
- ocupación física por tickets y walk-ins;
- reservaciones y tickets con varias mesas;
- exclusión de estados finales y retenciones vencidas;
- precedencia visual y accesibilidad;
- revalidación de mutaciones en backend;
- actualización temporal sin duplicar intervalos;
- pruebas automatizadas, HTTP, visuales y responsivas.

No se rediseñó la interfaz general, no se modificaron las transiciones de acciones rápidas, no se creó una migración y no se generó un commit.

## 2. Diagnóstico previo

### Mapa de reservaciones

Antes de esta etapa:

- `operationData` entregaba las mesas y conflictos de reservaciones, pero no incorporaba tickets abiertos ni walk-ins;
- el mapa dependía de que existiera una reservación seleccionada;
- el estado de carga ejecutaba `mapVisual.clear()` y sustituía visualmente el plano;
- la lista lateral representaba el día completo y no distinguía correctamente un horario vacío;
- la disponibilidad para reasignación sólo consideraba conflictos de reservaciones;
- no existía un estado común para próxima, bloqueada, ticket, walk-in y varias mesas.

### POS

Antes de esta etapa:

- calculaba en JavaScript las ventanas con `DURACION = 90` y `BLOQUEO = 30`;
- infería la advertencia de una hora mediante otro tramo fijo de 30 minutos;
- varias funciones repetían el cálculo de estado;
- el filtrado visual sólo descartaba explícitamente `cancelada`, por lo que otros estados finales podían continuar influyendo;
- el controlador repetía una consulta completa de tickets abiertos;
- los estados `proxima`, `llego`, `en-curso`, `walk-in` y `servicio-reservacion` dependían de clases particulares del POS.

### Backend compartido

Se encontraron estas diferencias:

- `ReservacionMesa::obtenerOcupacionDelDia()` repetía las cadenas de estados activos;
- `AsignacionMesasService` no incorporaba ocupación física al validar una asignación;
- `TicketMesa::ocupacionAbierta()` tenía la regla temporal correcta, pero consultaba y transformaba los tickets dentro de cada llamada;
- `PuntoVentaReservacionService::contextoMesa()` repetía estados activos;
- la advertencia de walk-in utilizaba la ventana estimada del servicio, 120 minutos, en lugar de la ventana de reservación próxima de 60 minutos;
- el POS conocía tickets y walk-ins, pero el Mapa de reservaciones no;
- una retención vencida estaba correctamente excluida en algunas consultas del backend, pero no existía una regla común para todos los serializadores y consumidores.

### Datos requeridos por `MapaVisual`

El componente necesitaba recibir un estado ya resuelto, con:

- identidad y etiqueta;
- geometría;
- capacidad y posibilidad de reservar;
- estado base;
- modificadores coexistentes;
- indicadores;
- contexto de reservación, ticket, walk-in y selección;
- texto accesible.

La decisión fue mantener `map-visual.js` como componente de presentación, sin trasladarle reglas de dominio.

## 3. Fuentes de verdad finales

La responsabilidad quedó separada de la siguiente forma:

1. `ReservacionConfig`
   - constantes temporales;
   - estados finales;
   - estados que ocupan;
   - condición SQL para ocupación activa;
   - validación de retención pendiente vigente;
   - configuración serializable para JavaScript.
2. `TicketMesa`
   - consulta N:M canónica de tickets abiertos;
   - ocupación física para una fecha y hora;
   - exclusión opcional de la reservación propia.
3. `MesaEstadoService`
   - normalización común de todas las mesas;
   - precedencia de estados;
   - clasificación temporal de reservaciones;
   - modificadores, indicadores y títulos accesibles.
4. `AsignacionMesasService`
   - revalidación autoritativa al guardar;
   - combinación de conflictos de reservaciones y tickets abiertos.
5. `MesaEstadoAdapter`
   - traducción del contrato normalizado al formato de dibujo de `MapaVisual`.
6. `MapaVisual`
   - creación de pines;
   - aplicación de clases e indicadores;
   - selección visual;
   - atributos accesibles;
   - emisión de eventos.

## 4. Contrato normalizado de mesa

Los endpoints del POS y del Mapa de reservaciones entregan `mesas_estado` con las mismas propiedades:

```text
id
numero
nombre
etiqueta
tipo
pos_x
pos_y
ancho
alto
capacidad
activo
reservable
estado_base
modificadores
indicadores
reservacion_proxima
minutos_restantes
reservacion_asociada
ticket_abierto
walk_in
seleccion_actual
motivo_bloqueo
titulo
```

Estados base:

- `disponible`;
- `ocupada`;
- `bloqueada`;
- `no_reservable`.

Modificadores aplicados según contexto:

- `reservacion_proxima`;
- `asignada`;
- `seleccion_actual`;
- `ticket_abierto`;
- `walk_in`;
- `varias_mesas`;
- `bloqueo_propio`, únicamente durante reasignación para excluir el conflicto de la reservación actual.

## 5. Precedencia y funcionamiento nuevo

La precedencia final es:

1. elemento inactivo o no reservable;
2. ticket o servicio abierto;
3. reservación actualmente ocupando;
4. bloqueo previo;
5. disponible con reservación próxima;
6. disponible.

Los modificadores no sustituyen el estado base. Esto permite, por ejemplo:

- ocupada + ticket abierto + walk-in + reservación próxima;
- disponible + reservación próxima + varias mesas;
- selección actual + asignada;
- bloqueada + reservación asociada;
- ocupada por ticket aunque la reservación relacionada esté finalizada.

La interfaz no depende sólo del color. Se añadieron:

- borde discontinuo para próxima;
- insignias `P`, `B`, `T`, `W` y número de mesas vinculadas;
- textos de leyenda;
- `title` y `aria-label` contextuales;
- aviso con `aria-live`;
- estado seleccionado con borde y contorno propios.

La reservación próxima utiliza un acento dorado de precaución, no rojo.

## 6. Reglas temporales

Todos los consumidores reciben su configuración desde `ReservacionConfig`:

| Regla | Valor |
|---|---:|
| Advertencia de reservación próxima | 60 minutos |
| Bloqueo previo | 30 minutos |
| Duración de reservación | 90 minutos |
| Tolerancia de llegada/no-show | 15 minutos |
| Refresco de estados | 60 segundos |

Para una reservación a las 20:30:

- 19:29: todavía no influye como próxima;
- 19:30 a 19:59: `disponible` + `reservacion_proxima`;
- 20:00 a 20:29: `bloqueada`;
- 20:30 a 21:59: `ocupada`;
- 22:00: termina la ventana de reservación.

La estimación de un ticket conserva su regla previa:

- servicio estimado: 120 minutos;
- margen de preparación: 15 minutos.

Esos valores no se cambiaron silenciosamente. Sólo se exponen junto con la configuración temporal y continúan utilizándose para estimar la ocupación física de tickets.

## 7. Mapa siempre visible

El Mapa de reservaciones ahora sigue esta secuencia:

1. dibuja todas las mesas de `mesas_estado`;
2. conserva la ocupación física;
3. superpone las asignaciones del horario;
4. superpone la selección actual, si existe;
5. muestra los estados vacíos sólo en la lista o el panel.

Comportamiento resultante:

- sin selección: todas las mesas siguen visibles y el panel indica “Selecciona una reservación”;
- sin reservaciones en el horario: todas las mesas siguen visibles y el panel/lista indican “No hay reservaciones para este horario”;
- al cambiar de selección: no persisten clases de la selección anterior;
- ya no se selecciona automáticamente la primera reservación;
- los tickets y walk-ins continúan visibles aunque la lista de reservaciones esté vacía.

## 8. Advertencia contextual

Al seleccionar una mesa con una reservación entre 31 y 60 minutos se muestra un aviso accesible con:

- título “Reservación próxima”;
- hora;
- minutos restantes;
- folio;
- cliente;
- comensales;
- mesas relacionadas.

El aviso:

- no usa `alert()`;
- no bloquea la selección;
- usa `role="status"` y `aria-live="polite"`;
- mantiene el estilo oscuro/editorial;
- no genera desbordamiento horizontal.

## 9. Bloqueo y validación de mutaciones

Entre 1 y 30 minutos antes:

- la mesa se dibuja como bloqueada;
- conserva la reservación asociada, la hora, los minutos y el motivo;
- no puede asignarse a otra reservación;
- no puede abrir un walk-in incompatible;
- el backend vuelve a consultar ocupación antes de guardar.

Durante una reasignación:

- la reservación seleccionada se excluye de sus propios conflictos;
- otras reservaciones siguen bloqueando;
- un ticket incompatible sigue bloqueando;
- se mantienen las validaciones previas de capacidad y número de mesas.

## 10. Tickets, walk-ins y varias mesas

`TicketMesa::abiertosParaMapa()` es ahora la consulta canónica compartida. Agrupa todas las relaciones de `ticket_mesas` una sola vez y permite que ambos mapas consuman la misma ocupación física.

El Mapa de reservaciones sólo recibe:

- identificador del ticket;
- reservación vinculada, si existe;
- mesas vinculadas;
- indicador de walk-in.

No se exponen importes, productos ni acciones sensibles del ticket.

Reglas:

- un ticket abierto ocupa todas sus mesas;
- un walk-in ocupa todas sus mesas y no aparece en la lista de reservaciones;
- un ticket prevalece aunque su reservación esté cancelada, completada o no-show;
- una relación de dos o tres mesas añade `varias_mesas`, una insignia con el total y texto accesible;
- todas las mesas comparten el mismo contexto de reservación o ticket.

## 11. Estados finales y retenciones

No influyen en disponibilidad:

- `expirada`;
- `completada`;
- `cancelada`;
- `no_show`;
- `pendiente_verificacion` con `hold_expires_at` vencido.

Una retención pendiente sólo influye mientras:

- el estado sea `pendiente_verificacion`;
- exista `hold_expires_at`;
- el vencimiento sea posterior al reloj de operación.

No existe `verification_expires_at` en el esquema vigente; la fuente aplicable es `hold_expires_at`.

No se implementó automatización n8n ni una herramienta para reparar inconsistencias históricas.

## 12. Actualización temporal, timers y listeners

Mapa de reservaciones:

- un único intervalo usa `refresco_estados_segundos`;
- antes de crear el intervalo se limpia el anterior;
- no refresca mientras la página está oculta o mientras se guarda/carga;
- `pagehide` limpia intervalo, timeout y solicitud activa;
- `pageshow` reinicia y actualiza;
- `visibilitychange` actualiza al volver a primer plano.

POS:

- el intervalo local de estado usa la configuración de 60 segundos;
- el polling operativo existente de 30 segundos se conserva porque también sincroniza acciones del POS;
- ambos iniciadores limpian su timer previo;
- `pagehide` limpia polling e intervalo local;
- `pageshow` reactiva una sola instancia.

No se añadió ninguna solicitud por segundo.

## 13. Código residual eliminado

Lista exacta de lógica sustituida o retirada:

- constantes JavaScript `DURACION = 90` y `BLOQUEO = 30`;
- función `snapTo30()`, reemplazada por intervalo configurable;
- ramas repetidas que sólo descartaban `cancelada`;
- cálculos repetidos `rIni`, `rFin` y el tramo adicional fijo de 30 minutos;
- función antigua `estadoMesaAhora()`;
- funciones `estadoVisualMapa()`, `clasesEstadoMapa()` y `normalizarMesaMapa()`;
- consulta SQL completa de tickets duplicada dentro de `PuntoVentaController::api`;
- listas SQL repetidas de estados activos en `ReservacionMesa`, `Reservacion` y contexto POS;
- llamada `mapVisual.clear('Cargando mapa')` del Mapa de reservaciones;
- normalización visual ad hoc dentro de `operation.js`;
- mensaje de día vacío usado en lugar del mensaje de horario vacío;
- selección automática de una reservación después de cargar;
- estilo rojo pulsante de `.mesa-pin--proxima`;
- animación `pulse-ring`;
- clases POS sustituidas `.mesa-pin--walk-in`, `.mesa-pin--servicio-reservacion`, `.mesa-pin--llego` y `.mesa-pin--en-curso`;
- alias visuales antiguos de ticket usados por el render del POS.

Se conservaron en `MapaVisual` algunos alias de clase antiguos únicamente como limpieza defensiva al actualizar un pin existente. Ya no son generados por los consumidores nuevos.

## 14. Archivos modificados

### Backend y dominio

- `services/ReservacionConfig.php`
- `services/MesaEstadoService.php` — nuevo
- `services/AsignacionMesasService.php`
- `services/PuntoVentaReservacionService.php`
- `models/Reservacion.php`
- `models/ReservacionMesa.php`
- `models/TicketMesa.php`
- `controllers/ReservacionOperacionController.php`
- `controllers/PuntoVentaController.php`

### Vistas

- `views/operation/partials/map-legend.php`
- `views/operation/reservations/index.php`
- `views/punto-de-venta/index.php`

### JavaScript

- `src/js/operation/table-state-adapter.js` — nuevo
- `src/js/operation/map-visual.js`
- `src/js/admin/reservations/operation.js`
- `src/js/modules/punto-de-venta.js`
- `gulpfile.js`

### SCSS

- `src/scss/operation/_feedback.scss`
- `src/scss/operation/_map-shell.scss`
- `src/scss/punto-de-venta/_punto-de-venta.scss`

### Pruebas

- `tests/ReservacionEtapa3Test.php`

### Artefactos compilados

- `assets/css/app.css`
- `assets/css/app.css.map`
- `public/build/css/app.css`
- `public/build/css/app.css.map`
- `public/build/css/operation/reservations.css`
- `public/build/js/admin/map.js`
- `public/build/js/admin/map.js.map`
- `public/build/js/admin/reservation-operation.js`
- `public/build/js/admin/reservation-operation.js.map`

La lógica nueva y las decisiones no evidentes incluyen comentarios de módulo o comentarios cercanos a la regla correspondiente.

## 15. Casos automatizados del 30/11/2026

Se validaron:

### Caso A · Reservación a las 20:30

- 19:29 sin advertencia;
- 19:30, 19:45 y 19:59 próxima;
- 20:00, 20:15 y 20:29 bloqueada;
- 20:30 ocupada;
- 22:00 liberada.

### Casos B a E · Estados y retenciones

- cancelada no bloquea ni ocupa;
- no-show no bloquea ni ocupa;
- pendiente vigente influye;
- pendiente vencida no influye.

### Casos F a H · Ocupación física

- walk-in visible como ticket, no como reservación;
- ticket abierto prevalece sobre estado final;
- tres mesas quedan vinculadas al mismo ticket;
- indicador y título accesible de varias mesas;
- cierre de ticket libera todas sus mesas.

### Casos I y J · Asignación

- la reservación propia se excluye del conflicto;
- otra reservación bloquea;
- ticket incompatible bloquea en backend;
- carreras simultáneas dejan un único ganador y ningún estado parcial.

### Plano vacío

- una fecha sin reservaciones conserva todas las mesas;
- conserva disponibles y elementos no reservables.

## 16. Resultados técnicos

### Sintaxis

- `php -l`: correcto en los 13 archivos PHP/vistas modificados.
- `node --check`: correcto en los 4 archivos JavaScript modificados y `gulpfile.js`.
- `git diff --check`: sin errores de espacios; sólo avisos de conversión LF/CRLF de Git.

### Pruebas automatizadas

`npm test`:

- Etapa 1: 37 comprobaciones;
- Etapa 2: 80 comprobaciones;
- Etapa 3: 99 comprobaciones;
- total: 216 comprobaciones correctas.

Los dos mensajes “forced assignment failure” y “forced otp failure” pertenecen a escenarios negativos intencionales de Etapa 2.

### Compilación

Correctos:

- `gulp adminReservationOperationJs`;
- `gulp adminMapJs`;
- `gulp operationCss`;
- `gulp css`.

La compilación global previa alcanzó los artefactos de esta etapa y después falló en un archivo ajeno, `public/build/js/admin/area.js`, por un bloqueo `EPERM` de Windows. Las tareas específicas se ejecutaron nuevamente y terminaron correctamente.

Sass mostró únicamente el aviso conocido de deprecación de la API JavaScript heredada. Node mostró el aviso conocido de `fs.Stats`.

## 17. Resultados HTTP

Se utilizó la cuenta demo documentada en `database/dml.sql`, sin modificar datos:

- login administrativo: correcto;
- `GET /admin/reservations/operation?fecha=2026-11-30&hora=20:30`: HTTP 200;
- aviso contextual presente en el HTML;
- assets `reservation-operation-v18` presentes;
- `GET /admin/api/reservations/operation?fecha=2026-11-30`: HTTP 200, `ok=true`;
- `GET /api/punto-de-venta?fecha=2026-11-30`: HTTP 200, `ok=true`;
- ambos endpoints devolvieron 16 mesas;
- ambos devolvieron exactamente las mismas claves del contrato;
- ambos devolvieron 60/30/90/15 y refresco de 60 segundos;
- `GET /admin/api/reservations/operation?fecha=2026-12-10`: 16 mesas y 0 reservaciones.

Esto confirma por HTTP que una fecha vacía no elimina el plano.

## 18. Resultados visuales y responsivos

Se montó un fixture temporal, posteriormente eliminado, con el DOM real del componente, los CSS compilados y seis combinaciones:

- disponible;
- ocupada + ticket + walk-in;
- bloqueada;
- próxima + varias mesas;
- próxima + varias mesas + selección actual;
- no reservable.

Anchos verificados sin overflow horizontal, sin pines fuera del mapa y con las seis mesas visibles:

| Ancho | Resultado |
|---:|---|
| 1440 px | Correcto |
| 1280 px | Correcto |
| 1024 px | Correcto |
| 900 px | Correcto |
| 768 px | Correcto |
| 640 px | Correcto |
| 480 px | Correcto |
| 390 px | Correcto |
| 360 px | Correcto |

También se verificó:

- leyenda sin overflow;
- aviso contextual sin overflow;
- próxima con borde discontinuo;
- selección actual distinguible;
- tickets y walk-ins con insignias;
- varias mesas con contador;
- etiquetas accesibles completas;
- consola del escenario visual sin errores.

Limitación de la revisión visual: la sesión disponible inicialmente en el navegador integrado no estaba autenticada. Por ello, la ruta real y sus APIs se validaron mediante HTTP autenticado, mientras que la revisión visual responsiva se ejecutó con un fixture temporal que reutilizó el markup y los estilos compilados del componente. No quedó ningún archivo temporal en el repositorio.

## 19. Decisiones tomadas

- “Próxima” se implementó como modificador, no como estado base.
- El ticket abierto representa verdad física y prevalece sobre una reservación final.
- La consulta de tickets se comparte y no se duplican datos sensibles.
- El Mapa de reservaciones refresca desde backend cada minuto para incorporar cambios del POS.
- El POS conserva su polling de 30 segundos y usa 60 segundos para recalcular la posición temporal local.
- La reservación propia puede reutilizar sus mesas durante reasignación; un ticket abierto no se autoexcluye.
- Los estados vacíos pertenecen a lista/panel y nunca sustituyen el mapa.
- Se conservaron las ventanas 120 + 15 de tickets porque no pertenecen a la regla 60/30/90 de reservaciones.
- No se necesitó migración: `hold_expires_at`, `ticket_mesas` y las relaciones requeridas ya existen.

## 20. Pendientes para la etapa de acciones rápidas

Quedaron deliberadamente fuera:

- rediseñar o cambiar llegada, inicio, completar, no-show y cancelar;
- cambiar la tolerancia de 15 minutos;
- añadir nuevas confirmaciones a transiciones de estado;
- automatizar expiraciones mediante n8n;
- crear una herramienta de reparación para tickets abiertos ligados a reservaciones finales;
- modificar el modal o el flujo general de asignación más allá de los estados necesarios.

Antes de un despliegue, conviene completar una pasada visual final dentro de una sesión administrativa real del navegador integrado y liberar el bloqueo de Windows sobre `admin/area.js` para ejecutar la compilación global en una sola corrida.
