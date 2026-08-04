# Etapa 7.5 — Estabilización cruzada de la gestión pública de reservaciones

**Fecha:** 2026-08-03  
**Repositorio:** `C:\xampp\htdocs\casa-pestalozzi`  
**Estado:** aprobado técnicamente con validación visual pendiente

## Resumen

La gestión pública quedó verificada contra carreras cruzadas entre procesos PHP independientes y una conexión MySQL independiente por proceso. No se demostró ningún estado final inválido, doble ocupación, doble reemplazo vigente ni fuga de OTP.

No fue necesario modificar servicios de producción: la estabilización se resolvió con una suite de pruebas multiproceso y un runner de instalación limpia. Tampoco se modificaron el esquema, el contrato POS, administración, proveedores de correo/WhatsApp ni la landing.

## Fuente de verdad revisada

Se leyó completamente `reservaciones_fuente_de_verdad.md` y se contrastaron los reportes de Etapas 5, 6, 6.1 y 7.

Se conservaron como invariantes:

- `America/Mexico_City` como zona canónica.
- Reservación de 90 minutos, retención de 15 minutos y OTP de 5 minutos.
- Modificación pública hasta 30 minutos antes y cancelación pública hasta 15 minutos después del horario.
- Asignación pública estricta de 1 a 12 comensales.
- El reemplazo queda pendiente mientras el original continúa confirmado; sólo el OTP válido puede confirmar el reemplazo.
- El reemplazo confirmado lleva el original a `reemplazada`; una retención expirada conserva el original confirmado.
- `confirmada` es el único estado que ocupa mesa para disponibilidad; POS consulta `confirmada` y `en_curso` como lista operativa.
- No se agregaron estados, columnas, reglas administrativas ni reglas de asignación automática.

## Entorno y reproducibilidad

- PHP 8.2.12 CLI, Composer 2.10.1, Node.js v24.12.0 y npm 11.6.2.
- XAMPP/MySQL local con la configuración del repositorio.
- Reloj de pruebas fijado en `2026-11-01 11:20:00` y timezone verificada como `America/Mexico_City`.
- Cada carrera usa dos procesos PHP, dos conexiones de base y una barrera de arranque común.
- El runner exige una base explícita de pruebas y rechaza `casa-pestalozzi` y `casa_pestalozzi`.

## Orden de locks auditado

El código productivo no se modificó; se auditó el orden efectivo usado por cada familia de operación:

1. Mutaciones públicas: `HorarioConfigLock` global, luego `ContactoOperacionLock` cuando aplica, fechas normalizadas en orden estable cuando aplica, transacción y filas de reservación necesarias (`FOR UPDATE`); después se valida disponibilidad, se asignan mesas y se escribe el OTP.
2. POS: `HorarioConfigLock`, fecha de operación, transacción, reservación original `FOR UPDATE`, mesas en orden ascendente, ocupación física, ticket y transición a `en_curso`.
3. Expirador: transacción y filas pendientes con `FOR UPDATE SKIP LOCKED`; actualiza estado y OTP sin tocar una fila que otro proceso ya bloqueó.

Las operaciones públicas que comparten contacto se serializan mediante el lock de contacto y las que comparten una reservación se serializan mediante la fila original/reemplazo. POS y las mutaciones no produjeron inversión de locks ni deadlock en las carreras ejecutadas. No se introdujeron reintentos indefinidos: un timeout o conflicto se devuelve como error controlado.

## Carreras cruzadas 1–5

La suite es `tests/php/etapa7_5_concurrencia_cruzada.php` y se ejecuta sobre fixtures únicos por corrida.

### 1. Confirmar reemplazo contra cancelar original

Resultados finales permitidos y observados:

- `original=reemplazada` + `reemplazo=confirmada`.
- `original=cancelada` + `reemplazo=expirada`.

Nunca quedó `cancelada + confirmada` ni `confirmada + confirmada`. Después de la carrera, el inicio POS directo de la reservación original devuelve `ESTADO_INVALIDO` y la original no aparece en `reservaciones_operativas`.

### 2. Confirmar reemplazo contra iniciar servicio en POS

Resultados finales permitidos y observados:

- Gana confirmación: `original=reemplazada`, `reemplazo=confirmada`, cero tickets abiertos para la original; el reemplazo aparece en la lista operativa POS.
- Gana POS: `original=en_curso`, `reemplazo=expirada`, exactamente un ticket abierto; el reemplazo no aparece en la lista operativa.

Nunca quedó una reservación reemplazada con ticket abierto ni una reservación `en_curso` con reemplazo confirmado.

### 3. Confirmar reemplazo contra expirador

Resultados finales permitidos y observados:

- Gana confirmación: `original=reemplazada` + `reemplazo=confirmada`.
- Gana expiración: `original=confirmada` + `reemplazo=expirada`.

Nunca quedaron original y reemplazo confirmados al mismo tiempo. Para alcanzar el borde exacto de los 15 minutos, la prueba extendió únicamente el `expires_at` del OTP fixture; la regla productiva de OTP continúa siendo de 5 minutos.

### 4. Dos modificaciones simultáneas sobre el mismo original

Se verificaron además tres casos de idempotencia:

- Mismo `request_token` y mismo payload: repetición idempotente.
- Mismo `request_token` y payload distinto: rechazado.
- Dos tokens y destinos distintos en paralelo: sólo un reemplazo pendiente vigente queda activo.

El original permaneció confirmado y quedó un solo OTP ligado activo. No se generaron dos reemplazos pendientes vigentes.

### 5. Dos cancelaciones simultáneas

El resultado fue `cancelada`, con dos respuestas exitosas y exactamente una marcada como idempotente. La relación histórica de mesas se conservó y la evaluación del mismo horario volvió a indicar disponibilidad.

## Reemplazadas y POS

La consulta `PosReservacionQueryService::paraFecha` filtra la reservación original reemplazada y conserva sólo el reemplazo confirmado en la lista operativa. El servicio de inicio POS también rechaza directamente una reservación original cancelada o reemplazada.

La carrera confirmar/POS verificó ambas ramas: si el reemplazo gana, el reemplazo confirmado queda visible para POS; si POS gana, la reservación original pasa a `en_curso`, se crea un único ticket y el reemplazo expira.

## Sesión y autorización

Los workers no comparten sesión ni estado de autenticación: cada proceso recibe su propio contexto de contacto, usa la ruta de sesiones de pruebas y mantiene su propia conexión. Las carreras usan contactos únicos por fixture, excepto cuando se prueba deliberadamente la serialización/idempotencia del mismo contacto.

No se introdujeron bypass de autorización, cuentas administrativas ni cambios de permisos. La suite no sustituye una prueba de navegador autenticada; valida el contrato de servicio público con el contacto/token que ya exige el dominio.

## Aislamiento de OTP

Cada modificación crea un reemplazo y un OTP vinculado a esa reservación. La suite comprueba que, tras dos modificaciones concurrentes, sólo queda un OTP activo para el único hold vigente. Las respuestas de workers redactan cualquier `preview_code` antes de imprimir JSON.

No se probó el envío real por correo o WhatsApp, conforme al alcance de la etapa.

## Instalación limpia

`tests/php/etapa7_5_instalacion_limpia.php`:

- Crea una base temporal con nombre único.
- Importa `database/ddl.sql` y `database/dml.sql`.
- Ejecuta la suite multiproceso completa.
- Elimina siempre la base temporal en `finally`.

Se ejecutó tres veces consecutivas: **35/35 aserciones aprobadas en cada corrida**, `fixtures_cleaned=true` y `dropped=true` en todos los casos. Las repeticiones observaron tanto la rama en que gana la confirmación como las ramas en que gana POS o expiración, siempre con estados válidos.

## Reproducibilidad de dependencias

En un directorio temporal se copiaron `composer.json`, `composer.lock`, `package.json` y `package-lock.json` y se ejecutaron instalaciones limpias:

- Composer instaló correctamente las dependencias bloqueadas y generó el autoloader.
- npm instaló correctamente las dependencias bloqueadas.
- Hubo advertencias de paquetes npm deprecados, sin fallo de instalación.
- No se modificaron manifests ni lockfiles del repositorio.
- El directorio temporal de dependencias fue eliminado después de la prueba.

## Validación visual

No se otorga aprobación visual en esta etapa. Apache pasó `httpd -t` y se inició temporalmente con la configuración local, pero el navegador integrado bloqueó la navegación posterior a la URL local por su política de URL. De acuerdo con esa restricción no se usó una superficie alternativa ni se fabricaron screenshots, consola o red.

Queda pendiente revisar explícitamente escritorio y móvil, incluyendo consulta, modificación, OTP, confirmación, cancelación, errores y estados loading/disabled. La accesibilidad pendiente existente no se amplió en esta etapa.

## Componentes reutilizados

La suite reutiliza el dominio existente: `ReservacionPublicaService`, `PuntoVentaReservacionService`, `PosReservacionQueryService`, `DisponibilidadReservacionService`, `ReservacionConfig`, locks/advisories existentes, autoload de Composer y los scripts oficiales DDL/DML. No se creó una segunda implementación de disponibilidad, asignación, expiración o contrato POS.

## Pruebas ejecutadas

- `php tests/php/etapa7_5_instalacion_limpia.php`: 35/35, repetido tres veces.
- `php tests/php/etapa7_publica.php`: 25/25.
- `php tests/php/etapa5_nucleo.php`: 59/59.
- `php tests/php/etapa6_publica.php`: 46/46.
- `php tests/php/etapa6_concurrencia.php`: creación/duplicado concurrente y limpieza aprobados.
- `php tests/php/etapa6_2_fecha_horarios_capacidad.php`: 20/20.
- `php tests/php/pos_reservacion_contrato.php`: aprobado.
- `php tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`: aprobado, paridad POS/admin sin fallos.
- `php tests/php/etapa5_instalacion_limpia.php`: DDL, DML y smoke de servicios aprobados.
- `node tests/js/reservation-form-state.test.js`: PASS.
- `php -l` en los dos nuevos runners y servicios auditados: sin errores.
- `node --check src/js/modules/reservation-access.js` y `git diff --check`: sin errores.

Después de las corridas no quedaron bases temporales, barreras ni archivos de sesión de Etapa 7.5.

## Archivos modificados

- `tests/php/etapa7_5_concurrencia_cruzada.php`: nuevo runner multiproceso para las cinco carreras y sus invariantes POS/disponibilidad/OTP.
- `tests/php/etapa7_5_instalacion_limpia.php`: nuevo runner de base temporal, importación DDL/DML, ejecución y limpieza.
- `docs/reports/2026-08-03_etapa7_5_estabilizacion_cruzada_publica.md`: este informe.

No se modificaron servicios PHP, vistas, JavaScript productivo, SCSS, schema, POS ni administración.

## Limitaciones y riesgos pendientes

- **Medio — validación visual:** el navegador integrado no permitió completar la navegación local; falta aprobación manual de escritorio/móvil.
- **Bajo — proveedor externo:** correo/WhatsApp real y reintentos de proveedor no se prueban en esta etapa.
- **Bajo — entorno:** las carreras se validaron con dos procesos contra la base local; no constituyen una prueba de partición de red, failover ni carga sostenida de producción.
- **Fuera de alcance:** administración, permisos, mapa, ARIA y reconstrucción del panel POS/admin.

No hay un defecto de concurrencia demostrado que justifique cambiar el dominio o el esquema.

## Decisiones de cierre

- **¿Gestión pública estabilizada frente a concurrencia cruzada?** Sí, con condición visual pendiente: la suite limpia pasó 35/35 en tres corridas y no mostró estados inválidos.
- **¿Se inicia Etapa 8 automáticamente?** No.
- **Recomendación para Etapa 8:** iniciar sólo después de completar la revisión visual local; no se reconstruye administración ni se amplía el alcance dentro de esta etapa.
