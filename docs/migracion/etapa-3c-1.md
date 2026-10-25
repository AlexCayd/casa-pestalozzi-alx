# Etapa 3C.1 — Motor transaccional

Base validada 3B: 4d65d30. Rama codex/almacenes-existencias-etapa-3c.
Se preservan como cambios previos database/deploy.sql (admin_demo) y
package-lock.json; no forman parte de los commits de 3C.

Implementados Almacenes, AlcanceInventario, DecimalInventario,
TransaccionInventario e InventarioError, con fábrica explícita desde el servicio
Inventario existente. Véase el [contrato de métodos](contrato-almacenes-3c.md).
Un solo motor sirve Global, Restaurante y Panadería. No hay interfaces,
transferencias, rutas ni descuentos comerciales nuevos.

Registra entradas, conteos con versión, mermas, salidas internas y compensaciones
limitadas a salidas históricas. Usa la misma conexión InnoDB para el documento,
las existencias y los movimientos; los coordinadores externos conservan control
del commit. Los saldos usan cadenas decimales y enteros escalados.

Validación aislada:

- 77 comprobaciones de integridad del motor correctas.
- 17 comprobaciones de concurrencia correctas con procesos/conexiones distintas.
- Dos descuentos de 4 sobre saldo 5: una sola operación confirmada.
- Misma clave simultánea: mismos IDs y un movimiento; distinto payload: conflicto.
- Compensaciones simultáneas: solo una repone el origen completo.
- Espera de bloqueo observada en performance_schema.data_lock_waits para el
  connection ID del worker temporal. No se infiere la espera por un sleep.
- Orden inverso de líneas termina correctamente; permiso revocado no escribe.
- Error SQL en segunda línea revierte saldo, versión, documento y movimientos.
- SAVEPOINT preserva el trabajo anterior del coordinador y rollback externo
  elimina los cambios de inventario.

Los runners exigen identidad de origen y conectan administrativamente sin
seleccionar su base. Crean cp_multinegocio_test_* con token privado y ejecutan
DDL/deploy solo tras marcar el destino. Limpieza verifica nombre y token.
Los workers verifican esa marca antes de seleccionar el esquema temporal.
Las carpetas de barrera también están marcadas y se limpian tras terminar workers.

    php scripts/tests/run-almacenes-3c.php --host 127.0.0.1 --base casa-pestalozzi --entorno development --solo-motor
    php scripts/tests/run-almacenes-3c-concurrencia.php --host 127.0.0.1 --base casa-pestalozzi --entorno development

La suite JS base pasa. PHP reproduce su fallo anterior de
run-reservaciones-cierre-static.php sobre estados de entrega compatibles con
cocina; no se elimina ni altera esa prueba. No se modifica la base configurada,
main ni los 112 productos comerciales. Sin push/merge.
