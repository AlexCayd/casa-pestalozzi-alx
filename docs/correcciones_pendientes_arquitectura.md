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
`PosReservacionQueryService::paraFecha()` construye `$reservacionesMapa` filtrando por estado confirmado y `aplica_hora_consultada`, y lo entrega a `MesaEstadoService::normalizarMesas()`. Más tarde, `ReservacionMapaAdministrativaService::proyectar()` establece `en_proyeccion_mapa = false` para una reservación confirmada fuera del horario efectivo. La secuencia se observa en `ReservacionOperacionController`; la prueba `scripts/tests/run-reservaciones-buzon-operativo.php` valida el flag de la fila, pero no el estado del pin para ese caso.

**Archivos afectados:**
- `services/PosReservacionQueryService.php`
- `services/ReservacionMapaAdministrativaService.php`
- `controllers/ReservacionOperacionController.php`
- `scripts/tests/run-reservaciones-buzon-operativo.php`

**Impacto:**
En horas que se traslapan con el intervalo planificado, la lista y el pin pueden comunicar estados distintos sobre la inclusión de una reservación fuera del horario efectivo.

**Fuera de alcance porque:**
El inventario solicitado documenta el comportamiento actual sin cambiar reglas del mapa. Corregir el filtro requiere decidir si la exclusión del horario efectivo debe afectar también ocupación y disponibilidad por mesa.

**Estado:** `pendiente`
