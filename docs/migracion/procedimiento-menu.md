# Procedimiento reproducible de conservación del menú

Estado actualizado: exportación/verificación/respaldo implementados y ensayados
en [Etapa 2](etapa-2-menu.md). El [formato v1](formato-menu-v1.md) es el contrato
del importador concretado en [Etapa 3A](etapa-3a.md) sobre el DDL definitivo.
Se ensaya solo en destinos desechables; no se reconstruye la base de desarrollo.
Política: [plan maestro](plan-maestro.md).

## 1. Entradas, responsables y salidas

Responsable de base/archivos identifica origen y destino y conserva respaldos.
Responsable del importador implementa el formato y verificaciones; responsable
de Restaurante valida administración, carta pública, PDFs y venta del catálogo.
La entrega no se acepta hasta disponer de la evidencia de los tres.

Entradas: host/nombre exactos de origen y destino, entorno development/test
declarado y confirmado como desechable, revisión del código, versión de esquema,
raíz pública de imágenes resuelta y directorio de respaldo fuera de cualquier
árbol que vaya a borrarse. Credenciales se leen del entorno y no se imprimen.

Salidas futuras, en una carpeta de ejecución con ID único:

| Archivo / directorio lógico | Contenido verificable |
| --- | --- |
| `respaldo/` | Dump completo anterior, archivos asociados/configuración necesaria para restaurar y reporte de ensayo; secretos fuera del repositorio |
| `menu.json` | Versión de formato, productos y dependencias con IDs de origen, cadenas decimales y NULL explícitos |
| `archivos/` | Copias exactas de imágenes y dependencias necesarias del menú |
| `manifiesto.json` | Host/BD identificados sin credenciales, revisión/esquema, fecha, conteos y hashes SHA256 de exportación y archivos |
| `correspondencias.json` | IDs producto/categoría/área y rutas origen → destino; incluso si se conservaron |
| `validacion.json` | Igualdad de datos, hashes, conteos y errores; destino identificado y resultado de importación |
| evidencia visual | Capturas admin/público, PDFs y comprobación del catálogo POS sobre destino nuevo |

Estos nombres definen el paquete y los resultados de Etapa 2. Cada ejecución
es inmutable; no sobrescribe una exportación aceptada. El respaldo completo se
retiene aunque solo se importe el menú. No versionar dumps, credenciales ni datos
personales de desarrollo en Git.

## 2. Preflight y congelación

1. Validar entorno y nombres/hosts exactos mediante parámetros explícitos y
   metadatos. Exigir marca persistida o registro local revisable que identifique
   el destino como desarrollo/test desechable. No inferir por `localhost`, un
   prefijo de nombre o por `APP_ENV` únicamente; abortar si falta identificación.
2. Separar exportador de solo lectura, importador sobre destino vacío y operación
   destructiva de reconstrucción. Un importador nunca decide por sí mismo hacer DROP.
   Un exportador nunca modifica catálogo ni sus archivos.
3. Detener edición de catálogo/subida/borrado y purgas de imágenes; para un respaldo
   completo consistente, detener también escritores/trabajos de la aplicación.
   Registrar la ventana; mantener coherencia entre snapshot SQL y copia de archivos.
4. Crear respaldo previo completo del esquema/datos y archivos pertinentes con
   checksum. Ensayar restauración en base desechable y verificar que el menú del
   estado anterior y sus imágenes pueden recuperarse. Un dump que solo existe
   en disco no acredita restauración. Registrar errores antes de continuar.

## 3. Exportación del catálogo y dependencias

Fuente actual inspeccionada: `productos`, `categorias`, `areas_produccion`,
`models/CategoriasMenu.php`, `services/Menu/Carta.php` y el sistema de imágenes.
La tabla antigua `menu` no es fuente activa del catálogo; no importar sus filas
como productos adicionales. Antes de ejecutar, contrastar el esquema efectivo
de la BD identificada y abortar ante campos desconocidos necesarios para la ficha.

- Exportar **todos** los productos actuales de Restaurante, sin filtrar activo:
  `id`, `nombre`, `descripcion`, `img`, `categoria_id`, `precio`, `area_id`, `activo`.
  Si aparecen datos multinegocio futuros, filtrar Restaurante explícitamente y
  registrar la regla; no asumir que todos los productos son de Restaurante.
- Exportar las categorías referenciadas: `id`, `nombre`, `carta`, `img`, `activo`.
  Carta hoy es `comida`, `maridaje` o `temporada`, no una tabla independiente.
  Conservar la semántica y orden actuales por ID; si se renumeran, persistir orden
  equivalente en el destino. Las categorías vacías no son necesarias salvo que
  constituyan una dependencia de presentación identificada y documentada.
- Conservar correspondencia de área de producción: hoy es FK necesaria y el POS
  la usa para dirigir comandas. Exportar ID/slug/nombre y otros metadatos mínimos
  referenciados o mapearlos a áreas equivalentes verificadas del seed definitivo.
  No arrastrar impresoras/configuraciones de prueba por esa relación.
- Registrar tipos y codificación UTF-8, precio como decimal exacto de dos
  decimales, descripción NULL frente a texto vacío y `img=NULL` frente a foto
  propia. No normalizar nombres ni fusionar por coincidencia de texto.
- Guardar proyecciones esperadas del catálogo administrativo y de las vistas
  públicas actuales para comprobar publicación y presentación después.
  No basta contar productos: conservar pertenencia, orden y disponibilidad.

No exportar recetas, variantes, ingredientes, stock, movimientos, tickets, pagos
ni analíticas en el paquete de importación. Pueden estar en el respaldo completo
como mecanismo de recuperación, pero no son entradas del importador.

## 4. Archivos e imágenes

Recolectar `productos.img`, `categorias.img` referenciadas y cualquier archivo
adicional imprescindible para la presentación constatado durante el inventario.
Hoy el uploader guarda rutas relativas bajo `public/build/images/`; comprobar
las rutas reales y no limitar la búsqueda a las fotos propias de producto.
Mantener recursos estáticos del repositorio con su revisión de código cuando
formen parte de la carta; no tratarlos como datos a descartar.

Para cada referencia: ruta relativa original, tamaño, tipo y SHA256. Copiar
archivos compartidos una sola vez pero registrar todos sus referentes. Conservar
foto propia NULL para que siga aplicándose el fallback de categoría. En temporada,
sin foto propia ni de categoría un producto hoy no aparece en la vitrina: conservar
esa semántica, no publicar automáticamente todo lo importado.

Resolver y comprobar rutas bajo raíces autorizadas; rechazar traversal, enlaces
que escapen y nombres inválidos. No descargar URLs externas silenciosamente:
registrar y resolver esas dependencias antes de aceptar el paquete. Cualquier
archivo esperado ausente o hash inconsistente bloquea reconstrucción hasta
corregir el paquete. Una ausencia de foto propia declarada como NULL es válida.

## 5. Verificación previa obligatoria

Validar JSON/formato, IDs únicos, categorías/áreas referenciadas existentes,
valores de carta conocidos, estados y decimales. El número de productos y sus
campos debe coincidir exactamente con el snapshot de origen, agrupado también
por categoría/carta/estado; recalcular hashes desde las copias, no desde una lista
confiada. Revisar los manifiestos contra los archivos efectivos y el respaldo.

La reconstrucción requiere simultáneamente: origen/destino identificados,
respaldo restaurable comprobado, exportación completa y verificable, archivos
íntegros y ensayo satisfactorio del importador en una base vacía desechable.
Hasta entonces no borrar ni reemplazar la BD actual de desarrollo.

## 6. Esquema definitivo e importación

En la ejecución futura autorizada:

1. Crear destino vacío de desarrollo identificado con el DDL definitivo y
   deploy mínimo. DDL no se considera reversible con ROLLBACK por sus commits
   implícitos. Preferir una BD nueva para conservar intacta la anterior.
2. Sembrar unidades, roles estructurales y áreas necesarias; separar estos seeds
   de productos de muestra. No ejecutar un development seed que inserte productos
   Restaurante encima del catálogo preservado. Crear credenciales dev seguras
   mediante configuración externa, nunca contraseñas versionadas.
3. Preparar imágenes en destino temporal, verificar todos sus hashes y colocar
   en rutas finales sin sobrescribir archivos distintos. Registrar mapa si cambian
   rutas; no publicar la aplicación hasta terminar las comprobaciones.
4. Importar categorías/cartas y sus estados/orden, luego productos e identidad
   comercial con sus ofertas **solo de Restaurante**, dentro de una transacción
   de datos. Mantener IDs cuando convenga; en otro caso registrar correspondencia
   biyectiva origen → destino y aplicar todas las FK desde ella.
5. Precio, categoría/carta y disponibilidad pertenecen a la oferta del negocio
   según el contrato nuevo; nombre/descripción/foto quedan en identidad/oferta
   según el DDL definitivo, con proyección equivalente al original. No poblar
   Panadería con esos productos ni crear recetas/variantes antiguas por defecto.
6. Repetir verificación antes de commit; si falla una fila, revertir toda la
   importación. Los archivos preparados no pueden quedar como catálogo publicado
   a medias. Si hay fallo de archivos, mantener destino sin activar y reintentar
   desde el paquete íntegro, sin tocar el respaldo.
7. Importación repetida del mismo paquete no duplica filas: registrar ID/hash de
   paquete y rechazar reimportación con estado claro. Para reensayar, usar otro
   destino vacío; no hacer upsert por nombre sobre catálogos editados.
8. Generar fixtures independientes y etiquetados para Panadería, y datos nuevos
   de recetas, inventario y operación Restaurante. No agregar productos de prueba
   a la oferta preservada sin distinguirlos en la verificación del paquete.

## 7. Verificación y activación

Comparar cada campo preservado mediante las correspondencias, no solamente
conteos o suma de precios. Comparar precio exacto, descripción/NULL, identidad,
categoría/carta/orden, disponibilidad y rutas/hash de imágenes. Cero huérfanos;
cero productos preservados importados involuntariamente a Panadería.

Comprobar en navegador `/admin/menu` (incluidos filtros y productos inactivos),
`/menu`, landing de comida y temporada, `/menu/pdf`, `/maridaje/pdf`, catálogo del
POS y encaminamiento de comandas a las áreas equivalentes. Los productos que
no debían publicarse deben seguir ocultos. Probar foto propia y fallback,
descripciones y acentos, móvil/escritorio. Con fixtures nuevos comprobar venta,
receta, stock/cancelación y reportes, sin exigir registros antiguos.

Activar la pareja código/esquema solo al pasar la regresión funcional y la
validación del menú. Registrar destino, revisión, hash del paquete y evidencia.
La importación no acredita impresión física ni credenciales externas.

## 8. Recuperación

Ante fallo, no reabrir la aplicación sobre un esquema parcial. Mantener la base
anterior intacta y el respaldo; restaurar código/esquema/archivos de una misma
revisión si fuera necesario. El destino fallido solo se limpia tras comprobar
su ruta/host/nombre explícitos de desarrollo/test. No aplicar borrados genéricos.
No sincronizar historia de prueba entre ambas bases ni construir compatibilidad
temporal. El reporte identifica la causa y permite repetir en destino vacío.
