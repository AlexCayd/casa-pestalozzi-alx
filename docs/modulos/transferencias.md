# Transferencias de Almacenes

## Contrato M0.5-B

Auditoría previa: el DDL 3C ya contiene cabecera, líneas con cantidades solicitada,
enviada, recibida y retornada, historial y FK desde movimientos. Los únicos
consumidores anteriores consultan pendientes para impedir la baja del almacén;
no existe un flujo de transferencias ni datos de apertura en esas tablas.
Se conservan sus FK, checks y movimientos inmutables. Se añade el estado
`autorizada` y un registro de comandos para idempotencia sin movimientos ficticios.
Las instalaciones nuevas incorporan el DDL; no se migra la base activa.

| Acción | Estado previo | Autorización física | Resultado |
|---|---|---|---|
| Solicitar | Nueva | Origen o destino propios en el contexto efectivo | Solicitada, sin saldo ni reserva |
| Autorizar | Solicitada | Origen | Autorizada; comprueba existencias, sin reservar |
| Enviar | Autorizada | Origen | Enviada; descuenta origen y crea tránsito |
| Recibir | Enviada / incidencia | Destino | Suma únicamente lo recibido; parcial deja incidencia |
| Registrar diferencia | Enviada / incidencia | Destino | Incidencia con motivo, incluso sin recepción |
| Retornar | Incidencia | Origen | Compensa lo que vuelve físicamente; no inventa recepción |
| Rechazar | Solicitada / autorizada | Origen o destino propios | Rechazada sin movimientos |
| Cancelar | Solicitada / autorizada | Origen o destino propios | Cancelada sin movimientos |

Cada acción requiere `inventario.editar`, contraseña, contexto propietario y
versión vigente. Global consolidado permite lectura; no escritura extranjera.
La petición ofrece solo identidad de la contraparte, no su saldo. La salida se
vuelve a validar al enviar y nunca permite transferir saldo inexistente, aunque
el almacén admita negativos para otros usos. No hay reserva entre autorización
y envío. Los almacenes deben ser distintos y las asociaciones explícitas.

La recepción y el retorno expresan incrementos, no totales sobrescribibles.
Por línea: `enviada = recibida + retornada + tránsito`. Una recepción parcial
exige motivo de diferencia y deja incidencia hasta resolver todo el tránsito.
Al agotarlo, queda recibida si todo llegó al destino; en otro caso, cerrada con
retorno. El historial retiene diferencias, actores y motivos. No se permite
cancelar lo enviado; se resuelve con recepción o retorno real.

Cantidad y costo usan `DecimalInventario`, sin flotantes. Cabecera, comandos,
almacenes ordenados y asociaciones se bloquean dentro de `TransaccionInventario`.
Cada comando tiene clave por actor/contexto y hash de contenido: repetición exacta
devuelve el resultado original, contenido distinto falla. La versión impide
repetir una recepción con una clave nueva sobre el estado ya observado.
Los movimientos de retorno apuntan a la salida original y a la línea; una reversa
genérica no puede compensar transferencias. No hay éxito antes del commit externo.

Se bloquea desactivar asociaciones o artículos con tránsito pendiente. Los
almacenes pendientes tampoco se desactivan ni eliminan. Los movimientos, historial
y comandos se conservan; las compensaciones nunca editan el ledger anterior.
