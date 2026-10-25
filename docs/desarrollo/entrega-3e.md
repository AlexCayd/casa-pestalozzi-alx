# Informe de validación de cierre 3E

**Fecha:** 25 de octubre de 2026
**Rama candidata:** `codex/cierre-arquitectura-etapa-3e`
**Estado de esta revisión:** pruebas locales completas; integración en `origin/main` aún pendiente en worktree separado.

Este informe distingue los checks ejecutados en este cierre de los runners históricos que dependen del paquete privado de Etapa 2. La referencia de implementación es [Arquitectura](../arquitectura.md); esta acta no convierte módulos preparados en funcionales.

## Base y cambios

La candidata parte del cierre 3D identificado en `2660ccf` e incluye las etapas 3E.1–3E.4. Commits de etapa: `30164f6` (Usuarios/Almacenes), `32d92f4` (instalación, menú y bootstrap), `8c505ac` (documentación 3E.3), `01bfcee` (aceptación 3E.4), más el commit de extensión del DML/validación de sus escenarios.

El fixture ahora reconstruye un año calendario completo de ventas de prueba de Restaurante (365 tickets y 803 líneas), ofertas/variantes sintéticas, pagos divididos cuadrables, cancelaciones, superficies, gastos y feedback. Los 112 productos originales y sus ofertas permanecen sin alteración. Los archivos normativos por etapas se consolidan en `docs/arquitectura.md`; se eliminan la copia `docs/contrato.md`, la antigua carpeta de instrucciones de migración y un índice obsoleto de Panadería. `package-lock.json` no se modificó.

## Comprobaciones ejecutadas

| Prueba | Resultado |
|---|---|
| `npm run build` | PASS; sólo avisos de deprecación de la API Sass y Node. |
| `npm run test:php` | PASS; suites PHP generales, autorización, POS, reservaciones, analíticas y límites. |
| `npm run test:js` | PASS; contratos JS/UI, workflows y 205 enlaces locales. |
| `npm run test:notifications` | PASS; suites aisladas, notificaciones y documentación. |
| `npm run test:menu-preservacion` | PASS; 34 aserciones. |
| `run-multinegocio-3b.php` | PASS; 128 comprobaciones. |
| `run-almacenes-3c.php` | PASS; 158 comprobaciones. |
| `run-almacenes-3c-concurrencia.php` | PASS; 17 escenarios/comprobaciones de concurrencia, replay, revocación y locks. |
| `run-usuarios-3e1.php` | PASS; 40 comprobaciones. |
| `run-instalacion-3e.php` | PASS; dos bases vacías independientes, 814 comprobaciones acumuladas. Cada instalación verificó 112 productos, 112 ofertas, 15 categorías, 3 cartas, 4 áreas y 13 imágenes; ambas bases fueron verificadas y limpiadas. |
| `pruebas-http-3e.php` | PASS; 106 comprobaciones autenticadas, incluyendo los tres ámbitos, CRUD/revocación, IDs ajenos, movimientos, stock negativo, consolidado y CSRF. El harness y su fixture privado se limpiaron tras la prueba. |
| Navegador con Chrome/Playwright | PASS; seis vistas (Usuarios y Almacenes en Global, Restaurante y Panadería) respondieron 200, mostraron contenido/controles y no desbordaron a 390 px. |
| `run-inventario-vista-3d.php` | PASS; 10 comprobaciones de presentación. |
| `git diff --check` | PASS. |

El build no dejó sourcemaps distintos: su única variación fue de fin de línea en artefactos generados y se revirtió después de comprobar que no cambió ninguna fuente.

## Menú, datos y seguridad

La instalación procede exclusivamente de `database/ddl.sql`, `database/deploy.sql`, `database/menu-restaurante.sql`, `database/development.sql` e imágenes versionadas. No se usó el paquete privado de Etapa 2. Los saldos coinciden con `movimientos_inventario`; los tickets tienen unidad vendedora; los pagos divididos igualan partidas no canceladas y las ventas no se duplican en Finanzas.

`admin_demo` se crea por el bootstrap CLI en la instalación aislada. La contraseña se recibió solo como variable de proceso, generó un hash seguro y no quedó en SQL, documentación, fixture HTTP o historial Git. La interfaz probó autenticación por contraseña y ningún flujo usa NIP para panel administrativo.

Global no recibe tickets ni almacén consolidado virtual. Panadería conserva existencias y oferta sintética separadas de Restaurante. La UI existente de Restaurante se reutiliza con el selector e identidad de ámbito.

## Alcance de runners y límites

Los runners históricos `run-multinegocio-3a.php` y `run-restaurante-3d1.php`–`3d3.php` requieren `--paquete` con el artefacto privado anterior. No se usó esa dependencia en esta reconstrucción; la aceptación 3E verificó instalación y fidelidad desde SQL, manifiesto e imágenes versionadas. La suite HTTP 3E cubrió rutas de Restaurante y los PDFs del menú/maridaje, pero no sustituyó un nuevo recorrido completo de POS con hardware.

No se certificaron impresoras físicas, SMTP, WhatsApp/Meta, entrega real de n8n, ni restauración tras fallo de energía. Esos ensayos requieren proveedores/equipo externos. No hay una afirmación de validación para ellos.

## Pendiente de integración

El siguiente paso es revalidar el remoto `origin/main`, ensayar el merge en un checkout limpio separado de la ubicación original con cambios ajenos, ejecutar las comprobaciones post-merge y, si pasan, publicar el merge autorizado en `origin/main` sin force. Esta acta se actualizará con los hashes y el estado remoto al completar la operación.
