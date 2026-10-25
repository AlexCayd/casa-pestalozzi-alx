# Reservaciones

Fuente normativa de horarios consultados, capacidad, asignación, operación y
proyección visual de mesas. Las decisiones de asignación y operación las
confirma el backend; el mapa presenta hechos, no concede permisos.

La operación de reservaciones vive **en el POS** (`/punto-de-venta`). La antigua
pantalla «Operación de reservaciones» se retiró: sus direcciones
(`/admin/reservaciones/operacion` y `/admin/reservations/operation`) responden
302 al POS conservando fecha, reservación y modo, y sus APIs ya no existen. El
panel administrativo es estratégico: histórico, agenda de grupos grandes y
casos accionables, sin mapa.

## Contexto temporal y disponibilidad

Toda consulta pertenece a una combinación de `fecha + hora`. Al cambiar de
fecha se descarta la hora anterior si no es válida para el nuevo día y se usa el
primer horario reservable. Una fecha pasada es de sólo lectura para crear; una
fecha actual sin horarios futuros no admite nuevas reservaciones.

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

### Máximo de comensales y grupos grandes

El máximo que la landing acepta por su cuenta es **configurable** (Configuración
→ Reservaciones, `configuracion_reservaciones.max_comensales_publico`, de 7 a
16, por defecto 12). Por encima, la landing no reserva: entra en modo «grupo
grande» y manda al WhatsApp del restaurante con el mensaje prellenado (tamaño
del grupo y, si ya se eligieron, fecha y hora). El número de WhatsApp vive en la
misma pantalla (`whatsapp_reservas`) y lo usan también las catas; si está vacío
se usa `RESERVAS_WHATSAPP` y después el teléfono público.

`ReservacionConfig::maxComensalesPublico()` y `umbralGrupoGrande()` devuelven
el mismo valor; la diferencia es de intención en cada llamada. Lee
`ReservacionAjustesService`, con caché por petición y valores por defecto si
falta la columna o la base.

El personal registra los grupos mayores al máximo a mano, desde «Nueva
reservación» en el POS, hasta `MAX_COMENSALES_ADMIN` (44). Esos grupos no
tienen asignación automática: sus mesas se eligen en el mapa **antes** de que
la reservación exista («Elegir mesas»), y se crea con ellas en una sola
petición.

Lo que sí tienen es una **propuesta**: la disponibilidad devuelve, por hora,
`mesa_ids_sugeridas` (`AsignacionPlanificadorService::sugerirGrupoGrande()`),
y el mapa abre con esas mesas ya marcadas para ajustarlas. Se llena por
cercanía —primero el exterior entero, después el interior por filas desde la
más próxima a él— con sólo las mesas libres sin ticket proyectado, y se sueltan
las que sobren al final. Si lo libre no alcanza no se propone nada. No es una
asignación: el servidor no la lee al crear, decide lo que el personal guarde.

### Zonas y reglas de asignación automática

`mesas.zona` (`interior` | `exterior`) sólo aplica a `tipo = 'mesa'`. Las Mesas
1–7 son interiores y las 8–11 exteriores. Una mesa sin zona no entra al bloque
exterior ni recibe reubicaciones. Las barras nunca se asignan.

`n` = comensales; `máx` = máximo configurable.

| n | Mesas | Si no hay lugar |
| --- | --- | --- |
| mayor que `máx` | Ninguna automática; el personal elige, hasta 44. | — |
| 9 a `máx` | Bloque exterior completo: Mesas 8+9+10+11 (16 asientos). Las reservas que se solapan ahí se reubican solas. | El horario no se ofrece. |
| 5–8 | Primera pareja libre de `GRUPOS_DOS_MESAS`: `[3,4]` → `[7,8]` → `[6,9]` → `[10,11]`. El orden es la preferencia. | El horario no se ofrece. |
| 1–4 | Primera mesa libre: interior 1→7, luego exterior 8→11. | El horario no se ofrece. |

Lo decide `AsignacionPlanificadorService`, que es puro (sin base): recibe la
solicitud, las mesas y un evaluador de ocupación, y devuelve las mesas, el tipo
(`individual` | `pareja` | `bloque_exterior`) y las reubicaciones, o `ninguna`
con su motivo. `AsignacionMesasService` lo invoca y persiste. Las reservas
viejas asignadas a un trío siguen siendo válidas y se tratan como manuales.

### Reubicación al tomar el bloque exterior

Si el bloque está ocupado por reservas más pequeñas, el planificador busca a
dónde moverlas:

- El bloque es imposible si falta alguna mesa exterior, si alguna tiene un
  ticket físico o proyectado, o si una de las reservas que lo ocupan es de 9+ o
  ya empezó sin ticket (es el cliente que llega o está en tolerancia).
- Cada reserva desplazada va a una mesa suelta 1–7 con capacidad suficiente o a
  la pareja `[3,4]`. Se recorren por hora y luego por id (determinista); dos
  desplazadas que se solapan no comparten mesa; el recorrido tiene tope de
  `REUBICACION_MAX_NODOS` (5000).
- **Limitación asumida:** los destinos nunca tocan el bloque y `[3,4]` es la
  única pareja interior, así que por cada ventana de 90 minutos se puede
  desplazar como máximo una reserva de 5–8.

`ReservacionReubicacionService::aplicar()` corre dentro de la transacción del
llamador: bloquea las reservas desplazadas (`FOR UPDATE`, por id), revalida
estado y asignación (`CONFLICTO_CONCURRENTE` si cambiaron), bloquea las mesas,
reemplaza la asignación, avanza la versión, escribe la bitácora
`reservacion_reubicaciones` y deja un aviso informativo «Reubicada» en el buzón.
El orden de locks no cambia: `HorarioConfigLock` → `FechaOperacionLock` →
transacción → reservas → mesas.

Al cliente no se le avisa: nunca se le comunica la mesa. El aviso es interno
(insignia en la ficha del POS, buzón y Casos) y se cierra con «Marcar
revisada». Si un hold expira, sus reubicaciones no se revierten.

En «Cambiar mesas», un conflicto sólo reubica a otras reservas cuando la
selección es exactamente el bloque exterior; entonces el servidor devuelve la
decisión `REUBICAR_RESERVACIONES` con la lista a mover y pide confirmación. En
cualquier otra selección, un conflicto es error.

### Capacidad, alta y buzón

**Toda reservación confirmada tiene mesas.** No existe «asignar más tarde»:
`ReservacionAdministrativaService::crear()` y `::actualizar()` asignan siempre
(el campo `asignar_automaticamente` se ignora) y, justo antes de cada commit,
comprueban que la reservación tenga filas en `reservacion_mesas`. Si no hay
lugar, no se guarda nada y responden `MESAS_REQUERIDAS` (con
`requiere_seleccion_mesas` cuando es un grupo mayor al máximo, que entonces
debe traer `mesa_ids`). Un cambio de hora o de personas que deja sin lugar se
rechaza y conserva las mesas de antes. Las reservaciones creadas antes de esta
regla se resuelven con `php scripts/asignar-mesas-pendientes.php`
(`--simular` para sólo listar). El límite y las validaciones de la landing no
se amplían por las excepciones administrativas.

`Nueva reservación` requiere fecha y horario válidos, contexto cargado sin error
y permisos. Fecha, hora y mesas se vuelven a validar en el backend al crear o
modificar. El alta admite `mesa_ids[]` explícitos, validados con la misma
asignación manual dentro de la transacción de creación: si fallan, no queda
ninguna reserva huérfana. El reenvío tras una decisión conserva el
`request_token`, así un doble toque no crea dos.

La creación, asignación y cierre no disparan la sincronización del buzón. La
sincronización administrativa se hace por su endpoint protegido y presenta
ausencias pendientes, necesidades de asignación y coordinaciones que requieren
acción. Una ausencia de prioridad alta requiere registrar el no-show mediante
la acción de dominio; leer una alerta no la resuelve.

La lista `/admin/reservaciones` excluye por defecto `pendiente_verificacion` y
`expirada`; un filtro explícito aún permite consultarlas. Las retenciones vigentes
siguen en los cálculos canónicos de capacidad y disponibilidad.

## Mapas y estados visuales

Hay **un solo mapa**, el del POS, y **tres estados** de mesa. Ningún estado
depende sólo del color: cada pin lleva icono y rótulo, y se lee en escala de
grises.

| Elemento | Rojo · Ocupada | Azul · Reservada | Verde · Libre |
| --- | --- | --- | --- |
| Mesa | Ticket abierto (en Ahora) o ticket que aún ocupa a la hora consultada (en proyección). | Reserva confirmada u hold sin ticket, con `inicio − 60 ≤ T < inicio + 90`. | Todo lo demás. |
| Barra | Tickets abiertos ≥ tope. | Nunca: las barras no se reservan. | Menos del tope; subrótulo `n/4` cuando hay alguno. |
| Caja, Llevar | — | — | — |

Caja y Llevar son **fichas neutras de acción**, sin estado. Llevar muestra «N
pedidos». Un elemento fuera de servicio sale como `no-utilizable` (neutro, «No
disponible»): no es un cuarto estado de reservas, es la restricción conocida de
una mesa desactivada.

Señales secundarias, que nunca cambian el color:

- **Reubicación por ticket abierto**: no hay estado «ocupada, con
  reservación». Cuando una reserva entra en su ventana (una hora antes, hasta el
  fin de la tolerancia) y su mesa sigue con un ticket abierto de otro, se mueve
  sola a una mesa libre en ese momento
  (`ReservacionReubicacionPorTicketService`, en el sondeo del POS). Queda en
  `reservacion_reubicaciones` con motivo `ticket_abierto`, en el buzón y en la
  ficha («Reubicada de … a …»). Si no hay a dónde, se queda donde estaba —nunca
  sin mesas— y se reintenta en la siguiente lectura; la mesa sale roja.
- **Ausencia automática**: al vencer la tolerancia (`inicio + 15`) sin ticket,
  la reserva pasa sola a `no_show` y su mesa queda libre
  (`ReservacionAusenciaAutomaticaService`). No hay cron: corre en la primera
  lectura que llega —el sondeo del POS cada 30 s, el panel de reservaciones,
  Casos o el buzón— y `php scripts/procesar-ausencias.php` sirve para uno. En
  esos segundos el pin sigue azul con «Se libera». Ya no hay estado «Sin llegar»
  ni botón «No llegó»: ese estado intermedio se arrastraba por el mapa, la
  ficha, el buzón y los casos.
- **«en 45 min»** en el subrótulo en Ahora; en proyección el subrótulo es la
  hora de la reserva («20:00»).
- **«Apartada»**: un hold vigente. Es una reserva a medio confirmar: azul, no
  rojo.
- **«Reubicada»**, en la ficha y el cajón.

Política de walk-in, sin cambios: de 60 a 30 minutos antes de una reserva se
puede sentar a alguien con confirmación; a 30 minutos o menos, no.

## Responsabilidad de las reglas

El flujo de presentación es:

```text
ReservacionConfig
    ↓
ReservacionVigenciaService ── ReservacionPoliticaPosService
    ↓
MesaEstadoService
    ↓
PosMesaProjectionPresenter
    ↓ contrato JSON (GET /api/punto-de-venta)
map-contract.js
    ↓
table-state-adapter.js
    ↓
map-visual.js
```

| Regla | Responsable | Resultado producido | Consumidor |
| --- | --- | --- | --- |
| Umbrales de advertencia, bloqueo y tolerancia (`60`, `30` y `15` minutos) | `ReservacionConfig` | Valores temporales canónicos | `ReservacionVigenciaService` y `ReservacionPoliticaPosService` |
| Clasificación temporal y elegibilidad de no-show | `ReservacionVigenciaService` | Ventana temporal, influencia operativa y `ausencia_pendiente` | Política POS, estado de mesa y acciones de la ficha |
| Política de ticket y walk-in | `ReservacionPoliticaPosService` | `disponible_para_ticket`, advertencia y bloqueo de walk-in | `MesaEstadoService` y POS |
| Ocupación física actual | `MesaEstadoService` | `ocupada_fisicamente` y hechos de tickets abiertos | `PosMesaProjectionPresenter` |
| Bloqueo del intervalo consultado | `OcupacionMesasService` | Mesas bloqueadas y causas para fecha/hora | `MesaEstadoService` y asignación |
| Ventana de la reserva respecto a la hora consultada | `ReservacionPoliticaPosService` | `ventana_mapa` | `MesaEstadoService` y el presenter en proyección |
| Disponibilidad final para asignación | `AsignacionMesasService`; `MesaEstadoService` proyecta el hecho de lectura | `disponible_para_asignacion` y causa de conflicto | «Cambiar mesas» y endpoint de asignación |
| Capacidades POS de Mesa, Barra, Caja, Llevar y elementos decorativos | `MesaEstadoService` | `capacidades_pos` derivadas de tipo, nombre, actividad y reservabilidad | `punto-de-venta.js` |
| Estado visual | `PosMesaProjectionPresenter` | `estado_visual_pos`, modificadores, rótulo, subrótulo y etiqueta accesible | POS y contrato JSON |
| Contexto de la consulta (Ahora o proyección) | `PosReservacionQueryService::resolverContexto()` | `contexto {modo, fecha, hora, solo_lectura}` y `horario` | `punto-de-venta.js` |
| Color, borde e indicador visual | Tokens y clases de `_map-shell.scss` | Estilos para el estado y modificadores recibidos | `map-visual.js` |
| Selección de mesas | `punto-de-venta.js` (abrir ticket y «Cambiar mesas»); el servidor revalida | Permiso de selección para la acción y snapshot actuales | Adaptador y renderer |
| Contrato visual inválido | `map-contract.js` | Estado seguro `no-utilizable`, no verificado, sin selección ni interacción | Adaptador |
| Snapshot HTTP inválido, timeout y contexto stale | `punto-de-venta.js` | Aceptar o descartar el snapshot y mensaje de actualización | Pantalla del POS |
| Dibujo, ARIA y eventos de interacción | `map-visual.js` | Pines, lista accesible, selección visual y eventos | `punto-de-venta.js` |

`MesaEstadoService` reúne ocupación, bloqueos del intervalo, reservaciones y
capacidades POS en hechos de mesa. No vuelve a calcular el intervalo ni los
umbrales temporales. `PosMesaProjectionPresenter` traduce esos hechos a estado,
modificadores y etiquetas; no consulta el DOM ni concede permisos de mutación.

| Hecho | Significado |
| --- | --- |
| `bloqueada_en_intervalo` | Existe una ocupación que cruza el intervalo consultado de 90 minutos. |
| `disponible_para_asignacion` | La mesa puede elegirse, incluidas las excepciones de conservación de la propia asignación. |
| `ventana_mapa` | Futura, advertencia, bloqueo previo, inicio/activa o irrelevante respecto al instante consultado. |
| `estado_visual_pos` | Libre, ocupada, reservacion-proxima o no-utilizable. |
| `seleccionada` | Capa de interfaz; no cambia el estado visual ni la disponibilidad. |

El bloqueo de asignación y el color son hechos distintos. Con una reserva a las
12:00, una proyección a las 10:45 queda **verde** aunque
`bloqueada_en_intervalo: true` y `disponible_para_asignacion: false`: el
intervalo `[10:45, 12:15)` ya choca, pero la mesa todavía no es de la reserva.

`map-contract.js` no usa DOM. Los estados admitidos son `libre`, `ocupada`,
`reservacion-proxima` y `no-utilizable`. La cadena heredada `seleccionada` sólo
se acepta con un estado previo válido y se convierte en selección secundaria.

### Ahora y proyección

El mapa del POS tiene dos modos, y el servidor decide cuál en cada respuesta:

- **Ahora**: hoy sin hora pedida. La operación en vivo; se abren tickets.
- **Proyección**: el mesero toca un horario o una reserva en el cajón. El mapa
  enseña cómo estará el salón a esa hora, con una trama diagonal y la barra de
  contexto («Lun 12 oct · 20:00»). Es de **sólo lectura**: tocar una mesa no
  abre ticket; avisa «vuelve a Ahora para operar». Otro día no tiene Ahora:
  abre proyectado a la primera reserva confirmada o a la apertura.

En proyección sólo pinta rojo el ticket que **sigue ahí** a la hora consultada
(`ticket_bloquea_consulta`); el que se habrá ido no. **No hay regreso automático
a Ahora**: sólo con el botón «Ahora»/«Hoy». El sondeo de 30 segundos conserva la
proyección.

Un enlace puede abrir el POS ya situado: `/punto-de-venta?fecha&hora`,
`?reservation_id=…` (abre su ficha, con la regla de tiempo de abajo) y
`&mode=assign` (entra directo a «Cambiar mesas»). Lo valida
`PuntoVentaController::enlaceInicial()`: una reservación manda sobre la fecha
del enlace, y al recargar no se repite.

### Elementos especiales del POS

Las capacidades se derivan en `MesaEstadoService` de los campos existentes. El
objeto `capacidades_pos` contiene `reservable`, `operable`, `ticketable`,
`independiente_consulta`, `abrir_caja`, `crear_pedido_llevar`,
`mostrar_estado_ticket`, `decorativo`, `etiqueta_operacion`, `multi_ticket`,
`tope_tickets` y `ficha`.

| Elemento | Participa en Reservaciones | Operable en POS | Ticketable | Depende del snapshot | Responsable de la capacidad |
| --- | --- | --- | --- | --- | --- |
| Mesa activa y reservable | Sí | Sí | Sí | Sí | `MesaEstadoService` |
| Barra activa | No | Sí | Sí, hasta su tope | Sí | `MesaEstadoService` |
| Caja activa | No | Sí | No; abre el corte | No | `MesaEstadoService` |
| Llevar activo | No | Sí | Flujo propio de pedido | No | `MesaEstadoService` |
| Elemento decorativo activo | No | No | No | No | `MesaEstadoService` |
| Mesa desactivada | No | No | No | No | `MesaEstadoService` |

### Barras con varios tickets

Las barras funcionan como Llevar: admiten varios tickets abiertos a la vez, cada
uno propio, con el tablero de pedidos del modal. El tope es configurable
(Configuración → POS, `configuracion_pos.max_tickets_barra`, 1 a 12, por
defecto 4); al llegar a él el servidor responde `BARRA_LLENA` (409) y el pin
sale rojo con `4/4`. Llevar no tiene tope. Barras, Caja y Llevar no se unen a
otras mesas.

Lo decide el servidor (`PuntoVentaReservacionService::abrirWalkIn`, con un solo
helper que reconoce barra por `tipo` y Caja/Llevar por nombre, y cuenta los
tickets abiertos con `FOR UPDATE`), no el cliente.

**Los comensales de barra no cuentan en capacidad ni ocupación** (el chip N/44,
la disponibilidad pública). Sí cuentan en ventas y en «Comensales atendidos». El
RevPASH es sólo del salón: ver `database/ANALITICAS.md`.

### Qué no hace el frontend

El frontend no decide la ventana que bloquea una mesa, el fin de la tolerancia,
si una ausencia ya permite marcar no-show, la capacidad, la disponibilidad, si
una mesa se puede asignar ni si se puede abrir un ticket. Valida la estructura y
el contexto de la respuesta HTTP, presenta las proyecciones del servidor,
descarta contratos inválidos de forma segura, gestiona la interacción y solicita
la mutación al backend para su validación final.

### Prioridad visual

Un elemento no utilizable permanece neutro. Un ticket que ocupa manda sobre la
reserva: rojo. Una reserva o un hold en su ventana es azul. Advertencia,
ausencia, reubicación, asignación y selección son señales secundarias. La
selección es una capa visual y **no garantiza disponibilidad** ni autoriza
abrir ticket, asignar o reasignar mesa, iniciar servicio ni marcar no-show.

`estadoNoVerificado` es un indicador exclusivo de presentación para el fallback
de un contrato visual ausente, desconocido o incompleto: fondo neutro, señal de
advertencia e interacción bloqueada hasta recibir un estado válido. El frontend
nunca interpreta un contrato desconocido como disponibilidad.

Una respuesta general HTTP fallida, incompleta o de otra fecha u hora se
descarta y muestra el aviso general de actualización; no convierte todas las
mesas en elementos no utilizables. Si el POS conserva la fotografía anterior,
la identifica como desactualizada y bloquea las operaciones que dependen de
ella.

Una reserva fuera del horario efectivo (`fuera_horario_operacion = true`)
sigue en la lista del día y en la ficha, con el indicador «Fuera de horario de
operación», pero no colorea el pin. Un ticket, hold u otro bloqueo
independiente sí conserva su presentación propia.

### Límites temporales

| Diferencia con el inicio | Ahora | Proyección a esa hora |
| --- | --- | --- |
| `> 60 min` (Disponible) | Verde, sin alerta. | Verde. |
| `> 30` y `≤ 60 min` (Advertencia) | Azul; walk-in con confirmación. | Azul. |
| `> 0` y `≤ 30 min` (Próxima) | Azul; walk-in bloqueado. | Azul. |
| `0` hasta `+15 min` (Inicio y Tolerancia) | Azul, «Esperando». | Azul. |
| Después de `+15 min` (Ausencia pendiente) | Pasa sola a `no_show` y la mesa se libera; mientras tanto, azul «Se libera». | Verde: la ausencia ya liberó la mesa. |
| `inicio + 90 min` exacto o posterior | Depende de la realidad física. | Verde. |

Un Ticket abierto es rojo en Ahora mientras exista; en proyección, hasta su
liberación estimada. Un elemento No utilizable es neutro en los dos. Una mesa
Seleccionada añade un anillo y conserva su fondo.

Ejemplos del límite semiabierto, con una reserva a las 12:00:

- `10:30` verde y asignable; `10:45` verde, pero no asignable porque
  `[10:45, 12:15)` se solapa con la reserva.
- `11:00` azul; `13:29` azul; `13:30` verde por el límite final exclusivo.
- Ticket abierto a las `09:10`, liberación estimada a las `10:40`: proyección a
  las `10:30` roja; a las `10:40` verde. En Ahora sigue rojo mientras esté
  abierto.

### Modal de ayuda

«Cómo interpretar el mapa de mesas» se abre desde el menú del avatar de la
barra («Cómo leer el mapa»), junto a los ajustes de la vista. Muestra los tres estados, cada uno con su icono, más
la trama de la proyección. Los colores orientan; las acciones se validan en la
operación.

## La operación en el POS

**El cajón** lista las reservas del día **por horario** («20:00 · 3 reservas ·
11 pers.»). Tocar un horario proyecta el mapa; tocar una reserva aplica la regla
de tiempo: si su hora ya llegó (hoy) o está resuelta, se abre en Ahora; si es
futura u otro día, se proyecta a su hora. Un horario pasado de hoy no es botón
(«En vivo»). Las resueltas (atendidas, canceladas y no-show) van plegadas al
final. En su pie, «Nueva reservación».

**La ficha** está **acoplada** al mapa, no flota ni es modal: en horizontal es
una columna a la derecha y el plano se reacomoda; en vertical va debajo.
Acciones:

- «Llegó» inicia el servicio y vuelve a Ahora; sólo el día de la reservación.
  No hay «No llegó»: la ausencia se registra sola al vencer la tolerancia.
- «Cancelar reservación» pide un motivo, que se guarda en `motivo_cancelacion`
  y reconcilia el seguimiento de horario igual que el camino del panel.
- «Cambiar» / «Asignar» mesas: modo selección sobre el mapa proyectado a la hora
  de la reserva, con la barra inferior («Descartar» / «Guardar mesas»). Guarda
  con `version_esperada` y `mesa_ids_actuales`: si otra tablet la movió, el
  servidor responde `VERSION_DESACTUALIZADA` en vez de pisar el cambio. Navegar
  a otra hora o reserva sale del modo.
- Comentario interno: el personal operativo puede leer la nota de atención;
  editarla exige `reservations/editar` en Restaurante (`PERMISO_DENEGADO` en el
  servidor). No se escriben ahí datos de contacto.
- «Abrir ticket de todas formas» sólo si la política permite el walk-in.
- «Reubicada de Mesa 9 a Mesa 3», con «Marcar revisada».

**Nueva reservación** es un `<dialog>`: personas → horas que el servidor ofrece
para esas personas → vista previa de en qué mesas quedaría y a quién movería.
El mesero no captura contacto. Un grupo mayor al máximo pasa a «Elegir mesas»:
el diálogo se cierra, el mapa se proyecta a su hora en modo selección y
«Crear reservación» la crea ya con esas mesas; «Descartar» no deja nada y
vuelve a Ahora si se empezó ahí. Al crear, el mapa proyecta su hora.

Endpoints, todos en `Auth::APIS_POS` por ruta exacta y con CSRF de personal:
`GET /api/punto-de-venta` (con `hora`, `reservacion_id`, `intencion=asignar`) y
`/api/punto-de-venta/reservaciones/{disponibilidad, crear, asignar-mesas,
reasignar, comentario, reubicaciones/revisar}`, más los que ya existían
(`comenzar`, `cancelar`, `no-show`). Las respuestas para el mesero pasan por
`PosReservacionSerializer::sanitizarParaWaiter`.

## Panel administrativo

`/admin/reservaciones` tiene tres pestañas, sin mapa:

- **Histórico** — el listado en tarjetas de siempre. «Abrir en el POS» lleva a
  la reserva en el mapa.
- **Grupos grandes** (`/admin/reservaciones/grupos-grandes`) — mira hacia
  delante (por defecto 30 días, tope 62). Por día, la franja del bloque exterior
  cada 30 minutos (libre, parcial, tomada, legible sin color) y los grupos de 9+
  o que usan el exterior. Lo calcula `ReservacionAgendaGruposService`.
- **Casos** (`/admin/reservaciones/casos`) — una tarjeta por reservación que
  pide algo: ausencia pendiente, sin mesas, afectada por cambio de horario,
  reubicada sin revisar, sin contacto. Acciones: abrir en el POS, asignar mesas
  (`mode=assign`), registrar que no llegó (`POST /admin/api/reservaciones/estado`),
  marcar revisada y ver detalle. Lo calcula `ReservacionCasosAccionablesService`.

## Cambios de horario y afectaciones

Si una modificación del horario efectivo deja una reserva fuera de operación,
el sistema conserva la reserva y crea seguimiento por reservación. No cambia
automáticamente fecha, hora, mesas, comensales ni estado; tampoco cancela ni
reprograma la reserva. La reserva sigue en la lista y fuera del mapa.

| Caso | Tratamiento |
| --- | --- |
| Hasta el máximo de la landing, con contacto | Se prepara un acceso para que el cliente responda; el buzón muestra espera. |
| Hasta el máximo, sin contacto | El caso queda accionable; agregar un contacto válido prepara el acceso. |
| Más del máximo configurable | Requiere gestión administrativa; no se crea autoservicio automático. |

La administración puede abrir la reserva, actualizar contacto, modificar o
cancelar cuando corresponda, asignar mesas con las reglas canónicas y cerrar el
seguimiento si la atenderá fuera del sistema. Cerrar seguimiento sólo retira el
pendiente administrativo; no altera la reservación. Cada reserva afectada se
gestiona de forma independiente.

La elegibilidad, vigencia, intentos y transporte de avisos son parte de
[Notificaciones de reservaciones](notificaciones.md); esa fuente también define
qué acredita `accepted` y cómo se tratan fallos o resultados inciertos.

## Permisos operativos

La operación de reservaciones se autoriza mediante permisos directos de
Restaurante, con las alternativas explícitas del caso de uso (`pdv/operar`,
`reservations_map/operar` o administración `reservations`). No se deduce del
nombre de un rol. Las mutaciones vuelven a comprobar permisos y reglas en backend.

El contacto y la edición de comentarios internos requieren los permisos administrativos
expresos correspondientes; una sesión de piso no los recibe por tener acceso al
mapa. El tratamiento de datos personales se rige por
[Privacidad](../privacidad/privacidad.md).

## Autoridad de reglas y persistencia

| Regla / dato | Responsable | Persistencia | Consumidores |
| --- | --- | --- | --- |
| Estados y vigencia que influyen en disponibilidad | `ReservacionConfig` y `ReservacionVigenciaService` | `reservaciones` | Ocupación, capacidad, disponibilidad y POS |
| Máximo de la landing y WhatsApp | `ReservacionAjustesService` | `configuracion_reservaciones` | Landing, catas, alta y buzón |
| Lectura básica por ID y `request_token` | `Model\Reservacion` | `reservaciones` | Services de reservaciones y POS |
| Lectura completa con lock por ID | `Model\Reservacion::buscarFilaPorIdParaActualizar()` | `reservaciones`, dentro de una transacción | Mutaciones administrativas y POS |
| Lectura de `request_token` con lock | `ReservacionPublicaService` conserva su lectura transaccional especializada | `reservaciones`, dentro de la creación idempotente | Creación pública; no hay otro consumidor bloqueante equivalente |
| Asignación y relación reservación ↔ mesa | `Model\ReservacionMesa` persiste; `AsignacionMesasService` decide | `reservacion_mesas` | Ocupación y mutaciones de asignación |
| Plan de asignación (mesa, pareja o bloque) | `AsignacionPlanificadorService` (puro) | — | `AsignacionMesasService` y disponibilidad |
| Reubicaciones | `ReservacionReubicacionService` | `reservacion_reubicaciones` | Buzón, ficha del POS y Casos |
| Mesas y tickets abiertos | `Model\Mesa` y `Model\TicketMesa` | `mesas`, `tickets`, `ticket_mesas` | Contexto diario de ocupación |
| Solapamiento de intervalos | `OcupacionMesasService::intervalosSeTraslapan()` | — | Ocupación, capacidad, disponibilidad y asignación |
| Hechos de ocupación para un horario | `OcupacionMesasService` | Lee los Models de mesas, tickets y reservas | Capacidad, disponibilidad, administración y asignación |
| Capacidad disponible y demanda sin asignar | `CapacidadReservacionesService` | Lee `Model\ReservacionMesa` para la demanda | Disponibilidad pública y administrativa |
| Horarios candidatos y validación temporal | `HorarioReservacionService` | Configuración de operación | Disponibilidad pública y administrativa |
| Disponibilidad pública | `DisponibilidadReservacionService` | — | Controladores públicos |
| Excepciones y respuesta administrativa | `ReservacionAdministrativaService` | Models y Services de dominio | Controladores administrativos y POS |
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
conservan sus decisiones y respuestas propias. Un horario en el que el grupo no
cabe —ni en su mesa, ni en su pareja, ni en el bloque con reubicación— no se
ofrece, y la landing propone sus alternativas.

### Flujo de asignación

```text
Reservación
    ↓
AsignacionMesasService
    ↓
consulta de ocupación canónica
    ↓
AsignacionPlanificadorService (mesa, pareja o bloque + reubicaciones)
    ↓
ReservacionReubicacionService (si mueve a otras)
    ↓
Model\ReservacionMesa
```

`AsignacionMesasService` **decide** y valida la mutación. `ReservacionMesa`
**persiste** la relación; no decide capacidad, disponibilidad ni selección. La
mutación vuelve a consultar y validar dentro de la transacción.

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

Las consultas de administración/reporting y los locks que tienen alcance
transaccional propio permanecen en los Services cuando no representan una
lectura básica reutilizada.

## Instalación y datos de desarrollo

El esquema definitivo se reconstruye con el procedimiento único de
[instalación](../desarrollo/instalacion.md): DDL, DML estructural, menú preservado,
fixtures sintéticos y bootstrap seguro. No se aplica una secuencia de ALTER
históricos a la base antigua ni se conservan registros históricos descartables.

Todas las reservas, mesas, tickets y relaciones del esquema multinegocio tienen
unidad; las consultas y mutaciones de Restaurante la fijan en servidor. Las
pruebas antiguas de base se adaptan al esquema vigente dentro de destinos
identificados y desechables; no se ejecutan contra la base original por omisión.

## Referencias

- [Configuración](../config.md)
- [Operación POS e impresión](../operacion.md)
- [Usuarios](../usuarios/usuarios.md)
- [Privacidad](../privacidad/privacidad.md)
