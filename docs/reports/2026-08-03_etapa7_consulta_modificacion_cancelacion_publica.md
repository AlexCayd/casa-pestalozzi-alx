# Informe de Etapa 7 — Consulta, modificación y cancelación pública de reservaciones

Fecha: 2026-08-03  
Zona horaria de reglas: `America/Mexico_City`  
Estado: implementada y verificada en el entorno local de pruebas.

## 1. Alcance y resultado ejecutivo

Se implementaron la consulta autenticada por sesión, la modificación pública con reemplazo pendiente y OTP ligado, la confirmación atómica del reemplazo, la expiración del hold y la cancelación pública idempotente.

La reservación original no se modifica durante la solicitud de cambio: permanece `confirmada` con sus mesas hasta que el OTP del reemplazo es validado. El reemplazo confirmado pasa a `confirmada` y la original a `reemplazada` dentro de una misma transacción.

No se tocaron POS, mapa, dashboard administrativo ni la creación administrativa. No se inició Etapa 8.

## 2. Fuente de verdad revisada y regla administrativa futura

Se revisó `reservaciones_fuente_de_verdad.md` junto con los informes de Etapas 5, 6, 6.1 y 6.2. Se añadió la sección “Creación administrativa y asignación — Etapa 8”.

La regla futura documentada indica que administración podrá crear reservaciones sin exigir una agrupación predefinida de mesas; la asignación automática será opcional, estará restringida a grupos públicos de 1 a 12 personas y los casos mayores o sin capacidad suficiente quedarán advertidos para asignación manual posterior. Una reservación administrativa activa sin filas en `reservacion_mesas` significará “pendiente de asignación manual”. Esta regla sólo quedó documentada; no se implementó en esta etapa.

## 3. Arquitectura existente y puntos de integración

Se conservaron los puntos de entrada existentes:

- `POST /api/reservaciones/contacto/codigo`: OTP genérico de acceso o reenvío de OTP de operación.
- `POST /api/reservaciones/contacto/verificar`: validación del OTP y creación de sesión.
- `GET /api/reservaciones/mis-reservaciones`: consulta autenticada.
- `POST /api/reservaciones/modificar`: creación del reemplazo pendiente.
- `POST /api/reservaciones/confirmar-modificacion`: confirmación del reemplazo.
- `POST /api/reservaciones/cancelar`: cancelación pública.

La interfaz mantiene el flujo de modal y picker existente. No se crearon rutas duplicadas ni una segunda implementación paralela de modificación: el método heredado `modificar()` quedó como alias de `crearReemplazo()`.

## 4. Acceso, normalización, OTP y sesión

El acceso sigue siendo genérico y no revela si un contacto tiene reservaciones. Email y teléfono se normalizan mediante `ContactoService`; los OTP de acceso se almacenan con `reservacion_id = NULL` y los OTP de creación o modificación quedan ligados a su reservación.

Se corrigió el aislamiento de consultas e invalidaciones de OTP para que un OTP de acceso no invalide un OTP ligado a una operación. Las solicitudes mutantes públicas validan CSRF, incluida la solicitud inicial de acceso.

La sesión de cliente tiene expiración fija. La actividad de consulta ya no extiende silenciosamente la sesión; sólo una nueva verificación crea otra sesión. Las respuestas públicas y el JavaScript ya no reciben el contacto completo verificado salvo donde el usuario lo escribe explícitamente en el flujo correspondiente.

## 5. Consulta pública y autorización

La consulta exige una sesión vigente y cada operación vuelve a comprobar que el contacto de sesión coincide con la reservación. El identificador enviado por la interfaz no se considera autoridad por sí solo.

La lista pública sólo expone los datos necesarios: identificador de operación, nombre, fecha, hora, comensales, nota, etiqueta de estado y permisos de modificar/cancelar. No expone `request_token`, `contacto`, `contacto_tipo`, `hold_expires_at` ni estados técnicos internos.

La visibilidad pública incluye reservaciones `confirmada` hasta la tolerancia de cancelación de 15 minutos posteriores al inicio. No muestra reservaciones `en_curso` ni estados históricos que ya no se pueden gestionar. Si existe un reemplazo pendiente, la reservación original muestra un aviso seguro de que el cambio requiere confirmación y que la original sigue vigente.

## 6. Modificación pública y límites

Sólo se permite modificar una reservación `confirmada` cuando la hora del servidor está como máximo 30 minutos antes del inicio. No se permite modificar reservaciones canceladas, reemplazadas, expiradas o ya iniciadas.

El nombre y el contacto permanecen inmutables. El usuario puede cambiar fecha, horario, cantidad de personas y nota, con el límite público de 1 a 12 personas y la validación canónica de horarios, calendario, capacidad y disponibilidad.

La disponibilidad del nuevo horario se evalúa dentro de la transacción y bajo locks, excluyendo la reservación original para que sus mesas no se contabilicen doblemente durante la preparación del cambio. Si el nuevo horario no está disponible, la original queda intacta.

## 7. Reemplazo, hold, asignación, confirmación y expiración

La modificación crea una nueva fila con `estado = pendiente_verificacion`, `origen = landing` y `reemplaza_reservacion_id` apuntando a la original. El reemplazo tiene su propio `request_token`, OTP ligado, hold de 15 minutos y asignación en `reservacion_mesas`.

La original conserva estado y mesas hasta que se valida el OTP. La confirmación comprueba nuevamente la identidad de sesión, la existencia del reemplazo, la vigencia del hold, el estado confirmado de la original y la existencia de mesas asignadas. Después consume el OTP y cambia ambas filas de forma atómica.

Si el hold vence, el reemplazo pasa a `expirada`, se invalidan sus OTP y la original permanece confirmada con sus mesas. Un nuevo intento de modificación invalida el reemplazo pendiente anterior antes de crear el nuevo, evitando cadenas de reemplazos activos.

## 8. Límite de cinco reservaciones activas

El conteo activo se mantiene en `ReservacionVigenciaService` y excluye explícitamente los reemplazos pendientes mediante `reemplaza_reservacion_id IS NULL`. Por ello, durante una modificación la original sigue contando una sola vez; el reemplazo pendiente no agrega una sexta reservación.

La prueba de Etapa 7 incluye una aserción específica sobre este caso. También se mantuvieron las comprobaciones bajo lock para creación y modificación.

## 9. Cancelación pública

Sólo se permite cancelar una reservación `confirmada` hasta 15 minutos después del inicio, usando la hora del servidor. La cancelación es lógica: conserva la fila histórica y las relaciones de mesas.

Si existía un reemplazo pendiente, la cancelación lo expira e invalida su OTP dentro de la misma transacción. Repetir la cancelación devuelve éxito idempotente sin cambiar nuevamente el estado ni la fecha de cambio. Una reservación ya iniciada o fuera de la tolerancia devuelve una respuesta de operación no permitida.

## 10. Transacciones, locks, rollback e idempotencia

Las operaciones críticas usan el orden compartido de `HorarioConfigLock`, lock por contacto y locks de fechas ordenadas. Dentro de la transacción se bloquean las filas de reservación necesarias con `FOR UPDATE`; la confirmación bloquea el reemplazo por token y la original antes de mover sus estados.

La creación del reemplazo, su asignación de mesas y su OTP se confirman juntos. Un error en cualquiera de ellos hace rollback de la operación. La confirmación consume OTP y cambia ambas filas en una sola transacción. La cancelación invalida el reemplazo pendiente y actualiza la original en la misma transacción.

Los `request_token` hacen idempotentes las repeticiones del mismo payload. Una repetición de creación o confirmación no duplica filas ni vuelve a consumir el OTP.

## 11. Contratos públicos efectivos

Los éxitos devuelven `ok: true` y un código funcional, por ejemplo `REEMPLAZO_CREADO`, `REEMPLAZO_CONFIRMADO` o `RESERVACION_CANCELADA`. Los errores distinguen datos inválidos, sesión expirada, reservación no perteneciente al contacto, cambio no permitido, hold expirado, falta de disponibilidad y conflictos de token.

La respuesta de creación del reemplazo entrega al navegador sólo el identificador de operación necesario para confirmar y, únicamente en el entorno de pruebas, el código OTP de vista previa. En producción el OTP sigue la integración de notificación existente; no se añadió envío real de correo, SMS o WhatsApp en esta etapa.

## 12. Interfaz de usuario y responsive

Se reutilizó el modal público existente. El editor deja el nombre sólo como lectura y permite fecha, horario, personas y nota. Se añadió un bloque de OTP de modificación con contador, reenvío y confirmación. La tarjeta de consulta indica cuándo hay un cambio pendiente y cuándo la reservación original sigue vigente.

El date picker limita la selección al horizonte operativo existente. Se regeneró el bundle JavaScript y se actualizó la versión de caché de la landing.

La verificación realizada fue de estructura, sintaxis y flujo de servicio; no se hizo una auditoría visual manual completa en navegador para cada breakpoint. Esto queda como riesgo residual de presentación, no como cambio de contrato.

## 13. Pruebas unitarias y de comportamiento

Resultados locales:

- `tests/php/etapa5_nucleo.php`: PASS, 58/58.
- `tests/php/etapa6_publica.php`: PASS, 46/46.
- `tests/php/etapa6_2_fecha_horarios_capacidad.php`: PASS, 20/20.
- `tests/php/etapa7_publica.php`: PASS, 25/25.
- `tests/js/reservation-form-state.test.js`: PASS.
- `node --check src/js/modules/reservation-access.js`: PASS.
- `php -l` en controladores, modelos, servicios, router público y prueba de Etapa 7: PASS.

La prueba de Etapa 7 cubre acceso, separación de OTP, confirmación original, reemplazo, idempotencia, asignación, límite activo, expiración, conservación de mesas, cancelación y respuesta pública segura.

## 14. Pruebas de integración

Pasaron `tests/php/pos_reservacion_contrato.php` y `tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`, incluyendo el contrato de reservaciones del POS, tickets con varias mesas, concurrencia y paridad de estados.

También se inició temporalmente el servidor PHP local y se consultó el endpoint de disponibilidad. Una fecha cerrada devolvió HTTP 422 con JSON válido, sin crear IDs, mesas, capacidad ni tickets. El servidor temporal se detuvo al terminar la comprobación.

## 15. Pruebas de concurrencia

La prueba de concurrencia existente de Etapa 6 pasó dentro de la regresión y sigue cubriendo el lock por contacto, la carrera de creación y la protección contra duplicados. En Etapa 7 se probaron repeticiones de creación y confirmación, además de la secuencia de expiración y cancelación bajo transacción.

No se añadió una prueba multiproceso independiente que lance simultáneamente “confirmar cambio” y “cancelar original”; esa combinación queda como verificación adicional recomendada antes de producción. La implementación comparte los mismos locks por contacto, configuración y fechas, y las transiciones de estado están protegidas por filas bloqueadas.

## 16. Instalación limpia y regeneración

Se regeneró el bundle con `node node_modules/gulp/bin/gulp.js js`, que terminó correctamente. La regresión de Etapas 5 y 6 se ejecutó con las dependencias locales instaladas.

No se hizo una instalación limpia destructiva de Composer/NPM durante esta etapa. El riesgo asociado es de reproducibilidad del entorno, no de la lógica de reservaciones.

## 17. Compatibilidad con POS

No se modificó el contrato de reservaciones consumido por POS ni se borraron mesas o reservaciones históricas durante la cancelación pública. La suite de contrato e integración del POS pasó.

Las nuevas filas de reemplazo conservan `origen = landing` y sus propias asignaciones; la resolución pública no reconstruye ni altera tickets, mapa o funciones administrativas.

## 18. Archivos principales modificados

- `services/ReservacionPublicaService.php`: reglas de modificación, reemplazo, confirmación, expiración, cancelación e idempotencia.
- `services/ReservacionVigenciaService.php`: visibilidad pública y conteo activo sin doble conteo de reemplazos.
- `services/ContactoAccesoService.php` y `models/VerificacionContacto.php`: separación de OTP de acceso y OTP ligado.
- `services/ReservationClientSession.php`: expiración fija de sesión.
- `controllers/ReservacionController.php` y `public/index.php`: CSRF, consulta segura y nueva confirmación de modificación.
- `models/Reservacion.php`: consulta de reemplazos pendientes.
- `views/home/_reserva.php`, `src/js/modules/reservation-access.js` y bundle generado: editor, OTP y mensajes públicos.
- `reservaciones_fuente_de_verdad.md`: regla administrativa futura de Etapa 8.
- `tests/php/etapa7_publica.php`: cobertura funcional de la etapa.

También se actualizaron los artefactos compilados existentes del frontend; no se generaron secretos ni archivos de entorno.

## 19. Código legado y duplicidad

La ruta heredada de modificación sigue disponible para compatibilidad, pero delega en `crearReemplazo()` y ya no actualiza directamente la original. No hay dos caminos activos con reglas distintas para el cambio público.

La asignación, vigencia, límites, autorización de contacto y normalización permanecen centralizadas en los servicios existentes y no se duplicaron en la vista.

## 20. Regla administrativa futura

La creación administrativa de Etapa 8 deberá distinguir claramente entre crear una reservación y asignarle mesas. Puede crear sin mesas cuando no exista agrupación o capacidad automática suficiente, mostrando advertencia reforzada y dejando la reservación pendiente de asignación manual. La regla pública estricta de 1 a 12 personas, disponibilidad y modificación no cambia.

Esta regla está documentada, pero no se habilitaron endpoints, botones ni asignación administrativa en Etapa 7.

## 21. Accesibilidad y ARIA

No se hizo una revisión sistemática de ARIA ni se amplió el alcance a correcciones de accesibilidad. La instrucción de la etapa fue conservar ese trabajo fuera de alcance.

## 22. Limitaciones conocidas

- No hay integración real nueva de correo, SMS o WhatsApp; el OTP de producción depende del canal existente.
- Falta una prueba multiproceso específica para la carrera confirmar-versus-cancelar de Etapa 7.
- Falta una instalación limpia completa de dependencias.
- Falta una revisión visual manual completa en navegador, especialmente en mobile, aunque el markup conserva el patrón responsive existente.
- Las rutas mantienen el contrato existente con endpoints estáticos y `reservacion_id`/`request_token` en el cuerpo; no se migraron a URLs dinámicas porque no era necesario ni seguro introducir rutas duplicadas en esta etapa.

## 23. Riesgos y severidad

| Riesgo | Severidad | Mitigación o siguiente verificación |
|---|---:|---|
| Carrera exacta entre confirmar modificación y cancelar original no probada en dos procesos | Media | Ejecutar prueba multiproceso antes de producción; la implementación usa locks y transacción única |
| Diferencias de entorno limpio de Composer/NPM | Baja | Ejecutar instalación limpia en CI o entorno de staging |
| Detalles visuales no revisados en todos los breakpoints | Baja | Revisión manual de landing, editor OTP y tarjetas en desktop/mobile |
| Canal de notificación OTP no ejercitado con proveedor real | Media | Prueba de staging del adaptador de notificación existente |

No se identificó un riesgo alto de corrupción de estados en las pruebas ejecutadas: la original queda protegida durante el hold y la transición confirmada se realiza en una transacción.

## 24. Decisión de cierre y continuidad

La gestión pública queda aceptable para cerrar Etapa 7 en el entorno local: consulta, modificación por reemplazo, confirmación OTP, expiración, cancelación, privacidad, límites e idempotencia están implementados y las pruebas principales pasan.

Etapa 8 no se inicia automáticamente. La recomendación es continuar sólo después de ejecutar las verificaciones pendientes de concurrencia cruzada, instalación limpia, canal OTP real y revisión visual. El alcance administrativo futuro ya está documentado para que no se reconstruyan reglas incompatibles.
