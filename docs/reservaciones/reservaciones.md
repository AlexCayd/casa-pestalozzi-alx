# Reservaciones

Fuente de verdad vigente para las reglas operativas de reservaciones, asignación de mesas y capacidad. Este documento es normativo: describe el comportamiento que debe observar el sistema, no el historial de cambios ni una implementación particular.

## Contexto temporal

Toda consulta operativa tiene un contexto compuesto por `fecha` y `hora`. Un cambio de fecha u hora crea un contexto nuevo. Horarios, capacidad, mesas, reservaciones y acciones disponibles deben corresponder a la misma combinación.

Al entrar con una hora explícita que pertenece a los horarios válidos, se conserva esa hora. Al cambiar manualmente de fecha no se hereda automáticamente la hora del día anterior: si no existe una hora explícita válida para la nueva fecha, se utiliza el primer horario reservable válido.

El intervalo consultado es semiabierto:

```text
[hora_consulta, hora_consulta + DURACION_RESERVACION_MINUTOS)
```

El límite final no pertenece al intervalo. La disponibilidad se calcula para el intervalo completo, no sólo para el instante inicial.

## Horarios y estados

Los horarios válidos provienen de la configuración de reservaciones y de las reglas de la fecha consultada. Una fecha pasada es de sólo lectura. Una fecha actual sin bloques futuros no ofrece alta de nuevas reservaciones; cambiar a una fecha válida debe recalcular el contexto completo.

Los estados siguientes no son equivalentes:

| Hecho | Significado |
| --- | --- |
| `ocupada_fisicamente` | La mesa tiene una ocupación real, por ejemplo un ticket que sigue abierto. |
| `bloqueada_en_intervalo` | Algún conflicto impide usar la mesa durante el intervalo consultado. |
| `disponible_para_asignacion` | La mesa puede asignarse a la reservación para ese intervalo. |
| `disponible_para_ticket` | La mesa puede recibir un ticket en el contexto operativo correspondiente. |
| `ausencia_pendiente` | Existe una reservación cuyo tratamiento operativo requiere atención. |

El color, icono o estado visual del mapa es una representación de estos hechos. No decide por sí mismo la asignabilidad.

Los estados finales de una reservación no deben volver a influir en la disponibilidad por sí mismos. Una ocupación física que todavía exista mediante un ticket se evalúa de manera independiente a través de la fuente canónica de tickets.

## Tickets y proyección temporal

Un ticket realmente abierto mantiene `ocupada_fisicamente = true` mientras permanezca abierto. La estimación nunca libera la fotografía física actual.

Para una consulta futura del día actual, la liberación estimada del ticket es:

```text
liberacion_estimada =
    hora_apertura
    + DURACION_ESTIMADA_TICKET_MINUTOS
    + RETRASO_ESTIMADO_TICKET_MINUTOS
```

Cuando el intervalo consultado comienza en la liberación estimada o después, el ticket ya no bloquea por sí mismo la proyección. Antes de ese límite, sí participa en el bloqueo si se superpone al intervalo. Para una fecha futura distinta al día actual, los tickets abiertos actuales no bloquean la disponibilidad de esa fecha.

La asignación y la capacidad utilizan la proyección temporal canónica del backend. No deben sustituirla por el hecho crudo `ticket_abierto` ni por `ocupada_fisicamente`.

## Reservaciones, holds y asignación

Las reservaciones confirmadas y los holds vigentes bloquean la capacidad cuando se superponen al intervalo consultado. Los holds vencidos, las reservaciones en estados finales y las reservaciones fuera del intervalo consultado no bloquean por sí mismos.

La asignación automática y manual deben consumir `disponible_para_asignacion`, `bloqueada_en_intervalo` y sus causas para la fecha y hora solicitadas. La interfaz puede mostrar advertencias, pero la decisión final pertenece al backend.

Una mesa puede estar físicamente ocupada ahora y ser asignable en una proyección futura si el ticket ya fue liberado para ese intervalo y no existe otro conflicto.

### Asignación administrativa

La creación administrativa separa dos decisiones distintas:

1. **Capacidad operativa:** indica si la demanda cabe en la capacidad estimada del horario.
2. **Asignación de mesas:** determina si el sistema puede proponer mesas concretas para esa reservación.

Para reservaciones de hasta 12 personas, la administración puede solicitar asignación automática mediante el motor canónico de asignación. La propuesta debe validarse nuevamente en backend antes de persistirse.

Para reservaciones de más de 12 personas no se realiza asignación automática. La reservación puede confirmarse administrativamente y quedar pendiente de asignación manual, siempre dentro del máximo administrativo permitido.

La falta de una propuesta automática no equivale por sí sola a impedir la reservación administrativa. Si no existe asignación automática posible, si se desactiva o si la capacidad operativa estimada resulta insuficiente, la interfaz administrativa debe mostrar una advertencia explícita y requerir confirmación antes de guardar sin mesas. La asignación manual posterior utiliza las mismas reglas canónicas de ocupación y disponibilidad.

La landing pública conserva su límite y validaciones estrictas; las excepciones administrativas no amplían el contrato público.

## Capacidad

La capacidad mostrada debe pertenecer al mismo snapshot de `fecha + hora` que el mapa. El cálculo considera, según corresponda, capacidad física, mesas no reservables, reservaciones, holds, tickets bloqueantes y demanda sin asignar.

No es válido mostrar un mapa de un contexto junto con capacidad de otro. Cualquier refresh efectivo debe volver a obtener o recalcular la capacidad de la combinación consultada.

## Operación y creación

`Nueva reservación` sólo puede habilitarse cuando el contexto vigente tiene una fecha válida, un horario válido, datos cargados sin error, modo editable y permisos suficientes. Los estados de carga o error del contexto anterior no deben impedir la operación de la nueva fecha.

La fecha, hora y asignaciones enviadas al crear o editar una reservación deben validarse nuevamente en backend. Los datos enviados por el navegador no sustituyen las reglas de disponibilidad.

## Seguimiento temporal administrativo

El buzón administrativo sincroniza sus pendientes mediante `POST /admin/api/buzon/sincronizar`, protegido por CSRF. La sincronización consulta en lote las reservaciones del día actual, las reservaciones que ya tienen un aviso temporal abierto y los tickets abiertos; no se ejecuta dentro de alta, edición, asignación o transición de estado.

Los avisos temporales iniciales son:

- `reservacion_ausencia_pendiente`: reservación confirmada del día, sin ticket abierto, cuya tolerancia de llegada venció y puede pasar a no-show;
- `reservacion_sin_asignacion_proxima`: reservación confirmada de hasta 12 personas, sin ticket ni mesas, dentro de la advertencia, bloqueo o tolerancia canónicos y dentro del horario efectivo.

`reservacion_ausencia_pendiente` es de prioridad alta. `reservacion_sin_asignacion_proxima` es normal entre 60 y 30 minutos antes de la reservación y alta a 30 minutos o menos, incluida la tolerancia. No se crea fuera de esa ventana. La ausencia suprime la notificación de asignación próxima.

El buzón también puede presentar `reservacion_grupo_grande` para una reservación confirmada de más de 12 personas cuando existe una necesidad real de coordinación, como falta de contacto o falta de mesas. El número de comensales por sí solo no debe mantener indefinidamente un pendiente si el caso ya está coordinado.

Leer un aviso sólo registra `leida_at`; resolverlo requiere la acción de dominio correspondiente y un cierre por `tipo + entidad_tipo + entidad_id` con motivo técnico auditable.

Una reservación confirmada que quede fuera del horario efectivo se conserva visible en el contexto operativo con `fuera_horario_operacion = true`. El mapa administrativo la incluye en `reservaciones_admin`, pero la excluye de `en_proyeccion_mapa`; el hecho no modifica estado, capacidad, ocupación, tickets ni asignación. El POS presenta el mismo indicador textual.

El listado normal de `/admin/reservaciones` excluye `pendiente_verificacion` y `expirada`, porque son holds o intentos de verificación pública y no reservaciones operativas. Un filtro explícito de estado puede consultarlas sin eliminarlas. Los holds vigentes siguen participando en capacidad y disponibilidad según las reglas canónicas.

## Roles operativos

El mapa operativo es una superficie compartida por los roles `admin` y `waiter`. El administrador puede gestionar reservaciones y, cuando corresponde, consultar los datos de contacto necesarios para la operación. El personal de piso puede operar el mapa y las asignaciones de acuerdo con sus permisos; no debe recibir teléfono ni correo de los clientes cuando la tarea no lo requiere.

Las observaciones de una reservación son operativas: pueden incluir celebración, ubicación solicitada o necesidades de accesibilidad. No deben usarse como canal de marketing ni contener secretos o credenciales.

## Comunicaciones y gestión por acceso temporal

Las comunicaciones operativas de reservaciones usan dos eventos:
`reservation.schedule_change` y `reservation.reminder_next_day`. PHP conserva
la elegibilidad, deduplicación, token, vigencia, capacidad y acciones de
dominio; n8n sólo transporta el mensaje y devuelve `delivered` o `failed`.

La configuración del recordatorio vive en
`/admin/configuracion/reservaciones`. Es una fila única de base de datos,
desactivada y con hora `18:00` por omisión. El proceso programado consulta cada
cinco minutos, pero prepara todas las reservaciones elegibles de mañana desde
la hora configurada: una caída temporal no limita la recuperación a una
ventana de cinco minutos.

El acceso temporal canónico es `/reservaciones/gestionar`. La URL intercambia
el token plano por una sesión limitada a `source_type + source_id +
reservation_id`; la base sólo almacena SHA-256. Las rutas anteriores de
`/reservaciones/cambio-horario` son aliases, no una segunda implementación.

Desde este acceso se puede modificar mediante el reemplazo canónico o cancelar
mediante la cancelación canónica, siempre con CSRF y revalidación transaccional.
Un recordatorio para más de 12 personas no permite modificación pública, pero
mantiene la cancelación mientras la política temporal lo permita. El éxito
invalida la fuente exacta; sólo un `schedule_change` resuelve además la
afectación y cierra su seguimiento de buzón.

Los estados `pending`, `accepted`, `delivered` y `failed` describen únicamente
el transporte. `delivered` no confirma, cancela ni resuelve una reservación. Un
fallo invalida el acceso y, para afectaciones, vuelve accionable el buzón.

La referencia normativa completa está en [Arquitectura de comunicaciones de
reservaciones con n8n](arquitectura_notificaciones_reservaciones_n8n.md).

## Referencias vigentes

Los cambios de horario que dejan reservaciones fuera del horario efectivo se registran como impactos persistentes y requieren seguimiento administrativo. La referencia normativa completa está en [Afectaciones de reservaciones por cambios de horario](afectaciones_reservaciones_por_cambios_horario.md).

- [Usuarios](../usuarios/usuarios.md)
- [Credenciales de desarrollo](../usuarios/credenciales.md)
- [Privacidad](../privacidad/privacidad.md)
