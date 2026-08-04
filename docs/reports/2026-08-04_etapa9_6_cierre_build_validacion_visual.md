# Etapa 9.6 — Cierre ambiental, build reproducible y validación visual operativa

Fecha de cierre: 2026-08-04  
Repositorio: `C:\xampp\htdocs\casa-pestalozzi`  
Alcance: estabilización ambiental, build, contratos de fecha/retención, tema, consola/red, validación visual y regresiones. No se agregó funcionalidad de reservaciones ni se modificó el esquema.

## 1. Resumen ejecutivo

Etapa 9.6 queda cerrada con condiciones documentadas. El build oficial pasó dos veces consecutivas con código 0, los 12 sourcemaps finales son JSON válidos y consistentes, y la matriz final de regresiones pasó 17/17 scripts.

Se corrigieron dos fixtures de prueba stale de horizonte/retención y se corrigió el orden de generación del sourcemap del bundle global. La operación del mapa se mantiene en tema oscuro oficial, sin introducir un tercer modo.

La validación visual oscura pasó en 1440×900, 1024×768, 768×900 y 390×844; la consola no reportó errores ni warnings en la navegación normal y en el cambio de fecha. La red local no mostró 404 en los assets servidos; la inspección de Network del navegador no estuvo disponible como panel independiente.

## 2. Fuente de verdad y reportes revisados

Se revisaron `reservaciones_fuente_de_verdad.md`, el reporte de Etapa 8, `docs/reports/2026-08-03_etapa9_asignacion_manual_mapa_compartido.md` y `docs/reports/2026-08-03_etapa9_5_estabilizacion_mapa_concurrencia.md`.

La fuente confirma que el horizonte público es de 0 a 90 días, que los holds sólo influyen mientras están vigentes y que una retención vencida pasa a `expirada`, libera disponibilidad e invalida sus verificaciones.

## 3. Ambiente auditado

- PHP CLI 8.2.12 x64.
- Node.js v24.12.0.
- npm 11.6.2.
- Composer 2.10.1.
- MySQL local activo; Apache/XAMPP no estaba escuchando en el puerto 80.
- Se usó PHP built-in server con router temporal para la inspección visual; quedó eliminado al terminar.
- Los procesos Node restantes pertenecen al runtime CUA de Codex, no a watchers del proyecto.

No se agregó una exclusión permanente de Microsoft Defender. El cierre no depende de esa excepción.

## 4. ACL, propietario y residuos ambientales

Se auditaron `public/build` y `assets`: 143 archivos tienen propietario `Leo_PC\leona` y 6 `Leo_PC\CodexSandboxOffline`; ninguno quedó marcado como read-only. Los archivos con propietario offline son artefactos generados o preexistentes y pudieron abrirse/escribirse durante los builds finales.

Se intentó reactivar herencia ACL con `icacls` en las dos raíces; Windows devolvió `Access is denied` para parte de los artefactos, por lo que no se afirma una normalización completa de ACL. No obstante, los dos builds finales escribieron correctamente todos sus destinos.

Se eliminaron el router, logs, sesiones PHP y archivos temporales de la validación. No quedan `.codex*`, `sess_*`, `.tmp`, `.map.tmp` ni `.part` en el workspace.

## 5. Comando oficial y builds reproducibles

El comando oficial definido en `package.json` es `npm run build`, que ejecuta `gulp build` y recorre la secuencia global de CSS, JS, imágenes, fuentes y assets.

En PowerShell, `npm run build` fue bloqueado por la política local sobre `npm.ps1`; se ejecutó el equivalente directo `npm.cmd run build` sin alterar la política del equipo.

Resultados finales consecutivos:

1. `npm.cmd run build`: PASS, 3.05 s, código 0.
2. `npm.cmd run build`: PASS, 3.13 s, código 0.

No hubo `EPERM`, `UNKNOWN`, truncamientos ni archivos temporales. Sólo permanecen los avisos conocidos de Sass legacy API y `fs.Stats` de Node.

## 6. Corrección de sourcemap del bundle global

La tarea `javascript()` generaba el bundle como `bundle.min.js`, pero escribía el mapa antes del rename; el código apuntaba a `bundle.js.map` mientras el archivo resultante era `bundle.js.min.map`.

Se movió el rename antes de `sourcemaps.write('.')`. El resultado estable ahora es `bundle.min.js` + `bundle.min.js.map`, tanto en `public/build/js` como en `assets/js`. Los mapas huérfanos `bundle.js.min.map` fueron retirados.

## 7. Validación de mapas y referencias

La auditoría final encontró 12 sourcemaps entre `public/build` y `assets`:

- 12/12 parsean como JSON.
- 12/12 tienen `file` apuntando a un asset existente.
- 12/12 tienen `sourceMappingURL` coincidente con el nombre real del mapa.
- 0 archivos temporales o mapas truncados.

También se verificó la sintaxis de los JavaScript de `src/js` y `tests/js` con `node --check`.

## 8. Horizonte de fechas

Con reloj de prueba `2026-11-01 12:00:00`, el límite de +90 días es `2027-01-30` y se acepta inclusivamente. La prueba de horizonte asegura apertura para el día de fixture antes de evaluar la fecha, evitando que un sábado cerrado produzca un falso negativo.

El día +91 se rechaza. No se modificó el servicio de producción: el comportamiento ya era correcto y el ajuste fue únicamente del fixture `tests/php/etapa5_nucleo.php`.

## 9. Regla de hold y frontera exacta

La condición canónica es `hold_expires_at > ahora`. Un hold exactamente igual al instante actual ya está vencido; uno menor también. Los holds vencidos no bloquean ocupación, no cuentan para el límite y pueden transicionar a `expirada` con OTP invalidado.

El fixture de Etapa 5 cerró tickets baseline antes de probar la retención vencida, porque una mesa ocupada por un ticket no podía atribuirse al hold. No se cambió la regla productiva.

## 10. Decisión de tema

Se eligió la opción A: la operación del mapa administrativo es dark-only. La vista operativa fija `data-admin-theme="dark"` y no expone un toggle de tema propio; eso coincide con el contrato visual actual de esa ruta.

No se implementó un tercer modo ni se añadió un toggle específico al mapa. El tema oscuro se verificó en las cuatro dimensiones solicitadas.

## 11. Consola del navegador

La pestaña operativa real se probó con la fecha de fixture normal `2026-11-01` y luego con cambio a `2026-11-02`.

Resultado de `TabDev.logs({levels: ['error', 'warning']})`: `[]` en ambas situaciones. No se observaron excepciones de JavaScript, warnings de runtime ni errores de inicialización del mapa.

## 12. Assets y red observada

La lista de assets incluyó los CSS/JS/fonts locales esperados y las llamadas API de disponibilidad/operación correspondientes. Los logs del servidor reportaron 200 para `admin.css`, `operation/reservations.css`, `admin/reservation-operation.js`, fuentes y bundles relacionados.

No se observó un 404 local ni polling duplicado al cambiar de fecha. El extractor de assets no pudo descargar dos fuentes externas de Google Fonts y señaló que `favicon.ico` devuelve HTML; son hallazgos ambientales/no bloqueantes, no errores del bundle local.

La capacidad del navegador disponible no expuso un panel Network independiente; por eso la evidencia de red combina inventario de assets, logs del servidor y la secuencia de requests observada.

## 13. Validación visual responsive

Métricas observadas en tema oscuro:

| Viewport | Mapa | Ancho del mapa | Resultado |
|---|---:|---:|---|
| 1440×900 | 688 px | 1001 px | PASS |
| 1024×768 | 582 px | 604 px | PASS |
| 768×900 | 278 px | 724 px | PASS |
| 390×844 | 320 px | 339 px | PASS |

El viewport móvil ya no deja el mapa en altura cero; conserva el mapa visible con scroll interno y sin overflow horizontal. El panel, toolbar, tarjetas y shell operativa se mantuvieron alineados.

## 14. Estados visuales y cobertura operativa

La vista normal mostró mapa, filtros, panel y selección. Las pruebas de estado JavaScript cubren precedencia de estados, rojo/azul, selección, mesa no reservable y clases resultantes. Las suites PHP/POS cubren warnings, historial, tickets, holds, reservaciones próximas y paridad de estado.

No se ejecutaron mutaciones reales de producción desde el navegador; las mutaciones fueron cubiertas por fixtures transaccionales y workers de concurrencia.

## 15. Instalaciones limpias

Pasaron las instalaciones limpias de Etapas 5, 7.5, 8, 9 y 9.5. Cada instalador reportó DDL/DML correctos y eliminación (`dropped: true`) de su base temporal.

La instalación limpia de Etapa 9.5 reportó 8/8 escenarios integrados, además de las suites cruzadas de Etapa 7.5 y POS.

## 16. Matriz de regresión final

La última matriz corregida ejecutó 17/17 PASS, incluyendo los argumentos obligatorios de concurrencia y POS:

- Etapas 5, 6, 6.2, 7, 7.5 y 8.
- `etapa9_mapa_manual.php`, `etapa9_concurrencia.php --db=casa_pestalozzi_etapa4_test` y `etapa9_instalacion_limpia.php`.
- `etapa9_5_concurrencia_integrada.php --db=casa_pestalozzi_etapa4_test` y `etapa9_5_instalacion_limpia.php`.
- `pos_reservacion_contrato.php` y `pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`.

Además: `npm.cmd run test:js` PASS con los dos tests JS, lint PHP PASS y `node --check` PASS.

## 17. Intermitencias observadas y reevaluación

Una corrida intermedia tuvo un fallo de fixture en Etapa 7 por consultar el conteo usando el instante de inicio de la reservación, y una corrida de Etapa 5 fue transitoria. También se observó una primera carrera F no reproducida en la suite directa de Etapa 9.5.

Se corrigieron sólo los fixtures stale de Etapas 5 y 7. La suite Etapa 9.5 pasó dos veces consecutivas después; la matriz final completa pasó 17/17. Estos eventos quedan como señal de sensibilidad del ambiente compartido, no como aceptación silenciosa de un fallo.

## 18. Archivos modificados en esta etapa

Cambios propios de Etapa 9.6:

- `gulpfile.js`: orden reproducible de sourcemaps del bundle global.
- `package.json`: `test:js` incluye el test de estado del mapa.
- `tests/php/etapa5_nucleo.php`: fixture de horizonte y aislamiento de tickets baseline.
- `tests/php/etapa7_publica.php`: conteo de límite usando el reloj de prueba actual.
- Assets generados por el build, incluyendo `bundle.min.js.map`.
- Este reporte.

Los cambios funcionales previos de Etapas 9 y 9.5 se conservaron sin ampliar alcance, sin migración y sin cambio de estados, permisos, OTP o algoritmo de asignación.

## 19. Riesgos y limitaciones pendientes

- Apache/XAMPP no estaba disponible en el puerto 80; la inspección visual usó PHP built-in con router temporal.
- El panel Network del navegador no estuvo expuesto; la red se validó con assets y logs locales.
- Google Fonts externo puede fallar en una red restringida; el CSS local sigue sirviendo.
- `favicon.ico` debería tener un recurso o respuesta de tipo correcto en una etapa futura de higiene web.
- Persisten propietarios offline en 6 artefactos sin read-only; los builds finales sí escriben.
- La sensibilidad ocasional de carreras sobre una base compartida aconseja repetir concurrencia en CI o una base limpia antes de una promoción.

## 20. Decisión final y Etapa 10

- ¿Build reproducible? **Sí, con condiciones**: dos builds globales consecutivos pasan después de corregir el sourcemap; en este Windows debe usarse `npm.cmd` por la política de PowerShell.
- ¿Mapa validado visualmente? **Sí, con condiciones**: dark-only, cuatro viewports, consola limpia, assets locales correctos y cobertura de estados por suites; no se validaron mutaciones reales desde una instancia Apache.
- ¿Es seguro avanzar a Etapa 10? **No automáticamente**. Etapa 9.6 está cerrada y la evidencia funcional final es PASS, pero Apache, el panel Network y la sensibilidad ambiental de concurrencia deben ser aceptados explícitamente por el responsable antes de iniciar Etapa 10. Esta tarea no inicia Etapa 10.
