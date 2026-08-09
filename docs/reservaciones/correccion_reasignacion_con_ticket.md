# Correccion de reasignacion ante ocupacion posterior por ticket

Fecha de validacion: 2026-08-08.

## Resultado

Se implemento la reasignacion administrativa de una reservacion confirmada
cuando una mesa que estaba en su asignacion persistida queda ocupada despues
por un ticket walk-in.

La fuente normativa es `docs/reservaciones.md`. La auditoria previa se uso
solo como evidencia del problema y no como contrato adicional.

## Contrato aplicado

- `currentAssignmentIds` representa exclusivamente el snapshot persistido de
  `reservacion_mesas`.
- `candidateSelectionIds` representa exclusivamente la propuesta que el
  operador esta construyendo.
- La mesa en conflicto permanece visible como asignacion actual, con el hecho
  fisico rojo y un indicador secundario de asignacion actual.
- La mesa en conflicto no se conserva como candidata; las nuevas candidatas
  deben ser reservables, disponibles y no tener ticket abierto.
- Un ticket ajeno no se cierra, mueve, vincula ni modifica.
- Un ticket propio sigue bloqueando la entrada al flujo de edicion de mesas.
- El guardado envia el snapshot actual, la propuesta nueva y la version; la
  transaccion rechaza la superposicion con `SUPERPOSICION_NO_AUTORIZADA` sin
  abrir una confirmacion de ticket.
- La validacion de version y la escritura atomica existentes se conservan.

## Casos cubiertos

| Caso | Comportamiento |
| --- | --- |
| Una mesa planificada ocupada por walk-in | Se muestra roja, conserva `asignada_actualmente` y exige sustitucion. |
| Reasignacion simple | Se selecciona una mesa valida y se envia solo como candidata nueva. |
| Reasignacion multimesa | Se conservan las mesas actuales y se valida la propuesta completa antes de guardar. |
| Ticket propio en curso | El flujo sigue bloqueado por la regla existente. |
| Ticket ajeno en una candidata | El backend rechaza la superposicion; no hay confirmacion ni mutacion del ticket. |

## Cambios principales

- El contrato de `docs/reservaciones.md` define el conflicto posterior,
  `assignment_snapshot`, `asignada_actualmente` y la precedencia visual.
- `MesaEstadoService`, el serializer y la proyeccion administrativa separan
  hechos de la asignacion actual de la seleccion editable.
- La operacion administrativa usa dos colecciones independientes y conserva
  el snapshot en el payload de guardado.
- `AsignacionMesasService` impide confirmar una nueva mesa con un ticket
  abierto cuando el flujo proviene del mapa administrativo.
- Se agrego `scripts/tests/run-reservaciones-reasignacion-ticket.php` y se
  incorporo a `npm run test:php`.

## Validacion

Ejecutado correctamente:

- `npm.cmd run test:php`
- `npm.cmd run test:js`
- `npm.cmd run build`
- `git diff --check`

La prueba nueva usa datos sinteticos y no escribe en la base de datos.

La comprobacion en navegador local llego a `http://127.0.0.1:8000/login` y
mostro la pantalla `Acceso del Personal`. No se pudo ejecutar el flujo
autenticado ni el escenario visual completo porque no habia una sesion valida
disponible; no se introdujeron credenciales ni se alteraron datos.

## Commits

1. `3fe225a docs(reservaciones): definir reasignacion ante conflictos posteriores`
2. `b2a0de0 refactor(reservaciones): separar snapshot y seleccion de mesas`
3. `6a31a91 fix(reservaciones): permitir resolver asignaciones con ticket ajeno`
4. `test(reservaciones): validar reasignacion ante ocupacion posterior` (este cierre)
