# Casa Pestalozzi — contrato rector

Revisión 3E · 25 de octubre de 2026. Esta es la fuente principal de reglas de
arquitectura y desarrollo. Describe la implementación de la rama candidata;
la aceptación y el merge son decisiones posteriores. Los resultados verificables
se registran en [la entrega 3E](desarrollo/entrega-3e.md).

## 1. Objetivo, alcance y precedencia

Casa Pestalozzi es una aplicación PHP MVC/MySQL con Global, Restaurante y
Panadería. Mantiene los flujos operativos de Restaurante y habilita Usuarios y
Almacenes en los tres ámbitos. No exige terminar los módulos comerciales de
Panadería ni los reportes organizacionales de Global.

Leer primero este contrato y [CLAUDE.md](../CLAUDE.md), después el contrato del
módulo. El código, DDL, DML y pruebas de la revisión deben concordar. Registrar y
resolver contradicciones antes de cambiar servicios compartidos. Los documentos
marcados como históricos son evidencia, no instrucciones de activación vigentes.
No convertir una tabla, maqueta o entrada de navegación en prueba de funcionalidad.

## 2. Estado de módulos

| Módulo | Global | Restaurante | Panadería |
|---|---|---|---|
| Usuarios | Implementado, interfaz compartida | Implementado | Implementado, interfaz compartida |
| Almacenes | Maestro, físicos propios y consolidado | Físicos propios y operaciones | Físicos propios y operaciones |
| Menú y ofertas | Reglas de identidad compartida | Operativo; menú original preservado | Modelo preparado; UI pendiente |
| Recetas y variantes | Contrato compartido | Operativo, recetas versionadas | Servicios/modelo preparados; UI pendiente |
| POS, mesas y KDS | No vendedor | Operativos | POS sin mesas pendiente |
| Tickets y pagos | Consulta consolidada definida, pendiente | Operativos | Modelo preparado; UI pendiente |
| Finanzas y analíticas | Consolidación definida, pendiente | Operativas | Modelo preparado; UI pendiente |
| Asistente IA | Organizacional pendiente | Integración existente conservada | Pendiente |
| Transferencias | Contrato y esquema; flujo pendiente | Pendiente | Pendiente |
| Producción y catering | Fuera de 3E | Fuera de 3E | Fuera de 3E |

Implementado significa backend, persistencia y superficie administrativa; la
cobertura y sus límites se declaran en las pruebas y el acta de entrega. Preparado
significa contratos o componentes sin flujo completo liberado. Los módulos futuros
no tienen endpoints de escritura parcial ni pantallas que simulen cobros reales.

## 3. Ámbitos y propiedad

`unidades.slug` identifica los tres ámbitos; no inferir propiedad mediante IDs
numéricos fijos, nombres o el selector visual. Global administra y puede poseer
almacenes físicos, pero nunca es vendedor ni propietario de tickets comerciales.
Restaurante y Panadería son negocios vendedores distintos.

La ruta selecciona contexto; el servidor carga identidad, membresías y permisos.
Aplicar el alcance también a detalles, exportaciones, filtros, PDFs, consultas e
integraciones. Una lectura consolidada no concede escritura sobre recursos ajenos.

## 4. Autenticación, autorización y Usuarios

Una identidad puede tener membresías combinables de los tres ámbitos. La fuente
operativa es `usuario_unidad_permisos`: panel `ver`/`editar`, piso `operar`.
Ausencia de permiso deniega; editar incluye ver. Roles permanecen como metadatos
internos y no agregan derechos. No hay plantillas de permisos.

Reutilizar `Auth`, `PermisosUsuario`, `Contextos`, `AccesosUsuario` y
`UsuarioService`. `usuarios.es_superadmin` es la excepción organizacional
explícita; el CRUD ordinario no admite promoverla mediante campos del cliente.
Las rutas privadas desconocidas deniegan acceso. Las APIs públicas se clasifican
expresamente y conservan sus verificaciones de tokens o secretos.

Si existe cualquier módulo de panel en cualquier ámbito activo, la cuenta exige
contraseña. NIP solo cuando todos los accesos son POS, KDS o mapa operativo de
reservaciones de Restaurante. Pasar de piso a panel exige contraseña y nueva
autenticación. Una sesión NIP nunca hereda privilegios de panel al cambiar permisos.

Cada petición protegida revalida el perfil. Las escrituras sensibles bloquean las
filas de autorización; una revocación se serializa con operaciones ya autorizadas
y deniega las siguientes. Mantener HMAC de NIP, hashes seguros, CSRF, limitación
de intentos, no-store para credenciales efímeras y protección del último superadmin.

Gestión requiere `users` y `usuarios.gestionar`; asignar o revocar derechos exige
`permisos.delegar`. Global administra ámbitos delegados con un techo por módulo y
facultad. Un administrador local solo gestiona su ámbito. No puede cambiar la
identidad, credenciales o estado global de una cuenta compartida ni revocar
membresías ajenas. La UI ofrece el mismo techo que aplica el servicio.

Referencia técnica: [autorización](migracion/contrato-autorizacion-3b.md).

## 5. Cuenta de desarrollo

La cuenta definida es `admin_demo`, activa, superadministradora organizacional y
con membresías Global, Restaurante y Panadería. Solo inicia por contraseña.
La contraseña acordada se aporta mediante `DEV_SUPERADMIN_PASSWORD` en la
configuración local ignorada; no se escribe en SQL, código, documentación
versionada ni logs. El bootstrap produce `password_hash` con el algoritmo seguro
del runtime y no crea la cuenta desde rutas públicas.

`Instalacion` crea una base nueva e identifica su entorno y propietario en
`instalacion_desarrollo`. `BootstrapDesarrollo` solo funciona por CLI en
`development` o `test` identificados y sobre esa instalación. Reejecutar valida
la identidad propia sin duplicarla ni rehacer su hash. Una cuenta homónima sin
marca de propiedad no se promueve. Una contraseña cambiada no se restablece
implícitamente; el comando separado requiere `--restablecer` explícito.

El seed estructural no crea cuentas conocidas. El cambio previo de desarrollo que
sembraba automáticamente un usuario limitado de Restaurante fue revisado y
sustituido por este aprovisionamiento seguro, sin alterar el checkout original.
No activar la cuenta conocida automáticamente en producción ni usarla en servicios
externos. La [guía de instalación](desarrollo/instalacion.md) fija los comandos.

## 6. Almacenes e inventario

El catálogo maestro `ingredientes` es organizacional y distinto de productos
comerciales. Cada almacén es físico, tiene propietario único, estado y política
de negativos. El consolidado es una consulta, nunca un almacén virtual.

`almacen_articulos` es la única fuente de saldo físico por almacén/artículo; su
mínimo y versión pertenecen a la asociación. No mantener stock editable en el
maestro ni en tablas de totales. No sumar artículos de unidades incompatibles.
Cantidades y costos siguen precisiones DECIMAL del esquema; no usar float para
decidir disponibilidad o mutar existencias.

Reutilizar `Almacenes` y su bucle de movimientos 3C. El adaptador administrativo
solo traduce formularios; no duplica reglas de stock. Global autorizado con
`almacenes.crear` crea físicos y asigna propietario entre los tres ámbitos.
`catalogo.editar` permite administrar el maestro desde Global. Cada ámbito solo
opera físicamente sus almacenes propios. Cambiar propietario después de operar
requiere un flujo futuro explícito; no editar directamente el dueño.

Las entradas, conteos, mermas y compensaciones generan operaciones y movimientos
trazables. Saldos, bitácora y documento de negocio se confirman atómicamente.
Idempotencia, versiones y bloqueos ordenados evitan duplicaciones o actualizaciones
perdidas. Solo anunciar éxito después del commit exterior; un savepoint válido
continúa pendiente del coordinador.

Sin negativos autorizados ninguna salida deja saldo negativo. La política se
configura por almacén. Las alertas comparan saldo y mínimo de cada asociación
activa, incluyendo estado del maestro y del almacén; excedentes de otra asociación
no ocultan faltantes. Listados y desgloses respetan paginación y recursos ajenos.
No implementar transferencias, producción ni variantes avanzadas durante 3E.

Referencia técnica: [motor de Almacenes](migracion/contrato-almacenes-3c.md).

## 7. Identidad comercial, ofertas, recetas y variantes

`productos` guarda identidad descriptiva compartible. `ofertas_producto` define
negocio, precio, categoría, disponibilidad, orden y área propios. No duplicar
identidades solo por vender en dos negocios ni fusionar productos por nombre.
Cartas, categorías, áreas, ofertas y composición tienen propietario validado.

Recetas/versiones, subrecetas y variantes pertenecen al negocio/oferta. Los
ajustes de variantes identifican componentes explícitos. Leer composición no
concede acceso a existencias. No compartir recetas editables implícitamente.
El origen de consumo debe ser un almacén del vendedor por oferta/artículo, sin
fallback, búsqueda de stock ajeno ni reparto implícito.

Los 112 productos y ofertas originales de Restaurante están versionados y
verificados por campos y archivos. Las ofertas sintéticas de desarrollo son
identidades separadas y están identificadas como prueba. No generan ofertas de
Panadería a partir del menú real sin una decisión comercial explícita.

## 8. Tickets y operaciones comerciales

Todo ticket pertenece a un negocio vendedor. Global no vende. El modelo admite
el futuro POS sin mesa de Panadería; ese POS no está implementado en 3E.
Restaurante mantiene mesas, comandas, KDS, reservas, cobros y cancelaciones.

Los ítems conservan oferta, unidad y snapshots de nombre, precio, variantes,
receta y costo conocido. El servidor resuelve esos datos; no vende por nombre ni
acepta el precio del cliente como fuente. La comanda recibe clave idempotente y
consume al enviarse. Abrir mesa, armar carrito o cobrar no repite el consumo.

Cancelar compensa los movimientos originales una vez, con su almacén y costo,
sin recalcular la receta actual. La decisión permitida de vender sin consumo
también queda congelada. Una receta inventariable con origen incompleto falla
sin escrituras parciales. Impresión ocurre después del commit; replay no imprime
otra comanda. El cobro revalida estados e importe dentro de su transacción.

Referencia: [contratos comerciales](migracion/etapa-3d-contratos-comerciales.md).

## 9. Finanzas y consultas

Ingresos, gastos, pagos, tickets y movimientos conservan propietario. Los
reportes locales filtran raíces y relaciones; los consolidados futuros deberán
agregar sin multiplicar filas ni contabilizar transferencias como ventas o gastos.
Los costos históricos presentes se conservan y los desconocidos se identifican;
no sustituirlos por el costo de la receta actual. Consumo netea cancelaciones y
presenta merma separada. Las herramientas locales y datos para IA respetan el
mismo alcance que las pantallas del usuario.

## 10. Diseño compartido

Global y Panadería reutilizan la interfaz equivalente de Restaurante, incluyendo
layout, sidebar, topbar, tablas, filtros, formularios, botones, modales, iconos,
alertas y comportamiento responsive. El contexto permanece debajo del logotipo
y el ámbito activo es visible. Parametrizar datos y acciones, no mantener tres
interfaces ni servicios completos para el mismo concepto.

Las diferencias justificadas son de propiedad y permisos: Global tiene maestro,
creación y consolidado; los negocios tienen operaciones locales. Conservar el
diseño de Restaurante, los componentes de confirmación/avisos y el teclado/foco.
Editar fuentes y compilar assets; no modificar manualmente sus derivados.

## 11. DDL, DML, fixtures e instalación

La instalación única ejecuta `database/ddl.sql`, `database/deploy.sql`,
`database/menu-restaurante.sql` y, en desarrollo, `database/development.sql`.
El SQL de menú es la fuente de datos; el manifiesto de integridad contiene
correspondencias y hashes, no otro catálogo editable. Las 13 imágenes fuente
están en `src/images/menu-preservado/` y el build las publica sin recomprimir.
No depende del paquete privado de Etapa 2, la base anterior ni archivos externos.

Los fixtures son descartables e identificables; no recuperan historiales de
prueba antiguos ni alteran los productos preservados. Sus saldos corresponden a
movimientos registrados. Los usuarios de referencia inactivos no tienen una
contraseña conocida; la cuenta operativa de desarrollo se aprovisiona aparte.

El instalador solo crea una base inexistente y falla ante una existente; no
aplica DROP ni reconstrucción implícita. Identificar host, base y entorno y
conservar el entorno anterior para recuperación. No seleccionar ni destruir
una base ambigua. El bootstrap nunca es un efecto automático del seed de despliegue.

## 12. Desarrollo y pruebas obligatorias

Antes de modificar un módulo: identificar contexto, estado y revisión; revisar
servicios compartidos, permisos, relaciones y UI real de Restaurante. Definir
entradas, salidas, propiedad, invariantes, transacciones y errores antes de editar
contratos compartidos. No crear otra autenticación ni arquitectura MVC.

Implementar en entregas acotadas. Si se usan subagentes, asignar archivos distintos
y un contrato único. Mantener DDL/DML sincronizados, actualizar documentación y
probar aislamiento, revocación, IDs manipulados, concurrencia y errores parciales.
No eliminar aserciones funcionales para ocultar incompatibilidades.

La puerta de aceptación incluye preservación, esquema 3A, permisos 3B, Almacenes
3C, comercial/Restaurante 3D, Usuarios/Almacenes 3E, PHP, JavaScript, instalación
independiente repetida, HTTP y navegador en escritorio/móvil. Reportar resultados
y limitaciones; una simulación no certifica hardware, SMTP, Meta, n8n ni reinicio.
Ver [pruebas](desarrollo/pruebas.md) y [desarrollo](desarrollo/desarrollo.md).

## 13. Módulos nuevos, transferencias y límites posteriores

Cada módulo posterior documenta estado real, contexto, actores, permisos,
facultades, dependencias, datos, invariantes, endpoints, UI reutilizada, pruebas y
exclusiones. Actualizar la matriz de este contrato al liberarlo. Un módulo
preparado permanece cerrado para escrituras hasta completar todo su flujo.

Transferencias requiere definir envío, recepción parcial, diferencias, pérdidas,
rechazos y retorno antes de implementarse. Tránsito no es saldo disponible del
destino ni ingreso/gasto financiero. Producción, catering, IA Global y nuevos POS
no se implementan por inferencia. El [backlog](backlog.md) distingue defectos,
mejoras y funciones nuevas; un defecto crítico no se rebaja a mejora futura.

No hacer push, modificar main ni merge sin autorización específica. La rama
candidata se entrega limpia, con commits identificables, datos reconstruibles,
auditoría frente a main e informe para el siguiente desarrollador. Los cambios
ajenos permanecen en su worktree original.
