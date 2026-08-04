# Etapa 8 — Reconstrucción administrativa de reservaciones

Fecha: 2026-08-03  
Estado: implementada y verificada en backend  
Alcance: gestión interna de reservaciones en `/admin/reservations`, sin cambiar el flujo público ni POS.

## 1. Decisión de alcance

Se reconstruyó la gestión administrativa como una fachada propia, con contratos de alta, consulta, detalle, edición y cancelación. Se reutilizan los servicios de horario, ocupación y asignación existentes, pero no se reutiliza el flujo público de OTP ni el contrato público de disponibilidad.

Quedan fuera: cambio de esquema, nuevos roles o permisos, notificaciones reales, rediseño global, cambios al algoritmo público estricto, límite público de 12 personas, POS, mapa compartido y `pos-reservacion.v1`.

## 2. Matriz de fuentes y reutilización

| Necesidad | Fuente administrativa | Reutilización | Decisión |
| --- | --- | --- | --- |
| Entrada y rutas | `public/index.php`, `AdminReservacionController`, `ReservacionOperacionController` | Router y autenticación existentes | Se agregan validación POST y CSRF administrativo |
| Horario válido | `HorarioReservacionService` | Sí | La agenda es la fuente de validez de fecha/hora |
| Capacidad estimada | `OcupacionMesasService` y `Mesa` | Sí | Se expone por separado de la asignación |
| Asignación automática | `AsignacionMesasService` | Sí | Sigue siendo la única ruta automática |
| Persistencia | `Reservacion`, `ReservacionMesa` | Sí | No se agrega columna de asignación manual |
| Ticket físico | `Ticket`, `TicketMesa` | Sí | Se consulta solo para contexto administrativo |
| Público/OTP | `ReservacionPublicaService`, `VerificacionContacto` | No para alta/edición admin | El admin no solicita ni confirma OTP |
| Operación/mapa | Operación existente | Parcial | Se añade CSRF y se conserva el mapa compartido |

## 3. Arquitectura antes

El controlador administrativo mezclaba traducción HTTP, persistencia, validación de disponibilidad y acciones operativas. La disponibilidad administrativa también podía quedar acoplada al resumen público, y el formulario dependía de confirmaciones booleanas separadas.

## 4. Arquitectura después

`ReservacionAdministrativaService` concentra el contrato administrativo:

```text
AdminReservacionController
        |
        v
ReservacionAdministrativaService
   |        |        |
horario  ocupación  asignación
   |        |        |
Reservacion + ReservacionMesa + locks
```

`ReservacionService` mantiene la fachada histórica y delega alta y edición. La operación continúa separada y solo recibe cambios compatibles con el contrato administrativo.

## 5. Estado y origen

Las altas administrativas se guardan con `origen='admin'`, `estado='confirmada'`, sin retención y sin OTP. Una reservación administrativa confirmada sin filas en `reservacion_mesas` se interpreta como pendiente de asignación manual:

```text
origen = admin
estado = confirmada
reservacion_mesas = 0
```

No se añadió `requiere_asignacion_manual` al esquema.

## 6. Listado administrativo

`/admin/reservations` ahora muestra y filtra origen, estado, asignación, fecha, contacto, ticket y nota. El estado visual para una alta administrativa confirmada sin mesas es “Pendiente de asignar mesas”, no “No disponible”.

La métrica “sin mesa” del resumen administrativo se restringe a altas administrativas confirmadas sin mesas; no se mezclan reservaciones públicas pendientes de OTP ni estados históricos.

## 7. Detalle administrativo

El detalle muestra origen, contacto opcional, tipo de contacto, estado de ticket, mesas físicas conocidas, estado de asignación y trazabilidad de actualización/cambio de estado. No expone request tokens, OTP ni códigos de verificación.

Una reservación pendiente de verificación no puede confirmarse desde el admin. Solo puede cancelarse si no tiene ticket abierto y se invalida su verificación pendiente.

## 8. Alta administrativa

La ruta `/admin/reservations/create` permite:

- 1 a 44 comensales;
- contacto opcional (`contacto_tipo=ninguno`);
- fecha/hora válidas según agenda;
- nota y comentario interno;
- asignación automática opcional para grupos de hasta 12;
- guardar sin mesas para cualquier grupo cuando se confirma la advertencia correspondiente.

El nombre sigue siendo obligatorio. El email/teléfono no se exige y, cuando se captura, se normaliza con `ContactoService`.

## 9. Contrato de alta

El contrato de escritura acepta `admin_csrf`, `request_token`, `nombre`, `contacto_tipo`, `contacto`, `fecha`, `hora`, `comensales`, `nota`, `comentario_admin`, `asignar_automaticamente` y `confirmaciones`.

El resultado incluye `codigo`, `warnings`, `requiredConfirmations`, capacidades, asignación, `mesa_ids`, `requiere_asignacion_manual` y redirección administrativa cuando aplica. `request_token` conserva idempotencia para reintentos.

## 10. Disponibilidad administrativa

`DisponibilidadReservacionService::consultarAdministrativa` delega en la fachada nueva. La respuesta distingue:

- `horario_valido`;
- `capacidad_estimada_suficiente`;
- `capacidad_estimada` y `capacidad_realmente_libre`;
- `asignacion_automatica_posible`;
- `requiere_asignacion_manual`;
- `depende_liberacion_proyectada`.

Para admin se permite consultar grupos mayores a 12 sin convertirlos en una reservación pública ni aplicarles el algoritmo público estricto.

## 11. Asignación automática

Para grupos de hasta 12 personas se usa exclusivamente `AsignacionMesasService::asignarAutomaticamente`. Se eliminó cualquier cálculo alternativo en controlador o JavaScript. La asignación se realiza dentro de la misma transacción de alta/edición.

Para más de 12 personas el control se deshabilita en UI y el resultado se guarda como pendiente de asignación manual, con `SIN_ASIGNACION` si no se confirma.

## 12. Capacidad estimada

La capacidad estimada es una advertencia operativa, no una asignación material. Puede existir capacidad global suficiente aunque la asignación automática no encuentre una combinación válida; ambos estados se informan de manera separada.

## 13. Advertencias y confirmaciones

Se usa un único modal consolidado. Los códigos exactos son:

- `SIN_CONTACTO`;
- `SIN_ASIGNACION`;
- `CAPACIDAD_INSUFICIENTE`.

El cliente devuelve los códigos aceptados en `confirmaciones` y el servidor los recalcula después de tomar locks. No se usa un booleano genérico de confirmación.

## 14. Alta sin mesas

Si no se solicita asignación automática, si el grupo supera 12 personas o si la asignación automática no puede resolverse, el alta puede persistir sin `reservacion_mesas` después de confirmar `SIN_ASIGNACION`. La reservación queda confirmada y visible como “Pendiente de asignar mesas”.

## 15. CSRF administrativo

Se agregó `AdminCsrfService` con token de sesión dedicado. Protege alta, edición, cancelación, reasignación y acciones de operación. Las rutas no reciben credenciales de OTP ni tokens públicos como sustituto.

## 16. Edición

Solo una reservación `confirmada` es editable con el contrato completo. La edición permite cambiar fecha, hora, comensales, nombre, contacto, nota y comentario interno.

Si las mesas actuales siguen siendo válidas y no se solicita reasignación, se conservan. Si dejan de ser válidas, se eliminan en la transacción y la reservación queda sin mesas; no se conserva una asignación inconsistente.

## 17. Contacto durante edición

El contacto puede agregarse cuando antes no existía. Si ya existe como email o teléfono, el tipo no puede cambiarse entre ambos; el servidor devuelve `CONTACTO_TIPO_NO_EDITABLE`. El cambio de tipo no se resuelve únicamente con JavaScript.

## 18. Cancelación

La cancelación administrativa es idempotente. Permite cancelar una reservación confirmada o una pendiente de verificación sin ticket abierto. Limpia retenciones, invalida verificaciones pendientes y expira un reemplazo pendiente relacionado sin borrar las mesas históricas.

La razón se recibe para contexto operativo, sin agregar una columna nueva.

## 19. Locks y transacciones

Las escrituras administrativas siguen este orden:

1. `HorarioConfigLock`;
2. locks `FechaOperacionLock` de las fechas afectadas, ordenadas;
3. transacción;
4. fila de reservación con `FOR UPDATE`;
5. filas de asignación/mesas mediante los servicios existentes;
6. commit o rollback;
7. liberación inversa de locks.

La cancelación también bloquea la fecha de un reemplazo pendiente cuando difiere de la fecha original. La configuración de horarios funciona como serializador transversal para evitar mezclar decisiones de agenda con escrituras concurrentes.

## 20. Idempotencia y errores

Alta por `request_token` devuelve el registro ya creado sin duplicar. Cancelación repetida devuelve éxito idempotente. Los errores de estado, horario, datos, contacto y CSRF se devuelven con códigos estables y sin confirmar parcialmente una operación.

## 21. Compatibilidad pública

No se cambiaron rutas, controladores ni contratos de creación/modificación/cancelación pública. La consulta pública mantiene su algoritmo estricto y su límite de 12. La expiración y confirmación OTP siguen perteneciendo al flujo público.

## 22. Compatibilidad POS y operación

No se cambió el modelo de ticket, el mapa compartido ni `pos-reservacion.v1`. La operación conserva asignación manual, reasignación y comentarios, con CSRF añadido. El detalle administrativo consulta el ticket físico más reciente sin asumir que un ticket equivale a una asignación administrativa pendiente.

## 23. Archivos principales

Nuevos:

- `services/AdminCsrfService.php`;
- `services/ReservacionAdministrativaService.php`;
- `tests/php/etapa8_administrativa.php`;
- `tests/php/etapa8_instalacion_limpia.php`;
- `tests/php/etapa8_concurrencia.php`;
- `tests/php/etapa8_concurrencia_worker.php`.

Actualizados:

- `controllers/AdminReservacionController.php`;
- `controllers/ReservacionOperacionController.php`;
- `models/Reservacion.php`;
- `models/Ticket.php`;
- `services/DisponibilidadReservacionService.php`;
- `services/ReservacionService.php`;
- `src/js/admin/reservations/form.js`;
- `src/js/admin/reservations/operation.js`;
- vistas administrativas y de operación de reservaciones.

## 24. Pruebas funcionales y de regresión

Resultados ejecutados:

- `php tests/php/etapa8_administrativa.php`: 19 casos, 0 fallas;
- `php tests/php/etapa8_instalacion_limpia.php`: DDL + DML + suite 19/19, base temporal eliminada;
- `php tests/php/etapa8_concurrencia.php`: 2 workers exitosos, mesas distintas, `duplicate_table_ids=false`;
- `php tests/php/etapa5_instalacion_limpia.php`: instalación limpia correcta;
- `php tests/php/etapa7_5_instalacion_limpia.php`: suite cruzada 35 casos, 0 fallas;
- `npm.cmd run test:js`: PASS;
- `php -l` de PHP modificado: PASS;
- `node --check` de JavaScript modificado: PASS;
- `git diff --check`: sin errores de whitespace.

## 25. Build y revisión visual

Las tareas específicas compilaron correctamente:

```text
npx gulp adminModuleCss adminReservationFormJs adminReservationOperationJs operationCss
```

El build global se detuvo por `EPERM` al escribir `public/build/js/admin/area.js`, archivo administrativo no relacionado con Etapa 8. El test visual con el navegador integrado no pudo acceder al servidor local aislado: PowerShell respondió 200 en `127.0.0.1:8081`, pero el navegador mantuvo `ERR_CONNECTION_REFUSED`. Por ello el estado de revisión visual es pendiente del entorno XAMPP/navegador, no una afirmación de layout verificado.

## 26. Legacy, riesgos y decisión de Etapa 9

Se conservan métodos históricos de `ReservacionService` para compatibilidad interna, pero alta y edición administrativas ya pasan por la nueva fachada. No se modificó el esquema ni se migraron datos históricos.

Riesgos conocidos:

- el build completo requiere resolver el bloqueo externo sobre `area.js`;
- la revisión visual real requiere que Apache/XAMPP sea accesible desde el navegador integrado;
- las operaciones sobre grupos mayores a 12 siguen necesitando asignación manual posterior;
- la regla de tipo de contacto existente es deliberadamente restrictiva para evitar reinterpretar datos históricos.

Decisión: Etapa 8 queda cerrada en backend y contratos administrativos, con pruebas limpias y de concurrencia aprobadas. No se inicia Etapa 9 automáticamente. La revisión visual y cualquier evolución de notificaciones, permisos o schema quedan como trabajo posterior explícito.
