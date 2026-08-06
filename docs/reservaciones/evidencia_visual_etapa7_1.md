# Evidencia visual — Etapa 7.1

Fecha de captura: 2026-08-06. Rama: `modulo-reservaciones`. Las pruebas de navegador usaron el usuario demo local y datos sintéticos; no se capturó información de contacto real.

Las imágenes PNG se tomaron desde el navegador integrado en los viewports solicitados. El shell accesible del `ConfirmationModal` se verificó además con el DOM runtime; el exportador PNG del navegador conservó el formulario padre en ese overlay, por lo que el estado exacto de acciones queda en las capturas DOM de B1 y B2.

| Caso | Viewport | Texto correcto | Acción correcta | Sin scroll innecesario | Resultado |
| ---- | -------: | --------------: | --------------: | ---------------------: | --------: |
| B1 >12 personas | 1366×768 | `Asignar más tarde`; no `Asignar después` | `Volver` / `Asignar más tarde` | Sí en el shell; el alta extensa conserva scroll propio | PASS — [b1-acciones-1366x768.png](capturas_etapa7_1/b1-acciones-1366x768.png) |
| B1 >12 personas | 1024×768 | Igual | Igual | Sí en el shell | PASS — [b1-confirmacion-dom-1024x768.md](capturas_etapa7_1/b1-confirmacion-dom-1024x768.md) |
| B1 >12 personas | 390×844 | Igual | Igual | Scroll sólo en el formulario largo | PASS — [b1-acciones-390x844.png](capturas_etapa7_1/b1-acciones-390x844.png) |
| B2 capacidad | 1366×768 | Título y consecuencia de capacidad | `Volver a seleccionar` / `Guardar de todas formas` | Resumen interno | PASS — [c4-assignment-edit-1366x768.png](capturas_etapa7_1/c4-assignment-edit-1366x768.png) + [b2-capacidad-dom-1024x768.md](capturas_etapa7_1/b2-capacidad-dom-1024x768.md) |
| B4 mapa automático | 1366×768 | Alta y mapa operativo | Checkbox marcado; propuesta automática | Sí | PASS — [b4-auto-mapa-1366x768.png](capturas_etapa7_1/b4-auto-mapa-1366x768.png) |
| B4 mapa automático | 1024×768 | Igual | Igual | Sí | PASS — [b4-auto-mapa-1024x768.png](capturas_etapa7_1/b4-auto-mapa-1024x768.png) |
| B7 editar mesas | 1366×768 | `ASIGNACIÓN DE MESAS` | `Cancelar`, `Liberar asignación`, `Guardar de todos modos` | La barra de edición permanece legible | PASS — [c4-assignment-edit-1366x768.png](capturas_etapa7_1/c4-assignment-edit-1366x768.png) |
| B7 editar mesas | 1024×768 | Igual | Igual | Sí | PASS — [c4-assignment-edit-1024x768.png](capturas_etapa7_1/c4-assignment-edit-1024x768.png) |
| B7 editar mesas | 390×844 | Igual | Igual | Scroll de la página sólo cuando el contenido lo requiere | PASS — [c4-assignment-edit-390x844.png](capturas_etapa7_1/c4-assignment-edit-390x844.png) |
| B10 POS próximo | 1366×768 | Causa específica `TICKET_ABIERTO` | Acción de servicio deshabilitada si hay conflicto | Sí | PASS — [c5-pos-reservacion-proxima-1366x768.png](capturas_etapa7_1/c5-pos-reservacion-proxima-1366x768.png) |
| B10 POS próximo | 1024×768 | Igual | Igual | Sí | PASS — [c5-pos-reservacion-proxima-1024x768.png](capturas_etapa7_1/c5-pos-reservacion-proxima-1024x768.png) |
| B10 POS próximo | 390×844 | Igual | Igual | Scroll sólo si el detalle excede el viewport | PASS — [c5-pos-reservacion-proxima-390x844.png](capturas_etapa7_1/c5-pos-reservacion-proxima-390x844.png) |

La carpeta también contiene variantes del mapa operativo en los tres viewports y las capturas de alta administrativa de más de 12 personas.
