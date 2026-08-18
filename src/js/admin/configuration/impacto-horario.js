/* Seguimiento obligatorio de reservaciones afectadas por cambios de horario. */
(function () {
  function initScheduleImpact() {
    var page = document.querySelector('[data-schedule-impact]');
    var modal = document.querySelector('[data-schedule-impact-modal]');
    if (!page || !modal) return;

    var impactId = Number(page.getAttribute('data-impact-id') || 0);
    var csrf = page.getAttribute('data-admin-csrf') || '';
    var list = modal.querySelector('[data-impact-list]');
    var status = modal.querySelector('[data-impact-status]');
    var footerStatus = modal.querySelector('[data-impact-footer-status]');
    var count = modal.querySelector('[data-impact-pending-count]');
    var prepareAll = modal.querySelector('[data-impact-notify-all]');
    var complete = modal.querySelector('[data-impact-complete]');
    var finish = modal.querySelector('[data-impact-finish]');
    var notice = modal.querySelector('[data-impact-test-link-notice]');
    var copyLink = modal.querySelector('[data-impact-copy-link]');
    var contactView = modal.querySelector('[data-impact-contact-view]');
    var contactForm = modal.querySelector('[data-impact-contact-form]');
    var contactCancel = modal.querySelector('[data-impact-contact-cancel]');
    var testLink = '';

    function setStatus(target, message, type) {
      if (!target) return;
      target.textContent = message || '';
      target.classList.remove('is-error', 'is-pending', 'is-success');
      if (type) target.classList.add('is-' + type);
    }

    function stateLabel(state) {
      return {
        pendiente_notificacion: 'Pendiente de aviso',
        notificacion_preparada: 'Aviso preparado',
        sin_contacto: 'Sin contacto',
        atendida_manual: 'Atendida manualmente',
        resuelta_por_cliente: 'Resuelta por cliente'
      }[state] || String(state || '').replace(/_/g, ' ');
    }

    function actionButton(label, attribute, variant) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'admin-btn admin-btn--' + variant + ' admin-btn--small';
      button.textContent = label;
      button.setAttribute(attribute, '');
      return button;
    }

    function showTestLink(url) {
      if (!url || !notice) return;
      testLink = String(url);
      notice.hidden = false;
      var label = notice.querySelector('[data-impact-test-link-label]');
      if (label) label.textContent = 'El enlace sólo vive en esta pantalla y se sobrescribe al regenerarlo.';
    }

    function clearTestLink() {
      testLink = '';
      if (notice) notice.hidden = true;
    }

    function fallbackCopy() {
      var input = document.createElement('textarea');
      input.value = testLink;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.appendChild(input);
      input.select();
      try { document.execCommand('copy'); } catch (error) {}
      input.remove();
      setStatus(status, 'Link de prueba copiado.', null);
    }

    function copyTestLink() {
      if (!testLink) return;
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(testLink).then(function () {
          setStatus(status, 'Link de prueba copiado.', null);
        }).catch(fallbackCopy);
        return;
      }
      fallbackCopy();
    }

    function showList() {
      if (list) list.hidden = false;
      if (contactView) contactView.hidden = true;
    }

    function showContact(row) {
      if (!contactView || !contactForm) return;
      if (list) list.hidden = true;
      contactView.hidden = false;
      contactForm.querySelector('[data-impact-contact-impact-id]').value = String(impactId);
      contactForm.querySelector('[data-impact-contact-reservation-id]').value = row.getAttribute('data-impact-reservation-id') || '';
      contactForm.querySelector('[data-impact-contact-value]').value = '';
      setStatus(contactForm.querySelector('[data-impact-contact-status]'), '', null);
      window.setTimeout(function () {
        var field = contactForm.querySelector('[data-impact-contact-value]');
        if (field) field.focus();
      }, 0);
    }

    function renderImpact(impact) {
      var rows = impact && Array.isArray(impact.reservaciones) ? impact.reservaciones : [];
      if (list) list.replaceChildren();

      rows.forEach(function (item) {
        var row = document.createElement('article');
        row.className = 'schedule-impact-row';
        row.setAttribute('data-impact-row', '');
        row.setAttribute('data-impact-reservation-id', String(item.id || ''));

        var identity = document.createElement('div');
        identity.className = 'schedule-impact-row__identity';
        var name = document.createElement('strong');
        name.setAttribute('data-impact-name', '');
        name.textContent = String(item.nombre || '');
        var date = document.createElement('span');
        date.setAttribute('data-impact-date', '');
        date.textContent = String(item.fecha || '') + ' · ' + String(item.hora || '');
        var guests = document.createElement('span');
        guests.setAttribute('data-impact-guests', '');
        guests.textContent = String(item.comensales || 0) + ' ' + (Number(item.comensales) === 1 ? 'comensal' : 'comensales');
        identity.append(name, date, guests);

        var contact = document.createElement('div');
        contact.className = 'schedule-impact-row__contact';
        var contactLabel = document.createElement('span');
        contactLabel.className = 'schedule-impact-row__label';
        contactLabel.textContent = 'Contacto';
        var contactValue = document.createElement('span');
        contactValue.setAttribute('data-impact-contact', '');
        contactValue.textContent = item.tiene_contacto ? String(item.contacto || '') : 'Sin contacto';
        contact.append(contactLabel, contactValue);

        var state = document.createElement('div');
        state.className = 'schedule-impact-row__state';
        var stateLabelElement = document.createElement('span');
        stateLabelElement.className = 'schedule-impact-row__label';
        stateLabelElement.textContent = 'Estado';
        var stateValue = document.createElement('span');
        stateValue.setAttribute('data-impact-state', '');
        stateValue.textContent = stateLabel(item.estado);
        state.append(stateLabelElement, stateValue);

        var actions = document.createElement('div');
        actions.className = 'schedule-impact-row__actions';
        actions.setAttribute('data-impact-actions', '');
        if (item.estado === 'pendiente_notificacion' && item.tiene_contacto) {
          actions.append(actionButton('Preparar aviso', 'data-impact-notify', 'primary'));
        } else if (item.estado === 'sin_contacto') {
          actions.append(actionButton('Agregar contacto', 'data-impact-add-contact', 'secondary'));
          var manual = actionButton('Atender manualmente', 'data-impact-manual', 'danger');
          manual.disabled = !item.manual_habilitada;
          actions.append(manual);
        } else if (item.test_link_disponible) {
          actions.append(actionButton('Regenerar link de prueba', 'data-impact-test-link', 'secondary'));
        }

        row.append(identity, contact, state, actions);
        if (list) list.append(row);
      });

      var pending = Number(impact && impact.pendientes) || 0;
      var manualReady = Boolean(impact && impact.manual_habilitada);
      if (count) count.textContent = pending + (pending === 1 ? ' pendiente' : ' pendientes');
      if (prepareAll) prepareAll.disabled = !rows.some(function (item) {
        return item.estado === 'pendiente_notificacion' && item.tiene_contacto;
      });
      if (complete) complete.hidden = pending !== 0;
      if (finish) finish.hidden = pending !== 0;
      if (footerStatus) footerStatus.textContent = manualReady ? 'Todos los avisos con contacto están preparados.' : '';
      modal.setAttribute('data-impact-pending', pending > 0 ? '1' : '0');
      if (pending === 0) showList();
    }

    function request(url, options) {
      var config = options || {};
      var headers = Object.assign({
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      }, config.headers || {});
      var body = Object.assign({}, config.body || {}, { admin_csrf: csrf });
      return fetch(url, {
        method: config.method || 'POST',
        headers: headers,
        body: config.method === 'GET' ? undefined : JSON.stringify(body),
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json().then(function (data) {
          data.httpStatus = response.status;
          return data;
        });
      });
    }

    function refresh(message) {
      return fetch('/admin/api/horarios-impactos?impacto_id=' + encodeURIComponent(String(impactId)), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) { return response.json(); }).then(function (data) {
        if (!data.ok) throw new Error(data.mensaje || 'No fue posible cargar el seguimiento.');
        renderImpact(data.impacto);
        if (message) setStatus(status, message, null);
        return data.impacto;
      });
    }

    function reportResult(data, successMessage) {
      var partial = data && data.codigo === 'AVISOS_PARCIALES';
      if (!data || (!data.ok && !partial)) {
        setStatus(status, data && data.mensaje ? data.mensaje : 'No fue posible completar la acción.', 'error');
        return false;
      }
      var link = data.test_access_url || '';
      if (link) showTestLink(link);
      if (partial) {
        setStatus(status, 'Se prepararon ' + Number(data.preparadas || 0) + ' de ' + Number(data.total || 0) + ' avisos. Los fallidos siguen pendientes.', 'error');
      } else {
        setStatus(status, successMessage || data.mensaje || 'Acción completada.', null);
      }
      return true;
    }

    function perform(url, body, message, button) {
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
      }
      setStatus(status, 'Procesando…', 'pending');
      return request(url, { method: 'POST', body: body }).then(function (data) {
        if (reportResult(data, message)) return refresh();
        return null;
      }).catch(function (error) {
        setStatus(status, error && error.message ? error.message : 'No fue posible completar la acción.', 'error');
        return null;
      }).finally(function () {
        if (button) {
          button.disabled = false;
          button.removeAttribute('aria-busy');
        }
      });
    }

    function confirmManual(row, button) {
      if (!window.ConfirmationModal) {
        setStatus(status, 'No fue posible abrir la confirmación. Intenta de nuevo.', 'error');
        return;
      }
      var name = row.querySelector('[data-impact-name]');
      var date = row.querySelector('[data-impact-date]');
      window.ConfirmationModal.get().open({
        variant: 'warning',
        eyebrow: 'Atención manual',
        title: 'Marcar afectación como atendida',
        description: 'Confirma que el restaurante revisó este caso por un canal no registrado.',
        consequence: 'La reservación no cambiará y el seguimiento registrará quién lo atendió.',
        summary: [name ? name.textContent : 'Reservación', date ? date.textContent : ''],
        secondaryLabel: 'Volver',
        primaryLabel: 'Confirmar atención manual',
        onPrimary: function () {
          return perform('/admin/api/horarios-impactos/atender-manual', {
            impacto_id: impactId,
            impacto_reservacion_id: Number(row.getAttribute('data-impact-reservation-id') || 0)
          }, 'Afectación atendida manualmente.', button);
        }
      });
    }

    if (list) {
      list.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        var row = event.target.closest('[data-impact-row]');
        if (!button || !row) return;
        var itemId = Number(row.getAttribute('data-impact-reservation-id') || 0);
        if (button.hasAttribute('data-impact-notify')) {
          perform('/admin/api/horarios-impactos/preparar', {
            impacto_id: impactId,
            impacto_reservacion_id: itemId
          }, 'Aviso preparado.', button);
        } else if (button.hasAttribute('data-impact-add-contact')) {
          showContact(row);
        } else if (button.hasAttribute('data-impact-manual')) {
          confirmManual(row, button);
        } else if (button.hasAttribute('data-impact-test-link')) {
          perform('/admin/api/horarios-impactos/acceso-prueba', {
            impacto_id: impactId,
            impacto_reservacion_id: itemId
          }, 'Link de prueba regenerado.', button);
        }
      });
    }

    if (prepareAll) {
      prepareAll.addEventListener('click', function () {
        perform('/admin/api/horarios-impactos/preparar-disponibles', {
          impacto_id: impactId
        }, 'Avisos disponibles preparados.', prepareAll);
      });
    }

    if (copyLink) copyLink.addEventListener('click', copyTestLink);
    if (contactCancel) contactCancel.addEventListener('click', showList);

    if (contactForm) {
      contactForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var type = contactForm.querySelector('[data-impact-contact-type]').value;
        var value = contactForm.querySelector('[data-impact-contact-value]').value.trim();
        var contactStatus = contactForm.querySelector('[data-impact-contact-status]');
        if (!value) {
          setStatus(contactStatus, 'Escribe un correo o teléfono.', 'error');
          return;
        }
        var submit = contactForm.querySelector('button[type="submit"]');
        submit.disabled = true;
        setStatus(contactStatus, 'Guardando contacto…', 'pending');
        request('/admin/api/horarios-impactos/contacto', {
          method: 'POST',
          body: {
            impacto_id: Number(contactForm.querySelector('[data-impact-contact-impact-id]').value || 0),
            impacto_reservacion_id: Number(contactForm.querySelector('[data-impact-contact-reservation-id]').value || 0),
            tipo: type,
            contacto: value
          }
        }).then(function (data) {
          if (!reportResult(data, 'Contacto agregado.')) return;
          showList();
          return refresh();
        }).catch(function (error) {
          setStatus(contactStatus, error && error.message ? error.message : 'No fue posible guardar el contacto.', 'error');
        }).finally(function () {
          submit.disabled = false;
        });
      });
    }

    // El seguimiento bloquea la salida hasta que no queden pendientes.
    document.addEventListener('keydown', function (event) {
      if (!modal.classList.contains('is-open') || modal.getAttribute('data-impact-pending') !== '1') return;
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);
    modal.addEventListener('click', function (event) {
      if (modal.getAttribute('data-impact-pending') !== '1') return;
      if (event.target.classList.contains('schedule-impact-modal__backdrop')) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);

    clearTestLink();
    modal.setAttribute('data-impact-pending', '1');
    setStatus(status, 'Cargando seguimiento…', 'pending');
    refresh().catch(function (error) {
      setStatus(status, error && error.message ? error.message : 'No fue posible cargar las reservaciones afectadas.', 'error');
    });
    window.setTimeout(function () {
      document.dispatchEvent(new CustomEvent('admin:open-modal', {
        detail: { id: 'schedule-impact-modal' }
      }));
    }, 0);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initScheduleImpact();
  });
})();
