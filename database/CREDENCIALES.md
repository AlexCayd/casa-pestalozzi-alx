# Credenciales de prueba (usuarios registrados en la DB)

Usuarios demo que crea `dml.sql`. Todos entran por **`/login`**, que muestra dos
pestañas:

- **NIP** → personal de piso (meseros y cocineros): 4 dígitos.
- **Contraseña** → administradores: usuario + contraseña alfanumérica.

| Usuario           | Nombre               | Rol           | Pestaña     | Credencial             | Nacimiento   | Activo |
| ----------------- | -------------------- | ------------- | ----------- | ---------------------- | ------------ | ------ |
| `admin_demo`      | Administrador Demo   | Administrador | Contraseña  | Pass: `Pestalozzi2026` | 1985-06-12   | ✅     |
| `mesero1`         | Carlos Hernández     | Mesero        | NIP         | NIP: `2345`            | 1993-11-23   | ✅     |
| `mesero2`         | Valeria Ríos         | Mesero        | NIP         | NIP: `1702`            | 1996-02-17   | ✅     |
| `cocinero1`       | Mariana López        | Cocinero      | NIP         | NIP: `3456`            | 1991-09-05   | ✅     |
| `mesero3`         | Emilio Cárdenas      | Mesero        | NIP         | NIP: `3007`            | 1998-07-30   | ✅     |
| `mesero_inactivo` | Daniel Torres        | Mesero        | —           | Sin acceso (inactivo)  | 1994-12-03   | ❌     |

> El usuario inactivo tiene NIP en la base pero no puede entrar: `Usuario::porNip()`
> filtra por `activo = 1`.

Los NIP de `mesero2` y `mesero3` son el DDMM de su cumpleaños: es el valor que
genera el alta cuando el administrador deja el campo vacío.

## A dónde llega cada rol

El rol decide el destino tras iniciar sesión y qué rutas se permiten
(`Classes\Auth::proteger()`):

| Rol      | Destino           | Acceso                                    |
| -------- | ----------------- | ----------------------------------------- |
| `admin`  | `/admin`          | Todo                                      |
| `waiter` | `/punto-de-venta` | Punto de venta y sus APIs                 |
| `cook`   | `/area`           | Tableros de producción y sus APIs         |

`/admin/login` sigue existiendo como endpoint del formulario de contraseña; si
se abre con GET redirige a `/login`.
