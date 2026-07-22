/**
 * Filtros GET progresivos para listados administrativos.
 * El backend conserva el filtrado y este componente sustituye solo el partial.
 */
(function () {
    var instances = new WeakMap();

    function ReactiveFilters(form, options) {
        this.form = form;
        this.options = options || {};
        this.debounceMs = parseInt(form.getAttribute('data-reactive-debounce') || '350', 10) || 350;
        var targetSelector = form.getAttribute('data-reactive-target');
        var loadingSelector = form.getAttribute('data-reactive-loading');
        var errorSelector = form.getAttribute('data-reactive-error');
        this.target = targetSelector ? document.querySelector(targetSelector) : null;
        this.loading = loadingSelector ? document.querySelector(loadingSelector) : null;
        this.error = errorSelector ? document.querySelector(errorSelector) : null;
        this.abortController = null;
        this.timer = null;
        this.requestSequence = 0;
        this.lastUrl = '';
        this.bind();
    }

    ReactiveFilters.prototype.controls = function () {
        return Array.prototype.slice.call(this.form.querySelectorAll('[data-reactive-control]'));
    };

    ReactiveFilters.prototype.isTextControl = function (control) {
        return control && (control.type === 'search' || control.type === 'text' || control.tagName === 'TEXTAREA');
    };

    ReactiveFilters.prototype.bind = function () {
        var self = this;
        this.form.classList.add('is-reactive');

        this.form.addEventListener('input', function (event) {
            var control = event.target.closest('[data-reactive-control]');
            if (!self.isTextControl(control)) {
                return;
            }
            window.clearTimeout(self.timer);
            self.timer = window.setTimeout(function () {
                self.trigger(control, 'input');
            }, self.debounceMs);
        });

        this.form.addEventListener('change', function (event) {
            var control = event.target.closest('[data-reactive-control]');
            if (!control || self.isTextControl(control)) {
                return;
            }
            window.clearTimeout(self.timer);
            self.trigger(control, 'change');
        });

        this.form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(self.timer);
            self.trigger(null, 'submit');
        });

        this.form.addEventListener('click', function (event) {
            var clear = event.target.closest('[data-reactive-clear]');
            if (!clear) {
                return;
            }
            event.preventDefault();
            self.resetControls();
            self.trigger(null, 'clear');
        });

        if (this.target) {
            this.target.addEventListener('click', function (event) {
                var clear = event.target.closest('[data-reactive-clear]');
                if (clear) {
                    event.preventDefault();
                    self.resetControls();
                    self.trigger(null, 'clear');
                    return;
                }
                var link = event.target.closest('[data-reactive-page]');
                if (!link) {
                    return;
                }
                event.preventDefault();
                self.request(link.href);
            });
        }

        if (this.error) {
            this.error.addEventListener('click', function (event) {
                if (event.target.closest('[data-reactive-retry]') && self.lastUrl) {
                    self.request(self.lastUrl);
                }
            });
        }

        this.updateClearVisibility();
    };

    ReactiveFilters.prototype.trigger = function (control, reason) {
        if (typeof this.options.onChange === 'function') {
            this.options.onChange({ form: this.form, control: control, reason: reason });
            this.updateClearVisibility();
            return;
        }

        this.request(this.buildUrl(true));
    };

    ReactiveFilters.prototype.buildUrl = function (resetPage) {
        var action = this.form.getAttribute('action') || window.location.pathname;
        var url = new URL(action, window.location.origin);
        var data = new FormData(this.form);

        data.forEach(function (value, key) {
            if (String(value) !== '') {
                url.searchParams.set(key, String(value));
            }
        });

        if (resetPage) {
            url.searchParams.delete('page');
        }

        return url.toString();
    };

    ReactiveFilters.prototype.resetControls = function () {
        this.controls().forEach(function (control) {
            var defaultValue = control.getAttribute('data-reactive-default');
            if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = defaultValue === '1';
            } else {
                control.value = defaultValue == null ? '' : defaultValue;
            }
        });
    };

    ReactiveFilters.prototype.hasActiveFilters = function () {
        return this.controls().some(function (control) {
            var current = (control.type === 'checkbox' || control.type === 'radio')
                ? (control.checked ? '1' : '0')
                : String(control.value || '');
            return current !== String(control.getAttribute('data-reactive-default') || '');
        });
    };

    ReactiveFilters.prototype.updateClearVisibility = function () {
        var active = this.hasActiveFilters();
        this.form.querySelectorAll('[data-reactive-clear]').forEach(function (clear) {
            clear.hidden = !active;
        });
    };

    ReactiveFilters.prototype.syncControls = function (url) {
        var parsed = new URL(url, window.location.origin);
        this.controls().forEach(function (control) {
            var value = parsed.searchParams.has(control.name)
                ? parsed.searchParams.get(control.name)
                : (control.getAttribute('data-reactive-default') || '');

            if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = String(value) === String(control.value || '1');
            } else {
                control.value = value;
            }
        });
    };

    ReactiveFilters.prototype.setLoading = function (active) {
        if (this.target) {
            this.target.classList.toggle('is-updating', active);
            this.target.setAttribute('aria-busy', active ? 'true' : 'false');
        }
        if (this.loading) {
            this.loading.hidden = !active;
        }
    };

    ReactiveFilters.prototype.showError = function (visible) {
        if (this.error) {
            this.error.hidden = !visible;
        }
    };

    ReactiveFilters.prototype.request = function (requestUrl) {
        var self = this;
        var sequence = ++this.requestSequence;
        var url = new URL(requestUrl, window.location.origin);
        url.hash = '';
        this.lastUrl = url.toString();

        if (this.abortController) {
            this.abortController.abort();
        }

        this.abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        this.setLoading(true);
        this.showError(false);

        fetch(url.toString(), {
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            signal: this.abortController ? this.abortController.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                var canonicalUrl = response.headers.get('X-Filter-URL') || url.toString();
                return response.text().then(function (html) {
                    return { html: html, canonicalUrl: canonicalUrl };
                });
            })
            .then(function (result) {
                if (sequence !== self.requestSequence || !self.target) {
                    return;
                }
                self.target.innerHTML = result.html;
                self.syncControls(result.canonicalUrl);
                if (window.history && window.history.replaceState) {
                    var canonical = new URL(result.canonicalUrl, window.location.origin);
                    window.history.replaceState({}, '', canonical.pathname + (canonical.search || ''));
                }
                self.updateClearVisibility();
                document.dispatchEvent(new CustomEvent('admin:reactive-updated', {
                    detail: { form: self.form, target: self.target, url: result.canonicalUrl }
                }));
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (sequence === self.requestSequence) {
                    self.showError(true);
                }
            })
            .finally(function () {
                if (sequence === self.requestSequence) {
                    self.setLoading(false);
                }
            });
    };

    function init(form, options) {
        if (!form) {
            return null;
        }
        if (instances.has(form)) {
            return instances.get(form);
        }
        var instance = new ReactiveFilters(form, options);
        instances.set(form, instance);
        return instance;
    }

    window.AdminReactiveFilters = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-reactive-filters]:not([data-reactive-manual])').forEach(function (form) {
            init(form);
        });
    });
})();
