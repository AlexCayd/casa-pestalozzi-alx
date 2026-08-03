# Etapa 4 — Reconstrucción controlada del esquema de reservaciones

Fecha de ejecución: 2026-08-02/03, zona `America/Mexico_City`.

## Decisión ejecutiva

La reconstrucción queda aprobada en el clon aislado y el código queda preparado para una futura ventana de migración explícita. La base activa no fue migrada ni modificada. No se inicia automáticamente la siguiente etapa.

La aprobación es condicionada únicamente por una validación visual manual pendiente en Apache/XAMPP: el navegador integrado no pudo abrir direcciones `localhost` por su política de URL, aunque el servidor PHP local respondió correctamente por HTTP y las tres rutas de contrato fueron ejercitadas por la suite integrada.

## Alcance ejecutado

- Se reconstruyó `reservaciones` con el contrato canónico: `id`, `nombre`, `contacto_tipo`, `contacto`, `fecha`, `hora`, `comensales`, `nota`, `comentario_admin`, `origen`, `request_token`, `hold_expires_at`, `estado`, `reemplaza_reservacion_id`, `estado_changed_at`, `created_at` y `updated_at`.
- Se retiraron del esquema activo del clon `llego`, `arrived_at`, `confirmed_at`, `completed_at`, `request_fingerprint`, `last_modified_by`, `last_modified_source`, `last_change_reason` y `status_changed_at`.
- `estado` quedó limitado a `pendiente_verificacion`, `confirmada`, `en_curso`, `completada`, `cancelada`, `no_show`, `expirada` y `reemplazada`.
- `origen` quedó limitado a `landing` y `admin`.
- Se conservaron las relaciones históricas de `reservacion_mesas`, `verificaciones_contacto`, `tickets.reservacion_id` y `ticket_mesas`.
- Se añadieron checks de dominio para comensales, contacto y vigencia de holds; unicidad de `request_token`; índices canónicos; FKs; y triggers de persistencia para impedir auto-reemplazos.
- Se adaptaron modelos, servicios POS/public/admin, serializer canónico y pruebas para dejar de escribir o leer las columnas retiradas.

## Backup y clon

El backup se creó antes de la reconstrucción y contiene la base completa, no sólo la tabla de reservaciones:

- Archivo: `database/backups/etapa4/casa_pestalozzi_reservaciones_pre_etapa4_2026-08-02_232730.sql`
- SHA-256: `3DA5427868299389CDEA691659BBF94806D34E3E5586D6602C077B21BB4A1DD4`
- Base activa previa y posterior: `45` reservaciones, `50` asignaciones, `10` verificaciones, `59` tickets y `63` relaciones `ticket_mesas`.
- Clon: `casa_pestalozzi_etapa4_test`.
- La migración del clon conservó exactamente esos cinco conteos y no se ejecutó contra `casa-pestalozzi`.

Reversión disponible: restaurar el dump anterior en una base previamente protegida y con aprobación explícita de la ventana de cambio. No se ejecutó ninguna reversión ni cutover.

## Resultado de la migración

- `llego` heredado: `2` casos; ambos pasaron a `confirmada` porque no tenían ticket abierto.
- Holds vencidos: `1` pasó a `expirada`; los demás pendientes conservaron hold válido.
- Contactos invalidados por normalización: `3` quedaron como `contacto_tipo=ninguno`, `contacto=NULL`.
- `estado_changed_at`: los `45` registros usaron el antiguo `status_changed_at` como fuente histórica.
- Origen: los `45` registros del clon se clasificaron como `landing` porque tenían token o evidencia de verificación; el DML limpio usa origen explícito según fixture.
- Reemplazo: todos los registros heredados quedaron con `reemplaza_reservacion_id=NULL` por ausencia de evidencia.

MySQL 9.7 no permite que un `CHECK` referencie una columna `AUTO_INCREMENT`. Para conservar la regla “no auto-reemplazo”, el DDL y la migración usan triggers `BEFORE INSERT/UPDATE`, y el modelo añade una segunda validación de dominio.

## Pruebas realizadas

- Instalación limpia con `database/ddl.sql` y `database/dml.sql`, usando entrada UTF-8 directa: válida. Conteos del seed: `32` reservaciones, `37` asignaciones, `0` verificaciones, `44` tickets y `46` `ticket_mesas`.
- Prueba estructural del seed limpio: OK.
- Prueba estructural del clon migrado: OK; columnas, enums, índices, checks, FKs, unicidad, no auto-reemplazo, orfandad y conteos preservados.
- `tests/php/pos_reservacion_contrato.php`: salida `OK`, exit `0`.
- Suite integrada POS–reservaciones con reloj reproducible: OK; contrato, serialización, mutaciones, idempotencia, ticket multimesa, cierre, `no_show` y paridad entre `pos_mapa`, `pos_reservaciones` y `admin_operation`.
- Concurrencia real con dos procesos PHP y conexiones MySQL independientes: OK. En `iniciar` vs `no_show` ganó una sola transición; en dos `iniciar` sobre la misma multimesa ganó una sola apertura, sin ticket parcial y con dos filas `ticket_mesas` completas.
- Lint PHP de `controllers`, `models`, `services`, `includes` y `tests/php`: `PHP_LINT_OK`.
- `git diff --check`: sin errores de whitespace; sólo avisos de normalización LF/CRLF de Git.
- La aplicación respondió `200` en el servidor PHP local. El navegador integrado no pudo cargar `localhost`/`127.0.0.1` por política de URL; por ello la inspección de píxeles y la captura visual quedan como pendiente manual en Apache/XAMPP.

## Riesgos y condiciones de cierre

1. La base activa conserva intencionalmente el esquema heredado. El despliegue real requiere una ventana separada, respaldo confirmado, migración transaccional y validación posterior.
2. El DML limpio contiene tickets abiertos de ejemplo; por eso las mutaciones completas se verificaron en el clon aislado con mesas libres, mientras que el clean install se validó estructuralmente y por integridad del seed.
3. La validación visual con navegador integrado no pudo completarse por la restricción local del entorno. La suite de rutas sí confirmó paridad contractual, pero falta la revisión manual de la UI con Apache disponible.
4. No se implementaron disponibilidad nueva, asignación nueva, rediseño de landing, mapa adicional ni cambios de la siguiente etapa.

## Archivos de prueba relevantes

- `tests/php/etapa4_reconstruir_clon.php`
- `tests/php/etapa4_estructura.php`
- `tests/php/etapa4_concurrencia.php`
- `tests/php/bootstrap_etapa4.php`
- `tests/php/pos_reservacion_integrado.php`

No se creó commit. Los cambios permanecen en el working tree para revisión y decisión de cutover.
