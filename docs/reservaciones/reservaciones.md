# Reservaciones

Fuente normativa de horarios consultados, capacidad, asignación, operación
administrativa y proyección visual de mesas. Las decisiones de asignación y
operación las confirma el backend; el mapa presenta hechos, no concede permisos.

## Contexto temporal y disponibilidad

Toda consulta pertenece a una combinación de `fecha + hora`. Al cambiar de
fecha se descarta la hora anterior si no es válida para el nuevo día y se usa el
primer horario reservable. Una fecha pasada es de sólo lectura; una fecha actual
sin horarios futuros no admite nuevas reservaciones.

El intervalo de una reserva es semiabierto:

```text
[hora_consulta, hora_consulta + DURACION_RESERVACION_MINUTOS)
```

La disponibilidad y la capacidad se calculan para el intervalo completo y deben
pertenecer al mismo snapshot de fecha y hora que el mapa. Los hechos
`ocupada_fisicamente`, `bloqueada_en_intervalo`, `disponible_para_asignacion`,
`disponible_para_ticket` y `ausencia_pendiente` son distintos. Una señal visual
no sustituye ninguno de ellos. La capacidad usa capacidad física, mesas no
reservables, reservas y holds, tickets bloqueantes y demanda sin asignar según
corresponda al intervalo.

Un ticket realmente abierto mantiene la ocupación física actual. Su proyección
puede dejar de bloquear un intervalo futuro después de su liberación estimada;
eso no declara cerrado el ticket. Para otra fecha, un ticket abierto actual no
bloquea automáticamente la asignación futura. Holds vigentes y reservas
confirmadas bloquean cuando se cruzan con el intervalo. Los holds vencidos y
estados finales no bloquean por sí mismos.

La fuente temporal es `ReservacionConfig` y la clasificación de
`ReservacionVigenciaService` / `ReservacionPoliticaPosService`. Los presenters
traducen hechos ya resueltos; JavaScript sólo adapta el contrato e interactúa.

## Asignación, capacidad y operación

La asignación automática y manual consumen `disponible_para_asignacion`,
`bloqueada_en_intervalo` y sus causas para la fecha y hora solicitadas. La
mutación vuelve a validar en backend; el navegador no es fuente de disponibilidad.

Capacidad operativa y asignación de mesas son decisiones distintas. En
administración puede pedirse asignación automática hasta 12 personas; grupos
mayores quedan para asignación manual. Si no hay propuesta automática, se
desactiva o la capacidad estimada no alcanza, guardar sin mesas requiere una
advertencia y confirmación explícitas. El límite y las validaciones de la
landing pública no se amplían por las excepciones administrativas.

`Nueva reservación` requiere fecha y horario válidos, contexto cargado sin error,
modo editable y permisos. Fecha, hora y mesas se vuelven a validar en el backend
al crear o modificar.

La creación, asignación y cierre no disparan la sincronización del buzón. La
sincronización administrativa se hace por su endpoint protegido y presenta
ausencias pendientes, necesidades de asignación y coordinaciones que requieren
acción. Una ausencia de prioridad alta requiere registrar el no-show mediante
la acción de dominio; leer una alerta no la resuelve.

La lista `/admin/reservaciones` excluye por defecto `pendiente_verificacion` y
`expirada`; un filtro explícito aún permite consultarlas. Las retenciones vigentes
siguen en los cálculos canónicos de capacidad y disponibilidad.

## Mapas y estados visuales

## Responsabilidad de las reglas

El flujo de presentación es:

```text
ReservacionConfig
    ↓
ReservacionVigenciaService ── ReservacionPoliticaPosService
    ↓
MesaEstadoService
    ↓
PosMesaProjectionPresenter ── ReservacionMapaMesaPresenter
    ↓ contrato JSON
map-contract.js
    ↓
table-state-adapter.js
    ↓
map-visual.js
```

| Regla | Responsable | Resultado producido | Consumidor |
| --- | --- | --- | --- |
| Umbrales de advertencia, bloqueo y tolerancia (`60`, `30` y `15` minutos) | `ReservacionConfig` | Valores temporales canónicos | `ReservacionVigenciaService` y `ReservacionPoliticaPosService` |
| Clasificación temporal y elegibilidad de no-show | `ReservacionVigenciaService` | Ventana temporal, influencia operativa y `ausencia_pendiente` | Política POS, estado de mesa y acciones de Reservaciones |
| Política de ticket y walk-in | `ReservacionPoliticaPosService` | `disponible_para_ticket`, advertencia y bloqueo de walk-in | `MesaEstadoService` y POS |
| Ocupación física actual | `MesaEstadoService` | `ocupada_fisicamente` y hechos de tickets abiertos | Presenters POS y de Reservaciones |
| Bloqueo del intervalo consultado | `OcupacionMesasService` | Mesas bloqueadas y causas para fecha/hora | `MesaEstadoService` y asignación |
| Disponibilidad final para asignación | `AsignacionMesasService`; `MesaEstadoService` proyecta el hecho de lectura | `disponible_para_asignacion` y causa de conflicto | Operación de Reservaciones y endpoint de asignación |
| Capacidades POS de Mesa, Barra, Caja, Llevar y elementos decorativos | `MesaEstadoService` | `capacidades_pos` derivadas de tipo, nombre, actividad y reservabilidad | `punto-de-venta.js` |
| Estado visual POS | `PosMesaProjectionPresenter` | Estado base, modificadores y etiqueta accesible del POS | POS y contrato JSON |
| Estado visual de Reservaciones | `ReservacionMapaMesaPresenter` | Estado base, modificadores y etiqueta del intervalo | Operación de Reservaciones y contrato JSON |
| Color, borde e indicador visual | Tokens y clases de `_map-shell.scss` | Estilos para el estado y modificadores recibidos | `map-visual.js` |
| Selección de mesas POS | `punto-de-venta.js` | Permiso de selección para la acción y snapshot actuales | Adaptador y renderer |
| Selección para asignación | `operation.js` más `AsignacionMesasService` en la mutación | Permiso visual de selección; el servidor vuelve a validar la asignación | Adaptador, renderer y endpoint de asignación |
| Contrato visual inválido | `map-contract.js` | Estado seguro `no-utilizable`, no verificado, sin selección ni interacción | Adaptador y consumidores del mapa |
| Snapshot HTTP inválido, timeout y contexto stale | El consumidor HTTP (`punto-de-venta.js` u `operation.js`) | Aceptar o descartar el snapshot y mensaje de actualización | Pantalla del POS u operación de Reservaciones |
| Dibujo, ARIA y eventos de interacción | `map-visual.js` | Pines, lista accesible, selección visual y eventos | `punto-de-venta.js` y `operation.js` |

`ReservacionConfig` concentra los valores temporales que usan los clasificadores.
`ReservacionVigenciaService` determina la vigencia y la ausencia;
`ReservacionPoliticaPosService` traduce esos hechos a permisos de POS.

`MesaEstadoService` reúne ocupación, bloqueos del intervalo, reservaciones y
capacidades POS en hechos de mesa. No vuelve a calcular el intervalo ni los
umbrales temporales.

`PosMesaProjectionPresenter` y `ReservacionMapaMesaPresenter` traducen esos
hechos a estados, modificadores y etiquetas propias de cada contexto. No
consultan el DOM ni conceden permisos de mutación.

El contrato JSON entrega por separado hechos y proyección visual.
`map-contract.js` valida únicamente los cuatro estados visuales permitidos y
los modificadores.

`table-state-adapter.js` produce el objeto visual que necesita el mapa:
normaliza el contrato, posiciones, atributos, selección solicitada y texto de
presentación. No decide disponibilidad ni reglas temporales.

`map-visual.js` dibuja los objetos adaptados, mantiene ARIA y la lista accesible
y emite interacciones. El consumidor le pasa cualquier aviso general y si debe
bloquear operaciones dependientes.

`map-contract.js` no usa DOM. Los cuatro estados base admitidos son `libre`,
`ocupada`, `reservacion-proxima` y `no-utilizable`. La cadena heredada
`seleccionada` sólo se acepta con un estado previo válido y se convierte en
selección secundaria; nunca reemplaza el estado base.

Al iniciar o fallar una consulta, el consumidor marca el snapshot como
desactualizado y pasa al mapa el aviso y la instrucción de bloquear operaciones
dependientes. El renderer aplica esa instrucción al dibujo; no infiere el estado
de la red ni clasifica la respuesta HTTP.

### Elementos especiales del POS

Las capacidades se derivan en `MesaEstadoService` de los campos existentes. No
requieren columnas nuevas. Llevar conserva su flujo propio de pedido, y Caja su
acción de corte; ambos se mantienen independientes del snapshot de disponibilidad.
El objeto `capacidades_pos` contiene `reservable`, `operable`, `ticketable`,
`independiente_consulta`, `abrir_caja`, `crear_pedido_llevar`,
`mostrar_estado_ticket`, `decorativo` y `etiqueta_operacion`.

| Elemento | Participa en Reservaciones | Operable en POS | Ticketable | Depende del snapshot | Responsable de la capacidad |
| --- | --- | --- | --- | --- | --- |
| Mesa activa y reservable | Sí | Sí | Sí | Sí | `MesaEstadoService` |
| Barra activa | No | Sí | Sí | Sí | `MesaEstadoService` |
| Caja activa | No | Sí | No; abre el corte | No | `MesaEstadoService` |
| Llevar activo | No | Sí | Flujo propio de pedido | No | `MesaEstadoService` |
| Elemento decorativo activo | No | No | No | No | `MesaEstadoService` |
| Mesa desactivada | No | No | No | No | `MesaEstadoService` |

### Qué no hace el frontend

El frontend no decide la ventana que bloquea una mesa, el fin de la tolerancia,
si una ausencia ya permite marcar no-show, la capacidad, la disponibilidad, si
una mesa se puede asignar ni si se puede abrir un ticket. Valida la estructura y
el contexto de la respuesta HTTP, presenta las proyecciones del servidor,
descarta contratos inválidos de forma segura, gestiona la interacción y solicita
la mutación al backend para su validación final.

El POS representa la operación actual. El mapa de Reservaciones representa la
fecha y hora consultadas. La misma mesa puede verse distinta en esos contextos.

| Hecho o ventana | POS — operación actual | Reservaciones — intervalo consultado |
| --- | --- | --- |
| Disponible | Verde cuando el backend permite abrir ticket. | Verde cuando está disponible para el intervalo. |
| Advertencia `>30` y `≤60` min | Verde con borde azul discontinuo si walk-in sigue permitido. | Indicador secundario; el fondo conserva la disponibilidad real del intervalo. |
| Próxima `>0` y `≤30` min | Azul sólido; walk-in bloqueado. | Azul cuando la reserva bloquea el intervalo; un conflicto independiente conserva rojo. |
| Inicio `00:00` | Azul mientras se espera al cliente, si aún no hay ticket. | Rojo si la reserva ocupa el intervalo seleccionado. |
| Tolerancia `00:00` a `+15:00` inclusive | Azul con señal de tolerancia; el backend decide si se puede iniciar el servicio. | Rojo mientras el intervalo siga bloqueado por la reserva. |
| Ausencia pendiente, después de `+15:00` | Azul oscuro de reserva bloqueante, con indicador de acción pendiente. No libera la mesa; sólo ofrece no-show si `puede_marcar_no_show` es verdadero. | La disponibilidad del intervalo decide el fondo; la ausencia queda como indicador secundario. No-show elimina sus modificadores y se recalcula cualquier bloqueo restante. |
| Ticket abierto | Rojo mientras exista ocupación física real. | Rojo sólo si el ticket bloquea el intervalo; una proyección futura puede liberarse sin cerrar el ticket. |
| No utilizable | Neutro y por encima de otros estados. | Neutro y por encima de otros estados. |
| Seleccionada | Anillo amarillo superpuesto; conserva el hecho base. | Anillo amarillo superpuesto; conserva el hecho base. |

### Prioridad visual

Un elemento no utilizable permanece neutro. En POS, ticket abierto conserva rojo;
en Reservaciones, ticket o conflicto que bloquea el intervalo conservan rojo.
Inicio y tolerancia se muestran según el contexto anterior. Advertencia, ausencia,
asignación y selección son señales secundarias: no vuelven libre un conflicto.
La selección es una capa visual y **no garantiza disponibilidad** ni autoriza
abrir ticket, asignar o reasignar mesa, iniciar servicio ni marcar no-show.

`no-utilizable` representa una restricción conocida. `estadoNoVerificado` es un
indicador exclusivo de presentación para el fallback de un contrato visual
ausente, desconocido o incompleto; conserva el fondo neutro, muestra una señal
de advertencia y bloquea interacción hasta recibir un estado válido. No añade
estados de backend. El frontend nunca interpreta un contrato desconocido como
disponibilidad.

Una respuesta general HTTP fallida, incompleta o de otra fecha u hora se
descarta y muestra el aviso general de actualización; no convierte todas las
mesas en elementos no utilizables. Si POS conserva la fotografía anterior,
la identifica como desactualizada y bloquea las operaciones que dependen de
esa consulta. POS reconoce el permiso operativo de Barra y de elementos
especiales activos aunque `reservable = false`; el estado y los tickets siguen
respetando los hechos de POS disponibles. Caja y Llevar conservan sus acciones
propias y no heredan la disponibilidad de una mesa ordinaria.

El fondo siempre expresa el estado base de la superficie consultada. El borde y
los iconos añaden alertas; por ejemplo, una reserva cercana conserva el fondo
rojo de una mesa ocupada y añade un borde azul discontinuo. En POS, una ausencia
pendiente después de la tolerancia usa el fondo azul oscuro de la reservación;
en Reservaciones, su indicador se superpone al color que marque la disponibilidad
del intervalo. El anillo amarillo indica selección: nunca reemplaza el fondo ni
la disponibilidad. POS representa la operación actual y Reservaciones la fecha
y hora elegidas, por lo que una misma señal puede combinarse con fondos
diferentes según el contexto.

### POS y proyección administrativa

POS clasifica la hora de operación actual. Reservaciones proyecta sobre la fecha
y hora elegidas, respetando sus flags de disponibilidad del intervalo. Una
reserva fuera del horario efectivo puede permanecer en la lista administrativa
con `fuera_horario_operacion = true` y `en_proyeccion_mapa = false`; esa fila no
colorea el pin. Un ticket, hold u otro bloqueo independiente sí conserva su
presentación propia. El POS mantiene un indicador textual fuera de horario.

### Límites temporales

| Diferencia con el inicio | Clasificación |
| --- | --- |
| `> 60 min` | Futura; POS sin alerta temporal. |
| `> 30` y `≤ 60 min` | Advertencia. |
| `≥ 0` y `≤ 30 min` | Bloqueo previo; el inicio exacto se presenta como inicio de servicio. |
| Desde el inicio hasta `+15 min` inclusive | Tolerancia. |
| `> +15 min` | Ausencia pendiente cuando sigue confirmada y no hay ticket. |

El intervalo planificado dura 90 minutos y no incluye su límite final. Los
umbrales los define `ReservacionConfig`; no se duplican en JavaScript.

### Modal de ayuda

POS y Reservaciones comparten «Cómo interpretar el mapa de mesas». Explica
«Estado de las mesas» y «Señales adicionales», con selección como capa y una
nota breve: los colores orientan y las acciones se validan en la operación.
Reservaciones no mantiene otra leyenda permanente. El modal contextualiza el
estado actual en POS y la fecha y hora consultadas en Reservaciones.

## Cambios de horario y afectaciones

Si una modificación del horario efectivo deja una reserva fuera de operación,
el sistema conserva la reserva y crea seguimiento por reservación. No cambia
automáticamente fecha, hora, mesas, comensales ni estado; tampoco cancela ni
reprograma la reserva. La proyección administrativa conserva la fila para
seguimiento y la excluye del mapa mediante `en_proyeccion_mapa = false`.

| Caso | Tratamiento |
| --- | --- |
| Hasta 12 personas, con contacto | Se prepara un acceso para que el cliente responda; el buzón muestra espera. |
| Hasta 12, sin contacto | El caso queda accionable; agregar un contacto válido prepara el acceso. |
| Más de 12 personas | Requiere gestión administrativa; no se crea autoservicio automático. |

La administración puede abrir la reserva, actualizar contacto, modificar o
cancelar cuando corresponda, asignar mesas con las reglas canónicas y cerrar el
seguimiento si la atenderá fuera del sistema. Cerrar seguimiento sólo retira el
pendiente administrativo; no altera la reservación. Cada reserva afectada se
gestiona de forma independiente.

La elegibilidad, vigencia, intentos y transporte de avisos son parte de
[Notificaciones de reservaciones](notificaciones.md); esa fuente también define
qué acredita `accepted` y cómo se tratan fallos o resultados inciertos.

## Roles operativos

El mapa operativo de reservaciones es una superficie compartida por los roles
`admin` y `waiter`. Cada mutación vuelve a validar los permisos y reglas en
backend. El manejo de datos personales por rol corresponde a
[Privacidad](../privacidad/privacidad.md).

## Referencias

- [Configuración](../config.md)
- [Operación POS e impresión](../operacion.md)
- [Usuarios](../usuarios/usuarios.md)
- [Privacidad](../privacidad/privacidad.md)
