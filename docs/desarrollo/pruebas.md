# Pruebas y criterios de aceptación

El [ensayo de rendimiento D3](rendimiento.md) documenta su reproducción, mediciones
y límites. En el cierre D3 se ejecutaron `node scripts/tests/run-reservaciones-notification-docs.cjs`
y `npm run test:menu-preservacion`; son comprobaciones documentales y de
preservación de fixtures, no certifican POS/comanda completa, restauración,
instalación ni la suite financiera. Esta última repitió el fallo de gasto fijo
de Panadería en base y actual, sin causa identificada; no se declara aprobada.

El [contrato rector](../arquitectura.md) fija la aceptación. Ejecutar las suites
relacionadas con el cambio, registrar sus resultados y no presentar evidencia de
una entrega anterior como resultado actual. Las revisiones A4, M2 y A6, sus
resultados y capturas se recuperan en la historia de Git.

## Integración de `refactor-almacenes` con `main`

12 de noviembre de 2026. Criterio: en Almacenes, Proveedores y Recetas manda la
rama; en lo demás, `main`. La caja de Panadería se ajustó al contrato sin
negativos (§16): la vitrina en cero vende, deja la pieza en cero y registra el
faltante (`VITRINA_FALTANTE`); el fixture ya no activa negativos. Se compararon
el resultado fusionado y `main` sin fusionar, cada uno en su worktree, con bases
temporales propias y la base del `.env` sólo como confirmación de argumentos.

| Comprobación | Fusión | `main` |
|---|---|---|
| `npm run test:php` y `npm run test:js`, paso por paso | 74 de 74 PASS | 72 de 72 PASS |
| Caja de Panadería / `run-caja-panaderia-db.php` | PASS: 77 (faltante y sin negativos) | PASS: 75 |
| Faltantes, vista de faltantes, tipo de venta, Proveedores | PASS: 68, 35, 49 y 81 | No existen |
| M2, Transferencias, A4, A3, A1, preservación de menú | PASS: 130, 138, 2510, 59, 15 y 7 | Iguales |
| `npm run test:notifications` | PASS | PASS |
| `run-instalacion-3e.php` | Falla en la comprobación 304 | Igual |
| `run-contextos-isolated.php` | Falla: columna `rol` inexistente | Igual |

`run-instalacion-3e.php` falla en ambos porque `pruebas-datos-3e.php` espera 47
asociaciones y treinta artículos fijos, y el fixture de la caja agregó 17 piezas;
no certifica la instalación completa. La carga `ddl → deploy → menu-restaurante →
development` sí la ejercitan las suites con base anteriores. No hubo recorrido en
navegador de la caja ni del Menú tras la fusión.

## Resultados R2 de la integración

Sin BD: `npm run test:php`, `npm run test:js` (13 perfiles,
75 variantes y 25 avisos) y `npm run test:menu-preservacion` (34) **PASS**.
La comprobación documental final verifica 263 enlaces locales, **PASS**.
El lint PHP inicial cubrió 93 archivos y el final los cuatro PHP modificados
posteriormente, ambos **PASS**. `npm run build` **PASS** en 5,69 s: JS/CSS iguales a
main; se restauraron diez mapas cuya única diferencia era CRLF.

En MySQL 9.7.1 privado, puerto 33078 y datadir nuevo: caja 75, A4 2474, M2 130,
Análisis 33, consultas 491 y Finanzas 49 comprobaciones **PASS**; notificaciones
16/16 en cada una de dos ejecuciones, cierre POS y reubicación también **PASS**.
La variante `--migrations` omitió scripts históricos retirados; no certifica su
reaplicación. Las fixtures de caja se alinearon con A1/A3 y zonas horarias y la
de reubicación con el reloj de prueba. Se reforzaron token de propiedad y puerto
DB, y se actualizaron perfiles y listas documentales sin rebajar aserciones.

Las mediciones, el fallo financiero preexistente y el límite de `FOR SHARE` en
MariaDB se detallan en [rendimiento](rendimiento.md#resultados-r2). Los servidores
temporales cerraron, sin bases fixture restantes ni uso de la base operativa.

## M5: proveedores por ámbito

Rama `refactor-almacenes`, 6 de noviembre de 2026 (arquitectura §17). Las suites con
base usaron bases temporales propias y las eliminaron. Para el recorrido en navegador
se aplicó `m5-proveedores.sql` (aditiva) a la base local de desarrollo, con
autorización, y se crearon ahí datos de prueba.

| Comprobación | Resultado M5 |
|---|---|
| `npm run test:php` | PASS |
| `npm run test:js` | Se detiene en `run-reservaciones-notification-docs.cjs`, que ya fallaba: no espera `docs/API.md` ni `docs/integracion-a6-main.md`, ajenos a M5. Los pasos posteriores de Almacenes y Artículos se corrieron por separado |
| Proveedores / `run-proveedores.php` | PASS: 81 comprobaciones (directorio propio y consolidado de Global, IDs ajenos, sólo consulta, alta y validación, asignaciones con un preferente por artículo, entrada ligada atómica con rollback, costo obligatorio, ajeno, inactivo, reintento y conflicto de idempotencia, histórico exacto con intervalo y evolución de costo, retiro con y sin historial) |
| Faltantes, vista de faltantes, tipo de venta | PASS: 68, 18 y 49 comprobaciones |
| Artículos M2 y resolución M2 | PASS: 130 y 29 comprobaciones |
| UI de Almacenes / `run-almacenes-ux.php` y JS de Almacenes y Artículos | PASS: 148 comprobaciones de representación; cinco suites JS |
| `run-estandares-estructura-m12.php` | FALLA también en `HEAD` (6faa9b6), sin estos cambios: espera 51 movimientos de fixture y M3 añadió más |
| Motor 3C / `run-almacenes-3c*.php` | No ejecutado: sólo aceptan `--base casa-pestalozzi` en `127.0.0.1` y la configuración local usa otra base. El motor no cambió |
| Navegador (Chrome, escritorio) | Global: menú, directorio, consolidado, alta, asignación, entrada con proveedor confirmada y ficha con histórico. Restaurante: ruta anterior redirige. Panadería: un ID de Restaurante responde «No se encontró el proveedor» |

## Cierre M3: refactor de Almacenes

Rama `refactor-almacenes`, 4 de noviembre de 2026 (arquitectura §16). Las suites
con base usaron bases temporales propias y las eliminaron; la base local
configurada no se migró.

| Comprobación | Resultado M3 |
|---|---|
| `npm run test:php` | PASS |
| `npm run test:js` | 21 de 22 pasos PASS. Falla `run-reservaciones-notification-docs.cjs` también en la línea base: el commit «Plan» movió `API.md` a `docs/` |
| Tipo de venta / `run-tipo-venta.php` | PASS: 49 comprobaciones (sugeridor, 112 en sugerido, reventa, variantes de precio, copa y botella en ml, piezas, lote, IDs ajenos) |
| Faltantes / `run-faltantes.php` | PASS: 68 comprobaciones (faltante parcial, replay, reparto, Global confirma y rechaza, saldo total insuficiente y entrada posterior, producción previa, cancelación, versión, migración de negativos, costo congelado) |
| Vista ↔ servicio / `run-faltantes-vista-db.php` | PASS: 18 comprobaciones; vista real con datos reales y envío de sus formularios |
| UI de Menú / `run-menu-tipo-venta-ui.cjs` | PASS |
| UI de Almacenes / `run-almacenes-ux.php` y `run-almacenes-m11-ui.cjs` | PASS: 56 comprobaciones de representación; piezas y reparto exacto |
| Motor 3C / `run-almacenes-3c.php` y concurrencia | PASS: 158 y 17 comprobaciones |
| Instalación 3E / `run-instalacion-3e.php` | PASS: 967 comprobaciones |
| M2, A4, A3, Transferencias, selección, resolución M2 | PASS: 130, 2,270, 59, 114, 70 y 29 comprobaciones |

Límites: `run-multinegocio-3a.php` y las suites 3D exigen `--paquete` externo y no
se ejecutaron (sus aserciones de negativos se actualizaron al contrato nuevo). El
conflicto de versión entre dos administradores se probó con versión vieja
secuencial, no con dos procesos. No hubo recorrido HTTP con sesión ni lector de
pantalla real; la interfaz se revisó en navegador con arneses y datos simulados.
`run-menu-m11.php`, `run-variantes-consumo.php` y `run-ux2.php` fallan igual en la
línea base.

## Cierre A4

La regresión exige retiro de rutas y escritores comerciales Global, permisos e
independencia local, menú público y administrativo, PDF, POS, recetas y
presentaciones heredadas, variantes, consumo, cancelaciones y snapshots.
`npm run test:menu:a4:db` usa una base temporal propia; la UI está incluida en
`npm run test:js`. Verificar requisitos y destino antes de ejecutar pruebas con BD.

## Cierre M2

`npm run test:articulos:m2` cubre resolución y UI; `npm run test:articulos:m2:db`
y `npm run test:articulos:m2:http` requieren su entorno aislado. Comprobar
versiones inmutables, composición anidada, fronteras de artículo almacenado,
variantes, precisión, permisos y revocación, IDs ajenos, concurrencia, rollback,
idempotencia y conservación de snapshots y movimientos. La cancelación compensa
el consumo original una sola vez; vender no abre una botella ni produce stock.
La revisión visual abarca escritorio, tableta y móvil, teclado y estados de error.
M2 no habilita producción física ni la integración de presentaciones a la caja
de Panadería; la caja de mostrador ya opera.

## Suites generales

M0.5-B añade [fixtures ampliadas de Almacenes](fixtures-almacenes.md) y el
[contrato de transferencias](../modulos/transferencias.md). Los runners aislados
`run-m05b-fixtures.php` y `run-transferencias-m05b.php` exigen los mismos argumentos
de origen; no migran la base activa. El segundo verifica cantidades exactas,
permisos cruzados, versiones, compensación, rollback y carreras entre dos procesos.
`pruebas-http-transferencias-m05b.php --fixture <archivo-privado>` verifica el
flujo real con CSRF y segundo factor sobre el harness 3E. El cliente HTTP común
vive en `scripts/tests/cliente-http-3e.php`; las aserciones 3E anteriores se conservan.

M0 añade los contratos puros y dos runners DB con destino propio. Identificar
host/base/entorno según la configuración local; nunca apuntar DDL a la base activa:

```sh
composer validate
npm run build
npm run test:menu-preservacion
php scripts/tests/run-dos-factores.php
php scripts/tests/run-jwt-integraciones.php
php scripts/tests/run-corte-turnos.php
php scripts/tests/run-dos-factores-3b.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-m0-integracion-db.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-instalacion-3e.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-notifications-isolated.php run-punto-venta-cierre-db.php
```

El runner 2FA hidrata usuarios reales del DDL 3B: superadministrador, permisos
directos y cuenta operativa; comprueba contraseña incorrecta, reto sin sesión,
cuenta sin correo y revocación/desactivación antes del código. El runner puro
mantiene vigencia, formato, intentos, reenvío y entornos cerrados por defecto.
El cliente HTTP 3E exige reto antes de autenticar, verifica el bloqueo del panel
durante ese reto y completa el código exclusivamente del entorno `test`.

La importación SQL directa carga los cuatro archivos versionados sin el marcador
del instalador, verifica `admin_demo`, tres ámbitos, datos y menú, y contrasta el
corte por negocio, tickets cerrados, propinas, pagos divididos y suma de ventanas.
La instalación alinea la zona horaria con la aplicación y usa últimos siete días
para el consumo del fixture de ayer, incluso un lunes; conserva sus aserciones.

Con dependencias y assets preparados según la [instalación](instalacion.md):

```sh
npm run test:php
npm run test:js
npm run test:notifications
```

`run-variantes-consumo.php` crea una base aislada con DDL/deploy y recorre una
variante que sustituye un artículo: venta rechazada sin almacén de consumo,
validación de asignaciones y permisos, descuento del artículo sustituto en el
almacén elegido y snapshot bajo el mismo producto. Se ejecuta con
`--host`, `--base` y `--entorno` iguales a `includes/.env`.

`run-usuarios-perfiles.cjs`, dentro de `test:js`, renderiza el formulario real de
Usuarios con PHP y lo ejercita con jsdom: perfiles, techo del actor, credencial
calculada, envío y detección de Personalizado. jsdom llega como dependencia
transitiva del lockfile; si se retira, la prueba falla al cargarlo.

Las pruebas de notificaciones crean y eliminan sus propias bases aisladas. La
variante `--migrations` puede informar que los scripts históricos están retirados
y comprobar el DDL vigente; ese mensaje no acredita que se hayan reaplicado esas
migraciones. No restaurar scripts retirados para simular una migración histórica.

```sh
php scripts/tests/run-notifications-isolated.php
php scripts/tests/run-notifications-isolated.php --migrations
```

## Esquema, permisos y motor

Los runners identifican el host, base configurada de origen y entorno, se conectan
sin seleccionar la base original y crean destinos desechables propios. Estos
argumentos no autorizan ejecutar DDL destructivo sobre la base indicada.

```sh
php scripts/tests/run-multinegocio-3b.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-almacenes-3c.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-almacenes-3c-concurrencia.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-usuarios-3e1.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
```

Los valores del ejemplo corresponden a la identificación admitida por esos
runners históricos de regresión. Su configuración de origen debe coincidir; no
cambiar sus validaciones para apuntar a una base real diferente. La prueba de
instalación 3E usa la configuración y los nombres nuevos descritos en la guía,
con su propia marca de propiedad.

## Caja de Panadería

```sh
npm run test:caja-panaderia   # php scripts/tests/run-caja-panaderia-db.php
```

El runner utiliza una base desechable propia (`cp_multinegocio_test_*`) con el
DDL y DML versionados y un secreto de NIP del proceso. Cubre permisos y rutas,
venta atómica e idempotente, vitrina, horneadas, anulación con NIP, tickets y
corte, revocación y preservación de Restaurante. Verificar aislamiento y destino
antes de ejecutarlo; detalle en [caja](../modulos/caja-panaderia.md#pruebas).

## Preservación y regresión comercial

```sh
npm run test:menu-preservacion
php scripts/tests/run-inventario-vista-3d.php
```

Los runners de integración 3A y 3D anteriores reciben `--paquete` como referencia
de integridad del ensayo histórico. Siguen disponibles para comparar la
preservación, pero **el paquete privado no es una dependencia de instalación**.
La aceptación 3E debe crear bases exclusivamente desde los DDL/DML/imágenes
versionados y verificar los mismos campos, correspondencias y huellas.

Ejecutar `run-multinegocio-3a.php`, `run-restaurante-3d1.php`,
`run-restaurante-3d2.php` y `run-restaurante-3d3.php` con sus argumentos explícitos
si se dispone de la referencia histórica. No sustituir una validación independiente
de instalación por la simple importación de ese paquete.

## Usuarios 3E

`pruebas-usuarios-3e1.php` se integra en una base de prueba del runner. Comprueba
CRUD y credenciales en los tres ámbitos, límites de delegación, IDs ajenos,
revocación local de una identidad compartida, estado de cuenta, protección del
superadministrador y equivalencia entre el catálogo del formulario y el servicio.
La suite 3B añade unión de accesos, PIN/contraseña, reautenticación, revocación y
último superadministrador activo.

## Instalación independiente

Realizar dos instalaciones nuevas mediante `scripts/desarrollo/instalar.php` y
repetir login, menú, Usuarios, Almacenes y Restaurante. Comprobar:

- Conteos y campos exactos de los 112 productos y ofertas originales, 15
  categorías, 3 cartas, 4 áreas y 13 imágenes, sin contar fixtures adicionales.
- Un solo `admin_demo`, hash seguro, autenticación por contraseña y acceso a los
  tres ámbitos; rechazo de bootstrap fuera de desarrollo/pruebas identificadas.
- Idempotencia controlada del bootstrap, rechazo de homónimo no creado por él y
  ausencia de restablecimiento implícito de una contraseña cambiada.
- Saldos iguales a la suma de movimientos, sin almacén virtual de consolidación.
- Entradas, ajustes y mermas atómicos; negativos por almacén; IDs ajenos
  rechazados; revocación y concurrencia.
- Menú, reservas, ticket, comanda, KDS, entrega, cobro, cancelación, PDFs,
  inventario, finanzas e informes existentes de Restaurante.

Las pruebas HTTP usan clientes con sesiones independientes y servidores loopback
sobre destinos identificados. `run-multinegocio-3b-http.php` verifica el fixture
3B; `run-restaurante-3d4-http.php` recibe un archivo privado de credenciales efímeras
del harness 3D. No versionar ese archivo ni registrar las credenciales.

## Navegador, responsive y evidencia

Probar las pantallas de Usuarios y Almacenes en los tres ámbitos con el mismo
layout de Restaurante: navegación, selector, lectura, formulario, errores,
confirmación, teclado, escritorio y móvil. No inferir la operatividad desde un
HTTP 200 que contiene errores fatales o desde una captura de un formulario vacío.

Registrar la revisión y el resultado junto con limitaciones concretas. Verificar
el flujo comercial en navegador real además de los servicios y los contratos
estáticos. Un test modificado conserva su intención; las contradicciones con el
modelo final se corrigen conjuntamente con la documentación y sus consumidores.

## HTTP 3E desde la instalación versionada

Con la contraseña de desarrollo en configuración local segura, iniciar:

```sh
php scripts/tests/servir-instalacion-3e.php --host 127.0.0.1 --base casa-pestalozzi --entorno development --fixture-salida <ruta-absoluta-privada-nueva-en-visualizations>
php scripts/tests/pruebas-http-3e.php --fixture <misma-ruta-privada>
```

El primer comando permanece abierto en loopback 8094. La salida privada contiene
credenciales efímeras: no mostrarla, versionarla ni compartirla. Enviar Enter por
STDIN al servidor al terminar: comprueba propiedad, detiene el proceso, elimina
su base temporal y retira sesiones y archivo privado. El router impide servir
scripts PHP arbitrarios o configuración privada. Se prueban lectura y escritura
autorizadas en los tres ámbitos, IDs ajenos, CSRF contextual, precisión,
idempotencia, revocación y ciclo de usuarios. No depende del paquete de Etapa 2.

## Comprobaciones externas

La simulación no certifica impresoras físicas, SMTP, Meta/WhatsApp, credenciales
n8n, entregas reales, reinicios o restauración. Registrar esas pruebas como no
realizadas cuando no haya evidencia. Un fallo crítico de aislamiento, integridad
o seguridad bloquea la candidatura a merge; no se etiqueta como mejora futura.

## Verificación documental

node scripts/tests/run-reservaciones-notification-docs.cjs valida estructura
de docs, contratos de transporte y enlaces locales con encabezados de las guías,
n8n, README y CLAUDE. Para cambios documentales comprobar también PRODUCT, API,
database/ANALITICAS y classes/Impresion/README: esos archivos quedan fuera de su
recorrido automático. Revisar enlaces internos y referencias a rutas retiradas.
No modificar pruebas para permitir una limpieza incompatible con sus consumidores.


## Menú y catálogo comercial M1

```sh
npm run test:menu:m1 -- --host 127.0.0.1 --base casa-pestalozzi --entorno development
npm run test:menu:m1-ui
```

El nombre del comando M1 se conserva por compatibilidad, pero ahora ejecuta la
suite A4 sobre su propia base temporal: edición local, aislamiento, permisos,
lectores, venta y preservación histórica. La UI A4 verifica rutas locales,
formularios simples y retiro del módulo; el recorrido HTTP M2 cubre rutas,
CSRF, permisos, artículos y presentaciones en el harness 3E. No se habilita un
escritor comercial Global.

### Cierre UX-5 anterior a A4

Las pruebas HTTP de publicación y personalización UX-5 se retiraron al eliminar
Productos de la casa. Sus recorridos oficiales no son parte del conjunto vigente;
la suite A4 las reemplaza con independencia local, rutas retiradas y preservación
de recetas, presentaciones, variantes y snapshots.
