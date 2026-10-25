# Clasificación documental

Fecha de revisión: 25 de octubre de 2026. El [contrato rector](../contrato.md)
es la única fuente principal de arquitectura y estado. Las referencias técnicas
explican contratos concretos; los antecedentes no compiten como procedimientos
actuales de instalación, activación o autorización.

## Criterios

- **Vigente:** guía operativa o normativa actual subordinada al contrato rector.
- **Referencia técnica:** explicación de servicios, capas o integración.
- **Histórico:** evidencia o propuesta de una etapa anterior con cabecera explícita.
- **Obsoleto:** instrucciones sustituidas; se conserva la ruta como puente cuando tiene enlaces.
- **Duplicado:** copia redundante de otra fuente; no se identificó una copia independiente que deba mantenerse como contrato.

## Inventario revisado

Cada archivo documental existente se clasifica a continuación. Las capturas
preexistentes se conservan como evidencia histórica y no como validación de 3E.

| Archivo | Clasificación | Tratamiento y motivo |
|---|---|---|
| [API.md](../../API.md) | Referencia técnica | Inventario API; el router y clasificarDirecto son la fuente ejecutable. |
| [CLAUDE.md](../../CLAUDE.md) | Vigente | Entrada para agentes; contrato, reglas y mecánica de UI. |
| [PRODUCT.md](../../PRODUCT.md) | Referencia técnica | Contexto de producto y diseño, subordinado al contrato rector. |
| [README.md](../../README.md) | Vigente | Índice principal de producto e instalación. |
| [classes/Impresion/README.md](../../classes/Impresion/README.md) | Histórico | Diseño técnico anterior; sus ejemplos no son instrucciones para aplicar DDL/DML. |
| [database/ANALITICAS.md](../../database/ANALITICAS.md) | Histórico | Diseño técnico anterior; sus ejemplos no son instrucciones para aplicar DDL/DML. |
| [docs/arquitectura.md](../arquitectura.md) | Referencia técnica | Capas, dominios y relaciones compartidas; enlaza el DDL ejecutable. |
| [docs/backlog.md](../backlog.md) | Vigente | Defectos/deuda, mejoras y funcionalidades futuras separados. |
| [docs/config.md](../config.md) | Vigente | Variables y configuración funcional de la aplicación. |
| [docs/contrato.md](../contrato.md) | Vigente | Única fuente principal de arquitectura, alcance y estado real. |
| [docs/correcciones_pendientes_arquitectura.md](../correcciones_pendientes_arquitectura.md) | Referencia técnica | Deuda técnica identificada; no autoriza una refactorización masiva. |
| [docs/desarrollo/clasificacion-documental.md](clasificacion-documental.md) | Vigente | Inventario y justificación de conservación. |
| [docs/desarrollo/entrega-3e.md](entrega-3e.md) | Vigente | Informe verificable de aceptación y entrega de 3E. |
| [docs/desarrollo/desarrollo.md](desarrollo.md) | Vigente | Protocolo para módulos posteriores. |
| [docs/desarrollo/instalacion.md](instalacion.md) | Vigente | Procedimiento único desde archivos versionados. |
| [docs/desarrollo/pruebas.md](pruebas.md) | Vigente | Suites y criterios; resultados en el informe de la revisión. |
| [docs/migracion/README.md](../migracion/README.md) | Histórico | Índice de antecedentes que enlaza primero las fuentes vigentes. |
| [docs/migracion/actualizacion-obligatoria-desarrollo.md](../migracion/actualizacion-obligatoria-desarrollo.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/capturas-3b/usuario-panaderia-movil.jpg](../migracion/capturas-3b/usuario-panaderia-movil.jpg) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/migracion/capturas-3b/usuarios-global.jpg](../migracion/capturas-3b/usuarios-global.jpg) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/migracion/capturas-3b/usuarios-panaderia.jpg](../migracion/capturas-3b/usuarios-panaderia.jpg) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/migracion/capturas-3b/usuarios-restaurante.jpg](../migracion/capturas-3b/usuarios-restaurante.jpg) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/migracion/contrato-almacenes-3c.md](../migracion/contrato-almacenes-3c.md) | Referencia técnica | Métodos, autorización, precisión y transacciones del motor utilizado. |
| [docs/migracion/contrato-autorizacion-3b.md](../migracion/contrato-autorizacion-3b.md) | Referencia técnica | Firmas e invariantes vigentes de asignaciones directas. |
| [docs/migracion/contratos-tecnicos.md](../migracion/contratos-tecnicos.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/dependencias-3b.md](../migracion/dependencias-3b.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-2-menu.md](../migracion/etapa-2-menu.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3a.md](../migracion/etapa-3a.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3b-1.md](../migracion/etapa-3b-1.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3b-2.md](../migracion/etapa-3b-2.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3c-1.md](../migracion/etapa-3c-1.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3c-2.md](../migracion/etapa-3c-2.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3d-auditoria.md](../migracion/etapa-3d-auditoria.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3d-cierre.md](../migracion/etapa-3d-cierre.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/etapa-3d-contratos-comerciales.md](../migracion/etapa-3d-contratos-comerciales.md) | Referencia técnica | Contratos de oferta, snapshots, consumo y compensación. |
| [docs/migracion/formato-menu-v1.md](../migracion/formato-menu-v1.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/modelo-relacional-3a.md](../migracion/modelo-relacional-3a.md) | Referencia técnica | Explica el núcleo relacional; no sustituye las extensiones del DDL actual. |
| [docs/migracion/plan-maestro.md](../migracion/plan-maestro.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/procedimiento-menu.md](../migracion/procedimiento-menu.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/migracion/validacion-plan.md](../migracion/validacion-plan.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/modulos/almacenes.md](../modulos/almacenes.md) | Vigente | Interfaz compartida y límites físicos de Almacenes. |
| [docs/operacion.md](../operacion.md) | Vigente | Operación POS, idempotencia e impresión de Restaurante. |
| [docs/panaderia/README.md](../panaderia/README.md) | Histórico | Índice de antecedentes que enlaza primero las fuentes vigentes. |
| [docs/panaderia/capturas/desktop-menu.png](../panaderia/capturas/desktop-menu.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/desktop.png](../panaderia/capturas/desktop.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/entrega-a-desktop.png](../panaderia/capturas/entrega-a-desktop.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/entrega-a-mobile.png](../panaderia/capturas/entrega-a-mobile.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/entrega-a-recetas.png](../panaderia/capturas/entrega-a-recetas.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/global-desktop.png](../panaderia/capturas/global-desktop.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/mobile-nav.png](../panaderia/capturas/mobile-nav.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/mobile.png](../panaderia/capturas/mobile.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/pos-restaurante.png](../panaderia/capturas/pos-restaurante.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/restaurante-desktop.png](../panaderia/capturas/restaurante-desktop.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/capturas/tablet-recetas.png](../panaderia/capturas/tablet-recetas.png) | Histórico | Captura de la etapa original; conserva evidencia y enlaces, sin acreditar aceptación actual. |
| [docs/panaderia/contratos-multinegocio-etapa-1.md](../panaderia/contratos-multinegocio-etapa-1.md) | Obsoleto | Contrato sustituido; la ruta conserva enlaces hacia las fuentes actuales sin copiar otro contrato. |
| [docs/panaderia/diseno.md](../panaderia/diseno.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/panaderia/documento-maestro-multinegocio.md](../panaderia/documento-maestro-multinegocio.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/panaderia/entrega-a.md](../panaderia/entrega-a.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/panaderia/etapa-1.md](../panaderia/etapa-1.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/panaderia/validacion-multinegocio-etapa-1.md](../panaderia/validacion-multinegocio-etapa-1.md) | Histórico | Antecedente de migración o evidencia fechada, identificado con cabecera y precedencia 3E. |
| [docs/privacidad/privacidad.md](../privacidad/privacidad.md) | Vigente | Captura mínima, acceso a contacto y límites de tratamiento. |
| [docs/reservaciones/notificaciones.md](../reservaciones/notificaciones.md) | Vigente | Transporte y autoridad PHP/n8n de comunicaciones. |
| [docs/reservaciones/reservaciones.md](../reservaciones/reservaciones.md) | Vigente | Reglas de reservas, capacidad, mapa y operación. |
| [docs/usuarios/credenciales.md](../usuarios/credenciales.md) | Vigente | Bootstrap y NIP sin valores planos versionados. |
| [docs/usuarios/roles-y-permisos.md](../usuarios/roles-y-permisos.md) | Vigente | Permisos directos; ruta conservada sin autorizaciones implícitas por rol. |
| [docs/usuarios/usuarios.md](../usuarios/usuarios.md) | Vigente | Identidad, membresías y credenciales. |
| [n8n/README.md](../../n8n/README.md) | Referencia técnica | Frontera de integración y procedimientos del proveedor. |
| [n8n/deploy/README.md](../../n8n/deploy/README.md) | Referencia técnica | Receta externa con límites de validación explícitos; no acredita entregas reales. |

## Decisiones de limpieza

No se movieron masivamente documentos ni se eliminaron capturas o pruebas útiles.
Se conserva la estructura conocida para no romper enlaces de herramientas y
evidencia. La separación histórica se hace mediante cabeceras e índices, sin
crear copias divergentes del contrato rector.

Se sustituyeron instrucciones obsoletas dentro de documentos vigentes: permisos
por rol, APIs desconocidas públicas, comanda sin idempotencia, seed automático de
credencial conocida y ALTER históricos sobre una base existente. La historia de
Git y los informes conservan el antecedente; no hay otra guía que invite a repetirlo.

El README raíz se normalizó a UTF-8 y pasó de títulos de plantilla a un índice
de producto e instalación. CLAUDE conserva la mecánica visual de Restaurante,
pero usa permisos directos y el único procedimiento de DDL/DML/bootstrap. No
se versionan valores de contraseñas ni secretos locales.

Los documentos nuevos y las revisiones posteriores actualizan este inventario
y comprueban enlaces locales/anclas con el verificador. Un documento histórico
con comandos sigue siendo evidencia de su etapa, no una autorización para
ejecutarlos en la instalación actual.

## Aceptación y límites

El inventario no declara todos los módulos funcionales. La matriz del contrato
y el informe final registran implementación y pruebas realmente alcanzadas.
Las limitaciones de hardware, infraestructura y proveedores externos permanecen
explícitas en sus guías.
