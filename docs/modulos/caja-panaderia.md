# Caja de mostrador de Panadería

Guía técnica subordinada a [arquitectura §7](../arquitectura.md#caja-de-mostrador-de-panaderia).
La Panadería vende en mostrador: sin mesas, comanda ni KDS. El cliente trae su
pan, se cobra y se va.

## Superficies

| Ruta | Qué es | Permiso |
|---|---|---|
| `/panaderia/caja` | La caja (piso, entra con NIP o con sesión de panel) | `pdv` o `vitrina` (operar) |
| `GET /api/panaderia/caja` | Estado: permisos, carta, vitrina, motivos, impresora | `pdv` o `vitrina` |
| `GET /api/panaderia/caja/vitrina` | Saldos de vitrina (refresco cada 45 s) | `pdv` o `vitrina` |
| `POST /api/panaderia/caja/vender` | Venta atómica con cobro | `pdv` |
| `POST /api/panaderia/caja/horneada` | Entrada de piezas horneadas | `vitrina` |
| `GET /api/panaderia/caja/tickets` | Cobros de hoy, con anulados | `pdv` |
| `POST /api/panaderia/caja/anular` | Anulación del día (permiso `anular` o NIP de quien lo tenga) | `pdv` |
| `POST /api/panaderia/caja/imprimir` | Comprobante en la estación `cuenta` de la unidad | `pdv` |
| `GET /api/panaderia/caja/corte` | Corte de consulta por turno o día | `pdv` |
| `/admin/panaderia/menu/vitrina` | «Vitrina de la caja»: opciones y pieza de cada pan | `menu` ver/editar |

Toda escritura de la caja exige el token CSRF de personal (`X-CSRF-Token`).
`Contextos::deRuta` resuelve `panaderia` para `/panaderia/caja` y
`/api/panaderia/caja/*`; el resto de `/panaderia` es la landing. Perfiles
sugeridos en Usuarios: «Cajero de Panadería» (`pdv` + `vitrina`) y «Encargado
de caja» (+ `anular`).

## Datos

- `vitrina_piezas(oferta_id, almacen_id, articulo_id, cantidad)`: la pieza que
  descuenta cada venta de la oferta. Sin fila, la oferta no se vende de vitrina.
- `vitrina_piezas_variante(variante_id, …)`: una opción que cambia la pieza
  (concha con nata) o la cantidad (media docena = 6 piezas; media hogaza = 0.5).
  Exige la fila base y todas pertenecen a un mismo grupo.
- `ticket_anulaciones(ticket_id, solicitante_id, autorizador_id, motivo, nota,
  total, metodo_pago)`.
- Tickets y líneas usan las tablas de siempre. Una venta guarda `receta_id` NULL
  cuando descuenta pieza: el snapshot dice que no consumió receta.
- El esquema vive sólo en `ddl.sql` (CHECK y triggers de permisos, tres
  tablas) y los datos de prueba en `development.sql`; `database/` no lleva
  migraciones sueltas. Una base anterior se reinstala con el instalador sobre
  una base nueva, o se le aplica el ALTER a mano sólo si se autoriza.
- Fixtures de desarrollo: 17 piezas en «Producto Terminado» (sin negativos,
  como todo almacén: §16), variantes de mostrador (concha con nata, brownie con nuez, media
  hogaza, cuernito con dos grupos, media docena de galletas), y las cuentas
  `cajera_panaderia` y `encargada_panaderia` sin NIP (se asigna en Usuarios).

## Venta

`CajaMostrador::vender(items, pago, clave)`:

1. Resuelve cada línea con `Variantes::resolver` (opciones predeterminadas
   incluidas) y el precio de la oferta; rechaza todo si algo no está a la venta.
2. Efectivo: lo recibido debe cubrir el total; tarjeta: se registra el total.
3. En una transacción: ticket abierto → `pos_operaciones` (`venta_caja`) →
   líneas `enviado` → `Almacenes::consumirVenta` por línea → líneas
   `entregado` → ticket `cerrado` con método. El resultado (con recibido y
   cambio) queda en la operación: un reintento con la misma clave lo devuelve
   sin cobrar ni descontar otra vez; la misma clave con otro contenido es
   `CONFLICTO_IDEMPOTENCIA`.
4. Responde los saldos de las piezas tocadas y `VITRINA_FALTANTE` si se vendieron
   más piezas de las que había: el saldo queda en cero y el resto es un faltante
   por conciliar en Almacenes (arquitectura §16).

## Anulación

Sólo un ticket `cerrado` de hoy. Sin `anular` propio se exige el NIP de cuatro
dígitos de una cuenta de piso que lo tenga (cinco fallos por minuto en la
sesión y el límite por IP del acceso por NIP). En la transacción: el ticket pasa
a `cancelado`, se inserta `ticket_anulaciones`, cada línea pasa a `cancelado` y
`Almacenes::revertirVenta` compensa sus movimientos. Finanzas, Análisis, el
Asistente y el corte cuentan sólo tickets `cerrado`, así que la venta deja de
sumar; el corte lista las anulaciones aparte.

## Pantalla

`views/caja/index.php`, `src/js/caja/` (bundle `caja.js`, `gulp cajaJs`) y
`src/scss/caja/app-caja.scss` (`caja.css`, `gulp cajaCss`, cargada después de
`operation.css`). Usa el shell y header de piso compartidos y el tema oscuro.

- Vitrina: una tecla por pan con piezas restantes y un filete que se vacía
  (ámbar con pocas, «Agotado» en cero, se vende igual). Un grupo de 2–4 opciones
  parte la tecla en gajos; con más, la tecla crece ocupando su fila.
- Cada toque suma una pieza; mantener presionado ofrece 2 · 3 · 6 · 12. En el
  ticket, −/+ y deslizar para quitar, con «Deshacer». Una recarga conserva la
  venta sin cobrar (`sessionStorage` por usuario).
- Cobro: Efectivo (billetes sugeridos, «Otro monto», cambio) y Tarjeta.
  Después, «Cobrado» con el cambio y «Imprimir ticket» si hay estación.
- Teléfono y tableta vertical (<960 px): el ticket es una hoja inferior abierta
  desde la barra «n piezas · total · Cobrar».
- Teclado: `/` o escribir busca; flechas entre teclas; Enter agrega; dígitos y
  Enter agregan varias; Alt+1…4 elige gajo; +/− y Supr sobre la última línea;
  F2/F3 cobran; Esc cierra.
- Hojas: Horneada, Tickets de hoy (reimprimir/anular), Corte y Atajos.

«Vitrina de la caja» (`views/admin/vitrina/`, `src/js/admin/vitrina/`,
`vitrina.scss`) edita en la fila de cada pan su pieza y sus opciones; guarda
variantes (`Variantes::guardar`) y piezas en una sola transacción. Si Global
protege precio o receta, las opciones son de sólo lectura y sus piezas sí se
editan. Una opción que sale de «La del pan» con otra cantidad (media docena = 6,
media hogaza = 0.5) se guarda como pieza propia con el mismo artículo; al
reabrir se muestra otra vez como «La del pan» con su cantidad. La rejilla del
editor responde al ancho de la tarjeta (container queries), no al de la ventana,
para no cortarse con el sidebar abierto.

## Pruebas

`php scripts/tests/run-caja-panaderia-db.php` (`npm run test:caja-panaderia`)
crea su propia base desechable con el DDL y DML versionados y comprueba
triggers y clasificación de rutas, permisos efectivos por persona, venta
atómica sin escritura parcial, idempotencia y conflicto de clave, vitrina en
cero que vende y deja faltante por conciliar, media pieza y media docena, horneada acotada e idempotente,
anulación con NIP (requerido, inválido, nota obligatoria, idempotente, no
repetible), tickets de hoy, corte frente al criterio de Finanzas, revocación y
Restaurante intacto. No certifica impresoras físicas ni hardware táctil.

## Límites

Sin pago mixto, transferencia ni propina; sin encargos, venta por peso ni KDS;
sin devoluciones parciales ni anulaciones de días anteriores; el corte no
guarda arqueos. Las horneadas no descuentan insumos: eso será Producción. Los
turnos del corte se leen de `configuracion_pos` de Panadería (hoy sin pantalla
propia: sin turnos, el corte es del día). La estación de impresión de Panadería
se registra en `impresoras` con `rol='cuenta'`; el panel de estaciones sigue
siendo de Restaurante.
