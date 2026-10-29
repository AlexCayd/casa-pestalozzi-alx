# Fixtures de Almacenes M0.5-B

Solo para instalaciones nuevas `development`/`test`. Nunca reaplicar
`database/development.sql` a una base existente. El instalador, el importador
controlado y los runners conservan sus marcas de propiedad y limpieza.

| ID físico | Propietario | Nombre | Harina de apertura (g) |
|---|---|---|---|
| 1001 | Global | Almacén Central | 10000.000 |
| 1004 | Global | Almacén de Suministros | 250.125 |
| 1002 | Restaurante | Cocina | 4920.000 |
| 1005 | Restaurante | Barra y Maridaje | 25.250 |
| 1006 | Restaurante | Refrigeración | 0.000 |
| 1003 | Panadería | Producción | 8000.000 |
| 1007 | Panadería | Producto Terminado | 125.375 |

Se reutilizan 1001–1003; el resto amplía el mismo catálogo físico. El consolidado
de harina es `23320.750 g`; seleccionar Central y Cocina conserva `14920.000 g`.
La leche consolida `11920.750 ml` y el café `2860.750 g`. Son consultas por
artículo y unidad canónica; no sumas entre unidades ni saldos virtuales.

Los cinco artículos previos conservan IDs y relaciones POS. Se agregan 25:
azúcar, sal marina, mantequilla, huevo, chocolate oscuro, levadura, aceite de
oliva, tomate, cebolla, queso fresco, limón, naranja, agua mineral, té negro,
canela, vainilla, nuez, almendra, miel, servilletas, bolsas de papel, cajas para
pan, jarabe de temporada, cacao retirado y semillas de amapola. Unidades: g, ml
y pza; costos DECIMAL(10,4) y cantidades DECIMAL(12,3).

Hay 30 identidades, 47 asociaciones, 11 operaciones y 51 movimientos. Cada saldo
y versión de apertura deriva del ledger en la misma transacción. Las 24 líneas
históricas permanecen; las 27 nuevas cubren aperturas y conteo negativo.
Sal marina en Cocina tiene `-1.125 g`, autorizado por ese almacén; Refrigeración
incluye ceros. Jarabe tiene asociación inactiva con saldo cero; cacao es artículo
inactivo sin existencias; amapola no tiene asociación. Los mínimos físicos
permiten detectar faltantes aunque otro almacén tenga excedente.

Se eliminan prefijos visibles de nombres, usuarios, snapshots, comentarios y
referencias documentales. `admin_demo`, hash, guard de instalación, entorno,
claves internas y marcas de propiedad mantienen su contrato. La identidad
sintética se verifica por ID y entorno, nunca por prefijo. No se añaden productos
comerciales de Restaurante: siguen intactos los 112 originales. M1.1 amplía
exclusivamente el menú sintético Panadería; no habilita ventas.

## Fixtures comerciales M1.1

`development.sql` añade 12 identidades/ofertas Panadería (IDs 1201–1212), sin
imágenes, recetas, producción, asociaciones inventariables ni movimientos nuevos.
Con la hogaza existente hay 13 ofertas: 12 activas y una inactiva para probar su
exclusión del PDF. Categorías: Panes artesanales (1002), Bollería (1201), Dulces
de la casa (1202) y Panes salados (1203). Precios y orden son locales.
Ejemplos: rol de canela, conchas, croissant, baguette, focaccia, brownie y panqué.
Totales de instalación nueva: 137 productos/ofertas (112 originales y 25
sintéticos), 20 categorías; Almacenes conserva 7 físicos, 30 artículos, 47
asociaciones, 11 operaciones y 51 movimientos. No reaplicar este seed a bases
existentes. La etiqueta de carta comida Panadería se actualiza en instalaciones
nuevas; su slug y propiedad permanecen.

Validación reproducible:

```text
php scripts/tests/run-m05b-fixtures.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-instalacion-3e.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-transferencias-m05b.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/pruebas-http-transferencias-m05b.php --fixture <archivo-privado-del-harness>
```

El primer runner importa DDL, estructura, menú y fixtures en una base propia,
verifica distribución exacta, estados, ledger, credenciales y corte por negocio,
y la elimina. El segundo compara dos instalaciones independientes, bootstrap,
Usuarios y Almacenes. Transferencias contrasta origen, tránsito, recepción y
retorno por línea, rollback e idempotencia, además de dos procesos concurrentes
para envío y recepción. Los HTTP usan solamente el harness temporal identificado.
