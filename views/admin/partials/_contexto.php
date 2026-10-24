<?php
use Services\Security\Contextos;
?>
<form class="admin-context-switch" action="/admin/contexto" method="get">
    <label for="admin-contexto">Contexto de trabajo</label>
    <div class="admin-context-switch__controls">
        <select data-native data-context-select id="admin-contexto" name="contexto" title="Contexto de trabajo: <?php echo Contextos::NOMBRES[$contextoActivo]; ?>" <?php echo count($contextosDisponibles) < 2 ? 'disabled' : ''; ?>>
            <?php foreach ($contextosDisponibles as $opcion): ?>
                <option value="<?php echo $opcion; ?>" <?php echo $contextoActivo === $opcion ? 'selected' : ''; ?>><?php echo Contextos::NOMBRES[$opcion]; ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><?php foreach ($contextosDisponibles as $opcion): ?><a href="/admin/contexto?contexto=<?php echo $opcion; ?>"><?php echo Contextos::NOMBRES[$opcion]; ?></a> <?php endforeach; ?></noscript>
    </div>
</form>
