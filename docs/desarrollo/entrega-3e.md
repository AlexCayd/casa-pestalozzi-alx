# Entrega de Etapa 3E

Fecha: 25 de octubre de 2026. Candidata: `codex/cierre-arquitectura-etapa-3e`.
El HEAD de entrega es el commit `test: 3E.4 aceptación y preparación de integración`
que incorpora este informe; obtener su identificador con `git rev-parse HEAD`.
La entrega queda preparada para revisión e integración, sin push ni merge.

## Procedencia y commits

El cierre real de 3D es `2660ccf98f99dff94bda733a1790a77b6d254503`, en
`codex/adaptacion-restaurante-etapa-3d`. Se creó un worktree independiente desde
ese commit, conservando las etapas 2, 3A, 3B, 3C y 3D completas.

| Entrega | Commit | Resultado |
|---|---|---|
| 3E.1 | `30164f6` | Usuarios y Almacenes compartidos y autorizados en los tres ámbitos |
| 3E.2 | `32d92f4` | DDL/DML, menú, imágenes, fixtures e instalación con bootstrap seguro |
| 3E.3 | `8c505ac` | Contrato definitivo, documentación clasificada y residuos retirados |
| 3E.4 | Commit que incorpora este informe | Evidencia de aceptación, harness HTTP y auditoría final |

No se aplicaron stashes ni se descartaron cambios ajenos. El checkout original
conserva exactamente sus modificaciones de `database/deploy.sql`,
`package-lock.json` y su `docs/contrato.md` sin seguimiento. El contrato se revisó
e incorporó explícitamente en esta rama. El seed previo de `admin_demo` se revisó:
no se copió su activación automática; se sustituyó por bootstrap CLI delimitado.
El cambio ajeno del lock no se incorporó.

## Estado funcional alcanzado

| Ámbito | Usuarios | Almacenes | Resto |
|---|---|---|---|
| Global | Funcional: delegación y permisos directos | Físicos propios, creación/asignación autorizada, maestro y consolidado con filtros | Administrativo; no vende. Dashboards y finanzas consolidadas pendientes |
| Restaurante | Funcional con componentes compartidos | Operación local, asociaciones, entradas, ajustes, mermas, alertas, negativos y bitácora | Menú, recetas, variantes, POS, comanda, KDS, tickets, pagos, reservas y consultas conservados |
| Panadería | Funcional con componentes compartidos | Mismas operaciones locales, aisladas por almacén propietario | Arquitectura comercial preparada; menú administrativo, recetas, POS, tickets y finanzas pendientes |

Contraseña para cualquier panel administrativo; PIN limitado a POS, KDS y mapa
operativo. Se probaron cuenta inactiva, revocación en sesión viva, protección del
superadministrador, ámbitos delegados, escalamiento y datos ajenos. El servidor
comprueba permiso y negocio tanto en endpoints como en servicios.

Almacenes utiliza el motor 3C, sin saldos paralelos ni almacén virtual Global.
La identidad maestra es compartida; existencia, negativos y movimientos pertenecen
al almacén físico. No se implementaron transferencias ni producción.

## Datos e instalación reproducible

La [guía de instalación](instalacion.md) es el procedimiento único. Recursos:

- `database/ddl.sql`: estructura definitiva.
- `database/deploy.sql`: unidades, permisos y datos estructurales; no activa `admin_demo`.
- `database/menu-restaurante.sql`: menú original preservado.
- `database/menu-integridad.json`: correspondencias y huellas de integridad.
- `database/development.sql`: fixtures sintéticos coherentes y aislados.
- `src/images/menu-preservado/`: 13 originales; Gulp los copia sin recomprimir.
- `scripts/desarrollo/instalar.php` y `bootstrap-admin.php`: instalación explícita de desarrollo.

Integridad comprobada: **112 productos originales, 112 ofertas Restaurante,
15 categorías, 3 cartas, 4 áreas y 13 imágenes**. Se verificaron IDs,
correspondencias, campos, precios, disponibilidad, orden y huellas; las imágenes
propias y el fallback de categoría permanecen disponibles. Las ofertas sintéticas
adicionales se identifican `[DEV]`; no alteran los 112 originales. Panadería tiene
producto/oferta sintéticos propios y no copias comerciales del menú preservado.
Los saldos de fixtures coinciden con movimientos y cada ticket tiene vendedor.

`admin_demo` se provisiona como superadministrador con las tres membresías, hash
seguro y sin PIN. La contraseña acordada se introduce exclusivamente por
configuración local `DEV_SUPERADMIN_PASSWORD`; no está en archivos versionados.
El bootstrap exige CLI, entorno development/test, destino coincidente y marca
propia de instalación. Rechaza producción, homónimos ajenos y promoción implícita;
repetirlo no duplica ni restablece silenciosamente una cuenta.

Se completaron **dos instalaciones de servicio independientes y vacías** desde
los archivos del repositorio, sin acceder al paquete privado de Etapa 2: **792
comprobaciones**. Además, **dos instalaciones HTTP independientes** pasaron **106
comprobaciones cada una**, con autenticación de desarrollo y CRUD real. Las cuatro
bases se identificaron mediante marcas aleatorias y se eliminaron solo después
de comprobar propiedad. La base original no se migró ni modificó.

## Pruebas ejecutadas

| Verificación | Resultado |
|---|---|
| Preservación pura de menú | PASS, 34 aserciones |
| Esquema 3A y menú importado de referencia | PASS, 114 comprobaciones, dos bases |
| Autorización 3B | PASS, 128 comprobaciones |
| Motor Almacenes 3C | PASS, 158 comprobaciones |
| Concurrencia Almacenes 3C | PASS, 17 comprobaciones |
| Restaurante 3D.1 / 3D.2 / 3D.3 | PASS, 56 / 58 / 64 comprobaciones |
| Restaurante 3D.4 HTTP completo | PASS, 59 comprobaciones, ciclo POS e aislamiento |
| Usuarios 3E | PASS, 40 comprobaciones por instalación |
| Almacenes administrativos 3E | PASS, 53 comprobaciones por instalación |
| Instalación y fixtures 3E | PASS, 792 comprobaciones en dos instalaciones |
| HTTP 3E | PASS, 106 por instalación, dos instalaciones |
| Suite `npm run test:php` | PASS |
| Suite `npm run test:js` | PASS |
| Suite `npm run test:notifications` | PASS; proveedores simulados y confinados |
| Enlaces/documentación | PASS; verificador de enlaces y contratos |
| `composer install`, `npm ci`, `npm run build` | PASS |

Las pruebas históricas de 3A/3D usan el paquete privado únicamente como referencia
de regresión; los ensayos de instalación 3E no lo consumen. Todas las bases de
regresión fueron temporales y limpiadas comprobando propiedad.

Navegador real: login `admin_demo`, selector debajo del logotipo, navegación entre
ámbitos y seis pantallas Usuarios/Almacenes en **1440×1000 y 390×844**, formulario de
asociación y teclado. Se corrigieron los filtros de Usuarios en móvil y la
exposición de formularios físicos para artículos inactivos. La revisión visual
independiente confirmó componentes de Restaurante compartidos. Se completó en
POS una mesa sintética: apertura, artículo `[DEV]`, envío de comanda, entrega,
cobro ficticio y ticket cerrado. Capturas de aceptación permanecen fuera del repo,
en el directorio privado de evidencia de esta sesión; no son dependencia de instalación.

## Auditoría de documentación y limpieza

Fuente principal: [contrato rector](../contrato.md). Entrada de agentes:
`CLAUDE.md`. Referencias vigentes: [arquitectura](../arquitectura.md),
[Usuarios](../usuarios/usuarios.md), [Almacenes](../modulos/almacenes.md),
[desarrollo](desarrollo.md), [pruebas](pruebas.md) y [backlog](../backlog.md).
El [inventario documental](clasificacion-documental.md) clasifica cada documento.
Los antecedentes de etapas 2/3A/3B/3C/3D y Panadería se conservaron en sus rutas
con cabeceras históricas y precedencia del contrato, evitando romper referencias.
No se movieron ni eliminaron documentos técnicos útiles indiscriminadamente.

Residuos retirados después de comprobar consumidores:

- `AdminRecetasControllerLegado.php` y `VariantesLegado.php`, con sus delegaciones:
  implementaciones paralelas del esquema retirado. El arranque exige esquema final.
- `scripts/seed-usuarios-prueba.php`: seed de roles/credenciales del modelo retirado;
  sustituido por fixtures inactivos y bootstrap delimitado.
- `backup_pre_refactor.sql`: dump heredado de main, sin consumidores. Se conserva
  en el original, historial Git y copia privada; no se distribuye en el árbol final.
- Diferencias de mapas compilados debidas exclusivamente a CRLF: no incorporadas.

No se incluyeron `.env` reales, dumps privados, credenciales, capturas temporales
ni dependencias instaladas. Se revisaron rutas y texto del diff y se comprobó la
ausencia de la contraseña conocida en los archivos finales. Esta revisión no
purga el historial Git preexistente ni sustituye una auditoría de infraestructura.

## Comparación con main y recomendación

Main permanece en `1aa642a54914985fd8a9b3ad264db43a10d5119e`, ancestro de la
candidata. `git log HEAD..main` está vacío: no hay cambios divergentes de main ni
archivos modificados por commits exclusivos suyos. Se revisó el diff acumulado:
servicios y esquema multinegocio; autorización; adaptación comercial Restaurante;
interfaces compartidas; instalación y seeds; scripts, pruebas y documentación.
El diff acumulado contiene **284 archivos: 126 añadidos, 156 modificados y dos
eliminados respecto a main**. Las eliminaciones netas son el dump heredado y el
seed retirado; las implementaciones legado creadas durante migración tampoco
forman parte del árbol final.
`package.json` añade comandos de pruebas de las etapas previas; no cambia versiones.
`composer.json`, `composer.lock` y `package-lock.json` no cambian respecto a main.
`.npmrc` hace reproducible el grafo peer heredado mediante `legacy-peer-deps=true`.
Las advertencias deprecadas de npm/Sass son heredadas; no se amplió el alcance
con actualizaciones de dependencias.

`git merge-tree --write-tree main HEAD` terminó correctamente sin conflictos;
no modifica main ni realiza el merge definitivo. La ausencia de conflictos
textuales se acompaña de las regresiones funcionales descritas. El resumen exacto
de archivos y líneas se obtiene con `git diff --stat main...HEAD`; el informe al
usuario registra los conteos finales del commit de entrega.

La candidata se entrega con índice y worktree limpios, cuatro commits 3E y
recursos de reconstrucción versionados. Se recomienda su revisión e integración
cuando el desarrollador receptor confirme estas evidencias. No hay excepciones
críticas conocidas trasladadas al backlog.

## Riesgos residuales y continuidad

No se certificaron impresoras físicas, SMTP real, Meta/WhatsApp, proveedores n8n,
entregas reales ni infraestructura de producción. La aceptación se realizó con
MySQL 9.7.1 y dependencias locales bloqueadas; otras plataformas deben ejecutar
la misma guía y suites. No se hizo push ni merge ni se modificó main.

El [backlog](../backlog.md) separa deuda/defectos, mejoras y funcionalidades nuevas:
UX/filtros/gestión avanzada de Usuarios, gráficas y transferencias de Almacenes;
menú, recetas, POS, tickets y finanzas de Panadería; dashboards, consultas y
asistente Global; producción, catering y devoluciones adicionales. Su existencia
contractual no implica implementación funcional. Los nuevos módulos deben seguir
el contrato rector, reutilizar servicios y diseño, probar aislamiento y actualizar
DDL/DML y documentación juntos.
