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


### Rediseño documental neutral — 12 de noviembre de 2026

Validación **local** del diseño vigente de los cinco PDF de Restaurante, sin cambiar SQL, filtros, métricas, prorrateo, permisos ni endpoints. No se rediseñaron el panel, el asistente o el calendario. El [contrato de informes](../arquitectura-informes-pdf.md#decisiones-de-síntesis-por-módulo) conserva las decisiones funcionales; esta sección registra sus comprobaciones.

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
