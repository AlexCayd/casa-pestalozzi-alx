# Revisión de consultas — Etapa 7

## Alcance

Se revisaron las consultas de disponibilidad, ocupación por mesa, tickets
abiertos, demanda sin mesas, retenciones, duplicados, contacto y límite por
contacto en los servicios canónicos y modelos de reservaciones. No se modificó
el DDL: no se detectó una contradicción crítica que lo justificara.

## Esquema e índices

La instalación limpia cargó `database/ddl.sql` y `database/dml.sql` en una base
temporal protegida. El DDL creó 26 tablas; las relaciones relevantes son:

| Relación | Restricción/índice | Resultado |
| --- | --- | --- |
| `tickets.reservacion_id` | `UNIQUE`, FK a `reservaciones` | evita dos tickets de una reservación |
| `ticket_mesas` | `uq_ticket_mesa`, `uq_ticket_orden`, índice por `mesa_id` | ticket multimesa atómico |
| `reservacion_mesas` | `uq_reservacion_mesa`, `uq_reservacion_orden` | asignación sin duplicados |
| `verificaciones_contacto` | FK y consultas con `FOR UPDATE` | OTP consumible de forma transaccional |
| `reservaciones.request_token` | `uq_reservaciones_request_token` | idempotencia y conflicto de token |
| `reservaciones.fecha, estado, hora` | `idx_reservaciones_fecha_estado_hora` | filtro temporal canónico |
| `reservacion_mesas.reservacion_id` | `idx_rm_reservacion` | antijoin de demanda sin mesas |

No se encontraron consultas activas a `request_fingerprint`, `created_by`,
`last_modified_by`, `last_modified_source`, `last_change_reason`, `arrived_at`,
`confirmed_at` o `completed_at`.

## EXPLAIN sobre base temporal

Se ejecutaron las consultas representativas sobre una base creada por
`run-instalacion-limpia-reservaciones.php`; la base fue eliminada al terminar.
El servidor MySQL devolvió el formato de plan textual de MySQL 8:

| Consulta | Plan observado | Evaluación |
| --- | --- | --- |
| Ocupación de reservaciones asignadas | `idx_reservaciones_fecha_estado_hora` + `uq_reservacion_mesa` | usa índice de fecha y lookup cubierto por reservación |
| Demanda no asignada | `idx_reservaciones_fecha_estado_hora` + `idx_rm_reservacion` en antijoin | evita recorrer asignaciones completas |
| Tickets abiertos por mesa | filtro sobre `tickets` y lookup `uq_ticket_mesa` en `ticket_mesas` | el DDL permite resolver la relación física sin duplicar tablas |

El plan de tickets mostró un table scan sobre el conjunto pequeño de `tickets`
sembrado en la base temporal y lookup indexado sobre `ticket_mesas`; no se
optimizó prematuramente una tabla de 44 filas de fixture.

## Decisiones

- Se conserva la consulta canónica de ocupación: `ticket_mesas` es la fuente
  física y `reservacion_mesas` sólo representa asignación de reservaciones.
- La proyección temporal permanece en `TicketTemporalService`; no se agregó un
  segundo cálculo SQL.
- `FOR UPDATE`, locks por fecha/horario y transacciones se conservan en las
  mutaciones críticas.
- No se agregaron migraciones incrementales ni cambios al DDL/DML vigentes.

## Reproducción

```text
php scripts/tests/run-instalacion-limpia-reservaciones.php
php scripts/tests/run-etapa7-concurrencia.php
```

Ambos runners rechazan la base activa y eliminan sus bases temporales por
defecto.
