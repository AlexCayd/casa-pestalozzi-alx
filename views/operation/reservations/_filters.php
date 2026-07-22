<form
    class="reservation-operation__filters"
    method="GET"
    action="/admin/reservations/operation"
    aria-label="Filtros de operación de reservaciones"
    data-operation-filters
>
    <div class="reservation-operation__filter reservation-operation__date-group" data-operation-date-group>
        <label class="operational-visually-hidden" for="operation-fecha-display">Fecha</label>
        <?php
        $rootId = 'operation-date-picker';
        $inputId = 'operation-fecha';
        $displayId = 'operation-fecha-display';
        $calendarId = 'operation-date-calendar';
        $name = 'fecha';
        $value = $fechaInicial;
        $min = '';
        $today = $fechaMinima;
        $disabled = false;
        $enabledWeekdays = [];
        $inputDataAttributes = ['data-operational-context-date' => true];
        $displayAriaDescribedby = 'operation-date-warning';
        $displayAriaInvalid = $fechaInvalidaRecibida !== '';
        $rootClass = 'operational-context-date';
        $showIcon = true;
        $prevId = '';
        $nextId = '';
        $labelId = '';
        $gridId = '';
        include __DIR__ . '/../../components/reservations/date-picker.php';
        ?>
        <div
            class="reservation-operation-notice reservation-operation-notice--warning reservation-operation-date-warning"
            id="operation-date-warning"
            role="status"
            aria-live="polite"
            data-operation-date-warning
            <?php echo $fechaInvalidaRecibida !== '' ? '' : 'hidden'; ?>
        >
            <span class="reservation-operation-notice__icon" aria-hidden="true">!</span>
            <span class="reservation-operation-notice__copy">
                <strong>Fecha inválida</strong>
                <span data-operation-date-warning-message>
                    La fecha recibida no tiene un formato válido.
                    <?php if ($fechaInvalidaRecibida !== ''): ?> Valor recibido: <?php echo $h($fechaInvalidaRecibida); ?>.<?php endif; ?>
                </span>
            </span>
        </div>
    </div>

    <div class="reservation-operation__filter" data-operation-time-group>
        <label class="operational-visually-hidden" for="operation-hora-display">Horario</label>
        <?php
        $rootId = 'operation-time-picker';
        $inputId = 'operation-hora';
        $displayId = 'operation-hora-display';
        $dropdownId = 'operation-time-dropdown';
        $name = 'hora';
        $value = $horaInicial;
        $endpoint = '';
        $disabled = false;
        $placeholder = 'Cargando horarios';
        $staticStep = 0;
        $required = false;
        $displayAriaDescribedby = '';
        $displayAriaInvalid = false;
        $inputDataAttributes = [
            'data-operation-hour' => true,
            'data-operational-context-hour' => true,
        ];
        include __DIR__ . '/../../components/reservations/time-picker.php';
        ?>
    </div>

    <div class="reservation-operation__filter-actions">
        <button type="submit" class="admin-btn admin-btn--secondary" data-operation-load>Consultar</button>
    </div>
</form>
