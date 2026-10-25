# Almacenes

Contrato funcional de la interfaz administrativa compartida de Global,
Restaurante y Panadería. Se apoya en el [motor 3C](../arquitectura.md#5-almacenes-e-inventario)
y en el [contrato rector](../arquitectura.md); no implementa un motor por ámbito.

## Superficies y permisos

| Ámbito | Ruta | Lectura | Escritura |
|---|---|---|---|
| Global | `/admin/global/almacenes` | Propios y consolidado autorizado | Solo físicos Global; creación/asignación de almacenes con facultad |
| Restaurante | `/admin/almacenes` | Almacenes y movimientos propios | Asociaciones y operaciones físicas propias |
| Panadería | `/admin/panaderia/almacenes` | Almacenes y movimientos propios | Las mismas operaciones locales de Restaurante |

El módulo es `inventario`: `ver` permite consultar y `editar` permite mutar según
el alcance del recurso. Global necesita además `almacenes.crear` para crear un
almacén físico y elegir propietario, y `catalogo.editar` para el maestro de
artículos. El consolidado no concede permiso para operar físicamente otro negocio.

`AdminAlmacenesController` obtiene el contexto de la ruta, el actor y nivel de
sesión del servidor y valida CSRF vinculado a la superficie. El coordinador
`AdministracionAlmacenes` adapta formularios al motor, que vuelve a comprobar
perfil, pertenencia, estado y reglas de operación. Los IDs del navegador no
autorizan una unidad, un almacén o un artículo ajenos.

## Consultas

La vista utiliza pestañas de existencias, almacenes y movimientos. Global dispone
también del catálogo maestro. Búsqueda, negocio, almacén, fechas, tipo y alertas
se aplican según el listado. Las cantidades se muestran por artículo/unidad de
medida y se desglosan por ámbito y almacén; no sumar piezas, gramos y litros
como si fueran una misma magnitud.

El saldo físico pertenece a `almacen_articulos`. Las alertas requieren artículo,
almacén y asociación activos y saldo inferior a su mínimo. Un excedente de otro
almacén no oculta ese faltante. El consolidado es una consulta, nunca otra fila
física ni un saldo editable global.

## Administración y operaciones

- Global autorizado crea almacenes físicos con propietario Global, Restaurante
  o Panadería. Cambiar propietario después de operaciones no equivale a editar
  libremente una columna: requiere un flujo futuro explícito.
- La configuración controla nombre, estado y negativos por almacén dentro del
  alcance permitido. La baja física está limitada a almacenes sin existencias,
  operaciones o dependencias que impidan eliminarlo.
- El maestro de artículos es compartido y solo se modifica desde Global autorizado.
  Los negocios asocian identidades existentes con mínimo y estado propios.
- Entradas, ajustes y mermas requieren almacén y artículo explícitos. Los ajustes
  envían la versión observada para detectar cambios concurrentes.
- Los formularios generan una clave idempotente y transmiten cantidades/costos
  como cadenas decimales. El motor valida precisión, negativos y bloqueos.
- Saldos, operación y movimientos se confirman atómicamente. No presentar éxito
  mientras un resultado esté pendiente de una transacción exterior.

## Trazabilidad y límites

La bitácora conserva actor, origen, clave, referencia y movimientos. El saldo de
cada asociación corresponde a sus deltas; se corrige compensando, no editando
movimientos históricos. Los fixtures de apertura cumplen esa misma integridad.

No se implementan transferencias, producción ni variantes avanzadas de inventario.
Los gráficos avanzados y filtros adicionales pertenecen al [backlog](../backlog.md).
El antiguo Inventario de Restaurante conserva sus consumidores operativos;
Almacenes comparte el motor y no introduce escrituras paralelas de saldo.

## Verificación

Ejecutar el motor y sus pruebas de concurrencia 3C, los ensayos 3E, las
[instalaciones independientes](../desarrollo/instalacion.md) y las
[pruebas HTTP/navegador](../desarrollo/pruebas.md). Comprobar solo consulta,
revocación, IDs ajenos, fallo de una línea, negativos por almacén, replay,
consolidado y las mismas interacciones en escritorio y móvil.
