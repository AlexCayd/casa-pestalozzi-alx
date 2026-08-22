(function () {
  function init() {
    var page = document.querySelector('[data-schedule-change-page]');
    if (!page) return;
    var form = page.querySelector('[data-schedule-change-form]');
    var card = page.querySelector('[data-schedule-change-card]');
    var csrf = page.querySelector('[data-management-csrf]');
    var source = page.getAttribute('data-management-source') || 'schedule_change';

    function request(url, body) {
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeoutId = window.setTimeout(function () { if (controller) controller.abort(); }, 12000);
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({}, body, { csrf_token: csrf ? csrf.value : '' })),
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        return response.text().then(function (raw) {
          var data;
          try { data = JSON.parse(raw); } catch (error) { throw new Error('La respuesta no fue válida. Intenta nuevamente.'); }
          if (!response.ok || data.ok === false) {
            throw new Error(data.mensaje || 'No fue posible completar la solicitud. Intenta nuevamente.');
          }
          return data;
        });
      }).catch(function (error) {
        if (error && error.name === 'AbortError') {
          throw new Error('La solicitud tardó demasiado. Revisa tu conexión e intenta nuevamente.');
        }
        throw error;
      }).finally(function () { window.clearTimeout(timeoutId); });
    }

    function formatDate(value) {
      var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!match) return String(value || '');
      var date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
      return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(date);
    }

    function successShell(eyebrowText, titleText) {
      var success = document.createElement('section');
      success.className = 'schedule-change-success';
      success.setAttribute('role', 'status');
      success.setAttribute('aria-labelledby', 'reservation-management-success-title');
      success.setAttribute('tabindex', '-1');
      var mark = document.createElement('span');
      mark.className = 'schedule-change-success__mark';
      mark.setAttribute('aria-hidden', 'true');
      mark.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="m5 12 4.5 4.5L19 7"/></svg>';
      var content = document.createElement('div');
      var eyebrow = document.createElement('p');
      eyebrow.className = 'schedule-change-eyebrow';
      eyebrow.textContent = eyebrowText;
      var title = document.createElement('h2');
      title.id = 'reservation-management-success-title';
      title.textContent = titleText;
      content.append(eyebrow, title);
      success.append(mark, content);
      return { root: success, content: content };
    }

    function replaceCard(success) {
      if (card) card.replaceChildren(success);
      else page.replaceChildren(success);
      window.requestAnimationFrame(function () { success.focus({ preventScroll: true }); });
    }

    function homeLink() {
      var home = document.createElement('a');
      home.className = 'btn-line';
      home.href = '/';
      home.innerHTML = 'Volver al inicio <span aria-hidden="true">→</span>';
      return home;
    }

    function renderCancellationSuccess() {
      var success = successShell('Reservación cancelada', 'Tu reservación ha sido cancelada.');
      var copy = document.createElement('p');
      copy.className = 'schedule-change-success__copy';
      copy.textContent = 'Esperamos recibirte en otra ocasión.';
      success.content.append(copy, homeLink());
      replaceCard(success.root);
    }

    function initCancellation() {
      var modal = page.querySelector('[data-management-cancel-modal]');
      var open = page.querySelector('[data-management-cancel-open]');
      if (!modal || !open || !csrf) return;
      var dialog = modal.querySelector('[role="dialog"]');
      var confirm = modal.querySelector('[data-management-cancel-confirm]');
      var status = modal.querySelector('[data-cancel-status]');
      var previousFocus = null;

      function closeModal() {
        if (confirm && confirm.disabled) return;
        modal.hidden = true;
        document.body.classList.remove('is-management-modal-open');
        if (previousFocus) previousFocus.focus();
      }
      function openModal() {
        previousFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('is-management-modal-open');
        if (status) status.textContent = '';
        window.requestAnimationFrame(function () { if (dialog) dialog.focus(); });
      }
      open.addEventListener('click', openModal);
      modal.querySelectorAll('[data-management-cancel-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
      });
      modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeModal();
          return;
        }
        if (event.key !== 'Tab') return;
        var focusable = Array.from(modal.querySelectorAll('button:not(:disabled), a[href]'));
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
      confirm.addEventListener('click', function () {
        confirm.disabled = true;
        if (status) {
          status.textContent = 'Cancelando reservación…';
          status.className = 'schedule-change-status is-pending';
        }
        request('/api/reservaciones/gestionar/cancelar', {}).then(function () {
          modal.hidden = true;
          document.body.classList.remove('is-management-modal-open');
          renderCancellationSuccess();
        }).catch(function (error) {
          if (status) {
            status.textContent = error.message || 'No fue posible cancelar. Intenta nuevamente.';
            status.className = 'schedule-change-status is-error';
          }
          confirm.disabled = false;
        });
      });
    }

    initCancellation();
    if (!form || !csrf) return;

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
    var guestPills = form.querySelector('[data-change-guest-pills]');
    var stepper = form.querySelector('[data-change-guest-stepper]');
    var minGuests = 1;
    var maxGuests = Number(form.getAttribute('data-max-guests') || '1');
    if (!Number.isFinite(maxGuests) || maxGuests < minGuests) maxGuests = minGuests;
    var pillMaxGuests = Math.min(6, maxGuests);
    var timePicker = null;
    var retryButton = null;
    if (!dateRoot || !timeRoot || !dateInput || !timeInput || !guests || !status || !submit) return;

    function setStatus(message, type) {
      status.textContent = message || '';
      status.className = 'schedule-change-status' + (type ? ' is-' + type : '');
    }
    function setTimeHint(message) { if (timeHint) timeHint.textContent = message || ''; }
    function ensureRetryButton() {
      if (retryButton) return retryButton;
      retryButton = document.createElement('button');
      retryButton.type = 'button';
      retryButton.className = 'schedule-change-retry';
      retryButton.textContent = 'Reintentar';
      retryButton.addEventListener('click', function () { if (dateInput.value) loadTimes(); });
      if (timeHint) timeHint.insertAdjacentElement('afterend', retryButton);
      return retryButton;
    }
    function hideRetryButton() { if (retryButton) retryButton.hidden = true; }
    function loadTimes() {
      if (!timePicker || !dateInput.value) {
        setTimeHint('Selecciona una fecha para ver horarios.');
        hideRetryButton();
        return;
      }
      hideRetryButton();
      setTimeHint('Consultando horarios…');
      timePicker.loadForDate(dateInput.value, '');
    }
    function updateGuestState(value, reload) {
      value = Math.max(minGuests, Math.min(maxGuests, Number(value) || minGuests));
      guests.value = String(value);
      if (guestValue) guestValue.textContent = String(value);
      form.querySelectorAll('[data-g]:not([data-change-guest-more])').forEach(function (button) {
        var selected = Number(button.getAttribute('data-g')) === value;
        button.classList.toggle('sel', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      if (moreGuests) {
        var expanded = value > pillMaxGuests;
        moreGuests.classList.toggle('sel', expanded);
        moreGuests.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
      if (guestPills) guestPills.hidden = value > pillMaxGuests;
      if (stepper) stepper.hidden = value <= pillMaxGuests;
      if (reload && dateInput.value) loadTimes();
    }

    window.createReservationDatePicker({ root: dateRoot, inline: false, allowPast: false });
    timePicker = window.createReservationTimePicker({
      root: timeRoot,
      inline: false,
      endpoint: '/api/reservaciones/gestionar/disponibilidad',
      autoLoad: false,
      requestTimeoutMs: 10000,
      getQueryParams: function () { return { personas: Number(guests.value || 0) }; }
    });
    dateInput.addEventListener('reservation:datechange', loadTimes);
    timeRoot.addEventListener('reservation:scheduleloaded', function (event) {
      var data = event.detail || {};
      if (data.ok === false) {
        setTimeHint(data.mensaje || 'No pudimos consultar los horarios.');
        ensureRetryButton().hidden = false;
        return;
      }
      var available = Array.isArray(data.horarios)
        ? data.horarios.filter(function (slot) { return slot && slot.disponible !== false; })
        : [];
      setTimeHint(data.abierto === false || available.length === 0
        ? 'No hay horarios disponibles para esa fecha.'
        : 'Elige una hora disponible.');
      hideRetryButton();
    });
    form.querySelectorAll('[data-g]:not([data-change-guest-more])').forEach(function (button) {
      button.addEventListener('click', function () { updateGuestState(button.getAttribute('data-g'), true); });
    });
    if (moreGuests) moreGuests.addEventListener('click', function () { updateGuestState(Math.max(7, Number(guests.value || 1)), true); });
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
      setStatus('Guardando cambio…', 'pending');
      request('/api/reservaciones/gestionar/modificar', {
        fecha: dateInput.value,
        hora: timeInput.value,
        personas: people,
        nota: note ? note.value : ''
      }).then(function () {
        var reminder = source === 'reminder_next_day';
        var success = successShell(
          reminder ? 'Reservación actualizada' : 'Nuevo horario confirmado',
          reminder ? 'Tu nuevo horario está confirmado' : 'Tu reservación está lista'
        );
        var summary = document.createElement('p');
        summary.className = 'schedule-change-success__summary';
        summary.textContent = formatDate(dateInput.value) + ' · ' + String(timeInput.value).substring(0, 5) + ' · ' + people + ' ' + (people === 1 ? 'persona' : 'personas');
        var name = document.createElement('p');
        name.className = 'schedule-change-success__name';
        name.textContent = 'A nombre de ' + ((card && card.getAttribute('data-change-name')) || 'tu reservación');
        success.content.append(summary, name, homeLink());
        replaceCard(success.root);
      }).catch(function (error) {
        setStatus(error.message || 'No fue posible completar la solicitud. Intenta nuevamente.', 'error');
        submit.disabled = false;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
