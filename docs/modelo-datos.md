# Modelo de datos: auditoría estática D2

[Arquitectura](arquitectura.md) es la norma; este inventario describe el árbol
versionado, no una base consultada ni autorización de cambios. Inspección de
`database/ddl.sql`, `database/deploy.sql`, las cinco migraciones en
`database/actualizaciones/` y consumidores PHP. No se ejecutó SQL. Las 83 tablas
del DDL aparecen una sola vez en la matriz; A1 añade una tabla adicional.

## Inventario y alcance

En `database/ddl.sql`: **83 CREATE TABLE, una vista, 53 CREATE TRIGGER y 165
cláusulas FOREIGN KEY**. Hay **191 declaraciones explícitas de índices**: 83
PRIMARY KEY, 64 UNIQUE KEY nombradas, 6 UNIQUE de columna y 38 KEY/INDEX no
únicos. Se cuentan declaraciones, no índices físicos: MySQL puede crear índices
implícitos para FKs o reutilizar índices; no se infiere el estado de una instancia.
Las declaraciones en migraciones no se suman a las del DDL porque reproducen o
modifican estructuras; no se ha verificado una instalación real.

El DDL deja `productos_oferta` con la proyección histórica M1.2. A1 crea
`oferta_datos_comerciales` (PK `oferta_id` y FK a oferta) y A3 sustituye la
vista por la proyección local. El instalador `Services\Development\Instalacion::ejecutar`
carga DDL, deploy, menú, fixture opcional, A1 y A3 en ese orden; después hace
bootstrap. Por declaraciones, el esquema nuevo posterior a A1 incluye 84 tablas
de aplicación y la misma vista reemplazada; el instalador crea temporalmente
`_cp_instalacion_owner` y la retira al completar. No es un conteo observado en BD.
`deploy.sql` siembra estructura y marcadores `multinegocio-3a-v1`/`3b-v1`;
`esquema_versiones` registra las versiones, no demuestra por sí sola integridad.

Las migraciones son `m1-facultad-comercial.sql` (facultad histórica),
`m12-estandares.sql` (publicaciones, recetas históricas y triggers),
`m2-articulos.sql` (artículos, presentaciones y triggers),
`a1-datos-comerciales-oferta.sql` (datos locales) y
`a3-datos-comerciales-local.sql` (validación y vista). Sus instrucciones y
marcadores se consultan en los archivos; el orden de actualización y preflight en
[instalación](desarrollo/instalacion.md#actualizacion-comercial-a1a3).

## Matriz de dependencias

Cada lista representa tablas declaradas en `ddl.sql`. «Historia» y
«compatibilidad» no significan que carezcan de lectores ni que puedan borrarse.
La referencia al DDL es el `CREATE TABLE` del nombre indicado; las referencias a
clases son puntos de entrada para investigar, no una enumeración exhaustiva de
consumidores. Global no vende; operaciones locales exigen contexto propietario.

| Grupo y tablas | Clasificación | Propiedad, consumidores, integridad y riesgo |
|---|---|---|
| Identidad y permisos: `unidades`, `roles`, `usuarios`, `facultades`, `rol_permisos`, `usuario_unidades`, `usuario_unidad_facultades`, `usuario_unidad_permisos` | Núcleo / compatibilidad | Global y membresías por unidad; `services/Security/ContextoNegocio.php`, `PermisosUsuario.php`, `services/Users/` leen/escriben permisos directos; En modo multinegocio los permisos efectivos son directos; `RolService::guardar` escribe `rol_permisos` y `Rol::permisos`/`::catalogo` lo leen en compatibilidad anterior. FKs de membresía y triggers `trg_membresia_*`, `trg_permiso_*`, `trg_facultad_*`. Alto: preservar autorización. |
| Catálogo y ofertas: `areas_produccion`, `cartas`, `categorias`, `productos`, `ofertas_producto` | Núcleo | Negocio dueño de oferta/categoría/área; `services/Menu/AdministracionMenu.php`, `Ofertas.php` y `MenuPdf.php`; FKs compuestas por unidad y unicidad producto/unidad. Alto: identidad y aislamiento. |
| Maestro e inventario: `ingredientes`, `almacenes`, `almacen_articulos`, `operaciones_inventario`, `movimientos_inventario`, `oferta_consumos` | Núcleo / historia | Maestro compartido, saldo por almacén propietario; `services/Inventory/Almacenes.php`, `AlmacenesConsumo.php`, `ConsumoOferta.php` leen y registran saldo, operaciones y ledger. FKs y `trg_almacen_*`, `trg_movimientos_inmutables_*`. Alto: no reconstruir ni borrar movimientos. |
| Transferencias: `transferencias`, `transferencia_lineas`, `transferencia_historial`, `transferencia_comandos` | Núcleo / historia | Origen y destino autorizados; `services/Inventory/Transferencias.php` registra documentos, comandos y tránsito; FKs y `trg_transferencia_historial_*` preservan trazabilidad. Alto: no confundir tránsito con saldo destino. |
| Recetas y variantes anteriores: `subrecetas`, `subreceta_ingredientes`, `recetas`, `receta_componentes`, `producto_variantes`, `variante_ajustes`, `receta_componentes_articulos` | Núcleo / compatibilidad | Oferta o unidad local; `services/Menu/RecetasOferta.php::guardar`, `Variantes.php`, `services/Inventory/Articulos.php` leen/escriben y adaptan referencias; FKs a recetas y artículos, triggers `m2_sub_*`, `m2_si_*`, `m2_componente_adoptado_*`. Alto: versiones usadas en tickets. |
| Artículos y presentaciones M2: `articulo_configuraciones`, `articulo_recetas`, `articulo_receta_componentes`, `oferta_presentaciones`, `presentacion_versiones`, `presentacion_componentes`, `articulo_correspondencias`, `articulo_comandos` | Núcleo / compatibilidad | Unidad o Global según configuración; `services/Inventory/Articulos.php`, `ResolucionArticulos.php`, `services/Menu/PresentacionesOferta.php::publicar` y `::paraVenta`. FKs de versiones, triggers `m2_ar_*`, `m2_arc_*`, `m2_pv_*`, `m2_pc_*`. Alto: versiones e idempotencia. |
| Ventas y mesas: `mesas`, `tickets`, `pos_operaciones`, `ticket_items`, `ticket_pagos`, `ticket_mesas`, `ticket_item_resoluciones` | Núcleo / historia | Unidad vendedora; `services/Pos/Comercial.php::enviarComanda`, `::cancelarItem` y `services/Menu/PresentacionesOferta.php::congelar` registran venta, resolución y compensación; FKs de ticket/receta, `m2_snapshot_*`. Alto: snapshots, precios, pagos e idempotencia. |
| Finanzas y proveedores: `operaciones_financieras`, `gastos_fijos`, `proveedores`, `ingrediente_proveedores`, `historial_precios` | Núcleo / historia | Finanzas por negocio; proveedores y artículo según relaciones DDL; `services/Finance/FinanzasNegocio.php::agregados`, `::gastosFijos`, `models/HistorialPrecio.php`, `controllers/AdminInventarioController.php`; FKs y referencias históricas. Alto: no recalcular costos anteriores. |
| Reservas y agenda: `reservaciones`, `verificaciones_contacto`, `reservacion_mesas`, `reservacion_reubicaciones`, `horarios_operacion`, `excepciones_operacion`, `horario_impactos`, `horario_impacto_reservaciones`, `reservacion_recordatorios`, `buzon_notificaciones` | Núcleo / historia | Reservas y agenda por unidad; `services/Reservations/ReservacionMantenimientoService.php`, `services/Scheduling/HorarioOperacionImpactoService.php`, `services/Notifications/ReservationReminderService.php::preparar` y `::reclamar`; FKs y `trg_reservaciones_no_auto_reemplazo_*`. Alto: evitar duplicación, pérdida de estados y datos personales. |
| Comunicación y operación: `catas`, `feedback_tokens`, `feedback`, `impresoras`, `impresion_alertas` | Núcleo / historia | Contexto según cada tabla; `services/Menu/CataService.php`, `controllers/FeedbackController.php`, `services/Pos/ImpresionAlertaService.php`; FKs y claves de acceso/estado en DDL. Medio/alto: privacidad y trazabilidad operativa. |
| Configuración y diagnóstico: `configuracion_anuncio`, `configuracion_pos`, `configuracion_paleta`, `configuracion_reservaciones`, `reportes_sistema` | Núcleo | Configuración por unidad donde corresponde; `services/Configuration/SitioConfig.php`, `models/ConfiguracionPaleta.php`, `services/Configuration/ReporteSistemaService.php`. FKs por unidad en DDL. Medio: conservar configuración y diagnóstico. |
| Instalación e importación: `importaciones_menu`, `esquema_versiones`, `instalacion_desarrollo` | Compatibilidad / trazabilidad | Registro técnico; `scripts/menu/ImportadorMenu.php`, `services/Development/Instalacion.php::ejecutar`, `database/deploy.sql` y migraciones. Marcadores controlan activación y procedencia. Medio/alto: no retirar por falta de uso en flujo de venta. |
| Productos de casa retirados: `producto_publicaciones`, `producto_estandares`, `oferta_personalizaciones`, `recetas_casa`, `receta_casa_versiones`, `receta_casa_componentes`, `receta_origen_casa`, `producto_cambios_locales`, `receta_casa_composiciones`, `presentacion_estandares` | Historia / compatibilidad | Sin publicación comercial Global operativa A4; FKs y `trg_casa_*`, `m2_casa_composicion_*`, `m2_presentacion_estandar_*` protegen historia. `classes/ImagenUploader.php::eliminar` consulta estándares, publicaciones, personalizaciones y cambios antes de borrar imágenes. Alto: preservación; no proponer eliminación física. |

**Tabla adicional A1:** `oferta_datos_comerciales` es núcleo comercial local:
`services/Menu/DatosComercialesOferta.php` y `services/Menu/AdministracionMenu.php`
validan/gestionan datos; la vista A3 y `classes/ImagenUploader.php::eliminar` los
leen. Una fila por oferta conserva nombre, descripción e imagen; su FK restringe
borrado. Riesgo alto para preservación y aislamiento. La vista `productos_oferta`
es interfaz de lectura compatible, no segunda autoridad de escritura.

## Hallazgos y orden de investigación

1. **Confirmado, alto:** DDL solo conserva una vista histórica; runtime A4
   requiere A3. Comparar marcadores y definición efectiva en una base aislada y
   respaldada antes de diagnosticar divergencias; nunca aplicar DDL de reset a
   una base existente (`a3-datos-comerciales-local.sql`, `DatosComercialesOferta.php`).
2. **Confirmado, alto:** los objetos históricos comerciales también tienen FKs,
   triggers y referencias de imágenes; «sin publicación activa» no equivale a
   «sin dependencias». Investigar relaciones y restauración antes de cualquier
   propuesta futura; aquí no se elimina ni modifica nada.
3. **Alto, compatibilidad de autorización:** `rol_permisos` tiene escritores y
   lectores: `services/Security/RolService.php::guardar` reemplaza filas mediante
   DELETE/INSERT; `models/Rol.php::permisos` y `::catalogo` las consultan;
   `classes/Auth.php::rolActual` usa la resolución de rol en el modo anterior.
   `public/index.php` conserva las rutas `/admin/roles`; en multinegocio,
   `Permisos::clasificarDirecto` las clasifica `solo_sistema` con módulos vacíos
   y `Auth::protegerDirecto` deniega el acceso. La autorización efectiva de ese
   modo usa permisos directos, pero la compatibilidad anterior sigue en código.
   Auditar consumidores y datos antes de cualquier propuesta; no se ha demostrado
   que la estructura sea eliminable.
4. **Bajo, hipótesis de índices:** contrastar las consultas y planes de tickets,
   movimientos y reservas con `idx_ticket_estado`, `idx_ticket_cierre`,
   `idx_mov_unidad_fecha`, `idx_mov_almacen_fecha` e
   `idx_reservaciones_fecha_estado_hora` (`ddl.sql`). No se ha identificado un
   índice redundante ni probado una mejora. Usar `SHOW INDEX` y planes SELECT en
   BD aislada, cardinalidad y coste de escritura antes de proponer algo.
5. **Uso no demostrado:** esta revisión no identifica ninguna tabla completa como
   sin uso; búsquedas parciales no prueban ausencia de consumidores dinámicos,
   migraciones, datos ni integraciones. Completar trazado por tabla y contratos,
   con inspección solo lectura de FKs/triggers y datos autorizados, antes de
   clasificar una candidata. No borrar tablas, índices ni adaptadores por intuición.

Las pruebas de aceptación y límites están en [pruebas](desarrollo/pruebas.md).
No se han medido rendimiento ni cardinalidad, ni comparado con la BD operativa.
