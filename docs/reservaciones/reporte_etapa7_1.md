# Reporte — Etapa 7.1

## Estado

- APROBADA

## Rama y commits

- Rama: modulo-reservaciones
- Commit inicial: 0642b562 — docs(reservaciones): cerrar estabilizacion del modulo
- Commits:
  - 4f7b5b5 — fix(reservaciones): corregir decisiones administrativas
  - cd296a4 — fix(reservaciones): restaurar asignacion de mesas en mapa
  - 0827b01 — fix(reservaciones): ampliar modales operativos
  - cuarto commit de cierre — test(reservaciones): validar regresiones operativas post-cierre
- Commit final: HEAD del cuarto commit de cierre
- Working tree inicial: limpio
- Working tree final: limpio después del cuarto commit

## Acciones administrativas

- Etiqueta final: Asignar más tarde
- Acción duplicada retirada: Asignar después
- Comportamiento para >12: la asignación automática queda deshabilitada y la reservación puede confirmarse sin mesas.
- Comportamiento para cancelar realmente: Volver cierra el modal sin crear la reservación; no se usa Cancelar para confirmar sin mesas.

## Capacidad y mensajes

| Caso | Código | Mensaje | Resultado |
| --- | --- | --- | --- |
| Selección manual con capacidad insuficiente | CAPACIDAD_INSUFICIENTE | Título, descripción, resumen, consecuencia y acciones específicas de capacidad | Se separa de tickets y ofrece Volver a seleccionar / Guardar de todas formas |
| Ticket abierto real | CONFLICTO_TICKETS_ABIERTOS / CONFLICTO_TICKET_ABIERTO | Conflicto físico con ticket y mesas afectadas | Conserva la confirmación específica de ticket |
| Mesa ocupada | MESA_OCUPADA | Causa de ocupación actual | No cae en el mensaje genérico |
| Versión o concurrencia | VERSION_DESACTUALIZADA / CONFLICTO_CONCURRENTE | La reservación cambió desde la consulta | Descarta la edición local y pide revalidar |

## Creación desde mapa

| Caso | Checkbox | Propuesta | Mesas guardadas | Resultado |
| --- | --- | --- | --- | --- |
| Grupo 1–12 con combinación válida | true normalizado con FILTER_VALIDATE_BOOLEAN | Automática | Sí, dentro de la transacción | Confirmada; no emite SIN_ASIGNACION |
| Creación manual | false | No se fuerza propuesta | No | SIN_ASIGNACION |
| Sin combinación o >12 | false efectivo o condición no elegible | No disponible | No | SIN_ASIGNACION |

El mapa envía explícitamente asignar_automaticamente=1 o 0, evitando la ambigüedad de un campo omitido en FormData y evitando listeners duplicados.

## Edición de mesas

- Estado requerido: confirmada, usuario autorizado, CSRF válido, versión actual, sin bloqueo específico de ticket/ocupación.
- Endpoint: POST /admin/api/reservations/operation/assign-tables
- Payload: reservation_id, fecha, hora, version_esperada, mesa_ids_actuales_presentes, mesa_ids_actuales[], mesa_ids[], CSRF y confirmaciones sólo cuando corresponden.
- Código anterior: presentación genérica Acción no permitida.
- Causa real: el consumidor frontend no conservaba el flujo de assignment_edit y consumía la respuesta sin utilizar el catálogo específico.
- Resultado: flujo canónico viewing → assignment_edit → saving → viewing/conflict, snapshot restaurable al cancelar, y errores específicos en el shell compartido.

## Modal

| Viewport | Ancho | Alto máximo | Scroll | Resultado |
| --- | ---: | ---: | --- | --- |
| 1366×768 | 840px | 736px | Sólo en el cuerpo si excede | Legible |
| 1024×768 | 737.28px aprox. | 736px | Sólo en el cuerpo si excede | Legible |
| 390×844 | 366px | 820px | Permitido sólo si el contenido excede | Adaptado |

El portal se monta directamente bajo document.body; el diálogo usa clamp(620px, 72vw, 840px), título mínimo de 24px, texto mínimo de 16px y botones de 46px. Los bundles fueron recompilados y las vistas mantienen sus versiones reservation-form-v9 y reservation-operation-v22.

## Pruebas

| Comando | Resultado | Comprobaciones |
| --- | --- | --- |
| php scripts/tests/run-reservaciones-catalogo.php | PASS | 191 códigos catalogados |
| php scripts/auditar-errores-reservaciones.php | PASS | 0 errores, 0 warnings |
| php scripts/tests/run-etapa4-motor-temporal.php --dynamic | PASS | Fixtures dinámicos aislados y eliminados |
| php scripts/tests/run-etapa5-capacidad.php --dynamic | PASS | Capacidad, demanda y proyección |
| php scripts/tests/run-etapa6-flujos-autenticados.php --static-only | PASS | Shell y consumidores |
| php scripts/tests/run-etapa6-flujos-autenticados.php --dynamic | PASS | HTTP, CSRF, tickets y capacidad |
| php scripts/tests/run-etapa7-concurrencia.php | PASS | Concurrencia e idempotencia |
| php scripts/tests/run-etapa7-1-regresiones-operativas.php | PASS | Contratos B1–B10 |
| php scripts/tests/run-etapa7-1-regresiones-operativas.php --dynamic | PASS | Smoke HTTP autenticado |
| php scripts/tests/run-reservaciones-integral.php --dynamic | PASS | 10 suites, 0 fallas |
| npm.cmd test / npm.cmd run audit:reservaciones | PASS | Catálogo, sintaxis JS y auditoría |
| npm.cmd run build | PASS | CSS, JS, mapas de operación y bundles |
| composer validate --no-check-publish | PASS con warning | Sólo falta licencia en composer.json |
| php -l, node --check, git diff --check | PASS | Sintaxis y whitespace |

## Evidencia visual

- Capturas: evidencia_visual_etapa7_1.md y capturas_etapa7_1.
- Datos utilizados: usuario demo local, Usuario test y nombre sintético Demo Etapa 7.1; no se incorporaron datos personales reales en las capturas.
- Incidencias: el exportador PNG del navegador conservó el formulario padre al capturar el portal global del ConfirmationModal; el DOM runtime confirmó el diálogo visible, su foco y las acciones exactas en b1-confirmacion-dom-1024x768.md y b2-capacidad-dom-1024x768.md.

## Archivos creados

- scripts/tests/run-etapa7-1-regresiones-operativas.php
- docs/reservaciones/evidencia_visual_etapa7_1.md
- docs/reservaciones/capturas_etapa7_1/
- docs/reservaciones/reporte_etapa7_1.md

## Archivos modificados

- Catálogo y servicios: services/ReservacionErrorCatalog.php, services/ReservacionAdministrativaService.php
- Controladores: controllers/AdminReservacionController.php, controllers/ReservacionOperacionController.php
- Frontend: src/js/admin/reservations/form.js, src/js/admin/reservations/operation.js, src/js/components/confirmation-modal.js, src/scss/components/_confirmation-modal.scss
- Vistas: views/admin/reservations/_form.php, views/operation/reservations/index.php
- Normativa y runner previo: reservaciones_fuente_de_verdad.md, scripts/tests/run-etapa6-flujos-autenticados.php
- Compilados: assets/ y public/build/ afectados por el build

## Confirmación de fuera de alcance

- Motor temporal: sin cambios
- Tolerancia: sin cambios
- Capacidad: se corrigió su presentación y transporte; no se alteró la fórmula
- DDL: sin cambios
- Catálogo: se extendió el contrato existente sin crear un traductor paralelo

## Riesgos restantes

- El build emite advertencias deprecadas de Sass legacy; no afectan el resultado compilado.
- Conviene repetir B2 con un fixture de integración dedicado en el entorno de merge si se requiere observar el modal de capacidad en un recorrido persistente; el contrato, la rama frontend y el auditor ya están cubiertos.

## Resultado

- Quedaron corregidas las acciones redundantes, el código/presentación de capacidad, la serialización booleana del mapa, la edición canónica de mesas, los errores específicos y el shell global de confirmación.
- El branch queda preparado para merge con working tree limpio y los bundles actualizados.
