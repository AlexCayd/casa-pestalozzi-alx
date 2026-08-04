# Etapa 11.6 — Validación externa final de accesibilidad, teclado y tráfico HTTP

Fecha de prueba: 2026-08-04 12:35 (-06:00), zona `America/Mexico_City`.  
Alcance: validación final disponible sin cambiar reglas funcionales, sin commits y sin iniciar Etapa 12.

## 1. Resumen ejecutivo

La regresión funcional queda verde: A–L `12/12`, versionado `14/14`, Etapa 5 `58/58`, Etapa 6.2 `20/20`, runner, regresiones, lint, contratos JavaScript y dos builds consecutivos.

La validación externa exigida no puede cerrarse completamente en esta sesión. Solo está disponible Codex In-app Browser; no existe instancia Chrome/Edge externa, panel Network accesible ni NVDA. Por honestidad, Network, zoom real al 200 %, recorrido puro de teclado y NVDA quedan como no concluyentes.

No se encontraron defectos reproducibles que justificaran una corrección mínima. No se añadió funcionalidad ni se modificaron estados, tablas, contratos o reglas de dominio.

## 2. Fuente de verdad

Se leyó completamente `reservaciones_fuente_de_verdad.md` en la raíz del repositorio, además de los reportes de Etapas 10, 11 y 11.5. Se conservaron como autoridad el esquema/estados canónicos, `pos-reservacion.v1`, los intervalos de ocupación, holds, OTP, límites de horario, asignación, concurrencia y versionado.

## 3. Entorno

| Elemento | Resultado |
|---|---|
| URL | `http://127.0.0.1:8000` |
| Servidor | Servidor local PHP sobre el workspace XAMPP; smoke read-only. |
| Base automatizada | Bases temporales locales creadas y eliminadas por los wrappers. |
| Base del smoke | `casa-pestalozzi`, solo lectura durante esta etapa. |
| PHP | 8.2.12 |
| MySQL | 9.7.1 |
| Node | v24.12.0 |
| Navegador disponible | Codex In-app Browser; no Chrome/Edge externo conectado. |
| DevTools | Logs de consola y viewport; sin Network API. |
| NVDA | No instalado/conectado a esta sesión; versión no disponible. |
| Viewports | `1440×900`, `1024×768`, `768×900`, `390×844`. |
| Zoom | Default del navegador; 200 % no disponible. |

Los contactos y fixtures mutables de las pruebas anteriores fueron ficticios `.test` y se limpiaron; esta etapa no creó nuevas mutaciones de negocio.

## 4. Network

Resultado: **No validado formalmente**.

El navegador conectado solo ofrece `console logs`, DOM, CUA, Playwright y viewport. No ofrece solicitudes/respuestas, método, ruta, initiator, status, timing, content type, CSRF, requests pendientes ni conteo de polling.

Como smoke HTTP independiente, `GET /`, `GET /build/js/bundle.min.js` y `GET /build/js/admin/map.js` respondieron `200` con content types HTML/JavaScript correctos. Esto confirma servicio local de assets, pero no sustituye la inspección Network solicitada.

No se declaran conteos para las 20 mutaciones ni para polling, ni se marca como probado el criterio de doble POST, 404, 5xx, HTML inesperado en APIs o tokens en URL.

## 5. Doble envío

No se pudo ejecutar la medición formal con throttling Fast 3G ni contar POST desde Network. Los contratos JavaScript `reservation-form-state`, `operation-map-state` y `accessibility-contract` pasan, y las suites de dominio mantienen idempotencia/concurrencia, pero eso no prueba por sí solo el tráfico real de doble clic.

Resultado: **No concluyente** para los dobles clics de OTP, creación, asignación, inicio, no-show y cierre.

## 6. Zoom real al 200 %

No existe control de zoom en el navegador conectado. `Ctrl +` no produjo una medición real de 200 % y no se usó transformación CSS ni `visualViewport.scale` como sustituto.

El baseline de reflow sin zoom sí pasó en las tres superficies:

| Superficie | `1440×900` | `1024×768` | `768×900` | `390×844` |
|---|---:|---:|---:|---:|
| Landing | PASS | PASS | PASS | PASS |
| Mapa administrativo | PASS | PASS | PASS | PASS |
| POS | PASS | PASS | PASS | PASS |

En los 12 checks no hubo overflow horizontal, error de aplicación ni pérdida de ruta. No se marca operabilidad al 200 % como PASS.

## 7. Teclado

Se inspeccionó el DOM accesible y se intentó el recorrido real con `Tab`/`TAB` en la página pública. La API CUA no avanzó `document.activeElement` de forma nativa; incluso después de enfocar el skip link, el foco permaneció en el objetivo `main`. Por eso no se puede declarar que los flujos críticos fueron completados sin mouse.

Sí pasó la evidencia estática/contractual de skip link, `main`, headings, diálogos, formularios, mapa, foco inicial y ausencia de `tabindex` positivo. También se confirmó que el mapa tiene alternativa estructurada en el DOM.

Resultado: **No concluyente** para los recorridos públicos, administrativos, POS y no-show exclusivamente con teclado.

## 8. NVDA

NVDA no está instalado ni conectado a la sesión. No se ejecutaron Speech Viewer, navegación por headings/landmarks, lectura de labels/errores, anuncios de modales, estados de mesa, capacidad, POS ni restauración de foco con lector real.

Resultado: **No validado**.

## 9. Live regions

El contrato estático de accesibilidad pasa y el código conserva regiones `aria-live` para estados operativos. No se pudo observar con NVDA si los mensajes se anuncian una sola vez, mantienen prioridad, evitan duplicación durante polling o anuncian correctamente sesión expirada, errores de versión, OTP, mesas y cierre.

Resultado: **Parcial**: estructura presente y contrato estático PASS; anuncio real no comprobado.

## 10. Contraste

No hubo cambios visuales en esta etapa, por lo que se conserva la evidencia válida de Etapa 11.5. Mediciones computadas con luminancia WCAG:

- texto principal: `15.49:1`;
- acento/dorado: `7.99:1`;
- texto de inputs de reservación: `18.82:1`;
- sección clara: `16.15:1`.

Las muestras críticas superan WCAG AA. Focus ring, disabled, estados del mapa, errores y tema oscuro no se volvieron a medir formalmente después de una corrección porque no hubo correcciones.

## 11. Flujos autenticados

El smoke local final cargó sin error de aplicación la landing, `/admin/login` (sesión de prueba existente, redirigió a `/admin`), `/punto-de-venta` y `/area/cocina`; consola con `0` warnings/errors.

La evidencia end-to-end de mutaciones pública, administrativa y POS continúa siendo la validada en Etapa 11.5: hold/OTP/confirmación/modificación/cancelación, alta con advertencias, asignación/reasignación y POS con producto/cocina/entrega/pago/cierre/liberación.

En Etapa 11.6 no se repitieron esas mutaciones porque el entorno externo obligatorio para conservar Network abierto y usar NVDA no está disponible. No se realizaron nuevas mutaciones sobre la base principal.

No-show, integración POS y contratos de dominio sí se repitieron mediante suites limpias automatizadas.

## 12. Consola

El smoke de las cuatro superficies locales no reportó warnings ni errores (`0`). No aparecieron fatal errors, stack traces ni errores de aplicación en el DOM.

Los builds emiten únicamente advertencias conocidas de Dart Sass legacy API y `fs.Stats`; no son errores de runtime ni de compilación.

## 13. Correcciones realizadas

No se realizaron correcciones en Etapa 11.6: no hubo defecto reproducible y no se cambió código de dominio, accesibilidad, polling, requests, contratos o esquema.

No se añadieron pruebas específicas de Etapa 11.6 porque no hubo un defecto real que automatizar. El working tree conserva las modificaciones de Etapa 11.5 y este reporte es el único artefacto nuevo de esta etapa.

## 14. Concurrencia y versionado

Las suites finales permanecen verdes:

- A–L: `12/12 PASS`.
- Versionado de asignaciones: `14/14 PASS`.
- Etapa 5: `58/58 PASS`.
- Etapa 6.2: `20/20 PASS`.
- Contrato `pos-reservacion.v1`: PASS y sin cambios.

Las instalaciones temporales se eliminaron mediante `finally`/`dropped=true`.

## 15. Build

Se ejecutó `npm.cmd run build` dos veces consecutivas; ambas terminaron con exit code `0`. Se conservaron solo las advertencias deprecatorias conocidas.

## 16. Runner e instalación limpia

Pasaron:

```text
php scripts/run-tests.php
npm.cmd test
php tests/php/etapa11_5_instalacion_limpia.php
```

El runner usa únicamente MySQL local temporal. La instalación limpia de Etapa 11.5 volvió a pasar A–L, versionado y Etapa 6.2, eliminando la base temporal.

## 17. Regresiones

Pasaron las instalaciones limpias de Etapas 5, 7.5, 8, 9.5, 10 y 11.5; la instalación 9.5 incluyó contrato POS, integración POS y concurrencia. También pasaron:

- `npm.cmd run test:js`;
- 202 archivos PHP sin errores de `php -l`;
- 47 archivos JavaScript/Gulp sin errores de `node --check`;
- `git diff --check` con exit code `0`.

El contrato POS, esquema y reglas funcionales no cambiaron.

## 18. Archivos modificados

En Etapa 11.6 no se modificaron archivos de aplicación. Se añadió únicamente:

```text
docs/reports/2026-08-04_etapa11_6_validacion_externa_final.md
```

El working tree también contiene los archivos y cambios de Etapa 11.5 documentados en su reporte; no se hizo reset, commit ni limpieza destructiva.

## 19. Limitaciones

Limitaciones externas reales:

1. No hay Chrome/Edge externo conectado.
2. El navegador integrado no expone DevTools Network.
3. No hay control de zoom real al 200 %.
4. La API de teclado no reproduce avance Tab nativo de forma confiable.
5. No hay NVDA ni Speech Viewer.

Estas limitaciones impiden certificar tráfico, zoom, teclado puro y lector de pantalla; no se sustituyeron con simulaciones ni inferencias.

## 20. Riesgos pendientes

1. **Alto:** no se puede probar formalmente una mutación HTTP por acción, polling único, requests pendientes, CSRF, 404/5xx o exposición de tokens.
2. **Alto:** no se puede cerrar accesibilidad completa al 200 % ni con NVDA real.
3. **Alto:** no se puede certificar que los flujos críticos terminen solo con teclado en un navegador real.
4. **Medio:** los flujos autenticados de mutación no se repitieron en esta etapa con Network y NVDA activos; conservan la evidencia de Etapa 11.5.
5. **Bajo:** Sass legacy API y `fs.Stats` continúan con warnings deprecatorios durante build.

## 21. Decisión final

**¿Network quedó validado?** No. Solo se validó smoke HTTP local y consola; falta DevTools Network externo.

**¿El módulo es operable al 200 % de zoom?** No concluyente. Los viewports base pasan, pero el zoom real no está disponible.

**¿Los flujos críticos son operables solo con teclado?** No concluyente. El contrato estático pasa, pero el recorrido nativo no pudo ejecutarse de forma fiable.

**¿La validación con NVDA es satisfactoria?** No. NVDA no está disponible.

**¿Es seguro iniciar la Etapa 12?** No todavía. No se inicia automáticamente; primero se requiere ejecutar esta validación en Chrome/Edge externo con DevTools Network y NVDA, y cerrar los riesgos alto/medio.
