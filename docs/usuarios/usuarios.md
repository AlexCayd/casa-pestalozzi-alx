# Usuarios

## 1. Objetivo

El módulo administra las cuentas internas de Casa Pestalozzi, sus roles,
estado y credenciales. La base de datos y el backend son la autoridad; la UI
no sustituye las validaciones ni los permisos.

## 2. Roles

- `admin`: administra el panel y se autentica con usuario + contraseña.
- `waiter`: opera el punto de venta y se autentica con NIP de cuatro dígitos.
- `cook`: opera las áreas de producción y se autentica con NIP de cuatro
  dígitos.

## 3. Matriz de acceso

| Rol | Acceso principal | Autenticación |
| --- | --- | --- |
| `admin` | Panel administrativo completo | Usuario + contraseña |
| `waiter` | `/punto-de-venta` | NIP |
| `cook` | `/area` | NIP |

La autorización se valida en las rutas y servicios. Ocultar una acción en el
frontend sólo mejora la UX y nunca concede permisos.

## 4. Creación de usuarios

Desde `/admin/usuarios/create` se capturan usuario, nombre, rol, estado y,
únicamente para `admin`, contraseña administrativa. El frontend nunca acepta
un NIP manual.

Al crear un `waiter` o `cook`, el backend genera el NIP dentro de la
transacción. Después del commit, el POST redirige a la pantalla de creación y
un flash de sesión de un solo consumo abre el modal de entrega. Aceptar lleva
al listado; actualizar o volver a entrar no recupera el NIP.

## 5. Edición

La edición modifica usuario, nombre, rol y estado. Para un administrador, los
campos de contraseña vacíos conservan la contraseña actual. El NIP actual
nunca se muestra.

El estado “NIP configurado” y la acción secundaria “Regenerar” sólo aparecen
para un usuario de piso que ya tiene credencial persistida. Si se selecciona
`admin`, desaparecen inmediatamente. Al pasar de `admin` a `waiter` o `cook`,
la interfaz informa que se generará un NIP al guardar.

## 6. Activación/desactivación

Un usuario inactivo conserva sus hashes y su `nip_lookup`, pero el login lo
rechaza. Al reactivarlo vuelve a usar la misma credencial. Siempre debe existir
un administrador activo.

## 7. Autenticación admin

El login administrativo localiza la cuenta por usuario, exige rol `admin` y
estado activo, y valida la contraseña con `password_verify()`. Los
administradores tienen ambas columnas de NIP en `NULL`.

## 8. Autenticación waiter/cook

El login de piso valida exactamente cuatro dígitos, calcula el lookup HMAC,
localiza directamente una cuenta activa con rol `waiter` o `cook` y sólo
después ejecuta `password_verify()` contra `nip_hash`. El mensaje de error es
genérico para no revelar si falló el usuario o el código.

## 9. Arquitectura NIP

El NIP se genera en el servidor con `random_int()` y ceros iniciales
conservados. El valor plano sólo existe durante la entrega posterior al
commit. No se deriva de datos personales y nunca es editable manualmente.

## 10. `nip_hash`

`nip_hash` contiene `password_hash($nip, PASSWORD_DEFAULT)`. Sirve para
verificar la credencial después de localizar la fila y no permite recuperar el
NIP plano.

## 11. `nip_lookup`

`nip_lookup` contiene un HMAC-SHA-256 determinista del NIP. Permite localizar
la cuenta directamente y soporta la restricción `UNIQUE` sin guardar un
digest precalculable del espacio de 10,000 códigos.

## 12. `NIP_LOOKUP_SECRET`

Es obligatorio para crear, migrar, regenerar o preparar credenciales de piso.
Debe vivir en la configuración del ambiente, no en Git, SQL, documentación ni
logs. Debe mantenerse estable dentro de una instalación; al rotarlo, hay que
preparar nuevamente los NIP.

## 13. Generación automática

`Services\NipService` centraliza formato, generación, HMAC y credenciales.
`Services\UsuarioService` genera el NIP durante altas y cambios de rol que lo
requieren. El máximo de reintentos es 50.

## 14. Unicidad y colisiones

La restricción `uq_usuarios_nip_lookup` de la base de datos es la autoridad
final. Si existe una colisión, el servicio revierte la transacción y prueba
otro candidato hasta el límite establecido. Un `username` duplicado se reporta
como tal y no se confunde con una colisión de NIP.

## 15. Regeneración

La regeneración requiere sesión administrativa, destino válido, rol de piso y
CSRF. Primero se confirma en un modal; cancelar no modifica la base de datos.
Después del commit, el NIP anterior deja de funcionar y el nuevo aparece en
un modal no cancelable con las únicas acciones “Copiar NIP” y “Aceptar”.
Aceptar cierra el modal y mantiene la edición abierta.

## 16. Cambio de roles

- `waiter` ↔ `cook`: conserva `nip_hash` y `nip_lookup`.
- `waiter` o `cook` → `admin`: elimina ambas columnas y exige contraseña
  administrativa cuando corresponde.
- `admin` → `waiter` o `cook`: genera un NIP nuevo al guardar y lo entrega una
  sola vez después del commit.

## 17. Eliminación

La eliminación física libera el `nip_lookup`. El sistema impide dejar el panel
sin un administrador activo; si un admin elimina su propia cuenta, la sesión
se cierra después de la operación.

## 18. Rate limiting

Los fallos de login por NIP usan un mensaje genérico y un límite de cinco
intentos por sesión/IP dentro de 60 segundos. No se bloquean cuentas
individuales.

## 19. Migraciones

Aplica el SQL de migración de acceso de la versión que estés desplegando con un
respaldo previo. El esquema canónico está en `database/ddl.sql`. Después
configura `NIP_LOOKUP_SECRET` y ejecuta
`php scripts/migrar-credenciales-piso.php`; la salida contiene los NIP nuevos
para entregarlos y no debe guardarse.

## 20. Seeds y credenciales de desarrollo

`database/dml_pruebas.sql` crea `admin_demo`. El seed PHP prepara `waiter` y
`cook`, calcula `nip_hash`/`nip_lookup` con el secreto del ambiente y muestra
la entrega sólo en la salida de esa ejecución. Los detalles de QA están en
`docs/usuarios/credenciales.md`. No hay usuarios ni `INSERT` de usuarios en
`database/ddl.sql`.

## 21. UX de entrega única

Las respuestas que renderizan un NIP usan `Cache-Control: no-store,
no-cache, must-revalidate` y `Pragma: no-cache`. El valor plano viaja sólo en
un flash de sesión one-shot hacia una pantalla de creación o edición. No se
coloca en query string, cookies, localStorage, sessionStorage, analytics,
logs ni listado posterior. Copiar conserva el modal abierto y muestra feedback
temporal.

## 22. Ventana temporal de entrega del NIP

Después del commit, la pantalla de alta o edición muestra el NIP en un modal
no cancelable. La credencial se visualiza en texto plano únicamente durante la
ventana configurada para la entrega; esta ventana no expira ni modifica la
credencial persistida.

La única fuente de verdad es
`Services\\UsuarioConfig::NIP_MODAL_VISIBILIDAD_SEGUNDOS`. El valor vigente es
10 segundos. La vista lo expone en `data-nip-visibility-seconds` y el frontend
lo usa para cerrar el modal y animar una barra de progreso discreta.

“Copiar NIP” copia exactamente los cuatro dígitos, conserva el modal abierto y
no reinicia la ventana. “Aceptar” y el cierre automático limpian el NIP del
DOM y del estado JavaScript y continúan al mismo destino: el listado después
del alta o cambio de rol, y la edición después de una regeneración.

## 23. Invariantes

```text
admin
→ nip_hash = NULL
→ nip_lookup = NULL
```

```text
waiter | cook
→ nip_hash != NULL
→ nip_lookup != NULL
```

```text
waiter ↔ cook
→ conserva NIP

staff → admin
→ elimina NIP

admin → staff
→ genera NIP nuevo

NIP
→ exactamente 4 dígitos
→ generado por servidor
→ nunca editable manualmente
→ nunca recuperable

NIP_LOOKUP_SECRET
→ obligatorio
→ no versionado
→ estable dentro de una instalación
```

## 24. Archivos relacionados

- `controllers/AdminUsersController.php`: rutas y PRG/flash.
- `services/UsuarioService.php`: transacciones, roles y mutaciones.
- `services/NipService.php`: generación, hash, HMAC y colisiones.
- `services/UsuarioConfig.php`: configuración de la ventana temporal de entrega.
- `models/Usuario.php`: validación y login.
- `views/admin/users/`: listado y formularios.
- `src/js/admin/users/users-form.js`: estados de rol, confirmación y entrega.
- `src/js/components/confirmation-modal.js`: modal global accesible.
- `src/scss/admin/modules/users.scss`: presentación del módulo.
- `database/ddl.sql`: esquema, índices e invariantes.
- `database/dml_pruebas.sql`: datos demo del administrador.
- `scripts/seed-usuarios-prueba.php`: seed de piso para desarrollo/QA.
- `scripts/migrar-credenciales-piso.php`: rotación controlada durante una
  migración.
