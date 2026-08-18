# Reporte final — refinamiento UX de NIP y consolidación de usuarios

## 1. Estado inicial

La arquitectura de acceso ya estaba estabilizada: `admin` usa usuario y
contraseña; `waiter` y `cook` usan NIP de cuatro dígitos generado por servidor;
`fecha_nacimiento` y la captura manual de NIP ya no existían. El pendiente de
esta etapa era la entrega visual, el flujo de regeneración, la visibilidad por
rol y la consolidación de seeds/documentación.

## 2. Flujo anterior vs. nuevo

Antes, alta y regeneración redirigían al listado y el NIP plano aparecía en
una tarjeta persistente. Ahora el POST hace commit, guarda un flash one-shot y
redirige a la pantalla de creación o edición. El GET consume el flash, abre el
modal y sólo después de “Aceptar” continúa a su destino lógico.

## 3. Alta con modal

Las altas de `waiter` y `cook`, y el cambio `admin → staff`, muestran el NIP en
el modal global de confirmación. “Copiar NIP” conserva el modal abierto,
mantiene ceros iniciales y muestra feedback temporal. “Aceptar” lleva al
listado; el admin se crea sin modal de NIP.

## 4. Regeneración con modal

“Regenerar” es una acción secundaria compacta. Primero abre una confirmación
cancelable que explica la invalidación inmediata del NIP actual. Al confirmar,
el backend hace commit y la edición vuelve con el nuevo NIP en el mismo flujo
de entrega. El modal de entrega no tiene X, no acepta backdrop ni Escape, y
“Aceptar” mantiene al administrador en la edición.

## 5. Comportamiento dinámico por rol

La sección de NIP sólo aparece para `waiter`/`cook` con credencial persistida.
Al seleccionar `admin` se oculta inmediatamente. Para `admin → staff` se
muestra el hint de generación futura y no la acción “Regenerar”. El cambio
`waiter ↔ cook` conserva la credencial.

## 6. Protección del NIP de un solo uso

El valor plano sólo se inserta temporalmente en el DOM de una respuesta que
abre el modal. No aparece en URL, listado, cookies, localStorage,
sessionStorage, analytics ni logs. El trigger se elimina al aceptar y el
listado nunca renderiza el NIP.

## 7. Cache y flash

Las pantallas que contienen el flash aplican `Cache-Control: no-store,
no-cache, must-revalidate, max-age=0`, `Pragma: no-cache` y `Expires: 0`. El
flash se elimina antes de renderizar; Back/Refresh no puede recuperarlo desde
servidor.

## 8. DDL

`database/ddl.sql` mantiene únicamente estructura: `password_hash`, `nip_hash`,
`nip_lookup`, `UNIQUE username`, `UNIQUE nip_lookup` y la invariante SQL que
impide NIP en admins. No se agregaron usuarios ni `INSERT` al DDL.

## 9. Seeds

`database/dml_pruebas.sql` conserva el admin demo. El seed PHP de piso lee
`NIP_LOOKUP_SECRET`, calcula `nip_hash` y `nip_lookup` correctamente y muestra
la entrega sólo en la salida de CLI. Los usuarios `mesero1`, `mesero2`,
`cocinero1`, `mesero3` y `mesero_inactivo` quedan disponibles para desarrollo y
QA.

## 10. Usuarios de desarrollo

`admin_demo` usa la contraseña `Pestalozzi2026` sólo en desarrollo/QA. Los NIP
de piso deben tomarse de la salida de `php scripts/seed-usuarios-prueba.php`
para esa instalación y no se documentan como credenciales de producción.

## 11. Credenciales

El contenido se movió a `docs/usuarios/credenciales.md`, con advertencias de
uso exclusivo en desarrollo, dependencia de `NIP_LOOKUP_SECRET`, ejecución del
seed y login por rol. La fuente anterior de credenciales fue retirada.

## 12. Consolidación documental

`docs/usuarios/usuarios.md` es ahora el contrato canónico del módulo y reúne
roles, matriz de acceso, creación, edición, autenticación, arquitectura NIP,
colisiones, regeneración, migraciones, seeds, UX e invariantes. El documento
duplicado de acceso fue retirado. El reporte actual permanece como histórico de
esta etapa, sin competir con el contrato canónico.

## 13. Archivos eliminados

- La copia anterior de credenciales.
- El documento duplicado de acceso.
- La tarjeta de entrega NIP del listado de usuarios.

## 14. Archivos creados

- `docs/usuarios/usuarios.md`.
- `docs/usuarios/credenciales.md`.

## 15. Archivos modificados

- `controllers/AdminUsersController.php`.
- `views/admin/users/create.php`.
- `views/admin/users/edit.php`.
- `views/admin/users/form.php`.
- `views/admin/users/index.php`.
- `src/js/admin/users/users-form.js`.
- `src/js/components/confirmation-modal.js`.
- `src/scss/admin/modules/users.scss`.
- `CLAUDE.md`.

## 16. Tests

Se ajustaron los contratos estáticos de usuarios para cubrir entrega modal,
flujo one-shot, visibilidad por rol, copia con fallback, cache y ausencia de la
tarjeta/listado. La suite completa se ejecuta con `npm.cmd test`.

## 17. Build

El bundle del módulo se regenera con `npm.cmd run build`. Los bundles generados
se mantienen separados de assets compilados concurrentes ajenos a usuarios.

## 18. Prueba manual

No fue posible completar la verificación manual HTTP porque no había un
servidor HTTP del proyecto disponible en el entorno de trabajo. La validación
debe cubrir alta de waiter, edición dinámica, cancelación/confirmación de
regeneración, copia, permanencia en edición y login con NIP nuevo.

## 19. Commits

Esta etapa quedó en un commit separado con el mensaje
`feat(users): mejorar entrega y regeneración de NIP`, sin push ni mezcla con
cambios concurrentes de otros módulos. El commit anterior de arquitectura se
conserva: `61163ae refactor(auth): generar NIP de personal automáticamente`.

## 20. Riesgos pendientes

La instalación debe configurar `NIP_LOOKUP_SECRET` antes de crear o rotar
credenciales de piso. La migración de datos debe ejecutarse con respaldo y con
entrega coordinada de los NIP nuevos.

## Confirmaciones de alcance

- No se cambió la arquitectura criptográfica del NIP.
- Se mantienen exactamente cuatro dígitos y ceros iniciales.
- No existe captura manual del NIP.
- El NIP actual nunca puede consultarse; regenerar es la única recuperación.
- No se colocaron usuarios ni `INSERT` dentro del DDL.
- Las credenciales documentadas son exclusivamente de desarrollo/QA.
- No se modificaron reservaciones, POS, OTP de clientes ni n8n.
