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
    var refreshSeconds = Math.max(1, Number(root.getAttribute('data-inbox-refresh-seconds') || '60'));
    var refreshMs = refreshSeconds * 1000;
    var activeFilter = 'all';
    var items = [];
    var loading = false;
    var syncInFlight = null;
    var lastSyncAt = 0;
    var lastFocus = null;

    function request(url, options) {
      options = options || {};
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeout = window.setTimeout(function () {
        if (controller) controller.abort();
      }, options.timeout || 9000);
      var headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
      var body = options.body;
      if (body && typeof body === 'object' && !(body instanceof URLSearchParams)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(Object.assign({}, body, { admin_csrf: csrf }));
      } else if (body instanceof URLSearchParams) {
        body.set('admin_csrf', csrf);
        headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
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
      var high = total > 0 && String(data && data.prioridad_maxima || '') === 'alta';
      root.classList.toggle('is-empty', total === 0);
      root.classList.toggle('has-items', total > 0);
      root.classList.toggle('has-high-priority', high);
      if (count) {
        count.textContent = String(total);
        count.hidden = total === 0;
      }
      if (summary) {
        summary.textContent = total === 0
          ? 'No hay casos pendientes.'
          : (total === 1 ? '1 caso requiere atención.' : total + ' casos requieren atención.');
      }
    }

    function refreshSummary() {
      return request('/admin/api/buzon/resumen').then(updateCount).catch(function () {
        // El buzón no debe romper la navegación si la tabla aún no fue migrada.
      });
    }

    function syncPendientes(force) {
      var now = Date.now();
      if (!force && now - lastSyncAt < refreshMs) return Promise.resolve(null);
      if (syncInFlight) return syncInFlight;
      lastSyncAt = now;
      syncInFlight = request('/admin/api/buzon/sincronizar', { method: 'POST', body: {} })
        .then(function (data) {
          updateCount(data);
          if (!drawer.hidden) return loadList();
          return data;
        })
        .catch(function () {
          return refreshSummary();
        })
        .finally(function () {
          syncInFlight = null;
        });
      return syncInFlight;
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
        var closeButton = drawer.querySelector('[data-inbox-close]');
        if (closeButton) closeButton.focus();
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

    function button(label, action, attrs, primary) {
      var element = document.createElement('button');
      element.type = 'button';
      element.className = 'admin-btn ' + (primary ? 'admin-btn--primary' : 'admin-btn--secondary') + ' admin-btn--small';
      element.textContent = label;
      element.setAttribute('data-inbox-action', action);
      Object.keys(attrs || {}).forEach(function (key) { element.setAttribute(key, String(attrs[key])); });
      return element;
    }

    function reservationLink(item, label) {
      var link = document.createElement('a');
      link.className = 'admin-btn admin-btn--secondary admin-btn--small';
      link.href = '/admin/reservaciones/detalle?id=' + encodeURIComponent(String(item.reservacion_id));
      link.textContent = label || 'Ver reservación';
      return link;
    }

    function assignmentLink(item) {
      var params = new URLSearchParams({
        reservation_id: String(item.reservacion_id),
        fecha: String(item.fecha || ''),
        hora: String(item.hora || ''),
        mode: 'assign'
      });
      var link = document.createElement('a');
      link.className = 'admin-btn admin-btn--primary admin-btn--small';
      link.href = '/admin/reservaciones/operacion?' + params.toString();
      link.textContent = 'Asignar mesas';
      return link;
    }

    function notificationAttrs(notification) {
      return {
        'data-notification-id': notification.id,
        'data-impact-id': notification.impacto_id || '',
        'data-impact-reservation-id': notification.impacto_reservacion_id || '',
        'data-motivo': notification.motivo || ''
      };
    }

    function visibleItems() {
      return activeFilter === 'all' ? items : items.filter(function (item) {
        return item.notificaciones && item.notificaciones.some(function (notification) {
          return notification.tipo.indexOf('reservacion_') === 0;
        });
      });
    }

    function render() {
      if (!list) return;
      list.replaceChildren();
      var visible = visibleItems();
      if (!visible.length) {
        var empty = document.createElement('div');
        empty.className = 'admin-inbox__empty';
        var title = document.createElement('strong');
        title.textContent = 'No hay acciones pendientes';
        var copy = document.createElement('span');
        copy.textContent = 'Cuando un caso necesite intervención aparecerá aquí.';
        empty.append(title, copy);
        list.appendChild(empty);
        return;
      }

      visible.forEach(function (item) {
        var card = document.createElement('article');
        card.className = 'admin-inbox__card' + (item.leida ? '' : ' is-unread');
        card.setAttribute('data-reservation-id', String(item.reservacion_id));
        var head = document.createElement('div');
        head.className = 'admin-inbox__card-head';
        var priority = document.createElement('span');
        priority.className = 'admin-inbox__priority' + (item.prioridad === 'alta' ? ' is-high' : '');
        priority.textContent = item.prioridad === 'alta' ? 'Alta' : 'Normal';
        var name = document.createElement('h3');
        name.textContent = item.nombre || 'Reservación';
        head.append(priority, name);

        var facts = document.createElement('div');
        facts.className = 'admin-inbox__facts';
        [
          [String(item.comensales || 0) + (Number(item.comensales) === 1 ? ' persona' : ' personas'), 'Comensales'],
          [formatDate(item.fecha), 'Fecha'],
          [String(item.hora || '').substring(0, 5), 'Hora']
        ].forEach(function (fact) {
          var wrapper = document.createElement('span');
          var value = document.createElement('strong');
          value.textContent = fact[0];
          var label = document.createElement('small');
          label.textContent = fact[1];
          wrapper.append(value, label);
          facts.appendChild(wrapper);
        });

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
        actions.appendChild(button('Revisar', 'review', { 'data-reservation-id': item.reservacion_id }, true));
        card.append(head, facts, reasons, actions);
        list.appendChild(card);
      });
    }

    function markItemRead(item) {
      var unread = (item.notificaciones || []).filter(function (notification) {
        return !notification.leida_at;
      });
      if (!unread.length) return Promise.resolve();
      return Promise.all(unread.map(function (notification) {
        return request('/admin/api/buzon/leida', {
          method: 'POST',
          body: { id: Number(notification.id) }
        }).then(function () {
          notification.leida_at = new Date().toISOString();
        });
      })).then(function () {
        item.leida = true;
        render();
      });
    }

    function openDetail(item) {
      if (!context) return;
      context.hidden = false;
      context.replaceChildren();
      var heading = document.createElement('div');
      heading.className = 'admin-inbox__context-head';
      var title = document.createElement('h3');
      title.textContent = 'Revisar reservación';
      var closeDetail = button('Cerrar detalle', 'close-detail', {}, false);
      closeDetail.classList.add('admin-inbox__context-close');
      heading.append(title, closeDetail);
      var summaryText = document.createElement('p');
      summaryText.textContent = (item.nombre || 'Reservación') + ' · ' + formatDate(item.fecha) + ' · ' + String(item.hora || '').substring(0, 5) + ' · ' + item.comensales + ' comensales';
      var motives = document.createElement('div');
      motives.className = 'admin-inbox__context-motives';
      (item.notificaciones || []).forEach(function (notification) {
        var motive = document.createElement('section');
        motive.className = 'admin-inbox__context-motive';
        var motiveTitle = document.createElement('strong');
        motiveTitle.textContent = notification.etiqueta || notification.tipo;
        var motiveCopy = document.createElement('p');
        motiveCopy.textContent = notification.descripcion || 'Este caso requiere atención administrativa.';
        motive.append(motiveTitle, motiveCopy);
        motives.appendChild(motive);
      });
      var actions = document.createElement('div');
      actions.className = 'admin-inbox__actions admin-inbox__context-actions';
      var notifications = item.notificaciones || [];
      notifications.forEach(function (notification) {
        var attrs = notificationAttrs(notification);
        if (notification.tipo === 'reservacion_ausencia_pendiente' && notification.puede_registrar_no_show) {
          actions.appendChild(button('Registrar no-show', 'no-show', attrs, true));
          actions.appendChild(reservationLink(item, 'Ver reservación'));
        } else if (notification.tipo === 'reservacion_sin_asignacion_proxima' && notification.puede_asignar_mesas) {
          actions.appendChild(assignmentLink(item));
          actions.appendChild(reservationLink(item, 'Ver reservación'));
        } else if (notification.tipo === 'reservacion_horario_afectado') {
          if (notification.estado === 'sin_contacto') {
            actions.appendChild(button('Agregar contacto', 'contact', attrs, true));
          }
          actions.appendChild(button('Mantener reservación', 'keep', Object.assign({}, attrs, { 'data-motivo': 'mantener_reservacion' }), false));
          actions.appendChild(button(
            Number(item.comensales) > 12 ? 'Coordinar con cliente' : 'Coordinar por otro medio',
            'coordinate',
            Object.assign({}, attrs, { 'data-motivo': 'coordinacion_externa' }),
            false
          ));
          if (notification.test_link_disponible) {
            actions.appendChild(button('Copiar link de prueba', 'test-link', attrs, false));
          }
          actions.appendChild(reservationLink(item, 'Ver reservación'));
        } else if (notification.tipo === 'reservacion_grupo_grande') {
          actions.appendChild(reservationLink(item, 'Gestionar reservación'));
        }
      });
      context.append(heading, summaryText, motives, actions);
      markItemRead(item).catch(function () {
        var status = document.createElement('p');
        status.className = 'admin-inbox__context-status';
        status.textContent = 'El detalle está abierto; no pudimos actualizar la lectura.';
        context.appendChild(status);
      });
    }

    function loadList() {
      if (loading) return Promise.resolve();
      loading = true;
      if (list) {
        list.replaceChildren();
        var loadingText = document.createElement('p');
        loadingText.className = 'admin-inbox__loading';
        loadingText.textContent = 'Cargando acciones…';
        list.appendChild(loadingText);
      }
      return request('/admin/api/buzon').then(function (data) {
        items = Array.isArray(data.items) ? data.items : [];
        render();
        updateCount({ cantidad: data.cantidad, prioridad_maxima: items.some(function (item) { return item.prioridad === 'alta'; }) ? 'alta' : null });
      }).catch(function (error) {
        if (!list) return;
        list.replaceChildren();
        var errorBox = document.createElement('div');
        errorBox.className = 'admin-inbox__empty is-error';
        var title = document.createElement('strong');
        title.textContent = 'No pudimos cargar el buzón';
        var copy = document.createElement('span');
        copy.textContent = error.message || 'Intenta nuevamente.';
        errorBox.append(title, copy);
        list.appendChild(errorBox);
      }).finally(function () { loading = false; });
    }

    function refreshAfterAction(message) {
      return loadList().then(function () {
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
      try { copied = document.execCommand('copy'); } finally { field.remove(); }
      return copied ? Promise.resolve() : Promise.reject(new Error('No fue posible copiar el link de prueba.'));
    }

    function findItem(notificationId) {
      for (var i = 0; i < items.length; i++) {
        if ((items[i].notificaciones || []).some(function (notification) { return Number(notification.id) === notificationId; })) {
          return items[i];
        }
      }
      return null;
    }

    function showContact(item, notification) {
      if (!context) return;
      context.replaceChildren();
      context.hidden = false;
      var title = document.createElement('h3');
      title.textContent = 'Agregar contacto';
      var copy = document.createElement('p');
      copy.textContent = 'Se guardará en la reservación y el acceso se preparará automáticamente.';
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
      form.querySelector('[data-contact-cancel]').addEventListener('click', function () { openDetail(item); });
      context.append(title, copy, form);
      form.elements.contacto.focus();
    }

    function confirmManual(item, notification, motivo) {
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
        }).then(function () { clearContext(); return refreshAfterAction('Caso resuelto.'); });
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

    function confirmNoShow(item, notification) {
      var run = function () {
        var form = new URLSearchParams();
        form.set('reservation_id', String(item.reservacion_id));
        form.set('estado', 'no_show');
        form.set('motivo', 'buzon_ausencia_pendiente');
        return request('/admin/api/reservaciones/operacion/estado', { method: 'POST', body: form })
          .then(function () { clearContext(); return refreshAfterAction('No-show registrado.'); });
      };
      if (window.ConfirmationModal) {
        window.ConfirmationModal.get().open({
          variant: 'warning',
          eyebrow: 'Buzón administrativo',
          title: 'Registrar ausencia',
          description: 'La tolerancia de llegada ya venció para esta reservación.',
          consequence: 'La reservación pasará a no-show y dejará de requerir seguimiento.',
          secondaryLabel: 'Cancelar',
          primaryLabel: 'Registrar no-show',
          onPrimary: run
        });
      }
    }

    function handleAction(control) {
      var action = control.getAttribute('data-inbox-action');
      var notificationId = Number(control.getAttribute('data-notification-id') || 0);
      var notification = null;
      var item = notificationId ? findItem(notificationId) : null;
      if (item) {
        notification = (item.notificaciones || []).find(function (candidate) { return Number(candidate.id) === notificationId; });
      }
      if (action === 'close-detail') clearContext();
      if (!item || !notification) return;
      if (action === 'contact') showContact(item, notification);
      if (action === 'keep' || action === 'coordinate') confirmManual(item, notification, control.getAttribute('data-motivo') || 'mantener_reservacion');
      if (action === 'no-show') confirmNoShow(item, notification);
      if (action === 'test-link') {
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
    }

    list.addEventListener('click', function (event) {
      var review = event.target.closest('[data-inbox-action="review"]');
      if (review) {
        var id = Number(review.getAttribute('data-reservation-id') || 0);
        var item = items.find(function (candidate) { return Number(candidate.reservacion_id) === id; });
        if (item) openDetail(item);
        return;
      }
      var control = event.target.closest('[data-inbox-action]');
      if (control) handleAction(control);
    });
    context.addEventListener('click', function (event) {
      var control = event.target.closest('[data-inbox-action]');
      if (control) handleAction(control);
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
    closeButtons.forEach(function (buttonElement) { buttonElement.addEventListener('click', close); });
    document.addEventListener('keydown', function (event) {
      if (!drawer.hidden && event.key === 'Escape') close();
    });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') syncPendientes(false);
    });
    window.setInterval(function () { syncPendientes(false); }, refreshMs);
    syncPendientes(true);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
