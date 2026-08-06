# Reporte de Etapa 5

## Estado

- APROBADA

## Rama y commits

- Rama: `modulo-reservaciones`
- Commit inicial: `4b99380` — cierre de Etapa 4
- Commit de cierre Etapa 4: `4b99380`
- Commits de Etapa 5:
  - `70cdca2` — `fix(reservaciones): centralizar capacidad fisica disponible`
  - `c62a160` — `fix(reservaciones): separar capacidad y asignacion administrativa`
  - `376e69a` — `fix(reservaciones): sincronizar capacidad entre superficies`
  - `8430143` — `test(reservaciones): validar capacidad y libertad administrativa`
- Commit final de código: `8430143`; este reporte se cierra en un commit documental posterior sin cambios funcionales.
- Working tree inicial: sólo `reservaciones_fuente_de_verdad.md` modificado y `plan_estabilizacion_reservaciones.md` sin seguimiento.
- Working tree final: conserva únicamente esos dos cambios preexistentes; los cambios de Etapa 5 quedan en los commits anteriores y el commit de cierre.

## Capacidad canónica

- Servicio: `services/CapacidadReservacionesService.php`
- Capacidad total: suma sólo `mesas.activo = 1`, `reservable = 1`, `tipo = 'mesa'`, con capacidad positiva.
- Capacidad comprometida: suma una vez la capacidad completa de cada mesa no disponible en la evaluación temporal.
- Capacidad física libre: total físico menos mesas bloqueadas; una liberación proyectada sólo libera la consulta futura correspondiente.
- Demanda no asignada: suma `comensales` de confirmadas activas sin filas en `reservacion_mesas`, con traslape semiabierto y sin ticket abierto.
- Capacidad real disponible: `MAX(0, capacidad_fisica_libre - demanda_no_asignada)`.
- Doble conteo: evitado con `NOT EXISTS`; `en_curso` y confirmadas con ticket abierto se representan por `ticket_mesas`, no por comensales.

## Políticas

| Superficie | Capacidad | Asignación | Puede confirmar sin mesas | Sobrecapacidad |
|---|---|---|---|---|
| Landing | Exige capacidad real | Exige combinación automática | No | No |
| Administración | Informa capacidad real | Opcional | Sí, con `SIN_ASIGNACION` | Sí, sólo con `confirmar_sobrecapacidad = 1` |
| Mapa | Consume el resumen canónico | Manual | Sí, según flujo administrativo | Requiere decisión administrativa |
| POS | Consume ocupación física | No cambia la política de walk-in | No aplica | No introduce restricción nueva |

## Casos

| Caso | Esperado | Resultado |
|---|---|---|
| C1 | 44 total, 44 libres, demanda 0 | PASS puro/dinámico |
| C2 | Mesa de 6 en mesas de 8 descuenta 8 | PASS puro |
| C3 | Dos grupos descuentan 12 | PASS puro |
| C4 | Confirmada sin mesas descuenta 5 | PASS puro/dinámico |
| C5 | 44 - 8 - 5 = 31 | PASS puro/dinámico |
| C6–C8 | Fuera de intervalo, cancelada y ausencia pendiente no descuentan | PASS puro/dinámico |
| C9–C10 | `en_curso`/ticket se cuentan sólo por mesas físicas | PASS puro/dinámico |
| C11–C12 | Liberación futura y bloqueo actual conservan Etapa 4 | PASS puro/dinámico |
| C13 | Capacidad suficiente y asignación automática distinta | PASS puro |
| C14 | Sobrecapacidad requiere decisión explícita y puede confirmar sin mesas | PASS dinámico |
| C15 | Repetición idempotente; token con otros datos entra en conflicto | PASS dinámico |
| C16 | POS/mapa comparten los valores base | PASS dinámico |

## Entorno dinámico

- MariaDB: `MySQL97`, disponible.
- Base temporal: nombre con prefijo protegido `casa_pestalozzi_tmp_etapa5_*`; creada y eliminada por el runner.
- HTTP: servidor integrado PHP en `127.0.0.1:8088` con `public/index.php` como router; landing respondió 200. Apache no estaba activo.
- Navegador: no ejecutado; la evidencia HTTP se realizó con cliente local y el endpoint administrativo sin sesión respondió 401 como exige la protección.
- Fixtures: mesas de 4 asientos, asignaciones, confirmadas sin mesas, cancelada, ausencia pendiente, tickets walk-in/reservación/en curso, horario semanal e idempotencia administrativa.
- Protección de base activa: no se selecciona `DB_NAME` activa; sólo se usa la base temporal generada y validada.

## Observabilidad

- Evento: `reservaciones.capacidad_evaluada`.
- Campos: fecha, hora, intervalo, total, comprometida, libre física, demanda sin asignar, disponible real, comensales solicitados, asignación automática, liberación proyectada, resultado y origen.
- Orígenes: `landing`, `admin`, `mapa`, `pos`.
- Datos sensibles excluidos: nombre, contacto, OTP, CSRF, cookies, SQL y stack trace.

## Pruebas

| Comando | Resultado | Comprobaciones |
|---|---|---|
| `php scripts/tests/run-reservaciones-catalogo.php` | PASS | 191 códigos catalogados |
| `php scripts/auditar-errores-reservaciones.php` | PASS | errors=0, warnings=0 |
| `php scripts/tests/run-etapa4-motor-temporal.php` | PASS | tickets, proyección y tolerancia |
| `php scripts/tests/run-etapa4-motor-temporal.php --dynamic` | PASS previo | fixtures temporales y no-show |
| `php scripts/tests/run-etapa5-capacidad.php` | PASS | C1–C13 puros |
| `php scripts/tests/run-etapa5-capacidad.php --dynamic` | PASS | SQL, C14–C16, idempotencia |
| `npm.cmd test` | PASS | catálogo y 47 archivos JavaScript |
| `npm.cmd run audit:reservaciones` | PASS | auditoría canónica |
| `composer validate --no-check-publish` | PASS | válido; advertencia previa de licencia no especificada |
| `npx.cmd gulp --tasks-simple` | PASS | tareas Gulp disponibles |
| `php -l` | PASS | 1200 archivos PHP |
| `node --check` | PASS | fuentes JS modificadas |
| `git diff --check` | PASS en archivos Etapa 5 | el chequeo global sólo reporta hard-breaks preexistentes en `reservaciones_fuente_de_verdad.md` |

## Evidencia HTTP

| Endpoint | Caso | Resultado |
|---|---|---|
| `/index.php/api/reservaciones/disponibilidad` | Landing estricta | PASS HTTP 200; respuesta binaria sin detalle interno |
| `/index.php/admin/api/reservations/disponibilidad` | Capacidad y asignación separadas | PASS de guardia HTTP 401 sin sesión administrativa |
| `/index.php/admin/api/reservations/operation` | Mapa/POS y resumen base | Cubierto por runner dinámico; guardia administrativa 401 sin sesión |

La evidencia equivalente de backend está cubierta por los runners dinámicos; no se alteró el motor temporal para suplir la falta de Apache.

## Archivos creados

- `services/CapacidadReservacionesService.php`
- `scripts/tests/run-etapa5-capacidad.php`
- `docs/reservaciones/reporte_etapa5_capacidad.md`

## Archivos modificados

- Servicios de disponibilidad, administración, POS y reservaciones públicas.
- Controladores y payloads de administración/mapa.
- Catálogo canónico de errores.
- Vistas y fuentes/bundles JavaScript administrativos.

## Confirmación de fuera de alcance

- Motor temporal sin modificar: sí; Etapa 4 continúa pasando.
- Tolerancia sin modificar: sí; no-show/ausencia continúa pasando.
- Modales sin modificar: sí; sólo se cambiaron mensajes y campos de decisión del formulario.
- DDL sin modificar: sí.
- Catálogo estable: sí; auditoría sin errores ni warnings.

## Riesgos y deuda restante

- Falta ejecutar evidencia HTTP real cuando Apache o un servidor compatible con `PATH_INFO` esté disponible.
- El frontend conserva algunos alias históricos para compatibilidad de consumidores; los campos canónicos son la fuente de verdad.

## Resultado

- La capacidad física real y la demanda de reservaciones sin mesas quedaron centralizadas.
- Administración puede confirmar sin mesas o bajo sobrecapacidad sólo mediante decisiones explícitas.
- Landing, administración, mapa y POS comparten los mismos hechos base.
- La Etapa 6 debería abordar la evidencia HTTP completa y cualquier retiro futuro de alias de compatibilidad.
