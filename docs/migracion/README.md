# Antecedentes de migración multinegocio

La fuente principal vigente es [docs/contrato.md](../contrato.md). Para instalar o
continuar desarrollo, consultar [instalación](../desarrollo/instalacion.md),
[desarrollo](../desarrollo/desarrollo.md), [pruebas](../desarrollo/pruebas.md) y
[clasificación documental](../desarrollo/clasificacion-documental.md).

## Referencias técnicas utilizadas

- [Autorización directa 3B](contrato-autorizacion-3b.md).
- [Motor de Almacenes 3C](contrato-almacenes-3c.md).
- [Servicios comerciales 3D](etapa-3d-contratos-comerciales.md).
- [Modelo relacional](modelo-relacional-3a.md), como explicación del núcleo;
  la estructura ejecutable completa está en `database/ddl.sql`.

## Evidencia histórica

Los documentos siguientes describen su revisión original. No son instrucciones
para activar retrospectivamente esquemas ni para reconstruir desarrollo desde
respaldos privados:

- [Plan maestro](plan-maestro.md), [política recibida](actualizacion-obligatoria-desarrollo.md),
  [propuesta técnica](contratos-tecnicos.md) y [validación del plan](validacion-plan.md).
- [Etapa 2](etapa-2-menu.md), [procedimiento de preservación](procedimiento-menu.md)
  y [formato de paquete](formato-menu-v1.md).
- [Esquema 3A](etapa-3a.md) y [dependencias previas a adaptación](dependencias-3b.md).
- [Núcleo 3B.1](etapa-3b-1.md) y [Usuarios 3B.2](etapa-3b-2.md).
- [Motor 3C.1](etapa-3c-1.md) y [consultas 3C.2](etapa-3c-2.md).
- [Auditoría 3D](etapa-3d-auditoria.md) y [cierre 3D](etapa-3d-cierre.md).

Las capturas 3B se conservan como evidencia de esa entrega; no sustituyen la
validación actual de UI. Los [antecedentes de Etapa 1](../panaderia/README.md)
conservan sus diferencias de alcance y precedencia.
