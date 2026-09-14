# Arquitectura del proyecto

## 1. Propósito

Este documento define la arquitectura y los criterios de organización del proyecto **Casa Pestalozzi**.

El sistema utiliza una arquitectura **MVC modular**, complementada con una capa de **Services** encargada de la lógica de negocio y la coordinación de los distintos casos de uso.

El objetivo de esta arquitectura es mantener una separación clara de responsabilidades, facilitar el mantenimiento y permitir que el sistema continúe creciendo sin concentrar funcionalidad no relacionada dentro de controladores, modelos o servicios demasiado grandes.

> Este documento define el estándar arquitectónico objetivo. Algunas partes existentes del proyecto pueden requerir migración progresiva para cumplir completamente con estas reglas.

---

# 2. Arquitectura general

El flujo principal de una petición es:

```text
HTTP Request
     │
     ▼
Controller
     │
     ▼
Service
     │
     ▼
Model / ActiveRecord
     │
     ▼
Database
```

Para respuestas HTML:

```text
Request
   ↓
Controller
   ↓
Service
   ↓
Model
   ↓
Controller
   ↓
View
   ↓
Response
```

Para APIs:

```text
Request
   ↓
Controller
   ↓
Service
   ↓
Model
   ↓
Serializer / Presenter
   ↓
JSON Response
```

Cada capa tiene una responsabilidad específica y debe evitar asumir responsabilidades pertenecientes a otra capa.

---

# 3. Organización modular

Además de la separación por capas MVC, el proyecto se organiza por **módulos funcionales**.

Ejemplos de módulos:

```text
Reservations
Pos
Tables
Scheduling
Menu
Inventory
Users
Contact
Notifications
Analytics
Security
```

Por lo tanto, las capas pueden dividirse internamente por módulo:

```text
controllers/
    Reservations/
    Pos/
    Inventory/

models/
    Reservations/
    Pos/
    Inventory/

services/
    Reservations/
    Pos/
    Inventory/
    Notifications/

views/
    reservaciones/
    punto-de-venta/
    admin/
```

No es necesario que todos los módulos existan en todas las capas.

Por ejemplo, `Notifications` puede existir únicamente dentro de `services/` si no requiere controladores, modelos o vistas propios.

---

# 4. Controllers

Los **Controllers** representan la capa de entrada de la aplicación.

Son responsables de recibir una petición HTTP, validar los datos correspondientes al protocolo HTTP, ejecutar el caso de uso requerido y construir la respuesta.

## Responsabilidades

Un Controller puede:

- Recibir parámetros `GET`, `POST` o JSON.
- Validar autenticación y autorización.
- Validar tokens CSRF.
- Normalizar parámetros de entrada.
- Invocar uno o más Services.
- Seleccionar una View.
- Construir respuestas HTTP o JSON.
- Definir códigos de estado HTTP y redirecciones.

Ejemplo conceptual:

```php
public static function cancelar(): void
{
    Auth::requireStaff();

    $request = self::parseRequest();

    $resultado = ReservacionService::cancelar(
        $request['reservacion_id']
    );

    self::json($resultado);
}
```

## Un Controller no debe

- Contener reglas de negocio.
- Gestionar transacciones de base de datos.
- Implementar algoritmos de disponibilidad.
- Decidir asignaciones de mesas.
- Contener consultas SQL complejas.
- Ejecutar directamente integraciones externas.
- Duplicar lógica utilizada por otros controladores.

La regla general es:

> **Controller = HTTP + coordinación del caso de uso.**

Cuando un Controller comienza a contener lógica que podría ser utilizada desde otro punto del sistema, dicha lógica debe trasladarse a un Service.

---

# 5. Services

Los **Services** contienen la lógica de negocio y los casos de uso de la aplicación.

Constituyen la capa principal entre los Controllers y los Models.

Ejemplos:

```text
crear una reservación
cancelar una reservación
determinar disponibilidad
asignar mesas
cerrar un ticket
procesar cambios de horario
enviar una notificación
```

## Responsabilidades

Un Service puede:

- Implementar reglas de negocio.
- Coordinar varios Models.
- Coordinar otros Services.
- Gestionar transacciones.
- Aplicar validaciones de dominio.
- Ejecutar operaciones atómicas.
- Implementar políticas del sistema.
- Coordinar integraciones externas mediante adaptadores.
- Implementar casos de uso reutilizables entre diferentes Controllers.

Ejemplo:

```text
Controller
    ↓
ReservacionPublicaService
    ├── DisponibilidadReservacionService
    ├── AsignacionMesasService
    ├── Reservacion
    └── ReservationConfirmationService
```

El Controller únicamente solicita la operación.

El Service decide **cómo debe realizarse**.

---

## 5.1 Organización de Services

Los Services deben organizarse primero por **módulo funcional**.

```text
services/
├── Reservations/
├── Pos/
├── Tables/
├── Scheduling/
├── Inventory/
├── Menu/
├── Users/
├── Contact/
├── Notifications/
├── Analytics/
├── Security/
└── Shared/
```

Cuando un módulo crezca lo suficiente puede utilizar subdominios:

```text
services/
└── Reservations/
    ├── Availability/
    ├── Access/
    ├── Notifications/
    ├── Presentation/
    └── Locks/
```

No deben crearse subcarpetas únicamente para contener uno o dos archivos sin una razón arquitectónica clara.

---

## 5.2 Responsabilidad de los Services

Cada Service debe tener una responsabilidad identificable.

Debe evitarse que una misma clase concentre simultáneamente:

```text
validación
persistencia
notificaciones
serialización
configuración
consultas
presentación
```

cuando estas responsabilidades pueden evolucionar independientemente.

El tamaño de una clase por sí solo **no determina** si debe dividirse.

Debe revisarse principalmente cuando:

- Tiene múltiples razones independientes para cambiar.
- Tiene demasiadas dependencias.
- Coordina casos de uso no relacionados.
- Mezcla integración, persistencia y presentación.
- Diferentes consumidores utilizan únicamente pequeñas partes de la clase.

---

## 5.3 Services compartidos

`Shared/` debe utilizarse únicamente para componentes realmente transversales.

No debe convertirse en una carpeta alternativa para código difícil de clasificar.

Antes de colocar algo en:

```text
services/Shared/
```

debe comprobarse que pertenece realmente a más de un dominio y que no tiene un módulo funcional natural.

Se deben evitar carpetas genéricas como:

```text
Helpers/
Utils/
Misc/
Managers/
Common/
```

salvo que exista una justificación arquitectónica específica.

---

# 6. Models

Los **Models** representan principalmente el estado persistente del sistema y el acceso a la base de datos.

El proyecto utiliza un patrón basado en `ActiveRecord`, por lo que cada modelo normalmente representa una entidad o tabla.

Ejemplos:

```text
Reservacion
Mesa
Ticket
TicketItem
Producto
Usuario
Proveedor
```

## Responsabilidades

Un Model puede:

- Definir atributos persistentes.
- Representar registros de base de datos.
- Crear, actualizar y consultar información.
- Implementar consultas relacionadas directamente con su entidad.
- Convertir datos persistentes a una representación utilizada por la aplicación.

Ejemplo conceptual:

```php
$reservacion = Reservacion::find($id);
$reservacion->estado = 'cancelada';
$reservacion->guardar();
```

## Un Model no debe

- Procesar peticiones HTTP.
- Renderizar Views.
- Enviar notificaciones.
- Conocer Controllers.
- Coordinar múltiples procesos del sistema.
- Contener reglas complejas de operación.
- Administrar casos de uso completos.

Una regla como:

> "Una reservación cancelada debe liberar sus mesas, actualizar ocupación y generar una notificación"

no pertenece únicamente al Model `Reservacion`.

Es un **caso de uso**, por lo que debe implementarse mediante un Service.

La regla general es:

> **Model = datos + persistencia.**

---

# 7. Views

Las **Views** son responsables exclusivamente de la presentación de información.

Actualmente pueden organizarse por superficie o módulo:

```text
views/
├── admin/
├── area/
├── auth/
├── components/
├── feedback/
├── home/
├── operation/
├── punto-de-venta/
├── reservaciones/
└── templates/
```

Esta organización debe mantenerse.

## Responsabilidades

Una View puede:

- Mostrar información.
- Ejecutar condicionales simples de presentación.
- Iterar colecciones.
- Utilizar componentes o partials.
- Aplicar escape y formato de salida.
- Construir formularios.

Ejemplo:

```php
<?php foreach ($reservaciones as $reservacion): ?>
    <tr>
        <td><?= htmlspecialchars($reservacion->nombre) ?></td>
    </tr>
<?php endforeach; ?>
```

## Una View no debe

- Consultar directamente la base de datos.
- Instanciar Services.
- Ejecutar reglas de negocio.
- Cambiar estados del sistema.
- Realizar operaciones transaccionales.

Cuando una View crezca demasiado, debe dividirse mediante:

```text
partials/
components/
sections/
```

La separación debe realizarse por componentes visuales coherentes, no simplemente por número de líneas.

La regla general es:

> **View = presentación.**

---

# 8. Dependencias entre capas

La dirección recomendada de dependencias es:

```text
Controller
    ↓
Service
    ↓
Model
```

y:

```text
Controller
    ↓
View
```

Los Services pueden depender de otros Services cuando sea necesario:

```text
Service
    ↓
Service
```

siempre que cada uno mantenga una responsabilidad claramente definida.

Debe evitarse:

```text
Model → Controller

Model → View

View → Model / Database

View → Service

Service → Controller

Service → View
```

La capa inferior no debe depender de la capa HTTP que la ejecuta.

---

# 9. Namespaces

Los namespaces deben reflejar la estructura física del proyecto.

Ejemplo:

```text
services/
└── Reservations/
    └── Availability/
        └── DisponibilidadReservacionService.php
```

debe utilizar:

```php
namespace Services\Reservations\Availability;
```

y consumirse mediante:

```php
use Services\Reservations\Availability\DisponibilidadReservacionService;
```

La misma regla debe aplicarse progresivamente a Controllers y Models cuando sean modularizados.

---

# 10. Convenciones de nombres

Los nombres deben expresar la responsabilidad de cada clase.

Preferir:

```text
ReservacionService
DisponibilidadReservacionService
ScheduleChangeNotificationService
PosReservacionSerializer
ReservacionMapaMesaPresenter
HorarioOperacionService
```

Evitar nombres ambiguos como:

```text
Helper
Manager
Utils
Processor
GeneralService
CommonService
```

cuando no indiquen claramente la responsabilidad del componente.

Los sufijos también deben representar la función real:

```text
Service      → lógica de negocio o caso de uso
Controller   → entrada HTTP
Model        → entidad persistente
Serializer   → transformación de datos
Presenter    → preparación para presentación
Provider     → implementación intercambiable
Factory      → construcción o selección de implementaciones
Config       → configuración
Lock         → coordinación/concurrencia
```

---

# 11. Regla para nuevas funcionalidades

Antes de crear una nueva clase debe determinarse:

```text
1. ¿A qué módulo pertenece?
2. ¿A qué capa pertenece?
3. ¿Ya existe un componente responsable de esta función?
4. ¿La nueva funcionalidad pertenece realmente a ese componente?
```

Ejemplo:

```text
Nueva función:
Enviar recordatorio de reservación por Email o WhatsApp

Módulo:
Reservations / Notifications

Caso de uso:
ReservationReminderService

Integración externa:
Integrations / N8nClient
```

De esta forma se evita agregar automáticamente nuevas clases a la raíz de `services/`.

### Notificaciones de reservaciones

La integración HTTP concreta vive en `services/Integrations/N8nClient.php` y
recibe URL/credencial del llamador, sin reglas de reservación. Los casos de uso
están en `services/Reservations/Notifications`; `NotificationConfig` centraliza
el entorno. No se introduce Provider/Factory/Dispatcher para esta única integración.
`ScheduleChangeNotificationService` coordina el dominio de impactos, que no lo
llama de vuelta. Todo HTTP ocurre post-commit, fuera de transacciones/locks.
Los tres workflows tienen transporte propio, sin subworkflow ni runtime compartido
entre ejecuciones. Ver [fuente funcional](reservaciones/notificaciones.md).

---

# 12. Principio de diseño

La arquitectura del proyecto debe priorizar:

**Cohesión alta:**
las funcionalidades relacionadas permanecen juntas.

**Acoplamiento bajo:**
los módulos conocen únicamente las dependencias necesarias.

**Responsabilidad clara:**
cada componente tiene una función identificable.

**Modularidad:**
el crecimiento ocurre dentro de dominios definidos.

**Migración incremental:**
los cambios arquitectónicos deben realizarse progresivamente y sin modificar comportamiento funcional innecesariamente.

---

# 13. Resumen

La responsabilidad principal de cada capa puede resumirse como:

| Capa           | Responsabilidad                           |
| -------------- | ----------------------------------------- |
| **Controller** | Recibir y responder solicitudes HTTP      |
| **Service**    | Ejecutar lógica de negocio y casos de uso |
| **Model**      | Representar y persistir datos             |
| **View**       | Presentar información                     |

En forma simplificada:

```text
Controller = entrada
Service    = comportamiento
Model      = datos
View       = presentación
```

La modularización agrega un segundo criterio:

```text
Capa
 ↓
Módulo
 ↓
Responsabilidad
```

Este será el estándar utilizado para la evolución y refactorización progresiva del proyecto Casa Pestalozzi.
