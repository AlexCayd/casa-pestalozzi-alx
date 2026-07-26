# Reporte acumulativo — Módulo de reservaciones

| Etapa | Estado | Objetivo | Resultado |
| --- | --- | --- | --- |
| Etapa 1 | Completada | Identidad y acceso | Conservar |
| Etapa 2 | Completada | Gestión pública | Conservar |
| Etapa 3 | Completada | Horarios, POS y capacidad física | Horario canónico, ocupación por tickets e integración operativa implementados y verificados en desarrollo/testing |
| Etapa 4 | Pendiente | Notificaciones y estabilización | — |

## Etapa 1 — Identidad por contacto y acceso seguro

### Fecha

25 de julio de 2026.

### Estado inicial

- `reservaciones` almacenaba únicamente `email`; no había teléfono ni identidad normalizada.
- El formulario público `POST /reservar` creaba la reservación y ejecutaba la asignación de mesas existente.
- `request_token` ya existía, pero sólo como llave de idempotencia de creación; no era un token de confirmación ni un OTP.
- Los estados reales eran `pendiente`, `confirmada`, `completada`, `cancelada` y `no_show`.
- Las sesiones administrativas usaban claves planas (`login`, `id`, `nombre`, `username`, `rol`).
- No existían verificaciones por contacto, sesión pública de cliente, consulta pública de reservaciones ni validación de teléfono.
- La normalización de correo estaba parcialmente duplicada dentro de `ReservacionService`.
- No había suite automatizada. El esquema limpio se administra directamente mediante `database/ddl.sql` y `database/dml.sql`.

Estas diferencias obligaron a conservar el flujo de creación actual, separar la nueva sesión del staff y tratar `request_token` como una función distinta del OTP.

### Objetivo

Permitir que una persona verifique un correo o teléfono con un código de seis dígitos, obtenga una sesión temporal y consulte exclusivamente sus reservaciones activas futuras, sin habilitar todavía creación nueva, modificación, cancelación, retenciones ni notificaciones reales.

### Alcance implementado

- Selector visible entre “Nueva reservación” y “Gestionar mis reservaciones”.
- Normalización centralizada de correo y teléfono mexicano.
- OTP de seis dígitos, cinco minutos de vigencia, cinco intentos y reenvío mínimo de 60 segundos.
- Persistencia exclusiva de `password_hash()` e invalidación de códigos anteriores.
- Consumo transaccional con `SELECT ... FOR UPDATE`.
- Preview sólo con doble condición de servidor: entorno development/testing y bandera explícita.
- Proveedor de notificación simulado, sin llamadas externas.
- Sesión pública renovable por actividad durante 30 minutos.
- Consulta de reservaciones por la identidad de sesión, no por query string.
- Conteo y límite informativo de cinco reservaciones activas.
- Logout idempotente del namespace público.
- DML reproducible para los seis escenarios solicitados.

### Decisiones de arquitectura

1. La identidad es la tupla `contacto_tipo + contacto_normalizado`. Correo y teléfono no se fusionan.
2. El portal consulta exclusivamente `contacto_normalizado`. Los campos `email` y `telefono` se conservan por compatibilidad.
3. Las altas y ediciones actuales sincronizan `contacto_tipo`, `contacto_valor` y `contacto_normalizado`; el DML hace el backfill de datos legacy.
4. El teléfono exige `+52` y diez dígitos. Se eliminan espacios, guiones, paréntesis y puntos, pero nunca se agrega el país implícitamente.
5. La validación correcta bloquea y consume la fila OTP dentro de una transacción antes de crear la sesión.
6. La sesión usa `reservation_client` y conserva cualquier sesión administrativa existente.
7. La expiración elegida es por inactividad: cada consulta válida renueva 30 minutos.
8. El portal no devuelve IDs, mesas, notas, tokens ni datos administrativos.
9. `pendiente` y `confirmada` son los estados activos reales. Se excluyen fechas/horas finalizadas y los estados finales.

### Modelo de datos

`reservaciones` incorpora:

- `telefono`
- `contacto_tipo`
- `contacto_valor`
- `contacto_normalizado`
- índice compuesto por contacto, estado, fecha y hora

`verificaciones_contacto` incorpora:

- contacto tipo y normalizado;
- `codigo_hash` de 255 caracteres;
- vencimiento;
- intentos y máximo;
- marcas de uso e invalidación;
- timestamps;
- checks e índices por contacto, expiración, uso e invalidación.

No existe ninguna columna para guardar el OTP original.

### Archivos creados

- `includes/.env.example`
- `models/VerificacionContacto.php`
- `services/ContactoService.php`
- `services/ContactoAccesoService.php`
- `services/ContactNotificationProvider.php`
- `services/DevelopmentContactNotificationProvider.php`
- `services/ReservationClientSession.php`
- `src/js/modules/reservation-access.js`
- `tests/ReservacionContactoEtapa1Test.php`
- `tests/.sessions/.gitignore`
- `REPORTE_CAMBIOS_MODULO_RESERVACIONES.md`

### Archivos modificados

- `controllers/ReservacionController.php`
- `database/ddl.sql`
- `database/dml.sql`
- `models/Reservacion.php`
- `public/index.php`
- `services/ReservacionConfig.php`
- `services/ReservacionService.php`
- `src/js/app.js`
- `src/scss/components/_reserva.scss`
- `views/home/_reserva.php`
- assets compilados en `assets/css`, `assets/js`, `public/build/css` y `public/build/js`

La eliminación previa de `storage/.gitignore` ya estaba presente al iniciar esta etapa y no pertenece a estos cambios.

### Métodos nuevos

- `ContactoService::normalizar()`
- `ContactoService::normalizarEmail()`
- `ContactoService::normalizarTelefono()`
- `ContactoService::enmascarar()`
- `ContactoAccesoService::solicitarCodigo()`
- `ContactoAccesoService::verificarCodigo()`
- `VerificacionContacto::buscarRecienteParaActualizar()`
- `VerificacionContacto::invalidarActivas()`
- `VerificacionContacto::crearHash()`
- `VerificacionContacto::registrarIntentoFallido()`
- `VerificacionContacto::marcarUsada()`
- `ReservationClientSession::start()`
- `ReservationClientSession::crear()`
- `ReservationClientSession::obtener()`
- `ReservationClientSession::cerrar()`
- `Reservacion::buscarActivasPorContacto()`
- `Reservacion::contarActivasPorContacto()`
- endpoints públicos nuevos en `ReservacionController`
- `ReservacionConfig::otpPreviewEnabled()`, `otpSendEnabled()` y `appEnvironment()`

### Métodos modificados

- `ReservacionService::crearReservacion()` sincroniza la identidad de correo.
- `ReservacionService::actualizarFila()` sincroniza cambios del correo legacy.
- `initReservationAccess()` se invoca desde el arranque de la home.

### Rutas agregadas

- `POST /api/reservaciones/contacto/codigo`
- `POST /api/reservaciones/contacto/verificar`
- `GET /api/reservaciones/mis-reservaciones`
- `POST /api/reservaciones/contacto/logout`

### Variables de entorno

```env
APP_ENV=development
CONTACT_OTP_PREVIEW=true
CONTACT_OTP_SEND_ENABLED=false
```

La vista previa sólo se activa si `APP_ENV` es `development` o `testing` y `CONTACT_OTP_PREVIEW=true`. El proveedor actual no envía mensajes aunque la arquitectura ya acepta una implementación posterior.

### Flujo anterior

`Formulario público → POST /reservar → validación → creación → asignación de mesas`

No existía una ruta pública segura para consultar reservaciones.

### Flujo resultante

`Gestionar mis reservaciones → contacto → solicitar OTP → preview de desarrollo → validar OTP → sesión reservation_client → consulta por contacto normalizado → tarjetas de sólo lectura`

El flujo anterior de “Nueva reservación” permanece sin cambios funcionales.

### Validaciones

- Tipo limitado a `email` o `telefono`.
- Correo válido, sin espacios externos y en minúsculas.
- Teléfono mexicano en formato internacional explícito.
- OTP exactamente numérico de seis dígitos.
- Vencimiento, uso, invalidación e intentos comprobados bajo lock.
- Reenvío limitado y códigos previos invalidados al generar uno nuevo.
- Sesión requerida para consultar; el contacto de la petición nunca es autoridad.
- Lista limitada a cinco tarjetas y conteo completo para `can_create_reservation`.

### Medidas de seguridad

- Generación con `random_int(100000, 999999)`.
- Persistencia con `password_hash()` y validación con `password_verify()`.
- Nunca se devuelve hash, ID interno ni detalle de intentos restantes.
- Preview controlado en PHP, no por CSS o JavaScript.
- OTP de un solo uso y validación transaccional.
- `session_regenerate_id(true)` tras verificar.
- Cookie `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS.
- Namespace público separado del administrativo.
- Respuestas de solicitud sin revelar si el contacto ya tiene reservaciones.
- SELECT público limitado a los campos necesarios.

### Pruebas ejecutadas y resultados

- `php -l` sobre los PHP modificados: sin errores.
- `node --check` sobre JavaScript modificado: sin errores.
- `php tests/ReservacionContactoEtapa1Test.php`: 33 comprobaciones aprobadas.
- Carga completa de `ddl.sql` y `dml.sql` en `casa_pestalozzi_etapa1_test`: aprobada.
- Ausencia de columnas y valores OTP planos: aprobada.
- Preview en desarrollo y ausencia en configuración production: aprobadas por integración.
- Normalización de email y teléfono: aprobada.
- Código correcto, incorrecto, vencido, invalidado, usado y máximo de intentos: aprobados.
- Reenvío inmediato e invalidación del anterior: aprobados.
- Sesión, regeneración, expiración, logout y coexistencia administrativa: aprobados por integración.
- Sin sesión, aislamiento entre contactos, cero/una/cinco activas e históricos excluidos: aprobados.
- `npm.cmd run build`: aprobado; Sass/Node emitieron únicamente advertencias deprecadas del toolchain.
- Diagnóstico HTTP previo a mutaciones:
  - `environment_database=casa_pestalozzi_etapa1_test`
  - `connected_database=casa_pestalozzi_etapa1_test`
  - `APP_ENV=development`
  - tabla `verificaciones_contacto` presente
- Navegador:
  - tabs y formulario visibles;
  - preview de desarrollo visible;
  - código incorrecto visible;
  - código correcto crea sesión;
  - una activa muestra una tarjeta y permite crear;
  - quinto intento bloquea;
  - código vencido se rechaza;
  - reenvío inmediato muestra espera;
  - cinco activas muestran cinco tarjetas y bloquean creación;
  - sesión vencida vuelve al acceso;
  - revisión visual de escritorio aprobada.

### Pruebas no ejecutadas

- No se probó envío real por WhatsApp, SMS o correo porque está fuera de alcance.
- No se probó HTTPS real; `Secure` se decide con el protocolo de la petición.
- No se volvió a enviar el formulario de “Nueva reservación” en navegador para evitar ejercitar o alterar la lógica de asignación de mesas que esta etapa debe conservar.
- No se hizo una sesión administrativa real en navegador; la coexistencia se verificó en integración.
- El logout del portal no produjo transición visual durante la automatización, aunque el endpoint idempotente respondió correctamente y la limpieza aislada de sesión pasó en integración. Debe repetirse manualmente en el entorno XAMPP normal.
- La simulación de production se validó en integración, no mediante un segundo servidor visual.

### Problemas encontrados

1. El cliente `mysql.exe` de XAMPP no pudo autenticar contra MySQL 9.7 por falta del plugin `caching_sha2_password`; la carga limpia se ejecutó con `mysqli`, el mismo driver de la aplicación.
2. PowerShell bloqueó `npm.ps1`; el build se ejecutó correctamente mediante `npm.cmd`.
3. El sandbox no permite escribir sesiones en `C:\xampp\tmp`; la prueba manual usó `tests/.sessions`, sin cambiar la configuración productiva.
4. Un primer diagnóstico detectó conexión a la base principal. No se ejecutó ningún POST en ese estado. El router temporal se corrigió, se exigió coincidencia entre entorno y `SELECT DATABASE()`, y sólo entonces continuaron las mutaciones.
5. Los procesos y archivos temporales de navegador fueron eliminados; el puerto 8091 quedó libre y la base desechable fue borrada.

### Limitaciones

- El proveedor es simulado y no entrega códigos fuera del preview.
- El acceso no está declarado listo para producción.
- No hay todavía rate limiting por IP o infraestructura distribuida.
- No hay fusión de identidades email/teléfono.
- Las reservaciones legacy necesitan backfill mediante el DML o una operación equivalente al desplegar el esquema.
- Modificar y cancelar se mantienen fuera del portal.

### Pendientes de la Etapa 2

- Reemplazar el flujo público de creación y aplicar el máximo de cinco antes de crear.
- Diseñar retenciones temporales de mesas.
- Habilitar modificación y cancelación públicas con reglas de negocio completas.
- Resolver concurrencia y expiración de retenciones.
- Mantener la identidad verificada como autoridad para todas las mutaciones.
- No abordar todavía POS, tickets, WhatsApp o reconfirmación.

### Veredicto parcial

La Etapa 1 queda completada para desarrollo y testing: la base de identidad, OTP, sesión y consulta segura funciona y dispone de pruebas reproducibles. No se declara lista para producción y no se avanzó a la Etapa 2.

## Etapa 2 — Creación, retención, modificación y cancelación

### Fecha

25 de julio de 2026.

### Estado inicial

- `POST /reservar`, registrado en `public/index.php`, llegaba a `ReservacionController::crear()` y delegaba en `ReservacionService::crearPublica()`.
- El flujo anterior podía devolver éxito sin una mesa asignada y creaba inicialmente el estado legacy `pendiente`.
- La asignación pública usaba `AsignacionMesasService`, combinaciones autorizadas en `ReservacionConfig` y una duración real de 90 minutos, con 30 minutos previos de bloqueo.
- Horarios semanales, excepciones, cierres y horarios especiales ya estaban centralizados en `HorarioOperacionService` y `HorarioReservacionService`.
- `request_token` evitaba una segunda inserción, pero no comparaba el contenido normalizado de la solicitud.
- La única serialización de capacidad era el lock asesor por fecha de `FechaOperacionLock`; no existía lock estable por contacto.
- `pendiente` sí tenía uso operativo administrativo. Por ello se conservó como estado legacy activo y no se reinterpretó como retención pública.
- La consulta del portal y la sesión `reservation_client` de la Etapa 1 eran de sólo lectura.
- Las pruebas base de Etapa 1 aprobaron sus 33 comprobaciones antes y después de sustituir el flujo público.

### Objetivo

Completar el ciclo público de explorar disponibilidad, retener mesas, verificar el contacto, confirmar, crear directamente con sesión válida, modificar y cancelar; todo con capacidad revalidada, máximo de cinco, idempotencia, transacciones y locks reproducibles, sin tocar punto de venta, tickets ni notificaciones reales.

### Alcance implementado

- Disponibilidad pública por fecha y personas sin requerir sesión.
- Selección definitiva de una a tres mesas mediante la misma estrategia usada por las mutaciones.
- Retención pública de cinco minutos en `pendiente_verificacion`.
- OTP ligado a la retención y creado dentro de su misma transacción.
- Confirmación atómica de la retención al consumir correctamente el OTP.
- Alta directa `confirmada` cuando `reservation_client` es válida, sin generar otro OTP.
- Máximo transaccional de cinco activas por contacto canónico.
- Modificación de nombre, fecha, hora, personas y notas, preservando la asignación original ante fallos.
- Cancelación lógica e idempotente.
- Expiración en lotes, también idempotente, sin borrar relaciones históricas.
- Gestión en la home con tarjetas, editor, cancelación, mensajes y actualización sin recarga completa.
- DDL limpio, DML con quince casos documentados y suite acumulativa reproducible.

### Decisiones de arquitectura

1. `DisponibilidadReservacionService` es la fuente única de verdad tanto para el GET orientativo como para la revalidación bajo transacción.
2. `request_token` sigue siendo sólo una llave de idempotencia. El OTP es un secreto diferente y sólo se persiste mediante hash.
3. El fingerprint es SHA-256 del payload canónico: nombre, identidad normalizada, fecha, hora, personas y notas.
4. Las retenciones conservan la asignación física. Al confirmar no se eligen otras mesas.
5. Una retención vencida deja de ocupar inmediatamente por su timestamp, aunque todavía no haya sido materializada como `expirada`.
6. `pendiente_verificacion` no cuenta en el máximo de cinco. `confirmada` y el legacy administrativo `pendiente` sí cuentan mientras su intervalo de 90 minutos no haya terminado.
7. El orden global es contacto → fechas ordenadas → transacción → reservación → mesas por ID → asignaciones.
8. Modificar y cancelar se permiten con precisión al segundo: a la hora exacta todavía se permite; un segundo después se rechaza. La tolerancia operativa de 15 minutos queda fuera de esta etapa.
9. Las relaciones en `reservacion_mesas` se conservan al expirar o cancelar para mantener el historial.
10. Los tickets abiertos no participan todavía en capacidad. Este riesgo queda expresamente reservado para la Etapa 3.

### Archivos creados

- `services/ContactoOperacionLock.php`
- `services/DisponibilidadReservacionService.php`
- `services/ReservacionPublicaService.php`
- `scripts/dev_router.php`
- `scripts/expire_reservation_holds.php`
- `scripts/run-tests.php`
- `tests/ReservacionEtapa2ConcurrencyWorker.php`
- `tests/ReservacionPublicaEtapa2Test.php`

### Archivos modificados

- `controllers/ReservacionController.php`
- `database/ddl.sql`
- `database/dml.sql`
- `models/Reservacion.php`
- `models/ReservacionMesa.php`
- `models/VerificacionContacto.php`
- `public/index.php`
- `services/ContactoAccesoService.php`
- `services/ReservacionConfig.php`
- `services/ReservacionService.php`
- `src/js/app.js`
- `src/js/components/reservation-time-picker.js`
- `src/js/modules/form.js`
- `src/js/modules/reservation-access.js`
- `src/scss/components/_reserva.scss`
- `views/home/_reserva.php`
- `views/home/index.php`
- assets compilados en `assets/css`, `assets/js`, `public/build/css` y `public/build/js`
- `REPORTE_CAMBIOS_MODULO_RESERVACIONES.md`

La eliminación previa de `storage/.gitignore` sigue siendo ajena a esta implementación.

### DDL

`reservaciones` incorpora:

- estados `pendiente_verificacion` y `expirada`, conservando `pendiente`;
- `verification_expires_at`;
- `confirmed_at`;
- `expired_at`;
- `cancelled_at`;
- `request_fingerprint`;
- índice por fecha, estado y hora;
- índice por estado y vencimiento;
- índice por contacto, estado y fecha;
- unicidad de `request_token`;
- checks para la longitud del fingerprint y el vencimiento obligatorio de una retención.

`verificaciones_contacto` incorpora:

- `reservacion_id`;
- `request_token` de correlación;
- llave foránea hacia la retención;
- índices por retención y por token.

No se agregó ninguna columna que pueda contener el OTP plano.

### DML

Al final de `database/dml.sql` se agregó el bloque “DATOS DE PRUEBA: GESTIÓN PÚBLICA — ETAPA 2” con quince casos comentados:

1. contacto con cuatro activas;
2. contacto con cinco activas;
3. retención vigente;
4. retención vencida;
5. confirmada modificable;
6. modificación sin capacidad;
7. cancelable;
8. posterior a la hora;
9. asignación de una mesa;
10. asignación de dos mesas;
11. asignación de tres mesas;
12. grupo de 13 personas;
13. competencia por la última combinación;
14. reservación histórica;
15. contacto telefónico.

Los fixtures no contienen códigos OTP ni hashes de códigos conocidos.

### Servicios y métodos

Nuevos casos de uso:

- `DisponibilidadReservacionService::consultar()`
- `DisponibilidadReservacionService::evaluarHorario()`
- `ReservacionPublicaService::crearRetencion()`
- `ReservacionPublicaService::confirmarRetencion()`
- `ReservacionPublicaService::reenviarOtpRetencion()`
- `ReservacionPublicaService::crearConfirmada()`
- `ReservacionPublicaService::modificar()`
- `ReservacionPublicaService::cancelar()`
- `ReservacionPublicaService::expirarRetenciones()`
- `ReservacionPublicaService::puedeGestionarse()`
- `ContactoOperacionLock::adquirir()` y `liberar()`

Métodos ampliados:

- `ContactoAccesoService::emitirCodigoEnTransaccion()`
- `ContactoAccesoService::validarCodigoEnTransaccion()`
- `Reservacion::buscarActivasPorContacto()`
- `Reservacion::contarActivasPorContacto()`
- `ReservacionMesa::obtenerIdsPorReservacion()`
- `ReservacionMesa::reemplazarAsignacion()`
- `ReservacionMesa::obtenerOcupacionDelDia()`
- `ReservacionService::expirarRetenciones()`

### Endpoints

- `GET /api/reservaciones/disponibilidad`
- `POST /api/reservaciones/retencion`
- `POST /api/reservaciones/crear`
- `POST /api/reservaciones/modificar`
- `POST /api/reservaciones/cancelar`
- `POST /api/reservaciones/contacto/codigo`, ampliado para reenvío ligado a retención
- `POST /api/reservaciones/contacto/verificar`, ampliado para confirmar una retención
- `GET /api/reservaciones/mis-reservaciones`, ampliado con campos de gestión
- `POST /reservar`, conservado como compatibilidad y protegido por sesión verificada

Los códigos de dominio se traducen consistentemente a 200/201, 401, 403, 404, 409, 410, 422, 429 o 500.

### Flujo para usuario nuevo

`Fecha y personas → disponibilidad → hora → nombre/contacto → POST retención → mesas y OTP en una transacción → preview de testing → código → verificación → estado confirmada → sesión pública → comprobante y portal`

Si falla cualquier inserción, asignación u OTP, el rollback elimina toda la operación parcial.

### Flujo para usuario verificado

`Fecha y personas → disponibilidad → hora → datos faltantes → identidad tomada de reservation_client → revalidación de límite/capacidad → alta confirmada y mesas → comprobante`

El contacto enviado por el navegador nunca sustituye al de la sesión y no se genera un OTP adicional.

### Reglas de capacidad

- Entre 1 y 12 personas.
- Máximo de tres mesas y únicamente combinaciones públicas autorizadas.
- Sólo mesas activas de tipo `mesa` y marcadas como reservables.
- Intervalos completos de 90 minutos, conservando los 30 minutos previos de bloqueo del sistema existente.
- Ocupan `confirmada`, `pendiente` legacy y `pendiente_verificacion` no vencida.
- No ocupan `cancelada`, `completada`, `no_show`, `expirada` ni una retención cuyo timestamp ya venció.
- Una modificación excluye su propia reservación durante el cálculo.
- El GET no expone IDs, combinaciones, contactos ni detalle interno de capacidad.

### Límite de cinco

El conteo se repite dentro del lock asesor derivado de `contacto_tipo + contacto_normalizado`. Una retención no incrementa el conteo; la confirmación vuelve a comprobarlo. La carrera real con dos conexiones partiendo de cuatro activas produjo una sola quinta y rechazó la otra solicitud.

### Retenciones y expiración

- Vencimiento: `NOW + 5 minutos`.
- Estado inicial: `pendiente_verificacion`.
- Las mesas quedan asignadas y el OTP queda ligado mediante `reservacion_id`.
- `php scripts/expire_reservation_holds.php` procesa lotes; acepta `--limit=N` y `--dry-run`.
- La materialización usa `FOR UPDATE SKIP LOCKED`, marca `expirada`, registra `expired_at` e invalida OTP relacionados.
- Repetir el proceso no vuelve a modificar filas.

### Modificación

Exige sesión y propiedad. Bloquea contacto, fecha original, fecha nueva y la reservación. Revalida horario/capacidad excluyendo el propio ID y sustituye las asignaciones dentro de la misma transacción. Si no hay capacidad, hace rollback y conserva datos y mesas originales.

No permite cambiar el contacto canónico.

### Cancelación

Exige sesión, propiedad, estado `confirmada` y no haber rebasado la hora. Cambia lógicamente a `cancelada`, registra `cancelled_at`, conserva `reservacion_mesas` y libera capacidad por exclusión del estado. Repetir la cancelación devuelve éxito idempotente.

### Idempotencia

- Mismo token y fingerprint: se devuelve la operación existente.
- Mismo token y payload diferente: `REQUEST_TOKEN_CONFLICTO` con HTTP 409.
- La unicidad también está protegida en MySQL.
- La confirmación y cancelación repetidas producen resultados estables.
- Nunca se reutiliza el token como OTP o autenticación.

### Transacciones y locks

- Lock asesor por contacto con nombre derivado de SHA-256, timeout y liberación en `finally`.
- Locks asesores de fecha adquiridos cronológicamente.
- `SELECT ... FOR UPDATE` para token, reservación y OTP.
- Mesas reservables bloqueadas en orden de ID.
- Alta, asignaciones y OTP comparten transacción.
- La modificación conserva la asignación original hasta tener una alternativa válida.
- La expiración usa lotes bloqueados y omite filas tomadas por otro proceso.

### Pruebas ejecutadas y resultados

- `php scripts/run-tests.php`: aprobado.
- `php tests/ReservacionContactoEtapa1Test.php`: 33 comprobaciones aprobadas.
- `php tests/ReservacionPublicaEtapa2Test.php`: 62 comprobaciones aprobadas.
- Carga completa de `database/ddl.sql` y `database/dml.sql` en `casa_pestalozzi_etapa2_test`: aprobada; la base se eliminó al finalizar.
- Diagnóstico previo a cada mutación: `SELECT DATABASE()` coincidió con la base desechable.
- `php -l` sobre controladores, modelos, servicios, scripts y pruebas: aprobado.
- `node --check` sobre los módulos JavaScript afectados: aprobado.
- `npm.cmd run build`: aprobado; sólo mostró advertencias deprecadas del toolchain Sass/Node.
- Rollbacks forzados mediante triggers temporales:
  - fallo después de insertar la reservación: sin fila ni asignaciones parciales;
  - fallo después de asignar mesas: sin reservación, asignación ni OTP utilizable.
- Disponibilidad de 1 y 12 personas, rechazo de 13 y caso que exigiría cuatro mesas: aprobados.
- Asignaciones de una, dos y tres mesas: aprobadas.
- Retención vigente ocupando, vencida ignorada por timestamp y expiración materializada/idempotente: aprobadas.
- OTP ligado, sólo hash, correcto, incorrecto, vencido y válido con retención vencida: aprobados.
- Confirmación y doble confirmación: aprobadas.
- Alta con sesión y ausencia de un OTP nuevo: aprobadas.
- Consulta actualizada tras crear: aprobada.
- Cuatro activas, quinta, sexta rechazada y carrera real por la quinta: aprobadas.
- Modificación exitosa, sin capacidad, conservación original, fuera de hora y ajena: aprobadas.
- Cancelación exitosa, idempotente, fuera de hora y ajena: aprobadas.
- Estados finales fuera de capacidad: aprobados.
- Dos procesos/conexiones reales por la última mesa: un ganador y un rechazo.
- Carreras reales confirmar/expirar y modificar/cancelar: un estado final serializable y sin datos parciales.
- Preview presente en testing y ausente con `APP_ENV=production`: aprobado.
- Interfaz en navegador local:
  - exploración sin sesión y slots disponibles;
  - formulario de identidad posterior a la selección;
  - retención y cuenta regresiva de cinco minutos;
  - preview de testing;
  - verificación y comprobante;
  - portal actualizado sin recarga completa;
  - modificación;
  - cancelación y liberación visual;
  - logout con retorno al acceso;
  - revisión visual de escritorio aprobada.
- Coexistencia con la sesión administrativa: conservada por la suite de Etapa 1.

### Pruebas no ejecutadas

- Entrega real por WhatsApp, SMS o correo: fuera de alcance y proveedor simulado.
- HTTPS real y atributos de cookie bajo un proxy TLS.
- Matriz visual completa de móviles, navegadores y tecnologías de asistencia.
- Pruebas prolongadas de carga, failover, múltiples nodos MySQL o locks distribuidos.
- Disponibilidad basada en tickets abiertos o estado físico de mesas: corresponde a la Etapa 3.
- Despliegue productivo y automatización real del expirer.

### Incidencias

1. Los fallos intencionales de rollback escriben dos entradas esperadas en `error_log`; no son fallos de la suite.
2. PowerShell bloquea `npm.ps1`; se utilizó `npm.cmd`, que compiló correctamente.
3. El servidor PHP integrado requirió un router de desarrollo para servir estáticos y un directorio de sesión escribible dentro de `tests`.
4. Se detectó caché del bundle durante la revisión visual; se actualizó el identificador de versión de los assets.
5. La automatización inicial pulsaba el logout fuera del viewport. Al llevar el control a la sección visible, el endpoint y la transición visual aprobaron.
6. El servidor, las sesiones y la base desechable usados para la revisión visual fueron eliminados al finalizar.

### Riesgos pendientes

- Los tickets abiertos todavía no bloquean mesas. Puede existir una diferencia entre capacidad de reservaciones y ocupación física real.
- Las combinaciones autorizadas siguen definidas en configuración y deben contrastarse con la operación física/POS durante la Etapa 3.
- El proveedor OTP continúa siendo simulado.
- No existe rate limiting distribuido, CAPTCHA ni observabilidad productiva.
- Los locks asesores dependen de una conexión MySQL estable; un despliegue distribuido debe medir timeouts y contención.
- El módulo no se declara listo para producción.

### Pendientes de la Etapa 3

- Integrar tickets abiertos y ocupación física en la fuente de disponibilidad.
- Definir llegada, tolerancia, no-show y transición hacia servicio.
- Relacionar una reservación confirmada con apertura/cierre de ticket sin romper el historial.
- Revisar walk-ins, mesas combinadas y liberación operacional.
- Mantener fuera WhatsApp real, reconfirmación y estabilización productiva hasta la etapa correspondiente.

### Veredicto parcial

La Etapa 2 queda completada para desarrollo y testing: los dos caminos públicos, capacidad, retenciones, confirmación, límite, idempotencia, modificación, cancelación y expiración funcionan con pruebas reproducibles y concurrencia real. No se avanzó a la Etapa 3 y el módulo no se declara listo para producción.

## Etapa 3 — Horarios, punto de venta y capacidad física

### Fecha

26 de julio de 2026.

### Estado inicial

- `horarios_operacion` ya modelaba la semana y `excepciones_operacion` las fechas especiales, pero `dias_reservacion` y `horarios_reservacion` podían quedar desincronizadas.
- La disponibilidad pública validaba reservaciones y retenciones, pero ignoraba tickets abiertos.
- El POS abría tickets escribiendo sólo `mesa_id` y `mesa_secundaria_id`; no existía una relación física capaz de representar tres mesas.
- El inicio de servicio, la llegada y el no-show no tenían transiciones operativas separadas.
- El cierre del ticket no completaba atómicamente su reservación.
- La ausencia de una fila semanal se interpretaba como día abierto, inventando disponibilidad.
- La última hora reservable dependía de lógica dispersa y no quedaba garantizada la regla de una hora antes del cierre.

### Problemas reproducidos y causas raíz

1. Al cambiar apertura o cierre, la tabla operativa podía persistir mientras la proyección legacy fallaba o conservaba slots anteriores.
2. Cerrar y reabrir un día dependía de que ya existiera su fila en `dias_reservacion`; una fila faltante interrumpía la sincronización.
3. El frontend consumía endpoints de horarios, pero la creación y disponibilidad podían volver a leer la proyección derivada, creando dos interpretaciones.
4. Un horario especial o cierre se resolvía correctamente en algunas vistas, pero no existía un endpoint mínimo común para todos los consumidores.
5. El guardado rechazaba conflictos, pero no ofrecía el contrato explícito `RESERVACIONES_AFECTADAS` ni una confirmación administrativa auditable.
6. Los tickets abiertos no participaban en capacidad y la ocupación legacy se consultaba con dos columnas fijas.
7. Abrir ticket, relacionarlo con la reservación y cambiar el estado eran escrituras separadas, expuestas a estados parciales.
8. El cierre de cuenta actualizaba ticket, pagos y token sin bloquear/completar en la misma unidad la reservación relacionada.

La causa principal de horarios fue mantener una fuente operativa y una proyección reservable como si ambas fueran editables. La causa principal del POS fue no disponer de una relación N:M canónica ni de un servicio transaccional que coordinara reservación, mesas y ticket.

### Decisiones de arquitectura

1. `horarios_operacion` es la fuente canónica semanal.
2. `excepciones_operacion` reemplaza la regla semanal para una fecha; `cerrado` tiene prioridad y `horario_especial` sustituye completamente al semanal.
3. Si no existe configuración válida, el restaurante se considera cerrado.
4. `dias_reservacion` y `horarios_reservacion` quedan como proyección legacy derivada. Se regeneran dentro de la misma transacción, pero ya no son autoridad para creación o disponibilidad.
5. Los slots se calculan dinámicamente desde el horario efectivo.
6. `ticket_mesas` es la fuente canónica de ocupación física; `mesa_id` y `mesa_secundaria_id` se conservan temporalmente para lectura legacy.
7. Una reservación representa capacidad prometida y un ticket ocupación física. Un walk-in sólo crea ticket.
8. `reservacion_eventos` conserva la auditoría operativa sin OTP, tokens ni datos personales innecesarios.
9. Se usa un lock asesor global de configuración de horarios, seguido por lock de fecha, reservación, mesas ordenadas, tickets, relaciones y eventos.

### Modelo de horarios

Las reglas centralizadas en `ReservacionConfig` son:

- intervalo reservable: 30 minutos;
- duración de reservación: 90 minutos;
- última reservación: 60 minutos antes del cierre;
- zona horaria: `America/Mexico_City`;
- tolerancia: 15 minutos;
- duración conservadora de servicio abierto: 120 minutos;
- preparación de mesa: 15 minutos.

La resolución efectiva aplica:

```text
Excepción activa para la fecha
→ horario especial o cierre total
→ horario semanal
→ cerrado si falta configuración válida
```

Un cierre a las 22:00 genera como último inicio las 21:00. El bloqueo visual previo de 30 minutos no sustituye esta regla.

### Actualización y conflictos de horarios

- El guardado semanal valida los siete días, apertura anterior al cierre y datos de días cerrados.
- La actualización de `horarios_operacion` y el recálculo de la proyección legacy comparten transacción.
- Un fallo de recálculo hace rollback y conserva el horario anterior.
- La interfaz conserva el estado “Cambios sin guardar”, habilita Guardar y Descartar cambios, y advierte con `beforeunload` o modal al intentar abandonar la vista.
- Las excepciones permiten alta, edición, cierre, horario especial, activación y eliminación; no admiten fechas pasadas, duplicados ni un cierre simultáneamente abierto.
- Antes de guardar se detectan reservaciones activas fuera del nuevo horario. La primera respuesta usa `RESERVACIONES_AFECTADAS`, cantidad y `requiere_confirmacion`.
- La confirmación explícita conserva las reservaciones para atención manual y registra `cambio_horario_con_conflictos`.
- No se cancela, mueve ni elimina automáticamente ninguna reservación afectada.

### Endpoint de horario efectivo

`GET /api/operacion/horario-efectivo?fecha=YYYY-MM-DD` devuelve fecha, zona horaria, abierto/cerrado, origen, apertura, cierre, slots reservables y la excepción pública aplicada. No expone IDs administrativos ni datos de clientes.

El formulario público, disponibilidad, creación, retención, modificación y pruebas llaman al mismo servicio de horario efectivo. JavaScript presenta el resultado, pero el servidor vuelve a validarlo antes de escribir.

### Capacidad física y compatibilidad legacy

`DisponibilidadReservacionService` combina:

- reservaciones confirmadas;
- clientes que llegaron;
- reservaciones en curso;
- retenciones vigentes;
- tickets abiertos con todas sus filas de `ticket_mesas`;
- tickets legacy abiertos sin filas canónicas.

Cuando el ticket pertenece a la misma reservación en curso, las mesas se deduplican por ID. Para un ticket abierto se estima liberación con el máximo entre apertura más 120 minutos y hora actual más 15 minutos. La estimación bloquea conservadoramente la oferta futura, pero nunca reemplaza el cierre real.

La respuesta pública continúa siendo sólo disponibilidad; no expone nombre, ticket, mesero, pago ni información interna del POS.

### Integración con POS

- Se agregó “Gestionar reservaciones” sin abandonar el mapa.
- El modal lista únicamente reservaciones operativas de la fecha; los walk-ins quedan excluidos.
- Las tarjetas usan icono de calendario, etiqueta, hora destacada, borde, nombre, personas, estado, mesas y acciones.
- El mapa conserva la reservación futura aunque exista ocupación actual y prioriza ticket abierto, en curso, llegada, actual, próxima y libre.
- Tickets canónicos, walk-ins, servicio de reservación y compatibilidad legacy tienen clases visuales diferenciadas.
- `GET /api/punto-de-venta/mesa-contexto` entrega ticket abierto, reservación actual/próxima, advertencia y liberación recomendada sin modificar datos.
- Un walk-in con reservación próxima devuelve advertencia y exige confirmación para continuar; nunca crea una reservación artificial.

### Estados y operaciones

Flujo principal:

```text
confirmada → llego → en_curso → completada
```

Salidas:

```text
confirmada → cancelada | no_show
llego → cancelada | no_show
```

- Llegada: bloquea la reservación, permite anticipación, registra `arrived_at`, no abre ticket y es idempotente.
- Tolerancia: comienza a la hora reservada. Antes de 15 minutos se rechaza no-show, salvo override administrativo con motivo.
- No-show: registra usuario y `no_show_at`, conserva mesas históricas y libera capacidad por estado.
- Cancelación: registra usuario/motivo, conserva historial y se rechaza si el servicio ya tiene ticket abierto.
- Comenzar: acepta `confirmada` o `llego`, bloquea mesas, rechaza otro ticket físico, crea ticket y todas las relaciones N:M, guarda `seated_at` y cambia a `en_curso` en una transacción.
- Cerrar: bloquea ticket y reservación, registra pagos/cierre, genera un único token de feedback, guarda `closed_at` y `completed_at`, y cambia a `completada`. Repetir el cierre es idempotente.
- Walk-in: crea sólo ticket y `ticket_mesas`; al cerrarlo libera las mesas sin crear historial de reservación.

### Endpoints

- `GET /api/operacion/horario-efectivo`
- `POST /api/configuracion/horarios/semanales`
- `POST /api/configuracion/horarios/especiales`
- `POST /api/configuracion/horarios/excepciones`
- `DELETE /api/configuracion/horarios/excepciones`
- `GET /api/punto-de-venta/reservaciones`
- `GET /api/punto-de-venta/mesa-contexto`
- `POST /api/punto-de-venta/reservaciones/llegada`
- `POST /api/punto-de-venta/reservaciones/comenzar`
- `POST /api/punto-de-venta/reservaciones/cancelar`
- `POST /api/punto-de-venta/reservaciones/no-show`
- `POST /api/cerrar-ticket`

Las rutas administrativas y del POS usan las protecciones de sesión/rol existentes. No se duplicó la escritura de apertura o cierre en rutas paralelas.

### DDL

`database/ddl.sql` incorpora:

- estados `llego` y `en_curso`;
- `arrived_at`, `seated_at`, `completed_at`, `no_show_at`, `cancelled_by` y `no_show_by`;
- `closed_at` y vínculo único ticket-reservación;
- `ticket_mesas` con FKs, índices, unicidad por ticket/mesa y ticket/orden;
- `reservacion_eventos` con eventos operativos, FKs e índices;
- unicidad del token de feedback por ticket;
- comentarios de fuente canónica y compatibilidad legacy.

No se crearon migraciones incrementales.

### DML

Al final de `database/dml.sql` se agregó exactamente la sección “DATOS DE PRUEBA: HORARIOS, POS Y CAPACIDAD — ETAPA 3”. Incluye 24 escenarios comentados con acción y resultado esperado: semana abierta/cerrada, horario especial, cierre, conflicto, límite de última hora, persistencia, llegada, tolerancia, servicio, cierre, cancelación, no-show, dos y tres mesas, walk-in, ticket legacy, reservación futura, consecutivas y conflicto físico.

También se proyectan tickets históricos hacia `ticket_mesas` sin borrar sus columnas legacy. No se agregó ningún OTP plano.

### Archivos modificados

- Enrutamiento y seguridad: `Router.php`, `public/index.php`, `classes/Auth.php`.
- Horarios: `services/ReservacionConfig.php`, `services/HorarioConfigLock.php`, `services/HorarioOperacionService.php`, `services/HorarioReservacionService.php`, `models/DiaReservacion.php`, `controllers/AdminConfigurationController.php`, `controllers/ReservacionController.php`.
- Capacidad/POS: `services/DisponibilidadReservacionService.php`, `services/PuntoVentaReservacionService.php`, `models/TicketMesa.php`, `models/Ticket.php`, `models/Reservacion.php`, `models/ReservacionMesa.php`, `models/ReservacionEvento.php`, `controllers/PuntoVentaController.php`.
- Vistas y frontend: `views/admin/configuration/hours.php`, `views/punto-de-venta/index.php`, `src/js/admin/configuration/configuration.js`, `src/js/modules/punto-de-venta.js`, `src/scss/admin/modules/configuration.scss`, `src/scss/punto-de-venta/_punto-de-venta.scss`.
- Base y pruebas: `database/ddl.sql`, `database/dml.sql`, `scripts/run-tests.php`, `tests/ReservacionEtapa3Test.php`, `tests/ReservacionEtapa3ConcurrencyWorker.php`.
- Assets generados: bundles, CSS y source maps bajo `public/build/` y `assets/`.

### Transacciones y concurrencia

El protocolo definitivo es:

```text
lock de configuración de horario
→ lock de fecha
→ reservación
→ mesas por ID ascendente
→ ticket
→ ticket_mesas
→ reservacion_eventos
→ commit
```

Las suites usan procesos PHP y conexiones `mysqli` independientes sincronizados por barrera. Se verificaron doble inicio, doble ticket, doble cierre, no-show contra inicio, cancelación contra inicio, cierre contra cierre, ticket walk-in contra inicio de reservación y cierre semanal contra creación. En todos los casos quedó un único resultado físico válido, o un reintento idempotente, sin relaciones parciales.

### Pruebas ejecutadas y resultados

- `php scripts/run-tests.php`: 33 comprobaciones de Etapa 1, 62 de Etapa 2 y 67 de Etapa 3 aprobadas.
- Carga de `database/ddl.sql` y `database/dml.sql` sobre `casa_pestalozzi_etapa3_test`: aprobada.
- `SELECT DATABASE()` antes de mutaciones: coincidió con la base desechable.
- Actualización de apertura/cierre, cierre, reapertura, persistencia, horario imposible, prioridad especial/cierre, última hora y rechazo posterior: aprobados.
- Conflicto de reservaciones y confirmación administrativa sin cancelación: aprobados.
- Rollback forzado durante la proyección legacy: aprobado; el mensaje en `error_log` es esperado.
- Ticket canónico, ticket legacy, una/dos/tres mesas, deduplicación, contexto, walk-in y liberación por cierre: aprobados.
- Llegada, llegada anticipada/idempotente, comienzo, doble comienzo, cierre/completado, doble cierre, tolerancia, override, no-show y cancelación: aprobados.
- Carreras reales descritas en la sección anterior: aprobadas.
- `php -l` sobre PHP afectado: aprobado.
- `node --check` sobre JavaScript fuente: aprobado.
- `npm.cmd run build`: aprobado; sólo advertencias deprecadas de Sass/Node.
- `git diff --check`: aprobado.
- Navegador local con base desechable: mapa POS, botón, modal, tarjetas diferenciadas y ausencia de error JSON aprobados en escritorio.

### Incidencias

1. La primera prueba visual se ejecutó contra la base local anterior al nuevo DDL y reveló la columna faltante `no_show_at`; se descartó ese resultado y se repitió contra una base desechable creada con el DDL/DML actual.
2. El servidor integrado necesitó `public/` como document root, un directorio de sesiones escribible y `variables_order=EGPCS` para recibir el nombre de la base desechable por entorno.
3. El modal heredaba los 400 px del diálogo de mesa y producía scroll horizontal; se agregó un ancho específico responsive y la segunda revisión visual aprobó.
4. El rollback forzado escribe una entrada esperada en `error_log`.
5. `npm.ps1` está restringido por PowerShell; `npm.cmd` completó la compilación.

### Pruebas no ejecutadas y limitaciones

- No se ejecutó una matriz visual completa de móvil, navegadores y tecnologías de asistencia.
- No se realizó una prueba prolongada de carga, failover, múltiples nodos PHP/MySQL ni medición de contención de locks.
- La carrera aislada “modificar reservación contra cambio de horario” no tiene todavía un worker dedicado; ambos caminos usan el lock global y el lock de fecha, y sí se probaron por separado y en carreras equivalentes de creación/cambio.
- La estimación de liberación de un ticket abierto es conservadora; el cierre real sigue siendo la única liberación definitiva.
- Las columnas legacy de tickets y la proyección `horarios_reservacion` permanecen por compatibilidad y requieren observación antes de retirarse.
- El proveedor OTP continúa simulado. No se probaron HTTPS real, entrega externa ni despliegue productivo.

### Riesgos y pendientes de la Etapa 4

- Observar timeouts y contención de locks con carga real.
- Medir falsas indisponibilidades producidas por la estimación conservadora de tickets largos.
- Definir una retirada gradual de columnas legacy y de la proyección reservable después de verificar consumidores externos.
- Completar pruebas responsive/accesibilidad y endurecimiento operativo.
- Integrar notificaciones reales, reconfirmación, CAPTCHA o rate limiting distribuido sólo cuando se autorice la Etapa 4.

No se integró WhatsApp, correo, SMS, Meta, Twilio ni n8n. No se implementó reconfirmación. El OTP sigue hasheado y su preview continúa restringido a development/testing.

### Veredicto parcial

La Etapa 3 queda completada para desarrollo y testing: existe una fuente canónica de horario, la disponibilidad combina capacidad prometida y ocupación física, y el POS coordina llegada, servicio, cierre, walk-ins y auditoría mediante operaciones transaccionales e idempotentes. Persisten las limitaciones de compatibilidad y pruebas no funcionales indicadas; el módulo no se declara listo para producción y no se avanzó a la Etapa 4.
