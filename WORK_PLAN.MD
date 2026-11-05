# WORK_PLAN.MD — Higiene documental, modelo de datos y rendimiento

> Proyecto: Casa Pestalozzi (`AlexCayd/casa-pestalozzi-alx`).
> Alcance: trabajo secuencial D1 → D2 → D3 → D4 sobre una sola rama y un solo worktree.
> Este archivo es el **contrato operativo de la intervención**, no reemplaza `docs/arquitectura.md` como fuente normativa del sistema.

## 0. Objetivo y definición de éxito

Reducir documentación obsoleta, verificar si el modelo de datos vigente está justificado y optimizar tiempos **solo donde existan mediciones**, sin regresiones de negocio, pérdida de datos ni modificaciones peligrosas de la base activa.

La intervención termina cuando:

1. La documentación vigente es coherente, breve y navegable; los documentos históricos recuperables desde Git dejan de competir con el contrato actual.
2. Existe una clasificación verificable de tablas, vistas, triggers, índices y consumidores, con candidatos a simplificar y riesgo de cada uno.
3. Existe una línea base reproducible de rendimiento y, cuando sea posible, una comparación antes/después de los cambios medidos.
4. Se aplicaron únicamente simplificaciones de riesgo controlado, con regresiones aprobadas, o se documentó explícitamente por qué no procede una modificación.

**No objetivos:** añadir módulos, implementar M2.5/M3, rediseñar UX, renombrar masivamente entidades, reestructurar la arquitectura de toda la aplicación, forzar la eliminación de tablas por cantidad, migrar la BD de producción o ejecutar un despliegue.

## 1. Reglas no negociables: Git, trabajo y seguridad

- **Una sola rama:** `codex/higiene-docs-datos-rendimiento`, nacida desde `origin/main`; reutilizarla en sesiones sucesivas. **Un solo worktree existente.** Prohibido crear otras ramas, worktrees, PR por etapa, cherry-picks o merges intermedios.
- Preflight obligatorio: `git status --short`, `git fetch origin main`, `git branch --show-current`, `git rev-parse HEAD`. Si el árbol no está limpio al inicio, examinar y proteger cambios ajenos; no sobrescribirlos ni hacer stash automático. Crear la rama **solo si no existe**; si existe, comprobar que es la rama correcta antes de continuar.
- Prohibidos `git reset --hard`, `git clean -fd`, `git push --force`, eliminar ramas ajenas, sobrescribir cambios no propios y hacer cambios directamente en `main`.
- **Máximo un commit por etapa terminada y validada** (D1, D2, D3, D4); cero commits vacíos. El orquestador realiza el commit después del visto bueno del validador. La creación inicial de este plan queda incluida en el commit de D1. Si existe una razón excepcional para un commit adicional, registrarla antes y obtener autorización.
- No hacer `push`, crear PR ni integrar a `main` automáticamente. Dejar todo local y reportar hash de los commits. Se requiere aprobación explícita para publicar/integrar.
- No tocar credenciales ni datos sensibles. Nunca ejecutar `database/ddl.sql` contra una BD existente: comienza con un **RESET destructivo**. Nunca aplicar `development.sql` a producción. No ejecutar `DROP`, `TRUNCATE`, migraciones destructivas ni borrados de datos.
- Respetar autorización por ámbito: Global no vende; Restaurante y Panadería poseen ofertas locales; cada almacén mantiene su saldo físico. Preservar historial inmutable, claves foráneas, triggers de integridad, idempotencia, transacciones y snapshots.
- Mantener las 112 ofertas originales del menú Restaurante, sus categorías, áreas, precios, identidades y recursos gráficos. No alterar datos históricos ni permisos de usuarios. La Panadería no debe presentarse como POS funcional antes de M3.
- No agregar librerías, servicios externos, herramientas de medición pesadas o nuevas configuraciones de producción sin justificación y validación.
- Trabajar con cambios mínimos: no formatear archivos completos por conveniencia ni mezclar refactors con cambios funcionales.

## 2. Arquitectura de agentes (tres roles, trabajo serial)

### Orquestador — modelo de alta capacidad

**Único responsable de planificación y aceptación.** Lee este archivo, `docs/arquitectura.md` en los apartados pertinentes y el diff. Descompone cada etapa en tareas que un ejecutor pueda resolver dentro de un contexto reducido. Verifica dependencias, protege invariantes y decide si una sugerencia se acepta, se rechaza o queda bloqueada. No crea un nuevo subagente por cada archivo. Actualiza la sección de estado, solicita revisión independiente y hace el único commit por etapa.

El orquestador seleccionará dinámicamente el modelo ejecutor y el validador según la complejidad y el riesgo optimizando simpre el consumo de tokens. Luna será el ejecutor predeterminado para tareas pequeñas y completamente especificadas; Terra se utilizará para auditorías y cambios de complejidad intermedia; Sol ejecutará o validará cualquier modificación que afecte integridad de datos, transacciones, autorización, inventarios o contratos arquitectónicos. Ningún agente validará su propio trabajo.

### Ejecutor — modelo económico/de menor capacidad

**Único rol con escritura activa en cada momento.** Recibe una tarea acotada: objetivo, archivos permitidos, invariantes, validación y criterio de terminado. Inspecciona solo rutas necesarias; modifica solo esos archivos; ejecuta pruebas adecuadas; devuelve un resumen compacto. **No crea ramas, no hace commits, no cambia de etapa por cuenta propia.** Para SQL, FKs, triggers, transacciones, permisos o reglas de reservas: propone un parche y remite al orquestador; no ejecuta cambios de alto riesgo autónomamente.

### Validador — modelo independiente de capacidad media-alta/alta

**Solo lectura y comprobación**: no edita, no hace commit, no corrige sobre el mismo worktree. Revisa el diff contra los criterios, busca fallos de seguridad, integridad, aislamiento multinegocio y regresiones; exige pruebas observadas, no afirmaciones. Emite `APROBADO`, `CAMBIOS_REQUERIDOS` o `BLOQUEADO`, con evidencias concretas y máximo cinco hallazgos priorizados. Si observa un error, el orquestador devuelve tareas puntuales al ejecutor y solicita nueva validación.

**Concurrencia:** solo puede haber un ejecutor escribiendo. El validador actúa cuando el ejecutor terminó. El orquestador puede solicitar dos investigaciones de solo lectura en paralelo si el entorno lo permite, pero para empezar se usa **un ejecutor + un validador**, no un enjambre. Si el sistema de agentes no permite perfiles de modelos por rol, mantener la división lógica de roles y los permisos, aunque se use el mismo modelo.

### Protocolo de entrega entre agentes (máximo 12 líneas)

Cada delegación usa únicamente:

- `ETAPA / TAREA:` identificador y objetivo de una sola frase.
- `LEER:` rutas concretas y, cuando proceda, líneas o funciones.
- `EDITAR:` lista blanca de archivos (vacía para el validador).
- `NO ROMPER:` hasta tres invariantes precisos.
- `COMPROBAR:` comandos o consultas concretas; prohibidas pruebas destructivas.
- `ENTREGAR:` archivos modificados, resultado de pruebas, hallazgos y bloqueo (≤ 12 líneas).

No trasladar chats ni volcados de código completos. Entregar **rutas + símbolos + hechos + diff**. Reiniciar/compactar contexto al cambiar de tarea. Cada sesión nueva comienza leyendo **§0, §1, §3, §8 y solamente la etapa activa**; no leer el plan entero ni todos los documentos por defecto.

## 3. Flujo de trabajo y puertas de aceptación

- **Una etapa activa a la vez:** D1 → D2 → D3 → D4. No se permite comenzar D(n+1) hasta validar D(n); ante bloqueo documentado, el orquestador decide con evidencia si puede continuar únicamente en análisis no dependiente.
- Por cada etapa: (a) delimitar tarea, (b) recopilar evidencia mínima, (c) ejecutar cambio pequeño, (d) probar, (e) revisión independiente, (f) consolidar `WORK_PLAN.MD`, (g) crear un único commit de etapa.
- Si una prueba falla, distinguir defecto nuevo, error preexistente o entorno no disponible. No declarar `PASS` cuando no se ejecutó. Registrar hash/estado y evidencias breves.
- Una propuesta sin medición demostrable puede quedar como **recomendación no ejecutada**. No inventar optimizaciones por intuición.
- Mantener todos los resultados y decisiones operativas **resumidos** en §8; no añadir miles de líneas de logs. Los documentos especializados existentes siguen siendo las fuentes de detalles funcionales.

## 4. D1 — Higiene documental

**Objetivo:** eliminar contradicciones y archivos de entrega superados sin perder trazabilidad histórica.

**Prioridades:** `README.md`, `docs/arquitectura.md`, `docs/backlog.md`, `docs/desarrollo/clasificacion-documental.md`, `docs/desarrollo/pruebas.md`, `docs/panaderia/README.md`, `docs/auditoria-retiro-productos-casa.md`, `docs/integracion-a6-main.md`, `docs/modulos/catalogo-comercial.md` y referencias enlazadas.

**Acciones:**

1. Identificar para cada archivo: vigente / referencia técnica / histórico puro / duplicado o contradicción. Verificar referencias entrantes antes de retirar un documento.
2. Mantener `docs/arquitectura.md` como **única norma**, con estado actual A4/A6 y módulos vigentes. Separar la narración histórica del contrato actual; arreglar numeración y contradicciones.
3. Actualizar `README.md` e índice documental. Reducir el historial acumulado en `docs/desarrollo/pruebas.md` a instrucciones reproducibles y enlaces a evidencia Git; preservar criterios de aceptación relevantes.
4. Verificar estado real de Panadería, reservas, artículos, inventario y POS en el código, sin asumir que tabla/ruta equivale a módulo completo.
5. Solo retirar archivos obsoletos cuando Git garantice recuperabilidad, no sean referencia funcional ni exista dependencia de pruebas/documentación. No crear carpeta `archive/` ni otra fuente normativa.

**No hacer:** eliminar SQL, migraciones, reglas de negocio, pruebas ni documentación de seguridad/privacidad por parecer antiguos.

**Salida:** navegación coherente, enlace a decisiones vigentes, lista resumida de retirados y razones en §8.

**Validación:** `git diff --check`, chequeo de enlaces locales, inspección de referencias y `npm run test:js` si la suite está disponible. El validador busca falsos estados de funcionalidad, enlaces rotos y pérdida de contrato.

**Commit esperado:** `docs: consolidar documentacion vigente`.

## 5. D2 — Auditoría del esquema de datos (predominantemente solo lectura)

**Objetivo:** determinar qué estructuras son necesarias y cuáles solo conservan historia, antes de plantear cambios SQL.

**Punto de partida identificado en main:** `database/ddl.sql` declara aproximadamente **83 tablas, 44 triggers y una vista**. Confirmar cifras contra el árbol actual; no asumir que la BD operativa tiene exactamente esa estructura.

**Acciones:**

1. Inventariar `database/ddl.sql`, `database/actualizaciones/*.sql`, `database/deploy.sql`, las vistas, triggers, FKs, índices, `esquema_versiones` y las relaciones por ámbito.
2. Clasificar cada grupo de tablas como: núcleo activo / historia y trazabilidad obligatoria / compatibilidad histórica / candidato a simplificación / uso no demostrado.
3. Construir una **matriz de dependencias resumida y mantenible** en `docs/modelo-datos.md`: grupo, tablas, propietarios de datos, escrituras y lecturas, FK/triggers importantes, riesgo y conclusión. No copiar el DDL entero ni crear un diagrama de 83 tablas.
4. Revisar con especial cuidado `productos`, `ofertas_producto`, `oferta_datos_comerciales`, `ingredientes`, `articulo_configuraciones`, `almacen_articulos`, `movimientos_inventario`, `recetas`, `articulo_recetas`, `presentacion_versiones`, `tickets`, `ticket_items`, `pos_operaciones`, `reservaciones` y sus auxiliares.
5. Analizar candidatos históricos de Productos de la casa (`producto_estandares`, `producto_publicaciones`, `oferta_personalizaciones`, `recetas_casa`, versiones/componentes, `producto_cambios_locales`, `presentacion_estandares`, etc.), con búsqueda real de consumidores, FKs y triggers. Recordar que `classes/ImagenUploader.php` aún revisa algunas referencias históricas antes de borrar imágenes.
6. Identificar índices compuestos o redundantes **solo como hipótesis**. Si existe BD de desarrollo/restauración autorizada, contrastar con `INFORMATION_SCHEMA`, `SHOW INDEX`, `SHOW CREATE TABLE` y cardinalidades, todo en solo lectura.

**No hacer:** ejecutar `ALTER`, `DROP`, suprimir triggers, modificar saldos, cambiar FKs, reemplazar snapshots ni fusionar entidades en D2.

**Salida:** `docs/modelo-datos.md` con mapa funcional, matriz de estructuras, problemas confirmados, candidatos de baja/media/alta criticidad y orden de investigación; §8 con máximo cinco conclusiones.

**Validación:** todas las afirmaciones importantes incluyen referencia a DDL o consumidor PHP; comparar matriz con FKs/triggers y contratos de A4/A6. El validador confirma que no se confunda “sin referencias encontradas” con “seguro para borrar”.

**Commit esperado:** `docs: auditar modelo de datos y dependencias`.

## 6. D3 — Línea base y optimización de rendimiento

**Objetivo:** medir primero, optimizar después, bajo cargas reproducibles.

**Recorridos prioritarios, en este orden:**

1. Carga inicial de POS y catálogo de ofertas.
2. Agregar artículos y enviar comanda con varias líneas, variantes, consumo y cancelación.
3. Consulta de disponibilidad y mapa de reservaciones.
4. Búsqueda administrativa de menú/recetas.
5. Panel de finanzas/analíticas.

**Hipótesis de código por comprobar:** verificaciones repetidas `ContextoNegocio::nuevo()` / `Auth::multinegocio()` / `DatosComercialesOferta::exigirA3()`, resolución repetida de `ContextoNegocio::unidad()`, consultas por línea de venta, `TransaccionInventario::ejecutar()` (autocommit y marcadores), agregaciones de tickets y búsquedas `LIKE '%...%'`.

**Método:**

1. Confirmar versión MySQL, PHP y entorno de pruebas. Trabajar con **BD aislada con datos representativos**, nunca con DB operativa.
2. Instrumentar en desarrollo la ruta/servicio (duración total PHP, tiempo SQL, número de consultas y tiempo de transacción). No loguear nombres, contactos, tokens, credenciales, SQL con valores sensibles ni datos reales.
3. Realizar calentamiento; obtener al menos 30 muestras comparables por recorrido cuando el entorno lo permita. Registrar p50, p95, consultas por petición y volumen de datos. Si la infraestructura no está disponible, marcar `BLOQUEADO: sin línea base`; no fabricar cifras ni implementar cambios SQL especulativos.
4. Analizar consultas lentas con `EXPLAIN FORMAT=JSON` o `EXPLAIN ANALYZE` solo en versiones compatibles y operaciones SELECT seguras; nunca usar planes analizadores para escrituras contra datos activos.
5. Priorizar optimizaciones **sin cambio de esquema**: caché estrictamente por petición para identificadores/versiones comprobados, reducción de consultas duplicadas, lecturas agrupadas y eliminación de N+1 si se demuestra. Cualquier caché debe invalidarse ante cambio de conexión, contexto o marcador relevante y conservar el fallo cerrado cuando no haya validación válida.
6. Considerar índices **solo** con planes y comparativa antes/después en BD de pruebas; observar coste en INSERT/UPDATE. No desplegar índices sobre BD activa.
7. Repetir las mismas pruebas con igual dataset y comparar sin exagerar mejora ni ocultar variación.

**Validación:** tests de POS/inventario, A4, autorizaciones, reservas y concurrencia relacionados; comandos disponibles incluyen `npm run test:php`, `npm run test:js`, `npm run test:menu:a4:db`, `npm run test:articulos:m2:db`, `npm run test:almacenes:concurrencia` (revisar `package.json` y requisitos antes de ejecutar). `git diff --check`. Prohibido ejecutar suites que creen/borren BD sin verificar destino y aislamiento.

**Salida:** resultados comparativos breves en §8; pruebas/medición reproducibles, sin instrumentación permanente insegura. Si la medición no es viable, documentar metodología y bloquear cambios basados en cifras ausentes.

**Commit esperado:** `perf: eliminar sobrecostos SQL medidos` **solo si hubo una mejora validada**; en caso contrario, `docs: documentar linea base y limites de medicion` si se generó evidencia útil.

## 7. D4 — Simplificación controlada y cierre

**Objetivo:** retirar complejidad comprobada, no imponer un esquema más pequeño por estética.

**Orden permitido:**

1. Remover código y adaptadores verdaderamente muertos, con verificación de llamadas y regresiones.
2. Eliminar duplicación no funcional de consultas/validadores después de identificar su contrato y consumidores.
3. Proponer cambios de índices o esquema únicamente si D2+D3 demostraron beneficio y seguridad.
4. **No ejecutar eliminación física de tablas históricas, vistas, triggers, FKs ni migraciones destructivas en esta intervención.** Documentar su propuesta por separado con dependencias, preflight, respaldo, plan de reversión y pruebas, para futura aprobación humana.

**Invariantes de cierre:** integridad de tickets, snapshots de precio/costo, inventario y movimientos inmutables, aislamiento por unidad, roles/permisos, idempotencia, reservaciones y catálogo preservado. La supresión de tablas de Productos de la casa exige un proyecto específico posterior, porque hay dependencias históricas y referencias para imágenes.

**Validación:** `npm ci` y `npm run build` si procede, suites PHP/JS y regresiones SQL aisladas relacionadas, instalación nueva y restauración del menú cuando sea viable. Informar explícitamente cualquier suite no ejecutada y por qué. No declarar “sistema optimizado” sin evidencia D3.

**Salida:** cambios mínimos, diferencias antes/después, inventario de riesgos residuales y recomendación razonada: mantener / simplificar / migrar en fase futura.

**Commit esperado:** `refactor: simplificar compatibilidad sin alterar contratos` (solo cuando hay cambios reales validados).

## 8. Estado de ejecución — actualizar y condensar; nunca acumular logs

- `BRANCH`: `codex/higiene-docs-datos-rendimiento`; base `origin/main` = `1ffe4f1ca14591b4458b05f2cbc0bdd5de9d5cdc`, fetch completado. Se reutiliza el checkout existente; otros worktrees intactos.
- `ETAPA ACTIVA`: ninguna; D1→D4 terminadas con aceptación acotada de D3 y límites de integración explícitos.
- `D1`: APROBADA por validador independiente (Sol). README, CLAUDE, arquitectura, backlog, índice, pruebas y guías comerciales alineados con A4/M2/A6; Panadería sin POS.
  Retirado `docs/integracion-a6-main.md` (histórico recuperable en `1ffe4f1`); auditoría A4 conservada por dependencia de prueba. El plan inicial sin seguimiento se trasladó íntegro de `docs/WORK_PLAN.MD` a la raíz.
  Ejecutor y validador: `git diff --check`, `npm run test:js` y 234 enlaces locales PASS; corregidas dos inconsistencias de permisos PDF Global. Sin SQL. Commit `ce030c9`.
- `D2`: APROBADA por validador independiente (Sol). `docs/modelo-datos.md`: 83 tablas DDL cubiertas; 53 triggers, 165 FKs y 191 índices explícitos; A1 añade tabla y A3 reemplaza vista.
  Historia comercial mantiene dependencias FK/triggers/imágenes; corregidos consumidores legacy de `rol_permisos` y riesgo alto. Ninguna tabla eliminable ni índice redundante demostrados.
  Arquitectura alinea instalador; índice y expectativa documental actualizados sin quitar aserciones. Ejecutor/validador: diff, JS y 238 enlaces PASS; sin SQL ni consulta a BD. Commit `2e7d763`.
- `D3`: APROBADA por validador independiente (Sol), acotada a `FinanzasNegocio::agregados`. MariaDB 10.4.32 propio en datadir nuevo/loopback 33077; PHP 8.2.12, Node 24.12.0; 5 calentamientos + 30 muestras, sin base operativa.
  `FinanzasNegocio::agregados`: 3→2 consultas sin cambiar esquema/cachés; ocho igualdades base `2e7d763`/actual PASS. Medianas PHP 27.229→16.233 ms y 26.599→16.072 ms (~40%); p95 variable, no extrapolar a página completa.
  Catálogo, mapa/disponibilidad y búsqueda medidos; EXPLAIN JSON sin cambio de índice. Runner reproducible y guía `docs/desarrollo/rendimiento.md`; salida cruda privada `tmp/d3-rendimiento-91882c323e5c4e5eb8e4cb1d5bb33858/resultado-validacion.json` (26.187→15.862 ms p50).
  Validador: PHP/JS, sintaxis/diff y 241 enlaces PASS. Comanda/transacción no medidas: SQL1064 FOR SHARE en MariaDB; suite financiera falla idéntica base/actual en gasto fijo (causa no informada), no PASS. Bases propias limpias/servidor detenido. Commit `d3e085d`.
- `D4`: APROBADA por validador independiente (Sol). Cierre en modelo de datos, backlog, pruebas y Panadería: mantener esquema/adaptadores sin evidencia de retiro seguro; única simplificación demostrada ya en D3.
  Corregidas referencias residuales a edición maestra Global/asociación comercial cruzada. Ejecutor económico Luna: diff y 243 enlaces PASS; verificador de menú 34 comprobaciones PASS; SQL/manifiesto/assets intactos contra `origin/main`.
  Sin cambios frontend/dependencias: no procede npm ci/build; PHP/JS completos PASS D3. No nuevas instalaciones/restauración ni suites DB completas; límites D3 conservados, sin secreto local. Commit documental de cierre: el que incorpora esta entrada.
- `ULTIMA VALIDACION`: D4 APROBADO; diff/enlaces y menú contra origin/main comprobados; D1–D4 con revisión independiente y ningún cambio destructivo sobre base operativa.
- `ULTIMO COMMIT DE ETAPA`: D4, commit que incorpora esta entrada; D3 `d3e085d`; D2 `2e7d763`; D1 `ce030c9`.
- `BLOQUEO ACTUAL`: ninguno para el alcance validado; pendientes integración de comanda en motor compatible y diagnóstico del fallo de gasto fijo (ver D3/backlog).
- `SIGUIENTE PASO`: entregar resultados locales. Cualquier publicación/integración requiere autorización; no ampliar D4 para resolver incidencias previas sin una intervención específica.

Cada etapa pasa por `PENDIENTE → EN_CURSO → EN_VALIDACION → APROBADA` o `BLOQUEADA`. Al concluir, sustituir el estado anterior por **máximo cinco líneas por etapa**: archivos clave, comprobaciones ejecutadas y resultado, decisión, hash y pendientes. No registrar transcripciones de agentes, logs completos ni conversaciones. Si surge una decisión irreversible, dejar una única nota con motivo y referencia de Git.

## 9. Prompt breve reutilizable para el orquestador

Actúa como **orquestador de alta capacidad** para Casa Pestalozzi. Tu única especificación operativa es `WORK_PLAN.MD`; respeta `docs/arquitectura.md` como contrato técnico vigente. Usa una sola rama `codex/higiene-docs-datos-rendimiento`, un solo worktree y etapas D1→D4 estrictamente secuenciales. Delega cada tarea de escritura a **un ejecutor económico**, exige revisión independiente de **un validador de solo lectura** y permite hasta un commit por etapa aprobada. No crees ramas/worktrees adicionales, no hagas push/merge y no ejecutes migraciones destructivas. Lee solo las secciones generales, el estado y la etapa activa. Evita conversaciones largas; resume hallazgos en un handoff de hasta 12 líneas y mantén §8 compacto. Comienza por el preflight y D1. Tras la aprobación independiente de cada etapa, continúa automáticamente con la siguiente dentro de la misma rama. Detente solo ante un bloqueo real, conflicto con trabajo ajeno, acción destructiva o solicitud de autorización expresa; en esos casos informa el estado y no improvises.
