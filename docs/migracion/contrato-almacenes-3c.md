# Contrato del motor de Almacenes 3C

El motor nuevo se obtiene con
Inventario::porAlmacenes(mysqli, actorId, authNivel), o construyendo Almacenes
con esos mismos argumentos. El actor y el nivel proceden de la sesión o del
coordinador servidor autenticado, nunca de parámetros del cliente. No hay rutas
nuevas ni activación del POS. El servicio antiguo conserva sus consumidores
hasta la adaptación explícita de 3D.

## Autorización

Contexto es el slug global/restaurante/panaderia. Cada llamada recarga el perfil
3B y comprueba inventario/ver o inventario/editar y autenticación por contraseña.
Las escrituras usan lecturas actuales FOR SHARE para identidad, membresías,
permisos y facultades, incluso en una transacción externa REPEATABLE READ.
Una revocación se serializa con una escritura que ya obtuvo esos bloqueos;
la siguiente llamada deniega los derechos revocados.

Crear almacén requiere Global + inventario/editar + almacenes.crear; puede
elegir cualquiera de los tres propietarios conocidos. Crear/editar artículo
maestro requiere Global + inventario/editar + catalogo.editar. Los negocios solo
asocian identidades existentes y operan almacenes propios. Global escribe solo
en almacenes Global; consultar consolidado no concede escritura ajena.
Conforme al contrato 3B, Global inventario/ver permite lectura consolidada
de los tres ámbitos conocidos sin conceder navegación local ni membresías.

## Métodos 3C.1

| Método | Argumentos después de contexto |
|---|---|
| crearAlmacen | propietarioSlug, nombre, permitirNegativos=false |
| configurarAlmacen | almacenId, datos(nombre?, activo?, permitir_negativos?) |
| eliminarAlmacen | almacenId; sin existencias, pendientes ni historial |
| crearArticulo | datos(nombre, unidad, costo decimal, activo?) |
| editarArticulo | articuloId, datos(nombre?, unidad?, costo?, activo?) |
| asociarArticulo | almacenId, articuloId, minimo='0.000'; inicia saldo cero |
| configurarAsociacion | almacenId, articuloId, datos(minimo?, activo?) |
| existencia | almacenId, articuloId; incluye inactivos autorizados |
| registrarEntrada | documento; cantidades positivas y costo opcional |
| registrarAjuste | documento; cantidad contada y versión esperada por línea |
| registrarMerma | documento; motivo del catálogo existente MOTIVOS_MERMA |
| registrarSalida | documento; descuento interno, sin venta/transferencia publicada |
| revertirMovimientos | documento; movimiento_id y cantidad a compensar |

Documento: clave, referencia, origen (opcional, motor_inventario), motivo, nota
opcional y lineas. De 1 a 200 líneas por documento. Cada línea normal contiene
almacen_id, articulo_id, cantidad; entrada acepta costo_unitario y ajuste exige
version. Reversión solo recibe movimiento_id/cantidad: deriva el almacén,
artículo y costo históricos del movimiento original. Se rechazan campos ajenos.

Cantidades son cadenas exactas hasta tres decimales, costos hasta cuatro.
No se admiten float, notación exponencial, exceso de precisión ni fuera de rango.
El motor calcula con enteros escalados; nunca usa float para decidir saldos.
Agrega líneas repetidas antes de operar y ordena bloqueos por almacén/artículo.
Un ajuste repetido o líneas con costos incompatibles se rechazan.

Una entrada no cambia el costo maestro; el costo explícito o vigente queda
congelado en su movimiento. Cambiar la unidad maestra después de movimientos
se rechaza. Un nombre maestro duplicado se rechaza por restricción única.

## Transacciones e idempotencia

Standalone: una transacción InnoDB cubre documento, saldo y bitácora. Retorna
ok=true y confirmado=true solo después de commit. Con transacción externa usa
SAVEPOINT y retorna ok=null, confirmado=false, pendiente=true; el coordinador
debe confirmar antes de anunciar éxito. Un error revierte solo el savepoint y
conserva trabajo anterior del coordinador; si InnoDB aborta su transacción
completa retorna TRANSACCION_ABORTADA y exige rollback del coordinador.
No se reintenta una transacción externa. Deadlock/timeout standalone admite
hasta tres intentos completos, revalidando autorización en cada uno.

El documento reserva clave/origen/unidad y referencia/tipo/unidad con UNIQUE.
Una huella canónica incluye actor, contexto, tipo y datos agregados/ordenados.
Reintento idéntico devuelve la operación y movimientos originales sin repetir
saldo; clave o referencia con contenido distinto devuelve CONFLICTO_IDEMPOTENCIA.
La reserva, el resultado JSON y los movimientos se confirman juntos.

Respuesta de operación: codigo, operacion_id, movimientos, reintento, además
del estado transaccional. Cada movimiento incluye id, almacen_id, articulo_id,
delta, saldo y version. Los errores contienen ok=false, confirmado=false,
codigo y mensaje de dominio; no exponen SQL ni credenciales.

La reversión bloquea orígenes y lee compensaciones actuales antes de validar
la cantidad pendiente; no consulta recetas. Un movimiento cero no se registra.
El saldo de cada asociación equivale a la suma de sus deltas. No hay stock
maestro ni tabla de totales.

## DDL mínimo

- UNIQUE ingredientes(nombre): identidad maestra compartida sin duplicaciones.
- Tipo salida negativo para el mecanismo interno sin simular ventas.
- Triggers UPDATE/DELETE de movimientos: bitácora inmutable; corregir compensando.
- Marcador multinegocio-3c-v1 al terminar DDL; el motor exige también la marca 3B.

Se conservan las demás relaciones de 3A y todos los bloqueos temporales 3B.
No se ejecuta el DDL sobre la base configurada.

## Consultas 3C.2

Almacenes expone consultarExistencias, consultarAlmacenes y consultarMovimientos,
todos con contexto y filtros opcionales. Retornan total, pagina, por_pagina y
filas estructuradas; no exponen una ruta HTTP nueva. La autenticación y el
alcance se recargan y bloquean antes de leer. Una consulta independiente usa
REPEATABLE READ para que conteo, página y desglose pertenezcan a la misma
instantánea. Una transacción externa conserva su instantánea de datos, pero la
autorización se obtiene de lecturas actuales; no revive permisos revocados.
En ella también se conserva el estado pendiente del coordinador.

Existencias admite q, negocio, almacen_id, articulo_id, pagina, por_pagina,
solo_alertas, sin_asociacion y estado. Almacenes admite q, negocio, almacen_id,
pagina, por_pagina y estado. Movimientos admite q, negocio, almacen_id,
articulo_id, pagina, por_pagina, desde, hasta y tipo. Estado:
todos/activos/inactivos; en existencias considera almacén, asociación y maestro.
Sin filtros, las cantidades físicas incluyen asociaciones inactivas autorizadas.
Las alertas requieren almacén, asociación y artículo activos y saldo menor al
mínimo de esa asociación; no se ocultan por excedentes de otro almacén.

Paginación de 1 a 100 filas, página hasta 100000. Fechas YYYY-MM-DD válidas,
hasta inclusiva por intervalo semiabierto al día siguiente. Búsqueda literal
escapa comodines y usa parámetros. Los filtros no aceptados se rechazan; un
almacén ajeno no devuelve cantidades ni historial. Un negocio local solo puede
filtrar su propia unidad. El filtro negocio Global no permite unidades futuras
fuera de los tres slugs conocidos.

Existencias retorna articulos, cada uno con unidad de medida, costo maestro,
saldo_total, minimo_total, alerta y ambitos. Cada ámbito contiene saldo, minimo
y almacenes con saldos, mínimos, estado y versión. No se suman artículos
distintos. Los totales son cadenas exactas y pueden superar el rango de una
asociación individual; el acumulador admite 18 cifras escaladas y rechaza
desbordamiento. El catálogo Global incluye artículos sin asociaciones con cero
y desglose vacío; sin_asociacion=true los filtra, sin filtro físico. Local solo
lista artículos asociados a sus almacenes.

El listado no hace consultas por artículo. La prueba compara Com_stmt_execute
para páginas de 1 y 100 artículos y exige el mismo número de consultas.
Dos índices adicionales soportan los historiales paginados por fecha/id:
idx_mov_unidad_fecha y idx_mov_almacen_fecha. EXPLAIN FORMAT=TRADITIONAL
comprueba que son índices elegibles para ambos patrones. No hay tabla de totales.
