# Clasificación documental

Revisión del 25 de octubre de 2026. [Arquitectura](../arquitectura.md) es la única fuente normativa sobre modelo, permisos, propiedad, estado de módulos, datos y límites. Esta tabla clasifica todos los documentos que permanecen en el árbol; un informe histórico o una referencia técnica no sustituye esa fuente.

## Criterios

- **Vigente:** guía actual o índice operativo subordinado a Arquitectura.
- **Referencia técnica:** inventario/explicación de una pieza ejecutable concreta.
- **Histórico:** evidencia o propuesta de una etapa anterior, sin instrucciones de activación presentes.
- **Obsoleto:** documento que ya no describe un proceso útil; los casos detectados se retiraron.
- **Duplicado:** copia normativa reemplazada por Arquitectura; no se deja una segunda fuente.

## Inventario de fuentes conservadas

| Archivo | Clasificación | Uso y precedencia |
|---|---|---|
| [Arquitectura](../arquitectura.md) | Vigente, rector | Fuente única para alcance y decisiones de arquitectura. |
| [Backlog](../backlog.md) | Vigente | Separa defectos/deuda, mejoras y funciones nuevas. |
| [Configuración](../config.md) | Vigente | Variables de entorno y configuración real. |
| [Deuda arquitectónica](../correcciones_pendientes_arquitectura.md) | Referencia técnica | Hallazgos puntuales vigentes; no reemplaza pruebas ni autoriza refactor masivo. |
| [Clasificación](clasificacion-documental.md) | Vigente | Este inventario; actualizarlo al añadir o retirar documentación. |
| [Guía de desarrollo](desarrollo.md) | Vigente | Procedimiento de trabajo para futuras entregas. |
| [Informe 3E](entrega-3e.md) | Vigente | Evidencia de cierre y estado de integración. |
| [Instalación](instalacion.md) | Vigente | Procedimiento único desde archivos versionados. |
| [Pruebas](pruebas.md) | Vigente | Runners y aceptación verificable. |
| [Almacenes](../modulos/almacenes.md) | Referencia técnica vigente | Interfaz y operación del motor compartido. |
| [Operación](../operacion.md) | Referencia técnica vigente | POS, impresión e idempotencia de Restaurante. |
| [Panadería: índice](../panaderia/README.md) | Histórico | Índice de evidencia de la primera etapa; no documenta el estado final. |
| [Panadería: diseño](../panaderia/diseno.md) | Histórico | Propuesta original; las decisiones vigentes están en Arquitectura. |
| [Panadería: documento maestro](../panaderia/documento-maestro-multinegocio.md) | Histórico | Contexto de la propuesta inicial, no contrato actual. |
| [Panadería: entrega A](../panaderia/entrega-a.md) | Histórico | Evidencia fechada de la primera entrega. |
| [Panadería: etapa 1](../panaderia/etapa-1.md) | Histórico | Antecedente de alcance y diseño. |
| [Panadería: validación inicial](../panaderia/validacion-multinegocio-etapa-1.md) | Histórico | Evidencia de su etapa; no valida la aceptación 3E. |
| [Privacidad](../privacidad/privacidad.md) | Vigente | Tratamiento y exposición de datos personales. |
| [Notificaciones de reservas](../reservaciones/notificaciones.md) | Referencia técnica vigente | Contrato de transporte y límites del proveedor. |
| [Reservaciones](../reservaciones/reservaciones.md) | Referencia técnica vigente | Reglas y operación del agregado; Arquitectura fija el alcance organizacional. |
| [Credenciales](../usuarios/credenciales.md) | Vigente | Bootstrap seguro y credenciales de panel/piso. |
| [Permisos directos](../usuarios/roles-y-permisos.md) | Referencia técnica vigente | Detalle de implementación subordinado a §§3–4 de Arquitectura. |
| [Usuarios](../usuarios/usuarios.md) | Referencia técnica vigente | Entidades, rutas y componentes subordinados a §4 de Arquitectura. |
| [README](../../README.md) | Vigente | Índice de entrada al producto, instalación y guías. |
| [Instrucciones de agentes](../../CLAUDE.md) | Vigente | Pasos de trabajo; remite a Arquitectura como autoridad. |
| [API](../../API.md) | Referencia técnica | Inventario de rutas; el router y clasificador ejecutable mandan. |
| [PRODUCT](../../PRODUCT.md) | Referencia técnica | Contexto de producto subordinado al alcance real de Arquitectura. |
| [Impresión](../../classes/Impresion/README.md) | Referencia técnica | Implementación y requisitos del adaptador; hardware no queda certificado. |
| [Analíticas](../../database/ANALITICAS.md) | Referencia técnica | Consultas/criterios de informes; no declara módulos pendientes funcionales. |
| [n8n](../../n8n/README.md) | Referencia técnica | Frontera y configuración de la integración externa. |
| [Despliegue n8n](../../n8n/deploy/README.md) | Referencia técnica | Receta externa; no acredita una entrega real del proveedor. |

Las capturas conservadas bajo `docs/panaderia/capturas/` son **históricas**: se retienen como evidencia visual de la etapa inicial, no como aceptación actual. Los documentos de Panadería permanecen porque aportan contexto histórico y visual; sus propuestas no autorizan duplicar interfaces ni servicios.

## Documentos retirados

- `docs/contrato.md` era una segunda copia normativa no versionada. Su contenido se integró y amplió en `docs/arquitectura.md`; se elimina la fuente divergente.
- Los planes, contratos y reportes por etapa 2/3A/3B/3C/3D se retiraron del árbol de documentación vigente. La reconstrucción actual, sus contratos técnicos necesarios y el plan posterior se expresan en Arquitectura, guías vigentes y backlog. La historia de Git conserva el cambio.
- `docs/panaderia/contratos-multinegocio-etapa-1.md` solo repetía enlaces a esos contratos y ya no aportaba evidencia técnica independiente.

No se eliminó código, DDL, DML, test, imagen preservada del menú original ni evidencia de pruebas válida. El clasificador de documentación automática y los enlaces locales deben pasar después de cada actualización de esta lista.
