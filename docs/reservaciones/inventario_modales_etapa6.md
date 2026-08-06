# Inventario de modales — Etapa 6

Fecha de validación: 2026-08-05.

El único shell de confirmación activo es `ConfirmationModal`, expuesto como
`window.ConfirmationModal` y definido en
`src/js/components/confirmation-modal.js`. Los consumidores sólo entregan
contenido y callbacks; no pueden redefinir el ancho del shell.

| Caso | Superficie | Componente | SCSS | JS consumidor | Ancho actual | Clasificación | Shell compartido |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Abrir ticket walk-in | POS / mapa | `.mesa-modal` como contenedor de selección | estilos POS existentes | `src/js/modules/punto-de-venta.js` | Contenedor funcional | CONTENEDOR_NO_MODAL | No aplica: no es confirmación; el backend decide `TICKET_ABIERTO` |
| Ticket abierto existente | POS / mapa | `ConfirmationModal` | `_confirmation-modal.scss` | `punto-de-venta.js` | `clamp(560px, 64vw, 760px)` | SHELL_CANONICO | Sí |
| Reservación próxima | POS / mapa | `ConfirmationModal` | `_confirmation-modal.scss` | `punto-de-venta.js` | Canónico | SHELL_CANONICO | Sí |
| Iniciar servicio | POS | `ConfirmationModal` para advertencias; aviso de error para respuestas técnicas | `_confirmation-modal.scss` | `punto-de-venta.js` | Canónico | VARIANTE_DEL_SHELL | Sí cuando requiere decisión |
| Registrar ausencia / no-show | POS | `ConfirmationModal` | `_confirmation-modal.scss` | `punto-de-venta.js` | Canónico | SHELL_CANONICO | Sí |
| Confirmar sin mesas | Administración / alta | `ConfirmationModal` | `_confirmation-modal.scss` | `src/js/admin/reservations/form.js` | Canónico | SHELL_CANONICO | Sí |
| Confirmar sobrecapacidad | Administración / alta | `ConfirmationModal` | `_confirmation-modal.scss` | `src/js/admin/reservations/form.js` | Canónico | VARIANTE_DEL_SHELL | Sí; variante de advertencia y decisión explícita |
| Cancelar reservación administrativa | Operación / detalle | `ConfirmationModal` | `_confirmation-modal.scss` | `src/js/admin/reservations/operation.js`, `form.js` | Canónico | VARIANTE_DEL_SHELL | Sí; incluye campo de motivo |
| Cancelación pública | Landing / acceso de contacto | `ConfirmationModal` | `_confirmation-modal.scss` | `src/js/modules/reservation-access.js` | Canónico | SHELL_CANONICO | Sí |
| Revisión de modificación pública | Landing / acceso de contacto | `ConfirmationModal` con `summaryRows` | `_confirmation-modal.scss` | `reservation-access.js` | Canónico; columnas se apilan en móvil | VARIANTE_DEL_SHELL | Sí |
| Conflicto de asignación | Operación / mapa | `ConfirmationModal` | `_confirmation-modal.scss` | `src/js/admin/reservations/operation.js`, `form.js` | Canónico | VARIANTE_DEL_SHELL | Sí |
| Reasignación / liberar asignación | Operación / detalle | `ConfirmationModal` | `_confirmation-modal.scss` | `operation.js`, `form.js` | Canónico | VARIANTE_DEL_SHELL | Sí |
| Cierre de ticket | POS / cobro | `.mesa-modal` multi-paso | estilos POS de cobro | `punto-de-venta.js` | Contenedor funcional | CONTENEDOR_NO_MODAL | No es confirmación aislada: es un wizard de método de pago, monto y división |
| Formulario de reservación operativa | Administración | formulario persistente | `_create-modal.scss` y layout de operación | `reservation-operation.js` | Contenedor funcional | CONTENEDOR_NO_MODAL | No es confirmación |

## Slots usados

Los casos de decisión usan causa (`eyebrow`, `title`, `description`), resumen,
advertencia o consecuencia y dos acciones. La comparación pública usa las
columnas `Actual` y `Nueva`. El caso de cancelación administrativa monta el
motivo dentro del slot de contenido personalizado sin crear otro shell.

## Shells retirados

- `CPConfirmationModal` y su alias ejecutable fueron eliminados.
- Se retiró el overlay nativo de confirmación de la operación.
- Se retiró el diálogo estático de acciones del detalle administrativo.
- Se retiró el bloque comparativo público autónomo; ahora vive dentro del
  `ConfirmationModal` mediante `summaryRows`.

El detalle de compatibilidad está en
`docs/reservaciones/compatibilidad_retirada_etapa6.md`.
