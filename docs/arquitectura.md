# Arquitectura del proyecto

## 1. Propósito

Este documento define la arquitectura y los criterios de organización del proyecto **Casa Pestalozzi**.

El sistema utiliza una arquitectura **MVC modular**, complementada con una capa de **Services** encargada de la lógica de negocio y la coordinación de los distintos casos de uso.

El objetivo es mantener responsabilidades claras, reducir acoplamiento y permitir que el sistema continúe creciendo sin concentrar funcionalidad no relacionada dentro de Controllers, Models o Services demasiado grandes.

> Este documento define el estándar arquitectónico objetivo. La migración hacia este estándar es progresiva y no debe modificar comportamiento funcional salvo cuando exista una corrección explícitamente autorizada.

---

# 2. Arquitectura general

El flujo principal recomendado es:

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

Módulos principales:

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
Configuration
Security
Integrations
```

No es necesario que todos los módulos existan en todas las capas.

La modularización principal de `services/` será:

```text
services/
├── Analytics/
├── Configuration/
├── Contact/
├── Integrations/
├── Inventory/
├── Menu/
├── Notifications/
├── Pos/
├── Reservations/
├── Scheduling/
├── Security/
├── Shared/
├── Tables/
└── Users/
```

`Shared/` sólo debe utilizarse para componentes realmente transversales y no como carpeta por defecto para código difícil de clasificar.

---

# 4. Controllers

Los **Controllers** representan la capa de entrada HTTP de la aplicación.

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

## Un Controller no debe

- Contener reglas de negocio complejas.
- Gestionar transacciones de base de datos.
- Implementar algoritmos de disponibilidad.
- Decidir asignaciones de mesas.
- Contener consultas SQL complejas.
- Ejecutar directamente integraciones externas.
- Duplicar lógica utilizada por otros controladores.

Regla general:

> **Controller = HTTP + coordinación del caso de uso.**

---

# 5. Services

Los **Services** contienen la lógica de negocio y los casos de uso de la aplicación.

Pueden:

- Implementar reglas de negocio.
- Coordinar Models.
- Coordinar otros Services.
- Gestionar transacciones.
- Aplicar validaciones de dominio.
- Ejecutar operaciones atómicas.
- Implementar políticas.
- Coordinar integraciones externas mediante adaptadores.
- Reutilizar casos de uso entre diferentes Controllers.

Regla general:

> **Service = comportamiento y coordinación de dominio.**

---

## 5.1 Organización por dominio

Los módulos de Services utilizan un solo nivel de carpetas. La carpeta identifica el dominio y el nombre de la clase identifica su responsabilidad.

Estructura:

```text
services/
└── Reservations/
    └── HorarioReservacionService.php
```

No utilizar subcarpetas internas como `Access`, `Availability`, `Config`, `Locks`, `Notifications`, `Presentation` o `ScheduleChanges` como organización habitual.

Una segunda profundidad sólo podrá introducirse en el futuro si el crecimiento real de un dominio la justifica claramente. No debe anticiparse.

---

## 5.2 Frontera entre Scheduling y Reservations

`Scheduling` representa exclusivamente el **horario operativo del restaurante**.

Incluye conceptos como:

```text
horario semanal
días abiertos o cerrados
hora de apertura
hora de cierre
excepciones operativas
horario efectivo para una fecha
locks de configuración de horario
```

Estructura esperada:

```text
services/
└── Scheduling/
    ├── HorarioOperacionService.php
    └── HorarioConfigLock.php
```

`Scheduling` **no representa ejecución programada de tareas, cron jobs ni recordatorios automáticos**.

La ejecución periódica de recordatorios mediante n8n es infraestructura de notificaciones y no pertenece al dominio `Scheduling`.

Las reglas que convierten un horario operativo en una **ventana reservable** pertenecen a Reservations:

```text
services/
└── Reservations/
    └── HorarioReservacionService.php
```

Esto incluye:

- horizonte de reservación;
- anticipación mínima;
- generación de intervalos reservables;
- última reservación antes del cierre;
- validación temporal específica de una reservación.

Los impactos generados cuando un cambio de horario afecta reservaciones existentes también pertenecen a Reservations:

```text
services/
└── Reservations/
    └── HorarioOperacionImpactoService.php
```

La relación conceptual es:

```text
Scheduling
    │
    │ horario efectivo
    ▼
Reservations
```

`Scheduling` debe conocer el horario operativo. Las reglas y consecuencias específicas de reservaciones deben mantenerse dentro de `Reservations`.

---

## 5.3 Notifications e Integrations

Debe distinguirse entre:

### Notificaciones generales

```text
services/
└── Notifications/
    ├── NotificationConfig.php
    └── BuzonNotificacionesService.php
```

Contienen configuración transversal de transporte.

### Notificaciones de reservaciones

```text
services/
└── Reservations/
    ├── ReservationConfirmationService.php
    ├── ReservationReminderService.php
    ├── ScheduleChangeNotificationService.php
    ├── ReservacionBuzonService.php
    ├── ReservacionNotificacionConfigService.php
    ├── ReservationNotificationContract.php
    ├── ReservationNotificationResultService.php
    └── ConfirmationResendPolicy.php
```

Contienen los casos de uso y contratos específicos de reservaciones.

Actualmente esta frontera incluye, entre otros:

```text
ReservationConfirmationService
ReservationReminderService
ScheduleChangeNotificationService
ReservationNotificationContract
ReservationNotificationResultService
ConfirmationResendPolicy
```

### Integraciones externas

```text
services/
└── Integrations/
    └── N8nClient.php
```

`N8nClient` es un adaptador HTTP. No debe contener reglas de reservaciones, horarios, capacidad, mesas, elegibilidad ni estados de dominio.

No debe reintroducirse una jerarquía Provider/Factory/Dispatcher para la integración actual con n8n mientras exista un único adaptador y no haya una necesidad arquitectónica real.

Todo transporte externo relacionado con reservaciones debe ejecutarse **después del commit y fuera de locks o transacciones**.

---

## 5.4 Users

El dominio de usuarios se organiza en:

```text
services/
└── Users/
    ├── UsuarioService.php
    ├── NipService.php
    └── UsuarioConfig.php
```

Responsabilidades:

- gestión de cuentas internas;
- reglas de roles;
- credenciales;
- generación y validación de NIP;
- cambios de contraseña;
- activación y desactivación.

La migración física no debe aprovecharse para cambiar simultáneamente reglas de autenticación o credenciales.

Dependencias inversas existentes, como un Model que utilice directamente un Service, deben registrarse como deuda arquitectónica y corregirse en una etapa posterior separada.

---

## 5.5 Responsabilidad de los Services

Cada Service debe tener una responsabilidad identificable.

Debe revisarse cuando concentra simultáneamente responsabilidades que pueden evolucionar de forma independiente, por ejemplo:

```text
validación
persistencia
notificaciones
serialización
configuración
consultas
presentación
```

El tamaño de una clase por sí solo **no determina** si debe dividirse.

Debe revisarse principalmente cuando:

- Tiene múltiples razones independientes para cambiar.
- Tiene demasiadas dependencias.
- Coordina casos de uso no relacionados.
- Mezcla integración, persistencia y presentación.
- Diferentes consumidores utilizan únicamente pequeñas partes de la clase.

---

# 6. Models

Los **Models** representan principalmente estado persistente y acceso a base de datos.

El proyecto utiliza ActiveRecord.

## Un Model puede

- Definir atributos persistentes.
- Representar registros.
- Crear, actualizar y consultar información.
- Implementar consultas directamente relacionadas con su entidad.
- Convertir datos persistentes a una representación de aplicación.

## Un Model no debe

- Procesar peticiones HTTP.
- Renderizar Views.
- Enviar notificaciones.
- Conocer Controllers.
- Coordinar múltiples procesos del sistema.
- Administrar casos de uso completos.
- Depender de Services salvo durante una migración temporal explícitamente documentada.

Regla general:

> **Model = datos + persistencia.**

---

# 7. Views

Las Views son responsables exclusivamente de presentación.

Una View puede:

- Mostrar información.
- Ejecutar condicionales simples.
- Iterar colecciones.
- Utilizar componentes o partials.
- Aplicar escape y formato.
- Construir formularios.

Una View no debe:

- Consultar directamente la base de datos.
- Invocar Services.
- Ejecutar reglas de negocio.
- Cambiar estados.
- Realizar operaciones transaccionales.

La información necesaria debe prepararse antes de renderizar la View.

Regla general:

> **View = presentación.**

---

# 8. Dependencias entre capas

Dirección recomendada:

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

Los Services pueden depender de otros Services cuando la dependencia tenga una frontera de dominio clara.

Debe evitarse:

```text
Model → Controller
Model → View
Model → Service
View → Model / Database
View → Service
Service → Controller
Service → View
```

Una dependencia existente que incumpla esta dirección no debe corregirse incidentalmente durante un simple movimiento de archivos. Debe registrarse y corregirse en un refactor separado.

---

# 9. Namespaces

Los namespaces deben reflejar la estructura física.

Ejemplo:

```text
services/
└── Reservations/
    └── DisponibilidadReservacionService.php
```

debe utilizar:

```php
namespace Services\Reservations;
```

y consumirse mediante:

```php
use Services\Reservations\DisponibilidadReservacionService;
```

Composer mantiene:

```json
"Services\\": "./services"
```

por lo que la carpeta del dominio representa directamente el namespace del módulo.

---

# 10. Convenciones de nombres

Preferir nombres que expresen responsabilidad:

```text
ReservacionService
DisponibilidadReservacionService
ScheduleChangeNotificationService
PosReservacionSerializer
ReservacionMapaMesaPresenter
HorarioOperacionService
```

Evitar nombres ambiguos:

```text
Helper
Manager
Utils
Processor
GeneralService
CommonService
```

Sufijos:

```text
Service      → lógica de negocio o caso de uso
Controller   → entrada HTTP
Model        → entidad persistente
Serializer   → transformación de datos
Presenter    → preparación para presentación
Config       → configuración
Lock         → coordinación/concurrencia
Client       → adaptador a servicio externo
```

---

# 11. Regla para nuevas funcionalidades

Antes de crear una nueva clase debe determinarse:

```text
1. ¿A qué módulo pertenece?
2. ¿A qué capa pertenece?
3. ¿Ya existe un componente responsable?
4. ¿La nueva función pertenece realmente a ese componente?
```

Ejemplo:

```text
Función:
Enviar recordatorio de reservación por Email o WhatsApp

Dominio:
Reservations (comunicaciones de reservaciones)

Caso de uso:
ReservationReminderService

Integración externa:
Integrations / N8nClient
```

No deben crearse nuevos Services directamente en la raíz de `services/` salvo una excepción arquitectónica explícitamente documentada.

---

# 12. Migración y correcciones fuera de alcance

La migración arquitectónica se ejecuta bajo la regla:

```text
Mover
↓
Actualizar namespace
↓
Actualizar imports/referencias
↓
Validar
```

No:

```text
Mover + renombrar + dividir + reescribir comportamiento
```

Si durante la migración se detecta una corrección necesaria pero **fuera del alcance de la fase actual**, debe registrarse únicamente cuando exista un problema real y verificable en:

```text
docs/correcciones_pendientes_arquitectura.md
```

El archivo no es un backlog general ni una lista de ideas.

Un hallazgo sólo debe registrarse cuando incluya:

- error o incumplimiento concreto;
- evidencia;
- archivos afectados;
- impacto;
- motivo por el que no se corrige en la fase actual.

No deben registrarse:

- preferencias de estilo;
- refactors hipotéticos;
- ideas sin evidencia;
- tareas ya cubiertas por el plan de migración;
- observaciones sin impacto real.

Las correcciones registradas se resolverán en cambios separados para no contaminar los commits de migración física.

---

# 13. Principios de diseño

La arquitectura prioriza:

**Cohesión alta:** funcionalidades relacionadas permanecen juntas.

**Acoplamiento bajo:** los módulos conocen únicamente dependencias necesarias.

**Responsabilidad clara:** cada componente tiene una función identificable.

**Modularidad:** el crecimiento ocurre dentro de dominios definidos.

**Migración incremental:** los cambios arquitectónicos se realizan progresivamente.

**Cambios verificables:** reorganización y corrección funcional no deben mezclarse sin necesidad.

---

# 14. Resumen

| Capa | Responsabilidad |
|---|---|
| Controller | Recibir y responder solicitudes HTTP |
| Service | Ejecutar lógica de negocio y casos de uso |
| Model | Representar y persistir datos |
| View | Presentar información |

```text
Controller = entrada
Service    = comportamiento
Model      = datos
View       = presentación
```

La modularización agrega:

```text
Capa
 ↓
Módulo
 ↓
Responsabilidad
```

Este documento constituye el estándar arquitectónico para la evolución progresiva de Casa Pestalozzi.
