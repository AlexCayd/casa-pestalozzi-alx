# Plan de migración de Services

> Histórico: conserva el plan original, no describe el estado desplegado ni
> autoriza reintroducir clases antiguas. Para notificaciones prevalecen
> [arquitectura](arquitectura.md), [contrato actual](reservaciones/notificaciones.md)
> y [operación n8n](../n8n/README.md). La integración vigente es
> Integrations/N8nClient, sin Provider/Factory/Dispatcher; tres workflows aislados.

## 1. Propósito

Este documento define el plan de migración de la capa `services/` hacia la arquitectura modular establecida para el proyecto **Casa Pestalozzi**.

La migración tiene como objetivo mejorar la organización, mantenibilidad y escalabilidad del código sin modificar innecesariamente el comportamiento actual del sistema.

El principio general será:

> **Primero reorganizar responsabilidades y namespaces; después refactorizar la lógica interna de las clases que lo requieran.**

La migración debe realizarse de forma incremental y verificable.

---

# 2. Estado actual

Actualmente la carpeta:

```text
services/
```

contiene una cantidad creciente de clases relacionadas con diferentes dominios del sistema.

Entre ellas existen servicios de:

- Reservaciones.
- Punto de venta.
- Mesas.
- Horarios.
- Inventario.
- Menú.
- Usuarios.
- Contacto.
- Notificaciones.
- Integraciones con n8n.
- Seguridad.
- Analíticas.
- Configuración.
- Locks y concurrencia.
- Serialización y presentación.

El principal problema no es únicamente la cantidad de archivos, sino que todas estas responsabilidades conviven en una misma carpeta y namespace.

Esto dificulta:

- Localizar funcionalidades.
- Identificar el dominio al que pertenece una clase.
- Definir dónde debe agregarse nueva funcionalidad.
- Entender dependencias entre componentes.
- Detectar servicios con demasiadas responsabilidades.

---

# 3. Arquitectura objetivo

La estructura objetivo será:

```text
services/
├── Analytics/
├── Contact/
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

Algunos módulos podrán contener subcarpetas cuando exista suficiente complejidad.

El principal ejemplo será `Reservations`:

```text
services/
└── Reservations/
    ├── Access/
    ├── Availability/
    ├── Config/
    ├── Locks/
    ├── Notifications/
    └── Presentation/
```

El objetivo final es que la raíz de `services/` contenga **cero o la menor cantidad posible de archivos PHP**.

---

# 4. Reglas de migración

Durante la migración se deberán cumplir las siguientes reglas.

## 4.1 No modificar comportamiento durante un movimiento

Mover una clase debe implicar únicamente:

```text
archivo
namespace
imports
referencias
```

No se deberá aprovechar el mismo cambio para modificar su comportamiento interno, salvo que sea estrictamente necesario para mantener compatibilidad.

Esto permite identificar con mayor facilidad cualquier regresión.

---

## 4.2 Un módulo por responsabilidad funcional

Cada Service debe ubicarse según el dominio al que pertenece.

Ejemplo:

```text
DisponibilidadReservacionService
```

pertenece a:

```text
Reservations/Availability/
```

y no a una carpeta genérica como:

```text
Shared/
Utils/
Helpers/
```

---

## 4.3 No crear nuevos Services en la raíz

A partir del inicio de la migración:

```text
services/NuevoService.php
```

deberá evitarse.

Toda clase nueva debe ubicarse directamente en su módulo correspondiente.

---

## 4.4 Mantener namespaces alineados con carpetas

Ejemplo:

```text
services/Notifications/N8n/N8nNotificationClient.php
```

debe utilizar:

```php
namespace Services\Notifications\N8n;
```

Composer ya utiliza la raíz PSR-4:

```json
"Services\\": "./services"
```

por lo que las subcarpetas pueden mapearse directamente a subnamespaces.

---

# 5. Mapeo de Services

## Reservations

### Services principales

```text
ReservacionService.php
ReservacionPublicaService.php
ReservacionAdministrativaService.php
ReservacionMantenimientoService.php
```

Destino:

```text
services/Reservations/
```

---

### Availability

```text
AsignacionMesasService.php
CapacidadReservacionesService.php
DisponibilidadReservacionService.php
ReservacionVigenciaService.php
ReservacionAsignacionVersionService.php
```

Destino:

```text
services/Reservations/Availability/
```

---

### Access

```text
ReservationAccessTokenService.php
ReservationClientSession.php
ReservationManagementAccessService.php
ReservationManagementAccessSession.php
```

Destino:

```text
services/Reservations/Access/
```

---

### Notifications

```text
ReservationNotificationResultService.php
ReservationReminderService.php
ReservationConfirmationService.php
ScheduleChangeNotificationService.php
ReservacionBuzonService.php
BuzonNotificacionesService.php
```

Destino:

```text
services/Reservations/Notifications/
```

---

### Configuración y errores

```text
ReservacionConfig.php
ReservacionErrorCatalog.php
ReservacionNotificacionConfigService.php
```

Destino:

```text
services/Reservations/Config/
```

---

### Locks

```text
FechaOperacionLock.php
ContactoOperacionLock.php
```

Destino:

```text
services/Reservations/Locks/
```

---

### Presentación

```text
ReservacionMapaAdministrativaService.php
ReservacionMapaMesaPresenter.php
```

Destino:

```text
services/Reservations/Presentation/
```

---

## POS

```text
PuntoVentaReservacionService.php
PosReservacionQueryService.php
PosReservacionSerializer.php
PosMesaProjectionPresenter.php
ReservacionPoliticaPosService.php
TicketTemporalService.php
```

Destino:

```text
services/Pos/
```

---

## Tables

```text
MesaEstadoService.php
OcupacionMesasService.php
```

Destino:

```text
services/Tables/
```

---

## Scheduling

```text
HorarioOperacionService.php
HorarioOperacionImpactoService.php
HorarioReservacionService.php
HorarioConfigLock.php
```

Destino:

```text
services/Scheduling/
```

En caso de crecer:

```text
services/Scheduling/Locks/
```

podrá utilizarse para los componentes de concurrencia.

---

## Menu

```text
Carta.php
MenuPdf.php
CategoriaMenuService.php
CataService.php
```

Destino:

```text
services/Menu/
```

---

## Inventory

```text
Inventario.php
Proveedores.php
HistorialPrecios.php
```

Destino:

```text
services/Inventory/
```

---

## Users

```text
UsuarioService.php
UsuarioConfig.php
NipService.php
```

Destino:

```text
services/Users/
```

---

## Contact

```text
ContactoService.php
ContactoAccesoService.php
```

Destino:

```text
services/Contact/
```

---

## Notifications

Las integraciones externas generales se separan de los casos de uso específicos
de reservaciones. La reestructuración de notificaciones elimina los providers y
la selección manual por entorno; los Services de reservaciones construyen el
contrato y usan el adaptador n8n únicamente después del commit.

### n8n

```text
N8nNotificationClient.php
```

Destino:

```text
services/Notifications/N8n/
```

### Configuración

```text
NotificationConfig.php
```

Destino:

```text
services/Notifications/
```

---

## Security

```text
AdminCsrfService.php
StaffCsrfService.php
```

Destino:

```text
services/Security/Csrf/
```

---

## Analytics

```text
Analiticas.php
AreasMejora.php
Sugerencias.php
```

Destino:

```text
services/Analytics/
```

---

## Shared

Los siguientes componentes deberán revisarse antes de clasificarse definitivamente:

```text
RangoPeriodo.php
ReporteSistemaService.php
SitioConfig.php
AnuncioConfig.php
AreasMejora.php
```

`Shared/` únicamente debe utilizarse cuando el componente sea realmente transversal a varios módulos.

Destino tentativo:

```text
services/Shared/
```

No debe utilizarse como carpeta por defecto para componentes difíciles de clasificar.

---

# 6. Orden de migración

La migración se realizará por fases.

## Fase 0 — Preparación

Antes de mover archivos:

1. Crear una rama específica de refactor.
2. Crear la nueva estructura de directorios.
3. Verificar configuración PSR-4.
4. Documentar el estándar arquitectónico.
5. Evitar nuevos archivos directamente en `services/`.
6. Confirmar que el proyecto funciona correctamente antes de comenzar.

Resultado esperado:

```text
Baseline funcional conocida.
```

---

# 7. Fase 1 — Notifications

Primero se migrarán los servicios de notificaciones generales.

Orden recomendado:

```text
Contracts
↓
Development
↓
N8n
↓
Factory
```

Motivo:

- Grupo relativamente aislado.
- Responsabilidades claras.
- Bajo impacto en lógica central.
- Permite validar el esquema de namespaces antes de modificar dominios críticos.

Después de cada movimiento deben actualizarse:

```text
namespace
use
instanciaciones
type hints
factories
tests
```

---

# 8. Fase 2 — Security

Mover:

```text
AdminCsrfService
StaffCsrfService
```

a:

```text
Services\Security\Csrf
```

Es un cambio pequeño que permite seguir validando el mecanismo de modularización.

---

# 9. Fase 3 — Reservations periférico

Antes de tocar los Services principales de reservaciones, migrar los componentes con responsabilidades más delimitadas.

Orden:

```text
Access
↓
Config
↓
Locks
↓
Notifications
↓
Presentation
```

Ejemplo:

```text
Services\ReservationAccessTokenService
```

pasará a:

```text
Services\Reservations\Access\ReservationAccessTokenService
```

---

# 10. Fase 4 — Reservations / Availability

Migrar:

```text
AsignacionMesasService
CapacidadReservacionesService
DisponibilidadReservacionService
ReservacionVigenciaService
ReservacionAsignacionVersionService
```

Esta fase requiere mayor validación debido a que la disponibilidad y asignación de mesas son utilizadas por diferentes superficies del sistema.

Se deberá comprobar al menos:

- Consulta de disponibilidad.
- Capacidad por horario.
- Asignación automática de mesas.
- Combinación de mesas.
- Creación de reservaciones.
- Modificación.
- Cancelación.
- Liberación de mesas.
- Detección de conflictos.

---

# 11. Fase 5 — Reservations principales

Una vez estabilizados sus componentes dependientes, mover:

```text
ReservacionService
ReservacionPublicaService
ReservacionAdministrativaService
ReservacionMantenimientoService
```

Destino:

```text
services/Reservations/
```

En esta etapa **no se dividirán todavía estas clases**, aunque alguna pueda presentar demasiadas responsabilidades.

El objetivo continúa siendo únicamente reorganizar la arquitectura física.

---

# 12. Fase 6 — POS y Tables

Migrar:

```text
Pos/
Tables/
```

Estos módulos se realizarán de forma consecutiva debido a su relación directa.

Validaciones principales:

- Apertura de mesa.
- Estado de mesa.
- Ticket activo.
- Agregar productos.
- Reservaciones desde POS.
- Cierre de ticket.
- Cancelación.
- Liberación de mesas.
- Proyección del mapa.
- Serialización de respuestas.

---

# 13. Fase 7 — Scheduling

Migrar:

```text
HorarioOperacionService
HorarioOperacionImpactoService
HorarioReservacionService
HorarioConfigLock
```

Validar:

- Horarios regulares.
- Excepciones.
- Cambios de horario.
- Impacto sobre reservaciones existentes.
- Disponibilidad resultante.
- Locks de configuración.

---

# 14. Fase 8 — Módulos restantes

Migrar individualmente:

```text
Menu
Inventory
Users
Contact
Analytics
Shared
```

Al ser grupos relativamente independientes, cada módulo debe realizarse como cambio separado siempre que sea posible.

---

# 15. Validación técnica por fase

Después de cada fase se deberá ejecutar como mínimo:

## Composer

```bash
composer dump-autoload
```

---

## PHP syntax

Validar los archivos modificados:

```bash
php -l ruta/al/archivo.php
```

o realizar validación sobre todos los archivos afectados.

---

## Búsqueda de referencias antiguas

Buscar imports como:

```php
use Services\NombreService;
```

que deberían haberse actualizado.

También revisar referencias mediante:

```text
new ClassName
ClassName::
type hints
interfaces
factories
callbacks
```

---

# 16. Validación funcional

Además de las verificaciones técnicas, después de cada módulo debe realizarse una prueba funcional.

Para servicios de reservaciones:

```text
Crear reservación
Consultar reservación
Editar reservación
Cancelar reservación
Asignar mesas
Liberar mesas
```

Para notificaciones:

```text
Generar evento
Enviar a n8n
Procesar respuesta
Validar fallback
```

Para POS:

```text
Abrir mesa
Crear ticket
Agregar producto
Actualizar ticket
Cerrar ticket
```

La funcionalidad antes y después de la migración debe ser equivalente.

---

# 17. Estrategia de commits

Los commits deben ser pequeños y representar una modificación arquitectónica específica.

Preferir:

```text
refactor(services): move n8n notification services
refactor(services): move notification contracts
refactor(services): modularize reservation access services
refactor(services): modularize reservation availability
```

Evitar commits como:

```text
refactor services
fix architecture
move everything
```

Esto permitirá revertir una fase sin afectar el resto de la migración.

---

# 18. Estrategia de ramas

La migración completa puede realizarse en una rama:

```text
refactor/services-modular-architecture
```

Sin embargo, se recomienda generar cambios independientes por módulo o fase.

Ejemplo:

```text
refactor/services-notifications
refactor/services-reservation-access
refactor/services-reservation-availability
refactor/services-reservations
refactor/services-pos
```

Esto facilita revisión, pruebas y rollback.

---

# 19. Segunda etapa: refactor interno

La finalización de la migración física no implica que todos los Services tengan una estructura interna óptima.

Después de estabilizar la arquitectura modular se realizará una revisión específica de complejidad.

Servicios prioritarios:

```text
ReservacionPublicaService
ReservacionErrorCatalog
HorarioOperacionImpactoService
PuntoVentaReservacionService
ReservacionAdministrativaService
ReservacionService
MesaEstadoService
HorarioOperacionService
AsignacionMesasService
Analiticas
```

El tamaño por sí solo no será criterio suficiente para dividirlos.

Se evaluará:

- Número de responsabilidades.
- Cantidad de dependencias.
- Número de casos de uso.
- Complejidad transaccional.
- Acoplamiento.
- Frecuencia de modificación.
- Reutilización parcial por otros componentes.

---

# 20. Criterios para dividir un Service

Un Service deberá considerarse candidato a división cuando presente varias de las siguientes características:

- Múltiples razones independientes para cambiar.
- Gran cantidad de dependencias.
- Casos de uso diferentes dentro de una sola clase.
- Persistencia, validación, notificación y transformación mezcladas.
- Diferentes consumidores utilizando subconjuntos distintos.
- Transacciones independientes.
- Métodos agrupables claramente por responsabilidad.

La división deberá hacerse por comportamiento y no únicamente por cantidad de líneas.

---

# 21. Criterios de finalización

La migración de `services/` se considerará completada cuando:

- Todos los Services tengan un módulo definido.
- La raíz de `services/` esté vacía o prácticamente vacía.
- Los namespaces reflejen la estructura de carpetas.
- No existan referencias a namespaces antiguos.
- Composer genere correctamente el autoload.
- Los principales flujos funcionales hayan sido validados.
- Las integraciones externas continúen funcionando.
- Los nuevos Services sigan obligatoriamente la arquitectura modular.

La estructura esperada será:

```text
services/
├── Analytics/
├── Contact/
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

---

# 22. Principio de migración

La estrategia del proyecto será:

```text
Organizar
    ↓
Estabilizar
    ↓
Validar
    ↓
Refactorizar
```

No:

```text
Mover + renombrar + dividir + reescribir
```

en una misma modificación.

Esta separación permitirá evolucionar la arquitectura manteniendo control sobre las regresiones y reduciendo el riesgo sobre funcionalidades críticas del sistema.
