/* Formulario de reserva + calendario personalizado + hora picker */

/* ---- Calendario ---- */
function initCalendar() {
  var wrap    = $("#datePicker");
  var display = $("#dateDisplay");
  var hidden  = $("#fechaHidden");
  var cal     = $("#cpCalendar");
  var label   = cal.querySelector(".cpc-label");
  var grid    = cal.querySelector(".cpc-grid");
  var prevBtn = cal.querySelector(".cpc-prev");
  var nextBtn = cal.querySelector(".cpc-next");

  var today    = new Date(); today.setHours(0,0,0,0);
  var curYear  = today.getFullYear();
  var curMonth = today.getMonth();
  var selected = null;

  var MONTHS = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];

  function pad(n) { return n < 10 ? "0" + n : "" + n; }

  function render() {
    label.textContent = MONTHS[curMonth] + " " + curYear;
    grid.innerHTML = "";

    var first = new Date(curYear, curMonth, 1).getDay();
    var days  = new Date(curYear, curMonth + 1, 0).getDate();

    for (var i = 0; i < first; i++) {
      var empty = document.createElement("span");
      empty.className = "cpc-day empty";
      grid.appendChild(empty);
    }

    for (var d = 1; d <= days; d++) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "cpc-day";
      btn.textContent = d;

      var date = new Date(curYear, curMonth, d);
      date.setHours(0,0,0,0);

      if (date < today) {
        btn.classList.add("disabled");
        btn.disabled = true;
      }
      if (date.getTime() === today.getTime()) btn.classList.add("today");
      if (selected && date.getTime() === selected.getTime()) btn.classList.add("selected");

      (function(dt) {
        btn.addEventListener("click", function() {
          selected = dt;
          hidden.value = dt.getFullYear() + "-" + pad(dt.getMonth() + 1) + "-" + pad(dt.getDate());
          display.value = pad(dt.getDate()) + " / " + pad(dt.getMonth() + 1) + " / " + dt.getFullYear();
          closeCalendar();
        });
      })(date);

      grid.appendChild(btn);
    }
  }

  function openCalendar() { cal.classList.add("open"); cal.setAttribute("aria-hidden","false"); render(); }
  function closeCalendar() { cal.classList.remove("open"); cal.setAttribute("aria-hidden","true"); }

  display.addEventListener("click", function(e) { e.stopPropagation(); cal.classList.contains("open") ? closeCalendar() : openCalendar(); });
  prevBtn.addEventListener("click", function(e) { e.stopPropagation(); curMonth--; if (curMonth < 0) { curMonth = 11; curYear--; } render(); });
  nextBtn.addEventListener("click", function(e) { e.stopPropagation(); curMonth++; if (curMonth > 11) { curMonth = 0; curYear++; } render(); });

  document.addEventListener("click", function(e) { if (!wrap.contains(e.target)) closeCalendar(); });
}

/* ---- Selector de hora ---- */
function initHourPicker() {
  var wrap     = $("#hourPicker");
  var display  = $("#hourDisplay");
  var hidden   = $("#horaHidden");
  var dropdown = $("#hourDropdown");
  var HOURS    = ["09:00","10:00","11:00","12:00","13:00","14:00","15:00","16:00","17:00","18:00","19:00","20:00","21:00"];

  HOURS.forEach(function(h) {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "hour-option";
    btn.textContent = h;
    btn.addEventListener("click", function(e) {
      e.stopPropagation();
      hidden.value = h;
      display.value = h;
      dropdown.querySelectorAll(".hour-option").forEach(function(b) { b.classList.remove("sel"); });
      btn.classList.add("sel");
      closeHourDropdown();
    });
    dropdown.appendChild(btn);
  });

  function closeHourDropdown() { dropdown.classList.remove("open"); dropdown.setAttribute("aria-hidden","true"); }

  display.addEventListener("click", function(e) {
    e.stopPropagation();
    var isOpen = dropdown.classList.contains("open");
    var cal = $("#cpCalendar");
    if (cal) { cal.classList.remove("open"); cal.setAttribute("aria-hidden","true"); }
    isOpen ? closeHourDropdown() : (dropdown.classList.add("open"), dropdown.setAttribute("aria-hidden","false"));
  });

  document.addEventListener("click", function(e) { if (!wrap.contains(e.target)) closeHourDropdown(); });
}

/* ---- Formulario ---- */
function initForm() {
  var form   = $("#reservaForm");
  var pills  = $$("#guestPills .pill");
  var extra  = $("#guestsExtra");
  var guests = 2;

  pills.forEach(function(p) {
    p.addEventListener("click", function() {
      pills.forEach(function(x) { x.classList.remove("sel"); });
      p.classList.add("sel");
      var g = p.getAttribute("data-g");
      if (g === "6+") {
        extra.classList.add("show");
        guests = guestCount || 6;
      } else {
        extra.classList.remove("show");
        guests = g;
      }
    });
  });

  var stepVal   = $("#guestsVal");
  var stepHid   = $("#guestsNum");
  var stepMinus = $("#guestsMinus");
  var stepPlus  = $("#guestsPlus");
  var guestCount = 6;

  function updateStepper() {
    stepVal.textContent = guestCount;
    stepHid.value = guestCount;
    stepMinus.disabled = (guestCount <= 6);
    guests = guestCount;
  }

  stepPlus.addEventListener("click", function() { guestCount = Math.min(99, guestCount + 1); updateStepper(); });
  stepMinus.addEventListener("click", function() { guestCount = Math.max(6, guestCount - 1); updateStepper(); });

  initCalendar();
  initHourPicker();

  var msg = $("#formMsg");
  var confirm = $("#reservaConfirm");
  var confirmMark = confirm ? confirm.querySelector(".mark") : null;
  var confirmTitle = confirm ? confirm.querySelector("h3") : null;
  var confirmText = $("#confirmText");

  function showInlineMessage(text) {
    msg.textContent = text;
    msg.classList.add("show");
    if (!reduce) gsap.fromTo(form, { x: -6 }, { x: 0, duration: 0.4, ease: "elastic.out(1,0.3)" });
  }

  function showConfirmation(options) {
    if (!confirm || !confirmText || !confirmTitle || !confirmMark) return;

    confirm.classList.remove("reserva__confirm--warning");
    if (options.warning) confirm.classList.add("reserva__confirm--warning");

    confirmMark.textContent = options.warning ? "!" : "✓";
    confirmTitle.textContent = options.title;
    confirmText.innerHTML = options.text;

    form.style.display = "none";
    confirm.classList.add("show");
    if (window.ScrollTrigger) ScrollTrigger.refresh();
  }

  form.addEventListener("submit", function(e) {
    e.preventDefault();
    var nombre = form.nombre.value.trim();
    var email  = form.email.value.trim();
    var fecha  = $("#fechaHidden").value;
    var hora   = $("#horaHidden").value;

    if (!nombre || !email || !fecha || !hora) {
      showInlineMessage("Completa nombre, correo, fecha y hora.");
      return;
    }

    msg.classList.remove("show");

    var data = new FormData();
    data.append("nombre", nombre);
    data.append("email", email);
    data.append("fecha", fecha);
    data.append("hora", hora);
    data.append("comensales", guests);
    data.append("nota", form.nota ? form.nota.value.trim() : "");

    fetch("/reservar", { method: "POST", body: data })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.ok) {
          showInlineMessage(res.msg || "No pudimos registrar tu reservación. Intenta nuevamente.");
          return;
        }

        var fd  = new Date(fecha + "T00:00:00");
        var fmt = fd.toLocaleDateString("es-MX", { weekday: "long", day: "numeric", month: "long" });

        if (res.requiere_confirmacion === true || res.warning) {
          showConfirmation({
            warning: true,
            title: "Solicitud recibida",
            text: (res.warning || "Solicitud recibida. Confirmaremos la disponibilidad de mesa para este horario.") +
              "<br>Te contactaremos si necesitamos ajustar algún detalle."
          });
          return;
        }

        showConfirmation({
          warning: false,
          title: "¡Mesa reservada!",
          text: 'Gracias, <b style="color:var(--accent);font-weight:400">' + nombre + '</b>. Mesa para <b style="color:var(--accent);font-weight:400">' + guests + '</b> el <b style="color:var(--accent);font-weight:400">' + fmt + '</b> a las <b style="color:var(--accent);font-weight:400">' + hora + '</b>. Te esperamos.'
        });
      })
      .catch(function() {
        showInlineMessage("Error de conexión. Intenta de nuevo.");
      });
  });
}
