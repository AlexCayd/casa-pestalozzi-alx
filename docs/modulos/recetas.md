# Recetas operativas

La receta operativa pertenece a una oferta y a su negocio. `RecetasOferta` conserva
su contrato de versiones en `recetas` y `receta_componentes`; las subrecetas y
variantes locales siguen funcionando. A4 no modifica recetas, componentes,
versiones, tickets ni snapshots existentes.

Las recetas materializadas anteriormente desde Productos de la casa son recetas
locales. Continúan siendo consultables y utilizables por sus ofertas. Una edición
local crea una versión nueva bajo las reglas de inmutabilidad existentes; no
sincroniza cambios de un estándar Global ni reemplaza versiones ya utilizadas.
Global ya no tiene recetario comercial, publicación de recetas ni rutas de
adaptación/restauración de estándares.

Al crear una oferta nueva no se exige receta ni se inventa una composición. El
negocio puede configurar después una receta operativa, presentación o variante en
su propio contexto. Seleccionar orígenes físicos requiere las autorizaciones
locales de Artículos e Inventario aplicables. No se crean asociaciones, existencias
ni movimientos para completar una receta.

El POS Restaurante continúa tomando `ticket_items.receta_id` al enviar la comanda;
el consumo y las compensaciones usan la versión y los movimientos congelados. Las
nuevas versiones no reescriben snapshots ni componentes históricos. M2 habilita
artículos y elaboraciones administrativas de Panadería, pero no su POS ni la
producción física por lotes.

Las tablas `recetas_casa`, `receta_casa_versiones`, `receta_casa_componentes` y
`receta_origen_casa`, junto con sus referencias y triggers, se conservan como
histórico. El código operativo de A4 no depende de ellas para componer, autorizar
ni vender.

## Artículos y elaboraciones M2

El catálogo compartido se abre desde `/admin/recetas/articulos`,
`/admin/panaderia/recetas/articulos` o `/admin/global/recetas/articulos`.
La ficha `/editar?id=…` identifica un artículo operativo, no un producto de venta.
El alta maestra utiliza el catálogo persistente con el escritor y permisos de Almacenes (UX-4). En cada ficha se
configuran capacidades combinables, se consulta el historial, se prepara una
composición y se revisa su previsión antes de confirmar la publicación inmutable.

Cada componente exige cantidad/unidad y modalidad. `almacenado` descuenta el
artículo y detiene el desglose. `desglosar` referencia una versión publicada y
escala por su rendimiento y merma. Puede contener otros artículos elaborados:
no existen un motor para ingredientes y otro para subpreparaciones. Se rechazan
ciclos por artículo, dimensiones incompatibles, versiones ajenas, rendimientos
inválidos y cantidades que no puedan expresarse en la precisión física.

Marcar «Se elabora antes de vender» distingue planificación y servicio. Desde M3
ni activar la presentación ni vender exige existencias terminadas: sin saldo, la
venta genera un faltante pendiente de origen como cualquier artículo. Publicar la
elaboración no registra producción. Un vino se guarda como un solo artículo en ml
con contenido por pieza de 750: la botella de reventa descuenta 750 ml y la copa
150 ml del mismo saldo (arquitectura §16).

**Tipo de venta (M3).** Cada oferta es *preparado al momento* (receta o
presentación) o *reventa* (un solo artículo almacenado por unidad vendida). En
una reventa confirmada, la presentación o la receta legada aceptan un único
componente almacenado de servicio y las variantes sólo pueden cambiar precio.
«Ingrediente» es la capacidad `componente` del artículo («Se usa como
ingrediente»); un artículo puede ser ingrediente y reventa a la vez.

Las preparaciones antiguas se asocian manualmente al artículo correspondiente en
el catálogo, con confirmación. Se exige la misma unidad para conservar sus
cantidades, se guarda correspondencia única y se fijan versiones de los usos
vigentes. Después se editan desde su artículo; los históricos siguen disponibles.
No se deduplican nombres ni se copian automáticamente productos comerciales.

Las recetas históricas pueden conservar referencias a elaboraciones Global ya
fijadas. Esa procedencia no habilita publicación ni sincronización comercial;
los orígenes se validan sobre los artículos físicos resultantes.

Ver [arquitectura](../arquitectura.md),
[menú local A4](catalogo-comercial.md#estado-vigente) y
[pruebas](../desarrollo/pruebas.md#cierre-m2).


## Integración de composiciones UX-3

La elaboración de un **artículo** define un lote, rendimiento y componentes de una
versión publicada. Una **presentación** define cantidades y modalidades por unidad
vendida, precio y disponibilidad de una oferta. Los estándares comerciales de
Productos de la casa son únicamente historia preservada por A4; ya no tienen
adaptadores ni políticas operativas. No se fusionan identidades ni se convierten
recetas al consultar o editar datos comerciales.

`ComposicionComercial` proyecta el mecanismo local vigente para la ficha y sus
enlaces; no resuelve consumo. Las configuraciones comerciales nuevas utilizan el
editor local de Presentaciones. Las recetas anteriores de Restaurante conservan
su lector y escritor compatible versionado. Si una receta histórica contiene
referencias que su editor no puede representar fielmente, sigue siendo consultable
y se bloquea esa edición visual.

Las presentaciones vigentes usan versiones locales por oferta; la receta anterior
se conserva y puede consultarse. Guardar nombre, descripción, imagen, precio u
otros datos de oferta no publica ni convierte recetas. A4 no crea una composición
operativa paralela ni transforma automáticamente recetas anteriores en
presentaciones M2.

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

Las presentaciones que antiguamente se publicaron como estándares conservan sus
versiones locales y referencias históricas. El código de venta no consulta
`presentacion_estandares`; la autorización y validez se resuelven con la oferta y
la versión local. Las ediciones locales añaden versiones sin reemplazar las
anteriores, y los artículos inactivos ya referenciados siguen identificables en
el historial.

Los retornos de UX-2 conservan selección de presentación al visitar variantes,
filtros, origen y versiones consultadas. Todos los parámetros nuevos tienen rutas,
formatos y pertenencia validados. No se añade persistencia de navegación ni SQL.

El recorrido UX-3 de publicación oficial fue retirado en A4. Sus contratos
operativos se sustituyen por preservación de recetas/presentaciones locales en
`scripts/tests/run-menu-a4.php` y por los recorridos HTTP M2/A4.


## Catálogo y ficha UX-4

El catálogo maestro y el contextual reutilizan `articulos.php`; no existe una
segunda identidad ni autoridad de escritura. La lista mantiene 30 filas por
página, búsqueda literal escapada y filtro de actividad. Solo carga metadatos de
esa página: capacidades efectivas y número de versión, sin componentes ni toda
la historia. `cargarArticulo` y la lectura por lote comparten la misma consulta
efectiva. La proyección de estado es común a listado y ficha y se prueba su paridad.
No se materializa compatibilidad al consultar. La autorización se comprueba por
petición y por operación; agrupar lecturas no almacena decisiones de permisos.

La ficha presenta identidad/estado al inicio, capacidades agrupadas y elaboración
visible cuando aplica. Capacidades ya configuradas, versiones y usos emplean
`details/summary` nativos; los errores de capacidades abren su sección y los
retornos a anclas conocidas la despliegan. La versión base del editor y los cambios
pendientes están indicados. Guardar capacidades sigue siendo independiente de
publicar: la confirmación UX-1 protege la elaboración sin guardar ni persistirla.
La sincronización inicial de selectores precede a la captura del borrador para
no anunciar cambios falsos. Cancelar conserva valores y foco.

«Dónde se utiliza» consulta hasta 20 referencias por sección y anuncia si hay más:
elaboraciones publicadas propias/Global, preparaciones anteriores, recetas
operativas, presentaciones, ajustes de variantes y asociaciones históricas.
Cada familia exige sus permisos y contexto; no expone recetas privadas ajenas.
Las versiones históricas de elaboraciones enlazan a la consulta inmutable; las
referencias comerciales indican que su enlace abre la configuración actual.
Las asociaciones mantienen el alcance consolidado Global o físico local autorizado.

Pruebas: `run-ux4-ui.cjs` y `pruebas-http-ux4.php`, además de UX-1/2/3 y M2.
Los contratos históricos de estándares se validan como preservación, no como
flujos operativos.

## Capacidades y continuidad de alta UX

Las siete capacidades conservan los campos y el escritor M2 existentes. La ficha
las presenta en «Uso y preparación», «Obtención e inventario» y «Costo»; el estado
activo de la configuración local queda junto al estado del artículo. La única
dependencia que se explica como regla es que la producción previa requiere
elaboración propia y almacenamiento. El navegador ayuda a localizar esa regla y
el servicio M2 vuelve a validarla al guardar.

La identidad y la ficha muestran un resumen calculado a partir del estado M2, sus
versiones publicadas y las asociaciones/existencias autorizadas del contexto. La
asociación solo se ofrece cuando el artículo mantiene existencias y el actor puede
editar Almacenes. El retorno conserva el ID y el contexto; editar capacidades no
publica una elaboración ni persiste el borrador de receta.

Pruebas dirigidas: `run-articulos-capacidades-ux.cjs` y
`pruebas-http-capacidades-ux.php --fixture ARCHIVO_PRIVADO`. La prueba HTTP usa
únicamente la instalación desechable 3E.
