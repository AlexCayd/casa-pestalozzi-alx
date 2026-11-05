# Casa Pestalozzi — instrucciones de desarrollo

Aplicación PHP 8 MVC + MySQL con tres ámbitos: **Global** (administrativo, no vende),
**Restaurante** y **Panadería**. [docs/arquitectura.md](docs/arquitectura.md) es la
única fuente normativa: alcance, propiedad, seguridad, datos y la tabla de estado de
cada módulo (§3). Si este archivo la contradice, manda arquitectura. Las guías
vigentes están en la [clasificación documental](docs/desarrollo/clasificacion-documental.md).

## Estado actual

- **Restaurante**: landing, menú, reservaciones, POS, KDS, cobro, finanzas e informes funcionales.
- **Compartidos en los tres ámbitos**: Usuarios (permisos directos), Almacenes,
  Transferencias (M0.5-B) y el Asistente IA, con consultas preensambladas por
  ámbito: Panadería pregunta por su caja, almacenes y menú; Global, por el
  consolidado de almacenes, sin ventas ([configuración](docs/config.md#asistente-ia)).
- **A4 / M2**: Menú y recetas locales, artículos unificados y presentaciones;
  Productos de la casa y su publicación Global fueron retirados
  ([catálogo](docs/modulos/catalogo-comercial.md), [recetas](docs/modulos/recetas.md)).
- **Finanzas**: Restaurante y Panadería (ventas en caja, referencias de
  panadería) y el consolidado de sólo lectura de Global
  ([finanzas](docs/modulos/finanzas.md)).
- **Análisis de datos**: Restaurante, Panadería (métricas de caja, mapa de calor de
  venta) y comparativos de menús locales en Global
  ([analíticas](database/ANALITICAS.md)).
- **Pendiente**: POS, tickets y pagos de Panadería; producción y catering
  ([backlog](docs/backlog.md)). Las
  rutas públicas `/panaderia/*` son sólo un andamio de navegación.

Una tabla, ruta, fixture o pantalla no acredita un flujo terminado.

## Antes de trabajar

1. Revisar rama, `git status` y worktrees; preservar cambios ajenos.
2. Identificar el ámbito (Global, Restaurante o Panadería) y su propietario.
3. Buscar servicios, consumidores y la UI equivalente de Restaurante antes de crear algo nuevo.
4. Mantener sincronizados DDL, `deploy.sql`, fixtures, rutas y consumidores.
5. Justificar en arquitectura cualquier cambio a un contrato compartido antes de editarlo.

No modificar `main`, hacer push/merge, aplicar stashes históricos, borrar trabajo ajeno
ni ampliar el alcance sin autorización.

## Stack

PHP MVC propio, sin framework ni ORM. `public/index.php` registra rutas exactas y
llama a `Auth::proteger()` antes de despachar con `Router.php`. Controllers coordinan;
la lógica de negocio vive en `services/<Dominio>/Clase.php` (un solo nivel, namespace
`Services\<Dominio>`; dominios en arquitectura §11); Models persisten con `mysqli` y
ActiveRecord propio; Views sólo presentan. Sentencias preparadas para todo valor del
usuario y lista permitida para identificadores SQL dinámicos.

## Seguridad

- Permisos efectivos por usuario, unidad y módulo (`usuario_unidad_permisos`); los
  roles internos no conceden nada. `usuarios.es_superadmin` es la excepción protegida.
- Panel: `ver`/`editar`. Piso (POS, KDS, mapa de reservas): `operar`. Cualquier
  permiso de panel exige contraseña y segundo factor
  ([credenciales](docs/usuarios/credenciales.md#segundo-factor-administrativo));
  el NIP de 4 dígitos es sólo para cuentas de piso.
- Cada petición relee estado, membresías y permisos. Ocultar un botón no es seguridad.
- Toda ruta privada nueva se clasifica en `Services\Security\Permisos`
  (`clasificarDirecto()`, `RUTAS`, `APIS`): una `/api/` desconocida se deniega. Una
  API pública nueva se añade a la lista explícita de `clasificarDirecto()`.
- `/api/integraciones/*` exige JWT HS256 y se registra en `Permisos::INTEGRACIONES`
  ([API](API.md#9-jwt-de-integraciones)).
- Preservar CSRF, límites de intentos, reautenticación y protección del último
  superadministrador activo. Detalle en arquitectura
  [§4](docs/arquitectura.md#4-autenticacion-autorizacion-y-usuarios).

## Datos

- `productos` es identidad comercial; `ofertas_producto` es la decisión de cada negocio
  (precio, categoría, área, disponibilidad). Recetas y variantes cuelgan de la oferta.
  Nada se une por nombre ni se clona entre negocios.
- El único saldo físico vive en `almacen_articulos`; el consolidado Global es una
  consulta. Cantidades y costos son DECIMAL y viajan como cadenas exactas.
- Las ventas guardan oferta y snapshots; consumo y compensación van en la misma
  transacción ([motor de Almacenes](docs/arquitectura.md#5-almacenes-e-inventario)).
- **Instalación**: sólo `php scripts/desarrollo/instalar.php` sobre una base **nueva**
  (rechaza una existente). Carga `ddl.sql` → `deploy.sql` → `menu-restaurante.sql`
  (112 productos originales, intocables) → `development.sql` y hace el bootstrap de
  `admin_demo`. Para bases existentes hay migraciones aditivas en
  `database/actualizaciones/`. Ver [instalación](docs/desarrollo/instalacion.md).
- Nunca versionar `.env`, secretos en claro, dumps, sesiones ni resultados de pruebas.

## Build y pruebas

```sh
composer install --no-interaction
npm ci                 # Node 24: con Node 25 no compila isolated-vm (dependencia de n8n)
npm run build          # `npm run dev` deja watchers
npm run test:php && npm run test:js && npm run test:notifications
```

La lista completa y los runners con base propia están en [pruebas](docs/desarrollo/pruebas.md).

Editar `src/`, nunca `public/build/`:

| Fuente | Tarea → uso |
|---|---|
| `src/scss/app.scss` | `gulp css` → landing y feedback |
| `src/scss/admin/shared/app-admin.scss` | `gulp adminCss` → panel |
| `src/scss/admin/modules/*.scss` | `gulp adminModuleCss` → todos los módulos |
| `src/scss/operation/app-operation.scss` | `gulp operationAppCss` → piso y login |
| `src/js/modules/punto-de-venta.js` y dependencias | `gulp adminMapJs` → `map.js` (POS) |
| `src/js/admin/users-directos.js` | `gulp adminUsersDirectosJs` → Usuarios |

Los bundles son concatenaciones en scope global: el orden de `gulpfile.js` resuelve
dependencias. `admin.js` carga antes que los bundles de módulo; el POS usa `map.js`,
no el bundle de la landing. Una tarea que recompila varios módulos exige revisar su diff.

## Interfaz y diseño

Global y Panadería reutilizan el panel de Restaurante parametrizado por ámbito,
permisos y acciones. La mecánica visual (tokens, tipografía, componentes, piso,
landing, movimiento) está en la [guía técnica de interfaz](docs/desarrollo/interfaz.md).
Las reglas que más se rompen: son **dos sistemas visuales distintos a propósito** (la
landing con la marca del restaurante; panel, POS, KDS y login en el sistema
administrativo blanco y negro), ningún hex ni `rgba()` fuera de `_reset.scss` y
`_globals.scss` salvo las excepciones de la guía, y prohibidos
`alert()`/`confirm()`/`prompt()`.

### Todo cambio visual pasa por el subagente `ux-ui`

**Obligatorio**, no opcional: antes de dar por terminado cualquier trabajo que cree o
modifique un archivo de `src/scss/`, un JS con animación, scroll o interacción, o el markup
de una vista con implicación visual, hay que invocar el subagente **`ux-ui`**
(`~/.claude/agents/ux-ui.md`, nivel de usuario: sirve a todos los proyectos) con la lista de
archivos tocados y qué debía conseguir la pantalla. Juzga concepto, jerarquía, tipografía,
color, movimiento y accesibilidad, y decide si entra tal cual.

Ahí vive el criterio de diseño; aquí y en la guía de interfaz, la mecánica de este
proyecto. El agente lee este archivo primero, así que lo de esta sección manda sobre
cualquier preferencia suya — en particular **que son dos sistemas visuales distintos a
propósito**: proponerle a la landing algo del panel, o al revés, es un error de lectura,
no una idea.

## Aceptación y entrega

- Probar permisos, aislamiento por propietario, revocación, IDs ajenos, integridad,
  idempotencia y rollback según corresponda. Preservar menú, snapshots y la operación
  de Restaurante.
- En cambios visuales, revisar escritorio, móvil, teclado, errores, solo consulta y temas.
- Informar sólo pruebas ejecutadas, con sus límites; un HTTP 200 no acredita un módulo.
- Comentarios, mensajes de UI y commits en español; los comentarios explican el porqué.
  Commits acotados y sin cambios ajenos.
