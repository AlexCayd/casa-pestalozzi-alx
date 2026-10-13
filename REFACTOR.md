# REFACTOR.md — Casa Pestalozzi: de ecosistema completo a plataforma de cotización

> **Para Claude Code.** Lee este documento completo antes de tocar cualquier archivo. Tu trabajo principal es **eliminar** código, no escribirlo.

---

## 1. Contexto y objetivo

El repositorio contiene el sistema actual de gestión de Casa Pestalozzi (stack documentado: **PHP + MySQL con `mysqli`, arquitectura MVC**; confirma esto en la Fase 0). Ese sistema es más grande de lo que se cotizó al cliente.

La **Cotización de Servicios Digitales (7 de octubre de 2026)** describe una versión **más sencilla**: una landing page autogestionada desde un panel de administración. El objetivo de este refactor es dejar el repositorio **exactamente al tamaño de esa cotización**:

- Conservar los módulos que ya están completamente programados y que la cotización necesita.
- Eliminar todo lo que la cotización no menciona.
- Simplificar el esquema de la base de datos hasta lo estrictamente necesario.

### Regla de oro

> **Eliminar antes que agregar.** El resultado esperado es un diff con muchas más líneas borradas que añadidas. Si necesitas escribir código nuevo sustancial, probablemente estás haciendo algo fuera del alcance de este documento: detente y repórtalo.

---

## 2. Principios de trabajo

1. **No reescribas lo que ya funciona.** Los módulos que se conservan ya tienen sus funcionalidades completas. No los reorganices, no los "mejores", no cambies su comportamiento.
2. **Cambios mínimos de pegamento.** El único código nuevo permitido es el estrictamente necesario para que lo conservado siga funcionando tras la poda (por ejemplo: quitar una ruta, ajustar un `require`, quitar una columna de un `SELECT`).
3. **Prefiere `DROP` sobre `RENAME`.** No renombres tablas ni columnas usadas por módulos conservados salvo que sea imprescindible. Menos renombres = menos riesgo de romper.
4. **Todo en una rama.** Nada de trabajo directo sobre `main`/`master`.
5. **Commits pequeños y temáticos**, uno por cada bloque eliminado, para poder revertir fácilmente.
6. **Ante la duda, no borres: marca y pregunta.** Si algo es ambiguo (no sabes si un archivo lo usa un módulo conservado), déjalo, anótalo en el inventario y consúltalo al final.
7. **Solo base de datos local.** Nunca ejecutes migraciones destructivas contra una base de datos remota o de producción.

---

## 3. Alcance de la cotización (lo que SE QUEDA)

| # | Módulo de la cotización | Qué debe poder hacer | Acción |
|---|---|---|---|
| 1 | **Landing page** | Sitio público, responsivo, que muestra el contenido de los demás módulos | **Conservar** la estructura que consume los módulos 2–6. El rediseño visual **no** es parte de este refactor |
| 2 | **Gestor dinámico de menús** | Menús de platillos y de maridajes (formato PDF interactivo) vinculados al panel; actualización en tiempo real | **Conservar** (módulo POS de menú dinámico existente) |
| 3 | **Platillos de temporada** | Sección destacada en la landing, 100% autogestiva | **Conservar** |
| 4 | **Gestor de eventos de cata** | Sección pública + CRUD (crear, editar, eliminar) desde el panel | **Conservar** (módulo de catas existente) |
| 5 | **Motor de reservaciones directo** | Formulario integrado que se enlaza a la línea de WhatsApp del restaurante | **Conservar** el formulario y el enlace a WhatsApp |
| 6 | **Horarios personalizados** | Horarios de servicio configurables + excepciones (Navidad, Año Nuevo, etc.) visibles en la landing | **Conservar** |
| 7 | **Panel de administración** | Interfaz segura y privada para gestionar menús, platillos especiales y catas sin conocimientos técnicos | **Conservar** autenticación y los CRUD de los módulos 2–6 |

### Reglas de conservación

- Un archivo, función, tabla o dependencia **se conserva solo si** es alcanzable desde alguno de los 7 módulos de arriba (directa o transitivamente).
- Si un módulo conservado tiene submódulos o variantes "extra" que la cotización no pide (por ejemplo: pagos en catas, cupos con cobro, múltiples roles de usuario, reportes), **elimina el extra** pero deja el módulo funcional.

---

## 4. Fuera de alcance (lo que SE ELIMINA)

Elimina todo lo que no esté mapeado en la sección 3. Ejemplos típicos que **probablemente** existen y deben desaparecer (verifícalo en la Fase 0, no asumas):

- Pasarelas o registros de pago, cobros, anticipos, facturación.
- Punto de venta de comandas/órdenes, mesas, cocina, tickets.
- Inventario, proveedores, compras, recetas/costeo.
- Gestión de empleados, nómina, turnos, asistencias.
- Sistema de roles y permisos granulares (la cotización solo requiere un panel privado).
- Clientes/CRM, programas de lealtad, cupones, promociones.
- Reportes, dashboards de analítica, exportaciones.
- Notificaciones por correo/SMS no relacionadas con reservaciones, colas, cron jobs.
- Multi-sucursal, multi-tenant, o cualquier preparación para SaaS (la Opción B de la cotización es un modelo comercial, **no** requiere código en este repositorio).
- Código muerto, comentado, archivos `.bak`, `_old`, `_copy`, vistas sin ruta, helpers sin referencias, assets sin uso.
- Dependencias (`composer`, `npm`, CDN) que solo usaban los módulos eliminados.

---

## 5. Plan de ejecución

### Fase 0 — Descubrimiento (sin modificar nada)

1. Identifica el stack real, el punto de entrada, el router y la estructura de carpetas.
2. Lista **todas las rutas/URLs** y a qué controlador, modelo y vista llegan.
3. Lista **todas las tablas** de la base de datos (revisa `schema.sql`, migraciones o el dump) con sus columnas y llaves foráneas.
4. Construye el grafo de dependencias: qué archivos usan qué tablas, qué vistas incluyen qué parciales, qué assets carga cada vista.
5. Genera **`REFACTOR_INVENTARIO.md`** con una tabla como esta:

   | Elemento (ruta / controlador / modelo / vista / tabla / asset) | Módulo de la cotización al que pertenece | Decisión (Conservar / Eliminar / Ambiguo) | Evidencia |
   |---|---|---|---|

6. Registra métricas **antes**: número de archivos, líneas de código (`cloc` o `git ls-files | xargs wc -l`), número de tablas, número de rutas.
7. **Detente y muestra el inventario** si hay elementos "Ambiguo". Continúa solo con lo que esté claro.

### Fase 1 — Red de seguridad

```bash
git checkout -b refactor/cotizacion-simplificada
git tag pre-refactor
# Respaldo de la base de datos local (ajusta usuario/base)
mysqldump -u <usuario> -p <base_de_datos> > backup_pre_refactor.sql
```

- Asegúrate de que `backup_pre_refactor.sql` y cualquier `.env` estén en `.gitignore` y **no** se commiteen.

### Fase 2 — Poda de código (de afuera hacia adentro)

Sigue este orden para que cada paso deje el proyecto en un estado coherente:

1. **Rutas y navegación:** quita rutas, entradas de menú del panel y enlaces de la landing que apunten a módulos eliminados.
2. **Controladores** de módulos eliminados.
3. **Vistas y parciales** que solo usaban esos controladores.
4. **Modelos / capa de datos** de entidades eliminadas.
5. **Helpers, librerías y utilidades** que queden sin referencias.
6. **Assets** (JS, CSS, imágenes, fuentes) sin referencias.
7. **Dependencias** (`composer.json`, `package.json`, `<script>`/`<link>` externos) que ya no se usen.
8. **Configuración:** constantes, variables de `.env`/`.env.example`, claves de APIs de servicios eliminados.

Después de cada bloque:

```bash
# Sintaxis PHP de todo el proyecto
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors"
# Referencias huérfanas: busca nombres de lo que acabas de borrar
grep -rniE "<nombre_borrado>" --include="*.php" --include="*.js" --include="*.css" .
```

Haz un commit por bloque (`refactor: elimina módulo X`).

### Fase 3 — Simplificación del esquema de base de datos

**Objetivo:** pasar a un esquema mínimo, de aproximadamente **8 tablas o menos**, sin columnas que ningún módulo conservado lea o escriba.

#### 3.1 Esquema objetivo (techo máximo)

Este es el **máximo** de lo que la cotización justifica. Si una tabla existente ya cubre una de estas entidades, **conserva su nombre y sus columnas funcionales** y solo elimina lo sobrante; no la recrees. Si una entidad de esta lista no existe hoy y no hay módulo que la use, no la inventes.

| Tabla | Propósito | Columnas esperadas (referencia) |
|---|---|---|
| `admin_users` | Acceso al panel (un solo rol) | `id`, `nombre`, `email` (único), `password_hash`, `created_at` |
| `menus` | Menús en PDF interactivo (platillos y maridajes) | `id`, `tipo` (`platillos` / `maridajes`), `titulo`, `archivo_pdf`, `activo`, `updated_at` |
| `platillos_temporada` | Sección destacada de temporada | `id`, `nombre`, `descripcion`, `precio` (opcional), `imagen`, `orden`, `activo`, `created_at`, `updated_at` |
| `eventos_cata` | Eventos de cata públicos | `id`, `titulo`, `descripcion`, `fecha_hora`, `lugar` (opcional), `imagen`, `activo`, `created_at`, `updated_at` |
| `reservaciones` | Registro de solicitudes (el envío principal es por WhatsApp). **Solo si el módulo existente ya las guarda** | `id`, `nombre`, `telefono`, `fecha`, `hora`, `personas`, `notas`, `created_at` |
| `horarios` | Horario semanal base | `id`, `dia_semana`, `abre`, `cierra`, `cerrado` |
| `horarios_excepciones` | Fechas especiales (Navidad, Año Nuevo…) | `id`, `fecha`, `motivo`, `cerrado`, `abre`, `cierra` |
| `configuracion` | Ajustes clave/valor (p. ej. número de WhatsApp, nombre del restaurante) | `clave` (PK), `valor` |

#### 3.2 Qué eliminar del esquema

- Tablas de módulos eliminados (pagos, órdenes, ítems de orden, mesas, inventario, empleados, clientes, roles, permisos, bitácoras, sesiones personalizadas, etc.).
- **Columnas** de tablas conservadas que ningún código conservado use (`grep` cada columna antes de borrarla).
- Llaves foráneas, índices y triggers que apunten a tablas eliminadas.
- Tablas de catálogo o de unión que solo servían a módulos eliminados.
- Consolidar usuarios y roles en `admin_users` (un único tipo de usuario del panel).

#### 3.3 Entregables de base de datos

1. **`database/migrations/001_simplificar_esquema.sql`** — script que lleva la BD actual al esquema nuevo:
   - Desactivar `FOREIGN_KEY_CHECKS` al inicio y reactivar al final.
   - Orden: primero `DROP` de llaves foráneas, luego `DROP TABLE` de tablas hijas, luego tablas padre, luego `ALTER TABLE ... DROP COLUMN`.
   - Usar `DROP ... IF EXISTS` para que sea re-ejecutable.
   - Comentar cada bloque con el módulo que justifica el borrado.
   - Recordatorio: el DDL de MySQL no es transaccional; por eso existe el respaldo de la Fase 1.
2. **`database/schema.sql`** — esquema completo **nuevo** (instalación desde cero), coherente con el resultado de la migración.
3. **`database/seed.sql`** — datos mínimos de arranque: un usuario administrador de prueba, horarios base, y un registro de ejemplo por módulo. **Sin contraseñas reales**: usa un hash de una contraseña de desarrollo documentada en el README.
4. Actualiza **todas las consultas** (`SELECT`, `INSERT`, `UPDATE`) de los modelos conservados para que no referencien columnas o tablas eliminadas.

### Fase 4 — Limpieza final

- Quita del `README.md` toda mención a módulos eliminados; deja solo: qué es, cómo instalar (`schema.sql` + `seed.sql`), cómo correr en local y la lista de los 7 módulos.
- Actualiza `.env.example` con solo las variables que realmente se usan.
- Elimina carpetas vacías y archivos sin referencias.
- Revisa que ningún secreto (credenciales, tokens, claves) haya quedado en el historial nuevo ni en archivos de ejemplo.

### Fase 5 — Verificación

**Instalación desde cero**

1. Crea una base de datos vacía local, importa `database/schema.sql` y `database/seed.sql`.
2. Levanta el proyecto en local (p. ej. `php -S localhost:8000 -t public`, o el método que use el repo).
3. No debe haber errores ni *warnings*/*notices* de PHP en el log.

**Migración desde la BD anterior**

1. Restaura `backup_pre_refactor.sql` en una base **temporal local**.
2. Ejecuta `001_simplificar_esquema.sql`.
3. Compara el resultado con `schema.sql` (`SHOW TABLES`, `DESCRIBE` por tabla): deben coincidir.

**Checklist funcional (manual, por módulo)**

- [ ] **Landing:** carga completa, responsiva (móvil y escritorio), sin enlaces rotos ni recursos 404.
- [ ] **Menús:** subir/reemplazar un PDF desde el panel → se refleja de inmediato en la landing (platillos y maridajes).
- [ ] **Platillos de temporada:** crear, editar, ocultar y eliminar desde el panel → cambia la sección pública.
- [ ] **Eventos de cata:** crear, editar y eliminar desde el panel → cambia la sección pública.
- [ ] **Reservaciones:** el formulario valida y abre/enlaza a la línea de WhatsApp del restaurante con los datos de la reservación.
- [ ] **Horarios:** editar horario semanal y agregar una excepción (p. ej. 25 de diciembre) → la landing muestra la excepción.
- [ ] **Panel:** login correcto e incorrecto, cierre de sesión, y que las rutas del panel no sean accesibles sin sesión.
- [ ] Ninguna URL de un módulo eliminado responde algo distinto de 404 / redirección limpia.

**Chequeos automáticos**

```bash
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors"
git diff --stat pre-refactor...HEAD | tail -n 1
```

---

## 6. Criterios de aceptación

- Los 7 módulos de la sección 3 funcionan igual que antes del refactor.
- No queda código, tabla, columna, ruta, asset ni dependencia de módulos fuera de la cotización.
- El esquema final tiene **≤ 8 tablas** (o se justifica cada tabla adicional por escrito).
- `schema.sql` instala el sistema desde cero y `001_simplificar_esquema.sql` migra una BD existente al mismo estado.
- El diff neto es **claramente negativo** en líneas de código.
- No hay secretos ni respaldos en el repositorio.

---

## 7. Qué NO hacer

- No rediseñar la landing ni cambiar estilos de los módulos conservados.
- No migrar de framework, ni introducir ORM, bundlers o dependencias nuevas.
- No implementar SaaS, multi-tenant, pasarelas de pago ni nada "para el futuro".
- No renombrar tablas/columnas/archivos conservados sin necesidad.
- No hacer `force push`, no tocar `main`, no ejecutar nada contra bases de datos remotas.
- No añadir funcionalidades "ya que estamos": cualquier mejora se anota en el informe, no se implementa.

---

## 8. Entregable final: `REFACTOR_REPORT.md`

Al terminar, genera un informe breve con:

1. **Métricas antes/después:** archivos, líneas de código, tablas, rutas, dependencias.
2. **Qué se eliminó**, agrupado por módulo (una línea por bloque).
3. **Esquema final:** lista de tablas y columnas.
4. **Elementos ambiguos** que dejaste sin tocar y por qué.
5. **Riesgos o pendientes** detectados (p. ej. funcionalidad conservada con deuda técnica, mejoras sugeridas fuera de alcance).
6. Resultado del checklist de verificación de la Fase 5.