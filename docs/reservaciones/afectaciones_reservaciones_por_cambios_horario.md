# Afectaciones de reservaciones por cambios de horario

## Alcance

Cuando un cambio del horario efectivo deja una reservación fuera de operación, el sistema conserva la reservación y crea un seguimiento durable. El cambio de horario no cancela, reprograma ni modifica automáticamente la reservación y nunca agrega un estado a `reservaciones`.

La autoridad de la afectación es `horario_impacto_reservaciones`. El buzón sólo presenta el caso y registra su cierre técnico; no es un CRM, un sistema de tickets ni una bitácora de decisiones humanas.

## Estados y tablas

Las tablas son:

- `horario_impactos`: cabecera del cambio, con estado `pendiente` o `resuelto`;
- `horario_impacto_reservaciones`: una fila por reservación afectada;
- `buzon_notificaciones`: presentación administrativa reutilizable.

Estados no finales de una fila afectada:

- `pendiente_notificacion`;
- `notificacion_preparada`;
- `sin_contacto`.

Estados finales:

- `atendida_manual`;
- `resuelta_por_cliente`.

`notificacion_preparada` significa que el aviso y el acceso temporal quedaron preparados para esperar la respuesta del cliente. No significa que un proveedor externo haya confirmado la entrega y no resuelve el impacto.

El impacto padre pasa a `resuelto` sólo cuando todas sus filas son finales.

La fila individual conserva `notification_attempts` y `last_notification_at`. No existe historial detallado de intentos ni un campo de motivo de resolución de negocio.

## Matriz operativa

| Caso | Al detectar el cambio | Buzón durante el acceso | Acciones administrativas |
| --- | --- | --- | --- |
| Hasta 12 personas con contacto válido | Preparar automáticamente el aviso y el acceso | `Aviso preparado` · `Esperando respuesta` | Sin CTA operativa principal mientras el acceso siga vigente; `Abrir reservación` puede aparecer como acción secundaria |
| Hasta 12 personas sin contacto | Mostrar el caso como accionable | `Sin contacto` | `Agregar contacto` como acción principal y `Abrir reservación` como acción secundaria |
| Más de 12 con contacto | No crear autoservicio ni acceso automático | `Grupo de más de 12 personas` · `Requiere gestión administrativa.` | Sólo `Abrir reservación` |
| Más de 12 sin contacto | No crear autoservicio ni acceso automático | `Grupo de más de 12 personas` y, en detalle, `Sin contacto registrado.` | Sólo `Abrir reservación` |

Agregar contacto guarda el dato mediante `ContactoService` y, si la reservación tiene hasta 12 personas, prepara automáticamente el aviso y el acceso. No existe un segundo paso administrativo para “preparar” después de guardar el contacto.

Si el acceso vence sin modificación del cliente, el caso pasa a `requiere_accion`. Cuando las reglas lo permiten, el detalle ofrece `Enviar recordatorio` como acción principal y `Abrir reservación` como acción secundaria.

`Enviar recordatorio` sólo puede habilitarse con contacto válido, reservación de hasta 12 personas, afectación todavía pendiente, acceso vencido, menos de tres intentos y cooldown terminado. Durante un acceso vigente no se permite reenviar.

Mientras no exista confirmación de entrega de un proveedor externo, la interfaz debe decir `Aviso preparado` o `Esperando respuesta`, nunca `Notificación enviada`.

Las herramientas de desarrollo se conservan en `development` y `testing`. `Copiar enlace de prueba` vive visualmente separado dentro de `Herramientas de desarrollo` y no cuenta como intento de notificación al cliente.

## Avisos y acceso

Una afectación admite como máximo tres avisos totales, incluido el automático. Cada preparación que cuenta como intento:

1. genera un token nuevo;
2. persiste únicamente su hash;
3. establece una nueva expiración para el acceso;
4. invalida de facto el token anterior;
5. incrementa `notification_attempts`;
6. actualiza `last_notification_at`.

El TTL predeterminado del acceso es de 60 minutos y se obtiene mediante `ReservacionConfig::scheduleChangeAccessTtlMinutes()`.

El cooldown entre recordatorios es de 15 minutos (`ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_COOLDOWN_MINUTES`). Al llegar a tres intentos se oculta `Enviar recordatorio` y se presenta el estado `Límite de recordatorios alcanzado`. La administración nunca queda bloqueada por ese límite.

El token plano sólo puede existir durante la preparación y entrega del acceso. Nunca se guarda en base de datos, logs, HTML persistente ni archivos del repositorio.

## Gestión y resolución

`Abrir reservación` lleva al detalle administrativo y conserva el contexto necesario para regresar al flujo administrativo.

Cuando existe una afectación activa, el detalle muestra el banner contextual:

> Cambio de horario
>
> Esta reservación quedó fuera del horario actual
>
> Modifica la fecha u hora, cancélala si corresponde o cierra el seguimiento si la atenderás fuera del sistema.

Desde ese detalle se reutilizan las acciones existentes `Modificar`, `Cancelar` y `Cerrar seguimiento`.

`Cerrar seguimiento` sólo resuelve el pendiente administrativo. Debe actualizar:

- `horario_impacto_reservaciones.estado = atendida_manual`;
- `resolved_by`;
- `resolved_at`;
- `access_invalidated_at = NOW()`;
- el cierre del aviso por tipo y entidad.

No cambia `reservaciones.estado`, fecha, hora, mesas ni comensales. Tampoco registra si el administrador llamó, escribió por WhatsApp, coordinó externamente o decidió mantener la reservación.

El modal de confirmación debe explicar expresamente que la reservación seguirá confirmada y que únicamente se retirará el seguimiento pendiente.

Se permite un motivo técnico de cierre, como `resuelta_admin`, `fuente_resuelta` o `resuelta_por_cliente`.

El contrato de cierre es `BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion()`. Para una afectación usa:

```text
tipo = reservacion_horario_afectado
entidad_tipo = horario_impacto_reservacion
entidad_id = impacto_reservacion_id
```

Nunca se usa el id de la afectación como si fuera el id de `buzon_notificaciones`. La resolución por cliente, la reconciliación posterior a una modificación o cancelación y la sincronización de fuentes finales usan el mismo contrato.

No existe `faseManualDisponible` ni `manual_habilitada`. Cada reservación afectada se gestiona de forma independiente; una reserva no bloquea el tratamiento de otra del mismo cambio de horario.

Tampoco existe una acción batch de “preparar disponibles”. El único contrato administrativo de preparación es:

```text
POST /admin/api/horarios-impactos/preparar
impacto_id + impacto_reservacion_id
```

Así el estado, cooldown, límite de intentos y acceso temporal siguen siendo propiedades del caso individual.

## Buzón administrativo

El trigger está en el topbar. El drawer vive como portal global en el layout y tiene dos vistas mutuamente excluyentes.

### Lista

La lista muestra una fila compacta y navegable por reservación. La fila contiene la identidad, fecha, hora, personas, motivo principal y, cuando corresponde, la existencia de motivos adicionales. No necesita un botón grande `Revisar`: la propia fila abre el detalle.

Los filtros visibles son:

- `Por atender`;
- `En espera`;
- `Todas`.

`Por atender` muestra casos con `requiere_accion = true`. `En espera` muestra seguimientos informativos mientras no requieren intervención inmediata.

### Detalle

El detalle muestra una sola reservación y sólo las acciones válidas para ese caso. Los filtros y el resumen global se ocultan mientras la vista de detalle está activa.

Las notificaciones de una misma reservación se agrupan para resolver el caso como una unidad de presentación. La lista usa `buzon_notificaciones` como autoridad para `requiere_accion`, `prioridad`, `leida_at` y `cerrada_at`; el estado de negocio de una afectación sigue viviendo en `horario_impacto_reservaciones`.

Debe existir como máximo una acción primaria operativa por caso. La prioridad de presentación es:

1. ausencia pendiente: `Registrar que no llegó`; `Abrir reservación` queda como secundaria;
2. afectación de horario hasta 12 sin contacto: `Agregar contacto`; `Abrir reservación` secundaria;
3. afectación de horario hasta 12 con acceso vencido y reenvío permitido: `Enviar recordatorio`; `Abrir reservación` secundaria;
4. afectación de horario hasta 12 esperando respuesta: sin CTA operativa principal; `Abrir reservación` secundaria;
5. afectación de horario de más de 12 personas: `Abrir reservación` como única acción principal;
6. reservación próxima sin mesas: `Asignar mesas`; `Abrir reservación` secundaria.

Si una ausencia pendiente coincide con otros motivos, la ausencia domina la acción principal y las acciones que quedarían obsoletas después de registrar el no-show no deben competir como CTA primarias.

Las herramientas de desarrollo aparecen en una sección separada y no modifican esta jerarquía.

El resumen distingue `cantidad_accionable`, `cantidad_seguimiento` y `prioridad_maxima_accionable`. El badge del topbar cuenta únicamente `cantidad_accionable`. El icono de seguimiento es discreto y no usa animaciones permanentes.

Al abrir el drawer se llama `window.AdminScrollLock.bloquear()` y al cerrar `desbloquear()`. La página no se desplaza; sólo el cuerpo del drawer usa `overflow-y: auto` y `overscroll-behavior: contain`.

Si falla una actualización, se conserva la lista anterior cuando ya existe y se muestra `No pudimos actualizar las notificaciones.` con `Reintentar`.

`GET /admin/api/buzon` y `GET /admin/api/buzon/resumen` son de lectura. La reconciliación y creación de avisos temporales ocurren en `POST /admin/api/buzon/sincronizar`. Una fuente final no vuelve a abrir su aviso por un `ON DUPLICATE KEY UPDATE` posterior.

## Otros avisos

La ausencia pendiente conserva el flujo canónico: `Tolerancia de llegada vencida`, `Registrar que no llegó` y `Abrir reservación`.

La asignación próxima conserva `Reservación próxima sin mesas`, `Asignar mesas` y `Abrir reservación`; las mesas se asignan en el mapa, no dentro del buzón.

`reservacion_grupo_grande` sólo permanece cuando existe una acción real de coordinación, como falta de contacto o falta de mesas. Una reservación de más de 12 personas ya coordinada no debe llenar el buzón indefinidamente por su tamaño.

Varios motivos de una misma reservación se agrupan en una sola fila y un solo detalle.

## Transporte externo y n8n

La rama actual prepara el dominio y el acceso temporal, pero la entrega externa definitiva de OTP y avisos operativos se integra como una capa separada.

La aplicación es la autoridad para decidir:

- qué reservación requiere notificación;
- qué contacto está autorizado;
- si el caso admite autoservicio;
- si el acceso sigue vigente;
- el límite y cooldown de intentos;
- la generación del token;
- el contenido mínimo que puede salir del sistema.

n8n actúa únicamente como transporte y orquestación del canal externo. No decide estados de reservación, capacidad, mesas, elegibilidad, intentos ni resolución del seguimiento.

El guardado de horarios y la persistencia de impactos deben confirmar su transacción antes de depender de una llamada externa. Una falla de n8n no debe revertir el cambio de horario ni dejar la base de datos en una transacción abierta.

Para la integración definitiva, el token plano del acceso debe entregarse al proveedor sólo en memoria y después persistirse únicamente su hash. El proveedor externo nunca recibe credenciales internas ni datos operativos adicionales que no sean necesarios para entregar el aviso.

Los exports versionados de n8n no deben contener `pinData`, códigos OTP, tokens de acceso, teléfono, correo ni payloads reales de clientes.

## Validación

La validación esperada incluye:

```text
npm test
npm run build
npm run test:runtime
php -l <cada PHP modificado>
git diff --check
```

También deben cubrirse la matriz de cuatro casos, límite y cooldown, invalidación de tokens, cierre por entidad, resolución sin modificar la reservación, modal de confirmación, drawer, listado administrativo, presentación POS y fallas del transporte externo sin rollback del cambio de horario.
