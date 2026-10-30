# Recetas operativas y recetas de la casa

La receta operativa pertenece a una oferta y a su negocio. `RecetasOferta` conserva
su contrato de versiones en `recetas` y `receta_componentes`; las subrecetas y
variantes locales existentes siguen funcionando para los productos anteriores.
No se modifican automáticamente los 112 productos preservados.

Global administra recetas de la casa dentro de Productos de la casa. El borrador
usa ingredientes del catálogo compartido, cantidades en su unidad canónica y un
rendimiento positivo. La publicación crea una versión inmutable con actor y fecha.
Los triggers rechazan cambios, eliminación o adición de componentes posteriores a
su cierre. Las subpreparaciones se desglosan en ingredientes; no se referencia una
subreceta privada desde un estándar Global.

Publicar exige `menu/editar` Global y `comercial.identidad.editar`; activar en los
negocios exige los permisos locales de Menú y Recetas. Configurar un origen exige
además `inventario/editar` del propietario. Las lecturas de almacenes respetan su
permiso de Inventario. Ni los destinos ni los IDs del formulario conceden acceso.

Para un producto nuevo, puede publicarse primero su oferta sin activar y sin
receta. Después se guarda el borrador de ingredientes y se eligen explícitamente
los orígenes de consumo por negocio entre asociaciones físicas activas existentes.
Publicar la receta valida todas las ofertas que la siguen y prepara conjuntamente
sus nuevas versiones operativas. La ficha del producto permite activar después la
disponibilidad. No se crean asociaciones físicas, existencias o movimientos.

El rendimiento convierte cantidades del lote a la cantidad por unidad vendida.
Se usan enteros escalados: si el resultado no se expresa exactamente a milésimas,
se rechaza y se pide ajustar cantidades/rendimiento. Un ingrediente inactivo, un
origen ausente/ajeno o una variante incompatible revierte toda la publicación,
incluida la versión estándar. No se dejan negocios parcialmente activados.

Una oferta sigue la receta de la casa, usa una adaptación permitida o está sin
receta. `receta_origen_casa` identifica inequívocamente la versión estándar aplicada
a cada receta operativa. Adaptar exige autorización por campo y permiso local de
Recetas; las adaptaciones de productos de la casa usan ingredientes desglosados y
validan sus orígenes. Volver a la casa revalida esos orígenes. Una receta requerida
no se puede retirar para activar una oferta incompleta.

La publicación no publica implícitamente otro borrador comercial pendiente. Primero
debe revisarse ese borrador; después la receta actualiza la versión elegida mediante
el mismo coordinador de publicación comercial, con revisión, huella y confirmación.
Dos procesos compiten por la misma revisión: uno publica y el otro debe recargar.

El POS Restaurante continúa tomando `ticket_items.receta_id` al enviar la comanda;
el consumo y las compensaciones usan esa versión y los movimientos congelados.
Las nuevas recetas no reescriben snapshots ni componentes históricos. Las variantes
se comprueban contra los ingredientes y cantidades nuevas y sus orígenes locales.
No se habilita POS ni producción por lotes de Panadería. M2 habilita el catálogo y las elaboraciones administrativas de Panadería,
conservando estos contratos de oferta como adaptadores.

Recetario: `/admin/global/productos/recetario`, con búsqueda y paginación.
Rutas: `/admin/global/productos/receta?id=…`,
`/admin/menu/casa-receta?id=…` y `/admin/panaderia/menu/casa-receta?id=…`.
El identificador local es de oferta; el Global identifica el producto. Los formularios
comparten el parcial de ingredientes, estilos del Menú y confirmación del panel.
Pruebas independientes: `run-recetas-casa-m12.php`; incluye consumo real desde Cocina,
publicación concurrente, receta histórica, inmutabilidad SQL y snapshot de comanda.

## Artículos y elaboraciones M2

El catálogo compartido se abre desde `/admin/recetas/articulos`,
`/admin/panaderia/recetas/articulos` o `/admin/global/recetas/articulos`.
La ficha `/editar?id=…` identifica un artículo operativo, no un producto de venta.
El alta maestra sigue en Almacenes con sus permisos existentes. En cada ficha se
configuran capacidades combinables, se consulta el historial, se prepara una
composición y se revisa su previsión antes de confirmar la publicación inmutable.

Cada componente exige cantidad/unidad y modalidad. `almacenado` descuenta el
artículo y detiene el desglose. `desglosar` referencia una versión publicada y
escala por su rendimiento y merma. Puede contener otros artículos elaborados:
no existen un motor para ingredientes y otro para subpreparaciones. Se rechazan
ciclos por artículo, dimensiones incompatibles, versiones ajenas, rendimientos
inválidos y cantidades que no puedan expresarse en la precisión física.

Marcar «Se elabora antes de vender» distingue planificación y servicio: la venta
requiere existencias previas del artículo almacenado incluso si el almacén
permite negativos. Publicar la elaboración no registra producción. Una botella
sellada puede producir conceptualmente 750 ml de vino abierto; la copa consume
150 ml abiertos. La apertura física se reserva para M2.5.

Las preparaciones antiguas se asocian manualmente al artículo correspondiente en
el catálogo, con confirmación. Se exige la misma unidad para conservar sus
cantidades, se guarda correspondencia única y se fijan versiones de los usos
vigentes. Después se editan desde su artículo; los históricos siguen disponibles.
No se deduplican nombres ni se copian automáticamente productos comerciales.

Las recetas de la casa pueden fijar elaboraciones Global. Publicar, adaptar y
volver al estándar conservan revisión, huella, confirmación, permisos y rollback
de todos los negocios. Los orígenes se validan sobre los artículos físicos
resultantes, no sobre una preparación virtual intermedia.

Ver [arquitectura](../arquitectura.md),
[catálogo](catalogo-comercial.md#presentaciones-y-variantes-m2) y
[pruebas](../desarrollo/pruebas.md#cierre-m2).
