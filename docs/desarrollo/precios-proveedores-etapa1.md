# Etapa 1: historial de precios de proveedores

## Alcance entregado

Se añadió una bitácora append-only de precios de referencia por asociación proveedor-artículo, incluyendo unidad y snapshots legibles del proveedor, artículo y responsable. Las asociaciones preexistentes se observan hoy como `base_observada` en su primer guardado; no se infiere la fecha real de la oferta original. Altas nuevas registran `inicial`; modificaciones posteriores registran `cambio`. Reasignaciones comparan contra el último precio persistido y las operaciones se serializan por proveedor dentro de transacción. El historial sobrevive al retiro de una asociación y protege el borrado físico.

La ficha separa Artículos, Entradas y Datos con vistas URL selectivas: solo consulta y renderiza el contenido elegido. Artículos es lectura por defecto; editar asociaciones requiere una acción explícita. El historial de precio se abre en una vista independiente y de solo lectura por proveedor-artículo, con tabla paginada de 25 filas y gráfica de hasta 40 eventos comparables. Los costos unitarios de entradas permanecen congelados; el historial de referencia conserva la unidad observada y los cambios de unidad abren una serie nueva. El ámbito Global conserva lectura cruzada, sin habilitar escrituras fuera de la unidad autorizada.

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

## Ajuste de acceso, series y gráfica

El listado de asociaciones ofrece `Ver histórico` tanto en modo edición como consulta.
La selección muestra proveedor, artículo, unidad actual y precio de referencia; se
puede abrir el historial de artículos retirados desde su selector. La tabla es de solo
lectura, conserva el artículo al paginar y presenta 25 eventos por página. La gráfica
usa fechas en X y precio en Y, consulta hasta 41 eventos comparables para representar
los últimos 40 y advertir truncamiento. No conecta tramos de unidades distintas ni
atraviesa una `base_observada`; sin tres eventos del tramo vigente muestra el motivo.

Las semillas viven en `database/development.sql` y utilizan los tres pares ya
presentes en ese archivo: Restaurante 45 eventos, Panadería 10 y Global 5. Los
snapshots declaran que son sintéticos, terminan en el costo de referencia ya sembrado
y no modifican costos de movimientos ni artículos. Para una instalación development
/test preexistente, el SQL independiente exige confirmación explícita, marcador de
instalación completada y el marcador de migración `etapa1-precios-proveedores`
(M5 de historial por sí solo no basta), además de coincidencia de pares, snapshots y costos; no
permite reejecución ni agrega series si ya hay historia en los pares objetivo. Correr
en una conexión dedicada con abort-on-error y sin `--force`; ante fallo de guardia,
hacer `ROLLBACK` y cerrar esa conexión. No se ejecutó en la base configurada de la app.

### Verificaciones y evidencia de este ajuste

`docs/desarrollo/evidencia-etapa1-ajuste/` conserva resultados y captura. El runner M5
pasó 146 comprobaciones en su base temporal, que eliminó al terminar
(`m5-chart-attempt2.log`). `npm run test:php` (`npm-test-php.log`), `npm run test:js`
(`npm-test-js.log`) y `npx gulp adminModuleCss` (`gulp-admin-module-css.log`) pasaron.
La captura y su salida (`precios-proveedor-*.png` y `ui-capture.log`) usan el JSON
extraído por M5 desde la base temporal antes de eliminarla; el fixture solo renderiza
la vista y no escribe a la base. Playwright comprobó enlace en edición/consulta,
contexto, query de paginación preservando `historial_articulo`, ancho 390/390 px,
scroll interno de tabla de 620 px, consulta sin formularios de escritura y cero errores
de página. La paginación HTTP no se navegó en el fixture; se verificó el `href`.

Comandos ejecutados para la evidencia:

```powershell
php scripts/tests/run-proveedores.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
npm run test:php
npm run test:js
npx gulp adminModuleCss
php docs/desarrollo/evidencia-etapa1-ajuste/render-ui-fixture.php
php -S 127.0.0.1:8097 -t public
node docs/desarrollo/evidencia-etapa1-ajuste/capture-ui.cjs
```

La segunda compilación global no se repitió: su intento anterior produjo errores de
copia en una fuente no relacionada; se compiló únicamente el CSS modular y se
restauraron los artefactos ajenos. El supervisor aceptó localmente el ajuste de
persistencia, UI y suite M5. La migración no se aplicó a la base de la aplicación; no
se hizo push ni se modificó `main`.

## Simplificación de la ficha y evidencia HTTP

La ficha presenta una sola vista por solicitud: `?vista=articulos`, `?vista=entradas`
o `?vista=datos`; omitir `vista` abre Artículos. Los controles de asociación requieren
`editar=1`; los artículos retirados son una consulta secundaria. El historial usa
`/admin/almacenes/proveedores/historial?id=...&articulo=...&pagina=...`, valida que el
par pertenezca al proveedor y no ofrece controles administrativos. Su tabla limita la
consulta a 25 eventos y mantiene los filtros al paginar. El resumen de retiro usa
consultas pequeñas, sin cargar el historial completo.

La evidencia final vive en `docs/desarrollo/evidencia-etapa1-simplificacion/`:

- `m5-final.log`: 151 comprobaciones; base temporal propia eliminada.
- `npm-test-php.log` y `npm-test-js.log`: ambas suites terminaron con código 0.
- `proveedores-http.log`: 23 comprobaciones HTTP, paginación y regreso reales,
  servidor loopback detenido y base temporal propia eliminada.
- `ui-capture.log` y PNG: capturas desde las vistas HTTP con datos fixture aislados;
  viewport/documento móvil 390/390 px, tablas con scroll interno, sin errores de
  página. Incluye Artículos, Entradas, Datos e historial.
- `gulp-admin-module-css.log`: compilación CSS modular de inventario aprobada.

La selectividad de consultas se comprobó por contrato del controlador y DOM de cada
vista; no se instrumentaron consultas SQL en runtime. Los tests de servicio cubren
permisos, ámbito y el par ajeno. La migración no se aplicó a la base configurada de la
aplicación; todas las pruebas HTTP/DB usaron bases temporales propias. No se modificó
`main` ni se hizo push.

Comandos reproducibles:

```powershell
php scripts/tests/run-proveedores.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-proveedores-http.php
npm run test:php
npm run test:js
npx gulp adminModuleCss
```

## Ajustes finales de historial y enlaces PDF

La bitácora mantiene las mismas consultas y límites. El gráfico interactivo muestra
hasta 40 puntos con fecha, precio decimal, unidad y variación comparable exactos;
teclado (flechas, Inicio/Fin, Enter/Espacio, Escape), mouse y toque actualizan un
tooltip accesible. La navegación Artículos/Entradas/Datos continúa con enlaces URL y
resalta la selección por `aria-current`. El resumen del historial deja visible solo
el precio ofrecido vigente; los costos reales de entradas siguen siendo independientes.
La comparación dejó de estar abierta en cada visita: aparece solo como acción
secundaria si hay otra oferta del mismo artículo/ámbito y excluye al proveedor actual.

El 404 de `/admin/finanzas/informe.pdf` se reprodujo con `php -S localhost:3000`
ejecutado desde `public/` sin router: el servidor integrado devolvió su HTML 404 antes
de cargar PHP; el alias `/admin/finanzas/informe` sí alcanzó la aplicación. Se
registraron cinco aliases sin extensión con los mismos handlers que las rutas `.pdf`
existentes. Los links de módulos e IA ahora usan el alias. Para desarrollo, el
comando recomendado es `php -S localhost:3000 ../scripts/dev-router.php` desde
`public/`, o `php -S localhost:3000 -t public scripts/dev-router.php` desde la raíz.
No se tocó configuración Apache. El diagnóstico y la reproducción constan en
`docs/desarrollo/informes-404-php-dev-server.md`.

Evidencia final en `docs/desarrollo/evidencia-etapa1-final/`:

- `m5-chart-final.log`: 157 comprobaciones en una base temporal propia, eliminada.
- `proveedores-http-final.log`: 27 verificaciones HTTP, incluyendo permisos, ámbitos,
  paginación real, asociación retirada, comparación explícita y cleanup.
- `ui-capture.log` y capturas: 40 puntos visibles, hover, mouse click, teclado y toque;
  navegación por 3 vistas, historial, paginación, 390 px sin overflow y scroll interno.
- `informes-matrix-final.log` y `informes-acceptance-final.log`: cinco informes por
  ruta alias y `.pdf` original, más alias sin router; 39 verificaciones HTTP por
  informe (195 en conjunto), MIME PDF, `%PDF-`, permisos, rangos, href, 404 del
  servidor frente al 404 de la app. `*-http-alias.pdf`, `*-http-legacy.pdf` y
  `*-http-bare-alias.pdf` son PDFs sintéticos; el log registra SHA-256/MIME.
- `npm-test-php.log`, `npm-test-js.log` y `build-modulos-final.log`: regresiones
  completas y compilación modular de Inventario aprobadas.

Las suites PDF y Proveedores instalaron y eliminaron sus bases de prueba; no se cambió
la base de la aplicación, semillas SQL, ni costos de otros módulos. La migración de
precios no se aplicó a la base configurada. No se añadió Redis. Ninguna ruta `.pdf`
original se eliminó.
