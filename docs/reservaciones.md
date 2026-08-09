# Módulo de reservaciones — Fuente de verdad vigente

**Proyecto:** Casa Pestalozzi
**Versión:** 2026-08-08
**Estado:** Contrato funcional y técnico vigente
**Zona horaria canónica:** `America/Mexico_City`
**Propósito:** Definir exclusivamente las reglas vigentes del módulo de reservaciones.

> Este documento reemplaza cualquier versión normativa anterior.
> Los planes, auditorías, reportes de implementación y decisiones sustituidas son documentación histórica y no modifican este contrato.

---

# 1. Precedencia y control de cambios

1. Este documento es la referencia funcional principal del módulo.
2. Todo comportamiento implementado que contradiga este documento se considera incorrecto.
3. Los reportes históricos no pueden modificar estas reglas.
4. Toda nueva decisión funcional debe integrarse directamente en la sección correspondiente.
5. Antes de implementar un cambio:
   1. actualizar esta fuente de verdad;
   2. identificar servicios y consumidores afectados;
   3. actualizar o crear pruebas;
   4. implementar;
   5. validar landing, administración, mapa y POS.

6. Ninguna superficie puede reconstruir en frontend reglas de ocupación, capacidad, traslape, tolerancia o ventanas POS.

---

# 2. Objetivo y superficies

El módulo permite:

- Consultar y crear reservaciones desde la landing.
- Verificar contacto mediante OTP.
- Consultar, modificar o cancelar reservaciones públicas autorizadas.
- Crear, consultar, modificar y cancelar reservaciones administrativas.
- Asignar y reasignar mesas.
- Integrar reservaciones con tickets sin doble conteo.
- Proyectar disponibilidad futura.
- Mostrar capacidad y estados operativos de mesas.
- Gestionar ausencias manualmente.

Las superficies son:

1. **Landing pública.**
2. **Panel administrativo.**
3. **Mapa de gestión de reservaciones.**
4. **Mapa del punto de venta.**

Todas consumen los mismos hechos canónicos.

Las superficies pueden derivar decisiones diferentes de esos hechos cuando este documento lo establece explícitamente.

---

# 3. Conceptos canónicos

## 3.1 Ocupación canónica

La ocupación canónica es el conjunto de hechos que pueden afectar mesas o capacidad:

- Tickets abiertos.
- Reservaciones `confirmada`.
- Holds vigentes de `pendiente_verificacion`.
- Reservaciones administrativas confirmadas sin mesas.
- Estado activo y reservable de las mesas.
- Vigencia de reservaciones y holds.
- Proyección temporal de tickets.

Se calcula exclusivamente en backend.

No existe un segundo motor para POS, mapa, landing o administración.

---

## 3.2 Disponibilidad común

Todas las superficies consultan los mismos hechos de:

- mesas elegibles;
- reservaciones;
- holds;
- tickets;
- intervalos;
- capacidades;
- vigencia;
- demanda no asignada.

Sin embargo, **un mismo hecho no implica necesariamente el mismo permiso operativo en todas las superficies**.

Debe distinguirse expresamente entre:

```text
ocupada_fisicamente
disponible_para_asignacion
disponible_para_ticket
```

Estos hechos no son aliases entre sí.

---

## 3.3 Hechos derivados por mesa

### 3.3.1 `ocupada_fisicamente`

Responde:

```text
¿La mesa está siendo utilizada físicamente?
```

Para la operación actual, la fuente física principal es:

```text
ticket abierto
+
ticket_mesas
```

Una reservación futura sin ticket:

```text
NO implica ocupada_fisicamente = true
```

Una reservación confirmada que todavía no inicia puede impedir una asignación futura o un walk-in próximo, pero no convierte por sí sola a la mesa en físicamente ocupada.

---

### 3.3.2 `bloqueada_en_intervalo`

Responde:

```text
¿La mesa presenta algún conflicto durante todo el intervalo consultado?
```

El intervalo se construye usando:

```text
DURACION_RESERVACION_MINUTOS
```

Para una consulta:

```text
intervalo_consulta =
[hora_consulta,
 hora_consulta + DURACION_RESERVACION_MINUTOS)
```

`mesa_ids_bloqueadas` y `bloqueada_en_intervalo` significan exclusivamente:

```text
mesa no disponible para el intervalo consultado
```

Nunca significan automáticamente:

```text
ocupada_fisicamente
```

ni:

```text
no puede abrirse un ticket POS ahora
```

---

### 3.3.3 `disponible_para_asignacion`

Responde:

```text
¿Puede esta mesa asignarse a una nueva reservación
durante TODO el intervalo solicitado?
```

Se deriva exclusivamente de la evaluación canónica de ocupación del intervalo.

Debe cumplir:

```text
bloqueada_en_intervalo = true
→ disponible_para_asignacion = false
```

y:

```text
bloqueada_en_intervalo = false
AND mesa elegible
→ disponible_para_asignacion = true
```

No debe volver a calcularse comparando únicamente la hora puntual con el inicio de una reservación.

---

### 3.3.4 `disponible_para_ticket`

Responde:

```text
¿Puede iniciarse ahora un ticket walk-in en esta mesa?
```

No se calcula simulando un ticket de 90 minutos ni reutilizando directamente `mesa_ids_bloqueadas`.

Se determina mediante:

- ocupación física;
- ticket abierto;
- reservación próxima;
- ventana de advertencia;
- ventana de bloqueo;
- tolerancia;
- ausencia pendiente;
- estado operativo de la reservación.

El backend es la única autoridad.

---

### 3.3.5 `requiere_advertencia_ticket`

Indica que un walk-in todavía es posible, pero existe una reservación suficientemente próxima para requerir confirmación explícita.

```text
requiere_advertencia_ticket = true
```

implica:

```text
disponible_para_ticket = true
```

pero obliga a una decisión antes del commit.

---

## 3.4 Capacidad física libre

Es la suma de los asientos de mesas elegibles que permanecen disponibles durante todo el intervalo solicitado.

```text
capacidad_fisica_libre =
SUM(mesas.capacidad
    de mesas con disponible_para_asignacion = true)
```

La capacidad se descuenta por los asientos completos de las mesas comprometidas.

Ejemplo:

```text
Capacidad física total: 44

Reservación asignada:
Mesa A = 4
Mesa B = 4

Capacidad física libre = 36
```

No se descuentan únicamente los comensales de una reservación físicamente asignada.

---

## 3.5 Demanda no asignada

Una reservación administrativa `confirmada` sin filas en `reservacion_mesas` no bloquea una mesa específica, pero sí afecta capacidad.

```text
demanda_no_asignada =
SUM(comensales de reservaciones confirmadas sin mesas
    que influyen en el intervalo consultado)
```

No debe pintarse una mesa ficticia para representar esta demanda.

---

## 3.6 Capacidad real disponible

```text
capacidad_real_disponible =
MAX(
    0,
    capacidad_fisica_libre - demanda_no_asignada
)
```

Esta capacidad es común para landing, administración y mapa.

No sustituye la validación física de combinaciones.

---

## 3.7 Asignabilidad automática

Indica si existe una mesa individual o grupo permitido capaz de soportar toda la reservación sin conflictos.

Es estricta para:

- landing;
- modificación pública.

Es opcional para administración.

---

## 3.8 Política por superficie

| Superficie           | Hecho principal                                  | Política                                 |
| -------------------- | ------------------------------------------------ | ---------------------------------------- |
| Landing              | `disponible_para_asignacion` + capacidad         | Exige capacidad y combinación automática |
| Modificación pública | Igual que landing                                | Estricta                                 |
| Administración       | Misma capacidad y asignabilidad                  | Puede confirmar sin mesas                |
| Mapa reservaciones   | Capacidad + asignabilidad + proyección visual    | Permite gestión manual                   |
| POS                  | `ocupada_fisicamente` + `disponible_para_ticket` | Opera tickets y servicio                 |

La libertad administrativa nunca altera el cálculo real.

---

# 4. Configuración canónica

Todos los parámetros temporales se definen en una única fuente de configuración.

No deben existir literales funcionales equivalentes en servicios, JavaScript, vistas o SQL.

Configuración vigente:

```php
final class ReservacionConfig
{
    public const DURACION_RESERVACION_MINUTOS = 90;

    public const DURACION_ESTIMADA_TICKET_MINUTOS = 90;
    public const RETRASO_ESTIMADO_TICKET_MINUTOS = 0;

    public const ANTICIPACION_MINIMA_MINUTOS = 40;
    public const MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION = 90;

    public const VIGENCIA_HOLD_MINUTOS = 15;
    public const TOLERANCIA_LLEGADA_MINUTOS = 15;

    public const AVISO_RESERVACION_PROXIMA_MINUTOS = 60;
    public const BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS = 30;
    public const INICIO_SERVICIO_ANTICIPADO_MINUTOS = 30;

    public const LIMITE_MODIFICACION_MINUTOS = 30;
    public const TOLERANCIA_CANCELACION_PUBLICA_MINUTOS = 15;

    public const MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO = 5;
    public const HORIZONTE_MAXIMO_DIAS = 90;

    public const GRUPOS_DOS_MESAS = [
        [7, 8],
        [6, 9],
        [10, 11],
        [3, 4],
    ];

    public const GRUPOS_TRES_MESAS = [
        [2, 4, 5],
        [11, 10, 9],
    ];
}
```

Si la implementación utiliza otro mecanismo de configuración, los nombres pueden adaptarse, pero debe existir una única fuente.

Queda eliminado conceptualmente cualquier parámetro paralelo como:

```text
MINUTOS_PREVIOS_BLOQUEO
```

cuando represente la misma regla que:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
```

---

## 4.1 Duración configurable

No se asume que una reservación siempre durará 90 minutos.

La regla es:

```text
duracion =
DURACION_RESERVACION_MINUTOS
```

Con la configuración vigente:

```text
duracion = 90
```

El intervalo planificado es:

```text
[inicio,
 inicio + DURACION_RESERVACION_MINUTOS)
```

Modificar el parámetro debe actualizar automáticamente:

- capacidad;
- conflictos;
- asignación;
- horarios;
- mapa;
- landing;
- administración;
- pruebas.

---

## 4.2 Ticket y reservación son configuraciones distintas

Nunca asumir:

```text
DURACION_RESERVACION_MINUTOS
=
DURACION_ESTIMADA_TICKET_MINUTOS
```

aunque actualmente ambas sean 90.

---

## 4.3 Tolerancia

```text
tolerancia =
[hora_reservacion,
 hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS]
```

La tolerancia está incluida dentro de la duración planificada.

No se suma a `DURACION_RESERVACION_MINUTOS`.

---

# 5. Horarios reservables

## 5.1 Prioridad

1. Excepción activa.
2. Excepción cerrada → sin horarios.
3. Horario especial → usarlo.
4. Sin excepción → horario semanal.
5. Día cerrado → sin horarios.

---

## 5.2 Anticipación mínima

```text
primera_hora_permitida =
ahora + ANTICIPACION_MINIMA_MINUTOS
```

Se selecciona el primer bloque configurado igual o posterior.

---

## 5.3 Última reservación

Debe utilizar una configuración apropiada y no un literal disperso.

Con el contrato vigente:

```text
ultima_hora =
cierre - MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION
```

---

## 5.4 Horizonte

```text
hoy
...
hoy + HORIZONTE_MAXIMO_DIAS
```

---

## 5.5 Navegación del mapa

La anticipación mínima restringe altas y cambios, no navegación histórica permitida por el mapa.

---

# 6. Mesas y grupos

## 6.1 Elegibilidad

Una mesa participa cuando:

```text
activo = 1
reservable = 1
tipo = mesa
```

Quedan excluidos:

- barra;
- caja;
- para llevar;
- elementos no reservables.

---

## 6.2 Grupos

Se identifican por `mesas.numero`.

---

## 6.3 Capacidad

La capacidad de una combinación es la suma actual de `mesas.capacidad`.

---

## 6.4 Asignación automática

Orden:

1. Mesa individual.
2. Grupo autorizado de dos.
3. Grupo autorizado de tres.

Preferencia:

1. Capacidad suficiente.
2. Menor cantidad de mesas.
3. Menor desperdicio.
4. Sin conflictos.
5. Grupo autorizado.

Todos los miembros deben tener:

```text
disponible_para_asignacion = true
```

---

## 6.5 Asignación manual

Sólo se modifica persistencia en:

```text
assignment_edit
```

Flujo:

```text
viewing
→ assignment_edit
→ saving
→ viewing
```

o:

```text
viewing
→ assignment_edit
→ conflict
```

La asignación manual utiliza `disponible_para_asignacion`, no los colores.

Una mesa puede mostrarse:

```text
verde con borde azul
```

o:

```text
azul
```

por proximidad de una reservación y, simultáneamente:

```text
disponible_para_asignacion = false
```

si el intervalo de la nueva reservación se traslapa.

En `assignment_edit`:

- el color se conserva para comunicar proximidad;
- el backend sigue siendo autoridad sobre asignabilidad;
- una mesa no asignable no puede persistirse;
- el frontend puede mostrar tooltip/estado de conflicto;
- no se debe inferir el permiso por color.

### Conflicto posterior de una asignación persistida

Una asignación persistida puede quedar posteriormente en conflicto debido a
una ocupación física nueva, como un ticket walk-in abierto sobre una de sus
mesas.

Este conflicto no invalida el snapshot histórico de la asignación ni impide
entrar en `assignment_edit` mientras:

- la reservación permanezca `confirmada`;
- continúe siendo editable;
- no tenga un ticket propio abierto.

La edición distingue obligatoriamente:

```text
currentAssignmentIds:
mesas persistidas actualmente en reservacion_mesas al abrir el modo de edición.

candidateSelectionIds:
mesas que el operador propone guardar como nueva asignación.
```

Ambos conjuntos son conceptualmente diferentes y no deben utilizar la misma
colección mutable.

Una mesa puede cumplir simultáneamente:

```text
asignada_actualmente = true
ocupada_fisicamente = true
disponible_para_asignacion = false
```

Esto significa que forma parte de la asignación persistida que se intenta
corregir, pero no puede formar parte de una nueva selección válida.

Las mesas de la asignación anterior permanecen visibles aunque hayan quedado
en conflicto. La validación de disponibilidad para guardar se aplica
exclusivamente a `candidateSelectionIds`; la asignación anterior no necesita
seguir disponible para poder reemplazarla.

Una mesa con ticket abierto ajeno no puede confirmarse como nueva asignación,
aunque anteriormente perteneciera a la reservación.

La reasignación:

- no cierra el ticket;
- no mueve el ticket;
- no modifica `ticket_mesas`;
- no vincula el ticket a la reservación;
- no realiza una reasignación automática silenciosa.

Una reservación `en_curso` con ticket propio abierto no utiliza este flujo
normal de `assignment_edit`.

---

# 7. Intervalos y proyección temporal

## 7.1 Traslape

Existe conflicto cuando:

```text
ocupacion_inicio < nueva_fin
AND
ocupacion_fin > nueva_inicio
```

Los intervalos son semiabiertos:

```text
[inicio, fin)
```

Por tanto:

```text
fin_existente = inicio_nuevo
```

no representa traslape.

---

## 7.2 Intervalo para capacidad y asignación

Para una nueva reservación:

```text
nueva_inicio = hora_consulta

nueva_fin =
hora_consulta
+ DURACION_RESERVACION_MINUTOS
```

Toda capacidad y asignación se evalúan contra ese intervalo completo.

---

## 7.3 Tickets abiertos

`ticket_mesas` es la fuente física canónica.

Un ticket está abierto cuando:

```text
estado = abierto
AND
closed_at IS NULL
```

Liberación proyectada:

```text
liberacion_estimada =
hora_apertura
+ DURACION_ESTIMADA_TICKET_MINUTOS
+ RETRASO_ESTIMADO_TICKET_MINUTOS
```

### Bloque actual

Si el ticket sigue realmente abierto:

```text
ocupada_fisicamente = true
```

aunque haya superado la liberación estimada.

### Proyección futura del mismo día

El ticket bloquea el intervalo cuando:

```text
hora_apertura < fin_consulta
AND
liberacion_estimada > inicio_consulta
```

Para otros días, los tickets actuales no bloquean.

---

## 7.4 Reservaciones confirmadas

Una reservación confirmada sin ticket tiene intervalo planificado:

```text
[
  hora_reservacion,
  hora_reservacion + DURACION_RESERVACION_MINUTOS
)
```

Mientras la reservación siga influyendo en disponibilidad, cualquier nueva reservación cuyo intervalo se traslape genera:

```text
disponible_para_asignacion = false
```

Esto puede ocurrir antes de que la mesa esté físicamente ocupada.

Ejemplo con configuración vigente:

```text
Reserva existente:
13:00–14:30

Nueva consulta:
12:00–13:30
```

Resultado:

```text
ocupada_fisicamente = false

bloqueada_en_intervalo = true

disponible_para_asignacion = false
```

POS puede tener simultáneamente otra decisión sobre `disponible_para_ticket`.

---

## 7.5 Inicio de servicio

Cuando se abre un ticket vinculado:

```text
confirmada → en_curso
```

Desde ese momento:

- la ocupación física proviene exclusivamente de `ticket_mesas`;
- `reservacion_mesas` no produce un segundo bloqueo;
- no existe doble conteo.

---

## 7.6 Holds

Bloquean cuando:

```text
estado = pendiente_verificacion
AND
hold_expires_at > ahora
```

Vencen inmediatamente desde el punto de vista de disponibilidad.

---

## 7.7 Reservaciones sin mesas

No bloquean mesas específicas.

Sí generan:

```text
demanda_no_asignada
```

---

## 7.8 Reloj real y hora de consulta

Son conceptos distintos:

```text
ahora
hora_consulta
```

`ahora` se utiliza para:

- vigencia;
- tolerancia;
- ausencia pendiente;
- POS actual.

`hora_consulta` se utiliza para:

- capacidad;
- asignabilidad;
- proyección del mapa.

El contrato backend debe transportar ambos contextos cuando sean necesarios.

---

# 8. Disponibilidad pública

Landing sólo continúa cuando:

1. horario válido;
2. capacidad real suficiente;
3. combinación automática válida;
4. sin conflictos;
5. revalidación transaccional exitosa.

De 1 a 12 personas:

```text
flujo público permitido
```

Desde 13:

```text
flujo público bloqueado
→ contactar restaurante
```

---

# 9. Administración

## 9.1 Principio

Administración usa exactamente la misma capacidad y asignabilidad que landing.

Puede continuar bajo políticas administrativas explícitas.

---

## 9.2 Asignación automática

1–12:

- opcional;
- mismo algoritmo de landing.

13+:

- automática deshabilitada;
- decisión `ASIGNACION_MANUAL_REQUERIDA`;
- acciones:
  - **Volver**
  - **Asignar más tarde**

---

## 9.3 Sin combinación automática

Código:

```text
SIN_ASIGNACION
```

Significa:

```text
puede existir capacidad
pero no existe combinación automática válida
```

No significa falta de capacidad.

Acciones:

- Volver.
- Asignar más tarde.

---

## 9.4 Capacidad insuficiente

Debe mostrar:

- comensales;
- capacidad disponible;
- diferencia;
- consecuencia.

Administración puede confirmar bajo responsabilidad si la política vigente lo permite.

---

## 9.5 Sin contacto

Código:

```text
REQUIERE_CONFIRMACION_SIN_CONTACTO
```

Acciones:

- Volver.
- Crear sin contacto.

Las decisiones se devuelven una por respuesta.

---

# 10. Tolerancia y ausencia pendiente

## 10.1 Condición

Existe ausencia pendiente cuando:

```text
ahora >
fecha_hora_reservacion
+ TOLERANCIA_LLEGADA_MINUTOS

AND estado = confirmada

AND no existe ticket abierto vinculado
```

Dentro de tolerancia:

```text
ahora <= limite_tolerancia
```

---

## 10.2 Efecto sobre capacidad

Al convertirse en ausencia pendiente:

- deja de bloquear capacidad;
- deja de bloquear asignación;
- no cambia automáticamente de estado;
- sigue `confirmada`;
- sigue requiriendo acción manual.

Por tanto:

```text
disponible_para_asignacion = true
```

si no existe otro conflicto.

---

## 10.3 Política POS — Opción B

Una ausencia pendiente **NO habilita todavía un walk-in en POS**.

Mientras:

```text
estado = confirmada
AND ausencia_pendiente = true
```

debe cumplirse:

```text
disponible_para_ticket = false
puede_iniciar = false
puede_marcar_no_show = true
```

La acción obligatoria es:

```text
Registrar ausencia
```

Sólo después de ejecutar:

```text
confirmada → no_show
```

la mesa puede utilizarse para un walk-in, sujeto a una nueva validación de ocupación.

No existe apertura automática de ticket.

---

## 10.4 Visual POS para ausencia pendiente

POS conserva el estado operativo especial:

```text
verde
+
indicador/borde gris
```

Texto accesible:

```text
Acción pendiente: registrar ausencia
```

Aunque el fondo sea verde:

```text
disponible_para_ticket = false
```

hasta registrar `no_show`.

El color no representa por sí solo permiso de acción.

---

## 10.5 Mapa de reservaciones

El mapa administrativo no crea un estado visual especial para ausencia pendiente.

Si la reserva dejó de influir en capacidad y no existe otro bloqueo:

```text
verde
```

La incidencia continúa disponible en panel/listado/detalle.

---

# 11. Estados de reservación

| Estado                   | Significado          | Efecto                   |
| ------------------------ | -------------------- | ------------------------ |
| `pendiente_verificacion` | Hold OTP             | Bloquea mientras vigente |
| `confirmada`             | Reservación aceptada | Bloquea según vigencia   |
| `en_curso`               | Ticket vinculado     | Bloquea por ticket       |
| `completada`             | Servicio cerrado     | No bloquea               |
| `cancelada`              | Cancelación          | No bloquea               |
| `no_show`                | Ausencia registrada  | No bloquea               |
| `expirada`               | Hold vencido         | No bloquea               |
| `reemplazada`            | Versión sustituida   | No bloquea               |

Transiciones:

```text
pendiente_verificacion
├── confirmada
└── expirada

confirmada
├── en_curso
├── cancelada
├── no_show
└── reemplazada

en_curso
└── completada
```

---

# 12. Modificación y cancelación pública

La modificación se permite hasta:

```text
LIMITE_MODIFICACION_MINUTOS
```

antes del inicio original.

Las propuestas utilizan holds vigentes.

La cancelación pública se permite hasta:

```text
hora_reservacion
+ TOLERANCIA_CANCELACION_PUBLICA_MINUTOS
```

No existe eliminación física.

---

# 13. Integración POS

## 13.1 Inicio de servicio

Puede iniciarse una reservación desde:

```text
INICIO_SERVICIO_ANTICIPADO_MINUTOS
```

antes de su horario.

Debe validarse transaccionalmente:

1. estado;
2. ventana;
3. mesas;
4. tickets;
5. versión;
6. ocupación;
7. idempotencia.

---

## 13.2 Ticket abierto

Prioridad:

```text
1. Ticket abierto
2. Reservación operable
3. Walk-in
```

Una mesa con ticket abierto nunca muestra **Abrir ticket**.

---

## 13.3 Walk-in con reservación próxima

Definir:

```text
segundos_para_inicio =
inicio_reservacion - ahora
```

### Normal

```text
segundos_para_inicio
>
AVISO_RESERVACION_PROXIMA_MINUTOS * 60
```

Resultado:

```text
disponible_para_ticket = true
requiere_advertencia_ticket = false
```

### Advertencia

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60
<
segundos_para_inicio
<=
AVISO_RESERVACION_PROXIMA_MINUTOS * 60
```

Resultado:

```text
disponible_para_ticket = true
requiere_advertencia_ticket = true
```

La primera petición no hace commit.

La segunda petición, después de confirmación explícita, revalida y puede abrir el ticket.

### Bloqueo

```text
0
<=
segundos_para_inicio
<=
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60
```

Resultado:

```text
disponible_para_ticket = false
requiere_advertencia_ticket = false
```

Exactamente en el límite de aviso:

```text
60 minutos actuales
→ advertencia
```

Exactamente en el límite de bloqueo:

```text
30 minutos actuales
→ bloqueado
```

con la configuración vigente.

---

## 13.4 Inicio exacto

En:

```text
segundos_para_inicio = 0
```

un walk-in está bloqueado.

La mesa debe resolverse como reservación operable.

La protección debe existir en backend aunque se invoque el endpoint directamente.

La UI no es barrera de seguridad ni de integridad.

---

## 13.5 Durante tolerancia

Si la reservación ya inició, todavía está dentro de tolerancia y no tiene ticket:

```text
disponible_para_ticket = false
```

La acción principal es iniciar la reservación.

---

## 13.6 Ausencia pendiente

Aplicar sección 10.3:

```text
disponible_para_ticket = false
```

hasta registrar ausencia.

---

# 14. Mapas y representación visual

## 14.1 Principio

POS y mapa de reservaciones reutilizan:

- mismo mapa;
- mismas coordenadas;
- mismo shell;
- mismos hechos backend.

Tienen presentadores separados.

No existe un segundo motor de capacidad.

---

## 14.2 El color NO determina la capacidad

Los colores indican el estado temporal/operativo.

La capacidad y la posibilidad de asignar se determinan mediante:

```text
disponible_para_asignacion
```

Por tanto puede existir:

```text
Mapa:
verde + borde azul punteado

disponible_para_asignacion:
false
```

si una reservación próxima provoca traslape con el intervalo completo solicitado.

Esto es correcto.

En `assignment_edit`, la mesa no puede guardarse aunque visualmente comunique proximidad y no ocupación actual.

---

## 14.3 Estados visuales del mapa de reservaciones

Prioridad:

```text
1. Selección válida
2. Ticket que ocupa/bloquea el punto consultado
3. Reservación que ya inició y sigue influyendo en disponibilidad
4. Reservación próxima dentro de la ventana de bloqueo
5. Reservación próxima dentro de la ventana de aviso
6. No utilizable
7. Disponible
```

### Amarillo — selección actual

```text
seleccionada = true
AND seleccion_valida = true
```

La selección no modifica los hechos backend.

---

### Rojo — ocupada

Se muestra rojo cuando:

- existe ticket que bloquea la hora consultada; o
- la reservación ya comenzó y sigue influyendo en disponibilidad.

Una reservación confirmada que inicia exactamente en la hora consultada:

```text
rojo
```

Mientras siga influyendo después del inicio:

```text
rojo
```

Si entra en ausencia pendiente y deja de influir:

```text
verde
```

en el mapa administrativo.

---

### Azul — reservación próxima

Cuando:

```text
0 <
minutos_para_inicio
<=
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
```

Con la configuración actual:

```text
1–30 minutos
```

y exactamente:

```text
30 minutos → azul
```

Ejemplo:

```text
Reserva: 13:00
Consulta: 12:30
Resultado: azul
```

---

### Verde con borde azul punteado — reservación cercana

Cuando:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
<
minutos_para_inicio
<=
AVISO_RESERVACION_PROXIMA_MINUTOS
```

Con la configuración actual:

```text
31–60 minutos
```

y exactamente:

```text
60 minutos → verde con borde azul punteado
```

Ejemplo:

```text
Reserva: 13:00
Consulta: 12:00
Resultado:
verde con borde azul punteado
```

---

### Verde — libre

Cuando no existe:

- ticket bloqueante;
- reservación iniciada que siga influyendo;
- reservación próxima dentro del aviso;
- condición no utilizable.

---

### Neutro — no utilizable

Barras, cajas, para llevar, inactivas o no reservables.

---

## 14.4 Matriz visual del mapa

Con reservación a las 13:00 y configuración actual:

| Hora consultada                           | Estado visual               |
| ----------------------------------------- | --------------------------- |
| Antes de 12:00                            | Verde                       |
| 12:00                                     | Verde + borde azul punteado |
| 12:01–12:29                               | Verde + borde azul punteado |
| 12:30                                     | Azul                        |
| 12:31–12:59                               | Azul                        |
| 13:00                                     | Rojo                        |
| Después de 13:00 mientras siga influyendo | Rojo                        |
| Ausencia pendiente sin otro bloqueo       | Verde                       |

Los límites deben derivarse de configuración, no de literales.

---

## 14.5 Diferencia visual y asignabilidad

Ejemplo:

```text
Reservación existente:
13:00

Duración:
90 minutos

Consulta:
12:00
```

Visual:

```text
verde + borde azul punteado
```

Pero una nueva reservación:

```text
12:00–13:30
```

se traslapa con:

```text
13:00–14:30
```

Por tanto:

```text
disponible_para_asignacion = false
```

Esto NO es una contradicción.

El visual responde:

```text
¿Qué tan próxima está la reservación existente?
```

La asignabilidad responde:

```text
¿Puede soportar esta mesa todo el nuevo intervalo?
```

---

## 14.6 POS

POS utiliza los mismos límites visuales antes del inicio:

```text
> aviso
→ verde

aviso–bloqueo
→ verde + borde azul punteado

0–bloqueo
→ azul
```

Después del inicio POS puede aplicar estados operativos adicionales:

- inicio de servicio;
- tolerancia;
- ausencia pendiente;
- ticket abierto.

Estos estados no obligan al mapa administrativo a usar el mismo color posterior al inicio.

---

## 14.7 Leyenda del mapa de reservaciones

Debe contener:

```text
Verde — Disponible

Verde + borde azul punteado —
Reservación cercana

Azul —
Reservación próxima

Rojo —
Ocupada

Amarillo —
Selección actual

Neutro —
No utilizable
```

---

## 14.8 Asignación manual

En modo `assignment_edit`:

- el mapa conserva los estados visuales anteriores;
- la selección válida continúa amarilla;
- la autorización se determina con `disponible_para_asignacion`;
- no se permite persistir una mesa cuyo intervalo esté bloqueado;
- una mesa de `currentAssignmentIds` puede permanecer roja por ticket abierto
  y mostrar una indicación secundaria de `asignada_actualmente`, pero no forma
  parte de `candidateSelectionIds` si no está disponible;
- una mesa azul o con borde azul puede ser visualmente seleccionable sólo como interacción exploratoria, pero debe quedar marcada como conflicto y no guardar;
- preferentemente el consumidor debe informar:
  - reservación próxima;
  - horario;
  - causa de no asignabilidad.

No usar colores como regla de negocio.

---

# 15. Shell de confirmación

Las superficies utilizan el mismo shell.

Debe soportar:

- título;
- descripción;
- resumen;
- advertencia;
- consecuencia;
- acciones provenientes del backend;
- loading;
- disabled;
- retorno de foco.

Los botones de decisiones no usan textos fijos.

Las acciones provienen de:

```text
acciones[].id
acciones[].label
acciones[].tipo
```

---

# 16. Contrato de decisiones y errores

Distinguir:

```text
exito
decision_requerida
error
```

Una decisión válida no se presenta como error.

Una operación con:

```text
commit = true
```

nunca puede afirmar:

```text
No se aplicaron cambios
```

Los mensajes y acciones provienen del catálogo canónico.

---

# 17. Servicios de dominio

## `ReservacionConfig`

Única fuente de configuración funcional.

## `HorarioReservacionService`

Horarios, excepciones, cierre, horizonte.

## `TicketTemporalService`

Ocupación física y liberación proyectada de tickets.

## `OcupacionMesasService`

Evalúa hechos de ocupación y conflicto de intervalos.

Debe diferenciar claramente:

```text
ocupacion_fisica
bloqueo_intervalo
```

## `CapacidadReservacionesService`

Calcula:

- capacidad física libre;
- demanda no asignada;
- capacidad real.

## `DisponibilidadReservacionService`

Compone capacidad y asignabilidad común.

## `MesaEstadoService`

No debe crear un segundo motor temporal.

Compone hechos derivados ya calculados:

```text
ocupada_fisicamente
bloqueada_en_intervalo
disponible_para_asignacion
disponible_para_ticket
requiere_advertencia_ticket
ausencia_pendiente
```

## `AsignacionMesasService`

Usa exclusivamente `disponible_para_asignacion`.

## `ReservacionVigenciaService`

Determina:

- tolerancia;
- ausencia pendiente;
- influencia en disponibilidad.

## `PuntoVentaReservacionService`

Autoridad de política POS:

- walk-in;
- warning;
- bloqueo;
- inicio de servicio;
- ausencia pendiente;
- idempotencia.

## `PosMesaProjectionPresenter`

Sólo presenta hechos POS.

No redefine ventanas.

## `ReservacionMapaMesaPresenter`

Sólo presenta hechos del mapa.

No calcula capacidad ni traslapes.

## `PosReservacionSerializer`

Serializa.

No decide negocio.

---

# 18. Transacciones e idempotencia

Mutaciones transaccionales:

- crear;
- confirmar;
- modificar;
- cancelar;
- reasignar;
- abrir ticket;
- cerrar ticket;
- registrar ausencia.

Toda mutación:

1. identidad;
2. CSRF;
3. transacción;
4. locks;
5. revalidación;
6. mutación;
7. commit/rollback.

---

# 19. Contratos mínimos de mesa

El backend debe poder emitir una estructura equivalente a:

```json
{
  "mesa_id": 4,
  "asignada_actualmente": false,
  "ocupada_fisicamente": false,
  "bloqueada_en_intervalo": true,
  "disponible_para_asignacion": false,
  "disponible_para_ticket": true,
  "requiere_advertencia_ticket": true,
  "ausencia_pendiente": false,
  "estado_visual_mapa": "reservacion-cercana",
  "estado_visual_pos": "libre",
  "modificadores_visual_mapa": ["reservacion_advertencia"],
  "modificadores_visual_pos": ["reservacion_advertencia"]
}
```

En `assignment_edit`, el contrato mínimo agrega `asignada_actualmente` y,
cuando exista, `causa_conflicto_asignacion`. Por ejemplo:

```json
{
  "mesa_id": 4,
  "asignada_actualmente": true,
  "ocupada_fisicamente": true,
  "disponible_para_asignacion": false,
  "causa_conflicto_asignacion": "ticket_abierto"
}
```

La lectura administrativa debe transportar además un snapshot explícito:

```json
{
  "assignment_snapshot": {
    "mesa_ids": [4],
    "version": "..."
  }
}
```

El amarillo sólo representa una mesa incluida en `candidateSelectionIds` y
con `disponible_para_asignacion = true`. La ocupación física por ticket
mantiene precedencia roja.

Este contrato es válido para una mesa físicamente libre que no puede asignarse a un intervalo completo pero todavía puede recibir un walk-in con advertencia.

---

# 20. Caso normativo de referencia

Reservación:

```text
13:00
```

Configuración:

```text
DURACION_RESERVACION_MINUTOS = 90
AVISO_RESERVACION_PROXIMA_MINUTOS = 60
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS = 30
TOLERANCIA_LLEGADA_MINUTOS = 15
```

## 11:30

```text
Nueva reservación:
11:30–13:00

No traslapa.
```

Resultado:

```text
disponible_para_asignacion = true
disponible_para_ticket = true
warning = false
mapa = verde
POS = verde
```

## 12:00

```text
Nueva reservación:
12:00–13:30

Traslapa.
```

Resultado:

```text
ocupada_fisicamente = false
disponible_para_asignacion = false
disponible_para_ticket = true
requiere_advertencia_ticket = true

mapa =
verde + borde azul punteado

POS =
verde + borde azul punteado
```

## 12:30

Resultado:

```text
ocupada_fisicamente = false
disponible_para_asignacion = false
disponible_para_ticket = false
requiere_advertencia_ticket = false

mapa = azul
POS = azul
```

## 13:00

Resultado:

```text
disponible_para_asignacion = false
disponible_para_ticket = false

mapa = rojo

POS =
reservación operable / iniciar servicio
```

No se permite walk-in directo.

## Dentro de tolerancia

```text
disponible_para_ticket = false
```

## Después de tolerancia sin ticket

```text
ausencia_pendiente = true

disponible_para_asignacion = true
disponible_para_ticket = false

mapa = verde

POS =
verde + indicador de ausencia pendiente
```

## Después de Registrar ausencia

```text
estado = no_show
ausencia_pendiente = false
```

Se revalida.

Si no existe otro conflicto:

```text
disponible_para_ticket = true
```

---

# 21. Criterios de aceptación

## Capacidad

1. Capacidad usa el intervalo completo configurable.
2. `DURACION_RESERVACION_MINUTOS` no se replica en literales.
3. Mesas asignadas descuentan todos sus asientos.
4. Sin mesas usa demanda no asignada.
5. Intervalos son semiabiertos.
6. `disponible_para_asignacion` coincide con capacidad.

## POS

7. `disponible_para_ticket` no se deriva de capacidad.
8. Exactamente en aviso → warning.
9. Exactamente en bloqueo → bloqueado.
10. Inicio exacto → walk-in bloqueado.
11. Ticket abierto → ver/continuar ticket.
12. Ausencia pendiente → no permite walk-in.
13. Registrar ausencia es obligatorio antes de walk-in.
14. No-show es manual.
15. Backend revalida toda acción.

## Mapa de reservaciones

16. Verde = disponible.
17. Verde + borde azul punteado = reservación dentro de ventana de aviso.
18. Azul = reservación dentro de ventana de bloqueo.
19. Rojo = ticket bloqueante o reservación ya iniciada que siga influyendo.
20. Amarillo = selección actual.
21. Exactamente 60 min → borde azul punteado con configuración actual.
22. Exactamente 30 min → azul con configuración actual.
23. Inicio exacto → rojo.
24. Los colores no determinan asignabilidad.
25. `assignment_edit` usa `disponible_para_asignacion`.
26. Ausencia pendiente no crea color especial en mapa administrativo.

## Integridad

27. POS y mapa consumen los mismos hechos.
28. Pueden tener presentaciones diferentes después del inicio.
29. JavaScript no calcula intervalos.
30. JavaScript no calcula ventanas 60/30.
31. No existe doble conteo reservación/ticket.
32. Configuración es única.
33. Serializers no deciden negocio.
34. Presenters no deciden negocio.
35. Todas las mutaciones son transaccionales e idempotentes.

---

# 22. Documentación complementaria

Los documentos de:

```text
docs/reservaciones/
```

pueden contener auditorías, reportes y evidencia.

No modifican esta fuente.

Sólo:

```text
docs/reservaciones.md
```

define el comportamiento vigente.
