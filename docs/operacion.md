# Operación POS e impresión

Contrato funcional del envío de comandas, impresión térmica y alertas de
Restaurante, subordinado al [contrato rector](arquitectura.md). La impresión es una copia física: el ticket y sus platillos se
persisten en PHP y alimentan el tablero de producción.

## Envío de comanda

`POST /api/enviar-comanda` valida que el ticket siga abierto, inserta cada
platillo enviado y aplica inventario. Después imprime sólo los platillos de esa
tanda, agrupados por área de producción. El envío a impresora es un efecto
secundario: un error de impresión no deshace las filas del pedido ni cambia
`ok: true`; la respuesta incluye `print_ok` y las alertas creadas.

La impresión se ejecuta dentro de la petición y por áreas, en serie. Cada
conexión de red tiene un timeout de dos segundos. Si la conexión falla, el
platillo ya aparece en el tablero del área; el papel no debe tratarse como la
fuente de verdad del pedido.

### Reintentos y duplicados

La comanda recibe una clave de idempotencia estable por actor y unidad. El
servidor congela la solicitud y el resultado junto con los items y el consumo.
Repetir el mismo contenido devuelve los IDs existentes sin descontar otra vez ni
reimprimir; reutilizar la clave con contenido diferente se rechaza.

El POS conserva la clave durante un fallo de red y evita envíos simultáneos.
`ok: true` público requiere confirmación de la transacción comercial/inventario.
Si la impresión falla después del commit, revisar la alerta y el tablero; no
crear una comanda nueva para intentar recuperar el papel. La cancelación compensa
los movimientos originales una sola vez, sin recalcular la receta actual.

## Servicio de impresión

El ajuste local de Restaurante `configuracion_pos.impresion_activa` se controla en
`/admin/configuracion/pos`. Pausarlo conserva las estaciones configuradas, pero
no envía comandas ni cuentas ni muestra como pendientes las alertas antiguas.
Los pedidos siguen guardándose, descontando inventario cuando corresponde y
llegando al tablero de producción. La acción administrativa de imprimir una
prueba de estación se mantiene disponible para diagnosticar antes de reanudar.

La configuración por unidad es distinta de `impresoras.activo`, que habilita cada
estación individual. Una falta de conexión o de impresora configurada se
registra como resultado de impresión fallida, no como fallo del ticket.

## Alertas de impresión

Si el servicio está activo y una comanda o cuenta no llega a la impresora, PHP
registra una alerta con documento, motivo, mesa, área y estación. La bandeja del
POS la muestra en todas las tablets, incluida la respuesta inmediata al mesero
que envió la comanda; las alertas pendientes se refrescan junto con el mapa.

Las alertas visibles corresponden a las últimas 16 horas, con un máximo de 30.
Un usuario autorizado del POS de Restaurante puede marcarlas como atendidas; no se borran filas del
pedido ni se cambia el ticket. Si falla una comanda, el aviso indica que el
pedido ya está en el tablero y que se debe avisar al área y revisar la
impresora. Si falla una cuenta, hay que revisar la estación y entregar la cuenta
al cliente.

El detalle técnico de red se reserva a administración. Un fallo al registrar la
alerta queda en el log y no interrumpe el envío de la comanda o el cobro.

## Barras, Llevar y Caja

Llevar y las barras son **ventanillas**: admiten varios tickets abiertos a la
vez sobre la misma fila de `mesas`, cada uno con su folio (el id del ticket).
Tocar el pin abre el tablero de pedidos; desde ahí se abre uno nuevo o se salta
a otro. Las barras tienen un tope configurable (`configuracion_pos.max_tickets_barra`,
por defecto 4): al alcanzarlo el pin sale rojo con `4/4` y el servidor rechaza
el siguiente con `BARRA_LLENA` (409). Llevar no tiene tope y nunca se pinta
ocupado. Ninguno de los tres se une a otras mesas; Caja abre el corte del día.

Lo decide el servidor (`PuntoVentaReservacionService::abrirWalkIn`), que cuenta
los tickets abiertos con `FOR UPDATE`; el cliente sólo enseña el tablero. Los
comensales de barra no cuentan en la capacidad del salón ni en la
disponibilidad de reservaciones; sí en ventas. Cobertura en
`npm run test:pos-llevar`.

Las reservaciones también se operan aquí, en el cajón y la ficha del mapa: ver
[Reservaciones](reservaciones/reservaciones.md#la-operacion-en-el-pos).

## Corte de caja

GET /api/corte-caja es solo lectura: no persiste arqueos. Consultas del día
usan tickets cerrados y DATE(COALESCE(hora_cierre, hora_apertura)); el top de
cinco productos por unidades usa ese mismo universo. El histórico del panel
repite parte de las consultas: al cambiar su definición revisar también
AdminFinanzasController::cortes, sin crear un contrato paralelo.

## Verificación

La cobertura automatizada de fallos de impresión está en
`scripts/tests/run-pos-print-alerts-db.php` y se ejecuta con
`npm run test:pos-print-alerts`. La prueba usa una base aislada; no modifica la
base configurada por la aplicación.

Para configuración y otras opciones administrativas consulta
[Configuración](config.md). Las decisiones de mesas y reservaciones están en
[Reservaciones](reservaciones/reservaciones.md).
