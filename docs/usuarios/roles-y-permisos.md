# Permisos directos y ámbitos

El nombre del archivo se conserva para no romper referencias históricas. La
fuente efectiva desde 3B es la asignación directa, no el rol. El
[contrato rector](../arquitectura.md) rige esta implementación y su expansión.

## Modelo de datos

| Entidad | Responsabilidad |
|---|---|
| `usuarios` | Identidad, estado y hashes; `es_superadmin` es la excepción organizacional explícita |
| `unidades` | Slugs Global, Restaurante y Panadería; no suponer IDs constantes |
| `usuario_unidades` | Membresía activa y metadatos internos, con `rol_id` nullable |
| `usuario_unidad_permisos` | Permiso directo por usuario, unidad y módulo |
| `usuario_unidad_facultades` | Facultades especiales delegadas por ámbito |
| `roles` / `rol_permisos` | Infraestructura interna; no concede derechos implícitos ni plantillas nuevas |

Ausencia de asignación deniega. Panel utiliza `ver` y `editar`; editar incluye
lectura. Piso utiliza `operar` para los módulos operativos expresamente definidos.
El catálogo real vive en `PermisosUsuario::catalogo($contexto)` y los niveles
válidos se comprueban antes de persistirlos.

## Autenticación

La unión de permisos en cualquier ámbito determina el acceso: con algún permiso
de panel, contraseña; exclusivamente POS/KDS/mapa operativo, NIP de cuatro dígitos.
`es_superadmin` exige contraseña. Un permiso de panel en Panadería también impide
utilizar una sesión NIP para operar Restaurante.

`Auth`, `Contextos`, `Permisos` y `PermisosUsuario` conservan una sola autenticación.
Cada petición protegida carga el perfil actual, comprueba estado, membresía,
nivel alcanzado y permiso. No hay un respaldo que convierta un fallo de BD en
permisos concedidos. Ocultar acciones en la UI no reemplaza esta frontera.

## Gobierno y delegación

- Consultar Usuarios exige `users/ver` y la facultad de gestión correspondiente.
- Mutar exige `users/editar`, `usuarios.gestionar` y `permisos.delegar` según la
  operación. El backend comprueba los límites aunque se manipule el formulario.
- Global puede delegar entre ámbitos autorizados sin otorgar más derechos que el
  actor; un negocio solo administra su propio ámbito.
- Una cuenta compartida no entrega su identidad, contraseña o estado global al
  administrador local. Revocar la membresía local conserva las ajenas.
- El CRUD ordinario no acepta `es_superadmin`. El último superadministrador activo
  no puede retirarse; las operaciones sensibles bloquean las filas afectadas.
- Cambiar credenciales exige la reautenticación que corresponde al caso de uso.
  No se acepta un NIP escrito por el cliente como parte de un alta normal.

`AccesosUsuario::catalogoFacultades()` proporciona los nombres/etiquetas que usa
el formulario y el conjunto que valida la delegación. `solo_global` limita las
facultades de crear almacenes físicos o editar el catálogo maestro al ámbito
Global. Poseer lectura consolidada no concede escritura sobre almacenes ajenos.

## Cómo decide el backend

`public/index.php` llama a `Auth::proteger()` antes del ruteo.
`Permisos::clasificarDirecto()` clasifica rutas públicas explícitas, módulos,
compartidas y rutas denegadas. Una API privada desconocida no queda pública por
defecto. Las APIs públicas conservan su autenticación de token/integración.

GET/HEAD solicitan lectura; las mutaciones exigen edición o la operación de piso
correspondiente. Los servicios recargan el perfil al ejecutar sus casos de uso;
el contexto y actor efectivos proceden del servidor, no de campos ocultos.
Los módulos futuros de Global/Panadería siguen bloqueados aunque exista su DDL.

## Cómo extender

Definir ámbito, propiedad, permiso, facultades y rutas antes de agregar un módulo.
Actualizar catálogo, guardia, servicio, DDL/DML, interfaz y pruebas de aislamiento
juntos. Reutilizar el módulo equivalente de Restaurante. No reintroducir permisos
por nombre de rol ni una segunda autenticación por ámbito.

## Pruebas

La suite `run-multinegocio-3b.php` cubre identidad, credenciales, delegación,
revocación, cuentas compartidas y protección de superadministrador. Su recorrido
HTTP comprueba autenticación y rechazos reales. `run-usuarios-3e1.php` añade los
tres ámbitos y la correspondencia entre facultades/techos de formulario y servicio.
Ver [pruebas de aceptación](../desarrollo/pruebas.md).

## Referencias

- [Usuarios y acceso](usuarios.md).
- [Contrato técnico 3B](../arquitectura.md#4-autenticacion-autorizacion-y-usuarios).
- [Instalación y bootstrap](../desarrollo/instalacion.md).
- [Índice documental](../desarrollo/clasificacion-documental.md).
