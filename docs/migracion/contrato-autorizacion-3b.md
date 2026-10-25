# Contrato de autorización 3B

**Referencia técnica vigente de los servicios compartidos.** El origen 3B se
conserva en el nombre; el [contrato rector 3E](../contrato.md) define el estado
actual y la [guía de Usuarios](../usuarios/usuarios.md) describe su interfaz.


La asignación directa sustituye el rol por membresía como fuente efectiva. Se
conservan roles/rol_permisos como infraestructura interna sin editor nuevo ni
derechos implícitos; rol_id es metadata nullable. La excepción organizacional
proviene solo de usuarios.es_superadmin, releída en servidor, nunca del cliente.

usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) tiene PK triple y FK
a membresía. Panel: ver/editar; piso: operar, claves pdv/area/reservations_map.
Este último es mapa operativo, distinto de reservations administrativo. Edición
incluye lectura; operar no concede panel. Ausencia de fila deniega. Operaciones
de piso se conceden solo en Restaurante mientras Panadería no tenga POS operativo.

Perfil resuelto por Services\Security\PermisosUsuario::cargar(id,db opcional):
id, nombre, username, activo, super y ambitos indexados por slug. Cada ámbito:
id (unidad), permisos (modulo=>nivel), facultades (lista de claves). Solo incluye
membresías activas; no lee ni suma rol_permisos. Fallo de BD deniega sin respaldos.
tipoAcceso(perfil) calcula contrasena si super o algún permiso de panel en la unión;
solo piso implica nip; ningún módulo implica ninguno. La sesión guarda el nivel
alcanzado y una sesión nip no se amplía al aparecer permisos administrativos.

Auth continúa siendo el único login/guardia y conserva hashes/HMAC. El contrato
directo exige la versión de esquema 3B; no se mezclan permisos ni credenciales
de esquemas. La instalación 3E crea su identidad y membresías nuevas sin migrar
usuarios de pruebas descartados ni modificar la base antigua.

Gestión: Global con users y facultad usuarios.gestionar puede administrar ámbitos
de la organización; negocio solo el propio. Escritura exige users/editar y para
asignar/revocar permisos, permisos.delegar. El techo por módulo es el permiso del
actor en el contexto desde el que administra: el permiso local en un negocio
o el permiso Global cuando delega desde Global. Una membresía adicional del
actor en el destino no amplía ese techo. Tener Global no concede entrar a otros ámbitos. Sin techo para
un módulo no puede concederlo; super es excepción explícita. Facultades nuevas
también están limitadas a las efectivamente delegadas. Ningún formulario acepta
es_superadmin, rol interno ni una ampliación de Global para sí mismo.

Cuentas multiunidad: administrador local solo modifica su membresía/permisos;
identidad, credenciales, estado global y accesos ajenos quedan protegidos. Una
cuenta exclusivamente local sí permite gestionar identidad/credenciales con sus
facultades. Ultimo superadministrador activo protegido mediante bloqueo de sus
filas en transacciones de gestión. Promoción nip→panel exige contraseña nueva y
elimina PIN; degradación a piso genera PIN sin conceder login por contraseña.

Servicios públicos para integración de Usuarios (UsuarioService):
listarDirectos(actorId,contexto,filtros=[]), detalleDirecto(actorId,contexto,id),
guardarDirecto(actorId,contexto,id nullable,datos), estadoDirecto(actorId,contexto,id,activo),
eliminarDirecto(actorId,contexto,id), regenerarNipDirecto(actorId,contexto,id).
Datos guardar: username,nombre,activo,password,password_confirm,ambitos:
slug=>{activo:bool,permisos:modulo=>nivel,facultades:list}. Rechazar claves ajenas,
valores inválidos y alcances fuera de delegación; nunca confiar en campos ocultos.
Respuestas: ok,codigo,errores y usuario; nip plano solo al crearlo/regenerarlo y
se muestra una sola vez con no-store. Listado/detalle nunca devuelven hashes/PIN.

La adaptación se prueba en bases desechables. La instalación 3E reconstruye
una base nueva desde recursos versionados, sin aplicar el DDL a la base antigua.
Usuarios y Almacenes tienen interfaces compartidas por ámbito; un módulo futuro
no se habilita por la sola existencia de sus tablas o por asignarle un permiso.
