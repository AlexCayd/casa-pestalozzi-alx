# Auditoría previa de datos para informes PDF

**Estado:** aceptación local aislada completada en `codex/auditoria-informes-pdf`, sobre `branch-leo` (`9b290627`), con referencia `main` (`972d5df8`). Esta etapa documenta datos y evidencia; no cambia la lógica de negocio ni reconcilia ramas.

## Alcance y aislamiento

La matriz parte del contrato de [arquitectura de informes PDF](../arquitectura-informes-pdf.md), los servicios actuales y las pruebas de `scripts/tests/informes-aceptacion/`. En la auditoría inicial sobre `main` `972d5df8` se revisaron `database/development.sql`, `database/ANALITICAS.md` y `docs/desarrollo/instalacion.md`; en esa primera ejecución el seed de desarrollo **no se importó**. La importación y consulta del seed de `5d24701a`, limitada al ámbito Restaurante, están documentadas en el complemento. La aceptación instaló el esquema en una base aleatoria `cp_pdf_test_<20 hex>` mediante `Services\Development\Instalacion`, sembró únicamente fixtures sintéticas del arnés y verificó la marca propietaria antes de limpiar.

La conexión local sirvió solo para crear y borrar la base temporal: no se seleccionó ni consultó el `DB_NAME` configurado en `includes/.env`. La salida de evidencia se guardó fuera del repositorio, sin identificadores o credenciales de conexión. Los PDFs, JSON de consultas y log persistente están en `C:\Users\leona\.codex\visualizations\2026\11\13\01a1d3a0-2f83-7bf0-9858-b535f6e058cd\informes-pdf\final-acceptance-good`.

El seed existente de `main:database/development.sql` aporta una muestra mayor para demos/regresiones: 365 tickets analíticos y 803 líneas, una semana RevPASH de 26 tickets con 11 mesas/44 asientos, reservaciones históricas y movimientos/saldos de inventario derivados de bitácora. En la aceptación inicial contra `main` `972d5df8`, no se importó el seed porque `pdfTestSetup()` llama al instalador con `$simulados=false`; se mantuvo vacía la base antes de aplicar el seed mínimo de aceptación. Reutilizar toda la muestra habría mezclado conteos y comparadores con las expectativas controladas, además de duplicar escenarios que la suite específica ya genera dentro de transacciones.

La guía de instalación confirma que `development.sql` se reserva a instalaciones de desarrollo/prueba. En el commit `main` `972d5df8`, el archivo `scripts/tests/run-proveedores.php` no existe (comprobado con `git ls-tree`), así que esa aceptación inicial no tuvo cobertura de proveedores. La ruta de datos existente se localiza en `services/Inventory/Proveedores.php`, pero los informes no incluyen proveedores. El esquema DDL y la instalación revisados no presentan diferencias entre los commits de referencia indicados.

## Matriz de escenarios y resultados

| Informe | Periodo PDF principal | Fuente SQL y ancla esperada | Resultado cotejado | Vacío / corte parcial / comparación |
|---|---|---|---|---|
| Finanzas | 2001-01-15 a 2001-01-17 | Tickets cerrados y partidas vigentes unidas por ticket y unidad; suma precio × cantidad, costo conocido, partidas sin costo y conteo de tickets. | Ventas `$125.00`, tickets `3`, COGS `$24.00`, ventas sin costo `$25.00`; utilidad bruta `$91.00`, neta `$61.00`. El periodo anterior registra `$10.00` de ventas y `$-22.00` netos. | PDF vacío incluido. La utilidad neta vacía es `$-820.00` porque gastos fijos vigentes se prorratean aunque no haya ventas; el reporte lo explica. Comparación histórica positiva cotejada. El PDF de corte parcial sí lleva el estado `Provisional` y advierte sobre gastos que abarcan tramos futuros. |
| Análisis | 2002-01-15 a 2002-01-17 | Tickets cerrados con subconsulta de partidas por ticket; suma de ventas/comensales/conteo y consulta separada de piezas. | Ventas `$450.00`, tickets `2`, piezas `45`, comensales `7`; `1` ticket con consumo y `1` sin partidas vigentes. Comparativo anterior `$100.00`. | PDF vacío, 366 días y corte parcial generados. Con corte 2004-01-04 y periodo hasta 2004-01-07 el resumen indica comparación provisional. Un rango anual con actividad enero-abril y ocho meses en cero pasa a tabla: al dividir la serie queda un lote sin magnitudes; se conservan todos los meses. |
| Reservaciones | 2001-01-15 a 2001-01-17 | Conteo y suma de comensales agrupados por fecha programada y estado de negocio; retenciones técnicas, expiradas y reemplazadas quedan excluidas. | `7` reservaciones, `29` comensales: confirmadas `2`, en curso `1`, completadas `2`, cancelada `1`, no-show `1`; pendientes de cierre `3`. | PDF vacío y futuro provisional generados. El PDF futuro conserva `Estado: Provisional`, ceros y suprime tasas finales. En periodo histórico la distribución de cinco estados se presenta como tabla; la serie diaria con magnitudes sí se grafica. |
| Inventario | 2001-01-15 a 2001-01-17 | Foto actual por asociaciones activas de almacén/artículo; movimientos por fecha registrada, unidad y almacén; merma conocida = `ABS(delta) × costo`. | Foto: `28` relaciones, `27` artículos, `27` bajo mínimo, `2` almacenes. Periodo: `11` movimientos; merma con costo `$10.00`; `1` merma sin costo. | PDF vacío de movimientos generado. “Vacío” se refiere a actividad en el periodo: la fotografía actual mantiene `25` relaciones, `10` alertas, `4` almacenes en la fixture gráfica. Esos saldos no son un corte histórico. Unidades mixtas no se suman. La fotografía actual es N/A para provisionalidad de fechas; no hay estado provisional. |
| Tickets | 2001-01-15 a 2001-01-17 | Conteo de tickets cerrados/cancelados por fecha efectiva; partidas vigentes agrupadas por ticket; propinas fuera de ventas. | Ventas `$125.00`, tickets cerrados `3`, cancelados `1`, promedio `$41.67`; ventas empatan con Finanzas y Análisis en el periodo compartido. | PDF vacío, 366 días y corte parcial generados. El resumen del corte 2004-01-04 indica comparación provisional. Meses con cero actividad se conservan en tabla, sin gráfico vacío. |

Los valores SQL independientes se conservan en `source-query-finanzas.json`, `source-query-analisis.json`, `source-query-reservaciones.json`, `source-query-inventario.json` y `source-query-tickets.json`. `acceptance-output.log` conserva sus resultados y los PASS de la suite. Las pruebas de dominio comparan métricas con tolerancia de `$0.01`; Finanzas/Análisis/Tickets también verifican la paridad en el periodo compartido, y el snapshot de 12 tablas queda estable tras lectura/generación.

## Gráficas y ausencias justificadas

- Finanzas no incluye gráfica por contrato: balance y comparación exacta en tabla ejecutiva.
- Análisis y Tickets usan gráfica solo con al menos dos observaciones completas, alguna magnitud no nula y geometría legible. El anual de 366 días divide meses en lotes; el lote de meses sin ventas hace que toda la serie se muestre en tabla para conservar ceros, sin una imagen vacía. Análisis también se generó con 14 tramos semanales y ambas agrupaciones fueron legibles.
- Reservaciones usa tabla para la distribución corta de cinco estados; la serie diaria/semanal se grafica si tiene datos. Periodos vacíos o completamente futuros mantienen la serie de ceros en tabla, no inventan barras ni tasas.
- Inventario sí presenta la gráfica de asociaciones activas y bajo mínimo por almacén, aunque el periodo de movimientos no tenga actividad: esa gráfica representa la foto actual. La sección de tipos de movimiento permanece como tabla y dice “Sin actividad en el periodo”.
- En periodo sin ventas, Finanzas puede conservar gastos actuales prorrateados. En periodo sin movimientos, Inventario conserva la foto actual. “Sin actividad” no implica que todos los indicadores deban ser cero.

Se renderizaron e inspeccionaron las páginas de los cinco informes principales y de las 22 variantes generadas por `graficas-representativas.php` (normal, vacío, 366 días, cortes parciales, futuro y 14 tramos). Salida A4, encabezados, tablas, gráficos, cortes, notas y folios legibles; no se observó recorte ni solapamiento. El copy de Inventario usa ahora singular/plural concordante: “Hay 1 movimiento…” y “Hay N movimientos…”. Cambio de presentación únicamente; no altera los agregados.

Los cinco PDFs con corte parcial usan el reloj inyectado `2004-01-04 12:00:00` para ejercitar el estado y la presentación. La fixture también contiene hechos del 5 al 7 de enero y las consultas cubren el rango completo hasta el 7: este ensayo **no** simula una reconstrucción point-in-time ni filtra hechos posteriores al reloj. Los avisos distinguen registros futuros de observaciones concluidas; sus totales del rango no deben leerse como una reproducción de lo conocido en la fecha simulada. En Finanzas, el renderizador compartido deriva el aviso provisional y agrega la nota de gastos de todo el rango aunque el servicio no exponga un booleano. Inventario es una foto actual y no presenta estado provisional por su rango de movimientos.

## Pruebas ejecutadas

- `php scripts/tests/informes-aceptacion/run.php`: **PASS** completo; fixture base, cinco servicios, 13 verificaciones de consistencia/solo lectura, 80 de IA de reportes, 22 PDFs representativos y 105 casos HTTP (21 por ruta). El runner falló con código no cero ante un error y solo limpió la base temporal al comprobar propiedad. El log final registra limpieza confirmada.
- `npm run test:informes`: prueba de fundación del generador, rangos y gráficas.
- `php -l` para los scripts de aceptación modificados.

No se ejecutaron `npm run test:php` ni `npm run test:js`: no se modificó aplicación ni frontend; se revalidó la suite de informes afectada en aislamiento. No se tocaron instalaciones operativas ni los datos locales de desarrollo.

## Limitaciones y puntos para reconciliación

1. Las consultas de las pruebas agregan todo el rango solicitado, incluso si el reloj de corte es anterior a su fecha final. El corte etiqueta qué tramos se consideran observados; no reconstruye qué datos estaban presentes en ese momento.
2. Inventario no tiene saldo histórico: solo puede informar foto actual junto con movimientos del periodo. No debe describirse como “existencias al fin del periodo” ni etiquetarse como provisional por ese rango.
3. **Limitación de la primera auditoría (baseline 972d5df8):** la fixture de proveedores/documentos no se utilizó y `development.sql` no se importó en aquella ejecución. El complemento posterior sí importa el seed actual en una DB temporal y ejecuta la suite M5; los cinco informes continúan excluyendo proveedores por contrato.
4. Una prueba local pasa contra MySQL de prueba; no certifica MariaDB, proveedores, SMTP/Meta, credenciales externas, ni operación de producción.

## Repetir y conservar evidencia

Usar una cuenta de servidor MySQL **de prueba**, con privilegios para crear/borrar bases temporales y para el instalador; asignar explícitamente `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER`, `TEST_DB_PASS`. No pasar `DB_NAME` ni reutilizar una base de aplicación. Preparar una carpeta nueva y vacía fuera del repositorio, por ejemplo `$env:TEMP\cp-pdf-evidence`.

```powershell
$env:TEST_DB_HOST = '127.0.0.1'
$env:TEST_DB_PORT = '3306'
$env:TEST_DB_USER = '<cuenta de prueba>'
$env:TEST_DB_PASS = '<secreto local>'
$env:PDF_TEST_EVIDENCE_DIR = "$env:TEMP\cp-pdf-evidence"
New-Item -ItemType Directory -Path $env:PDF_TEST_EVIDENCE_DIR
php scripts/tests/informes-aceptacion/run.php
```

El runner rechaza carpetas de evidencia dentro del repositorio o no vacías. Conserva PDFs, tres documentos JSON de fixture con datos sintéticos, las cinco salidas SQL y el log; nunca copia `fixture.json`, dueño, contraseñas aleatorias ni configuración. La base temporal de nombre aleatorio se elimina después de verificar su token propietario. Para inspección visual, renderizar PDFs con Poppler `pdftoppm -png -r 100 archivo.pdf salida` y revisar cada página.

## Dictamen de esta etapa

**Dictamen inicial (baseline 972d5df8): aprobada para auditoría de datos y cobertura local.** En la revisión complementaria de `5d24701a` se corrigió el copy singular/plural de Inventario (“Hay 1 movimiento…”); ya no queda como observación pendiente. No se certifican datos de producción, servicios externos o reconstrucción point-in-time. La salida queda en `codex/auditoria-informes-pdf`; no se integró con `main`.

## Complemento: main actual `5d24701a` y compatibilidad M5

**Referencia complementaria:** `main` remoto `5d24701a65697476ff4966188d9aad4c157dab17`, comparado con la base auditada `972d5df8972c22e658cb487ee9b8dec8022cad3e`. El commit se obtuvo explícitamente sin mover `main`; la validación se ejecutó en worktrees temporales separados. `git diff --numstat 972d5df8 5d24701a -- database/ddl.sql database/development.sql` da `ddl.sql 65/1`, `development.sql 22/8`: 87 adiciones y 9 eliminaciones, 96 líneas modificadas en total.

El DDL nuevo agrega M5 `entradas_proveedor`, cuya PK es `operacion_id` y cuyas FKs compuestas vinculan operación y proveedor al mismo ámbito; así solo existe una atribución por operación. También incorpora partes de M3 (tipo de venta/contenido de pieza) y M4 (faltantes, transferencias tipadas y `CHECK(permitir_negativos=0)`). El seed de desarrollo añade tres vínculos de proveedor para entradas iniciales y convierte el ejemplo de saldo negativo de M4 en saldo cero más un faltante pendiente de 1.125. No hay `run-proveedores.php` en `972d5df8`; sí existe en `5d24701a`, donde su suite cubre el módulo M5.

### Suficiencia observada del seed actual

Se instaló exclusivamente el esquema y `development.sql` de `5d24701a` en una base aleatoria temporal con token de propiedad, se ejecutaron las consultas con filtro explícito `unidades.slug='restaurante'` y el runner eliminó la base tras comprobar el token. No se abrió la base nombrada por la configuración local. Resultados del ámbito Restaurante al 2026-11-13: Finanzas, 3,525 tickets cerrados, 13,118 líneas, ventas `$5,663,705.00` y COGS `$8.00`, del 2025-11-01 al 2026-11-13; 13,117 líneas no tienen costo conocido. Análisis, las mismas 13,118 líneas y ventas, 26,187 piezas, en las mismas fechas. Reservaciones: completadas 8 (25 personas, 6–12 nov), confirmadas 5 (36 personas, 16–22 nov), en curso 1, cancelada 1 y no-show 1; el seed también tiene una pendiente de verificación, una expirada y una reemplazada, que no son estados de negocio finales. Inventario: 20 relaciones activas, 13 bajo mínimo, 3 almacenes activos, 27 movimientos del 12–13 nov y 1 faltante activo; una entrada está vinculada a proveedor. Tickets registra 3,525 cierres y `$5,663,705.00`, igual a las ventas de Finanzas. Estos datos del ámbito Restaurante bastan para una demo representativa de cinco informes y aportan variación por fecha/estado, alertas, movimientos y faltante; los periodos vacíos, largos e históricos comparativos siguen cubiertos por las fixtures sintéticas de aceptación. El seed de Restaurante es reciente y no sustituye escenarios sintéticos de amplitud temporal histórica. La primera consulta global se descartó para juzgar suficiencia y el log corregido, con todos los predicados de ámbito, es `C:\\Users\\leona\\.codex\\visualizations\\2026\\11\\13\\01a1d3a0-2f83-7bf0-9858-b535f6e058cd\\informes-pdf\\main-seed-restaurante-sufficiency.log`.

### Compatibilidad de aceptación y contrato M4

La primera ejecución del runner de `branch-leo` contra el DDL actual falló al insertar `permitir_negativos=1`, rechazado por la nueva restricción M4. Se adaptaron únicamente los fixtures en `scripts/tests/informes-aceptacion/`: almacenes usan `permitir_negativos=0`; los casos conservan saldos cero, fraccionarios, bajo mínimo, movimientos y costo desconocido. Ya no se persiste saldo negativo porque el contrato M4 lo prohíbe; el faltante es la representación vigente. No se alteraron cálculos de negocio. En worktree con `ddl.sql`/`development.sql` de `5d24701a` y servicios/pruebas de `branch-leo`, la aceptación completa pasó: consultas SQL origen FIN/ANA/RES/INV/TIC, 13 verificaciones de consistencia, 80 verificaciones IA, 105 casos HTTP y 22 PDFs representativos. La salida íntegra se conserva en `C:\Users\leona\.codex\visualizations\2026\11\13\01a1d3a0-2f83-7bf0-9858-b535f6e058cd\informes-pdf\schema-compat-output.log`.

### Riesgo M5 y corrección de copy

`FinanzasNegocio` también consulta la asociación existente `ingrediente_proveedores` en `inventarioValor()` para estimar el costo preferente que se muestra en el resumen general. Esa ruta no equivale a la nueva relación de recepciones M5 `entradas_proveedor`: las métricas PDF de Finanzas (ventas, COGS congelado en partidas, mermas y gastos) no usan ese vínculo, y `informe()` no expone la valuación `inventarioValor()` como KPI. El PDF de Inventario agrega movimientos y toma foto de `almacen_articulos`; tampoco une `entradas_proveedor`. Una unión futura 1:N podría duplicar movimientos, pero el vínculo M5 actual tiene PK en `operacion_id` (1:1) y FKs de ámbito; la prueba antes/después confirma que la atribución sola no altera las métricas auditadas. La suite nueva `php scripts/tests/run-proveedores.php` pasó 81 comprobaciones y limpió su DB temporal propia. Adicionalmente, en la DB temporal con seed actual se creó en una transacción una operación y un vínculo de proveedor sintéticos, sin crear movimientos; consultas antes/después de ventas/COGS, merma y alertas de Inventario en Restaurante fueron idénticas (`tickets 3525`, ventas `$5,663,705.00`, COGS `$8.00`; merma 1, valor `$0.15`; 20 relaciones, 13 alertas). Se revirtió la transacción; no quedó fixture ni hecho adicional.

Se corrigió en `services/Inventory/ConsultasAlmacen.php` la concordancia “1 movimiento”/“N movimientos”, sin tocar cifras. La suite de informe también afirma el copy singular para una merma.

### Dictamen complementario

**Dictamen complementario vigente: aprobada la compatibilidad de datos/esquema para la etapa previa de auditoría.** El seed actual es suficiente para una demo representativa de cinco informes; el acceptance sintético sigue siendo necesario para variación temporal y vacíos. Se evidencia compatibilidad branch-leo con M4 tras alinear fixtures al contrato vigente, no mediante cambio del negocio. M5 no altera los agregados auditados. No se certifica producción ni reconstrucción point-in-time; Inventario conserva faltantes como dominio M4 en consultas generales, mientras que el PDF de rama auditada informa la foto y movimientos contractuales existentes. Las worktrees fueron `tmp/codex-main-schema-audit` (overlay temporal de schema + acceptance) y `tmp/codex-main-m5-audit` (snapshot limpio de `5d24701a`); ninguna movió `main` ni modificó la DB operativa.




