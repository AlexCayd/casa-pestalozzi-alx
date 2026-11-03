# A6 — Integración con main

## Base Git y alcance

- Integración temporal: `codex/integracion-leo-main`, creada desde `origin/main`.
- `origin/main` usado: `c99b3a235a760ca3fa21609d8e481b5fe12c1b76`.
- Rama fuente A5: `codex/eliminar-productos-casa`, HEAD `603da3f6a2742a0b81fcca6417a6b7b2012c1347`.
- Ancestro común: `baf089b4922a01165a4a2afec733183cdffffeb8`.
- La rama `main` local y la rama fuente no se modificaron. No se hizo push ni merge definitivo a `main`.

## Resolución de conflictos

Los conflictos de arquitectura quedaron resueltos en `services/Security/ContextoNegocio.php` y `services/Security/Permisos.php`. Global conserva los módulos transversales de usuarios, inventario, artículos, finanzas, analíticas e IA; no ofrece el módulo comercial `menu`. Restaurante conserva sus módulos de menú, POS y reservaciones; Panadería conserva menú local y capacidades habilitadas. La biblioteca de menús PDF sigue autorizada por módulos Global compatibles y la ruta antigua `/admin/global/productos` queda cerrada.

Los conflictos de `public/build/js/admin.js` y su mapa se resolvieron regenerando los assets con Gulp desde las fuentes integradas. No se mezcló contenido minificado manualmente. También se actualizaron rutas, permisos y textos del asistente para retirar la antigua intención `productos_casa` sin retirar el Asistente IA.

En analíticas Global se mantienen los totales y comparativos por negocio y los productos más vendidos de cada menú local. Se quitó la consulta a `producto_estandares` y la comparación de Productos de la casa. Las lecturas usan la vista compatible `productos_oferta`, que desde A3 proyecta los datos comerciales locales de cada oferta.

`services/Pos/Comercial.php` conserva operaciones transaccionales e idempotentes de `main` y añade resolución de presentaciones, precio y snapshot comercial. Se mantienen venta, consumo, cancelación y reversión de inventario, cierre y compatibilidad de reservaciones; M2/A4 ejercitan presentaciones, recetas, variantes, costos y reintentos.

## Migraciones

Para una instalación nueva, el instalador ejecuta DDL, despliegue estructural, menú y fixtures de desarrollo; después aplica A1 y A3 y valida el bootstrap. Para actualizar una instalación existente, con respaldo restaurable, aplicar sólo las migraciones que falten en este orden: M1.2 si parte de M1.1, M2 si aún no existe, A1 y finalmente A3. A3 comprueba integridad y discrepancias antes de activar los datos locales. Si el preflight detecta diferencias, detener el despliegue y reconciliar antes de actualizar código.

La instalación nueva se ejecutó dos veces en bases temporales distintas: 963 comprobaciones acumuladas, con marcadores A1/A3 y una fila comercial local para cada oferta. Los runners de A1 y A3 de actualización, así como M2 y A4, se ejecutaron en bases de prueba aisladas. El runner de notificaciones informó que tres migraciones históricas retiradas ya no están disponibles; omitió sólo esa etapa histórica y validó el DDL vigente y el resto de las suites.

## Ajustes de pruebas

- `run-reservaciones-reubicacion-ticket-db.php` conserva fecha dinámica y fija la hora lógica en 15:00; así los casos +40 minutos y +3 horas permanecen el mismo día y dentro del horario configurado.
- `run-pos-print-alerts-db.php` se actualizó al esquema por unidad y al control de acceso actual: usa un usuario autenticado, un ticket del Restaurante y un área de la fixture aislada.
- `run-analytics-ambito-isolated.php` verifica instalación temporal, propaga `$_ENV` a los procesos hijos y exige el mensaje final de cada suite. La tarea npm `test:analytics:ambito:db` permite repetirla sin tocar la base configurada.

## Validación ejecutada

Todas las pruebas con base de datos usaron bases temporales; cada runner verificó propiedad y eliminó su base al terminar.

- `npm ci` y `npm run build`: PASS. Gulp regeneró JS, CSS y mapas desde fuentes integradas.
- `npm run test:php`, `npm run test:js`, `npm run test:notifications`: PASS.
- `npm run test:analytics:ambito:db`: PASS; análisis 33, Asistente 484 y finanzas 49 comprobaciones.
- Instalación nueva: PASS, 963 comprobaciones acumuladas en dos instalaciones.
- A1/A3, M2 y A4 aislados: PASS (15, 59, 130 y 2.258 comprobaciones, respectivamente); UI M2 y A4: PASS.
- Almacenes 3C: PASS, 158 comprobaciones; concurrencia: PASS, 17 escenarios.
- Preservación de menú: PASS, 7 casos; PDF, POS para llevar y alertas de impresión: PASS.

La prueba de preservación y los ensayos de actualización no modificaron la base local de la aplicación. La restauración de un respaldo y el despliegue sobre una instalación operativa quedan para el proceso de despliegue; esta integración no aplica migraciones a esa base.
