# Auditoría inicial: retirada de Productos de la casa

Estado: investigación inicial; no es una implementación ni una migración aprobada para producción.

## Bases revisadas

- `origin/branch-leo`: dc198d2b42703b079c6c911865358994fd159aab (incluye M2).
- `origin/main`: c99b3a235a760ca3fa21609d8e481b5fe12c1b76.
- Ancestro común de esas referencias: baf089b4922a01165a4a2afec733183cdffffeb8.
- La rama local `branch-leo` estaba en 39b8c68fe6fff76e9640bca511c3edf3221714fc, 19 commits detrás de la referencia remota.

## Dependencias identificadas

- Administración e interfaz: `AdminProductosCasaController`, `AdminMenuController`, rutas en `public/index.php`, vistas `views/admin/menu/casa-*`, `global.php`, `views/admin/recetas/presentaciones-global.php` y `src/js/admin/menu/casa.js`.
- Restricciones y autorización: `ProductosCasa`, `Ofertas`, `CatalogoComercial`, `AdministracionMenu`, `PresentacionesOferta`, `RecetasOferta`, `Variantes`, `AdminArticulosController`, `AdminRecetasController` y servicios de permisos. El permiso `productos` también protege artículos y debe conservarse.
- Composición y consumo: `ComposicionComercial` selecciona explícitamente el modo casa; `RecetasCasa` materializa recetas operativas y valida orígenes y versiones. No basta con eliminar estas clases sin adaptar a sus consumidores.
- Histórico y compatibilidad: `producto_publicaciones`, `producto_estandares`, `oferta_personalizaciones`, `recetas_casa`, `receta_casa_versiones`, `receta_casa_componentes`, `receta_origen_casa`, `producto_cambios_locales` y `presentacion_estandares`; las migraciones históricas y pruebas M1.2/M2 también los referencian. Preservar tablas, claves, versiones y triggers.
- Reportes de `origin/main`: `AnalisisConsolidado` consulta `producto_estandares`; `ConsultasRapidasAmbito` ofrece la consulta `productos_casa` y accede a estándares y publicaciones. Adaptar las secciones específicas sin retirar analíticas completas.

## Riesgos de datos y secuencia pendiente

`productos_oferta` calcula nombre, descripción e imagen efectivos con reglas publicadas y personalizaciones, incluyendo el tratamiento de imagen JSON nula. Antes de sustituirla hay que materializar y comparar esos valores por oferta. Las ofertas comparten `productos`; editar esa identidad puede afectar a otro negocio. Separar identidades exige atender la clave foránea compuesta `(oferta_id, producto_id, unidad_id)` de partidas históricas y otras referencias: no se debe actualizar simplemente `ofertas_producto.producto_id` ni modificar tickets históricos. Una alternativa a evaluar es guardar los datos comerciales locales en la oferta, conservando ambas identidades históricas y cambiando las rutas de lectura y escritura de forma transaccional.

Antes de cualquier cambio de esquema: inventariar ofertas compartidas, valores efectivos, recetas, presentaciones, claves y referencias; diseñar respaldo, migración idempotente y comprobaciones antes/después en una base aislada. No se ha inspeccionado ni alterado ninguna base operativa en esta auditoría. No se ha implementado ni ejecutado una migración.

## Línea base ejecutada

- `npm run test:php`: falla en `run-services-paths.php`, aserción «La sesión local utiliza el destino del proyecto»; la suite se detuvo allí. Ocurrió antes de modificar código.
- `npm run test:js`: pasó en la rama inicial, incluidas las pruebas antiguas de interfaz de casa.
- `npm run test:articulos:m2`: pasó (resolución y pruebas de interfaz de artículos y capacidades).

Faltan las pruebas de base aislada, HTTP, integración y compilación. No se ha realizado la integración temporal ni se puede declarar listo para merge.
