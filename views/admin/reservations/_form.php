<?php
/**
 * Shared admin reservation form for create/edit.
 */

$modo = (string)($modo ?? 'editar');
$reservacion = is_object($reservacion ?? null) ? $reservacion : new \Model\Reservacion();
$errores = is_array($errores ?? null) ? $errores : [];
$editable = (bool)($editable ?? true);
$returnUrl = (string)($returnUrl ?? '/admin/reservations');
$backUrl = (string)($backUrl ?? '/admin/reservations');
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$fechaActual = (string)($fechaActual ?? \Services\ReservacionConfig::fechaActual());
$diasActivos = is_array($diasActivos ?? null) ? $diasActivos : [];
$maxComensalesAdmin = (int)($maxComensalesAdmin ?? \Services\ReservacionConfig::MAX_COMENSALES_ADMIN);
$comentarioAdminDisponible = (bool)($comentarioAdminDisponible ?? true);
$asignarAutomaticamente = (bool)($asignarAutomaticamente ?? true);
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$motivoNoEditable = (string)($motivoNoEditable ?? '');

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$valor = static function ($item, string $campo, $default = '') {
    if (is_array($item)) {
        return $item[$campo] ?? $default;
    }

    if (is_object($item)) {
        return $item->$campo ?? $default;
    }

    return $default;
};

$errorCampo = static function (string $campo) use ($errores): string {
    $mensajes = $errores[$campo] ?? [];

    if (is_array($mensajes)) {
        return (string)($mensajes[0] ?? '');
    }

    return (string)$mensajes;
};

$id = (int)$valor($reservacion, 'id', 0);
$nombre = (string)$valor($reservacion, 'nombre');
$contactoTipo = (string)$valor($reservacion, 'contacto_tipo', 'email');
$contacto = (string)$valor($reservacion, 'contacto');
$fecha = (string)$valor($reservacion, 'fecha', $fechaActual);
$hora = \Services\HorarioReservacionService::normalizarHoraCorta((string)$valor($reservacion, 'hora'));
$comensales = max(1, (int)$valor($reservacion, 'comensales', 2));
$nota = (string)$valor($reservacion, 'nota');
$comentarioAdmin = (string)$valor($reservacion, 'comentario_admin');
$estado = (string)$valor($reservacion, 'estado', 'confirmada');
$requestToken = (string)$valor($reservacion, 'request_token');
$tieneMesas = count($mesasAsignadas) > 0 || (int)$valor($reservacion, 'mesas_count', 0) > 0;
$iniciarEdicion = $modo === 'crear' || (!empty($errores) && $editable);
$disabled = !$iniciarEdicion;
$action = $modo === 'crear' ? '/admin/reservations/create' : '/admin/reservations/update';
$titulo = $modo === 'crear' ? 'Nueva reservacion' : 'Datos de la reservacion';
$subtitulo = $modo === 'crear' ? 'Alta administrativa' : 'Informacion editable';
$formId = $modo . '-reservation-admin-form';

$mensajeBloqueo = match ($motivoNoEditable) {
    \Services\ReservacionService::RESERVACION_PASADA => 'No se pueden modificar reservaciones de fechas anteriores.',
    \Services\ReservacionService::RESERVACION_HORARIO_PASADO => 'Esta reservacion ya paso de horario y no puede modificarse.',
    \Services\ReservacionService::ESTADO_NO_EDITABLE => 'Esta reservacion ya fue finalizada y no puede modificarse.',
    default => 'Esta reservacion no puede modificarse.',
};
?>

<article class="reservation-detail-card admin-card reservation-admin-form-card" data-reservation-form-card>
    <div class="reservation-detail-card__head">
        <div>
            <span class="reservation-detail-card__label"><?php echo $h($subtitulo); ?></span>
            <h3><?php echo $h($titulo); ?></h3>
        </div>
        <?php if ($modo === 'editar' && $editable) : ?>
            <button
                type="button"
                class="admin-btn admin-btn--secondary"
                aria-controls="<?php echo $h($formId); ?>"
                aria-expanded="<?php echo $iniciarEdicion ? 'true' : 'false'; ?>"
                data-form-edit
                <?php echo $iniciarEdicion ? 'hidden' : ''; ?>
            >Editar</button>
        <?php endif; ?>
    </div>

    <?php if (!$editable) : ?>
        <p class="reservation-detail-warning"><?php echo $h($mensajeBloqueo); ?></p>
    <?php endif; ?>

    <?php if ($modo === 'editar') : ?>
        <div class="reservation-edit-mode" aria-live="polite" data-edit-mode-banner <?php echo $iniciarEdicion ? '' : 'hidden'; ?>>
            <span class="reservation-edit-mode__icon" aria-hidden="true">E</span>
            <div>
                <strong>Modo edicion activo</strong>
                <p>Estas editando esta reservacion. Los cambios no se aplicaran hasta guardar.</p>
            </div>
        </div>
    <?php endif; ?>

    <form
        id="<?php echo $h($formId); ?>"
        class="reservation-detail-form <?php echo $iniciarEdicion ? 'is-editing' : ''; ?>"
        method="POST"
        action="<?php echo $h($action); ?>"
        data-admin-reservation-form
        data-form-mode="<?php echo $h($modo); ?>"
        data-form-editable="<?php echo $editable ? '1' : '0'; ?>"
        data-initial-date="<?php echo $h($fecha); ?>"
        data-initial-time="<?php echo $h($hora); ?>"
        data-initial-guests="<?php echo $comensales; ?>"
        data-has-tables="<?php echo $tieneMesas ? '1' : '0'; ?>"
    >
        <?php if ($modo === 'editar') : ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
        <?php else : ?>
            <input type="hidden" name="request_token" value="<?php echo $h($requestToken); ?>">
        <?php endif; ?>
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">

        <div class="reservation-detail-form__grid">
            <label>
                <span>Nombre</span>
                <input type="text" name="nombre" value="<?php echo $h($nombre); ?>" maxlength="<?php echo \Services\ReservacionConfig::NOMBRE_MAX_CARACTERES; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('nombre'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <label>
                <span>Tipo de contacto</span>
                <select name="contacto_tipo" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                    <option value="email" <?php echo $contactoTipo === 'email' ? 'selected' : ''; ?>>Correo</option>
                    <option value="telefono" <?php echo $contactoTipo === 'telefono' ? 'selected' : ''; ?>>Teléfono</option>
                </select>
            </label>

            <label>
                <span>Contacto</span>
                <input type="text" name="contacto" value="<?php echo $h($contacto); ?>" maxlength="<?php echo \Services\ReservacionConfig::EMAIL_MAX_CARACTERES; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('contacto'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <label>
                <span>Fecha</span>
                <?php
                $rootId = $modo . '-reservation-date-picker';
                $inputId = $modo . '-reservation-date';
                $displayId = $modo . '-reservation-date-display';
                $calendarId = $modo . '-reservation-calendar';
                $name = 'fecha';
                $value = $fecha;
                $min = $fechaActual;
                $enabledWeekdays = $diasActivos;
                include __DIR__ . '/../../components/reservations/date-picker.php';
                ?>
                <?php $error = $errorCampo('fecha'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <label>
                <span>Hora</span>
                <?php
                $rootId = $modo . '-reservation-time-picker';
                $inputId = $modo . '-reservation-time';
                $displayId = $modo . '-reservation-time-display';
                $dropdownId = $modo . '-reservation-time-dropdown';
                $name = 'hora';
                $value = $hora;
                $endpoint = '/api/reservation-schedules';
                include __DIR__ . '/../../components/reservations/time-picker.php';
                ?>
                <?php $error = $errorCampo('hora'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-time-status><?php echo $h($error); ?></span>
            </label>

            <label>
                <span>Comensales</span>
                <input type="number" name="comensales" min="1" max="<?php echo $maxComensalesAdmin; ?>" value="<?php echo $comensales; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('comensales'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <?php if ($modo === 'crear') : ?>
                <label class="reservation-detail-form__wide">
                    <span>Nota del cliente</span>
                    <textarea name="nota" rows="3" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($nota); ?></textarea>
                    <?php $error = $errorCampo('nota'); ?>
                    <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
                </label>
            <?php endif; ?>

            <?php if ($comentarioAdminDisponible) : ?>
                <label class="reservation-detail-form__wide">
                    <span>Comentario interno</span>
                    <textarea name="comentario_admin" rows="4" maxlength="<?php echo \Services\ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($comentarioAdmin); ?></textarea>
                    <?php $error = $errorCampo('comentario_admin'); ?>
                    <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
                </label>
            <?php else : ?>
                <p class="reservation-operation-inline reservation-operation-inline--warning reservation-detail-form__wide">Los comentarios internos no estan disponibles en esta instalacion.</p>
            <?php endif; ?>
        </div>

        <?php if ($modo === 'crear') : ?>
            <label class="reservation-admin-form__check">
                <input type="hidden" name="asignar_automaticamente" value="0">
                <input type="checkbox" name="asignar_automaticamente" value="1" <?php echo $asignarAutomaticamente ? 'checked' : ''; ?> data-reservation-control>
                <span>Asignar mesas automaticamente despues de crear</span>
            </label>
        <?php endif; ?>

        <div class="reservation-detail-form__actions">
            <?php if ($modo === 'editar') : ?>
                <button type="button" class="admin-btn admin-btn--secondary" data-form-cancel <?php echo !$iniciarEdicion ? 'hidden' : ''; ?>>Cancelar edicion</button>
            <?php endif; ?>
            <button type="submit" class="admin-btn admin-btn--primary" data-form-save <?php echo (!$editable || !$iniciarEdicion) ? 'hidden disabled' : ''; ?>>Guardar cambios</button>
        </div>
    </form>
</article>
