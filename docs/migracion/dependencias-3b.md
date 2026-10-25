# Dependencias operativas tras el esquema de Etapa 3A

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


El nuevo DDL se valida exclusivamente en bases desechables. **No es compatible
con el código operativo actual por sí solo y no debe activarse en desarrollo.**
La base configurada permanece con su estructura anterior; ningún adaptador
temporal ni escritura doble se introduce para mezclar ambas versiones.

| Área | Consumidores identificados | Adaptación pendiente y prueba funcional |
| --- | --- | --- |
| Login y usuarios | classes/Auth.php, models/Usuario.php, services/Security/PermisosUsuario.php, services/Users/AccesosUsuario.php | Adaptados en 3B: permisos directos por membresía, unión contraseña/NIP/HMAC, delegación y último superadmin. Roles son metadata; sin plantillas. Solo validar en bases aisladas hasta adaptar los demás consumidores. |
| Carta y catálogo | models/Producto.php, CategoriasMenu.php, services/Menu/Carta.php, MenuPdf.php, controllers/AdminMenuController.php, MenuController.php | Unir identidad a oferta del negocio; categorías/cartas/áreas por unidad, filtros y orden explícito, foto propia NULL/fallback, generación PDF y temporada. No deducir producto por nombre |
| Recetas y variantes | controllers/AdminRecetasController.php, models/Subreceta.php, services/Menu/Variantes.php, JS de variantes | Receta versionada por oferta/unidad; referencias artículo/subreceta con FK, variantes específicas. Mantener agregar/quitar/sustituir y fórmulas actuales con fixtures nuevos |
| Inventario | models/Ingrediente.php, controllers/AdminInventarioController.php, services/Inventory/Inventario.php | Retirar todos los escritores/lectores de ingredientes.stock/stock_minimo, usar asociación por almacén; transacción común saldo/bitácora, idempotencia, negativos, entradas/ajustes/merma y cancelación en almacén original |
| Venta y ocupación | controllers/PuntoVentaController.php, PosReservacionController.php, models/Ticket.php, TicketItem.php, TicketMesa.php | Ticket unidad obligatoria, oferta y producto explícitos, snapshots de costo/receta, items/pagos/mesas por unidad. Restaurante conserva mapas, comandas y cobro; Panadería no se habilita operativamente en 3A |
| Reservas y mapa | services/Reservations/, services/Tables/, controllers de reservaciones | Raíces con unidad, enlaces mesa/reserva y ticket del mismo negocio. Conservar versión, bloqueos, intervalos, tolerancia/no-show, reubicación, integración POS y política vigente |
| KDS e impresión | controllers/AreaController.php, AdminAreaController.php, AdminPrintersController.php, classes/TicketPrinter.php, classes/Impresion/ | Área/impresora/comanda por unidad; datos de conexión configurables sin IP de ejemplo como seed operativo. Conservar estados enviado/en_preparacion/listo/entregado/cancelado y alertas/reintentos |
| Finanzas y analíticas | controllers/AdminFinanzasController.php, AdminConsultasController.php, services/Analytics/Analiticas.php, ConsultasRapidas.php, Sugerencias.php | Todas las agregaciones por negocio y consolidadas autorizadas; snapshot de costo real para hechos nuevos, pagos agregados antes del JOIN, gastos/operaciones con dueño. Sin consultas por nombre/stock maestro ni conciliación de historia descartada |
| Configuración y horarios | services/Configuration/, services/Scheduling/, models de configuraciones/horarios, controllers/AdminConfigurationController.php | Resolver registros por unidad, no singleton global ID=1 para módulos locales. Mantener versión/impactos y canales externos de Restaurante |
| Asistentes locales | controllers/AdminIaController.php, consultas de herramientas SQL, integraciones n8n consumidoras de catálogo/ventas | Parámetro de alcance validado en servidor/consultas, identidad de oferta en datos nuevos. No crear asistente global antes de validar locales |
| Fixtures y runners existentes | database/development.sql, database/fixtures/panaderia.php, scripts/seed-usuarios-prueba.php, scripts/tests/ de contexto/POS/reservas/notificaciones | Reemplazar supuestos mononegocio por datos nuevos compatibles. No cargar el antiguo development sobre el DDL nuevo ni borrar pruebas funcionales para conseguir éxito |

Los nombres se conservan como puntos concretos de auditoría, no como promesa de
que sus consultas ya sean compatibles. Las carpetas de servicios y controllers
operativos no se modifican en 3A. El importador aislado y su proyección comprueban
el catálogo persistido; no equivalen a certificar interfaz, login, POS o impresión.

## Puertas antes de activar la pareja código/esquema

1. Adaptar todos los consumidores y escrituras de la misma entidad; no permitir
   que un formulario antiguo siga guardando una segunda existencia o precio.
2. Verificar seguridad por identidad/ámbito/módulo/nivel/operación y pertenencia
   SQL. Los CHECK/FK no prueban facultades del actor autenticado.
3. Validar atomicidad real, carreras de venta/cancelación y transferencias, límites
   de compensación y transiciones. El modelo prepara referencias, no ejecuta
   esos procesos. Configuración negativos y consistencia saldo/bitácora son cosas
   distintas: una FK válida no garantiza un movimiento aplicado una sola vez.
4. Recorrer Restaurante completo con fixtures nuevos: login, reservas/mapa,
   mesas/comandas, KDS/impresión, menú/recetas/variantes, inventario/cobro y reportes.
5. Ejecutar importación sobre un destino vacío identificado y comparar proyección,
   imágenes y visuales administrativas/públicas; resolver fallos previos del POS
   con su contrato funcional, sin eliminarlos.

Incidencias de transferencias y devolución cobrada siguen pendientes funcionales;
no implementarlas por haber preparado columnas. La activación y la Etapa 3B no
forman parte de esta entrega.
