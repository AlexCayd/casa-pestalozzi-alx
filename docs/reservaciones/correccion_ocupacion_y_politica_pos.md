# Corrección integral de ocupación y política POS

Fecha de cierre: 2026-08-08  
Rama: `main`  
Base funcional: `fea5924728cd44e315b27066402d32da94689e09`

## Alcance y trazabilidad

La documentación normativa `docs/reservaciones.md` ya tenía cambios de usuario al iniciar el trabajo. Se conservó esa edición y se formalizó como primer commit. No se modificó el esquema ni se agregaron migraciones SQL.

Commits aplicados, en el orden solicitado:

1. `fc7cb5a` — `docs(reservaciones): formalizar ocupacion y politicas operativas`
2. `eb3970e` — `refactor(reservaciones): centralizar hechos derivados de mesas`
3. `e827873` — `fix(pos): unificar disponibilidad y proteccion de reservaciones`
4. `b13a015` — `fix(reservaciones): alinear proyeccion y asignacion manual del mapa`
5. Pendiente de crear con este informe — `test(reservaciones): validar ocupacion y ventanas configurables`

## Contrato implementado

El backend concentra los hechos derivados por mesa y evita que POS, mapa y asignación manual reconstruyan ventanas por separado:

- `ocupada_fisicamente`
- `bloqueada_en_intervalo`
- `disponible_para_asignacion`
- `disponible_para_ticket`
- `requiere_advertencia_ticket`
- `ausencia_pendiente`

`MesaEstadoService` toma la asignabilidad únicamente de `mesa_ids_bloqueadas` proveniente de `OcupacionMesasService`. El serializer sólo proyecta decisiones ya calculadas. `ReservacionPoliticaPosService` centraliza advertencia, bloqueo, inicio exacto, tolerancia, ausencia pendiente, no-show y acción primaria.

La mutación POS `abrirWalkIn` revalida dentro de la transacción. A las 12:00, una primera solicitud en la ventana de advertencia devuelve `REQUIERE_CONFIRMACION` y no crea ticket; la solicitud confirmada vuelve a consultar el estado y crea exactamente un ticket. A las 13:00 el walk-in queda bloqueado incluso por endpoint directo, mientras el servicio de la reservación puede iniciar.

## Matriz verificada para reservación a las 13:00

La prueba `run-reservaciones-mesa-facts.php` cubre las ocho consultas y verifica mapa, POS, bloqueo canónico, ticket y advertencia.

| Consulta | Mapa | POS | Bloqueo de intervalo | Ticket walk-in | Advertencia |
|---|---|---|---:|---:|---:|
| 11:30 | verde/libre | verde/libre | no | sí | no |
| 12:00 | verde con borde azul discontinuo | verde con borde azul discontinuo | sí | sí, con confirmación | sí |
| 12:30 | azul/reservación próxima | azul/reservación próxima | sí | no | no |
| 12:59 | azul/reservación próxima | azul/reservación próxima | sí | no | no |
| 13:00 | rojo/ocupada | rojo de reservación próxima | sí | no | no |
| 13:30 | verde con acción pendiente | verde con acción pendiente | no | no, hasta no-show manual | no |
| 14:00 | verde con acción pendiente | verde con acción pendiente | no | no, hasta no-show manual | no |
| 14:30 | verde con acción pendiente | verde con acción pendiente | no | no, hasta no-show manual | no |

El caso 13:16 se verifica directamente: `ausencia_pendiente=true`, `puede_marcar_no_show=true`, acción primaria `REGISTRAR_AUSENCIA`; una reservación ya marcada manualmente como `no_show` libera la revalidación del walk-in.

## Intervalos y configuración

El motor mantiene el intervalo semiabierto `[hora_consulta, hora_consulta + DURACION_RESERVACION_MINUTOS)` y el traslape `inicio_existente < fin_nuevo && fin_existente > inicio_nuevo`. Las pruebas cubren los bordes de 60, 30 y 0 minutos, además de una duración alternativa de 120 minutos.

La configuración única queda en `services/ReservacionConfig.php`:

- duración productiva: 90 minutos;
- advertencia: 60 minutos;
- bloqueo de walk-in: 30 minutos;
- anticipación de inicio de servicio: 30 minutos;
- tolerancia de llegada: 15 minutos.

La prueba estática comprueba la presencia de las constantes canónicas, la ausencia de los alias ambiguos anteriores y que los consumidores JS no decidan por ventanas locales `0_30`/`30_60`.

## Mapa, POS y asignación manual

El mapa administrativo y POS consumen `ventana_mapa`, `ventana_pos`, modificadores y hechos serializados por backend. La asignación manual de `operation.js` sólo habilita mesas con `disponible_para_asignacion === true` y consulta al backend excluyendo la reservación que se está editando. Los textos de ticket no se usan para resolver conflictos de asignación.

Se recompilaron los artefactos CSS/JS necesarios con `npm run build`. El build terminó correctamente; Sass sólo emitió advertencias de deprecación de su API heredada.

## Validación ejecutada

- `npm run test:php` — OK: catálogo, configuración estática, política POS, presentador, hechos de mesa, paridad capacidad-mapa y contrato administrativo.
- `npm run test:js` — OK: sintaxis y contrato JS.
- `npm run audit:reservaciones` — OK.
- `npm run build` — OK.
- `php -l` sobre los PHP modificados y `node --check` sobre los JS modificados — OK.
- `git diff --check` — sin errores de whitespace.

La validación visual en navegador no pudo ejecutarse: Apache/XAMPP rechazó la conexión y la política del navegador integrado bloqueó también el host local temporal. Se levantó y detuvo un servidor PHP temporal sin tocar datos; el bloqueo quedó documentado en lugar de sortearse.

## Estado de cierre

El quinto commit debe incluir las pruebas nuevas/ajustadas, `package.json` y este informe forzado dentro del repositorio pese a la regla general de ignorar `docs/reservaciones/`.
