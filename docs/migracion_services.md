# Plan vigente de migración de Services

## 1. Propósito

Este documento define el plan **vigente** para migrar la capa `services/` hacia la arquitectura modular de Casa Pestalozzi.

La migración tiene como objetivo mejorar organización, mantenibilidad y claridad de dependencias **sin alterar comportamiento funcional durante los movimientos físicos**.

La fuente arquitectónica principal es:

```text
docs/arquitectura.md
```

Para notificaciones de reservaciones continúan siendo normativas:

```text
docs/reservaciones/notificaciones.md
docs/reservaciones/afectaciones_reservaciones_por_cambios_horario.md
n8n/README.md
```

El principio general es:

> **Primero reorganizar archivos, namespaces y referencias. Después refactorizar responsabilidades internas en cambios separados.**

---

# 2. Estado de partida

La raíz `services/` todavía contiene Services pertenecientes a diferentes dominios.

Sin embargo, la migración ya comenzó en algunas áreas.

Actualmente existen, entre otras:

```text
services/
├── Integrations/
│   └── N8nClient.php
│
├── Notifications/
│   └── NotificationConfig.php
│
└── Reservations/
    └── Notifications/
        ├── ConfirmationResendPolicy.php
        ├── ReservationConfirmationService.php
        ├── ReservationNotificationContract.php
        ├── ReservationNotificationResultService.php
        ├── ReservationReminderService.php
        └── ScheduleChangeNotificationService.php
```

Esta estructura se considera correcta y **no debe revertirse**.

No deben reintroducirse Provider, Factory o Dispatcher antiguos para notificaciones.

---

# 3. Arquitectura objetivo

```text
services/
├── Analytics/
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

El objetivo es que la raíz de `services/` quede vacía o contenga únicamente excepciones explícitamente justificadas.

---

# 4. Reglas obligatorias de migración

## 4.1 Movimiento físico sin cambio funcional

Un commit de movimiento debe limitarse a:

```text
archivo
namespace
use/imports
referencias
tests afectados por namespace/path
```

No debe incluir simultáneamente:

```text
cambio de reglas de negocio
cambio de esquema de BD
renombrado conceptual masivo
división de clases
reescritura de algoritmos
cambio de contratos HTTP
cambio de contratos n8n
```

salvo que sea estrictamente necesario para mantener el sistema funcionando.

---

## 4.2 Un dominio por responsabilidad

Cada Service debe ubicarse según la función que realmente ejecuta, no únicamente por su nombre.

La clasificación vigente distingue expresamente:

```text
Scheduling                → horario operativo del restaurante
Reservations/Availability → horario reservable y disponibilidad
Reservations/ScheduleChanges → consecuencias sobre reservaciones de cambios de horario
Notifications             → configuración transversal de transporte
Reservations/Notifications → comunicaciones específicas de reservaciones
Integrations              → adaptadores a sistemas externos
```

---

## 4.3 No crear nuevos Services en la raíz

A partir de esta migración debe evitarse:

```text
services/NuevoService.php
```

Toda clase nueva debe ubicarse en el módulo correspondiente.

---

## 4.4 Namespaces alineados con carpetas

Composer ya utiliza:

```json
"Services\\": "./services"
```

Ejemplo:

```text
services/Users/NipService.php
```

debe declarar:

```php
namespace Services\Users;
```

---

# 5. Registro de correcciones fuera de alcance

Durante la migración puede aparecer código incorrecto, una dependencia invertida o una violación arquitectónica que no sea necesario corregir para completar la fase.

Estos hallazgos se registrarán en:

```text
docs/correcciones_pendientes_arquitectura.md
```

## Regla de uso

**No es un backlog general.**

Sólo debe añadirse una entrada cuando:

1. exista un error, incumplimiento o riesgo concreto;
2. pueda indicarse evidencia o reproducción;
3. tenga archivos afectados identificables;
4. la corrección quede fuera del alcance de la fase actual;
5. resolverlo durante el movimiento mezclaría refactor funcional con migración física.

Cada entrada debe incluir como mínimo:

```text
Error encontrado
Evidencia
Archivos afectados
Impacto
Por qué queda fuera de alcance
```

No registrar:

- ideas de mejora;
- preferencias personales;
- posibles optimizaciones sin evidencia;
- nombres que simplemente podrían gustar más;
- problemas ya incluidos en una fase de este documento;
- deuda genérica sin impacto concreto.

Si no existe un hallazgo real, **el archivo debe permanecer vacío salvo por su plantilla**.

---

# 6. Mapeo vigente

## 6.1 Users

Mover:

```text
UsuarioService.php
NipService.php
UsuarioConfig.php
```

a:

```text
services/Users/
```

Namespace:

```text
Services\Users
```

Durante esta fase no se modificará la lógica de autenticación, generación de NIP o credenciales.

La dependencia existente:

```text
Model\Usuario → NipService
```

no debe resolverse incidentalmente durante el movimiento. Debe documentarse en `correcciones_pendientes_arquitectura.md` si continúa vigente tras verificarla y tratarse posteriormente.

---

## 6.2 Notifications e Integrations

Mantener:

```text
services/Integrations/N8nClient.php
services/Notifications/NotificationConfig.php
services/Reservations/Notifications/*
```

Mover:

```text
ReservacionNotificacionConfigService.php
```

a:

```text
services/Reservations/Config/
```

Mover:

```text
ReservacionBuzonService.php
```

a:

```text
services/Reservations/Notifications/
```

Mover:

```text
BuzonNotificacionesService.php
```

a:

```text
services/Notifications/
```

No modificar contratos n8n ni reintroducir abstracciones antiguas.

---

## 6.3 Scheduling

`Scheduling` significa **horario operativo**, no ejecución de cron jobs.

Mover:

```text
HorarioOperacionService.php
```

a:

```text
services/Scheduling/HorarioOperacionService.php
```

Mover:

```text
HorarioConfigLock.php
```

a:

```text
services/Scheduling/Locks/HorarioConfigLock.php
```

No mover a Scheduling reglas específicas de reservaciones.

---

## 6.4 Reservations / Availability

Mover:

```text
HorarioReservacionService.php
DisponibilidadReservacionService.php
CapacidadReservacionesService.php
AsignacionMesasService.php
ReservacionVigenciaService.php
ReservacionAsignacionVersionService.php
```

a:

```text
services/Reservations/Availability/
```

`HorarioReservacionService` pertenece aquí porque contiene reglas como:

- horizonte de reservación;
- anticipación mínima;
- generación de intervalos;
- última reservación antes del cierre;
- validación temporal específica de una reservación.

---

## 6.5 Reservations / ScheduleChanges

Mover:

```text
HorarioOperacionImpactoService.php
```

a:

```text
services/Reservations/ScheduleChanges/
```

Este Service no pertenece al dominio puro de Scheduling porque trabaja directamente con:

- reservaciones futuras;
- impactos persistidos;
- resolución de afectaciones;
- seguimiento;
- acceso temporal;
- estado de notificación.

`ScheduleChangeNotificationService` permanece en:

```text
services/Reservations/Notifications/
```

La relación buscada es:

```text
Scheduling
    ↓
Reservations / ScheduleChanges
    ↓
Reservations / Notifications
```

Debe evitarse crear dependencias circulares entre estos módulos.

---

## 6.6 Reservations / Access

Mover:

```text
ReservationAccessTokenService.php
ReservationClientSession.php
ReservationManagementAccessService.php
ReservationManagementAccessSession.php
ScheduleChangeAccessService.php
ScheduleChangeAccessSession.php
```

a:

```text
services/Reservations/Access/
```

La migración no debe cambiar contratos de sesión, tokens, hashes ni vigencias.

---

## 6.7 Reservations / Config

Mover:

```text
ReservacionConfig.php
ReservacionErrorCatalog.php
ReservacionNotificacionConfigService.php
```

a:

```text
services/Reservations/Config/
```

---

## 6.8 Reservations / Locks

Mover:

```text
FechaOperacionLock.php
ContactoOperacionLock.php
```

a:

```text
services/Reservations/Locks/
```

---

## 6.9 Reservations / Presentation

Mover:

```text
ReservacionMapaAdministrativaService.php
ReservacionMapaMesaPresenter.php
```

a:

```text
services/Reservations/Presentation/
```

Revisar posteriormente si `ReservacionMapaAdministrativaService` continúa siendo realmente presentación o si mezcla coordinación de dominio. No modificar su comportamiento durante el movimiento.

---

## 6.10 Reservations principales

Mover, una vez estabilizadas sus dependencias:

```text
ReservacionService.php
ReservacionPublicaService.php
ReservacionAdministrativaService.php
ReservacionMantenimientoService.php
```

a:

```text
services/Reservations/
```

No dividir estas clases durante esta fase.

---

## 6.11 POS

Mover:

```text
PuntoVentaReservacionService.php
PosReservacionQueryService.php
PosReservacionSerializer.php
PosMesaProjectionPresenter.php
ReservacionPoliticaPosService.php
TicketTemporalService.php
```

a:

```text
services/Pos/
```

---

## 6.12 Tables

Mover:

```text
MesaEstadoService.php
OcupacionMesasService.php
```

a:

```text
services/Tables/
```

---

## 6.13 Security

Mover:

```text
AdminCsrfService.php
StaffCsrfService.php
```

a:

```text
services/Security/Csrf/
```

---

## 6.14 Contact

Mover:

```text
ContactoService.php
ContactoAccesoService.php
```

a:

```text
services/Contact/
```

Sólo después de revisar consumidores deberá decidirse si `ContactoAccesoService` es realmente transversal o específico de reservaciones.

No reclasificarlo y cambiar comportamiento en el mismo commit.

---

## 6.15 Menu

Mover:

```text
Carta.php
MenuPdf.php
CategoriaMenuService.php
CataService.php
```

a:

```text
services/Menu/
```

---

## 6.16 Inventory

Mover:

```text
Inventario.php
Proveedores.php
HistorialPrecios.php
```

a:

```text
services/Inventory/
```

---

## 6.17 Analytics

Mover:

```text
Analiticas.php
AreasMejora.php
Sugerencias.php
```

a:

```text
services/Analytics/
```

La clasificación debe verificarse por consumidores antes del movimiento.

---

## 6.18 Shared y configuración general

Los siguientes componentes requieren clasificación individual antes de moverse:

```text
RangoPeriodo.php
ReporteSistemaService.php
SitioConfig.php
AnuncioConfig.php
```

No deben enviarse automáticamente a `Shared/`.

`Shared/` sólo es válido si se demuestra que el componente es transversal y no tiene un dominio natural.

---

# 7. Orden de ejecución

## Fase 0 — Baseline y preparación

Antes de mover código:

1. Confirmar working tree limpio.
2. Partir del `main` actualizado.
3. Crear rama de refactor.
4. Ejecutar baseline:
   ```bash
   npm test
   ```
5. Ejecutar cuando exista BD de pruebas configurada:
   ```bash
   npm run test:runtime
   ```
6. Registrar el commit/HEAD de partida.
7. Confirmar PSR-4.
8. Crear `docs/correcciones_pendientes_arquitectura.md` con su plantilla vacía.
9. No modificar comportamiento durante esta fase.

Resultado:

```text
Baseline funcional conocida.
```

---

## Fase 1 — Users

Mover:

```text
UsuarioService
NipService
UsuarioConfig
```

Actualizar:

```text
namespace
imports
Model\Usuario
AdminUsersController
scripts
tests
views que usen UsuarioConfig
```

Validar al menos:

```bash
php scripts/tests/run-usuarios-acceso.php
npm test
```

Commit independiente.

---

## Fase 2 — Cierre de Notifications

Mantener la estructura modular ya existente.

Mover únicamente los componentes todavía ubicados en raíz:

```text
ReservacionNotificacionConfigService
ReservacionBuzonService
BuzonNotificacionesService
```

Validar:

```bash
npm run test:notifications
npm test
```

Cuando exista BD:

```bash
npm run test:runtime
```

No alterar workflows n8n.

Commit independiente.

---

## Fase 3 — Frontera Scheduling / Reservations

Mover en cambios controlados:

```text
HorarioOperacionService
    → Scheduling/

HorarioConfigLock
    → Scheduling/Locks/

HorarioReservacionService
    → Reservations/Availability/

HorarioOperacionImpactoService
    → Reservations/ScheduleChanges/
```

Actualizar todas las referencias.

Validar especialmente:

- horarios regulares;
- excepciones;
- horario efectivo;
- disponibilidad;
- impactos;
- buzón;
- cambio de horario;
- notificaciones;
- creación pública y administrativa.

Ejecutar:

```bash
npm test
npm run test:notifications
```

y cuando exista BD:

```bash
npm run test:runtime
```

---

## Fase 4 — Reservations periférico

Orden recomendado:

```text
Access
↓
Config
↓
Locks
↓
Presentation
↓
resto de Availability
```

No mover todavía los Services principales si existen referencias pendientes.

Cada subdominio debe poder cerrarse con un commit separado.

---

## Fase 5 — Reservations principales

Mover:

```text
ReservacionService
ReservacionPublicaService
ReservacionAdministrativaService
ReservacionMantenimientoService
```

No dividirlos todavía.

Validar todos los flujos de reservaciones.

---

## Fase 6 — POS y Tables

Migrar ambos dominios de forma consecutiva por su relación funcional.

Validar:

- apertura de ticket;
- estado de mesa;
- ticket activo;
- productos;
- reservaciones desde POS;
- cierre;
- cancelación;
- liberación;
- proyección;
- serialización.

---

## Fase 7 — Security y Contact

Mover de forma independiente.

No mezclar cambios de permisos, roles, CSRF o política de contacto con el movimiento físico.

---

## Fase 8 — Menu, Inventory y Analytics

Migrar módulo por módulo.

Cada módulo debe tener commit y validación propios.

---

## Fase 9 — Clasificación residual

Revisar Services que permanezcan en raíz.

Para cada uno:

```text
¿Tiene dominio claro?
¿Es realmente transversal?
¿Debe permanecer temporalmente?
```

No utilizar `Shared/` para vaciar artificialmente la raíz.

---

# 8. Validación técnica obligatoria

Después de cada fase:

## Autoload

```bash
composer dump-autoload
```

## Sintaxis PHP

```bash
php -l ruta/al/archivo.php
```

sobre los archivos modificados o un chequeo equivalente.

## Referencias antiguas

Buscar:

```text
use Services\ClaseMovida;
Services\ClaseMovida
ClaseMovida::
new ClaseMovida
```

También revisar:

```text
scripts
tests
views
controllers
models
docs normativos cuando referencien rutas físicas
```

---

# 9. Suite de pruebas

La suite disponible incluye:

```bash
npm test
npm run test:notifications
npm run test:runtime
```

`test:runtime` depende de una BD de pruebas operativa.

Una fase no debe considerarse cerrada si rompe la suite que funcionaba en el baseline.

Cuando una prueba falle:

1. determinar si es por namespace/path;
2. corregir únicamente lo necesario para la migración;
3. si revela un defecto funcional previo y fuera de alcance, documentarlo en `docs/correcciones_pendientes_arquitectura.md`.

No modificar lógica funcional sólo para hacer pasar una prueba sin identificar antes la causa.

---

# 10. Estrategia de commits

Preferir commits como:

```text
refactor(users): move user services to module
refactor(reservations): move notification support services
refactor(scheduling): move operation schedule services
refactor(reservations): move reservation schedule availability
refactor(reservations): move schedule change impact service
```

Evitar:

```text
refactor services
fix architecture
move everything
cleanup
```

Cada commit debe representar una unidad reversible.

---

# 11. Segunda etapa: refactor interno

La migración física no implica que todas las responsabilidades existentes sean óptimas.

Una vez estabilizados namespaces y estructura, se realizará un segundo pase.

Candidatos conocidos para revisión:

```text
ReservacionPublicaService
ReservacionAdministrativaService
ReservacionService
ReservacionErrorCatalog
HorarioOperacionService
HorarioOperacionImpactoService
PuntoVentaReservacionService
MesaEstadoService
AsignacionMesasService
Analiticas
```

También deberán revisarse dependencias de capa que continúen vigentes, por ejemplo:

```text
Model → Service
View → Service
```

Sólo deben tratarse después de verificar que realmente continúan existiendo.

No convertir estas observaciones en cambios dentro de los commits de movimiento.

---

# 12. Criterios para dividir un Service

Un Service puede ser candidato a división cuando presente varias de estas condiciones:

- múltiples razones independientes para cambiar;
- demasiadas dependencias;
- casos de uso diferentes en la misma clase;
- persistencia, validación, transporte y presentación mezclados;
- consumidores que utilizan subconjuntos diferentes;
- transacciones independientes;
- grupos claros de métodos por responsabilidad.

La cantidad de líneas no es por sí sola un criterio suficiente.

---

# 13. Criterios de finalización

La migración se considera completada cuando:

- todos los Services tienen un módulo definido;
- la raíz de `services/` está vacía o contiene sólo excepciones justificadas;
- los namespaces reflejan carpetas;
- no quedan referencias a namespaces antiguos;
- Composer genera correctamente el autoload;
- las suites relevantes permanecen en verde;
- las integraciones externas mantienen sus contratos;
- las nuevas clases siguen la arquitectura modular;
- `docs/arquitectura.md` coincide con la estructura implementada;
- las correcciones fuera de alcance están documentadas de forma concreta, no como backlog genérico.

---

# 14. Principio de migración

La secuencia es:

```text
Organizar
    ↓
Validar
    ↓
Estabilizar
    ↓
Refactorizar
```

No:

```text
Mover + renombrar + dividir + reescribir
```

en el mismo cambio.

La prioridad es mantener trazabilidad y reducir el riesgo de regresión en reservaciones, horarios, notificaciones, usuarios y POS.
