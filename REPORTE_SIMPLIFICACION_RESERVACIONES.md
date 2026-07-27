# Simplificación integral del módulo de reservaciones

## 1. Resumen ejecutivo

El módulo quedó alineado con un único contrato de datos. El contacto se
persiste como `contacto_tipo + contacto`; las mesas prometidas viven solamente
en `reservacion_mesas`; las mesas físicamente ocupadas por tickets viven
solamente en `ticket_mesas`; y los horarios se resuelven desde
`horarios_operacion + excepciones_operacion`.

La causa del error original `Unknown column 'contacto_tipo'` era una
desalineación entre el código PHP y la base conectada: el modelo ya consultaba
el contrato nuevo mientras esa base todavía tenía el esquema anterior. El
repositorio ahora incluye un DDL/DML final coherente y probado desde cero. Por
restricción expresa, no se alteró ni se vació la base activa; por tanto, una
instalación que siga usando el esquema anterior debe reconstruir su base de
desarrollo de forma controlada antes de probar la aplicación.

Se conservaron las transacciones, locks por contacto y fecha, idempotencia,
OTP con hash, límites de capacidad, retenciones, asignación de hasta tres mesas
en el flujo público y la integración con POS. No se creó una capa nueva ni una
tabla sustituta de auditoría.

Estado final: DDL y DML cargan en bases desechables, las suites automatizadas
pasan y los assets compilan. No se modificó una base productiva o activa. La
restricción inicial de no crear commits fue reemplazada posteriormente por una
instrucción explícita de versionar estos cambios.

### Matriz inicial de dependencias legacy

La matriz se levantó antes de retirar consumidores.

| Elemento legacy | Archivos consumidores | Lecturas | Escrituras | Sustitución propuesta |
| --- | --- | ---: | ---: | --- |
| `reservaciones.email`, `telefono`, `contacto_valor`, `contacto_normalizado` | DDL/DML, modelos de reservación y OTP, servicios público/administrativo, controladores, formularios, JS y pruebas | Sí | Sí | `contacto_tipo + contacto`, canónico antes de persistir o consultar |
| `verification_expires_at` | DDL/DML, modelo, disponibilidad, servicio público, expirador y pruebas | Sí | Sí | `hold_expires_at` |
| `expired_at`, `cancelled_at`, `seated_at`, `no_show_at`, `cancelled_by`, `no_show_by` | DDL/DML, servicios público/POS/administrativo y pruebas | Sí | Sí | Estado, `status_changed_at` y campos de último cambio; inicio de servicio desde ticket |
| Estado `pendiente` | DDL/DML, configuración, filtros, vistas, estilos, JS y pruebas | Sí | Sí | `confirmada`; las retenciones usan `pendiente_verificacion` |
| `reservacion_eventos` / `ReservacionEvento` | DDL, modelo, horarios, POS y pruebas | Sí | Sí | Último cambio relevante en `reservaciones` |
| OTP `request_token`, `max_attempts`, `updated_at` e índices individuales | DDL, modelo OTP, servicios y pruebas | Sí | Sí | Relación opcional por `reservacion_id`; máximo en configuración PHP |
| `tickets.mesa_id`, `tickets.mesa_secundaria_id` y fallback | DDL/DML, modelos, controladores de ticket, POS, sugerencias, frontend y pruebas | Sí | Sí | Uso exclusivo de `ticket_mesas` |
| `idx_rm_reservacion` | DDL | No | No | Eliminación; los índices únicos ya cubren el mismo prefijo |
| `dias_reservacion`, `horarios_reservacion` y su proyección | DDL/DML, modelos, servicios de horarios, controladores y pruebas | Sí | Sí | Slots dinámicos desde el horario efectivo |
| `POST /reservar`, `ReservacionController::crear()` y `ReservacionService::crearPublica()` | Router público, controlador, servicio, formulario, JS y pruebas | Sí | Sí | `/api/reservaciones/retencion` y `/api/reservaciones/crear` |
| Sesión pública de 30 minutos | Configuración, sesión pública, acceso y pruebas | Sí | Sí | 15 minutos de inactividad con renovación |
| Migración incremental de compatibilidad | `database/migrations/20260726_alinear_reservaciones_publicas.sql` | Sí | Sí | Eliminación; la estructura final vive directamente en DDL/DML |

## 2. Estructura anterior

| Elemento | Motivo por el que era legacy o redundante |
| --- | --- |
| Cuatro representaciones del contacto | Permitían valores divergentes y obligaban a detectar columnas en tiempo de ejecución. |
| Estado `pendiente` | Duplicaba una etapa ambigua frente a la retención verificable. |
| Seis timestamps/usuarios por transición | Repetían información derivable del estado, ticket y último cambio. |
| `reservacion_eventos` | Mantenía historial y JSON que el alcance actual no necesita. |
| Configuración repetida por fila OTP | `max_attempts` era una constante funcional, no un dato de cada desafío. |
| Mesas en `tickets` y en `ticket_mesas` | Creaban dos fuentes físicas y exigían fallback. |
| Índice simple por reservación en `reservacion_mesas` | Era redundante con dos índices únicos que comienzan por `reservacion_id`. |
| Tablas de días/slots proyectados | Duplicaban el horario semanal y sus excepciones. |
| Ruta y servicio público antiguos | Duplicaban el flujo actual de retención/OTP o creación con sesión. |

## 3. Estructura final

- `reservaciones`: identidad canónica, fecha/hora, estado final, retención,
  idempotencia y último cambio relevante.
- `reservacion_mesas`: única fuente de mesas prometidas, con orden y unicidad
  por mesa y posición.
- `tickets`: cuenta, estado, reservación y mesero; ya no contiene mesas.
- `ticket_mesas`: única fuente de ocupación física de una o varias mesas.
- `verificaciones_contacto`: contacto canónico, hash, vigencia, intentos,
  consumo e invalidación; `reservacion_id` puede ser `NULL`.
- `horarios_operacion`: fuente semanal.
- `excepciones_operacion`: cierre o horario especial por fecha, con precedencia
  sobre la semana.

Los slots se calculan dinámicamente. La ausencia de configuración válida se
interpreta como cerrado.

## 4. Elementos eliminados

Se eliminaron por completo:

- Tabla `reservacion_eventos`, modelo `ReservacionEvento.php`, escrituras,
  lecturas, fixtures y pruebas exclusivas.
- Columnas `email`, `telefono`, `contacto_valor`, `contacto_normalizado`,
  `verification_expires_at`, `expired_at`, `seated_at`, `cancelled_at`,
  `no_show_at`, `cancelled_by` y `no_show_by` de `reservaciones`.
- Estado exacto `pendiente`.
- Columnas OTP `request_token`, `max_attempts`, `updated_at` e índices
  individuales de consumo e invalidación.
- Columnas `tickets.mesa_id`, `tickets.mesa_secundaria_id`, propiedades del
  modelo y todos sus fallbacks.
- Índice `reservacion_mesas.idx_rm_reservacion`.
- Tablas `dias_reservacion`, `horarios_reservacion`, sus modelos y la
  sincronización/proyección persistida.
- Ruta `POST /reservar`, método de controlador, servicio `crearPublica`,
  JavaScript y estilos exclusivos de compatibilidad.
- Migración incremental de alineación; no se creó otra.

El escaneo final sólo encuentra los nombres retirados en aserciones negativas
que confirman su ausencia y en esta documentación. No quedó una dependencia
funcional que impidiera eliminar alguno.

## 5. Archivos modificados

### Base de datos

- `database/ddl.sql`, `database/dml.sql`.
- Eliminado
  `database/migrations/20260726_alinear_reservaciones_publicas.sql`.

### Modelos

- `Reservacion.php`, `ReservacionMesa.php`, `Ticket.php`, `TicketMesa.php`,
  `VerificacionContacto.php`.
- Eliminados `ReservacionEvento.php`, `DiaReservacion.php` y
  `HorarioReservacion.php`.

### Servicios

- `AsignacionMesasService.php`, `ContactoAccesoService.php`,
  `DisponibilidadReservacionService.php`, `HorarioOperacionService.php`,
  `HorarioReservacionService.php`, `PuntoVentaReservacionService.php`,
  `ReservacionConfig.php`, `ReservacionPublicaService.php`,
  `ReservacionService.php`, `ReservationClientSession.php`,
  `Sugerencias.php`.

### Controladores y rutas

- `AdminController.php`, `AdminReservacionController.php`, `AreaController.php`,
  `FeedbackController.php`, `PuntoVentaController.php`,
  `ReservacionController.php`, `ReservacionOperacionController.php`.
- `public/index.php`.

### Frontend

- Formularios y vistas administrativas de reservaciones.
- JS de formulario público, reservaciones administrativas, operación, POS y
  datos de analytics.
- SCSS de reservaciones, mapa, operación y POS.
- Assets compilados bajo `public/build/` y `assets/`.

### Scripts e integraciones

- `scripts/expire_reservation_holds.php` fue verificado sin requerir cambios:
  delega al servicio actualizado y conserva `--limit` y `--dry-run`.
- `n8n/sugerencias.json` se alineó con el contacto canónico; no se configuró
  ninguna automatización externa.

### Pruebas

- `ReservacionContactoEtapa1Test.php`,
  `ReservacionPublicaEtapa2Test.php`, `ReservacionEtapa3Test.php` y sus workers
  de concurrencia.

### Documentación y configuración

- Este reporte, `REPORTE_CAMBIOS_MODULO_RESERVACIONES.md` e
  `includes/.env.example`.

La eliminación preexistente de `storage/.gitignore` no pertenece a esta tarea y
no fue intervenida.

## 6. Cambios de comportamiento

- Todo correo se normaliza a minúsculas y todo teléfono al formato
  `+52` seguido por diez dígitos nacionales antes de insertar, actualizar,
  buscar, crear sesión, emitir OTP, contar activas o adquirir locks.
- La sesión pública expira tras 15 minutos de inactividad y se renueva con
  actividad válida sin destruir la sesión administrativa.
- Una retención ocupa durante cinco minutos mediante `hold_expires_at`.
  Disponibilidad ignora una retención vencida aunque el script todavía no la
  haya materializado como `expirada`.
- Cada transición actualiza estado, fecha del cambio, fuente, usuario cuando
  aplica y motivo. Los eventos excepcionales conservan el motivo más reciente,
  no metadata ni historial completo.
- Reservaciones y tickets usan exclusivamente sus respectivas tablas N:M. El
  ticket vinculado no duplica la ocupación de la misma reservación.
- Los horarios siguen la precedencia: excepción activa, horario semanal,
  cerrado cuando no existe configuración válida.
- El flujo público sin sesión usa retención y OTP; con sesión crea una
  reservación confirmada directamente.

El script de expiración debe programarse operacionalmente cada cinco minutos.
Esta tarea no configuró cron, n8n ni el Programador de tareas.

## 7. Fixtures y pruebas ejecutadas

Los fixtures de reservaciones usan exclusivamente `2026-11-27` a
`2026-12-03`, con reloj y jornada principal `2026-11-30 12:00:00`. El lunes
tiene horario semanal; `2026-11-29` es cierre excepcional y `2026-12-02`
horario especial. Los contactos `*.example.test` y el teléfono
`+525544442026` identifican datos ficticios.

Se cubren cero/una/cuatro/cinco activas, histórico, retenciones vigente/vencida,
modificación/cancelación, bloqueo de capacidad, una/dos/tres mesas públicas,
un caso administrativo de cuatro mesas, consecutivas, llegada, tolerancia,
no-show, servicio, cierre, walk-ins y reserva futura. Se usan las mesas 1 a 11
en horarios separados o estados que no ocupan.

| Comando | Resultado |
| --- | --- |
| `php -l` sobre 146 archivos PHP fuera de `vendor/` | Correcto, sin errores de sintaxis |
| `node --check` sobre 41 archivos JS fuente | Correcto |
| `npm.cmd run build` | Correcto; sólo advertencias deprecadas de Sass/Node |
| `php scripts/run-tests.php` | Correcto: 37 + 69 + 78 = 184 comprobaciones |
| `php -d variables_order=EGPCS scripts/expire_reservation_holds.php --dry-run --limit=1` sobre la base Etapa 2 conservada temporalmente | Correcto: simulación `true`, límite aceptado y salida JSON |
| `git diff --check` | Correcto, sin errores |
| Escaneo `rg` de nombres retirados y `COMMENT` | Sin consumidores funcionales; sólo aserciones negativas/documentación |

Las suites crearon y eliminaron
`casa_pestalozzi_etapa1_test`, `casa_pestalozzi_etapa2_test` y
`casa_pestalozzi_etapa3_test`. Cada suite confirmó `SELECT DATABASE()` contra
su nombre fijo antes de cargar DDL/DML o ejecutar casos mutables. El DDL y DML
cargaron desde cero en las tres.

La limpieza normal es el `DROP DATABASE` que cada suite hace en `finally`.
No deben copiarse los fixtures a la base activa. Si se conserva
intencionalmente una base con las variables `E*_KEEP_DATABASE`, debe eliminarse
completa al terminar; esa opción no se usó en esta ejecución.

## 8. Pruebas no ejecutadas

- Recorrido manual en navegador bajo Apache/XAMPP.
- Prueba contra la base activa o conversión de sus 45 reservaciones.
- Envío real por correo, SMS o WhatsApp.
- Ejecución mediante cron/Programador de tareas.
- Pruebas de producción, carga prolongada o recuperación ante caída del
  proceso/servidor.

## 9. Riesgos residuales

- La base activa permanece con su contrato anterior. Mientras apunte a ese
  esquema, puede reproducir el error `Unknown column 'contacto_tipo'`. Esto es
  deliberado para no ejecutar una reconstrucción destructiva sin autorización.
- El diseño conserva sólo el último cambio relevante; no permite reconstruir
  un historial completo de transiciones.
- La liberación física periódica depende de programar el expirador cada cinco
  minutos, aunque la disponibilidad ya libera lógicamente por timestamp.
- El build usa APIs de Sass/Node marcadas como deprecadas; no bloquean el
  resultado actual, pero conviene actualizar el toolchain en una tarea aparte.

## 10. Veredicto

- DDL desde cero: **sí**.
- DML desde cero: **sí**.
- Pruebas automatizadas: **184/184 correctas**.
- Referencias legacy funcionales: **no**.
- Listo para pruebas manuales sobre una base de desarrollo reconstruida:
  **sí**.
- Listo para producción: **no se declara**.
- Base activa modificada: **no**.
- Versionado en Git: **autorizado posteriormente por el usuario**.
