# Reservaciones

Fuente normativa de horarios consultados, capacidad, asignación, operación
administrativa y proyección visual de mesas. Las decisiones de asignación y
operación las confirma el backend; el mapa presenta hechos, no concede permisos.

## Contexto temporal y disponibilidad

Toda consulta pertenece a una combinación de `fecha + hora`. Al cambiar de
fecha se descarta la hora anterior si no es válida para el nuevo día y se usa el
primer horario reservable. Una fecha pasada es de sólo lectura; una fecha actual
sin horarios futuros no admite nuevas reservaciones.

El mapa admite consultas por minuto dentro de la jornada operativa. Eso no
cambia los horarios de 30 minutos disponibles al crear una reservación.

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
| Ventana de reservación proyectada para el mapa | `ReservacionPoliticaPosService` | `ventana_mapa` relativa a la hora seleccionada | `MesaEstadoService` |
| Disponibilidad final para asignación | `AsignacionMesasService`; `MesaEstadoService` proyecta el hecho de lectura | `disponible_para_asignacion` y causa de conflicto | Operación de Reservaciones y endpoint de asignación |
| Capacidades POS de Mesa, Barra, Caja, Llevar y elementos decorativos | `MesaEstadoService` | `capacidades_pos` derivadas de tipo, nombre, actividad y reservabilidad | `punto-de-venta.js` |
| Estado visual POS | `PosMesaProjectionPresenter` | Estado base, modificadores y etiqueta accesible del POS | POS y contrato JSON |
| Estado visual de Reservaciones | `ReservacionMapaMesaPresenter` | Estado base, modificadores y etiqueta de la proyección puntual | Operación de Reservaciones y contrato JSON |
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

| Hecho | Significado |
| --- | --- |
| `bloqueada_en_intervalo` | Existe una ocupación que cruza el intervalo consultado de 90 minutos. |
| `disponible_para_asignacion` | La mesa puede elegirse en el contexto de la operación, incluidas las excepciones de conservación de la propia asignación. |
| `ventana_mapa` | Futura, advertencia, bloqueo previo, inicio/activa o irrelevante respecto al instante consultado. |
| `estado_visual_mapa` | Proyección visual de la mesa en la hora consultada: libre, ocupada, reservacion-proxima o no-utilizable. |
| `seleccionada` | Capa de interfaz; no cambia el estado visual ni la disponibilidad. |

Una reservación propia puede conservar su estado proyectado —verde, azul o
rojo— y a la vez tener `disponible_para_asignacion: true` si la mutación excluye
esa misma reserva del cálculo de conflicto.

En Reservaciones, el fondo representa la hora seleccionada. Una reserva dentro
de `[inicio, inicio + 90 min)` es roja; el bloqueo preventivo de los 30 minutos
anteriores es azul; la advertencia entre 30 y 60 minutos es verde con borde
azul discontinuo; después de 60 minutos conserva verde sin alerta. Las etiquetas
son “Ocupada por reservación”, “Reservación próxima”, “Disponible con
reservación próxima” y “Disponible”, respectivamente.

`OcupacionMesasService::intervalosSeTraslapan()` decide el bloqueo del intervalo
y la asignabilidad. `ventana_mapa` decide la proyección visual. Una consulta
10:45 con una reserva a las 12:00 queda verde, aunque
`bloqueada_en_intervalo: true` y `disponible_para_asignacion: false`. Los campos
`reservacion_cercana_mapa` y `reservacion_influye_en_consulta` mantienen usos de
compatibilidad y no deciden el color.

Una reserva confirmada dentro del periodo programado se proyecta roja incluso
si POS informa ausencia pendiente. La ausencia añade un indicador y una acción;
no cambia la ventana proyectada. Después de persistir `no_show`, la reserva deja
de proyectarse y se vuelven a evaluar los demás bloqueos.

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

| Hecho o ventana | POS — operación actual | Reservaciones — instante consultado |
| --- | --- | --- |
| Disponible | Verde cuando el backend permite abrir ticket. | Verde si no hay reserva activa, bloqueo previo, ticket proyectado ni restricción independiente. |
| Advertencia `>30` y `≤60` min | Verde con borde azul discontinuo si walk-in sigue permitido. | Verde con borde azul discontinuo y “Disponible con reservación próxima”. |
| Próxima `>0` y `≤30` min | Azul sólido; walk-in bloqueado. | Azul con “Reservación próxima”; la reserva aún no inició. |
| Inicio `00:00` | Azul mientras se espera al cliente, si aún no hay ticket. | Rojo: “Ocupada por reservación”. |
| Dentro de `[inicio, fin)` | POS conserva espera, tolerancia y ausencia; un ticket físico es rojo. | Rojo durante los 90 minutos programados, incluso si aún no existe ticket. |
| Ausencia pendiente, después de `+15:00` | Azul oscuro de reserva bloqueante, con indicador de acción pendiente. No libera la mesa; sólo ofrece no-show si `puede_marcar_no_show` es verdadero. | La proyección sigue roja si la consulta cae dentro del periodo programado; la ausencia queda como indicador secundario. |
| Ticket abierto | Rojo mientras exista ocupación física real. | Rojo hasta la liberación estimada; después puede ser verde aunque POS siga rojo físicamente. |
| No utilizable | Neutro y por encima de otros estados. | Neutro y por encima de otros estados. |
| Seleccionada | Anillo amarillo superpuesto; conserva el hecho base. | Anillo amarillo superpuesto; conserva el hecho base. |

### Prioridad visual

Un elemento no utilizable permanece neutro. En POS, un ticket abierto conserva
rojo. En Reservaciones, un ticket proyectado, una restricción independiente y
una reserva activa son rojos; el bloqueo previo es azul y la advertencia conserva
verde. Una advertencia puede acompañar el rojo de un ticket. Ausencia, asignación
y selección son señales secundarias.
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

En Reservaciones, el fondo representa la proyección temporal en la hora
consultada; la asignabilidad sigue representando el intervalo completo de 90
minutos. El borde discontinuo expresa una advertencia, y el anillo amarillo
indica selección sin cambiar el estado base. POS conserva su política de
ocupación física.

Ejemplos del límite semiabierto `[inicio, fin)`:

- Reservación `12:00`: consulta `10:30` queda verde y asignable; `10:45` queda
  verde, pero no asignable porque `[10:45, 12:15)` se solapa con la reserva.
- La misma reserva: `11:00` queda verde con advertencia; `11:30` azul;
  `[12:00, 13:30)` roja; `13:30` verde por el límite final exclusivo.
- Ticket abierto a `09:10`, con liberación estimada a `10:40`: consulta `10:30`
  queda roja; `10:40` queda verde. POS sigue rojo mientras continúe abierto.
- La selección añade un anillo amarillo y conserva el fondo verde, azul o rojo.

### POS y proyección administrativa

POS clasifica la operación actual. Reservaciones proyecta el estado de la mesa
en la fecha y hora elegidas, y calcula por separado si puede comprometerse
durante todo el intervalo siguiente. Una
reserva fuera del horario efectivo puede permanecer en la lista administrativa
con `fuera_horario_operacion = true` y `en_proyeccion_mapa = false`; esa fila no
colorea el pin. Un ticket, hold u otro bloqueo independiente sí conserva su
presentación propia. El POS mantiene un indicador textual fuera de horario.

### Límites temporales

| Diferencia con el inicio | POS — operación actual | Reservaciones — hora consultada |
| --- | --- | --- |
| `> 60 min` | Futura; sin alerta temporal. | Verde, sin alerta. |
| `> 30` y `≤ 60 min` | Advertencia. | Verde con borde azul discontinuo. |
| `> 0` y `≤ 30 min` | Walk-in bloqueado. | Azul, reservación próxima. |
| `0` hasta `+15 min` inclusive | Espera o Tolerancia POS. | Rojo por periodo activo. |
| Después de `+15 min` | Ausencia pendiente si sigue confirmada y no hay ticket. | Rojo hasta el fin programado, con indicador de ausencia si aplica. |
| `inicio + 90 min` exacto o posterior | POS depende de la realidad física. | La reserva deja de ocupar el mapa. |

El intervalo planificado dura 90 minutos y no incluye su límite final. Los
umbrales y duraciones los define `ReservacionConfig`; no se duplican en
JavaScript.

### Modal de ayuda

POS y Reservaciones comparten «Cómo interpretar el mapa de mesas». Explica
«Estado de las mesas» y «Señales adicionales», con selección como capa y una
nota breve: los colores orientan y las acciones se validan en la operación.
Reservaciones no mantiene otra leyenda permanente. El modal contextualiza el
estado actual en POS y la fecha y hora consultadas en Reservaciones.

### Fixtures visuales locales

`php scripts/dev/seed-map-visual-validation.php` crea o actualiza mesas,
reservaciones, retenciones y tickets identificados con `[MAP TEST]` en la base
local de desarrollo. Imprime IDs, horas y URLs de `http://localhost:3000` para
la reservación de referencia y la proyección de ticket. Los fixtures se dejan
persistidos para revisión manual; el seeder no tiene operación de limpieza.

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

## Autoridad de reglas y persistencia

| Regla / dato | Responsable | Persistencia | Consumidores |
| --- | --- | --- | --- |
| Estados y vigencia que influyen en disponibilidad | `ReservacionConfig` y `ReservacionVigenciaService` | `reservaciones` | Ocupación, capacidad, disponibilidad y POS |
| Lectura básica por ID y `request_token` | `Model\Reservacion` | `reservaciones` | Services de reservaciones y POS |
| Lectura completa con lock por ID | `Model\Reservacion::buscarFilaPorIdParaActualizar()` | `reservaciones`, dentro de una transacción | Mutaciones administrativas y POS |
| Lectura de `request_token` con lock | `ReservacionPublicaService` conserva su lectura transaccional especializada | `reservaciones`, dentro de la creación idempotente | Creación pública; no hay otro consumidor bloqueante equivalente |
| Asignación y relación reservación ↔ mesa | `Model\ReservacionMesa` persiste; `AsignacionMesasService` decide | `reservacion_mesas` | Ocupación y mutaciones de asignación |
| Mesas y tickets abiertos | `Model\Mesa` y `Model\TicketMesa` | `mesas`, `tickets`, `ticket_mesas` | Contexto diario de ocupación |
| Solapamiento de intervalos | `OcupacionMesasService::intervalosSeTraslapan()` | — | Ocupación, capacidad, disponibilidad y asignación |
| Hechos de ocupación para un horario | `OcupacionMesasService` | Lee los Models de mesas, tickets y reservas | Capacidad, disponibilidad, administración y asignación |
| Capacidad disponible y demanda sin asignar | `CapacidadReservacionesService` | Lee `Model\ReservacionMesa` para la demanda | Disponibilidad pública y administrativa |
| Horarios candidatos y validación temporal | `HorarioReservacionService` | Configuración de operación | Disponibilidad pública y administrativa |
| Disponibilidad pública | `DisponibilidadReservacionService` | — | Controladores públicos |
| Excepciones y respuesta administrativa | `ReservacionAdministrativaService` | Models y Services de dominio | Controladores administrativos |
| Mutación pública | `ReservacionPublicaService` | `Model\Reservacion`, `Model\ReservacionMesa` y Models relacionados | Controladores públicos |
| Selección y validación de mesas | `AsignacionMesasService` | `Model\ReservacionMesa` persiste el resultado | Creación, administración y POS |

La búsqueda ordinaria por `request_token` reutiliza
`Model\Reservacion::buscarPorRequestToken()`. La lectura bloqueante de ese token
permanece en el flujo público de creación porque tiene un único consumidor y su
`FOR UPDATE` forma parte de la protección transaccional de idempotencia. Las
lecturas bloqueantes por ID repetidas usan el método del Model cuyo nombre
explicita el lock; el orden de adquisición de locks de cada operación se
conserva.

### Flujo de disponibilidad

```text
Fecha + horario solicitado
        ↓
HorarioReservacionService
        ↓
contexto de ocupación del día (una carga)
        ↓
OcupacionMesasService
        ↓
hechos de ocupación para cada intervalo
        ↓
CapacidadReservacionesService
        ↓
DisponibilidadReservacionService
        ↓
respuesta pública / administrativa
```

`HorarioReservacionService` resuelve la fecha y valida los horarios candidatos.
`OcupacionMesasService` prepara los hechos persistidos del día una vez y los
evalúa en memoria para cada intervalo; no modifica los datos.
`CapacidadReservacionesService` combina mesas libres con demanda confirmada sin
asignar. Disponibilidad pública y administración comparten esos hechos, pero
conservan sus decisiones y respuestas propias.

### Flujo de asignación

```text
Reservación
    ↓
AsignacionMesasService
    ↓
consulta de ocupación canónica
    ↓
selección / validación
    ↓
Model\ReservacionMesa
    ↓
persistencia
```

`AsignacionMesasService` **decide** qué mesas seleccionar y valida la mutación.
`ReservacionMesa` **persiste** la relación; no decide capacidad, disponibilidad
ni selección. La mutación vuelve a consultar y validar dentro de la transacción.

### Semántica de solapamiento y rendimiento

Los intervalos son semiabiertos. Dos intervalos se solapan cuando
`inicioA < finB` y `inicioB < finA`; por eso `[12:00, 13:30)` y
`[13:30, 15:00)` son consecutivos y no se solapan. La misma autoridad de
ocupación proporciona el predicado SQL estricto usado para seleccionar demanda
sin asignar; su lectura y la evaluación en memoria comparten los límites
exclusivos. Las pruebas de ocupación verifican el inicio, el límite final y el
caso consecutivo.

La preparación diaria se mide con un fake DB de test y diez horarios. Al evaluar
cada slot desde cero, el runner cuenta 10 consultas de asignaciones, 10 de
tickets, 20 de mesas (mapa y catálogo reservable) y 10 de demanda por intervalo.
Con contexto compartido cuenta 1 consulta de asignaciones, 1 de tickets, 1 de
mesas y 1 de demanda diaria; las diez evaluaciones posteriores no consultan DB.
El runner compara además los hechos de ocupación y capacidad de cada horario.

Las consultas de administración/reporting y los locks que tienen alcance
transaccional propio permanecen en los Services cuando no representan una
lectura básica reutilizada. En particular, una evaluación bloqueante mantiene
su consulta de demanda acotada al intervalo y el orden actual de locks; el
contexto diario compartido se usa para recorridos de lectura de varios slots.

## Referencias

- [Configuración](../config.md)
- [Operación POS e impresión](../operacion.md)
- [Usuarios](../usuarios/usuarios.md)
- [Privacidad](../privacidad/privacidad.md)
