# Credenciales de desarrollo

Estas credenciales son exclusivamente para desarrollo y QA. No deben
reutilizarse en producción ni copiarse a tickets, capturas o documentación
operativa.

## Administrador demo

El seed SQL de pruebas crea este usuario:

| Usuario | Contraseña | Rol | Acceso |
| --- | --- | --- | --- |
| `admin_demo` | `Pestalozzi2026` | `admin` | usuario + contraseña |

La contraseña es un valor de desarrollo. Cámbiala antes de usar cualquier
instalación fuera de QA.

## Usuarios de piso

El seed PHP prepara estos usuarios de desarrollo:

| Usuario | Nombre | Rol | Estado |
| --- | --- | --- | --- |
| `mesero1` | Carlos Hernández | `waiter` | activo |
| `mesero2` | Valeria Ríos | `waiter` | activo |
| `cocinero1` | Mariana López | `cook` | activo |
| `mesero3` | Emilio Cárdenas | `waiter` | activo |
| `mesero_inactivo` | Daniel Torres | `waiter` | inactivo |

Los NIP no se documentan como credenciales permanentes. Ejecuta el seed y
entrega únicamente los NIP que muestre esa ejecución:

```text
php scripts/seed-usuarios-prueba.php
```

La salida del seed pertenece a esa instalación concreta. Los NIP se guardan
en la base de datos como `nip_hash` y `nip_lookup`; nunca se escribe el valor
plano en SQL, archivos, cookies, URL, logs o almacenamiento del navegador.

El seed requiere `NIP_LOOKUP_SECRET` configurado en el entorno. La clave no
debe versionarse y debe permanecer estable dentro de una instalación. Si se
cambia, prepara nuevamente las credenciales de piso antes de intentar el
login.

## Login por rol

1. Entra a `/login`.
2. Usa la pestaña de contraseña con `admin_demo` para entrar al panel.
3. Usa la pestaña de NIP con los cuatro dígitos entregados por el seed para
   entrar como `waiter` o `cook`.

Si una persona olvida su NIP, no existe una consulta del código actual:
regenera la credencial desde la edición del usuario y entrega el nuevo valor
cuando aparezca el modal de un solo consumo.
