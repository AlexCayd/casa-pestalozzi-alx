# A3: independencia comercial por negocio

`productos_oferta` conserva su contrato de lectura y pasa a proyectar `nombre`, `descripcion` e `img` desde `oferta_datos_comerciales`. A partir del marcador `a3-datos-comerciales-local-v1`, cada escritura local modifica únicamente la fila de su oferta. La identidad de `productos` se conserva para las referencias técnicas e históricas. La compatibilidad de Productos de la casa no reescribe ofertas existentes; mientras este módulo permanezca, las personalizaciones se reflejan en su historial temporal y los cambios Globales a nombre, descripción o imagen se bloquean cuando ya hay ofertas.

## Despliegue coordinado

1. Confirmar que A1 está aplicada y que A2 está desplegado.
2. Desplegar el código de esta versión antes de cambiar la vista. Sin el marcador A3, el código conserva el comportamiento A2 y calcula explícitamente la proyección M1.2 desde sus tablas originales; no usa `productos_oferta` como fuente del sincronizador.
3. Ejecutar el preflight de solo lectura con los valores del entorno configurado:

   ```powershell
   php scripts/menu/a3-preflight.php --host HOST --base BASE --entorno development
   ```

   Usa `--entorno test` cuando corresponda. Revisa las ofertas faltantes, los IDs con diferencias y las filas huérfanas. El preflight no escribe ni cambia la base.

4. Poner la aplicación en mantenimiento de escritura, detener las altas/ediciones y dejar terminar las solicitudes de escritura ya iniciadas. Ejecutar de nuevo el preflight; así ninguna escritura del modo A2 puede cruzarse con el cambio de vista y el marcador.
5. Si no hay discrepancias, ejecutar `database/actualizaciones/a3-datos-comerciales-local.sql` en una sola sesión SQL. La migración completa filas faltantes sin actualizar las existentes, valida la paridad con la proyección anterior, reemplaza la vista e inserta el marcador A3 al final.
6. Si hay diferencias que se deben conservar como datos locales válidos, documentar los IDs revisados y ejecutar en esa misma sesión `SET @cp_a3_preservar_locales=1;` antes del archivo SQL. La migración conserva las filas existentes; solo completa faltantes. Sin esa decisión explícita, una diferencia detiene el corte antes de reemplazar la vista.
7. Ejecutar el preflight posterior. Con A3 activo, las diferencias frente a la proyección M1.2 anterior son informativas; la fuente vigente es la tabla local y la vista `productos_oferta` debe coincidir con ella. Reanudar las escrituras cuando el marcador esté activo y las comprobaciones posteriores terminen.

La migración no es una transacción de esquema completa: MySQL confirma de forma implícita el backfill y las operaciones de rutina/vista. Por eso la guardia de diferencias corre antes de `CREATE OR REPLACE VIEW`; ante un aborto, la vista anterior sigue activa y las únicas escrituras previas posibles son inserciones de filas faltantes. La tabla A1, las claves técnicas y las referencias a tickets no se reconstruyen.

En instalaciones nuevas, cargar el esquema, deploy, menú y fixtures iniciales, aplicar A1 y ejecutar A3 antes de habilitar el sitio para edición. No ejecutar el SQL A3 contra una base operativa desde los runners.

## Verificación aislada

El runner crea una base `cp_multinegocio_test_*` propia, aplica A1 y A3, verifica el aborto estricto, la preservación aprobada, CRUD independiente, lectores administrativos/públicos/PDF/POS, rollback, tickets y repetición de migración, y elimina solo esa base:

```powershell
npm run test:menu:a3:db -- --host HOST --base BASE --entorno development
```

La suite completa de A3 requiere además las suites existentes de PHP, JavaScript, menú M1/M1.2, y M2. La sincronización temporal de la tabla `oferta_personalizaciones` se retirará junto con Productos de la casa en el siguiente bloque.
