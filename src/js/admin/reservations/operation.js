/**
 * Seleccion manual de mesas para la vista operativa de reservaciones.
 */
(function () {
    function initReservationOperation() {
        var root = document.querySelector('[data-page="reservation-operation"]');
        var form = document.getElementById('operation-assign-form');

        if (!root || !form) {
            return;
        }

        var requiredGuests = parseInt(root.getAttribute('data-required-guests') || '0', 10);
        var capacityTarget = root.querySelector('[data-operation-selected-capacity]');
        var countTarget = root.querySelector('[data-operation-selected-count]');
        var tablesTarget = root.querySelector('[data-operation-selected-tables]');
        var saveButton = root.querySelector('[data-operation-save]');
        var tableButtons = Array.prototype.slice.call(root.querySelectorAll('[data-operation-table]'));

        function inputFor(button) {
            return form.querySelector('input[name="mesa_ids[]"][value="' + button.getAttribute('data-table-id') + '"]');
        }

        function updateSummary() {
            var capacity = 0;
            var names = [];

            tableButtons.forEach(function (button) {
                var input = inputFor(button);
                var checked = Boolean(input && input.checked);

                button.classList.toggle('mesa-pin--highlight', checked);
                button.classList.toggle('reservation-operation-pin--selected', checked);

                if (!checked) {
                    return;
                }

                capacity += parseInt(button.getAttribute('data-capacity') || '0', 10);
                names.push(button.getAttribute('data-table-name') || ('Mesa ' + button.getAttribute('data-table-id')));
            });

            if (capacityTarget) {
                capacityTarget.textContent = String(capacity);
                capacityTarget.classList.toggle('is-insufficient', capacity < requiredGuests);
            }

            if (countTarget) {
                countTarget.textContent = String(names.length);
            }

            if (tablesTarget) {
                tablesTarget.textContent = names.length ? names.join(', ') : 'Sin mesas seleccionadas';
                tablesTarget.classList.toggle('is-empty', names.length === 0);
            }

            if (saveButton && saveButton.getAttribute('data-can-assign') === '1') {
                saveButton.disabled = names.length === 0 || capacity < requiredGuests;
            }
        }

        tableButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled) {
                    return;
                }

                var input = inputFor(button);

                if (!input) {
                    return;
                }

                input.checked = !input.checked;
                updateSummary();
            });
        });

        form.addEventListener('change', updateSummary);
        updateSummary();
    }

    document.addEventListener('DOMContentLoaded', initReservationOperation);
})();
