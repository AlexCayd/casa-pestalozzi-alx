/** Buzón administrativo: una tarjeta por reservación y una vista a la vez. */
(function () {
  'use strict';

  function initScheduleImpactBanner(root) {
    var banner = document.querySelector('[data-schedule-impact-banner]');
    if (!banner) return;

    var csrf = root.getAttribute('data-admin-csrf') || '';
    function request(url, body) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({}, body, { admin_csrf: csrf }))
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || data.ok === false) {
            throw new Error(data.mensaje || data.message || 'No fue posible completar la acción.');
          }
          return data;
        });
      });
    }

    var edit = banner.querySelector('[data-schedule-impact-edit]');
    if (edit) {
      edit.addEventListener('click', function () {
        var form = document.querySelector('[data-admin-reservation-form]');
        var editButton = form && form.closest('[data-reservation-form-card]')
          ? form.closest('[data-reservation-form-card]').querySelector('[data-form-edit]')
          : null;
        if (editButton && !editButton.hidden) editButton.click();
        if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    var resolve = banner.querySelector('[data-schedule-impact-resolve]');
    if (!resolve || !window.ConfirmationModal) return;
    resolve.addEventListener('click', function () {
      var impactId = Number(resolve.getAttribute('data-impact-id') || 0);
      var impactReservationId = Number(resolve.getAttribute('data-impact-reservation-id') || 0);
      window.ConfirmationModal.get().open({
        variant: 'warning',
        eyebrow: 'Buzón administrativo',
        title: 'Cerrar seguimiento',
        description: 'Confirma que deseas retirar este pendiente del buzón.',
        consequence: 'La reservación seguirá confirmada. Sólo se retirará este pendiente del buzón.',
        secondaryLabel: 'Cancelar',
        primaryLabel: 'Cerrar seguimiento',
        onPrimary: function () {
          return request('/admin/api/horarios-impactos/atender-manual', {
            impacto_id: impactId,
            impacto_reservacion_id: impactReservationId
          }).then(function () {
            window.location.reload();
          });
        }
      });
    });
  }

  function init() {
    var root = document.querySelector('[data-admin-inbox]');
    if (!root) return;
    initScheduleImpactBanner(root);
    var trigger = document.querySelector('[data-inbox-open]');
    if (!trigger) return;

    var drawer = root.querySelector('[data-inbox-drawer]');
    var shade = root.querySelector('.admin-inbox__backdrop');
    var closeButtons = root.querySelectorAll('[data-inbox-close]');
    var backButton = root.querySelector('[data-inbox-back]');
    var title = root.querySelector('[data-inbox-title]');
    var filters = root.querySelector('[data-inbox-filters]');
    var count = document.querySelector('[data-inbox-count]');
    var summary = root.querySelector('[data-inbox-summary]');
    var list = root.querySelector('[data-inbox-list]');
    var context = root.querySelector('[data-inbox-context]');
    var csrf = root.getAttribute('data-admin-csrf') || '';
    var refreshMs = Math.max(1, Number(root.getAttribute('data-inbox-refresh-seconds') || '60')) * 1000;
    var activeFilter = 'action';
    var items = [];
    var loading = false;
    var syncInFlight = null;
    var lastSyncAt = 0;
    var lastFocus = null;
    var scrollLocked = false;
    var view = 'list';

    function request(url, options) {
      options = options || {};
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeout = window.setTimeout(function () { if (controller) controller.abort(); }, options.timeout || 9000);
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
        method: options.method || 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: headers, body: body, signal: controller ? controller.signal : undefined
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || data.ok === false) {
            var error = new Error(data.mensaje || data.message || 'No fue posible actualizar las notificaciones.');
            error.data = data;
            throw error;
          }
          return data;
        });
      }).finally(function () { window.clearTimeout(timeout); });
    }

    function summaryText(actionable, followup) {
      return actionable + ' por atender · ' + followup + ' en espera';
    }

    function updateCount(data) {
      var actionable = Number(data && data.cantidad_accionable);
      var followup = Number(data && data.cantidad_seguimiento);
      if (!Number.isFinite(actionable)) actionable = 0;
      if (!Number.isFinite(followup)) followup = 0;
      var total = actionable + followup;
      var high = actionable > 0 && String(data && data.prioridad_maxima_accionable || '') === 'alta';
      root.classList.toggle('is-empty', total === 0);
      root.classList.toggle('has-items', total > 0);
      root.classList.toggle('has-followup', followup > 0 && actionable === 0);
      root.classList.toggle('has-high-priority', high);
      trigger.classList.toggle('is-empty', total === 0);
      trigger.classList.toggle('has-items', total > 0);
      trigger.classList.toggle('has-followup', followup > 0 && actionable === 0);
      trigger.classList.toggle('has-high-priority', high);
      if (count) { count.textContent = String(actionable); count.hidden = actionable === 0; }
      if (summary && view === 'list') summary.textContent = summaryText(actionable, followup);
    }

    function refreshSummary() { return request('/admin/api/buzon/resumen').then(updateCount); }

    function syncPendientes(force) {
      var now = Date.now();
      if (!force && now - lastSyncAt < refreshMs) return Promise.resolve(null);
      if (syncInFlight) return syncInFlight;
      lastSyncAt = now;
      syncInFlight = request('/admin/api/buzon/sincronizar', { method: 'POST', body: {} })
        .then(function (data) { updateCount(data); return drawer.hidden ? data : loadList(); })
        .catch(function () { return refreshSummary().catch(function () { return null; }); })
        .finally(function () { syncInFlight = null; });
      return syncInFlight;
    }

    function lockScroll() {
      if (scrollLocked) return;
      if (window.AdminScrollLock) window.AdminScrollLock.bloquear();
      scrollLocked = true;
    }

    function unlockScroll() {
      if (!scrollLocked) return;
      if (window.AdminScrollLock) window.AdminScrollLock.desbloquear();
      scrollLocked = false;
    }

    function clearContext() { if (context) { context.hidden = true; context.replaceChildren(); } }

    function setView(next, item) {
      view = next;
      var detail = next === 'detail';
      if (list) list.hidden = detail;
      if (filters) filters.hidden = detail;
      if (backButton) backButton.hidden = !detail;
      if (title) title.textContent = detail ? 'Detalle de reservación' : 'Notificaciones';
      if (summary && !detail) refreshSummary().catch(function () {});
      if (!detail) clearContext(); else if (item) renderDetail(item);
    }

    function close() {
      if (drawer.hidden) return;
      drawer.classList.remove('is-open');
      if (shade) shade.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      unlockScroll();
      window.setTimeout(function () { drawer.hidden = true; if (shade) shade.hidden = true; }, 220);
      setView('list');
      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }

    function open() {
      if (!drawer.hidden) return;
      lastFocus = document.activeElement;
      drawer.hidden = false;
      if (shade) shade.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      lockScroll();
      setView('list');
      window.requestAnimationFrame(function () {
        drawer.classList.add('is-open');
        if (shade) shade.classList.add('is-open');
        var closeButton = drawer.querySelector('[data-inbox-close]');
        if (closeButton) closeButton.focus();
      });
      loadList();
    }

    function formatDate(value) {
      var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!match) return String(value || '');
      var date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
      return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(date).replace('.', '').toUpperCase();
    }

    function formatTime(value) {
      var text = String(value || '');
      var match = text.match(/\b\d{2}:\d{2}/);
      return match ? match[0] : text.substring(0, 5);
    }

    function button(label, action, attrs, primary, small) {
      var element = document.createElement('button');
      element.type = 'button';
      element.className = 'admin-btn ' + (primary ? 'admin-btn--primary' : 'admin-btn--secondary') + (small ? ' admin-btn--small' : '');
      element.textContent = label;
      element.setAttribute('data-inbox-action', action);
      Object.keys(attrs || {}).forEach(function (key) { element.setAttribute(key, String(attrs[key])); });
      return element;
    }

    function reservationLink(item, label, notification) {
      var params = new URLSearchParams();
      params.set('id', String(item.reservacion_id));
      params.set('return_url', '/admin/reservaciones');
      if (notification && notification.impacto_reservacion_id) params.set('impacto_reservacion_id', String(notification.impacto_reservacion_id));
      var link = document.createElement('a');
      link.className = 'admin-btn admin-btn--secondary';
      link.href = '/admin/reservaciones/detalle?' + params.toString();
      link.textContent = label || 'Abrir reservación';
      return link;
    }

    function assignmentLink(item) {
      var params = new URLSearchParams({ reservation_id: String(item.reservacion_id), fecha: String(item.fecha || ''), hora: String(item.hora || ''), mode: 'assign', return_url: '/admin/reservaciones' });
      var link = document.createElement('a');
      link.className = 'admin-btn admin-btn--primary';
      link.href = '/admin/reservaciones/operacion?' + params.toString();
      link.textContent = 'Asignar mesas';
      return link;
    }

    function notificationAttrs(notification) {
      return { 'data-notification-id': notification.id, 'data-impact-id': notification.impacto_id || '', 'data-impact-reservation-id': notification.impacto_reservacion_id || '' };
    }

    function itemRequiresAction(item) {
      return (item.notificaciones || []).some(function (notification) { return notification.requiere_accion !== false; });
    }

    function visibleItems() {
      return items.filter(function (item) {
        if (activeFilter === 'all') return true;
        return activeFilter === 'action' ? itemRequiresAction(item) : !itemRequiresAction(item);
      });
    }

    function render() {
      if (!list) return;
      list.replaceChildren();
      var visible = visibleItems();
      if (!visible.length) {
        var empty = document.createElement('div');
        empty.className = 'admin-inbox__empty';
        var emptyTitle = document.createElement('strong');
        emptyTitle.textContent = activeFilter === 'followup' ? 'No hay reservaciones en espera.' : 'Todo al día';
        var emptyCopy = document.createElement('span');
        emptyCopy.textContent = activeFilter === 'followup' ? 'Las notificaciones informativas aparecerán aquí mientras el cliente responde.' : 'No hay reservaciones por atender.';
        empty.append(emptyTitle, emptyCopy);
        list.appendChild(empty);
        return;
      }
      visible.forEach(function (item) {
        var card = document.createElement('article');
        card.className = 'admin-inbox__card' + (item.leida ? '' : ' is-unread') + (itemRequiresAction(item) ? ' is-action' : ' is-followup');
        card.setAttribute('data-reservation-id', String(item.reservacion_id));
        var head = document.createElement('div');
        head.className = 'admin-inbox__card-head';
        var name = document.createElement('h3');
        name.textContent = item.nombre || 'Reservación';
        head.append(name);
        var facts = document.createElement('p');
        facts.className = 'admin-inbox__card-facts';
        facts.textContent = formatDate(item.fecha) + ' · ' + formatTime(item.hora) + ' · ' + item.comensales + (Number(item.comensales) === 1 ? ' persona' : ' personas');
        var reasons = document.createElement('div');
        reasons.className = 'admin-inbox__reasons';
        var primaryReason = (item.motivos || [])[0];
        if (primaryReason) {
          var reasonLabel = document.createElement('span');
          reasonLabel.className = 'admin-inbox__card-reason';
          reasonLabel.textContent = primaryReason.etiqueta || primaryReason.tipo;
          reasons.appendChild(reasonLabel);
          if ((item.motivos || []).length > 1) {
            var moreReasons = document.createElement('span');
            moreReasons.className = 'admin-inbox__card-more';
            moreReasons.textContent = '+ ' + ((item.motivos || []).length - 1) + ' más';
            reasons.appendChild(moreReasons);
          }
        }
        var actions = document.createElement('div');
        actions.className = 'admin-inbox__actions';
        actions.appendChild(button('Ver detalle', 'review', { 'data-reservation-id': item.reservacion_id }, false));
        card.append(head, facts, reasons, actions);
        list.appendChild(card);
      });
    }

    function markItemRead(item) {
      var unread = (item.notificaciones || []).filter(function (notification) { return !notification.leida_at; });
      if (!unread.length) return Promise.resolve();
      return Promise.all(unread.map(function (notification) {
        return request('/admin/api/buzon/leida', { method: 'POST', body: { id: Number(notification.id) } }).then(function () { notification.leida_at = new Date().toISOString(); });
      })).then(function () { item.leida = true; render(); });
    }

    function renderDetail(item) {
      if (!context) return;
      context.hidden = false;
      context.replaceChildren();
      var identity = document.createElement('div');
      identity.className = 'admin-inbox__detail-identity';
      var name = document.createElement('h3');
      name.textContent = item.nombre || 'Reservación';
      var facts = document.createElement('p');
      facts.textContent = formatDate(item.fecha) + ' · ' + formatTime(item.hora) + ' · ' + item.comensales + (Number(item.comensales) === 1 ? ' persona' : ' personas');
      identity.append(name, facts);
      var reasons = document.createElement('div');
      reasons.className = 'admin-inbox__detail-reasons';
      (item.notificaciones || []).forEach(function (notification) {
        var reason = document.createElement('section');
        reason.className = 'admin-inbox__detail-reason';
        var reasonTitle = document.createElement('strong');
        reasonTitle.textContent = notification.etiqueta || 'Seguimiento';
        var reasonCopy = document.createElement('p');
        if (notification.tipo === 'reservacion_horario_afectado' && notification.requiere_accion === false) {
          reasonCopy.textContent = 'El cliente tiene un enlace activo hasta ' + formatTime(notification.access_expires_at) + '.';
        } else {
          reasonCopy.textContent = notification.descripcion || 'Esta reservación requiere atención administrativa.';
        }
        reason.append(reasonTitle, reasonCopy);
        reasons.appendChild(reason);
      });
      var actions = document.createElement('div');
      actions.className = 'admin-inbox__detail-actions';
      var gestionarAgregado = false;
      function addGestionar(notification) {
        if (gestionarAgregado) return;
        gestionarAgregado = true;
        actions.appendChild(reservationLink(item, 'Abrir reservación', notification));
      }
      var development = document.createElement('div');
      development.className = 'admin-inbox__development';
      var developmentTitle = document.createElement('strong');
      developmentTitle.textContent = 'Herramientas de desarrollo';
      development.appendChild(developmentTitle);
      var hasDevelopment = false;
      (item.notificaciones || []).forEach(function (notification) {
        var attrs = notificationAttrs(notification);
        if (notification.tipo === 'reservacion_ausencia_pendiente' && notification.puede_registrar_no_show) {
          actions.appendChild(button('Registrar que no llegó', 'no-show', attrs, true));
          addGestionar(notification);
        } else if (notification.tipo === 'reservacion_sin_asignacion_proxima' && notification.puede_asignar_mesas) {
          actions.appendChild(assignmentLink(item));
          addGestionar(notification);
        } else if (notification.tipo === 'reservacion_horario_afectado') {
          if (notification.tiene_contacto === false && Number(item.comensales) <= 12) {
            actions.appendChild(button('Agregar contacto', 'contact', attrs, true));
            addGestionar(notification);
          } else if (Number(item.comensales) > 12) {
            addGestionar(notification);
          } else if (notification.requiere_accion === false) {
            var waiting = document.createElement('p');
            waiting.className = 'admin-inbox__waiting';
            waiting.textContent = 'Esperando respuesta';
            actions.appendChild(waiting);
            addGestionar(notification);
          } else {
            if (notification.puede_mandar_aviso) {
              actions.appendChild(button('Enviar recordatorio', 'notify', attrs, true));
            } else if (Number(notification.notification_attempts || 0) >= 3) {
              var limit = document.createElement('p');
              limit.className = 'admin-inbox__limit';
              limit.textContent = 'Se alcanzó el límite de recordatorios.';
              actions.appendChild(limit);
            } else if (notification.cooldown_hasta) {
              var cooldown = document.createElement('p');
              cooldown.className = 'admin-inbox__limit';
              cooldown.textContent = 'Podrás enviar otro recordatorio a las ' + formatTime(notification.cooldown_hasta) + '.';
              actions.appendChild(cooldown);
            }
            addGestionar(notification);
          }
          if (notification.test_link_disponible) {
            hasDevelopment = true;
            development.appendChild(button('Copiar enlace de prueba', 'test-link', attrs, false, true));
          }
        } else if (notification.tipo === 'reservacion_grupo_grande') {
          addGestionar(notification);
        }
      });
      if (hasDevelopment) context.append(identity, reasons, actions, development); else context.append(identity, reasons, actions);
      markItemRead(item).catch(function () {});
    }

    function openDetail(item) { view = 'detail'; setView('detail', item); }

    function showContact(item, notification) {
      context.replaceChildren();
      context.hidden = false;
      var heading = document.createElement('h3');
      heading.textContent = 'Agregar contacto';
      var copy = document.createElement('p');
      copy.textContent = 'Agrega un correo o teléfono para enviar el enlace.';
      var form = document.createElement('form');
      form.className = 'admin-inbox__contact-form';
      form.innerHTML = '<label><span>Tipo de contacto</span><select name="tipo"><option value="email">Correo electrónico</option><option value="telefono">Teléfono</option></select></label><label><span>Contacto</span><input name="contacto" required autocomplete="off"></label><p class="admin-inbox__context-status" data-contact-status></p><div><button type="button" class="admin-btn admin-btn--secondary" data-contact-cancel>Cancelar</button><button type="submit" class="admin-btn admin-btn--primary">Guardar contacto</button></div>';
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = form.querySelector('[type="submit"]');
        var status = form.querySelector('[data-contact-status]');
        submit.disabled = true;
        status.textContent = 'Guardando…';
        request('/admin/api/horarios-impactos/contacto', { method: 'POST', body: { impacto_id: Number(notification.impacto_id || 0), impacto_reservacion_id: Number(notification.impacto_reservacion_id || 0), tipo: form.elements.tipo.value, contacto: form.elements.contacto.value.trim() } })
          .then(function () { setView('list'); return refreshAfterAction('Contacto agregado; el recordatorio quedó preparado.'); })
          .catch(function (error) { status.textContent = error.message || 'No fue posible guardar el contacto.'; submit.disabled = false; });
      });
      form.querySelector('[data-contact-cancel]').addEventListener('click', function () { openDetail(item); });
      context.append(heading, copy, form);
      form.elements.contacto.focus();
    }

    function confirmNoShow(item) {
      if (!window.ConfirmationModal) return;
      var run = function () {
        var form = new URLSearchParams();
        form.set('reservation_id', String(item.reservacion_id));
        form.set('estado', 'no_show');
        form.set('motivo', 'buzon_ausencia_pendiente');
        return request('/admin/api/reservaciones/operacion/estado', { method: 'POST', body: form }).then(function () { setView('list'); return refreshAfterAction('Se registró que no llegó.'); });
      };
      window.ConfirmationModal.get().open({
        variant: 'warning', eyebrow: 'Buzón administrativo', title: 'Registrar que no llegó',
        description: 'La tolerancia de llegada ya venció para esta reservación.',
        consequence: 'La reservación quedará registrada como ausencia y dejará de requerir seguimiento.', secondaryLabel: 'Cancelar', primaryLabel: 'Registrar que no llegó', onPrimary: run
      });
    }

    function sendNotice(item, notification, control) {
      control.disabled = true;
      request('/admin/api/horarios-impactos/preparar', { method: 'POST', body: { impacto_id: Number(notification.impacto_id || 0), impacto_reservacion_id: Number(notification.impacto_reservacion_id || 0) } })
        .then(function () { setView('list'); return refreshAfterAction('Recordatorio preparado; el cliente puede responder.'); })
        .catch(function (error) { control.disabled = false; if (summary) summary.textContent = error.message || 'No fue posible preparar el recordatorio.'; });
    }

    function copyText(value) {
      if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(value);
      var field = document.createElement('textarea');
      field.value = value; field.setAttribute('readonly', ''); field.style.position = 'fixed'; field.style.opacity = '0';
      document.body.appendChild(field); field.select();
      var copied = false;
      try { copied = document.execCommand('copy'); } finally { field.remove(); }
      return copied ? Promise.resolve() : Promise.reject(new Error('No fue posible copiar el enlace de prueba.'));
    }

    function findItem(notificationId) {
      return items.find(function (item) { return (item.notificaciones || []).some(function (notification) { return Number(notification.id) === notificationId; }); }) || null;
    }

    function handleAction(control) {
      var action = control.getAttribute('data-inbox-action');
      if (action === 'close-detail') { setView('list'); return; }
      if (action === 'retry') { loadList(); return; }
      if (action === 'review') {
        var reviewed = items.find(function (item) { return Number(item.reservacion_id) === Number(control.getAttribute('data-reservation-id')); });
        if (reviewed) openDetail(reviewed);
        return;
      }
      var notificationId = Number(control.getAttribute('data-notification-id') || 0);
      var item = notificationId ? findItem(notificationId) : null;
      var notification = item && (item.notificaciones || []).find(function (candidate) { return Number(candidate.id) === notificationId; });
      if (!item || !notification) return;
      if (action === 'contact') showContact(item, notification);
      if (action === 'no-show') confirmNoShow(item);
      if (action === 'notify') sendNotice(item, notification, control);
      if (action === 'test-link') {
        control.disabled = true;
        request('/admin/api/horarios-impactos/acceso-prueba', { method: 'POST', body: { impacto_id: notification.impacto_id, impacto_reservacion_id: notification.impacto_reservacion_id } })
          .then(function (data) { if (!data.test_access_url) throw new Error('No hay un enlace de prueba disponible.'); return copyText(data.test_access_url); })
          .then(function () { if (summary) summary.textContent = 'Enlace de prueba copiado.'; })
          .catch(function (error) { if (summary) summary.textContent = error.message || 'No fue posible copiar el enlace de prueba.'; })
          .finally(function () { control.disabled = false; });
      }
    }

    function showError(error) {
      if (!list) return;
      if (items.length) {
        if (summary) summary.textContent = error && error.message
          ? error.message
          : 'No pudimos actualizar las notificaciones.';
        return;
      }
      list.replaceChildren();
      var box = document.createElement('div');
      box.className = 'admin-inbox__empty is-error';
      var heading = document.createElement('strong');
      heading.textContent = 'No pudimos actualizar las notificaciones.';
      box.append(heading, button('Reintentar', 'retry', {}, true));
      list.appendChild(box);
      if (summary) summary.textContent = error && error.message ? error.message : 'Intenta nuevamente.';
    }

    function loadList() {
      if (loading) return Promise.resolve();
      loading = true;
      if (list && !items.length) {
        list.replaceChildren();
        var loadingText = document.createElement('p');
        loadingText.className = 'admin-inbox__loading';
        loadingText.textContent = 'Cargando notificaciones…';
        list.appendChild(loadingText);
      }
      return request('/admin/api/buzon').then(function (data) { items = Array.isArray(data.items) ? data.items : []; updateCount(data); render(); }).catch(showError).finally(function () { loading = false; });
    }

    function refreshAfterAction(message) {
      return loadList().then(function () { if (summary && message && view === 'list') summary.textContent = message; return refreshSummary(); });
    }

    list.addEventListener('click', function (event) { var control = event.target.closest('[data-inbox-action]'); if (control) handleAction(control); });
    context.addEventListener('click', function (event) { var control = event.target.closest('[data-inbox-action]'); if (control) handleAction(control); });
    root.querySelectorAll('[data-inbox-filter]').forEach(function (filter) {
      filter.addEventListener('click', function () {
        activeFilter = filter.getAttribute('data-inbox-filter') || 'action';
        root.querySelectorAll('[data-inbox-filter]').forEach(function (other) { var selected = other === filter; other.classList.toggle('is-active', selected); other.setAttribute('aria-selected', selected ? 'true' : 'false'); });
        render();
      });
    });
    trigger.addEventListener('click', open);
    backButton.addEventListener('click', function () { setView('list'); });
    closeButtons.forEach(function (buttonElement) { buttonElement.addEventListener('click', close); });
    document.addEventListener('keydown', function (event) { if (!drawer.hidden && event.key === 'Escape') close(); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') syncPendientes(false); });
    window.setInterval(function () { syncPendientes(false); }, refreshMs);
    syncPendientes(true);
  }

  document.addEventListener('DOMContentLoaded', init);
}());
