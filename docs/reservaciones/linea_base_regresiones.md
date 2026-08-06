# Línea base de regresiones — Etapa 1

**Estado del reporte:** PARCIAL: se completaron la congelación documental, el inventario y las validaciones estáticas; la validación dinámica quedó bloqueada por la ausencia de servidor HTTP, base de datos de prueba y navegador operativo dentro de esta ejecución.

**Fecha de captura:** 2026-08-05 17:34:48 -06:00  
**Zona horaria:** `America/Mexico_City` / `Central Standard Time (Mexico)`  
**Repositorio:** `C:\xampp\htdocs\casa-pestalozzi`  
**Rama:** `modulo-reservaciones`  
**HEAD:** `afd51c1c7f890670e603c1bed60a11eadca135be` (`afd51c1 merge`)  
**Estado inicial:** no limpio; ya existían `M reservaciones_fuente_de_verdad.md` y `?? plan_estabilizacion_reservaciones.md`. Esos cambios se conservaron sin sobrescribirlos.  
**Estado final de código:** sin cambios funcionales hechos por esta etapa; se agregaron únicamente los documentos de trazabilidad indicados al final.

## 1. Congelación normativa

La única fuente normativa vigente identificada en el árbol de trabajo es:

`reservaciones_fuente_de_verdad.md`

El contenido local está fechado 2026-08-05 y se declara como contrato funcional/técnico vigente. La solicitud usa el nombre `reservaciones_fuente_de_verdad_vigente.md`, pero ese archivo no existe en el árbol actual. No se renombró el archivo local porque estaba modificado antes de iniciar la etapa y renombrarlo habría creado una mutación innecesaria sobre el trabajo del usuario. Tampoco se creó un alias, para mantener una sola fuente de verdad.

El archivo histórico anterior queda señalado en [reservaciones_fuente_de_verdad_anterior.md](historial/reservaciones_fuente_de_verdad_anterior.md). Su contenido completo se conserva en Git en el commit `afd51c1c7f890670e603c1bed60a11eadca135be`; la copia histórica no se duplica para evitar dos documentos normativos competidores.

`plan_estabilizacion_reservaciones.md` se considera plan de trabajo no normativo. No se usó como contrato de comportamiento; solo se usó para localizar los hallazgos estáticos previos que debían revalidarse.

## 2. Identificación del entorno y línea base

| Dato | Resultado |
|---|---|
| PHP CLI | PHP 8.2.12, ZTS, Windows x64 |
| Composer | 2.10.1 |
| Node.js | v24.12.0 |
| npm | 11.6.2, ejecutado con `npm.cmd` porque `npm.ps1` está bloqueado por la política de ejecución de PowerShell |
| Apache instalado | 2.4.58 en `C:\xampp\apache\bin\httpd.exe` |
| Cliente MySQL/MariaDB | MariaDB 10.4.32 en `C:\xampp\mysql\bin\mysql.exe` |
| MySQL/MariaDB activo | No; no había proceso `mysqld`/`mariadbd` ni escucha en el puerto 3306 |
| Apache activo | No; no había proceso `httpd` ni escucha HTTP detectada |
| `DB_NAME` | `casa-pestalozzi` |
| `APP_ENV` | `development` |
| Base de datos de prueba | No configurada ni identificada |
| Instalación limpia | No ejecutada; habría requerido instalar dependencias y no era necesaria para las validaciones estáticas |

La configuración existente usa `America/Mexico_City`. No se registran secretos ni contraseñas en este documento.

## 3. Inventario de suites y herramientas reales

| Suite/herramienta | Presencia | Ejecución | Resultado |
|---|---:|---|---|
| Lint PHP | Sí, mediante `php -l` | 87 archivos de `controllers`, `services`, `models`, `classes`, `includes` y `public` | `ALL_PHP_LINT_OK` |
| Verificación sintáctica JS | Sí, mediante `node --check` | 47 archivos bajo `src` | `ALL_JS_SYNTAX_OK` |
| Composer | Sí | `composer validate --no-check-publish` | Correcto; solo advierte que falta licencia |
| PHPUnit/PHP harness | No encontrado | No ejecutable | `scripts/run-tests.php` no existe |
| Pruebas JS declaradas | Referenciadas, no presentes | `npm.cmd run test:js` | Falla por `tests/js/reservation-form-state.test.js` inexistente |
| `npm test` | Declarado en `package.json` | No se usa como evidencia de éxito | Depende de los dos runners faltantes |
| Gulp | Sí | `npx.cmd gulp --tasks-simple` | Lista correctamente las tareas `build`, `dev`, CSS, JS, imágenes y fuentes |
| Build frontend | Sí, pero muta salidas compiladas | No ejecutado | Se evitó modificar `public/build` durante una etapa de congelación |
| Script de fixtures | `scripts/limpiar-fixtures-reservaciones.php` | No ejecutado | Es destructivo y exige una DB de pruebas explícita; no había una disponible |
| Suite HTTP/browser | No configurada localmente | No ejecutada | Sin servidor HTTP ni navegador operativo conectado |
| Suite de concurrencia | No encontrada | No ejecutada | Sin runner ni base de datos de prueba |

Comandos principales ejecutados:

```text
composer validate --no-check-publish                         OK
npm.cmd run test:php                                          FALLA: falta scripts/run-tests.php
npm.cmd run test:js                                           FALLA: falta tests/js/reservation-form-state.test.js
npx.cmd gulp --tasks-simple                                   OK
php -l [87 archivos PHP]                                     OK
node --check [47 archivos JS]                                OK
```

## 4. Resumen ejecutivo

- No se hicieron cambios de lógica, estilos, mensajes, tickets, capacidad, modales ni rutas.
- R1 no pudo medirse en navegador; el código contiene varios shells de modal con dimensiones declaradas y tamaños pequeños que deben comprobarse con captura real.
- R2 aparece corregido en el flujo principal de POS: el ticket abierto se busca antes de permitir abrir otro y el identificador se propaga por `ticket_mesas`. La reproducción dinámica quedó pendiente.
- R3 tiene un hallazgo estático confirmado: el motor de ocupación calcula proyección, pero `MesaEstadoService` sigue marcando físicamente ocupada una mesa con ticket abierto; además `TicketMesa` y `OcupacionMesasService` calculan liberaciones con reglas distintas.
- R4 tiene un hallazgo estático confirmado: la capacidad de reservas confirmadas sin mesa asignada no se resta como demanda no asignada.
- R5 no pudo reproducirse como respuesta HTTP; se observaron mensajes con variantes y texto literal de tolerancia, además de un caso de mojibake en el serializador POS.
- R6 tiene un hallazgo estático confirmado: una reservación confirmada con tolerancia vencida conserva `influye_disponibilidad=true`, aunque el contrato vigente indica que debe dejar de bloquear capacidad y mesas y quedar pendiente de registrar ausencia.
- El árbol ya estaba sucio al comenzar. La documentación de esta etapa no altera los dos cambios preexistentes.

## 5. Línea base R1–R6

### R1 — Modal de confirmación y legibilidad

**Estado:** `REQUIERE_EJECUCION` para aceptación visual; no reproducido dinámicamente.

**Evidencia estática:**

- El modal compartido de `src/scss/components/_confirmation-modal.scss` declara un diálogo de `min(100%, 34rem)`, altura máxima de `min(90vh, 42rem)`, `overflow: auto`, padding fluido y texto secundario de `0.8rem`–`0.86rem`.
- El modal administrativo de `_modal.scss` usa un ancho estándar de 440px y variantes de 680px/1080px.
- El modal de POS usa aproximadamente 400px en escritorio, 90vw y un panel inferior en móvil; la confirmación de cancelación tiene un ancho mayor y altura limitada.
- El modal de operación usa 860px y una confirmación separada de 620px.

Hay por tanto más de un shell visual. Sin navegador no se midieron dimensiones computadas en los viewports solicitados, overflow, scroll, foco, botones ni capturas. No se declara una regresión visual como confirmada.

### R2 — Ticket abierto por mesa y multimesa

**Estado:** estáticamente alineado en el flujo principal; reproducción HTTP/POS pendiente (`REQUIERE_EJECUCION`).

**Cadena técnica:**

`PuntoVentaController::api()` → `PosReservacionQueryService::paraFecha()` → `TicketMesa::abiertosParaMapa()` → serialización de `ticket_id` y `mesa_ids` → `punto-de-venta.js::ticketActual()` → modal `con-ticket`.

El JS busca primero un ticket abierto por cualquiera de las mesas del ticket, evita abrir uno nuevo y muestra el flujo de ticket existente. La consulta agrupa las mesas con el mismo `ticket_id`. `PuntoVentaReservacionService::contextoMesa()` mantiene una consulta contextual propia y devuelve acciones de abrir/cerrar, pero no una acción primaria explícita; debe verificarse con el flujo real antes de decidir si es una divergencia funcional.

La prueba dinámica pendiente debe abrir un ticket en una mesa, intentar reabrirla, asociar una segunda mesa, volver a consultar ambas y comprobar que el mismo ticket aparece en las dos sin permitir un ticket paralelo.

### R3 — Proyección de ocupación y liberación

**Estado:** `CONFIRMADO` como divergencia estática; la matriz real por hora requiere DB/HTTP.

La cadena `OcupacionMesasService::evaluarTickets()` calcula una liberación proyectada a partir de la apertura más 90 minutos y distingue `bloquea` de `disponible_proyectada`. En una verificación normativa de una apertura a las 09:00, la expectativa a validar es: a las 09:00 y 10:00 bloqueada; a las 10:30 liberación/proyección disponible; a las 11:00 libre.

Sin embargo:

- `MesaEstadoService` conserva `OCUPADA` cuando existe un ticket abierto y solo añade el modificador `disponible_proyectada`; no convierte el estado base en libre para el mapa.
- `models/TicketMesa.php` calcula otra liberación, incorporando margen de preparación y margen mínimo de seguridad.
- El payload general de tickets no expone de forma uniforme `liberacion_estimada`; otros payloads la exponen con nombres distintos.
- El POS consume `mesas_estado` y no pudo verificarse un control de hora que regenere realmente la consulta para los cuatro puntos.

Esto deja una ruta donde el motor interno puede considerar liberable una mesa mientras el estado consumido por el mapa todavía la presenta como ocupada.

### R4 — Capacidad total, libre, proyectada y demanda no asignada

**Estado:** `CONFIRMADO` como divergencia estática; reproducción con datos reales pendiente.

La cadena es `ReservacionAdministrativaService`/`DisponibilidadReservacionService` → `OcupacionMesasService::resumenCapacidad()` → `capacidad_realmente_libre` y `capacidad_estimada` → formularios y operación administrativa.

El cálculo suma capacidad de mesas libres/proyectadas y resta la capacidad proyectada en `capacidad_realmente_libre`. Pero la consulta de reservas del día se basa en `reservacion_mesas`; no se encontró una resta de `comensales` o demanda equivalente para una reservación confirmada sin mesa asignada. El contrato vigente exige distinguir capacidad física libre, capacidad proyectada y `demanda_no_asignada`, y calcular la capacidad real disponible con esa demanda.

La prueba pendiente debe usar una capacidad total conocida, una reservación que ocupe mesas y otra confirmada sin asignación, y verificar que el payload administrativo no trate la segunda como si no existiera.

### R5 — Mensajes de bloqueo/error

**Estado:** no reproducido por HTTP (`REQUIERE_EJECUCION`); hallazgos estáticos de consistencia.

`PuntoVentaController::responder()` y los controladores de reservaciones tienen rutas separadas para `mensaje`, `msg`, `mensaje_bloqueo` y códigos de error. En el código se observan variantes textuales sin acento, por ejemplo `La seleccion`, `La liberacion` y `La reservacion`, además de mensajes de tolerancia literal como `La tolerancia de 15 minutos sigue vigente.`. También existe el texto mojibake `identidad canÃ³nica es reservacion_id.` en `services/PosReservacionSerializer.php`.

No se asigna un mismo código a varias causas sin una respuesta HTTP concreta; esa parte queda pendiente de capturar con casos de reserva vencida, mesa ocupada, ticket abierto, capacidad insuficiente y sesión no autorizada.

### R6 — Tolerancia vencida y no-show pendiente

**Estado:** `CONFIRMADO` como divergencia estática; reproducción POS/DB pendiente.

`ReservacionVigenciaService::clasificar()` calcula `tolerancia_vencida` y `elegible_no_show`, y el servicio de comienzo rechaza iniciar con `TOLERANCIA_LLEGADA_VENCIDA`. La parte problemática es que `influye_disponibilidad` se calcula para una reservación confirmada sin ticket abierto sin excluir `tolerancia_vencida`.

Después, `PosReservacionSerializer` usa esa bandera para `bloquea_walk_ins`, y `MesaEstadoService` incorpora los modificadores de vencimiento/acción pendiente. Según el contrato vigente, la reservación debe conservarse persistida y mostrar `REGISTRAR_AUSENCIA`, pero dejar de bloquear mesa/capacidad y quedar visualmente libre con indicador gris. El camino actual puede mantener el bloqueo por la bandera de disponibilidad.

La prueba pendiente debe cubrir: confirmada dentro de tolerancia, confirmada vencida sin ticket, acción de registrar ausencia, intento de iniciar rechazado, y liberación de capacidad/mesa antes y después de registrar el no-show.

## 6. Revalidación de hallazgos estáticos previos

| Hallazgo revisado | Clasificación | Evidencia actual |
|---|---|---|
| Residuo `llego` | `CORREGIDO_PREVIAMENTE` | Sin coincidencias en código fuente excluyendo salidas generadas, `vendor` y `node_modules`. |
| Ruta `/api/liberar-mesa` y método asociado | `CORREGIDO_PREVIAMENTE` | Sin coincidencias actuales; la ruta aparece en el diff histórico de la revisión anterior, no en el árbol actual. |
| Alias de autenticación retirados | `CORREGIDO_PREVIAMENTE` | `classes/Auth.php` conserva la lista vigente de APIs y no contiene los alias históricos `liberar-reservacion`, `liberar-mesa` ni `punto-de-venta/reservaciones/llegada`. |
| Reglas incompatibles en `ReservacionVigenciaService` | `CONFIRMADO` | `tolerancia_vencida` existe, pero la expresión de `influye_disponibilidad` para confirmadas sin ticket no la excluye. Impacta R6. |
| Mutaciones administrativas sin CSRF | `CORREGIDO_PREVIAMENTE` | Se encontraron validaciones de `AdminCsrfService` en mantenimiento, operación y administración; POS usa `StaffCsrfService`. |
| `/api/corte-caja` fuera del guard | `CORREGIDO_PREVIAMENTE` | La ruta existe en `public/index.php` y está incluida en `classes/Auth.php::APIS_POS`; no se encontró una ruta pública equivalente fuera de ese guard. |
| Consultas contextuales duplicadas de ocupación en POS | `CONFIRMADO` | `PuntoVentaReservacionService::contextoMesa()` consulta ticket abierto y reservas para una mesa por separado de la consulta central de `PosReservacionQueryService`; es una duplicación contextual que debe compararse con payloads antes de consolidar. |
| Mojibake ubicado en `ReservacionPublicaService` | `CAMBIO_DE_UBICACION` | No apareció allí; sí aparece `canÃ³nica` en `services/PosReservacionSerializer.php`. |
| Fallbacks literales de tolerancia en JavaScript | `CONFIRMADO` | `src/js/modules/punto-de-venta.js` conserva textos literales de 15 minutos, aunque otros caminos ya leen configuración temporal. |
| Respuestas frontend obsoletas después de tickets | `CORREGIDO_PREVIAMENTE` | POS usa `dataRequestSequence` y aborta/descarta respuestas viejas; operación administrativa usa `state.requestSequence` para descartar respuestas fuera de secuencia. |

Las clasificaciones anteriores son de lectura estática. No sustituyen la prueba dinámica solicitada.

## 7. Pruebas de caracterización

No se agregaron pruebas de caracterización. El repositorio no contiene `tests/`, no tiene runner PHP funcional y las pruebas JS declaradas apuntan a archivos inexistentes. Crear un arnés aislado en esta etapa habría requerido decidir dobles de DB, contratos de fixtures y formato de respuesta sin poder contrastarlos con una base de pruebas real. La ausencia queda registrada como deuda de la siguiente etapa, no como evidencia de que las regresiones estén resueltas.

## 8. Archivos creados o modificados

**Creados por esta etapa:**

- `docs/reservaciones/linea_base_regresiones.md`
- `docs/reservaciones/historial/reservaciones_fuente_de_verdad_anterior.md`

**Preexistentes y preservados sin modificar por esta etapa:**

- `reservaciones_fuente_de_verdad.md`
- `plan_estabilizacion_reservaciones.md`

No se modificaron PHP, JavaScript, SCSS, vistas, SQL, rutas, mensajes ni archivos compilados. No se creó commit.

## 9. Riesgos y bloqueadores

1. La aceptación dinámica de R1–R6 está bloqueada por la falta de una instancia activa de MariaDB/MySQL, una base de pruebas explícita y un servidor HTTP.
2. No se ejecutaron pruebas de concurrencia ni de instalación limpia.
3. La solicitud y el árbol usan nombres distintos para la fuente vigente: `_vigente.md` solicitado frente a `reservaciones_fuente_de_verdad.md` real.
4. El archivo vigente local ya estaba modificado al comenzar; cualquier comparación contra `HEAD` mezcla el contrato nuevo local con el contrato histórico del commit.
5. Los scripts de limpieza de fixtures son potencialmente destructivos y exigen una base de pruebas identificada; por eso no se ejecutaron.

## 10. Resultado y siguiente paso seguro

La Etapa 1 queda cerrada documentalmente con resultado **PARCIAL**: el contrato vigente fue identificado, el histórico fue marcado, la línea de comandos fue inventariada, PHP/JS fueron validados sintácticamente y se revalidaron los hallazgos estáticos. No queda justificación para afirmar que R1–R6 están aprobadas.

La siguiente ejecución debe aportar una DB de pruebas aislada y un HTTP/browser operativo. Con esos recursos, se deben capturar los payloads y pantallas de R1–R6, empezando por R3, R4 y R6, que ya presentan divergencias estáticas confirmadas. Cualquier corrección funcional debe esperar a esa evidencia y a una nueva autorización fuera de esta congelación.
