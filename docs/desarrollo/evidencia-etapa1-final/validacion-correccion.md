# Validación: histórico de precios e informes

- Rama de partida: branch-leo, revisión 9680661a896e.
- `npx gulp adminIaJs`, `npx gulp adminInventarioJs` y `npx gulp adminModuleCss`: completados.
- `npm run test:js` y `npm run test:informes`: correctos.
- `php scripts/tests/run-informes-aceptacion-local.php`: aceptación completa con base aislada; cinco módulos, rutas canónicas y heredadas, validación, permisos y PDF. El caso IA ejecuta la función JavaScript real con la respuesta de la API y sigue la URL resultante con la sesión HTTP; el navegador se simula en ese paso.
- `php scripts/tests/run-proveedores-http.php`: 27 comprobaciones HTTP y navegador real en Chromium. Ratón, toque y teclado sobre los puntos 0, 20 y 39 en 1440, 768 y 390 px; 40 puntos, sin desbordamiento global ni errores de página.
- Las rutas canónicas producen PDF también con `php -S` sin router de desarrollo. `public/.htaccess` reescribe rutas inexistentes a `index.php` conservando la consulta, por lo que las rutas canónicas siguen el mismo patrón que el resto de rutas MVC. No se ejecutó una prueba contra Apache de producción.
- Las capturas anteriores proceden de la evidencia existente en la revisión base; las posteriores se capturaron con datos sintéticos aislados en los tres anchos.
