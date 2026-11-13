# Integración local Etapa 0: informes PDF + M3–M6

**Estado:** integración y aceptación local completadas en `codex/integracion-etapa0`. No se hizo push ni merge definitivo.

## Referencias y preservación

| Referencia | SHA | Estado al cierre |
|---|---|---|
| `origin/main` | `5d24701a65697476ff4966188d9aad4c157dab17` | Actualizada mediante fetch; base de integración |
| `origin/branch-leo` | `9b290627c92f88fba54f9d851bf6991a33193364` | Sin cambios |
| `codex/auditoria-informes-pdf` | `9b290627c92f88fba54f9d851bf6991a33193364` | Sin cambios |
| `main` local | `972d5df8972c22e658cb487ee9b8dec8022cad3e` | Sin mover; divergía del `main` remoto actualizado |
| `codex/snapshot-auditoria-informes-pdf` | `731251b1b838a2f19bca97ef7ceca87158bc6403` | Snapshot local de los 10 archivos pendientes de auditoría |

La rama temporal se creó desde el `main` remoto actual, integró `branch-leo` y aplicó el snapshot de auditoría. Los commits locales de la integración son `6bb9811`, `10c0f0b` y el commit de cierre de esta etapa. Las referencias originales de `main`, `branch-leo` y auditoría permanecieron intactas. El árbol final debe quedar limpio; la integración queda local para revisión.

## Resolución y revisión de contenido

El merge produjo cuatro conflictos:

- `controllers/AdminAlmacenesController.php`: se conservaron proveedores por ámbito y almacenes editables de M5, junto con el enlace PDF de Restaurante y la validación del periodo.
- `package.json`: se conservaron comandos de tipo de venta y faltantes M3/M4, y se unieron los comandos de informes y selector de periodo. Se añadió `test:articulos:m6:db` para la clasificación M6 aislada.
- `public/build/js/admin.js` y `.map`: se regeneraron desde las fuentes ya conciliadas con `npx gulp adminJs`.

La revisión transversal confirmó rutas PDF solo para Restaurante, controles de autenticación, NIP, permisos de módulo y ámbito, filtros/rangos validados y denegaciones HTTP. El controlador de Inventario conserva proveedor M5 y consulta PDF de solo lectura. `database/ddl.sql` y `database/development.sql` no difieren de `main` en esta integración; no se añadió una migración de esquema. Los contratos M4 mantienen `permitir_negativos=0`, cantidades faltantes explícitas y saldo no negativo; M5 conserva vínculo de entrada, ámbito y costo congelado; M6 deriva la clasificación explícita desde las capacidades vigentes.

La documentación de informes conserva sus límites: Inventario presenta fotografía actual y movimientos del rango, nunca saldo histórico; Finanzas puede prorratear gastos fijos aunque no haya ventas; el reloj de corte marca provisionalidad, pero no reconstruye qué datos existían entonces.

## Ajustes de compatibilidad de integración

- `run-menu-tipo-venta-ui.cjs`: el contrato de `menu_csrf` acepta LF y CRLF. La primera ejecución Windows falló por un salto LF literal, aunque el campo y el formulario estaban presentes.
- `views/admin/almacenes/index.php`: se usa `!empty($informePeriodoTexto)` para que el include directo de la vista, además del render del controlador, no emita warning cuando la variable opcional no viene definida.
- `run-variantes-consumo.php`: el fixture temporal ahora aplica A1 y A3 en modo estricto y registra la fila `oferta_datos_comerciales` de su oferta sintética; el runner se alinea así con el esquema actual sin saltarse guardias ni cambiar servicios.
- `run-articulos-m6.php`: se añadieron 10 comprobaciones de clasificación compra/preparación, conservación de almacenamiento/costo efectivos, revisión vigente/obsoleta, permiso de edición, aislamiento de ámbito y no mutación cuando se deniega.

## Pruebas ejecutadas

Todas las suites con base de datos crearon bases temporales con nombre aleatorio y token de propiedad; cada runner confirmó y eliminó su propia base. La conexión se hizo al MySQL local XAMPP; no se seleccionó la base configurada por la aplicación para ejecutar fixtures. No se ejecutaron migraciones sobre una base existente.

| Área | Suite | Resultado |
|---|---|---|
| Informes PDF | `scripts/tests/informes-aceptacion/run.php` | PASS: FIN, Análisis, Reservaciones, Inventario, Tickets; 13 consistencia; 80 IA de informes; 22 PDFs representativos; 105 HTTP (21 por módulo); limpieza confirmada |
| Informes fundación | `npm run test:informes` | PASS |
| Selector de periodo | `npm run test:range-picker` | PASS: fechas estrictas, 366/367 días, teclado, cancelar e inicialización idempotente |
| IA y ámbitos | `run-analytics-ambito-isolated.php` | PASS: 33 análisis, 491 consultas predefinidas, 49 finanzas; Restaurante/Panadería/Global |
| M3 | `run-tipo-venta.php` | PASS: 49 comprobaciones |
| M4 | `run-faltantes.php`, `run-faltantes-vista-db.php` | PASS: 68 y 35 comprobaciones; contrato sin negativos, faltantes y vista |
| M5 | `run-proveedores.php` | PASS: 81 comprobaciones; aislamiento, atribución, costo e idempotencia |
| M6/M2 | `run-articulos-m6.php`, `run-articulos-m2.php` | PASS: 10 de clasificación/permisos/revisión y 130 de dominio M2 |
| UI M6/M2 | `run-articulos-capacidades-ux.cjs`, `run-articulos-m2-ui.cjs` | PASS |
| Almacenes | `run-almacenes-3c.php`, `run-almacenes-3c-concurrencia.php` | PASS: 159 y 17 comprobaciones |
| Consumo POS | `run-variantes-consumo.php` | PASS: 21 comprobaciones de almacén seleccionado, variante y limpieza de asignaciones |
| Costos históricos | FIN acceptance, `run-articulos-m2.php`, `run-faltantes.php` | PASS: FIN usa costo congelado en `ticket_items` (COGS sintético `$24.00`, venta sin costo `$25.00`); M2 verifica costo por copa congelado; M4 confirma que el costo del ticket no se recalcula |
| Migración A3 y menú | `run-a3-datos-comerciales.php`, `run-menu-preservacion-db.php` | PASS: 59 y 7 casos; diferencia estricta aborta sin reemplazar datos locales y el paquete/menú preservado se valida en DB aislada |
| PHP general | `npm run test:php` | PASS completo |
| JavaScript general | `npm run test:js` | PASS completo tras corregir regex de saltos de línea |
| Bundle admin | `npx gulp adminJs` | PASS; bundle y mapa regenerados |

Evidencia de aceptación y log final fuera del repositorio:

`C:\Users\leona\AppData\Local\Temp\cp_pdf_final_evidence_9fc235b24644438a8c4b508eba7f67bc\acceptance-output.log`

PDFs y JSON sintéticos de aceptación están en el mismo directorio. El contacto visual de los 11 folios principales y el de 22 variantes (normal, vacía, parcial, futura y extensa) están en:

- `C:\Users\leona\.codex\visualizations\2026\11\13\01a1d628-453f-7fb3-8729-5799770bc737\etapa0-pdf-review\informes-contacto.png`
- `C:\Users\leona\.codex\visualizations\2026\11\13\01a1d628-453f-7fb3-8729-5799770bc737\etapa0-pdf-review\variantes-contacto.png`

Se inspeccionaron jerarquía, tipografía neutral, tablas, gráficas, notas, secciones y pies/paginación; no se observaron recortes ni solapamientos. Las muestras con texto HTML sintético aparecen escapadas como texto visible.

## Límites de la validación

- `run-restaurante-3d2.php` no se ejecutó: sus assertions legacy esperan habilitar saldo negativo, contrario al contrato M4. Las rutas actuales de consumo, faltantes, costo y compensación sí se validaron en runners compatibles con M4.
- `run-restaurante-3d3.php` no se ejecutó porque requiere un paquete privado de preservación externo que no está disponible en este entorno. A3 y preservación se cubrieron con sus runners aislados específicos.
- Las consultas financieras históricas se verificaron con fixtures sintéticas; no certifican datos de producción, MariaDB externa, credenciales de proveedores ni integraciones externas.
- No se añadió Redis ni historial de precios de proveedores. No hubo push ni merge a `main` o `branch-leo`.
