# Acceso de usuarios

## Roles y autenticación

- `admin`: usuario + contraseña administrativa; no utiliza credenciales de piso.
- `waiter`: NIP numérico de cuatro dígitos; entra al punto de venta.
- `cook`: NIP numérico de cuatro dígitos; entra a los tableros de producción.

El NIP se genera exclusivamente en el servidor con `random_int()`. Ningún
formulario acepta NIP, confirmación de NIP ni otros datos personales para
derivarlo. Los ceros iniciales forman parte del valor.

## Persistencia y unicidad

`nip_hash` guarda `password_hash(NIP)` y sólo sirve para confirmar la
credencial. `nip_lookup` guarda un HMAC-SHA-256 determinista del NIP usando
`NIP_LOOKUP_SECRET`, que es configuración del servidor y nunca se guarda en la
BD ni en Git.

La restricción `uq_usuarios_nip_lookup` es la autoridad final de unicidad. La
aplicación genera un candidato dentro de una transacción, intenta persistirlo y
reintenta con otro candidato cuando la BD rechaza una colisión. Hay un máximo
de 50 intentos. Los administradores conservan ambas columnas en `NULL` para
permitir múltiples administradores.

## Alta y regeneración

Al crear un `waiter` o `cook`, el sistema genera y reserva el NIP antes del
commit. Después del commit se entrega mediante un flash de sesión de un solo
consumo en la pantalla de usuarios. El código no se guarda en cookies,
almacenamiento del navegador, URL, logs ni listados. No existe recuperación del
NIP actual; si se pierde, la única alternativa es regenerarlo.

La regeneración requiere una sesión administrativa, CSRF y confirmación
explícita. Sustituye `nip_hash` y `nip_lookup` en una transacción, por lo que el
NIP anterior deja de funcionar inmediatamente. El nuevo código se muestra una
sola vez.

## Cambios de rol y estado

- `waiter` ↔ `cook`: conserva `nip_hash` y `nip_lookup`.
- `waiter`/`cook` → `admin`: limpia ambas columnas y exige una contraseña
  administrativa válida antes de completar el cambio.
- `admin` → `waiter`/`cook`: genera un NIP nuevo; nunca se captura manualmente.
- Un usuario inactivo conserva su NIP y su reserva única, pero no puede iniciar
  sesión. Al reactivarlo vuelve a usar el mismo código.
- Una eliminación física libera naturalmente el `nip_lookup` de ese registro.

## Migración

Para instalaciones existentes:

1. Ejecuta `database/migrations/20260817_reestructurar_acceso_usuarios.sql`
   con un respaldo previo.
2. Configura `NIP_LOOKUP_SECRET` en el entorno del servidor.
3. Ejecuta `php scripts/migrar-credenciales-piso.php` y entrega la salida a
   cada persona de piso. No guardes ese listado.

Los hashes antiguos no permiten reconstruir los NIP, por eso la rutina rota
las credenciales de todos los usuarios `waiter` y `cook`. Los administradores
quedan sin credencial de piso durante la migración.

## Seguridad operacional

El login por NIP hace lookup directo por `nip_lookup`, filtra usuarios activos y
roles de piso y después ejecuta `password_verify()`. No recorre hashes. Los
fallos usan un mensaje genérico y se limitan a cinco intentos por sesión/IP en
una ventana de 60 segundos, sin bloquear cuentas individuales.
