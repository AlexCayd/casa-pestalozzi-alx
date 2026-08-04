# Etapa 11 — Accesibilidad, semántica y validación integral de interfaces

Fecha: 2026-08-04  
Resultado: **implementación avanzada, validación condicional; no se autoriza iniciar Etapa 12**.

## 1. Resumen ejecutivo

Se corrigieron defectos de accesibilidad e interacción en las cuatro superficies del módulo:

- enlace para saltar al contenido y foco programático del `main`;
- landmarks, headings, tablas, listas, labels, mensajes y estados dinámicos;
- foco inicial, trampa de foco, `Escape` y restauración de foco en overlays y modales;
- alternativa estructurada y textual para el mapa compartido y POS;
- nombres accesibles para mesas, reservaciones, estados, acciones y controles iconográficos;
- mensajes de carga, éxito y error con `role="status"`, `aria-live` o `role="alert"` según el caso;
- estilos de foco visible, reducción de movimiento y reflow móvil;
- corrección del pipeline que eliminaba `public/build/js/admin/map.js` después de generarlo;
- corrección de versionado de asignaciones concurrentes sin modificar esquema, estados ni contrato POS.

La validación de interfaz no equivale todavía a una certificación WCAG. El árbol accesible del navegador integrado y los flujos de teclado principales pasaron, pero no hubo lector de pantalla real, inspector Network disponible ni prueba formal de zoom 200 %. Por eso la decisión final es condicional.

No se modificaron tablas, columnas, estados canónicos, ventanas operativas, tolerancia, holds, OTP, algoritmo de asignación ni `pos-reservacion.v1`. No se hicieron commits.

## 2. Fuente de verdad

Se revisó `reservaciones_fuente_de_verdad.md` y los reportes previos de las etapas 3, 7, 7.5, 8, 9, 9.5, 9.6 y 10 antes de cerrar los cambios. La accesibilidad se adaptó a los flujos ya aprobados; no se introdujo una regla funcional para resolver un problema visual.

## 3. Alcance de accesibilidad

Estándar de referencia: WCAG 2.2 nivel AA, sin declarar certificación formal externa.

Superficies revisadas:

1. landing pública y flujo de reservación;
2. administración de reservaciones;
3. mapa operativo compartido;
4. punto de venta.

Limitaciones del entorno: navegador integrado con árbol accesible y CUA, sin lector de pantalla conectado, sin panel Network expuesto por la API disponible y sin control de zoom de navegador.

## 4. Auditoría inicial

| Superficie | Problema inicial | Severidad | Corrección |
| --- | --- | --- | --- |
| Landing | Sin salto consistente al contenido; navegación y lightbox con foco incompleto | Alta | Skip link, foco del `main`, estado `aria-expanded`, `inert`, trap y restauración |
| Landing | Subheadings usados como decoración y footer con cierre HTML incorrecto | Media | Jerarquía coherente y markup reparado |
| Reservación pública | Errores y estados de fecha/hora no siempre asociados al control | Alta | `aria-describedby`, `aria-invalid`, live regions y labels explícitos |
| Administración | Tabla sin caption/scope y acciones genéricas | Alta | Caption, `scope="col"` y nombres contextuales |
| Mapa | La alternativa dependía de la geometría y del color | Bloqueante | Lista estructurada de mesas, nombres accesibles, estados textuales y leyenda semántica |
| POS | Modal personalizado sin garantías completas de foco y estados | Alta | Dialog semantics, `inert`, foco inicial, `Escape`, trap y restauración |
| Build | `map.js` se borraba en una tarea posterior | Alta | Limpieza separada por bundle; el artefacto permanece después del build |
| Concurrencia | Dos asignaciones con el mismo snapshot podían aprobarse | Alta | Avance monotónico de `updated_at` al reemplazar asignación, sin cambio de esquema |

## 5. Semántica

- Cada shell revisado emite un único `main`.
- Los títulos principales de landing, administración, mapa compartido y POS son `h1`.
- La navegación principal, la navegación administrativa, el banner, los complementarios y las regiones operativas tienen landmarks identificables.
- Se corrigieron saltos de jerarquía evidentes y headings usados sólo para tamaño visual.
- Las acciones son botones reales y los destinos son enlaces reales.
- La tabla administrativa tiene `caption`, `thead`, `th scope="col"` y acciones con contexto de nombre, fecha y hora.
- La leyenda del mapa es una lista semántica y la alternativa del mapa es una lista estructurada de controles.
- No se detectaron `tabindex` positivos en las vistas críticas revisadas.
- Los títulos de página se mantienen específicos en las vistas involucradas; no se cambió el contrato de rutas.

## 6. Teclado

Se implementaron y revisaron `Tab`, `Shift+Tab`, `Enter`, `Space`, `Escape` y, donde aplica, flechas/Home/End:

- navegación pública: apertura/cierre del menú, primer enlace enfocado, `Escape` y navegación de anclas;
- lightbox público: activación por teclado, cierre por `Escape`, flechas y foco restaurado;
- filtros y controles administrativos: botones nativos y selector personalizado con teclado;
- modales administrativos: trap de foco, cierre y restauración;
- shell operativo: drawer, menú de usuario y salto al contenido;
- mapa: mesas como botones y lista estructurada como alternativa no espacial;
- POS: apertura de mesa, modal de preferencias, cierre por `Escape` y controles reales.

El navegador integrado marcó el `summary` de la lista estructurada como activo tras la interacción y mostró nombres completos de los controles. La verificación de foco en algunos `main` con `tabindex="-1"` no fue completamente observable desde el adaptador del navegador, aunque el comportamiento está implementado en los handlers de las tres superficies.

## 7. Foco

Se añadieron o consolidaron:

- `tabindex="-1"` sólo en destinos concretos de skip link y contenedores de diálogo;
- foco inicial en el primer control lógico del modal;
- trampa de foco dentro de modal, preferencias, confirmaciones y drawer;
- cierre por `Escape` cuando la acción es cancelable;
- `inert` y `aria-hidden` para impedir interacción con el fondo;
- restauración al botón que abrió el modal;
- foco visible y estilos de foco para interfaces pública, administrativa y operativa;
- `prefers-reduced-motion` para skip links, navegación, lightbox y transiciones relevantes.

En navegador se comprobó explícitamente el cierre de lightbox público y de los modales POS de mesa y preferencias mediante `Escape`.

## 8. Formularios

Se revisaron labels visibles, campos requeridos, descripciones, fecha, hora, comensales, contacto, OTP, filtros y formularios administrativos.

- Los controles de fecha y hora conservan labels y referencias `aria-describedby`.
- Los errores de campo usan mensajes específicos y `aria-invalid`.
- Los mensajes de formularios se diferencian entre error, éxito, advertencia e información.
- Los botones de incremento/decremento de comensales tienen nombres funcionales.
- Se conserva el límite público de 12 y la flexibilidad administrativa para cantidades mayores.
- El código OTP no se expone fuera del preview autorizado por las pruebas existentes.

No se ejecutó desde navegador el ciclo público completo crear hold → OTP → confirmar → modificar → cancelar; la cobertura funcional equivalente sí pasó en las suites PHP de instalación limpia.

## 9. Alertas y live regions

- Mensajes informativos y de carga usan `role="status"`/`aria-live="polite"`.
- Errores bloqueantes usan `role="alert"` o `aria-live="assertive"` cuando requieren lectura inmediata.
- El estado de la reservación, la actualización del mapa y el resumen operativo tienen texto, no sólo spinner o color.
- Los controles de carga conservan texto como “Actualizando mapa…” y “Cargando mapa”.
- El polling no añade un nuevo estado de negocio ni anuncia repetitivamente el mismo mensaje.
- La confirmación administrativa de no show anuncia “Reservación marcada como no show.” en el resultado actualizado.

## 10. Mapa

El mapa compartido y POS ahora ofrecen dos representaciones equivalentes:

1. mapa visual físico;
2. `details` con lista estructurada de mesas.

Cada mesa expone nombre, capacidad cuando aplica y estado textual, por ejemplo “Mesa 6. Disponible., capacidad 4, disponible”. Las mesas no elegibles se deshabilitan realmente cuando no existe override. La leyenda es una lista con Disponible, Ocupada, Selección actual, Reservación próxima y No utilizable, además de una nota que explica que el estado no depende sólo del color.

En el navegador se abrió la alternativa estructurada en mapa compartido y POS. La lista mostró las mesas con estados y capacidades; el mapa conservó el rojo físico y los modificadores de reservación/ticket en el nombre accesible.

## 11. Administración

- Skip link y `main` identificable en el layout administrativo.
- Listado de reservaciones con caption, encabezados y acciones contextuales.
- Métricas agrupadas en región con nombre.
- Estados completos: “Confirmada”, “No show”, “Sin ticket abierto”, etc.; no dependen sólo de badges de color.
- Formularios y modales de warnings con descripción, botones concretos y trap de foco.
- Detalle con `h1`, regiones de datos, acciones y mensajes de resultado.

En navegador se abrió el listado autenticado, se verificaron tabla y acciones, y se ejecutó el no show de la reservación local de prueba 321. El detalle quedó en “No show” y el mapa liberó las mesas que no tenían una ocupación física independiente.

## 12. POS

- El mapa POS muestra botones con estado, capacidad, ticket abierto, comensales y hora de apertura.
- La apertura de ticket se ejecutó desde navegador en Mesa 6 y produjo el estado “Ocupada. Ticket abierto”.
- Los modales de mesa y preferencias exponen `role="dialog"`, nombre, `aria-modal`, foco y cierre por `Escape`.
- El texto de no show administrativo conserva “Registrar que el cliente no se presentó”.
- Las suites backend verifican inicio simple/multimesa, idempotencia, no show, cierre, pagos, liberación y contrato `pos-reservacion.v1`.

El cierre visual completo no pudo finalizarse en navegador porque el fixture abierto desde la UI no contenía productos y el POS bloqueó el cierre con una alerta nativa de cuenta vacía. El cierre equivalente del ticket de prueba se realizó por servicio para no dejar estado local abierto; el flujo completo de cierre sigue cubierto por integración/concurrencia PHP.

## 13. Contraste y reflow

- Se mantuvieron los colores canónicos del mapa: verde disponible, amarillo selección, azul reservación/advertencia y rojo ocupación física.
- Los estados tienen texto y leyenda además del color.
- Se reforzaron outlines, focus rings, skip links, tamaños táctiles y estilos de controles del mapa/lista.
- Se agregaron reglas para reducción de movimiento.
- Se validó en navegador un viewport móvil explícito de 375×800 en landing: skip link, navegación y menú siguieron siendo operables.
- Se revisó el POS en viewport normal y la estructura móvil mediante árbol accesible.

No se ejecutó una medición formal de contraste ni una prueba automatizada de zoom de navegador al 200 %. El navegador integrado no expone control de zoom; esto queda como riesgo pendiente.

## 14. Mutaciones autenticadas

| Flujo | Resultado |
| --- | --- |
| No show administrativo | **Pasó en navegador local**: se abrió el modal, se confirmó, apareció el mensaje y se verificó estado/mapeo posterior |
| Inicio POS | **Pasó en navegador local**: se abrió Mesa 6 y el mapa mostró ticket abierto y hora de apertura |
| Cierre POS | **Condicional**: el UI llegó al selector de cierre pero el fixture sin productos fue rechazado; servicio de cierre y suites limpias pasaron |
| Creación/asignación administrativa | Pasó en suites PHP limpias; no se ejecutó el formulario completo en navegador para no contaminar el fixture demo con varias mutaciones |
| Reasignación | Pasó en suites PHP limpias y en concurrencia; no se ejecutó la secuencia completa de formulario/mapa en navegador |
| Flujo público OTP | Pasó en suites PHP limpias; no se completó end-to-end en navegador |
| Sincronización entre superficies | Se verificó no show navegador → mapa y POS start/close → estado del mapa; la sincronización completa de cuatro pestañas no se certifica con Network no disponible |

## 15. Consola

Se revisaron warnings y errors del navegador integrado después de landing, administración, mapa compartido, POS y viewport móvil. Los snapshots de consola fueron `[]` en las superficies visitadas. No quedaron errores de JavaScript al abrir/cerrar los modales probados.

Persisten sólo warnings de build de Sass sobre la API JS legacy y `fs.Stats`; no son warnings de ejecución de la aplicación.

## 16. Network

El adaptador del navegador integrado no expone un panel Network ni una API de inspección de requests. Como smoke HTTP, estos recursos respondieron 200:

- `/`;
- `/build/js/admin/map.js`;
- `/build/js/admin.js`;
- `/build/css/app.css`.

Las suites PHP multiproceso y de integración verificaron respuestas JSON, idempotencia, CSRF/contratos de servicio y ausencia de dobles mutaciones en los escenarios cubiertos. No se puede certificar desde esta sesión el conteo exacto de POST, polling, requests pendientes, headers CSRF o ausencia de tokens en URLs.

## 17. Concurrencia A–L

La matriz pedida por la fuente de verdad queda trazada así. “Parcial” significa que existe una carrera cercana o que la invariante aparece en otra suite, pero no se debe presentar como cobertura exacta del par solicitado.

| Caso | Carrera | Suite | Test/aserción | Resultado |
| --- | --- | --- | --- | --- |
| A | Confirmación pública vs asignación administrativa | `etapa9_5_concurrencia_integrada.php` | `C_mapa_vs_hold_publico` cubre hold público contra asignación; no confirmación OTP exacta contra asignación | Parcial |
| B | Cancelación vs inicio POS | `etapa10_concurrencia.php` | `cancel_vs_start`; nunca cancelada con ticket abierto | Pasó |
| C | No show vs inicio POS | `etapa10_concurrencia.php` | `no_show_vs_start`; nunca no show con ticket abierto | Pasó |
| D | Cierre vs reasignación | `etapa10_concurrencia.php` | `close_vs_reassign`; cierre gana y reasignación se rechaza | Pasó |
| E | Cierre vs segunda apertura | `etapa10_concurrencia.php` | `double_start_one_ticket` y `double_close_idempotent` cubren invariantes relacionadas, no el par exacto cierre/segunda apertura | Parcial |
| F | Reemplazo público vs inicio POS | `etapa7_5_concurrencia_cruzada.php` | `confirmar_vs_pos`; sólo una transición operativa válida | Pasó |
| G | Expiración de hold vs confirmación | `etapa7_5_concurrencia_cruzada.php` | `confirmar_vs_expirar`; nunca original y reemplazo confirmados simultáneamente | Pasó |
| H | Reasignación vs creación pública | `etapa9_5_concurrencia_integrada.php` | `C_mapa_vs_hold_publico` y `H_reasignacion_vs_reemplazo` cubren conflictos de asignación/reemplazo, no creación pública exacta | Parcial |
| I | Cancelación administrativa vs reemplazo público | `etapa7_5_concurrencia_cruzada.php` | `confirmar_vs_cancelar` es cancelación pública contra reemplazo; falta la variante administrativa exacta | Pendiente |
| J | No show vs cancelación | `etapa10_concurrencia.php` / `etapa7_5_concurrencia_cruzada.php` | Existen no show, cancelación e invariantes separadas, no la carrera simultánea solicitada | Pendiente |
| K | Cierre vs no show | `etapa10_concurrencia.php` | `close_vs_reassign`, `no_show_vs_start` y reconciliación cubren estados terminales, no la carrera exacta | Pendiente |
| L | Dos cierres simultáneos | `etapa10_concurrencia.php` | `double_close_idempotent`; un cierre materializa y el otro es idempotente | Pasó |

La regresión reproducible encontrada en dos asignaciones simultáneas se corrigió avanzando la versión de reservación al reemplazar el pivote; después de la corrección `etapa9_5` pasó 8/8.

## 18. Invariantes

Resultado verde en instalación limpia de Etapa 10 y en Etapa 9.5 corregida:

- nunca cancelada/no show/completada con ticket abierto;
- nunca `en_curso` sin ticket;
- nunca ticket abierto sin `ticket_mesas`;
- nunca dos tickets abiertos para una reservación;
- nunca reasignación exitosa después de iniciar;
- cierre idempotente;
- no show libera capacidad y conserva historial;
- asignación concurrente con snapshot obsoleto devuelve `VERSION_DESACTUALIZADA`.

Las carreras A, H, I, J y K no tienen todavía cobertura exacta, por lo que no se declara trazabilidad completa A–L.

## 19. Tests automatizados

- `npm.cmd run test:js`: **pasó**.
  - `reservation-form-state`: PASS;
  - `operation-map-state`: PASS;
  - `accessibility-contract`: PASS.
- La prueba de accesibilidad estática cubre skip links, main, dialogs, labels/descripciones, caption/scope, lista del mapa, estados, handlers de foco y ausencia de tabindex positivo.
- `node --check`: pasó para JS crítico y `gulpfile.js`.
- `php -l`: pasó para las vistas modificadas y `services/AsignacionMesasService.php`.
- `git diff --check`: sin errores de whitespace; sólo avisos normales de conversión LF/CRLF de Git en el working tree.

## 20. Instalación limpia

Pasaron y eliminaron sus bases temporales:

- Etapa 5: DDL/DML y smoke de instalación (`dropped=true`);
- Etapa 7.5: 35 casos cruzados;
- Etapa 8: 19 casos administrativos;
- Etapa 9: mapa manual y carrera de asignación;
- Etapa 9.5, después de la corrección: 8 escenarios de concurrencia y regresiones POS (`dropped=true`);
- Etapa 10: integración 11/11, concurrencia e invariantes (`dropped=true`).

## 21. Regresiones

No se declara una aprobación global porque permanecen dos regresiones/precondiciones históricas fuera del alcance de accesibilidad:

1. `tests/php/etapa5_nucleo.php`: 36 casos pasaron y 4 expectativas 5.4 fallaron sobre combinación física/salida pública/horarios alternativos.
2. `tests/php/etapa6_2_fecha_horarios_capacidad.php`: no encontró una fecha libre en el rango del fixture de la base activa y abortó antes de ejecutar sus aserciones.

Pasaron `etapa6_publica` (46), `etapa6_concurrencia`, `etapa7_publica` (25), Etapa 7.5, Etapa 8, Etapa 9, Etapa 9.5 corregida y Etapa 10.

`npm.cmd test` continúa apuntando a `scripts/run-tests.php`, archivo inexistente en el repositorio; `npm.cmd run test:js` sí pasa. No se creó un runner nuevo porque sería alcance de infraestructura de tests, no una corrección de la interfaz.

## 22. Build

`npm.cmd run build` pasó dos veces consecutivas después de separar la limpieza de `map.js` y `reservation-form.js`. El bundle `public/build/js/admin/map.js` quedó presente y respondió HTTP 200.

El build emite warnings deprecatorios de Sass legacy API y `fs.Stats`; no hay error de compilación. No se modificó el tema operativo oscuro.

## 23. Archivos modificados

Responsabilidades principales:

- `views/home/*`: skip link, landmarks, headings, labels, mensajes y lightbox.
- `views/admin/layout.php`, `views/admin/reservations/*`: main, caption, scope, acciones contextualizadas, alertas y headings.
- `views/operation/partials/*`: shell, heading, mapa, loading y leyenda semántica.
- `views/punto-de-venta/partials/pos-workspace.php`: dialogs y preferencias POS.
- `src/js/modules/nav.js`, `lightbox.js`, `form.js`, `punto-de-venta.js`: navegación, foco, live regions y modales.
- `src/js/admin/admin.js`, `src/js/admin/reservations/*`: foco de skip links y traps administrativos.
- `src/js/operation/shell.js`, `map-visual.js`, `reservation-card.js`: shell, lista estructurada y nombres de reservaciones/mesas.
- `src/js/components/reservation-time-picker.js`: semántica de opción de hora.
- `src/scss/layout/_reset.scss`, `src/scss/admin/shared/base/_globals.scss`, `src/scss/operation/_map-shell.scss`: foco, visually hidden, reflow y alternativa estructurada.
- `tests/js/accessibility-contract.test.js`: contratos estáticos de accesibilidad.
- `gulpfile.js`: limpieza independiente de bundles para evitar borrar `admin/map.js`.
- `services/AsignacionMesasService.php`: avance monotónico de la versión al reemplazar asignación; no agrega esquema ni estado.
- `public/build/*` y `assets/*`: artefactos recompilados existentes del working tree.

Se preservaron los cambios preexistentes y no se hizo reset ni commit.

## 24. Limitaciones

- No se ejecutó un lector de pantalla real; se usó árbol accesible del navegador.
- La API del navegador integrado no expone Network.
- No hubo medición automática de contraste.
- No hubo zoom de navegador al 200 % ni viewport exacto de 1440×900, 1024×768, 768×900 y 390×844; sí se probó 375×800.
- El flujo público OTP completo no se ejecutó por navegador.
- La secuencia administrativa crear sin contacto → asignar → reasignar no se ejecutó completa por navegador.
- El cierre POS visual se detuvo correctamente ante el fixture sin productos; el cierre backend fue verificado por servicio y suites.
- La matriz A–L contiene cinco casos parciales/pendientes que no deben presentarse como carreras exactas cubiertas.

## 25. Riesgos pendientes

Ordenados por severidad:

1. **Alta:** completar las carreras exactas A, H, I, J y K y ejecutar su evidencia multiproceso.
2. **Alta:** completar el flujo autenticado público OTP y el flujo administrativo completo en navegador sobre una base de prueba aislada.
3. **Alta:** repetir el cierre POS con un fixture que tenga al menos un producto entregado y capturar la secuencia de pagos desde navegador.
4. **Media:** habilitar una herramienta Network o instrumentación equivalente para certificar POST únicos, polling, 404, CSRF y requests pendientes.
5. **Media:** ejecutar lector de pantalla, contraste formal y zoom 200 %.
6. **Media:** reparar o parametrizar las precondiciones históricas de Etapa 5.4 y Etapa 6.2, y crear el runner ausente de `npm.cmd test` si se desea una regresión única.
7. **Baja:** migrar Sass desde la legacy JS API cuando corresponda.

## 26. Decisión final

**¿Las interfaces del módulo son accesibles y operables?**  
**Sí, con condiciones.** Las superficies críticas tienen semántica, teclado, foco, dialogs, live regions y alternativa estructurada del mapa; faltan lector de pantalla, contraste formal y zoom 200 %.

**¿Las mutaciones autenticadas están validadas end-to-end?**  
**Sí, con condiciones.** No show y apertura POS se probaron en navegador local; integración, cierre, pagos, concurrencia y reconciliación pasan en suites limpias. El OTP público, la asignación administrativa completa y el cierre visual con productos todavía requieren una pasada de navegador aislada.

**¿Es seguro iniciar la Etapa 12 de limpieza y cierre?**  
**No.** Primero deben cerrarse los riesgos de cobertura A–L, la validación de Network/lector/zoom, los flujos autenticados faltantes y las regresiones históricas declaradas. No se inició Etapa 12.

