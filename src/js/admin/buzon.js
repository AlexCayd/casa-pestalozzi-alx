/** Buzón administrativo flotante: el contador muestra avisos abiertos visibles. */
(function () {
  function init() {
    var root = document.querySelector('[data-admin-inbox]');
    if (!root) return;

    var trigger = root.querySelector('[data-inbox-open]');
    var drawer = root.querySelector('[data-inbox-drawer]');
    var backdrop = root.querySelector('.admin-inbox__backdrop');
    var closeButtons = root.querySelectorAll('[data-inbox-close]');
    var count = root.querySelector('[data-inbox-count]');
    var summary = root.querySelector('[data-inbox-summary]');
    var list = root.querySelector('[data-inbox-list]');
    var context = root.querySelector('[data-inbox-context]');
    var csrf = root.getAttribute('data-admin-csrf') || '';
    var activeFilter = 'all';
    var items = [];
    var loading = false;
    var lastFocus = null;

    function request(url, options) {
      options = options || {};
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeout = window.setTimeout(function () {
        if (controller) controller.abort();
      }, options.timeout || 9000);
      var headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
      var body = options.body;
      if (body && typeof body === 'object') {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(Object.assign({}, body, { admin_csrf: csrf }));
      }
      return fetch(url, {
        method: options.method || 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: headers,
        body: body,
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || data.ok === false) {
            throw new Error(data.mensaje || 'No fue posible consultar el buzón.');
          }
          return data;
        });
      }).finally(function () {
        window.clearTimeout(timeout);
      });
    }

    function updateCount(data) {
      var total = Number(data && data.cantidad) || 0;
      if (count) {
        count.textContent = String(total);
        count.hidden = total === 0;
      }
      if (summary) {
        summary.textContent = total === 1
          ? '1 caso requiere atención.'
          : total + ' casos requieren atención.';
      }
    }

    function refreshSummary() {
      return request('/admin/api/buzon/resumen').then(updateCount).catch(function () {
        // El buzón no debe romper la navegación si la tabla aún no fue migrada.
      });
    }

    function clearContext() {
      if (!context) return;
      context.hidden = true;
      context.replaceChildren();
    }

    function close() {
      if (drawer.hidden) return;
      drawer.classList.remove('is-open');
      if (backdrop) backdrop.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('admin-inbox-open');
      window.setTimeout(function () {
        drawer.hidden = true;
        if (backdrop) backdrop.hidden = true;
      }, 220);
      clearContext();
      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    function open() {
      if (!drawer.hidden) return;
      lastFocus = document.activeElement;
      drawer.hidden = false;
      if (backdrop) backdrop.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      document.body.classList.add('admin-inbox-open');
      window.requestAnimationFrame(function () {
        drawer.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
        var close = drawer.querySelector('[data-inbox-close]');
        if (close) close.focus();
      });
      loadList();
    }

    function formatDate(value) {
      var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!match) return String(value || '');
      var date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
      return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', timeZone: 'UTC' })
        .format(date).replace('.', '');
    }

    function button(label, action, attrs) {
      var element = document.createElement('button');
      element.type = 'button';
      element.className = 'admin-btn admin-btn--secondary admin-btn--small';
      element.textContent = label;
      element.setAttribute('data-inbox-action', action);
      Object.keys(attrs || {}).forEach(function (key) { element.setAttribute(key, String(attrs[key])); });
      return element;
    }

    function render() {
      if (!list) return;
      list.replaceChildren();
      var visible = activeFilter === 'all' ? items : items.filter(function (item) {
        return item.notificaciones && item.notificaciones.some(function (notification) {
          return notification.tipo.indexOf('reservacion_') === 0;
        });
      });
      if (!visible.length) {
        var empty = document.createElement('div');
        empty.className = 'admin-inbox__empty';
        empty.innerHTML = '<strong>No hay acciones pendientes</strong><span>Cuando un caso necesite intervención aparecerá aquí.</span>';
        list.appendChild(empty);
        return;
      }

      visible.forEach(function (item) {
        var card = document.createElement('article');
        card.className = 'admin-inbox__card';
        card.setAttribute('data-reservation-id', String(item.reservacion_id));
        var head = document.createElement('div');
        head.className = 'admin-inbox__card-head';
        var priority = document.createElement('span');
        priority.className = 'admin-inbox__priority' + (item.prioridad === 'alta' ? ' is-high' : '');
        priority.textContent = item.prioridad === 'alta' ? 'Alta' : 'Normal';
        var name = document.createElement('h3');
        name.textContent = item.nombre || 'Reservación';
        head.append(priority, name);
        var meta = document.createElement('p');
        meta.className = 'admin-inbox__meta';
        meta.textContent = String(item.comensales || 0) + ' ' + (Number(item.comensales) === 1 ? 'persona' : 'personas')
          + ' · ' + formatDate(item.fecha) + ' · ' + item.hora;
        var reasons = document.createElement('div');
        reasons.className = 'admin-inbox__reasons';
        (item.motivos || []).forEach(function (reason) {
          var tag = document.createElement('span');
          tag.className = 'admin-inbox__reason';
          tag.textContent = reason.etiqueta || reason.tipo;
          reasons.appendChild(tag);
        });
        var actions = document.createElement('div');
        actions.className = 'admin-inbox__actions';
        var manage = document.createElement('a');
        manage.className = 'admin-btn admin-btn--primary admin-btn--small';
        manage.href = '/admin/reservaciones/detalle?id=' + encodeURIComponent(String(item.reservacion_id));
        manage.textContent = 'Gestionar reservación';
        actions.appendChild(manage);

        (item.notificaciones || []).forEach(function (notification) {
          var attrs = {
            'data-notification-id': notification.id,
            'data-impact-id': notification.impacto_id || '',
            'data-impact-reservation-id': notification.impacto_reservacion_id || ''
          };
          if (notification.tipo === 'reservacion_horario_afectado') {
            if (notification.estado === 'sin_contacto') {
              actions.appendChild(button('Agregar contacto', 'contact', attrs));
            }
            actions.appendChild(button('Mantener reservación', 'keep', Object.assign({}, attrs, { 'data-motivo': 'mantener_reservacion' })));
            actions.appendChild(button(
              Number(item.comensales) > 12 ? 'Coordinar con cliente' : 'Coordinar por otro medio',
              'coordinate',
              Object.assign({}, attrs, { 'data-motivo': 'coordinacion_externa' })
            ));
            if (notification.test_link_disponible) {
              actions.appendChild(button('Copiar link de prueba', 'test-link', attrs));
            }
          }
          if (!notification.leida_at) {
            actions.appendChild(button('Marcar leída', 'read', attrs));
          }
        });
        card.append(head, meta, reasons, actions);
        list.appendChild(card);
      });
    }

    function loadList() {
      if (loading) return;
      loading = true;
      if (list) list.innerHTML = '<p class="admin-inbox__loading">Cargando acciones…</p>';
      request('/admin/api/buzon').then(function (data) {
        items = Array.isArray(data.items) ? data.items : [];
        render();
        return refreshSummary();
      }).catch(function (error) {
        if (list) list.innerHTML = '<div class="admin-inbox__empty is-error"><strong>No pudimos cargar el buzón</strong><span>' + (error.message || 'Intenta nuevamente.') + '</span></div>';
      }).finally(function () { loading = false; });
    }

    function refreshAfterAction(message) {
      return request('/admin/api/buzon').then(function (data) {
        items = Array.isArray(data.items) ? data.items : [];
        render();
        if (summary && message) summary.textContent = message;
        return refreshSummary();
      });
    }

    function copyText(value) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(value);
      }
      var field = document.createElement('textarea');
      field.value = value;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      var copied = false;
      try {
        copied = document.execCommand('copy');
      } finally {
        field.remove();
      }
      return copied ? Promise.resolve() : Promise.reject(new Error('No fue posible copiar el link de prueba.'));
    }

    function showContact(notification) {
      if (!context) return;
      context.hidden = false;
      context.innerHTML = '';
      var title = document.createElement('h3');
      title.textContent = 'Agregar contacto';
      var copy = document.createElement('p');
      copy.textContent = 'Se guardará en la reservación seleccionada y el acceso se preparará automáticamente.';
      var form = document.createElement('form');
      form.className = 'admin-inbox__contact-form';
      form.innerHTML = '<label><span>Tipo de contacto</span><select name="tipo"><option value="email">Correo electrónico</option><option value="telefono">Teléfono</option></select></label><label><span>Correo o teléfono</span><input name="contacto" required autocomplete="off"></label><p class="admin-inbox__context-status" data-contact-status></p><div><button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-contact-cancel>Cancelar</button><button type="submit" class="admin-btn admin-btn--primary admin-btn--small">Guardar contacto</button></div>';
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = form.querySelector('[type="submit"]');
        var status = form.querySelector('[data-contact-status]');
        submit.disabled = true;
        status.textContent = 'Guardando…';
        request('/admin/api/horarios-impactos/contacto', {
          method: 'POST',
          body: {
            impacto_id: Number(notification.impacto_id || 0),
            impacto_reservacion_id: Number(notification.impacto_reservacion_id || 0),
            tipo: form.elements.tipo.value,
            contacto: form.elements.contacto.value.trim()
          }
        }).then(function () {
          clearContext();
          return refreshAfterAction('Contacto agregado; el acceso quedó preparado.');
        }).catch(function (error) {
          status.textContent = error.message || 'No fue posible guardar el contacto.';
          submit.disabled = false;
        });
      });
      form.querySelector('[data-contact-cancel]').addEventListener('click', clearContext);
      context.append(title, copy, form);
      form.elements.contacto.focus();
    }

    function confirmManual(notification, motivo) {
      var title = motivo === 'coordinacion_externa' ? 'Confirmar coordinación externa' : 'Mantener reservación fuera de horario';
      var consequence = motivo === 'coordinacion_externa'
        ? 'El sistema registrará la coordinación sin afirmar que se envió un aviso digital.'
        : 'La reservación seguirá confirmada y el restaurante aceptará atenderla aunque quede fuera del horario nuevo.';
      var run = function () {
        return request('/admin/api/horarios-impactos/atender-manual', {
          method: 'POST',
          body: {
            impacto_id: Number(notification.impacto_id || 0),
            impacto_reservacion_id: Number(notification.impacto_reservacion_id || 0),
            cierre_motivo: motivo
          }
        }).then(function () { return refreshAfterAction('Caso resuelto.'); });
      };
      if (window.ConfirmationModal) {
        window.ConfirmationModal.get().open({
          variant: 'warning',
          eyebrow: 'Buzón administrativo',
          title: title,
          description: 'Confirma esta acción para la reservación actual.',
          consequence: consequence,
          secondaryLabel: 'Cancelar',
          primaryLabel: 'Confirmar',
          onPrimary: run
        });
      }
    }

    list.addEventListener('click', function (event) {
      var control = event.target.closest('[data-inbox-action]');
      if (!control) return;
      var notification = {
        id: Number(control.getAttribute('data-notification-id') || 0),
        impacto_id: Number(control.getAttribute('data-impact-id') || 0),
        impacto_reservacion_id: Number(control.getAttribute('data-impact-reservation-id') || 0),
        motivo: control.getAttribute('data-motivo') || 'mantener_reservacion'
      };
      if (control.getAttribute('data-inbox-action') === 'contact') showContact(notification);
      if (control.getAttribute('data-inbox-action') === 'keep' || control.getAttribute('data-inbox-action') === 'coordinate') {
        confirmManual(notification, notification.motivo);
      }
      if (control.getAttribute('data-inbox-action') === 'read') {
        control.disabled = true;
        request('/admin/api/buzon/leida', { method: 'POST', body: { id: notification.id } })
          .then(function () { return refreshAfterAction(); }).catch(function () { control.disabled = false; });
      }
      if (control.getAttribute('data-inbox-action') === 'test-link') {
        control.disabled = true;
        request('/admin/api/horarios-impactos/acceso-prueba', {
          method: 'POST',
          body: { impacto_id: notification.impacto_id, impacto_reservacion_id: notification.impacto_reservacion_id }
        }).then(function (data) {
          if (!data.test_access_url) throw new Error('No hay un link de prueba disponible.');
          return copyText(data.test_access_url);
        }).then(function () {
          if (summary) summary.textContent = 'Link de prueba copiado.';
        }).catch(function (error) {
          if (summary) summary.textContent = error.message || 'No fue posible copiar el link de prueba.';
        }).finally(function () { control.disabled = false; });
      }
    });

    root.querySelectorAll('[data-inbox-filter]').forEach(function (filter) {
      filter.addEventListener('click', function () {
        activeFilter = filter.getAttribute('data-inbox-filter') || 'all';
        root.querySelectorAll('[data-inbox-filter]').forEach(function (other) {
          var selected = other === filter;
          other.classList.toggle('is-active', selected);
          other.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        render();
      });
    });
    trigger.addEventListener('click', open);
    closeButtons.forEach(function (button) { button.addEventListener('click', close); });
    document.addEventListener('keydown', function (event) {
      if (!drawer.hidden && event.key === 'Escape') close();
    });
    refreshSummary();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
