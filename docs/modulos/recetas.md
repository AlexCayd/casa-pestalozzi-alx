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


## Integración de composiciones UX-3

La elaboración de un **artículo** define un lote, rendimiento y componentes de una
versión publicada. Una **presentación** define cantidades y modalidades por unidad
vendida, precio y disponibilidad de una oferta. La **receta de la casa** es un
estándar comercial con sus adaptadores operativos y políticas M1.2. No se fusionan
estas identidades ni se convierten recetas al consultar o editar datos comerciales.

`ComposicionComercial` proyecta el mecanismo vigente para la ficha y sus enlaces;
no resuelve consumo. Las configuraciones comerciales nuevas utilizan el editor
compartido de Presentaciones. Las recetas anteriores de Restaurante conservan su
escritor compatible y muestran sus elaboraciones fijadas. El editor anterior no
ofrece preparaciones versionadas nuevas como ingredientes simples: ese caso pasa
a Presentaciones, donde se eligen modalidad y versión. Si una receta anterior fija
varias versiones de un mismo artículo o una preparación anterior vinculada a una
versión publicada, se conserva consultable y se bloquea su escritura visual: el
escritor anterior no puede mantener fielmente esas referencias por fila.
Los escritores de servicios usados por compatibilidad y publicación siguen activos.

Una oferta con presentaciones publicadas utiliza ese editor como vía de consumo;
los accesos y POST de los editores comerciales anteriores no crean una composición
operativa paralela. La receta anterior se conserva y puede consultarse. En
Panadería no se envían IDs locales al editor de Restaurante: la presentación
muestra la receta anterior conservada y permite configurar un consumo nuevo de
forma explícita. Guardar un atributo comercial no publica ni convierte recetas.

La ficha de artículo y su listado usan `Articulos::estadoAdministrativo`, que
consulta `cargarArticulo`, el mismo lector efectivo del motor. Distinguen origen
explícito, compartido o heredado y estados incompletos/incompatibles. La ausencia
de una fila propia no implica ausencia de capacidad de almacenamiento. Las
existencias no habilitan capacidades por inferencia; se consultan como información
física solo con permiso de Inventario. La proyección no escribe configuración,
asociaciones, saldos o movimientos. Publicar una elaboración propia sigue exigiendo
configuración explícita autorizada. Si fijar capacidades heredadas requiere edición
física que el actor no tiene, la ficha explica esa dependencia.

La consulta `/recetas/articulos/editar?id=…&version=…` y `&comparar=…` valida artículo,
unidad y publicación. Lee los componentes inmutables de cada versión, su rendimiento,
fecha, procedencia, unidades, merma y referencias fijadas. No ejecuta la receta
vigente de sus componentes ni aplica variantes actuales. Las referencias compartidas
Global son consultables conforme al lector M2; las versiones privadas ajenas se
rechazan. El historial no acepta POST y enlaza a la ficha actual para preparar una
versión nueva. Los nombres de artículos siguen siendo etiquetas del catálogo;
las cantidades, identidades y versiones consultadas proceden del registro publicado.

Las presentaciones oficiales se seleccionan por `referencia` estable dentro del
producto. Se cargan desde `presentacion_estandares.configuracion`, nunca desde una
adaptación local. La referencia no cambia al actualizar. La revisión muestra el
oficial vigente, la propuesta, las nuevas versiones por destino y las
personalizaciones que se conservarán. Previsión y publicación reutilizan la misma
selección de configuración efectiva por destino. Se mantienen la transacción,
huella, permisos, confirmación e idempotencia existentes; actualizar añade versiones
sin reemplazar las anteriores. Los artículos inactivos ya referenciados permanecen
identificables en el formulario, aunque el servidor rechaza su nueva publicación.

Los retornos de UX-2 conservan selección de presentación al visitar variantes,
filtros, origen y versiones consultadas. Todos los parámetros nuevos tienen rutas,
formatos y pertenencia validados. No se añade persistencia de navegación ni SQL.

Pruebas dirigidas: `run-ux3.php`; preparar `fixture-ux3.php --fixture ARCHIVO_PRIVADO`
y ejecutar `pruebas-http-ux3.php --fixture ARCHIVO_PRIVADO` en el harness 3E aislado.
Cada preparación genera identidades sintéticas nuevas; el runner modifica solo su
base temporal verificada y restaura los permisos de prueba al terminar.
