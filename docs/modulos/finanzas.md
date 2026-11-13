# Finanzas

Referencia técnica del módulo en los tres ámbitos. La norma está en
[arquitectura §8](../arquitectura.md#8-pagos-finanzas-y-analitica); la mecánica
visual, en la [guía de interfaz](../desarrollo/interfaz.md).

| Ámbito | Ruta | Qué es | Escribe |
|---|---|---|---|
| Restaurante | `/admin/finanzas` | Estado de resultados, diagnóstico, signos vitales, flujo, cortes, gastos fijos y rentabilidad | Sus gastos fijos |
| Panadería | `/admin/panaderia/finanzas` | La misma pantalla, con ventas en caja y referencias de panadería | Sus gastos fijos |
| Global | `/admin/global/finanzas` | Consolidado de los dos negocios: hoy, periodo lado a lado y total | Nada |

Cada pantalla la abre el módulo **Finanzas** de su ámbito (`ver`; guardar o
borrar un gasto exige `editar`). El ámbito sale de la ruta.

## Una sola fuente de cifras

`Services\Finance\FinanzasNegocio` tiene las consultas de un negocio vendedor,
acotadas por `unidad_id`: ingresos, propinas, tickets, costo de insumos y lo
vendido sin costo (`agregados`), merma, valor de inventario, rentabilidad por
producto, cortes de caja, gastos fijos prorrateados, el estado de resultados con
su comparativo (`resumen`) y el pulso de hoy (`pulsoHoy`). Las usan las tres
pantallas, así que una cifra que el dueño ve en Global es la misma que ve el
negocio en la suya.

- Ventas: tickets cerrados, sin renglones cancelados, por
  `COALESCE(hora_cierre, hora_apertura)`.
- Costo de insumos: el `costo_unitario` que el ticket congeló al vender. Lo
  vendido sin costo se separa y se avisa; no se estima.
- Gastos fijos: monto mensual sin fecha, prorrateado a los días del periodo; el
  periodo anterior repite el mismo prorrateo.

## Panadería

Vende en caja de mostrador ([caja](caja-panaderia.md)), sin mesas ni propinas:
la pantalla no pinta propinas y el diagnóstico no ofrece Tickets, que
Panadería no tiene. Las ventas salen de los tickets que la caja cierra; una
anulación del día deja de sumar porque su ticket pasa a `cancelado`. El costo
de lo vendido es el de la pieza de vitrina; merma, inventario y gastos fijos
son reales.

`DiagnosticoFinanciero::GIROS` da a cada giro sus referencias, textos y enlaces.
Las de Panadería (constantes `PANADERIA_*`) son orientativas y están para que el
dueño las valide:

| Indicador | Panadería | Restaurante | Fuente (Panadería) |
|---|---|---|---|
| Costo de insumos | 25–35 % de la venta | 28–35 % | Toast; Bake Magazine (2012) |
| Merma | 2–6 % de lo producido | 5–15 % de lo comprado | Publicaciones del sector; Bhardwaj et al. (2023) |
| Margen neto | 5–10 % | 3–6 % | Harvest; Metrobi |
| Nómina alta | > 35 % | > 33 % | Toast |
| Costo primo sano | ≤ 60 % | ≤ 55 % | Toast |
| Días de inventario | meta 7, alto 14 | meta 14, alto 30 | Práctica del giro |

## Global

`Services\Finance\ConsolidadoFinanciero` arma tres alturas de lectura:

1. **Hoy**, por negocio: cobrado, tickets, cuentas abiertas y lo que llevan,
   contra lo cobrado el mismo día de la semana pasada hasta la misma hora.
2. **El periodo**, cada negocio con su estado de resultados y el mismo
   veredicto de su pantalla (`resumen` con su giro).
3. **El total** de la organización, la participación de cada negocio y lo que
   pide atención (los signos vitales fuera de lo sano).

Reglas del consolidado:

- Suma ventas y costos de los negocios y nada más. Una transferencia entre
  almacenes no es venta ni gasto.
- El inventario consolidado incluye los almacenes de Global.
- Los márgenes del total salen de los totales, nunca de promediar márgenes.
- Global no tiene gastos fijos propios (`GastoFijo` rechaza el ámbito).
- Leer el consolidado no abre la pantalla ni los gastos de un negocio: el
  enlace a cada negocio sólo aparece con su permiso.

## Pruebas

- `php scripts/tests/run-diagnostico-financiero.php` (en `test:php`): contrato
  del diagnóstico.
- `php scripts/tests/run-finanzas-ambito-db.php`: cifras de Panadería contra un
  día de caja sembrado, consolidado igual a la suma de los negocios, gastos
  fijos aislados y permisos. Corre contra la base de desarrollo en una
  transacción que se revierte.
