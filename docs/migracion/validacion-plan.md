# Validación de la actualización documental

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


Fecha: 2026-10-24. Política aplicada: actualización obligatoria para desarrollo.
Estado: plan actualizado; decisiones técnicas y ejecución posterior pendientes.

## Alcance realizado

- Referencia vigente centralizada en `docs/migracion/`; índices y contrato anterior
  remiten a ella. El adjunto nuevo se preserva íntegro y el original se identifica
  como antecedente cuya conservación histórica ha quedado sustituida.
- Plan maestro revisado: solo menú Restaurante y dependencias; reconstrucción
  limpia con respaldo y exportación verificados; fixtures nuevos independientes.
- Contrato técnico compartido con los doce puntos de arquitectura, sin migración
  histórica ni adaptadores de esquemas descartados. Conserva las garantías de las
  operaciones nuevas: autorización, propiedad, atomicidad, idempotencia y tránsito.
- Protocolo reproducible de menú especificado: entradas/salidas, formato,
  dependencias, archivos, hashes, correspondencias, importación, pruebas y recuperación.
- Etapas, riesgos y aceptación reorganizados alrededor del esquema definitivo.

## Verificaciones realizadas

Inspección del código y esquema versionado para definir las dependencias reales
del catálogo: `models/Producto.php`, `models/CategoriasMenu.php`,
`services/Menu/Carta.php`, `services/Menu/FotoPlatillo.php`,
`classes/ImagenUploader.php`, `controllers/MenuController.php`,
`controllers/AdminMenuController.php` y `database/ddl.sql`.

Hallazgos incorporados: carta vive en categoría; hay comida, maridaje y temporada;
la foto NULL del producto conserva el fallback de categoría; el orden actual usa
IDs; área de producción es dependencia del catálogo POS; la tabla antigua `menu`
no debe importarse como otra fuente de productos.

Comprobaciones documentales: enlaces Markdown locales existentes, ausencia de
whitespace accidental en documentos redactados y hash SHA256 idéntico del nuevo
adjunto frente a su copia. El adjunto preserva sus saltos Markdown originales.
Revisión de cambios limitada a documentación; `package-lock.json` ya estaba
modificado y queda fuera de esta entrega.

No se vuelven a ejecutar suites de aplicación para cambios exclusivamente
documentales. La evidencia anterior registró JS/contextos correctos y un fallo
PHP previo en `run-reservaciones-cierre-static.php`; esta actualización no lo
corrige ni lo declara resuelto.

## No ejecutado y aceptación futura

No se consultaron registros de la BD de aplicación, no se exportó el menú ni se
creó un respaldo. No se cambiaron SQL, fixtures, servicios, rutas o interfaces.
No se creó herramienta nueva ni se reconstruyó base alguna. Ninguna verificación
de importación, restauración o navegador se presenta aquí como ya realizada.

Las pruebas futuras se definen en el [plan maestro](plan-maestro.md), el
[contrato compartido](contratos-tecnicos.md) y el [protocolo de menú](procedimiento-menu.md).
Su implementación y evidencia son puertas de aceptación antes de sustituir el
entorno de desarrollo. Identificar explícitamente host/BD development/test,
respaldo restaurable y paquete íntegro sigue siendo obligatorio antes de cualquier
acción destructiva futura. En esta entrega no se ejecuta esa acción.
