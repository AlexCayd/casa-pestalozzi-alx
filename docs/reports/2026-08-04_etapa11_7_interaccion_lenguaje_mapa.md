# Etapa 11.7 — Cierre de interacción, lenguaje operativo y modos del mapa

Fecha: 2026-08-04  
Repositorio: `C:\xampp\htdocs\casa-pestalozzi`  
Estado: implementada y validada localmente; sin commit.

## 1. Resumen

Se coordinó el rail público con los selectores de fecha/hora, se eliminó el segundo OTP de modificación pública, se ajustó el lenguaje operativo POS, se aisló `reemplazada` de proyecciones operativas, se corrigió la hora inicial del mapa y se hizo explícito el modo de asignación.

## 2. Informe obligatorio

Este documento es el informe obligatorio de Etapa 11.7: `docs/reports/2026-08-04_etapa11_7_interaccion_lenguaje_mapa.md`.

## 3. Fuente de verdad

Se actualizó primero `reservaciones_fuente_de_verdad.md` con el anexo aprobado de Etapa 11.7. La implementación conserva los estados, contratos, locks, transacciones y permisos existentes; no agrega migraciones, tablas, columnas ni estados nuevos.

## 4. Rail y selectores

El rail usa navegación nativa como fallback, mantiene `aria-current="location"` y coordina el scroll con la navegación principal. Los selectores de fecha y hora comparten un coordinador: sólo un popover puede estar abierto, el anterior se cierra al abrir otro, Escape cierra y devuelve el foco, y el clic exterior cierra sin duplicar listeners.

## 5. Modificación pública

La sesión de contacto ya verificada autoriza la modificación; no se solicita un segundo OTP. La creación del reemplazo conserva CSRF, token de operación, sesión, hold, disponibilidad, locks, transacción e idempotencia. La reservación original permanece `confirmada` hasta la confirmación final, que sólo envía `request_token` y CSRF. La ruta heredada de reenvío quedó como compatibilidad sin emitir OTP.

## 6. Modal POS de 60–30

El aviso usa el título `Hay una reservación próxima`, informa mesa, hora, nombre cuando existe, comensales, minutos restantes y la consecuencia operativa en lenguaje humano. Se conservaron los botones `Volver a la selección` y `Abrir ticket de todas formas`; no se muestran códigos técnicos.

## 7. Reservación reemplazada y etiqueta de versión

El estado canónico interno sigue siendo `reemplazada`. En historial y administración se presenta como `Versión anterior`. Se excluye de POS, ocupación, warnings, selección operativa y proyección visual del mapa; la nueva versión `confirmada` sí permanece visible y operativa.

## 8. Hora inicial del mapa

El mapa consulta bloques configurados sin aplicar la anticipación mínima de creación. Para el día actual inicia en el último bloque configurado menor o igual a la hora actual; en la validación local resolvió `14:30`. La creación de nuevas reservaciones conserva la regla independiente de ahora + 40 minutos. La API expone `horarios_mapa` además de `horarios` reservables.

## 9. Modo explícito de asignación

Fuera de `assignmentMode`, los pines del mapa sólo informan y no mutan la selección. El mensaje indica que se debe elegir una reservación y pulsar `Cambiar mesas`. Dentro del modo explícito se mantienen snapshot, versión, cancelación, revalidación de conflicto y commit existentes.

## 10. Mapa visual

Se simplificó la leyenda sin crear colores nuevos: verde disponible, rojo ocupada, amarillo selección actual, azul reservación próxima o mesa comprometida según contexto y neutro no utilizable. La reservación reemplazada no altera la ocupación ni genera pin operativo.

## 11. Pruebas, concurrencia y build

- `npm.cmd test`: PASS; PHP runner Etapa 5 y Etapa 11.5 en instalación limpia, más los tres contratos JS.
- Regresiones limpias Etapas 5, 7.5, 8, 9.5, 10 y 11.5: PASS, con limpieza de fixtures.
- Concurrencia pública/POS y de reemplazo cruzado: PASS en las suites existentes.
- Lint completo local: 202 archivos PHP; sintaxis Node: 51 archivos.
- `npm.cmd run build`: PASS en dos ejecuciones consecutivas. Persisten sólo avisos conocidos de la API legacy de Sass y `fs.Stats`.
- `git diff --check`: sin errores; Git sólo reportó avisos de conversión LF/CRLF.
- Smoke funcional directo de `operationData()`: PASS; devolvió `horarios_mapa` completo, hora inicial vigente y `en_proyeccion_mapa=false` para una reservación `reemplazada`.

## 12. Riesgos y límites

La validación anterior del navegador integrado no pudo alcanzar el servidor temporal del entorno (`ERR_CONNECTION_REFUSED`). En la corrección 11.7.1 el navegador integrado sí alcanzó `http://127.0.0.1:8000/` para la pasada desktop de anchors, menú y lightbox. No se marca como PASS una emulación móvil independiente, zoom 200 %, lector NVDA ni inspección de Network porque esta superficie no expone viewport móvil, lector ni panel de tráfico. La advertencia POS conserva la duración ya existente del contrato; no se inventa un algoritmo nuevo.

## 13. Decisión para reanudar Etapa 11.6

Etapa 11.7 queda lista para revisión. Se recomienda reanudar Etapa 11.6 con una pasada externa final sobre teclado real, zoom 200 %, lector de pantalla y tráfico HTTP una vez disponible el servidor/navegador externo; después de esa pasada puede cerrarse la validación externa final.

## Corrección 11.7.1 — Rail y modificación pública

1. **Causa raíz del rail.** `views/home/_nav.php` renderizaba el rail visible con `aria-hidden="true" inert`; por eso sus anchors no podían recibir foco ni activarse. Además, `src/js/modules/rail.js` cancelaba el click sin actualizar el hash y `src/js/modules/lenis.js` no compensaba los controles fijos.
2. **Archivos corregidos.** Se corrigieron `views/home/_nav.php`, `src/js/modules/rail.js`, `src/js/modules/lenis.js`, el template de `views/home/_reserva.php`, `src/js/modules/reservation-access.js`, `services/ReservacionPublicaService.php`, `src/scss/components/_reserva.scss`, la versión de assets en `views/home/index.php` y los contratos de prueba.
3. **Navegación real.** En el navegador integrado se probaron anchors del rail con mouse y Space; se verificó `href="#..."`, actualización de hash, `aria-current="location"` y ausencia de `inert`/`aria-hidden` en el rail. Enter queda cubierto por el comportamiento nativo del anchor y el listener de Space evita el desplazamiento de página.
4. **Menú, overlays y foco.** Se verificó menú cerrado/abierto, transición de `aria-expanded` y `aria-hidden`, retirada/restauración de `inert`, Escape y retorno de foco a `#navToggle`. También se abrió y cerró el lightbox, comprobando `aria-hidden`, `inert` y restauración al botón de origen. No se agregaron listeners duplicados.
5. **Causa raíz del error de modificación.** El backend respondía correctamente `ok=true` con `REEMPLAZO_CREADO`, pero `showEditorComparison()` llamaba a `fechaLegible()` inexistente en `reservation-access.js`. El `ReferenceError` caía en el `catch` y se presentaba como error genérico después de abrir parcialmente la revisión.
6. **Contrato de creación/recuperación.** `POST /api/reservaciones/modificar` conserva sesión verificada, CSRF y `request_token`; entrega `request_token`, hold, `original`, `propuesta` y el alias compatible `replacement`, sin datos sensibles ni OTP. La respuesta idempotente recupera el mismo contrato.
7. **Contrato de confirmación.** `POST /api/reservaciones/confirmar-modificacion` envía exclusivamente `request_token` y `csrf_token`; la sesión es la identidad. La transacción deja la original `reemplazada` y la propuesta `confirmada` sólo al confirmar; antes de eso la original sigue `confirmada`.
8. **Lenguaje y botones.** El editor usa `Aceptar` y `Cancelar`. La revisión usa `Volver a editar` y `Confirmar modificación`; el éxito se comunica como `Tu reservación fue modificada.`
9. **Resumen visual.** La revisión muestra actual/nueva para fecha, hora, personas e indicaciones, resalta sólo renglones cambiados y muestra `Tu reservación actual seguirá vigente hasta que confirmes este cambio.` junto con `Esta disponibilidad se conservará durante 15 minutos.` No muestra timestamps técnicos.
10. **Volver a editar.** Cierra la revisión, conserva los valores editados, limpia el token de la operación, devuelve el foco a `Aceptar` y no ejecuta POST ni crea automáticamente otro reemplazo.
11. **Estados de frontend.** Se documentan y reflejan `editing`, `creating_replacement`, `reviewing`, `confirming`, `success` y `error`; no se agregaron estados de base de datos.
12. **Errores operativos.** Se traducen sesión expirada, disponibilidad agotada, hold vencido, límite de tiempo, conflicto de token, reservación ajena y error inesperado; se conserva la original cuando el cambio no puede continuar.
13. **OTP heredado.** El OTP inicial de acceso y su reenvío siguen activos. `reenviarOtpModificacion` permanece sólo como compatibilidad y no es invocado por el nuevo editor; la modificación no crea un segundo OTP.
14. **Pruebas funcionales e idempotencia.** `npm.cmd test` pasa; `tests/php/etapa7_publica.php` pasa 29/29, incluyendo `original`, `propuesta`, hold de 15 minutos, ausencia de segundo OTP y repetición idempotente; la instalación limpia 11.5 mantiene concurrencia, versionado y capacidad.
15. **Build y validación de assets.** `npm.cmd run build` pasa tras reintento por un error transitorio de apertura de sourcemap en una tarea paralela; se regeneraron CSS/JS y se subió el cache-buster a `v17`/`v20`. Persisten sólo avisos conocidos de Sass legacy API y `fs.Stats`.
16. **Riesgo y decisión.** La pasada desktop real queda validada; la pasada móvil independiente y Network requieren un navegador externo con control de viewport/inspector. No se modificaron esquema, algoritmo de disponibilidad, POS, mapa, administración, permisos ni se creó commit. La decisión es no reanudar Etapa 11.6 todavía: primero debe ejecutarse esa validación externa final.
