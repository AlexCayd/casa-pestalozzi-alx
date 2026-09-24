# Operación POS e impresión

Fuente normativa del envío de comandas, impresión térmica y alertas visibles en
el piso. La impresión es una copia física: el ticket y sus platillos se
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

El endpoint no recibe una clave de idempotencia ni deduplica tandas. Cada POST
válido inserta los platillos de nuevo; el POS tampoco bloquea el botón mientras
el POST está en curso. Un doble clic o reenvío tras una respuesta de red
incierta puede duplicar el pedido. Antes de reenviar, revisa los platillos del
ticket y el tablero de producción; no repitas una tanda sólo porque la impresión
falló o la respuesta tardó.

## Servicio de impresión

El ajuste global `configuracion_pos.impresion_activa` se controla en
`/admin/configuracion/pos`. Pausarlo conserva las estaciones configuradas, pero
no envía comandas ni cuentas ni muestra como pendientes las alertas antiguas.
Los pedidos siguen guardándose, descontando inventario cuando corresponde y
llegando al tablero de producción. La acción administrativa de imprimir una
prueba de estación se mantiene disponible para diagnosticar antes de reanudar.

La configuración global es distinta de `impresoras.activo`, que habilita cada
estación individual. Una falta de conexión o de impresora configurada se
registra como resultado de impresión fallida, no como fallo del ticket.

## Alertas de impresión

Si el servicio está activo y una comanda o cuenta no llega a la impresora, PHP
registra una alerta con documento, motivo, mesa, área y estación. La bandeja del
POS la muestra en todas las tablets, incluida la respuesta inmediata al mesero
que envió la comanda; las alertas pendientes se refrescan junto con el mapa.

Las alertas visibles corresponden a las últimas 16 horas, con un máximo de 30.
Cualquier usuario de piso puede marcarlas como atendidas; no se borran filas del
pedido ni se cambia el ticket. Si falla una comanda, el aviso indica que el
pedido ya está en el tablero y que se debe avisar al área y revisar la
impresora. Si falla una cuenta, hay que revisar la estación y entregar la cuenta
al cliente.

El detalle técnico de red se reserva a administración. Un fallo al registrar la
alerta queda en el log y no interrumpe el envío de la comanda o el cobro.

## Verificación

La cobertura automatizada de fallos de impresión está en
`scripts/tests/run-pos-print-alerts-db.php` y se ejecuta con
`npm run test:pos-print-alerts`. La prueba usa una base aislada; no modifica la
base configurada por la aplicación.

Para configuración y otras opciones administrativas consulta
[Configuración](config.md). Las decisiones de mesas y reservaciones están en
[Reservaciones](reservaciones/reservaciones.md).
