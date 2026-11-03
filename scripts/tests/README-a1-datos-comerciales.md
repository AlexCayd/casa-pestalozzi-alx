# A1: datos comerciales por oferta

La migración `database/actualizaciones/a1-datos-comerciales-oferta.sql` debe ejecutarse después de que exista la vista `productos_oferta` (esquema M1.2). Copia nombre, descripción e imagen efectivos en una fila por oferta y no reemplaza filas ya guardadas.

Para probarla, configura `includes/.env` con una conexión de desarrollo o pruebas que pueda crear y eliminar bases. Confirma el origen explícitamente:

```powershell
php scripts/tests/run-a1-datos-comerciales.php --host HOST --base BASE --entorno development
```

Usa `--entorno test` si `APP_ENV=test`. El runner carga `ddl.sql`, `deploy.sql`, `menu-restaurante.sql` y `development.sql` en una base aleatoria `cp_multinegocio_test_*`, marca su propiedad y solo la elimina tras verificarla. No modifica la base configurada.
