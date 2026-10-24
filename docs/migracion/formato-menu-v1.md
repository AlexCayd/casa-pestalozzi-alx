# Contrato de preservación e importación: menú v1

Estado: formato implementado de exportación/verificación. El importador definitivo
se completará con el DDL multinegocio aprobado; no existe un importador provisional.

## Paquete inmutable

```text
paquete/
  menu.json
  archivos/<ruta pública original>
  manifiesto.json
  correspondencias.json
  validacion.json
```

`menu.json` tiene formato `casa-pestalozzi.menu`, versión entera 1,
negocio `restaurante`, listas productos/categorias/areas y objeto orden.
Todo valor SQL es string o NULL; no convertir decimales a float ni normalizar
texto. IDs positivos canónicos como strings; no fusionar productos por nombre.

| Lista | Campos exactos |
| --- | --- |
| productos | id, nombre, descripcion, img, categoria_id, precio, area_id, activo |
| categorias | id, nombre, carta, img, activo |
| areas | id, nombre, slug, color |

Precio tiene dos decimales exactos; activo es `"0"` o `"1"`. Descripción e imagen
permiten NULL o vacío y se conservan distintos. Carta: comida/maridaje/temporada.
Referencias existentes e IDs únicos. Se exportan todos los productos, incluidos
inactivos, y sus categorías/áreas referenciadas. La tabla antigua menu no se usa.

`orden.productos` y `orden.categorias` contienen todos los IDs en orden numérico
ascendente, igual que sus listas de filas. La carta se reconstruye por categoría
y luego producto siguiendo esos ordinales. Si se renumeran IDs, persistir orden
explícito equivalente; nunca orden lexicográfico (1,10,2).

Cada imagen no vacía se copia bajo archivos/ conservando ruta relativa y bytes.
Una imagen compartida se copia una sola vez. NULL no fabrica foto propia; conserva
fallback de categoría. Se rechazan URLs, traversal, rutas absolutas/separadores
inversos y enlaces simbólicos. No se descarga ni elimina nada del origen.

`manifiesto.json` tiene formato `casa-pestalozzi.menu.manifiesto`, versión 1,
origen (host/puerto/base/entorno/revisión/fecha UTC/servidor/huella de esquema),
esquema efectivo, conteos, hashes de menu.json y correspondencias.json y archivos
con ruta/SHA256/tamaño/MIME. El esquema contiene SHOW FULL COLUMNS y SHOW CREATE
TABLE; su SHA256 se calcula sobre `Comun::json(esquema.tablas)`, UTF-8 sin BOM.
No inventar versión de migración si no hay tabla de versiones: registrar DDL real.
Revisión Git y estado de cambios locales se registran por separado; el árbol
respaldado puede contener trabajo local que no esté en ese commit.

Se admite metadata temporal created_at/updated_at en esas tres tablas, que no es
parte de la ficha importable; cualquier otra columna extra o esquema incompatible
obliga a revisar el exportador. Se comprueban tipos, nulabilidad y DECIMAL(p,2).

`correspondencias.json`: versión 1, objetos productos/categorias/areas/archivos.
En esta etapa cada ID/ruta se mapea a sí mismo de forma completa y exacta.
`validacion.json` registra errores, conteos y comparación posterior de origen;
repetir el verificador, no confiar únicamente en una bandera ok guardada.

Las huellas detectan corrupción, no autentican al autor. Conservar SHA256 del
manifiesto en evidencia externa. Un payload alterado con hashes recalculados
puede ser internamente coherente: contrastar contra snapshot/origen confiable
para detectar cambios válidos pero no autorizados de precio, carta o estado.

## Obligaciones del importador futuro

1. Validar paquete/versión/hashes/referencias antes de escribir. Usar solo DDL
   multinegocio aprobado y destino identificado, sin esquema alternativo.
2. Importar identidad comercial y oferta exclusivamente Restaurante. Mantener
   precio, ficha, categoría/carta, área, disponibilidad y orden. No importar
   productos a Panadería ni recetas/stock/usuarios/tickets anteriores.
3. Preferir IDs originales cuando convenga; si cambian, emitir un informe nuevo
   con mapa de cada producto origen a identidad y oferta destino, y mapas de
   categorías, áreas y archivos. Exigir cobertura completa, FK coherentes y
   ausencia de fusiones. No editar el paquete original.
4. Mantener bytes/hash de imágenes, foto propia o NULL/fallback. No publicar un
   catálogo parcialmente importado ni sobrescribir archivos diferentes.
5. Transacción completa de datos e identificación/hash único de paquete: repetir
   no duplica ni hace upsert por nombre sobre catálogos ya editados.
6. Emitir una **proyección reconstruida v1** con la misma estructura de menu.json,
   expresada en IDs/rutas originales mediante los mapas inversos verificados.
   Obtenerla del destino persistido, nunca copiando el JSON de entrada. Verificar
   primero que la oferta real pertenece a Restaurante y no a Panadería.

Ya está preparado el comparador independiente del DDL:

```powershell
php scripts/preservar-menu.php comparar --paquete=CARPETA --proyeccion=CATALOGO_RECONSTRUIDO.json
```

No abre conexión ni importa datos. Valida el paquete y compara cada campo/tipo,
filas ausentes, inactivos, orden, precio, estado, carta y NULL/vacío. Exit 0 solo
con igualdad completa. API PHP: `VerificadorMenu::comparar($original, $proyeccion)`
devuelve diferencias por ruta. Las pruebas de Etapa 2 incluyen cambios semánticos
con hashes recalculados para asegurar que se comparan campos individuales.

Cuando exista el importador, probar la proyección desde el esquema nuevo,
sus mapas y admin/público/PDF/POS en navegador. Esta etapa no certifica una
importación multinegocio ni visuales que todavía no existen.

## Respaldo completo separado del catálogo comercial

`respaldo/esquema.json` conserva DDL efectivo de todas las tablas, vistas y triggers,
columnas, PK, servidor y defaults. `datos/<tabla>.jsonl` contiene filas como listas
de valores base64 o NULL, preservando bytes binarios. El manifiesto de respaldo
contiene hashes, conteos, multiconjuntos para tablas sin PK y huellas de vistas.
Estos registros se restauran solo para ensayar recuperación, no se migran al
esquema multinegocio.

`entorno/` conserva código local, configuración privada, activos, documentos,
scripts y archivos raíz. Excluye vendor/node_modules, .git, tmp, sesiones y runtime
n8n; dependencias recuperables con manifiestos/locks. No se afirma respaldar todo
el sistema operativo, infraestructura Docker o credenciales externas de n8n.
El destino debe quedar fuera del repositorio y su raíz web contenedora. Puede
contener secretos y datos personales: no versionar ni publicar. El ensayo de
archivos restaura a una carpeta nueva y compara todas sus huellas.

El ensayo SQL genera `cp_menu_restore_<20hex>` y un token de propiedad. No admite
nombre destino proporcionado por el usuario. Solo esa base creada en la llamada
y con token verificado puede eliminarse. Importa filas sin disparar triggers,
recrea estos después, reconstruye vistas y comprueba DDL, datos, FK y menú.
En el clon remapea referencias al esquema fuente y omite DEFINER para usar la
cuenta de ensayo; el respaldo conserva el DDL original. Se mantienen charset,
collation y SQL mode de objetos. El origen permanece solo lectura.

Soporte actual: tablas InnoDB, vistas y triggers de la instalación inspeccionada.
Otro motor, rutinas o eventos obligan a abortar y ampliar soporte antes de aceptar
un respaldo; no se omiten silenciosamente. Una ejecución fallida puede dejar
salida parcial para diagnóstico que nunca debe considerarse paquete aceptado.
