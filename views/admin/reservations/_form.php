<?php
/**
 * Shared admin reservation form for create/edit.
 */

$modo = (string)($modo ?? 'editar');
$reservacion = is_object($reservacion ?? null) ? $reservacion : new \Model\Reservacion();
$errores = is_array($errores ?? null) ? $errores : [];
$editable = (bool)($editable ?? true);
$returnUrl = (string)($returnUrl ?? '/admin/reservaciones');
$backUrl = (string)($backUrl ?? '/admin/reservaciones');
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$fechaActual = (string)($fechaActual ?? \Services\ReservacionConfig::fechaActual());
$diasActivos = is_array($diasActivos ?? null) ? $diasActivos : [];
$maxComensalesAdmin = (int)($maxComensalesAdmin ?? \Services\ReservacionConfig::MAX_COMENSALES_ADMIN);
$comentarioAdminDisponible = (bool)($comentarioAdminDisponible ?? true);
$asignarAutomaticamente = (bool)($asignarAutomaticamente ?? ($modo === 'crear'));
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$motivoNoEditable = (string)($motivoNoEditable ?? '');
$formTransport = (string)($formTransport ?? 'html');
$formActionsExternal = (bool)($formActionsExternal ?? false);
$modalForm = $formTransport === 'json';
$formAction = trim((string)($formAction ?? ''));
$mostrarCamposContacto = (bool)($mostrarCamposContacto ?? true);
$disponibilidadEndpoint = (string)($disponibilidadEndpoint ?? '/admin/api/reservaciones/disponibilidad');
$capacidadWarning = is_array($capacidadWarning ?? null) ? $capacidadWarning : [];
$mostrarCapacidadWarning = $modo === 'crear'
    && $formTransport === 'html'
    && !empty($capacidadWarning['confirmaciones_requeridas']);

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
$contacto = (string)$valor($reservacion, 'contacto');
$contactoTipoRegistrado = trim((string)$valor($reservacion, 'contacto_tipo'));
$tiposContacto = [
    'email' => 'Correo',
    'telefono' => 'Teléfono',
];
$tiposContacto['ninguno'] = 'Sin contacto';
$contactoTipo = in_array($contactoTipoRegistrado, array_keys($tiposContacto), true)
    ? $contactoTipoRegistrado
    : 'ninguno';
$fecha = (string)$valor($reservacion, 'fecha', $fechaActual);
$hora = \Services\HorarioReservacionService::normalizarHoraCorta((string)$valor($reservacion, 'hora'));
$comensales = max(1, (int)$valor($reservacion, 'comensales', 2));
$nota = (string)$valor($reservacion, 'nota');
$comentarioAdmin = (string)$valor($reservacion, 'comentario_admin');
$estado = (string)$valor($reservacion, 'estado', 'confirmada');
$requestToken = (string)$valor($reservacion, 'request_token');
$tieneMesas = count($mesasAsignadas) > 0 || (int)$valor($reservacion, 'mesas_count', 0) > 0;
$iniciarEdicion = $modo === 'crear' || (!empty($errores) && $editable);
$formDisabled = !$iniciarEdicion;
$action = $formAction !== ''
    ? $formAction
    : ($modo === 'crear' ? '/admin/reservaciones/crear' : '/admin/reservaciones/actualizar');
$formId = $modo . '-reservation-admin-form';
$fieldId = static fn (string $field): string => $formId . '-' . $field;
$fieldErrorId = static fn (string $field): string => $fieldId($field) . '-error';
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());
$autoAssignmentDisabled = $comensales > \Services\ReservacionConfig::MAX_COMENSALES_PUBLICO;
$contactInputDisabled = $formDisabled || $contactoTipo === 'ninguno';

$mensajeBloqueo = match ($motivoNoEditable) {
    \Services\ReservacionService::RESERVACION_PASADA => 'No se pueden modificar reservaciones de fechas anteriores.',
    \Services\ReservacionService::RESERVACION_HORARIO_PASADO => 'Esta reservacion ya paso de horario y no puede modificarse.',
    \Services\ReservacionService::ESTADO_NO_EDITABLE => 'Esta reservacion ya fue finalizada y no puede modificarse.',
    default => 'Esta reservacion no puede modificarse.',
};
?>

<article class="reservation-detail-card admin-card reservation-admin-form-card" data-reservation-form-card>
    <?php if ($modo === 'editar') : ?>
    <div class="reservation-detail-card__head reservation-admin-form-card__head">
        <div>
            <span class="reservation-detail-card__label">Reservación</span>
            <h3>Datos de la reservación</h3>
        </div>
        <?php if ($editable) : ?>
            <span class="admin-badge admin-badge--info" data-editing-badge <?php echo !$iniciarEdicion ? 'hidden' : ''; ?>>Editando</span>
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
    <?php endif; ?>

    <?php if (!$editable) : ?>
        <p class="reservation-detail-warning"><?php echo $h($mensajeBloqueo); ?></p>
    <?php endif; ?>

    <form
        id="<?php echo $h($formId); ?>"
        class="reservation-detail-form <?php echo $iniciarEdicion ? 'is-editing' : ''; ?>"
        method="POST"
        action="<?php echo $h($action); ?>"
        novalidate
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
        <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
        <input type="hidden" name="confirmaciones" value="" data-admin-confirmations>
        <input type="hidden" name="confirmar_sobrecapacidad" value="0" data-admin-overcapacity-confirmation>
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
        <?php if ($formTransport === 'json') : ?>
            <input type="hidden" name="response_format" value="json">
        <?php endif; ?>

        <div
            class="reservation-detail-form__feedback"
            role="status"
            aria-live="polite"
            hidden
            data-form-feedback
        ></div>

        <div class="reservation-detail-form__grid">
            <section class="reservation-detail-form__section" aria-labelledby="<?php echo $h($formId . '-visit-title'); ?>">
                <div class="reservation-detail-form__section-head">
                    <h3 class="reservation-detail-form__group-label" id="<?php echo $h($formId . '-visit-title'); ?>">Visita</h3>
                </div>
                <div class="reservation-detail-form__fields reservation-detail-form__fields--visit">
                    <label class="reservation-detail-form__field reservation-detail-form__field--date">
                        <span>Fecha</span>
                        <?php
                        $error = $errorCampo('fecha');
                        $rootId = $modo . '-reservation-date-picker';
                        $inputId = $modo . '-reservation-date';
                        $displayId = $modo . '-reservation-date-display';
                        $calendarId = $modo . '-reservation-calendar';
                        $name = 'fecha';
                        $value = $fecha;
                        $min = $fechaActual;
                        $enabledWeekdays = $diasActivos;
                        $disabled = $formDisabled;
                        $displayAriaDescribedby = $fieldErrorId('fecha');
                        $displayAriaInvalid = $error !== '';
                        include __DIR__ . '/../../components/reservations/date-picker.php';
                        ?>
                        <span id="<?php echo $h($fieldErrorId('fecha')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="fecha" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>

                    <label class="reservation-detail-form__field reservation-detail-form__field--time">
                        <span>Hora</span>
                        <?php
                        $error = $errorCampo('hora');
                        $rootId = $modo . '-reservation-time-picker';
                        $inputId = $modo . '-reservation-time';
                        $displayId = $modo . '-reservation-time-display';
                        $dropdownId = $modo . '-reservation-time-dropdown';
                        $name = 'hora';
                        $value = $hora;
                        // Los parciales de fecha/hora limpian sus parámetros al
                        // terminar. Mantener este estado con otro nombre evita
                        // que el include destruya la condición del formulario.
                        $disabled = $formDisabled;
                        $endpoint = $disponibilidadEndpoint;
                        $displayAriaDescribedby = $fieldErrorId('hora');
                        $displayAriaInvalid = $error !== '';
                        include __DIR__ . '/../../components/reservations/time-picker.php';
                        ?>
                        <span id="<?php echo $h($fieldErrorId('hora')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-time-status data-field-error="hora" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>

                    <label class="reservation-detail-form__field reservation-detail-form__field--guests">
                        <span>Comensales</span>
                        <?php $error = $errorCampo('comensales'); ?>
                        <input id="<?php echo $h($fieldId('comensales')); ?>" type="number" name="comensales" min="1" max="<?php echo $maxComensalesAdmin; ?>" value="<?php echo $comensales; ?>" aria-describedby="<?php echo $h($fieldErrorId('comensales')); ?>" aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>" required data-reservation-control <?php echo $formDisabled ? 'disabled' : ''; ?>>
                        <span id="<?php echo $h($fieldErrorId('comensales')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="comensales" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>
                </div>
                <div class="reservation-capacity-summary" data-reservation-capacity-summary hidden>
                    <div>
                        <span>Capacidad para este horario</span>
                        <strong><span data-capacity-real>0</span> lugares disponibles</strong>
                    </div>
                    <div>
                        <span>Solicitud</span>
                        <strong><span data-capacity-requested>0</span> personas</strong>
                    </div>
                    <p class="reservation-capacity-summary__warning" data-capacity-warning hidden></p>
                </div>
            </section>

            <section class="reservation-detail-form__section" aria-labelledby="<?php echo $h($formId . '-client-title'); ?>">
                <div class="reservation-detail-form__section-head">
                    <h3 class="reservation-detail-form__group-label" id="<?php echo $h($formId . '-client-title'); ?>">Cliente</h3>
                </div>
                <div class="reservation-detail-form__fields reservation-detail-form__fields--client">
                    <label class="reservation-detail-form__field reservation-detail-form__field--name">
                        <span>Nombre</span>
                        <?php $error = $errorCampo('nombre'); ?>
                        <input id="<?php echo $h($fieldId('nombre')); ?>" type="text" name="nombre" value="<?php echo $h($nombre); ?>" maxlength="<?php echo \Services\ReservacionConfig::NOMBRE_MAX_CARACTERES; ?>" aria-describedby="<?php echo $h($fieldErrorId('nombre')); ?>" aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>" required data-reservation-control <?php echo $formDisabled ? 'disabled' : ''; ?>>
                        <span id="<?php echo $h($fieldErrorId('nombre')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="nombre" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>

                    <?php if ($mostrarCamposContacto) : ?>
                    <label class="reservation-detail-form__field reservation-detail-form__field--contact-type">
                        <span>Tipo de contacto</span>
                        <?php $error = $errorCampo('contacto_tipo'); ?>
                        <select id="<?php echo $h($fieldId('contacto_tipo')); ?>" name="contacto_tipo" aria-describedby="<?php echo $h($fieldErrorId('contacto_tipo')); ?>" aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>" data-reservation-control data-contact-type required <?php echo $formDisabled ? 'disabled' : ''; ?>>
                                <option value="email" <?php echo $contactoTipo === 'email' ? 'selected' : ''; ?>>Correo</option>
                                <option value="ninguno" <?php echo $contactoTipo === 'ninguno' ? 'selected' : ''; ?>>Sin contacto</option>
                                <option value="telefono" <?php echo $contactoTipo === 'telefono' ? 'selected' : ''; ?>>Teléfono</option>
                        </select>
                        <span id="<?php echo $h($fieldErrorId('contacto_tipo')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="contacto_tipo" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>
                    <?php endif; ?>

                    <?php if ($mostrarCamposContacto) : ?>
                    <label class="reservation-detail-form__field reservation-detail-form__field--contact">
                        <span data-contact-field-label><?php echo $modo === 'editar' ? ($contactoTipo === 'telefono' ? 'Teléfono' : 'Correo electrónico') : 'Contacto'; ?> <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                        <?php $error = $errorCampo('contacto'); ?>
                        <input
                            id="<?php echo $h($fieldId('contacto')); ?>"
                            type="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            name="contacto"
                            value="<?php echo $h($contacto); ?>"
                            maxlength="<?php echo \Services\ReservacionConfig::EMAIL_MAX_CARACTERES; ?>"
                            <?php if ($modo === 'editar') : ?>
                                placeholder="<?php echo $contactoTipo === 'telefono' ? '+52 55 1234 5678' : 'cliente@ejemplo.com'; ?>"
                            <?php endif; ?>
                            autocomplete="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            inputmode="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            aria-describedby="<?php echo $h($fieldId('contacto') . '-help ' . $fieldErrorId('contacto')); ?>"
                            aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>"
                            data-reservation-control
                            data-contact-value
                            <?php echo $contactInputDisabled ? 'disabled' : ''; ?>
                        >
                        <small id="<?php echo $h($fieldId('contacto') . '-help'); ?>" class="reservation-detail-form__helper" data-contact-help><?php echo $modo === 'editar' ? ($contactoTipo === 'telefono' ? 'Incluye lada y diez dígitos; el sistema normalizará el prefijo de México.' : 'Escribe un correo electrónico válido.') : ''; ?></small>
                        <span id="<?php echo $h($fieldErrorId('contacto')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="contacto" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <?php if ($modo === 'crear' || $comentarioAdminDisponible) : ?>
            <fieldset class="reservation-detail-form__optional-section">
                <legend>Detalles</legend>
                <p class="reservation-detail-form__section-description">Información opcional para el equipo.</p>
                <?php if ($modo === 'crear') : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--note">
                            <span>Nota del cliente <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                            <?php $error = $errorCampo('nota'); ?>
                            <textarea id="<?php echo $h($fieldId('nota')); ?>" name="nota" rows="3" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" aria-describedby="<?php echo $h($fieldId('nota') . '-help ' . $fieldErrorId('nota')); ?>" aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>" data-reservation-control <?php echo $formDisabled ? 'disabled' : ''; ?>><?php echo $h($nota); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small id="<?php echo $h($fieldId('nota') . '-help'); ?>" class="reservation-detail-form__helper"><?php echo $modalForm ? 'Indicaciones para la visita.' : 'Indicaciones proporcionadas por el cliente para su visita.'; ?></small>
                            <?php endif; ?>
                        <span id="<?php echo $h($fieldErrorId('nota')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="nota" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>
                <?php endif; ?>

                <?php if ($comentarioAdminDisponible) : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--internal-comment">
                            <span>Comentario interno <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                            <?php $error = $errorCampo('comentario_admin'); ?>
                            <textarea id="<?php echo $h($fieldId('comentario_admin')); ?>" name="comentario_admin" rows="3" maxlength="<?php echo \Services\ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES; ?>" aria-describedby="<?php echo $h(($modo === 'crear' ? $fieldId('comentario_admin') . '-help ' : '') . $fieldErrorId('comentario_admin')); ?>" aria-invalid="<?php echo $error !== '' ? 'true' : 'false'; ?>" data-reservation-control <?php echo $formDisabled ? 'disabled' : ''; ?>><?php echo $h($comentarioAdmin); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small id="<?php echo $h($fieldId('comentario_admin') . '-help'); ?>" class="reservation-detail-form__helper"><?php echo $modalForm ? 'Visible sólo para el personal.' : 'Información visible únicamente para el personal.'; ?></small>
                            <?php endif; ?>
                        <span id="<?php echo $h($fieldErrorId('comentario_admin')); ?>" class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="comentario_admin" aria-live="polite"><?php echo $h($error); ?></span>
                    </label>
                <?php else : ?>
                    <p class="reservation-operation-inline reservation-operation-inline--warning">Los comentarios internos no estan disponibles en esta instalacion.</p>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($modo === 'crear' || $modo === 'editar') : ?>
            <fieldset class="reservation-detail-form__assignment-section">
                <legend>Asignación de mesas</legend>
            <label class="reservation-admin-form__check">
                <input type="hidden" name="asignar_automaticamente" value="0">
                <input type="checkbox" name="asignar_automaticamente" value="1" <?php echo $asignarAutomaticamente && !$autoAssignmentDisabled ? 'checked' : ''; ?> <?php echo $autoAssignmentDisabled ? 'disabled' : ''; ?> data-reservation-control data-automatic-assignment data-auto-disabled="<?php echo $autoAssignmentDisabled ? '1' : '0'; ?>">
                <span class="reservation-admin-form__check-copy">
                    <span>Asignar mesas automáticamente</span>
                    <small data-assignment-help><?php echo $autoAssignmentDisabled ? 'Para más de 12 personas se requiere asignación manual.' : 'Disponible hasta 12 personas.'; ?></small>
                </span>
            </label>
            </fieldset>
        <?php endif; ?>

        <?php if (!$formActionsExternal) : ?>
            <div class="reservation-detail-form__actions">
                <?php if ($modo === 'editar') : ?>
                    <button type="button" class="admin-btn admin-btn--ghost" data-form-cancel <?php echo !$iniciarEdicion ? 'hidden' : ''; ?>>Cancelar edición</button>
                <?php endif; ?>
                <button type="submit" class="admin-btn admin-btn--primary" data-form-save <?php echo (!$editable || !$iniciarEdicion) ? 'hidden disabled' : ''; ?>><?php echo $modo === 'crear' ? 'Crear reservacion' : 'Guardar cambios'; ?></button>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($modo === 'crear') : ?>
        <div
            class="reservation-business-confirmation-host"
            data-reservation-confirmation
            data-confirmation-autostart="<?php echo $mostrarCapacidadWarning ? 'capacity' : ''; ?>"
            data-confirmation-requested="<?php echo (int)($capacidadWarning['capacidad_solicitada'] ?? 0); ?>"
            data-confirmation-available="<?php echo (int)($capacidadWarning['capacidad_disponible'] ?? 0); ?>"
        ></div>
    <?php elseif ($modo === 'editar') : ?>
        <div
            class="reservation-business-confirmation-host"
            data-reservation-confirmation
        ></div>
    <?php endif; ?>
</article>
