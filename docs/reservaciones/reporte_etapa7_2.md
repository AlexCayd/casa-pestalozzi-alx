# Reporte de validación — Etapa 7.2

## Estado

**APROBADA**

## Alcance

- Restauración de creación de reservaciones desde el mapa operativo.
- Barra del modo de selección con exactamente dos acciones: `Cancelar`/`Asignar más tarde` y `Guardar asignación`.
- Advertencia POS para reservaciones próximas, incluyendo el envío explícito de `confirmar_reservacion_proxima=1`.
- Revisión del ciclo de vida de `ConfirmationModal` y sus consumidores existentes.

## Cambios principales

- El formulario de creación se abre correctamente desde `Crear reservación`, conserva el contexto de fecha/hora y permite el alta sin contacto.
- Las confirmaciones aceptadas se conservan en el envío del formulario y el estado de contacto se sincroniza al reiniciar el formulario.
- La advertencia POS usa el catálogo/backend existente: solicita confirmación en el rango próximo, bloquea el caso protegido y permite abrir el ticket sólo después de la decisión explícita.
- `ConfirmationModal` limpia loading, disabled, estado, foco, overflow y callbacks al cerrar o reabrir; además resuelve la promesa en cierre, cancelación, Escape, backdrop, confirmación y error.
- El portal del modal se monta dentro de un `<dialog>` nativo activo cuando corresponde, para conservar visibilidad e interacción en el top layer.

## Validaciones ejecutadas

| Validación | Resultado |
| --- | --- |
| `php scripts/tests/run-etapa7-2-creacion-modales.php` | PASS |
| `php scripts/tests/run-etapa7-2-creacion-modales.php --dynamic` | PASS — smoke HTTP autenticado |
| `php scripts/tests/run-etapa7-1-regresiones-operativas.php` | PASS |
| `php scripts/tests/run-reservaciones-catalogo.php` | PASS — 191 códigos |
| `php scripts/auditar-errores-reservaciones.php` | PASS — errors=0, warnings=0 |
| `npm.cmd test` | PASS — PHP y 47 archivos JavaScript |
| `npm.cmd run build` | PASS |

## Evidencia

Las capturas y sus resoluciones están documentadas en [evidencia_visual_etapa7_2.md](evidencia_visual_etapa7_2.md). La comprobación visual cubre móvil para creación, escritorio para asignación y escritorio amplio para la advertencia POS.

## Commits previstos

1. `fix(reservaciones): restaurar creacion desde mapa`
2. `fix(pos): restaurar confirmacion por reservacion proxima`
3. `fix(reservaciones): evitar bloqueos en modales de confirmacion`
4. `test(reservaciones): validar creacion y ciclo de modales`

## Fuera de alcance

No se modificaron reglas de dominio ajenas a la etapa ni el esquema/DDL de la base de datos.

## Riesgos residuales

La captura manual cubre los estados principales en navegador local; las regresiones de contrato, el smoke HTTP y la compilación quedan automatizados en los runners y comandos indicados arriba.
