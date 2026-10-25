# Migración multinegocio

La política vigente es la [actualización obligatoria para desarrollo](actualizacion-obligatoria-desarrollo.md).
Sustituye las obligaciones anteriores de conservar registros históricos de prueba.

- [Plan maestro actualizado](plan-maestro.md): conservación, arquitectura, etapas y aceptación.
- [Contrato técnico compartido](contratos-tecnicos.md): datos, permisos, servicios e invariantes para toda implementación.
- [Procedimiento de menú](procedimiento-menu.md): exportación, archivos, reconstrucción e importación verificable.
- [Validación de esta actualización](validacion-plan.md): alcance realizado y verificaciones pendientes.
- [Etapa 2 de preservación](etapa-2-menu.md): herramientas, ejecución real y respaldo restaurado.
- [Formato de menú v1](formato-menu-v1.md): contrato del importador posterior.
- [Etapa 3A](etapa-3a.md): DDL, seeds, importador y validación aislada.
- [Modelo relacional y diagrama](modelo-relacional-3a.md): contrato físico revisado.
- [Dependencias de adaptación](dependencias-3b.md): consumidores pendientes antes de activar.
- [Contrato de autorización 3B](contrato-autorizacion-3b.md): permisos directos, autenticación y delegación.
- [Núcleo 3B.1](etapa-3b-1.md): primer commit y comprobaciones aisladas.
- [Usuarios 3B.2](etapa-3b-2.md): interfaz, pruebas, auditoría y límites de activación.
- [Contrato de Almacenes 3C](contrato-almacenes-3c.md): motor, autorización y composición transaccional.
- [Motor 3C.1](etapa-3c-1.md): implementación y pruebas aisladas de integridad/concurrencia.
- [Consultas 3C.2](etapa-3c-2.md): consolidado, filtros, autorización y cierre de etapa.

La Etapa 2 preserva el menú y su respaldo restaurable. La Etapa 3A implementa
el esquema definitivo y su importador, probados solo en bases desechables.
No se ha reconstruido ni sustituido la base de desarrollo. La adaptación de
servicios y la activación permanecen fuera de esta subetapa.

Antecedentes: [documento funcional original](../panaderia/documento-maestro-multinegocio.md)
y [demo integrada de contextos](../panaderia/etapa-1.md). El original se mantiene
como fuente recibida; su conservación histórica queda sustituida por esta política.
