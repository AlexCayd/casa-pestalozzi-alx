# Credenciales de desarrollo

## Administrador demo

La identidad de demostración es `admin_demo`, superadministradora organizacional,
activa en una instalación de desarrollo identificada. `database/development.sql`
incluye solo su hash bcrypt, nunca la contraseña en texto claro. El instalador
CLI activa su guard de sesión en `development`/`test`; si los archivos SQL se
importan directamente en una base local nueva sin el marcador del instalador,
el DML de desarrollo también crea la cuenta. Esa importación directa es solo
para pruebas controladas, no sustituye el procedimiento CLI ni se ejecuta en
producción. `deploy.sql` no la crea ni permite login de superadministrador
mediante NIP.

Seguir el [procedimiento único de instalación](../desarrollo/instalacion.md).
El procedimiento CLI agrega las tres membresías solo cuando el instalador corre
con la opción simulada en `development`/`test`. El comando
[bootstrap-admin.php](../../scripts/desarrollo/bootstrap-admin.php) valida el
hash frente al secreto local `DEV_SUPERADMIN_PASSWORD` y deja una marca para impedir que se promueva una
cuenta homónima ajena. La reejecución no restablece una contraseña cambiada;
`--restablecer` expresa esa decisión solo en desarrollo/pruebas identificados.

## Usuarios de piso

Crear usuarios operativos desde Usuarios con los permisos directos de piso
necesarios. El servidor genera el NIP y lo muestra una sola vez. No copiar NIP a
SQL, archivos, cookies, URL, logs, capturas ni almacenamiento del navegador.

`NIP_LOOKUP_SECRET` vive en configuración local no versionada y debe permanecer
estable. Cambiarlo exige preparar nuevamente las credenciales afectadas mediante
el procedimiento autorizado; no se recuperan NIP anteriores desde hashes.

Los usuarios sintéticos de fixtures no equivalen a credenciales activas. No usar
el antiguo seed de roles como procedimiento alternativo de instalación.

## Comprobar el acceso

1. Abrir `/login` y la pestaña Contraseña para `admin_demo`.
2. Introducir el secreto local configurado, completar el segundo factor y verificar el selector de los tres ámbitos.
3. Crear una cuenta exclusivamente POS/KDS/mapa operativo y comprobar su NIP.
4. Agregar un permiso de panel exige contraseña y nueva autenticación; el NIP
   anterior no debe ampliar una sesión a administración.
5. Regenerar un NIP desde su flujo autorizado y comprobar que el anterior falla.

La cuenta conocida de desarrollo no debe activarse automáticamente fuera de una
instalación identificada. Mantener todos los secretos de despliegues reales fuera
del repositorio y de esta documentación.

## Segundo factor administrativo

En 3B requieren contraseña y código todos los superadministradores y las cuentas
con algún permiso administrativo directo, incluso solo lectura y en cualquier
ámbito. Se decide con `PermisosUsuario::cargar()` y `tipoAcceso()`, nunca con
`usuarios.rol`, que no existe en el DDL 3B. Las cuentas exclusivamente operativas
siguen usando NIP. El esquema heredado conserva su política para roles de sistema.

La contraseña correcta abre un reto de sesión, sin autenticar. El código dura
600 segundos, permite cinco intentos y hasta cinco envíos separados por 30
segundos. Un código nuevo invalida el anterior. Agotar intentos exige volver
a la contraseña. Al verificar se releen estado y privilegios efectivos; una
desactivación o pérdida de acceso administrativo cancela el reto.

Solo `APP_ENV=development` o `test` permite mostrar el código de prueba.
Un entorno vacío, ausente, desconocido o de producción nunca lo muestra.
`admin_demo` sigue esta misma política, sin excepción por nombre de usuario.
En producción una cuenta sin correo no puede recibir el código ni completar
el acceso: configurar correo y transporte antes de habilitarla. No autenticar
por un fallo de entrega. El webhook `/webhook/admin/codigo-acceso` usa el
transporte saliente documentado en [n8n](../../n8n/README.md).
