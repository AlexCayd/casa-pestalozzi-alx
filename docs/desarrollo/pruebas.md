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


## Informes PDF: validación local

`npm run test:informes` comprueba la fundación sin base de datos. `npm run test:informes:db` ejecuta la aceptación aislada en un servidor MySQL compatible **de prueba**, configurado explícitamente mediante `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER` y `TEST_DB_PASS` (esta última puede estar vacía). No obtiene la conexión desde `.env` ni utiliza `DB_NAME` existente. La cuenta de prueba necesita privilegios para crear y eliminar bases temporales y para instalar el esquema, además de conexiones concurrentes. Se requieren PHP con mysqli, curl y DOM, Composer instalado y un puerto TCP local disponible.

El ejecutor crea una base de nombre aleatorio mediante `Instalacion`, comprueba su marca de propiedad antes de eliminarla y guarda los artefactos en un directorio temporal por ejecución. El servidor HTTP y su router de sesiones de prueba se inician solo para esta suite en loopback, fuera de `public/`. Un fallo o una prueba omitida detiene la suite con código distinto de cero. Si no se verifica la propiedad durante la limpieza, se conserva el directorio temporal para recuperación manual. La validación siguiente corresponde a una ejecución local aislada, no certifica el entorno de producción.


**Evidencia histórica del 7 de noviembre de 2026, anterior al diseño neutral del 12 de noviembre.** La aceptación independiente de esa fecha usó MySQL 9.7.1 y PHP 8.2.12 en un servidor de prueba, con bases temporales verificadas y eliminadas por propietario. **PASS** indica evidencia local; **FAIL_NUEVO** indica defecto introducido y **BLOQUEADO_ENTORNO** una condición del entorno. No se infiere compatibilidad con MariaDB ni comportamiento de producción.

| Criterio | Estado | Evidencia |
|---|---|---|
| 1. PDF válidos integrados | PASS | Cinco rutas integradas; acción única, etiqueta PDF, foco por teclado y rango aplicado; diez capturas a 1440×1000 y 390×844. |
| 2. Indicadores | PASS | Cotejo con autoridades de dominio y paridad FIN/ANA/TIC con diferencia ≤ 0.01, incluidos casos sin actividad. |
| 3. Rangos, filtros, permisos, aislamiento y fallos | PASS | 105 casos HTTP: fechas inválidas, límite de 366 días, filtros, NIP, ámbitos, revocación, manipulación y fuente ausente sin PDF parcial; el servidor de desarrollo también prueba rutas PDF, estáticos y rechazo de `.htaccess`. |
| 4. Solo lectura | PASS | Hash de 12 tablas estable y 13 comprobaciones de dos conexiones, incluida lectura consistente y rechazo de escritura y transacción externa. |
| 5. A4, uniformidad y paginación | PASS | Plantilla compartida y PDF multipágina revisados; encabezado de Inventario y nota final de Tickets corregidos y revalidados. |
| 6. Funcional, seguridad, rendimiento y regresión | PASS | Aceptación de cinco dominios, suites PHP y JS completas, regresiones aisladas y carga representativa descritas abajo. |
| 7. Arquitectura preparada sin activación futura | PASS | Generador compartido neutral y cinco rutas de Restaurante; Panadería y Global sin rutas ni botones de informe habilitados. |

| Informe | Estado | Evidencia funcional |
|---|---|---|
| Finanzas | PASS | Ventas, costos, propinas, cancelaciones y paridad del agregado. |
| Análisis | PASS | Totales, serie, productos más vendidos y paridad financiera. |
| Reservaciones | PASS | Estados, exclusiones técnicas, fecha programada y periodos provisionales. |
| Inventario | PASS | Foto actual frente a movimientos, múltiples almacenes, alertas y lectura sin cambios. |
| Tickets | PASS | Cerrados, cancelados, ventas y promedio; abiertos y propinas excluidos. |

Comandos reproducibles del repositorio: `npm run test:informes`, `npm run test:informes:db`, `npm run test:php` y `npm run test:js`. La ejecución independiente de aceptación pasó los cinco casos de dominio, los 13 de consistencia y los 105 HTTP; las bases temporales se limpiaron tras verificar su propiedad. La regresión final de PHP incluyó `php scripts/tests/run-roles-permisos.php` (106 comprobaciones); JS verificó 266 enlaces documentales. Las regresiones aisladas de dominio reportaron A4 2494, M2 130, Almacenes 158, Analytics 33, consultas 491 y Finanzas 49 comprobaciones. Se usaron sus scripts existentes y un servidor privado de prueba; el HTTP de M2 **no se ejecutó** en esta validación.

La primera ejecución de Analytics quedó **BLOQUEADA_ENTORNO** por una credencial efímera incompatible con el seed histórico; se repitió con una credencial legítima verificada, sin cambiar `.env` ni variables `DB_*`, y pasó. El **FAIL_NUEVO** de clasificación heredada de `/admin/almacenes/informe.pdf` se corrigió con una excepción exacta y se revalidó en permisos y HTTP. En el arnés portátil se corrigieron la exigencia de marcadores positivos y la limpieza de recursos HTTP ante fallos de arranque antes de su aceptación.

En el ensayo representativo de 366 días se cargaron 1000 tickets, 2000 partidas, 1000 reservas, 1000 movimientos, tres almacenes y 30 relaciones. Las ventas FIN/ANA/TIC fueron 17,470, sin diferencia, y el hash de 12 tablas permaneció igual. La preparación tardó 5.06–22.42 ms y el render 198.41–957.47 ms; el pico de memoria informado durante la ejecución fue 24–50 MB. Páginas: FIN 2, ANA 16, RES 16, INV 2 y TIC 15. Son mediciones de esta muestra local, no garantías de capacidad o latencia.

En aquella revisión histórica, el detector visual Impeccable emitió dos advertencias `overused-font` sobre Montserrat en la plantilla y salió con código 1. Son observaciones estéticas sobre una fuente frecuente, no errores de carga o diseño: en esa versión se conservó la identidad institucional compartida con MenuPdf (sustituida para informes por el diseño neutral del 12 de noviembre); el renderizador carga las fuentes locales de `public/build/fonts` bajo su `chroot`. Los PDF y capturas se revisaron independientemente.

Panadería y Global siguen sin informes PDF habilitados. La suite no certifica MariaDB, credenciales o servicios externos en vivo, ni reinicio y restauración del despliegue.

### Evidencia histórica: informes y calendario IA — 11 de noviembre de 2026

Registro **histórico local** del 11 de noviembre, sobre `branch-leo`: su presentación Montserrat/Playfair precede al rediseño neutral del día 12; no describe la tipografía vigente. El [contrato específico](../arquitectura-informes-pdf.md) describe las decisiones vigentes; este registro conserva resultados y límites, sin ampliar la arquitectura normativa.

| Comprobación | Resultado real |
|---|---|
| Enrutamiento HTTP real | PASS: login con CSRF y segundo factor de fixture bajo `scripts/dev-router.php`; las cinco rutas de octubre de 2026 responden 200 PDF/no-store. Anónimo y sesión pendiente de segundo factor no acceden. Sin router se reproduce 404 de Finanzas; el router existente no requirió cambios. Véase el comando y la salvedad histórica en el contrato. |
| Diagnóstico de cifras locales | Lectura consistente sin escrituras: 58 tickets cerrados sin partidas, ventas cero y gastos prorrateados explican la utilidad negativa. No se modificaron datos ni definiciones; no se puede reconstruir el importe histórico ausente. |
| Fundación (`npm run test:informes`) | PASS: rangos, escape, SVG interno sin recursos externos, origen común y signos, ceros/ausencias, fallback ante extremos, precisión de centavos, 14 tramos y validación controlada de metadatos. |
| Aceptación aislada (`npm run test:informes:db`) | PASS: cinco dominios y paridad, 13 comprobaciones de consistencia y 105 casos HTTP. La revisión gráfica añade 17 PDF representativos con actividad: cinco normales, cinco vacíos, cinco extensos de 366 días, Análisis con 14 tramos semanales y Reservaciones futuras provisionales. Hash de datos sin cambios tras generar. |
| Gráficas y paginación | PASS: Dompdf, render Poppler e inspección de todas las páginas; importes/centavos exactos, ejes comunes, negativos, etiquetas largas, unidades mixtas sin sumarlas, cabeceras y folios. Auditoría de fuentes de los 17 PDF confirma Montserrat/Playfair locales sin fallback Times/Helvetica. Tras corregir dos huérfanos se rerenderizaron Análisis vacío y Tickets extenso: PASS, sin comprimir texto. |
| IA HTTP actualizada | PASS: 80 comprobaciones documentales y nuevamente 105 HTTP. Una píldora/base, cinco tipos filtrados por permisos efectivos, nueve presets más Personalizado, revocación tras catálogo, IA sin módulos, módulos sin IA, otros contextos, NIP/2FA, entradas manipuladas y 366/367 días. Los endpoints originales vuelven a autorizar. |
| Calendario compartido (`npm run test:range-picker`) | PASS: parcial PHP real y jsdom ya disponible; inicialización idempotente, evento cancelable frente a navegación por defecto, 366/367 inclusivos, fecha imposible, cancelar sin aplicar y teclado de rejilla frente a botones Aplicar/Cancelar/navegación mensual. Sin dependencias nuevas. |
| Navegador real | PASS en Chrome, escritorio 1440×1000 y móvil 390×844: una píldora y una opción Ctrl+K; abrir Personalizado y mantenerlo abierto, seleccionar/aplicar sin recarga, teclado nativo, cancelar sin aplicar, cambiar cinco tipos y conversaciones conservando fechas; enviar los cinco PDF reales en nuevas pestañas. Dos meses/escritorio y uno/móvil, Aplicar visible y sin overflow. 366 aceptado y 367 bloqueado; consulta convencional vigente y selector de Finanzas navegando con comparador. Cero errores JavaScript. |
| Regresión y compilación | PASS: suites completas `npm run test:php` y `npm run test:js`, sintaxis PHP y `git diff --check`. Gulp `adminJs`, `adminIaJs` y CSS del módulo IA; se restauró la deriva generada ajena. Sin DDL, nuevos paquetes, endpoints adicionales ni archivos PDF en `public/`. |

Los periodos sin actividad conservan la semántica: Finanzas puede tener gastos mensuales prorrateados sin ventas; Inventario conserva la foto actual aunque no existan movimientos históricos. No se sustituyeron por ceros. Los controles de error/popup bloqueado y conservación del borrador ya contaban con verificación de navegador en la integración precedente; el nuevo lote se concentró en el calendario compartido y sus regresiones.

El primer intento del test Ctrl+K esperaba un nombre accesible exacto que excluía la descripción del elemento. Se corrigió el selector a prefijo manteniendo la aserción de una sola opción; el lote completo posterior pasó. No fue un cambio funcional para esconder una duplicación.

Se mantienen las correcciones verificadas de **aserciones preexistentes de fixtures**: Análisis anual suma $550 al incluir los $100 del 14/01/2002; Tickets anual suma $135 al incluir los $10 del 14/01/2001. Los rangos cortos conservan $450 y $125 respectivamente y la paridad con sus autoridades. No se alteraron cálculos para hacer pasar pruebas.

Los logs exactos y evidencia no secreta se conservan fuera del repositorio y de `public/`, bajo la raíz privada de visualizaciones de esta sesión, en `correccion-etapa1`, `correccion-etapa2` y `correccion-etapa3`. Incluyen `http-real.log`, `diagnostico-local.json`, `test-informes.log`, `test-informes-db.log`, `test-pagefix.log`, `auditoria-fuentes.json`, `test-ia-http.log`, `test-range-picker.log`, `browser-flow.log`, logs PHP/JS y capturas finales `calendario-desktop-rango.png` y `calendario-mobile-rango.png`. Se detuvieron servidores propios, eliminaron bases aisladas comprobando token de propiedad y retiraron temporales con credenciales sintéticas; `cleanup.log` conserva la confirmación.

Las sesiones y el segundo factor eran de fixture local; no certifican servicios externos, credenciales reales ni despliegue. Móvil significa viewport, no dispositivo físico. Las mediciones del 7 de noviembre son históricas y no un ensayo nuevo de capacidad. No se certifican MariaDB, reinicio o restauración, ni se habilitan informes de Panadería/Global.


### Rediseño documental neutral — 12 de noviembre de 2026 (encargo anterior a los ajustes finales)

Registro **local del encargo anterior** de rediseño neutral de los cinco PDF de Restaurante, sin cambiar SQL, filtros, métricas, prorrateo, permisos ni endpoints. La selección de gráficas y el espaciado vigentes se precisan en los ajustes finales siguientes. No se rediseñaron el panel, el asistente o el calendario. El [contrato de informes](../arquitectura-informes-pdf.md#decisiones-de-síntesis-por-módulo) conserva las decisiones funcionales; esta sección registra sus comprobaciones.

| Comprobación | Resultado real |
|---|---|
| Finanzas prioritario | PASS: seis casos (normal, negativo, vacío, extenso, cruzado al futuro y futuro con actividad registrada), todos de una página en ese fixture. Tabla de cuatro columnas, cuerpo 10 pt y notas 8.5 pt, seis KPI contra su autoridad, documento sin mutación y hash DB idéntico. Auditoría: solo Montserrat. |
| Lote representativo de cinco módulos | PASS: **32 PDF**: seis condiciones por cada módulo (normal, vacío, 366 días, cruzado, futuro con actividad y futuro sin actividad), más Análisis de 14 tramos variados y Reservaciones provisionales. Fixture con 93 ventas y centavos, tres almacenes de nombre amplio además del sembrado, saldos negativos y unidades mixtas. Paridad FIN/ANA/TIC de $227,783.75 en el extenso y datos sin mutación. |
| Render y fuentes | PASS: Dompdf, Poppler color y gris, inspección de páginas y hojas completas. **44 PDF auditados**, que incluyen los 32 representativos, cinco independientes de dominio, cinco HTTP, Análisis de 366 días y una prueba tipográfica sintética de KPI amplios. Recursos de fuente exclusivamente Montserrat regular/negrita, incluidos SVG y folios; sin rutas internas visibles. La prueba tipográfica no representa cifras de negocio. |
| Composición específica | PASS por supervisión: anchos efectivos de Inventario en celdas y alertas prioritarias; estados Tickets en KPI; Reservaciones diario sin tabla duplicada; importes y MXN separados de Alcance en el original futuro. Análisis puede conservar dos páginas lógicas; no se forzó una página reduciendo letra. |
| Fundación y regresión PHP | PASS: `npm run test:informes`, `npm run test:php` y sintaxis de archivos PHP afectados. Fundación añadió conservación de filas/importes/null/ceros en clasificación temporal y fallback de serie completa ante un lote vacío. |
| Aceptación aislada | PASS: cinco dominios, paridad, **13** comprobaciones de consistencia, **105 HTTP** y **80 IA** incluidas por el runner existente. Guardias y lectura sin mutación verificadas; las bases propias se eliminaron tras comprobar propiedad. |
| Última corrección dirigida | Tras la batería completa se detectó que los extensos podían producir un segundo lote gráfico íntegramente cero. Se corrigió solo la presentación y se ejecutaron fundación y render real dirigido de ANA/RES/TIC extensos: PASS, documentos inalterados, tabla completa de 12 tramos, ceros y cabeceras repetidas, sin panel vacío. Los 14 tramos variados conservan su gráfica y precisión. Se repitió la auditoría de fuentes de 44 PDF y `diff --check`, sin repetir toda la batería ni los normales ya aceptados. |
| JS, navegador y compilación | **NO_EJECUTADO en este encargo:** no hubo cambios JavaScript, SCSS del panel, bundles ni nuevo flujo de navegador IA. El PASS de JS y navegador del 11 de noviembre es evidencia previa, en `correccion-etapa3/test-js.log` y `browser-flow.log`; no un rerun del 12 de noviembre. |

La evidencia del nuevo encargo se conserva fuera del repositorio y de `public/`, bajo la raíz privada de visualizaciones de la sesión: `redisenio-etapa1` contiene los seis FIN, baseline y paridad; `redisenio-etapa2` contiene los 32 representativos y 12 comprobaciones PDF adicionales, PNG color/gris, `test-lote.log`, `test-informes-db.log`, `test-php.log`, `test-informes.log`, `php-lint.log`, `confirmacion-extensos.log`, `test-fuentes.log`, `auditoria-fuentes.json`, `cleanup.log` y `delta-nuevo.patch`. Este último compara los archivos propios contra el baseline del inicio del encargo, preservando cambios anteriores sin commit. `redisenio-etapa3` conserva comprobación documental y delta final.

Los scripts de evidencia de esta sesión reutilizaron el bootstrap aislado; la configuración local se trasladó en memoria a `TEST_DB_*`, sin imprimir credenciales ni cambiar la BD de aplicación. No quedaron servidores o navegadores propios activos. La legibilidad en gris se verificó con render digital: **no se realizó impresión física**. No hay nueva prueba de capacidad, despliegue, servicios externos, MariaDB, reinicio o restauración; Panadería y Global siguen inactivos para informes.


### Ajustes finales de gráficas, títulos y legibilidad — 12 de noviembre de 2026

Validación local sobre el nuevo baseline de `branch-leo`, conservando todos los cambios previos sin commit. Se modificaron metadatos y presentación, no SQL, cifras, filtros, permisos ni cálculos. El contrato vigente describe la partición observada/restante, los identificadores estables y la prioridad de separación sobre compactación.

| Comprobación | Resultado real |
|---|---|
| Temporalidad y selección gráfica | PASS en fundación: gráfica solo de tramos enteramente anteriores al día del corte; ceros/null observados conservados, sin unir líneas a través de null. Dos observaciones informativas de Tickets permiten gráfica; observado único o futuro completo conserva tabla. Tramos parciales sin dividir ni recalcular, con importes completos y signos exactos. |
| Títulos e identidad | PASS: cinco títulos en sentence case y sans-serif; Reservaciones tiene título fijo y estado provisional en metadatos. Pruebas con títulos alternativos confirman que FIN conserva aviso de gastos/comparativa de cuatro columnas, INV ordena alertas por metadatos y RES deduplica demanda por IDs. Metadatos malformados rechazados. |
| Muestra visual seleccionada | PASS: **23 PDF** con render Poppler color/gris y hojas completas: cinco históricos con actividad, cinco cruzados simulados, cinco vacíos, cinco con reloj real y rango actual/futuro, más TIC parcial sin tramos completos, TIC futuro y RES futuro programado. Todas sus páginas se inspeccionaron; fuente exclusivamente Montserrat. `manifest.md`/`manifest.json` indican módulo, rango, corte, páginas, gráfica sí/no y razón. El arnés reutilizado generó un lote auxiliar de **37 PDF**; no se afirma que todos esos auxiliares fueran la muestra visual seleccionada. |
| Corte simulado y reloj real | El cruzado con actividad usa corte **simulado 15/02/2004**, rango 01/01–31/03/2004: seis semanas completas observadas en gráfica y siete tramos parciales/futuros íntegros en tabla de ventas. Se añadieron cinco PDF con reloj **real local 12/11/2026 11:38:53**, America/Mexico_City, y rango **29/10–19/11/2026**; datos del fixture, no de producción. Esos casos vacíos no inventan actividad observada ni una gráfica. |
| Regresión | PASS: `npm run test:php`; cinco dominios, paridad, **13** comprobaciones de consistencia, **105 HTTP** y **80 IA** incluidas por el runner de aceptación aislada. Hash de datos/documentos inalterado y bases propias eliminadas comprobando propiedad. No se repitió navegador IA, JS ni compilación: no hubo cambios frontend. |
| Corrección final dirigida | Se corrigió un aviso ambiguo que decía «Sin datos disponibles» al existir importes parciales/futuros: ahora explica que no hay tramos completos observados suficientes para gráfica. Fundación secuencial final y render dirigido TIC parcial/futuro: PASS, documentos intactos, corte/alcance y cifras conservados. Se confirmaron únicamente esos casos, sin repetir la matriz ni los normales ya aceptados. |

**Advertencia de fuentes conservada, no ocultada.** `test-informes.log` contiene warnings BinaryStream.php221/222 de lectura de cero bytes mientras aceptación DB y fundación se ejecutaban concurrentemente. La lectura de vendor, sin modificarlo, muestra escritura de caché UFM/TTF compartida sin bloqueo: es compatible con una carrera transitoria, pero no se capturó evidencia para afirmar causalidad exclusiva. La repetición secuencial (`test-informes-secuencial.log`) y la fundación final (`test-informes-final.log`) pasaron sin advertencias. Los TTF cacheados se comprobaron idénticos a las fuentes locales (`fuentes-cache.json`); un PDF posterior se generó con warnings convertidos a excepción y se validaron sus fuentes Montserrat (`confirmacion-fuentes.log`). No se afirma haber resuelto la concurrencia general del registro de fuentes.

La evidencia está en `ajustes-finales-20261112`, fuera del repositorio y de `public/`: `baseline`, `delta-final.patch`, `manifest.md`, `manifest.json`, `seleccion.json`, PDF/PNG seleccionados, `test-actual.log`, `test-lote.log`, `test-php.log`, `test-informes-db.log`, los logs de fundación anteriores, `confirmacion-parciales.log`, `test-fuentes.log`, `auditoria-fuentes.json` y `nota-warning-fuentes.md`. El delta compara exclusivamente este encargo contra su nuevo baseline; no confunde entregas anteriores pendientes de commit. Las comprobaciones documentales y `diff --check` se registran al cierre.

Los scripts usaron credenciales locales solo en memoria para fixtures propias; no cambiaron la BD de aplicación. Servidores de prueba y bases aisladas fueron limpiados; no se inició un navegador en este encargo. Validación de legibilidad en render digital, **sin impresión física**, despliegue, servicios externos o nueva prueba de capacidad. Los registros históricos ausentes no se reconstruyen; Panadería y Global permanecen sin informes habilitados.
