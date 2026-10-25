# Etapa 2 — Preservación del menú de Restaurante

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


Fecha: 2026-10-24. Rama `codex/preservacion-menu-etapa-2`, creada desde main
integrado `1aa642a`. Se incorporaron por cherry-pick los dos commits documentales
previos. Sin stashes, push ni merge; el commit de cierre se obtiene con git log -1
en esta rama. Solo incluye herramientas, pruebas y documentación de esta etapa.

## Herramientas y uso

CLI `scripts/preservar-menu.php`; helpers `scripts/menu/`. Las credenciales se
leen de includes/.env sin imprimirlas. Todo acceso a BD requiere host/base/entorno
explícitos e iguales a configuración development/test; conexión origen READ ONLY
con snapshot consistente. Nunca recibe DDL de siembra ni ejecuta mutaciones allí.

```powershell
php scripts/preservar-menu.php inspeccionar --host=127.0.0.1 --base=casa-pestalozzi --entorno=development
php scripts/preservar-menu.php exportar --host=127.0.0.1 --base=casa-pestalozzi --entorno=development --salida=CARPETA_NUEVA
php scripts/preservar-menu.php preservar --host=127.0.0.1 --base=casa-pestalozzi --entorno=development --salida=CARPETA_PRIVADA_NUEVA
php scripts/preservar-menu.php verificar --paquete=CARPETA_PAQUETE
php scripts/preservar-menu.php verificar-respaldo --respaldo=CARPETA_RESPALDO
php scripts/preservar-menu.php ensayar --host=127.0.0.1 --base=casa-pestalozzi --entorno=development --paquete=CARPETA_PAQUETE --respaldo=CARPETA_RESPALDO --salida=CARPETA_PRIVADA_NUEVA
```

CARPETA indica rutas absolutas a reemplazar, con padre existente; nunca sobrescribe
salidas. Preservar reúne exportación, respaldo, comparación posterior del origen y
ensayos SQL/archivos. Exportar solo crea paquete comercial: no basta para reconstruir.
Ensayar reutiliza respaldo/paquete confiables; no reconstruye la base configurada.
Ver [contrato v1](formato-menu-v1.md) para formato y futura importación.

## Origen y catálogo efectivos

Host 127.0.0.1, puerto 3306, base casa-pestalozzi, entorno development, MySQL 9.7.1.
Se inspeccionó el DDL efectivo, no se asumió igualdad con deploy.sql. Huella del
esquema de catálogo:
`ee2a9689357768f122be784fe80dfeb38123c49a0a453b7394c0a9b9d5116d54`.

| Elemento | Cantidad |
| --- | --- |
| Productos | 112 |
| Activos / inactivos reales | 112 / 0 |
| Productos comida / maridaje / temporada | 78 / 29 / 5 |
| Categorías / áreas referenciadas | 15 / 4 |
| Imágenes únicas copiadas | 13 |
| Productos con foto propia | 3 |

Los inactivos se comprobaron con fixtures nuevos aislados; no se modificaron
productos reales para fabricar casos. Fotos NULL y fallback preservados, precios
como cadenas exactas y correspondencias completas por ID/ruta sin fusionar nombres.

Dos respaldos completos se restauraron con éxito: 48 tablas, 2,441 filas, una
vista y dos triggers. Menú igual campo por campo, DDL equivalente, todas las FK
comprobadas y bases aleatorias eliminadas tras verificar propiedad. El segundo
ensayo también compara las huellas de resultados de la vista. Los archivos del
entorno se copiaron/restauraron con verificación integral de hashes.

SHA256 menu.json, idéntico en ambas ejecuciones:
`49051f6ef17446efef69862a235e4515c5dc7d8b010dad43694cf249402a7bd0`.
SHA256 manifiesto.json del segundo ensayo:
`2dd893a9e13d9267d5d02f571df173ec9148db84659e214e338de68566db2287`.
El manifiesto cambia entre ejecuciones porque incluye fecha/metadata; el contenido
comercial y sus archivos no cambiaron. El origen completo se comparó después del
respaldo; no se detectaron cambios.

## Resultados privados

```text
C:\Users\leona\.codex\visualizations\2026\10\24\01a16c88-dff9-7930-adef-c2e4a2bb06b1\
  preservacion-menu-etapa2-01\  primer ensayo
  preservacion-menu-etapa2-02\  ensayo final ampliado
```

Cada ejecución contiene paquete/, respaldo/, restauracion.json,
restauracion-archivos.json y entorno-restaurado/. El primer ensayo restauró
848 archivos y el segundo 849, tras incorporar el runner de integración.
Respaldos/configuraciones/datos están fuera de htdocs y de Git.
Guardar una copia privada adicional antes de reconstruir en una etapa futura.

## Pruebas y pendientes

- `npm run test:menu-preservacion`: 34 aserciones correctas; hashes, referencias,
  mapas, IDs, orden numérico, NULL/vacío, precios/estados/cartas alterados con
  hashes recalculados, pérdida de inactivos, rutas e imágenes ausentes/corruptas.
- `npm run test:menu-preservacion:db -- --host 127.0.0.1 --base casa-pestalozzi --entorno development`:
  siete casos correctos sobre base aleatoria con token y limpieza verificada;
  tres cartas, inactivos, acentos, decimales, fotos propias/fallback, repetición,
  rechazo de esquema inesperado, escala de precio distinta, URL y archivo ausente.
- PHP lint correcto en CLI, helpers y runners.
- Regresión PHP conserva el fallo previo en run-reservaciones-cierre-static.php
  sobre estados de entrega del POS; no se borró ni relajó esa aserción.
- La lista de fuentes de la prueba documental JS ahora incluye docs/migracion;
  conserva sus aserciones funcionales y revisión de enlaces. `npm run test:js`
  completo pasó, incluidos 121 enlaces locales y los contratos del POS.

No hay cambios en módulos operativos, vistas, frontend o DDL. package-lock.json
ya estaba modificado y queda fuera del commit. No se reconstruyó desarrollo ni
comenzó la Etapa 3. El importador definitivo y la equivalencia visual del catálogo
multinegocio siguen pendientes del DDL aprobado; no se presentan como certificados.
