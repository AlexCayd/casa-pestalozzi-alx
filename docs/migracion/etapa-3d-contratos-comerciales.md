# Etapa 3D — Contratos comerciales

## 3D.1: identidad, oferta y preparación

En el esquema multinegocio, los formularios existentes conservan `id` como
identificador de la oferta. Los DTO exponen además `oferta_id`, `producto_id`
y `unidad_id`; el identificador de producto representa la identidad compartida.
Ningún consumidor debe suponer que ambos identificadores coinciden.

La oferta determina precio, categoría, área, disponibilidad y orden. Crear una
oferta de otro negocio reutiliza el producto maestro. Modificar su identidad
cuando tiene ofertas compartidas requiere la facultad Global `catalogo.editar`.
Retirar una oferta conserva el producto y las referencias de las ventas.
La limpieza de imágenes conserva archivos todavía referenciados por cualquier
producto o categoría, independientemente de su negocio.

La carta pública y las dos piezas PDF consultan exclusivamente Restaurante,
sin depender de la sesión del administrador. El catálogo POS exige permiso
operativo. Menú y Recetas mantienen sus permisos independientes; una concesión
de lectura no permite escribir, y ningún rol agrega derechos implícitos.

Las recetas pertenecen a la oferta y su unidad. Cada guardado crea una versión
y desactiva la anterior; una lista vacía desactiva la receta vigente y deja
explícitamente la oferta sin receta. Las versiones anteriores conservan sus
componentes. Cantidades y rendimientos se validan como decimales exactos con
hasta tres posiciones; no se redondea silenciosamente una entrada inválida.
Las subrecetas y los ajustes de variantes rechazan referencias de otro negocio.

Las escrituras revalidan la autorización dentro de su transacción. Los servicios
que participan mediante savepoint devuelven estado pendiente; únicamente su
coordinador puede comunicar una confirmación después del commit exterior.

La selección del esquema conserva exclusivamente el código anterior para la
instalación previa al corte 3E. No hay doble escritura, sincronización ni vistas
de compatibilidad. Los bloqueos temporales se retiran únicamente para módulos
adaptados de Restaurante; las nuevas interfaces de Panadería siguen pendientes.

## Validación reproducible

El runner `scripts/tests/run-restaurante-3d1.php` recibe las confirmaciones de
origen y la ruta del paquete privado preservado mediante `--host`, `--base`,
`--entorno` y `--paquete`. Conecta al servidor sin seleccionar la base original,
crea otra base aleatoria con marca de propiedad, importa DDL, seed y paquete,
y elimina exclusivamente los recursos cuya propiedad puede verificar.

La equivalencia comercial se compara antes de introducir fixtures: 112 productos,
112 ofertas de Restaurante, 15 categorías, 4 áreas y 13 archivos. La oferta de
Panadería usada para probar precio y receta propios existe únicamente en esa
base desechable. El paquete privado no se incorpora al DML durante 3D.

La validación HTTP, la generación efectiva de documentos y el recorrido visual
integrado corresponden al cierre 3D.4; las comprobaciones de servicio no los
sustituyen. Tampoco certifican impresoras físicas ni credenciales externas.

Resultado de servicio 3D.1: 54 comprobaciones correctas, incluida reversión de
encabezado/composición de subreceta ante un ingrediente inválido, versiones
históricas, permisos separados e imagen compartida. Regresiones ejecutadas:
3A, 114 comprobaciones en dos bases temporales; 3B, 128 comprobaciones;
preservación del menú, 34; recursos PDF, 15; especiales de temporada, 30.
Todos los destinos temporales fueron verificados y eliminados. Las suites
generales conservan los dos fallos iniciales documentados en la auditoría;
su resolución y repetición integral siguen pendientes de 3D.3/3D.4.
