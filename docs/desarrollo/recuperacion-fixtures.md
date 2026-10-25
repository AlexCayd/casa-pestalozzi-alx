# Recuperación integral de fixtures de desarrollo

**Estado:** recuperación cerrada para la arquitectura integrada en `92dd80a9fcde55ca8ad3080e8f58d99d700bd04c` y la rama `codex/recuperacion-fixtures-desarrollo`. Los seis bloques de fixtures del commit histórico `140f05efcf2c0359807c51e260339aabab569c15` se contrastaron con el esquema actual y se reconstruyeron sobre entidades canónicas. Los INSERT antiguos no se importan literalmente.

La instalación reproducible usa `database/ddl.sql`, `database/deploy.sql`, `database/menu-restaurante.sql` y `database/development.sql`, en ese orden mediante `scripts/desarrollo/instalar.php`. El paquete privado de Etapa 2 fue una referencia de integridad; no es necesario para instalar ni probar el menú versionado. No se alteró ninguno de los 112 productos preservados.

## Matriz de los seis bloques históricos

| Bloque y fuente anterior | Intención y modelo anterior | Representación y resultado actual |
|---|---|---|
| 1. Datos base (`database/dml_pruebas.sql`; bloque 1) | Cuentas/permisos, mapa, catálogo, reservas, tickets, KDS, pagos, existencias, proveedores, finanzas, feedback y catas. El esquema antiguo mezclaba `ingredientes.stock` y componentes de producto. | IDs multinegocio en `usuarios` y permisos directos; las 16 superficies originales de Restaurante; hechos operativos en reservas/tickets/comandas/pagos; almacenes, asociaciones y movimientos trazables; proveedores, precios y eventos. Las cinco referencias de impresora son `127.0.0.1` e inactivas. Los saldos de los tres almacenes coinciden con su ledger. |
| 2. Analíticas (`analiticas-datos-ex.sql`; bloque 2) | Hechos agregados `a5_*` para ventas y comparaciones históricas. | No se recrearon tablas agregadas paralelas. Las consultas usan `tickets`, snapshots de `ticket_items`, `ticket_mesas`, `ticket_pagos`, feedback y finanzas. El DML mantiene los 365 tickets analíticos y 803 líneas previstos, con doce meses, siete días, varias horas, pagos divididos y cancelaciones. |
| 3. RevPASH (`REVPash-pruebas.sql`; bloque 3) | Ocupación, duración, capacidad, tarifa y franjas a partir de auxiliares `a5_*`. | 26 tickets cerrados cubren los siete días recientes, dentro del horario configurado. Usan las 11 mesas reservables del mapa (44 asientos), respetan capacidades y no se solapan por mesa. El servicio del cuarto día de la ventana a las 13:00 representa un grupo de seis personas: la reservación y el ticket comparten exactamente dos mesas, con capacidad conjunta de ocho. Se conserva la diferencia de actividad entre lunes y sábado. |
| 4. Variantes (bloque 4) | Variantes sobre `producto_componentes`, recetas y costos del esquema anterior. | El modelo canónico tiene 3 recetas, 1 subreceta, 9 variantes, 4 ajustes (agregar, quitar y sustituir) y 7 consumos de oferta. Las líneas vendidas mantienen snapshots; un costo desconocido permanece `NULL`, sin recalcularse desde recetas actuales. |
| 5. Especiales de temporada (bloque 5) | Cinco productos estacionales con estado, imagen, categoría y orden. | Los cinco productos originales (IDs 300–304) ya forman parte del menú preservado de 112 productos en `menu-restaurante.sql`: tres tienen imagen propia y dos usan el fallback de categoría. El fixture añade únicamente dos ofertas sintéticas para probar estado activo/inactivo; no duplica ni sustituye los originales. Las 13 imágenes preservadas siguen versionadas. |
| 6. Uso del Asistente (bloque 6) | Hechos recientes de ventas, consumo y consultas locales. | El desarrollo contiene 3,074 tickets sintéticos en un periodo móvil de 91 fechas observadas, con ritmo diario desigual, 2–6 líneas, pagos y cancelaciones. Hay ocho ofertas activas sin ventas para probar esa consulta. El Asistente es un intérprete estructurado local, no un LLM ni un proveedor externo. Sus 12 intenciones actuales son ventas total/producto/categoría, top, ticket promedio, sin venta, meseros, horas pico, cancelaciones, stock, consumo y bajo stock. No ofrece consultas de rentabilidad/costo, mesas, coocurrencias ni finanzas desde el Asistente; esas capacidades no se presentan como implementadas. |

## Resultado reproducible

Las dos instalaciones independientes sobre bases vacías produjeron los mismos conteos. El runner `scripts/tests/run-instalacion-3e.php` validó **885 comprobaciones acumuladas** entre ambas bases y eliminó cada base temporal solo después de verificar el token de propiedad.

| Grupo | Resultado por instalación |
|---|---|
| Menú preservado | 112 productos, 112 ofertas, 15 categorías, 3 cartas, 4 áreas, 13 imágenes |
| Datos comerciales de desarrollo | 125 productos/ofertas totales (112 preservados + 13 de desarrollo), 17 categorías, 6 cartas, 5 áreas; la oferta de Panadería es propia y sintética |
| Usuarios | 8 cuentas con `admin_demo` provisionado en desarrollo, 6 membresías, 17 permisos directos y 8 facultades |
| Mapa | 16 superficies, 11 mesas reservables, 44 asientos |
| Almacenes | 3 almacenes, 5 artículos maestros, 11 asociaciones, 10 operaciones y 24 movimientos; todos los saldos cuadran con el ledger |
| Recetas y variantes | 3 recetas, 1 subreceta, 9 variantes, 4 ajustes y 7 consumos de oferta |
| Reservaciones | 19 reservaciones, 21 relaciones reserva-mesa y cobertura de ocho estados; tres reservas futuras agrupan 2, 3 y 4 mesas |
| Operación de Restaurante | 3,469 tickets (3 abiertos, 3,466 cerrados), 13,154 líneas, 3,470 relaciones ticket-mesa, 3,776 pagos y 556 tickets divididos |
| Analítica | 365 tickets anuales y 803 líneas; 12 meses, 7 días de semana; 3,074 tickets del Asistente en 91 fechas observadas |

Los tickets de desarrollo tienen unidad vendedora Restaurante. Global no vende. La distribución de Panadería es independiente. Los ocho casos sin venta no reciben líneas de ticket. Las líneas canceladas, pagos, consumos y costos conservan la semántica del contrato.

## Instalación, credenciales y aislamiento

La guía única es [instalación](instalacion.md). La cuenta `admin_demo` se crea con el bootstrap CLI de desarrollo, mediante `DEV_SUPERADMIN_PASSWORD` configurado localmente; `deploy.sql` no contiene la contraseña en claro ni activa por sí solo esa cuenta. El bootstrap no está disponible en producción. Las decisiones rectoras y la [arquitectura vigente](../arquitectura.md) describen los límites funcionales.

Los ensayos usaron `run-instalacion-3e.php` con dos esquemas temporales aleatorios sobre MySQL local. La herramienta no se conectó a la base persistente seleccionada como destino de escrituras y no ejecutó `DROP` sobre ella. La contraseña se entregó mediante variable de entorno temporal del proceso y se quitó al finalizar; no se guardó en archivos versionados. No hubo acceso a SMTP, impresoras físicas ni servicios externos.

## Validación y límites de evidencia

Pasaron en esta rama:

- Dos instalaciones limpias independientes: 885 comprobaciones combinadas, conteos equivalentes y menú preservado íntegro.
- Suites PHP, JavaScript y notificaciones; pruebas de preservación (34 assertions y siete casos sobre base aislada), multinegocio 3B (128), Almacenes 3C (158), concurrencia 3C (17), vista de inventario (10) y roles/permisos (79).
- Pruebas HTTP 3E (106), incluyendo usuarios, revocación, tres ámbitos, almacenes, movimientos, negativos y protección contra IDs de otra unidad.
- Humo de navegador local: carga pública del sitio, autenticación de `admin_demo` y apertura de Usuarios en contexto Global. No se hizo una exploración visual completa de cada acción de inventario, POS o KDS; estas rutas tienen cobertura automatizada, pero la inspección visual queda limitada a ese flujo.

Los runners históricos de esquema 3A y Restaurante 3D que exigen `--paquete` no se pudieron repetir porque el paquete privado de Etapa 2 no está disponible en este entorno. No se fabricó un sustituto ni se declararon esos runners como aprobados. El menú actual sí se verificó desde archivos versionados, sin depender del paquete privado. La suite de consultas rápidas prueba el contrato local existente; no valida proveedores externos ni nuevas consultas fuera de sus 12 intenciones.

Este archivo registra la recuperación post-3E y es documentación de desarrollo vigente. Las decisiones de arquitectura se rigen por [docs/arquitectura.md](../arquitectura.md); esta guía no reabre decisiones de 3A–3E.
