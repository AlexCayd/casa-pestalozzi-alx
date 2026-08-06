# Revisión de accesibilidad — Etapa 7

Comprobación manual y estática realizada en landing, consulta pública,
administración, mapa operativo y POS. Se revisaron los tres viewports
`1366×768`, `1024×768` y `390×844`. No se declara cumplimiento completo de
WCAG.

## Matriz de comprobación

| Superficie | Comprobaciones realizadas | Resultado |
| --- | --- | --- |
| Rail y navegación admin | enlaces con nombres accesibles, botón de apertura/cierre y retorno visual | PASS |
| Landing y portal público | tabs, labels de fecha/hora/contacto, progreso y `aria-live` para resultados | PASS |
| Selectores de fecha y hora | controles de teclado, estados disabled/selected y mensajes de estado | PASS |
| Mapa de reservaciones/POS | botones de mesa, panel lateral, acciones nombradas y estado no dependiente sólo del color | PASS |
| Formularios admin | asociación label/control, `aria-invalid`, mensajes de campo y foco en el primer error | PASS |
| Alertas | roles/status y mensajes diferenciados de confirmaciones | PASS |
| Shell de confirmación | `role=dialog`, `aria-modal`, `aria-labelledby`, `aria-describedby`, `inert`, foco inicial, trampa Tab, Shift+Tab, Escape y retorno de foco | PASS |
| Responsive | sin overflow horizontal de documento en las superficies comprobadas | PASS |
| Movimiento | reglas existentes de `prefers-reduced-motion` revisadas donde aplica | PASS |

## Evidencia del shell modal

El componente canónico está en `src/js/components/confirmation-modal.js` y sus
estilos en `src/scss/components/_confirmation-modal.scss`. Las capturas de
Etapa 6 documentan el tamaño y legibilidad del shell en:

- `docs/reservaciones/capturas_etapa6/modal-1366x768.png`
- `docs/reservaciones/capturas_etapa6/modal-1024x768.png`
- `docs/reservaciones/capturas_etapa6/modal-390x844.png`

La evidencia nueva de landing, sin datos de clientes, se conserva en:

- `docs/reservaciones/capturas_etapa7/landing-1366x768.png`
- `docs/reservaciones/capturas_etapa7/landing-1024x768.png`
- `docs/reservaciones/capturas_etapa7/landing-390x844.png`

## Límites

No se ejecutó una auditoría formal con lectores de pantalla, matriz completa de
combinaciones de alto contraste, pruebas de zoom del 200%/400% ni validación
externa de WCAG. Esas actividades permanecen fuera del cierre de estabilización.
