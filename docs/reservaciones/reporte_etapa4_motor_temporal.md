# Reporte de Etapa 4

## Estado

- APROBADA para el motor temporal y la ausencia pendiente.

## Rama y commits

- Rama: modulo-reservaciones
- Commit inicial: aa3c47d0d617ea36ad1c46ed1e7fb12c3394ae9c
- Commit de cierre de Etapa 3: aa3c47d0d617ea36ad1c46ed1e7fb12c3394ae9c — Migra errores de reservaciones al catalogo canonico
- Commits de Etapa 4:
  - e760e0f — fix(reservaciones): unificar proyeccion temporal de tickets
  - 2c87675 — fix(reservaciones): liberar mesas tras tolerancia vencida
  - de4ed8e — fix(reservaciones): sincronizar ocupacion proyectada en mapas
- Commit final: el commit de cierre que contiene este reporte.
- Working tree inicial: cambios preexistentes en reservaciones_fuente_de_verdad.md y plan_estabilizacion_reservaciones.md.
- Working tree final: los dos cambios preexistentes permanecen fuera de los commits de Etapa 4.

## Entorno dinámico

- MariaDB: servicio local MySQL97, disponible.
- Base temporal: nombre único casa_pestalozzi_tmp_etapa4_*; creada y eliminada por el runner.
- Protección de base activa: el runner exige un nombre alfanumérico con _tmp o _test, rechaza DB_NAME activa y nunca ejecuta DDL sobre ella.
- HTTP: no ejecutado; no había servicio Apache local disponible en la sesión.
- Navegador: no ejecutado.
- Fixtures: mesas 7 y 8, reservaciones confirmadas 45 y 46, ticket abierto 100, relación multimesa en las pruebas en memoria; limpieza exclusiva de la base temporal.

## Motor temporal

- Fuente canónica: services/TicketTemporalService.php.
- Cálculos duplicados retirados: TicketMesa, OcupacionMesasService, PuntoVentaReservacionService y los consumidores JavaScript ya no vuelven a calcular la liberación.
- Fórmula de ticket: hora_apertura + DURACION_ESTIMADA_TICKET_MINUTOS + RETRASO_ESTIMADO_TICKET_MINUTOS; con la configuración actual, apertura + 90 minutos.
- Regla del bloque actual: todo ticket abierto con estado = abierto y closed_at IS NULL bloquea la fotografía actual, aunque su liberación estimada haya pasado.
- Regla de proyección futura: el ticket bloquea por traslape de [inicio, fin) y deja de bloquear desde el límite exacto de liberacion_estimada.

## Tolerancia

- Comparación inclusiva: ahora <= fecha_hora_reservacion + 15 minutos.
- Ausencia pendiente: confirmada, tolerancia vencida y sin ticket abierto.
- influye_disponibilidad: falso después de la tolerancia; verdadero antes o en el límite.
- puede_iniciar: falso cuando la tolerancia venció.
- puede_marcar_no_show: verdadero sólo para la ausencia pendiente.
- Transición automática: no existe; el registro permanece confirmada hasta la acción manual.
- Idempotencia: no_show repetido devuelve éxito informativo sin segunda transición.

## Sincronía

| Caso | POS | Mapa | Coinciden |
|---|---|---|---|
| Ticket abierto antes de liberación | OCUPADA, bloqueo rojo | OCUPADA, bloqueo rojo | Sí |
| Ticket proyectado después de liberación | Disponible proyectada | Disponible proyectada | Sí |
| Tolerancia vencida sin ticket | Disponible, registrar ausencia | Verde con AUSENCIA_PENDIENTE | Sí |
| Ticket abierto distinto sobre ausencia pendiente | Ocupada | Ocupada | Sí |

## Pruebas

| Comando | Resultado | Comprobaciones |
|---|---|---|
| php scripts/tests/run-etapa4-motor-temporal.php | PASS | Fixtures puros T1–T8 y R1–R6 |
| php scripts/tests/run-etapa4-motor-temporal.php --dynamic | PASS | DDL aislado, proyección real, no-show e idempotencia |
| php scripts/tests/run-reservaciones-catalogo.php | PASS | 191 códigos, contrato canónico |
| php scripts/auditar-errores-reservaciones.php | PASS | 0 errores, 0 warnings |
| npm.cmd test | PASS | PHP catalogo y 47 archivos JavaScript |
| node --check | PASS | Archivos JavaScript modificados |
| php -l | PASS | Archivos PHP modificados |

## Casos temporales

| Caso | Esperado | Resultado |
|---|---|---|
| T1: bloque actual después de liberación | Rojo y bloqueado | PASS |
| T2: consulta 10:00, liberación 10:30 | Bloqueado | PASS |
| T3: consulta 10:30 exacta | No bloqueado por ticket | PASS |
| T4: consulta 11:00 futura | Disponible proyectada | PASS |
| T6: fecha futura | Ignora ticket actual | PASS |
| T7: ticket cerrado | No aparece como abierto | PASS |
| T8: ticket multimesa | Misma liberación en todas las mesas | PASS |
| R2: 15:15:00 | Dentro de tolerancia y bloquea | PASS |
| R3: 15:15:01 | Ausencia pendiente y libera | PASS |
| R7–R8: no-show | Transaccional e idempotente | PASS dinámico |

## Archivos creados

- services/TicketTemporalService.php
- scripts/tests/run-etapa4-motor-temporal.php
- docs/reservaciones/reporte_etapa4_motor_temporal.md

## Archivos modificados

- models/TicketMesa.php
- services/OcupacionMesasService.php
- services/ReservacionVigenciaService.php
- services/PosReservacionSerializer.php
- services/PosReservacionQueryService.php
- services/PuntoVentaReservacionService.php
- services/MesaEstadoService.php
- src/js/operation/table-state-adapter.js
- src/js/modules/punto-de-venta.js
- src/js/admin/reservations/operation.js

## Confirmación de fuera de alcance

- R4 sin modificar.
- Modales sin cambios de contrato ni estilo; sólo se alimentan con hechos temporales canónicos.
- DDL de la aplicación sin modificar; el DDL se cargó únicamente en la base temporal del runner.
- Catálogo estable: 191 definiciones, auditoría sin errores ni warnings.

## Riesgos y deuda restante

- No se ejecutaron endpoints HTTP ni comprobación visual porque Apache no estaba disponible en la sesión.
- El runner dinámico omite triggers del DDL porque las pruebas de Etapa 4 no ejercitan sus reglas de reemplazo; la base temporal siempre se elimina.

## Resultado

- POS y mapa comparten el contexto temporal, la liberación estimada y los flags de bloqueo.
- Los tickets abiertos siguen ocupando físicamente, pero sólo bloquean proyecciones hasta su liberación estimada.
- Una reservación confirmada con tolerancia vencida queda libre, visible como ausencia pendiente y sin transición automática.
- La siguiente etapa puede abordar la evidencia HTTP/visual y la deuda de observabilidad sin reabrir el contrato temporal.
