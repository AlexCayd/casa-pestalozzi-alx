# Etapa 6 — Reconexión del flujo público de creación y confirmación de reservaciones

**Fecha:** 2026-08-03  
**Repositorio:** `C:/xampp/htdocs/casa-pestalozzi`  
**Estado:** completada, sin commit  
**Alcance:** creación pública, disponibilidad, retención, OTP, confirmación y expiración. Modificación y cancelación quedan fuera.

## 1. Resumen ejecutivo

Se reconectó el flujo público existente de cuatro pasos con el núcleo de Etapa 5:

1. selección de fecha, comensales y horario;
2. datos de contacto;
3. notas;
4. revisión, creación de retención y confirmación OTP.

La escritura vuelve a validar horario, ocupación y asignación dentro de una transacción protegida por locks de configuración, contacto y fecha. La retención nace como `pendiente_verificacion`, dura 15 minutos, conserva la asignación de mesas y sólo pasa a `confirmada` después de consumir un OTP válido.

La decisión de cierre es: **la creación pública puede considerarse estable: Sí, con condiciones**. El núcleo transaccional, idempotencia, expiración, límites, OTP y concurrencia quedaron cubiertos; la entrega real de correo/WhatsApp sigue deliberadamente deshabilitada y la verificación visual integrada no pudo ejecutarse porque el navegador aislado no alcanzó el servidor local.

No se modificó el esquema, no se tocó POS/mapa, no se implementó modificación o cancelación pública y no se creó ningún commit.

## 2. Fuentes de verdad y reportes revisados

Se revisaron la fuente `reservaciones_fuente_de_verdad.md` y los reportes:

- `docs/reports/2026-08-03_etapa5_nucleo_horarios_ocupacion_asignacion_disponibilidad.md`;
- `docs/reports/2026-08-03_etapa4_5_cutover_esquema_reservaciones.md`;
- `docs/reports/2026-08-02_etapa3_contrato_canonico_pos_reservaciones.md`;
- `docs/backlog/accesibilidad_landing_pendiente.md`.

Las reglas aplicadas son: zona `America/Mexico_City`, anticipación mínima de 40 minutos, horizonte de hoy a hoy + 90 días inclusive, última reservación a cierre - 90 minutos, ocupación `[inicio, inicio + 90)`, hold de 15 minutos, OTP de 5 minutos, máximo de 5 activas por contacto, máximo público de 12 personas y máximo de 5 alternativas.

## 3. Auditoría inicial

La base ya contenía una primera reconexión parcial: rutas públicas, servicio transaccional, OTP y el asistente existían, pero había huecos de cierre:

- la consulta pública no proyectaba correctamente alternativas y todavía podía devolver motivos internos;
- el request token no distinguía siempre un reintento idéntico de un payload distinto;
- el frontend no rotaba token al vencer la retención;
- el límite de activas no incluía correctamente holds pendientes y podía contar la retención actual al confirmar;
- el mensaje decía cinco minutos aunque la regla canónica es 15;
- faltaban pruebas deterministas de creación, OTP, rollback lógico, expiración y concurrencia.

## 4. Reconexión del flujo público

Se conservaron las rutas públicas existentes:

- `GET /api/reservaciones/disponibilidad`;
- `POST /api/reservaciones/retencion`;
- `POST /api/reservaciones/contacto/codigo` para reenvío asociado a retención;
- `POST /api/reservaciones/contacto/verificar` para confirmar la retención.

El formulario utiliza el mismo DOM montado durante el flujo. El asistente mantiene el estado de visita, contacto, notas y revisión; al crear una retención oculta el formulario y muestra el bloque OTP; al confirmar muestra la confirmación pública sin mesas, capacidad ni razones internas.

## 5. Disponibilidad pública

`DisponibilidadReservacionService` acepta fecha, personas y opcionalmente hora solicitada. La consulta evalúa el horario efectivo, ocupación, elegibilidad y asignación del núcleo de Etapa 5.

La respuesta pública conserva únicamente campos de presentación:

```json
{
  "ok": true,
  "fecha": "2026-11-09",
  "abierto": true,
  "horarios": [{"hora": "14:00", "disponible": true}],
  "disponible": false,
  "motivo": "sin_disponibilidad",
  "alternativas": ["13:00", "15:00"]
}
```

No se exponen `mesa_ids`, capacidad, tickets, reservaciones, fuentes de ocupación, `codigo` interno ni `reservable`. Más de 12 personas se proyecta como `requiere_contactar_restaurante`.

## 6. Alternativas y presentación

Cuando la hora solicitada no está disponible, el backend entrega como máximo cinco horarios alternativos. El selector existente conserva la presentación actual y muestra el mensaje con las alternativas; no se creó un segundo selector ni se introdujo CSS nuevo.

El calendario público ahora limita también el máximo visual a hoy + 90 días. El backend continúa siendo la autoridad y vuelve a rechazar fechas fuera de horizonte.

## 7. Request token e idempotencia

El token se genera inicialmente en servidor, se conserva para reintentos del mismo payload y se rota en el frontend cuando cambia la operación o vence una retención. El backend valida que el token pertenezca al flujo `landing` y que coincidan nombre, tipo/contacto normalizado, fecha, hora, personas y nota.

- mismo token y mismo payload: respuesta idempotente, sin segunda reservación;
- mismo token y payload distinto: `REQUEST_TOKEN_CONFLICTO`;
- token de una retención vencida: `RETENCION_EXPIRADA`, sin reutilizar la retención anterior.

## 8. Duplicados y límite de activas

El duplicado se busca por contacto normalizado + fecha + hora, dentro del lock de contacto y fecha. La condición de límite cuenta:

- holds `pendiente_verificacion` cuyo `hold_expires_at` aún es futuro;
- reservaciones `confirmada` vigentes o operativamente activas.

Al confirmar, la propia retención se excluye del conteo; esto permite confirmar correctamente la quinta reservación. La sexta operación se rechaza con `LIMITE_RESERVACIONES_ALCANZADO`.

## 9. Retención

La creación pública realiza en una sola transacción:

1. lock global de configuración, contacto y fecha;
2. comprobación de token y duplicado;
3. límite de activas;
4. revalidación de disponibilidad con asignación pública;
5. inserción de reservación `pendiente_verificacion`, `origen=landing` y `hold_expires_at = ahora + 15 min`;
6. inserción atómica de `reservacion_mesas`;
7. generación y hash del OTP;
8. commit.

Si alguna parte falla, la transacción revierte reservación, asignación y OTP. Las mesas no se eliminan al expirar; sólo dejan de influir en disponibilidad por la condición temporal.

## 10. OTP y privacidad

El OTP se genera con `random_int`, se guarda exclusivamente con `password_hash`, expira a los 5 minutos, registra intentos y se invalida al alcanzar cinco fallos. El OTP es efectivo únicamente si el código es válido, el hold sigue vigente y la reservación permanece pendiente.

El proveedor actual es `DevelopmentContactNotificationProvider`: no hace envío externo y sólo muestra `preview_code` en desarrollo/testing cuando está habilitado por configuración. No se implementó correo real, WhatsApp ni mensajería externa en esta etapa.

## 11. Reenvío

El reenvío utiliza el mismo token de operación, contacto normalizado y reservación vinculada. Se conserva el límite de 60 segundos entre códigos, se invalidan códigos anteriores y no se permite reenvío después de expirar el hold.

## 12. Confirmación

`confirmarRetencion` bloquea la reservación y el OTP dentro de la misma transacción. Después de validar el código:

- consume el OTP;
- cambia `pendiente_verificacion` a `confirmada`;
- actualiza `estado_changed_at` con el reloj canónico;
- hace commit;
- crea la sesión temporal de contacto.

Una repetición posterior con el mismo token devuelve éxito idempotente porque la reservación ya está confirmada; no reutiliza el OTP ni cambia `estado_changed_at`.

## 13. Expiración

La interfaz muestra la cuenta regresiva del hold. Al llegar a cero:

- se detiene la verificación;
- se oculta el bloque OTP;
- se rota el request token;
- se conserva fecha y personas para volver a consultar;
- se limpia la hora anterior;
- se vuelve al primer paso y se reconsulta disponibilidad.

El backend también materializa holds vencidos como `expirada`, invalida sus OTP y conserva `reservacion_mesas`. La confirmación de una retención vencida no puede confirmar ni reasignar silenciosamente la operación anterior.

## 14. Seguridad HTTP y datos públicos

Las mutaciones del nuevo flujo incluyen el token CSRF de `ReservationClientSession`. El controlador exige CSRF en creación, reenvío y confirmación cuando existe `request_token`; el acceso administrativo heredado sin token conserva su flujo anterior.

La salida pública no contiene IDs de mesa, capacidad, tickets, motivos técnicos, hashes, OTP persistidos ni detalles de asignación. La normalización de correo y teléfono sigue centralizada en `ContactoService`.

## 15. Archivos funcionales modificados

- `controllers/ReservacionController.php`: CSRF, hora solicitada y proyección pública de disponibilidad;
- `models/Reservacion.php`: conteo de activas con exclusión opcional;
- `services/DisponibilidadReservacionService.php`: alternativas y adaptador seguro de consulta;
- `services/ReservacionPublicaService.php`: token por payload, locks ya existentes, hold de 15 minutos, límites, timestamps y expiración;
- `services/ReservacionVigenciaService.php`: inclusión de holds pendientes en el límite;
- `src/js/modules/form.js`: CSRF, token por operación, reconsulta al vencer, confirmación de errores y alternativas;
- `src/js/components/reservation-date-picker.js` y `views/components/reservations/date-picker.php`: horizonte máximo;
- `src/js/components/reservation-time-picker.js`: presentación de alternativas;
- `views/home/_reserva.php`: límite visual de fecha;
- bundles generados en `assets/js/` y `public/build/js/`.

## 16. Modificación y cancelación pública

No se implementó, reactivó ni amplió modificación o cancelación pública. Las rutas y código heredado existentes se dejaron fuera del flujo de Etapa 6 y no se usaron para validar la creación. No se alteraron `modificarPublica`, `cancelarPublica`, el panel de gestión ni sus contratos.

## 17. Esquema, DDL, DML y POS

No hubo cambios en `database/ddl.sql`, `database/dml.sql`, migraciones ni DML persistente. No se modificaron mapa, POS visual, contratos POS ni rutas administrativas. La instalación limpia y las pruebas POS posteriores confirman que la ocupación física sigue leyendo `ticket_mesas` y que no hay doble conteo por reservación vinculada a ticket.

## 18. Pruebas deterministas de Etapa 6

`tests/php/etapa6_publica.php` usa el reloj fijo `2026-11-01 12:00:00`, crea fixtures dentro de una transacción y revierte todo al terminar.

Resultado: **46/46 PASS**.

Se cubrieron disponibilidad pública segura, alternativas, grupo grande, creación atómica, asignación, hash OTP, mensaje de 15 minutos, idempotencia, conflicto de token, duplicado normalizado, confirmación, repetición idempotente, `estado_changed_at`, intentos erróneos, expiración OTP, límite de cinco confirmadas, límite de cinco holds pendientes, expiración de hold, conservación de mesas y job de expiración.

## 19. Concurrencia

`tests/php/etapa6_concurrencia.php` inicia dos procesos PHP independientes con el mismo contacto, fecha y hora, pero tokens distintos.

Resultado observado: **1 `RETENCION_CREADA` + 1 `RESERVACION_DUPLICADA`**, con limpieza exacta de los fixtures generados. Esto verifica que el lock de contacto/fecha y la reconsulta transaccional impiden duplicar el turno.

## 20. Regresiones y compatibilidad

- `php tests/php/etapa5_nucleo.php`: **PASS, 59/59**;
- `php tests/php/etapa5_instalacion_limpia.php`: **PASS**, base temporal creada, smoke ejecutado y base eliminada;
- `php tests/php/pos_reservacion_contrato.php`: **PASS**;
- `php tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`: **PASS**;
- `php tests/php/etapa4_estructura.php --db=casa_pestalozzi_etapa4_test`: **PASS**;
- lint PHP de archivos nuevos/modificados: **PASS**;
- `node --check` de los módulos JavaScript modificados: **PASS**;
- build `node node_modules/gulp/bin/gulp.js js`: **PASS**;
- `git diff --check`: sin errores de whitespace.

El repositorio no contiene actualmente `tests/js/reservation-form-state.test.js`, aunque aparece referenciado por `package.json`; por eso no se reporta como prueba ejecutada.

## 21. Verificación visual y limitaciones de entorno

Se intentó abrir el flujo en el navegador integrado. El entorno rechazó `localhost` con `ERR_CONNECTION_REFUSED`; después se levantó un servidor PHP temporal en Windows, que respondió correctamente a `Invoke-WebRequest` local y devolvió una consulta pública sin claves internas. El navegador integrado no pudo alcanzar ese servidor aislado y bloqueó también la IP local por política de seguridad.

Por tanto, la validación visual interactiva de calendario, selector, OTP y expiración queda **pendiente de ejecutarse en un navegador que tenga acceso al Apache/XAMPP local**. El servidor temporal se detuvo al finalizar la comprobación.

## 22. Riesgos residuales y backlog

| Riesgo | Severidad | Tratamiento |
|---|---|---|
| Proveedor real de correo/WhatsApp no conectado | Media | Mantener preview sólo en desarrollo/testing y conectar un proveedor autorizado en una etapa separada |
| Verificación visual bloqueada por aislamiento del navegador | Media | Repetir en XAMPP/Apache accesible desde el navegador integrado |
| Consulta evalúa cada candidato del horario | Baja | Medir con tráfico real antes de optimizar |
| Código heredado de modificación/cancelación existe | Media | No activarlo desde esta etapa; auditarlo en una etapa propia |
| Deuda ARIA de la landing | Baja | Mantener `docs/backlog/accesibilidad_landing_pendiente.md` sin corregirla aquí |

## 23. Decisiones de cierre y siguiente etapa

- **¿La creación pública puede considerarse estable?** **Sí, con condiciones**: lógica transaccional, idempotencia, OTP, expiración, límites y concurrencia están cubiertos; falta conectar un proveedor de notificación real y repetir la verificación visual en un entorno accesible.
- **¿Es seguro reconstruir la consulta pública?** **Sí, con condiciones**: consume la proyección segura y las alternativas acotadas; cualquier nuevo consumidor debe mantener el contrato sin detalles internos.
- **¿Es seguro reconstruir modificación pública?** **No en esta etapa**: permanece explícitamente fuera de alcance y no debe activarse por esta entrega.
- **¿Es seguro reconstruir cancelación pública?** **No en esta etapa**: requiere su propia auditoría, pruebas y decisión de alcance.

No se inicia una etapa posterior automáticamente. El siguiente trabajo autorizado debe ser una etapa separada para modificación/cancelación, notificación real y verificación visual, sin asumir que la creación habilita esas mutaciones.
