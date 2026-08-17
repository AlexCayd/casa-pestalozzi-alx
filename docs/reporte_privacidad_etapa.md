# Reporte — Sanitización y minimización de datos por superficie

Proyecto: Casa Pestalozzi  
Fecha: 2026-08-16  
Rama: `main`  

## 1. Resultado

Se implementó la proyección de datos por consumidor para reservaciones, con cierre por defecto hacia la superficie de mesero/POS. Administración conserva el detalle de contacto; las respuestas del POS y sus estructuras anidadas ya no exponen datos de contacto ni alias equivalentes.

También se retiró la previsualización de OTP del flujo real y del navegador, se neutralizaron los textos de indicaciones públicas, se actualizó el aviso de privacidad, se dejó de exportar `pinData` desde `n8n/exportar.js` y se desindexaron los archivos de sesión sin borrar sus copias locales.

## 2. Fuentes de autoridad

La solicitud referencia `docs/reservaciones.md`, `docs/verificacion_contacto.md` y `docs/privacidad_datos.md`. Esos tres paths no existen en el estado actual del repositorio. Los archivos disponibles son:

- `docs/reservaciones/reservaciones.md`
- `docs/reservaciones/reservaciones_mantenimiento.md`

Se usó el primer archivo disponible como autoridad operativa para reservaciones y el contenido vigente de `views/home/_privacidad.php` como base del aviso. No se inventaron reglas legales, retenciones, proveedores concretos, horarios, capacidades ni condiciones de operación. La revisión legal y la reconciliación de las rutas documentales faltantes quedan pendientes.

## 3. Superficies revisadas

Se revisaron las consultas, serializadores, servicios y respuestas de:

- `/api/punto-de-venta`
- `/api/punto-de-venta/reservaciones`
- `/api/punto-de-venta/mesa-contexto`
- `/admin/api/open-ticket`
- `/api/punto-de-venta/reservaciones/comenzar`
- cancelación y no-show de reservaciones en POS
- operación administrativa de reservaciones y mapa administrativo

La proyección se aplica en backend. Además, las respuestas mutables del controlador POS se sanitizan antes de entregarse a usuarios que no son administradores, incluyendo reservaciones anidadas en advertencias o resultados de apertura de ticket.

## 4. Contrato de datos

### Administración

Conserva el contacto completo necesario para la gestión interna, incluyendo `contacto_tipo` y `contacto` cuando están disponibles.

### Mesero/POS

Conserva únicamente el contexto operativo necesario: nombre de la reservación, fecha y hora, comensales, mesas, estado, nota, `comentario_admin`, ticket y acciones disponibles.

Se eliminan de forma recursiva, sin importar mayúsculas o minúsculas, estas claves y aliases:

`contacto`, `contacto_tipo`, `email`, `telefono`, `phone`, `mobile`, `correo`, `correo_electronico`, `numero_telefono`, `contact`, `contact_info`.

La regla es fail-closed: las consultas usan `waiter` por defecto; los consumidores administrativos actuales declaran explícitamente `admin`.

La fila de contacto se eliminó del modal de POS. La tarjeta operacional compartida conserva la posibilidad de mostrar el contexto en la ruta administrativa, pero el POS no activa esa opción.

## 5. Landing y privacidad

Los placeholders públicos de alta y edición se cambiaron de referencias a alergias a indicaciones neutrales: celebración, ubicación preferida, accesibilidad u otra indicación para la visita.

El aviso de privacidad ahora menciona datos de nombre, email/teléfono, datos de visita e indicaciones opcionales; el propósito de gestionar la reservación y enviar códigos de verificación; el procesamiento por proveedores tecnológicos por cuenta del restaurante; y que no se enviarán comunicaciones comerciales o publicitarias no solicitadas. Se mantuvo el texto legal y de contacto existente, sin agregar información no verificada.

## 6. OTP y verificación

- Se eliminaron `preview_code`, `OTP_GENERADO`, el flag de preview y las vistas/estilos de previsualización.
- Las respuestas públicas conservan únicamente la expiración del OTP.
- El navegador ya no recibe código plano ni texto de “código de prueba”.
- Se conservaron generación, hash, persistencia, expiración, intentos e invalidación del código anterior.
- Se agregó `FakeContactNotificationProvider` para pruebas, que captura el código fuera del flujo real sin exponerlo al navegador.

## 7. Sesiones y n8n

`storage/sessions/` ya estaba ignorado, pero tenía cuatro archivos versionados. Se removieron del índice con `git rm --cached`; sus archivos locales permanecen en disco y no se reescribió el historial.

`n8n/exportar.js` dejó de seleccionar y emitir `pinData`. Los workflows existentes no se modificaron. Los archivos actuales `n8n/sugerencias.json` y `n8n/areas-de-mejora.json` conservan su `pinData: {}` porque están fuera de alcance.

## 8. Archivos principales modificados

- Backend: `services/PosReservacionSerializer.php`, `services/PosReservacionQueryService.php`, `services/PuntoVentaReservacionService.php`, `controllers/PuntoVentaController.php`, `controllers/AdminPuntoVentaController.php`, `controllers/ReservacionOperacionController.php`.
- OTP: `services/ContactoAccesoService.php`, `services/ReservacionPublicaService.php`, `services/ReservacionConfig.php`, `services/DevelopmentContactNotificationProvider.php`.
- Frontend: `src/js/modules/form.js`, `src/js/modules/reservation-access.js`, `src/js/modules/punto-de-venta.js`, `src/scss/components/_reserva.scss`, `views/home/_reserva.php`, `views/home/_privacidad.php`.
- Documentación y pruebas: `docs/reservaciones/reservaciones.md`, `docs/reservaciones/reservaciones_mantenimiento.md`, `scripts/tests/FakeContactNotificationProvider.php`, `scripts/tests/run-privacidad-contract.php`, `scripts/tests/run-reservaciones-js-syntax.cjs`, `package.json`.
- Compilados: bundles correspondientes en `assets/` y `public/build/`.

No se cambiaron reglas de capacidad, asignación de mesas, intervalos, horarios, holds, estados, tickets ni lógica de operación; solo se modificaron proyecciones, presentación y textos de privacidad.

## 9. Pruebas y verificación

- `npm.cmd test`: aprobado. Incluye el contrato de privacidad/POS, pruebas PHP existentes, sintaxis JS y pruebas de advertencias, refresh y open-ticket.
- `npm.cmd run build`: aprobado. Solo emitió advertencias deprecadas de la API JS de Sass y de `fs.Stats` de Node.
- `php -l`: aprobado en los PHP modificados.
- `git diff --check`: sin errores de contenido; Git reporta únicamente avisos de conversión LF/CRLF de archivos existentes.
- Se verificó que `preview_code`, el código de prueba y el modo de desarrollo no aparecen en los bundles públicos de reservaciones.
- La verificación manual en navegador no pudo ejecutarse porque `http://localhost/casa-pestalozzi/public/` respondió `ERR_CONNECTION_REFUSED`; XAMPP no estaba sirviendo HTTP en ese momento.

## 10. Riesgos y pendientes

- Confirmar las tres fuentes documentales referenciadas cuando estén disponibles y reconciliar cualquier diferencia normativa.
- Hacer revisión legal del aviso de privacidad, especialmente retención y proveedores.
- Probar con el proveedor real de SMS/WhatsApp en un entorno controlado; las pruebas automatizadas usan el proveedor falso.
- Revisar posteriormente la limpieza histórica de OTP, HMAC, ocultamiento de errores y el proveedor externo; no fueron alterados en esta etapa.

## 11. Commits

Se dejarán dos commits locales, sin push:

1. `feat(privacidad): minimizar datos expuestos por reservaciones`
2. `chore(security): retirar sesiones y pinData versionable`

