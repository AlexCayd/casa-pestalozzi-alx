# Afectaciones de reservaciones por cambios de horario

## Propósito

Cuando una modificación del horario efectivo deja una reservación confirmada fuera de operación, el sistema conserva la reservación y registra un seguimiento durable. Ningún cambio de horario cancela reservaciones automáticamente.

El seguimiento es operativo: prepara avisos, permite atender manualmente los casos sin contacto y ofrece al cliente un acceso temporal para elegir otro horario disponible.

## Tablas

La implementación usa únicamente estas dos tablas:

- `horario_impactos`: un evento de cambio de horario, con `tipo_origen`, `origen_id`, `estado`, `dedup_key`, autoría y resolución.
- `horario_impacto_reservaciones`: una fila por reservación afectada. Guarda `estado`, `notification_prepared_at`, `access_token_hash`, `access_expires_at`, `access_invalidated_at`, `resolved_by`, `resolved_at` y timestamps.

Los estados de una fila son:

| Estado | Significado |
| --- | --- |
| `pendiente_notificacion` | Tiene contacto y aún no se prepara el aviso. |
| `notificacion_preparada` | El aviso y el acceso temporal están preparados. |
| `sin_contacto` | No hay contacto utilizable; espera captura interna o atención manual. |
| `atendida_manual` | El personal confirmó la atención por un canal no registrado. |
| `resuelta_por_cliente` | El cliente confirmó un reemplazo desde el acceso temporal. |

El impacto pasa a `resuelto` cuando todas sus filas están en un estado final. El estado canónico de `reservaciones` no se duplica en el seguimiento.

## Migración

El DDL base crea las dos tablas finales. La migración forward `2026_08_18_simplificar_afectaciones_horario.sql` sirve para instalaciones que ya ejecutaron la primera versión: traduce estados antiguos, migra registros de avisos al timestamp de preparación, invalida enlaces anteriores y elimina las tablas históricas de notificaciones y links.

No se crea una outbox ni se realiza un envío externo en esta etapa. `notification_prepared_at` y el estado `notificacion_preparada` forman el contrato futuro para una integración con n8n.

## Acceso temporal del cliente

El acceso se abre en:

```text
GET /reservaciones/cambio-horario?access=<TOKEN>
```

Al preparar un aviso se genera un token con `bin2hex(random_bytes(32))`; la base de datos sólo conserva `hash('sha256', $token)`. El TTL se configura con `SCHEDULE_CHANGE_ACCESS_TTL_MINUTES`, con valor predeterminado de 60 minutos y límites de 15 a 180 minutos.

El primer GET valida el token, crea un contexto de sesión independiente y redirige con 303 a `/reservaciones/cambio-horario` sin query string. El contexto sólo contiene:

- `impacto_reservacion_id`;
- `reservacion_id`;
- `expires_at`;
- un CSRF independiente.

No reutiliza `ReservationClientSession` y no guarda nombre, correo, teléfono ni otra PII en sesión, query string, `localStorage` o `sessionStorage`. Cada request vuelve a validar token/estado, expiración, ids y editabilidad.

Un token preparado se invalida al modificar la reservación, resolver manualmente, regenerar el acceso o invalidar el impacto. La regeneración sobrescribe el mismo hash y la misma expiración lógica; no crea una fila paralela.

Un token inválido o expirado muestra una página de acceso vencido con el enlace `Gestionar mi reservación` hacia `/reservaciones`.

## Formulario público

La página nueva es independiente del gestor general de reservaciones. Muestra únicamente:

- nombre en modo readonly;
- fecha y hora actuales;
- comensales actuales;
- nueva fecha, hora, comensales y comentario.

El contacto nunca llega al HTML, JSON, atributos `data-*`, JavaScript, URL o parámetros del formulario.

Los endpoints son:

```text
POST /api/reservaciones/cambio-horario/disponibilidad
POST /api/reservaciones/cambio-horario/modificar
```

La consulta y la modificación reutilizan disponibilidad, horario efectivo, límites de comensales, mesas y demás reglas canónicas. La modificación autorizada sólo acepta el contexto temporal directo; no acepta una identidad de contacto como sustituto.

La confirmación del reemplazo se realiza en una transacción: reserva el nuevo horario, confirma la nueva reservación, marca la original como reemplazada, marca la afectación como `resuelta_por_cliente` e invalida su acceso.

## Seguimiento administrativo

El aviso de pendientes es una alerta flotante fija: esquina inferior derecha en escritorio y con margen interior en móvil. El layout obtiene el resumen con una consulta `resumenPendientes()` y no carga la lista completa en cada pantalla.

Al resolver una alerta se abre un modal de flujo ancho, exclusivo de este proceso:

- `width: min(960px, calc(100vw - 40px))`;
- altura máxima `calc(100dvh - 40px)`;
- encabezado y pie fijos;
- cuerpo con scroll;
- filas renderizadas una sola vez desde la respuesta de API;
- contacto como vista interna del mismo modal, sin modal anidado.

Mientras existan filas pendientes no hay X, Escape, cierre por backdrop ni botón de finalizar. La fase manual sólo se habilita cuando todos los casos con contacto están en `notificacion_preparada`.

Acciones administrativas:

- preparar un aviso individual;
- preparar todos los avisos disponibles;
- agregar contacto a una fila `sin_contacto`;
- atender manualmente cuando la fase manual está habilitada;
- generar o regenerar un link de prueba sólo en `development`/`testing`.

La preparación masiva devuelve `total`, `preparadas`, `fallidas` y `fallas`. Si hay fallos parciales responde `ok: false`; los registros fallidos permanecen pendientes y los exitosos no se revierten.

## Confirmación de cambios de horario

Las mutaciones semanales y de excepciones consultan primero los conflictos. Si existen, el cliente muestra un único `ConfirmationModal` con el copy:

> Este cambio afecta N reservaciones. Ninguna será cancelada automáticamente.

La acción primaria es `Aplicar cambio`. No se renderiza un segundo botón de “guardar de todas formas” ni una confirmación paralela del servidor.

## Seguridad y privacidad

- Las rutas públicas sólo autorizan el registro de afectación que corresponde al token y a su reservación.
- Un mismo contacto no puede usar el acceso de otra afectación: el contexto queda ligado a ids concretos y se revalida en cada request.
- Las respuestas públicas no exponen contacto ni identificadores innecesarios.
- Los encabezados de las respuestas temporales evitan cache y referrer leakage.
- Los tokens planos sólo se devuelven en desarrollo/pruebas para copiar el link en memoria.

## Validación

Antes de integrar cambios se ejecutan:

```text
npm test
npm run build
php -l <cada PHP modificado>
git diff --check
```

También se verifica manualmente el formulario público, expiración e invalidación del acceso, preparación individual/masiva, contacto interno, atención manual, reemplazo atómico y bloqueo de salida del modal.
