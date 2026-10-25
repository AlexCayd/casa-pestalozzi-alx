# Etapa 3D — Auditoría inicial y contrato de adaptación

Base real: e6cf89e, cierre de 3C.2; 3C.1 es 685b2b0 y 3B es 4d65d30.
Rama independiente codex/adaptacion-restaurante-etapa-3d, un solo worktree.
Cambios previos preservados: database/deploy.sql, package-lock.json y el nuevo
docs/contrato.md sin seguimiento. No se aplican stashes ni se modifica main.

## Precedencia resuelta antes de cambiar componentes compartidos

El contrato rector recibido describe el objetivo posterior a 3E, expresamente
sin acreditar entregas anteriores. Durante 3D rigen sus invariantes de propiedad,
autorización y preservación de Restaurante; sus procedimientos de instalación
y aprovisionamiento admin_demo son dependencia de 3E, no se adelantan.
El seed demo previo no se descarta, promueve ni incorpora a los commits de 3D.

La mención de roles por membresía del plan original queda sustituida por el
contrato 3B de permisos directos. No se agregan permisos implícitos de roles.
Los bloqueos 503 de 3B/3C fueron una frontera temporal frente al SQL sin adaptar;
en 3D se retiran solo para consumidores de Restaurante realmente adaptados y
verificados. Las capacidades futuras Global/Panadería siguen cerradas.

La base de desarrollo anterior no se modifica. Los ensayos usan exclusivamente
otra base desechable marcada. No hay vistas de compatibilidad, reescritor SQL,
sincronización de datos ni doble escritura. Las ramas de esquema existentes
permiten conservar la instalación anterior hasta el corte explícito de 3E;
las nuevas consultas y escrituras operan sobre las entidades definitivas.

## Mapa de consumidores y dependencias

| Área | Consumidores | Incompatibilidad a adaptar | Contrato conservado / cierre |
|---|---|---|---|
| Identidad y oferta | models/Producto.php; AdminMenuController | precio/categoría/área/activo ya no son columnas de productos | identidad compartida + oferta local; ID de oferta explícito, nunca nombre; 3D.1 |
| Carta pública, categorías y PDF | CategoriasMenu, CategoriaMenuService, Carta, MenuPdf, MenuController | categorías/cartas/áreas por unidad; lecturas p.precio/p.activo | tres cartas, orden, NULL y fallback; proyección de los 112 productos; 3D.1 |
| Recetas y subrecetas | AdminRecetasController, Subreceta | producto_componentes retirado; receta/version y subreceta por negocio | composición propia de oferta, sin referencias cruzadas; 3D.1 |
| Variantes | Menu/Variantes, editor existente | variante por oferta y ajustes con referencias explícitas | agregar/quitar/sustituir, selección y precio de servidor; 3D.1 |
| Inventario comercial | Inventory/Inventario y Almacenes | enlace por nombre y UPDATE ingredientes.stock | motor 3C, origen físico explícito y misma conexión; 3D.2 |
| POS y tickets | PuntoVentaController, Ticket, TicketItem, TicketMesa | unidades y ofertas obligatorias; snapshots e identidad antes inferida | apertura, mapa, comanda, cobro, cierre y cancelación; 3D.2 |
| Consumo de recetas | enviarComanda (antes línea 732) | descuento silencioso no transaccional | consumir al enviar comanda; no al abrir, cargar carrito o cobrar; 3D.2 |
| Cancelación | cancelarItem (antes línea 821) | reposición independiente tras cambiar estado | compensar movimientos originales una vez, sin recalcular receta; 3D.2 |
| Reservas y mapa | Reservacion, Mesa, Reservations/*, PosReservacionQueryService | raíces/relaciones por unidad; filtros y escrituras incompletos | horarios, asignación, versión, tolerancia, no-show y tokens vigentes; 3D.3 |
| KDS | AreaController y AdminAreaController | IDs estáticos de área; lectura/mutación solo por item_id | resolver slug local y mantener transiciones de estado; 3D.3 |
| Impresión | TicketPrinter, Impresora, ImpresionAlertaService | destinos y configuración sin unidad | comanda posterior al commit; alertas y reintento locales; 3D.3 |
| Finanzas y tickets administrativos | AdminFinanzasController, GastoFijo, reportes | campos de stock/precio/componentes retirados, joins sin negocio | ingresos/gastos/pagos propios, costo histórico y sin duplicaciones; 3D.3 |
| Analíticas y herramientas | Analiticas, ConsultasRapidas, Sugerencias, AdminConsultas/IA | ofertas por nombre, stock maestro, consultas sin alcance | reportes y datos enviados al proveedor solo del negocio; 3D.3 |
| Configuración y horarios | Configuration/*, modelos Configuracion*, Scheduling/* | singleton id=1 ahora PK unidad/id | lectura y escritura local; no alterar otro ámbito; 3D.3 |
| Notificaciones | Reservations/* y endpoints n8n | reserva/configuración/estado por unidad | conservar tokens, commit canónico y canales; prueba externa separada; 3D.3 |
| Integración | fixtures/runners, rutas/Auth, vistas actuales | fixture antiguo incompatible y rutas temporalmente cerradas | base aislada reconstruida y recorrido navegador; 3D.4 |

Las pantallas, SCSS y componentes actuales se reutilizan; no se diseñan interfaces
de Almacenes ni módulos Panadería. Se ajustan DTO/IDs y controles de contexto
solo donde cambie la propiedad real. Los servicios no aceptan alcance efectivo,
actor o derechos enviados por el cliente.

## Estado inicial de las suites

- PHP: reproduce run-reservaciones-cierre-static.php, aserción de compatibilidad
  de estados de entrega con cocina. Se investigará en 3D.3 sin quitar la aserción.
- JavaScript: contratos POS/mapa y notificaciones pasan hasta el verificador
  de documentación. Este rechaza el archivo rector nuevo docs/contrato.md
  porque su lista de fuentes permitidas es anterior. Es un fallo inicial,
  distinto de una regresión de JavaScript operativo; se actualizará la lista
  de fuentes legítimas conservando el rechazo de fuentes no reconocidas.

## Evidencia requerida por subetapa

3D.1: oferta/receta Panadería sin afectar Restaurante, IDs cruzados denegados,
equivalencia completa del paquete preservado y PDF/carta existentes.
3D.2: venta/comanda/consumo y cancelación atómicos, negativos y concurrencia,
sin ventas Global ni ofertas de negocio ajeno. 3D.3: consumidores locales,
fallo de estados resuelto conforme al comportamiento real y pruebas de módulos.
3D.4: reconstrucción aislada, permisos HTTP, navegador y regresiones 3A/3B/3C.
No se certifican impresoras físicas o credenciales externas mediante simulación.
