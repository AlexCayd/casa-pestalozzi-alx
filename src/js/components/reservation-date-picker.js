/**
 * Shared reservation date picker.
 */
(function () {
  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  function parseDate(value) {
    var match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;

    var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    date.setHours(0, 0, 0, 0);

    return date.getFullYear() === Number(match[1]) &&
      date.getMonth() === Number(match[2]) - 1 &&
      date.getDate() === Number(match[3]) ? date : null;
  }

  function formatValue(date) {
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
  }

  function formatDisplay(date) {
    return pad(date.getDate()) + " / " + pad(date.getMonth() + 1) + " / " + date.getFullYear();
  }

  function parseWeekdays(value) {
    if (Array.isArray(value)) {
      return value.map(function (day) { return parseInt(day, 10); }).filter(function (day) {
        return day >= 0 && day <= 6;
      });
    }

    return String(value || "")
      .split(",")
      .map(function (day) { return parseInt(day, 10); })
      .filter(function (day) { return day >= 0 && day <= 6; });
  }

  window.createReservationDatePicker = function createReservationDatePicker(options) {
    options = options || {};

    var root = options.root;
    if (!root) return null;

    var display = options.display || root.querySelector("[data-date-display]") || root.querySelector(".date-display");
    var input = options.input || root.querySelector("[data-date-input]") || root.querySelector('input[type="hidden"]');
    var calendar = options.calendar || root.querySelector("[data-date-calendar]") || root.querySelector(".cp-calendar");
    var label = root.querySelector("[data-date-label]") || root.querySelector(".cpc-label");
    var grid = root.querySelector("[data-date-grid]") || root.querySelector(".cpc-grid");
    var prevBtn = root.querySelector("[data-date-prev]") || root.querySelector(".cpc-prev");
    var nextBtn = root.querySelector("[data-date-next]") || root.querySelector(".cpc-next");
    var minDate = parseDate(options.minDate || root.getAttribute("data-min-date")) || parseDate(new Date().toISOString().slice(0, 10));
    var enabledWeekdays = parseWeekdays(options.enabledWeekdays || root.getAttribute("data-enabled-weekdays"));
    var MONTHS = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
    var selected = parseDate(options.initialValue || (input ? input.value : ""));
    var current = selected || minDate || new Date();
    var curYear = current.getFullYear();
    var curMonth = current.getMonth();

    if (!display || !input || !calendar || !label || !grid || !prevBtn || !nextBtn) {
      return null;
    }

    if (selected) {
      input.value = formatValue(selected);
      display.value = formatDisplay(selected);
    }

    function isDisabled(date) {
      if (minDate && date < minDate) return true;
      return enabledWeekdays.length > 0 && enabledWeekdays.indexOf(date.getDay()) === -1;
    }

    function emitChange() {
      var event;
      try {
        event = new CustomEvent("reservation:datechange", { detail: { fecha: input.value } });
      } catch (error) {
        event = document.createEvent("CustomEvent");
        event.initCustomEvent("reservation:datechange", true, true, { fecha: input.value });
      }
      input.dispatchEvent(event);
      if (typeof options.onChange === "function") {
        options.onChange(input.value);
      }
    }

    function render() {
      label.textContent = MONTHS[curMonth] + " " + curYear;
      grid.innerHTML = "";

      var first = new Date(curYear, curMonth, 1).getDay();
      var days = new Date(curYear, curMonth + 1, 0).getDate();
      var today = new Date();
      today.setHours(0, 0, 0, 0);

      for (var i = 0; i < first; i++) {
        var empty = document.createElement("span");
        empty.className = "cpc-day empty";
        grid.appendChild(empty);
      }

      for (var d = 1; d <= days; d++) {
        (function (day) {
          var btn = document.createElement("button");
          btn.type = "button";
          btn.className = "cpc-day";
          btn.textContent = day;

          var date = new Date(curYear, curMonth, day);
          date.setHours(0, 0, 0, 0);

          if (isDisabled(date)) {
            btn.classList.add("disabled");
            btn.disabled = true;
          }
          if (date.getTime() === today.getTime()) btn.classList.add("today");
          if (selected && date.getTime() === selected.getTime()) btn.classList.add("selected");

          btn.addEventListener("click", function () {
            selected = date;
            input.value = formatValue(date);
            display.value = formatDisplay(date);
            closeCalendar();
            emitChange();
          });

          grid.appendChild(btn);
        })(d);
      }
    }

    function openCalendar() {
      if (display.disabled) return;
      calendar.classList.add("open");
      calendar.setAttribute("aria-hidden", "false");
      display.setAttribute("aria-expanded", "true");
      render();
    }

    function closeCalendar() {
      calendar.classList.remove("open");
      calendar.setAttribute("aria-hidden", "true");
      display.setAttribute("aria-expanded", "false");
    }

    display.addEventListener("click", function (event) {
      event.stopPropagation();
      calendar.classList.contains("open") ? closeCalendar() : openCalendar();
    });

    display.addEventListener("keydown", function (event) {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        openCalendar();
      }
      if (event.key === "Escape") {
        closeCalendar();
      }
    });

    prevBtn.addEventListener("click", function (event) {
      event.stopPropagation();
      curMonth--;
      if (curMonth < 0) {
        curMonth = 11;
        curYear--;
      }
      render();
    });

    nextBtn.addEventListener("click", function (event) {
      event.stopPropagation();
      curMonth++;
      if (curMonth > 11) {
        curMonth = 0;
        curYear++;
      }
      render();
    });

    document.addEventListener("click", function (event) {
      if (!root.contains(event.target)) {
        closeCalendar();
      }
    });

    return {
      setDisabled: function (disabled) {
        display.disabled = Boolean(disabled);
        input.disabled = Boolean(disabled);
        display.setAttribute("aria-disabled", disabled ? "true" : "false");
        root.classList.toggle("is-disabled", Boolean(disabled));
        if (disabled) closeCalendar();
      },
      setValue: function (value, silent) {
        selected = parseDate(value);
        input.value = selected ? formatValue(selected) : "";
        display.value = selected ? formatDisplay(selected) : "";
        if (selected) {
          curYear = selected.getFullYear();
          curMonth = selected.getMonth();
        }
        if (!silent) emitChange();
      },
      getValue: function () {
        return input.value;
      },
      close: closeCalendar
    };
  };
})();
