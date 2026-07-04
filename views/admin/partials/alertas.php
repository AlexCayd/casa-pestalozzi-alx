<?php
    $alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
    $alertasNormalizadas = [];

    $agregarAlerta = static function ($tipo, $mensajes) use (&$alertasNormalizadas, &$agregarAlerta): void {
        if ($mensajes === null || $mensajes === '') {
            return;
        }

        if (is_array($mensajes)) {
            foreach ($mensajes as $clave => $mensaje) {
                if (is_string($clave) && is_array($mensaje)) {
                    $agregarAlerta($clave, $mensaje);
                    continue;
                }

                $agregarAlerta($tipo, $mensaje);
            }

            return;
        }

        $tipo = is_string($tipo) ? $tipo : 'error';
        $alertasNormalizadas[$tipo][] = (string) $mensajes;
    };

    foreach ($alertas as $tipo => $mensajes) {
        if (is_int($tipo) && is_array($mensajes)) {
            foreach ($mensajes as $tipoAnidado => $mensajesAnidados) {
                $agregarAlerta($tipoAnidado, $mensajesAnidados);
            }

            continue;
        }

        $agregarAlerta($tipo, $mensajes);
    }
?>

<?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
    <?php if ($tipo === 'error') : ?>
        <div class="admin-menu__alert">
            <strong>Revisa los siguientes datos:</strong>
            <ul>
                <?php foreach ($mensajes as $mensaje) : ?>
                    <li><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>
