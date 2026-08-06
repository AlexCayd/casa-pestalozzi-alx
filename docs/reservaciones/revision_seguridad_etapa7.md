# Revisión de seguridad — Etapa 7

Esta revisión es evidencia técnica del módulo; no sustituye una prueba de
penetración ni una certificación de seguridad.

## Controles comprobados

| Control | Resultado | Evidencia |
| --- | --- | --- |
| Sesión pública separada | PASS | `ReservationClientSession`: namespace `reservation_client` y CSRF propio |
| Cookie pública | PASS | `HttpOnly`, `SameSite=Lax` y `Secure` cuando la petición usa HTTPS |
| Sesión de personal | PASS | `Auth::start` endurece la cookie y usa ruta temporal escribible en desarrollo/pruebas |
| Regeneración de sesión | PASS | `Auth::login` y `ReservationClientSession::crear` regeneran el ID |
| Guardia administrativa/POS | PASS | `Auth::proteger` y allowlists de APIs revisadas contra las 168 rutas |
| CSRF admin | PASS | `AdminCsrfService`, validación en mutaciones de reservaciones |
| CSRF POS/personal | PASS | `StaffCsrfService` y token de header/cuerpo |
| OTP | PASS | `password_hash`, `password_verify`, expiración de 5 minutos, máximo 5 intentos y consumo transaccional |
| Preview OTP | PASS | sólo con `APP_ENV` no productivo y `CONTACT_OTP_PREVIEW` explícito |
| Tokens | PASS | `request_token` validado, persistido con índice único y enviado en body/form; no forma parte de URLs públicas |
| Métodos HTTP | PASS | mutaciones de reservaciones, tickets y estados usan POST/DELETE según contrato |
| Entradas | PASS | fecha, hora, comensales, contacto, IDs, versión, mesas y confirmaciones se normalizan en servicios/controladores |
| Respuestas | PASS | JSON canónico sin SQL, stack trace, ruta local, secreto ni OTP en el contrato normal |

## Revisión específica de entradas y salidas

- La autorización ocurre antes de resolver la ruta para `/admin`, POS y áreas.
- Los endpoints públicos de reservaciones validan la sesión de contacto y CSRF
  cuando la operación muta datos.
- Las asignaciones y tickets bloquean recursos dentro de transacciones y
  revalidan ocupación, estado y versión.
- El código OTP nunca se persiste en claro. El preview controlado no se usa en
  producción.
- Las respuestas de error pasan por `ReservacionErrorCatalog`; el frontend no
  compara mensajes visibles.
- La telemetría de capacidad registrada por `CapacidadReservacionesService`
  contiene fecha, hora, capacidades, resultado y origen, pero no contacto,
  OTP, CSRF ni cookies.

## Hallazgos y deuda aceptada

1. `Auth::proteger` usa listas explícitas de APIs. Toda ruta nueva debe añadirse
   a la allowlist adecuada antes de desplegarse; la revisión de Etapa 7 no
   encontró una ruta de reservaciones faltante.
2. La cookie `Secure` sólo puede activarse cuando el servidor recibe HTTPS; el
   comportamiento de HTTP local es deliberado para desarrollo.
3. No se declara cumplimiento completo de OWASP o WCAG: esta entrega documenta
   controles revisados y runners reproducibles.

## Resultado

No se detectó una vulnerabilidad crítica dentro del alcance del módulo. Las
deudas aceptadas quedan visibles para el siguiente cambio de infraestructura o
auditoría formal.
