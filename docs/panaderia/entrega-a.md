# Ajustes de navegación · Entrega A

El selector único está debajo del logotipo del sidebar. Es un `select` nativo
etiquetado; `src/js/admin/core/contextos.js` envía el formulario al elegir.
Se reutilizan `/admin/contexto`, Auth y Contextos. Sin JavaScript hay enlaces
alternativos autorizados. Con un solo contexto se muestra fijo.

La topbar conserva sus controles y muestra únicamente el identificador de
contexto. Los títulos principales permanecen dentro de las páginas.
Menú, Recetas e Inventarios de demostración usan las clases, hojas de estilos,
filtros, campos y tablas administrativos existentes. La búsqueda es de solo
consulta sobre fixtures. Recetas usa tabla y composición desplegable.

Verificado en navegador: selector de escritorio y drawer móvil, cambio directo
a Panadería, identificador y tabla de menú. Evidencia en
`capturas/entrega-a-desktop.png` y `capturas/entrega-a-mobile.png`.

Pruebas de contextos, sintaxis JS/PHP y build administrativo ejecutados.
El fallo inicial de `run-reservaciones-cierre-static.php` continúa idéntico;
se resolverá su contrato obsoleto durante la validación de B, sin alterar el
comportamiento de entrega del POS para acomodar la prueba.

Este commit no incluye modelo, migración ni refactorización de inventarios.
