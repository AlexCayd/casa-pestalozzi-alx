# Módulo de reservaciones — Fuente de verdad vigente

**Proyecto:** Casa Pestalozzi
**Versión:** 2026-08-10
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

1. ****Landing pública.****
2. ****Panel administrativo.****
3. ****Mapa de gestión de reservaciones.****
4. ****Mapa del punto de venta.****

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
- conserva la acción manual ****Registrar ausencia****;
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

# 11. Simbología visual canónica del mapa administrativo

Esta sección define la presentación del mapa administrativo de reservaciones.

El POS tiene una presentación propia. Comparte los hechos de dominio, pero no
debe copiar automáticamente el color del mapa:

```text
mismos hechos backend
≠
mismo color en todas las superficies
```

En particular:

```text
MAPA ADMINISTRATIVO
rojo puede representar ocupación planificada

POS
rojo representa ocupación física o ticket abierto
```

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

## 11.3 Azul — reservación próxima

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

Intervalo visual del mapa administrativo:

```text
[
    inicio_azul,
    hora_reservacion
)
```

Con configuración actual:

```text
30 minutos antes
→ azul

inmediatamente antes del inicio
→ azul

hora exacta
→ rojo en el mapa administrativo
```

Ejemplo:

```text
Reservación: 14:00

13:30 → azul
13:59:59 → azul
14:00 → rojo
```

El azul del mapa administrativo comunica:

```text
reservación próxima antes de su inicio
```

No representa tolerancia posterior al inicio y no significa ocupación física.
La tolerancia azul posterior al inicio pertenece exclusivamente a la presentación POS definida en la sección 17.

---

## 11.4 Rojo — ocupada

```text
ROJO
```

representa en el mapa administrativo una mesa ocupada físicamente o una ocupación planificada vigente que todavía influye en la proyección.

Se utiliza por:

### Ticket abierto

```text
ticket abierto sobre la mesa
→ rojo
```

### Reservación iniciada que todavía influye

La reservación tiene un intervalo planificado de:

```text
DURACION_RESERVACION_MINUTOS
```

Con configuración actual:

```text
90 minutos
```

Mientras la reservación siga influyendo en disponibilidad, el mapa administrativo la representa en rojo desde su hora exacta de inicio dentro del intervalo planificado:

```text
hora_consulta >= hora_reservacion
AND
hora_consulta < hora_reservacion + DURACION_RESERVACION_MINUTOS
AND
reservacion_influye_en_disponibilidad = true

→ rojo
```

Ejemplo de proyección mientras la reservación todavía influye:

```text
Reservación 14:00
Duración 90

13:30–13:59:59
→ azul

14:00–15:29:59
→ rojo

15:30
→ recalcular
```

La matriz anterior describe la proyección planificada. `ausencia_pendiente` se decide con `ahora`, no únicamente con `hora_consulta`.

Si la tolerancia real ya expiró, no existe ticket propio abierto y se cumple:

```text
ausencia_pendiente = true
```

entonces esa reservación deja de influir en capacidad y asignación y **deja también de determinar el estado base visual**. El estado base se recalcula sin esa reservación y después se agrega el indicador gris.

Ejemplos después de una ausencia pendiente real:

```text
sin otro conflicto
→ verde + gris

otro ticket abierto
→ rojo + gris

otra reservación próxima
→ azul + gris

otra reservación en aviso
→ verde + borde azul punteado + gris
```

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

El gris sólo indica una acción pendiente. El estado base debe provenir de otros hechos vigentes después de retirar del cálculo a la reservación cuya tolerancia expiró.

Ejemplo con otro hecho que determina rojo:

```text
estado base = rojo
causa del rojo = ticket abierto u otra ocupación vigente
ausencia_pendiente = true

→ rojo + gris
```

Ejemplo sin otro conflicto:

```text
estado base = verde
ausencia_pendiente = true

→ verde + gris
```

Ejemplo con otra reservación próxima:

```text
estado base = azul
ausencia_pendiente = true

→ azul + gris
```

La reservación asociada a la ausencia pendiente no puede conservar artificialmente rojo, azul ni un bloqueo de asignación por sí misma. La política funcional se obtiene por hechos backend, no por el gris.

---

# 13. Matriz visual normativa del mapa administrativo

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

la representación base del **mapa administrativo**, mientras la reservación todavía influya en disponibilidad, es:

| Hora consultada | Visual base del mapa |
|---|---|
| Antes de 13:00 | Verde |
| 13:00 | Verde + borde azul punteado |
| 13:01–13:29 | Verde + borde azul punteado |
| 13:30 | Azul |
| 13:31–13:59:59 | Azul |
| 14:00 | Rojo |
| Después de 14:00 y antes de 15:30 | Rojo mientras la reservación siga influyendo |
| 15:30 en adelante | Recalcular estado base |

La tolerancia no vuelve azul al mapa después del inicio. Esa representación azul posterior al inicio pertenece al POS.

La ausencia pendiente se evalúa con el reloj real `ahora`:

```text
ahora > hora_reservacion + TOLERANCIA_LLEGADA_MINUTOS
AND estado = confirmada
AND no existe ticket propio abierto

→ ausencia_pendiente = true
```

En cuanto `ausencia_pendiente = true`, la reservación vencida deja de determinar el estado base, incluso si `hora_consulta` todavía cae dentro de sus 90 minutos planificados.

Ejemplo, si realmente son las 14:20 y la reservación de las 14:00 no llegó:

```text
ausencia_pendiente = true
sin otro ticket ni reservación conflictiva

base = verde
modificador = gris
→ verde + gris
```

Si existe otro hecho vigente:

```text
otro ticket abierto
→ rojo + gris

otra reservación próxima
→ azul + gris

otra reservación en aviso
→ verde + borde azul punteado + gris
```

Una proyección futura realizada **antes** de que venza realmente la tolerancia puede seguir mostrando rojo dentro del intervalo planificado, porque en ese momento todavía no existe `ausencia_pendiente`.

---

# 14. Prioridad visual del mapa administrativo

Primero se calcula el estado base con hechos vigentes.

Prioridad:

```text
1. Selección válida → amarillo

2. Ticket abierto → rojo

3. Reservación iniciada que todavía influye en disponibilidad:
   desde hora exacta
   hasta fin del intervalo planificado
   mientras reservacion_influye_en_disponibilidad = true
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

Antes de aplicar esta prioridad, una reservación con:

```text
ausencia_pendiente = true
```

debe retirarse como causa de ocupación planificada, capacidad y asignación. Después se recalcula el estado base con tickets y otras reservaciones.

Finalmente:

```text
si ausencia_pendiente = true
→ agregar indicador gris
```

El indicador gris nunca participa en la elección del estado base y nunca bloquea por sí mismo la selección o la asignación.

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

El POS resuelve `estado_visual_pos` mediante `PosMesaProjectionPresenter`.
`estado_visual_mapa` pertenece al presenter del mapa administrativo y no se
reutiliza para decidir el color POS.

La precedencia de presentación POS es:

```text
ticket_abierto u ocupada_fisicamente → rojo
reservación desde 0 hasta BLOQUEO antes → azul
reservación iniciada dentro de tolerancia → azul
reservación en ventana de aviso → verde + borde azul punteado
ausencia_pendiente → agregar gris al estado base recalculado
```

El rojo POS no representa una reservación confirmada sin ticket por el solo
hecho de haber llegado su hora.

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
azul
```

La mesa espera al cliente y no se considera ocupada físicamente mientras no
exista ticket abierto.

## 17.5 Contrato de respuestas mutables

Las respuestas de mutaciones deben interpretarse con la combinación de
`tipo`, `codigo` y `commit`. `ok` indica que la respuesta fue comprendida y no
es, por sí solo, una afirmación de que la mutación ya escribió datos.

### Decisión requerida

```json
{
  "ok": true,
  "commit": false,
  "tipo": "decision_requerida",
  "codigo": "..."
}
```

La solicitud fue comprendida, pero requiere una decisión explícita antes de
escribir. El cliente debe mostrar la presentación y no exigir datos que sólo
existen después del commit, como `ticket_id`.

### Éxito confirmado

```json
{
  "ok": true,
  "commit": true,
  "tipo": "exito",
  "codigo": "..."
}
```

La mutación fue confirmada. Cuando la operación crea un ticket, `ticket_id`
debe estar presente.

### Error

```json
{
  "ok": false,
  "commit": false,
  "tipo": "error",
  "codigo": "..."
}
```

Las siguientes combinaciones son inválidas:

```text
decision_requerida + commit=true
error              + commit=true
exito              + commit=false
```

En el POS, la precedencia de interpretación es:

```text
decision_requerida
error
éxito confirmado
respuesta inconsistente
```

Para apertura de ticket, el primer POST de una reservación en ventana de
advertencia devuelve `REQUIERE_CONFIRMACION` sin escribir; el POST confirmado
revalida la política y sólo entonces puede devolver un ticket creado con
`commit=true`.

---

## 17.6 Tolerancia vencida

Si existe ausencia pendiente:

```text
disponible_para_ticket = false
puede_marcar_no_show = true
```

hasta registrar ausencia.

La reservación vencida se retira como causa del estado base. El POS recalcula el estado con los demás hechos vigentes y después agrega gris. El gris no habilita walk-in: `disponible_para_ticket` permanece en `false` hasta registrar ausencia.

---

## 17.7 Proyección de datos por consumidor

El serializer canónico puede leer los datos completos para resolver reglas
operativas, pero la respuesta se proyecta según el consumidor:

```text
ADMIN
→ conserva contacto_tipo y contacto en las superficies administrativas autorizadas.

POS / waiter
→ conserva nombre, fecha, hora, comensales, nota, comentario_admin, mesas,
  estado, ticket, ventanas y acciones operativas.
→ no incluye contacto, contacto_tipo, email, telefono ni aliases equivalentes,
  incluso dentro de estructuras anidadas.
```

La proyección POS se aplica en backend al payload de salida. La interfaz no es
la frontera de seguridad y no puede reconstruir el contacto.

---

# 18. Mapa de reservaciones

## 18.1 Acceso a la superficie operativa

El mapa operativo de reservaciones es una superficie compartida por los roles
`admin` y `waiter`. El waiter puede consultar y ejecutar las acciones
operativas permitidas por la política vigente, incluyendo gestión de mesas,
liberación, reasignación, comentarios operativos y las transiciones operativas
expresamente autorizadas. Ambos roles pueden dar de alta desde el mismo
formulario del mapa y ese alta reutiliza el flujo interno administrativo de
horarios, capacidad, asignación y advertencias. En el waiter, el servidor
fuerza `contacto_tipo = 'ninguno'` y `contacto = NULL`, aunque se manipule la
petición; no se muestra ni se acepta contacto y no se inicia OTP ni
verificación. El admin conserva el formulario completo de contacto y sus
funciones administrativas de alta, edición general y consulta de contacto
fuera del mapa. La proyección de datos respeta las reglas de privacidad según
el rol autenticado.

El mapa conserva su propia simbología de proyección administrativa:

```text
Verde
→ disponible

Verde + borde azul punteado
→ reservación cercana

Azul
→ reservación próxima, desde el bloqueo hasta inmediatamente antes del inicio

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

En el mapa administrativo, el rojo comienza exactamente con la hora de la
reservación y continúa dentro del intervalo planificado **mientras esa reservación siga influyendo en disponibilidad**; la tolerancia no convierte ese estado base en azul. Si la tolerancia real vence sin ticket propio, `ausencia_pendiente=true` retira esa reservación como causa del estado base y se recalcula con los demás hechos. Estas reglas no obligan al POS a usar rojo: el POS mantiene azul en el inicio exacto y durante la tolerancia mientras no exista ticket abierto.

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

El backend debe poder transportar hechos funcionales y presentaciones separadas. Ejemplo de una mesa con reservación en ventana de advertencia:

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

Ejemplo con tolerancia **realmente vencida**, sin ticket ni otro conflicto:

```json
{
  "mesa_id": 4,

  "ocupada_fisicamente": false,
  "bloqueada_en_intervalo": false,

  "disponible_para_asignacion": true,
  "disponible_para_ticket": false,

  "requiere_advertencia_ticket": false,
  "ausencia_pendiente": true,

  "estado_visual_mapa": "libre",
  "estado_visual_pos": "libre",

  "modificadores_visual_mapa": ["ausencia_pendiente"],
  "modificadores_visual_pos": ["ausencia_pendiente"]
}
```

Resultado visual:

```text
mapa administrativo → verde + gris
POS                 → verde + gris
```

El hecho de que POS se vea verde + gris **no habilita walk-in**: `disponible_para_ticket=false` continúa siendo la autoridad operativa hasta registrar ausencia.

Si existe otro ticket o reservación vigente, `estado_visual_mapa` y `estado_visual_pos` se recalculan con ese otro hecho y el modificador gris se conserva.

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

## 24.1 Mapa administrativo

1. Verde significa disponible.
2. Verde + borde azul punteado significa reservación entre aviso y bloqueo.
3. Exactamente en aviso se usa verde + borde azul punteado.
4. Exactamente en bloqueo se usa azul.
5. Azul comienza `BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS` antes del inicio.
6. Azul termina inmediatamente antes de la hora exacta de la reservación.
7. Exactamente en el inicio, una reservación que todavía influye se muestra roja.
8. Después del inicio, la reservación continúa roja sólo mientras siga influyendo en disponibilidad dentro de su intervalo planificado.
9. Si `ausencia_pendiente=true`, esa reservación deja de determinar el estado base; se recalcula y después se agrega gris.
10. Ticket abierto corresponde rojo.
11. Amarillo significa únicamente selección válida.

## 24.2 POS

12. Verde significa mesa sin contexto de mayor prioridad.
13. Verde + borde azul punteado corresponde a la ventana de advertencia 60–30.
14. Exactamente en bloqueo se usa azul.
15. Desde 30 minutos antes hasta inmediatamente antes del inicio se usa azul.
16. En el inicio exacto, si no existe ticket abierto, POS permanece azul.
17. Durante toda la tolerancia, si no existe ticket abierto, POS permanece azul.
18. Exactamente al final de tolerancia todavía corresponde azul.
19. Después de la tolerancia sin llegada, se recalcula el estado base y se agrega gris.
20. Ticket abierto u ocupación física corresponde rojo.
21. Rojo POS no se usa por la sola existencia de una reservación confirmada sin ticket.

## 24.3 Modificadores y reglas compartidas

22. Gris nunca es color base.
23. Gris significa tolerancia vencida/ausencia pendiente actual.
24. Gris puede superponerse a verde.
25. Gris puede superponerse a azul.
26. Gris puede superponerse a rojo.
27. Gris puede coexistir con borde azul punteado.
28. El gris sólo aparece sobre mesas afectadas por una ausencia pendiente vigente.
29. Gris no modifica `disponible_para_asignacion` ni `disponible_para_ticket` por sí mismo.
30. Registrar no-show elimina la incidencia gris después de revalidar.
31. JavaScript no calcula ventanas temporales.
32. Los colores no determinan capacidad ni permisos.
33. `estado_visual_mapa` y `estado_visual_pos` son contratos distintos y no deben reutilizarse entre superficies.

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

Para este ejemplo, salvo donde se indique una proyección futura, `ahora` avanza junto con la hora mostrada.

## 12:59

```text
mapa = verde
POS  = verde
```

## 13:00

```text
mapa = verde + borde azul punteado
POS  = verde + borde azul punteado

walk-in POS = permitido con advertencia
```

## 13:29

```text
mapa = verde + borde azul punteado
POS  = verde + borde azul punteado

walk-in POS = permitido con advertencia
```

## 13:30

```text
mapa = azul
POS  = azul

walk-in POS = bloqueado
```

## 13:59

```text
mapa = azul
POS  = azul

walk-in POS = bloqueado
```

## 14:00

```text
mapa = rojo
POS  = azul

walk-in POS = bloqueado
reservación = operable
```

La diferencia es deliberada:

```text
mapa → ocupación planificada desde el inicio
POS  → espera al cliente dentro de tolerancia mientras no exista ticket
```

## 14:15

Exactamente al final de tolerancia todavía no existe ausencia pendiente:

```text
mapa = rojo
POS  = azul

ausencia_pendiente = false
```

## Después de 14:15 sin llegada ni ticket propio

En cuanto:

```text
ahora > 14:15
```

se cumple:

```text
ausencia_pendiente = true
reservacion_influye_en_disponibilidad = false
```

La reservación de las 14:00 deja de determinar el estado base inmediatamente; no espera hasta las 15:30 para liberar asignación.

Si no existe ningún otro hecho:

```text
mapa base = verde
POS base  = verde
modificador = gris

mapa = verde + gris
POS  = verde + gris

disponible_para_asignacion = true
disponible_para_ticket = false
puede_marcar_no_show = true
```

El POS continúa sin permitir walk-in hasta registrar ausencia aunque su estado visual base sea verde.

Si existe otro ticket abierto:

```text
mapa = rojo + gris
POS  = rojo + gris
```

Si existe otra reservación próxima en su ventana azul:

```text
mapa = azul + gris
POS  = azul + gris
```

Si existe otra reservación en ventana de advertencia:

```text
mapa = verde + borde azul punteado + gris
POS  = verde + borde azul punteado + gris
```

## 15:30

Finaliza el intervalo planificado original. Esto no cambia la semántica de una ausencia pendiente ya existente: la reservación original ya había dejado de bloquear capacidad y asignación desde que venció realmente la tolerancia.

Si la incidencia todavía no fue resuelta, el gris permanece sobre el estado base calculado con los demás hechos hasta registrar `no_show` o resolver la reservación.

## Nota sobre proyección futura

Si `ahora` todavía es anterior al vencimiento real de tolerancia y se consulta una `hora_consulta` futura dentro de `[14:00, 15:30)`, el mapa puede proyectar rojo por ocupación planificada porque todavía no existe `ausencia_pendiente`.

Por tanto:

```text
ahora
→ decide tolerancia real y ausencia_pendiente

hora_consulta
→ decide la proyección temporal solicitada
```

Nunca deben intercambiarse ambos relojes.

---

# 27. Documentación complementaria

La implementación y sus procedimientos de mantenimiento se documentan en:

```text
docs/reservaciones_mantenimiento.md
```

Este documento no sustituye la fuente funcional. Sólo:

```text
docs/reservaciones.md
```

define el comportamiento vigente.
