# Informe final de cierre e integración de Etapa 3E

**Fecha:** 25 de octubre de 2026
**Estado:** integración local validada; esta rama contiene el merge autorizado de 3E sobre origin/main. La publicación remota se registra en el historial de Git de la rama.

Este informe describe lo implementado y separa las pruebas ejecutadas de las limitaciones externas. La referencia de arquitectura vigente es [docs/arquitectura.md](../arquitectura.md). La guía de instalación es [docs/desarrollo/instalacion.md](instalacion.md); los datos y criterios de prueba están en database/ y docs/desarrollo/.

## Ramas, commits y comparación

- Rama candidata: codex/cierre-arquitectura-etapa-3e, HEAD 36360e301986b37b7b235fd3cc9d80068fd82b78.
- Base remota revisada: origin/main en 140f05efcf2c0359807c51e260339aabab569c15; era ancestro de la candidata y seguía sin cambios al actualizar referencias antes de publicar.
- Integración ensayada en el worktree limpio codex/integracion-main-post-3e, sin editar main directamente.
- Commit de merge local: 2f0c4244326d08ce4effadd795c311733abcc11d; parents 140f05e y 36360e3. Git ort no informó conflictos.
- Commits propios de 3E: 30164f6 (Usuarios y Almacenes), 32d92f4 (instalación, menú y bootstrap), 8c505ac (documentación), 01bfcee (aceptación), e3fec59 (fixtures reproducibles) y 36360e3 (arquitectura final y limpieza documental). La integración preserva todos estos commits.
- Diff acumulado candidata contra la base: 282 archivos, 22,499 inserciones y 10,136 eliminaciones. Incluye arquitectura/servicios 3A–3D, interfaz y permisos de 3E, esquema y DML reproducibles, menú versionado, pruebas y documentación. package-lock.json no forma parte del cambio.
- El dump previo backup_pre_refactor.sql y documentos de migración que ya no son instrucciones vigentes se retiraron; el detalle de clasificación está abajo. El worktree original conserva intactos cambios ajenos en database/deploy.sql, docs/arquitectura.md, package-lock.json y docs/contrato.md que no pertenecían a esta rama.

## Resultado funcional

- **Usuarios:** alta, edición, asignación de varios ámbitos y permisos directos por módulo/nivel; acceso administrativo con contraseña; revocación, estado de cuenta y protección contra escalamiento/superadministrador comprobados en Global, Restaurante y Panadería.
- **Almacenes:** servicios y pantalla reutilizada por ámbito; Global crea/asigna almacenes, ve distribución y consolidado sin almacén virtual; Restaurante y Panadería operan únicamente sus existencias con asociación de artículos, entradas, ajustes, mermas, movimientos, negativos según permisos y alertas. Los servicios aplican autorización y transacciones.
- **Restaurante:** se preserva la interfaz y operación existentes. Menú, ofertas, inventario, reservaciones y flujos comerciales mantienen los límites del contrato; la validación de esta etapa no certifica periféricos físicos.
- **Global y Panadería:** los dos módulos prioritarios (Usuarios y Almacenes) tienen vistas y permisos funcionales. El resto de sus módulos comerciales está definido como pendiente, no se declara terminado.
- La cuenta admin_demo se aprovisiona mediante bootstrap CLI de desarrollo, que rechaza otros entornos. El hash se guarda en base; la contraseña se pasa como configuración local de proceso y no está en SQL, fixtures, documentación ni Git. No hay ruta pública para crear superadministradores.

## DDL, DML, menú e instalación

- Esquema definitivo: database/ddl.sql.
- Datos estructurales: database/deploy.sql.
- Menú preservado: database/menu-restaurante.sql, manifiesto database/menu-integridad.json e imágenes versionadas en src/images/menu-preservado/.
- Fixtures de desarrollo reproducibles: database/development.sql y database/fixtures/panaderia.php.
- Se ensayó el procedimiento documentado sobre **dos bases vacías e independientes**, creadas y eliminadas por el runner: 814 comprobaciones acumuladas. En cada base se verificaron 112 productos, 112 ofertas de Restaurante, 15 categorías, 3 cartas, 4 áreas de producción y 13 imágenes. Los IDs, precios, disponibilidad, orden, categorías, correspondencias y fallback se validan desde los recursos versionados, sin paquete privado de Etapa 2. Panadería usa ofertas sintéticas identificables, sin copiar el menú del Restaurante.
- El fixture genera 365 tickets de Restaurante, 803 líneas y pagos divididos cuadrables, más estados de reservación, pruebas de cancelación, gastos, superficies y feedback. Saldos de inventario provienen de movimientos y tickets llevan unidad vendedora.
- Guía única: [instalación de desarrollo](instalacion.md). Describe dependencias, entorno, creación de base, DDL, seeds, bootstrap, assets, arranque, login, validación de Usuarios/Almacenes y regresión.

## Validaciones finales

Todas las pruebas siguientes se ejecutaron en el worktree de integración después del merge, salvo la comprobación estática 3D indicada explícitamente.

| Validación | Resultado |
|---|---|
| npm run build | PASS; advertencias conocidas deprecadas de Sass y fs.Stats de Node, sin error. |
| npm run test:php | PASS; suites PHP generales. |
| npm run test:js | PASS; suites JS, workflows y 199 enlaces locales vigentes. |
| npm run test:notifications | PASS; pruebas aisladas, avisos y documentación. |
| npm run test:menu-preservacion | PASS; 34 aserciones. |
| run-instalacion-3e.php | PASS; dos instalaciones en bases independientes y vacías, 814 comprobaciones, recursos del menú completos. |
| run-multinegocio-3b.php | PASS; 128 comprobaciones. |
| run-almacenes-3c.php | PASS; 158 comprobaciones. |
| run-almacenes-3c-concurrencia.php | PASS; 17 comprobaciones de concurrencia, replay, locks y revocación. |
| run-usuarios-3e1.php | PASS; 40 comprobaciones. |
| pruebas-http-3e.php | PASS; 106 comprobaciones autenticadas sobre los tres ámbitos, CRUD/revocación, aislamiento de IDs, movimientos, negativos, consolidado y CSRF. Se eliminó la base/fixture temporal. |
| Chrome/Playwright | PASS; login 200 y seis pantallas de Usuarios/Almacenes en los tres ámbitos con respuesta 200, contexto visible, sin errores JS inesperados ni overflow a 390 px. |
| run-inventario-vista-3d.php | PASS antes del merge; 10 aserciones estáticas de presentación. |
| git diff --check | PASS contra origin/main; la rama de integración está limpia tras registrar este informe. |

El build no dejó sourcemaps distintos: su única variación fue de fin de línea en artefactos generados y se revirtió después de comprobar que no cambió ninguna fuente.

Los tests de preservación se ejecutaron sobre los archivos del repositorio. La comprobación del runner histórico que requiere paquete privado de Etapa 2 se trató como limitación, no como pase.

## Documentación y limpieza

- **Vigentes:** docs/arquitectura.md (contrato rector), docs/desarrollo/instalacion.md, docs/desarrollo/entrega-3e.md, docs/backlog.md, guías de base y API, contratos funcionales de Usuarios/Almacenes y pruebas aún usados.
- CLAUDE.md ahora instruye leer el contrato rector, identificar ámbito, reutilizar servicios/diseño de Restaurante, comprobar permisos de servidor, sincronizar DDL/DML, probar aislamiento y registrar estado real de módulos.
- **Históricos:** contratos funcionales de etapas previas que se conservan están rotulados como antecedentes. La carpeta de migración ya no ofrece planes por etapa como instrucciones activas.
- **Retirados:** docs/contrato.md duplicado (su contenido normativo se consolidó en docs/arquitectura.md), docs/migracion/ (planes y evidencia de etapas obsoletos para la operación diaria), e índice contractual de Panadería que solo apuntaba a esos planes. El dump SQL antiguo se eliminó para que no parezca fuente instalable; el DDL/DML actual versionado es la fuente reproducible.
- No se eliminaron pruebas válidas ni datos preservados. No se incluyó .env real, dump privado, credencial ni fixture temporal. git grep no encontró la contraseña literal en el commit integrado; solo están versionados los .env.example.

## Conflictos, limitaciones y trabajo posterior

El merge de candidata sobre origin/main se resolvió sin conflictos textuales. Se revisó el diff acumulado (282 archivos), el build, pruebas PHP/JS, instalación, endpoints y seis vistas. No se detectó una regresión crítica durante esta aceptación.

Los runners históricos run-multinegocio-3a.php y run-restaurante-3d1.php–3d3.php necesitan --paquete con el artefacto privado de Etapa 2 y no se usaron como dependencia para instalar. La fidelidad actual se comprobó con menú/DML/manifiesto/imágenes versionados; eso valida la reconstrucción limpia, aunque no reejecuta aquellos comparadores históricos. Tampoco se sustituyó la aceptación HTTP 3E por una repetición completa del recorrido POS 3D. No se probaron impresoras/periféricos, hardware, SMTP, WhatsApp/Meta, servicio externo de n8n ni recuperación de fallo eléctrico.

Backlog posterior vigente en [docs/backlog.md](../backlog.md): mejoras de presentación y filtros de Usuarios; gráficos/UX y transferencias de Almacenes; módulos de Panadería (menú, recetas, POS, tickets, pagos y finanzas); tableros y consultas consolidadas/IA de Global; producción, catering y devoluciones adicionales. Transferencias, producción y demás funcionalidades nuevas están fuera de 3E. Cualquier defecto crítico encontrado posteriormente debe registrarse como defecto, no degradarse a mejora.

## Recomendación

La candidata quedó limpia y reproducible; el ensayo de integración con la versión de origin/main revisada no tuvo conflictos y las validaciones 3E posteriores al merge pasaron. Con las limitaciones externas e históricas documentadas arriba, la integración local queda lista y se publica en main sin force. Consultar el HEAD remoto final en git ls-remote origin refs/heads/main y el commit de publicación en el historial de esta rama.
