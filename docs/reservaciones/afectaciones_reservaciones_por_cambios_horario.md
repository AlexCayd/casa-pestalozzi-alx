# Afectaciones de reservaciones por cambios de horario

## Alcance

Cuando un cambio del horario efectivo deja una reservación fuera de operación, el sistema conserva la reservación y crea un seguimiento durable. El cambio de horario no cancela, reprograma ni modifica automáticamente la reservación y nunca agrega un estado a `reservaciones`.

La autoridad de la afectación es `horario_impacto_reservaciones`. El buzón sólo presenta el caso y registra su cierre técnico; no es un CRM, un sistema de tickets ni una bitácora de decisiones humanas.

## Estados y tablas

Las tablas son:

- `horario_impactos`: cabecera del cambio, con estado `pendiente` o `resuelto`.
- `horario_impacto_reservaciones`: una fila por reservación afectada.
- `buzon_notificaciones`: presentación administrativa reutilizable.

Estados no finales de una fila afectada:

- `pendiente_notificacion`;
- `notificacion_preparada`;
- `sin_contacto`.

Estados finales:

- `atendida_manual`;
- `resuelta_por_cliente`.

`notificacion_preparada` significa que el aviso/acceso está listo para esperar la respuesta del cliente; no resuelve el impacto. El impacto padre pasa a `resuelto` sólo cuando todas sus filas son finales.

La fila individual también conserva `notification_attempts` y `last_notification_at`. No existe historial de intentos ni campo de motivo de resolución de negocio.

## Matriz operativa

| Caso | Al detectar el cambio | Buzón durante el acceso | Acciones administrativas |
| --- | --- | --- | --- |
| Hasta 12 personas con contacto válido | Preparar automáticamente el aviso y el acceso | `Aviso preparado` · `Esperando respuesta` | Ninguna CTA principal; el detalle puede ofrecer `Gestionar` como enlace secundario |
| Hasta 12 personas sin contacto | Mostrar el caso como accionable | `Sin contacto` | `Agregar contacto` y `Gestionar` |
| Más de 12 con contacto | No crear autoservicio ni acceso automático | `Grupo de más de 12 personas` · `Requiere gestión administrativa.` | Sólo `Gestionar` |
| Más de 12 sin contacto | No crear autoservicio ni acceso automático | `Grupo de más de 12 personas` y, en detalle, `Sin contacto registrado.` | Sólo `Gestionar` |

Agregar contacto guarda el dato mediante `ContactoService` y prepara automáticamente el aviso si la reservación tiene hasta 12 personas. No hay un segundo paso de preparar, enviar o confirmar envío.

Si el acceso vence sin modificación del cliente, el caso pasa a `requiere_accion` y el detalle muestra `Gestionar` y `Mandar aviso`. `Mandar aviso` sólo se habilita con contacto válido, hasta 12 personas, afectación pendiente, acceso vencido, menos de tres intentos y cooldown terminado. Durante el acceso vigente no se permite reenviar.

Mientras no exista confirmación de entrega de un proveedor externo, la interfaz dice `Aviso preparado` y no `Notificación enviada`. El enlace de prueba sólo aparece en `development` o `testing`, dentro de `Herramientas de desarrollo`, y no cuenta como intento de aviso.

## Avisos y acceso

Una afectación admite como máximo tres avisos totales, incluido el automático. Cada nuevo aviso:

1. genera un token nuevo;
2. persiste sólo su hash;
3. reemplaza la expiración por un TTL de 60 minutos;
4. invalida de facto el token anterior;
5. incrementa `notification_attempts` y actualiza `last_notification_at`.

El cooldown es de 15 minutos (`ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_COOLDOWN_MINUTES`). Al llegar a tres intentos, se oculta `Mandar aviso` y se muestra `Se alcanzó el límite de avisos.`. La administración nunca queda bloqueada por ese límite.

## Gestión y resolución

`Gestionar` lleva al detalle administrativo de la reservación y conserva `return_url` cuando se llega desde el buzón. El detalle muestra el banner contextual:

> Esta reservación requiere atención
>
> Un cambio en el horario de operación dejó esta reservación fuera del horario actual.
>
> Modifica la fecha u horario, cancela la reservación si corresponde o confirma que el caso se resolverá fuera del sistema.

Desde ahí se reutilizan las acciones existentes `Modificar reservación` y `Cancelar reservación`, además de `Marcar como resuelta`. La última usa el modal `Marcar seguimiento como resuelto` y sólo actualiza:

- `horario_impacto_reservaciones.estado = atendida_manual`;
- `resolved_by`;
- `resolved_at`;
- `access_invalidated_at = NOW()`;
- el cierre del aviso por tipo y entidad.

No cambia `reservaciones.estado`, fecha, hora, mesas ni comensales. No se guarda si el administrador llamó, escribió por WhatsApp, coordinó externamente o decidió mantener la reservación. Se permite sólo un motivo técnico de cierre, como `resuelta_admin`, `fuente_resuelta` o `resuelta_por_cliente`.

El contrato de cierre es `BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion()`. Para una afectación usa:

```text
tipo = reservacion_horario_afectado
entidad_tipo = horario_impacto_reservacion
entidad_id = impacto_reservacion_id
```

Nunca se usa el id de la afectación como si fuera el id de `buzon_notificaciones`. La resolución por cliente, la reconciliación posterior a una modificación/cancelación y la sincronización de fuentes finales usan el mismo contrato.

No existe una `faseManualDisponible` ni `manual_habilitada`. Cada reservación afectada se gestiona de forma independiente; una reserva no bloquea el tratamiento de otra del mismo cambio de horario.

Tampoco existe una acción batch de “preparar disponibles”: el único contrato de
preparación es `POST /admin/api/horarios-impactos/preparar` para una fila
`impacto_id + impacto_reservacion_id`. Así el estado, el cooldown, el límite de
intentos y el acceso temporal siguen siendo propiedades del caso individual.

## Buzón administrativo

El trigger está en el topbar. El drawer vive como portal global en el layout y tiene dos vistas mutuamente excluyentes:

- **Lista:** una card por reservación, con `Revisar` como única acción de la card y filtros `Atención`, `Seguimiento` y `Todas`.
- **Detalle:** una sola reservación y sólo sus acciones válidas.

Las tarjetas se agrupan por entidad y muestran una sola reservación afectada,
aunque tenga varios motivos o seguimientos abiertos. La lista usa la autoridad
de `buzon_notificaciones` para `requiere_accion`, `prioridad`, `leida_at` y
`cerrada_at`; el estado de negocio de la afectación sigue viviendo en
`horario_impacto_reservaciones` (`pendiente_notificacion`,
`notificacion_preparada`, `sin_contacto`, `atendida_manual` o
`resuelta_por_cliente`). Las acciones visibles son `Revisar`, `Gestionar`,
`Agregar contacto`, `Mandar aviso` cuando las reglas lo permiten y `Cerrar
seguimiento`; no se agrega un estado paralelo en `reservaciones`.

El resumen distingue `cantidad_accionable`, `cantidad_seguimiento` y `prioridad_maxima_accionable`. El badge del topbar cuenta únicamente `cantidad_accionable`. El icono de seguimiento es discreto y no usa pulse ni shake permanentes.

Al abrir el drawer se llama `window.AdminScrollLock.bloquear()` y al cerrar `desbloquear()`. La página no se desplaza; sólo el cuerpo del drawer usa `overflow-y: auto` y `overscroll-behavior: contain`. Si falla una actualización, se conserva la lista anterior cuando ya existe y se muestra `No pudimos actualizar las notificaciones.` con `Reintentar`.

`GET /admin/api/buzon` y `GET /admin/api/buzon/resumen` son de lectura. La reconciliación y la creación de avisos temporales ocurren en `POST /admin/api/buzon/sincronizar`. Una fuente final no vuelve a abrir su aviso por un `ON DUPLICATE KEY UPDATE` posterior.

## Otros avisos

La ausencia pendiente conserva el flujo canónico: `Tolerancia de llegada vencida`, `Registrar no-show` y `Gestionar`. La asignación próxima conserva `Reservación próxima sin mesas`, `Asignar mesas` y `Gestionar`; las mesas se asignan en el mapa, no dentro del buzón. Varios motivos de una misma reservación se agrupan en una sola card.

## Validación

La validación esperada incluye:

```text
npm test
npm run build
php -l <cada PHP modificado>
git diff --check
```

También deben cubrirse la matriz de cuatro casos, el límite y cooldown, la invalidación de tokens, cierre por entidad, resolución sin modificar la reservación, modal de confirmación, drawer, listado administrativo y presentación POS.
