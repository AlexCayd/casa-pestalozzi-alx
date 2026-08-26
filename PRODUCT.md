# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

PHP 8 MVC existente, con frontend compilado desde SCSS y JavaScript; el proyecto no debe asumir una infraestructura local específica.

## Users

- Clientes del restaurante: consultan disponibilidad y crean, consultan, modifican o cancelan reservaciones desde la superficie pública.
- Personal operativo, principalmente meseros y cocina: usa POS, mapa de mesas, mapa de reservaciones y KDS para entender el estado del restaurante y ejecutar rápidamente la siguiente acción.
- Administradores: usan las herramientas operativas cuando corresponde y además gestionan reservaciones, usuarios, inventario, menú, recetas, finanzas, reportes y configuración.

Los flujos y la información deben mantenerse separados por rol. Una interfaz operativa no debe volverse administrativa sólo porque un administrador también pueda acceder a ella.

## Product Purpose

Casa Pestalozzi conecta en una sola aplicación la experiencia pública del cliente con la operación interna de un restaurante: reservaciones, mesas, POS, tickets, cocina, inventario y administración.

El éxito consiste en que cada usuario vea rápidamente lo necesario para decidir y ejecutar la siguiente acción, sin información administrativa innecesaria. Las superficies deben compartir la misma lógica de negocio y componentes cuando sea posible; en particular, el mapa de mesas y las reglas de reservaciones deben mantenerse conceptualmente consistentes.

## Positioning

Es un sistema integral de restaurante que une la experiencia pública de reservaciones con la operación diaria y la administración, manteniendo una lógica compartida entre sus superficies en lugar de tratar cada módulo como una herramienta aislada.

## Operating Context

La operación se realiza principalmente en desktop y tablet, con interacción táctil para el personal de piso. El personal necesita reconocer el estado actual del restaurante y actuar con pocos clics. Las superficies con mapas deben conservar la prioridad espacial del mapa; paneles, drawers y detalles temporales deben superponerse cuando sea posible.

Los roles `admin`, `waiter` y `cook` tienen permisos diferentes. Teléfono y correo son datos administrativos y no deben mostrarse a un waiter; nombre, notas operativas y comentarios internos pueden formar parte de la operación cuando corresponda.

## Capabilities and Constraints

- Reservaciones públicas y administrativas, disponibilidad, modificación y cancelación.
- POS, tickets, mesas, mapa de mesas, operación de reservaciones y KDS.
- Administración de menú, recetas, inventario, proveedores, finanzas, usuarios, reportes, configuración e impresoras.
- Integración y compatibilidad con n8n y los módulos existentes.
- El backend y el dominio son la autoridad para capacidad, disponibilidad, estados, tolerancias, asignación de mesas, tickets y permisos; la interfaz no debe reconstruir esas reglas.
- Mantener un único mapa de mesas compartido conceptualmente entre las superficies que lo utilizan.
- Reutilizar componentes existentes antes de crear nuevos patrones visuales.
- Evitar dashboards saturados de tarjetas, métricas o información redundante; priorizar jerarquía, espacio y acciones claras.
- En pantallas pequeñas se deben priorizar tareas esenciales y usar drawers, overlays o sheets cuando aporten claridad; responsive no significa comprimir toda la interfaz.

## Brand Commitments

La identidad es Casa Pestalozzi, un restaurante de estilo editorial/elegante. La interfaz interna debe ser sobria, oscura, de alto contraste, profesional y orientada a operación rápida; no debe sentirse como un dashboard SaaS genérico. La superficie pública y la administrativa pueden tener necesidades visuales diferentes y no necesitan compartir exactamente el mismo tratamiento.

El idioma principal es español. Deben preservarse la marca, la lógica visual existente y los cambios funcionales ya estabilizados.

## Evidence on Hand

El repositorio contiene la implementación existente de la landing pública, autenticación, reservaciones, POS, operación de mesas y reservaciones, administración, menú, recetas, inventario, finanzas, usuarios, reportes, configuración, KDS y assets tipográficos/visuales compilados. Las rutas principales están organizadas en `public/index.php`, `Router.php`, `controllers/`, `models/`, `views/`, `src/` y `public/build/`.

No deben fabricarse testimonios, métricas, clientes, precios, pruebas sociales ni claims comerciales que no estén presentes en el producto o que el usuario no haya confirmado.

## Product Principles

1. Mostrar lo necesario para responder qué está pasando y qué hay que hacer ahora.
2. Separar claramente la experiencia pública, la operación y la administración según el rol.
3. Mantener una lógica de negocio y unos componentes compartidos coherentes entre superficies.
4. Priorizar acciones rápidas, legibles y táctiles en la operación diaria.
5. Mejorar la interfaz sin alterar reglas funcionales estabilizadas del dominio.

## Accessibility & Inclusion

Mantener navegación por teclado, foco visible, contraste suficiente, labels y ARIA cuando sean necesarios, y no depender únicamente del color para comunicar estados. Los controles importantes de la interfaz operativa deben tener targets táctiles cómodos, aproximadamente de 44 px o superiores cuando corresponda.
