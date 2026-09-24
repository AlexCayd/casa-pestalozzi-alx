# Correcciones pendientes detectadas durante la migración

## Propósito

Este archivo registra exclusivamente **errores, incumplimientos arquitectónicos o riesgos concretos y verificables** detectados durante la migración que queden fuera del alcance de la fase en curso.

No es un backlog general, una lista de ideas ni un espacio para mejoras opcionales.

Si no existe un hallazgo real, este documento debe permanecer sin entradas.

---

## Criterios para agregar una entrada

Sólo registrar un hallazgo cuando:

1. exista un error o incumplimiento concreto;
2. exista evidencia verificable;
3. puedan identificarse los archivos afectados;
4. exista impacto técnico o funcional;
5. corregirlo durante la fase actual mezclaría migración física con un refactor o cambio funcional distinto.

No registrar:

- preferencias de estilo;
- nombres que podrían mejorarse;
- optimizaciones hipotéticas;
- ideas sin evidencia;
- tareas ya cubiertas por `docs/migracion_services.md`;
- observaciones sin impacto real.

---

## Formato obligatorio

### [ID] Título breve

**Error encontrado:**
Descripción concreta del problema.

**Evidencia:**
Cómo se verificó, referencia al código, prueba que falla o comportamiento reproducible.

**Archivos afectados:**
- `ruta/archivo.php`

**Impacto:**
Consecuencia real o riesgo técnico.

**Fuera de alcance porque:**
Razón por la que no debe corregirse en la fase actual.

**Estado:** `pendiente`

---

## Entradas

### [ARQ-001] Model\Usuario depende de NipService

**Error encontrado:**
`Model\Usuario::porNip()` llama directamente a `NipService::lookup()`. Esto conserva una dependencia `Model → Service` que contradice la dirección de capas definida en `docs/arquitectura.md`.

**Evidencia:**
La llamada está en `models/Usuario.php`, dentro de `porNip()`. El movimiento de Users sólo cambia el import a `Services\Users\NipService`; la llamada y el flujo de autenticación siguen existiendo.

**Archivos afectados:**
- `models/Usuario.php`
- `services/Users/NipService.php`

**Impacto:**
El Model de usuario depende de una clase de casos de uso y credenciales, lo que acopla la persistencia a Services y dificulta sustituir o probar cada capa por separado.

**Fuera de alcance porque:**
Esta fase es un movimiento físico y requiere conservar el login por NIP. Refactorizar `porNip()` o el flujo de autenticación cambiaría responsabilidades y queda para una etapa separada.

**Estado:** `pendiente`

### [ARQ-002] El pin administrativo incluye reservaciones fuera del horario efectivo

**Error encontrado:**
El mapa administrativo calcula `mesas_estado` con reservaciones confirmadas que se traslapan con la hora consultada, incluso cuando la reservación está marcada `fuera_horario_operacion`. Después, la proyección administrativa la excluye de `en_proyeccion_mapa`. Por tanto, la lista puede decir que la reservación no participa en el mapa mientras el pin de su mesa todavía refleja esa reservación.

**Evidencia:**
Antes de esta corrección, `PosReservacionQueryService::paraFecha()` construía `$reservacionesMapa` con reservaciones confirmadas que aplicaban a la hora, sin filtrar `fuera_horario_operacion`. Después, `ReservacionMapaAdministrativaService::proyectar()` las marcaba con `en_proyeccion_mapa = false`.

**Archivos afectados:**
- `services/Pos/PosReservacionQueryService.php`
- `services/Reservations/ReservacionMapaAdministrativaService.php`
- `controllers/ReservacionOperacionController.php`
- `services/Tables/MesaEstadoService.php`
- `scripts/tests/run-reservaciones-buzon-operativo.php`

**Impacto:**
En horas que se traslapan con el intervalo planificado, la lista y el pin pueden comunicar estados distintos sobre la inclusión de una reservación fuera del horario efectivo.

**Fuera de alcance porque:**
La proyección visual se clasifica sin quitar la reservación de ocupación, disponibilidad, asignación ni seguimiento. Este cambio no reestructura `MesaEstadoService` ni altera las reglas canónicas de intervalo.

**Resolución y evidencia:**
`PosReservacionQueryService::reservacionesParaProyeccionVisual()` ahora excluye `fuera_horario_operacion` únicamente del presenter administrativo; `ReservacionMapaAdministrativaService::proyectar()` conserva la reservación en la fila administrativa con `en_proyeccion_mapa = false`. La prueba `scripts/tests/run-reservaciones-buzon-operativo.php` verifica en el mismo caso la fila, la ausencia de alertas visuales en el pin y un bloqueo independiente que conserva el rojo y comunica su causa.

**Estado:** `resuelto`

### [ARQ-003] Las Views invocan directamente HorarioOperacionService

**Error encontrado:**
Las Views de inicio llaman directamente a métodos estáticos de `HorarioOperacionService` para preparar excepciones semanales. La vista queda acoplada a un Service y ejecuta lógica de preparación fuera del Controller.

**Evidencia:**
`views/home/_footer.php` importa `Services\Scheduling\HorarioOperacionService` y llama `mapearExcepcionesDeLaSemana()`. `views/home/_reserva.php` hace la misma llamada mediante FQCN. La migración sólo actualizó el namespace para preservar el montaje actual.

**Archivos afectados:**
- `views/home/_footer.php`
- `views/home/_reserva.php`

**Impacto:**
La presentación depende directamente de la capa Services, lo que acopla el renderizado de estas Views al autoload y a la ejecución del servicio.

**Fuera de alcance porque:**
Esta fase sólo mueve archivos y actualiza namespaces. Trasladar el cálculo al Controller cambiaría la preparación de datos de la View y corresponde a un refactor separado.

**Estado:** `pendiente`

### [ARQ-004] Scheduling depende de Availability para normalizar horas

**Error encontrado:**
`Services\Scheduling\HorarioOperacionService` delega la normalización de una hora a `Services\Reservations\Availability\HorarioReservacionService`. Esto crea una dependencia desde Scheduling hacia el módulo de disponibilidad de reservaciones para una operación de normalización temporal.

**Evidencia:**
`HorarioOperacionService::horaComparable()` llama a `HorarioReservacionService::normalizarHoraSql()`. La referencia permanece después de mover físicamente `HorarioReservacionService` a `services/Reservations/Availability/`.

**Archivos afectados:**
- `services/Scheduling/HorarioOperacionService.php`
- `services/Reservations/Availability/HorarioReservacionService.php`

**Impacto:**
El servicio de horario operativo queda acoplado a Availability y puede requerir cargar el módulo de reservaciones para comparar horas.

**Fuera de alcance porque:**
Esta fase sólo reubica clases y conserva el comportamiento. Extraer o reasignar la normalización de horas cambiaría responsabilidades y requiere una fase de refactor separada.

**Estado:** `pendiente`

### [ARQ-005] Scheduling y ScheduleChanges dependen entre sí

**Error encontrado:**
`Services\Scheduling\HorarioOperacionService` usa el servicio de impactos de cambios de horario, mientras que `Services\Reservations\ScheduleChanges\HorarioOperacionImpactoService` consulta de vuelta el horario operativo. La relación entre ambos módulos es circular.

**Evidencia:**
`HorarioOperacionService` llama a `HorarioOperacionImpactoService::evaluarHorarioSemanal()` y `::persistir()`. A su vez, `HorarioOperacionImpactoService` llama a `HorarioOperacionService::estaAbierto()` para reconciliar reservaciones y validar accesos. El movimiento hace explícitas ambas dependencias entre módulos.

**Archivos afectados:**
- `services/Scheduling/HorarioOperacionService.php`
- `services/Reservations/ScheduleChanges/HorarioOperacionImpactoService.php`

**Impacto:**
Scheduling y ScheduleChanges quedan acoplados en ambos sentidos, dificultando probarlos o cambiar sus responsabilidades por separado.

**Fuera de alcance porque:**
Esta fase sólo reubica el servicio y actualiza namespaces. Romper el ciclo requiere cambiar cómo se entrega o consulta el horario efectivo, lo cual altera contratos entre servicios y corresponde a un refactor posterior.

**Estado:** `pendiente`
