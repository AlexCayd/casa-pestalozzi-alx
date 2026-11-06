# WORK_PLAN.MD — Reconciliación de `branch-leo` con `main`

> **Proyecto:** Casa Pestalozzi (`AlexCayd/casa-pestalozzi-alx`).
> **Intervención:** R0 → R1 → R2 → R3. Este archivo **sustituye integralmente** el anterior plan D1–D4.
> **Rama única:** `branch-leo`. **Worktree único:** el ya existente. **Objetivo de Git:** un solo commit de integración al terminar, sin push ni merge posterior a `main`.
> **Norma funcional:** `docs/arquitectura.md`, conciliada con el código actual. Este plan es operativo, no una segunda norma.
> **Ubicación canónica autorizada:** `docs/WORK_PLAN.MD`; sustituye al antiguo `WORK_PLAN.MD` de la raíz. El usuario confirmó conservar esta ubicación.

## 0. Objetivo y situación verificada

**Objetivo:** integrar los cambios nuevos de `main` con el trabajo D1–D4 ya realizado en `branch-leo`, sin perder funcionalidades ni la mejora financiera, actualizar la documentación y validar el resultado antes del commit.

**Referencias al redactar el plan** (comprobar de nuevo en R0):

| Referencia | SHA abreviado | Observación |
|---|---|---|
| `branch-leo` | `bd5a39b` | Contiene cuatro commits D1–D4: `ce030c9`, `2e7d763`, `d3e085d`, `bd5a39b` |
| `main` | `8139808` | Incluye caja/POS de mostrador de Panadería y cambios recientes de POS/KDS |
| Ancestro común | `1ffe4f1` | `branch-leo` 4 commits adelante / 7 detrás de `main` |

**D1–D4 no se repiten**: documentación depurada, auditoría estática de datos, ensayo de rendimiento y cierre conservador. Se preservan `docs/modelo-datos.md`, `docs/desarrollo/rendimiento.md`, `scripts/tests/run-rendimiento-d3.php` y la optimización de `services/Finance/FinanzasNegocio.php` salvo evidencia de incompatibilidad.

**Diferencias que obligan a reconciliar:** el DDL de `branch-leo` declara 83 tablas; el de `main` declara 87 por `vitrina_piezas`, `vitrina_piezas_variante`, `ticket_anulaciones` y `configuracion_avisos`. En `main` ya no existen `database/actualizaciones/m1-facultad-comercial.sql` ni `m12-estandares.sql` como archivos separados. `main` implementa caja de Panadería (`/panaderia/caja`); documentos de `branch-leo` todavía la describen como pendiente. En `main` se añadieron funcionalidades y pruebas de caja, vitrina, avisos, variantes y POS/KDS: **preservarlas**.

**Éxito:** (a) una rama integrada sin conflictos, (b) código y datos nuevos de `main` intactos salvo corrección justificada, (c) mejora financiera conservada o revertida con evidencia y autorización, (d) documentos actuales coherentes con caja funcional, (e) suites pertinentes ejecutadas o limitaciones explícitas, (f) un único commit validado, sin publicar.

## 1. Reglas estrictas: seguridad y Git

1. **Trabajar exclusivamente sobre `branch-leo` y el worktree actual.** No crear ramas, worktrees, PR, commits de checkpoints, cherry-picks, stash automático ni procesos que editen simultáneamente. No cambiar `main` ni hacer push.
2. **Un único commit nuevo, idealmente un merge commit** con los cambios de conciliación incluidos. No crear un commit separado para este plan, la documentación o las pruebas. Si la integración necesita commits adicionales, detenerse y solicitar autorización.
3. Preflight: `git status --short`, `git branch --show-current`, `git rev-parse HEAD`, `git fetch origin main`, `git rev-parse origin/main`, `git merge-base origin/main branch-leo`, `git log --oneline --left-right origin/main...branch-leo` y `git diff --name-status <merge-base>..origin/main`. **No asumir que los SHAs anteriores siguen vigentes.**
4. Al iniciar se permite **únicamente** el reemplazo autorizado del plan: `WORK_PLAN.MD` eliminado en la raíz y `docs/WORK_PLAN.MD` nuevo. El usuario confirmó conservarlo en `docs/`. Identificar, leer y proteger ese diff. Cualquier otro cambio sin commit es trabajo ajeno: detener la fusión hasta aclararlo; no sobrescribir, no limpiar, no ocultar con stash.
5. Con preflight seguro, preparar la integración con **`git merge --no-commit --no-ff origin/main`** desde `branch-leo`. Resolver conflictos y ajustar documentos **antes del único commit**. El commit se realiza solamente después de la validación R3. No usar `git merge -X ours/theirs`, `git checkout --ours/--theirs` para archivos completos ni `git rebase` para simplificar a la fuerza.
6. Prohibidos: `git reset --hard`, `git clean -fd`, `push --force`, borrados de datos, eliminación de ramas ajenas, uso de la base operativa para ensayos y regeneración masiva de recursos sin motivo. Si se requiere abortar, comprobar que `git merge --abort` no perderá el `WORK_PLAN.MD` preexistente; preservar su contenido antes de ejecutar acciones que puedan descartarlo.
7. **No ejecutar** `database/ddl.sql` ni `database/development.sql` sobre bases existentes o productivas: el DDL tiene reset destructivo. Las suites DB solo pueden ejecutarse tras inspeccionar que crean/usan una base **aislada propia**, verifican propietario y limpian únicamente sus datos de ensayo. No hacer `DROP`, `TRUNCATE` ni migraciones en una base ajena.
8. Conservar contratos: Global no vende ni administra ofertas comerciales; cada negocio es propietario de sus ofertas y almacenes; permisos por ámbito, inventario y movimientos inmutables, snapshots de ticket, transacciones, reintentos idempotentes, cancelaciones, reservaciones, y las **112 ofertas originales** del Restaurante.
9. No ampliar el alcance hacia rediseño de POS/KDS, nuevos módulos, eliminación de tablas históricas, refactors genéricos ni correcciones de defectos ajenos sin relación demostrable con la integración. Si aparece una anomalía preexistente, clasificarla, documentarla y mantener acotado el trabajo.

## 2. Organización de agentes y contexto reducido

| Rol | Modelo recomendado | Escritura | Alcance |
|---|---|---|---|
| **Orquestador** | GPT-6.1 Sol (High) | Control de Git y estado | Evalúa riesgo, prepara `merge --no-commit`, asigna tareas, decide aceptación y crea el commit único |
| **Ejecutor** | GPT-6 Luna (Medium/High); Terra para conflictos técnicos medios | Único escritor de archivos en cada tarea | Resuelve conflictos puntuales, documentación, pruebas y ajustes delimitados |
| **Validador independiente** | GPT-6.1 Terra (High); **Sol** para cambios de SQL/transacciones/permisos/inventario | Solo lectura | Revisa diff, invariantes, ejecutabilidad y evidencias; no valida su propio parche |

**Ejecución serial:** orquestador → ejecutor → validador → corrección (si aplica) → siguiente tarea. No crear agentes por archivo, ni investigaciones redundantes. Solo un agente escribe a la vez. El orquestador controla todos los comandos de Git; el ejecutor **no** crea commits ni modifica `main`.

**Handoff máximo 12 líneas:** `ETAPA`, `OBJETIVO`, `LEER`, `EDITAR`, `INVARIANTES` (máx. 3), `PRUEBAS`, `HALLAZGOS`, `RESULTADO` (`APROBADO` / `CAMBIOS_REQUERIDOS` / `BLOQUEADO`). Compartir nombres de archivos, símbolos, líneas y diff, no transcripciones completas. Cada agente lee **§0, §1, §2, §6 y solo la etapa activa**, y los fragmentos de código pertinentes. Condensar §6 al final de cada etapa. Logs extensos quedan fuera de `WORK_PLAN.MD` y de los mensajes entre agentes.

**Escalamiento a Sol:** conflictos de lógica PHP que afecten caja/POS, finanzas, autorización, inventario, reservas, FKs, transacciones o snapshots; fallos inexplicados de integridad; decisiones de descartar código de una rama. El validador debe ser independiente del autor del parche.

## 3. R0 — Preflight y mapa de colisiones (solo lectura)

**Meta:** confirmar SHAs/estado local y construir lista mínima de conflictos reales antes de fusionar.

- Verificar identidad de rama, cambios locales y ancestro. Si `origin/main` avanzó más allá del estado verificado, revisar nuevos commits antes de continuar; no integrar cambios desconocidos ciegamente.
- Comparar `git diff --name-status <base>..branch-leo` con `git diff --name-status <base>..origin/main`. **Posibles colisiones ya identificadas:** `CLAUDE.md`, `docs/arquitectura.md`, `docs/backlog.md`, `docs/desarrollo/pruebas.md`, `docs/modulos/catalogo-comercial.md` y `docs/panaderia/README.md`. Confirmar con Git.
- Identificar las adiciones nuevas de `main`: `services/Pos/CajaMostrador.php`, `controllers/CajaPanaderiaController.php`, `services/Menu/VitrinaCaja.php`, `docs/modulos/caja-panaderia.md`, scripts de pruebas, rutas y DDL. No atribuir a `branch-leo` su implementación.
- Registrar en §6: SHAs, qué cambió, cambios ajenos, aptitud para fusionar. **Puerta:** rama correcta, plan preservado, colisiones comprendidas.

## 4. R1 — Integración sin commit y resolución de conflictos

**Meta:** incorporar **todo** `origin/main` sin perder D1–D4 ni la caja de Panadería.

- Ejecutar `git merge --no-commit --no-ff origin/main` solo cuando R0 pase. Si hay conflictos, resolver uno a uno según intención funcional; no asumir que «main gana» o «branch-leo gana» para archivos completos.
- **Código PHP, SQL, JS, SCSS, assets y rutas:** preservar las funcionalidades nuevas de `main`; no reescribirlas por ajuste documental. Mantener `FinanzasNegocio::agregados()` de D3: `ingresos`, `sinCosto` y `cogs` en una agregación, con `tickets` y `propinas` en otra. Si `main` introduce otro comportamiento financiero, comparar cuidadosamente antes de adaptar la consulta.
- **Arquitectura, CLAUDE, backlog y Panadería:** usar como estado vigente las funciones verificadas en `main`. La caja de mostrador vende y cobra, maneja vitrina/horneadas, anula ventas del día y permite corte de consulta; **producción física por lotes, catering y otros pendientes permanecen según contrato actual**. No llamar «POS pendiente» a toda Panadería; distinguir la caja actual de un eventual POS de restaurante con mesas/KDS.
- **D1:** retener el enfoque de documentación breve, jerarquía normativa y enlaces válidos, integrando los cambios recientes de `main`. `docs/desarrollo/pruebas.md` no debe perder instrucciones nuevas ni afirmar suites no ejecutadas.
- **D2:** preservar la matriz y D3, corregir cifras y referencias históricas obsoletas según el DDL y el instalador de la versión fusionada. No reintroducir migraciones retiradas por `main`.
- **Plan:** actualizar este documento durante el merge y registrar solo decisiones relevantes. Se mantiene como único archivo de control de esta intervención.
- Revisar conflictos/estado con `git diff --name-only --diff-filter=U`, `git diff --check`, `git status --short` y `git diff --cached` cuando corresponda. **Puerta:** cero marcadores de conflicto, `MERGE_HEAD` pendiente (sin commit) y funcionalidad coherente.

## 5. R2 — Reconciliación documental, esquema y regresiones

**Meta:** validar la versión integrada, no volver a auditar el proyecto desde cero.

### R2-A. Documentación y datos

- Corregir `README.md`, `CLAUDE.md`, `docs/arquitectura.md`, `docs/panaderia/README.md`, `docs/backlog.md`, `docs/desarrollo/pruebas.md`, `docs/desarrollo/clasificacion-documental.md` y `docs/modulos/catalogo-comercial.md` **solo donde difieran del código fusionado**. Preservar `docs/modulos/caja-panaderia.md` y la documentación nueva de avisos, vitrina y variantes.
- En `docs/modelo-datos.md`: actualizar desde el `database/ddl.sql` resultante: **87 CREATE TABLE y 53 CREATE TRIGGER** verificados para `main` antes de la fusión; documentar las cuatro tablas nuevas, dependencias relevantes y el efecto A1 (`oferta_datos_comerciales`) cuando corresponda. Volver a contar FKs/índices si el DDL cambió y no reutilizar cifras de la revisión antigua sin verificarlas. Distinguir **DDL versionado** de estructura observada en una BD real.
- Corregir referencia a migraciones: en `main` están `m2-articulos.sql`, `a1-datos-comerciales-oferta.sql` y `a3-datos-comerciales-local.sql`; corroborar instalador y ruta real antes de afirmar cómo se actualizan bases existentes.
- Comprobar navegación y enlaces; conservar auditorías históricas cuando alguna prueba las requiera y no recuperar archivos históricos solo para hacer pasar enlaces.

### R2-B. Pruebas y rendimiento

- **Primero pruebas sin BD:** `git diff --check`, validación de enlaces/documentos, `npm run test:php`, `npm run test:js`, `npm run test:menu-preservacion` y lint/sintaxis PHP de archivos afectados. Registrar comandos, resultado y entorno; no inventar PASS.
- **Pruebas de BD (solo tras verificar aislamiento de cada runner):** `npm run test:caja-panaderia`, `npm run test:menu:a4:db`, `npm run test:articulos:m2:db`, `npm run test:analytics:ambito:db` y las pruebas de POS/inventario/reservaciones afectadas. Usar pruebas adicionales de caja/vitrina/variantes/avisos definidas por `package.json` y los scripts efectivamente presentes. La existencia del comando no certifica seguridad de ejecución.
- Si el entorno tiene dependencias de compilación, `npm run build` y verificar que los assets generados correspondan a fuentes nuevas; **no regenerar/borrar assets a ciegas** ni mezclar cambios de estilo no solicitados.
- **D3 financiero:** comprobar equivalencia de `FinanzasNegocio::agregados` en ambos negocios y periodos, incluidas partidas canceladas y `costo_unitario` nulo/cero; preferir medición base/actual con la misma BD y orden alternado para controlar calentamiento. `scripts/tests/run-rendimiento-d3.php` usa referencia `2e7d763` y configuración de MariaDB/puerto aislados: inspeccionar su instalación y protección de borrado antes de correrlo; actualizar fixtures de forma legítima si el esquema cambió, sin falsear resultados.
- **Bloqueos anteriores D3:** no se midió la comanda porque MariaDB 10.4.32 rechazó `FOR SHARE` (SQL 1064); una suite financiera falló en «Panadería guarda su gasto fijo» **tanto antes como después** del cambio. Registrar ambos como **no resueltos** salvo nueva prueba concluyente. No alterar bloqueos ni semántica transaccional para forzar compatibilidad. Si se dispone de MySQL 8 compatible y aislado, medir comanda y cancelación sin tocar BD activa.
- Ante fallos, separar **regresión nueva**, **fallo ya presente en `main`/D3**, **entorno** y **no ejecutado**. Comprobar sobre referencias apropiadas cuando sea seguro; no declarar PASS por equivalencia de dos fallos.

**Puerta R2:** cambios documentales y de compatibilidad pequeños, pruebas críticas sin regresiones nuevas comprobadas, evidencias resumidas de suites y bloqueos. Una prueba crítica sin evaluación fiable impide aprobación incondicional.

## 6. R3 — Validador final, commit único y reporte

**Meta:** revisión independiente de lo que se va a guardar en Git.

- Validador comprueba `git diff` (incluyendo cambios staged y unstaged), ausencia de conflictos/markers, nombres de archivos modificados, autorizaciones, aislamiento, caja funcional, contabilidad y conservación del contrato de datos. Revisar que el **único cambio de negocio procedente de D1–D4** siga siendo la agregación financiera localizada.
- Revisar y resumir el resultado de cada prueba: `PASS`, `FAIL_PREEXISTENTE`, `FAIL_NUEVO`, `BLOQUEADO_ENTORNO` o `NO_EJECUTADO`; nunca colapsarlos en «todo aprobado».
- **Condición del commit:** cero conflictos, cambios de código de riesgo evaluados, documentación coherente y cero regresiones nuevas conocidas en funciones críticas. Si queda un problema crítico no verificado, no hacer commit y reportar bloqueo + siguiente acción segura.
- Actualizar §7 como máximo 8 líneas, ejecutar `git add` **solo** sobre los archivos revisados y crear **un único merge commit** con mensaje sugerido `merge: reconciliar main con D1-D4 en branch-leo`. Verificar `git rev-list --parents -n 1 HEAD` (dos padres), `git status --short` (limpio), `git log -1 --oneline` y `git merge-base --is-ancestor origin/main HEAD`.
- **No push, no PR y no merge adicional a main.** Entregar resumen de cambios, pruebas realmente ejecutadas, riesgos abiertos, hash del commit y recomendación de aptitud para integración posterior a `main`.

### Estado operativo (mantener breve)

- `ETAPA`: R0/R1/R2 aprobados; R3 validación técnica apta para el único merge commit.
- `RAMA`: `branch-leo` `bd5a39b`; `origin/main` `8139808`; base `1ffe4f1`.
- `MERGE`: cuatro conflictos documentales resueltos; cierre mediante un único merge commit local, sin publicación.
- `PRUEBAS`: PHP, JS, menú, build, lint inicial93/final4, enlaces263 y suites aisladas MySQL PASS; detalle en pruebas.
- `RENDIMIENTO`: equivalencia 8/8; comanda MySQL medida; detalle en rendimiento.
- `RIESGOS`: MariaDB `FOR SHARE` 1064 y gasto fijo Panadería FAIL_PREEXISTENTE.
- `ENTORNO`: servidores de prueba cerrados; cero bases fixture; puertos 33077/33078 libres.
- `SIGUIENTE`: dictamen final independiente, crear y verificar el único merge commit; sin push, PR ni cambios a main.

## 7. Control compacto

Pruebas y límites en [pruebas](desarrollo/pruebas.md) y
[rendimiento](desarrollo/rendimiento.md); revisión independiente y cierre mediante
un único merge commit local con padres `bd5a39b` y `8139808`.
Consultar su identidad con `git log -1`; no incorporar un hash autorreferente.
Sin BD operativa, push, PR ni merge a main; servidores privados cerrados.
