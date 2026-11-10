# Transferencias de Almacenes

## Contrato M0.5-B

Auditoría previa: el DDL 3C ya contiene cabecera, líneas con cantidades solicitada,
enviada, recibida y retornada, historial y FK desde movimientos. Los únicos
consumidores anteriores consultan pendientes para impedir la baja del almacén;
no existe un flujo de transferencias ni datos de apertura en esas tablas.
Se conservan sus FK, checks y movimientos inmutables. Se añade el estado
`autorizada` y un registro de comandos para idempotencia sin movimientos ficticios.
Las instalaciones nuevas incorporan el DDL; no se migra la base activa.

### Fases según el caso (8 nov 2026)

El número de pasos depende de quién registra la transferencia y de si los dos
almacenes son del mismo negocio. Global, Restaurante y Panadería son negocios
distintos entre sí.

| Caso | Fases | Al registrarla |
|---|---|---|
| Mismo negocio (p. ej. Barra → Cocina) | Una | Salida y entrada en la misma transacción; nace `recibida` |
| Superadministrador, cualquier par de almacenes | Una | Igual que la interna, aunque los almacenes sean de negocios ajenos al contexto desde el que opera |
| Entre negocios, la registra el dueño del origen | Enviada → recibida | Descuenta el origen y nace `enviada`; el destino sólo recibe |
| Entre negocios, la pide el destino | Solicitada → enviada → recibida | Queda `solicitada`, sin saldo ni reserva, hasta que el origen la envía |

En una sola fase cada movimiento lleva la unidad dueña de su almacén, con una
operación de salida y otra de entrada, como la regularización de faltantes.
Superadministrador es sólo `usuarios.es_superadmin`; tener edición en los dos
negocios no basta. Sobre documentos ajenos ya registrados, el superadministrador
respeta al propietario físico como cualquier otro actor.

| Acción | Estado previo | Autorización física | Resultado |
|---|---|---|---|
| Registrar | Nueva | Origen o destino propios en el contexto efectivo; el superadministrador, cualquiera | Recibida, enviada o solicitada según la tabla anterior |
| Enviar | Solicitada (o `autorizada` heredada) | Origen | Enviada; revalida saldo, descuenta origen y crea tránsito |
| Recibir | Enviada / incidencia | Destino | Suma únicamente lo recibido; parcial deja incidencia |
| Registrar diferencia | Enviada / incidencia | Destino | Incidencia con motivo, incluso sin recepción |
| Retornar | Incidencia | Origen | Compensa lo que vuelve físicamente; no inventa recepción |
| Rechazar | Solicitada / autorizada | Origen o destino propios (la interfaz la ofrece al origen) | Rechazada sin movimientos |
| Cancelar | Solicitada / autorizada | Origen o destino propios (la interfaz la ofrece al destino) | Cancelada sin movimientos |

**Autorizar se retiró.** Era un paso sin efecto físico: comprobaba existencias sin
reservarlas, y el envío las volvía a comprobar. Ahora el servicio responde
`ACCION_INVALIDA`. Los documentos ya `autorizada` se conservan con ese estado y se
envían, rechazan o cancelan igual que una solicitada. El ENUM no cambia, así que
no hace falta migración.

Diferencia y retorno no son fases: sólo aparecen cuando una recepción no cubre
todo lo enviado. La recepción llega precargada con lo enviado, de modo que el
caso normal es un solo envío del formulario.

Cada acción requiere `inventario.editar`, contraseña, contexto propietario y
versión vigente. Global consolidado permite lectura; no escritura extranjera.
La petición ofrece solo identidad de la contraparte, no su saldo. La salida se
vuelve a validar al enviar y nunca permite transferir saldo inexistente; desde M3
ningún almacén admite negativos. No hay reserva entre la solicitud y el envío.
Una línea sin saldo revierte el documento entero, incluso en una transferencia de
un paso. Los almacenes deben ser distintos.

### Asociación del artículo (9 nov 2026)

Ya no hace falta asociar el artículo a los dos almacenes antes de transferir.

| Almacén | Si el artículo no está asociado | Motivo |
|---|---|---|
| Origen | Se rechaza (`ASOCIACION_NO_EXISTE`) | Sin asociación no hay existencias que enviar |
| Destino | Se asocia al registrar: mínimo 0, sin saldo, unidad dueña del almacén | Recibir mercancía implica manejar el artículo |

Aplica también cuando el destino es de otro negocio. Una asociación inactiva o un
artículo inactivo se siguen rechazando: reactivar es una decisión explícita. El
resultado lleva `asociados` (almacén y artículos) y el aviso de la interfaz lo
nombra. Antes de enviar, el formulario avisa qué artículos quedarán asociados al
destino y cuáles faltan en el origen, con un mapa artículo → almacenes que sólo
contiene identidad, nunca saldos.

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

## Regularización de faltantes (M3)

`transferencias.tipo` distingue `ordinaria` y `regularizacion`. Una regularización
nace al conciliar un faltante de venta (arquitectura §16) y no admite el flujo
ordinario (enviar, recibir, incidencia, retornar ni cancelar manual):

| Caso | Estado | Quién actúa | Efecto |
|---|---|---|---|
| Origen del mismo negocio | Recibida al crearse | Quien concilia | Salida del origen, entrada al almacén de consumo y consumo ligado al item, en una transacción |
| Origen de otro propietario | Solicitada | Dueño del origen: `confirmar` | Envío, recepción y consumo en una transacción; exige saldo suficiente al confirmar |
| Origen de otro propietario | Rechazada | Dueño del origen: `rechazar` con motivo | La parte vuelve a pendiente en el faltante |
| Item vendido cancelado | Cancelada | Sistema, al cancelar el item | La parte en confirmación se anula |

El destino sólo ve la identidad del origen; el origen sólo ve la del destino.
El costo de la línea es el del origen y es el que guarda el consumo conciliado.

