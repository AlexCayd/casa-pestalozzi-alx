# Informe de pruebas funcionales M3 — Recetas, subrecetas y Almacenes

6 de noviembre de 2026 · ejecución del plan `pruebas-almacenes.md`.

Sólo se probó y se reportó. No se modificó código de producto, no se hizo push ni
merge y no se migró la base configurada en `includes/.env`. Las únicas escrituras
son el runner `scripts/tests/pruebas-http-m3-funcional.php` y este informe.

## 1. Revisión y entorno

| Dato | Valor |
| --- | --- |
| Revisión probada | `6faa9b6` (`origin/refactor-almacenes`), en un worktree propio sin los cambios locales del árbol principal |
| PHP | 8.5.7 (CLI, ZTS, Windows x64) con `mysqli`, `mbstring`, `bcmath` y `curl` |
| MySQL | 8.0.46 |
| Node | 24.16.0 |
| Sistema | Windows 11 Pro, Git Bash y PowerShell 5.1 |

`vendor` se copió del árbol principal y se regeneró su autoload dentro del worktree.
En el primer intento se usó un enlace de directorio y el autoload cargaba las clases
del árbol principal. Esa corrida se descartó y se repitió con el código del worktree.
`node_modules` se enlazó (los lockfiles son idénticos a la revisión) y se ejecutó
`npm run build` en el worktree.

## 2. Línea base automatizada

| Comando | Resultado |
| --- | --- |
| `npm run test:php` | PASS |
| `npm run test:js` | FAIL en `run-reservaciones-notification-docs.cjs`: `docs/` contiene `API.md` e `integracion-a6-main.md`, que la prueba no espera. Ajeno a M3 y ya documentado en la línea base. La cadena se detiene ahí |
| `run-tipo-venta.php` | PASS: 49 comprobaciones |
| `run-faltantes.php` | PASS: 68 |
| `run-faltantes-vista-db.php` | PASS: 18 |
| `run-transferencias-m05b.php` | PASS: 114 |
| `run-articulos-m2.php` | PASS: 130 |
| `run-resolucion-articulos-m2.php` | PASS: 29 |
| `run-almacenes-3c.php` | PASS: 158 |
| `run-almacenes-3c-concurrencia.php` | PASS: 17 |
| `run-menu-tipo-venta-ui.cjs` | FAIL en este checkout: la expresión de la prueba espera `\n` y la copia de Windows tiene finales CRLF. No se comparó con una copia en LF |
| `run-menu-m11.php`, `run-variantes-consumo.php`, `run-ux2.php` | FAIL, como anticipa la línea base («Aplica la migración A3…», «Conservar presentación y sección») |

Los runners 3C sólo aceptan `--host 127.0.0.1 --base casa-pestalozzi` y además exigen
que coincidan con `includes/.env`. Para ejecutarlos se cambiaron esas dos líneas sólo
en el `.env` copiado al worktree y se restauró al terminar. La base `casa-pestalozzi`
no existe. Los runners sólo crean y eliminan bases temporales.

## 3. Cómo se probó

- **Harness 3E.** `servir-instalacion-3e.php` contra `127.0.0.1:8094`, con base
  temporal `cp_multinegocio_test_*` y salida privada en un `CODEX_HOME` propio.
  Cada corrida completa empezó con un harness nuevo, y al detenerlo se verificó que
  se eliminaran la base, las sesiones y el archivo privado.
- **Runner nuevo.** Todo cambio de datos pasa por la interfaz o la API: formularios
  con su CSRF, POS con su token de personal y sesiones de contraseña más 2FA o NIP.
  La base temporal sólo se lee con `SELECT` en una sesión `READ ONLY`. Al cierre de
  cada sección se ejecuta la consulta de integridad del plan.
- **Navegador.** Recorrido parcial en escritorio con `operador_restaurante` (Almacenes,
  aviso de faltantes y topbar). La extensión de Chrome se desconectó y no hubo
  recorrido en móvil (ver §7).

Resultado de la corrida completa: **103 PASS, 19 OBS y 2 FAIL** en 124 casos.
Las ocho consultas de integridad (saldo = suma de movimientos) devolvieron cero filas.

## 4. Casos

### Prueba 1 — Alta en las tres categorías

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 1.1 Catálogo | PASS | Global crea el vino sin asociaciones ni saldo. Restaurante recibe 403 en el alta. El duplicado da «El artículo ya existe en el catálogo maestro». Contenido por pieza en `pza` se rechaza |
| 1.2 Capacidades y entrada | PASS | Ingrediente más existencias. Barra con mínimo 1500 ml. Entrada de 6 piezas = `4500.000` ml, lectura «6 piezas», un movimiento. Saldos iniciales de §1.5 completos |
| 1.3 Preparado (copa) | PASS | Sin tipo de venta no se guarda. La copa queda `preparado/confirmado`, con presentación «Copa» de vino 150 ml almacenado y origen Barra |
| 1.3d | OBS | La primera versión habilitada exige orígenes (`ALMACEN_ORIGEN_FALTANTE`), pero la página sólo ofrece orígenes de artículos de versiones ya publicadas. Hay que publicar deshabilitada, elegir orígenes y volver a publicar habilitada |
| 1.4 Reventa (botella) | PASS | `reventa/confirmado`. El editor fija un artículo almacenado. Dos componentes dan `REVENTA_COMPOSICION`. Una variante que agrega un artículo da `REVENTA_SIN_AJUSTES`; una de sólo precio se guarda. Pasar a reventa una oferta con tres componentes se rechaza sin cambios |
| 1.5 Ingrediente | PASS | Salsa: 1000 g = tomate 800 g + vino 200 ml. Pasta al vino con salsa desglosada y orígenes en Cocina. «Dónde se utiliza» del vino enlaza copa, botella, pasta y salsa |
| 1.5e | OBS | «Dónde se utiliza» rotula cada presentación sólo con su nombre («Copa · Versión 2») y repite cada versión: dos «Copa» de productos distintos no se distinguen |
| 1.6a | OBS | Precondición inexistente: las 129 ofertas en `sugerido` son `preparado` y ninguna es `reventa`. No se pudo provocar el rechazo por un segundo componente |
| 1.6b–c | PASS | Lote de tres sugeridas desde «Por confirmar». La huella de precio, receta y disponibilidad de los 112 originales queda igual |
| 1.7 | OBS | Una oferta con Copa 150 ml y Botella 750 ml se guarda como preparado y se puede pasar a reventa. El tipo es de la oferta y no cambia el descuento (AP-6) |

### Prueba 2 — Preparado y faltantes

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 2.1 Camino feliz | PASS | 2 × Pasta: pasta −240 g, vino −160 ml y tomate −240 g, exactos. Una resolución por item y sin faltantes |
| 2.1c | OBS | El vino directo (100 ml) y el de la salsa (60 ml) quedan en un solo movimiento de −160 ml |
| 2.2a–b | PASS | Oferta 1002: harina −80 g y leche −20 ml. La subreceta «Porción de leche» se vincula desde «Preparaciones anteriores por vincular» |
| 2.2c | OBS | Después de vincular, la venta siguió descontando harina −80 y leche −20. No se reprodujo el cambio de descuento que anticipa AP-7 |
| 2.3a–d | PASS | 10 × Copa de fresas con Barra en 20: HTTP 200, Barra en 0 con venta −20, faltante de 30 ligado al item, fila en `buzon_notificaciones` y aviso en Almacenes |
| **2.3e** | **FAIL** | AP-1. `operador_restaurante` recibe 403 en `/admin/api/buzon/resumen`. `admin_demo` recibe `cantidad: 0` con faltantes abiertos. En el navegador el operador no tiene buzón en el topbar |
| 2.4 | PASS | Un ticket con dos productos sin saldo genera un faltante por artículo y almacén. El replay con la misma clave no descuenta ni duplica |
| 2.5 | PASS | Con producción previa y la salsa servida como almacenada, el faltante es de salsa (100 g), no de tomate ni de vino, y la comanda sale |
| 2.6a–b | PASS | Un reparto con un origen en 0 se rechaza completo. Una suma inexacta da `SUMA_INVALIDA` |
| 2.6c | OBS | AP-12 confirmado: «0.00» en un origen no usado se rechaza con «Cada almacén aparece una vez con cantidad positiva» |
| 2.6d | PASS | Mismo negocio: regularización `recibida`, `transferencia_salida` + `transferencia_entrada` + venta `venta_conciliada` con `faltante_id`. Barra en 0 y faltante `conciliado` |
| 2.6e–f | PASS | Otro negocio: la parte de Refrigeración se aplica, el faltante queda `en_confirmacion` y `operador_global` lo confirma |
| 2.6g–h | PASS | Confirmar 30 desde un Central con 10 falla por saldo. El rechazo con motivo devuelve la parte a pendiente |
| 2.6i–k | PASS | Bandeja «Ningún almacén propio cubre». Tras una entrada se concilia. Dos pestañas: la segunda da `VERSION_CONFLICTO` |
| 2.7 | PASS | Pendiente → `anulado` sin compensación. En confirmación → transferencia `cancelada`. Conciliado → Barra recibe 50 y la transferencia sigue `recibida` |
| 2.8x | OBS | Un producto nuevo no admite receta anterior (409: «se configura mediante Presentaciones») |
| 2.8a | PASS | Una variante que agrega pasta, sin origen en la copa, da `ALMACEN_ORIGEN_FALTANTE` y rechaza la comanda completa |
| 2.8b | OBS | AP-2: la copa de fresas, válida, tampoco sale. La variante se guardó sin pedir el origen del artículo que agrega |
| 2.8c | PASS | Una asociación inactiva rechaza la comanda completa (`ALMACEN_ORIGEN_FALTANTE`) |
| 2.9 | OBS | AP-3: 8 tickets dejan 8 faltantes que se concilian uno por uno y 0 avisos nuevos (ya había uno del día para ese almacén y artículo) |

### Prueba 3 — Reventa

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 3.1 | PASS | Una botella: un solo movimiento de −750 ml en Barra. Central y Cocina intactos. «5 piezas» |
| 3.2 | PASS | Dos copas: 3450 ml, «4 piezas + 450 ml» |
| 3.3a | PASS | Con 600 ml se descuentan 600 y queda un faltante de 150 ml |
| 3.3b | OBS | AP-6: la venta de una botella que no está completa no se bloquea |
| 3.4 | PASS | Cancelar la botella de 3.1 regresa 750 ml a Barra |

### Prueba 4 — Entradas, mermas y transferencias

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 4.1 | PASS | 2 piezas + 100 ml = 1600 ml. Costo por pieza 300 → `0.4000`/ml y por ml `0.5000` en el movimiento; el maestro no cambia. La misma clave no duplica |
| 4.2a–b | PASS | Los seis motivos de merma. Una merma mayor que el saldo da «no tiene saldo suficiente» |
| 4.2c | OBS | AP-9: «Faltante de inventario» como motivo de merma convive con «faltantes por conciliar» |
| 4.3 | PASS | Cocina → Barra: el origen descuenta al enviar y el destino suma al recibir. El historial guarda actor y motivo en cada paso. Con NIP la acción da 403 |
| 4.4 | PASS | Entre negocios: el destino no autoriza ni envía. Una recepción parcial deja `incidencia` y el retorno la cierra. Rechazar o cancelar no mueve existencias. El exceso se rechaza al autorizar. Sin reserva: el envío se rechaza si otra operación consumió el origen |
| 4.5 | PASS | En una regularización, Global sólo ve confirmar y rechazar; Restaurante, ninguna acción |
| 4.6a–c | PASS | La lectura no ve botones y su POST da 403. Un almacén o artículo ajeno se rechaza. Almacenes sin CSRF da 403 |
| **4.6d** | **FAIL** | El editor de receta anterior `/admin/recetas/editar` no valida CSRF. En el runner, un cuerpo inválido sin token llega a la validación (oferta 1001, 422). En una comprobación aparte sobre la misma base temporal, reenviar sin token los componentes vigentes de la oferta 1402 creó la versión 2 de su receta |

### Prueba 5 — «Conteo físico»

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 5.1 | PASS | Ajuste con delta = contado − saldo. Igual no crea movimiento. Negativo da «Un conteo físico no puede ser negativo». 1 pieza + 250 ml = 1000 ml. Un conteo con versión vieja tras una venta da `VERSION_CONFLICTO` |
| 5.2a | OBS | Con un faltante pendiente en Barra, el conteo lo ignora: no lo cierra ni lo reduce |
| 5.2b | OBS | El conteo baja Refrigeración a 0 y la conciliación desde Refrigeración falla por saldo: no hay doble descuento, pero el faltante queda sin origen posible |
| 5.2c | OBS | La merma guarda su motivo (`dano`). El conteo, `operacion_administrativa` con nota vacía: el historial no dice por qué faltó |
| 5.2d | OBS | Un conteo que sube el saldo tampoco deja motivo |

**Dictamen.** Se cumplen las condiciones de 5.2c y 5.2d: el conteo deja diferencias
sin motivo que no se distinguen de una merma. Por la regla del plan, se recomienda
**eliminar** el botón en su forma actual. Si el negocio hace conteos periódicos,
**rediseñarlo**: motivo obligatorio de la diferencia y advertencia o bloqueo cuando
haya faltantes pendientes del artículo en ese almacén. No hubo doble descuento
(5.2b), pero sí un faltante que queda sin origen.

### Prueba 6 — «Catálogo compartido»

| Caso | Estado | Evidencia |
| --- | --- | --- |
| 6.1 | OBS | `/admin/almacenes/catalogo` y `/admin/recetas/articulos` muestran «Catálogo compartido» y la ficha. La vinculación de preparaciones apareció en Recetas (2.2b); al llegar a 6.1 ya no quedaban pendientes, así que no se pudo comparar su ausencia en Almacenes |
| 6.2 | PASS | `%`, `_` y `!` se escapan. Páginas de 30. Restaurante ve todo el maestro. Filtro de estado. Los enlaces conservan `volver=` |
| 6.3a–c, e | PASS | Restaurante no edita la identidad (403). La unidad con historial no cambia. No se inactiva con saldo. Cambiar el contenido por pieza no altera saldo ni movimientos |
| 6.3d | OBS | Con una transferencia abierta la inactivación se rechaza, pero el artículo también tenía saldo: no se aisló la causa |
| 6.4 | OBS | AP-8: sigue «Existencias previas requeridas» |
| 6.5 | PASS | La producción previa exige elaboración y almacenamiento. Las versiones se consultan y comparan |
| 6.6 | PASS | `/admin/global/catalogo` → `?vista=catalogo` → `/admin/global/almacenes/catalogo`: doble redirección |

## 5. Action points

| # | Veredicto | Evidencia |
| --- | --- | --- |
| AP-1 | Confirmado | 2.3e y recorrido: no hay buzón para el operador de inventario y el buzón completo no cuenta faltantes |
| AP-2 | Confirmado | 2.8a–c. Además, una variante puede agregar un artículo sin origen y bloquear la comanda |
| AP-3 | Confirmado | 2.9 |
| AP-4 | Confirmado | 5.2 y dictamen |
| AP-5 | No probado | No se escribió la prueba de concurrencia entre cancelación y confirmación |
| AP-6 | Confirmado | 1.7 y 3.3 |
| AP-7 | No reproducido | 2.2c: tras vincular, la venta siguió desglosando la subreceta |
| AP-8 | Confirmado | 6.4 |
| AP-9 | Confirmado | 4.2c |
| AP-10 | Parcial | 6.1: misma sección en ambas rutas; la ausencia de la vinculación en Almacenes no se pudo comparar |
| AP-11 | Confirmado y ampliado | La salida privada exige `CODEX_HOME/visualizations`. En Windows, `proc_terminate` no detiene el `php -S` hijo y el harness queda esperando: hubo que detener el servidor para que limpiara. `php -S` atiende una petición a la vez, así que el navegador y el runner compiten. Los runners 3C exigen una identidad fija de `.env` |
| AP-12 | Confirmado | 2.6c |
| AP-13 | Confirmado antes del merge | `origin/main` tiene `services/Pos/CajaMostrador.php`, que llama a `consumirVenta`, y cambió `Almacenes.php` (+39/−4) desde la base común. `git merge-tree` resuelve `Almacenes.php` sin conflicto y marca conflicto en `CLAUDE.md`, `database/ddl.sql`, `docs/arquitectura.md`, `docs/desarrollo/pruebas.md`, `gulpfile.js`, `package.json`, `views/admin/menu/variantes.php` y bundles generados. Repetir las pruebas 2 y 3 en Panadería tras integrar |
| AP-14 | Confirmado | 2.6f: Global escribe la venta conciliada en Barra al confirmar. 2.6j: una entrada reabre avisos |

### Hallazgos nuevos

| # | Hallazgo | Prioridad | Evidencia |
| --- | --- | --- | --- |
| N-1 | El editor de receta anterior (`POST /admin/recetas/editar`) guarda sin validar CSRF | Alta | 4.6d: versión nueva v1 → v2 sin token |
| N-2 | La primera versión habilitada de una presentación no se puede publicar: pide orígenes que sólo se ofrecen después de publicar | Media | 1.3d |
| N-3 | Una variante puede agregar un artículo sin origen y deja la oferta sin vender | Media | 2.8a–b |
| N-4 | `/admin/api/buzon/resumen` da 403 a quien no tiene Reservaciones | Media | 2.3e |
| N-5 | «Dónde se utiliza» no nombra el producto de cada presentación | Baja | 1.5e |
| N-6 | El fixture no tiene ninguna oferta sugerida como reventa (precondición de 1.6) | Baja | 1.6a |
| N-7 | En el navegador, `?vista=faltantes` y «Conciliar faltantes» no abrieron la bandeja mientras el runner ocupaba el servidor (la página quedó en `readyState: interactive`). Despachar `admin:open-modal` sí la abre. **No concluyente**: hay que repetirlo sin carga | Por confirmar | Recorrido |

## 6. Reproducción

```sh
git worktree add --detach ../qa-m3 6faa9b6
# vendor real (no enlazado) con autoload regenerado; node_modules según el lockfile
composer install --no-interaction && npm ci && npm run build
export CODEX_HOME="$HOME/.cp-qa"; mkdir -p "$CODEX_HOME/visualizations"
php scripts/tests/servir-instalacion-3e.php --host <DB_HOST> --base <DB_NAME> --entorno development \
  --fixture-salida "$CODEX_HOME/visualizations/fixture-m3.json"
# en otra terminal, con el harness arriba:
php scripts/tests/pruebas-http-m3-funcional.php --fixture "$CODEX_HOME/visualizations/fixture-m3.json" \
  [--resultados ABS_JSON] [--solo 1,2,3,4,5,6]
```

El runner necesita un harness recién levantado: crea datos con nombres fijos
(`QA M3 …`) y la prueba 1 no es repetible sobre la misma base. `--solo` ejecuta
secciones sueltas sobre un harness donde la prueba 1 ya corrió. Una corrida completa
tardó unos 40 minutos en esta máquina. Al terminar, Enter en el harness limpia la base
temporal, las sesiones y la salida privada; en Windows puede hacer falta detener el
`php -S` en 8094 (AP-11).

## 7. Límites

- **Navegador.** Sólo escritorio y sólo Almacenes, aviso de faltantes y topbar. La
  extensión de Chrome se desconectó antes de la bandeja, el POS, Presentaciones, el
  catálogo y la vista en móvil. Esas pantallas se probaron por HTTP, sin juicio visual.
- **AP-5** no se probó.
- **1.6:** el rechazo por un segundo componente en una sugerida como reventa no se pudo
  provocar porque no existe la precondición.
- **6.3d:** no se aisló la causa del rechazo (saldo o transferencia abierta).
- **Plataforma:** Windows con PHP 8.5 y MySQL 8.0.46. `run-menu-tipo-venta-ui.cjs` no
  se ejecutó con una copia en LF.
- Un HTTP 200 no se tomó como prueba: cada caso se confirmó con consultas `SELECT`
  sobre la base temporal.
