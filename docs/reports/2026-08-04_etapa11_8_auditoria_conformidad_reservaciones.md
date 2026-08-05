# Etapa 11.8 — Auditoría de conformidad de reservaciones

Fecha de corte: 2026-08-04  
Rama auditada: `modulo-reservaciones`  
Alcance: fuente de verdad, esquema, servicios, rutas, consumidores, bundles, pruebas y reportes históricos.  
Regla aplicada: auditoría documental y técnica únicamente; no se modificó comportamiento, esquema, rutas, contratos ni estado funcional.

## Resumen ejecutivo

El módulo tiene una base canónica consistente en constantes, horarios, ocupación, asignación, estados, OTP, mapas, POS y esquema. La matriz confirma 17 áreas directamente conformes y 4 conformes con deuda técnica controlada.

No se recomienda iniciar Etapa 12. Hay dos divergencias funcionales críticas que afectan la exclusión de doble ocupación y la revalidación transaccional, dos divergencias altas de seguridad y una herramienta destructiva de desarrollo que contradice el contrato de no eliminación física desde interfaces. La deuda documental y la suite histórica de Etapa 9.5 también deben reconciliarse antes de ampliar el módulo.

La decisión es: **Etapa 12 no iniciar**. Primero deben cerrarse o aceptarse formalmente los hallazgos `F-01` a `F-05`, reconciliarse `D-01` y `D-05`, y retirar o aislar documentalmente el mantenimiento destructivo `F-04`.

## Conteo y rating

La matriz contiene 36 hallazgos:

| Clasificación | Conteo |
|---|---:|
| `CONFORME` | 17 |
| `CONFORME_CON_DEUDA_TECNICA` | 4 |
| `DIVERGENCIA_FUNCIONAL` | 5 |
| `DIVERGENCIA_DOCUMENTAL` | 5 |
| `IMPLEMENTACION_PARCIAL` | 1 |
| `NO_IMPLEMENTADA` | 0 |
| `NO_VERIFICABLE` | 1 |
| `CODIGO_RESIDUAL` | 3 |
| **Total** | **36** |

| Severidad | Conteo | Prioridad dominante |
|---|---:|---|
| `CRITICA` | 2 | P0 |
| `ALTA` | 2 | P0 |
| `MEDIA` | 7 | P1/P2 |
| `BAJA` | 8 | P3 |

Las filas conformes no reciben severidad. La numeración y evidencia completa están en la [matriz de conformidad](2026-08-04_etapa11_8_matriz_conformidad_reservaciones.md).

## Divergencias críticas y altas

### F-01 — Confirmación final de reemplazo sin revalidación canónica

Clasificación: `DIVERGENCIA_FUNCIONAL`, severidad `CRITICA`, prioridad `P0`.

La fuente exige que la confirmación final bloquee original y reemplazo, revalide disponibilidad y asignación, y sólo después cambie los estados (`reservaciones_fuente_de_verdad.md:675-690`). `ReservacionPublicaService::confirmarReemplazo()` bloquea ambas filas y verifica que el reemplazo tenga pivotes, pero pasa directamente a actualizar estados (`services/ReservacionPublicaService.php:684-768`); no invoca `DisponibilidadReservacionService`, `OcupacionMesasService` ni una revalidación equivalente de mesas/tickets dentro de esa confirmación.

El flujo de creación del reemplazo sí revalida y asigna provisionalmente. Eso no sustituye la obligación de revalidar al confirmar, porque una ruta concurrente puede alterar el contexto entre ambos momentos.

### F-02 — Walk-in alternativo omite holds vigentes

Clasificación: `DIVERGENCIA_FUNCIONAL`, severidad `CRITICA`, prioridad `P0`.

La fuente define que un hold vigente bloquea mesas (`reservaciones_fuente_de_verdad.md:355-371`) y exige revalidar ocupación física al abrir ticket (`reservaciones_fuente_de_verdad.md:979-989`). `PuntoVentaReservacionService::abrirWalkIn()` sólo consulta tickets abiertos y `proximasReservaciones()`; esta última filtra `r.estado = 'confirmada'` (`services/PuntoVentaReservacionService.php:300-398`, `820-850`). Por tanto, la ruta de walk-in puede abrir ticket sobre una mesa retenida por una reserva pública pendiente.

El riesgo alcanza la ruta heredada `/api/abrir-ticket`, que conserva el dispatch de walk-in en `controllers/PuntoVentaController.php:96-114` y `public/index.php:207`.

### F-03 — Bypass condicional de CSRF en creación confirmada pública

Clasificación: `DIVERGENCIA_FUNCIONAL`, severidad `ALTA`, prioridad `P0`.

`/api/reservaciones/crear` exige sesión pública verificada, pero sólo valida CSRF cuando llega `request_token` (`controllers/ReservacionController.php:310-331`). El documento de verdad establece que `request_token` no reemplaza CSRF (`reservaciones_fuente_de_verdad.md:1354-1383`). Si una petición autenticada omite el token de operación, puede alcanzar `crearConfirmada()` sin la validación CSRF que sí se exige en las demás mutaciones públicas.

El cliente normal genera token, por lo que las pruebas de frontend pasan; el defecto está en la frontera HTTP y no debe depender de que el cliente sea cooperativo.

### F-05 — Mutaciones POS autenticadas sin protección CSRF equivalente

Clasificación: `DIVERGENCIA_FUNCIONAL`, severidad `ALTA`, prioridad `P0`.

Las rutas POS están protegidas por sesión/rol mediante `Classes\Auth::proteger()` (`classes/Auth.php:19-38`, `103-143`), pero `PuntoVentaController` no valida un token CSRF antes de las mutaciones de comenzar, cancelar, no-show, abrir walk-in, cerrar o liberar (`controllers/PuntoVentaController.php:96-124`, `625-658`). La sesión de personal se usa como única barrera HTTP. Esto deja una superficie de mutación cross-site si el navegador conserva la cookie de sesión y el entorno no impone una política equivalente fuera del código.

No se realizó explotación en vivo; el hallazgo es estático y se conserva como alta prioridad por tratarse de acciones operativas irreversibles o con impacto físico.

## Divergencia funcional media

### F-04 — Eliminación física desde interfaz de desarrollo

Clasificación: `DIVERGENCIA_FUNCIONAL`, severidad `MEDIA`, prioridad `P1`.

La fuente prohíbe eliminación física desde interfaces (`reservaciones_fuente_de_verdad.md:807`, `1696`). La interfaz de desarrollo ofrece “Eliminar definitivamente” y `ReservacionMantenimientoService::limpiar()` ejecuta `DELETE` sobre verificaciones, pivotes y reservaciones (`views/admin/reservations/development-tools.php:53-130`, `services/ReservacionMantenimientoService.php:150-224`). El controlador limita la ruta a `APP_ENV=development` (`controllers/ReservacionMantenimientoController.php:56-66`), lo que reduce la exposición productiva pero no elimina la contradicción contractual.

## Divergencias documentales

- `D-01`: el código de consulta pública muestra reservas confirmadas hasta `inicio + 15` (`models/Reservacion.php:256-300`, `services/ReservacionVigenciaService.php:285-300`), mientras la sección 14 dice “confirmadas futuras” (`reservaciones_fuente_de_verdad.md:589-602`). El reporte histórico de Etapa 7 documenta la decisión de incluir el margen de tolerancia; la fuente principal debe resolver la contradicción antes de cambiar código.
- `D-02`: la especificación/AGENTS mencionan `database/database.sql`, pero el esquema canónico vigente está dividido en `database/ddl.sql` y `database/dml.sql`. No se creó ni renombró ningún archivo.
- `D-03`: el nombre de reporte esperado `2026-08-03_etapa8_administracion_reservaciones.md` no existe; el artefacto presente es `2026-08-03_etapa8_reconstruccion_administrativa_reservaciones.md`. Es un mismatch documental, no una ausencia de evidencia de implementación.
- `D-04`: la fuente repite el encabezado `## 15. Modificación pública` en líneas 606-607. El contenido no cambia, pero la duplicidad puede romper referencias por sección.
- `D-05`: `tests/php/etapa9_5_instalacion_limpia.php` falló únicamente en el caso histórico F de dos asignaciones con el mismo snapshot: ambos workers devolvieron `ASIGNACION_GUARDADA` en vez de dejar un perdedor `VERSION_DESACTUALIZADA`. La suite vigente `tests/php/etapa11_5_instalacion_limpia.php` pasó 12/12 carreras A-L y 14/14 casos de versionado. Es una contradicción entre suites que requiere reconciliación documental antes de usar Etapa 9.5 como gate.

## Deuda técnica y código residual

Hallazgos controlados:

- `T-01`: `ReservacionConfig` contiene aliases históricos y constantes operativas adicionales (`services/ReservacionConfig.php:19-48`, `121-131`). Los valores canónicos coinciden, pero hay más nombres para la misma regla.
- `T-02`: `PuntoVentaReservacionService::contextoMesa()` usa un margen informativo de preparación de 15 minutos al calcular `liberacion_estimada` (`services/PuntoVentaReservacionService.php:611-629`), mientras la ocupación canónica usa apertura + 90 + retraso. No gobierna la capacidad, pero puede confundir la lectura operativa.
- `T-03`: `AsignacionMesasService::obtenerOcupacionParaHorario()` conserva una rama `forzarOcupacionFisica` que combina consultas antiguas con el servicio canónico (`services/AsignacionMesasService.php:76-105`). Actualmente la usa el inicio POS; requiere retirar la duplicidad sólo después de una migración trazable.
- `R-01`: `ReservacionService::actualizarDatos()` retorna inmediatamente a la fachada administrativa y conserva debajo una implementación histórica inalcanzable (`services/ReservacionService.php:139-305`).
- `R-02`: `MesaEstadoService::normalizarMesas()` retorna a `normalizarMesasCanonicas()` y conserva una implementación histórica inalcanzable (`services/MesaEstadoService.php:27-58`).
- `R-03`: quedan referencias históricas a `llego`, `status_changed_at` y otros campos retirados dentro de migradores/aserciones de pruebas; no aparecen en el DDL vigente ni en consultas activas. También quedan rutas y aliases de compatibilidad inventariados en el documento de limpieza.

No se encontraron `request_fingerprint`, `created_by`, `last_modified_by`, `last_modified_source`, `last_change_reason`, `arrived_at`, `confirmed_at` ni `completed_at` en el esquema o código activo. Las ocurrencias restantes están en la fuente de verdad o en verificaciones/migración histórica.

## Integridad de estados, ocupación y mapas

La implementación vigente es conforme en los puntos siguientes:

- enum de ocho estados, transiciones y etiqueta `Reemplazada`;
- `pendiente_verificacion` con hold vigente y `confirmada` como fuentes de ocupación de reserva;
- `en_curso` representado por ticket abierto y `ticket_mesas`, sin doble conteo;
- duración de reserva y ticket de 90 minutos, retraso cero, tolerancia de 15 minutos;
- pares/tríos por número de mesa y selección determinista;
- inicio POS multimesa atómico, con un ticket y todos los pivotes o ninguno;
- mapas con la misma proyección, sólo identidad confirmada, holds sin tarjeta y `reemplazada` excluida;
- asignación administrativa explícita con snapshot, versión y conflicto;
- contrato `pos-reservacion.v1` sin cambios de esquema en esta auditoría.

Estas conformidades no neutralizan F-01, F-02 ni F-05: los tres atacan rutas de escritura distintas del lector canónico.

## Rutas, consumidores y estado

La superficie completa está inventariada en [Inventario de limpieza y rutas](2026-08-04_etapa11_8_inventario_limpieza_etapa12.md). En síntesis:

- público: disponibilidad, retención, creación verificada, modificación/reemplazo, confirmación, cancelación, OTP, consulta por sesión y logout;
- administración: listado, creación, actualización, estado, reasignación y mapa operativo;
- POS: lectura operativa, inicio, no-show, cancelación, walk-in, cierre y compatibilidad histórica;
- bundles: `gulpfile.js` recompila los fuentes canónicos de landing, admin, mapa, operación y POS; los dos builds consecutivos fueron reproducibles.

Las rutas heredadas no se eliminaron durante la auditoría. Las principales son `/api/reservation-schedules`, `/api/operacion/horario-efectivo`, `/api/abrir-ticket`, `/api/liberar-reservacion`, `/api/cerrar-ticket` y los posts no-API de operación administrativa. Se clasifican como deuda/compatibilidad, salvo F-02 que es funcional por el comportamiento walk-in.

## Seguridad y privacidad

Resultado: **parcialmente conforme, con dos hallazgos altos**.

Conforme:

- OTP persistido como hash, con vencimiento, intentos y un solo uso (`services/ContactoAccesoService.php` y `models/VerificacionContacto.php`);
- consulta pública basada en sesión verificada, sin seleccionar mesas, tokens ni campos administrativos (`models/Reservacion.php:248-285`);
- sesión pública con cookie HttpOnly/SameSite y CSRF (`services/ReservationClientSession.php`);
- rutas administrativas y POS detrás de autenticación y rol global (`classes/Auth.php:103-143`);
- respuestas públicas sin exponer capacidad interna ni identidad de holds.

Pendiente: cerrar F-03 y F-05. La protección no puede descansar en el token enviado por el frontend ni en que el POS se use exclusivamente como kiosco.

## Concurrencia

Resultado: **mixto**.

Las carreras A-L de Etapa 11.5 pasaron 12/12; el versionado de asignaciones pasó 14/14; las pruebas de inicio/cierre multimesa, idempotencia, cancelación y no-show pasaron en las suites aisladas. Sin embargo, la confirmación final de reemplazo no revalida la disponibilidad canónica, y el walk-in bypassa holds. Por eso la evidencia de concurrencia positiva no permite cerrar F-01/F-02.

## Pruebas y builds ejecutados

| Comando/suite | Resultado |
|---|---|
| `php scripts/run-tests.php` | PASS: Etapa 5, 11.5 y 11.7.2 limpias |
| `npm.cmd test` | PASS: runner PHP + 5 suites JS |
| `npm.cmd run test:js` | PASS: reservation form, operation map, accessibility, modal, multitable |
| `npm.cmd run build` | PASS; advertencias deprecación Sass/Node |
| `npm.cmd run build` segunda ejecución | PASS; reproducible |
| `git diff --check` | PASS |
| `tests/php/etapa5_nucleo.php` | PASS 59 |
| `tests/php/etapa6_2_fecha_horarios_capacidad.php` | PASS 20 |
| `tests/php/etapa7_5_instalacion_limpia.php` | PASS 35 |
| `tests/php/etapa8_instalacion_limpia.php` | PASS 19 |
| `tests/php/etapa9_5_instalacion_limpia.php` | FAIL histórico F; ver D-05 |
| `tests/php/etapa10_instalacion_limpia.php` | PASS integración 11 + concurrencia |
| `tests/php/etapa11_5_instalacion_limpia.php` | PASS A-L 12/12, versionado 14/14, crítica 20/20 |
| `tests/php/pos_reservacion_contrato.php` | PASS |
| `tests/php/pos_reservacion_integrado.php` | No independiente: exige `--db`; integración POS cubierta por Etapa 10 |

No se ejecutó validación manual de navegador, Network, lector de pantalla o prueba física XAMPP durante esta auditoría; se conserva como `NO_VERIFICABLE` (`N-01`). Los reportes históricos tampoco deben sustituir esa verificación.

## Artefactos entregados

- [Matriz de conformidad](2026-08-04_etapa11_8_matriz_conformidad_reservaciones.md)
- [Inventario de limpieza, rutas y Etapa 12](2026-08-04_etapa11_8_inventario_limpieza_etapa12.md)

## Decisión de Etapa 12

**NO INICIAR Etapa 12.** La base del módulo es suficientemente estable para una corrección focalizada, pero no está cerrada en conformidad: deben resolverse o aceptarse explícitamente las divergencias P0, reconciliarse las suites de versionado y actualizar la documentación fuente antes de abrir una etapa de limpieza o ampliación.
