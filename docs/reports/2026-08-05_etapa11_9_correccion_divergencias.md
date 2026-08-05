# Etapa 11.9 — Corrección de divergencias críticas previas a la limpieza final

Fecha de corte: 2026-08-05  
Alcance: correcciones F-01 a F-05, decisiones D-01 a D-05, pruebas de instalación limpia y documentación de deuda controlada.  
Decisión: Etapa 12 no inicia en esta entrega.

## Resultado

La fuente de verdad se actualizó primero en `reservaciones_fuente_de_verdad.md`. Después se implementaron las correcciones funcionales y de seguridad, se reconciliaron las suites históricas y se verificó una instalación temporal completa. No se modificaron el esquema, los enums, los contratos de payload ni las rutas funcionales de reservaciones; la única retirada de rutas corresponde a la limpieza física web prohibida por F-04.

## Correcciones cerradas

### F-01 — Revalidación final de reemplazo

`services/ReservacionPublicaService.php` ahora bloquea original y reemplazo con fechas de operación, vuelve a consultar disponibilidad canónica con ambas exclusiones, valida ocupación, número de mesas, capacidad y agrupación pública, y sólo entonces cambia los estados. Ante conflicto hace rollback y devuelve `SIN_DISPONIBILIDAD`; la original permanece confirmada.

La exclusión canónica acepta múltiples IDs en `OcupacionMesasService` y `DisponibilidadReservacionService`, necesaria para no contar como conflicto las dos filas que participan en el reemplazo.

### F-02 — Holds en walk-in y apertura de ticket

`PuntoVentaReservacionService` usa la ocupación canónica para el flujo walk-in y ejecuta una segunda validación transaccional sobre las mesas seleccionadas. Un hold vigente bloquea tanto la apertura walk-in como el inicio de un ticket de reservación. La fuente de ocupación es la misma que usa el resto del módulo y respeta la zona horaria del restaurante.

### F-03 — CSRF público obligatorio

`ReservacionController::crearVerificada()` valida CSRF sin condición. La presencia de `request_token` sólo conserva idempotencia y trazabilidad; nunca sustituye el CSRF.

### F-05 — CSRF común para POS

Se añadió `services/StaffCsrfService.php`. Todas las mutaciones POS pasan por la misma validación y aceptan el token en `X-CSRF-TOKEN` o en el cuerpo; no se acepta token por URL. La vista expone el token a los scripts y `src/js/modules/punto-de-venta.js` lo envía en las escrituras.

### F-04 — Sin borrado físico desde la web

Se eliminaron la vista y las rutas web de `cleanup-preview` y `cleanup`. La pantalla de herramientas sólo procesa retenciones vencidas. El borrado físico de fixtures queda en `scripts/limpiar-fixtures-reservaciones.php`, exclusivo de CLI, con base de datos de prueba identificable, base activa distinta, prefijo, rango, estados explícitos y confirmación literal.

### D-01 — Fecha visible y límite por día del restaurante

`ReservacionVigenciaService` define visibilidad pública y límite de cuenta como reservación confirmada con `fecha >= DATE(instante_restaurante)`. Los holds nuevos vigentes usan la misma fecha, excluyen reemplazos pendientes y no se comparan sólo por hora. Las autorizaciones para modificar o cancelar siguen siendo una decisión separada.

## Reconciliación documental y de pruebas

- D-02: la instalación canónica queda documentada en `database/ddl.sql` y `database/dml.sql`; `database/database.sql` no se usa como fuente de instalación.
- D-03: el reporte histórico de Etapa 8 existente es `2026-08-03_etapa8_reconstruccion_administrativa_reservaciones.md`. Se añadió `docs/reports/README.md` como índice para evitar depender de un nombre inexistente.
- D-04: `reservaciones_fuente_de_verdad.md` conserva una sola sección `## 15. Modificación pública`.
- P-01: `reenviarOtpModificacion()` se conserva como compatibilidad de la ruta heredada y devuelve una respuesta de compatibilidad; no crea un segundo OTP. Su retiro queda fuera de esta etapa.
- D-05: la suite Etapa 9.5 se corrigió para que cada worker capture su snapshot antes de abrir la barrera. La barrera espera archivos `ready` de todos los workers; no se relajaron expectativas ni se ocultó una condición de carrera.

## Verificación

Resultados de la ejecución final:

| Comprobación | Resultado |
|---|---|
| `php tests/php/etapa11_9_instalacion_limpia.php` | PASS; instalación temporal, casos F-01/F-02/F-03/F-04/F-05/D-01 y regresiones 9.5/11.5/11.7.2; base eliminada |
| `php tests/php/etapa9_5_instalacion_limpia.php` | PASS; base temporal eliminada |
| `npm.cmd test` | PASS; PHP 5/11.5/11.7.2 y cinco suites JS |
| `npm.cmd run build` | PASS; build finito completo |
| `git diff --check` | PASS; sólo advertencias de conversión LF/CRLF del entorno |

El build mantiene únicamente las advertencias conocidas de la API legacy de Sass y `fs.Stats` de Node; no hubo error de compilación. No se creó commit.
