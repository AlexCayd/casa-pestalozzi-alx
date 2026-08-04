# Etapa 9.5 — Estabilización transversal del mapa compartido y concurrencia operativa

## 1. Resultado ejecutivo

La etapa queda implementada y probada en backend, contratos operativos, estados visuales, refresco/versionado y concurrencia multiproceso. La matriz nueva A–H pasó 8/8 y la instalación limpia pasó todas las suites incluidas.

La decisión de producción es **condicional**: la lógica queda estable, pero el build global sigue presentando fallos intermitentes de apertura de source maps en Windows y la validación visual de tema claro, consola y Network no puede declararse completa. **No es seguro iniciar Etapa 10 todavía.**

No se modificó el esquema, no se agregaron estados de reservación y no se inició ninguna parte de Etapa 10.

## 2. Fuente de verdad y alcance

Se revisaron `reservaciones_fuente_de_verdad.md` y los reportes de Etapas 3, 3.5, 7.5, 8, 9 y de estabilización previa del mapa. Se conservaron:

- `pos-reservacion.v1` como contrato canónico de lectura POS.
- `ticket_mesas` como fuente física de ocupación.
- Los estados existentes de reservación y los locks de horario/fecha/contacto.
- El mapa, coordenadas, shell operativo, filtros y flujo OTP/público existentes.

El trabajo se limitó a estabilización transversal: precedencia visual, refresh/versiones, selección local, respuestas obsoletas, concurrencia y pruebas reproducibles.

## 3. Build y diagnóstico de EPERM/UNKNOWN

El task `build` de `gulpfile.js` no tiene duplicación de tareas ni watchers activos. El primer bloqueo reproducible estaba concentrado en `public/build/js/admin/area.js`: tenía ACL/propietario distinto (`Leo_PC\CodexSandboxOffline`). Se ejecutó `icacls public/build/js/admin/area.js /reset`, después de lo cual el archivo permitió apertura de lectura/escritura y el bundle relacionado compiló.

La evidencia más reciente es mixta:

```text
build global: pasó una vez después del ajuste móvil (3.24 s)
build global siguiente: UNKNOWN al abrir configuration.js.map
build global siguiente: UNKNOWN al abrir reservation-operation.js.map
build global siguiente: UNKNOWN al abrir map.js.map
build relacionado: operationCss adminMapJs adminReservationOperationJs — pasó
```

Los source maps afectados tienen ACL normal y se pueden abrir manualmente con acceso `ReadWrite`; no quedó otro proceso Node/Gulp escribiéndolos. `openfiles.exe` no estaba habilitado y la consulta de Restart Manager no fue utilizable en este entorno. Por tanto, el problema residual se clasifica como interferencia transitoria del filesystem/Defender/sandbox de Windows, no como error Sass, JavaScript o una tarea duplicada. El build global no se marca verde hasta obtener dos ejecuciones consecutivas posteriores completamente exitosas.

## 4. Fuente física y R10

R10 quedó alineado con el contrato POS y con el mapa compartido:

- Un ticket abierto mantiene el fondo físico rojo.
- Una reservación próxima agrega borde/advertencia azul.
- Rojo y azul coexisten; el azul no reemplaza al rojo.
- No se introducen letras dentro de las mesas.
- La fuente física sigue siendo `ticket_mesas`, no una inferencia desde reservaciones.

`pos_reservacion_integrado.php` pasó R10 y la instalación limpia volvió a pasar el flujo completo, incluyendo los modificadores `ticket_abierto`, `walk_in`, `reservacion_proxima` y `reservacion_advertencia`.

## 5. Precedencia visual

`src/js/operation/table-state-adapter.js` centraliza la precedencia y `src/scss/operation/_map-shell.scss` conserva los indicadores aditivos:

| Base / modificador | Estado visual esperado |
| --- | --- |
| Disponible sin reservación próxima | Verde |
| Disponible con reservación próxima | Azul |
| Ticket abierto sin reservación | Rojo |
| Ticket abierto + reservación próxima | Rojo + borde azul |
| Disponible + selección válida | Amarillo |
| Ticket abierto + próxima + selección válida | Rojo + azul + aro amarillo |
| No reservable/inactivo | Neutro; selección rechazada |

La selección inválida no sustituye el estado físico ni convierte elementos no reservables en mesas elegibles.

## 6. Refresh, versionado y selección local

`src/js/admin/reservations/operation.js` ahora:

- Conserva la reservación activa y su selección local durante un refresh que no sea descarte explícito.
- Captura `assignmentInitialVersion` al entrar en modo de asignación.
- Marca que los datos remotos se actualizaron y muestra: “Tu selección local se conserva; vuelve a validar antes de guardar”.
- Descarta respuestas obsoletas mediante `requestSequence` y `AbortController`.
- No guarda automáticamente la selección local.
- Descarta la selección sólo al cancelar, liberar, cambiar de reservación o resolver un conflicto con refresh explícito.

La respuesta obsoleta nunca pisa el estado más reciente ni reabre un modo de edición abandonado.

## 7. Auditoría de locks

No se detectó inversión de orden en los flujos revisados. El orden efectivo quedó documentado así:

1. Operación administrativa/POS: `HorarioConfigLock` → `FechaOperacionLock` → transacción → fila de reservación → mesas ordenadas.
2. Operación pública: `HorarioConfigLock` → `ContactoOperacionLock` → `FechaOperacionLock` ordenada → transacción → reservación/mesas.
3. Confirmación de reemplazo público: `HorarioConfigLock` → `ContactoOperacionLock` → transacción → fila de reemplazo y fila original.

Las mesas se bloquean en orden ascendente. No se agregaron bucles de reintento ciego ni commits parciales.

## 8. Matriz multiproceso A–H

Se agregaron `tests/php/etapa9_5_concurrencia_integrada.php` y `tests/php/etapa9_5_concurrencia_worker.php`. Cada caso usa procesos PHP independientes, conexiones independientes, barrera de arranque, fixtures exclusivos, reloj controlado y limpieza final.

| Caso | Carrera | Invariante verificada | Resultado |
| --- | --- | --- | --- |
| A | Reasignación administrativa vs inicio POS | No hay doble asignación física; el ticket sólo vincula mesas finales válidas | Pasa |
| B | Liberación vs inicio POS | Si libera primero, POS no inicia sin mesas; no queda ticket parcial | Pasa |
| C | Asignación manual vs hold público | El hold no pisa la asignación administrativa; relaciones disjuntas | Pasa |
| D | Asignación manual vs alta administrativa automática | Una sola asignación por reservación; capacidad y pivotes coherentes | Pasa |
| E | Reasignación vs cancelación | Cancelación terminal prevalece; no quedan pivotes activos | Pasa |
| F | Dos asignaciones sobre conjuntos distintos | Una gana la versión; la otra recibe `VERSION_DESACTUALIZADA` | Pasa |
| G | Liberación vs cancelación | No quedan mesas asignadas ni relaciones huérfanas | Pasa |
| H | Reasignación vs confirmación de reemplazo público | Original/reemplazo quedan en estados terminales coherentes | Pasa |

Ejecución sobre `casa_pestalozzi_etapa4_test`:

```text
ok=true, pasadas=8, fallidas=0
```

## 9. Instalación limpia y regresiones

`tests/php/etapa9_5_instalacion_limpia.php` crea una base temporal desde `database/ddl.sql` y `database/dml.sql`, ejecuta las suites, restaura los tickets base y elimina la base.

Resultado:

```text
ddl=true
dml=true
etapa9_5_concurrencia_integrada=true
etapa9_mapa_manual=true (25/25)
etapa9_concurrencia=true
etapa7_5_concurrencia_cruzada=true (35/35)
pos_reservacion_contrato=true
pos_reservacion_integrado=true, incluido R10
dropped=true
ok=true
```

Las pruebas administrativas de Etapa 8 permanecen en 19/19. El test baseline de Etapa 5 conserva sus dos expectativas históricas fallidas sobre horizonte incluido y hold vencido; no fueron causadas por esta etapa.

## 10. Validación visual local

Se levantó un servidor PHP local con un router temporal sólo para servir correctamente los assets estáticos y se accedió con el usuario demo documentado. La primera captura sin ese router confirmó que el servidor frontal devolvía HTML para CSS/JS; se corrigió el entorno de prueba, no la aplicación.

En tema oscuro, el mapa operativo cargó con CSS y JavaScript en estos tamaños, sin overflow horizontal:

```text
1440×900 → mapa 1001×688
1024×768 → mapa 604×582
768×900  → mapa 724×278
390×844  → mapa 339×320, con scroll interno
```

La validación de 390 px encontró y corrigió un colapso real de la fila del mapa a altura cero. `src/scss/operation/_layout.scss` ahora reserva una altura táctil mínima de 320 px antes del panel de detalle.

El shell operativo fija actualmente `data-admin-theme="dark"` y no expone un control claro/oscuro en ese layout. Existen tokens y reglas para claro/oscuro en el sistema admin, pero no se declara aprobada la inspección end-to-end del tema claro para este mapa. Tampoco se declara aprobada una revisión completa de consola y Network: el navegador disponible sólo expuso control de viewport, navegación, DOM y captura, no un recolector dedicado de esos logs.

## 11. Archivos principales

- `src/js/operation/table-state-adapter.js`: precedencia y elegibilidad visual.
- `src/js/admin/reservations/operation.js`: refresh, selección local, versiones y respuestas obsoletas.
- `src/scss/operation/_map-shell.scss`: composición rojo/azul/amarillo y estados físicos.
- `src/scss/operation/_layout.scss`: altura mínima responsive del mapa móvil.
- `src/scss/operation/_assignment-mode.scss` y `views/operation/reservations/index.php`: aviso de actualización remota.
- `tests/js/operation-map-state.test.js`: matriz visual en JavaScript.
- `tests/php/etapa9_5_concurrencia_integrada.php`: orquestador A–H.
- `tests/php/etapa9_5_concurrencia_worker.php`: worker independiente por proceso.
- `tests/php/etapa9_5_instalacion_limpia.php`: DDL/DML y regresión limpia.
- Assets compilados relacionados bajo `public/build/` y `assets/css/`.

No se modificaron `database/ddl.sql` ni `database/dml.sql`.

## 12. Verificaciones de código

Pasaron:

```text
php -l en los tres scripts PHP nuevos
node --check src/js/operation/table-state-adapter.js
node --check src/js/admin/reservations/operation.js
node tests/js/operation-map-state.test.js
git diff --check
```

El build específico de mapa/reservaciones pasa; el global mantiene la intermitencia de source maps descrita en la sección 3.

## 13. Compatibilidad y límites

- El algoritmo público, OTP, holds, cancelación pública y reemplazo no fueron reescritos.
- El contrato POS por defecto no recibe campos administrativos nuevos.
- No se implementaron drag-and-drop, editor de coordenadas, unión física, historial avanzado ni nuevos estados.
- La validación clara end-to-end y la captura dedicada de consola/Network quedan pendientes.
- El build global requiere una ejecución estable posterior al problema de filesystem/Defender/sandbox.

## 14. Decisión de avance

**Etapa 9.5 técnica:** sí, completada; concurrencia A–H, R10, refresh/versionado y regresión limpia pasan.

**Salida a producción:** no todavía; queda condicionada a dos builds globales consecutivos verdes y a cerrar la validación visual del tema claro, consola y Network.

**Etapa 10:** no es segura y no debe iniciarse automáticamente.
