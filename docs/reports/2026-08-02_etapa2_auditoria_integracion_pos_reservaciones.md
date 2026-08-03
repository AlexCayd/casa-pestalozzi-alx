# Etapa 2 — Auditoría y definición del contrato POS–reservaciones

**Fecha:** 2026-08-02  
**Repositorio:** C:\xampp\htdocs\casa-pestalozzi  
**Alcance:** lectura y documentación del flujo actual entre reservaciones, mapa operativo y POS.  
**Resultado:** no se modificaron lógica, endpoints, esquema, datos, landing ni código de aplicación.

## 1. Resumen ejecutivo

El flujo actual de reservaciones hacia mapa y POS existe y tiene bases transaccionales sólidas, pero no hay un contrato único consumido por todas las capas.

La ruta POS GET /api/punto-de-venta?fecha=YYYY-MM-DD lee mesas, reservaciones del día y tickets abiertos. El backend también calcula mesas_estado mediante MesaEstadoService, pero src/js/modules/punto-de-venta.js no consume esa decisión: vuelve a resolver ventanas temporales, influencia en disponibilidad, bloqueo de mesas, títulos y advertencias. La operación administrativa GET /admin/api/reservations/operation sí consume mesas_estado. Por eso dos pantallas pueden interpretar distinto el mismo dominio.

El inicio de servicio está bien protegido en PuntoVentaReservacionService: locks, transacción, reservación y mesas bloqueadas, validación de ticket/mesero/asignación/conflictos, inserción de tickets y ticket_mesas, cambio a en_curso y actualización de status_changed_at antes de commit. El cierre de ticket actualiza ticket y reservación completada en la misma transacción. No se observó borrado físico de reservacion_mesas ni ticket_mesas para liberar mesas.

Riesgos principales:

1. **Crítico:** el POS ignora el estado de mesa que ya calcula el backend.
2. **Crítico:** la operación administrativa permite iniciar desde llego en su UI, mientras comenzar() exige confirmada al crear el servicio.
3. **Alto:** una reservación confirmada con tolerancia vencida sigue influyendo hasta el no-show manual.
4. **Alto:** reservacion_mesas y ticket_mesas son relaciones físicas separadas, mientras el frontend mantiene reglas duplicadas.
5. **Medio:** existen reglas temporales paralelas para ventanas, liberación proyectada y contexto de mesa.

La Etapa 3 debe iniciar aprobando un contrato canónico backend con capacidades explícitas. No se recomienda eliminar archivos en esta etapa.

## 2. Alcance, método y árbol de trabajo

Se revisaron Router.php, public/index.php, controladores, servicios, modelos, SQL, vistas y módulos JS relacionados con:

- lectura de mesas, reservaciones, tickets y meseros;
- mapa operativo y mapa POS;
- ventanas, tolerancia y no-show;
- inicio, cierre y liberación;
- relaciones reservaciones–reservacion_mesas y tickets–ticket_mesas;
- landing pública;
- duplicaciones y payloads.

El working tree ya tenía cambios de la etapa anterior en CSS, JS, controladores, servicios, vistas y public/index.php. Fueron preservados. El único archivo nuevo de esta auditoría es este reporte. No se ejecutaron mutaciones contra la base de datos ni pruebas con datos reales.

## 3. Flujo real observado

~~~mermaid
flowchart TD
    DB["MySQL: reservaciones, reservacion_mesas, mesas, tickets, ticket_mesas"]
    POSGET["GET /api/punto-de-venta"]
    OPGET["GET /admin/api/reservations/operation"]
    POSJS["POS JavaScript"]
    OPJS["Operación JavaScript"]
    VIG["ReservacionVigenciaService"]
    MESA["MesaEstadoService"]
    OCUP["OcupacionMesasService y TicketMesa"]
    MAP["MapaVisual y MesaEstadoAdapter"]
    MUT["PuntoVentaReservacionService"]
    TICKET["tickets + ticket_mesas + estado de reservación"]

    DB --> POSGET
    DB --> OPGET
    POSGET --> VIG
    POSGET --> MESA
    OPGET --> VIG
    OPGET --> MESA
    OPGET --> OCUP
    POSGET --> POSJS
    OPGET --> OPJS
    POSJS --> MAP
    OPJS --> MAP
    POSJS --> MUT
    MUT --> TICKET
    MESA --> MAP
    VIG --> MESA
    OCUP --> MESA
~~~

El backend ya resuelve parte de la decisión, pero después de la respuesta el POS reconstruye reglas con datos crudos. El mapa visual sí es compartido; la decisión de negocio aún no.

## 4. Inventario de componentes

| Capa | Componentes | Responsabilidad |
|---|---|---|
| Esquema | database/ddl.sql | Estados, pivots, FKs, índices y unicidad de tickets. |
| Routing | Router.php, public/index.php | Entrada HTTP, sesión y despacho. |
| POS | controllers/PuntoVentaController.php | Lectura POS, serialización y wrappers de acciones. |
| Operación | controllers/ReservacionOperacionController.php | Lectura administrativa, ocupación, alertas y acciones. |
| Tiempo | services/ReservacionConfig.php, services/ReservacionVigenciaService.php | Zona horaria, ventanas, tolerancia y capacidades. |
| Visual backend | services/MesaEstadoService.php | Estado de mesa, precedencia, modifiers y títulos. |
| Ocupación | services/OcupacionMesasService.php, models/TicketMesa.php, models/ReservacionMesa.php | Ocupación física/proyectada y relaciones N:M. |
| Asignación | services/AsignacionMesasService.php | Capacidad, conflictos y asignación. |
| Mutación | services/PuntoVentaReservacionService.php | Inicio, no-show, cancelación, walk-in y cierre. |
| Mapa frontend | src/js/operation/map-visual.js, src/js/operation/table-state-adapter.js | Render y traducción visual compartidos. |
| POS frontend | src/js/modules/punto-de-venta.js | Polling, selección, modal y tickets. |
| Operación frontend | src/js/admin/reservations/operation.js | Acciones administrativas y mapa operativo. |
| Landing | views/home/_reserva.php, form.js, reservation-access.js, reservation-time-picker.js | Flujo público. |

## 5. Ruta de lectura de reservaciones y mesas

### POS principal

PuntoVentaController::api():

1. Normaliza fecha con HorarioReservacionService::fechaSeguraGet.
2. Lee mesas activas.
3. Lee reservaciones con LEFT JOIN a reservacion_mesas y mesas, agrupando todos los IDs y nombres.
4. No filtra estados en SQL; también puede incluir estados finales. Cada registro recibe clasificación y el flag influye_disponibilidad.
5. Lee tickets abiertos con TicketMesa::abiertosParaMapa(). Esa consulta es física y no se limita a la fecha del mapa.
6. Calcula mesas_estado con MesaEstadoService::normalizarMesas().
7. Devuelve mesas, reservaciones, tickets, meseros, config.temporal y actualizado_en.

El LEFT JOIN hace visibles las reservaciones sin mesas asignadas. El inicio de servicio las rechaza porque requiere al menos una mesa.

### Ruta POS alternativa

GET /api/punto-de-venta/reservaciones usa PuntoVentaReservacionService::listar(). Esa consulta filtra estados operativos confirmada, llego y en_curso, agrega ticket abierto y después aplica filtrarPendientesOperacion() para conservar el bloque anterior, actual y posterior del día.

Es un segundo contrato de lectura. El mapa principal usa la primera ruta, de modo que ambas pueden diferir en campos y universo de estados.

### Operación

GET /admin/api/reservations/operation usa ReservacionOperacionController y agrega horarios efectivos, ocupación por horario, alertas, capacidad, ocupación física, asignación y mesas_estado. Consume MesaEstadoAdapter y MapaVisual como autoridad visual backend.

## 6. Estados incluidos y casos límite

Estados persistidos: pendiente_verificacion, confirmada, llego, en_curso, expirada, completada, cancelada y no_show.

Estados de lista operativa: confirmada, llego y en_curso. Estados finales: expirada, completada, cancelada y no_show.

Casos observados:

- Sin mesas: aparece en las lecturas; iniciar servicio la rechaza.
- Varias mesas: el pivot conserva orden; el ticket copia todos los IDs a ticket_mesas.
- Varias reservaciones futuras: se pueden devolver todas; algunos consumidores escogen la más próxima. El flujo walk-in calcula varias advertencias en los siguientes 60 minutos.
- Ticket abierto: proviene de ticket_mesas y tiene precedencia física sobre la reservación.
- Fecha diferente: reservaciones se filtran por fecha; tickets abiertos se consideran ocupación real global.

El endpoint principal POS puede transportar estados finales, mientras listar() los excluye. El contrato definitivo debe marcar explícitamente si el registro es operativo, visual o histórico.

## 7. Fuente de tiempo y ventanas

PHP usa America/Mexico_City mediante ReservacionConfig. MySQL fija el offset -06:00. El backend envía actualizado_en. El POS sincroniza un offset con ese valor y usa Intl.DateTimeFormat con la zona operativa; tiene fallback a la hora local del navegador.

ReservacionVigenciaService usa segundos exactos y ceil para minutos:

| Regla actual | Condición | Contrato propuesto |
|---|---|---|
| future | más de 60 minutos | futura |
| warning | más de 30 y hasta 60 | 30_60 |
| service_window | de 30 minutos antes hasta la hora | 0_30 |
| tolerance | desde la hora hasta antes de 15 minutos después | tolerancia |
| overdue | 15 minutos después o más | tolerancia_vencida |
| estado en_curso | servicio persistido en curso | en_curso |

Configuración observada: advertencia 60 minutos, bloqueo preventivo 30, tolerancia 15, duración estimada de ticket 90, margen de preparación 15 y seguridad mínima 30.

El POS vuelve a implementar resolverVentanaOperativaReservacion, estadoMesa, reservacionProximaMesa, reservacionesProximasParaTicket y tituloMesaMapa. El fetch POS ocurre aproximadamente cada 30 segundos; la actualización local de estado, cada 60. El slider recalcula localmente. El backend usa fecha/hora completas; el frontend tiene redondeo visual a intervalos de 30 minutos. Estas decisiones deben quedar en backend.

## 8. Inicio de servicio y ticket

El POS llama:

~~~text
POST /api/punto-de-venta/reservaciones/comenzar
{ reservacion_id, mesero_id }
~~~

El servicio:

- toma locks de horario y fecha;
- abre transacción y bloquea la reservación con FOR UPDATE;
- hace idempotencia si ya está en_curso y encuentra ticket abierto;
- actualmente exige confirmada para crear un servicio nuevo;
- exige fecha operativa actual y puede_iniciar_servicio;
- rechaza ticket abierto, valida mesero activo y exige mesas;
- bloquea mesas en orden estable y verifica conflicto de horario;
- inserta tickets y todas las filas ticket_mesas;
- cambia reservación a en_curso, registra arrived_at si falta, status_changed_at y auditoría;
- hace commit y devuelve ticket_id y mesa_ids.

Es atómico respecto de ticket, pivots y estado.

Divergencia: operation.js habilita start-service para confirmada y llego; la ruta administrativa /admin/api/reservations/operation/status delega en ReservacionService::ejecutarAccionOperativa(), que llama al mismo comenzar(). Debe decidirse si llego puede iniciar directamente. No debe quedar habilitado sólo en UI.

## 9. No-show

La condición de servidor es:

- estado confirmada;
- tolerancia de 15 minutos vencida;
- sin arrived_at ni evidencia de llegada;
- sin ticket abierto;
- elegible_no_show verdadero.

Ruta:

~~~text
POST /api/punto-de-venta/reservaciones/no-show
{ reservacion_id }
~~~

El frontend muestra el botón si estado local es confirmada, ventana local overdue, no_show_disponible o elegible_no_show es verdadero y no existe ticket_id. El servidor vuelve a validar; los parámetros de override actuales no saltan la regla.

La mutación cambia estado a no_show, actualiza status_changed_at y auditoría. No borra reservacion_mesas. No se observó cron/job de no-show. Una confirmada vencida puede seguir influyendo en disponibilidad hasta la acción manual; esa dependencia debe expresarse mediante capacidades explícitas.

## 10. Tickets, pivots y liberación

Relaciones:

~~~text
reservaciones.id 1 ── N reservacion_mesas N ── 1 mesas.id
reservaciones.id 1 ── N tickets.reservacion_id
tickets.id       1 ── N ticket_mesas N ── 1 mesas.id
~~~

El esquema tiene UNIQUE reservacion_id en tickets. Los walk-ins usan reservacion_id NULL. La condición de ticket abierto es estado abierto y closed_at NULL.

La reservación puede tener ticket_id, pero no debe contarse como una segunda ocupación. La precedencia correcta es ticket físico, reservación que influye y después libre/no reservable. Backend la aplica; POS la repite.

POST /api/cerrar-ticket valida productos y pago y delega al servicio. La transacción bloquea ticket, cambia a cerrado, registra cierre/pagos/token y, si pertenece a reservación en_curso, cambia la reservación a completada con completed_at y status_changed_at. No se borran ticket_mesas; se libera porque los tickets cerrados ya no satisfacen el predicado de ocupación.

No se observó endpoint de cancelación del ticket completo. Sólo se cancelan ticket_items, lo que no libera mesas. Una futura cancelación de ticket deberá ser auditable y usar estado, no DELETE.

El contrato debe separar mesa_ids de la asignación de reservación y ticket_mesa_ids de la ocupación física real, porque no se debe asumir que ambas listas siempre coinciden en históricos o reasignaciones.

## 11. Contrato actual

POS entrega:

- mesas: ID, número, nombre, tipo, capacidad, coordenadas y reservable;
- reservaciones: datos de cliente, fecha/hora, comensales, notas, estado, timestamps, ticket_id, mesa_ids, mesas, no_show_disponible, influye_disponibilidad y clasificación temporal;
- tickets: ID, nombre, comensales, hora_apertura, reservacion_id, mesero y mesa_ids;
- meseros;
- mesas_estado;
- config.temporal;
- actualizado_en.

Operación agrega estado de operación, horarios, reservaciones operativas, asignación, capacidad, alertas, ocupación física y transiciones.

Hay información suficiente, pero mezclada: datos crudos, flags derivados, ventanas internas y estado de mapa. El POS no consume mesas_estado como autoridad.

## 12. Contrato canónico propuesto

La siguiente estructura es una propuesta para aprobar en Etapa 3; no se implementó:

~~~json
{
  "schema_version": "pos-reservacion.v1",
  "reservacion_id": 123,
  "estado": "confirmada",
  "fecha": "2026-08-02",
  "hora": "20:00",
  "nombre": "Cliente",
  "contacto_tipo": "telefono",
  "contacto": "***",
  "comensales": 4,
  "nota": "",
  "mesa_ids": [4, 5],
  "mesas": [
    { "id": 4, "numero": 4, "nombre": "Mesa 4" },
    { "id": 5, "numero": 5, "nombre": "Mesa 5" }
  ],
  "ticket_id": null,
  "ticket_abierto": false,
  "ticket_mesa_ids": [],
  "ventana_operativa": "30_60",
  "minutos_para_reservacion": 48,
  "minutos_retraso": 0,
  "puede_iniciar_servicio": false,
  "puede_registrar_ausencia": false,
  "bloquea_walk_ins": false,
  "muestra_advertencia": true,
  "motivo_operativo": "reservacion_proxima"
}
~~~

Reglas:

- ventana_operativa sólo permite futura, 30_60, 0_30, tolerancia, tolerancia_vencida y en_curso;
- backend calcula ventana, minutos, puede_iniciar_servicio, puede_registrar_ausencia, bloquea_walk_ins, muestra_advertencia y motivo_operativo;
- frontend no deduce inicio, no-show, bloqueo o warning por minutos, estado visual o colores;
- ticket_id, ticket_abierto y ticket_mesa_ids deben ser coherentes;
- mesa_ids/mesas son asignación planificada; ticket_mesa_ids es ocupación efectiva;
- timestamps y auditoría pueden vivir en un bloque operativo separado;
- future, warning, service_window y overdue no deben ser nombres del contrato POS definitivo.

MesaEstadoAdapter puede seguir traduciendo el resultado a las cinco clases visuales actuales: libre, ocupada, reservacion-proxima, seleccionada y no-utilizable. Esa traducción debe ser visual, no una nueva decisión de negocio.

## 13. Clasificación de archivos y métodos

| Clasificación | Elementos | Decisión |
|---|---|---|
| Conservar | database/ddl.sql | Mantener persistencia y relaciones; cualquier cambio requiere migración separada. |
| Conservar | models/TicketMesa.php, models/ReservacionMesa.php | Mantener pivots y consulta física N:M. |
| Conservar | services/AsignacionMesasService.php, services/OcupacionMesasService.php | Mantener capacidad, locks, conflictos y ocupación. |
| Conservar | src/js/operation/map-visual.js, table-state-adapter.js | Mantener mapa visual y traducción, sin reglas nuevas. |
| Conservar y adaptar | ReservacionConfig.php, ReservacionVigenciaService.php | Convertir clasificación temporal a ventana canónica. |
| Conservar y adaptar | MesaEstadoService.php | Mantener precedencia y títulos; consumir contrato único. |
| Conservar y adaptar | PuntoVentaReservacionService.php | Mantener transacciones; revisar inicio desde llego, contexto y warnings múltiples. |
| Conservar y adaptar | PuntoVentaController.php y ReservacionOperacionController.php | Mantener wrappers; usar lector/serializador común. |
| Conservar y adaptar | punto-de-venta.js y operation.js | Mantener interacción; retirar deducciones de negocio. |
| Conservar y adaptar | vistas POS y operación | Mantener estructura visual; ajustar después del contrato. |
| Conservar visual / adaptar negocio después | views/home/* y módulos visuales | No modificar landing en esta etapa. |
| Conservar y adaptar después | form.js, reservation-access.js, reservation-time-picker.js y servicios públicos | Mantener separación de disponibilidad pública. |
| Sustituir por lector canónico | Consulta/serialización de PuntoVentaController::api() y PuntoVentaReservacionService::listar() | Unificar fuente; no implica borrar archivos completos. |
| Sustituir por capacidades | resolverVentanaOperativaReservacion, estadoMesa, reservacionProximaMesa, tituloMesaMapa y warnings POS | Conservar presentación, retirar autorización/bloqueo local. |
| Revisar antes de eliminar | contextoMesa(), reservaContexto() y registrarLlegada() | Tienen reglas o dependencias activas; no eliminar sin grafo. |
| Eliminar | Ninguno identificado | No hay evidencia suficiente para eliminar código en Etapa 2. |

## 14. Duplicaciones y diferencias

| Regla | Ubicaciones | Riesgo |
|---|---|---|
| Ventana temporal | ReservacionVigenciaService y punto-de-venta.js | Acción distinta por clocks/fallbacks PHP y JS. |
| Estado de mesa | MesaEstadoService, estadoMesa() POS y adapter | POS y operación pueden mostrar estados diferentes. |
| Título/ARIA | tituloAccesible() backend y tituloMesaMapa() POS | Mensajes y elección de reservación próxima pueden diferir. |
| Lectura | PuntoVentaController::api() y PuntoVentaReservacionService::listar() | Estados/campos diferentes en endpoints paralelos. |
| Liberación proyectada | TicketMesa/OcupacionMesasService, contextoMesa() y POS | Duración/margen interpretados en más de un sitio. |
| Reservaciones futuras | MesaEstadoService, POS y proximasReservaciones() | Mapa escoge una; walk-in puede evaluar varias. |
| Acciones | Flags backend y condiciones del modal | Botón visible aunque servidor rechace. |

MapaVisual no es la duplicación problemática: se conserva como componente visual. La duplicación está en las decisiones previas.

## 15. Landing: visual contra negocio

Visual a preservar:

- views/home/index.php y parciales visuales;
- hero, navegación, galería, menú, maridaje, firma, chef, panadería, eventos y ubicación;
- assets, SCSS y módulos sin dependencia de operación.

Negocio público:

- views/home/_reserva.php monta el formulario y usa /api/reservaciones/disponibilidad;
- form.js consulta horarios, retención/creación y OTP;
- reservation-access.js consulta, modifica y cancela por contacto verificado;
- reservation-time-picker.js sólo consume horarios;
- HomeController, ReservacionController, DisponibilidadReservacionService y ReservacionPublicaService sostienen el flujo.

La landing no consume APIs POS ni de operación. La disponibilidad pública es orientativa y el servidor revalida bajo lock al mutar. No se debe reconstruir esa disponibilidad dentro del POS ni conectar la landing al contrato interno sin una decisión separada de seguridad y producto.

## 16. Riesgos priorizados

| Severidad | Riesgo | Evidencia e impacto |
|---|---|---|
| Crítico | POS ignora mesas_estado | Backend lo emite; POS recalcula. Puede diferir color, bloqueo, warning y acción. |
| Crítico | Inicio no unificado | UI administrativa admite llego; comenzar() exige confirmada. Puede aparecer un botón que falla. |
| Alto | No-show manual | Confirmada vencida sigue influenciando hasta la acción; puede retener mesas. |
| Alto | Listas físicas separadas | reservacion_mesas y ticket_mesas pueden divergir en históricos/reasignaciones. |
| Alto | Múltiples futuras | Algunos consumidores muestran una advertencia aunque backend evalúe varias. |
| Alto | Proyección duplicada | Liberación de ticket se calcula en varios sitios. |
| Medio | Dos lecturas POS | Cambios de contrato pueden aplicarse a una ruta y omitirse en otra. |
| Medio | Estados finales en payload principal | Un consumidor futuro puede tratar historial como operativo. |
| Medio | Fallback de reloj local | El navegador puede mostrar una ventana que el servidor rechaza. |
| Medio | Sin cancelación de ticket completo | No existe aún un contrato de liberación para esa acción. |

## 17. Recomendación concreta para Etapa 3

1. Aprobar el contrato canónico y la semántica exacta de llego, tolerancia, bloqueo, múltiples reservaciones y ticket_mesa_ids.
2. Crear un DTO/serializador común con ReservacionVigenciaService y MesaEstadoService para POS y operación.
3. Decidir si llego puede iniciar directamente y alinear UI, servicio y respuesta.
4. Expresar estado final, no-show manual, ticket abierto/cerrado, asignación y ocupación física como datos/capacidades explícitos.
5. Reducir POS a presentación: conservar selección, modal, tickets y polling; retirar cálculos de ventana y autorización.
6. Conservar ticket_mesas como historial y liberar por estado, nunca por DELETE.
7. Crear fixtures de paridad para las seis ventanas, sin mesas, varias mesas, múltiples futuras, ticket abierto y ticket cerrado.
8. Mantener landing fuera del primer cambio y tratar después su contrato público como flujo separado.

## 18. Validación realizada

Se hicieron:

- búsquedas de rutas, endpoints, métodos y referencias con rg;
- lectura de consultas, serializadores, reglas temporales y transacciones;
- php -l sin errores en controladores, servicios y modelos centrales;
- node --check sin errores en JS POS, operación, formulario y selector de horario;
- git diff --check sin errores de whitespace. Git sólo emitió advertencias informativas de conversión LF/CRLF en archivos ya modificados.

No se hicieron:

- mutaciones de base de datos;
- apertura/cierre de tickets o cambios de estado;
- pruebas end-to-end con sesión autenticada;
- cambios de landing, endpoints, build o esquema.

## 19. Limitaciones y supuestos

- No se contó con credenciales de personal ni dataset controlado para observar tickets reales en navegador.
- El working tree tenía cambios previos; se trataron como estado actual y no se reescribieron.
- registrarLlegada() no se marca eliminable porque la operación administrativa lo usa indirectamente.
- El contrato canónico es una recomendación futura, no una modificación aplicada.
- La equivalencia de hora PHP/MySQL debe confirmarse en el entorno real antes de confiar en datos históricos.

## 20. Conclusión

La integración tiene bases sólidas en persistencia, locks, transacciones y mapa visual compartido. El pendiente principal no es reconstruir la landing ni reemplazar el modelo de tickets; es consolidar la decisión operativa.

La frontera recomendada es: backend calcula vigencia, ocupación, capacidades y relaciones físicas; frontend presenta y ejecuta acciones idempotentes. Mientras POS recalcule reglas ya presentes en backend, permanecerá el riesgo de divergencia en ventanas, bloqueos, no-show y tickets. La Etapa 3 debe comenzar con contrato canónico y fixtures de paridad, antes de retirar duplicaciones.

