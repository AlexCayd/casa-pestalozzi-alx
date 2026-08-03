# Deuda de accesibilidad de la landing

Estas observaciones se registran durante la Etapa 5 y quedan diferidas hasta
después de la reconstrucción de la landing. No forman parte del núcleo de
horarios, ocupación, asignación o disponibilidad.

| Componente | Problema | Evidencia | Impacto | Corrección esperada | Etapa prevista |
| --- | --- | --- | --- | --- | --- |
| Menú principal | El control conserva `aria-label="Abrir menú"` al cerrar y no expone `aria-expanded` ni `aria-controls`. | `views/home/_nav.php` y el script de navegación del menú. | Las tecnologías asistivas no conocen el estado ni la relación con el panel. | Actualizar etiqueta según estado, añadir `aria-expanded` y enlazar el panel con `aria-controls`. | Etapa 11 — Estabilización, accesibilidad y pruebas integrales |
| Lightbox | El contenedor visible no declara `role="dialog"` ni `aria-modal="true"`. | `views/home/_footer.php`, bloque `#lightbox`. | El cambio de contexto modal no se anuncia correctamente. | Añadir semántica de diálogo, nombre accesible, foco inicial y retorno de foco al cierre. | Etapa 11 — Estabilización, accesibilidad y pruebas integrales |
| Galería | Los contenedores interactivos no son focusables y no exponen rol o activación por teclado. | `views/home/_firma.php` y `src/js/modules/gallery.js`. | La galería no es operable de forma equivalente sin ratón. | Usar controles semánticos o añadir foco, rol, nombre accesible y activación por teclado. | Etapa 11 — Estabilización, accesibilidad y pruebas integrales |

No corregir estos puntos durante la Etapa 5: la superficie de la landing será
reconectada posteriormente y sus componentes pueden cambiar.
