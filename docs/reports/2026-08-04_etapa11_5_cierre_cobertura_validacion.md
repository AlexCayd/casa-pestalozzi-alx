# Etapa 11.5 — Cierre de cobertura concurrente, regresiones y validación autenticada

Fecha: 2026-08-04  
Entorno: PHP 8, MySQL local temporal, `APP_ENV=testing` para suites aisladas y servidor local `http://127.0.0.1:8000` para navegador.  
Alcance: cierre de Etapa 11.5. No se modificó el esquema, no se hicieron commits y no se inició Etapa 12.

## 1. Resumen ejecutivo

El cierre automatizado queda verde: carreras A–L `12/12`, contrato de versionado `14/14`, Etapa 6.2 `20/20`, Etapa 5 completa, runner oficial funcional, regresiones JavaScript verdes y dos builds consecutivos exitosos.

Los flujos autenticados y públicos solicitados se ejecutaron en navegador local, incluyendo OTP, modificación, cancelación, alta administrativa con advertencias, asignación/reasignación y POS completo con producto, cocina, pago y liberación de mesa.

La aceptación integral no puede declararse incondicional porque el navegador integrado no expone inspección Network, zoom operativo ni lector de pantalla real. Esas limitaciones quedan separadas de los resultados verdes y bloquean una decisión afirmativa sin condiciones sobre accesibilidad y Etapa 12.

## 2. Fuente de verdad

Se revisó `docs/reservaciones_fuente_de_verdad.md` y se respetaron sus contratos: horizonte de 90 días, anticipación mínima de 40 minutos, tolerancia de 15 minutos, llegada temprana de 30 minutos, hold de 15 minutos, OTP de 5 minutos, ocupación canónica, grupos de mesas, estados canónicos, transacciones, edición administrativa bloqueada después de iniciar POS y versionado monotónico.

## 3. Pendientes heredados y resolución

| Pendiente | Resultado |
|---|---|
| Etapa 6.2 abortaba buscando una fecha libre | Reemplazada por fechas deterministas de lunes a jueves en una base temporal; `20/20`, sin escaneo de rangos. |
| No había runner PHP oficial | Creado `scripts/run-tests.php`, con política de bases temporales locales y validación de JSON/exit/drop. |
| Concurrencia A–L incompleta | Creada suite multiproceso exacta con dos workers por carrera, barreras y limpieza. |
| Versionado de asignaciones pendiente | Creada suite de `14/14` aserciones. |
| Sesiones locales generaban warnings | Sesiones de desarrollo/testing dirigidas a `tests/.sessions`, ignorado salvo `.gitkeep`. |
| Validación autenticada parcial | Completados recorridos público, admin y POS en navegador. |

## 4. Suite concurrente A–L

La suite `tests/php/etapa11_5_concurrencia_completa.php` usa workers PHP independientes, reloj fijo, barreras de arranque y fixtures controladas. Cada carrera deja estado final e invariantes en JSON. Se ejecutó tres veces consecutivas dentro de instalaciones limpias; las tres dieron `12/12`.

| Caso | Carrera | Resultado y estado observado |
|---|---|---|
| A | OTP público vs asignación administrativa | PASS; el ganador conserva exclusividad de mesa y el perdedor recibe rechazo de ocupación. |
| B | Cancelación administrativa vs inicio POS | PASS; `cancelada`, sin ticket. |
| C | No-show vs inicio POS | PASS; una sola transición válida, sin combinación inválida de estado y ticket. |
| D | Cierre POS vs reasignación | PASS; `completada`, ticket `cerrado`, reasignación rechazada. |
| E | Cierre POS vs segunda apertura | PASS; `completada`, un solo ticket cerrado. |
| F | Reemplazo público vs inicio POS | PASS; original `reemplazada` o la transición POS gana de forma coherente. |
| G | Expiración de hold vs confirmación OTP | PASS; hold `expirada`, OTP no confirma. |
| H | Reasignación administrativa vs nuevo hold | PASS; no existe doble ocupación de la misma mesa. |
| I | Cancelación administrativa vs confirmación de reemplazo | PASS; solo una rama terminal es válida y la otra queda rechazada/expirada. |
| J | No-show vs cancelación | PASS; una única transición terminal, observada como `no_show`. |
| K | Cierre POS vs no-show | PASS; `completada`, ticket `cerrado`, no-show rechazado. |
| L | Dos cierres simultáneos | PASS; `completada`, ticket `cerrado`, un cierre material y el segundo idempotente. |

La suite normaliza tickets POS de demostración abiertos únicamente dentro de la base temporal que recibe, para que el DML no contamine las ocho mesas de fixture. No toca la base principal.

## 5. Versionado de asignaciones

`tests/php/etapa11_5_version_asignaciones.php`: `14/14`. Se verificó avance al asignar, avance al reasignar y liberar, ausencia de avance con snapshot idéntico, rechazo de snapshot obsoleto con `VERSION_DESACTUALIZADA`, invalidación por inicio/cancelación/no-show/reemplazo POS y ausencia de regresión numérica.

## 6. Etapa 5

La instalación limpia de Etapa 5 pasó y fue eliminada al finalizar. La suite nuclear queda en `58/58`.

## 7. Etapa 6.2

La instalación limpia determinista pasó `20/20`, con reloj fijo `2026-11-01 12:00:00`, fechas de prueba `2026-11-02` a `2026-11-05` y base temporal eliminada (`dropped=true`).

## 8. Runner

Pasaron ambos comandos:

```text
php scripts/run-tests.php
npm.cmd test
```

El runner valida Etapa 5 y Etapa 11.5 sobre bases locales temporales; `npm.cmd test` además pasó los tres contratos JavaScript.

## 9. Flujo público en navegador

PASS en servidor local, usando contactos ficticios `.test` y limpieza exacta posterior:

- hold público, solicitud OTP, uso del código de preview y confirmación;
- modificación de hora, con segundo OTP para confirmar cambios;
- cancelación pública con confirmación del diálogo;
- mensajes de éxito/error y estado de sesión de contacto verificado.

No se transmitieron contactos reales ni se conservaron las reservaciones creadas para la prueba.

## 10. Flujo administrativo en navegador

PASS con sesión autenticada local:

- alta sin contacto y sin mesas;
- advertencias explícitas `SIN_CONTACTO` y `SIN_ASIGNACION`, confirmadas por el flujo de advertencia;
- asignación manual desde el mapa;
- reasignación de una mesa a otra;
- cancelación posterior de la fixture para limpiar.

También se verificaron login, analytics y mapa operativo sin warnings de sesión en consola.

## 11. Flujo POS en navegador

PASS de punta a punta:

1. apertura de ticket en mesa disponible;
2. agregado de producto y envío a cocina;
3. cocina: preparado y listo;
4. POS: entrega;
5. pago en efectivo con importe suficiente;
6. cierre de ticket;
7. liberación visible de la mesa.

El ticket de fixture fue eliminado después de verificar `remaining_tickets=0`.

## 12. Network

Resultado: no concluyente en este runtime. El Browser integrado no expone API de inspección de requests/responses, conteo de POST, polling, requests pendientes ni verificación independiente de CSRF. Por ello no se inventan conteos ni se marca este criterio como PASS. La inspección disponible de consola no mostró errores/warnings y los contratos JavaScript pasaron, pero eso no sustituye Network.

## 13. Zoom y viewports

Los cuatro viewports exactos pasaron sin overflow horizontal en superficie pública, mapa administrativo y POS:

| Viewport | Pública | Admin mapa | POS |
|---|---:|---:|---:|
| `1440×900` | PASS | PASS | PASS |
| `1024×768` | PASS | PASS | PASS |
| `768×900` | PASS | PASS | PASS |
| `390×844` | PASS | PASS | PASS |

El zoom 200 % no pudo validarse de forma concluyente: el navegador integrado no ofrece control de zoom; los intentos con `Ctrl+` no modificaron `visualViewport.scale`.

## 14. Contraste

Se midieron estilos computados en el navegador y se calculó luminancia relativa WCAG. Muestras críticas: texto principal `15.49:1`, acento/dorado `7.99:1`, texto de inputs de reservación `18.82:1` y sección clara `16.15:1`. Todas superan AA en las muestras revisadas.

## 15. Lector de pantalla y teclado

No se ejecutó un lector de pantalla real porque no está disponible en esta superficie. El contrato estático de accesibilidad pasó: skip link, `main`, headings, diálogos, formularios, mapa, ausencia de `tabindex` positivo y orden inicial de foco. El recorrido físico completo con Tab no fue concluyente en el runtime integrado.

## 16. Consola

Sin `warn/error` en las superficies pública, login admin, analytics, mapa operativo, POS y cocina después de corregir la ruta de sesiones locales. Los builds solo emiten advertencias conocidas de deprecación de Dart Sass y `fs.Stats`; no son fallos de compilación ni bloqueos funcionales.

## 17. Instalación limpia

`tests/php/etapa11_5_instalacion_limpia.php` pasó tres veces consecutivas: DDL, DML, A–L `12/12`, versionado `14/14`, Etapa 6.2 `20/20` y `dropped=true` en cada ejecución.

## 18. Regresiones

Pasó el loop de regresión de los módulos existentes: núcleo Etapa 5, pública Etapa 6, concurrencia Etapa 6, Etapa 6.2, pública/concurrencia de Etapa 7, Etapa 7.5, admin y concurrencia de Etapa 8, pruebas manuales/limpias de Etapa 9 y 9.5, integración/concurrencia Etapa 10 y contrato POS–reservaciones. Los scripts directos que requieren `--db` se ejecutan mediante sus wrappers de instalación limpia.

## 19. Build

```text
npm.cmd run build  # PASS
npm.cmd run build  # PASS
```

Ambas ejecuciones completaron todas las tareas Gulp. Solo quedaron las advertencias deprecadas ya descritas.

## 20. Invariantes y contrato

Quedaron verdes: no doble ocupación física, un solo ticket por inicio, cierre terminal consistente, reasignación bloqueada después de POS, rechazo de snapshots obsoletos, versión monotónica, expiración de holds y limpieza de fixtures. El contrato `pos-reservacion.v1` no cambió y no hubo cambios en `database/database.sql`.

## 21. Archivos modificados o añadidos

- `.gitignore` y `tests/.sessions/.gitkeep`.
- `classes/Auth.php` y `services/ReservationClientSession.php` para ruta de sesiones de desarrollo/testing.
- `services/ReservacionMapaAdministrativaService.php` para avance monotónico al liberar asignación.
- `tests/php/etapa6_2_fecha_horarios_capacidad.php` para instalación temporal y fechas deterministas.
- `tests/php/etapa11_5_concurrencia_worker.php` y `tests/php/etapa11_5_concurrencia_completa.php`.
- `tests/php/etapa11_5_version_asignaciones.php`.
- `tests/php/etapa11_5_instalacion_limpia.php`.
- `scripts/run-tests.php`.
- Este reporte.

No se modificó el esquema ni se dejaron fixtures de navegador en la base principal.

## 22. Limitaciones

Quedan pendientes de una herramienta externa o de ejecución manual asistida: inspección Network, conteo de POST/polling y requests pendientes, zoom real al 200 %, lector de pantalla real y recorrido completo de teclado en runtime. El reporte los marca como no concluyentes, no como aprobados por inferencia.

## 23. Riesgos pendientes, por severidad

1. **Alto:** la aceptación WCAG 2.2 AA completa no puede cerrarse sin lector de pantalla y zoom 200 % operables verificables.
2. **Alto:** la ausencia de inspección Network impide probar formalmente duplicación de POST, polling y requests pendientes.
3. **Medio:** el recorrido físico completo de teclado no fue concluyente en el navegador integrado, aunque el contrato estático sí pasó.
4. **Bajo:** el build mantiene advertencias de deprecación de Sass y `fs.Stats`; no bloquean el producto actual.

## 24. Decisión final

**¿La cobertura concurrente A–L está completa?** Sí. `12/12` en tres instalaciones limpias consecutivas.

**¿Las mutaciones autenticadas están validadas end-to-end?** Sí, con condiciones: los recorridos público, admin y POS pasaron en navegador local, pero Network no pudo observarse formalmente.

**¿La accesibilidad cumple el objetivo aplicable de WCAG 2.2 AA?** Sí, con condiciones: contraste, estructura y contratos pasan; faltan zoom 200 %, lector real y recorrido completo de teclado concluyentes.

**¿Es seguro iniciar la Etapa 12?** No todavía. No se inicia automáticamente; primero deben cerrarse las validaciones externas indicadas en los riesgos alto/medio.
