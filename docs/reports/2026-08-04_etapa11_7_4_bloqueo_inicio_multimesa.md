# Etapa 11.7.4 — Explicación de bloqueo al iniciar reservaciones multimesa

Fecha: 2026-08-04  
Estado: cerrada con verificación automática y manual acotada  
Commit: no creado, conforme a la instrucción de la etapa.

## 1. Resumen

Se corrigió la diferencia entre la decisión de backend y la explicación visible en el modal operativo compartido. Una reservación multimesa sólo puede iniciar si todas sus mesas asignadas son válidas y están disponibles. El backend ahora conserva la decisión atómica y entrega el detalle seguro de cada mesa bloqueante; el POS muestra esa decisión dentro del mismo modal y mantiene visible el botón real `Iniciar servicio` en estado deshabilitado.

No se modificó `pos-reservacion.v1`, el esquema de base de datos, los estados de reservación ni la asignación automática/manual.

## 2. Fuente de verdad

Se añadió en `reservaciones_fuente_de_verdad.md`, dentro de la apertura de ticket:

> Cuando una reservación utiliza varias mesas, su inicio es atómico: todas las mesas asignadas deben estar disponibles y ser válidas. Si una o más mesas bloquean el inicio, el modal de la reservación debe mostrar la causa, identificar las mesas afectadas y mantener deshabilitada la acción Iniciar servicio. No se permite iniciar la reservación parcialmente.

También quedó explícito que la transacción no crea tickets con un subconjunto libre, no desasigna mesas en conflicto y no reasigna automáticamente.

## 3. Backend y contrato

`PosReservacionQueryService` ya no reduce el conflicto a un booleano. Consulta el contexto completo de mesas para identificar también asignaciones inactivas o no utilizables y delega el detalle seguro a `PosReservacionSerializer::bloqueosOperativos()`.

El contrato mantiene sus campos existentes y agrega:

```json
{
  "puede_iniciar": false,
  "motivo_bloqueo": "MESAS_ASIGNADAS_NO_DISPONIBLES",
  "mensaje_bloqueo": "No se puede iniciar el servicio porque una de las mesas asignadas no está disponible.",
  "accion_sugerida": "Cierra o mueve ese servicio antes de iniciar la reservación.",
  "mesas_bloqueantes": [
    {
      "mesa_id": 8,
      "numero": "8",
      "motivo": "TICKET_ABIERTO",
      "descripcion": "Mesa 8 tiene un ticket abierto.",
      "accion_sugerida": "Cierra o mueve ese servicio antes de iniciar la reservación."
    }
  ]
}
```

Los motivos implementados distinguen `TICKET_ABIERTO`, `OTRA_OPERACION`, `MESA_NO_UTILIZABLE` y `CONFLICTO_ASIGNACION`. No se exponen ids de tickets, identidad de otras reservaciones ni datos técnicos.

La ruta transaccional de `PuntoVentaReservacionService::comenzar()` sigue bloqueando la reservación y todas las mesas en orden ascendente, revalidando la ocupación, creando un único ticket, copiando todas las mesas a `ticket_mesas` y cambiando la reservación a `en_curso`. En un conflicto, el rollback devuelve el detalle por mesa; nunca hay inicio parcial.

## 4. Modal operativo

El modal compartido conserva el mismo resumen de reservación y agrega, cuando corresponde:

- Encabezado: `No se puede iniciar el servicio`.
- Explicación multimesa: `Esta reservación utiliza varias mesas y todas deben estar disponibles para iniciar.`
- Lista de mesas afectadas, causa específica y acción sugerida.
- Botón `Iniciar servicio` visible con el atributo HTML `disabled` real.

La elegibilidad sigue dependiendo de la respuesta canónica del backend; no se añadió una regla independiente en JavaScript ni un segundo modal.

## 5. Polling y concurrencia de lectura

Mientras el modal de reservación permanece abierto, una respuesta nueva vuelve a serializar su contenido con la reservación canónica actual. Si el bloqueo se libera, el mensaje desaparece y el botón sólo se habilita con esa respuesta fresca. Si aparece un nuevo bloqueo, se muestra su nueva causa.

Se conserva la secuencia `dataRequestSequence` y `AbortController` existentes para que una respuesta vieja no reemplace una lectura más nueva. Cuando una reservación ya pasó a `en_curso`, el modal reconoce el ticket vinculado y no ofrece una segunda apertura.

## 6. Accesibilidad

- El detalle de bloqueo usa `role="alert"` y `aria-live="polite"`.
- El botón deshabilitado se relaciona con el detalle mediante `aria-describedby`.
- Se conserva `aria-modal`, el foco inicial, la trampa de Tab/Shift+Tab, Escape y la restauración del foco.
- El bloqueo no depende únicamente de `aria-disabled`.

## 7. Pruebas

Se incorporaron comprobaciones de instalación limpia para:

- Dos mesas libres: contrato habilitable.
- Una mesa con ticket abierto: sólo esa mesa aparece como bloqueante.
- Dos bloqueos simultáneos: cada mesa conserva su motivo.
- Mesa inactiva: `MESA_NO_UTILIZABLE`.
- Mensaje final, alias de compatibilidad y no exposición de datos sensibles.

Regresiones ejecutadas correctamente:

```text
php scripts/run-tests.php
npm.cmd test
npm.cmd run test:js
git diff --check
```

El runner PHP pasó las instalaciones temporales de Etapa 5, Etapa 11.5 y Etapa 11.7.2; dentro de ellas pasaron concurrencia, versionado, integración POS y las nuevas aserciones del contrato multimesa.

## 8. Verificación manual

Se levantó temporalmente el POS en `http://127.0.0.1:8000/punto-de-venta` porque XAMPP no tenía servidor HTTP escuchando. El dataset local mostró la reservación multimesa 1081 sobre Mesa 6 y Mesa 7; ambas estaban disponibles, por lo que el resultado correcto fue el modal de resumen con `Iniciar servicio` habilitado. Se verificó además que:

- El resumen muestra las dos mesas.
- Escape cierra el modal.
- No hubo errores ni advertencias de consola.
- A viewport equivalente a 200% (`640×360`) el modal conserva el ancho y utiliza desplazamiento vertical interno.

No se fabricó ni se mutó una reservación local para provocar un conflicto artificial en una de sus mesas. Por ello, el caso visual de una reserva multimesa bloqueada y su desbloqueo en vivo queda cubierto por el contrato/pruebas automatizadas, pero no por una reproducción manual completa sobre el dataset actual.

## 9. Build e instalación limpia

```text
npm.cmd run build   # PASS
npm.cmd run build   # PASS
```

Los dos builds terminaron correctamente. Permanecen advertencias deprecadas de la API legacy de Sass y `fs.Stats` de Node, sin fallo de compilación.

La instalación limpia temporal de PHP creó, ejecutó DDL/DML, ejecutó las regresiones y eliminó sus bases temporales correctamente.

## 10. Archivos principales

- `reservaciones_fuente_de_verdad.md`
- `services/PosReservacionSerializer.php`
- `services/PosReservacionQueryService.php`
- `services/PuntoVentaReservacionService.php`
- `controllers/PuntoVentaController.php`
- `src/js/modules/punto-de-venta.js`
- `src/scss/punto-de-venta/_punto-de-venta.scss`
- `tests/js/multitable-blocking.test.js`
- `tests/php/etapa11_7_2_instalacion_limpia.php`
- `package.json`

## 11. Riesgos pendientes

- Falta repetir manualmente la transición bloqueada → liberada con una reservación multimesa real en un dataset controlado, sin tocar datos operativos del usuario.
- Sass y Node emiten advertencias deprecadas durante el build; no son regresiones de esta etapa.
- El worktree conserva cambios de etapas anteriores y no se generó commit.

## 12. Decisión

Etapa 11.7.4 cerrada. No se inicia la auditoría de conformidad ni la Etapa 12; requieren autorización explícita posterior.
