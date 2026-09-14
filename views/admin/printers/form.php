<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Impresión</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Impresora'); ?></h2>
            <p class="admin-page__subtitle">Impresoras térmicas ESC/POS por red (TCP, puerto 9100) o por nombre de impresora de Windows (spooler / smb://). El ancho típico es 48 columnas (32 en papel angosto).</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/printers">Volver</a>
    </header>

    <?php /* --wide: este formulario tiene ocho campos y tres grupos de pills, y
             a los 720px del panel estrecho salía como una columna larguísima
             que obligaba a desplazar para ver el botón de guardar. El resto de
             los formularios que comparten .admin-menu__panel--form son de dos o
             tres campos y se quedan como están. */ ?>
    <section class="admin-menu__panel admin-menu__panel--form admin-menu__panel--wide admin-panel admin-card">
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

        <?php
        /*
         * Los tres selects del formulario son ahora grupos de pills.
         *
         * Rol y conexión tienen DOS opciones cada uno y área tiene una por área
         * de producción: en todos los casos el catálogo entero cabe en pantalla,
         * así que esconderlo detrás de un desplegable obligaba a abrir para
         * saber qué había. Con las opciones a la vista, elegir es un toque —que
         * es lo que importa en una tablet— y el rótulo largo ("Comanda (área de
         * producción)") se puede partir en título y explicación.
         *
         * Radios reales y no botones: conservan el envío del formulario, la
         * navegación con flechas dentro del grupo y el estado sin una línea de
         * JS. El aspecto sale del :checked, no de una clase.
         */
        $rolActual = $impresora->rol ?? 'comanda';
        $areaActual = (int) ($impresora->area_id ?? 0);
        $conexion = $impresora->conexion ?? 'red';

        $roles = [
            'comanda' => ['Comanda', 'Va a un área de producción'],
            'cuenta'  => ['Cuenta', 'Ticket de cobro para el comensal'],
        ];
        $conexiones = [
            'red'     => ['Red', 'TCP / IP, normalmente el puerto 9100'],
            'windows' => ['Windows', 'Nombre de impresora del spooler o smb://'],
        ];
        ?>
        <form class="admin-menu__form" method="POST">
            <div class="admin-menu__field admin-menu__field--full">
                <label for="nombre">Nombre de la impresora</label>
                <input type="text" id="nombre" name="nombre" maxlength="100"
                       placeholder="Cocina, Barra, Caja..."
                       value="<?php echo htmlspecialchars($impresora->nombre ?? ''); ?>" required>
            </div>

            <fieldset class="admin-menu__field admin-menu__field--full admin-pills">
                <legend class="admin-pills__legend">Rol</legend>
                <div class="admin-pills__group">
                    <?php foreach ($roles as $valor => [$titulo, $ayuda]) : ?>
                        <label class="admin-pill">
                            <input type="radio" name="rol" value="<?php echo htmlspecialchars($valor); ?>"
                                   <?php echo $rolActual === $valor ? 'checked' : ''; ?> required>
                            <span class="admin-pill__body">
                                <span class="admin-pill__title"><?php echo htmlspecialchars($titulo); ?></span>
                                <span class="admin-pill__hint"><?php echo htmlspecialchars($ayuda); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="admin-menu__field admin-menu__field--full admin-pills">
                <legend class="admin-pills__legend">Área de producción</legend>
                <div class="admin-pills__group">
                    <?php /* value="" es "sin área": una opción más del grupo, no la
                             ausencia de elección. */ ?>
                    <label class="admin-pill">
                        <input type="radio" name="area_id" value="" <?php echo $areaActual === 0 ? 'checked' : ''; ?>>
                        <span class="admin-pill__body">
                            <span class="admin-pill__title">Sin área</span>
                            <span class="admin-pill__hint">Sólo para rol Cuenta</span>
                        </span>
                    </label>
                    <?php foreach ($areas as $areaId => $areaNombre) : ?>
                        <label class="admin-pill">
                            <input type="radio" name="area_id" value="<?php echo (int) $areaId; ?>"
                                   <?php echo $areaActual === (int) $areaId ? 'checked' : ''; ?>>
                            <span class="admin-pill__body">
                                <span class="admin-pill__title"><?php echo htmlspecialchars($areaNombre); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="admin-menu__field admin-menu__field--full admin-pills" id="conexion">
                <legend class="admin-pills__legend">Tipo de conexión</legend>
                <div class="admin-pills__group">
                    <?php foreach ($conexiones as $valor => [$titulo, $ayuda]) : ?>
                        <label class="admin-pill">
                            <input type="radio" name="conexion" value="<?php echo htmlspecialchars($valor); ?>"
                                   <?php echo $conexion === $valor ? 'checked' : ''; ?> required>
                            <span class="admin-pill__body">
                                <span class="admin-pill__title"><?php echo htmlspecialchars($titulo); ?></span>
                                <span class="admin-pill__hint"><?php echo htmlspecialchars($ayuda); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="admin-menu__conexion" data-conexion="red"
                 <?php echo $conexion === 'red' ? '' : 'hidden'; ?>>
                <div class="admin-menu__field">
                    <label for="host">Host / IP</label>
                    <input type="text" id="host" name="host" maxlength="100"
                           placeholder="192.168.1.50"
                           value="<?php echo htmlspecialchars($impresora->host ?? ''); ?>">
                </div>

                <div class="admin-menu__field">
                    <label for="puerto">Puerto</label>
                    <input type="number" id="puerto" name="puerto" min="1" max="65535"
                           value="<?php echo htmlspecialchars((string) ($impresora->puerto ?? 9100)); ?>">
                </div>
            </div>

            <div class="admin-menu__conexion" data-conexion="windows"
                 <?php echo $conexion === 'windows' ? '' : 'hidden'; ?>>
                <div class="admin-menu__field admin-menu__field--full">
                    <label for="dispositivo">Nombre de la impresora de Windows</label>
                    <input type="text" id="dispositivo" name="dispositivo" maxlength="120"
                           placeholder="Nombre de impresora o smb://host/recurso"
                           value="<?php echo htmlspecialchars($impresora->dispositivo ?? ''); ?>">
                </div>
            </div>

            <div class="admin-menu__field">
                <label for="ancho">Ancho (columnas)</label>
                <input type="number" id="ancho" name="ancho" min="1" max="96"
                       value="<?php echo htmlspecialchars((string) ($impresora->ancho ?? 48)); ?>" required>
            </div>

            <?php /* Sin --full: comparte fila con el ancho. Solo, el ancho dejaba
                     media fila vacía a su derecha justo antes de los botones. */ ?>
            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($impresora->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Impresora activa (se usará al imprimir comandas/cuentas)</label>
            </div>

            <div class="admin-menu__form-actions admin-menu__field--full">
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
    //
    // El tipo de conexión dejó de ser un <select> y es un grupo de radios, así
    // que el valor se lee del radio marcado y el listener va delegado en el
    // fieldset: 'change' burbujea desde cada radio, y con la delegación da
    // igual cuántas opciones haya.
    (function () {
        var grupo = document.getElementById('conexion');
        if (!grupo) return;

        var bloques = document.querySelectorAll('.admin-menu__conexion');

        function valorActual() {
            var marcado = grupo.querySelector('input[name="conexion"]:checked');
            return marcado ? marcado.value : '';
        }

        function actualizar() {
            var valor = valorActual();

            bloques.forEach(function (bloque) {
                var visible = bloque.dataset.conexion.split(' ').indexOf(valor) !== -1;
                bloque.hidden = !visible;
                bloque.querySelectorAll('input, select').forEach(function (campo) {
                    campo.disabled = !visible;
                });
            });
        }

        grupo.addEventListener('change', actualizar);
        actualizar();
    })();
</script>
