# Panadería

Usuarios y Almacenes son funcionales mediante componentes compartidos con los
otros ámbitos. Menú administrativo M1 permite altas, asociación de identidades, ofertas, precios,
orden, disponibilidad, imágenes y categorías con el mismo recorrido de Restaurante.
Finanzas, Análisis de datos y el Asistente IA son funcionales con métricas de
panadería: sus ventas en caja contestan en cero hasta que exista la caja
([Finanzas](../modulos/finanzas.md), [analíticas](../../database/ANALITICAS.md)).
Recetas administrativas completas, POS sin mesas, tickets y pagos siguen
pendientes; no se activan desde fixtures.

- [Catálogo comercial y Menú](../modulos/catalogo-comercial.md).
- [Landing pública `/panaderia`](landing.md): vitrina, temporada, encargos y las tres capas (panadería, restaurante y marca).

- [Arquitectura y estado de módulos](../arquitectura.md#3-ambitos-propiedad-y-estado-de-modulos).
- [Usuarios](../usuarios/usuarios.md) y [Almacenes](../modulos/almacenes.md).
- [Backlog y dependencias](../backlog.md).
- [Interfaz compartida](../desarrollo/interfaz.md).

Consultar Git para entregas y capturas iniciales de contextos. El estado actual
se verifica con las [pruebas vigentes](../desarrollo/pruebas.md).
