# Credenciales de desarrollo

## Administrador demo

La identidad de demostración es `admin_demo`, superadministradora organizacional,
activa en una instalación de desarrollo identificada. La contraseña acordada se
aporta localmente mediante `DEV_SUPERADMIN_PASSWORD`; no se versiona su valor.
El SQL no crea automáticamente una credencial conocida ni permite login de
superadministrador mediante NIP.

Seguir el [procedimiento único de instalación](../desarrollo/instalacion.md).
El comando [bootstrap-admin.php](../../scripts/desarrollo/bootstrap-admin.php) genera un hash seguro, crea las tres membresías y deja una marca
que permite validar su reejecución sin duplicar o promover una cuenta homónima.
La reejecución no restablece una contraseña cambiada; `--restablecer` expresa esa
decisión solo en desarrollo/pruebas identificados.

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
2. Introducir el secreto local configurado y verificar el selector de los tres ámbitos.
3. Crear una cuenta exclusivamente POS/KDS/mapa operativo y comprobar su NIP.
4. Agregar un permiso de panel exige contraseña y nueva autenticación; el NIP
   anterior no debe ampliar una sesión a administración.
5. Regenerar un NIP desde su flujo autorizado y comprobar que el anterior falla.

La cuenta conocida de desarrollo no debe activarse automáticamente fuera de una
instalación identificada. Mantener todos los secretos de despliegues reales fuera
del repositorio y de esta documentación.
