# Validación contractual de reservaciones

Fecha: 2026-08-06
Rama: `modulo-reservaciones`
Base auditada: `889a621` (`test(reservaciones): validar mensajes y decisiones canonicas`)

## Estado

**APROBADA para contratos, código y build. Validación funcional administrativa en navegador: PARCIAL.**

La validación estática, contractual y de compilación quedó aprobada. La pantalla pública se abrió en navegador local. La pantalla administrativa redirigió correctamente a `/login`, pero no se ejecutaron acciones administrativas con efectos de base de datos porque no había una sesión autenticada ni credenciales de prueba disponibles.

## Línea base y alcance

- `git diff --check` limpio antes de iniciar.
- Sin cambios de DDL ni de `database/database.sql`.
- Se conservaron sin modificar los documentos preexistentes no rastreados:
  - `docs/reservaciones/auditoria_codigo_integral.md`
  - `docs/reservaciones/auditoria_funcional_integral.md`
- El POS conserva su proyección y cálculo visual existentes; la proyección nueva se entrega bajo campos específicos del mapa administrativo.

## Cambios entregados

1. `ea27ffe` — `fix(reservaciones): recuperar interfaz tras acciones y cambios de fecha`
   - Cierre completo del no-show después del POST confirmado.
   - Liberación de loading, cursor, botones, `body.inert` y foco.
   - Refresco posterior no bloqueante y sin repetir la mutación.
   - Protección de respuestas de fecha fuera de orden y limpieza completa al cambiar de fecha.
2. `ee683c5` — `fix(reservaciones): corregir resultados de creacion administrativa`
   - `SIN_ASIGNACION` como `decision_requerida`, `commit=false` y presentación contractual exacta.
   - Creación automática como `exito`, `ok=true`, `commit=true`, con `reservacion_id` y `mesa_ids`.
   - Reconciliación y log de respuestas contradictorias.
3. `7ab6b8f` — `refactor(reservaciones): alinear estados visuales del mapa`
   - Presenter exclusivo del mapa administrativo con precedencia explícita.
   - Verde disponible, rojo ocupado/exacto, azul próximo/tolerancia y modificadores de 30–60 y ausencia pendiente.
   - Leyenda y etiquetas accesibles.
   - El adaptador compartido sólo consume `estadoVisual` administrativo mediante opt-in.
4. El cuarto commit incorpora esta batería de pruebas y este informe con el mensaje `test(reservaciones): validar recuperacion y estados del mapa`.

## Evidencia automatizada

Comandos ejecutados con resultado satisfactorio:

- `npm.cmd run test:php`
  - Catálogo contractual y presenter del mapa OK.
  - Incluye bordes 60, 30, 0, tolerancia, ausencia pendiente, ticket y mesa no utilizable.
- `npm.cmd run test:js`
  - Sintaxis y contratos de POS, formulario, operación, mapa visual y adaptador OK.
  - Incluye aislamiento de `estado_visual_mapa` para que POS no lo adopte sin opt-in.
- `npm.cmd run audit:reservaciones`
  - Auditoría contractual OK.
- `npm.cmd run build`
  - Build completo OK.
  - Sólo se observaron advertencias conocidas de Sass legacy API y `fs.Stats`.
- `git diff --check`
  - Sin errores de whitespace.

Los mensajes de log del test PHP sobre un `commit` contradictorio y un código desconocido son casos provocados por la propia prueba para verificar reconciliación y degradación segura; no indican fallo.

## Evidencia funcional en navegador

- Servidor temporal local: `http://127.0.0.1:8000/`, con `public/` como document root.
- `/reservaciones` cargó con título `Casa Pestalozzi · Cocina mediterránea con alma mexicana` y mostró la tarea `Elige una tarea de reservación` junto con el campo de fecha.
- `/admin/reservations` redirigió a `/login` por autenticación requerida.
- No se envió ningún formulario ni se modificó la base de datos durante esta validación.

## Riesgo residual

Queda pendiente repetir en una sesión administrativa real los flujos de no-show, cambio rápido de fecha, creación sin asignación, creación automática y lectura visual de las siete combinaciones del mapa. El código y las pruebas ejecutables cubren sus contratos, pero esa evidencia requiere una cuenta de prueba con datos reales o un entorno administrativo autenticado.
