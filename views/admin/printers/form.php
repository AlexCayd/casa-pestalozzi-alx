<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Impresión</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Impresora'); ?></h2>
            <p class="admin-page__subtitle">Impresoras térmicas ESC/POS por red (TCP, puerto 9100) o por nombre de impresora de Windows (spooler / smb://). El ancho típico es 48 columnas (32 en papel angosto).</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/printers">Volver</a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <?php if (!empty($alertas['error'])) : ?>
            <div class="admin-menu__alert">
                <strong>Revisa los siguientes datos:</strong>
                <ul>
                    <?php foreach ($alertas['error'] as $error) : ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="admin-menu__form" method="POST">
            <label for="nombre">Nombre de la impresora</label>
            <input type="text" id="nombre" name="nombre" maxlength="100"
                   placeholder="Cocina, Barra, Caja..."
                   value="<?php echo htmlspecialchars($impresora->nombre ?? ''); ?>" required>

            <label for="rol">Rol</label>
            <select id="rol" name="rol" required>
                <option value="comanda" <?php echo ($impresora->rol ?? 'comanda') === 'comanda' ? 'selected' : ''; ?>>Comanda (área de producción)</option>
                <option value="cuenta" <?php echo ($impresora->rol ?? '') === 'cuenta' ? 'selected' : ''; ?>>Cuenta (ticket de cobro)</option>
            </select>

            <label for="area_id">Área de producción</label>
            <select id="area_id" name="area_id">
                <option value="">Sin área (sólo para rol Cuenta)</option>
                <?php foreach ($areas as $areaId => $areaNombre) : ?>
                    <option value="<?php echo (int) $areaId; ?>"
                        <?php echo (int) ($impresora->area_id ?? 0) === (int) $areaId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($areaNombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php $conexion = $impresora->conexion ?? 'red'; ?>
            <label for="conexion">Tipo de conexión</label>
            <select id="conexion" name="conexion" required>
                <option value="red" <?php echo $conexion === 'red' ? 'selected' : ''; ?>>Red (TCP / IP)</option>
                <option value="windows" <?php echo $conexion === 'windows' ? 'selected' : ''; ?>>Windows (nombre de impresora / spooler)</option>
            </select>

            <div class="admin-menu__conexion" data-conexion="red"
                 <?php echo $conexion === 'red' ? '' : 'hidden'; ?>>
                <label for="host">Host / IP</label>
                <input type="text" id="host" name="host" maxlength="100"
                       placeholder="192.168.1.50"
                       value="<?php echo htmlspecialchars($impresora->host ?? ''); ?>">

                <label for="puerto">Puerto</label>
                <input type="number" id="puerto" name="puerto" min="1" max="65535"
                       value="<?php echo htmlspecialchars((string) ($impresora->puerto ?? 9100)); ?>">
            </div>

            <div class="admin-menu__conexion" data-conexion="windows"
                 <?php echo $conexion === 'windows' ? '' : 'hidden'; ?>>
                <label for="dispositivo">Nombre de la impresora de Windows</label>
                <input type="text" id="dispositivo" name="dispositivo" maxlength="120"
                       placeholder="Nombre de impresora o smb://host/recurso"
                       value="<?php echo htmlspecialchars($impresora->dispositivo ?? ''); ?>">
            </div>

            <label for="ancho">Ancho (columnas)</label>
            <input type="number" id="ancho" name="ancho" min="1" max="96"
                   value="<?php echo htmlspecialchars((string) ($impresora->ancho ?? 48)); ?>" required>

            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($impresora->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Impresora activa (se usará al imprimir comandas/cuentas)</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/printers">Cancelar</a>
            </div>
        </form>
    </section>
</section>

<script>
    // Muestra sólo los campos del tipo de conexión seleccionado (red / windows).
    // Los inputs de los bloques ocultos se deshabilitan para que NO se envíen
    // (evita que el host de 'red' o el dispositivo de 'windows' arrastren valores
    // del modo no elegido).
    (function () {
        var selector = document.getElementById('conexion');
        if (!selector) return;

        var bloques = document.querySelectorAll('.admin-menu__conexion');

        function actualizar() {
            var valor = selector.value;

            bloques.forEach(function (bloque) {
                var visible = bloque.dataset.conexion.split(' ').indexOf(valor) !== -1;
                bloque.hidden = !visible;
                bloque.querySelectorAll('input, select').forEach(function (campo) {
                    campo.disabled = !visible;
                });
            });
        }

        selector.addEventListener('change', actualizar);
        actualizar();
    })();
</script>
