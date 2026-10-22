# Contextos y demostración de Panadería · Etapa 1

## Alcance entregado

Una sola aplicación, autenticación y sesión; navegación distinta para Global,
Restaurante y Panadería. Se conservan controladores y lógica operativa del
Restaurante. No hay migraciones de esquema ni altas de productos de Panadería
en la base operativa. No se reutilizaron commits ni stashes de la rama anterior.

Se partió de `main`, limpio e idéntico a `origin/main` después de `git fetch`,
en la rama nueva `feature/panaderia-contextos`. No se hace push ni merge.

## Rutas

| Contexto | Ruta GET | Comportamiento |
| --- | --- | --- |
| Selector | `/admin/contexto?contexto=global\|restaurante\|panaderia` | Valida acceso y redirige al inicio autorizado. |
| Global | `/admin/global` | Entrada a los negocios. |
| Global | `/admin/global/catalogo` | Catálogo maestro pendiente. |
| Global | `/admin/global/inventario` | Consulta consolidada pendiente; no calcula saldos. |
| Global | `/admin/global/usuarios` | Espacio central pendiente; enlace identificado a gestión actual de Restaurante. |
| Global | `/admin/global/configuracion` | Configuración central pendiente; enlace identificado a configuración actual. |
| Restaurante | `/admin` y rutas existentes | Panel y módulos actuales. |
| Panadería | `/admin/panaderia` | Inicio y accesos a las consultas permitidas. |
| Panadería | `/admin/panaderia/menu` | Catálogo ilustrativo. Permiso `menu`. |
| Panadería | `/admin/panaderia/recetas` | Recetas estructuradas. Permiso `productos`. |
| Panadería | `/admin/panaderia/inventario` | Existencias ilustrativas. Permiso `inventario`. |

No se registraron rutas de venta, cobro, transferencias, movimientos o producción
para Panadería. Sus entradas en la navegación son texto deshabilitado.

## Navegación y experiencia

Se reutilizan `AdminController::render`, el layout administrativo, sidebar,
topbar, botones, tarjetas, tablas, temas y catálogo SVG. El selector usa un
control nativo etiquetado y el botón **Cambiar**. Con una sola unidad autorizada
se muestra el contexto fijo. La topbar identifica negocio y módulo, y el
sidebar marca la página actual. El selector funciona sin JavaScript.

El contexto de la petición se deriva de la ruta, no de una variable global de
sesión que pueda cambiar otra pestaña. Las rutas existentes significan
Restaurante. Cambiar de contexto es navegación, no concesión de permisos.

El buzón, su resumen y el reporte operativo permanecen en Restaurante: no se
consultan ni se muestran dentro de Global o Panadería. La identidad visual
heredada y el comportamiento responsive se describen en [diseno.md](diseno.md).

## Contrato con el responsable de permisos

`Services\Security\Contextos` aporta una frontera adicional a
`Permisos::decidir`; no reemplaza el catálogo ni la evaluación de permisos.
`Auth::proteger` exige ambas decisiones antes de ejecutar el controlador,
incluidos los endpoints protegidos ya existentes. No basta ocultar enlaces.

- `super_admin` puede entrar a los tres contextos. Su inicio tras login es Global.
- `admin` necesita una `usuarios.unidad_id` válida, resuelta por JOIN a
  `unidades.slug` (`restaurante` o `panaderia`), sin asumir IDs de producción.
- Una unidad nula o desconocida de `admin` deniega acceso; nunca hereda Global.
- El personal y los roles personalizados que hoy no tienen unidad conservan
  únicamente Restaurante y sus permisos actuales. Una asignación explícita
  inválida no usa este respaldo.
- Un rol exclusivamente de piso asignado a Panadería queda sin destino
  operativo: este piso todavía no existe. No se amplían sus permisos.
- Las tres consultas de Panadería intersectan unidad autorizada y permiso del
  módulo. Un rol con solo `menu` no abre recetas ni inventario.
- La unidad y el estado de la cuenta se releen en cada petición protegida.
  Una reasignación vale desde la siguiente petición. Si no puede verificarse
  la unidad, no se conserva una asignación anterior para autorizar operaciones.
- Una infracción de contexto responde 403 (JSON para APIs, pantalla de
  recuperación para páginas). Una infracción de módulo conserva la política
  existente de `Permisos`/`Auth`.

**Antes de desplegar:** el superadministrador debe revisar y asignar la unidad
de las cuentas `admin` existentes desde Usuarios. No se asigna Restaurante de
manera automática ni se modifican cuentas reales durante esta entrega. Una
cuenta sin unidad verá una explicación en el login, sin bucle de redirecciones.

La asociación actual de una sola unidad basta para esta etapa. La futura
multiunidad de usuarios, el alcance de la gestión de personal y roles, y el
recorte de módulos por negocio deben acordarse con el responsable de permisos.
No se creó otro modelo de roles, tabla `negocios` ni una tabla de membresías.

**Panadería continúa sin acceso operativo real**, incluso en producción. La
frontera bloquea todas las rutas protegidas de Restaurante. Las rutas públicas
de clientes e integraciones conservan sus mecanismos actuales; no se convierten
en operaciones de Panadería por tener una sesión abierta. Toda nueva API de
personal debe seguir clasificándose en `Permisos`, como exige el repositorio.

## Fixtures

Fuente: [`database/fixtures/panaderia.php`](../../database/fixtures/panaderia.php).
Solo se lee para `APP_ENV=development` o `APP_ENV=test`; cualquier otro entorno,
incluido el ausente, muestra el estado de demostración no disponible. En las
pantallas habilitadas se advierte **Datos de prueba · Solo consulta**.

El fixture tiene versión e identificadores estables como claves. Incluye
artículos con unidad, existencia y mínimo ilustrativo; cinco productos con
precio MXN, categoría, disponibilidad y preparación; recetas de capuchino,
rol de canela, masa fermentada y pan de masa madre. Cantidades y unidades
coinciden con las referencias de artículos. La receta del pan referencia la
masa fermentada y su receta intermedia. El rol tiene rendimiento de doce piezas
y salida en el artículo terminado.

No se ejecuta un motor de recetas ni se descuenta stock. El fixture no escribe
en `productos`, `ingredientes.stock`, tickets, pagos ni movimientos. Puede
importarse posteriormente a una base desechable, sin convertirse en fuente
operativa paralela.

## Inventarios futuros: contrato, sin implementación

Inventario global (consulta consolidada) → unidad de negocio → uno o varios
inventarios físicos → artículos con existencias propias.

- Cada inventario físico pertenecerá exclusivamente a una unidad.
- El catálogo de artículos será compartido; un artículo podrá existir en
  varios inventarios, con cantidades independientes.
- Global sumará y desglosará existencias por consulta, sin duplicar saldos.
- Cada negocio consultará y operará solo sus inventarios.
- Administración configurará el inventario del que se descuentan ingredientes.
- `permitir_negativos = true` permitirá descuentos con saldo negativo;
  `false` bloqueará estrictamente existencias insuficientes. La validación
  será del servidor y todas las operaciones conservarán trazabilidad.

No se añade esta configuración a la base ni se cambia la política vigente de
Restaurante. Quedan pendientes los inventarios reales, transferencias, POS de
Panadería, producción, precios comerciales y recetas operativas por negocio.

## Verificación

- `php scripts/tests/run-contextos.php`: 231 comprobaciones de contextos,
  rutas, permisos y referencias del fixture.
- `php scripts/tests/run-contextos-isolated.php`: 278 comprobaciones HTTP/MySQL
  con logins reales del fixture, los tres contextos, cuenta sin unidad,
  permisos parciales, todas las rutas protegidas de Restaurante bloqueadas
  para Panadería y reasignación de unidad sin cerrar sesión. Crea y elimina
  una base temporal; no modifica la base configurada de la aplicación.
- `php scripts/tests/run-contextos-isolated.php --browser`: entorno temporal
  para revisión manual, puerto 8088; Enter cierra servidor y elimina la base.
- `npm run test:js`: suite completa correcta.
- `npm run test:php`: se detiene en `run-reservaciones-cierre-static.php`.
  Ejecución individual de sus 39 scripts: 38 correctos y el mismo fallo.
  Exige `WHERE id = {$itemId} AND estado = 'listo'`, que no existe en el
  controlador original de `main`; ese controlador no se modificó aquí.
- Pruebas adicionales aisladas: `run-punto-venta-cierre-db.php` correcta.
  `run-pos-api-contrato-db.php` falla al buscar las rutas dentro de
  `classes/Auth.php`; el catálogo ya estaba en `Permisos.php` en `main`.
  No se cambiaron estas pruebas ni la lógica del POS para ocultar el fallo.
- Compilación `node node_modules/gulp/bin/gulp.js adminCss` correcta.
  La regeneración también actualiza tres reglas cuyo SCSS ya difería del CSS
  versionado: fallback de fuentes del logotipo y hover del calendario.
- Revisión visual en navegador: Global y Restaurante en escritorio;
  Panadería en escritorio y móvil (1440×900, 390×844), recetas en tablet
  (820×1180), drawer móvil, temas claro/oscuro y estado pendiente global.
  El selector llevó al panel actual y el POS cargó su mapa en vivo en la base
  aislada. La revisión `ux-ui` aprobó las capturas y archivos examinados.

Evidencia: [Global](capturas/global-desktop.png),
[Restaurante](capturas/restaurante-desktop.png), [menú](capturas/desktop-menu.png),
[inventario](capturas/desktop.png), [móvil](capturas/mobile.png),
[navegación móvil](capturas/mobile-nav.png), [tablet](capturas/tablet-recetas.png)
y [POS](capturas/pos-restaurante.png). Todas las capturas usan la base desechable.

La implementación y su validación específica están entregadas. **La aceptación
global de regresión queda pendiente de resolver los dos contratos existentes
señalados arriba**. La revisión visual y las pruebas de acceso no certifican
cobros reales ni integraciones externas. No se inicia ninguna etapa posterior.
