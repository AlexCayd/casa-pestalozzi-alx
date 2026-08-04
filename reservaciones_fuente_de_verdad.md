# Módulo de reservaciones — Fuente de verdad funcional y técnica

**Proyecto:** Casa Pestalozzi  
**Estado del documento:** Fuente de verdad funcional y técnica vigente  
**Última actualización funcional:** 2026-08-04 — interacción pública, modificación, proyección temporal de mapas y lenguaje operativo  
**Propósito:** Establecer el comportamiento esperado del módulo, sus reglas, estructura de datos, flujos, validaciones y plan de desarrollo.  
**Uso previsto:** Este documento debe utilizarse para diseñar, implementar, revisar y validar el módulo. Cualquier comportamiento que contradiga esta definición debe considerarse incorrecto, salvo que el documento sea actualizado de forma explícita.

---

## 1. Objetivo del módulo

El módulo de reservaciones debe permitir:

1. Que un cliente consulte disponibilidad y cree una reservación desde la landing.
2. Que un cliente autenticado mediante código pueda consultar, modificar o cancelar sus reservaciones activas.
3. Que el personal cree, consulte, modifique y cancele reservaciones desde el panel administrativo.
4. Que el personal gestione visualmente las reservaciones y la asignación de mesas desde el mismo mapa utilizado por el punto de venta.
5. Que las reservaciones se integren con los tickets sin duplicar ocupación.
6. Que toda la lógica de horarios, disponibilidad, asignación, estados y validaciones se implemente una sola vez y sea reutilizada por landing, administración y mapa.

El sistema debe ser sencillo para personas no técnicas. La interfaz debe priorizar acciones claras, advertencias comprensibles y controles consistentes, evitando exponer detalles internos innecesarios.

---

## 2. Alcance de la reconstrucción

La lógica actual del módulo de reservaciones no se conservará.

### Se conserva

- La interfaz visual existente de la landing como base de frontend.
- La interfaz y componentes visuales del mapa del punto de venta.
- Las tablas generales del proyecto que siguen siendo útiles, como:
  - `mesas`
  - `tickets`
  - `ticket_mesas`
  - `horarios_operacion`
  - `excepciones_operacion`
  - `usuarios`
- Los componentes reutilizables de alertas, formularios, mapa, navegación y diseño que sean compatibles con esta especificación.

### No se conserva

- Controladores actuales de reservaciones.
- Modelos o consultas actuales de disponibilidad.
- Endpoints actuales del módulo cuando reproduzcan lógica anterior.
- Asignación automática actual.
- Cálculos actuales de capacidad.
- Estados o transiciones incompatibles con este documento.
- Vistas administrativas u operativas actuales del módulo de reservaciones.
- Código duplicado entre landing, panel y mapa.
- Compatibilidad temporal con la implementación anterior.

La reconstrucción debe hacerse desde cero sobre servicios nuevos, sin crear adaptadores para mantener el comportamiento anterior.

---

## 3. Principios funcionales

### 3.1 Fuente única de reglas

Landing, panel administrativo y mapa deben utilizar los mismos servicios de dominio.

Ninguna pantalla debe calcular por su cuenta:

- Disponibilidad.
- Capacidad.
- Conflictos.
- Horarios válidos.
- Mesas candidatas.
- Estados permitidos.
- Límite por contacto.
- Vigencia de holds.
- Restricciones de modificación o cancelación.

### 3.2 Disponibilidad por combinación física

La disponibilidad no se determina únicamente sumando lugares libres.

Una solicitud es aceptable cuando existe una mesa individual o un grupo autorizado de mesas que:

- Está activo.
- Es reservable.
- Es de tipo `mesa`.
- Tiene capacidad suficiente.
- No presenta conflictos durante el intervalo solicitado.
- Respeta los grupos de mesas definidos en este documento.

### 3.3 Validación final en backend

Toda operación que crea, modifica o asigna mesas debe volver a validar disponibilidad dentro de una transacción antes de guardar.

La validación visual previa no garantiza disponibilidad.

### 3.4 Experiencia simple

El cliente no debe ver cálculos internos de capacidad.

El personal debe ver únicamente la información operativa necesaria para tomar decisiones:

- Mesas asignadas.
- Capacidad de las mesas seleccionadas.
- Número de comensales.
- Diferencia entre capacidad seleccionada y comensales.
- Conflictos o advertencias.

---

## 4. Puntos de entrada

El módulo tiene tres puntos de entrada.

### 4.1 Landing

Uso público por parte de clientes.

Funciones:

- Consultar disponibilidad.
- Crear reservaciones.
- Confirmar el contacto mediante código.
- Consultar reservaciones activas después de verificar el contacto.
- Modificar reservaciones.
- Cancelar reservaciones dentro del límite permitido.

### 4.2 Panel administrativo

Uso del personal.

Funciones:

- Listado por rango de fechas.
- Filtros.
- Buscador.
- Resumen por estado.
- Crear reservaciones.
- Consultar detalle.
- Editar datos permitidos.
- Cancelar reservaciones.
- Acceder al mapa para asignar o revisar mesas.

No existe eliminación física desde la interfaz.

### 4.3 Mapa de reservaciones integrado al punto de venta

No debe ser una interfaz completamente diferente.

Debe utilizar la misma estructura visual y los mismos componentes del mapa de punto de venta:

- Mismo encabezado.
- Mismo mapa.
- Mismo tamaño y posición de mesas.
- Mismos controles de navegación.
- Mismos comportamientos de zoom o maximización.
- Misma barra lateral base.
- Mismo lenguaje visual.
- Misma respuesta en pantallas pequeñas.
- Mismos patrones de botones, alertas y modales.

El acceso debe realizarse directamente desde el punto de venta mediante una acción clara como **Gestionar reservaciones**.

Al entrar en este modo:

- El contexto visual debe cambiar lo mínimo posible.
- El usuario debe reconocer inmediatamente el mapa.
- Se habilitan controles específicos de fecha, hora, reservaciones y asignación.
- Debe existir una acción clara para regresar al modo normal del punto de venta.
- No deben duplicarse dos mapas distintos en el código.

---

## 5. Constantes del módulo

Las siguientes reglas deben definirse en una clase o archivo central de configuración.

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
    public const LLEGADA_ANTICIPADA_MINUTOS = 30;

    /**
     * Tiempo previo durante el cual el mapa del punto de venta muestra
     * visualmente que una mesa tiene una reservación próxima.
     */
    public const AVISO_RESERVACION_PROXIMA_MINUTOS = 60;

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

Estas constantes no deben repetirse como números literales en controladores, JavaScript, vistas o consultas SQL.

---

## 6. Horarios reservables

### 6.1 Prioridad de reglas

Para resolver si una fecha es operable:

1. Consultar una excepción activa para la fecha.
2. Si la excepción es `cerrado`, no existen horarios.
3. Si la excepción es `horario_especial`, usar su apertura y cierre.
4. Si no existe excepción, usar el horario normal del día de la semana.
5. Si el día normal está cerrado, no existen horarios.

### 6.2 Anticipación mínima

Una reservación pública o administrativa no puede comenzar antes de:

```text
hora actual + 40 minutos
```

Se debe elegir el primer horario configurado igual o posterior a ese límite.

Ejemplo:

```text
Hora actual: 09:15
Límite: 09:55
Horarios configurados: 09:30, 10:00, 10:30
Primer horario válido: 10:00
```

### 6.3 Última reservación

La última reservación debe iniciar al menos 90 minutos antes del cierre.

```text
última hora reservable = hora de cierre - 90 minutos
```

### 6.4 Horizonte máximo

Solo se permiten fechas desde el día actual hasta 90 días después.

Las fechas pasadas y las posteriores al horizonte deben bloquearse.

---

## 7. Mesas y grupos autorizados

### 7.1 Mesas elegibles

Una mesa puede utilizarse para reservaciones cuando:

```text
mesas.activo = 1
mesas.reservable = 1
mesas.tipo = 'mesa'
```

Las barras no forman parte de la capacidad reservable.

### 7.2 Identificación de grupos

Los grupos se definen utilizando `mesas.numero`, no `mesas.id`.

#### Grupos de dos mesas

- Mesas 7 y 8.
- Mesas 6 y 9.
- Mesas 10 y 11.
- Mesas 3 y 4.

#### Grupos de tres mesas

- Mesas 2, 4 y 5.
- Mesas 11, 10 y 9.

### 7.3 Capacidad de un grupo

La capacidad se obtiene sumando la capacidad actual de cada mesa.

```text
capacidad del grupo = suma de mesas.capacidad
```

No se almacena una capacidad fija del grupo.

Si una mesa del grupo está inactiva, no es reservable, está ocupada o presenta conflicto, el grupo completo deja de estar disponible.

### 7.4 Orden de selección automática

El algoritmo debe evaluar:

1. Mesas individuales.
2. Grupos autorizados de dos mesas.
3. Grupos autorizados de tres mesas.

Debe elegir la opción que:

1. Tenga capacidad suficiente.
2. Utilice el menor número de mesas.
3. Desperdicie la menor cantidad de lugares.
4. No presente conflictos.
5. Sea un grupo autorizado.

---

## 8. Intervalos de ocupación

Una reservación bloquea mesas durante:

```text
[inicio, inicio + 90 minutos)
```

Existe conflicto cuando:

```text
ocupacion_inicio < nueva_fin
AND
ocupacion_fin > nueva_inicio
```

No debe compararse únicamente la igualdad de horas.

Ejemplo:

- Reservación A: 13:00 a 14:30.
- Reservación B: 14:00 a 15:30.

Ambas se traslapan y no pueden utilizar la misma mesa.

---

## 9. Fuentes de ocupación

### 9.1 Reservaciones

Bloquean mesas:

- `pendiente_verificacion` con hold vigente.
- `confirmada`.

No bloquean mesas:

- `en_curso`, porque su ocupación se obtiene del ticket.
- `completada`.
- `cancelada`.
- `no_show`.
- `expirada`.
- `reemplazada`.

### 9.2 Tickets abiertos

`ticket_mesas` es la fuente canónica de ocupación física.

Un ticket abierto ocupa las mesas realmente asociadas al ticket.

La liberación futura estimada se calcula como:

```text
tickets.hora_apertura
+ 90 minutos
+ RETRASO_ESTIMADO_TICKET_MINUTOS
```

Con la configuración actual:

```text
hora_apertura + 90 minutos
```

### 9.3 Día actual

Para el día actual se consideran:

- Holds vigentes.
- Reservaciones confirmadas.
- Tickets abiertos.
- Bloqueos futuros derivados de las reservaciones.
- Estado real de las mesas.

Un ticket abierto mantiene la mesa ocupada en el momento actual aunque haya superado su liberación estimada.

Para horarios futuros del mismo día puede utilizarse la liberación estimada para proyectar disponibilidad.

### 9.4 Días futuros

Para fechas posteriores al día actual se consideran:

- Holds vigentes de esa fecha.
- Reservaciones confirmadas.
- No se consideran tickets actuales.
- Las ocupaciones se calculan con intervalos de 90 minutos.

### 9.5 Prevención de doble conteo

Cuando un ticket está vinculado a una reservación:

```text
tickets.reservacion_id = reservaciones.id
```

la ocupación física se toma de `ticket_mesas`.

No se debe sumar al mismo tiempo la ocupación de `reservacion_mesas`.

---

## 10. Disponibilidad en la landing

Después de seleccionar:

- Fecha.
- Hora.
- Comensales.

El backend debe responder si existe una combinación física válida.

### Respuesta disponible

```text
Hay disponibilidad para la fecha, hora y número de personas seleccionados.
```

El usuario puede avanzar.

### Respuesta no disponible

```text
No hay disponibilidad para esta combinación. Cambia la fecha, la hora o el número de personas para continuar.
```

El usuario no puede avanzar.

La landing no debe mostrar:

- Lugares libres.
- Capacidad total.
- Número de mesas restantes.
- Nombres de grupos de mesas.
- Mesas candidatas.
- Cálculos internos.

Puede sugerir horarios alternativos disponibles sin mostrar capacidad.

---

## 11. Flujo de creación desde la landing

### Etapa 1 — Visita

Campos:

- Fecha.
- Hora.
- Comensales.

Validaciones:

- Fecha dentro del horizonte.
- Día abierto.
- Horario válido.
- Anticipación mínima.
- Última reservación antes del cierre.
- Comensales mayores a cero.
- Máximo público permitido.
- Disponibilidad física.

### Etapa 2 — Contacto

Campos:

- Nombre.
- Tipo de contacto: email o teléfono.
- Contacto.

El contacto debe normalizarse antes de comparar o guardar.

### Etapa 3 — Comentarios

Campo opcional:

- Nota del cliente.

### Etapa 4 — Confirmación

Al enviar:

1. Volver a validar disponibilidad dentro de una transacción.
2. Validar duplicado.
3. Validar máximo activo por contacto.
4. Crear reservación en `pendiente_verificacion`.
5. Asignar mesas en `reservacion_mesas`.
6. Definir `hold_expires_at = ahora + 15 minutos`.
7. Crear verificación OTP.
8. Mostrar interfaz de captura de código.

Al confirmar el código:

1. Verificar que el código siga vigente.
2. Verificar que el hold siga vigente.
3. Cambiar el estado a `confirmada`.
4. Actualizar `estado_changed_at`.

Si el hold vence:

- Deja de bloquear capacidad inmediatamente.
- El estado pasa a `expirada`.
- Las mesas quedan liberadas.
- El código deja de ser válido.

---

## 12. Prevención de reservaciones duplicadas

La landing no permite que el mismo contacto tenga dos reservaciones en:

```text
misma fecha + misma hora
```

Se consideran duplicados únicamente:

- `pendiente_verificacion` con hold vigente.
- `confirmada`.

No se considera `en_curso`.

Tampoco cuentan:

- `completada`.
- `cancelada`.
- `no_show`.
- `expirada`.
- `reemplazada`.

Una modificación pendiente vinculada a otra reservación no debe considerarse una nueva reservación duplicada.

---

## 13. Máximo por contacto

Cada contacto puede tener como máximo cinco reservaciones activas futuras.

La constante es:

```text
MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO = 5
```

Cuentan:

- Holds vigentes de reservaciones nuevas.
- Reservaciones confirmadas futuras.

No cuentan:

- Modificaciones pendientes.
- `en_curso`.
- `completada`.
- `cancelada`.
- `no_show`.
- `expirada`.
- `reemplazada`.

---

## 14. Consulta pública de reservaciones

El cliente no debe ver reservaciones únicamente por escribir un correo o teléfono.

Flujo:

1. Captura el contacto.
2. Recibe o consulta el código OTP.
3. Valida el código.
4. El sistema crea una sesión temporal para ese contacto.
5. Se muestran sus reservaciones confirmadas futuras.
6. Se habilitan modificar y cancelar cuando correspondan.

No debe revelarse si un contacto existe antes de verificarlo.

---

## 15. Modificación pública

La modificación se permite hasta 30 minutos antes del inicio de la reservación original.

La interfaz debe mostrar una nota clara:

```text
Puedes modificar tu reservación hasta 30 minutos antes del horario reservado.
```

### 15.1 Modelo de modificación

No se modifica directamente la fila original.

Se crea una nueva reservación temporal con:

```text
estado = pendiente_verificacion
hold_expires_at = ahora + 15 minutos
reemplaza_reservacion_id = reservación original
origen = landing
```

La reservación original permanece `confirmada` y conserva su asignación hasta que el cambio se confirme.

### 15.2 Autorización y secuencia visual

El contacto se verifica mediante OTP para acceder a sus reservaciones. Mientras la sesión pública siga vigente, no se solicita un segundo OTP específico para modificar.

La secuencia aprobada es:

```text
Modificar
→ editar fecha, hora, comensales o nota
→ Aceptar
→ crear o recuperar el reemplazo pendiente
→ Revisa tu cambio
→ Confirmar modificación
```

El botón **Aceptar**:

1. Valida los datos.
2. Comprueba el límite de modificación.
3. Crea o recupera idempotentemente el reemplazo pendiente.
4. Revalida disponibilidad.
5. Asigna provisionalmente las mesas.
6. Mantiene la disponibilidad durante 15 minutos.
7. Abre un modal con la comparación entre la reservación actual y la propuesta.

El botón **Aceptar** no cambia el estado de la reservación original.

El modal debe mostrar:

- Fecha actual y propuesta.
- Hora actual y propuesta.
- Comensales actuales y propuestos.
- Nota actual y propuesta.
- Los campos que realmente cambiaron.
- El mensaje: `Tu reservación actual seguirá vigente hasta que confirmes este cambio.`
- El mensaje: `Esta disponibilidad se conservará durante 15 minutos.`

Acciones del modal:

```text
Volver a editar
Confirmar modificación
```

### 15.3 Confirmación del cambio

`Confirmar modificación` utiliza la sesión pública vigente, CSRF y `request_token`. No solicita otro OTP.

Dentro de una transacción:

1. Validar la sesión y la pertenencia de la reservación.
2. Bloquear la reservación original.
3. Bloquear la nueva versión.
4. Validar que el hold siga vigente.
5. Revalidar disponibilidad y asignación.
6. Cambiar la nueva versión a `confirmada`.
7. Cambiar la original a `reemplazada`.
8. Actualizar `estado_changed_at` en ambas.
9. Liberar la asignación anterior para futuros cálculos.
10. Confirmar la transacción.

La operación debe ser idempotente. Si la sesión pública expiró, se solicita nuevamente la verificación del contacto y no se confirma el cambio mediante un bypass.

### 15.4 Expiración del cambio

Si no se confirma:

- La nueva versión pasa a `expirada`.
- La reservación original continúa `confirmada`.
- La asignación original se conserva.
- Las mesas retenidas por la nueva versión se liberan.
- El reemplazo vencido no puede confirmarse posteriormente.

### 15.5 Disminución de comensales

Al disminuir comensales:

- Se recalcula la mejor asignación.
- Se liberan mesas adicionales únicamente al confirmar el cambio.
- No se liberan antes de la confirmación.

### 15.6 Aumento de comensales sin cambiar fecha u hora

Orden de búsqueda:

1. Intentar ampliar la asignación actual dentro de un grupo autorizado.
2. Si no es posible, buscar otra combinación completa.
3. Si no existe una combinación válida, rechazar el cambio.

La reservación original se excluye del conflicto durante la evaluación porque sólo será sustituida si la propuesta se confirma.

### 15.7 Horario original y anticipación mínima

La anticipación mínima de 40 minutos se aplica cuando el cliente elige una fecha o una hora distinta.

Cuando la modificación conserva la fecha y hora originales, el horario original debe permanecer disponible mientras:

- La reservación continúe `confirmada`.
- Todavía falten al menos 30 minutos para su inicio.
- El horario siga siendo operativo para esa fecha.
- La nueva cantidad de comensales pueda resolverse con una asignación física válida.

El selector de modificación se construye con:

```text
horarios válidos para una nueva fecha u hora
+
horario original preservable
```

El sistema no debe desplazar silenciosamente el horario original al siguiente bloque.

La anticipación se calcula sobre la hora exacta actual antes de elegir el bloque configurado:

```text
límite = hora actual + 40 minutos
primer horario nuevo válido = primer bloque configurado >= límite
```

No se redondea la hora actual antes de sumar los 40 minutos.

Ejemplo:

```text
Hora actual: 15:15
Límite exacto: 15:55
Bloques: 15:30, 16:00, 16:30
Primer horario nuevo válido: 16:00
```

Ejemplo de preservación:

```text
Hora actual: 15:25
Reservación original: 16:00
Modificar la misma reservación conservando 16:00: permitido
Mover otra reservación a las 16:00: no permitido por anticipación mínima
```

Si faltan menos de 30 minutos para el inicio, la modificación queda bloqueada aunque se pretenda conservar el horario original.

### 15.8 Cambio de fecha u hora

Una fecha u hora distinta se evalúa como una reservación nueva y debe cumplir:

- Horario operativo.
- Anticipación mínima exacta de 40 minutos.
- Última reservación antes del cierre.
- Disponibilidad física.
- Grupos autorizados.
- Reglas públicas de 1 a 12 comensales.

La reservación original se excluye del conflicto únicamente porque será reemplazada si el cambio se confirma.

---

## 16. Cancelación pública

Se permite cancelar hasta 15 minutos después de la hora reservada.

```text
límite = fecha y hora de reservación + 15 minutos
```

Después de ese momento:

- La cancelación pública queda bloqueada.
- El personal puede resolver el caso.
- La reservación puede pasar a `no_show`.

Al cancelar:

- Estado: `cancelada`.
- Actualizar `estado_changed_at`.
- Dejar de considerar sus mesas en disponibilidad.

No existe eliminación física.

---

## 17. Reservaciones de grupos grandes

### Landing

Cuando el número de comensales llega a 14:

- No se consulta asignación automática.
- Se muestra un mensaje indicando que debe contactar directamente al restaurante.
- Se muestra el contacto definido por el negocio.

### Administración y mapa

Se permite crear reservaciones mayores de 13 personas.

Reglas:

- El contacto puede ser opcional.
- Se crea como `confirmada`.
- Puede quedar sin mesas asignadas.
- Debe mostrarse una advertencia persistente de asignación manual pendiente.
- La asignación debe resolverse desde el mapa.

Una reservación confirmada sin mesas no garantiza capacidad física. Debe considerarse una incidencia operativa visible.

---

## 18. Creación desde administración y mapa

Panel y mapa utilizan exactamente el mismo servicio.

### Reglas

- Estado inicial: `confirmada`.
- Origen: `admin`.
- Contacto opcional.
- Si no existe contacto:
  - `contacto_tipo = ninguno`
  - `contacto = NULL`
- No requiere OTP.
- Debe consultar disponibilidad.
- Debe asignar mesas automáticamente cuando exista una combinación válida.
- Para grupos mayores de 13 se permite crear sin mesas con advertencia.

### Edición de contacto

- Si la reservación fue creada con `contacto_tipo = ninguno`, puede agregarse contacto.
- Si ya existe contacto, no se modifica desde el flujo normal.

### 18.1 Creación administrativa y asignación — Etapa 8

La creación administrativa y la asignación manual se implementarán en la
**Etapa 8 — Administración de reservaciones**. Esta regla no aplica a la
landing ni a la gestión pública de la Etapa 7.

- La asignación automática administrativa es opcional.
- Para 1–12 personas puede utilizar el mismo algoritmo estricto de la landing.
- Para más de 12 personas la asignación automática queda deshabilitada.
- Si no existe un grupo predefinido disponible, administración puede crear la
  reservación sin mesas después de aceptar una advertencia.
- Una capacidad estimada insuficiente no bloquea definitivamente la creación
  administrativa.
- En ese caso se exige una advertencia reforzada y asignación manual posterior.
- Una reservación administrativa activa sin filas en `reservacion_mesas` se
  considera pendiente de asignación manual.
- La capacidad administrativa se calcula con todas las mesas físicas
  reservables disponibles, sin exigir grupos predefinidos.
- La landing y la modificación pública continúan siendo estrictas y sólo
  permiten crear un reemplazo cuando existe una combinación física automática
  válida.


### 18.2 Presentación del listado administrativo

El listado principal debe ser compacto.

Para mesas asignadas muestra únicamente:

```text
Sin mesas
1 mesa
2 mesas
3 mesas
```

No muestra en el listado:

- Números o nombres de mesas.
- Lista completa de mesas asignadas.
- Indicador de ticket abierto.
- ID del ticket.
- Mesas físicas del ticket.

La vista de detalle conserva la información completa de mesas planificadas, ticket relacionado, estado del ticket y diferencias entre asignación planificada y ocupación física cuando sean relevantes.

---

## 19. Estados de reservación

### 19.1 Estados definitivos

| Estado | Significado | Bloquea mesas |
|---|---|---:|
| `pendiente_verificacion` | Hold esperando OTP | Sí, mientras siga vigente |
| `confirmada` | Reservación aceptada y futura | Sí |
| `en_curso` | Existe ticket abierto asociado | No desde la reservación; sí mediante `ticket_mesas` |
| `completada` | Ticket cerrado | No |
| `cancelada` | Cancelación explícita | No |
| `no_show` | No llegó dentro de la tolerancia | No |
| `expirada` | Hold vencido | No |
| `reemplazada` | Versión sustituida por una modificación | No |

La etiqueta visible del estado debe ser **Reemplazada**, igual que el valor canónico. No se utiliza ninguna etiqueta alternativa como `Versión anterior`.

### 19.2 Transiciones permitidas

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

Los estados terminales son:

- `completada`
- `cancelada`
- `no_show`
- `expirada`
- `reemplazada`

### 19.3 Estado `en_curso`

No cuenta como reservación activa.

Se utiliza únicamente para representar que:

- El cliente ya está siendo atendido.
- Existe un ticket abierto asociado.
- La ocupación se controla desde `ticket_mesas`.

No puede modificarse ni cancelarse desde la landing.

---

## 20. Integración con punto de venta

### 20.1 Llegada anticipada

Desde 30 minutos antes del horario:

- Al seleccionar una mesa en el punto de venta, debe mostrarse la reservación `confirmada` próxima asociada.
- Debe permitirse iniciar el servicio cuando corresponda.
- No existe estado `llego`.

Al abrir el ticket desde la reservación:

```text
confirmada → en_curso
```

### 20.2 Apertura del ticket

En una transacción:

1. Validar que la reservación esté `confirmada`.
2. Validar que se encuentre dentro de la ventana permitida.
3. Revalidar las mesas y la ocupación física.
4. Crear el ticket con `reservacion_id`.
5. Crear `ticket_mesas`.
6. Cambiar la reservación a `en_curso`.
7. Actualizar `estado_changed_at`.

### 20.3 Apertura walk-in con reservación próxima de 60 a 30 minutos

Cuando se intenta abrir un ticket walk-in en una mesa con una reservación `confirmada` dentro de los siguientes 60 a 30 minutos, se permite continuar únicamente después de una advertencia explícita.

Se reutiliza el componente modal existente, pero con contenido específico.

Título:

```text
Hay una reservación próxima
```

El contenido debe informar:

- Mesa o mesas involucradas.
- Hora de la reservación.
- Nombre, cuando corresponda mostrarlo al personal.
- Número de comensales.
- Minutos restantes.
- Consecuencia de abrir el ticket.

Ejemplo:

```text
Esta mesa tiene una reservación a las 15:00 para 4 personas. Faltan 42 minutos.
Puedes abrir el ticket, pero la mesa deberá estar disponible antes de la reservación.
```

Si la duración estimada del servicio supera el tiempo restante, agregar:

```text
La duración estimada del servicio supera el tiempo disponible antes de la reservación.
```

Acciones:

```text
Volver a la selección
Abrir ticket de todas formas
```

No se muestran códigos, flags, ventanas internas ni explicaciones de backend. El servidor vuelve a validar la ocupación, la reservación próxima y la idempotencia antes de abrir.

### 20.4 Confirmación de ausencia

El modal de ausencia utiliza el mismo shell visual, pero una variante y contenido propios.

Título:

```text
Registrar que el cliente no se presentó
```

El contenido debe explicar:

- Hora de la reservación.
- Tolerancia de 15 minutos.
- Que la tolerancia ya terminó.
- Que la reservación cambiará a `no_show`.
- Que sus mesas dejarán de estar comprometidas por la reservación.

Ejemplo:

```text
La reservación era a las 15:00 y la tolerancia de 15 minutos ya terminó.
Al confirmar, se registrará que el cliente no se presentó y sus mesas dejarán de estar comprometidas.
```

Acciones:

```text
Volver
Registrar ausencia
```

Los modales de reservación próxima y ausencia comparten overlay, foco, estructura y responsive, pero nunca un cuerpo genérico vacío. Toda confirmación debe explicar la causa y la consecuencia de la acción.

### 20.5 Cierre del ticket

Al cerrar:

```text
en_curso → completada
```

Actualizar `estado_changed_at`.

---

## 21. Mapas operativos de punto de venta y reservaciones

### 21.1 Componente compartido y continuidad visual

El punto de venta y la gestión de reservaciones deben reutilizar el mismo componente de mapa, coordenadas, shell, toolbar, leyenda, paneles y patrones de interacción.

No debe desarrollarse un segundo motor de mapa ni una segunda interpretación de disponibilidad.

Ambos mapas consumen la misma proyección de ocupación. Sólo difieren en las acciones disponibles:

| Superficie | Información | Acciones principales |
|---|---|---|
| Punto de venta | Estado actual o proyectado, tickets y reservaciones confirmadas | Abrir ticket, iniciar servicio, registrar ausencia |
| Gestión de reservaciones | La misma ocupación actual o proyectada | Consultar, crear, asignar y reasignar mesas |

### 21.2 Estado inicial del día actual

Al entrar:

- Fecha: día actual.
- Hora: último bloque configurado menor o igual a la hora actual.
- Los bloques anteriores no aparecen en el selector.
- Si la hora actual es anterior a la apertura, se selecciona el primer bloque.
- Si la jornada terminó, se muestra el último bloque consultable o el estado de jornada terminada conforme al diseño.
- No se selecciona automáticamente una reservación.

Ejemplo:

```text
Horario de operación: 10:00–19:00
Hora actual: 11:05
Primer horario visible y seleccionado: 11:00
```

Ejemplos de redondeo:

```text
11:05 → 11:00
11:29 → 11:00
11:30 → 11:30
11:59 → 11:30
```

Un horario anterior enviado por URL, caché o estado persistido se normaliza al primer bloque visible del día actual. No se ofrece una vista histórica desde estos mapas.

### 21.3 Controles de fecha y hora

#### Fecha

- Desde el día actual.
- Fechas pasadas bloqueadas.
- Máximo 90 días.
- Respetar cierres y excepciones.

#### Hora del día actual

- Mostrar únicamente el bloque actual y los bloques posteriores.
- No aplicar la anticipación mínima de 40 minutos a la navegación del mapa.
- El primer bloque visible es el configurado inmediatamente anterior o igual a la hora actual.
- Cambiar de horario actualiza la misma proyección en ambos mapas.

#### Hora de una fecha futura

- Mostrar todos los bloques operativos válidos desde la apertura.
- Considerar excepciones antes que horario semanal.

#### Creación de reservaciones

Los horarios visibles del mapa no determinan por sí solos si puede crearse una reservación. Las nuevas altas continúan usando la regla independiente de `hora actual + 40 minutos`.

### 21.4 Fotografía actual y proyección futura

El bloque actual representa la fotografía operativa real.

Debe considerar:

- Tickets físicamente abiertos.
- Reservaciones `confirmada` que se traslapen con el bloque.
- Holds vigentes como bloqueo temporal.
- Mesas activas y reservables.
- Ocupación física real.

Un ticket abierto sigue ocupando su mesa en el bloque actual aunque haya superado su liberación estimada.

Los bloques posteriores representan una proyección estimada. Se calcula con la misma lógica de ocupación y disponibilidad utilizada para crear reservaciones:

```text
fecha y hora consultadas
+ tickets abiertos y liberación estimada
+ reservaciones confirmadas
+ holds vigentes
+ mesas reservables
+ conflictos por intervalo
= estado proyectado por mesa
```

Para tickets abiertos:

```text
liberación estimada =
hora_apertura
+ DURACION_ESTIMADA_TICKET_MINUTOS
+ RETRASO_ESTIMADO_TICKET_MINUTOS
```

Para reservaciones confirmadas:

```text
[inicio, inicio + 90 minutos)
```

Un bloque futuro puede mostrar una mesa como disponible después de su liberación estimada, siempre que no exista otra reservación, hold o conflicto.

La proyección es orientativa. Toda creación, asignación o apertura vuelve a validar dentro de una transacción.

### 21.5 Reservaciones visibles en los mapas

Los mapas muestran como reservaciones operativas únicamente:

```text
estado = confirmada
```

Esto aplica a:

- Tarjetas del panel lateral.
- Reservaciones próximas.
- Advertencias vinculadas a una reservación.
- Tooltips con identidad.
- Selección operativa.
- Asignación y reasignación.
- Resaltado azul por reservación.

No se muestran como reservaciones:

- `pendiente_verificacion`.
- `en_curso`.
- `completada`.
- `cancelada`.
- `no_show`.
- `expirada`.
- `reemplazada`.

Los holds vigentes continúan afectando disponibilidad y pueden marcar una mesa como temporalmente comprometida, pero no exponen una tarjeta ni datos de cliente.

`en_curso` se representa exclusivamente mediante el ticket abierto y `ticket_mesas`, evitando duplicidad visual y de ocupación.

Una reservación anterior al bloque seleccionado puede continuar apareciendo cuando siga `confirmada` y su intervalo se traslape con el bloque actual. Por ejemplo, una reservación de las 11:00 que continúa confirmada a las 11:35 sigue siendo operativamente visible en el bloque 11:30. No se crea una sección separada de incidencias o acciones vencidas; el retraso, la tolerancia y la acción de ausencia se presentan dentro del flujo normal.

### 21.6 Selección de reservación

Al seleccionar una reservación `confirmada`:

- Ajustar fecha y hora del mapa cuando corresponda.
- Resaltar sus mesas.
- Mostrar:
  - Nombre.
  - Contacto.
  - Fecha.
  - Hora.
  - Comensales.
  - Nota.
  - Comentario administrativo.
  - Estado.
  - Capacidad total de sus mesas.
  - Diferencia entre capacidad y comensales.

No se habilita selección operativa para estados distintos de `confirmada`.

### 21.7 Edición explícita de mesas

La asignación no cambia inmediatamente al tocar una mesa.

Flujo:

1. Seleccionar una reservación `confirmada`.
2. Activar **Editar asignación** o **Cambiar mesas**.
3. Capturar el snapshot y la versión actual.
4. Seleccionar o deseleccionar mesas provisionalmente.
5. Mostrar capacidad, diferencia, conflictos y advertencias.
6. Presionar **Guardar asignación**.
7. Revalidar disponibilidad, versión, estado y tickets.
8. Guardar dentro de una transacción.

Fuera del modo de edición:

- Los pines sólo informan.
- No cambian la selección persistente.
- No aparece `Guardar asignación`.
- No se envía ninguna mutación.

Cancelar restaura el snapshot exacto.

### 21.8 Sistema visual y colores

El punto de venta y el modo de reservaciones utilizan la misma paleta:

| Apariencia | Significado |
|---|---|
| Verde | Disponible |
| Rojo | Ticket abierto u ocupación física |
| Amarillo | Selección actual |
| Azul | Reservación próxima o mesa comprometida |
| Neutro | No utilizable |

Los colores provienen de variables CSS compartidas.

#### Prioridad

```text
1. Selección actual → amarillo
2. Ticket abierto → rojo
3. Reservación confirmada o hold aplicable → azul
4. No utilizable → neutro
5. Disponible → verde
```

Una mesa con ticket abierto y reservación próxima continúa roja; el riesgo se explica mediante texto o modal.

Los holds no crean una tarjeta identificable y pueden describirse como:

```text
Mesa temporalmente comprometida
```

No se agregan colores para estados históricos, conflictos, capacidad insuficiente o liberación estimada.

### 21.9 Leyenda e información complementaria

La leyenda muestra únicamente:

- Disponible.
- Ocupada.
- Selección actual.
- Reservación próxima, en punto de venta.
- Mesa comprometida, en gestión de reservaciones.
- No utilizable.

No muestra estados técnicos, letras, códigos ni explicaciones de implementación.

Cada mesa expone mediante tooltip, panel, lista estructurada y atributos accesibles:

- Número o nombre.
- Estado actual o proyectado.
- Ticket abierto y liberación estimada cuando aplique.
- Reservación próxima y hora.
- Mesa comprometida.
- No utilizable.
- Seleccionada.

### 21.10 Alertas

Las alertas aparecen superpuestas sin desplazar el contenido.

Casos:

- No hay disponibilidad.
- Reservación sin mesas.
- Capacidad insuficiente.
- Mesa con ticket abierto.
- Mesa con reservación próxima.
- Fecha cerrada.
- Horario no válido.
- Cambio no guardado.
- Conflicto al guardar.

---

## 22. `request_token`

`request_token` evita que la misma acción se procese dos veces.

Casos que cubre:

- Doble clic.
- Reenvío del formulario.
- Recarga.
- Reintento del navegador.
- Respuesta lenta.

Comportamiento:

1. El frontend genera un token por operación.
2. El mismo token se reutiliza si se repite exactamente el mismo envío.
3. El servidor busca una reservación con ese token.
4. Si existe, devuelve la reservación ya creada.
5. Si no existe, procesa la operación.
6. La base de datos mantiene el token como único.

No se utiliza para:

- Validar contacto.
- Autenticar al cliente.
- Confirmar OTP.
- Reemplazar CSRF.
- Vincular modificaciones.

No se utiliza `request_fingerprint`.

---

## 23. Estructura simplificada de base de datos

### 23.1 Tabla `reservaciones`

```sql
CREATE TABLE reservaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,

    nombre VARCHAR(100) NOT NULL,

    contacto_tipo ENUM(
        'email',
        'telefono',
        'ninguno'
    ) NOT NULL DEFAULT 'ninguno',

    contacto VARCHAR(150) NULL,

    fecha DATE NOT NULL,
    hora TIME NOT NULL,

    comensales INT UNSIGNED NOT NULL DEFAULT 2,

    nota TEXT NULL,
    comentario_admin TEXT NULL,

    origen ENUM(
        'landing',
        'admin'
    ) NOT NULL,

    estado ENUM(
        'pendiente_verificacion',
        'confirmada',
        'en_curso',
        'completada',
        'cancelada',
        'no_show',
        'expirada',
        'reemplazada'
    ) NOT NULL DEFAULT 'pendiente_verificacion',

    hold_expires_at DATETIME NULL,

    reemplaza_reservacion_id INT NULL,

    request_token VARCHAR(64) NULL,

    estado_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NULL
        DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reservacion_reemplazada
        FOREIGN KEY (reemplaza_reservacion_id)
        REFERENCES reservaciones(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_reservacion_comensales
        CHECK (comensales > 0),

    CONSTRAINT chk_reservacion_contacto
        CHECK (
            (
                contacto_tipo = 'ninguno'
                AND contacto IS NULL
            )
            OR
            (
                contacto_tipo IN ('email', 'telefono')
                AND contacto IS NOT NULL
                AND contacto <> ''
            )
        ),

    CONSTRAINT chk_reservacion_hold
        CHECK (
            estado <> 'pendiente_verificacion'
            OR hold_expires_at IS NOT NULL
        ),

    UNIQUE KEY uq_reservacion_request_token (
        request_token
    ),

    INDEX idx_reservacion_disponibilidad (
        fecha,
        estado,
        hora
    ),

    INDEX idx_reservacion_contacto_horario (
        contacto_tipo,
        contacto,
        fecha,
        hora,
        estado
    ),

    INDEX idx_reservacion_holds (
        estado,
        hold_expires_at
    ),

    INDEX idx_reservacion_reemplazo (
        reemplaza_reservacion_id
    )
);
```

### 23.2 Campos descartados

No se utilizan:

- `request_fingerprint`
- `created_by`
- `last_modified_by`
- `last_modified_source`
- `last_change_reason`
- `arrived_at`
- `confirmed_at`
- `completed_at`

### 23.3 `estado_changed_at`

Se conserva.

Debe actualizarse cada vez que cambie `estado`.

No debe actualizarse por cambios de nombre, nota, contacto, comensales o mesas.

### 23.4 `verificaciones_contacto`

Debe conservar:

- `reservacion_id`
- `contacto_tipo`
- `contacto`
- `codigo_hash`
- `expires_at`
- `attempts`
- `used_at`
- `invalidated_at`
- `created_at`

Usos:

- Confirmación inicial de una reservación creada desde la landing.
- Acceso público a reservaciones por contacto.
- Renovación de una sesión pública expirada antes de consultar, modificar o cancelar.

La modificación pública no genera un segundo OTP específico; se confirma mediante la sesión pública vigente, CSRF y `request_token`.

### 23.5 `reservacion_mesas`

Representa mesas prometidas o retenidas.

Debe permitir:

- Reservaciones confirmadas.
- Holds vigentes.
- Modificaciones pendientes.

### 23.6 `tickets`

Debe conservar:

```text
tickets.reservacion_id
```

Una reservación tiene como máximo un ticket.

### 23.7 `ticket_mesas`

Es la fuente de ocupación física de tickets abiertos.

---

## 24. Servicios de dominio

La implementación debe separar responsabilidades.

### 24.1 `ScheduleService`

Responsable de:

- Horario semanal.
- Excepciones.
- Días cerrados.
- Horarios válidos.
- Anticipación mínima.
- Última reservación.
- Horizonte máximo.

### 24.2 `AvailabilityService`

Responsable de:

- Intervalos.
- Holds vigentes.
- Reservaciones confirmadas.
- Tickets abiertos.
- Conflictos.
- Disponibilidad por fecha, hora y comensales.

### 24.3 `TableAssignmentService`

Responsable de:

- Mesas individuales.
- Grupos autorizados.
- Capacidad real.
- Selección óptima.
- Ampliación de asignación.
- Reducción de asignación.
- Validación manual.

### 24.4 `ReservationService`

Responsable de:

- Crear.
- Confirmar.
- Modificar.
- Cancelar.
- Expirar.
- Reemplazar.
- Marcar no-show.
- Cambiar a en curso.
- Completar.

### 24.5 `VerificationService`

Responsable de:

- Generar código.
- Guardar hash.
- Validar código.
- Controlar intentos.
- Invalidar códigos.
- Crear sesión pública.

### 24.6 `OccupancyService`

Responsable de unificar:

- Reservaciones.
- Holds.
- Tickets.
- Mesas activas.
- Mesas reservables.
- Ocupación actual.
- Proyección futura.

---

## 25. Transacciones obligatorias

Se requieren transacciones para:

- Crear reservación.
- Confirmar hold.
- Crear modificación.
- Confirmar modificación.
- Cancelar.
- Reasignar mesas.
- Abrir ticket desde reservación.
- Cerrar ticket.
- Marcar no-show cuando implique liberación.

En creación y reasignación:

1. Abrir transacción.
2. Bloquear recursos candidatos.
3. Revalidar disponibilidad.
4. Guardar reservación.
5. Guardar mesas.
6. Cambiar estado cuando corresponda.
7. Confirmar transacción.

---

## 26. Criterios de aceptación funcional

El módulo se considera correcto cuando:

1. Landing, admin y mapa obtienen el mismo resultado de disponibilidad.
2. No pueden asignarse las mismas mesas a dos intervalos traslapados.
3. Dos envíos simultáneos no crean duplicados.
4. Los holds vencidos dejan de bloquear inmediatamente.
5. Una modificación no elimina la reservación original antes de confirmarse.
6. Una reducción confirmada libera mesas adicionales.
7. Una reservación vinculada a ticket no se cuenta dos veces.
8. `en_curso` no cuenta como reservación activa.
9. Las barras nunca se utilizan para reservaciones.
10. Los grupos automáticos coinciden exactamente con los definidos.
11. El mapa de reservaciones reutiliza la interfaz del punto de venta.
12. El usuario puede cambiar entre punto de venta y gestión de reservaciones sin aprender una interfaz nueva.
13. Las excepciones de operación tienen prioridad.
14. La última reservación respeta 90 minutos antes del cierre.
15. No se permiten fechas posteriores a 90 días.
16. El contacto no puede tener más de cinco reservaciones activas futuras.
17. El mismo contacto no puede reservar dos veces el mismo día y hora.
18. `estado_changed_at` se actualiza solo cuando cambia el estado.
19. Las reservaciones mayores de 13 quedan claramente marcadas cuando no tienen mesas.
20. No existe eliminación física desde las interfaces.
21. No existen indicadores operativos con letras en el mapa.
22. Verde significa disponible en ambos modos.
23. Rojo significa ticket abierto u ocupación física.
24. Amarillo representa únicamente la selección actual y se aplica como relleno, no como borde.
25. Azul representa reservación próxima en el punto de venta o mesa comprometida en el horario consultado.
26. Mesas inactivas y elementos no reservables comparten la misma apariencia neutra en modo reservaciones.
27. No se agregan colores adicionales para holds, conflictos o estados históricos.
28. Los colores provienen de variables compartidas y no de valores escritos directamente en los componentes.
29. Para hoy, ambos mapas comienzan en el bloque actual y no muestran horarios anteriores.
30. El bloque actual muestra la ocupación real y los bloques posteriores una proyección común.
31. Ambos mapas muestran como reservaciones únicamente las que están `confirmada`.
32. Los holds afectan disponibilidad sin exponerse como reservaciones con identidad.
33. Las reservaciones confirmadas retrasadas se resuelven dentro del flujo normal, sin una sección duplicada de incidencias.
34. Los modales de reservación próxima y ausencia explican causa y consecuencia.
35. El listado administrativo muestra sólo la cantidad de mesas y reserva los datos de ticket para el detalle.
36. `reemplazada` se presenta visualmente como `Reemplazada`.
37. El horario original puede preservarse durante una modificación válida aunque no cumpla la anticipación de una hora nueva.
38. La anticipación de 40 minutos se calcula desde la hora exacta actual, sin redondeo previo.

---

# Plan de reconstrucción

## Fase 0 — Congelar la definición

### Objetivo

Usar este documento como criterio único antes de escribir código.

### Trabajo

- Revisar nombres definitivos de rutas.
- Verificar números y capacidades reales de mesas.
- Confirmar que todos los grupos configurados existen.
- Confirmar diseño de la landing que se conservará.
- Identificar el componente canónico del mapa del punto de venta.
- Identificar componentes reutilizables de alertas, modal, toolbar y sidebar.

### Resultado

- Documento aprobado.
- Inventario de archivos que se conservan.
- Inventario de archivos que se eliminan.

---

## Fase 1 — Estandarización del mapa del punto de venta

### Objetivo

Estabilizar el componente canónico del mapa antes de construir el modo de reservaciones.

### Trabajo

- Identificar el componente único que renderiza el mapa.
- Eliminar indicadores operativos con letras.
- Eliminar la leyenda de letras.
- Eliminar funciones, estilos y datos usados únicamente para esas letras.
- Centralizar los colores mediante variables CSS.
- Aplicar la paleta:
  - Verde: disponible.
  - Rojo: ocupada.
  - Amarillo: selección actual.
  - Azul: reservación próxima.
  - Neutro: no utilizable.
- Aplicar amarillo como relleno, no como borde.
- Hacer que las mesas recuperen su estado real al cancelar la selección.
- Mostrar la información antes representada por letras en tooltips o panel lateral.
- Validar modo claro y oscuro.
- Validar maximización y comportamiento responsive.
- Mantener operables barras, caja y para llevar en el POS.
- Preparar su apariencia neutra y deshabilitada cuando el mapa opere en modo reservaciones.

### Validaciones

- El mapa del POS continúa permitiendo abrir y gestionar tickets.
- No quedan letras operativas.
- No quedan colores escritos directamente en componentes.
- La leyenda tiene solo cinco estados.
- Una mesa ocupada con reservación próxima continúa roja y muestra la advertencia mediante texto.
- Una selección inválida no cambia a amarillo.
- La misma mesa conserva posición, tamaño y comportamiento al cambiar de modo.

### Resultado

Existe un único mapa estable y visualmente estandarizado que puede reutilizarse para reservaciones.

---

## Fase 2 — Retiro completo de la lógica anterior

### Objetivo

Eliminar el comportamiento anterior sin mantener compatibilidad.

### Eliminar o reemplazar

- Controladores de reservaciones.
- Servicios de disponibilidad.
- Consultas de capacidad.
- Endpoints públicos y administrativos.
- Asignación automática anterior.
- Vistas administrativas de reservaciones.
- Vista operativa de reservaciones.
- JavaScript de flujos anteriores.
- SCSS exclusivo del módulo anterior que no se reutilice.
- Pruebas ligadas al comportamiento anterior.

### Conservar temporalmente

- Frontend visual de la landing.
- Componente base del mapa del punto de venta.
- Componentes visuales reutilizables.
- Tablas generales necesarias.

### Regla

No crear capas de compatibilidad.

### Resultado

El proyecto queda sin lógica funcional de reservaciones, pero conserva las bases visuales seleccionadas.

---

## Fase 3 — Reconstrucción de base de datos

### Objetivo

Alinear el esquema con esta fuente de verdad.

### Trabajo

- Recrear `reservaciones`.
- Ajustar `contacto_tipo`.
- Agregar `origen`.
- Agregar `reemplaza_reservacion_id`.
- Agregar `estado_changed_at`.
- Eliminar campos descartados.
- Revisar `verificaciones_contacto`.
- Revisar `reservacion_mesas`.
- Confirmar `tickets.reservacion_id`.
- Confirmar `ticket_mesas`.
- Crear índices.
- Crear restricciones.
- Crear DML de prueba.

### Estrategia

El entorno está en desarrollo. Se permite:

- Eliminar tablas anteriores.
- Recrearlas.
- Reiniciar datos de reservaciones.
- No escribir migraciones de compatibilidad histórica.

### Resultado

Esquema limpio y alineado.

---

## Fase 4 — Núcleo de dominio

### Objetivo

Implementar las reglas sin depender de interfaces.

### Orden

1. `ReservacionConfig`.
2. `ScheduleService`.
3. `TableAssignmentService`.
4. `OccupancyService`.
5. `AvailabilityService`.
6. `ReservationService`.
7. `VerificationService`.

### Pruebas

- Horarios normales.
- Excepciones.
- Anticipación.
- Cierre.
- Intervalos.
- Grupos.
- Tickets.
- Holds.
- Duplicados.
- Límite por contacto.
- Modificaciones.
- Estados.

### Resultado

El núcleo puede ejecutarse desde pruebas o endpoints sin duplicar lógica.

---

## Fase 5 — API interna nueva

### Objetivo

Exponer endpoints consistentes.

### Endpoints mínimos

#### Públicos

- Consultar horarios.
- Consultar disponibilidad.
- Crear hold.
- Confirmar OTP.
- Solicitar acceso por contacto.
- Validar acceso.
- Listar reservaciones activas.
- Crear modificación.
- Confirmar modificación.
- Cancelar.

#### Administrativos

- Listar.
- Consultar detalle.
- Crear.
- Editar datos permitidos.
- Cancelar.
- Consultar mapa.
- Guardar asignación.
- Crear desde mapa.

#### Punto de venta

- Consultar reservaciones próximas por mesa.
- Abrir ticket desde reservación.
- Completar reservación al cerrar ticket.

### Resultado

Interfaces desacopladas del dominio.

---

## Fase 6 — Reconectar la landing

### Objetivo

Conservar la presentación visual y reemplazar completamente su lógica.

### Trabajo

- Mantener estructura por etapas.
- Sustituir llamadas actuales por API nueva.
- Mantener inputs montados.
- Implementar disponibilidad binaria.
- Implementar OTP.
- Implementar sesión de contacto.
- Implementar listado.
- Implementar modificación.
- Implementar cancelación.
- Eliminar textos y comportamientos heredados incompatibles.

### Resultado

Landing funcional sobre el nuevo núcleo.

---

## Fase 7 — Panel administrativo nuevo

### Objetivo

Crear un CRUD simple y consistente.

### Vistas

- Listado.
- Detalle.
- Crear.
- Editar.

### Funciones

- Rango de fechas.
- Filtros.
- Buscador.
- Conteos por estado.
- Contacto opcional.
- Cancelación.
- Acceso al mapa.
- Advertencia para grupos grandes sin mesas.
- Cantidad de mesas asignadas en el listado, sin mostrar sus números.
- Datos de mesas y ticket únicamente en el detalle.

### Resultado

Panel independiente de la landing, pero usando los mismos servicios.

---

## Fase 8 — Modo reservaciones dentro del mapa del punto de venta

### Objetivo

Incorporar la gestión sin crear una interfaz paralela.

### Trabajo

- Añadir acceso desde punto de venta.
- Reutilizar shell, mapa y sidebar.
- No crear un segundo componente de mapa.
- Añadir modo de reservaciones.
- Aplicar la misma paleta centralizada.
- Mostrar en neutro mesas inactivas, barras, caja, para llevar y elementos no reservables.
- Mostrar en azul mesas comprometidas para la fecha y hora consultadas.
- Mostrar en amarillo únicamente la selección provisional o la asignación de la reservación seleccionada.
- Añadir controles de fecha y hora.
- Añadir lista lateral.
- Añadir selección y detalle.
- Añadir modo explícito de edición.
- Añadir validación y guardado.
- Añadir modal de creación.
- Añadir alertas superpuestas.
- Mantener accesos y controles familiares.

### Resultado

El personal gestiona reservaciones dentro de una interfaz casi idéntica al punto de venta.

---

## Fase 9 — Integración con tickets

### Objetivo

Conectar reservación y atención real.

### Trabajo

- Mostrar reservación próxima al seleccionar mesa.
- Permitir apertura desde 30 minutos antes.
- Crear ticket vinculado.
- Copiar mesas a `ticket_mesas`.
- Cambiar a `en_curso`.
- Evitar doble conteo.
- Cambiar a `completada` al cerrar.

### Resultado

Continuidad entre reservación y venta.

---

## Fase 10 — Automatizaciones de estados

### Objetivo

Mantener estados coherentes.

### Procesos

- Expirar holds.
- Invalidar OTP vencidos.
- Marcar no-show después de tolerancia cuando aplique.
- Detectar reservaciones confirmadas sin mesas.
- Detectar inconsistencias básicas para desarrollo.

La disponibilidad no debe depender de que el proceso de expiración ya se haya ejecutado. Un hold vencido se ignora inmediatamente.

### Resultado

Estados limpios sin afectar la disponibilidad en tiempo real.

---

## Fase 11 — Pruebas integrales

### Casos obligatorios

- Una mesa individual.
- Cada grupo de dos.
- Cada grupo de tres.
- Grupo inactivo.
- Barra excluida.
- Traslape parcial.
- Hold vigente.
- Hold vencido.
- Creación simultánea.
- Doble clic.
- Cinco reservaciones activas.
- Sexta reservación.
- Duplicado mismo horario.
- Modificación con aumento.
- Modificación con reducción.
- Cambio de fecha.
- Cambio de hora.
- Expiración de modificación.
- Cancelación pública.
- No-show.
- Apertura anticipada.
- Ticket abierto.
- Cierre de ticket.
- Día futuro.
- Excepción cerrada.
- Horario especial.
- Grupo mayor de 13.
- Reasignación manual.
- Mapa y landing con el mismo resultado.

### Resultado

Reporte único de validación.

---

## Fase 12 — Limpieza final

### Trabajo

- Eliminar archivos huérfanos.
- Eliminar rutas antiguas.
- Eliminar estilos duplicados.
- Eliminar funciones no utilizadas.
- Documentar endpoints.
- Documentar constantes.
- Actualizar este documento si existió algún cambio aprobado.
- Verificar que no queden dos motores de disponibilidad.

### Resultado

Un único módulo, una única lógica y una única fuente de verdad.

---

## Orden recomendado de implementación

```text
1. Estandarizar el mapa del punto de venta
2. Eliminar indicadores operativos con letras
3. Centralizar la paleta de colores
4. Validar completamente el mapa POS
5. Eliminar la lógica anterior de reservaciones
6. Reconstruir la base de datos
7. Crear constantes
8. Implementar horarios
9. Implementar grupos y asignación
10. Implementar ocupación
11. Implementar disponibilidad
12. Implementar reservaciones y estados
13. Implementar OTP
14. Crear API nueva
15. Reconectar la landing
16. Crear panel administrativo
17. Incorporar modo reservaciones al mapa POS
18. Integrar reservaciones con tickets
19. Automatizar expiraciones y no-show
20. Ejecutar pruebas integrales
21. Eliminar código residual
```

---

## Regla de control de cambios

Cuando una decisión funcional cambie:

1. Actualizar primero este documento.
2. Identificar servicios afectados.
3. Actualizar pruebas.
4. Implementar el cambio.
5. Validar landing, administración, mapa y punto de venta.
6. Evitar correcciones aisladas en una sola interfaz.

Este documento debe permanecer como la referencia principal para evaluar si el enfoque técnico y el comportamiento implementado son correctos.
## Anexo aprobado — Etapa 11.7: interacción, lenguaje operativo y modos del mapa

Este anexo documenta decisiones de presentación e interacción aprobadas para la Etapa 11.7 sin cambiar el esquema, los estados canónicos, las transiciones, la ocupación física, la disponibilidad, la asignación pública ni `pos-reservacion.v1`.

### 27.1 Estado `reemplazada`

`reemplazada` permanece como estado terminal canónico y como valor interno de base de datos, servicios, filtros, payloads, logs y pruebas.

Su etiqueta visible es:

```text
Reemplazada
```

No se utiliza ninguna etiqueta alternativa como `Versión anterior`.

Las reservaciones `reemplazada` no bloquean mesas, no cuentan como activas y se excluyen de POS, mapa de reservaciones, panel lateral operativo, reservaciones próximas, warnings, tooltips con identidad, selección y acciones. La nueva versión `confirmada` sí permanece visible y operativa.

### 27.2 Modificación pública bajo sesión verificada

El contacto se verifica mediante OTP para acceder a sus reservaciones. Mientras la sesión pública verificada siga vigente, la modificación se autoriza con esa sesión, CSRF, token de operación, reemplazo pendiente, hold vigente, disponibilidad, locks e idempotencia dentro de una transacción. No se solicita un segundo OTP específico para confirmar la modificación.

La original permanece `confirmada` hasta la confirmación final; el reemplazo conserva hold y mesas provisionales; el modal comparativo confirma el cambio; después la original pasa a `reemplazada` y la nueva versión a `confirmada`. Si la sesión expira, no existe bypass: se solicita nueva verificación de contacto.

### 27.3 Horario y proyección compartida de los mapas

Para el día actual, ambos mapas comienzan en el último bloque configurado menor o igual a la hora actual. Los bloques anteriores no se muestran ni pueden recuperarse mediante URL, caché o estado persistido.

El bloque actual presenta la ocupación real. Los bloques posteriores presentan una proyección común calculada con tickets abiertos, liberación estimada, reservaciones `confirmada`, holds vigentes, mesas reservables y conflictos de intervalo.

La anticipación mínima de 40 minutos se usa para crear o mover una reservación a un nuevo horario, no para navegar el mapa.

Los mapas muestran como reservaciones únicamente las que están `confirmada`. Un hold puede comprometer una mesa sin mostrarse como tarjeta. Una reservación retrasada que siga `confirmada` continúa apareciendo dentro de la operación normal cuando su intervalo afecte el bloque seleccionado; no se crea una sección independiente de incidencias vencidas.

### 27.4 Modo explícito de asignación

El mapa opera en los estados de frontend `viewing`, `assignment_edit`, `saving` y `conflict`.

La secuencia de edición es:

```text
Seleccionar reservación
→ Editar asignación
→ modificar selección provisional
→ Guardar asignación
```

En `viewing`, tocar mesas no modifica la asignación, no muestra guardar y no envía mutaciones. Al entrar en edición se capturan mesas y versión esperadas; se muestran capacidad, diferencia, warnings, `Guardar asignación` y `Cancelar cambios`. Cancelar restaura el snapshot y vuelve a `viewing`. Guardar revalida versión, estado, tickets, conflictos y payload en el backend transaccional.

### 27.5 Modales operativos con causa y consecuencia

Entre 60 y 30 minutos antes de una reservación `confirmada`, el intento de abrir un ticket walk-in reutiliza el modal existente con:

```text
Volver a la selección
Abrir ticket de todas formas
```

El modal informa mesa, hora, nombre cuando aplique, comensales, minutos restantes y el riesgo de que la mesa no se libere a tiempo.

El registro de ausencia utiliza el mismo shell, pero explica la hora reservada, la tolerancia vencida, el cambio a `no_show` y la liberación de la ocupación planificada.

Ambas variantes deben tener título, resumen y consecuencia propios. No se acepta un cuerpo genérico con sólo botones ni lenguaje técnico de backend.

### 27.6 Interacción de landing y selectores

El rail debe cerrar, retirar `inert`, liberar scroll, actualizar `aria-expanded`, compensar el header fijo y navegar de forma nativa si JavaScript no carga. Debe operar con Tab, Shift+Tab, Enter, Space y Escape sin overlay residual ni listeners duplicados.

Los selectores de fecha y hora comparten un coordinador: como máximo uno puede estar abierto. Abrir uno cierra el otro, Escape cierra sólo el activo y restaura foco, seleccionar fecha cierra fecha e invalida una hora incompatible, y el clic fuera cierra el selector activo.

### 27.7 Lenguaje y leyenda

La leyenda sólo muestra `Disponible`, `Ocupada`, `Selección actual`, `Reservación próxima` o `Mesa comprometida` según el modo, y `No utilizable`. No muestra explicaciones de implementación, accesibilidad, backend, clases, estados de base de datos ni códigos internos. El texto de cada mesa, tooltip, panel y lista estructurada mantiene la información accesible sin depender sólo del color.

### 27.8 Corrección 11.7.1 — rail y modificación pública

Esta corrección mantiene el modelo canónico de la sección 27.2 y no agrega estados de base de datos ni un segundo OTP.

#### Secuencia visual aprobada de modificación

```text
Modificar
  ↓
editar fecha / hora / personas / nota
  ↓
Aceptar
  ↓  (se crea o recupera un reemplazo pendiente y se reserva durante 15 minutos)
Revisa tu cambio
  ↓
Volver a editar                  Confirmar modificación
                                      ↓
                             Tu reservación fue modificada.
```

El editor muestra exactamente `Aceptar` y `Cancelar`. La revisión muestra lado a lado la reservación actual y la nueva propuesta con fecha, hora, personas y nota; sólo los renglones modificados se resaltan. Incluye `Tu reservación actual seguirá vigente hasta que confirmes este cambio.` y `Esta disponibilidad se conservará durante 15 minutos.` No muestra timestamps técnicos.

`Volver a editar` cierra la revisión, devuelve el foco a `Aceptar`, conserva los valores editados y no hace POST ni crea otro reemplazo. `Confirmar modificación` envía sólo `request_token` y CSRF; la sesión verificada es la identidad. La secuencia visual y sus estados de frontend son `editing`, `creating_replacement`, `reviewing`, `confirming`, `success` y `error`.

La respuesta de creación o recuperación entrega `request_token`, la retención y los valores públicos de `original` y `propuesta`. La original permanece `confirmada` hasta la confirmación final; la propuesta es `pendiente_verificacion` con hold. La confirmación final es transaccional e idempotente: original `reemplazada` y propuesta `confirmada`. Los errores de disponibilidad, sesión, hold, límite de tiempo, conflicto de token, cambio concurrente e inesperado se traducen a lenguaje operativo.

El rail conserva anchors nativos `href="#..."`, queda operable con ratón, Tab, Shift+Tab, Enter y Space, y mantiene la navegación nativa si JavaScript no carga. Al abrir o cerrar cualquier overlay se retiran `inert` y `aria-hidden` donde corresponda, se libera scroll, se compensa el header fijo, se restaura el foco y no se registran listeners duplicados.
### 27.9 Proyección operativa exclusiva de estados confirmados

Las proyecciones operativas de ambos mapas incluyen únicamente reservaciones `confirmada`.

- `en_curso` se representa mediante el ticket abierto.
- `pendiente_verificacion` puede bloquear temporalmente como hold sin exponer identidad.
- Estados terminales no se muestran.
- `reemplazada` conserva su etiqueta canónica `Reemplazada` únicamente en administración e historial.

Esta regla separa ocupación esperada, ocupación física y trazabilidad.

### 27.10 Listado administrativo compacto

El listado administrativo muestra solamente la cantidad de mesas asignadas y no sus números. Tampoco muestra si existe ticket abierto.

Los números de mesa, el ticket y su estado quedan disponibles en la vista detallada.

### 27.11 Preservación del horario original al modificar

El horario original se conserva como opción válida cuando la fecha y hora no cambian y todavía faltan al menos 30 minutos.

La anticipación mínima de 40 minutos se aplica sólo a una fecha u hora diferente. El umbral se calcula sumando 40 minutos a la hora exacta actual y después seleccionando el primer bloque configurado igual o posterior.

No se redondea la hora actual antes de sumar la anticipación y no se reemplaza silenciosamente el horario original por el siguiente bloque.

### 27.12 Fuente común de capacidad y estado visual

El mapa y los formularios de creación consumen los mismos servicios de ocupación y disponibilidad.

La interfaz del mapa transforma ese resultado en estado visual por mesa y, para uso interno, puede presentar capacidad proyectada. La landing continúa mostrando únicamente disponibilidad binaria.

No se permite que JavaScript vuelva a calcular independientemente liberación, conflictos, elegibilidad o capacidad.

### 27.13 Criterio de sincronía entre mapas

Para la misma fecha y hora, punto de venta y gestión de reservaciones deben recibir la misma ocupación actual o proyectada.

Las diferencias entre ambos modos se limitan a:

- Acciones disponibles.
- Información administrativa adicional.
- Elegibilidad de elementos no reservables.

No puede existir una mesa disponible en un mapa y comprometida en el otro por usar motores temporales diferentes.
