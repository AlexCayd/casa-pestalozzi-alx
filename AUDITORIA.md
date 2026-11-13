# Auditoría M3 — Plan de corrección de Recetas, subrecetas y Almacenes

6 de noviembre de 2026 · rama `refactor-almacenes`, revisión `6faa9b6`.

Este plan reúne los hallazgos y los action points de la ejecución del plan de pruebas
`pruebas-almacenes.md`. Las pruebas y su evidencia están en
[docs/pruebas/informe-m3-funcional.md](docs/pruebas/informe-m3-funcional.md). El runner
que las reproduce es `scripts/tests/pruebas-http-m3-funcional.php`: 124 casos,
103 PASS, 19 OBS y 2 FAIL.

> **Para quien implemente.** Rige junto con `CLAUDE.md` y `docs/arquitectura.md`
> §15–§16. Un cambio a un contrato compartido (motor de Almacenes, consumo comercial,
> permisos) se justifica en arquitectura antes de editarlo. Todo cambio visual pasa
> por el subagente `ux-ui`. Cada punto se cierra con su criterio de aceptación
> probado; un HTTP 200 no basta.

## Estado del sistema tras las pruebas

Lo que sigue describe la revisión `6faa9b6`, probada en un worktree propio, sin los
cambios locales posteriores: filtro de almacenes en consulta y módulo de Proveedores,
que tienen sus propias pruebas. Las ocho consultas de integridad (saldo = suma de
movimientos) devolvieron cero filas: ningún saldo quedó descuadrado ni negativo.

### Lo que sí hace

**Catálogo y recetas**
- Global da de alta artículos del catálogo maestro. Restaurante recibe 403 y los
  duplicados se rechazan (1.1).
- El contenido por pieza convierte las entradas: 6 botellas de 750 ml son `4500.000`
  ml y se leen «6 piezas» (1.2).
- El tipo de venta distingue *preparado* (copa) de *reventa* (botella). La reventa
  rechaza dos componentes y las variantes que agregan artículos (1.3, 1.4).
- Las subrecetas se desglosan: salsa 1000 g = tomate 800 g + vino 200 ml (1.5).
- «Dónde se utiliza» enlaza el artículo con cada producto que lo usa (1.5).
- La huella de precio, receta y disponibilidad de los 112 productos originales no
  cambia (1.6).

**Venta y faltantes**
- La venta descuenta exacto del almacén de origen de cada artículo, incluidas las
  subrecetas (2.1, 2.2).
- Sin saldo, la venta **no se detiene**: descuenta lo disponible, deja un faltante
  ligado al item y avisa en Almacenes (2.3a–d).
- El replay con la misma clave no descuenta ni duplica (2.4).
- Con producción previa, el faltante es del artículo elaborado (salsa), no de sus
  ingredientes (2.5).

**Conciliación de faltantes**
- En el mismo negocio, la regularización transfiere desde otro almacén y concilia en
  una transacción (2.6d).
- Entre negocios, queda en confirmación hasta que Global confirma o rechaza
  (2.6e–h).
- Se rechazan los repartos inexactos, los orígenes sin saldo y la edición simultánea
  en dos pestañas (`VERSION_CONFLICTO`) (2.6a–b, 2.6g, 2.6k).
- La cancelación anula o revierte el faltante según su estado (2.7).

**Reventa**
- Una botella descuenta 750 ml sólo de Barra (3.1).
- Dos copas dejan «4 piezas + 450 ml» (3.2).
- Cancelar regresa el inventario (3.4).

**Entradas, mermas y transferencias**
- Las entradas admiten piezas más mililitros, calculan el costo unitario sin tocar el
  maestro y son idempotentes (4.1).
- Funcionan los seis motivos de merma, y no se merma más que el saldo (4.2).
- Las transferencias internas y entre negocios cubren el ciclo completo: autorizar,
  enviar, recibir parcial, incidencia, retorno, rechazo y cancelación. Cada paso deja
  actor y motivo (4.3, 4.4).

**Seguridad de Almacenes**
- Quien sólo consulta no ve acciones y su POST da 403 (4.6a).
- Se rechazan los almacenes y artículos de otro negocio (4.6b).
- Sin CSRF, Almacenes responde 403 (4.6c).
- El NIP de piso no autoriza operaciones de panel (4.3).

### Lo que no hace o hace mal

| Hallazgo | Efecto | Punto |
| --- | --- | --- |
| El editor de receta anterior guarda sin CSRF (**corregido el 7 nov 2026**) | Una página externa podía reescribir recetas si un administrador tenía la sesión abierta | [A-1](#a-1-el-editor-de-receta-anterior-guarda-sin-csrf) |
| Los faltantes no llegan al buzón | El operador de inventario recibe 403 y el administrador ve el contador en 0; sólo se enteran dentro de Almacenes | [A-2](#a-2-los-faltantes-no-llegan-al-buzón) |
| Una línea sin origen rechaza la comanda completa | Tampoco salen las líneas válidas | [A-3](#a-3-una-línea-mal-configurada-detiene-la-comanda-completa) |
| «Conteo físico» sobrescribía sin motivo (**retirado el 8 nov 2026**) | No se distinguía de una merma, ignoraba faltantes pendientes y podía dejar un faltante sin origen | [A-4](#a-4-conteo-físico-ambiguo) |
| Conciliación fila por fila | Un día con muchos tickets se concilia uno por uno | [A-6](#a-6-conciliación-fila-por-fila) |
| La primera presentación habilitada no se publica | Hay que publicarla deshabilitada, elegir orígenes y volver a publicar | [A-7](#a-7-primera-presentación-habilitada-imposible) |
| Variantes sin origen | Una variante que agrega un artículo se guarda sin origen y bloquea la venta | [A-8](#a-8-variantes-que-agregan-artículos-sin-origen) |
| Tipo de venta por oferta | Copa y Botella en una oferta descuentan igual como preparado o reventa | [A-9](#a-9-tipo-de-venta-por-oferta-y-reventa-sin-existencias) |
| Reventa sin existencias completas | La botella se vende y deja faltante; falta decidir si debe bloquearse | [A-9](#a-9-tipo-de-venta-por-oferta-y-reventa-sin-existencias) |
| Global escribe en almacenes de Restaurante | Así está diseñado, pero no está documentado | [A-16](#a-16-efectos-entre-negocios) |
| «0.00» en un origen no usado | Rechaza la conciliación | [A-14](#a-14-cero-con-decimales-en-la-conciliación) |
| Textos | «Existencias previas requeridas» ya no es cierto; «Faltante de inventario» como merma confunde; dos «Copa» no se distinguen; doble redirección del catálogo | [A-15](#a-15-textos-y-etiquetas) |

### Lo que no quedó probado

- **POS de Panadería.** Vive en `main`, no en esta rama. Al integrarla también
  generará faltantes; hay que repetir las pruebas 2 y 3 ([A-5](#a-5-integración-con-main-y-pos-de-panadería)).
- **Cancelación y confirmación simultáneas.** Posible interbloqueo; falta la prueba
  ([A-10](#a-10-orden-de-bloqueos-entre-cancelación-y-confirmación)).
- **Navegador.** Sólo escritorio, en Almacenes, el aviso de faltantes y el topbar.
  Faltan POS, Presentaciones, catálogo y móvil.
- **Bandeja de faltantes.** No abrió desde el aviso con el servidor ocupado por el
  runner. Hay que repetirlo sin carga ([A-12](#a-12-bandeja-de-faltantes-en-el-navegador)).
- **Rechazo en lote de reventa (1.6).** El fixture no tiene la precondición
  ([A-17](#a-17-datos-de-prueba-y-plan)).

## Resumen y orden de trabajo

| Prioridad | Punto | Tipo | Estado |
| --- | --- | --- | --- |
| **Crítica** | [A-1](#a-1-el-editor-de-receta-anterior-guarda-sin-csrf) El editor de receta anterior guarda sin CSRF | Seguridad | **Corregido** (7 nov 2026) |
| Alta | [A-2](#a-2-los-faltantes-no-llegan-al-buzón) Los faltantes no llegan al buzón (AP-1) | Operación | Confirmado |
| Alta | [A-3](#a-3-una-línea-mal-configurada-detiene-la-comanda-completa) Una línea mal configurada detiene la comanda completa (AP-2) | Operación | Confirmado |
| Alta | [A-4](#a-4-conteo-físico-ambiguo) «Conteo físico» ambiguo (AP-4) | Datos | **Corregido: retirado** (8 nov 2026) |
| Alta | [A-5](#a-5-integración-con-main-y-pos-de-panadería) Integración con `main` y POS de Panadería (AP-13) | Integración | Confirmado antes del merge |
| Media-alta | [A-6](#a-6-conciliación-fila-por-fila) Conciliación fila por fila (AP-3) | Operación | Confirmado |
| Media | [A-7](#a-7-primera-presentación-habilitada-imposible) Primera presentación habilitada imposible | Flujo | Confirmado |
| Media | [A-8](#a-8-variantes-que-agregan-artículos-sin-origen) Variantes que agregan artículos sin origen | Flujo | Confirmado |
| Media | [A-9](#a-9-tipo-de-venta-por-oferta-y-reventa-sin-existencias) Tipo de venta por oferta y reventa sin existencias (AP-6) | Decisión de negocio | Confirmado |
| Media | [A-10](#a-10-orden-de-bloqueos-entre-cancelación-y-confirmación) Orden de bloqueos entre cancelación y confirmación (AP-5) | Concurrencia | No probado |
| Media | [A-11](#a-11-harness-y-runners-de-prueba) Harness y runners de prueba (AP-11) | Pruebas | Confirmado y ampliado |
| Media | [A-12](#a-12-bandeja-de-faltantes-en-el-navegador) Bandeja de faltantes en el navegador | Interfaz | Por confirmar |
| Baja-media | [A-13](#a-13-dos-entradas-al-catálogo) Dos entradas al catálogo (AP-10) | Interfaz | Parcial |
| Baja | [A-14](#a-14-cero-con-decimales-en-la-conciliación) Cero con decimales en la conciliación (AP-12) | Validación | Confirmado |
| Baja | [A-15](#a-15-textos-y-etiquetas) Textos y etiquetas (AP-8, AP-9, «Dónde se utiliza») | Interfaz | Confirmado |
| Baja | [A-16](#a-16-efectos-entre-negocios) Efectos entre negocios (AP-14) | Documentación | Confirmado |
| Baja | [A-17](#a-17-datos-de-prueba-y-plan) Datos de prueba y plan | Pruebas | Confirmado |
| — | [A-18](#a-18-vinculación-de-preparaciones-anteriores) Vinculación de preparaciones anteriores (AP-7) | — | No reproducido |

Orden sugerido: A-1 antes de cualquier despliegue. Después A-2, A-3 y A-4, que
cambian lo que el operador ve y decide. A-5 antes del merge. El resto puede ir en
lotes por superficie (menú, almacenes y pruebas).

---

## Crítica

### A-1. El editor de receta anterior guarda sin CSRF

**Hallazgo.** `POST /admin/recetas/editar?id=…` guarda una versión nueva de la receta
sin validar ningún token. `AdminRecetasController::receta` exige `productos/editar` y
llama a `guardarComponentes` sin pasar por `AdminCsrfService::validar`. Sólo
`almacenes()`, la ruta de orígenes, valida `admin_csrf`. Un sitio externo puede hacer
que un administrador con sesión abierta reescriba recetas.

**Evidencia.** Prueba 4.6d. Sin token, un cuerpo inválido llega a la validación de
datos (oferta 1001, HTTP 422 «Un componente de receta no es válido»). Reenviar sin
token los componentes vigentes de la oferta 1402 creó la versión 2 de su receta.

**Propuesta.**
- Validar `admin_csrf` en el POST de `receta()` antes de tocar datos, con el mismo
  patrón de `almacenes()`.
- Agregar el campo oculto a `views/admin/recetas/form.php`. Hoy sólo lo lleva el
  formulario de almacenes.
- Revisar con `grep` los demás POST del panel que no pasen por un token (subrecetas,
  categorías, toggles) y listarlos en el mismo cambio.

**Aceptación.**
- Un POST sin token o con un token ajeno da 403 o 419 y no crea versión.
- Con token, el guardado sigue funcionando.
- Prueba de regresión en el runner (4.6d pasa de FAIL a PASS).

**Archivos.** `controllers/AdminRecetasController.php`, `views/admin/recetas/form.php`.

**Estado: corregido el 7 de noviembre de 2026.**
- `receta()` valida `admin_csrf` después de exigir `productos/editar` y antes de
  `guardarComponentes`. Sin token, o con uno ajeno, responde 403, vuelve a mostrar el
  formulario con «La receta no se modificó» y no crea versión.
- El formulario de componentes lleva el campo oculto. La vista de error 422 también
  recibe el token, para que el reenvío corregido funcione.
- Runner M3 completo en un harness nuevo sobre el árbol actual: 105 PASS, 19 OBS y
  1 FAIL (2.3e, que corresponde a A-2). 4.6d pasa de FAIL a PASS (HTTP 403). La nueva
  4.6e comprueba que los componentes vigentes reenviados sin token dejan la versión
  en 1, y que con el token del formulario se guarda la versión 2. `npm run test:php`
  pasa.
- Pendiente del tercer punto de la propuesta: los POST de `subrecetaEdit()` y
  `subrecetaDelete()` en el mismo controlador tampoco validan token. No se tocaron.

---

## Alta

### A-2. Los faltantes no llegan al buzón

**Hallazgo (AP-1).** El faltante se registra en `buzon_notificaciones`, pero no se
pinta ni se cuenta en el topbar:
- `ReservacionBuzonBandejaService::fuente()` sólo resuelve tipos de reservaciones y
  devuelve `null` para `inventario.faltante`.
- `/admin/api/buzon/*` está clasificado bajo el módulo `reservations`, así que un
  operador de inventario sin Reservaciones recibe 403 y su topbar no tiene buzón.

**Evidencia.** Prueba 2.3e:
- `operador_restaurante`: HTTP 403 `PERMISO_DENEGADO`.
- `admin_demo`: `cantidad: 0` con faltantes abiertos.

En el navegador el operador sólo ve el aviso dentro de Almacenes.

**Propuesta.**
- Añadir la fuente `inventario.faltante` al buzón, con enlace a
  `?vista=faltantes` del ámbito.
- Abrir el resumen del buzón a quien tenga `inventario/ver`, filtrando por los tipos
  que su permiso alcanza. No conceder Reservaciones.
- Justificar en arquitectura §16 el cambio de clasificación de la ruta.

**Aceptación.**
- Un operador con sólo `inventario/editar` ve el contador y el aviso.
- Un usuario con sólo Reservaciones no ve faltantes.
- El aviso se cierra al conciliar.

**Archivos.** `services/Reservations/ReservacionBuzonBandejaService.php`,
`services/Inventory/Faltantes.php` (`avisar`), `services/Security/Permisos.php`,
`controllers/AdminBuzonController.php`.

### A-3. Una línea mal configurada detiene la comanda completa

**Hallazgo (AP-2).** Si un artículo de una línea no tiene origen en `oferta_consumos`,
o su almacén o asociación están inactivos, `ALMACEN_ORIGEN_FALTANTE` rechaza **toda**
la comanda, incluidas las líneas válidas. Contradice el requisito de no detener la
producción.

**Evidencia.**
- Prueba 2.8a: copa con una variante sin origen más una copa de fresas válida; no
  sale ninguna.
- Prueba 2.8c: una asociación inactiva produce el mismo rechazo.

**Propuesta.** Es una decisión de negocio, con dos caminos:
1. **Prevenir.** No permitir que una oferta activa quede sin origen. Ver A-8 para
   variantes; bloquear la desactivación de una asociación que alguna oferta activa
   usa como origen.
2. **Aislar.** Rechazar sólo la línea afectada y avisar al mesero, si la comanda
   puede confirmarse parcialmente sin romper la idempotencia.

Se recomienda el camino 1: mantiene la comanda atómica, que hoy es un contrato
probado.

**Aceptación.** Ninguna oferta visible en el POS puede llegar a la venta sin origen
para todos sus artículos, incluidas variantes. Si se elige el camino 2, las líneas
válidas salen y la rechazada se informa con su motivo.

**Archivos.** `services/Inventory/ResolucionArticulos.php`,
`services/Inventory/AlmacenesConsumo.php`, `services/Menu/Variantes.php`,
`services/Inventory/Almacenes.php` (`configurarAsociacion`).

### A-4. «Conteo físico» ambiguo

**Hallazgo (AP-4).** El conteo sobrescribe el saldo como `ajuste` con motivo
`operacion_administrativa` y nota vacía:
- Ignora los faltantes pendientes del artículo en ese almacén (5.2a).
- No deja motivo ni cuando baja ni cuando sube el saldo (5.2c, 5.2d). En el historial
  no se distingue de una merma.
- No hubo doble descuento. Pero si el conteo baja un almacén a 0, el faltante ya no
  puede conciliarse desde ahí (5.2b).

**Dictamen.** Por la regla del plan, en su forma actual se recomienda **eliminar** el
botón. Si el negocio hace conteos periódicos, **rediseñarlo**.

**Propuesta de rediseño.**
- Motivo obligatorio de la diferencia (lista cerrada más nota), guardado en el
  movimiento.
- Advertencia, o bloqueo configurable, cuando haya faltantes pendientes del artículo
  en ese almacén, con enlace a conciliarlos primero.
- Distinguir en Movimientos el conteo de la merma.

**Aceptación.** Ningún ajuste queda sin motivo. Contar con un faltante pendiente
muestra el aviso antes de confirmar. El historial permite filtrar por conteos.

**Archivos.** `services/Inventory/AdministracionAlmacenes.php` (`ajuste`),
`services/Inventory/Almacenes.php` (`operar('ajuste')`),
`views/admin/almacenes/_asociacion.php`, `src/js/admin/inventario/inventario.js`.

**Estado: retirado el 8 de noviembre de 2026** (decisión: eliminar, no rediseñar).
- Sin botón ni diálogo «Conteo físico» en Almacenes, y sin la columna «Ajuste rápido» ni la ruta `POST /admin/inventario/ajustar` del módulo antiguo de Inventario.
- `AdministracionAlmacenes` responde `ACCION_INVALIDA` a `ajuste`. El motor ya no
  tiene `registrarAjuste` ni ramas de conteo, y ninguna operación acepta `version`.
- Una diferencia real se registra como merma con su motivo, o como entrada.
- Los movimientos `ajuste` ya guardados (conteos anteriores y la conversión de
  negativos de M3) se conservan: borrarlos descuadraría el saldo, que es la suma de sus
  movimientos. Movimientos los rotula «ajuste (histórico)». El ENUM no cambia.
- Pruebas: Almacenes 3C (159) y concurrencia (17), selección (70), transferencias (120),
  faltantes (68 y 18), tipo de venta (49), proveedores (81), representación (140), M1.1
  UI e Inventario 3D pasan. Se reescribieron la Prueba 5 del runner M3 y las de 3E y
  3E.1 que leían el saldo del formulario de conteo; éstas necesitan el harness y no se
  han corrido todavía.
- El rediseño (motivo obligatorio y aviso de faltantes pendientes) queda en el backlog
  por si el negocio vuelve a necesitar conteos periódicos.

### A-5. Integración con `main` y POS de Panadería

**Hallazgo (AP-13).** La rama salió de un `main` anterior. `origin/main` tiene ahora
`services/Pos/CajaMostrador.php` (POS de Panadería), que llama a `consumirVenta`, y
cambió `services/Inventory/Almacenes.php` (+39/−4) desde la base común. Al integrar,
Panadería también generará faltantes.

**Evidencia.** `git merge-tree --write-tree HEAD origin/main`, calculado en memoria:
- `Almacenes.php` se fusiona sin conflicto.
- Hay conflictos en `CLAUDE.md`, `database/ddl.sql`, `docs/arquitectura.md`,
  `docs/desarrollo/pruebas.md`, `gulpfile.js`, `package.json`,
  `views/admin/menu/variantes.php` y bundles de `public/build/`.

**Propuesta.**
- Integrar en una rama propia y resolver los conflictos.
- Recompilar los bundles en lugar de fusionarlos.
- Repetir las pruebas 2 y 3 del runner en Panadería con `CajaMostrador`.

**Aceptación.**
- Una venta de Panadería sin saldo descuenta lo disponible y deja el faltante.
- La bandeja de Panadería lo concilia.
- Las suites de faltantes y tipo de venta pasan tras el merge.

---

## Media-alta

### A-6. Conciliación fila por fila

**Hallazgo (AP-3).** Hay un faltante por item, almacén y artículo
(`uq_faltante_item`). Un día con muchos tickets obliga a conciliar fila por fila. El
aviso del buzón sí se deduplica por almacén, artículo y día.

**Evidencia.** Prueba 2.9: 8 tickets dejan 8 faltantes que se concilian por separado
y 0 avisos nuevos.

**Propuesta.** Conciliación agrupada por almacén, artículo y día: un solo reparto que
se distribuye entre los faltantes abiertos en orden de antigüedad, con la misma
transacción y la misma idempotencia que la conciliación individual.

**Aceptación.**
- Un reparto de 40 sobre ocho faltantes de 5 los deja conciliados.
- Un reparto inexacto se rechaza completo.
- La integridad saldo = movimientos se mantiene.

**Archivos.** `services/Inventory/Faltantes.php`,
`views/admin/almacenes/_faltantes.php`.

---

## Media

### A-7. Primera presentación habilitada imposible

**Hallazgo.** Publicar una presentación con «Habilitar» exige orígenes
(`ALMACEN_ORIGEN_FALTANTE`), pero el formulario de orígenes sólo lista artículos de
versiones ya publicadas. La primera publicación habilitada nunca pasa. Hoy hay que
publicarla deshabilitada, elegir orígenes y publicarla otra vez.

**Evidencia.** Prueba 1.3d, que se repite en todas las ofertas nuevas del runner.

**Propuesta.** Una de dos:
- Pedir los orígenes de los artículos del borrador en el mismo formulario de la
  presentación, después de «Revisar consumo previsto».
- Permitir guardar orígenes de artículos aún no publicados, validados contra el
  borrador.

**Aceptación.** Una oferta nueva queda habilitada y vendible en un solo recorrido.

**Archivos.** `services/Menu/PresentacionesOferta.php` (`guardarVersion`, `plan`),
`services/Inventory/AlmacenesConsumo.php`, `controllers/AdminArticulosController.php`
(`presentaciones`), `views/admin/recetas/presentaciones.php`. Visual: subagente
`ux-ui`.

### A-8. Variantes que agregan artículos sin origen

**Hallazgo.** Una variante que agrega un artículo se guarda sin pedir su origen. La
oferta queda sin poder venderse con esa variante, y por A-3 la comanda completa se
rechaza.

**Evidencia.** Prueba 2.8a: la variante «Con pasta» de la copa se guardó (HTTP 200)
sin origen de pasta; la venta dio `ALMACEN_ORIGEN_FALTANTE`.

**Propuesta.**
- Al guardar variantes, exigir origen para cada artículo nuevo, o dejar la variante
  inactiva hasta tenerlo.
- Mostrar el pendiente en Presentaciones.

**Aceptación.** No hay variante activa cuyo artículo carezca de origen.

**Archivos.** `services/Menu/Variantes.php` (`guardar`),
`services/Inventory/AlmacenesConsumo.php`, `src/js/admin/menu/variantes.js`.

### A-9. Tipo de venta por oferta y reventa sin existencias

**Hallazgo (AP-6).**
- El tipo de venta es de la oferta, no de la presentación: una oferta con Copa 150 ml
  y Botella 750 ml se acepta como preparado y como reventa, y el descuento es el
  mismo (1.7).
- Una botella de reventa sin la cantidad completa no se bloquea: descuenta lo que hay
  y deja un faltante (3.3).

**Propuesta (decisión de negocio).**
- Definir si el tipo vive en la presentación.
- Definir si la reventa sin existencias completas debe bloquearse en el POS (por
  ejemplo, con un aviso al mesero) o seguir el contrato §16 de no detener la venta.
- Documentar la decisión en arquitectura §16 antes de cambiar código.

**Aceptación.** Contrato escrito y, si cambia, pruebas de tipo de venta actualizadas.

**Archivos.** `services/Menu/TipoVenta.php`, `services/Menu/PresentacionesOferta.php`,
`services/Inventory/Almacenes.php` (`operar('venta')`).

### A-10. Orden de bloqueos entre cancelación y confirmación

**Hallazgo (AP-5, no probado).**
- La cancelación bloquea faltante → asignación → transferencia, y la confirmación de
  Global, transferencia → asignación → faltante. Puede haber interbloqueo.
- Dentro de la transacción del POS no se reintenta (`CONCURRENCIA_CONFLICTO`).

**Propuesta.**
- Escribir primero la prueba de concurrencia: dos procesos, cancelación del item y
  confirmación de su regularización a la vez, con el patrón de
  `run-almacenes-3c-concurrencia.php`.
- Si reproduce, unificar el orden de bloqueo.

**Aceptación.**
- Cien repeticiones sin interbloqueo ni estado inconsistente.
- La transferencia queda cancelada o confirmada, nunca las dos.

**Archivos.** `services/Inventory/Faltantes.php` (`anularPorCancelacion`),
`services/Inventory/Transferencias.php` (`regularizacion`),
`services/Inventory/TransaccionInventario.php`.

### A-11. Harness y runners de prueba

**Hallazgo (AP-11, ampliado).**
- `servir-instalacion-3e.php` sólo acepta la salida privada dentro de
  `CODEX_HOME/visualizations`, una ruta atada a una herramienta.
- En Windows, `proc_terminate` no detiene el `php -S` hijo. Tras el Enter, el harness
  queda esperando en `proc_close` y no limpia hasta que alguien detiene el servidor a
  mano.
- `php -S` atiende una petición a la vez: el runner y el navegador compiten, y una
  página puede quedarse en `readyState: interactive` (ver A-12).
- `run-almacenes-3c*.php` exigen `--host 127.0.0.1 --base casa-pestalozzi` **y** que
  coincidan con `includes/.env`. Con otra configuración local no corren, aunque sólo
  crean bases temporales.
- Un worktree con `vendor` enlazado carga las clases del árbol principal. Hay que
  documentarlo en la guía de pruebas.

**Propuesta.**
- Variable neutral para la salida privada, por ejemplo `CP_QA_SALIDA`, con
  `CODEX_HOME` como compatibilidad.
- Detener el servidor por PID en Windows (`taskkill /T`) cuando `proc_terminate` no
  baste.
- Aceptar la identidad 3C desde `.env` sin el nombre fijo.
- Incorporar `pruebas-http-m3-funcional.php` a la suite documentada en
  `docs/desarrollo/pruebas.md`.

**Aceptación.**
- El harness arranca y limpia solo en Linux y en Windows.
- 3C corre con cualquier `.env` de desarrollo.
- El runner M3 figura en la guía con su comando.

**Archivos.** `scripts/tests/servir-instalacion-3e.php`,
`scripts/tests/run-almacenes-3c.php`, `scripts/tests/run-almacenes-3c-concurrencia.php`,
`docs/desarrollo/pruebas.md`.

### A-12. Bandeja de faltantes en el navegador

**Hallazgo (por confirmar).** En el recorrido, `/admin/almacenes?vista=faltantes` y el
botón «Conciliar faltantes» no abrieron la bandeja (`#almacen-faltantes` seguía
oculto). Despachar `admin:open-modal` a mano sí la abrió. La página estaba en
`readyState: interactive` con el servidor de un solo hilo ocupado por el runner, así
que puede ser carga y no defecto.

**Propuesta.**
- Repetir sin carga concurrente, en escritorio y en móvil.
- Si se reproduce, revisar en `inventario.js` el momento en que se despacha la vista
  inicial frente al registro del gestor de modales de `admin.js`.
- Dar a «Conciliar faltantes» un `data-admin-modal-open` como respaldo del enlace.

**Aceptación.** La bandeja abre desde el aviso y desde la URL en los dos tamaños, con
JavaScript lento o rápido.

**Archivos.** `src/js/admin/inventario/inventario.js`,
`views/admin/almacenes/index.php`. Visual: subagente `ux-ui`.

---

## Baja-media

### A-13. Dos entradas al catálogo

**Hallazgo (AP-10, parcial).** `/admin/almacenes/catalogo` (exige `inventario/ver`) y
`/admin/recetas/articulos` (exige `productos/ver`) muestran la misma sección
«Catálogo compartido». La vinculación de preparaciones anteriores apareció en Recetas
(2.2b). No se pudo comprobar si falta en Almacenes, porque al llegar a 6.1 ya no
quedaban preparaciones pendientes.

**Propuesta.**
- Una sola superficie de catálogo con acciones según permiso, o títulos distintos
  que digan qué se hace en cada una.
- Repetir 6.1 con una preparación pendiente.

**Archivos.** `controllers/AdminArticulosController.php` (`index`, `maestro`),
`views/admin/recetas/articulos.php`.

---

## Baja

### A-14. Cero con decimales en la conciliación

**Hallazgo (AP-12).** El formulario descarta filas vacías sólo si la cantidad es
exactamente `''`, `0` o `0.000`. Un `0.00` en un origen que no se usa rechaza la
conciliación con «Cada almacén aparece una vez con cantidad positiva».

**Evidencia.** Prueba 2.6c.

**Propuesta.** Normalizar la cantidad con `DecimalInventario::leer` antes de filtrar,
en conciliación y en recepción y retorno de transferencias (mismo patrón).

**Aceptación.** `0`, `0.0`, `0.00` y `0.000` se descartan igual; un valor negativo se
sigue rechazando.

**Archivos.** `services/Inventory/AdministracionAlmacenes.php`, filtros de `lineas` y
`asignaciones`.

### A-15. Textos y etiquetas

| Punto | Hallazgo | Propuesta | Archivo |
| --- | --- | --- | --- |
| AP-8 | La columna «Configuración del negocio» dice «Existencias previas requeridas» para producción previa; desde M3 la venta no las exige (6.4) | «Se elabora antes de vender» | `views/admin/recetas/articulos.php` |
| AP-9 | El motivo de merma «Faltante de inventario» choca con «faltantes por conciliar» (4.2c) | «Diferencia de inventario», o retirarlo y migrar los movimientos existentes sólo en su etiqueta | `services/Inventory/Inventario.php` (`MOTIVOS_MERMA`) |
| Nuevo | «Dónde se utiliza» rotula cada presentación sólo con su nombre («Copa · Versión 2») y repite cada versión: dos «Copa» de productos distintos no se distinguen (1.5e) | Nombre del producto más la presentación y sólo la versión vigente, con las anteriores agrupadas | `services/Inventory/Articulos.php` (`usos`), vista de la ficha |
| Nuevo | La redirección `/admin/global/catalogo` → `?vista=catalogo` → `/admin/global/almacenes/catalogo` da dos saltos (6.6) | Redirigir directo al destino final | `public/index.php` |

Los tres primeros son visuales: subagente `ux-ui`.

### A-16. Efectos entre negocios

**Hallazgo (AP-14).** Es así por diseño, pero no está documentado:
- Al confirmar una regularización, Global escribe la entrada y la venta conciliada en
  el almacén del restaurante (2.6f).
- Una entrada en cualquier negocio reabre avisos de faltantes de los demás (2.6j).

**Propuesta.** Documentarlo en arquitectura §16 y en la guía de permisos: quién
escribe en qué almacén, cuándo y con qué rastro.

### A-17. Datos de prueba y plan

- El fixture no tiene ninguna oferta sugerida como reventa (las 129 en `sugerido` son
  `preparado`). Sin eso no se puede probar el rechazo en lote de 1.6. Añadir una en
  `database/development.sql`.
- `run-menu-tipo-venta-ui.cjs` falla en un checkout de Windows con finales CRLF: su
  expresión espera `\n`. Normalizar con `\r?\n` o fijar `eol=lf` para las vistas en
  `.gitattributes`.
- `run-reservaciones-notification-docs.cjs` falla porque `docs/` contiene `API.md` e
  `integracion-a6-main.md`. Actualizar la lista esperada o mover esos archivos según
  la clasificación documental.
- La consulta de movimientos del plan usa `faltante_id`, que existe por un `ALTER` al
  final del DDL. Conviene declararla con el resto de las columnas de
  `movimientos_inventario`.

---

## No reproducido

### A-18. Vinculación de preparaciones anteriores

**AP-7.** Se esperaba que vincular una subreceta legada a un artículo cambiara en
silencio el descuento, de desglosar los ingredientes a descontar el artículo
almacenado. En la prueba 2.2c, después de vincular «Porción de leche», la venta de la
oferta 1002 siguió descontando harina −80 g y leche −20 ml.

**Propuesta.** No corregir. Documentar el comportamiento observado y, si se espera
que el artículo vinculado reemplace al desglose, definir cuándo (por ejemplo, al
tener existencias y origen) y probarlo.

---

## Cierre de la auditoría

Este plan se da por cumplido cuando:

1. A-1 a A-5 están corregidos o decididos por escrito, y su prueba en
   `pruebas-http-m3-funcional.php` pasa en un harness nuevo.
2. Las decisiones de negocio (A-3, A-4, A-9) están en arquitectura §16.
3. El runner M3 forma parte de la guía de pruebas y la línea base no tiene fallas que
   no estén explicadas.
4. Los cambios visuales tienen el visto bueno del subagente `ux-ui`.
