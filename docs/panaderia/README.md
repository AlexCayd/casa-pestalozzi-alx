# Panadería

Usuarios y Almacenes son funcionales mediante componentes compartidos con los
otros ámbitos. El menú administrativo permite administrar ofertas locales:
altas, precios, orden, disponibilidad, imágenes y categorías, con el mismo
recorrido de Restaurante. Recetas operativas versionadas y variantes se
administran en el contexto local.
La [caja de mostrador](../modulos/caja-panaderia.md) (`/panaderia/caja`) vende
y cobra sin mesas, descuenta piezas de vitrina, registra horneadas, anula
cobros del día y da el corte; «Vitrina de la caja» define en Menú las opciones
y la pieza de cada pan. Finanzas, Análisis de datos y el Asistente IA leen sus
tickets ([Finanzas](../modulos/finanzas.md), [analíticas](../../database/ANALITICAS.md)).
Producción física y la integración de presentaciones a la caja siguen pendientes.

- [Catálogo comercial y Menú](../modulos/catalogo-comercial.md).
- [Landing pública `/panaderia`](landing.md): vitrina, temporada, encargos y las tres capas (panadería, restaurante y marca).

- [Arquitectura y estado de módulos](../arquitectura.md#3-ámbitos-propiedad-y-estado-de-módulos).
- [Usuarios](../usuarios/usuarios.md) y [Almacenes](../modulos/almacenes.md).
- [Backlog y dependencias](../backlog.md).
- [Interfaz compartida](../desarrollo/interfaz.md).

Consultar Git para entregas y capturas iniciales de contextos. El estado actual
se verifica con las [pruebas vigentes](../desarrollo/pruebas.md).
