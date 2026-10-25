# Etapa 3C.2 — Consultas consolidadas y autorización

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


Primera entrega: commit 685b2b0. La segunda entrega incorpora
ConsultasAlmacen y los tres métodos de consulta expuestos por Almacenes.
Véase el [contrato completo](contrato-almacenes-3c.md) para firmas, filtros,
respuestas y límites transaccionales.

## Resultado funcional

Global consulta inventario consolidado por artículo, ámbito y almacén. Local
solo consulta almacenes, existencias e historial propios. Global de lectura
no concede operaciones físicas locales. Las consultas reconocen inactivos con
historial y artículos sin asociaciones; no inventan un almacén consolidado.
Los filtros de búsqueda, negocio, almacén, artículo, estado, alertas y fechas
se validan en servidor y se aplican en SQL parametrizado.

El fixture Fresa comprueba Global 6 kg, Restaurante 3 kg (dos almacenes, 2+1)
y Panadería 1 kg: total físico 10 kg. Cada cantidad queda explicada en su
desglose. Otro caso suma 1800000000.000 sin truncar al máximo de una asociación
individual. No se suman artículos o unidades de medida diferentes.

## Validación

| Grupo | Evidencia |
|---|---|
| Motor 3C.1 | 77 comprobaciones correctas en su commit |
| Motor + consultas 3C.2 | 158 comprobaciones correctas en base temporal |
| Concurrencia | 17 correctas; procesos PHP y conexiones InnoDB independientes |
| Regresión 3A | 114 correctas, dos destinos nuevos; 112 productos y 13 imágenes preservados |
| Regresión 3B | 128 correctas; permisos directos y credenciales conservados |
| PHP existente | Reproduce el fallo previo de estados de entrega de reservaciones |
| JavaScript existente | Suite completa correcta al inicio y al cierre; 147 enlaces locales verificados |

Las pruebas de consulta cubren la distribución por tres ámbitos, varios
almacenes del mismo negocio, artículos exclusivos, maestro sin asociación,
búsqueda/paginación, filtros combinados, inactivos con historial, lectura sin
escritura, recurso ajeno, filtros manipulados, revocación y consistencia SQL.
Com_stmt_execute demuestra número constante de consultas con páginas de 1 y
100 artículos. EXPLAIN verifica los dos índices nuevos de historial.

Una conexión independiente confirma una compensación mientras el coordinador
mantiene una instantánea REPEATABLE READ anterior. El motor rechaza otra
compensación, usando lecturas actuales de los movimientos. Otra prueba revoca
el permiso tras crear la instantánea externa y comprueba la denegación tanto
del consolidado local como de la lectura individual.

    php scripts/tests/run-almacenes-3c.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
    php scripts/tests/run-almacenes-3c-concurrencia.php --host 127.0.0.1 --base casa-pestalozzi --entorno development

## Revisión de autorización e integración

La revisión inicial asistida identificó los límites de Global/local, el alcance
de la consolidación y las carreras de compensación. La integración final
verificó cada método público, los filtros SQL, la autenticación por contraseña,
el bloqueo actual de membresías/permisos, los savepoints y la huella idempotente.
No hay actor ni unidades permitidas aceptados dentro del payload.
La bitácora rechaza UPDATE/DELETE también en SQL; el catálogo maestro no tiene
stock. Una excepción no confirma una operación parcial ni expone SQL.

La aplicación anterior mantiene sus consumidores. No se retiró MODULO_PENDIENTE
de los módulos nuevos ni se publicaron interfaces o APIs de Almacenes. La
conexión/actor/nivel del servicio son una interfaz interna para coordinadores
autenticados; no son parámetros HTTP. En 3D deberá conectarse el documento
comercial a esta misma transacción y emitir éxito solo tras su commit.

## Límites y estado de Git

No hay transferencias, tránsito, ventas integradas, POS Panadería ni adaptación
comercial de Restaurante. No se modificaron sus productos ni la base configurada.
No se migraron saldos, movimientos o costos anteriores. Los fixtures incluyen
un almacén Restaurante con negativos habilitados; no es un seed de operación.

El seed admin_demo de database/deploy.sql y package-lock.json eran cambios
preexistentes: se conservan sin incluirlos en los commits de 3C. main no cambia;
sin push ni merge. Los archivos nuevos no requieren paquetes adicionales.
La validación concurrente local no constituye certificación de rendimiento o
recuperación tras caída de un despliegue real. Se detiene el trabajo al cerrar 3C.
