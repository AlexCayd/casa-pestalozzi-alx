# Evidencia de la Etapa 1 técnica

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../arquitectura.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


> Evidencia de la propuesta inicial, conservada como antecedente. Sus instrucciones
> de migración no son vigentes; el estado actual y sus reglas están en
> [Arquitectura](../arquitectura.md).

Fecha: 2026-10-24. Base: `main`, `1aa642a`.
Alcance: documentación de contratos; sin cambios de código, esquema, rutas,
fixtures, configuración o activos compilados. Esta evidencia no demuestra que
los contratos propuestos estén implementados.

## Comprobaciones ejecutadas

| Comando / comprobación | Resultado |
| --- | --- |
| SHA256 del adjunto frente a `documento-maestro-multinegocio.md` | Iguales; referencia funcional preservada byte por byte |
| `php scripts/tests/run-contextos.php` | Correcta: 235 comprobaciones |
| `php scripts/tests/run-contextos-isolated.php` | Correcta: 280 comprobaciones HTTP/DB; base temporal eliminada |
| `npm run test:js` | Correcta: suite completa |
| `npm run test:php` | Se detiene en `run-reservaciones-cierre-static.php`; no se ejecutan sus scripts posteriores |
| Enlaces Markdown locales de los cuatro documentos nuevos | Destinos existentes |
| Revisión de whitespace de documentos redactados | Correcta; `git diff --cached --check` señala solo dos saltos Markdown del original preservado |

El fallo PHP reproducido es:

```text
FALLO: la entrega conserva los estados de entrega compatibles con cocina
```

La prueba exige en `PuntoVentaController.php` la cadena literal
`WHERE id = {$itemId} AND estado = 'listo'` y una segunda condición de estados.
La suite se ejecutó antes de crear los documentos, con código idéntico a la base
integrada. El fallo ya figura en [la entrega anterior](etapa-1.md); no fue
introducido por esta documentación. No se cambió la entrega de productos ni se
relajó la prueba para declarar éxito. Hace falta revisar por separado qué contrato
de entrega debe conservarse y validarlo funcionalmente antes de cerrar regresión.

Las pruebas HTTP usan el runner existente que crea una base aleatoria y la elimina
en su limpieza. No se aplicaron migraciones a la base configurada de la aplicación.
No se verificaron visuales nuevos porque no hay cambios de interfaz. No se han
certificado impresión física, credenciales de integraciones ni restauración.

## Estado de aceptación

La documentación está preparada para revisión, **no hay aceptación de la etapa
ni autorización implícita para activar los módulos siguientes**. Pendientes:

- Aprobar las decisiones propuestas en el contrato técnico.
- Resolver la validación de regresión PHP ya fallida en la base integrada.
- Definir diferencias/incidencias antes de habilitar transferencias entre ámbitos.

Las pruebas de concurrencia, migración, aislamiento de datos operativos y
conciliación descritas en los contratos son requisitos futuros; no se presentan
como pruebas ejecutadas en esta entrega documental.

El cambio previo de `package-lock.json` se mantuvo fuera de esta entrega.
Los dos espacios finales en las líneas de versión y estado del documento maestro
son saltos de línea Markdown del adjunto; se conservan para mantener la copia exacta.
