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
$asignarAutomaticamente = (bool)($asignarAutomaticamente ?? ($modo === 'crear'));
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$motivoNoEditable = (string)($motivoNoEditable ?? '');
$formTransport = (string)($formTransport ?? 'html');
$formActionsExternal = (bool)($formActionsExternal ?? false);
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
$disabled = !$iniciarEdicion;
$action = $modo === 'crear' ? '/admin/reservations/create' : '/admin/reservations/update';
$formId = $modo . '-reservation-admin-form';
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());
$autoAssignmentDisabled = $comensales > \Services\ReservacionConfig::MAX_COMENSALES_PUBLICO;
$contactInputDisabled = $disabled || $contactoTipo === 'ninguno';

$mensajeBloqueo = match ($motivoNoEditable) {
    \Services\ReservacionService::RESERVACION_PASADA => 'No se pueden modificar reservaciones de fechas anteriores.',
    \Services\ReservacionService::RESERVACION_HORARIO_PASADO => 'Esta reservacion ya paso de horario y no puede modificarse.',
    \Services\ReservacionService::ESTADO_NO_EDITABLE => 'Esta reservacion ya fue finalizada y no puede modificarse.',
    default => 'Esta reservacion no puede modificarse.',
};
?>

<article class="reservation-detail-card admin-card reservation-admin-form-card" data-reservation-form-card>
    <div class="reservation-detail-card__head reservation-admin-form-card__head">
        <div>
            <span class="reservation-detail-card__label"><?php echo $modo === 'crear' ? 'Alta administrativa' : 'Informacion editable'; ?></span>
            <h3>Datos de la reservacion</h3>
            <p><?php echo $modo === 'crear' ? 'Registra la visita y los datos de contacto del cliente.' : 'Actualiza la informacion de la visita sin perder el contexto operativo.'; ?></p>
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
                    <h3 class="reservation-detail-form__group-label" id="<?php echo $h($formId . '-visit-title'); ?>">Datos de visita</h3>
                    <p>Define cuándo y para cuántas personas se prepara la visita.</p>
                </div>
                <div class="reservation-detail-form__fields reservation-detail-form__fields--visit">
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
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="fecha"><?php echo $h($error); ?></span>
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
                        $endpoint = '/admin/api/reservations/disponibilidad';
                        include __DIR__ . '/../../components/reservations/time-picker.php';
                        ?>
                        <?php $error = $errorCampo('hora'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-time-status data-field-error="hora"><?php echo $h($error); ?></span>
                    </label>

                    <label class="reservation-detail-form__field reservation-detail-form__field--guests">
                        <span>Comensales</span>
                        <input type="number" name="comensales" min="1" max="<?php echo $maxComensalesAdmin; ?>" value="<?php echo $comensales; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                        <?php $error = $errorCampo('comensales'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="comensales"><?php echo $h($error); ?></span>
                    </label>
                </div>
                <div class="reservation-capacity-summary" data-reservation-capacity-summary hidden>
                    <div>
                        <span>Capacidad total</span>
                        <strong data-capacity-total>0</strong>
                    </div>
                    <div>
                        <span>Libre actualmente</span>
                        <strong data-capacity-real>0</strong>
                    </div>
                    <div>
                        <span>Liberación proyectada</span>
                        <strong data-capacity-projected>0</strong>
                    </div>
                    <div>
                        <span>Estimada para el horario</span>
                        <strong data-capacity-estimated>0</strong>
                    </div>
                    <p class="reservation-capacity-summary__warning" data-capacity-warning hidden></p>
                </div>
            </section>

            <section class="reservation-detail-form__section" aria-labelledby="<?php echo $h($formId . '-client-title'); ?>">
                <div class="reservation-detail-form__section-head">
                    <h3 class="reservation-detail-form__group-label" id="<?php echo $h($formId . '-client-title'); ?>">Cliente y contacto</h3>
                    <p>Usa estos datos para identificar y contactar al cliente.</p>
                </div>
                <div class="reservation-detail-form__fields reservation-detail-form__fields--client">
                    <label class="reservation-detail-form__field reservation-detail-form__field--name">
                        <span>Nombre</span>
                        <input type="text" name="nombre" value="<?php echo $h($nombre); ?>" maxlength="<?php echo \Services\ReservacionConfig::NOMBRE_MAX_CARACTERES; ?>" required data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>>
                        <?php $error = $errorCampo('nombre'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="nombre"><?php echo $h($error); ?></span>
                    </label>

                    <label class="reservation-detail-form__field reservation-detail-form__field--contact-type">
                        <span>Tipo de contacto</span>
                        <select name="contacto_tipo" data-reservation-control data-contact-type required <?php echo $disabled ? 'disabled' : ''; ?>>
                                <option value="email" <?php echo $contactoTipo === 'email' ? 'selected' : ''; ?>>Correo</option>
                                <option value="ninguno" <?php echo $contactoTipo === 'ninguno' ? 'selected' : ''; ?>>Sin contacto</option>
                                <option value="telefono" <?php echo $contactoTipo === 'telefono' ? 'selected' : ''; ?>>Teléfono</option>
                        </select>
                        <?php $error = $errorCampo('contacto_tipo'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="contacto_tipo"><?php echo $h($error); ?></span>
                    </label>

                    <label class="reservation-detail-form__field reservation-detail-form__field--contact">
                        <span data-contact-field-label><?php echo $modo === 'editar' ? ($contactoTipo === 'telefono' ? 'Teléfono' : 'Correo electrónico') : 'Contacto'; ?> <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                        <input
                            type="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            name="contacto"
                            value="<?php echo $h($contacto); ?>"
                            maxlength="<?php echo \Services\ReservacionConfig::EMAIL_MAX_CARACTERES; ?>"
                            <?php if ($modo === 'editar') : ?>
                                placeholder="<?php echo $contactoTipo === 'telefono' ? '+52 55 1234 5678' : 'cliente@ejemplo.com'; ?>"
                            <?php endif; ?>
                            autocomplete="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            inputmode="<?php echo $contactoTipo === 'telefono' ? 'tel' : 'email'; ?>"
                            data-reservation-control
                            data-contact-value
                            <?php echo $contactInputDisabled ? 'disabled' : ''; ?>
                        >
                        <?php $error = $errorCampo('contacto'); ?>
                        <small class="reservation-detail-form__helper" data-contact-help><?php echo $modo === 'editar' ? ($contactoTipo === 'telefono' ? 'Incluye lada y diez dígitos; el sistema normalizará el prefijo de México.' : 'Escribe un correo electrónico válido.') : ''; ?></small>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="contacto"><?php echo $h($error); ?></span>
                    </label>
                </div>
            </section>
        </div>

        <?php if ($modo === 'crear' || $comentarioAdminDisponible) : ?>
            <fieldset class="reservation-detail-form__optional-section">
                <legend>Detalles adicionales</legend>
                <p class="reservation-detail-form__section-description">Añade indicaciones del cliente o información útil para el equipo.</p>
                <?php if ($modo === 'crear') : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--note">
                            <span>Nota del cliente <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                            <textarea name="nota" rows="3" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($nota); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small class="reservation-detail-form__helper">Indicaciones proporcionadas por el cliente para su visita.</small>
                            <?php endif; ?>
                            <?php $error = $errorCampo('nota'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="nota"><?php echo $h($error); ?></span>
                    </label>
                <?php endif; ?>

                <?php if ($comentarioAdminDisponible) : ?>
                        <label class="reservation-detail-form__field reservation-detail-form__field--internal-comment">
                            <span>Comentario interno <small class="reservation-detail-form__optional-label">(Opcional)</small></span>
                            <textarea name="comentario_admin" rows="3" maxlength="<?php echo \Services\ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES; ?>" data-reservation-control <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $h($comentarioAdmin); ?></textarea>
                            <?php if ($modo === 'crear') : ?>
                                <small class="reservation-detail-form__helper">Información visible únicamente para el personal.</small>
                            <?php endif; ?>
                            <?php $error = $errorCampo('comentario_admin'); ?>
                        <span class="reservation-detail-field-msg <?php echo $error !== '' ? 'show' : ''; ?>" data-field-error="comentario_admin"><?php echo $h($error); ?></span>
                    </label>
                <?php else : ?>
                    <p class="reservation-operation-inline reservation-operation-inline--warning">Los comentarios internos no estan disponibles en esta instalacion.</p>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($modo === 'crear' || $modo === 'editar') : ?>
            <fieldset class="reservation-detail-form__assignment-section">
                <legend>Asignación de mesas</legend>
                <p class="reservation-detail-form__section-description">El sistema buscara una combinacion de mesas que cubra el numero de comensales.</p>
            <label class="reservation-admin-form__check">
                <input type="hidden" name="asignar_automaticamente" value="0">
                <input type="checkbox" name="asignar_automaticamente" value="1" <?php echo $asignarAutomaticamente && !$autoAssignmentDisabled ? 'checked' : ''; ?> <?php echo $autoAssignmentDisabled ? 'disabled' : ''; ?> data-reservation-control data-automatic-assignment data-auto-disabled="<?php echo $autoAssignmentDisabled ? '1' : '0'; ?>">
                <span class="reservation-admin-form__check-copy">
                    <span>Asignar automáticamente las mesas disponibles.</span>
                    <small data-assignment-help><?php echo $autoAssignmentDisabled ? 'Para mas de 12 personas se requiere asignacion manual.' : 'Puedes guardar sin mesas y asignarlas despues desde Operacion.'; ?></small>
                </span>
            </label>
            </fieldset>
        <?php endif; ?>

        <?php if (!$formActionsExternal) : ?>
            <div class="reservation-detail-form__actions">
                <?php if ($modo === 'editar') : ?>
                    <button type="button" class="admin-btn admin-btn--secondary" data-form-cancel <?php echo !$iniciarEdicion ? 'hidden' : ''; ?>>Cancelar edicion</button>
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
