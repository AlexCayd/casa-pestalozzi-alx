# Credenciales de prueba

`dml_pruebas.sql` crea el administrador demo. Después de cargarlo, ejecuta
`php scripts/seed-usuarios-prueba.php` con `NIP_LOOKUP_SECRET` configurado para
crear las cuentas de piso y calcular sus `nip_hash`/`nip_lookup`.

Estas credenciales son únicamente de desarrollo/QA; no deben reutilizarse en
producción.

| Usuario | Nombre | Rol | Acceso | Credencial | Activo |
| --- | --- | --- | --- | --- | --- |
| `admin_demo` | Administrador Demo | Administrador | usuario + contraseña | `Pestalozzi2026` | Sí |
| `mesero1` | Carlos Hernández | Mesero | NIP | `2345` | Sí |
| `mesero2` | Valeria Ríos | Mesero | NIP | `1702` | Sí |
| `cocinero1` | Mariana López | Cocinero | NIP | `3456` | Sí |
| `mesero3` | Emilio Cárdenas | Mesero | NIP | `3007` | Sí |
| `mesero_inactivo` | Daniel Torres | Mesero | NIP | `7788` | No |

El usuario inactivo conserva su credencial y su `nip_lookup`, pero el login lo
rechaza mientras `activo = 0`. Al reactivarlo vuelve a utilizar el mismo NIP.

## Acceso por rol

| Rol | Destino | Método |
| --- | --- | --- |
| `admin` | `/admin` | usuario + contraseña |
| `waiter` | `/punto-de-venta` | NIP de cuatro dígitos |
| `cook` | `/area` | NIP de cuatro dígitos |

`NIP_LOOKUP_SECRET` es obligatorio para crear o rotar credenciales de piso y
debe vivir fuera del repositorio.
