# Pruebas y criterios de aceptación

El [contrato rector](../contrato.md) fija los criterios de entrega. Este documento
identifica comandos y alcance; los números y resultados de cada ejecución deben
registrarse en el informe de la revisión validada.

## Suites generales

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

## Comprobaciones externas

La simulación no certifica impresoras físicas, SMTP, Meta/WhatsApp, credenciales
n8n, entregas reales, reinicios o restauración. Registrar esas pruebas como no
realizadas cuando no haya evidencia. Un fallo crítico de aislamiento, integridad
o seguridad bloquea la candidatura a merge; no se etiqueta como mejora futura.
