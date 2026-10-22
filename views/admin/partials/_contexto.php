<?php
use Services\Security\Contextos;
?>
<form class="admin-context-switch" action="/admin/contexto" method="get">
    <label for="admin-contexto">Contexto de trabajo</label>
    <div class="admin-context-switch__controls">
        <select data-native id="admin-contexto" name="contexto" <?php echo count($contextosDisponibles) < 2 ? 'disabled' : ''; ?>>
            <?php foreach ($contextosDisponibles as $opcion): ?>
                <option value="<?php echo $opcion; ?>" <?php echo $contextoActivo === $opcion ? 'selected' : ''; ?>><?php echo Contextos::NOMBRES[$opcion]; ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (count($contextosDisponibles) > 1): ?>
            <button type="submit" class="admin-btn admin-btn--secondary">Cambiar</button>
        <?php endif; ?>
    </div>
</form>
