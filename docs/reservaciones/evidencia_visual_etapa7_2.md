# Evidencia visual — Etapa 7.2

## Capturas

| Flujo | Resolución | Evidencia |
| --- | ---: | --- |
| Creación desde mapa | 390 × 844 | [creacion-mapa-390x844.png](capturas_etapa7_2/creacion-mapa-390x844.png) |
| Modo de selección de mesas | 1024 × 768 | [asignacion-dos-acciones-1024x768.png](capturas_etapa7_2/asignacion-dos-acciones-1024x768.png) |
| Advertencia POS por reservación próxima | 1366 × 768 | [pos-advertencia-1366x768.png](capturas_etapa7_2/pos-advertencia-1366x768.png) |

## Observaciones verificadas

- La creación desde el mapa abre el formulario operativo y permite continuar sin contacto; el formulario inicia con `SIN CONTACTO (Opcional)`.
- La barra visible del modo de selección contiene únicamente `Cancelar` y `Guardar asignación`. Después de una creación que requiere asignación manual, la primera acción cambia a `Asignar más tarde`, manteniendo las dos acciones únicas.
- En POS, una mesa con reservación próxima muestra `Hay una reservación próxima`, `Volver` y `Abrir ticket de todas formas`, con hora, comensales y minutos restantes.
- La acción `Volver` cierra la advertencia y conserva la selección de mesas para que el operador pueda corregirla.

## Método

Las capturas se obtuvieron desde el navegador local autenticado contra `http://127.0.0.1:8000`. La fixture de prueba `POS Etapa72 #1111`, utilizada para la evidencia POS y de asignación, fue eliminada de la base activa después de la verificación.
