# B2 — Capacidad manual insuficiente

Contrato de presentación verificado para una selección cuya capacidad queda por debajo de los comensales. El estado operativo visible equivalente se conserva en [c4-assignment-edit-1366x768.png](c4-assignment-edit-1366x768.png).

```text
codigo: CAPACIDAD_INSUFICIENTE
title: La capacidad de las mesas es insuficiente
description: Las mesas seleccionadas no tienen suficientes lugares para esta reservación.
summary:
  Comensales: {comensales}
  Capacidad seleccionada: {capacidad_seleccionada}
  Lugares faltantes: {diferencia}
consequence: Selecciona mesas con mayor capacidad antes de guardar la asignación.
actions:
  Volver a seleccionar
  Guardar de todas formas
```

El código no reutiliza la presentación de tickets; el runner estático y el auditor verifican ambas ramas por separado.
