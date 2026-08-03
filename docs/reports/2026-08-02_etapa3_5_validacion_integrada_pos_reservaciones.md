# Etapa 3.5 — Validación integrada del contrato POS–reservaciones

Fecha: 2026-08-02  
Repositorio: `C:\xampp\htdocs\casa-pestalozzi`

## 1. Resumen ejecutivo

Resultado general: aprobado con condiciones para iniciar la reconstrucción controlada del esquema.

Se validó contra MySQL real el contrato `pos-reservacion.v1`, las seis ventanas, la separación entre `mesa_ids` y `ticket_mesa_ids`, los estados visuales, la paridad contractual de las tres rutas, el inicio de servicio para una y varias mesas, no-show, cierre de tickets, idempotencia y el conflicto de apertura sobre una mesa compartida.

También se validaron las interfaces reales mediante sesiones autenticadas locales: POS con usuario de piso y operación administrativa con usuario demo. No se modificó la lógica de negocio de Etapa 3; sólo se añadió el runner integrado y este reporte.

## 2. Fuente de verdad

Antes de probar se revisaron completamente:

- `reservaciones_fuente_de_verdad.md`.
- `docs/reports/2026-08-02_etapa3_contrato_canonico_pos_reservaciones.md`.

La validación siguió las reglas ya implementadas: intervalos de 90 minutos, hold de 15 minutos, tolerancia de 15 minutos, aviso a 60 minutos, inicio dentro de 30 minutos, tickets como ocupación física y `en_curso` sin doble conteo de reservación.

## 3. Entorno de prueba

- Base: MySQL local `casa-pestalozzi`, servicio `MySQL97`.
- Bootstrap: `includes/app.php`, Composer, Dotenv, `includes/.env` y `ActiveRecord::setDB()`.
- PHP CLI: 8.2.12.
- Zona horaria: `America/Mexico_City`.
- Servidor HTTP temporal: PHP built-in server en `127.0.0.1:8088` y `127.0.0.1:8089`.
- Sesiones: directorios temporales aislados dentro del workspace; fueron eliminados al finalizar.
- POS: sesión autenticada con usuario demo de piso documentado en `database/dml.sql`.
- Administración: sesión autenticada con usuario demo documentado en `database/dml.sql`.
- No se guardaron credenciales en archivos ni se incluyeron en este reporte.

## 4. Corrección de conexión CLI

La prueba anterior fallaba porque se ejecutaba el servicio sin cargar el bootstrap; por eso `ActiveRecord::getDB()` era `null`.

La solución fue crear `tests/php/pos_reservacion_integrado.php` con `require` a `includes/app.php`. El runner usa la misma conexión, variables de entorno, autoload, zona horaria y constantes que la aplicación.

Durante la primera ejecución se corrigieron únicamente problemas del runner:

- Se usaba usuario `0` en mutaciones, incompatible con la FK de auditoría `last_modified_by`; ahora se selecciona un usuario activo real de la base.
- Los controladores tipados recibían `null`; ahora se invocan con una instancia real de `MVC\Router`.
- La aserción de tickets usaba `ticket_mesa_ids` en el objeto de ticket, cuando ese objeto expone `mesa_ids`; la aserción fue alineada al contrato.

No fue necesario modificar el código de dominio por estos hallazgos.

## 5. Fixtures creados

Los fixtures usaron el prefijo identificable `ETAPA3_5_IT_...`, se probaron contra la base real y se limpiaron al terminar.

| Caso | Estado inicial | Horario relativo | Mesas | Ticket | Resultado |
|---|---|---:|---|---|---|
| R1 | `confirmada` | +120 min | una mesa | no | `futura`, sin advertencia ni bloqueo |
| R2 | `confirmada` | +45 min | una mesa | no | `30_60`, advertencia sin bloqueo |
| R3 / R7 / R9 | `confirmada` | +10 min | dos mesas | creado durante la prueba | `0_30`; ticket único multimesa |
| R4 | `confirmada` | −5 min | una mesa | creado durante la prueba | `tolerancia`; inicio permitido |
| R5 | `confirmada` | −20 min | una mesa | no | `tolerancia_vencida`; no-show permitido |
| R6 / R12 | `en_curso` | −45 min | mesas asignadas distintas | abierto en otras dos mesas | ocupación física sólo por `ticket_mesa_ids` |
| R8 | `confirmada` | +90 min | sin mesas | no | visible con motivo `sin_mesas`; no inicia servicio |
| R10 | ticket walk-in | +45 min | ticket en una mesa | abierto | rojo con advertencias de R2/R11 |
| R11 | `confirmada` | +40 min | misma mesa de R2 | no | orden cronológico controlador |
| R13 | `llego` legado | +20 min | sin mesas | no | identificado como legado, sin nuevas capacidades |

## 6. Validación del contrato

Todas las fixtures contractuales pasaron. Se verificaron valores, no sólo presencia de claves:

- `schema_version = pos-reservacion.v1`.
- `server_time` y `timezone` presentes y coherentes.
- `reservacion_id` coincide con el alias `id`.
- Estado, fecha, hora, contacto, comensales, nota y mesas.
- `ticket_id`, `ticket_abierto` y `ticket_mesa_ids`.
- Las seis ventanas: `futura`, `30_60`, `0_30`, `tolerancia`, `tolerancia_vencida`, `en_curso`.
- Capacidades `puede_iniciar_servicio` y `puede_registrar_ausencia`.
- `bloquea_walk_ins`, `muestra_advertencia`, `influye_disponibilidad` y `motivo_operativo`.
- R8 conserva asignación vacía y motivo `sin_mesas`.
- R6 conserva separadas las mesas planificadas `[7,8]` y las físicas `[9,10]`.

## 7. Paridad entre rutas

Se consultaron los datos reales mediante:

- `GET /api/punto-de-venta`.
- `GET /api/punto-de-venta/reservaciones`.
- `GET /admin/api/reservations/operation`.

La comparación automática ignoró metadatos propios de cada envoltura y comparó los campos contractuales:

| Ruta | Resultado | Reservaciones observadas |
|---|---|---:|
| POS mapa | OK | 18 |
| POS reservaciones | OK; lista operativa filtrada | 1 |
| Operación administrativa | OK | 18 |

No hubo divergencias en identidad, estado, ventana, minutos, mesas, ticket, capacidades, bloqueo, advertencia, disponibilidad ni motivo operativo.

## 8. Validación visual POS

Resultado: aprobado para los estados observados.

- Sesión autenticada real en `/punto-de-venta`.
- Mapa único y leyenda de cinco estados.
- Mesas disponibles verdes y tickets abiertos rojos.
- Selección válida: Mesa 1 pasó a `mesa-pin--seleccionada`, con modificador `seleccion_actual` y acento amarillo.
- Cancelación: Mesa 1 volvió a `mesa-pin--libre` sin crear ticket.
- `title` y `aria-label` describieron estado, ticket y servicio.
- No se observaron letras ni badges operativos.
- Barras, caja y para llevar conservaron su comportamiento operativo.
- La comprobación directa de esta sesión se realizó sobre tema oscuro; el reporte de estabilización visual anterior documenta también tema claro, maximización y responsive.
- La consola del navegador no reportó errores ni advertencias en POS.

## 9. Validación administrativa

Resultado: aprobado.

- Sesión autenticada real de administración en `/admin/reservations/operation`.
- La fecha persistida `30/11/2026` cargó reservaciones reales y el mismo mapa base.
- La lista lateral mostró reservaciones sin mesas, de una mesa, de dos mesas y de tres mesas.
- La mesa asignada mostró el estado bloqueado y el detalle lateral conservó identidad, hora, personas, capacidad y diferencia.
- Tickets abiertos conservaron precedencia roja y contexto de reservación próxima.
- Barras, caja y para llevar aparecieron neutros/no reservables en este modo.
- La leyenda fue la misma que en POS.
- No se observaron errores ni advertencias en la consola administrativa.

## 10. Inicio de servicio

Se probaron R3 con dos mesas y R4 con una mesa mediante `PuntoVentaReservacionService::comenzar()`.

- Ambos crearon un solo ticket.
- Se crearon todas las filas necesarias en `ticket_mesas`.
- La reservación cambió a `en_curso` y luego a `completada` al cerrar el ticket.
- La segunda solicitud de R3 devolvió `ok=true`, `idempotente=true` y el mismo ticket.
- No se escribió `llego` ni se requirió `arrived_at`.

## 11. Registro de ausencia

R5 se probó en `tolerancia_vencida`.

- Primera solicitud: `ok=true`, transición a `no_show`.
- Segunda solicitud: `ok=true`, `idempotente=true`.
- La reservación dejó de influir en disponibilidad.
- Las filas históricas de `reservacion_mesas` no fueron eliminadas.

## 12. Cierre del ticket

Se cerraron los tickets de R3 y R4 con el servicio real de cierre.

- Ambos cierres fueron exitosos.
- El ticket multimesa mantuvo dos filas históricas en `ticket_mesas`.
- El ticket de una mesa mantuvo una fila histórica.
- La reservación pasó a `completada`.
- La ocupación abierta dejó de aparecer en el mapa.
- No se contó nuevamente `reservacion_mesas` como ocupación física.

## 13. Concurrencia

Se ejecutó una simulación de apertura concurrente sobre la misma mesa:

- Primera apertura walk-in: exitosa.
- Segunda apertura sobre la misma mesa: rechazada con conflicto operativo.
- El primer ticket fue cerrado y limpiado.

También se probó el inicio repetido de una misma reservación y el no-show repetido; ambos fueron idempotentes. No se ejecutó una carrera real de tres procesos simultáneos ni la carrera exacta entre inicio y no-show en dos workers independientes.

## 14. Estado heredado `llego`

R13 fue creado de forma controlada con estado `llego` y `arrived_at`.

- El serializador lo identificó con `estado_legado=true`.
- No habilitó inicio de servicio ni ausencia.
- `confirmada` no tiene transición nueva hacia `llego`.
- No se agregaron botones ni consumidores frontend para ese estado.
- No se modificaron la columna `arrived_at` ni el esquema histórico.

## 15. Componentes reutilizados

Se conservaron y reutilizaron:

- Shell y mapa operativo compartido.
- Leyenda común de estados.
- Sidebar/drawer de reservaciones.
- Panel de detalle operativo.
- Modales y alertas existentes.
- Variables y clases visuales ya presentes para claro/oscuro.

No se creó un segundo mapa, un motor de disponibilidad ni un componente visual paralelo.

## 16. Archivos modificados

- `tests/php/pos_reservacion_integrado.php`: runner integrado con bootstrap real, fixtures, paridad, mutaciones e higiene de datos.
- `docs/reports/2026-08-02_etapa3_5_validacion_integrada_pos_reservaciones.md`: este reporte.

Los cambios de Etapa 3 permanecen en el workspace y no fueron reescritos. No se modificaron tablas, DDL, landing, motor público ni estilos durante esta etapa.

## 17. Pruebas ejecutadas

- `php tests/php/pos_reservacion_integrado.php` — OK contra MySQL real.
- `php tests/php/pos_reservacion_contrato.php` — OK.
- `php -l` sobre runner, serializador, lector, vigencia, estados, servicio POS y controladores — OK.
- `node --check` sobre fuentes POS/operación y bundles afectados — OK.
- `git diff --check` — OK; sólo avisos de conversión de fin de línea de Git.
- Búsqueda estática de `puede_confirmar_llegada`, `elegible_no_show`, `fechaHoraOperativa`, `relojMapa`, `service_window`, `overdue` y `tolerance` en los dos consumidores frontend — sin coincidencias.
- Servidor HTTP local y sesiones autenticadas — OK.
- Consola del navegador POS y administración — sin errores ni warnings.

## 18. Limitaciones

- No se ejecutó una carrera real con procesos simultáneos independientes; se cubrió la ruta de conflicto e idempotencia de forma secuencial/simulada.
- Tema claro, responsive móvil y maximización no se repitieron todos en esta sesión; el reporte de estabilización visual previo documenta esos casos para el mapa compartido.
- No se probó una sesión remota o un navegador externo; la validación HTTP se realizó con PHP local y el navegador integrado.
- No se modificó el estado legado ni se migró el esquema, por restricción explícita de la etapa.

## 19. Riesgos pendientes

1. Alto: antes de reconstruir el esquema se requiere respaldo verificable y ensayo sobre una base clonada.
2. Medio: ejecutar una prueba de carrera real entre workers para inicio/no-show y apertura multimesa.
3. Medio: repetir claro, oscuro, responsive y maximización con fixtures reservacionales vivos en el entorno objetivo.
4. Bajo: medir rendimiento del lector común con el volumen real de reservaciones y tickets.

## 20. Decisión de avance

**¿Es seguro iniciar la reconstrucción controlada del esquema?**

**Sí, con condiciones.**

El contrato integrado, la paridad de rutas, la ocupación física, las mutaciones principales y las interfaces autenticadas pasaron. La siguiente etapa puede iniciar únicamente con respaldo, base de pruebas o clon restaurable, plan de reversión y una validación adicional de concurrencia real. Esta decisión no autoriza todavía eliminar columnas históricas, migrar `llego`/`arrived_at` ni modificar la landing o el motor público.

No se realizaron commits.
