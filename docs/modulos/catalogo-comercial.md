# Catálogo comercial y Menú

## M1-A: auditoría y contrato previo

Base local y remota `branch-leo`: `404df872a387f59ee7a7a43d30d4f463e4d33259`.
Checkout limpio; rama de trabajo `codex/m1-catalogo-menu-multinegocio`.
No se modifican otros worktrees ni se publica la rama.

### Inventario y dependencias

| Archivos / componentes | Clasificación | Decisión y consumidores |
|---|---|---|
| `controllers/AdminMenuController.php`, `views/admin/menu/{index,form,categories,category-form}.php` | Adaptación multinegocio | Un solo recorrido contextual; sustituir destinos fijos, conservar diseño |
| `controllers/AdminContextosController.php`, `services/Security/{Contextos,ContextoNegocio,Permisos,PermisosUsuario}.php`, sidebar del layout admin | Compartido / adaptación | Autorizar rutas y habilitar solo superficies completas |
| `models/Producto.php`, `models/CategoriasMenu.php` | Compartido y legado con consumidores | Fachadas con implementación multinegocio; conservar API para POS, recetas y pruebas históricas |
| `services/Menu/Ofertas.php` | Compartido / adaptación | Asociación explícita, bloqueo de identidad y unicidad producto/unidad |
| `services/Menu/{CategoriaMenuService,FotoPlatillo}.php`, `classes/ImagenUploader.php` | Compartido | Propiedad local, compensación, pendientes y protección de referencias |
| `services/Menu/{Carta,MenuPdf,CataService}.php`, `controllers/MenuController.php`, `views/admin/menu/items-pdf.php` | Exclusivo Restaurante en M1 | Conservar carta pública, especiales, PDF y maridaje |
| `services/Menu/{Variantes,RecetasOferta}.php`, `views/admin/menu/variantes.php`, `controllers/AdminRecetasController.php` | Legado con consumidores / fuera de M1 para Panadería | Oferta como identidad operativa; no habilitar M2 |
| `controllers/PanaderiaController.php`, `views/panaderia/` | Fuera de M1 | Andamio público sin pedidos ni pagos |
| `src/scss/admin/modules/menu.scss`, parciales de menú, `src/js/admin/core/{reactive-filters,foto-platillo,historial-precios}.js`, `src/js/admin/menu/variantes.js` | Compartido / consumidores vigentes | Reutilizar filtros, foto, tabla, botones, alertas y modales; no duplicar bundles |
| POS, KDS, tickets, snapshots, Analytics, Asistente | Consumidores vigentes / fuera de M1 | Mantener IDs de oferta, precio local y snapshots; ninguna escritura de stock nueva |
| `database/{ddl,deploy,menu-restaurante,development}.sql` | Compartido / preservación | FK y unicidad ya presentes; añadir solo facultad comercial sin concesiones implícitas |
| Redirecciones `/admin/menu/items*`, `/admin/menu/categories*`, `/admin/productos*` | Legado con consumidores | Mantener compatibilidad; no hay eliminación demostrada en la auditoría |

No se ha identificado código obsoleto sin consumidores con evidencia suficiente
para eliminarlo. La antigüedad de las fachadas no acredita obsolescencia.

### Propiedad de datos

`productos`: ID permanente, nombre, descripción e imagen compartidos. No fusionar
por nombre. `ofertas_producto`: negocio vendedor, categoría (que determina carta),
precio DECIMAL, área, activo y orden. Una identidad admite una oferta por negocio;
Global nunca es vendedor. Categorías y áreas pertenecen a la misma unidad que la
oferta. Las cartas conservan claves `comida`, `maridaje`, `temporada` y sus FK;
Panadería adapta etiquetas, sin copiar categorías ni ofertas de Restaurante.
Recetas y variantes pertenecen a ofertas; existencias a almacenes físicos.
Retirar significa desactivar la oferta, nunca borrar identidad ni historial.

### Rutas y autorización

| Superficie | Lectura | Escritura |
|---|---|---|
| `/admin/menu` y destinos históricos | `menu/ver` Restaurante | `menu/editar` Restaurante; operaciones actuales |
| `/admin/panaderia/menu` y `create`, `edit`, `toggle`, `delete`, `categorias/*`, `asociar` | `menu/ver` Panadería | `menu/editar` Panadería; sin POS, PDF público, variantes ni editor de recetas |
| `/admin/global/productos` y `edit` | `menu/ver` Global; uso comercial solo en negocios con `menu/ver` | `menu/editar` Global + `comercial.identidad.editar`; solo atributos maestros |
| `/admin/global/catalogo` | Contrato vigente Almacenes | Sin cambios |

La nueva facultad `comercial.identidad.editar` es exclusiva de Global y separada
de `catalogo.editar`, que continúa siendo inventariable. No migrar asignaciones
existentes automáticamente. Una identidad con más de una oferta exige dicha
facultad y consentimiento explícito de impacto compartido, incluso desde una
edición local. Sin facultad, el formulario conserva identidad y permite editar
condiciones locales. El servidor relee membresías, permisos y facultades; las
mutaciones vuelven a comprobarlos con bloqueo dentro de la transacción.

Asociar permite consultar identidades maestras para elegir un ID permanente,
sin revelar precios/categorías de otros negocios. No clona ni crea oferta ajena.
La ruta efectiva establece el contexto; campos del navegador no cambian unidad,
ID de oferta ni ID maestro. CSRF y listas permitidas protegen las mutaciones.

### Cambios mínimos, riesgos y aceptación inicial

Cambios: contextualizar rutas/vistas; exponer asociación existente; agregar
consulta/edición maestra autorizada; mantener exactitud decimal, validación y
bloqueos; proteger sincronización contra IDs manipulados. El DDL comercial no
requiere cambios. Nueva facultad estructural necesita DML en instalaciones
existentes, mediante despliegue revisado, nunca sobre la base activa en pruebas.

Riesgos detectados: sincronización genérica admite IDs; consentimiento ausente
para identidad compartida; precio convertido a float; rutas Restaurante fijas;
acciones de recetas/variantes no disponibles en Panadería; imágenes pendientes
de alta comparten ID cero entre contextos. Se resolverán antes de habilitar Menú.

Aceptación: alta nueva en ambos negocios, asociación sin duplicación, independencia
de precio/estado/categoría/área, rechazo Global vendedor e IDs ajenos, solo lectura,
revocación, CSRF, confirmación de identidad, archivos referenciados, unicidad
concurrente, rollback, retiro con historial, menú original y consumidores
Restaurante. Ejecutar suites generales, 3E/3B/3C/M0.5 y recorridos HTTP y navegador
en base desechable. No certificar UI por HTTP 200 ni paquetes privados ausentes.

No hay incompatibilidad que requiera cambiar el modelo comercial establecido.
M2, M3, Finanzas, Analíticas, Asistente e integraciones quedan fuera del alcance.
