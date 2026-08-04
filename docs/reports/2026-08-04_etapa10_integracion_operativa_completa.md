# Etapa 10 — Integración operativa completa del ciclo de reservaciones

Fecha de validación: 2026-08-04 12:00, zona `America/Mexico_City`.

## 1. Resumen ejecutivo

Resultado: APROBADA con una limitación de observabilidad documentada. El ciclo público, administrativo y POS quedó validado sobre el contrato `pos-reservacion.v1`, sin cambios de esquema, estados o roles.

## 2. Fuente de verdad

Se leyó `reservaciones_fuente_de_verdad.md` completo y los reportes de Etapas 2, 3, 3.5, 4.5, 5, 7, 7.5, 8, 9, 9.5 y 9.6. El reporte de contrato referido con fecha 2026-08-03 no existe en el repositorio; se utilizó su versión presente `docs/reports/2026-08-02_etapa3_contrato_canonico_pos_reservaciones.md`.

## 3. Arquitectura integrada

Landing, administración, mapa y POS convergen en los servicios existentes. La asignación planificada vive en `reservacion_mesas`; la ocupación física operativa vive en `ticket_mesas`. El serializer y las consultas POS conservan `pos-reservacion.v1` y no duplican una reservación con su ticket.

## 4. Ciclo público

La prueba integrada confirmó creación de retención, asignación, OTP almacenado como hash, confirmación de un solo uso e idempotencia. El hold conserva 15 minutos y el reemplazo confirmado mantiene la reservación original hasta la confirmación.

## 5. Ciclo administrativo

Se validó alta administrativa sin mesas, advertencia explícita de asignación pendiente y asignación posterior desde el mapa compartido. La administración conserva capacidad flexible y no exige OTP para una alta administrativa.

## 6. Estados

Se mantuvieron únicamente `pendiente_verificacion`, `confirmada`, `en_curso`, `completada`, `cancelada`, `no_show`, `expirada` y `reemplazada`. No se agregó ni se escribió `llego`.

## 7. Inicio de servicio

`confirmada → en_curso` crea el ticket y todas sus filas `ticket_mesas` en una transacción. El inicio repetido devuelve el mismo ticket de forma idempotente. Una reservación `en_curso` sin ticket ya no se reporta como inicio exitoso.

## 8. Cierre de ticket

El cierre exige ticket abierto con al menos una mesa; para tickets vinculados exige reservación `en_curso`. Completa la reservación, registra pagos/token y conserva pivotes históricos. Se alineó el orden de locks con el mapa: horario global → fecha → reservación → ticket.

## 9. No show

Se validó el límite de tolerancia: antes de `hora + 15` no procede; después transiciona a `no_show`, conserva `reservacion_mesas` y libera capacidad. La acción administrativa usa el texto primario “Registrar que el cliente no se presentó”.

## 10. Cancelación

Cancelación pública y administrativa rechazan una reservación con ticket abierto. La cancelación administrativa y la de POS invalidan reemplazos pendientes dentro de la misma operación; no se borra historial.

## 11. Modificación pública

La validación cruzada previa confirmó que el reemplazo original permanece `confirmada` hasta OTP, que la confirmación aplica el cambio atómicamente y que un reemplazo expirado deja vigente al original.

## 12. Capacidad

La suite confirmó liberación después de cierre, no-show, cancelación y expiración de hold. El borde `hold_expires_at = ahora` se considera expirado. No se cuenta dos veces una reservación vinculada a un ticket físico.

## 13. Sincronización

El mapa administrativo conserva selección local durante refresh. El POS ahora aborta la consulta anterior, usa secuencia de respuesta y descarta datos de otra fecha para evitar sobrescritura obsoleta. Cada superficie conserva un solo polling activo.

## 14. Idempotencia

Se verificaron request tokens públicos, confirmación OTP repetida, inicio POS repetido y cierre repetido. Las respuestas repetidas no mutan `estado_changed_at` ni crean tickets/pivotes adicionales.

## 15. Concurrencia

`tests/php/etapa10_concurrencia.php` pasó seis escenarios multiproceso: cancelación contra inicio, no-show contra inicio, dos inicios, dos cierres, cierre contra reasignación y reconciliación posterior. Todos terminaron sin estado terminal con ticket abierto.

## 16. Invariantes

La reconciliación de sólo lectura terminó con cero tickets vinculados fuera de `en_curso`, cero tickets abiertos sin `ticket_mesas` y cero reservaciones terminales con ticket abierto. La instalación limpia reprodujo los mismos invariantes.

## 17. Validación visual

Se inspeccionaron la landing, el formulario público, el login administrativo y el acceso POS en el navegador local. La landing mantuvo el tratamiento oscuro/dorado y el formulario mostró fecha, comensales, hora, progreso y estado inicial. No se enviaron formularios ni se ejecutaron mutaciones reales. La validación de los cuatro viewports y del mapa oscuro queda respaldada por el reporte 9.6; en la revisión actual no hubo logs `warn`/`error`.

## 18. Build

`npm.cmd run test:js` pasó ambas suites. `npm.cmd run build` terminó exitosamente dos veces consecutivas después de estabilizar el task de Gulp para limpiar sólo las cuatro salidas JS/map afectadas antes de regenerarlas. Durante la validación se reprodujo el fallo intermitente de escritura de mapas; quedó mitigado en `gulpfile.js`.

## 19. Instalación limpia

`tests/php/etapa10_instalacion_limpia.php` creó una base temporal, instaló `database/ddl.sql` y `database/dml.sql`, ejecutó integración y concurrencia y eliminó la base temporal. Resultado: `ok=true`, `dropped=true`.

## 20. Regresiones

Pasaron las suites históricas de Etapas 5 a 9, incluidas instalaciones limpias, el contrato POS y `pos_reservacion_integrado.php`. También pasó la corrección de fixture de Etapa 6.2, que ahora busca un jueves libre para su excepción en vez de agotar los lunes disponibles.

## 21. Compatibilidad pública

Se conservaron las rutas, payloads, tokens, OTP, mensajes de hold, sesiones de contacto y cancelación pública. No se agregó email, WhatsApp ni un contrato alterno.

## 22. Compatibilidad administrativa

Se conservaron las confirmaciones de advertencia, asignación manual, versiones y CSRF administrativo. Los errores de CSRF, sesión, conflicto de mesa y concurrencia tienen mensajes recuperables en la interfaz.

## 23. Compatibilidad POS

Se conservaron inicio, walk-in, tickets multimesa, cierre con pagos, feedback, advertencias de reservaciones próximas y la consulta canónica. El controlador ya no expone errores SQL al cliente al calcular totales.

## 24. Archivos modificados

Cambios de esta etapa: `services/PuntoVentaReservacionService.php`, `services/ReservacionPublicaService.php`, `services/ReservacionAdministrativaService.php`, `controllers/PuntoVentaController.php`, `src/js/modules/punto-de-venta.js`, `src/js/admin/reservations/operation.js`, `views/admin/reservations/show.php`, `gulpfile.js`, el fixture de Etapa 6.2 y las cuatro suites nuevas en `tests/php/`. Se añadió este reporte. Los cambios previos del worktree se preservaron.

## 25. Limitaciones

No se usaron correos, WhatsApp ni credenciales reales. El servidor Apache no estaba escuchando; la revisión visual se hizo con el servidor PHP integrado temporal en `127.0.0.1:8001`. El panel Network del navegador no estuvo disponible, por lo que no se afirma una inspección de red detallada.

## 26. Riesgos pendientes

El build aún emite advertencias deprecadas de Dart Sass y Node sobre APIs heredadas; no afectan el resultado. La operación POS requiere una sesión de personal real para probar mutaciones visuales autenticadas, que deliberadamente no se ejecutaron en esta validación.

## 27. Decisión

Etapa 10 queda cerrada como APROBADA: ciclo operativo integrado, invariantes y concurrencia validados, regresiones verdes, instalación limpia reproducible y build exitoso. No se hicieron commits, no se modificó el esquema y no se inició Etapa 11.
