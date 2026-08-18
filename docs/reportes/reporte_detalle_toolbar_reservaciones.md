# Reporte UX/UI — Detalle operativo y toolbar de reservaciones

Fecha: 17 de agosto de 2026
Superficie: mapa operativo de reservaciones
Alcance: corrección final de presentación, sin cambios de negocio

## Objetivo

Cerrar la jerarquía visual del detalle operativo y del toolbar sin rediseñar la superficie ni alterar sus flujos. La ficha debe leerse de arriba hacia abajo —identidad, metadatos, mesas, notas y acciones— y el toolbar debe separar con claridad utilidades, contexto temporal y creación.

## Cambios realizados

### Detalle operativo

- Se conservaron nombre en dorado, estado, hora, personas y mesas.
- La nota del cliente ahora tiene una zona discreta con borde, fondo y padding; el estado vacío permanece legible, atenuado y en cursiva.
- El comentario interno usa la misma legibilidad operativa para texto real. Cuando está vacío muestra "Sin notas internas" junto a "Agregar nota"; el botón conserva un target compacto de 40 px.
- "Más acciones" queda al cierre del flujo con separación real respecto de las notas. El panel puede crecer y desplazarse de forma natural cuando el viewport no alcanza.
- Se conservaron los grupos de acciones y sus permisos; liberar mesas sigue siendo secundario y cancelar sigue siendo destructivo.

### Toolbar

- Desktop: región izquierda para maximizar, actualizar, "Mesas" y disponibilidad; región central real para fecha y hora; región derecha para "Crear reservación" cuando el permiso y el horario lo permiten.
- Maximizar y actualizar no se eliminaron: quedaron como iconos secundarios de 44 px, antes de "Mesas".
- La disponibilidad visible se redujo a "X / Y". El contenedor actualiza aria-label y title con "X de Y lugares disponibles".
- "Mesas" es el único control de esa agrupación que conserva etiqueta visible e interacción principal.
- Tablet usa dos filas limpias y móvil apila izquierda, contexto y acción; fecha/hora pasan a una columna cuando el ancho lo exige.

## Límites respetados

No se modificaron backend, cálculo de capacidad, estados, asignaciones, mesas, tickets, permisos, privacidad, OTP, n8n, endpoints ni acciones. La capacidad continúa usando los valores autoritativos recibidos del servidor.

El toolbar compartido del POS conserva su control de mapa; la restauración de maximizar/actualizar se limita al contexto de reservaciones.

## Archivos principales

- views/operation/reservations/index.php
- src/js/admin/reservations/operation.js
- src/scss/operation/_toolbar.scss
- src/scss/operation/_reservation-detail.scss
- controllers/ReservacionOperacionController.php
- scripts/tests/run-reservaciones-ux-map-contract.php

## Validación

- Contrato UX/UI estático: OK.
- Suite npm test: OK.
- php -l sobre la vista y el contrato: OK.
- Build Gulp completo: OK; se conservaron sólo los bundles específicos de operación y se revirtió la regeneración global no relacionada.
- Detector Impeccable sobre vista y estilos: sin findings.
- Revisión visual interactiva: estado sin selección, nota del cliente vacía, comentario interno poblado, comentario interno vacío, varias mesas, scroll corto y actualización manual.
- El responsive quedó cubierto por las reglas de contenido en 1199, 900, 640 y 480 px; el navegador conectado mantuvo su viewport normal durante la inspección visual, por lo que la verificación exacta por captura en cada dimensión queda como revisión manual complementaria en los cuatro tamaños solicitados.

## Estado

Listo para integrar. Commit sugerido:

style(reservaciones): ajustar detalle y toolbar operativa

No se hizo push.
