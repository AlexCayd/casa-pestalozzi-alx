# Módulo de reservaciones — Fuente de verdad vigente

**Proyecto:** Casa Pestalozzi
**Versión:** 2026-08-09
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
7. Los colores y modificadores visuales no sustituyen hechos de dominio ni permisos operativos.

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

Las superficies pueden derivar decisiones diferentes cuando este documento lo establece expresamente.

---

# 3. Conceptos canónicos

## 3.1 Ocupación canónica

La ocupación canónica reúne los hechos que pueden afectar mesas o capacidad:

- Tickets abiertos.
- Reservaciones `confirmada`.
- Holds vigentes de `pendiente_verificacion`.
- Reservaciones administrativas confirmadas sin mesas.
- Mesas activas y reservables.
- Vigencia de reservaciones y holds.
- Proyección temporal de tickets.

Se calcula exclusivamente en backend.

No existe un segundo motor para POS, mapa, landing o administración.

---

## 3.2 Hechos independientes

Debe distinguirse expresamente entre:

```text
ocupada_fisicamente
bloqueada_en_intervalo
disponible_para_asignacion
disponible_para_ticket
ausencia_pendiente
```

No son aliases.

Una mesa puede, por ejemplo:

```text
ocupada_fisicamente = false
bloqueada_en_intervalo = true
disponible_para_asignacion = false
disponible_para_ticket = true
```

sin contradicción.

---

## 3.3 `ocupada_fisicamente`

Responde:

```text
¿La mesa está siendo utilizada físicamente ahora?
```

La fuente física canónica es:

```text
ticket abierto
+
ticket_mesas
```

Una reservación sin ticket no convierte por sí sola:

```text
ocupada_fisicamente = true
```

aunque pueda bloquear capacidad o asignación.

---

## 3.4 `bloqueada_en_intervalo`

Responde:

```text
¿La mesa presenta un conflicto durante el intervalo completo consultado?
```

Para una reservación nueva:

```text
intervalo_consulta =
[
    hora_consulta,
    hora_consulta + DURACION_RESERVACION_MINUTOS
)
```

`mesa_ids_bloqueadas` y `bloqueada_en_intervalo` significan exclusivamente:

```text
no disponible para el intervalo solicitado
```

No significan automáticamente:

```text
ocupada_fisicamente
```

ni:

```text
no puede abrir ticket POS
```

---

## 3.5 `disponible_para_asignacion`

Responde:

```text
¿Puede esta mesa soportar toda la nueva reservación?
```

Regla:

```text
bloqueada_en_intervalo = true
→ disponible_para_asignacion = false
```

```text
bloqueada_en_intervalo = false
AND mesa elegible
→ disponible_para_asignacion = true
```

No se calcula sólo comparando la hora puntual.

---

## 3.6 `disponible_para_ticket`

Responde:

```text
¿Puede iniciarse ahora un ticket walk-in?
```

Se determina mediante:

- ticket abierto;
- ocupación física;
- reservación próxima;
- ventana POS;
- tolerancia;
- ausencia pendiente;
- estado operativo.

No depende directamente de `disponible_para_asignacion`.

---

## 3.7 `requiere_advertencia_ticket`

Cuando:

```text
requiere_advertencia_ticket = true
```

debe cumplirse:

```text
disponible_para_ticket = true
```

pero la apertura requiere confirmación explícita antes del commit.

---

# 4. Capacidad

## 4.1 Capacidad física libre

```text
capacidad_fisica_libre =
SUM(
    mesas.capacidad
    de mesas con disponible_para_asignacion = true
)
```

Una mesa comprometida descuenta toda su capacidad física.

---

## 4.2 Demanda no asignada

```text
demanda_no_asignada =
SUM(
    comensales de reservaciones confirmadas sin mesas
    que se traslapan con el intervalo
)
```

No se asigna artificialmente esa demanda a una mesa concreta.

---

## 4.3 Capacidad real

```text
capacidad_real_disponible =
MAX(
    0,
    capacidad_fisica_libre - demanda_no_asignada
)
```

---

# 5. Configuración canónica

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

No repetir estos valores funcionales como literales en controladores, JavaScript, presenters, SQL o vistas.

---

## 5.1 Parámetros independientes

Aunque actualmente tengan valores iguales:

```text
DURACION_RESERVACION_MINUTOS
DURACION_ESTIMADA_TICKET_MINUTOS
```

son reglas diferentes.

También son independientes:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
INICIO_SERVICIO_ANTICIPADO_MINUTOS
```

Que actualmente ambos sean 30 no implica que deban conservar el mismo valor.

---

# 6. Horarios

## 6.1 Anticipación mínima

```text
primera_hora_permitida =
ahora + ANTICIPACION_MINIMA_MINUTOS
```

## 6.2 Última reservación

```text
ultima_hora =
cierre - MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION
```

## 6.3 Horizonte

```text
hoy
...
hoy + HORIZONTE_MAXIMO_DIAS
```

---

# 7. Mesas y asignación

Una mesa participa cuando:

```text
activo = 1
reservable = 1
tipo = mesa
```

Barras, cajas, para llevar y elementos no reservables quedan excluidos.

---

## 7.1 Asignación automática

Orden:

1. Mesa individual.
2. Grupo autorizado de dos.
3. Grupo autorizado de tres.

Todos los miembros deben cumplir:

```text
disponible_para_asignacion = true
```

---

## 7.2 Asignación manual

Sólo se modifica persistencia en:

```text
assignment_edit
```

La autorización depende de:

```text
disponible_para_asignacion
```

y nunca del color.

---

## 7.3 Snapshot y candidatos

La edición distingue:

```text
currentAssignmentIds
candidateSelectionIds
```

`currentAssignmentIds`:

```text
asignación persistida actual
```

`candidateSelectionIds`:

```text
propuesta que se pretende guardar
```

Ambos conjuntos son independientes.

---

## 7.4 Conflicto posterior

Una mesa previamente asignada puede quedar ocupada posteriormente por un ticket.

Puede existir:

```text
asignada_actualmente = true
ocupada_fisicamente = true
disponible_para_asignacion = false
```

La mesa sigue visible como asignación actual, pero no puede formar parte de una nueva selección válida.

El ticket no se cierra, mueve ni modifica.

---

# 8. Intervalos

## 8.1 Traslape

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

no representa conflicto.

---

## 8.2 Reservación

Una reservación tiene intervalo planificado:

```text
[
    hora_reservacion,
    hora_reservacion + DURACION_RESERVACION_MINUTOS
)
```

Con configuración actual:

```text
14:00
→ intervalo planificado 14:00–15:30
```

Una nueva reservación a:

```text
14:05
```

produce:

```text
14:05–15:35
```

y se traslapa con:

```text
14:00–15:30
```

Por tanto, la misma mesa:

```text
disponible_para_asignacion = false
```

Esto es independiente de su representación visual.

---

# 9. Tickets

## 9.1 Ticket abierto

Un ticket está abierto cuando:

```text
estado = abierto
AND
closed_at IS NULL
```

---

## 9.2 Liberación proyectada

```text
liberacion_estimada =
hora_apertura
+ DURACION_ESTIMADA_TICKET_MINUTOS
+ RETRASO_ESTIMADO_TICKET_MINUTOS
```

Para proyecciones futuras del día, se utiliza esa liberación.

Para el momento actual:

```text
ticket realmente abierto
→ ocupada_fisicamente = true
```

aunque haya superado su tiempo estimado.

---

# 10. Tolerancia y ausencia pendiente

## 10.1 Tolerancia

```text
limite_tolerancia =
hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS
```

Dentro de tolerancia:

```text
ahora <= limite_tolerancia
```

Ausencia pendiente:

```text
ahora > limite_tolerancia
AND estado = confirmada
AND no existe ticket propio abierto
```

---

## 10.2 Política funcional

Una ausencia pendiente:

- no cambia automáticamente a `no_show`;
- conserva la acción manual **Registrar ausencia**;
- no permite iniciar la reservación;
- no permite walk-in en POS hasta registrar ausencia;
- no modifica por sí sola los demás hechos físicos de la mesa;
- deja de influir en capacidad y asignación de mesas;
- conserva el indicador visual gris como acción pendiente.

```text
ausencia_pendiente = true

→ disponible_para_ticket = false
→ reservacion_influye_en_disponibilidad = false
→ disponible_para_asignacion depende de los demás conflictos
→ puede_iniciar = false
→ puede_marcar_no_show = true
```

La restricción POS y la asignabilidad administrativa son permisos distintos:

```text
ausencia_pendiente = true
sin otro conflicto

→ disponible_para_ticket = false
→ disponible_para_asignacion = true
```

---

## 10.3 No-show

Al registrar:

```text
confirmada → no_show
```

La operación es transaccional.

Después se recalcula completamente la mesa.

---

# 11. Simbología visual canónica

Esta sección es la única definición normativa de colores del mapa.

Los colores representan estados visuales.

No determinan por sí mismos:

```text
disponible_para_asignacion
disponible_para_ticket
```

La representación se divide en:

```text
ESTADO BASE
+
MODIFICADORES
+
SELECCIÓN
```

---

## 11.1 Verde — disponible

```text
VERDE
```

Significa:

```text
mesa visualmente disponible
```

Se utiliza cuando ningún estado con mayor prioridad define otro color.

---

## 11.2 Verde con borde azul punteado — reservación cercana

Una reservación entra en ventana de aviso cuando:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
<
minutos_para_inicio
<=
AVISO_RESERVACION_PROXIMA_MINUTOS
```

Con configuración actual:

```text
30 < minutos_para_inicio <= 60
```

Visual:

```text
VERDE
+
BORDE AZUL PUNTEADO
```

Significado:

```text
Reservación cercana
```

Límites actuales:

```text
60 minutos exactos
→ verde + borde azul punteado

30 minutos exactos
→ ya no pertenece a esta ventana
```

---

## 11.3 Azul — reservación próxima y tolerancia

La mesa se muestra:

```text
AZUL
```

desde:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
```

antes de la reservación hasta inmediatamente antes de su inicio.

Formalmente:

```text
inicio_azul =
hora_reservacion
- BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
```

```text
fin_azul =
hora_reservacion
```

Intervalo visual:

```text
[
    inicio_azul,
    hora_reservacion
)
]
```

Con configuración actual:

```text
30 minutos antes
→ azul

inmediatamente antes del inicio
→ azul

hora exacta
→ rojo
```

Ejemplo:

```text
Reservación: 14:00

13:30 → azul
13:59:59 → azul
14:00 → rojo
```

El azul comunica:

```text
reservación próxima o dentro de tolerancia
```

No significa ocupación física.

---

## 11.4 Rojo — ocupada

```text
ROJO
```

representa una mesa ocupada o dentro de una ocupación planificada vigente.

Se utiliza por:

### Ticket abierto

```text
ticket abierto sobre la mesa
→ rojo
```

### Reservación dentro de su intervalo planificado

La reservación conserva un intervalo planificado de:

```text
DURACION_RESERVACION_MINUTOS
```

Con configuración actual:

```text
90 minutos
```

La reservación se representa como rojo desde su hora exacta de inicio y mientras continúe influyendo en el intervalo consultado:

```text
ROJO
```

Ejemplo:

```text
Reservación 14:00
Duración 90
Tolerancia 15

13:30–13:59:59
→ azul

14:00–15:29:59
→ rojo

15:30
→ recalcular
```

Si la tolerancia ya expiró y la ausencia sigue pendiente:

```text
estado base recalculado sin esa reservación + indicador gris
```

La ausencia pendiente ya no bloquea capacidad ni asignación. Si existe otro ticket o reservación bloqueante, ese otro hecho conserva su color base.

---

## 11.5 Amarillo — selección actual

```text
AMARILLO
```

significa exclusivamente:

```text
selección válida actual del operador
```

Sólo aplica cuando:

```text
seleccionada = true
AND
seleccion_valida = true
```

El amarillo tiene prioridad visual durante una selección válida.

No convierte una mesa no asignable en válida.

---

## 11.6 Neutro — no utilizable

Representa:

- barra;
- caja;
- para llevar;
- mesa inactiva;
- mesa no reservable;
- elemento no operativo.

---

# 12. Borde gris — tolerancia expirada

El gris:

```text
NO ES UN COLOR BASE
```

Es un modificador visual.

Representa:

```text
Existe una ausencia pendiente actual asociada a esta mesa.
```

Debe superponerse al estado base.

---

## 12.1 Composición

Son combinaciones válidas:

```text
verde + gris
```

```text
azul + gris
```

```text
rojo + gris
```

```text
verde + borde azul punteado + indicador gris
```

El gris no sustituye:

- verde;
- azul;
- rojo;
- borde azul punteado.

---

## 12.2 Alcance del gris

El borde gris se muestra únicamente cuando existe:

```text
ausencia_pendiente = true
```

para una reservación actual cuya tolerancia ya expiró y cuya incidencia todavía no ha sido resuelta.

No se muestra:

- por reservaciones futuras;
- por reservaciones históricas;
- por reservaciones ya `no_show`;
- por reservaciones canceladas;
- por reservaciones completadas;
- indiscriminadamente en todas las mesas de una reservación diferente.

Debe estar asociado exclusivamente a las mesas afectadas por la ausencia pendiente actual.

---

## 12.3 El gris no determina disponibilidad

Ejemplo:

```text
estado base = rojo
ausencia_pendiente = true

→ rojo + gris
```

Otro:

```text
estado base = verde
ausencia_pendiente = true

→ verde + gris
```

Otro:

```text
estado base = azul
ausencia_pendiente = true

→ azul + gris
```

La política funcional se obtiene por hechos backend, no por el gris.

---

# 13. Matriz visual normativa

Con una reservación a las:

```text
14:00
```

y configuración:

```text
AVISO = 60
BLOQUEO = 30
TOLERANCIA = 15
DURACION = 90
```

la representación base es:

| Hora consultada                   | Visual base                 |
| --------------------------------- | --------------------------- |
| Antes de 13:00                    | Verde                       |
| 13:00                             | Verde + borde azul punteado |
| 13:01–13:29                       | Verde + borde azul punteado |
| 13:30                             | Azul                        |
| 13:31–13:59                       | Azul                        |
| 14:00                             | Rojo                        |
| 14:01–15:29                       | Rojo                        |
| 15:30 en adelante                 | Recalcular estado base      |

Si después de las 14:15 existe:

```text
ausencia_pendiente = true
```

se agrega gris.

Ejemplo:

```text
14:20
base = rojo
ausencia pendiente = true

→ rojo + gris
```

Si a las 15:30 la reservación ya no determina el estado base y no existe otro bloqueo:

```text
base = verde
ausencia pendiente = true

→ verde + gris
```

Si a esa hora existe otra reservación próxima:

```text
base = azul
ausencia pendiente = true

→ azul + gris
```

---

# 14. Prioridad visual

Primero se calcula el estado base.

Prioridad:

```text
1. Selección válida → amarillo

2. Ticket abierto → rojo

3. Reservación iniciada y vigente:
   desde hora exacta
   hasta fin del intervalo planificado
   → rojo

4. Reservación próxima:
   desde BLOQUEO minutos antes
   hasta inmediatamente antes del inicio
   → azul

5. Reservación cercana:
   AVISO–BLOQUEO
   → verde + borde azul punteado

6. No utilizable → neutro

7. Disponible → verde
```

Después de calcular el estado base:

```text
si ausencia_pendiente = true
→ agregar indicador gris
```

El indicador gris nunca participa en la elección del estado base.

Cuando la reservación asociada está en `ausencia_pendiente`, se retira del cálculo de capacidad y asignación. El estado base se recalcula con tickets y otras reservaciones; después se agrega el gris.

---

# 15. Relación entre visual y capacidad

El mapa visual no responde necesariamente:

```text
¿puedo asignar esta mesa?
```

Responde:

```text
¿qué contexto operativo tiene esta mesa?
```

Por ello puede existir:

```text
visual =
verde + borde azul punteado

disponible_para_asignacion =
false
```

Ejemplo:

```text
Reservación existente:
14:00–15:30

Nueva reservación:
13:00–14:30
```

Visual a las 13:00:

```text
verde + borde azul punteado
```

Pero los intervalos se traslapan.

Por tanto:

```text
disponible_para_asignacion = false
```

Esto es correcto.

Excepción de ausencia pendiente:

```text
ausencia_pendiente = true
sin ticket ni otro conflicto

disponible_para_asignacion = true
```

---

# 16. Caso 14:00 vs 14:05

Reservación existente:

```text
14:00
```

Duración:

```text
DURACION_RESERVACION_MINUTOS = 90
```

Intervalo:

```text
[14:00, 15:30)
```

Nueva reservación:

```text
14:05
```

Intervalo:

```text
[14:05, 15:35)
```

Condición:

```text
14:00 < 15:35
AND
15:30 > 14:05
```

Existe traslape.

Por tanto, esa misma mesa:

```text
bloqueada_en_intervalo = true
disponible_para_asignacion = false
```

No debe permitirse reservar la misma mesa a las 14:05.

Esto no es un problema de la ventana visual 60–30.

Es un conflicto real entre dos reservaciones cuyos intervalos planificados de ocupación se superponen.

---

# 17. POS

## 17.1 Walk-in normal

Cuando:

```text
minutos_para_inicio > AVISO_RESERVACION_PROXIMA_MINUTOS
```

```text
disponible_para_ticket = true
requiere_advertencia_ticket = false
```

---

## 17.2 Walk-in con advertencia

Cuando:

```text
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
<
minutos_para_inicio
<=
AVISO_RESERVACION_PROXIMA_MINUTOS
```

```text
disponible_para_ticket = true
requiere_advertencia_ticket = true
```

La primera petición no hace commit.

La segunda petición confirmada revalida.

---

## 17.3 Bloqueo de walk-in

Cuando:

```text
0
<=
minutos_para_inicio
<=
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS
```

```text
disponible_para_ticket = false
```

---

## 17.4 Durante tolerancia

Desde el inicio hasta:

```text
hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS
```

el walk-in continúa bloqueado.

Visual:

```text
rojo
```

---

## 17.5 Tolerancia vencida

Si existe ausencia pendiente:

```text
disponible_para_ticket = false
puede_marcar_no_show = true
```

hasta registrar ausencia.

El visual conserva el estado base y agrega gris.

---

# 18. Mapa de reservaciones

El mapa utiliza la misma simbología:

```text
Verde
→ disponible

Verde + borde azul punteado
→ reservación cercana

Azul
→ reservación próxima o dentro de tolerancia

Rojo
→ ticket abierto o reservación iniciada y vigente dentro de su intervalo planificado

Amarillo
→ selección actual válida

Neutro
→ no utilizable

Gris
→ modificador de tolerancia vencida/ausencia pendiente
```

El mapa no deduce asignabilidad por color.

---

# 19. Representación accesible

El estado debe expresarse mediante texto además del color.

Ejemplos:

```text
Mesa 4, disponible.
```

```text
Mesa 4, reservación cercana.
```

```text
Mesa 4, reservación próxima.
```

```text
Mesa 4, ocupada.
```

```text
Mesa 4, ocupada. Acción pendiente: registrar ausencia.
```

```text
Mesa 4, reservación próxima. Acción pendiente: registrar ausencia.
```

```text
Mesa 4, disponible. Acción pendiente: registrar ausencia.
```

---

# 20. Contrato mínimo de proyección

El backend debe poder transportar:

```json
{
  "mesa_id": 4,

  "ocupada_fisicamente": false,
  "bloqueada_en_intervalo": true,

  "disponible_para_asignacion": false,
  "disponible_para_ticket": true,

  "requiere_advertencia_ticket": true,
  "ausencia_pendiente": false,

  "estado_visual_mapa": "libre",
  "estado_visual_pos": "libre",

  "modificadores_visual_mapa": ["reservacion_advertencia"],

  "modificadores_visual_pos": ["reservacion_advertencia"]
}
```

Ejemplo con tolerancia vencida:

```json
{
  "mesa_id": 4,

  "estado_visual_mapa": "ocupada",

  "modificadores_visual_mapa": ["ausencia_pendiente"],

  "ausencia_pendiente": true
}
```

Resultado:

```text
rojo + gris
```

---

# 21. Responsabilidad de capas

## Dominio

Calcula:

- ocupación;
- intervalos;
- vigencia;
- permisos;
- ausencia.

## Presenters

Transforman hechos en:

```text
estado_visual_base
+
modificadores
+
aria
```

No recalculan negocio.

## JavaScript

Renderiza:

```text
estado_visual
modificadores
acciones
```

No calcula:

- 90;
- 60;
- 30;
- 15;
- traslapes;
- tolerancia;
- disponibilidad.

## CSS

Representa:

- color base;
- borde azul;
- modificador gris;
- selección amarilla.

No decide reglas de negocio.

---

# 22. Servicios de dominio

## `ReservacionConfig`

Fuente única de configuración.

## `HorarioReservacionService`

Horarios, excepciones y horizonte.

## `TicketTemporalService`

Tickets y liberación proyectada.

## `OcupacionMesasService`

Bloqueos e intervalos.

## `CapacidadReservacionesService`

Capacidad física y demanda.

## `DisponibilidadReservacionService`

Disponibilidad común.

## `MesaEstadoService`

Compone hechos por mesa.

## `AsignacionMesasService`

Valida selección manual.

## `ReservacionVigenciaService`

Tolerancia y ausencia.

## `ReservacionPoliticaPosService`

Política temporal POS.

## `PuntoVentaReservacionService`

Mutaciones POS.

## `PosMesaProjectionPresenter`

Presentación POS.

## `ReservacionMapaMesaPresenter`

Presentación del mapa de reservaciones.

## `PosReservacionSerializer`

Serialización únicamente.

---

# 23. Transacciones

Se requiere transacción para:

- crear;
- confirmar;
- modificar;
- cancelar;
- reasignar;
- iniciar servicio;
- abrir ticket;
- cerrar ticket;
- registrar ausencia.

Toda mutación:

1. valida identidad;
2. valida CSRF;
3. abre transacción;
4. bloquea recursos;
5. recalcula hechos;
6. valida;
7. muta;
8. commit o rollback.

---

# 24. Criterios de aceptación visual

1. Verde significa disponible.
2. Verde + borde azul punteado significa reservación entre aviso y bloqueo.
3. Exactamente en aviso se usa verde + borde azul punteado.
4. Exactamente en bloqueo se usa azul.
5. Azul comienza `BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS` antes.
6. Azul continúa desde el inicio durante toda la tolerancia.
7. Exactamente al final de tolerancia todavía corresponde azul.
8. Después de tolerancia, mientras la reservación siga dentro de su intervalo planificado, corresponde rojo.
9. Ticket abierto corresponde rojo.
10. Amarillo significa únicamente selección válida.
11. Gris nunca es color base.
12. Gris significa tolerancia vencida/ausencia pendiente actual.
13. Gris puede superponerse a verde.
14. Gris puede superponerse a azul.
15. Gris puede superponerse a rojo.
16. Gris puede coexistir con borde azul punteado.
17. El gris sólo aparece sobre mesas afectadas por una ausencia pendiente vigente.
18. Registrar no-show elimina la incidencia gris después de revalidar.
19. JavaScript no calcula ventanas temporales.
20. Los colores no determinan capacidad ni permisos.

---

# 25. Criterios de aceptación funcional

1. Capacidad usa todo el intervalo configurable.
2. Una reservación de 14:00 bloquea la misma mesa para una reservación de 14:05.
3. Los intervalos son semiabiertos.
4. El fin exacto de una ocupación permite iniciar otra.
5. Ticket actual abierto conserva ocupación física.
6. Ticket futuro se proyecta usando duración estimada configurable.
7. `disponible_para_asignacion` no depende del color.
8. `disponible_para_ticket` no depende del color.
9. POS permite walk-in en ventana de advertencia con confirmación.
10. POS bloquea walk-in dentro de la ventana de bloqueo.
11. Ausencia pendiente mantiene walk-in bloqueado hasta registrar ausencia.
12. No-show sigue siendo manual.
13. Reasignación usa snapshot y candidatos separados.
14. Ticket ajeno no se modifica al reasignar.
15. Una nueva candidata con ticket abierto no puede persistirse.
16. No existe doble conteo ticket/reservación.
17. Toda duración proviene de configuración.
18. No existe DDL implícito desde interfaces.

---

# 26. Caso normativo completo

Reservación:

```text
14:00
```

Configuración:

```text
DURACION_RESERVACION_MINUTOS = 90
AVISO_RESERVACION_PROXIMA_MINUTOS = 60
BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS = 30
TOLERANCIA_LLEGADA_MINUTOS = 15
```

## 12:59

```text
visual = verde
```

## 13:00

```text
visual = verde + borde azul punteado
```

## 13:29

```text
visual = verde + borde azul punteado
```

## 13:30

```text
visual = azul
```

## 13:59

```text
visual = azul
```

## 14:00

```text
visual = azul
walk-in = bloqueado
reservación = operable
```

## 14:15

```text
visual = azul
```

## Después de 14:15 sin llegada

```text
ausencia_pendiente = true
```

Mientras continúe el intervalo planificado:

```text
visual base = rojo
modificador = gris

resultado = rojo + gris
```

## 15:30

Finaliza el intervalo planificado.

Se recalcula el estado base.

Si no existe ningún otro hecho:

```text
base = verde
```

Si la ausencia sigue pendiente:

```text
resultado = verde + gris
```

Si existe otra reservación próxima:

```text
base = azul
resultado = azul + gris
```

Si existe ticket:

```text
base = rojo
resultado = rojo + gris
```

---

# 27. Documentación complementaria

Los archivos ubicados en:

```text
docs/reservaciones/
```

pueden contener:

- auditorías;
- reportes;
- evidencia;
- pruebas de cierre.

No modifican este contrato.

Sólo:

```text
docs/reservaciones.md
```

define el comportamiento vigente.
