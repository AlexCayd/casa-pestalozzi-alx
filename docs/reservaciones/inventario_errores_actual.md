# Inventario actual de errores, advertencias y decisiones

**Etapa:** 3 — Migración completa al catálogo canónico  
**Fecha:** 2026-08-05  
**Rama:** `modulo-reservaciones`  
**HEAD inicial:** `afd51c1c7f890670e603c1bed60a11eadca135be`  
**Fuente normativa:** `reservaciones_fuente_de_verdad.md`  
**Línea base:** `docs/reservaciones/linea_base_regresiones.md`

## Alcance y método

Se auditaron los archivos relacionados de `controllers/`, `services/`,
`models/`, `classes/`, `includes/`, `public/`, `src/js/`, `src/scss/`,
`views/`, `database/` y `scripts/`, excluyendo `vendor`, `node_modules` y
salidas compiladas. El inventario estático cubrió 20 controladores, 37
servicios, 19 modelos, 10 clases, 5 includes, 3 archivos públicos, 48 JS,
78 SCSS, 85 vistas, 5 archivos de base de datos y 3 scripts. Se encontraron
168 rutas declaradas en `public/index.php`.

La auditoría distingue el código de la traducción visible, y registra cuando
la transacción fue confirmada, revertida o no pudo comprobarse sin DB. Los
mensajes dinámicos no se consideran identidad del error.

## Resumen de clasificación

| Categoría | Situación actual |
|---|---|
| Errores | Seguridad, sesión, entrada, estado, horario, identidad y fallos internos |
| Conflictos recuperables | Disponibilidad, asignación, concurrencia, ticket y token |
| Advertencias | Liberación proyectada, reservación próxima, tolerancia vigente y reservación sin ticket |
| Decisiones requeridas | Confirmar sin mesas, capacidad, sin contacto, reasignación y ausencia |
| Información | Resultados exitosos, consultas y estados de ticket |
| Duplicados | Las declaraciones literales duplicadas de Etapa 2 fueron convertidas a fachadas con valor canónico |
| Código perdido | Los resultados ahora conservan código, contexto seguro y presentación catalogada |
| Texto como contrato | Retirado de servicios, controladores, serializador y consumidores JS |

Conteo ejecutable actual sobre los 115 códigos emitidos encontrados por el auditor:

| Tipo | Cantidad |
|---|---:|
| Error | 58 |
| Conflicto recuperable | 18 |
| Advertencia | 2 |
| Decisión requerida | 10 |
| Información | 23 |
| Declaraciones duplicadas | 0 |
| Mensajes literales en servicios | 0 |
| Aliases ejecutables | 0 |
| Consumidores `msg`/`message`/`mensaje_bloqueo` | 0 |
| Contextos sensibles interpolados fuera del catálogo | 0 |
| Comparaciones de mensajes en JavaScript | 0 después de la centralización |
| Ramas POS que sólo devolvían `msg` y ahora tienen código | 24 |

## Registro principal

| ID auditoría | Archivo y línea | Capa | Código actual | Mensaje actual | Condición aparente | HTTP | Consumidor |
|---|---|---|---|---|---|---:|---|
| ERR-001 | `services/ContactoAccesoService.php:37,79` | dominio OTP | `DATOS_INVALIDOS` | Texto tomado de `InvalidArgumentException` | Contacto o formato inválido | 422 | landing |
| ERR-002 | `services/ContactoAccesoService.php:21-24,229-264` | dominio OTP | `OTP_INCORRECTO`, `OTP_EXPIRADO`, `OTP_INTENTOS_AGOTADOS`, `VERIFICACION_NO_ENCONTRADA` | Varias traducciones del servicio | El código no puede consumirse | 422/410/429 | landing |
| ERR-003 | `services/ContactoAccesoService.php:163-164` | dominio OTP | `REENVIO_NO_DISPONIBLE` | Espera antes de solicitar otro código | Intervalo de reenvío activo | 429 | landing |
| ERR-004 | `services/ReservacionPublicaService.php:695,1571-1631` | dominio público | `SESION_PUBLICA_EXPIRADA`, `SIN_DISPONIBILIDAD`, `RETENCION_EXPIRADA`, `ERROR_INTERNO` | Mensajes específicos y mensajes enviados por argumento | Sesión, disponibilidad, hold o fallo interno | 401/409/410/500 | landing |
| ERR-005 | `controllers/ReservacionController.php:367-386` | HTTP público | `CSRF_INVALIDO`, `METODO_NO_PERMITIDO` | Textos definidos en controlador | Seguridad o método HTTP inválido | 403/405 | landing |
| ERR-006 | `services/HorarioReservacionService.php:90-270` | dominio horario | `FECHA_INVALIDA`, `FECHA_PASADA`, `FECHA_FUERA_DE_HORIZONTE`, `HORARIO_INVALIDO`, `HORARIO_PASADO`, `HORARIO_SIN_CONFIGURACION`, `DESPUES_DE_ULTIMA_RESERVACION`, `DIA_INACTIVO` | Motivos internos más mensajes de control | Fecha u horario no operativos | 422 | landing/admin/mapa |
| ERR-007 | `services/DisponibilidadReservacionService.php:145,225,248` | disponibilidad | `DISPONIBILIDAD_CONSULTADA`, `SIN_DISPONIBILIDAD`, `DATOS_INVALIDOS`, `ERROR_INTERNO` | Fachadas pública e interna distintas | No existe una combinación válida o la entrada falla | 200/409/422/500 | landing/admin |
| ERR-008 | `services/AsignacionMesasService.php:278-581` | asignación | `RESERVACION_NO_EXISTE`, `RESERVACION_NO_EDITABLE`, `ESTADO_INVALIDO`, `MESAS_INVALIDAS`, `SIN_CAPACIDAD`, `MESA_OCUPADA`, `SUPERPOSICION_NO_AUTORIZADA`, `CONFLICTO_CONCURRENTE`, `AGRUPACION_NO_AUTORIZADA`, `CONFLICTO_TICKETS_ABIERTOS`, `CAPACIDAD_INSUFICIENTE` | Resultados sin contrato común; algunos registran rollback | Asignación inválida, ocupada o concurrente | 409/422 | mapa/admin |
| ERR-009 | `services/ReservacionAdministrativaService.php:260-761` | administración | `SIN_ASIGNACION`, `CAPACIDAD_INSUFICIENTE`, `REQUIERE_CONFIRMACION_CAPACIDAD`, `RESERVACION_CREADA_SIN_MESA`, `CONTACTO_TIPO_NO_EDITABLE` | `msg` con advertencias y éxitos locales | Administración debe decidir si confirma sin mesas o sobre capacidad | 200/409/422 | admin |
| ERR-010 | `controllers/AdminReservacionController.php:88-193,314-364` | HTTP admin | `METODO_NO_PERMITIDO`, `RESERVACION_NO_EXISTE`, `ERROR_INTERNO`, `DATOS_INVALIDOS` | `message`, `mensaje` y `msg` mezclados | Formulario o recurso inválido | 404/422/500 | admin |
| ERR-011 | `controllers/ReservacionOperacionController.php:160-210,347-436,597-824` | HTTP mapa | `FECHA_INVALIDA`, `DATOS_INCOMPLETOS`, `METODO_INVALIDO`, `CSRF_INVALIDO`, códigos de asignación y transición | Varias funciones `mensaje*Api()` | Consulta, asignación, transición o CSRF inválidos | 419/422/409 | mapa |
| ERR-012 | `services/PuntoVentaReservacionService.php:18-30,138-182,247-449` | dominio POS | `NO_EXISTE`, `ESTADO_INVALIDO`, `MESA_OCUPADA`, `TOLERANCIA_VIGENTE`, `TOLERANCIA_LLEGADA_VENCIDA`, `TICKET_ABIERTO`, `REQUIERE_CONFIRMACION`, `REQUIERE_REASIGNACION`, `SIN_CAPACIDAD`, `CONFLICTO_CONCURRENTE` | `msg` y `mensaje_bloqueo` | Inicio, cancelación, no-show o apertura incompatible | 409/422 | POS |
| ERR-013 | `controllers/PuntoVentaController.php:702-741,853-868` | HTTP POS | códigos POS del servicio y errores de ticket | `msg` era traducido localmente o no tenía código | La capa HTTP reinterpretaba códigos y algunos endpoints perdían la causa | 409/422/419 | POS |
| ERR-014 | `services/ReservacionVigenciaService.php:241-281` | vigencia | `TOLERANCIA_LLEGADA_VENCIDA` derivado | No siempre se preservaba el código hasta el cliente | Reservación confirmada fuera de tolerancia | 409 | POS/mapa |
| ERR-015 | `src/js/modules/reservation-access.js:18-23` | frontend | códigos públicos | Diccionario local retirado; ahora consume `mensaje` del catálogo | Frontend traducía por su cuenta la causa | n/a | landing |
| ERR-016 | `src/js/modules/punto-de-venta.js:1984` | frontend | `TICKET_ABIERTO`, advertencia de reservación próxima | Comparación de texto y literal temporal retirados | Frontend reconstruía mensaje de bloqueo | n/a | POS |
| ERR-017 | `services/PosReservacionSerializer.php:8,11,113` | serializador | n/a | Mojibake corregido | Codificación visible incorrecta | n/a | POS/mapa |
| ERR-018 | `views/operation/reservations/index.php:203` | vista | n/a | Mojibake corregido en “selección” | Texto visible corrupto | n/a | mapa |
| ERR-019 | `services/ReservacionMantenimientoService.php:13-17` | mantenimiento | `AMBIENTE_NO_PERMITIDO`, `DATOS_INVALIDOS`, `CONFIRMACION_INVALIDA`, `ERROR_INTERNO` | Mensajes del script de limpieza | Fixture destructivo sin ambiente de prueba | 422/500 | CLI/admin |

## Detalle normalizado por hallazgo

### ERR-001 / ERR-002 / ERR-003 — OTP y acceso público

**Origen real:** `ContactoAccesoService` normaliza contacto, valida formato,
consulta el OTP vigente y consume intentos dentro de transacción.  
**Servicio o método:** `solicitarCodigo()`, `verificarCodigo()`,
`validarCodigoEnTransaccion()`.  
**Condición exacta:** contacto inválido, código incorrecto, código vencido,
intentos agotados, verificación ausente o reenvío dentro del intervalo.  
**Datos de contexto:** tipo de contacto y contacto normalizado; el código OTP
no se devuelve salvo preview de desarrollo controlado.  
**Transacción:** inicia en solicitud/verificación; un intento inválido puede
confirmar sólo el contador, mientras que una validación no utilizable revierte.  
**Commit/rollback:** explícito en el servicio; el catálogo expone el resultado
común sin sustituirlo.  
**Autenticación:** sesión pública se crea sólo después de verificación exitosa.  
**Autorización:** no aplica rol; depende de contacto verificado.  
**CSRF:** lo valida el controlador público antes del servicio.  
**Idempotencia:** el reenvío tiene ventana de protección y la verificación
consume el código.  
**Superficies:** landing y gestión pública.  
**Acciones actuales:** verificar, solicitar código, reintentar o esperar.  
**Coherencia:** ahora cada código tiene tipo, HTTP, mensaje, consecuencia y
acción en el catálogo.  
**Duplicados:** las fachadas PHP que conservan nombres históricos apuntan al
valor canónico; no hay aliases de runtime.
**Riesgo:** queda por ejecutar la prueba dinámica con DB y navegador; el
contrato estático ya no depende de campos heredados.
**Código canónico propuesto:** los códigos específicos ya existentes; no se
agrupan OTP incorrecto, vencido y agotado.

### ERR-004 / ERR-005 — Sesión pública, CSRF y retenciones

**Origen real:** `ReservationClientSession`, `ReservacionPublicaService` y
`ReservacionController`.  
**Servicio o método:** validación de sesión, creación/confirmación de
retención, `validarCsrfPublico()`, `status()`.  
**Condición exacta:** sesión ausente, token CSRF inválido, hold vencido,
disponibilidad ausente o fallo interno.  
**Datos de contexto:** identificador de reservación, fecha, hora y request
token; se filtran antes de enviar la respuesta.  
**Transacción:** la retención y confirmación son transaccionales; CSRF y
método inválido no inician transacción.  
**Commit/rollback:** el servicio decide; el resultado enriquecido conserva un
`commit` explícito si fue informado.  
**Autenticación/autorización:** sesión pública y coincidencia de contacto.  
**CSRF:** requerido para mutaciones públicas.  
**Idempotencia:** `request_token` se conserva y ahora se presenta con código y
acciones comunes.  
**Superficies:** landing y gestión pública.  
**Acciones actuales:** verificar contacto, actualizar, cambiar horario,
reintentar.  
**Coherencia:** la traducción ya no depende de los textos enviados por el
servicio.  
**Duplicados:** `RESERVACION_NO_EXISTE` y `RESERVACION_NO_ENCONTRADA` quedan
con alias temporal.  
**Riesgo:** la vista pública todavía tiene fallbacks locales para errores de
red sin payload; no son la fuente del código de dominio.  
**Código canónico propuesto:** `SESION_PUBLICA_EXPIRADA`, `CSRF_INVALIDO`,
`RETENCION_EXPIRADA`, `REQUEST_TOKEN_CONFLICTO`, `SIN_DISPONIBILIDAD`.

### ERR-006 / ERR-007 — Horario y disponibilidad

**Origen real:** `HorarioReservacionService` y
`DisponibilidadReservacionService`.  
**Servicio o método:** `resolverFecha()`, `validarHora()`, `evaluarHorario()`
y fachadas pública/interna.  
**Condición exacta:** fecha inválida, fecha pasada, fuera de horizonte, día no
operativo, horario no configurado, horario pasado o sin disponibilidad.  
**Datos de contexto:** fecha, hora y motivo de calendario; la fachada pública
no expone mesas ni SQL.  
**Transacción:** consulta sin transacción; la revalidación mutacional ocurre
posteriormente dentro del caso de uso correspondiente.  
**Commit/rollback:** no inicia transacción en esta capa.  
**Autenticación/autorización/CSRF:** dependen de la ruta consumidora.  
**Idempotencia:** consulta pura.  
**Superficies:** landing, administración y mapa.  
**Acciones actuales:** cambiar fecha/hora, actualizar o consultar modo
histórico.  
**Coherencia:** el catálogo distingue información (`HORARIO_DISPONIBLE`) de
error.  
**Duplicados:** `HORARIO_INVALIDO` y motivos de calendario aún conviven; se
documentan como códigos de distinta capa.  
**Riesgo:** controladores todavía agregan mensajes de horario para vistas no
JSON.  
**Código canónico propuesto:** conservar códigos específicos y retirar
gradualmente el uso de `HORARIO_INVALIDO` como cajón de sastre.

### ERR-008 / ERR-009 / ERR-010 / ERR-011 — Capacidad y asignación

**Origen real:** `AsignacionMesasService`,
`ReservacionAdministrativaService` y controladores administrativos.  
**Servicio o método:** selección, reasignación, creación/actualización y
transiciones administrativas.  
**Condición exacta:** mesa ocupada, versión desactualizada, conflicto de
ticket, capacidad insuficiente, sin asignación automática, confirmación sin
mesas o datos inválidos.  
**Datos de contexto:** IDs de mesas, reservación, versión, capacidad y
advertencias; el catálogo no agrega datos sensibles.  
**Transacción:** asignación y mutaciones administrativas inician transacción y
usan rollback en conflictos.  
**Commit/rollback:** el resultado funcional ya decide commit/rollback; el
catálogo no altera locks ni estados.  
**Autenticación:** guard administrativo.  
**Autorización:** CSRF administrativo y rol admin.  
**CSRF:** validado antes de las mutaciones.  
**Idempotencia:** algunos casos conservan `request_token` o `idempotente`; debe
probarse con DB en la siguiente etapa.  
**Superficies:** administración y mapa.  
**Acciones actuales:** actualizar, reasignar, confirmar sin mesas, volver.  
**Coherencia:** el catálogo separa conflicto de decisión administrativa.  
**Duplicados:** `SIN_CAPACIDAD` fue unificado en `CAPACIDAD_INSUFICIENTE`;
`RESERVACION_NO_EXISTE` en `RESERVACION_NO_ENCONTRADA` y
`TICKET_ABIERTO_EXISTENTE` en `TICKET_ABIERTO`.
**Riesgo:** R4 sigue fuera de alcance; la capacidad real no se corrige en esta
etapa.  
**Código canónico propuesto:** `CAPACIDAD_INSUFICIENTE`, `SIN_ASIGNACION`,
`REQUIERE_CONFIRMACION_CAPACIDAD`, `MESA_OCUPADA`, `CONFLICTO_CONCURRENTE`.

### ERR-012 / ERR-013 / ERR-014 / ERR-016 — POS, tickets y no-show

**Origen real:** `PuntoVentaReservacionService`, `PuntoVentaController`,
`PosReservacionSerializer` y `punto-de-venta.js`.  
**Servicio o método:** listar, comenzar, cancelar, registrar no-show y
`responder()`.  
**Condición exacta:** ticket abierto, mesa ocupada, tolerancia vigente o
vencida, reservación próxima, conflicto concurrente o re-asignación necesaria.  
**Datos de contexto:** reservación, mesa, ticket y bloqueo; el serializador
conserva el código.  
**Transacción:** comenzar/cancelar/no-show usan transacción; consultas no.  
**Commit/rollback:** el servicio conserva sus decisiones; `commit` se expone
en el resultado enriquecido cuando falta un campo histórico.  
**Autenticación:** rol POS.  
**Autorización:** `Auth::APIS_POS` y CSRF POS en mutaciones.  
**CSRF:** `StaffCsrfService` antes del servicio.  
**Idempotencia:** los métodos del servicio tienen caminos idempotentes; falta
prueba dinámica por ausencia de DB.  
**Superficies:** POS y mapa operativo.  
**Acciones actuales:** consultar ticket, actualizar mapa, registrar ausencia,
reintentar.  
**Coherencia:** se retiró el traductor `mensajeOperacion()` del controlador;
ahora la respuesta de reservaciones POS se enriquece desde el catálogo.  
**Duplicados:** `TICKET_ABIERTO` es el único código canónico para ticket abierto;
las advertencias anidadas también llevan `presentacion` del catálogo.
**Riesgo:** R3 y R6 no se corrigen; sólo se centraliza su comunicación.  
**Código canónico propuesto:** `TICKET_ABIERTO`, `TOLERANCIA_VIGENTE`,
`TOLERANCIA_LLEGADA_VENCIDA`, `REQUIERE_CONFIRMACION`.

### ERR-015 / ERR-017 / ERR-018 — Frontend, mojibake y duplicación

**Origen real:** diccionario local en `reservation-access.js`, traducción POS
local y textos codificados incorrectamente en serializador/vista.  
**Condición exacta:** el cliente traducía códigos por cuenta propia,
comparaba mensajes visibles y mostraba mojibake.  
**Transacción:** no aplica.  
**Commit/rollback:** no aplica.  
**Autenticación/autorización/CSRF:** no aplica; el frontend consume una
respuesta ya validada.  
**Idempotencia:** no aplica.  
**Superficies:** landing, POS y mapa.  
**Acciones actuales:** ahora se leen `mensaje`, `consecuencia` y `acciones`
del payload; quedan fallbacks genéricos sólo para fallos de red sin respuesta.  
**Coherencia:** se retiró el diccionario de mensajes de modificación pública,
la comparación de textos del modal POS y los literales temporales auditados.  
**Duplicados:** no quedan avisos de mensajes literales en servicios.  
**Riesgo:** builds generados no se recompilaron en esta etapa; se validó la
sintaxis de `src/js`.  
**Código canónico propuesto:** usar `codigo` y `acciones`; nunca texto como
contrato.

## Compatibilidad retirada

- `msg`, `message` y `mensaje_bloqueo` ya no se emiten ni consumen.
- `RESERVACION_NO_EXISTE`, `SIN_CAPACIDAD` y `TICKET_ABIERTO_EXISTENTE` no
  aparecen en `ReservacionErrorCatalog::aliases()` ni en respuestas runtime.
- Las fachadas de constantes PHP restantes son nombres internos de transición,
  no aliases de código; su valor es el canónico.

## Contradicciones fuera de alcance

1. R3: proyección futura y estado visual de mesas.
2. R4: capacidad y demanda no asignada.
3. R6: liberación después de tolerancia vencida.
4. Consultas contextuales duplicadas de ocupación POS.
5. Reglas de asignación y estados persistidos.

## Resultado

El inventario identifica 115 códigos encontrados por el auditor estático y 191
entradas en el catálogo ejecutable, incluyendo códigos de campo requeridos por
la fuente vigente que todavía no tienen consumidores directos. La traducción común ya está
integrada en los controladores público, administrativo, mapa y POS de
reservaciones. Las pruebas estáticas pasan sin errores; queda pendiente la
verificación dinámica con DB/Apache sin alterar los motores R3/R4/R6.
