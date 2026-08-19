# Afectaciones de reservaciones por cambios de horario

## Propósito

Cuando una modificación del horario efectivo deja una reservación confirmada fuera de operación, el sistema conserva la reservación y registra un seguimiento durable. Ningún cambio de horario cancela reservaciones automáticamente.

El seguimiento es operativo. El sistema debe intentar resolver automáticamente los casos simples, recordar de forma persistente los casos que requieren intervención administrativa y ofrecer un acceso temporal al cliente únicamente cuando el autoservicio sea apropiado.

El estado canónico de `reservaciones` no se modifica para representar afectaciones de horario. Una reservación afectada continúa siendo una reservación confirmada mientras no exista una transición canónica distinta.

## Principios

1. Un cambio de horario nunca cancela, reprograma ni modifica automáticamente una reservación existente.
2. Una afectación de horario no es un estado del ciclo de vida de la reservación.
3. Las incidencias operativas se registran fuera de `reservaciones.estado`.
4. El guardado del horario no depende de que las afectaciones se resuelvan en la misma petición.
5. El sistema debe recordar los pendientes; el administrador no debe memorizar reservaciones afectadas.
6. Las acciones repetitivas deben automatizarse cuando exista suficiente información.
7. Las reservaciones de más de 12 personas requieren tratamiento administrativo especial y no deben entrar automáticamente al flujo de autoservicio.
8. El buzón administrativo es reutilizable por otros módulos y no constituye una segunda fuente de verdad del dominio.
9. Leer una notificación del buzón no equivale a resolverla.
10. Las acciones del buzón deben delegar la resolución al servicio de dominio correspondiente.

## Modelo de datos

La arquitectura utiliza tres tablas relacionadas con esta funcionalidad:

- `horario_impactos`: cabecera de un evento de cambio de horario.
- `horario_impacto_reservaciones`: una fila por reservación afectada por ese evento.
- `buzon_notificaciones`: buzón administrativo reutilizable para acciones pendientes de reservaciones y otros módulos.

No se crean tablas independientes para links temporales ni una outbox de notificaciones en esta etapa.

### `horario_impactos`

Representa un único cambio de horario que afectó una o más reservaciones.

Campos mínimos:

- `id`;
- `tipo_origen`;
- `origen_id`;
- `estado`;
- `dedup_key`;
- `created_by`;
- `created_at`;
- `resolved_at`.

Estados:

- `pendiente`;
- `resuelto`.

El impacto pasa a `resuelto` únicamente cuando todas sus filas asociadas están en un estado final.

### `horario_impacto_reservaciones`

Representa la afectación individual de una reservación.

Campos mínimos:

- `id`;
- `impacto_id`;
- `reservacion_id`;
- `estado`;
- `notification_prepared_at`;
- `access_token_hash`;
- `access_expires_at`;
- `access_invalidated_at`;
- `resolved_by`;
- `resolved_at`;
- `created_at`;
- `updated_at`.

Estados:

| Estado                   | Significado                                                            |
| ------------------------ | ---------------------------------------------------------------------- |
| `pendiente_notificacion` | Tiene contacto, admite autoservicio y aún no se ha preparado el aviso. |
| `notificacion_preparada` | El aviso y el acceso temporal están preparados.                        |
| `sin_contacto`           | No hay contacto utilizable; requiere revisión administrativa.          |
| `atendida_manual`        | Administración resolvió el seguimiento sin autoservicio.               |
| `resuelta_por_cliente`   | El cliente confirmó un reemplazo desde el acceso temporal.             |

No se agregan estados como `afectada_horario` a `reservaciones.estado`.

### `buzon_notificaciones`

Es una infraestructura administrativa genérica. Su objetivo es presentar acciones pendientes sin duplicar la lógica del módulo que las originó.

Campos mínimos recomendados:

- `id`;
- `tipo`;
- `modulo`;
- `entidad_tipo`;
- `entidad_id`;
- `prioridad`;
- `visible_from`;
- `leida_at`;
- `cerrada_at`;
- `cerrada_por`;
- `cierre_motivo`;
- `dedup_key`;
- `created_at`;
- `updated_at`.

Índices mínimos:

- `UNIQUE(dedup_key)`;
- índice por `cerrada_at, visible_from`;
- índice por `tipo, cerrada_at`;
- índice por `entidad_tipo, entidad_id`.

El buzón no almacena nombre, teléfono, correo ni otros datos personales del cliente.

`entidad_tipo + entidad_id` identifica la fuente del aviso. La integridad de esta referencia polimórfica se valida desde `BuzonNotificacionesService`; si la entidad fuente ya no existe o dejó de requerir atención, el aviso debe cerrarse/reconciliarse automáticamente.

El buzón no almacena una copia del estado de negocio. El estado real permanece en la entidad fuente.

## Semántica del buzón

Una notificación puede estar:

- visible y no leída;
- visible y leída;
- cerrada.

`leida_at` sólo registra que un administrador abrió o vio el aviso.

Una notificación permanece pendiente mientras `cerrada_at IS NULL`.

Cerrar una notificación relacionada con una acción operativa debe ocurrir después de que el servicio de dominio correspondiente haya resuelto o descartado explícitamente el caso.

No debe existir una acción genérica que oculte silenciosamente una incidencia no resuelta.

Cuando el producto permita “Descartar”, la acción debe tener una semántica de dominio explícita, por ejemplo:

- mantener la reservación actual;
- no requiere notificación;
- coordinación completada;
- caso resuelto por otro medio.

La resolución se registra primero en el dominio y después se cierra el aviso del buzón.

## Detección de impacto

Las siguientes mutaciones deben evaluar impacto:

- modificación del horario semanal;
- creación de excepción;
- edición de excepción;
- activación de excepción;
- desactivación de excepción;
- eliminación de excepción.

La evaluación compara el horario efectivo anterior con el horario efectivo resultante.

Una reservación genera una afectación cuando era válida antes del cambio y queda fuera del nuevo horario efectivo.

La misma mutación no debe duplicar el impacto ni sus reservaciones si la petición se repite.

## Confirmación de cambios de horario

Cuando existen reservaciones afectadas se muestra un único `ConfirmationModal`:

> Este cambio afecta N reservaciones. Ninguna será cancelada automáticamente.

La acción primaria es `Aplicar cambio`.

No existe un segundo botón de “Guardar de todas formas” ni una confirmación paralela.

Después de confirmar:

1. se guarda el cambio de horario;
2. se persiste `horario_impactos`;
3. se persisten sus reservaciones afectadas;
4. se generan o programan los avisos de buzón correspondientes;
5. la petición termina.

La resolución de los casos no bloquea el guardado del horario.

## Clasificación de reservaciones afectadas

### Reservaciones de 1 a 12 personas con contacto

Son elegibles para autoservicio.

El sistema prepara automáticamente el aviso y el acceso temporal; no se requiere que el administrador pulse un botón de envío por cada reservación.

En una integración futura con n8n, la aplicación enviará automáticamente la intención de notificación al mecanismo de entrega.

Mientras no exista envío externo real, `notification_prepared_at` significa únicamente “aviso preparado”, no “mensaje enviado”.

Al preparar el acceso se crea también una notificación de buzón con `visible_from = access_expires_at`.

Si el cliente modifica la reservación antes del vencimiento, la afectación y el aviso del buzón se resuelven automáticamente y nunca requieren intervención administrativa.

Si el acceso vence sin modificación, el aviso se vuelve visible en el buzón.

### Reservaciones de 1 a 12 personas sin contacto

No se intenta autoservicio.

La fila queda en `sin_contacto` y se crea una notificación de buzón visible inmediatamente.

El administrador puede:

- gestionar la reservación;
- agregar contacto;
- mantener la reservación actual;
- resolver el caso por otro medio.

Agregar contacto permite reclasificar el caso como notificable y preparar el acceso temporal.

La falta de contacto nunca bloquea el cambio de horario.

### Reservaciones de más de 12 personas

Las reservaciones de más de 12 personas son administrativas y requieren coordinación especial.

Aunque tengan contacto, no se envía automáticamente un enlace de autoservicio por un cambio de horario.

Se crea una notificación de buzón de prioridad alta para revisión administrativa.

El administrador puede:

- gestionar la reservación;
- agregar o actualizar contacto si corresponde;
- coordinar directamente con el cliente;
- mantener la reservación aunque quede fuera del nuevo horario;
- reprogramarla administrativamente;
- marcar la coordinación como completada.

La razón es que las reservaciones de más de 12 personas no usan asignación automática y pueden requerir decisiones de capacidad, mesas y operación que no deben delegarse al formulario público.

## Seguimiento de grupos de más de 12 personas

El buzón puede reutilizarse también para reservaciones administrativas de grupo grande aunque no exista un cambio de horario.

Cuando una reservación de más de 12 personas se crea o queda en una condición que requiera intervención operativa, puede generarse una notificación de tipo `reservacion_grupo_grande`.

No se crean estados adicionales en `reservaciones`.

La reservación permanece `confirmada` y el seguimiento se expresa mediante el buzón y los servicios administrativos.

Una misma reservación puede tener varios motivos de seguimiento, pero la interfaz debe agruparlos por reservación para no mostrar avisos redundantes.

Ejemplo:

- grupo grande;
- afectada por cambio de horario;
- sin mesas asignadas.

La interfaz puede mostrar una sola tarjeta con todos los motivos activos.

## Acceso temporal del cliente

El acceso se abre en:

```text
GET /reservaciones/cambio-horario?access=<TOKEN>
```

Al preparar un aviso se genera:

```php
bin2hex(random_bytes(32))
```

La base de datos conserva únicamente:

```php
hash('sha256', $token)
```

El TTL se configura mediante:

```text
SCHEDULE_CHANGE_ACCESS_TTL_MINUTES
```

Reglas:

- predeterminado: 60 minutos;
- mínimo: 15 minutos;
- máximo: 180 minutos.

El primer GET valida el token y crea un contexto de sesión independiente limitado a:

- `impacto_reservacion_id`;
- `reservacion_id`;
- `expires_at`;
- CSRF independiente.

Después responde con `303` hacia `/reservaciones/cambio-horario` sin query string.

No reutiliza `ReservationClientSession`.

No guarda nombre, contacto, teléfono, correo ni otra PII en el contexto temporal.

Cada petición vuelve a validar:

- afectación;
- reservación;
- expiración;
- ids;
- estado;
- editabilidad.

El token se invalida cuando:

- el cliente modifica correctamente;
- administración resuelve manualmente;
- administración regenera el acceso;
- la afectación deja de ser válida.

Un acceso inválido o expirado muestra una pantalla con:

`Gestionar mi reservación`

hacia el flujo normal de `/reservaciones` con verificación de contacto.

## Formulario público

La página es independiente del gestor general, pero debe utilizar el mismo lenguaje visual de la landing.

Debe reutilizar, siempre que sean compatibles:

- selector de fecha de reservaciones;
- selector de horarios;
- stepper de comensales;
- estilos de campos;
- botones;
- tipografía;
- espaciado;
- tokens visuales;
- comportamiento responsive.

No se debe crear una segunda familia visual de componentes para este formulario.

La pantalla muestra únicamente:

- nombre readonly;
- fecha y hora actuales;
- comensales actuales;
- nueva fecha;
- nuevo horario;
- comensales;
- comentario público.

El contacto nunca llega a:

- HTML;
- JSON;
- atributos `data-*`;
- JavaScript;
- URL;
- inputs hidden.

Los endpoints son:

```text
POST /api/reservaciones/cambio-horario/disponibilidad
POST /api/reservaciones/cambio-horario/modificar
```

La consulta y modificación reutilizan las reglas canónicas de disponibilidad, horario, capacidad, mesas, límites públicos y modificación.

El acceso temporal sólo sustituye la verificación inicial de identidad.

## Modificación y resolución

La confirmación de un reemplazo desde el acceso temporal coordina en una misma operación:

1. reservar el nuevo horario;
2. confirmar la nueva reservación;
3. marcar la original como reemplazada;
4. marcar la afectación como `resuelta_por_cliente`;
5. invalidar el acceso temporal;
6. cerrar cualquier aviso de buzón asociado;
7. marcar `horario_impactos` como resuelto si ya no quedan filas pendientes.

Si administración modifica la reservación por el flujo normal y ésta deja de estar afectada, la afectación debe reconciliarse automáticamente.

También debe resolverse automáticamente cuando:

- la reservación se cancela;
- la reservación termina en un estado final que elimina la obligación;
- un cambio posterior de horario vuelve a hacer válida la reservación.

## Buzón administrativo

El panel administrativo dispone de un buzón flotante y persistente.

El contador muestra avisos abiertos y visibles, no simplemente avisos no leídos.

El buzón puede implementarse como drawer lateral para no alterar el layout principal.

La consulta global debe obtener únicamente un resumen ligero:

- cantidad visible;
- prioridad máxima;
- primeros identificadores si son necesarios.

La lista completa se carga únicamente al abrir el buzón.

Una notificación debe mostrar información derivada de su entidad fuente y acciones específicas según su `tipo`.

Tipos iniciales permitidos:

- `reservacion_horario_afectado`;
- `reservacion_grupo_grande`;
- `reservacion_ausencia_pendiente`;
- `reservacion_sin_asignacion_proxima`.

Los avisos temporales se sincronizan desde el buzón con el endpoint administrativo protegido por CSRF. La sincronización usa la vigencia y la política POS canónicas, lee en lote los tickets abiertos y no se inserta en los flujos críticos de alta, edición, asignación o transición.

`reservacion_ausencia_pendiente` se crea para una reservación confirmada del día cuya tolerancia venció sin ticket abierto y cuya acción canónica permite no-show. Es de prioridad alta, permanece abierto aunque cambie el día y se cierra después de no-show, inicio de servicio, ticket abierto o estado final.

`reservacion_sin_asignacion_proxima` se crea para una reservación confirmada de hasta 12 personas, sin ticket ni mesas, dentro del horario efectivo y de la ventana canónica. Es normal entre 60 y 30 minutos y alta a 30 minutos o menos, incluida la tolerancia. No se crea después de 60 minutos, fuera de horario, para grupos grandes o cuando existe ausencia pendiente. Se cierra al asignar mesas, abrir ticket, salir de la ventana, cancelar/finalizar, entrar en ausencia o quedar fuera del horario efectivo.

No agregar tipos hipotéticos hasta que exista una necesidad real. La agrupación muestra una tarjeta por reservación y ordena ausencia, afectación de horario, grupo grande y asignación próxima.

El buzón queda diseñado para admitir posteriormente avisos de otros módulos sin cambiar su modelo base.

## Acciones de buzón para cambios de horario

Para una reservación afectada se pueden ofrecer, según contexto:

- `Gestionar reservación`;
- `Agregar contacto`;
- `Mantener reservación`;
- `Coordinar por otro medio`;
- `Copiar link de prueba` únicamente en `development`/`testing`.

`Mantener reservación` significa que administración acepta conservar el compromiso aunque se encuentre fuera del nuevo horario.

Esa acción:

- no cambia `reservaciones.estado`;
- resuelve la afectación administrativamente;
- invalida el acceso temporal si existe;
- cierra el aviso correspondiente.

No se debe obligar al administrador a mantener abierto un modal hasta resolver todos los casos.

## Modo development/testing

No se realizan envíos externos.

Para reservaciones elegibles se prepara automáticamente el acceso temporal.

El sistema puede ofrecer:

`Copiar link de prueba`

para validar el flujo.

La interfaz debe indicar:

`Aviso preparado para pruebas`

y nunca afirmar que el mensaje fue enviado.

Regenerar el link sobrescribe el hash y la expiración anteriores; no crea otra fila.

## Integración futura con n8n

El guardado del horario y el seguimiento no dependen de n8n.

Cuando se integre:

1. la aplicación decide qué reservaciones son notificables;
2. genera el acceso temporal;
3. prepara la intención de notificación;
4. n8n se encarga de la entrega externa;
5. cualquier fallo de entrega genera o mantiene una acción administrativa visible.

El administrador no debe enviar manualmente uno por uno los avisos normales.

Las reservaciones de más de 12 personas permanecen bajo coordinación administrativa salvo que en el futuro se defina explícitamente un flujo distinto.

## Seguridad y privacidad

- El acceso temporal autoriza únicamente la reservación asociada a la afectación.
- El mismo contacto no permite acceder a otra reservación desde ese contexto.
- El contacto nunca se expone en el formulario directo.
- Los tokens planos no se persisten.
- Las respuestas temporales evitan cache y referrer leakage.
- El buzón no duplica PII.
- El buzón no sustituye la autorización del módulo fuente.
- Cada acción vuelve a validar permisos y estado de la entidad.
- Un aviso huérfano o cuya fuente ya fue resuelta debe cerrarse por reconciliación.

## Validación de arquitectura

La implementación debe mantener una única autoridad por responsabilidad:

| Responsabilidad                        | Autoridad                            |
| -------------------------------------- | ------------------------------------ |
| Ciclo de vida de la reservación        | `reservaciones.estado`               |
| Evento de cambio de horario            | `horario_impactos`                   |
| Afectación individual                  | `horario_impacto_reservaciones`      |
| Presentación persistente de pendientes | `buzon_notificaciones`               |
| Disponibilidad/capacidad/mesas         | servicios canónicos de reservaciones |

No almacenar el mismo hecho de negocio como dos estados independientes.

El buzón puede registrar lectura y cierre, pero la resolución de una afectación siempre se realiza primero en el dominio correspondiente.

## Validación funcional

Antes de integrar cambios se ejecutan:

```text
npm test
npm run build
php -l <cada PHP modificado>
git diff --check
```

Además deben existir pruebas para:

- cambio de horario con reservaciones afectadas;
- deduplicación de impactos;
- reservación de 1–12 con contacto;
- expiración del acceso temporal;
- aparición diferida en buzón al expirar;
- reservación sin contacto;
- reservación de más de 12 personas;
- agrupación de varios motivos sobre una misma reservación;
- resolución por cliente;
- resolución administrativa;
- restauración posterior del horario;
- cancelación de reservación;
- privacidad del formulario;
- buzón leído sin resolver;
- cierre del buzón únicamente después de una resolución válida;
- contador global sin cargar la lista completa.
