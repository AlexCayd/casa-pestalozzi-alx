# Migración multinegocio

La política vigente es la [actualización obligatoria para desarrollo](actualizacion-obligatoria-desarrollo.md).
Sustituye las obligaciones anteriores de conservar registros históricos de prueba.

- [Plan maestro actualizado](plan-maestro.md): conservación, arquitectura, etapas y aceptación.
- [Contrato técnico compartido](contratos-tecnicos.md): datos, permisos, servicios e invariantes para toda implementación.
- [Procedimiento de menú](procedimiento-menu.md): exportación, archivos, reconstrucción e importación verificable.
- [Validación de esta actualización](validacion-plan.md): alcance realizado y verificaciones pendientes.
- [Etapa 2 de preservación](etapa-2-menu.md): herramientas, ejecución real y respaldo restaurado.
- [Formato de menú v1](formato-menu-v1.md): contrato del importador posterior.

La Etapa 2 implementa exportación, verificación y respaldo con ensayo aislado.
No se ha reconstruido la base de desarrollo ni implementado el esquema
multinegocio. El importador definitivo permanece pendiente del DDL aprobado.

Antecedentes: [documento funcional original](../panaderia/documento-maestro-multinegocio.md)
y [demo integrada de contextos](../panaderia/etapa-1.md). El original se mantiene
como fuente recibida; su conservación histórica queda sustituida por esta política.
