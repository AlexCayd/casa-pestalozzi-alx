# Pruebas y criterios de aceptación

## Cierre M1.2

Rama `codex/m12-estandarizacion-productos-recetas`, base aprobada M1.1-E
`a0562c344c0c66c7d197a03a5bd350006ff6742e`. Fases A `3f5447d`, B `3eb1acc`,
C `a9e375c`; D es el commit de interfaz, fixtures y cierre de esta rama.
El HEAD final se obtiene con `git rev-parse HEAD`; el informe entregado fuera del
repositorio registra su hash exacto y el inventario completo sin autorreferencia.

| Prueba ejecutada | Resultado M1.2 |
|---|---|
| Estructura/migración repetida y fixtures | 16 PASS |
| Productos estándar: publicación, política, concurrencia, permisos/adopción/restauración | 39 PASS |
| Recetas maestras: versiones, rollback, adaptación, concurrencia y consumo POS real | 29 PASS |
| Filas dinámicas de ingredientes con selector real | PASS |
| PHP general / JS general / build | PASS; deprecaciones existentes Sass/Node |
| Menú M1 + M1.1 | 75 PASS; 52 aserciones originales conservadas |
| HTTP M1 / M1.1 / M1.1-E | 47 / 47 / 25 PASS |
| HTTP M1.2 | 43 PASS |
| Contextos puro / Autorización 3B / Usuarios 3E.1 | 263 / 128 / 40 PASS |
| Instalación reproducible (dos bases) | 957 PASS |
| Almacenes / selección / concurrencia | 158 / 70 / 17 PASS |
| Transferencias / fixtures M0.5-B | 114 / 378 PASS |
| HTTP instalación / Transferencias | 121 / 35 PASS |
| Preservación Menú puro / DB | 34 / 7 casos PASS |
| PDF de reservaciones | PASS |
| Notificaciones y POS incluido, DDL y variante migrations | 16 de 16 PASS en ambas ejecuciones actuales |
| POS print alerts histórico | FAIL: fixture sin `unidad_id` |
| POS llevar histórico | FAIL: fixture con actor 0 |
| Contextos DB histórico | FAIL: columna retirada `usuarios.rol` |

Los tres fallos históricos anteriores mantienen los errores ya reproducidos sobre
la base previa durante M1.1; sus runners y contratos afectados no se alteraron.
La incidencia temporal del POS a las 18:30 documentada en M1.1 no se reprodujo en
las ejecuciones actuales: se informa PASS de esta ejecución, sin afirmar que se
haya reparado aquella incidencia. No se reconstruyeron los paquetes privados
retirados de suites 3A/3D. Simulaciones locales no certifican servicios externos.

Todas las pruebas SQL crean bases aleatorias identificadas, verifican propiedad
y limpian sus recursos. La base activa no recibió migraciones, fixtures ni pruebas.
HTTP usa `servir-instalacion-3e.php`, su fixture privado y cuentas efímeras.
Las pruebas nuevas incluyen datos inválidos, permisos revocados, Global sin facultad,
solo lectura, superadministrador, CSRF, cambio de contexto, reglas manipuladas e
IDs ajenos. Los componentes históricos de recetas y snapshots permanecen intactos;
el POS descuenta de Cocina la versión operativa aplicada y Panadería no consume
almacenes ajenos.

```text
php scripts/tests/run-estandares-estructura-m12.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-estandares-m12.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-recetas-casa-m12.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
npm run test:menu:m12-ui
php scripts/tests/pruebas-http-m12.php --fixture <ruta-privada-del-harness-3e>
```

La migración aditiva añade `producto_estandares`, `producto_publicaciones`,
`oferta_personalizaciones`, `producto_cambios_locales`, `recetas_casa`,
`receta_casa_versiones`, `receta_casa_componentes` y `receta_origen_casa`, además de
`productos_oferta` y triggers de inmutabilidad. Contrato/matriz de permisos en
[Catálogo](../modulos/catalogo-comercial.md#estado-vigente-m12) y
[Recetas](../modulos/recetas.md). Archivo SQL:
`database/actualizaciones/m12-estandares.sql`, incluido también en el DDL nuevo.

Defectos corregidos durante aceptación: carrera de creación de identidad, ámbito
del historial de precios, resolución SQL de booleanos/imagen null, receta anterior
activa al publicar sin receta, cierre SQL inmutable de componentes, errores HTTP
sin filtrar, reglas manipuladas, IDs extranjeros, selectores duplicados y restauración
de precio que persistía incorrectamente al republicar. Se conserva una única fuente
oficial y personalizaciones explícitas; ninguna solución crea stock o almacenes.

La revisión real cubrió Global, Restaurante y Panadería, escritorio/tableta/móvil,
claro/oscuro, foco, teclado y controles protegidos. Evidencia privada fuera de Git en
`C:/Users/leona/.codex/visualizations/2026/10/28/01a180d0-2909-7a11-ab40-bc53fa4d8fde/`:
`m12-global-desktop.png`, `m12-global-desktop-claro.png`,
`m12-restaurante-precio-local.png`, `m12-panaderia-mobile-claro.png` y
`m12-receta-mobile-claro.png`. Los flujos se comprobaron con la instalación temporal;
no se presenta esta revisión como certificación de otros navegadores o motores SQL.

El inventario exacto de archivos de las cuatro fases se obtiene con
`git diff --name-only a0562c344c0c66c7d197a03a5bd350006ff6742e HEAD`.
El cierre conserva la rama limpia, sin push ni merge; los otros worktrees quedan
intactos. La preparación de despliegue y las fichas culinarias productivas están
pendientes de la operación, fuera de estas pruebas.


## M1.1-E

`run-inicio-m11.php` amplía la matriz con quince módulos Restaurante, cuenta sin
Analíticas, iconos y rechazo PIN. `test:js` y `test:menu:m11-ui` incorporan
`run-almacenes-auto-selection.cjs` y conservan todas las aserciones de selección
anteriores. Los temporizadores controlados comprueban una sola consulta por
ráfaga, envío manual, vuelta por historial, todos, vacío y filtros GET.

```text
php scripts/tests/pruebas-http-m11-e.php --fixture <ruta-privada-del-harness-3e>
```

Esta matriz usa solo el harness identificado, no conecta a SQL y no escribe
operaciones físicas. Resultados, evidencia y limitaciones en la
[guía de interfaz](interfaz.md#m11-e-inicio-restaurante-y-seleccion-compacta).

## M1.1: inicios, catálogo, PDF y Almacenes

```text
npm run test:menu:m11 -- --host 127.0.0.1 --base casa-pestalozzi --entorno development
npm run test:menu:m11-ui
php scripts/tests/pruebas-http-m11.php --fixture <ruta-privada-del-harness-3e>
```

El runner M1.1 amplía M1 sin quitar sus 52 aserciones y conserva creación/limpieza
de la base marcada. Comprueba PDF por dueño, vacío/inactivo, filtros, alta rápida
con doble autorización y métricas físicas. La prueba JS ejecuta el archivo real
de inventario y verifica milésimas con BigInt, negativos, precisión y confirmación.
HTTP usa únicamente el harness 3E identificado. Resultados, fallos históricos
reproducidos en el commit base y evidencia visual en
[refinamiento M1.1](../modulos/catalogo-comercial.md#refinamiento-m11).

El [contrato rector](../arquitectura.md) fija los criterios de entrega. Este documento
identifica comandos y alcance; los números y resultados de cada ejecución deben
registrarse en el informe de la revisión validada.

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
php scripts/tests/pruebas-http-menu-m1.php --fixture <ruta-privada-del-harness-3e>
```

El runner M1 crea y limpia una base aleatoria marcada desde los cuatro SQL
versionados. Verifica nuevas identidades, oferta explícita, independencia,
permisos/revocación, facultad separada de inventario, precios exactos, retiro con
ventas, referencias de imágenes, fallos después de la primera escritura y carreras
entre dos procesos. No selecciona ni migra la base activa identificada por argumentos.

El cliente HTTP 3E incorpora perfiles `menu/ver` y `menu/editar`. La suite M1
verifica CSRF contextual, IDs manipulados, categorías ajenas, asociación, edición
maestra con confirmación y huella, fotos pendientes/confirmadas y retiro de sus
archivos propios, además de PDFs públicos y superficies futuras bloqueadas.
Sus datos viven únicamente durante el harness. El cliente común admite multipart
para imágenes efímeras sin exponer credenciales. La prueba UI usa el componente
real de confirmación en JSDOM, disponible en las dependencias bloqueadas.
Completar con recorrido real de temas, teclado, categorías, filtros, modal,
Global, Restaurante y Panadería en escritorio y móvil; las capturas quedan fuera
de Git. Resultados de la revisión en [Catálogo y Menú](../modulos/catalogo-comercial.md).
