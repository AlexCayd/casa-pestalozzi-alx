# Reporte — Permisos del mapa operativo de reservaciones

Proyecto: Casa Pestalozzi
Fecha: 2026-08-16

## 1. Causa raíz

`Classes\Auth::proteger()` trataba toda ruta que comenzara con `/admin/` como exclusiva de `admin`. El mapa operativo y sus APIs viven bajo `/admin/reservaciones/operacion`, por lo que un `waiter` era redirigido antes de llegar al controlador.

Además, el controlador consultaba siempre con superficie `admin`, y la vista compartida mostraba contacto, alta administrativa y enlace a edición administrativa sin distinguir el rol.

## 2. Matriz final

| Capacidad | admin | waiter | cook |
| --- | --- | --- | --- |
| Mapa operativo de reservaciones | Sí | Sí | No |
| Consulta operativa | Sí | Sí | No |
| Asignar mesas | Sí | Sí | No |
| Liberar mesas | Sí | Sí | No |
| Reasignar mesas | Sí | Sí | No |
| Comentario administrativo | Sí | Sí | No |
| Iniciar servicio / cancelar / no-show | Sí | Sí | No |
| Alta administrativa | Sí | No | No |
| Edición o detalle administrativo completo | Sí | No | No |
| Teléfono/email | Sí, en superficies autorizadas | No | No |

El waiter no puede enviar transiciones arbitrarias al endpoint genérico `/estado`: desde el mapa sólo se aceptan `en_curso`, `cancelada` y `no_show`. El admin conserva el comportamiento previo.

## 3. Rutas habilitadas para waiter

- `GET /admin/reservaciones/operacion`
- `GET /admin/api/reservaciones/operacion`
- `POST /admin/api/reservaciones/operacion/asignar-mesas`
- `POST /admin/api/reservaciones/operacion/liberar-mesas`
- `POST /admin/api/reservaciones/operacion/reasignar`
- `POST /admin/api/reservaciones/operacion/comentario`
- `POST /admin/api/reservaciones/operacion/estado`, con las transiciones operativas indicadas
- Alias de redirección `GET /admin/reservations/operation`

El acceso rápido existente del POS ahora apunta al mismo mapa para `admin` y `waiter`.

## 4. Rutas que continúan siendo admin-only

El resto de `/admin/*` permanece restringido a `admin`, incluyendo el listado completo, alta, detalle, actualización, disponibilidad administrativa, estados y reasignación administrativas, herramientas de mantenimiento, configuración, analíticas, usuarios, inventario, menú, recetas, finanzas y tickets administrativos.

No se relajó globalmente la protección del panel.

## 5. Datos que recibe waiter

El mapa conserva nombre, fecha, hora, comensales, nota, `comentario_admin`, mesas, estado, información operativa de tickets, ventanas, bloqueos y acciones disponibles.

La proyección backend elimina recursivamente `contacto`, `contacto_tipo`, `email`, `telefono` y aliases, incluyendo los campos derivados `contacto_visible` y `contacto_presente`. El waiter tampoco ve la fila de contacto ni el enlace de edición administrativa.

`comentario_admin` continúa visible y editable desde el mapa operativo.

Admin sigue recibiendo la proyección completa autorizada, incluido contacto.

## 6. Implementación

- `Auth` tiene una lista explícita de las rutas compartidas del mapa; cook no forma parte de esa excepción.
- El controlador selecciona superficie `admin` o `waiter` según la sesión y sanitiza respuestas del mapa para waiter.
- El servicio de mapa acepta una opción explícita de liberación operativa para waiter, conservando la regla heredada para admin.
- La vista no emite el modal ni el botón de alta administrativa para waiter.
- La tarjeta operativa sólo muestra contacto y detalle administrativo en superficie admin.
- No se duplicó el mapa ni se modificaron reglas de capacidad, mesas, horarios, estados de mapa o tickets.

## 7. Documentación y fuentes

Se agregó la regla de acceso compartido en `docs/reservaciones/reservaciones.md`, sin duplicar allí el contrato detallado de privacidad.

La solicitud referencia `docs/reservaciones.md`, `docs/verificacion_contacto.md` y `docs/privacidad_datos.md`, pero esos paths no existen en el repositorio actual. Los archivos disponibles siguen siendo `docs/reservaciones/reservaciones.md` y `docs/reservaciones/reservaciones_mantenimiento.md`; no se inventaron reglas a partir de las fuentes ausentes.

## 8. Archivos modificados

- Autorización: `classes/Auth.php`.
- Control y dominio operativo: `controllers/ReservacionOperacionController.php`, `services/ReservacionMapaAdministrativaService.php`, `services/PosReservacionSerializer.php`.
- UI y acceso POS: `views/operation/reservations/index.php`, `views/punto-de-venta/partials/pos-workspace.php`, `src/js/admin/reservations/operation.js`.
- Documentación y pruebas: `docs/reservaciones/reservaciones.md`, `scripts/tests/run-reservaciones-permissions.php`, `scripts/tests/run-privacidad-contract.php`, `package.json`.
- Compilados: bundles de `assets/js/` y `public/build/js/` correspondientes a la superficie modificada.

## 9. Pruebas

Se agregó `run-reservaciones-permissions.php` para comprobar rutas compartidas, rutas admin-only, cook sin acceso, acciones de mesas/comentarios, superficie waiter, privacidad, acceso desde POS y ausencia de edición/alta administrativa. Se ajustó el contrato de privacidad para cubrir aliases derivados del mapa.

- `npm.cmd test`: aprobado completo.
- `npm.cmd run build`: aprobado completo.
- `php -l`: aprobado en los PHP modificados.
- El build sólo emitió advertencias existentes de la API legacy de Sass y `fs.Stats` de Node.

## 10. Prueba manual

No fue posible realizar la verificación manual porque no había un servidor HTTP del proyecto disponible.

## 11. Confirmaciones de no regresión

- No se abrió `/admin/*` de forma general al waiter.
- Cook sigue sin acceso al mapa.
- Contacto sigue siendo admin-only.
- `comentario_admin` sigue visible al waiter.
- No se modificó lógica de capacidad.
- No se modificó lógica de mesas, salvo la autorización explícita de liberar desde la superficie operativa.
- No se modificó lógica de tickets.
- No se modificó OTP ni n8n.
- Los commits anteriores `86cb2fe`, `a52f21f` y `ef45cb8` no fueron reescritos.

## 12. Commit

Se creó un commit independiente, sin push:

`fix(auth): permitir mapa de reservaciones a meseros`
