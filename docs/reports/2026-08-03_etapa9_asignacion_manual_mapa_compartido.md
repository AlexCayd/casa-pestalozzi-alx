# Etapa 9 — Asignación y reasignación manual de reservaciones en el mapa compartido

## 1. Resumen ejecutivo

La etapa queda implementada en el mapa operativo existente. El modo de Reservaciones ahora permite consultar la fecha, filtrar el listado, seleccionar una reservación confirmada, seleccionar combinaciones arbitrarias de mesas reservables, validar conflictos y guardar una asignación o reasignación atómica.

También se implementó `Dejar pendiente de asignación` para reservaciones administrativas confirmadas sin ticket abierto. La decisión de avance es **Sí, con condiciones**: el backend y el flujo transaccional están verificados, pero falta cerrar la validación visual con Apache/credenciales funcionales y resolver bloqueos baseline antes de iniciar Etapa 10.

No se modificó el esquema, no se agregaron estados, no se modificó la landing ni el flujo OTP, y no se creó commit.

## 2. Fuente de verdad

Se revisaron completamente:

- `reservaciones_fuente_de_verdad.md`.
- `docs/reports/2026-08-03_etapa3_contrato_canonico_pos_reservaciones.md`.
- `docs/reports/2026-08-03_etapa5_nucleo_horarios_ocupacion_asignacion_disponibilidad.md`.
- `docs/reports/2026-08-03_etapa7_5_estabilizacion_cruzada_publica.md`.
- `docs/reports/2026-08-03_etapa8_reconstruccion_administrativa_reservaciones.md`.
- Reportes visuales y operativos relacionados con el mapa compartido, apertura multimesa, inicio de servicio, tolerancia, no-show y estados canónicos.

La implementación conserva las reglas públicas estrictas y utiliza el contrato `pos-reservacion.v1` como fuente de lectura compartida.

## 3. Auditoría inicial

- POS carga el mapa desde `views/punto-de-venta/partials/pos-workspace.php` y utiliza los componentes compartidos de `src/js/operation/`.
- Reservaciones carga el mismo mapa desde `views/operation/partials/map.php`, con el shell operativo compartido y contexto explícito `operacion-reservaciones`.
- La consulta operativa existente es `GET /admin/api/reservations/operation`.
- Las mutaciones principales son `POST /admin/api/reservations/operation/assign-tables`, `POST /admin/api/reservations/operation/clear-tables`, comentarios y estados.
- La escritura de `reservacion_mesas` permanece en `ReservacionMesa` y la coordinación de asignación en `AsignacionMesasService`.
- El estado físico proviene de `TicketMesa`, `MesaEstadoService` y `OcupacionMesasService`; no se creó una fuente paralela.
- La lógica compartida de mesas está en `map-visual.js`, `table-state-adapter.js`, `shell.js` y `reservation-card.js`.
- La duplicación encontrada fue una ruta HTML heredada de asignación; ahora también delega en la fachada administrativa transaccional, sin crear otro flujo de dominio.

## 4. Arquitectura final

Se agregó `Services\ReservacionMapaAdministrativaService` como fachada del mapa administrativo. Recibe la lectura canónica, agrega una proyección administrativa aditiva y coordina las escrituras.

El modo se expresa en el componente mediante `contexto: 'operacion-reservaciones'`; POS conserva su contexto y comportamiento actual. La proyección agrega `reservaciones_admin`, `en_lista_terminal`, `asignacion_pendiente`, contacto visible, origen, nota breve y capacidad de liberar asignación.

El origen sólo se solicita como contexto administrativo en `PosReservacionQueryService`; la serialización POS por defecto no cambia su forma canónica.

## 5. Panel lateral

El panel conserva scroll independiente y muestra hora, nombre, comensales, contacto o `Sin contacto`, estado, mesas asignadas, nota breve y origen. Las tarjetas no exponen OTP, tokens, hashes ni el comentario administrativo completo.

Se agregaron filtros mínimos de cliente: `Todas`, `Pendientes de asignar`, `Con mesas`, `En curso` y búsqueda por nombre/contacto. Las reservaciones terminales pueden permanecer visibles como contexto de consulta, pero no son editables.

La asignación pendiente se deriva de `estado = confirmada` y ausencia de filas en `reservacion_mesas`; no se inventa una mesa ni se infiere desde tickets.

## 6. Selección manual

La selección inicia con exactamente los IDs actuales de `reservacion_mesas`. El operador puede conservar, remover, agregar o reemplazar mesas sin depender de pares o tríos canónicos. La barra de asignación muestra comensales, capacidad seleccionada, diferencia y acciones de guardar, cancelar y liberar cuando corresponde.

La selección no persiste automáticamente. El refresco conserva la reservación seleccionada y la escritura vuelve a validar el contexto recibido.

## 7. Elegibilidad

El backend sólo acepta mesas activas, reservables, de tipo `mesa` y con capacidad positiva. Las mesas no existentes, inactivas, no reservables, de barra, caja, llevar u otros tipos quedan fuera de `Mesa::reservablesParaActualizar()`.

Los conflictos con otra reservación confirmada superpuesta, holds vigentes, estado no editable o ticket vinculado a la misma reservación son duros. La interfaz no es la fuente de seguridad: el backend vuelve a validar todos los IDs.

## 8. Tickets abiertos

La ocupación física se consulta desde `ticket_mesas`. La mesa con ticket abierto mantiene fondo rojo; una selección administrativa agregada conserva la selección como indicador secundario sin ocultar la ocupación física.

Se distinguen:

- Liberación proyectada: warning `DEPENDE_LIBERACION_PROYECTADA` y confirmación explícita.
- Ticket abierto en la mesa seleccionada: warning `CONFLICTO_TICKET_ABIERTO`, IDs de tickets y token de conflicto.
- Ticket abierto vinculado a la misma reservación: bloqueo duro `RESERVACION_NO_EDITABLE`.

La excepción administrativa requiere códigos explícitos, IDs de ticket y token vigente. No cierra tickets, no modifica `ticket_mesas`, no promete disponibilidad física y registra la dependencia de liberación en la asignación.

## 9. Persistencia

Toda escritura del mapa pasa por una transacción. El orden aplicado es:

1. Sesión administrativa y CSRF en el controlador.
2. `HorarioConfigLock`.
3. `FechaOperacionLock`.
4. Transacción MySQL.
5. Reservación `FOR UPDATE`.
6. Validación de estado, versión, fecha, hora y mesas actuales.
7. Mesas seleccionadas bloqueadas en orden ascendente.
8. Recalculo de ocupación y warnings.
9. `ReservacionMesa::reemplazarAsignacion()` dentro de la misma transacción.
10. Commit o rollback completo.

Para liberar una asignación se bloquean también las mesas actuales con `Mesa::bloquearPorIds()`, lo que permite limpiar una relación histórica aun si la mesa dejó de ser reservable.

## 10. Reasignación

La reasignación reemplaza el conjunto anterior por el nuevo en una sola transacción. No hay `delete + commit + insert`; un error conserva la asignación anterior mediante rollback.

La versión se deriva de `updated_at/created_at` y de los IDs actuales. Si otro operador cambia la asignación, la segunda solicitud recibe `VERSION_DESACTUALIZADA` y el frontend refresca antes de reintentar.

La liberación exige `LIBERAR_ASIGNACION_ACTUAL`, conserva el estado `confirmada`, elimina todas las relaciones planificadas y muestra de nuevo `Pendiente de asignar mesas`.

## 11. Reservaciones públicas

Una reservación pública confirmada puede reasignarse explícitamente si no tiene ticket abierto y supera las mismas validaciones de ocupación. No puede quedar sin mesas desde esta operación: `LIBERACION_NO_AUTORIZADA` bloquea el intento.

La asignación manual del mapa no se aplica a la landing, al OTP ni a la asignación automática pública. El algoritmo público de grupos estrictos permanece intacto.

## 12. POS

El inicio de servicio continúa usando `PuntoVentaReservacionService` y valida las mesas vigentes. Una reservación sin mesas no puede iniciar. Una reservación en curso o con ticket vinculado no puede reasignarse desde el mapa.

La apertura de ticket continúa escribiendo `ticket_mesas`; la reasignación planificada no modifica esas filas. La prueba `pos_reservacion_contrato.php` pasó y el esquema `pos-reservacion.v1` permanece sin cambio para sus consumidores por defecto.

## 13. Contratos administrativos

Lectura:

```text
GET /admin/api/reservations/operation?fecha=YYYY-MM-DD&hora=HH:MM
```

Asignación/reasignación:

```text
POST /admin/api/reservations/operation/assign-tables
reservation_id, fecha, hora, version_esperada, mesa_ids_actuales[], mesa_ids[], confirmaciones[]
```

Liberación:

```text
POST /admin/api/reservations/operation/clear-tables
reservation_id, fecha, hora, version_esperada, mesa_ids_actuales[], confirmaciones[]=LIBERAR_ASIGNACION_ACTUAL
```

Las confirmaciones son códigos explícitos; no existe un booleano genérico de override.

## 14. Reutilización visual

Se conserva el único mapa físico, sus coordenadas, zoom, scroll, header, toolbar, panel, responsive, alert overlay y refresco. POS y Reservaciones comparten `MapaVisual`, `MesaEstadoAdapter`, tarjetas y estados visuales.

Los colores canónicos se mantienen: verde disponible, amarillo selección manual, azul advertencia/próxima, rojo ocupación física y neutro no reservable. No se agregaron letras dentro de las mesas ni un segundo sistema de coordenadas.

## 15. Pruebas funcionales

`tests/php/etapa9_mapa_manual.php` pasó **25/25** aserciones, incluyendo:

- Asignación administrativa sin mesas.
- Combinación arbitraria `[2, 3]`.
- Capacidad exacta e insuficiente con confirmación.
- Warning de `SIN_CONTACTO`.
- Conflicto con otra confirmada y hold vigente.
- Liberación proyectada y ticket abierto.
- Conservación de `ticket_mesas`.
- Bloqueo de ticket vinculado.
- Liberación administrativa explícita.
- Reasignación pública permitida y liberación pública bloqueada.
- Rechazo por versión obsoleta.
- Proyección administrativa del mapa.

## 16. Pruebas de concurrencia

Se agregó `tests/php/etapa9_concurrencia.php` con workers PHP independientes, conexiones MySQL independientes, barrera de arranque, fixtures exclusivos y limpieza garantizada. El caso verifica dos asignaciones simultáneas de la misma mesa: una gana y la otra recibe `MESA_OCUPADA`; quedó una sola relación durante la verificación.

La instalación limpia ejecutó esta suite con resultado correcto. La suite cruzada previa `etapa7_5_instalacion_limpia.php` también pasó **35/35**, cubriendo carreras pública/POS/expiración/cancelación/modificación. Aún no existe una matriz multiproceso específica para cada combinación Etapa 9 contra inicio POS, liberación, creación pública y cancelación; queda como riesgo pendiente.

## 17. Instalación limpia

`tests/php/etapa9_instalacion_limpia.php` creó una base temporal usando `database/ddl.sql` y `database/dml.sql`, ejecutó el mapa manual y la carrera multiproceso, y eliminó la base al finalizar.

Resultado final: `ok=true`, mapa manual **25/25**, concurrencia correcta, `ddl=true`, `dml=true`, `dropped=true`.

## 18. Compatibilidad pública

No se modificaron landing, OTP, `ReservacionPublicaService`, grupos estrictos ni holds. Las pruebas cruzadas existentes pasaron y el test de contrato POS confirmó que la serialización pública/operativa por defecto no recibe el campo administrativo `origen`.

La compatibilidad pública queda condicionada únicamente a la ejecución futura de la matriz completa de asignación administrativa contra carreras públicas, no a un cambio observado en el algoritmo público.

## 19. Compatibilidad administrativa

`tests/php/etapa8_administrativa.php` pasó **19/19**. La alta administrativa sin mesas, el indicador pendiente, la persistencia de mesas y la separación entre capacidad estimada y asignación automática permanecen funcionando.

## 20. Compatibilidad POS

`php tests/php/pos_reservacion_contrato.php` pasó. La ruta integrada `pos_reservacion_integrado.php`, ejecutada sobre `casa_pestalozzi_etapa4_test`, completó sus flujos principales, pero conservó un fallo baseline en `R10 ticket rojo con advertencias azules`; el detalle corresponde a una expectativa previa de estados visuales, no a la asignación manual de Etapa 9.

## 21. Build

El build relacionado pasó:

```text
node_modules\\.bin\\gulp.cmd operationCss adminMapJs adminReservationOperationJs
```

Se generaron los assets del mapa compartido, CSS operativo y bundle administrativo de Reservaciones.

El build global continúa bloqueado por el problema previamente documentado:

```text
EPERM: operation not permitted, open C:\xampp\htdocs\casa-pestalozzi\public\build\js\admin\area.js
```

No se eliminaron tareas ni se modificaron archivos ajenos para ocultar el bloqueo. El problema requiere liberar el handle/proceso externo.

## 22. Validación visual

La auditoría estática confirma reutilización de layout, colores, modal consolidado, alert overlay y estilos desktop/móvil definidos. El bundle específico compila y el JavaScript pasa `node --check`.

No fue posible completar una validación visual real en escritorio/móvil/temas: el navegador local devolvió `ERR_CONNECTION_REFUSED`, Apache no quedó escuchando en el puerto 80 y el log muestra además credenciales MySQL de XAMPP no válidas para la base activa. Por ello no se afirma que la consola de navegador, Network o render final estén validados.

## 23. Archivos modificados

- `services/ReservacionMapaAdministrativaService.php`: proyección, locks, asignación y liberación administrativas.
- `services/AsignacionMesasService.php`: modo mapa, warnings, ticket override y validaciones.
- `services/PosReservacionQueryService.php` y `services/PosReservacionSerializer.php`: contexto administrativo opcional sin alterar el contrato POS por defecto.
- `models/Mesa.php`: bloqueo de mesas actuales para liberar relaciones.
- `controllers/ReservacionOperacionController.php` y `public/index.php`: endpoints, CSRF, respuestas y fallback legado.
- `views/operation/reservations/index.php` y `_filters.php`: barra de asignación, modal consolidado y filtros.
- `src/js/admin/reservations/operation.js`: selección, filtros, advertencias, guardado, liberación y refresco.
- `src/js/operation/table-state-adapter.js` y `reservation-card.js`: estado físico superpuesto y contexto administrativo.
- `src/scss/operation/_map-shell.scss`, `_reservation-list.scss` y `_toolbar.scss`: colores, metadatos y filtros.
- `tests/php/etapa9_mapa_manual.php`, `etapa9_concurrencia.php`, `etapa9_concurrencia_worker.php` y `etapa9_instalacion_limpia.php`: pruebas funcionales, carrera e instalación limpia.
- Assets compilados relacionados en `public/build/` y `assets/css/`.
- Este reporte.

No se modificaron `database/ddl.sql`, `database/dml.sql` ni fuentes públicas/OTP.

## 24. Código heredado

- **Conservado:** mapa, shell, visualizador, adapter, consulta canónica y servicios POS.
- **Adaptado:** controlador de operación, servicio de asignación manual, ruta HTML heredada y tarjetas del listado.
- **No eliminado:** ningún componente con consumidor activo conocido; no se creó una implementación duplicada que justificara una eliminación segura.

## 25. Limitaciones

- La validación visual end-to-end no se pudo ejecutar por Apache/credenciales locales.
- El build global sigue bloqueado por `EPERM` en `area.js`.
- La suite nueva de concurrencia cubre la misma mesa entre dos asignaciones; la matriz completa contra POS, landing, cancelación y liberación aún no está automatizada.
- El test integrado POS conserva un caso baseline fallido de estados visuales.
- No se implementaron drag-and-drop, editor de coordenadas, unión física, historial avanzado, múltiples pisos ni auditoría avanzada.
- No se realizó una auditoría ARIA general, de acuerdo con el alcance de la etapa.

## 26. Riesgos pendientes

1. **Alto:** resolver el `EPERM` del proceso que bloquea `public/build/js/admin/area.js` antes de declarar verde el build global.
2. **Alto:** corregir o aislar el fallo baseline `R10 ticket rojo con advertencias azules` del test integrado POS.
3. **Medio:** ejecutar la validación visual con Apache, credenciales MySQL correctas y viewport desktop/móvil.
4. **Medio:** ampliar la suite multiproceso a reasignación contra inicio POS, liberación contra inicio POS, alta pública y cancelación.
5. **Bajo:** revisar el backlog ARIA y microcopys fuera del alcance de Etapa 9.

## 27. Decisión de avance

### ¿La asignación manual en el mapa compartido puede considerarse estable?

**Sí, con condiciones.** El dominio, la proyección, la persistencia transaccional, el rollback, los warnings, la liberación y la carrera de misma mesa están comprobados; el contrato POS por defecto permanece estable. La condición es completar la validación visual y cerrar los riesgos baseline indicados.

### ¿Es seguro iniciar la integración operativa completa de la Etapa 10?

**No.** No debe iniciarse automáticamente. Primero deben resolverse el `EPERM` del build global, el fallo baseline del contrato integrado POS, la validación visual local y la matriz multiproceso completa de las interacciones Etapa 9/POS/pública.
