# Etapa 4.5 — Validación visual final y cutover controlado del esquema de reservaciones

Fecha de ejecución: 2026-08-03 00:00–01:00, zona `America/Mexico_City`  
Repositorio: rama `modulo-reservaciones`  
Base configurada en `includes/.env`: `casa-pestalozzi`

## 1. Alcance y criterio de seguridad

Se releyeron completamente la fuente de verdad y los reportes de etapas 3, 3.5 y 4 antes de operar. La ejecución se limitó a validación, respaldo, ensayo de restauración, pruebas de contrato/concurrencia y revisión visual. No se añadieron estados, columnas, versiones, reglas de disponibilidad ni rediseños.

No se hizo commit. Tampoco se inició la etapa 5.

## 2. Fuentes revisadas

| Documento | Líneas | SHA-256 |
|---|---:|---|
| `reservaciones_fuente_de_verdad.md` | 1940 | `E7F33FF678E9B9935C55C46E79DEDD1006BCAC685996B665D7EBE407EF3E7219` |
| `docs/reports/2026-08-02_etapa3_contrato_canonico_pos_reservaciones.md` | 55 | `21DCAA0AE6CB416AB407D1E96EC79DFDB013BA98223D69047F662C4AAA434688` |
| `docs/reports/2026-08-02_etapa3_5_validacion_integrada_pos_reservaciones.md` | 231 | `755CE74776CF7A9BCE5E5849931138421B3F7C20677CF4D19EF6D086CE045569` |
| `docs/reports/2026-08-02_etapa4_reconstruccion_controlada_esquema_reservaciones.md` | 72 | `6635D3568C263E8624253A7CBB824733524ADC6100759CF468BB0520DCE61380` |

## 3. Hallazgo previo al cutover

La base activa no estaba en el estado legado esperado por el procedimiento de etapa 4. Antes de cualquier mutación ya tenía:

- las 17 columnas canónicas de `reservaciones`;
- los dos triggers canónicos de auto-reemplazo;
- ningún `status_changed_at`, `personas` ni `admin_comment` en la tabla activa;
- conteos `reservaciones=34`, `reservacion_mesas=41`, `verificaciones_contacto=1`, `tickets=48`, `ticket_mesas=53`.

Esto no coincide con el respaldo legado de etapa 4 ni con el clon aprobado, que tenía 45, 50, 10, 59 y 63 filas respectivamente. Por seguridad no se ejecutó una segunda reconstrucción encima de la activa, no se restauró el clon sobre ella y no se afirmó que ese cambio previo proviniera de esta ejecución. El cutover destructivo de esta etapa queda, por tanto, como **no ejecutado por este proceso; la base ya estaba en esquema nuevo al iniciar**.

## 4. Respaldo y ensayo de restauración

El respaldo de etapa 4 se verificó sin alterarlo:

- archivo: `database/backups/etapa4/casa_pestalozzi_reservaciones_pre_etapa4_2026-08-02_232730.sql`;
- SHA-256: `3DA5427868299389CDEA691659BBF94806D34E3E5586D6602C077B21BB4A1DD4`;
- contiene el esquema legado, incluyendo `status_changed_at`.

Se restauró íntegramente en la base temporal `casa_pestalozzi_etapa4_5_restore` con `utf8mb4`. La restauración produjo `45/50/10/59/63` filas y conservó `status_changed_at`; el ensayo terminó correctamente y se eliminó únicamente esa base temporal. La base activa no fue restaurada ni modificada durante este ensayo.

Como la activa ya estaba en el esquema nuevo, se tomó antes de las pruebas un snapshot íntegro del estado observado:

- archivo: `database/backups/etapa4_5/casa_pestalozzi_pre_cutover_observado_2026-08-03_004117.sql`;
- bytes: `103830`;
- SHA-256: `E8258BBFF4C069A2DD7F9B50640B36F6C8710BAE38B15BC3BD95B61051FD9593`;
- conteos: `34/41/1/48/53`.

Este snapshot es respaldo de entrada a la validación, no evidencia de un cutover ejecutado por esta etapa.

## 5. Revisión de migración y origen

Se revisó `tests/php/etapa4_reconstruir_clon.php`. El procedimiento conserva el guard que exige una base de prueba y rechaza `casa-pestalozzi`; la migración aprobada del clon permanece documentada en el reporte de etapa 4. No se debilitó ese guard para forzar una operación sobre la activa ya canónica.

En la base activa observada, el origen ya estaba persistido: 29 filas `admin` y 5 `landing`. Las 34 filas tenían `request_token`; había una reservación con verificación de contacto y dos con tickets vinculados. La muestra de IDs 1–12 incluyó filas administrativas y landing, estados `confirmada` y `completada`, relaciones de mesa y relaciones con tickets. Debido a que el esquema y el conjunto de datos ya habían cambiado respecto del respaldo legado, no es válido recalcular retrospectivamente el origen activo con la regla de migración de etapa 4.

## 6. Validación estructural, contractual y de integridad

Con `--allow-active` explícito para evitar ejecuciones accidentales sobre producción/desarrollo, pasaron:

- `etapa4_estructura.php`: columnas en orden canónico, enums, origen, índices, FKs, checks, conteos, no huérfanos, unicidad de token, reemplazo y `ON DELETE RESTRICT`.
- Conteos antes/después de las pruebas: `34/41/1/48/53`; quedaron cero fixtures de prueba y cero huérfanos.
- `pos_reservacion_integrado.php`: contratos R1, R2, R3, R4, R5, R6, R8, R10 y R11; inicio una/multimesa, idempotencia, no-show, conflicto físico, cierre y paridad de `pos_mapa`, `pos_reservaciones` y `admin_operation`.
- `etapa4_concurrencia.php`: dos procesos PHP y conexiones independientes para carrera inicio/no-show y carrera de inicio multimesa; pasó la exclusión mutua y dejó un solo ganador/ticket abierto según el caso.
- `pos_reservacion_contrato.php`: OK.
- Lint PHP: 77 archivos, 0 fallos.
- `node --check`: 43 archivos JavaScript, 0 fallos.
- `git diff --check`: 0 errores.

La búsqueda final de campos históricos sólo encontró referencias intencionales en el migrador, respaldos y aserciones de pruebas, además de alias de compatibilidad `personas` en entradas/serialización. No encontró consultas funcionales contra columnas históricas.

## 7. Validación visual

Se levantó temporalmente el servidor PHP con un directorio de sesiones aislado y se retiró al terminar. La página pública se revisó en 1440×900 y 390×844:

- landing clara y legible, menú overlay, navegación y reserva pública visibles;
- pestaña de gestión, selección correo/teléfono y estados accesibles en el DOM;
- lightbox de imágenes abre y cierra visualmente;
- título de página correcto y sin errores de consola después de corregir el path de sesión temporal;
- la ruta `/punto-de-venta` redirige a `/login` y `/admin` a `/admin/login` sin sesión.

Limitaciones encontradas:

1. No había una sesión autenticada ni credenciales autorizadas para entrar al mapa POS o al dashboard admin; por ello no se pudo completar la inspección manual interna de estados claros/oscuros, tooltips y modales de esas superficies.
2. El toggle del menú cambia visualmente a cierre, pero conserva `aria-label="Abrir menú"` y no expone `aria-expanded`/`aria-controls`.
3. El lightbox visible no expone `role="dialog"` ni `aria-modal="true"`, y los contenedores de galería `.m` no son focusables ni tienen rol de control.

Estas observaciones son de accesibilidad/validación visual y no alteran el contrato ni el esquema; no se corrigieron dentro de esta etapa para mantener el alcance solicitado.

## 8. Decisión

**¿La base activa de desarrollo utiliza correctamente el esquema nuevo?** Sí, estructural y contractualmente: la activa ya usa el esquema canónico y todas las pruebas automatizadas ejecutadas sobre ella pasan. No puede certificarse, sin embargo, la trazabilidad del cutover ni la equivalencia de sus 34 filas con el snapshot legado de 45.

**¿Es seguro iniciar la reconstrucción del núcleo de disponibilidad?** No todavía. La etapa 5 no debe iniciar automáticamente hasta reconciliar quién produjo el cambio previo de la base activa y aprobar la diferencia de datos; además debe cerrarse la validación visual autenticada de POS/admin y atender las observaciones ARIA del control de menú/lightbox.

