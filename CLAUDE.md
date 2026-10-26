# Casa Pestalozzi — instrucciones de desarrollo

Leer primero [docs/arquitectura.md](docs/arquitectura.md), la fuente principal de verdad.
Después consultar la [instalación](docs/desarrollo/instalacion.md),
[guía de desarrollo](docs/desarrollo/desarrollo.md),
[pruebas](docs/desarrollo/pruebas.md) y [backlog](docs/backlog.md).
La [clasificación documental](docs/desarrollo/clasificacion-documental.md)
identifica las guías vigentes.

## Antes de trabajar

1. Revisar rama, HEAD, `git status` y worktrees; preservar cambios previos ajenos.
2. Identificar si el módulo pertenece a Global, Restaurante o Panadería.
3. Revisar servicios compartidos y consumidores antes de crear clases nuevas.
4. Distinguir funcional y validado, preparado y pendiente: una tabla no acredita UI.
5. Reutilizar el diseño equivalente de Restaurante con configuración por ámbito.
6. Validar permisos en servidor, incluidos detalles, exportaciones y IDs manipulados.
7. Mantener DDL, DML, fixtures, rutas y consumidores sincronizados.
8. Añadir pruebas de aislamiento, autorización, revocación y operaciones atómicas.
9. Documentar resultado real y límites externos; no afirmar pruebas no ejecutadas.
10. Justificar y documentar cualquier cambio a un contrato compartido antes de editarlo.

No modificar `main`, hacer push/merge, aplicar stashes históricos, borrar trabajo
ajeno o ampliar el alcance sin autorización. Un worktree de entrega puede quedar
limpio mientras los cambios ajenos permanecen intactos en el checkout original.

## Stack y organización

PHP MVC propio, sin framework ni ORM. `public/index.php` registra rutas exactas
y llama a `Auth::proteger()` antes del ruteo de `Router.php`. Controllers y Services
coordinan casos de uso; Models persisten mediante `mysqli` y ActiveRecord propio;
Views reutilizan layouts/parciales y presentan datos preparados.

Composer mantiene los namespaces `Controllers\`, `Model\`, `Classes\`, `MVC\`
y `Services\`. Los servicios viven en `services/<Dominio>/Clase.php`, un nivel
por dominio. Usar sentencias preparadas para valores del usuario y validar cualquier
identificador SQL dinámico con una lista permitida. No introducir un SQL rewriter,
doble escritura de stock ni compatibilidad nueva con datos históricos descartados.

## Autenticación, autorización y ámbitos

La identidad y las membresías se comparten. Los permisos efectivos se asignan por
usuario, unidad y módulo (`usuario_unidad_permisos`); los roles internos no conceden
derechos implícitos. `usuarios.es_superadmin` es la excepción explícita protegida.
Consultar [el contrato técnico de autorización](docs/arquitectura.md#4-autenticacion-autorizacion-y-usuarios).

Panel utiliza `ver`/`editar`; piso `operar` para POS, KDS y mapa operativo de reservas.
La unión de permisos de panel en cualquier ámbito exige contraseña, incluso para
solo consulta. Un NIP de cuatro dígitos sirve exclusivamente para las cuentas de
piso autorizadas. Cada petición relee estado, membresías y permisos; una revocación
no espera un nuevo login. La UI no sustituye la validación del servicio.

Registrar cada API privada en `Permisos::clasificarDirecto()` o su catálogo efectivo;
una API desconocida no queda pública por omisión. Los endpoints públicos existentes
conservan tokens y autenticación de integración. El selector y los campos ocultos
no conceden ámbito ni privilegios. Preservar CSRF, límites de intentos,
reautenticación de credenciales y protección del último superadministrador activo.

Toda ruta bajo `/api/integraciones/` exige JWT HS256 y deniega por defecto,
antes de abrir sesión. Registrar su audiencia en `Permisos::INTEGRACIONES`;
consultar [API](API.md#9-jwt-de-integraciones) y [configuración](docs/config.md).
La política de segundo factor está en
[credenciales](docs/usuarios/credenciales.md#segundo-factor-administrativo).
El corte por turnos conserva la unidad vendedora de Restaurante; consultar
[operación](docs/operacion.md#corte-de-caja-por-turnos).

## Datos e instalación

El procedimiento único es `scripts/desarrollo/instalar.php`, documentado en
[instalación](docs/desarrollo/instalacion.md). Crea una base nueva identificada y
rechaza una existente. El orden es:

1. `database/ddl.sql`: estructura definitiva.
2. `database/deploy.sql`: unidades, facultades y configuración estructural.
3. `database/menu-restaurante.sql`: los 112 productos/ofertas originales y sus
   15 categorías, 3 cartas y 4 áreas; las 13 imágenes están versionadas.
4. `database/development.sql`: escenarios sintéticos con saldos trazables.
5. El seed de `database/development.sql` contiene solo el hash bcrypt de
   `admin_demo`: el instalador CLI lo habilita explícitamente en development/test;
   una importación SQL directa de los archivos de desarrollo lo habilita solo si
   no existe el marcador del instalador. El bootstrap CLI valida
   `DEV_SUPERADMIN_PASSWORD` de configuración local.

`deploy.sql` no crea `admin_demo`; el seed no almacena texto claro ni se ejecuta
en producción. El bootstrap no duplica cuentas, no promueve un homónimo ajeno ni
restablece una contraseña cambiada sin `--restablecer`. Nunca versionar texto
claro de secretos, `.env` reales, dumps privados, logs sensibles ni archivos
efímeros de pruebas.

`productos` representa identidad comercial; `ofertas_producto` contiene el precio,
categoría, área y disponibilidad del negocio. Recetas/variantes pertenecen a la
oferta. No enlazar ventas o inventario por nombre ni clonar ofertas de Restaurante
como una decisión comercial implícita de Panadería.

Cada existencia pertenece a almacén y artículo. El único saldo físico vive en
`almacen_articulos`; el consolidado Global es una consulta. Operar requiere almacén
propio y autorización del [motor de Almacenes](docs/arquitectura.md#5-almacenes-e-inventario).
Las cantidades y costos respetan DECIMAL y se transmiten como cadenas exactas.
Las ventas guardan oferta y snapshots; consumo y compensación utilizan operaciones
trazables en la misma transacción. Global no vende y las transferencias siguen
pendientes.

## Build y pruebas

```sh
composer install --no-interaction
npm ci
npm run build
npm run test:php
npm run test:js
npm run test:notifications
```

Consultar las guías enlazadas para requisitos de plataforma, configuración privada,
ensayos de instalación y límites de los runners históricos. `npm run dev` deja
watchers activos; `npm run build` termina. El procedimiento de instalación no
requiere respaldos privados ni la antigua base local.

Editar `src/`, nunca bundles manualmente:

| Fuente | Tarea / salida |
|---|---|
| `src/scss/app.scss` | `gulp css` → landing / feedback |
| `src/scss/admin/shared/app-admin.scss` | `gulp adminCss` → panel |
| `src/scss/admin/modules/*.scss` | `gulp adminModuleCss` → módulos |
| `src/scss/operation/app-operation.scss` | `gulp operationAppCss` → piso y login |
| `src/js/modules/punto-de-venta.js` y sus dependencias | `gulp adminMapJs` → `public/build/js/admin/map.js` |
| `src/js/admin/users-directos.js` | `gulp adminUsersDirectosJs` → Usuarios compartido |

Los bundles son concatenaciones en scope global: preservar el orden de dependencias
del `gulpfile.js`. `admin.js` se carga antes de los bundles de módulos; el POS usa
`map.js`, no el bundle de la landing. La UI nueva reutiliza estilos y componentes
existentes. Cualquier tarea Gulp que abarque varios módulos requiere revisar su diff.

## Reutilización de interfaz

Global y Panadería reutilizan el panel de Restaurante, parametrizado por ámbito,
permisos y acciones. Consultar la [guía técnica de interfaz](docs/desarrollo/interfaz.md)
para tokens, tipografía, componentes, accesibilidad y contratos visuales.
La landing conserva su identidad propia. Arquitectura conserva la autoridad
sobre alcance y seguridad; las guías técnicas están subordinadas a ella.

## Organización documental

- Arquitectura es la única fuente normativa. Las guías técnicas la desarrollan.
- README orienta; PRODUCT aporta contexto sin declarar módulos liberados.
- Backlog distingue defectos/deuda, mejoras y funciones nuevas pendientes.
- Instalación, desarrollo y pruebas explican procedimientos reproducibles.
- Actualizar enlaces y secciones al mover contenido. Consultar Git para informes
  consolidados; no conservar copias históricas ni contratos duplicados.
- Mantener secretos, sesiones, dumps privados y resultados efímeros fuera de Git.
  No borrar archivos locales ajenos para limpiar el repositorio.

## Criterios de aceptación

Comprobar permisos, aislamiento por propietario, revocación, IDs ajenos,
integridad, idempotencia y rollback donde corresponda. Preservar menú,
snapshots y comportamiento operativo de Restaurante.
Para cambios visuales revisar escritorio, móvil, teclado, errores, solo consulta
y temas; usar la skill UX/UI disponible y registrar resultados.
Ejecutar las pruebas de la guía vigente y documentar límites concretos.
Un HTTP 200, tabla, fixture o maqueta no acredita un módulo terminado.

Escribir comentarios, mensajes y commits en español. Los comentarios explican
decisiones e invariantes. Crear commits acotados, sin cambios ajenos. No hacer
push ni merge sin autorización explícita; dejar la entrega limpia y revisable.
