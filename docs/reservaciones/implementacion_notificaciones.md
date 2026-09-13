# Implementación de correcciones de notificaciones

## Etapa 0 — Baseline (2026-09-13)

Objetivo: preservar el trabajo previo y establecer las pruebas de referencia.

Rama inicial: `refactor/reservaciones-notificaciones`; HEAD `f274eda`.
`git status --short`, `git diff --check` y `git log --oneline -10` ejecutados antes de editar.
El árbol ya contenía cambios de la migración de notificaciones: servicios nuevos sin rastrear,
providers/dispatcher y tres documentos antiguos eliminados, cambios en controladores,
configuración, frontend, tests y bundles. También había cambios en Auth y privacidad.
Los commits de esta implementación incorporan únicamente los archivos del módulo necesarios
para que las etapas sean revisables; los cambios ajenos se conservan en el working tree.

Baseline aprobado: contratos PHP de comunicaciones, impactos y privacidad; tests JS de
workflows; suites DB de comunicaciones, notificaciones development y matriz de cambios
de horario. Los tests DB usan fixtures y limpieza al terminar. No se contactaron proveedores.
`git diff --check`: sin errores, con advertencias LF/CRLF preexistentes.

La referencia solicitada `afectaciones_cambio_horario.md` no existe: se usa el documento
vigente `afectaciones_reservaciones_por_cambios_horario.md`.

## Etapa 1 — Dependencias

Objetivo: separar infraestructura y casos de uso, eliminar la circularidad.
Causa: el cliente HTTP validaba rutas de reservaciones y dependía de configuración del
dominio; el servicio de impacto llamaba al orquestador que a su vez lo utilizaba.

Archivos: `services/Integrations/N8nClient.php`, servicios de confirmación/cambio de horario,
`ContactoAccesoService`, `HorarioOperacionImpactoService`, `AdminHorarioImpactoController`
y tests de comunicaciones/development/matriz afectados por el namespace.

Decisiones: el cliente recibe URL/credencial; acepta rutas webhook seguras independientes
del dominio. `ScheduleChangeNotificationService::addContact` coordina guardado y dispatch
post-commit; las constantes de intento pertenecen al dominio de impactos.
No se agregan interfaces, factories ni providers.

Tests: lint PHP, contratos de comunicaciones/impactos y DB de comunicaciones.
Resultado y commit: registrados en el historial del commit que introduce esta sección.
Riesgos pendientes: feedback síncrono OTP, reenvíos, autenticación, recuperación y despliegue
se resuelven en etapas posteriores. No se alteraron reglas de capacidad/mesas/horarios.
