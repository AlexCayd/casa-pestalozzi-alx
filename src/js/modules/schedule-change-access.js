(function () {
  function init() {
    var page = document.querySelector('[data-schedule-change-page]');
    var form = document.querySelector('[data-schedule-change-form]');
    if (!page || !form) return;

    var csrf = form.querySelector('[data-change-csrf]');
    var date = form.querySelector('[data-change-date]');
    var time = form.querySelector('[data-change-time]');
    var guests = form.querySelector('[data-change-guests]');
    var note = form.querySelector('[data-change-note]');
    var status = form.querySelector('[data-change-status]');
    var submit = form.querySelector('[data-change-submit]');

    function setStatus(message, type) {
      status.textContent = message || '';
      status.className = 'schedule-change-status' + (type ? ' is-' + type : '');
    }

    function request(url, body) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({}, body, { csrf_token: csrf.value }))
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || data.ok === false) {
            throw new Error(data.mensaje || 'No fue posible continuar.');
          }
          return data;
        });
      });
    }

    function loadTimes() {
      time.disabled = true;
      time.replaceChildren(new Option('Cargando horarios…', ''));
      if (!date.value) {
        time.replaceChildren(new Option('Selecciona una fecha', ''));
        return;
      }
      request('/api/reservaciones/cambio-horario/disponibilidad', {
        fecha: date.value,
        personas: Number(guests.value || 0)
      }).then(function (data) {
        time.replaceChildren(new Option('Selecciona un horario', ''));
        var slots = Array.isArray(data.horarios) ? data.horarios : [];
        slots.filter(function (slot) { return slot.disponible; }).forEach(function (slot) {
          time.appendChild(new Option(slot.hora, slot.hora));
        });
        time.disabled = slots.filter(function (slot) { return slot.disponible; }).length === 0;
        if (time.disabled) setStatus('No hay horarios disponibles para esa fecha y número de personas.', 'error');
        else setStatus('', '');
      }).catch(function (error) {
        time.replaceChildren(new Option('No disponible', ''));
        setStatus(error.message, 'error');
      });
    }

    function changeGuests(delta) {
      var min = Number(guests.min || 1);
      var max = Number(guests.max || 20);
      guests.value = String(Math.max(min, Math.min(max, Number(guests.value || min) + delta)));
      if (date.value) loadTimes();
    }

    form.querySelector('[data-change-minus]').addEventListener('click', function () { changeGuests(-1); });
    form.querySelector('[data-change-plus]').addEventListener('click', function () { changeGuests(1); });
    date.addEventListener('change', loadTimes);
    guests.addEventListener('change', function () { if (date.value) loadTimes(); });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!date.value || !time.value) {
        setStatus('Selecciona una nueva fecha y un horario disponible.', 'error');
        return;
      }
      submit.disabled = true;
      setStatus('Guardando modificación…', 'pending');
      request('/api/reservaciones/cambio-horario/modificar', {
        fecha: date.value,
        hora: time.value,
        personas: Number(guests.value || 0),
        nota: note.value
      }).then(function () {
        setStatus('Tu reservación fue modificada correctamente.', 'success');
        form.replaceWith(Object.assign(document.createElement('p'), {
          className: 'schedule-change-success',
          textContent: 'Listo. Tu nueva fecha y horario quedaron confirmados.'
        }));
      }).catch(function (error) {
        setStatus(error.message, 'error');
        submit.disabled = false;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
