# Etapa 1: historial de precios de proveedores

## Alcance entregado

Se añadió una bitácora append-only de precios de referencia por asociación proveedor-artículo, incluyendo unidad y snapshots legibles del proveedor, artículo y responsable. Las asociaciones preexistentes se observan hoy como `base_observada` en su primer guardado; no se infiere la fecha real de la oferta original. Altas nuevas registran `inicial`; modificaciones posteriores registran `cambio`. Reasignaciones comparan contra el último precio persistido y las operaciones se serializan por proveedor dentro de transacción. El historial sobrevive al retiro de una asociación y protege el borrado físico.

La ficha presenta historial paginado, comparación en el ámbito consultado y gráfica sólo cuando hay tres o más eventos compatibles por unidad. Los costos unitarios de entradas permanecen congelados; el historial de referencia conserva la unidad observada y los cambios de unidad abren una serie nueva. El ámbito Global conserva lectura cruzada, sin habilitar escrituras fuera de la unidad autorizada.

## Despliegue de esquema

Aplicar `database/actualizaciones/etapa1-precios-proveedores.sql` después de M5 y antes de habilitar la nueva ficha. El DDL también está reflejado en `database/ddl.sql`. La migración tiene marcador de versión, se puede repetir y no hace backfill. La migración **no se aplicó a la base configurada de la aplicación**; las suites DB usaron bases temporales propias, eliminadas al cerrar cada runner.

## Evidencia y verificaciones

Los resultados completos están en `docs/desarrollo/evidencia-etapa1/`:

- `m5-final.log`: 121 comprobaciones; traza sintética sin precios reales en `traza-precios-sintetica.json`.
- `npm-test-php-final.log` y `npm-test-js.log`: suites PHP y JS aprobadas.
- `almacenes-3c-final.log` (159), `almacenes-3c-concurrencia.log` (17), `pos-variantes-consumo.log` (21), `pos-caja-panaderia.log` (77) e `informes-aceptacion-db-final.log` (aceptación completa, 105 casos HTTP).
- `precios-proveedor-desktop.png`, `precios-proveedor-mobile.png` y `precios-proveedor-mobile-asignaciones.png`: captura con la vista real y datos sintéticos. La comprobación Playwright registró viewport móvil 390 px, ancho de documento 390 px, cero errores de página y scroll interno de tabla de precios de 472 px; resultado en `ui-capture.log`.
- `gulp-admin-module-css.log`: compilación final del CSS administrativo modular aprobada.

Comandos ejecutados (las suites DB crean y eliminan sus propias bases temporales):

```powershell
npm run test:php
npm run test:js
php scripts/tests/run-proveedores.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-almacenes-3c.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-almacenes-3c-concurrencia.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-variantes-consumo.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-caja-panaderia-db.php
npm run test:informes:db
npm run build
npx gulp adminModuleCss
```

Los runners de integración recibieron configuración local a través de su wrapper de entorno y apuntaron a bases de prueba aisladas. No se escribieron credenciales en los logs. La primera ejecución de `npm run build` completó correctamente; al repetirlo después del último ajuste visual, Gulp compiló el CSS y falló al copiar la fuente `public/build/fonts/KudosKapsOneNF.otf` por un error de apertura `UNKNOWN` del filesystem. Para verificar el CSS final se ejecutó `npx gulp adminModuleCss`, que aprobó; se conservó sólo `public/build/css/admin/inventario.css` y se restauraron los demás artefactos regenerados ajenos. La invocación directa `npm run test:informes:db` requiere `TEST_DB_*`; el log inicial documenta que se detuvo al validar `TEST_DB_HOST`, antes de crear una base. La ejecución final aprobada usó variables cargadas en memoria y la suite creó y eliminó su propia base temporal.

## Arquitectura

Decisiones y relaciones de datos: `docs/arquitectura.md`, sección 17.1. Arquitectura, persistencia, UI y pruebas fueron revisadas y aceptadas localmente por el supervisor. No se envió push y no se modificó `main`.
