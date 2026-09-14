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

Commit etapa 1: `8073afe`.

## Etapa 2 — Respuesta síncrona

Objetivo: informar aceptación o fallo real del intento. Causa: el 202 anterior
precedía al proveedor. PHP exige HTTP 200 y el canal esperado, con timeout de
25 segundos; n8n responde sólo después del nodo SMTP/Meta. El 202 sigue siendo
únicamente ACK de trabajo para cambio de horario. No hay callback de confirmación.

Archivos: cliente HTTP, servicio de confirmación, catálogo, workflow confirmación
y pruebas de transporte/DB/workflows. Una falla conserva la retención y el hash.
Tests: Email/WhatsApp accepted/failed, timeout, 4xx, 5xx, JSON inválido, 202 temprano,
canal incorrecto; suite DB prueba commit visible antes de HTTP.
Resultado: tests PHP aprobados; commit PHP `0762a41`. El artefacto n8n se cierra
junto con autenticación nativa en etapa 5 para mantener un export coherente.
Riesgo: falta ensayo con proveedores reales; accepted no significa entrega o lectura.

## Etapa 3 — Política de reenvíos

Objetivo: máximo tres aceptaciones por ciclo y cooldown backend de 60 segundos.
Causa: antes sólo se comparaba created_at, sin acreditar aceptación ni límite.
Archivos: VerificacionContacto, ConfirmationResendPolicy, ContactoAccesoService,
ReservationConfirmationService, ReservacionPublicaService, controlador/ruta estado,
catálogo y migración OTP. accepted_at permite derivar send_count; created_at limita
todas las solicitudes, incluso fallidas. No se persiste contador redundante ni OTP plano.

Decisiones: ciclo por retención durante todo el hold; acceso de contacto tiene ciclo
fijo de 15 minutos, que también termina al consumir el código. Reenvíos no renuevan
el ciclo. La sesión del navegador no controla el cupo. Development simula aceptación
sin HTTP para ejercer la misma política. Un proceso que muere sin guardar resultado
deja pending y bloquea reenvíos hasta fin del ciclo: no inventa una aceptación o fallo.
Las filas históricas sin evidencia quedan legacy hasta expirar su ciclo vivo.

Tests: suite OTP aislada y tres suites DB previas; máximo/cooldown/rechazo, hash,
OTP anterior inválido, refresh/nueva sesión, dos procesos concurrentes y visibilidad
post-commit desde otra conexión. PASS. Runner crea/elimina sólo una BD temporal;
la BD configurada en includes/.env no recibe migraciones.
Riesgo: aplicar migración una vez durante ventana coordinada antes del nuevo código.
Commit: `1d19b91`.

## Etapa 4 — UX de reenvío

Objetivo: presentar el cupo y cooldown que decide PHP. Causa: el botón anterior
no reflejaba el estado persistente. Archivos: componente reservation-resend,
form.js, reservation-access.js, vista _reserva.php, bundles y test de UI.
Decisiones: contador por fecha límite, bloqueo inmediato de doble clic, fallo
cerrado ante respuestas incompletas y consulta de estado sin reenviar al volver
a capturar el contacto tras refresh. No se guarda contacto ni OTP en storage.
La skill impeccable orientó anuncios accesibles sólo por transición y mensajes
neutros cuando no existe evidencia de aceptación; no se rediseñó la interfaz.
Tests: simulador JS de contador/cupo/concurrencia/limpieza PASS; build JS PASS.
Navegador sobre base desechable: primer envío muestra 2 reenvíos y contador;
primer reenvío muestra 1 y reinicia contador. Transporte development simulado.
Resultado: interfaz conectada al backend; límites y concurrencia cubiertos en DB.
Riesgo: no sustituye ensayo con SMTP/Meta reales. Commit: el de esta sección.

Commit etapa 4: `4a020e9`. El segundo reenvío y refresh también se verificaron:
botón deshabilitado y cero disponibles, sin errores JS observados. Se cerró la
vista y se eliminó la base desechable; no se enviaron mensajes externos.

## Etapa 5 — Autenticación y configuración de workflows

Objetivo: eliminar secretos en Code y separar direcciones. Causa: autenticación
manual con acceso al entorno y una clave compartida. Archivos: NotificationConfig,
N8nReservationsController, configuración PHP de ejemplo, tres JSON y contrato PHP de tests.
Decisiones: Webhook Header Auth X-N8N-Secret; HTTP Header Auth saliente con
X-N8N-Callback-Secret; secretos distintos obligatorios. Configuración no secreta
en Set independiente por workflow, sin $env ni $vars. Exports inactivos sin credenciales.
Los JSON incluyen ya los contratos coordinados de etapas 2, 7 y 8; el despliegue
debe tomar el conjunto final, no un commit intermedio.
Tests: 251 escenarios de grafo/Code, transporte PHP y autenticación ausente,
incorrecta, cruzada y claves iguales PASS. Resultado: contratos locales aprobados.
Riesgo: asignar credenciales y probar Header Auth nativo en n8n real sigue pendiente.
Commit: el de esta sección.

Commit etapa 5: `3570af2`.

## Etapa 6 — Despliegue reproducible preparado

Objetivo: persistencia y reinicio sin comandos set. Causa: arranque manual no
reproducible. Archivos: n8n/README.md y n8n/deploy/{compose.yaml,.env.example,
.gitignore,README.md}. Decisiones: Linux Compose de instancia única con n8n y
runner 2.38.7 fijados, secretos por archivo, volumen exclusivo, restart policy,
zona horaria, healthcheck y red interna; proxy TLS del host como requisito.
Incluye backup consistente, restore a otro volumen, actualización/rollback y rotación.
Tests: revisión estática y fuentes oficiales de versión/configuración. No se
ejecutó Docker porque no está instalado, ni se asignó infraestructura definitiva.
Resultado: receta preparada, no desplegada. Riesgo: faltan digests, arranque,
credenciales, permisos, reinicio host/contenedor y restore reales en TEST.
Commit: el de esta sección. No se exportaron secretos ni se modificó n8n vivo.

Commit etapa 6: `0014ce8`.

## Etapa 7 — Recuperación conservadora de recordatorios

Objetivo: recuperar caídas sin duplicar aceptación conocida. Causa: la fila
deduplicada bloqueaba cualquier recuperación. Archivos: ReminderService,
ResultService, Contract, ruta claim, DDL/migraciones y tests de recuperación/DB.
Decisiones: claim atómico obligatorio antes del proveedor; fuente estable por
raíz/fecha, intento creciente hasta tres, backoff de cinco minutos y token
rotado en cada recuperación. Prepared sin claim y failed retryable se recuperan.
Claim sin resultado permanece pendiente de revisión; ausencia de callback no
demuestra rechazo y no autoriza otro envío. Accepted es terminal. Migración marca
filas históricas reclamadas para no suponer que nunca se enviaron.
ResultService y DDL incluyen la semántica accepted de etapa 8 por ser el mismo
contrato de persistencia. No cambia elegibilidad ni regla de día anterior.
Tests: cinco suites DB aisladas PASS; caída tras prepare, claim duplicado,
callback perdido/obsoleto, rechazo/reintento/límite y rotación de token PASS.
Migración desde f274eda y traducción de estados históricos PASS en BD desechable.
Resultado: recuperación segura implementada. Riesgo: resultados inciertos exigen
evidencia del proveedor y revisión operativa; no existe garantía exactly-once.
Commit: el de esta sección.

Commit etapa 7: `53ed5f9`.

## Etapa 8 — Semántica de estados

Objetivo: no presentar aceptación como entrega. Causa: delivered significaba
respuesta del proveedor y accepted significaba 202 de n8n. Archivos: impactos,
ScheduleChangeNotificationService, AdminBuzonController, buzon.js/bundles,
documentación de afectaciones y tests DB/buzón. DDL, migración y callbacks quedaron
coordinados en etapa 7. Decisiones: pending hasta callback, accepted sólo proveedor,
failed sin alterar estado de reservación. ACK explícito accepted_by=n8n no cambia
estado persistente. Callback rápido no es sobrescrito; accepted no expira por timeout.
Tests: contratos buzón/impactos, matriz DB y compilación admin PASS. La migración
convierte accepted histórico a pending y delivered histórico a accepted.
Resultado: estados y UI alineados. Riesgo: pausar/drenar workflows para migrar;
el timeout de cambio de horario conserva reenvío manual sujeto a revisión del
resultado incierto. Commit: el de esta sección.

Commit etapa 8: `eacbdc2`.

## Etapa 9 — Frontera del contrato

Objetivo: sólo intención y datos funcionales desde PHP. Causa: el array data
permitía claves arbitrarias aunque los casos de uso ya enviaban datos mínimos.
Archivos: ReservationNotificationContract y test notification-boundary.
Decisión: allowlist por evento y valores textuales; propósito/código/vencimiento
o enlace/vencimiento. Modo text/template es una indicación de transporte; nombres
de plantilla, bodyParameters y components se construyen exclusivamente en cada n8n.
Tests: seis combinaciones evento/canal y rechazo de detalles SMTP/Meta PASS;
contratos y transporte existentes PASS. Email conserva el mismo contrato.
Resultado: límite explícito y comprobable. Riesgo: nuevos campos funcionales deben
agregarse deliberadamente con pruebas en los tres exports. Commit: el de esta sección.
