# Reporte final — Etapa 6

Estado: **APROBADA**.

## Entregables

- Shell canónico en `src/js/components/confirmation-modal.js`.
- Estilos compartidos en `src/scss/components/_confirmation-modal.scss`.
- Consumidores migrados en POS, landing, formulario administrativo, operación
  y detalle administrativo.
- Runner HTTP autenticado en
  `scripts/tests/run-etapa6-flujos-autenticados.php`.
- Inventario, compatibilidad retirada y evidencia visual documentados en esta
  carpeta.

## Legibilidad y accesibilidad

| Viewport | Shell | Resultado |
| ---: | --- | --- |
| 1366 × 768 | 760px, padding 32px | APROBADO |
| 1024 × 768 | 655.36px, padding 32px | APROBADO |
| 390 × 844 | 366px, padding 24px, acciones apiladas | APROBADO |

El shell expone diálogo modal etiquetado, foco inicial configurable, trampa de
foco, retorno al disparador, `Escape`, backdrop, estado de carga, `inert` y
restauración del scroll.

## Validación autenticada

| Caso | Resultado |
| --- | --- |
| A1 login admin, vista y API operativa | PASS |
| A2 login POS, vista, CSRF y mapa | PASS |
| A3 ticket 100 desde mesas 7 y 8 | PASS |
| A4 apertura duplicada | `TICKET_ABIERTO`, ticket 100, sin ticket nuevo |
| A5 reservación próxima | PASS |
| A6 ausencia pendiente | Acción `REGISTRAR_AUSENCIA`, no permite iniciar |
| A7 no-show repetido | Idempotente; estado final `no_show` |
| A7b inicio de servicio | Ticket creado para la reservación elegible |
| A8 confirmación sin mesas | Confirmada sin filas en `reservacion_mesas` |
| A9 sobrecapacidad | Primer POST exige decisión; segundo confirma con bandera explícita |
| A10 modificación pública | Sesión OTP, propuesta, confirmación y reemplazo atómico |

Comando reproducible:

```text
php scripts/tests/run-etapa6-flujos-autenticados.php --dynamic
```

Resultado observado:

```text
PASS: flujos autenticados HTTP, CSRF, tickets, ausencia y capacidad de Etapa 6.
```

## Regresiones y build

- `php scripts/tests/run-etapa6-flujos-autenticados.php --static-only`: PASS.
- `php scripts/tests/run-etapa5-capacidad.php --dynamic`: PASS.
- `npm run build`: PASS; sólo permanecen advertencias deprecadas de Sass y
  `fs.Stats` del toolchain.
- `git diff --check`: sin errores introducidos por Etapa 6.

## Fuera de alcance preservado

- No se modificó el DDL.
- No se modificó el motor temporal, la tolerancia ni la fórmula de capacidad.
- No se cambió la semántica de `influye_disponibilidad`.
- La capacidad y la asignación física siguen separadas; la sobrecapacidad sólo
  se confirma con decisión explícita.
