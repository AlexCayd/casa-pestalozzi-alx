# Etapa 5 — Reconstrucción del núcleo de horarios, ocupación, asignación y disponibilidad

**Fecha:** 2026-08-03  
**Repositorio:** C:\xampp\htdocs\casa-pestalozzi  
**Estado:** completada, sin commit

## 1. Resumen ejecutivo

Se reconstruyó el núcleo backend de la Etapa 5 en los bloques 5.1 a 5.5:

- horario efectivo por fecha, con prioridad de excepciones sobre horario semanal;
- ocupación física, proyectada y de reservaciones;
- asignación automática únicamente con grupos autorizados;
- disponibilidad interna y proyección pública binaria;
- validación integrada, instalación limpia y compatibilidad con POS.

La decisión es que el núcleo sí puede ser la fuente única de disponibilidad, con condiciones: la consulta pública ya proyecta una respuesta binaria y el backend quedó preparado para una futura reconexión controlada, pero la landing no se reconectó en esta etapa. La creación o modificación de una reservación debe continuar revalidando horario, ocupación y asignación dentro de su propia transacción.

No se modificaron landing, mapa, POS visual, CSS/SCSS, DDL, DML ni esquema. No se creó ningún commit.

## 2. Matriz de reglas y fuente de verdad

| Regla | Fuente | Implementación | Evidencia |
|---|---|---|---|
| Duración de reservación: 90 min | reservaciones_fuente_de_verdad.md | ReservacionConfig::DURACION_RESERVACION_MINUTOS | Caso de traslape y ventana |
| Anticipación mínima: 40 min | fuente de verdad | ReservacionConfig::ANTICIPACION_MINIMA_MINUTOS | Caso de hoy |
| Última reservación: cierre menos 90 min | fuente de verdad | HorarioReservacionService | Horarios candidatos |
| Hold vigente: hold_expires_at > ahora | fuente de verdad | OcupacionMesasService | Hold expirado y vigente |
| Estados que cuentan | fuente de verdad | pendiente vigente y confirmada | Casos de ocupación |
| Ticket físico abierto | fuente de verdad | TicketMesa::abiertosParaMapa + tickets | Ticket actual y ticket de otro día |
| Proyección de ticket | fuente de verdad | 90 min + retraso 0 | Caso ticket proyectado |
| Grupos autorizados | fuente de verdad | AsignacionMesasService | Individual, pareja, trío y manual |
| Respuesta pública binaria | fuente de verdad | DisponibilidadReservacionService::respuestaPublica | Casos de salida pública |

## 3. Arquitectura anterior y adaptación

Se conservaron los servicios existentes y se separaron sus responsabilidades sin crear una segunda arquitectura:

- HorarioOperacionService ya resolvía horario semanal y excepciones; se reutilizó como lector del horario efectivo.
- HorarioReservacionService concentra ahora fecha, horizonte, anticipación, cierre y candidatos.
- OcupacionMesasService concentra el cálculo de ocupación y diferencia fuente física de proyección.
- AsignacionMesasService concentra elegibilidad y combinaciones autorizadas.
- DisponibilidadReservacionService orquesta horario → ocupación → elegibilidad → asignación y proyecta la salida pública.
- ReservacionService conserva las fachadas existentes, pero delega horario y validación al núcleo para evitar duplicación.

Se dejaron fachadas y métodos heredados donde existen consumidores POS o administrativos. Los caminos POS que representan la operación física mantienen sus ventanas operativas históricas; no se mezclan con la regla canónica de disponibilidad de reservación.

## 4. Configuración central

La configuración canónica quedó documentada en services/ReservacionConfig.php:

| Parámetro | Valor |
|---|---:|
| Duración de reservación | 90 min |
| Duración estimada de ticket | 90 min |
| Retraso estimado de ticket | 0 min |
| Hold | 15 min |
| Anticipación mínima | 40 min |
| Última reservación antes del cierre | 90 min |
| Tolerancia de llegada | 15 min |
| Aviso de reservación próxima | 60 min |
| Límite de modificación | 30 min |
| Tolerancia de cancelación pública | 15 min |
| Máximo de reservaciones activas por contacto | 5 |
| Horizonte | 90 días, incluyendo hoy y el día límite |
| Bloqueo previo canónico de mesa | 0 min |
| Máximo de horarios alternativos | 5 |

MINUTOS_PREVIOS_BLOQUEO = 30 se conserva como compatibilidad del flujo operativo POS. El núcleo de reservaciones usa BLOQUEO_PREVIO_MESA_MINUTOS = 0, porque la fuente define la ocupación de reservación como el intervalo [inicio, inicio + 90) y no define un bloqueo previo adicional.

## 5. Bloque 5.1 — Horario efectivo

HorarioReservacionService::resolverFecha() valida formato, fecha pasada, horizonte y horario operativo. La resolución usa la siguiente prioridad:

1. excepción cerrada → día no operativo;
2. excepción special → usa únicamente sus horas;
3. horario semanal configurado → usa sus horas;
4. ausencia de configuración → horario_sin_configuracion;
5. horario inválido o invertido → no reservable, sin inventar una franja nocturna no representable por el esquema actual.

Los candidatos se generan en intervalos de 30 minutos hasta cierre - 90 min. Para hoy se excluyen los inicios anteriores a ahora + 40 min; para fechas futuras se devuelven todos los candidatos válidos. La respuesta interna conserva códigos estables y la salida pública sólo expone disponibilidad y motivo público.

## 6. Bloque 5.2 — Ocupación

OcupacionMesasService::evaluarHorario() maneja tres fuentes separadas:

- **reservación:** pendiente con hold vigente o confirmada, siempre que no tenga ticket abierto vinculado;
- **ticket físico:** ticket abierto con estado = abierto y closed_at IS NULL, leído desde ticket_mesas;
- **ticket proyectado:** para una fecha futura del mismo día, se libera después de 90 minutos más el retraso configurado.

Un ticket físico tiene prioridad sobre una reservación planificada. Una reservación vinculada a un ticket abierto no se cuenta dos veces. Un ticket abierto de otro día sigue siendo una ocupación física actual, pero no bloquea fechas futuras distintas. Para el momento actual o para una hora ya alcanzada, el ticket abierto continúa bloqueando aunque haya vencido su hora estimada.

La salida interna identifica hold, reservacion, ticket_abierto, ticket_proyectado, libre y no_reservable, además de IDs y capacidad. Esos detalles no se exponen en la proyección pública.

## 7. Bloque 5.3 — Asignación

La asignación automática sólo considera mesas reservables y activas:

- 1–4 personas: una mesa individual;
- 5–8 personas: parejas predefinidas;
- 8–12 personas: tríos predefinidos;
- más de 12: requiere_asignacion_manual.

No se generan agrupaciones arbitrarias. Las combinaciones se expresan con los números autorizados de la fuente de verdad. Si más de una combinación es válida, se priorizan menos mesas, menor exceso de capacidad y el orden estable de la configuración. La consulta pública no recibe IDs, capacidad ni detalles de ticket.

## 8. Bloque 5.4 — Disponibilidad

La entrada interna acepta fecha, hora y cantidad de personas; opcionalmente puede recibir un ID de reservación a excluir durante modificación. El orquestador valida primero el horario, después obtiene ocupación, filtra mesas elegibles y finalmente ejecuta la asignación.

La respuesta interna conserva codigo, motivo, mesa_ids, capacidad_total, capacidad_disponible, asignacion_automatica y fuentes de ocupación para administración y pruebas. La respuesta pública se reduce a:

~~~json
{"disponible": true}
~~~

o:

~~~json
{"disponible": false, "motivo": "..."}
~~~

El motivo interno de asignación manual se proyecta públicamente como requiere_contactar_restaurante. No se expone información operativa sensible ni capacidad numérica.

## 9. Tiempo, zona horaria y pruebas deterministas

La aplicación usa America/Mexico_City. Los servicios aceptan un reloj opcional (DateTimeImmutable) en los puntos de resolución necesarios; las pruebas fijan RESERVATION_TEST_NOW para evitar depender del reloj real.

El runner tests/php/etapa5_nucleo.php usa la fecha 2026-11-01 12:00:00, crea fixtures dentro de una transacción y ejecuta ROLLBACK. Por ello, los casos de hold, hoy, excepción, ticket actual, proyección y asignación no dejan datos permanentes.

## 10. Consultas, transacciones y rendimiento

La lectura de disponibilidad es orientativa y no sustituye la revalidación transaccional al crear o modificar. evaluarHorario(..., bloquear = true) conserva el punto de entrada para lectura con bloqueo cuando un flujo de escritura lo requiera.

En la corrida local del núcleo se observaron estos tiempos:

| Bloque | Tiempo |
|---|---:|
| Horario | 0.45 ms |
| Ocupación | 0.95 ms |
| Disponibilidad | 1.41 ms |

Son mediciones de una base local y no un benchmark de producción. La consulta pública de todos los horarios evalúa cada candidato por separado; el riesgo actual es bajo con el volumen de la instalación, pero debe optimizarse si el catálogo o tráfico crece.

## 11. Validación ejecutada

Resultados:

- php tests/php/etapa5_nucleo.php — **PASS**, 59/59 aserciones; fixtures revertidos.
- php tests/php/etapa5_instalacion_limpia.php — **PASS**, DDL, DML y smoke de servicios; base temporal eliminada.
- php tests/php/pos_reservacion_contrato.php — **PASS**.
- php tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test — **PASS**, contrato, mutaciones, idempotencia, simulación de concurrencia y paridad.
- php tests/php/etapa4_estructura.php --db=casa_pestalozzi_etapa4_test — **PASS**.
- Lint PHP en servicios, modelos, controladores, includes y tests — **PASS**, 79 archivos.
- Verificación sintáctica Node en JS fuente y compilado — **PASS**, 53 archivos.
- git diff --check — sin errores de whitespace; sólo avisos de normalización LF/CRLF.

## 12. Instalación limpia

El runner creó una base temporal propia, ejecutó database/ddl.sql y database/dml.sql, cargó el catálogo inicial y ejecutó un smoke del núcleo. Los conteos fueron:

~~~text
mesas: 16
horarios_operacion: 7
reservaciones: 32
tickets: 44
ticket_mesas: 46
~~~

La base temporal se eliminó en el bloque finally; no se alteró la base activa ni los archivos de esquema.

## 13. Compatibilidad POS

Los contratos POS–reservaciones y POS integrado siguen pasando. No se modificó pos-reservacion.v1, ni el flujo visual, ni las tablas canónicas. La separación importante es:

- disponibilidad de reservación: intervalo canónico de 90 minutos y hold vigente;
- operación POS: conserva sus ventanas de preparación y bloqueo físico históricas donde todavía son requeridas.

La ocupación física se sigue leyendo desde ticket_mesas, por lo que la nueva consulta no reemplaza ni duplica el origen físico del POS.

## 14. Archivos modificados y responsabilidad

- services/ReservacionConfig.php: configuración canónica y aliases compatibles.
- services/HorarioOperacionService.php: marca explícita de horario configurado.
- services/HorarioReservacionService.php: resolución de fecha, candidatos y validación.
- services/ReservacionService.php: delegación de fachadas al horario canónico.
- services/OcupacionMesasService.php: ocupación física, planificada y proyectada.
- services/AsignacionMesasService.php: combinaciones autorizadas y selección determinista.
- services/DisponibilidadReservacionService.php: orquestación y proyección pública.
- tests/php/etapa5_nucleo.php: pruebas deterministas de los bloques 5.1–5.4.
- tests/php/etapa5_instalacion_limpia.php: prueba de instalación limpia.
- docs/backlog/accesibilidad_landing_pendiente.md: backlog ARIA diferido.

No se modificaron vistas, JavaScript, SCSS, CSS, mapas, landing, database/ddl.sql, database/dml.sql ni modelos de esquema.

## 15. Código heredado y duplicaciones

No se borró código funcional de forma destructiva. Las duplicaciones de horario en ReservacionService fueron sustituidas por delegación. Las fachadas de administración y los métodos históricos que pueden ser consumidores externos se conservaron para compatibilidad.

Las referencias a la ventana POS de 30 minutos y a la estimación operativa de tickets no se interpretan como reglas nuevas del núcleo; quedan identificadas como compatibilidad del flujo físico. La nueva constante canónica de bloqueo previo es 0 minutos.

## 16. Limitaciones y supuestos

- El esquema actual usa campos TIME; no representa de forma inequívoca un horario operativo que cruza medianoche. Esos casos se reportan como no configurados hasta definir una extensión de datos.
- No se reconectó la landing ni se hizo una prueba visual autenticada en esta etapa, por alcance explícito.
- La consulta pública no entrega capacidad, IDs de mesa ni detalles de tickets.
- La disponibilidad no reserva por sí misma; el flujo de escritura debe revalidar dentro de transacción.
- La asignación automática no resuelve grupos mayores de 12 personas.
- No se cambió la semántica de estados POS ni se usa llego como estado válido del núcleo.

## 17. Backlog de accesibilidad

Se creó docs/backlog/accesibilidad_landing_pendiente.md con tres hallazgos observados en la auditoría previa:

- menú: sincronizar aria-expanded, aria-controls, foco y navegación de teclado;
- lightbox: completar diálogo modal, cierre, foco y navegación por teclado;
- galería: hacer controles e imágenes operables y perceptibles para teclado y lector de pantalla.

El backlog queda diferido a una etapa específica y no se considera una falla de Etapa 5.

## 18. Riesgos residuales

| Riesgo | Severidad | Tratamiento |
|---|---|---|
| Evaluación por candidato en consulta pública | Media | Medir con volumen real y optimizar sólo si el crecimiento lo justifica |
| Futuras integraciones podrían consumir detalles internos | Media | Mantener el adaptador público binario y pruebas de contrato |
| Ventanas históricas POS distintas del núcleo | Baja | Mantener separación documentada y cubrir cualquier nuevo consumidor |
| Falta de validación visual de landing en esta etapa | Baja | Ejecutar junto con la reconexión futura |

## 19. Decisiones de cierre

1. El núcleo de horarios, ocupación, asignación y disponibilidad queda centralizado en servicios existentes.
2. La disponibilidad pública queda limitada a {disponible, motivo}.
3. La ocupación distingue hold, reservación, ticket físico y ticket proyectado, sin doble conteo de reservación vinculada a ticket.
4. Sólo se usan combinaciones autorizadas; los casos fuera del rango automático requieren contacto/manual.
5. La landing permanece sin reconectar en Etapa 5.
6. La reconexión futura es viable con condiciones: debe consumir la proyección binaria, agregar pruebas de contrato extremo a extremo y conservar la revalidación transaccional en escritura.
7. No se requieren cambios de esquema para cerrar Etapa 5.
