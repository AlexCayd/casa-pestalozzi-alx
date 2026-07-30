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
$formTransport = (string)($formTransport ?? 'html');
$formActionsExternal = (bool)($formActionsExternal ?? false);

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
        data-form-transport="<?php echo $h($formTransport); ?>"
    >
        <?php if ($modo === 'editar') : ?>
            <input type="hidden" name="id" value="<?php echo $id; ?>">
        <?php else : ?>
            <input type="hidden" name="request_token" value="<?php echo $h($requestToken); ?>">
        <?php endif; ?>
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">

        <div class="reservation-detail-form__grid">
            <?php if ($modo === 'crear') : ?>
                <span class="reservation-detail-form__group-label reservation-detail-form__group-label--visit">Visita</span>
                <span class="reservation-detail-form__group-label reservation-detail-form__group-label--client">Cliente</span>
            <?php endif; ?>
            <label class="reservation-detail-form__field reservation-detail-form__field--name">
                <span>Nombre</span>
                <input type="text" name="nombre" value="<?php echo $h($nombre); ?>" maxlength="<?php echo \Services\ReservacionConfig::NOMBRE_MAX_CARACTERES; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('nombre'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <label class="reservation-detail-form__field reservation-detail-form__field--contact-type">
                <span>Tipo de contacto</span>
                <span class="reservation-admin-select">
                    <span class="reservation-admin-select__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                            <circle cx="12" cy="8" r="3.5"/>
                            <path d="M5.5 20c.8-3.3 3.1-5 6.5-5s5.7 1.7 6.5 5"/>
                        </svg>
                    </span>
                    <select class="reservation-admin-select__native" name="contacto_tipo" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                        <option value="email" <?php echo $contactoTipo === 'email' ? 'selected' : ''; ?>>Correo</option>
                        <option value="telefono" <?php echo $contactoTipo === 'telefono' ? 'selected' : ''; ?>>Teléfono</option>
                    </select>
                    <button class="reservation-admin-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false" data-contact-select-trigger <?php echo $disabled ? 'disabled' : ''; ?>>
                        <span data-contact-select-value>Correo</span>
                    </button>
                    <span class="reservation-admin-select__menu" role="listbox" aria-label="Tipo de contacto" hidden data-contact-select-menu>
                        <button type="button" role="option" aria-selected="<?php echo $contactoTipo === 'email' ? 'true' : 'false'; ?>" data-contact-select-option="email">Correo</button>
                        <button type="button" role="option" aria-selected="<?php echo $contactoTipo === 'telefono' ? 'true' : 'false'; ?>" data-contact-select-option="telefono">Teléfono</button>
                    </span>
                    <span class="reservation-admin-select__chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                            <path d="m7 10 5 5 5-5"/>
                        </svg>
                    </span>
                </span>
            </label>

            <label class="reservation-detail-form__field reservation-detail-form__field--contact">
                <span>Contacto</span>
                <input type="text" name="contacto" value="<?php echo $h($contacto); ?>" maxlength="<?php echo \Services\ReservacionConfig::EMAIL_MAX_CARACTERES; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('contacto'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

            <label class="reservation-detail-form__field reservation-detail-form__field--date">
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

            <label class="reservation-detail-form__field reservation-detail-form__field--time">
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

            <label class="reservation-detail-form__field reservation-detail-form__field--guests">
                <span>Comensales</span>
                <input type="number" name="comensales" min="1" max="<?php echo $maxComensalesAdmin; ?>" value="<?php echo $comensales; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                <?php $error = $errorCampo('comensales'); ?>
                <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
            </label>

        </div>

        <?php if ($modo === 'crear' || $comentarioAdminDisponible) : ?>
            <fieldset class="reservation-detail-form__optional-section">
                <legend>Detalles adicionales</legend>
                <?php if ($modo === 'crear') : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--note">
                            <span>Nota del cliente</span>
                            <textarea name="nota" rows="3" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($nota); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small class="reservation-detail-form__helper">Indicaciones proporcionadas por el cliente para su visita.</small>
                            <?php endif; ?>
                            <?php $error = $errorCampo('nota'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
                    </label>
                <?php endif; ?>

                <?php if ($comentarioAdminDisponible) : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--internal-comment">
                            <span>Comentario interno</span>
                            <textarea name="comentario_admin" rows="3" maxlength="<?php echo \Services\ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($comentarioAdmin); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small class="reservation-detail-form__helper">Información visible únicamente para el personal.</small>
                            <?php endif; ?>
                            <?php $error = $errorCampo('comentario_admin'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>"><?php echo $h($error); ?></span>
                    </label>
                <?php else : ?>
                    <p class="reservation-operation-inline reservation-operation-inline--warning">Los comentarios internos no estan disponibles en esta instalacion.</p>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($modo === 'crear') : ?>
            <label class="reservation-admin-form__check">
                <input type="hidden" name="asignar_automaticamente" value="0">
                <input type="checkbox" name="asignar_automaticamente" value="1" <?php echo $asignarAutomaticamente ? 'checked' : ''; ?> data-reservation-control>
                <span class="reservation-admin-form__check-copy">
                    <span>Asignar automáticamente las mesas disponibles.</span>
                    <small>Podrás cambiar las mesas después de crear la reservación.</small>
                </span>
            </label>
        <?php endif; ?>

        <?php if (!$formActionsExternal) : ?>
            <div class="reservation-detail-form__actions">
                <?php if ($modo === 'editar') : ?>
                    <button type="button" class="admin-btn admin-btn--secondary" data-form-cancel <?php echo !$iniciarEdicion ? 'hidden' : ''; ?>>Cancelar edicion</button>
                <?php endif; ?>
                <button type="submit" class="admin-btn admin-btn--primary" data-form-save <?php echo (!$editable || !$iniciarEdicion) ? 'hidden disabled' : ''; ?>>Guardar cambios</button>
            </div>
        <?php endif; ?>
    </form>
</article>
