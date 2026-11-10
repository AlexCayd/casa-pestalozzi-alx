# Plan de pruebas funcionales — Recetas, subrecetas y Almacenes (M3)

6 de noviembre de 2026 · Emilio Arriaga

> **Para Claude Code.** Este plan se ejecuta sobre la rama `refactor-almacenes` (revisada en `6faa9b6`). Rige junto con `CLAUDE.md` y `docs/arquitectura.md` §15–§16. El objetivo es **probar y reportar**, no corregir: no modifiques código de producto, no hagas push, merge ni cambios en `main`, y no migres la base configurada en `includes/.env`. Las únicas escrituras permitidas son pruebas nuevas en `scripts/tests/` y el informe final. Todo hallazgo que requiera cambiar código se anota como action point.

## 0. Cómo funciona hoy

Antes de probar, conviene alinear los términos del negocio con los del sistema:

| Lo que dice el negocio | Cómo existe en el sistema |
| --- | --- |
| Producto **que se produce** (copa de vino) | Oferta con `tipo_venta = 'preparado'`. Se descuenta con su receta legada o su presentación M2. |
| Producto **producido** (botella de vino) | Oferta con `tipo_venta = 'reventa'`. Una reventa confirmada solo admite **un** componente `almacenado` y variantes de precio. |
| **Ingrediente** (vino para la salsa de una pasta) | No es un producto del menú: es un **artículo** (`ingredientes`) con la capacidad `componente` («Se usa como ingrediente»). El mismo artículo puede ser ingrediente y reventa. |
| Receta | Receta legada (`recetas` / `receta_componentes`, versionada por oferta) **o** presentación M2 (`presentacion_versiones` / `presentacion_componentes`). Si la oferta tiene presentaciones, el POS exige elegir una. |
| Subreceta | Dos caminos. **Legado**: `subrecetas` con rendimiento, que se desglosa un nivel; ya no se crean nuevas (`/recetas/subrecetas/create` redirige a Artículos). **M2**: un artículo con elaboración propia (`articulo_recetas`, versiones inmutables) que se usa en modalidad `desglosar` o `almacenado`. |
| Almacén del que sale cada insumo | `oferta_consumos`: un solo almacén por artículo y oferta, elegido a mano y sin alternativa automática. |
| Falta stock al vender | `Almacenes::operar('venta')` descuenta lo disponible, deja el saldo en 0 y registra el resto en `faltantes_inventario` con un aviso `inventario.faltante`. El admin lo concilia en `?vista=faltantes`. |
| Vino por copa y por botella | Un solo artículo en `ml` con `contenido_pieza = 750`. La botella descuenta 750 ml y la copa 150 ml del mismo saldo. |

Archivos clave:

- `services/Inventory/Almacenes.php`, `Faltantes.php`, `ConsumoOferta.php`, `ResolucionArticulos.php`, `AlmacenesConsumo.php`, `Transferencias.php`, `AdministracionAlmacenes.php`, `Articulos.php`
- `services/Menu/TipoVenta.php`, `PresentacionesOferta.php`, `RecetasOferta.php`, `Variantes.php`
- `services/Pos/Comercial.php`
- Vistas en `views/admin/almacenes/`, `views/admin/recetas/` y `views/admin/menu/form.php`

## 1. Preparación del entorno

### 1.1 Código y dependencias

```sh
git fetch origin refactor-almacenes
git switch --detach origin/refactor-almacenes   # o un worktree propio; no tocar trabajo ajeno
composer install --no-interaction
npm ci            # Node 24
npm run build
```

Requisitos: PHP con `mysqli`, `mbstring`, `bcmath` y `curl`; MySQL 8.0.16 o posterior; `includes/.env` configurado según `docs/desarrollo/instalacion.md`.

### 1.2 Línea base automatizada

Corre esto antes de las pruebas funcionales y registra el resultado de cada comando. Para los runners con base propia, usa los mismos `--host`, `--base` y `--entorno` que `includes/.env`.

```sh
npm run test:php
npm run test:js
php scripts/tests/run-tipo-venta.php --host 127.0.0.1 --base <DB_NAME> --entorno development
php scripts/tests/run-faltantes.php --host 127.0.0.1 --base <DB_NAME> --entorno development
php scripts/tests/run-faltantes-vista-db.php --host 127.0.0.1 --base <DB_NAME> --entorno development
php scripts/tests/run-almacenes-3c.php
php scripts/tests/run-almacenes-3c-concurrencia.php
php scripts/tests/run-transferencias-m05b.php    # revisa en el script los argumentos que exige
php scripts/tests/run-articulos-m2.php
php scripts/tests/run-resolucion-articulos-m2.php
node scripts/tests/run-menu-tipo-venta-ui.cjs
```

Fallas que ya existen en la línea base, según `docs/desarrollo/pruebas.md` (Cierre M3): `run-reservaciones-notification-docs.cjs`, `run-menu-m11.php`, `run-variantes-consumo.php` y `run-ux2.php`. No las atribuyas a M3 sin compararlas con la base.

### 1.3 Servidor HTTP desechable (harness 3E)

`scripts/tests/servir-instalacion-3e.php` crea una base temporal `cp_multinegocio_test_*` desde los SQL versionados, siembra cuentas efímeras y levanta `php -S 127.0.0.1:8094`.

**Ajuste obligatorio para Claude Code:** la salida privada del fixture solo se acepta dentro de `$CODEX_HOME/visualizations` (ver `servir3eSalidaPrivada`). En Linux, antes de arrancar:

```sh
export CODEX_HOME="$HOME/.cp-qa"
mkdir -p "$CODEX_HOME/visualizations" && chmod 700 "$CODEX_HOME" "$CODEX_HOME/visualizations"
php scripts/tests/servir-instalacion-3e.php --host 127.0.0.1 --base <DB_NAME> --entorno development \
  --fixture-salida "$CODEX_HOME/visualizations/fixture-m3.json"
```

Reglas del harness:

- Deja el proceso abierto mientras pruebas. Al terminar, envía Enter por STDIN: el script borra la base temporal, las sesiones y el archivo privado.
- El JSON contiene credenciales efímeras. **No lo imprimas, no lo copies al informe y no lo versiones.** Léelo solo desde tus scripts.
- El reto 2FA del panel en entorno `test` expone el código en `data-otp-usar` de `/login/verificacion`. `scripts/tests/cliente-http-3e.php` ya implementa el login completo.

Cuentas que siembra el harness:

| Cuenta | Ámbito | Permisos relevantes |
| --- | --- | --- |
| `administrador_global` | Global | `inventario`/`productos` editar; facultades `almacenes.crear` y `catalogo.editar` |
| `operador_restaurante` | Restaurante | `inventario`, `productos` y `menu` editar |
| `lectura_restaurante` | Restaurante | Solo ver |
| `operador_global`, `operador_panaderia`, `lectura_*` | Su ámbito | Equivalentes |
| `piso_nip` | Restaurante | `pdv` operar (POS), login por NIP |

Almacenes del fixture: `1001` Almacén Central (Global), `1002` Cocina, `1005` Barra y Maridaje y `1006` Refrigeración (Restaurante), `1003` Producción y `1007` Producto Terminado (Panadería). Barra y Refrigeración **no** tienen asociaciones iniciales. Después de instalar ya existe un faltante de migración pendiente en Cocina: es la línea base, no un error.

### 1.4 Herramientas de ejecución

- **Flujos con datos:** escribe un runner nuevo, `scripts/tests/pruebas-http-m3-funcional.php`, que reutilice `cliente-http-3e.php` (sesiones independientes, CSRF, 2FA). Debe recibir `--fixture <ruta>` igual que `pruebas-http-3e.php`.
- **POS:** usa `/api/abrir-ticket`, `/api/enviar-comanda` y `/api/cancelar-item` con el token `data-staff-csrf` de `/punto-de-venta`, como en `run-restaurante-3d4-http.php`.
- **Interfaz:** recorre las pantallas en navegador contra `http://127.0.0.1:8094` con la sesión del rol correspondiente, en escritorio y en móvil. Si usas Playwright, instálalo fuera del repo (no lo agregues a `package.json`).
- **Verificación:** haz consultas SQL de solo lectura sobre la base temporal (`base_temporal` del fixture). Nunca escribas en ella por SQL: todo cambio de datos pasa por la interfaz o la API.

Consultas de verificación:

```sql
-- Integridad: cada saldo debe igualar la suma de sus movimientos.
SELECT aa.almacen_id, aa.articulo_id, aa.saldo, COALESCE(SUM(m.delta),0) AS ledger
FROM almacen_articulos aa
LEFT JOIN movimientos_inventario m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id
GROUP BY aa.almacen_id, aa.articulo_id, aa.saldo
HAVING aa.saldo <> COALESCE(SUM(m.delta),0);

-- Movimientos de un item vendido.
SELECT tipo, almacen_id, articulo_id, delta, motivo, costo_unitario, faltante_id
FROM movimientos_inventario WHERE ticket_item_id = ? ORDER BY id;

-- Faltantes y avisos.
SELECT id, almacen_id, articulo_id, ticket_item_id, origen, cantidad, cantidad_conciliada,
       cantidad_en_confirmacion, estado, version
FROM faltantes_inventario ORDER BY id DESC;
SELECT id, unidad_id, entidad_id, prioridad, cerrada_at, cierre_motivo, dedup_key
FROM buzon_notificaciones WHERE tipo = 'inventario.faltante' ORDER BY id DESC;
```

Ejecuta la consulta de integridad al final de **cada** sección. Cualquier fila que devuelva es una falla crítica.

### 1.5 Datos de prueba que se crean por la interfaz

Crear estos datos **forma parte de la prueba 1**. Usa nombres con el prefijo `QA M3` para encontrarlos fácilmente.

| Dato | Configuración |
| --- | --- |
| Artículo `QA M3 Vino tinto` | Unidad `ml`, contenido por pieza 750, costo 0.40. En Restaurante: ingrediente y mantiene existencias. Asociado a Barra (mínimo 1500 ml), a Cocina y al Almacén Central de Global. |
| Artículo `QA M3 Fresa` | Unidad `pza`. Asociado a Barra, a Refrigeración y al Almacén Central. |
| Artículo `QA M3 Pasta seca` | Unidad `g`. Asociado a Cocina. |
| Tomate (`1013`, del fixture) | Asociarlo a Cocina: el fixture no lo trae asociado. |
| Artículo `QA M3 Salsa al vino` | Unidad `g`, con elaboración propia: rendimiento 1000 g = tomate 800 g + vino 200 ml. Asociado a Cocina. |
| Saldos iniciales (por Entrada) | Barra: vino 6 piezas = 4500 ml y fresas 20. Refrigeración: fresas 40. Central: vino 4 piezas y fresas 30. Cocina: pasta 5000 g, tomate 5000 g y vino 2 piezas = 1500 ml. |
| Oferta `QA M3 Copa de vino tinto` | Preparado. Presentación «Copa»: vino 150 ml `almacenado`. Origen: Barra. |
| Oferta `QA M3 Botella de vino tinto` | Reventa. Editor de reventa: vino 750 ml por unidad. Origen: Barra. |
| Oferta `QA M3 Pasta al vino` | Preparado: pasta 120 g + vino 50 ml (ingrediente) + salsa 150 g `desglosar`. Orígenes: Cocina. |
| Oferta `QA M3 Copa de fresas` | Preparado: fresas 5 pza. Origen: Barra. |

## 2. Casos de prueba

Registra cada caso como **PASS**, **FAIL** u **OBS** (funciona según el contrato, pero contradice lo que pide el negocio o es confuso), con su evidencia: respuesta HTTP o captura, filas SQL y el ID del ticket o movimiento.

### Prueba 1 — Alta de un producto en cada una de las 3 categorías

1.1 **Catálogo.** Como `administrador_global`, crea `QA M3 Vino tinto` en `/admin/global/almacenes/catalogo/nuevo`. Esperado: queda creado sin asociaciones ni saldo. Como `operador_restaurante`, abrir `/admin/almacenes/catalogo/nuevo` responde 403. Un nombre duplicado da `ARTICULO_DUPLICADO`. Declarar contenido por pieza en un artículo `pza` da `CONTENIDO_PIEZA_INVALIDO`.

1.2 **Capacidades.** En Restaurante, abre la ficha del artículo y activa «Se usa como ingrediente» y «Mantiene existencias». Asócialo a Barra. Registra una entrada de 6 piezas. Esperado: saldo `4500.000 ml`, lectura «6 piezas» y un solo movimiento `entrada`.

1.3 **Preparado (copa).** Crea `QA M3 Copa de vino tinto` en `/admin/menu/create`. Esperado: el formulario no se guarda sin elegir tipo de venta y la oferta queda `preparado/confirmado`. Configura la presentación y el origen Barra.

1.4 **Reventa (botella).** Crea `QA M3 Botella de vino tinto` como reventa. Esperado: el editor pide un solo artículo y su cantidad por unidad vendida. Casos negativos:
- Publicar una versión con dos componentes da `REVENTA_COMPOSICION`.
- Una variante que agrega un artículo da `REVENTA_SIN_AJUSTES`; una variante solo de precio se guarda.
- Cambiar a reventa una oferta con dos componentes se rechaza sin cambios.

1.5 **Ingrediente.** Crea `QA M3 Pasta al vino` como preparado, con el vino de ingrediente. Esperado: el vino aparece en «Dónde se utiliza» de su ficha, junto con la copa y la botella.

1.6 **Sugeridos.** En `/admin/menu?tipo=por_confirmar`, elige una oferta sugerida como reventa y, mientras siga en `sugerido` (no tiene restricciones), agrégale un segundo componente. Luego confirma en lote esa oferta junto con otras dos sugeridas. Esperado: las válidas quedan confirmadas y la modificada sigue en `sugerido` con su motivo. Ninguno de los 112 productos originales cambia de precio, receta ni disponibilidad.

1.7 **OBS a registrar.** Crea una oferta única `QA M3 Vino tinto (carta)` con dos presentaciones, Copa de 150 ml y Botella de 750 ml. Anota qué tipo de venta acepta y si «preparado» o «reventa» cambia en algo el descuento. Ver AP-6.

### Prueba 2 — Preparado: descuento por receta y faltante sin detener la producción

2.1 **Camino feliz.** Como `piso_nip`, abre un ticket y envía 2 × `QA M3 Pasta al vino`. Esperado en Cocina:
- Pasta: −240 g.
- Vino directo: −100 ml.
- Salsa desglosada: 300 g de salsa → tomate −240 g y vino −60 ml.

Debe existir un solo `ticket_item_resoluciones` por item, sin faltantes. Verifica la aritmética exacta a milésimas.

2.2 **Subreceta legada.** Primero registra en Cocina una entrada de harina (`1001`) y de leche (`1002`) para que no se mezcle con faltantes. Luego envía la oferta `1002` del fixture: su receta `1002` lleva harina 80 g y la subreceta `1001` «Porción de leche» × 20, con orígenes en Cocina. Esperado: harina −80 g y leche −20 ml (20 sobre el rendimiento de 100 ml). Luego vincula esa subreceta a un artículo desde «Preparaciones anteriores por vincular» y vuelve a vender. Registra si ahora descuenta el artículo almacenado en lugar de la leche. Es un OBS esperado, ver AP-7.

2.3 **Faltante.** Con Barra en 20 fresas, envía 10 × `QA M3 Copa de fresas` (50 pza). Esperado:
- HTTP 200 y la comanda sale a producción.
- Barra queda en 0, con un movimiento `venta` de −20.
- `faltantes_inventario` registra 30 pendientes ligados al item.
- Hay una fila en `buzon_notificaciones` y el aviso «1 faltante por conciliar» aparece en `/admin/almacenes`.

**Verifica si el aviso aparece en el buzón del topbar.** Lo que se sospecha está en AP-1.

2.4 **Varios faltantes a la vez.** Un ticket con dos productos a los que les falta stock genera un faltante por cada artículo y almacén. El replay de la misma comanda, con la misma `clave_idempotencia`, no descuenta ni crea otro faltante.

2.5 **Producción previa.** Marca la salsa como «Se elabora antes de vender» con existencias y úsala como `almacenado` en una presentación. Con salsa en 0, vende. Esperado: el faltante es de **salsa**, no de tomate ni de vino, y la comanda sale.

2.6 **Conciliación** (`/admin/almacenes?vista=faltantes`, como `operador_restaurante`):
Los números siguen los saldos de 1.5; cada faltante nuevo sale de otra venta de 10 copas con Barra en 0.

- **Por API** (la interfaz oculta los orígenes sin saldo): repartir 20 de Refrigeración y 10 de Barra, que está en 0, se rechaza completo. Ningún origen queda negativo.
- **Mismo negocio:** 30 de Refrigeración (que queda en 10) se aplica al instante. Esperado: transferencia `regularizacion` en estado `recibida` y los movimientos `transferencia_salida`, `transferencia_entrada` y `venta` (motivo `venta_conciliada`, con `faltante_id`). Barra sigue en 0 y el faltante queda `conciliado`.
- **Otro negocio, confirma:** faltante nuevo de 30 → 10 de Refrigeración y 20 del Almacén Central. La parte de Refrigeración se aplica y el faltante queda `en_confirmacion` hasta que `operador_global` confirme en Transferencias. Al confirmar queda `conciliado`.
- **Otro negocio, rechaza:** faltante nuevo → 30 del Almacén Central, que solo tiene 10. Al confirmar debe fallar por saldo. Luego `operador_global` lo rechaza con motivo: esa parte vuelve a pendiente y el aviso se reabre.
- Una suma inexacta da `SUMA_INVALIDA`.
- **Nadie alcanza:** con Refrigeración en 0, la bandeja marca «Ningún almacén propio cubre…» y el faltante sigue pendiente. Después de una entrada de 30 en Refrigeración, el aviso se reabre y se puede conciliar.
- Con dos pestañas abiertas sobre el mismo faltante, la segunda da `VERSION_CONFLICTO`.
- Escribir `0.00` en un origen que no se usa: registra si da error (AP-12).

2.7 **Cancelación.** Cancela un item con faltante pendiente: el faltante queda `anulado` y solo se compensa lo descontado. Cancela otro con una parte en confirmación: su transferencia queda `cancelada`. Cancela un tercero ya conciliado: la compensación regresa a Barra y la transferencia no se revierte.

2.8 **Lo que sí detiene la comanda** (registrar como OBS frente al requisito de no detener la producción):
- Una oferta con un artículo sin origen en `oferta_consumos` da `ALMACEN_ORIGEN_FALTANTE`.
- Un almacén o una asociación inactivos.

Confirma que se rechaza **la comanda completa**, aunque los demás productos estén bien.

2.9 **Volumen.** Envía 8 tickets separados con faltante de fresas el mismo día. Registra cuántas filas hay que conciliar una por una y cuántos avisos se crean (deduplicados por almacén, artículo y día). Ver AP-3.

### Prueba 3 — Reventa: descuento normal solo del almacén declarado

3.1 Vende 1 × `QA M3 Botella de vino tinto`. Esperado: un solo movimiento `venta` de −750 ml en **Barra**. El Almacén Central y Cocina, que también tienen vino, no se tocan. La lectura pasa a «5 piezas».

3.2 Vende 2 × `QA M3 Copa de vino tinto`. Esperado: −300 ml del mismo saldo, con lectura «4 piezas + 450 ml» (3450 ml).

3.3 Deja Barra en 600 ml con una merma y vende una botella. Esperado por contrato: descuenta 600 y crea un faltante de 150 ml. **Registra como OBS** si el negocio espera bloquear la venta de una botella que no está. Ver AP-6.

3.4 Cancela la botella: la compensación regresa 750 ml a Barra.

### Prueba 4 — Transferencias, mermas y entradas

4.1 **Entrada.**
- Captura en piezas más resto (2 piezas + 100 ml = 1600 ml).
- Costo por pieza frente a costo por ml: el costo queda en el movimiento y no cambia el costo maestro.
- La vista previa coincide con el resultado.
- Enviar dos veces el mismo formulario (misma `clave`) no duplica.

4.2 **Merma.** Prueba cada motivo de `Inventario::MOTIVOS_MERMA`. Una merma mayor que el saldo da `SALDO_INSUFICIENTE`. Registra la confusión del motivo «Faltante de inventario» frente a los faltantes de venta (AP-9).

4.3 **Transferencia en el mismo negocio** (Cocina → Barra, vino 1 pieza): solicitar → autorizar → enviar → recibir. Verifica los saldos en cada paso: el origen descuenta al enviar y el destino suma al recibir. El historial debe mostrar actor y motivo. Cada acción exige una sesión de panel (contraseña más 2FA): con una sesión de NIP debe rechazarse.

4.4 **Transferencia entre negocios** (Central de Global → Barra de Restaurante):
- Cada paso solo lo puede hacer su dueño (`AMBITO_DENEGADO` en caso contrario).
- Una recepción parcial deja `incidencia` con motivo, y un retorno desde el origen la cierra.
- Rechazar o cancelar antes de enviar no genera movimientos.
- Enviar más que el saldo se rechaza.
- Demuestra que no hay reserva: autoriza, consume el saldo del origen con otra operación y luego intenta enviar.

4.5 **Regularizaciones.** En Transferencias solo ofrecen «confirmar» y «rechazar», y únicamente al dueño del origen.

4.6 **Permisos.** `lectura_restaurante` no ve botones de operación y sus POST dan 403. Un `almacen_id` o `articulo_id` ajeno se rechaza. Un POST sin CSRF se rechaza.

### Prueba 5 — Botón «Conteo físico»: funcionamiento y si conviene conservarlo

Qué hace hoy: la operación es `ajuste`. El saldo pasa a la cifra contada y la diferencia queda como movimiento `ajuste` con motivo `operacion_administrativa`. No pide motivo de la diferencia y exige la versión de la asociación.

5.1 **Funcional.**
- Contar distinto del saldo crea un `ajuste` con delta = contado − saldo.
- Contar igual al saldo no crea movimiento.
- Un conteo negativo da `CONTEO_INVALIDO`.
- Captura en piezas más resto.
- Abre el diálogo, vende desde el POS y luego guarda el conteo: debe dar `VERSION_CONFLICTO`.

5.2 **Escenarios de ambigüedad** (documenta el resultado de cada uno):
- a) Contar en Barra con un faltante pendiente de fresas en Barra. ¿El conteo cierra, cambia o ignora el faltante?
- b) Las fresas salieron físicamente de Refrigeración sin registro. Contar Refrigeración (ajuste negativo) y luego conciliar el faltante desde Refrigeración. ¿Se descuenta dos veces o la conciliación falla por saldo?
- c) La misma pérdida registrada como merma (con motivo) y como conteo (sin motivo). ¿El historial permite distinguirlas?
- d) Un conteo que sube el saldo (apareció producto): ¿queda algún registro de por qué?

5.3 **Dictamen.** Recomienda **eliminar** el botón si se cumple cualquiera de estas condiciones:
- Produce doble descuento con la conciliación (5.2 b).
- Deja diferencias sin motivo que no se distinguen de una merma (5.2 c y d).
- El negocio no hace conteos periódicos.

Si el negocio sí cuenta inventario, recomienda **rediseñarlo** en lugar de quitarlo: motivo de la diferencia obligatorio, y bloqueo o advertencia cuando haya faltantes pendientes del artículo en ese almacén.

Lectura previa del código: el conteo no considera los faltantes y su motivo es genérico, así que **hoy es ambiguo**. Ver AP-4.

### Prueba 6 — «Catálogo compartido»

6.1 **Dos entradas.** `/admin/almacenes/catalogo` (exige `inventario/ver`) y `/admin/recetas/articulos` (exige `productos/ver`) muestran la misma sección «Catálogo compartido». Compara qué acciones ofrece cada una: alta, ficha y «Preparaciones anteriores por vincular». Ver AP-10.

6.2 **Lista.** Búsqueda (incluye `%`, `_` y `!` para comprobar el escape), filtro por estado, paginación de 30 y conservación de filtros al volver. Restaurante ve todo el maestro, incluidos artículos sin asociación propia.

6.3 **Alta y edición.**
- El alta solo funciona con edición de Almacenes local y Global más la facultad `catalogo.editar`.
- La identidad solo se edita desde Global.
- No se puede cambiar la unidad de un artículo con historial.
- No se puede inactivar un artículo con saldo o transferencias abiertas.
- Cambiar el contenido por pieza no altera saldo ni movimientos.

6.4 **Etiquetas.** La columna «Configuración del negocio» muestra «Existencias previas requeridas» para producción previa. Desde M3 eso ya no es cierto (AP-8).

6.5 **Ficha.** Revisa capacidades, la dependencia de producción previa con elaboración y almacenamiento, «Dónde se utiliza», versiones y comparación, y la vinculación de preparaciones (enlazar con 2.2).

6.6 **Redirecciones.** `/admin/global/catalogo` → `/admin/global/almacenes?vista=catalogo` → `/admin/global/almacenes/catalogo`. Registra la doble redirección.

## 3. Entregable

1. `scripts/tests/pruebas-http-m3-funcional.php` con los casos que se puedan automatizar, cada uno con una aserción y un mensaje en español.
2. `docs/pruebas/informe-m3-funcional.md` con:
   - Revisión probada (hash) y versiones de PHP, MySQL y Node.
   - Resultados de la línea base.
   - Tabla caso → PASS/FAIL/OBS → evidencia.
   - Action points confirmados o descartados.
   - Límites de lo que no se pudo probar.

   Sin credenciales, rutas privadas ni capturas con datos del fixture.
3. Comandos para reproducir el runner nuevo.

Informa solo lo que ejecutaste. Un HTTP 200 no acredita un flujo: confírmalo con SQL.

## 4. Action points preliminares

Salen de la lectura del código. Cada uno indica qué prueba lo confirma.

| # | Hallazgo | Evidencia | Propuesta | Prioridad | Confirma |
| --- | --- | --- | --- | --- | --- |
| AP-1 | El aviso de faltante no se ve en el buzón del topbar: `ReservacionBuzonBandejaService::fuente()` devuelve `null` para todo tipo que no sea de reservaciones, así que el aviso se guarda pero no se pinta ni se cuenta. Solo se ve al entrar a Almacenes. | `services/Reservations/ReservacionBuzonBandejaService.php`, `Faltantes::avisar` | Añadir una fuente `inventario.faltante` al buzón con enlace a `?vista=faltantes`, o un contador en el topbar. | Alta | 2.3 |
| AP-2 | La venta sí se detiene por configuración: si falta el origen en `oferta_consumos` o hay un almacén o asociación inactivos, se rechaza **toda** la comanda. | `ResolucionArticulos.php` (`ALMACEN_ORIGEN_FALTANTE`), arquitectura §16 | Decisión de negocio: impedir activar en el menú un producto sin orígenes, o rechazar solo esa línea. | Alta | 2.8 |
| AP-3 | Un faltante por cada item, almacén y artículo: un día con muchos tickets obliga a conciliar fila por fila. | `uq_faltante_item`, `_faltantes.php` | Conciliación agrupada por almacén, artículo y día, que reparta entre los faltantes abiertos. | Media-alta | 2.9 |
| AP-4 | «Conteo físico» es ambiguo: sobrescribe el saldo sin motivo, ignora los faltantes pendientes y puede duplicar el descuento con la conciliación. | `AdministracionAlmacenes::guardar` (motivo `operacion_administrativa`), `Almacenes::operar('ajuste')` | Eliminarlo, o rediseñarlo con motivo obligatorio y bloqueo por faltantes (según 5.3). | Alta | 5 |
| AP-5 | Bloqueos en orden inverso: la cancelación bloquea faltante → asignación → transferencia, y la confirmación de Global transferencia → asignación → faltante. Puede haber interbloqueo; dentro de la transacción del POS no se reintenta (`CONCURRENCIA_CONFLICTO`). | `Faltantes::anularPorCancelacion`, `Transferencias::regularizacion`, `TransaccionInventario` | Unificar el orden de bloqueo y añadir una prueba de concurrencia con dos procesos. | Media | Prueba nueva de concurrencia |
| AP-6 | El tipo de venta es de la oferta, no de la presentación, y no cambia el descuento: una copa de 150 ml cumple también las reglas de reventa. Una botella sin stock genera faltante en lugar de bloquearse. | `TipoVenta.php`, `PresentacionesOferta.php` | Definir si el tipo vive en la presentación y si la reventa sin stock debe bloquearse. | Media | 1.7, 3.3 |
| AP-7 | Vincular una subreceta legada a un artículo cambia en silencio el descuento: deja de desglosar sus ingredientes y descuenta el artículo almacenado. | `ConsumoOferta::preparar` (`articulo_correspondencias`) | Advertir en la confirmación de la vinculación y mostrar el cambio en la ficha. | Media | 2.2 |
| AP-8 | Etiqueta desactualizada: el catálogo muestra «Existencias previas requeridas» para producción previa, pero desde M3 la venta ya no las exige. | `views/admin/recetas/articulos.php` | Cambiar el texto a «Se elabora antes de vender». | Baja | 6.4 |
| AP-9 | El motivo de merma «Faltante de inventario» choca con el nuevo concepto de faltante de venta. | `Inventario::MOTIVOS_MERMA` | Renombrarlo («Diferencia de inventario») o retirarlo. | Baja | 4.2 |
| AP-10 | Hay dos entradas al catálogo, con permisos y funciones distintos: la vinculación de preparaciones solo está en Recetas. | `AdminArticulosController::index` / `maestro` | Una sola superficie, o diferenciar claramente sus títulos. | Baja-media | 6.1 |
| AP-11 | El harness 3E depende de `CODEX_HOME/visualizations` y M3 no tiene un recorrido HTTP con sesión (lo dice `pruebas.md`). | `servir-instalacion-3e.php`, Cierre M3 | Variable de entorno neutral para la salida privada e incorporar el runner de este plan a la suite. | Media | 1.3 |
| AP-12 | El formulario de conciliación solo descarta filas vacías si dicen exactamente `''`, `0` o `0.000`; un `0.00` se rechaza. | `AdministracionAlmacenes::guardar` (`conciliar_faltante`) | Normalizar la cantidad a decimal antes de filtrar. | Baja | 2.6 |
| AP-13 | La rama salió de un `main` anterior. Desde entonces `main` agregó `CajaMostrador.php` (POS de Panadería), que también llama a `consumirVenta`: al fusionar, Panadería también generará faltantes y es probable que haya conflictos en `Almacenes.php`. | `git log origin/main`, `services/Pos/CajaMostrador.php` en `main` | Repetir las pruebas 2 y 3 en Panadería después de integrar. | Alta antes del merge | Tras el merge |
| AP-14 | Efectos entre negocios por diseño: Global escribe la entrada y la venta en el almacén del restaurante al confirmar, y una entrada en cualquier negocio reabre avisos de los demás. | `Faltantes::aplicar`, `reactivarAvisos` | Documentarlo en permisos y en la arquitectura §16. | Baja | 2.6 |