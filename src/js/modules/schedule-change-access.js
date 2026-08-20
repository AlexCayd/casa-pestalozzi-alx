(function () {
  function init() {
    var page = document.querySelector('[data-schedule-change-page]');
    var form = document.querySelector('[data-schedule-change-form]');
    if (!page || !form) return;

    var csrf = form.querySelector('[data-change-csrf]');
    var dateRoot = form.querySelector('[data-reservation-date-picker]');
    var timeRoot = form.querySelector('[data-reservation-time-picker]');
    var dateInput = form.querySelector('[data-change-date]');
    var timeInput = form.querySelector('[data-change-time]');
    var guests = form.querySelector('[data-change-guests]');
    var guestValue = form.querySelector('[data-change-guests-value]');
    var note = form.querySelector('[data-change-note]');
    var status = form.querySelector('[data-change-status]');
    var timeHint = form.querySelector('[data-change-time-hint]');
    var submit = form.querySelector('[data-change-submit]');
    var moreGuests = form.querySelector('[data-change-guest-more]');
    var stepper = form.querySelector('[data-change-guest-stepper]');
    var minGuests = 1;
    var maxGuests = Number(form.getAttribute('data-max-guests') || '0');
    if (!Number.isFinite(maxGuests) || maxGuests < minGuests) maxGuests = minGuests;
    var datePicker = null;
    var timePicker = null;
    var retryButton = null;

    if (!csrf || !dateRoot || !timeRoot || !dateInput || !timeInput || !guests || !status || !submit) {
      return;
    }

    function setStatus(message, type) {
      status.textContent = message || '';
      status.className = 'schedule-change-status' + (type ? ' is-' + type : '');
    }

    function setTimeHint(message) {
      if (timeHint) timeHint.textContent = message || '';
    }

    function formatConfirmedDate(value) {
      var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!match) return String(value || '');
      var date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
      return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(date);
    }

    function request(url, body) {
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeoutId = setTimeout(function () {
        if (controller) controller.abort();
      }, 12000);

      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({}, body, { csrf_token: csrf.value })),
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        return response.text().then(function (raw) {
          var data;
          try {
            data = JSON.parse(raw);
          } catch (error) {
            throw new Error('No fue posible completar la solicitud.');
          }
          if (!response.ok || data.ok === false) {
            throw new Error(data.mensaje || 'No fue posible completar la solicitud.');
          }
          return data;
        });
      }).catch(function (error) {
        if (error && error.name === 'AbortError') {
          throw new Error('La solicitud tardó demasiado. Intenta nuevamente.');
        }
        throw error;
      }).finally(function () {
        clearTimeout(timeoutId);
      });
    }

    function updateGuestState(value, reload) {
      value = Math.max(minGuests, Math.min(maxGuests, Number(value) || minGuests));
      guests.value = String(value);
      if (guestValue) guestValue.textContent = String(value);

      form.querySelectorAll('[data-change-guest]').forEach(function (button) {
        var selected = Number(button.getAttribute('data-change-guest')) === value;
        button.classList.toggle('sel', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      if (moreGuests) {
        var expanded = value > 6;
        moreGuests.classList.toggle('sel', expanded);
        moreGuests.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
      if (stepper) stepper.hidden = value <= 6;
      if (reload && dateInput.value) loadTimes();
    }

    function ensureRetryButton() {
      if (retryButton) return retryButton;
      retryButton = document.createElement('button');
      retryButton.type = 'button';
      retryButton.className = 'schedule-change-retry';
      retryButton.textContent = 'Reintentar';
      retryButton.addEventListener('click', function () {
        if (dateInput.value) loadTimes();
      });
      if (timeHint) timeHint.insertAdjacentElement('afterend', retryButton);
      return retryButton;
    }

    function hideRetryButton() {
      if (retryButton) retryButton.hidden = true;
    }

    function loadTimes() {
      if (!timePicker || !dateInput.value) {
        setTimeHint('Primero elige una fecha.');
        hideRetryButton();
        return;
      }
      hideRetryButton();
      setTimeHint('Consultando horarios…');
      timePicker.loadForDate(dateInput.value, '');
    }

    datePicker = window.createReservationDatePicker({
      root: dateRoot,
      inline: true,
      allowPast: false
    });
    timePicker = window.createReservationTimePicker({
      root: timeRoot,
      inline: true,
      endpoint: '/api/reservaciones/cambio-horario/disponibilidad',
      requestTimeoutMs: 10000,
      getQueryParams: function () {
        return { personas: Number(guests.value || 0) };
      }
    });

    dateInput.addEventListener('reservation:datechange', loadTimes);
    timeRoot.addEventListener('reservation:scheduleloaded', function (event) {
      var data = event.detail || {};
      if (data.ok === false) {
        setTimeHint(data.mensaje || 'No pudimos consultar los horarios.');
        ensureRetryButton().hidden = false;
        setStatus('', '');
        return;
      }
      var available = Array.isArray(data.horarios)
        ? data.horarios.filter(function (slot) { return slot && slot.disponible !== false; })
        : [];
      if (data.abierto === false || available.length === 0) {
        setTimeHint(data.mensaje || 'No hay horarios disponibles para esa fecha.');
      } else {
        setTimeHint('Elige una hora disponible.');
      }
      hideRetryButton();
    });

    form.querySelectorAll('[data-change-guest]').forEach(function (button) {
      button.addEventListener('click', function () {
        updateGuestState(button.getAttribute('data-change-guest'), true);
      });
    });
    if (moreGuests) {
      moreGuests.addEventListener('click', function () {
        var next = Math.max(7, Number(guests.value || 1));
        updateGuestState(next, true);
      });
    }
    var minus = form.querySelector('[data-change-minus]');
    var plus = form.querySelector('[data-change-plus]');
    if (minus) minus.addEventListener('click', function () { updateGuestState(Number(guests.value) - 1, true); });
    if (plus) plus.addEventListener('click', function () { updateGuestState(Number(guests.value) + 1, true); });

    updateGuestState(guests.value, false);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var people = Number(guests.value || 0);
      if (!dateInput.value || !timeInput.value) {
        setStatus('Selecciona una nueva fecha y un horario disponible.', 'error');
        return;
      }
      if (people < minGuests || people > maxGuests) {
        setStatus('Elige entre ' + minGuests + ' y ' + maxGuests + ' personas.', 'error');
        return;
      }

      submit.disabled = true;
      setStatus('Guardando modificación…', 'pending');
      request('/api/reservaciones/cambio-horario/modificar', {
        fecha: dateInput.value,
        hora: timeInput.value,
        personas: people,
        nota: note ? note.value : ''
      }).then(function () {
        var success = document.createElement('section');
        success.className = 'schedule-change-success';
        success.setAttribute('role', 'status');
        var eyebrow = document.createElement('p');
        eyebrow.className = 'schedule-change-eyebrow';
        eyebrow.textContent = 'Listo';
        var title = document.createElement('h2');
        title.textContent = 'Reservación actualizada';
        var summary = document.createElement('p');
        summary.className = 'schedule-change-success__summary';
        summary.textContent = formatConfirmedDate(dateInput.value) + ' · ' + String(timeInput.value || '').substring(0, 5) + ' · ' + people + ' ' + (people === 1 ? 'persona' : 'personas');
        var copy = document.createElement('p');
        copy.textContent = 'Te esperamos en Casa Pestalozzi.';
        success.append(eyebrow, title, summary, copy);
        form.replaceWith(success);
      }).catch(function (error) {
        setStatus(error.message || 'No fue posible completar la solicitud.', 'error');
        submit.disabled = false;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
