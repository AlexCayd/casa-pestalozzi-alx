# Roles y permisos

Fuente de verdad del módulo de roles y permisos: cómo decide el backend quién entra a qué, qué invariantes sostiene, cómo se extiende y qué hay que cambiar cuando empiece la integración multiempresa.

Lectura previa: [Usuarios y acceso](usuarios.md) (credenciales, NIP y contraseñas).

## Resumen

- Cada cuenta (`usuarios`) tiene **un rol** (`usuarios.rol`, llave foránea a `roles.slug`).
- Cada rol tiene, por **módulo** del sistema, uno de tres estados: sin acceso, `ver` o `editar` (`rol_permisos`).
- La guardia central decide cada petición **antes del ruteo**: `ver` abre las peticiones GET/HEAD; `editar`, todo lo demás.
- La **vía de acceso** (contraseña o NIP) no se elige: se deduce de los módulos del rol.
- Hay dos roles de **sistema** (super admin y administrador) que lo tienen todo; dos **predefinidos** (Mesero y Cocinero) editables; y los **personalizados** que se crean en el panel.
- Las **unidades** de negocio (Restaurante, Panadería) se asignan a los administradores y restringen sus contextos desde Etapa 1. Panadería solo ofrece consultas de demostración; los datos operativos por unidad siguen pendientes.

## Modelo de datos

Definido en [`database/ddl.sql`](../../database/ddl.sql), sembrado en [`database/deploy.sql`](../../database/deploy.sql).

| Tabla | Qué guarda | Notas |
| --- | --- | --- |
| `roles` | `id`, `slug`, `nombre`, `descripcion`, `tipo` | `slug` es inmutable y es lo que referencia `usuarios.rol`. `nombre` es único. `tipo` ∈ `sistema`, `predefinido`, `personalizado`. |
| `rol_permisos` | `rol_id`, `modulo`, `nivel` | Un renglón por módulo con acceso. Sin renglón = sin acceso. `ON DELETE CASCADE` desde `roles`. Los roles de sistema **no** tienen renglones: sus permisos se calculan. |
| `unidades` | `id`, `nombre`, `slug` | Restaurante (1) y Panadería (2). |
| `usuarios.rol` | slug del rol | `VARCHAR(40)` con llave foránea a `roles(slug)` **sin acciones referenciales**: MySQL no admite un `CHECK` sobre una columna que participa en una acción referencial, y el slug no cambia. |
| `usuarios.unidad_id` | unidad del administrador | `NULL` = sin unidad. Sólo se guarda para el rol `admin`; en cualquier otro rol el servicio escribe `NULL`. |

El `CHECK chk_usuarios_admin_sin_nip` impide que un rol de sistema tenga NIP. Para los roles personalizados esa regla depende de sus permisos y la aplica PHP ([`UsuarioService`](../../services/Users/UsuarioService.php)), porque un `CHECK` no puede consultar otra tabla.

### Roles sembrados

| id | slug | Nombre | Tipo | Permisos |
| --- | --- | --- | --- | --- |
| 1 | `super_admin` | Super admin | sistema | todos en `editar` (calculado) |
| 2 | `admin` | Administrador | sistema | todos en `editar` (calculado) |
| 3 | `waiter` | Mesero | predefinido | `pdv: editar` |
| 4 | `cook` | Cocinero | predefinido | `area: editar` |

Los slugs `waiter` y `cook` se conservan en inglés porque el sistema ya los usaba: cambiarlos obligaría a migrar cuentas y fixtures sin beneficio.

## Catálogo de módulos

Vive en `Permisos::MODULOS` ([`services/Security/Permisos.php`](../../services/Security/Permisos.php)). Las claves son **las mismas** que `AdminController::MODULES` (el menú del panel) y son las que se guardan en `rol_permisos.modulo`: **no se renombra una clave sin migrar la tabla**.

| Clave | Módulo | Grupo | Ruta de entrada |
| --- | --- | --- | --- |
| `analytics` | Análisis de datos | panel | `/admin/analytics` |
| `ia` | Asistente IA | panel | `/admin/ia` |
| `menu` | Menú | panel | `/admin/menu` |
| `productos` | Recetas | panel | `/admin/recetas` |
| `inventario` | Inventario | panel | `/admin/inventario` |
| `finanzas` | Finanzas | panel | `/admin/finanzas` |
| `pdv` | Punto de venta | piso | `/punto-de-venta` |
| `area` | Producción | piso | `/area` |
| `reservations` | Reservaciones | panel | `/admin/reservaciones` |
| `catas` | Catas | panel | `/admin/catas` |
| `feedback` | Feedback de clientes | panel | `/admin/feedback` |
| `tickets` | Tickets | panel | `/admin/tickets` |
| `printers` | Estaciones de impresión | panel | `/admin/printers` |
| `users` | Usuarios | panel | `/admin/usuarios` |
| `configuration` | Configuración | panel | `/admin/configuracion` |
| `roles` | Roles y permisos | panel | `/admin/roles` |

El **grupo** decide la vía de acceso: un rol con al menos un módulo `panel` entra con usuario y contraseña; un rol que sólo tiene módulos `piso` entra con NIP. `pdv` y `area` abren tanto la pantalla de tablet como su entrada del panel (`/admin/punto-de-venta`, `/admin/area`): es la misma operación vista desde otro dispositivo.

La clave `productos` corresponde a Recetas por herencia del menú lateral. Es incómoda pero estable: es lo que está en la base.

## Cómo decide el backend

### Flujo de una petición

1. [`public/index.php`](../../public/index.php) llama a `Auth::proteger($url)` antes de resolver la ruta.
2. `Permisos::clasificar($url)` dice qué protege la ruta:
   - `publica` — sitio público, `/login`, `/admin/login` y cualquier `/api/...` que no esté en `Permisos::APIS`.
   - `compartida` — `/admin` (inicio), `/admin/api/buzon*` y `/admin/api/reportes`: cualquiera con algún módulo del panel.
   - `modulos` — la ruta exige uno de una lista de módulos (basta uno).
   - `solo_sistema` — una ruta `/admin/*` que no casa con ninguna entrada de `Permisos::RUTAS`. **Sólo la abren los roles de sistema.**
3. Si hay sesión, `Auth::refrescarSesion()` relee `rol` y `activo` de la cuenta. Si la cuenta ya no existe o está inactiva, cierra la sesión. Si el rol cambió, actualiza `$_SESSION['rol']`.
4. `Auth::rolActual()` resuelve el rol de la sesión: los de sistema sin tocar la base; los demás desde `roles`/`rol_permisos`, una vez por petición.
5. `Permisos::decidir($url, $metodo, $rol)` —**función pura**— devuelve `libre`, `login`, `permitir` o `negar` (con motivo `sin_modulo` o `solo_lectura`).
6. Auth responde:

| Decisión | Página | API (`/api/*`, `/admin/api/*`) |
| --- | --- | --- |
| `login` | 302 a `/login` | 401 JSON `NO_AUTORIZADO` |
| `negar` · `sin_modulo` | 302 al destino del rol | 403 JSON `PERMISO_DENEGADO` |
| `negar` · `solo_lectura` (POST de formulario) | 302 de vuelta al `Referer` propio con aviso en sesión | 403 JSON `PERMISO_DENEGADO` |
| `negar` y el rol ya no tiene módulos | cierra sesión, 302 a `/login?resultado=sin_acceso` | — |

El aviso de sólo consulta lo consume `AdminController::render()` y lo pinta [`views/admin/layout.php`](../../views/admin/layout.php) con `AppNotice.warning()`. Va por el layout y no por las alertas de la vista porque puede caer en cualquier módulo.

### Ver y editar

El nivel requerido sale del **método HTTP**, no de una lista por ruta: `GET`/`HEAD` exigen `ver`; `POST`, `DELETE` y cualquier otro exigen `editar`. Ventaja: una ruta nueva hereda la regla sola. Consecuencias que hay que respetar:

- **Una consulta no se implementa como POST** dentro de un módulo: un rol con `ver` no podría usarla. Si una lectura necesita POST (cuerpo grande, por ejemplo), va como ruta compartida o hay que extender la tabla de rutas con un nivel explícito (ver [Deuda técnica conocida](#deuda-técnica-conocida)).
- **Una escritura no se implementa como GET**: un rol con `ver` podría ejecutarla. Es la regla HTTP de siempre, y aquí además es frontera de seguridad.

### Inicio de sesión y destino

- `Usuario::porCredenciales()` sólo encuentra cuentas cuyo rol entra con contraseña (`Rol::slugsConAcceso('contrasena')`).
- `Usuario::porNip()` sólo encuentra cuentas cuyo rol entra con NIP. Una cuenta que conserve un NIP viejo tras pasar a un rol de panel ya no entra con él.
- `Permisos::destino()`: los de sistema a `/admin`; el resto al primer módulo del panel que tengan (en el orden del menú) o, si sólo son de piso, a su pantalla. `/admin` redirige ahí a quien no tenga Análisis.

### Uso desde controladores y vistas

API de [`Classes\Auth`](../../classes/Auth.php):

| Método | Significa | Úsalo para |
| --- | --- | --- |
| `Auth::puede($modulo, $nivel = 'ver')` | el rol llega a ese nivel en ese módulo | cualquier capacidad que dependa de un módulo |
| `Auth::tienePanel()` | tiene algún módulo del panel | enlaces «volver al panel» desde el piso |
| `Auth::esAdmin()` | es rol de **sistema** (super admin o administrador) | casi nunca: prefiere `puede()` |
| `Auth::esSuperAdmin()` | es super admin | unidades y cuentas de administrador |
| `Auth::esMesero()` / `esCocinero()` | es el rol predefinido exacto | listas de «meseros» (ver abajo) |
| `Auth::nombreRol()` | nombre del rol para pintar | encabezados del piso |
| `Auth::rolActual()` | `[slug, nombre, sistema, permisos]` | servicios que necesitan el rol completo (RolService) |

`Auth::puede()` lee `$_SESSION` sin reabrir la sesión, así que también sirve en endpoints que ya llamaron a `liberarSesion()`.

Equivalencias ya aplicadas, para no reintroducir el rol escrito a mano:

- Las capacidades administrativas de reservaciones en el punto de venta —comentario interno, ver el contacto del cliente, capturarlo en el alta, superficie administrativa del mapa, forzar un no-show— son de `Auth::puede('reservations', 'editar')` (`ver` para mirar el contacto). Antes eran «rol admin».
- El detalle técnico de una impresión fallida (IP, ruta SMB) es de `Auth::puede('printers')`.
- Las listas de «meseros» (asignar mesero a un ticket, desempeño por mesero) siguen siendo **sólo el rol `waiter`**: un rol personalizado con punto de venta opera, pero no figura como mesero. Fue una decisión explícita.

## Reglas de gobierno

Viven en [`Services\Security\RolService`](../../services/Security/RolService.php) y [`AdminUsersController`](../../controllers/AdminUsersController.php). El «actor» es `Auth::rolActual()`.

| Regla | Dónde |
| --- | --- |
| Nadie da un permiso que no tiene: módulo por módulo, el nivel nuevo no pasa del suyo. | `Permisos::puedeOtorgar()` |
| Los roles de sistema no los edita ni los borra nadie, tampoco el super admin. | `RolService::puedeGestionar()` |
| No se edita ni se borra un rol con más permisos que los del actor. | `RolService::puedeGestionar()` |
| Un rol con usuarios no cambia de vía de acceso (contraseña ↔ NIP): sus cuentas tienen la credencial de la otra. | `RolService::guardar()` |
| Un rol con usuarios no se borra; los predefinidos no se borran nunca. | `RolService::eliminar()` |
| Un rol necesita al menos un módulo. | `RolService::guardar()` |
| El super admin asigna cualquier rol y gestiona cualquier cuenta. El resto, sólo roles que no sean de sistema y que quepan en sus permisos; y sólo gestiona cuentas con esos roles. | `RolService::puedeAsignar()` |
| La unidad sólo la asigna el super admin, y sólo a cuentas con rol `admin`. | `AdminUsersController`, `UsuarioService::unidadPara()` |
| Siempre queda al menos un super admin activo. | `UsuarioService` (bloquea con `FOR UPDATE`) |
| La contraseña propia se cambia siempre; la de otra cuenta, sólo si su rol es gestionable. | `AdminUsersController::cambiarPassword()` |

En la interfaz, el listado de usuarios enseña sin acciones las filas que el actor no gestiona, y el formulario de roles deshabilita las opciones por encima del tope del actor. **Las dos cosas son comodidad: el servidor las vuelve a comprobar.**

## Cómo extender

### Una ruta nueva en un módulo existente

Si cuelga de un prefijo ya mapeado (`/admin/menu/...`), no hay nada que hacer: hereda el módulo y el nivel sale del método. Si es una API fuera de `/admin/`, **hay que** añadirla a `Permisos::APIS` con su módulo; si no, queda pública.

### Un módulo nuevo del panel

1. Añadirlo a `AdminController::MODULES` (menú) y a `Permisos::MODULOS` con la **misma clave**, grupo, título, detalle y ruta.
2. Mapear sus prefijos en `Permisos::RUTAS` (y sus APIs en `Permisos::APIS`).
3. Darle icono en [`views/admin/partials/_icons.php`](../../views/admin/partials/_icons.php) con la misma clave.
4. Correr `php scripts/tests/run-roles-permisos.php`: falla si el menú y el catálogo no coinciden o si una ruta `/admin` quedó sin módulo.

Ningún rol personalizado tiene el módulo nuevo hasta que alguien se lo dé: se publica cerrado.

### Una capacidad dentro de una pantalla

Se decide con `Auth::puede('<modulo>', 'editar')` en el servidor, y la vista sólo esconde lo que el servidor ya niega. Nunca comparar `$_SESSION['rol']` con un literal.

### Retirar un módulo

Quitarlo de los catálogos deja renglones huérfanos en `rol_permisos`: `Permisos::normalizar()` los ignora, pero conviene borrarlos en la misma migración.

## Pruebas

| Prueba | Qué protege |
| --- | --- |
| [`run-roles-permisos.php`](../../scripts/tests/run-roles-permisos.php) | menú = catálogo; ninguna ruta `/admin` sin módulo; matriz de decisiones; vía de acceso y destino; quién otorga y asigna qué; orden de tablas y semillas. |
| `run-reservaciones-permissions.php`, `run-reservaciones-operational-create.php` | el punto de venta: APIs con módulo `pdv`, poderes de reservaciones por permiso, rutas administrativas cerradas al mesero. |

`Permisos::decidir()`, `acceso()`, `destino()` y `puedeOtorgar()` son puras: cualquier regla nueva se prueba ahí sin base de datos.

## Deuda técnica conocida

Ordenada por lo que más estorbaría a la integración multiempresa.

1. **Configuraciones de fila única.** `configuracion_anuncio`, `configuracion_pos`, `configuracion_paleta` y `configuracion_reservaciones` son una fila con `id = 1` (`ID_UNICO` en sus modelos). Con varias unidades cada una necesita su fila. Es el primer cambio de esquema del plan multiempresa.
2. **Nombres únicos globales que el negocio usa como llave.** `productos.nombre` es `UNIQUE` y el descuento de inventario, el costo y las sugerencias **unen por nombre** (`ticket_items.nombre` = `productos.nombre`, en `Analytics`, `AdminFinanzasController`, `PuntoVentaController` y `AreaController`). Dos unidades no podrían tener un «Latte» cada una. También son globales `mesas.numero`, `proveedores.nombre`, `areas_produccion.slug`, `horarios_operacion.dia_semana` y `excepciones_operacion.fecha`.
3. **Los botones no se esconden por permiso.** Con `ver`, el servidor rechaza el guardado, pero el botón se ve. Ningún riesgo de seguridad; sí de experiencia. Al esconderlos, hacerlo con `Auth::puede()` en la vista y nunca como sustituto del servidor.
4. **Ver/editar por método no admite excepciones.** No hay manera de declarar «este POST es una consulta». Si aparece el caso, añadir un nivel explícito por ruta en `Permisos::RUTAS` (por ejemplo `'/admin/x/buscar' => ['modulos' => ['x'], 'nivel' => 'ver']`) en vez de abrir la ruta como compartida.
5. **Permisos por módulo, no por acción.** Si hace falta «puede editar platillos pero no borrarlos», el siguiente paso es un tercer nivel o acciones por módulo; la tabla `rol_permisos` lo admite cambiando el `ENUM` del nivel.
6. **Producción no distingue estaciones.** `area` abre las cuatro (café, jugos, cocina, horno). Si un cocinero sólo debe ver la suya, se añade un permiso por estación o una tabla `usuario_estaciones`.
7. **Dos consultas extra por petición protegida** (releer la cuenta y el rol). Es barato, pero si crece el tráfico se puede cachear el rol por petición en APCu o en la sesión con una versión (`roles.updated_at`).
8. **Sin bitácora de cambios de permisos.** No queda registro de quién dio qué a quién. Antes de multiempresa conviene una tabla `auditoria_permisos` (actor, rol, antes, después, fecha).
9. **Nombres heredados.** `es_admin` en la respuesta del POS significa «edita Reservaciones»; la clave de módulo `productos` es Recetas. Renombrarlos es cosmético y caro: se documentan aquí en vez de cambiarlos.
10. **`Rol::catalogo()` se recalcula** en cada formulario de usuarios y en cada login. Bien para decenas de roles; con cientos (multiempresa), cachear por unidad.

## Integración multiempresa

Esta sección conserva el plan de datos multiempresa. La frontera inicial de navegación y acceso ya está implementada: consulta [Contextos de negocio: Etapa 1](#contextos-de-negocio-etapa-1). El resto del plan continúa pendiente.

### Estado de hoy

- **Super admin** ve todo el sistema y es el único que asigna unidades y gestiona cuentas de administrador.
- **Administrador** opera Restaurante únicamente si tiene esa unidad asignada; con Panadería solo abre su entorno de demostración. Las consultas operativas todavía no tienen filtrado multiempresa: la separación inicial bloquea las rutas del otro negocio.
- **Los datos son un solo conjunto**: ninguna tabla de negocio tiene unidad.

### Decisiones que hay que tomar antes de empezar

1. **¿Empresa o unidad?** Si «multiempresa» significa varias empresas con varias unidades cada una, hace falta una tabla `empresas` y `unidades.empresa_id`, y el super admin pasa a ser de plataforma (todas las empresas) o de empresa. Si sólo son Restaurante y Panadería de la misma casa, basta con `unidades`. El resto de este plan dice «unidad» y vale para los dos casos cambiando el nivel donde se filtra.
2. **¿Qué datos comparten las unidades?** Candidatos a ser compartidos: proveedores, ingredientes (si se compran juntos), usuarios. Candidatos a ser propios: menú, mesas, tickets, reservaciones, inventario (existencias), finanzas, configuración, catas, feedback. Cada tabla tiene que quedar en una de las dos columnas **antes** de escribir código.
3. **¿Un usuario puede trabajar en varias unidades?** Si sí, `usuarios.unidad_id` no basta: hace falta `usuario_unidades (usuario_id, unidad_id)` y una **unidad activa** en la sesión con selector.
4. **¿Los roles son globales o por unidad?** Recomendación: globales los de sistema y los predefinidos; personalizados con `roles.unidad_id` opcional (`NULL` = de toda la empresa). Un administrador sólo crearía roles de su unidad.
5. **¿Cómo se identifica la unidad en el sitio público y en las tablets?** Dominio o subdominio para la landing; configuración del dispositivo (o la unidad del usuario que inicia sesión) para el piso.

### Cambios por capa

#### Base de datos

1. Añadir `unidad_id` (o `empresa_id`) a cada tabla de negocio que sea propia de una unidad. Migración en tres pasos: columna `NULL` → *backfill* a la unidad 1 (Restaurante, que es donde vive todo hoy) → `NOT NULL` + llave foránea + índice que empiece por `unidad_id`.
2. Convertir las configuraciones de fila única en una fila por unidad: `PRIMARY KEY (unidad_id)` en lugar de `id = 1`, y quitar `ID_UNICO` de sus modelos.
3. Volver compuestos los únicos globales: `UNIQUE (unidad_id, nombre)` en `productos` (y en `menu`, `proveedores` si son propios), `UNIQUE (unidad_id, numero)` en `mesas`, `UNIQUE (unidad_id, dia_semana)` en `horarios_operacion`, `UNIQUE (unidad_id, fecha)` en `excepciones_operacion`, `UNIQUE (unidad_id, slug)` en `areas_produccion`.
4. **Cambiar las uniones por nombre a uniones por id** (`ticket_items.producto_id`) o, como mínimo, añadir `unidad_id` a cada unión por nombre. Es el cambio más delicado: hoy funciona sólo porque el nombre es único en todo el sistema.
5. Tabla `unidad_modulos (unidad_id, modulo)`: qué módulos tiene cada unidad (Panadería quizá no tiene Reservaciones ni Catas). La gestiona el super admin.
6. Si aplica la decisión 3: `usuario_unidades` y retirar `usuarios.unidad_id` (migrando su valor).
7. `nip_lookup` puede seguir siendo único global: identifica a la persona, y la unidad sale después de su asignación.

#### Contexto de unidad

Hace falta **una sola fuente** para «la unidad de esta petición»:

- Un servicio nuevo, por ejemplo `Services\Shared\Contexto::unidadId()`, que la resuelva una vez por petición: desde la sesión (unidad activa del usuario), desde el dominio (landing) o desde el dispositivo (tablet).
- Ninguna consulta debe leer `$_SESSION` para saber la unidad: siempre `Contexto::unidadId()`. Es lo que permite probarlo y cambiar el origen sin tocar cientos de consultas.
- El super admin sin unidad activa ve todo (vista consolidada); con unidad activa, sólo esa.

#### Permisos

1. **Permiso efectivo = permisos del rol ∩ módulos de la unidad.** El cambio va en un solo sitio: `Auth::rolActual()` (o una función pura nueva junto a `Permisos::decidir()`) recorta los permisos con `unidad_modulos` antes de entregarlos. `decidir()` no cambia de firma si recibe los permisos ya recortados; es lo recomendable.
2. Los roles de sistema dejan de ser idénticos: `admin` lo tiene todo **dentro de los módulos de su unidad**; `super_admin` sigue teniéndolo todo. Hoy `Permisos::esSistema()` los trata igual; habrá que distinguirlos en `rolActual()`.
3. `RolService::puedeAsignar()` y `puedeGestionar()` añaden la regla de unidad: un administrador sólo gestiona cuentas y roles de su unidad. Es una condición más en dos funciones, no un sistema nuevo.
4. `Permisos::destino()` y el menú (`AdminController::render()`) ya se basan en `Auth::puede()`: con el recorte del punto 1, se adaptan solos.
5. Selector de unidad en el topbar para quien tenga más de una (super admin, usuarios multiunidad). Cambiar de unidad cambia el contexto, no la sesión.

#### Consultas de datos

1. Cada consulta de una tabla con `unidad_id` filtra por `Contexto::unidadId()`. Para no depender de la memoria de cada desarrollador:
   - un método base en `ActiveRecord` (por ejemplo `static::deUnidad()`) que añada el filtro, y
   - una prueba de contrato que busque en `services/`, `controllers/` y `models/` consultas `FROM <tabla con unidad>` sin `unidad_id` cerca, como hace `run-roles-permisos.php` con las rutas.
2. Las altas escriben `unidad_id` desde el contexto, **nunca desde el formulario**: un `unidad_id` en el POST permitiría escribir en otra unidad.
3. Los servicios de analítica y finanzas reciben la unidad como parámetro explícito para poder dar la vista consolidada al super admin.

#### Sitio público y piso

- `Carta::publica()`, `Carta::especiales()`, `CataService::agendaPublica()`, `PaletaLanding`, el anuncio y los horarios filtran por la unidad del dominio.
- Reservaciones públicas: la disponibilidad y las mesas son de la unidad del dominio.
- Punto de venta y producción: la unidad sale del usuario que entra; si un usuario tiene varias, la tablet lo pregunta una vez y lo recuerda.
- `n8n` y las notificaciones: los mensajes llevan la identidad de la unidad (nombre, WhatsApp, horarios), hoy fijos en configuración.

### Orden recomendado

1. Tomar las decisiones de [Decisiones que hay que tomar antes de empezar](#decisiones-que-hay-que-tomar-antes-de-empezar) y dejar escrita la columna de cada tabla (compartida o propia).
2. Contexto de unidad (`Contexto::unidadId()`) con una sola unidad: todo sigue igual, pero el código ya pregunta.
3. Esquema: `unidad_id` + *backfill* + únicos compuestos + configuraciones por unidad. Uniones por id en lugar de por nombre.
4. Filtro de datos en modelos y servicios, con la prueba de contrato.
5. `unidad_modulos` y el recorte de permisos; reglas de unidad en `RolService`.
6. Selector de unidad, landing por dominio y piso por usuario.
7. Vistas consolidadas para el super admin.

Cada paso deja el sistema funcionando con una sola unidad, que es la manera de migrar sin congelar la operación.

### Lo que no hay que hacer

- **No** filtrar por unidad en las vistas o en el JS: la frontera es el servidor, igual que con los permisos.
- **No** duplicar la base por unidad: multiplica migraciones y rompe la vista consolidada.
- **No** decidir la unidad en cada controlador con su propia lógica: una sola fuente (`Contexto`), o en seis meses habrá seis maneras distintas.
- **No** reutilizar `usuarios.unidad_id` como «unidad activa»: es la asignación del administrador, no el contexto de la petición.
- **No** abrir rutas como compartidas para resolver un problema de unidad: compartida significa «del cromo del panel», no «sin dueño».

## Contextos de negocio: Etapa 1

La frontera de acceso por `usuarios.unidad_id` ya se aplica en `Auth` junto a
`Services\Security\Contextos`. Global es exclusivo de `super_admin`; `admin`
requiere unidad válida. Panadería ofrece únicamente consultas de demostración
en desarrollo/pruebas y no abre operaciones de Restaurante. El plan futuro de
datos por unidad descrito arriba continúa pendiente. El contrato vigente,
rutas y limitaciones están en [Panadería · Etapa 1](../panaderia/etapa-1.md).

## Referencias de código

- [`services/Security/Permisos.php`](../../services/Security/Permisos.php) — catálogo, tabla de rutas y decisiones puras.
- [`services/Security/RolService.php`](../../services/Security/RolService.php) — altas, cambios, bajas y reglas de gobierno.
- [`classes/Auth.php`](../../classes/Auth.php) — sesión, rol de la petición y guardia.
- [`models/Rol.php`](../../models/Rol.php), [`models/Unidad.php`](../../models/Unidad.php), [`models/Usuario.php`](../../models/Usuario.php).
- [`controllers/AdminRolesController.php`](../../controllers/AdminRolesController.php) y [`views/admin/roles/`](../../views/admin/roles/) — el módulo.
- [`controllers/AdminUsersController.php`](../../controllers/AdminUsersController.php) — asignación de roles y unidades.
- Migración para una base existente: [Configuración administrativa en config.md](../config.md).
