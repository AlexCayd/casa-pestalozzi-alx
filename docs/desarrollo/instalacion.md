# Instalación de desarrollo

Esta guía es el procedimiento único para reconstruir la aplicación desde los
archivos versionados. El [contrato rector](../arquitectura.md) define su alcance.
No utiliza la base antigua ni el paquete privado de preservación de Etapa 2.

## Requisitos

- PHP compatible con `composer.json`, con `mysqli`, `mbstring`, `bcmath`, `curl`
  y las extensiones que comprueba Composer para las dependencias bloqueadas.
- MySQL con InnoDB y soporte efectivo de las restricciones del DDL (8.0.16+ o 9.x).
- Composer, Node.js y npm compatibles con los archivos de bloqueo del repositorio.
- Servidor web con `public/` como document root. En XAMPP, configurar el virtual
  host para esa carpeta; no publicar la raíz que contiene `includes/`.

No mezclar ejecutables y DDL de revisiones diferentes.

## 1. Obtener código y dependencias

Clonar el repositorio y elegir la revisión aprobada de la rama candidata.
Desde su raíz ejecutar:

```sh
composer install --no-interaction
npm ci
```

El repositorio configura `legacy-peer-deps=true` en `.npmrc`, de modo que
`npm ci` utiliza el archivo de bloqueo versionado y ejecuta los scripts de
instalación requeridos por dependencias nativas como Sharp. No usar
`--ignore-scripts`: impediría preparar esos recursos para el build. Conservar el archivo de bloqueo de la revisión elegida.

## 2. Configurar el entorno local

Crear `includes/.env` como archivo local ignorado por Git. Mantenerlo fuera de
capturas, informes y exports. Configurar estas claves con valores locales:

```dotenv
APP_ENV=development
APP_TIMEZONE=America/Mexico_City
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=<usuario-local-de-instalacion>
DB_PASS=<secreto-local>
DB_NAME=casa_pestalozzi_dev
DEV_SUPERADMIN_PASSWORD=<contraseña-local-de-desarrollo-acordada>
NIP_LOOKUP_SECRET=<secreto-aleatorio-local-de-al-menos-32-bytes>
```

Los valores entre `<...>` son marcadores, no credenciales para copiar. El seed
de desarrollo guarda únicamente el hash bcrypt de `admin_demo`; el instalador
requiere que `DEV_SUPERADMIN_PASSWORD` local corresponda a ese hash para validar
la cuenta. El texto claro no se almacena en SQL ni documentación versionada ni
se pasa como argumento del comando. `NIP_LOOKUP_SECRET` debe permanecer estable dentro de la instalación: permite
localizar NIP mediante HMAC y no es una contraseña de usuario.

El nombre `DB_NAME` del ejemplo es una base nueva. El instalador exige que todavía
no exista; incluso una base existente vacía se rechaza. Una cuenta MySQL utilizada
para instalar necesita permisos para crear esa base. El usuario de ejecución de
la aplicación puede tener privilegios más limitados según el despliegue.

## 3. Instalar estructura, menú y fixtures

```sh
php scripts/desarrollo/instalar.php --host 127.0.0.1 --base casa_pestalozzi_dev --entorno development
```

Los tres argumentos deben coincidir exactamente con la configuración local.
El comando CLI rechaza entornos diferentes de `development` y `test`, consulta
si el destino existe y crea únicamente una base nueva. Ejecuta en este orden:

1. `database/ddl.sql`: estructura definitiva.
2. `database/deploy.sql`: unidades, facultades y estructura mínima.
3. `database/menu-restaurante.sql`: menú preservado de Restaurante.
4. `database/development.sql`: fixtures sintéticos y, solo cuando el instalador
   CLI activa el guard `@cp_dev_admin_demo_seed` en `development`/`test`, la
   cuenta `admin_demo` mediante hash y sus tres membresías.
5. Bootstrap `Services\Development\BootstrapDesarrollo`: valida el hash frente
   al secreto local y marca la identidad como propiedad de esta instalación.

`--sin-simulados` omite solo el cuarto archivo; conserva estructura, menú y
bootstrap. No ejecutar manualmente bloques sueltos como un segundo procedimiento.
Los DML de fixtures están diseñados para instalación nueva, no para reaplicarse
sobre una base activa. Una segunda instalación usa otro nombre de base y su
configuración correspondiente.

El instalador registra una marca privada de propiedad y una huella del DML.
Un fallo conserva el destino incompleto para diagnóstico y recuperación; no
elimina datos automáticamente. Identificar el destino y conservar el respaldo
necesario antes de cualquier limpieza explícita.

## 4. Cuenta de desarrollo

La identidad es `admin_demo`, activa y superadministradora organizacional. El
seed SQL contiene el hash bcrypt de la contraseña de desarrollo acordada; su
inserción bajo CLI está condicionada al guard del instalador identificado.
La importación SQL directa sin marcador también activa la cuenta; se reserva
a pruebas controladas sobre bases locales nuevas, nunca a producción. El bootstrap valida la contraseña local contra ese hash y
registra la propiedad de la cuenta. La cuenta tiene membresías Global,
Restaurante y Panadería, no tiene NIP y no existe una ruta pública para crear
superadministradores. Si se usa `--sin-simulados`, el bootstrap crea la cuenta
con hash generado localmente en vez de cargar el seed.

Para verificar nuevamente la cuenta en esa instalación:

```sh
php scripts/desarrollo/bootstrap-admin.php --host 127.0.0.1 --base casa_pestalozzi_dev --entorno development
```

La reejecución valida la identidad marcada, no duplica la cuenta, no promueve una
cuenta homónima ajena y no restablece silenciosamente una contraseña cambiada.
Si se necesita un restablecimiento deliberado en desarrollo, actualizar el secreto
local y ejecutar el mismo comando con `--restablecer`. El mecanismo está bloqueado
fuera de los entornos identificados y `deploy.sql` no activa esta cuenta.

## 5. Compilar e iniciar

El build finito del proyecto está en `gulpfile.js`:

```sh
npm run build
```

`npm run dev` compila y deja watchers en ejecución. No edita el código fuente
cuando genera `public/build/`; los archivos fuente están en `src/` y los recursos
compartidos en `assets/`. Conservar las 13 imágenes preservadas y validar sus
huellas después de compilar.

Iniciar el servidor con document root `public/`. Para una comprobación local
sin Apache, el servidor integrado de PHP puede arrancarse desde la raíz:

```sh
php -S 127.0.0.1:8080 -t public
```

La configuración de URL y reescritura de un despliegue Apache/Nginx debe enviar
las rutas de la aplicación a `public/index.php`. No servir scripts de instalación
ni el archivo de entorno desde HTTP.

## 6. Comprobar la instalación

1. Abrir `/login`, elegir Contraseña e iniciar con `admin_demo` y el secreto local.
2. Cambiar entre Global, Restaurante y Panadería con el selector bajo el logotipo.
3. Probar Usuarios: crear una cuenta local, una compartida, editar permisos,
   revocar una membresía y comprobar el rechazo de un ID ajeno.
4. Probar Almacenes: alta física autorizada desde Global, catálogo maestro,
   asociación local, entrada, ajuste, merma, movimientos y consolidado.
5. Comprobar el menú original (112 productos/ofertas, 15 categorías, 3 cartas,
   4 áreas y 13 imágenes) sin contar ofertas sintéticas adicionales de QA.
6. Recorrer Restaurante: mapa, ticket, comanda, KDS, entrega, cobro y cancelación.
7. Ejecutar las [pruebas obligatorias](pruebas.md) y registrar resultados.

No habilitar impresoras ni proveedores externos por la sola presencia de los
fixtures. Consultar [configuración](../config.md),
[notificaciones](../reservaciones/notificaciones.md) y [operación](../operacion.md).
Las simulaciones locales no certifican credenciales, entregas ni hardware reales.

## Recuperación y dos instalaciones independientes

La instalación candidata no aplica DDL al entorno antiguo. Conservar su copia
identificada y los recursos necesarios para volver a esa revisión hasta aprobar
la nueva. La existencia de ese respaldo no es una dependencia de reconstrucción.

Ensayar esta guía dos veces con nombres nuevos independientes, verificando menú,
movimientos/saldos, login, permisos y flujo comercial. Registrar los nombres
sintéticos o sus identificadores de prueba y el resultado, sin publicar secretos.
No declarar la rama lista para merge hasta completar esa evidencia.

## Cobertura de fixtures

El DML de desarrollo reúne datos base, analítica anual, RevPASH, recetas/variantes,
especiales de temporada y ventas recientes del Asistente. Usa entidades canónicas,
no agregados a5 ni stock maestro duplicado. Los especiales originales son parte
del menú preservado; los ejemplos adicionales tienen ofertas sintéticas propias.
Las impresoras de ejemplo están inactivas y apuntan a loopback. Hay escenarios
de reservas multimesa, pagos divididos, cancelaciones y costos desconocidos.
No copiar INSERTs de revisiones anteriores.

Los resultados y conteos de una ejecución se registran fuera de la documentación
operativa; verificar el DML de la revisión elegida con run-instalacion-3e.php.
