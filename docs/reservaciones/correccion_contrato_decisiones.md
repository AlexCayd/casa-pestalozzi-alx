# Corrección contractual de mensajes y decisiones

## Línea base

- Rama: `modulo-reservaciones`.
- HEAD inicial: `23b97654fdda3dfd89d3bd397e44cdd8c628dab1`.
- El árbol inicial tenía `docs/reservaciones/` sin seguimiento; sus archivos existentes se conservaron fuera de esta serie.
- La fuente normativa utilizada fue exclusivamente `docs/reservaciones.md`.
- No se modificaron `database/database.sql`, migraciones, autorización de `/api/sugerencias`, Router ni reglas de capacidad, tolerancia o proyección temporal.

## Cambios implementados

1. `ReservacionErrorCatalog` es la única fuente de `mensaje`, `descripcion`, `consecuencia`, `acciones`, `tipo`, `http_status` y `commit`.
2. Las decisiones administrativas se exponen en `confirmaciones_requeridas` como presentaciones completas. `requiredConfirmations` queda como alias con la misma forma.
3. El POS ya no muestra `motivo` ni códigos internos de bloqueo; cada mesa recibe su presentación canónica.
4. La advertencia de reservación próxima se calcula y valida en backend. La primera apertura responde `RESERVACION_PROXIMA` sin commit; la confirmación explícita revalida y permite el commit.
5. Los formularios y el mapa administrativo adaptan únicamente la presentación recibida y sus acciones; no contienen mapas locales de mensajes ni reconstruyen capacidad o consecuencias.
6. Se agregaron pruebas PHP, prueba de sintaxis y auditoría estática contractual.

## Verificación

Pasaron los siguientes comandos:

- `npm.cmd run test:php`
- `npm.cmd run test:js`
- `npm.cmd run audit:reservaciones`
- `npm.cmd run build`
- `git diff --check`
- `php -l` en los servicios y controladores modificados.
- `node --check` en los módulos JS modificados.

El build terminó correctamente. Sass y Node mostraron advertencias de deprecación de sus dependencias, sin impedir la generación de artefactos.

## Serie de commits

- `refactor(reservaciones): unificar contrato de decisiones`
- `fix(pos): ocultar causas internas de reservaciones`
- `fix(pos): usar advertencia canonica de reservacion proxima`
- `refactor(reservaciones): centralizar decisiones administrativas`
- `test(reservaciones): validar mensajes y decisiones canonicas`
