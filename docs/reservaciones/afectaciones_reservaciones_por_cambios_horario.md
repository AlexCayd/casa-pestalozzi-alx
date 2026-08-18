# Afectaciones de reservaciones por cambios de horario

**Proyecto:** Casa Pestalozzi  
**Carácter:** Normativo  
**Ámbito:** Horarios de operación, reservaciones afectadas, seguimiento administrativo y notificaciones al comensal.

## 1. Propósito

Este documento define cómo debe comportarse el sistema cuando un cambio en los horarios de operación deja una o más reservaciones activas fuera del nuevo horario vigente.

El objetivo es preservar las reservaciones existentes, evitar cancelaciones automáticas, obligar a que los casos afectados reciban seguimiento y dejar preparada la integración de notificaciones mediante n8n.

Este documento describe reglas de comportamiento. No es un historial de cambios ni una guía de implementación por archivos.

---

## 2. Principios generales

1. Un cambio de horario **no cancela, reprograma ni modifica automáticamente** una reservación existente.
2. Las reservaciones afectadas conservan su estado vigente hasta que el comensal o un administrador realice una acción explícita.
3. La modificación del horario puede completarse aunque existan reservaciones afectadas, siempre que el administrador confirme el impacto.
4. Toda reservación afectada debe generar un **seguimiento persistente**.
5. El seguimiento no puede depender de la pestaña, navegador o sesión desde la que se realizó el cambio.
6. Una afectación de horario es un hecho operativo adicional y **no constituye un nuevo estado de reservación**.
7. Las notificaciones al comensal se gestionan de forma desacoplada del guardado del horario.
8. La indisponibilidad de n8n, WhatsApp, SMS o correo **no debe impedir guardar el cambio de horario** ni perder la afectación.
9. Las nuevas reservaciones deben respetar inmediatamente el nuevo horario vigente.
10. Las reservaciones existentes siguen participando en mapa, capacidad, asignación y operación aunque su hora haya dejado de estar disponible para nuevas altas.

---

## 3. Cambios de horario que deben evaluar impacto

El mismo contrato de impacto debe aplicarse cuando se:

- modifica el horario semanal;
- crea una excepción;
- edita una excepción;
- activa una excepción;
- desactiva una excepción;
- elimina una excepción.

La evaluación debe comparar el horario efectivo anterior contra el horario efectivo resultante.

Una reservación se considera afectada cuando, después del cambio, su fecha y hora quedan fuera del horario efectivo permitido y antes del cambio eran válidas bajo la configuración anterior.

---

## 4. Reservaciones incluidas

Deben evaluarse las reservaciones futuras activas que todavía representen un compromiso operativo.

Como mínimo:

- `confirmada`;
- retenciones/holds vigentes cuando formen parte del flujo público;
- cualquier otro estado activo que el contrato vigente de reservaciones considere pendiente de atención.

No deben generar seguimiento nuevo:

- `cancelada`;
- `completada`;
- `no_show`;
- reservaciones históricas que ya no puedan ser atendidas.

Una reservación ya afectada por el mismo cambio no debe duplicar su seguimiento.

---

## 5. Flujo previo al guardado

Cuando el administrador intenta aplicar un cambio con reservaciones afectadas:

```text
Modificar horario
      ↓
Calcular impacto
      ↓
¿Hay reservaciones afectadas?
      ├── No → guardar normalmente
      └── Sí
            ↓
      Mostrar confirmación
            ↓
      Administrador acepta
            ↓
      Guardar horario + registrar afectaciones
```

La confirmación debe indicar al menos:

- número total de reservaciones afectadas;
- que ninguna será cancelada automáticamente;
- que después del cambio será obligatorio gestionar su seguimiento.

No es necesario resolver una por una antes de guardar el horario.

---

## 6. Persistencia de la afectación

Al confirmar el cambio se debe registrar de forma persistente un lote o conjunto de afectaciones.

El sistema debe poder conocer posteriormente:

- qué cambio de horario originó el seguimiento;
- qué reservaciones fueron afectadas;
- cuáles tienen contacto;
- cuáles no tienen contacto;
- cuáles ya tienen notificación encolada;
- cuáles fueron atendidas manualmente;
- cuáles fueron modificadas por el cliente;
- cuáles siguen pendientes.

No se debe duplicar innecesariamente nombre, teléfono o correo dentro de la afectación. La PII se obtiene de la reservación al momento de necesitarla.

---

## 7. Fases obligatorias de resolución administrativa

Después de guardar el cambio debe abrirse un **modal de resolución obligatorio**.

La gestión se divide en dos fases.

### Fase 1 — Reservaciones con contacto

Todas las reservaciones afectadas que ya tengan un medio de contacto válido deben ser gestionadas primero.

Para cada una se debe mostrar:

- nombre;
- fecha;
- hora;
- comensales;
- tipo de contacto;
- contacto enmascarado;
- estado de la notificación.

Debe existir:

- acción individual `Enviar aviso`;
- acción global `Enviar avisos disponibles`.

Mientras exista una reservación con contacto cuya notificación no haya sido registrada de forma durable:

- no se habilita la atención manual de los casos sin contacto;
- no se permite finalizar el seguimiento.

La obligación administrativa se considera cumplida cuando la notificación queda **encolada/persistida**, no cuando el proveedor externo confirma entrega.

---

## 8. Fase 2 — Reservaciones sin contacto

Una vez que todas las reservaciones con contacto tengan su notificación encolada, se habilita la gestión de reservaciones sin contacto.

Cada reservación sin contacto debe ofrecer únicamente:

- `Agregar contacto`;
- `Atendida manualmente`.

### Agregar contacto

La edición debe realizarse dentro del mismo flujo modal, sin sacar al administrador de la gestión pendiente.

Después de agregar un contacto:

1. la reservación pasa a ser notificable;
2. debe aparecer la acción `Enviar aviso`;
3. el caso no se considera resuelto hasta que la notificación quede encolada.

Agregar contacto por sí solo no resuelve la afectación.

### Atención manual

`Atendida manualmente` debe requerir confirmación explícita.

Esta acción significa únicamente que el personal revisó el caso por un canal no registrado en el sistema.

No debe:

- cancelar la reservación;
- cambiar su fecha u hora;
- marcarla como notificada digitalmente;
- modificar su estado canónico.

Debe registrar quién resolvió el seguimiento y cuándo.

---

## 9. El modal no debe poder descartarse mientras haya pendientes

Mientras existan afectaciones sin resolver, el modal no debe ofrecer una salida normal que abandone la gestión.

Durante este estado:

- no mostrar botón `X`;
- no cerrar con backdrop;
- no cerrar con `Escape`;
- no mostrar `Resolver después`;
- no mostrar `Finalizar`.

La acción `Finalizar` aparece únicamente cuando todas las afectaciones del lote se encuentran en alguno de estos resultados:

- notificación encolada;
- atención manual confirmada;
- reservación modificada por el cliente y ya válida bajo el horario vigente.

El sistema no debe confiar en el modal para garantizar persistencia.

---

## 10. Cierre del navegador, recarga o pérdida de sesión

Cerrar el navegador no debe hacer desaparecer el seguimiento.

Las afectaciones pendientes deben permanecer en base de datos.

Cuando exista al menos una afectación pendiente:

1. el área administrativa debe mostrar una advertencia global persistente;
2. debe existir un acceso visible para `Resolver`;
3. al entrar nuevamente un administrador, el sistema puede abrir automáticamente el flujo pendiente;
4. cualquier administrador autorizado puede continuar la resolución.

El seguimiento pertenece a la operación del restaurante, no a la sesión del administrador que originó el cambio.

---

## 11. Aviso global para administración

Mientras existan afectaciones pendientes, el shell administrativo debe mostrar una alerta equivalente a:

```text
Hay reservaciones pendientes por un cambio de horario.  [Resolver]
```

Puede incluir un contador.

La alerta sólo desaparece cuando no existen afectaciones pendientes.

No debe depender de `sessionStorage`, `localStorage` ni de variables JavaScript temporales.

---

## 12. Mensaje al comensal

Todas las notificaciones por cambio de horario pueden utilizar la misma plantilla funcional.

Contenido esperado:

```text
Actualización de tu reservación

Realizamos un ajuste en nuestros horarios de operación que afecta tu
reservación del {fecha} a las {hora}.

Tu reservación no ha sido cancelada. Te pedimos modificarla para elegir
una nueva fecha u horario disponible.

[ Modificar mi reservación ]
```

El texto final puede adaptarse al canal, pero debe conservar estas ideas:

- existe un cambio de horario;
- la reservación no fue cancelada automáticamente;
- el cliente debe revisarla/modificarla;
- existe una acción directa hacia la landing.

---

## 13. Acceso automático desde la notificación

El botón `Modificar mi reservación` debe poder iniciar una sesión pública sin solicitar nuevamente OTP cuando el enlace sea válido.

Este acceso se implementa mediante un **magic link de un solo uso**.

No debe utilizar:

- ID de reservación como credencial;
- teléfono o correo en texto plano como autenticación;
- parámetros que otorguen acceso permanente;
- sesión PHP serializada en la URL.

---

## 14. Magic link

Cada enlace debe utilizar un token criptográficamente aleatorio.

En base de datos sólo se almacena su hash.

El registro asociado debe permitir validar, como mínimo:

- reservación;
- afectación de horario;
- propósito del enlace;
- expiración;
- consumo;
- fecha de creación.

El token:

- es de un solo uso;
- tiene expiración configurable;
- sólo sirve para el propósito de modificación por afectación de horario;
- no otorga privilegios administrativos;
- no permite acceder a reservaciones de otro contacto.

---

## 15. Consumo seguro del magic link

El `GET` inicial no debe consumir definitivamente el token.

Motivo: proveedores de correo, mensajería y sistemas antispam pueden abrir enlaces automáticamente.

Flujo esperado:

```text
GET magic link
      ↓
validar sin consumir
      ↓
confirmación técnica / POST seguro
      ↓
consumir token
      ↓
crear ReservationClientSession
      ↓
303 redirect
      ↓
landing sin token en URL
```

Una vez creada la sesión:

- se regenera el ID de sesión;
- se utiliza el namespace público existente;
- el token queda invalidado;
- la URL final no conserva el token.

---

## 16. Sesión pública creada por magic link

La sesión creada mediante magic link debe utilizar el mismo contrato que una sesión pública creada por verificación OTP.

Por tanto, debe permitir utilizar los flujos existentes de:

- `Mis reservaciones`;
- consulta de disponibilidad;
- modificación pública;
- confirmación de modificación;
- cancelación, cuando las reglas vigentes lo permitan.

El magic link sustituye únicamente la verificación inicial de posesión del contacto.

No crea un segundo sistema de reservaciones públicas.

---

## 17. Enlace inválido o expirado

Si el enlace:

- expiró;
- ya fue usado;
- fue invalidado;
- no corresponde a una afectación activa;

no se debe crear sesión.

El usuario debe ser enviado al flujo público normal y se le puede indicar que el enlace ya no es válido.

Desde ahí puede utilizar la verificación OTP vigente.

---

## 18. Duración del magic link

La vigencia debe ser configurable.

Valor inicial recomendado:

```text
72 horas
```

La vigencia del enlace no debe extender el plazo durante el cual la reservación puede modificarse públicamente.

Si las reglas del módulo ya no permiten modificar la reservación, un magic link válido no debe saltarse esa restricción.

---

## 19. Resolución automática por modificación del cliente

Cuando el cliente modifica correctamente una reservación afectada:

```text
notificación
      ↓
magic link
      ↓
sesión pública
      ↓
modificar reservación
      ↓
nuevo horario válido
```

la afectación debe cerrarse automáticamente como:

```text
resuelta_por_cliente
```

No se requiere acción adicional del administrador.

Si la modificación todavía deja la reservación fuera del horario efectivo, la afectación no debe cerrarse.

---

## 20. Estados mínimos de seguimiento

La implementación puede usar nombres internos distintos, pero debe representar como mínimo los siguientes hechos:

### Por reservación afectada

- pendiente de notificación;
- notificación encolada;
- sin contacto;
- atendida manualmente;
- resuelta por el cliente.

### Por lote de impacto

- pendiente;
- resuelto.

Estos estados pertenecen al seguimiento de horario y no sustituyen `reservaciones.estado`.

---

## 21. Notificaciones y n8n

El módulo de horarios no debe llamar directamente a n8n.

El flujo debe ser:

```text
HorarioOperacion
      ↓
Afectación persistente
      ↓
Solicitud de notificación
      ↓
Outbox / dispatcher
      ↓
Provider
      ↓
n8n
      ↓
WhatsApp / SMS / correo
```

La aplicación es responsable de decidir:

- cuándo debe notificarse;
- qué reservación;
- qué evento ocurrió;
- si existe contacto;
- idempotencia;
- persistencia.

n8n es responsable de la entrega y orquestación externa.

---

## 22. Contrato de notificación

Las notificaciones operativas por cambios de horario deben utilizar un contrato separado del proveedor OTP.

El sistema debe poder emitir un evento equivalente a:

```text
reservation.schedule_change
```

El payload puede incluir al momento del despacho:

- identificador del evento;
- identificador de reservación;
- nombre;
- canal;
- contacto;
- fecha;
- hora;
- comensales;
- URL de acción;
- tipo de plantilla.

La PII no debe persistirse innecesariamente dentro del outbox si puede resolverse desde la reservación al momento del envío.

---

## 23. Idempotencia

La misma afectación no debe producir avisos duplicados por:

- doble click;
- reenvío del formulario;
- retry HTTP;
- recarga;
- reintento de worker;
- repetición del webhook.

Cada notificación debe tener una clave de deduplicación estable.

Un retry de entrega debe reutilizar la misma intención de notificación y no crear otra afectación.

---

## 24. Fallos de notificación

Si n8n o el proveedor falla:

- la reservación no cambia;
- la afectación no se pierde;
- la notificación queda pendiente o fallida;
- debe poder reintentarse;
- el administrador debe poder conocer que sigue pendiente.

No se debe revertir el cambio de horario por un fallo posterior del canal externo.

---

## 25. Privacidad

El seguimiento debe respetar las reglas vigentes de privacidad.

En particular:

- no duplicar contacto sin necesidad;
- mostrar contacto enmascarado en listados;
- sólo administración autorizada puede ver/editar contacto;
- no enviar PII innecesaria a n8n;
- no registrar magic links en logs;
- no guardar tokens en texto plano;
- no incluir tokens en analytics;
- no conservar el token en la URL final;
- no exponer datos del cliente a personal sin permiso.

---

## 26. Reservaciones sin contacto

Una reservación sin contacto nunca debe:

- bloquear indefinidamente el guardado del horario;
- cancelarse automáticamente;
- inventar un canal de comunicación;
- considerarse notificada.

Debe permanecer como seguimiento operativo hasta que:

1. se agregue contacto y se encole el aviso; o
2. un administrador confirme atención manual; o
3. la reservación sea modificada por otro flujo válido y deje de estar afectada.

---

## 27. Reglas de interfaz administrativa

El flujo debe conservar una jerarquía clara:

### Antes de guardar

- mostrar impacto;
- permitir cancelar;
- permitir confirmar el cambio.

### Después de guardar

- abrir modal de resolución;
- mostrar primero las reservaciones con contacto;
- permitir envío individual y masivo;
- bloquear fase manual hasta resolver las notificables;
- habilitar después los casos sin contacto;
- mantener el modal abierto mientras existan pendientes.

El cambio de horario ya aplicado no debe depender de que el modal permanezca abierto.

---

## 28. Integridad frente a concurrencia

Las operaciones de horario y creación de afectaciones deben garantizar que:

- el impacto corresponde al cambio realmente guardado;
- no se creen lotes duplicados;
- no se pierdan reservaciones afectadas por dos administradores trabajando al mismo tiempo;
- una reservación resuelta no vuelva a quedar pendiente por un retry del mismo evento.

Cuando sea necesario, utilizar transacciones y locks en las capas de dominio correspondientes.

---

## 29. Reglas de no regresión

Esta implementación no debe cambiar:

- estados canónicos de reservación;
- cálculo de capacidad;
- reglas de asignación de mesas;
- proyección de tickets;
- política pública de modificación;
- OTP normal de la landing;
- permisos de roles;
- configuración efectiva de horarios.

El magic link sólo agrega una vía segura de autenticación para un caso ya notificado.

---

## 30. Regla normativa resumida

> Cuando un cambio de horario afecta reservaciones existentes, el sistema conserva las reservaciones, registra persistentemente cada afectación y obliga a administración a gestionar el seguimiento. Las reservaciones con contacto deben tener una notificación encolada antes de habilitar la resolución manual de las que no tienen contacto. El mensaje permite acceder mediante un enlace seguro de un solo uso a la sesión pública existente para modificar la reservación sin repetir OTP. Si el navegador se cierra o la sesión administrativa termina, las afectaciones permanecen pendientes y deben volver a mostrarse mediante una alerta global hasta que todas queden resueltas.

---

## 31. Criterios de aceptación

La implementación se considera completa cuando:

- [ ] Cambiar horarios nunca cancela reservaciones automáticamente.
- [ ] Todos los tipos de modificación de horario evalúan impacto.
- [ ] El administrador conoce el número de reservaciones afectadas antes de confirmar.
- [ ] Al confirmar se persiste el lote de afectaciones.
- [ ] El modal de seguimiento se abre después de guardar.
- [ ] Las reservaciones con contacto deben notificarse primero.
- [ ] Existe envío individual y envío masivo.
- [ ] Una notificación se considera gestionada al quedar encolada durablemente.
- [ ] Los casos sin contacto se habilitan después de resolver los notificables.
- [ ] Agregar contacto ocurre dentro del modal.
- [ ] Agregar contacto obliga después a enviar el aviso.
- [ ] Atención manual requiere confirmación.
- [ ] El modal no tiene salida normal mientras haya pendientes.
- [ ] Cerrar navegador no elimina el seguimiento.
- [ ] Existe alerta global administrativa para pendientes.
- [ ] Otro administrador puede continuar el seguimiento.
- [ ] El mensaje usa una plantilla común.
- [ ] El botón del mensaje usa magic link de un solo uso.
- [ ] El magic link no se consume con un GET de scanner.
- [ ] El token se almacena únicamente como hash.
- [ ] El magic link crea la `ReservationClientSession` existente.
- [ ] El cliente no necesita OTP si el magic link es válido.
- [ ] Un link expirado vuelve al flujo OTP normal.
- [ ] La modificación correcta del cliente resuelve automáticamente la afectación.
- [ ] n8n queda desacoplado del guardado de horarios.
- [ ] Los envíos son idempotentes.
- [ ] Un fallo del proveedor no revierte el horario ni pierde la afectación.
- [ ] No se duplican innecesariamente datos personales.

## 32. Configuración operativa

La migración `database/migrations/2026_08_18_reservaciones_afectaciones_horario.sql` agrega las tablas de impactos, sus reservaciones hijas, el outbox de notificaciones y los magic links. Debe ejecutarse después del esquema base.

La vigencia del enlace se configura con `SCHEDULE_CHANGE_LINK_TTL_HOURS` y tiene un valor predeterminado de 72 horas. En producción `RESERVATION_PUBLIC_BASE_URL` debe apuntar al origen público canónico de la landing; desarrollo y testing usan `http://localhost` sólo como fallback local.

El guardado de horarios no llama a n8n ni a un proveedor externo. Primero escribe la intención durable en `reservacion_notificaciones`; un dispatcher/proveedor operativo puede consumirla posteriormente. En `development` y `testing` no se realizan envíos externos: la interfaz administrativa ofrece copiar temporalmente el enlace de prueba en memoria.
