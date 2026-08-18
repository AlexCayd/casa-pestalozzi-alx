# Reporte: reestructuración del acceso de usuarios

## 1. Estado inicial

El sistema derivaba el NIP desde un dato personal, aceptaba NIP manual en
altas/ediciones y cambios de credencial, consultaba una API de disponibilidad y
recorría todos los hashes con `password_verify()` durante el login.

## 2. Arquitectura final

`Usuario` valida datos base y contraseñas administrativas. `NipService` es la
fuente única para validar/generar NIP, calcular HMAC y controlar pruebas. `UsuarioService`
coordina altas, ediciones, cambios de rol, regeneraciones y transacciones.

## 3. Esquema y migración

`usuarios` ya no almacena el campo retirado. Conserva `nip_hash VARCHAR(255)` y
agrega `nip_lookup CHAR(64) NULL`, `UNIQUE KEY uq_usuarios_nip_lookup` y una
regla `CHECK` que impide credenciales de piso en admins. La migración explícita
es `database/migrations/20260817_reestructurar_acceso_usuarios.sql`.

Como los hashes antiguos no permiten recuperar NIP, la migración se completa
con `scripts/migrar-credenciales-piso.php`, que rota usuarios de piso y muestra
la entrega sólo en la salida de esa ejecución.

## 4. Generación, HMAC y unicidad

Se usa `str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT)`. El hash se calcula
con `password_hash()` y el lookup con HMAC-SHA-256 y `NIP_LOOKUP_SECRET`, que es
obligatorio y vive fuera del repositorio. El código preselecciona candidatos y
la base de datos respalda la autoridad final: una colisión sobre
`uq_usuarios_nip_lookup` hace rollback y reintenta hasta 50 veces.

## 5. Flujos de usuario

- Alta admin: username, nombre, rol, estado y contraseña; ambas columnas de NIP
  quedan en `NULL`.
- Alta waiter/cook: genera NIP dentro de la transacción, guarda hash + lookup y
  lo muestra una sola vez mediante flash de sesión después del commit.
- Regeneración: POST protegido por admin + CSRF + confirmación; sustituye ambas
  columnas en transacción y el NIP anterior deja de funcionar.
- Cambio waiter ↔ cook: conserva la credencial.
- Piso → admin: limpia la credencial y exige contraseña administrativa válida.
- Admin → piso: genera NIP nuevo automáticamente.
- Inactivo: conserva su reserva y no puede entrar; al reactivarse usa el mismo NIP.

## 6. Login y seguridad

El login de piso valida cuatro dígitos, calcula el HMAC y hace `SELECT` directo
por `nip_lookup` con rol y estado. Sólo después ejecuta `password_verify()`. El
mensaje es genérico y hay un límite de cinco fallos por sesión/IP durante 60
segundos. No existe recuperación del NIP actual: regenerar es la única opción.

## 7. Rutas y UI retiradas

Se eliminó el endpoint de disponibilidad y la captura de NIP/confirmación en
formularios. El cambio de contraseña quedó reservado a admins en
`/admin/usuarios/cambiar-password`; el personal usa
`/admin/usuarios/regenerar-nip`. La vista de edición sólo indica “NIP
configurado” y nunca revela el valor actual.

## 8. Archivos relevantes

Se modificaron el modelo, servicios, controladores, rutas, vistas, JS/SCSS del
módulo, DDL, seeds, credenciales de desarrollo, `CLAUDE.md`, `package.json` y
los bundles generados de usuarios. Se agregaron `NipService`, la migración, las
rutinas de migración/seed, el contrato `run-usuarios-acceso.php` y
`docs/usuarios_acceso.md`.

No se tocaron reservaciones, POS, KDS, tickets, OTP de clientes ni n8n. Hay
cambios concurrentes staged de reservaciones fuera de este trabajo; permanecen
separados.

## 9. Pruebas y build

- `npm.cmd test`: pasa la suite PHP y JS completa, incluido el contrato de
  acceso, HMAC, cuatro dígitos y colisión determinista `1234 → 1234 → 5678`.
- `npm.cmd run build`: pasa completo después de un primer intento bloqueado al
  abrir temporalmente un `.map` generado.
- `php -l`: pasa en todos los PHP modificados.
- Prueba manual HTTP: no fue posible realizarla porque no se detectó un
  servidor HTTP local disponible.
- Pruebas contra MySQL real de creación, UNIQUE, regeneración y cambios de rol:
  no se ejecutaron porque no existe `.env` ni cliente/configuración de BD en
  este entorno; quedan cubiertas por el código transaccional y los contratos
  estáticos, y deben ejecutarse al aplicar la migración en una instalación con
  datos.

## 10. Riesgos pendientes

La configuración de cada ambiente debe definir `NIP_LOOKUP_SECRET` antes de
crear o rotar usuarios de piso. La migración de producción debe ejecutarse con
respaldo y entrega coordinada de los nuevos NIP. El espacio de 10,000 códigos
es adecuado para el volumen esperado, pero debe monitorearse la cercanía al
límite.

## 11. Git

Se creó un commit acotado con el mensaje `refactor(auth): generar NIP de
personal automáticamente`. No se hizo push. Los cambios
previos/concurrentes de reservaciones y archivos no relacionados quedaron fuera.
