# Casa Pestalozzi — Actualización obligatoria del plan de migración

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


## Contexto

Casa Pestalozzi se encuentra completamente en desarrollo.

No existen datos productivos que deban conservarse durante la migración multinegocio.

Todos los registros actuales son datos de prueba, con una única excepción: el catálogo de productos del menú de Restaurante.

## 1. Política de conservación

Preservar exclusivamente los productos actuales del menú de Restaurante y los datos necesarios para reconstruir correctamente sus fichas y presentación:

- Identidad de producto.
- Nombre.
- Descripción.
- Imágenes y archivos asociados.
- Precio.
- Categoría.
- Carta correspondiente.
- Disponibilidad.
- Metadatos indispensables para que el producto siga siendo visible y comercializable.

Conservar los IDs originales cuando resulte conveniente; si cambian, mantener correspondencias verificables durante la importación.

No es obligatorio preservar las recetas, variantes, existencias ni registros históricos asociados a los productos.

## 2. Datos que pueden reiniciarse

Se permite reconstruir desde cero:

- Usuarios de desarrollo.
- Membresías y asignaciones de roles.
- Inventarios y almacenes.
- Ingredientes.
- Existencias.
- Movimientos.
- Recetas y subrecetas.
- Variantes.
- Reservaciones.
- Tickets.
- Pagos.
- Datos financieros.
- Analíticas.
- Configuraciones de prueba.
- Fixtures de Panadería.

Crear nuevos datos de prueba coherentes con la arquitectura multinegocio.

No introducir lógica compleja exclusivamente para conservar información descartable.

## 3. Estrategia de base de datos

Priorizar un esquema definitivo y limpio.

Actualizar:

- `database/ddl.sql`.
- `database/deploy.sql`.
- `database/development.sql`, cuando corresponda.
- Fixtures y scripts de prueba.

Puede reconstruirse la base de desarrollo, pero primero debe existir una exportación verificable del menú y una copia de seguridad del estado anterior.

Nunca ejecutar scripts destructivos sobre una base no identificada explícitamente como entorno de desarrollo o pruebas.

No utilizar una migración histórica compleja cuando baste reconstruir el esquema e importar los productos conservados.

Evitar:

- Doble escritura de existencias.
- Adaptadores destinados exclusivamente a esquemas descartados.
- Reconstrucción de movimientos antiguos.
- Migración de tickets de prueba.
- Conciliación financiera histórica.
- Compatibilidad con estructuras anteriores que ya no se utilizarán.

## 4. Importación del menú

Crear un procedimiento reproducible para:

1. Exportar los productos actuales.
2. Conservar los archivos de imagen asociados.
3. Reconstruir categorías y cartas necesarias.
4. Crear el esquema definitivo.
5. Importar los productos como catálogo comercial de Restaurante.
6. Verificar conteos, datos e imágenes.
7. Confirmar que aparecen correctamente en el menú administrativo y en las vistas públicas correspondientes.

No cargar automáticamente productos de Restaurante en Panadería.

Generar los productos de Panadería mediante fixtures independientes.

## 5. Protección funcional de Restaurante

Se permite modificar su estructura interna cuando sea necesario para adoptar la arquitectura definitiva.

Sin embargo, deben conservarse los comportamientos funcionales:

- Login.
- POS.
- Mesas.
- Comandas.
- Reservaciones.
- Menú.
- Recetas.
- Inventario.
- Finanzas.
- Analíticas.
- KDS e impresión.

Las pruebas deberán utilizar datos nuevos compatibles con el esquema.

No conservar registros antiguos únicamente para hacer pasar pruebas históricas.

No eliminar pruebas funcionales vigentes; adaptar sus fixtures y contratos cuando el esquema cambie justificadamente.

## 6. Actualización de documentos

Modificar los documentos de `docs/migracion/` para reflejar esta política.

En particular:

- Eliminar obligaciones de conservación histórica innecesarias.
- Simplificar el plan de migración de base de datos.
- Sustituir conciliaciones antiguas por pruebas de reconstrucción e importación.
- Actualizar los criterios de aceptación.
- Reducir dependencias entre etapas cuando sea posible.
- Mantener las pruebas de seguridad y consistencia de las operaciones nuevas.

## 7. Reorganización de etapas

Reevaluar la planificación tomando en cuenta que se permite reconstruir la base de desarrollo.

Priorizar la implementación directa del esquema definitivo en lugar de mantener dos modelos funcionando simultáneamente.

Los subagentes deben utilizar un contrato técnico compartido y no implementar mecanismos de retrocompatibilidad que ya no sean necesarios.

## 8. Entrega

Actualizar el plan maestro y presentar:

- Esquema simplificado de migración.
- Datos que se conservarán.
- Procedimiento de exportación e importación del menú.
- Secuencia actualizada de etapas.
- Riesgos funcionales restantes.
- Pruebas necesarias.

No ejecutar todavía la reconstrucción de la base ni comenzar módulos adicionales.

**Esta política sustituye cualquier instrucción anterior que exija conservar datos históricos de prueba.**