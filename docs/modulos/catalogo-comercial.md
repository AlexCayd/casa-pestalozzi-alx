# Menú comercial local después de A4

## Estado vigente

Productos de la casa y su catálogo comercial Global fueron retirados en A4. No
existe un escritor ni un flujo equivalente de publicación, adhesión, políticas o
personalización entre negocios. Global conserva Artículos, Inventario, Usuarios,
Transferencias y las funciones transversales autorizadas; no crea ni administra
ofertas comerciales.

Restaurante y Panadería administran su propio menú y sus ofertas dentro del
contexto local. Nombre, descripción e imagen se guardan en
`oferta_datos_comerciales`; precio, disponibilidad, categoría, área y orden son
propiedades de la oferta. Las escrituras requieren permisos del negocio dueño.
Crear un producto no requiere configurar receta ni presentación y no inventa una
composición.

Las recetas operativas, versiones de presentaciones, variantes, ajustes,
referencias a artículos, almacenes y snapshots previos se conservan. Una receta
que alguna vez se originó en un estándar Global es una receta local versionada:
puede consultarse, usarse en ventas existentes y editarse mediante el flujo local
que crea versiones nuevas. Los tickets siguen apuntando a sus versiones y
snapshots originales. Las presentaciones estándar heredadas se venden según la
versión local válida, sin consultar `presentacion_estandares` para autorizar la
venta.

Las tablas de publicaciones, personalizaciones, estándares y recetas oficiales,
así como sus triggers históricos, permanecen intactos para preservar referencias.
El código operativo ya no las lee para decidir identidad comercial, permisos,
composición, precio ni venta. No se eliminan productos ni datos históricos durante
esta retirada.

## Rutas y permisos

El menú administrativo existe solo en los contextos locales habilitados. Global
no tiene el módulo `menu`; conserva `productos` para Artículos y preparaciones.
`productos` no representa un permiso para crear o compartir productos comerciales.
Los PDF administrativos Global se leen bajo el permiso de Menú del negocio dueño;
no habilitan escritura ni requieren conservar el antiguo módulo comercial.

Las rutas antiguas de Productos de la casa y publicaciones oficiales ya no están
registradas y responden de forma segura. Las rutas locales de menú, recetas,
presentaciones y variantes siguen usando el contexto y autorización del negocio.

## Regresión A4

`php scripts/tests/run-menu-a4.php` crea una base temporal propia y comprueba
retiro de rutas/escritores, cierre de permisos Global, edición y aislamiento local,
menús, PDFs, POS, recetas y presentaciones históricas, variantes, consumo,
cancelaciones y snapshots. El runner elimina la base temporal que creó. La suite
de interfaz está en `scripts/tests/run-menu-a4-ui.cjs`.

Las decisiones y límites de preservación de datos están en [Arquitectura](../arquitectura.md)
y en [Pruebas](../desarrollo/pruebas.md#cierre-a4). El estado anterior se conserva
en la historia de Git, no como procedimiento vigente.
