# 404 de informes en el servidor integrado de PHP

## Causa reproducida

La URL reportada era:

```text
http://localhost:3000/admin/finanzas/informe.pdf?desde=2026-10-01&hasta=2026-10-31
```

El servidor se inició desde `public/` con `php -S localhost:3000`, sin router. En
esa modalidad, el servidor integrado busca un archivo real para una URL con extensión
`.pdf`. Como no existe `public/admin/finanzas/informe.pdf`, devuelve su propio `404
Not Found` HTML antes de ejecutar `public/index.php`; por tanto el 404 no procede de
`Router` ni de `AdminFinanzasController::informe`.

Reproducción: con el mismo servidor, `/admin/finanzas/informe.pdf?...` respondió
404 y el HTML estándar del servidor de PHP; `/admin/finanzas/informe?...` llegó a la
aplicación y, sin sesión, respondió 401 de autenticación. La suite aislada confirma
también que una ruta desconocida sin extensión llega al 404 de la aplicación. No se
modificó Apache ni se inició el servidor Apache del sistema.

## Solución local

Inicia el servidor integrado con el router del proyecto, desde `public/`:

```powershell
cd C:\xampp\htdocs\casa-pestalozzi\public
php -S localhost:3000 ../scripts/dev-router.php
```

O desde la raíz del repositorio:

```powershell
cd C:\xampp\htdocs\casa-pestalozzi
php -S localhost:3000 -t public scripts/dev-router.php
```

El router deja servir archivos estáticos de `public/` y envía rutas dinámicas,
incluidos los endpoints `.pdf`, al front controller. Los aliases sin extensión
(`.../informe`) también permiten que los enlaces de informes funcionen si el
servidor se inició accidentalmente sin router. Las cinco rutas `.pdf` originales
continúan registradas y usan los mismos handlers.

## Evidencia automatizada

`scripts/tests/informes-aceptacion/informes-http-independent.php` compara ambos modos
con una instalación temporal independiente:

- Sin router: alias sin extensión llega a la guardia de la app; URL `.pdf` produce el
  HTML 404 del servidor; una ruta sin extensión desconocida produce el 404 de la app.
- Con el router del proyecto: los cinco informes generan PDFs tanto en el alias como
  en la ruta `.pdf` original. Se verifica HTTP 200, `Content-Type: application/pdf`,
  prefijo `%PDF-`, rangos válidos, permisos y que la página usa el alias.
- Con sesión de lectura y sin router, el alias también genera un PDF real.

La matriz usa un esquema temporal `cp_pdf_test_*` con marcador de propiedad y lo
elimina al concluir. No cambia la base configurada de la aplicación. Resultados,
hashes MIME y PDFs sintéticos están en
`docs/desarrollo/evidencia-etapa1-final/`.
