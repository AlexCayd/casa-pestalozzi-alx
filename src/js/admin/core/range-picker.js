/**
 * Selector de periodo con calendario de rango (estilo aerolínea/hotel).
 *
 * Se construyó aparte del `createReservationDatePicker` compartido porque aquel
 * es de fecha única y su contrato de markup lo usa el módulo de reservaciones:
 * convertirlo a rango obligaba a tocar esa pantalla sin necesidad.
 *
 * Comportamiento: el primer clic fija el inicio, al mover el cursor se
 * previsualiza el tramo, el segundo clic fija el fin. Al reabrir con un rango
 * ya elegido se ven ambos extremos y los días intermedios resaltados.
 *
 * El servidor sigue filtrando: al aplicar se recarga con ?rango=N o
 * ?desde&hasta (más ?comparar=1), que es lo que Services\RangoPeriodo valida.
 *
 * Vive en admin/core porque lo usan analíticas, finanzas e inventario; viaja en
 * admin.js, que todas las pantallas del panel cargan.
 */
(function () {
  var MESES = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio",
               "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
  var MESES_CORTOS = ["ene", "feb", "mar", "abr", "may", "jun",
                      "jul", "ago", "sep", "oct", "nov", "dic"];

  function pad(n) { return n < 10 ? "0" + n : "" + n; }

  function parseISO(value) {
    var m = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return null;
    var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    d.setHours(0, 0, 0, 0);
    return isNaN(d.getTime()) ? null : d;
  }

  function toISO(d) {
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  }

  function bonita(d) {
    return d.getDate() + " " + MESES_CORTOS[d.getMonth()];
  }

  function mismoDia(a, b) {
    return !!a && !!b && a.getTime() === b.getTime();
  }

  function initRangePicker(root) {
    var trigger = root.querySelector("[data-range-trigger]");
    var pop = root.querySelector("[data-range-pop]");
    var gridA = root.querySelector("[data-range-grid-a]");
    var gridB = root.querySelector("[data-range-grid-b]");
    var titleA = root.querySelector("[data-range-title-a]");
    var titleB = root.querySelector("[data-range-title-b]");
    var prevBtn = root.querySelector("[data-range-prev]");
    var nextBtn = root.querySelector("[data-range-next]");
    var applyBtn = root.querySelector("[data-range-apply]");
    var cancelBtn = root.querySelector("[data-range-cancel]");
    var summary = root.querySelector("[data-range-summary]");

    if (!trigger || !pop || !gridA || !gridB) return;

    var hoy = parseISO(root.getAttribute("data-today")) || new Date();
    hoy.setHours(0, 0, 0, 0);
    var allowFuture = root.getAttribute("data-allow-future") === "1";
    var preserveQuery = root.getAttribute("data-preserve-query") === "1";
    var startParam = root.getAttribute("data-start-param") || "desde";
    var endParam = root.getAttribute("data-end-param") || "hasta";

    var inicioGuardado = parseISO(root.getAttribute("data-start"));
    var finGuardado = parseISO(root.getAttribute("data-end"));

    var inicio = inicioGuardado;
    var fin = finGuardado;
    var hover = null;
    // Tras fijar los dos extremos, el siguiente clic empieza un rango nuevo.
    var eligiendo = false;

    // Índice iso -> <button>. Permite repintar el tramo alternando clases en
    // vez de reconstruir las celdas: al recrear el nodo bajo el cursor se
    // perdía el hover y la previsualización parpadeaba.
    var celdas = {};
    var focoIso = null;
    var hoverIso = null;

    /**
     * El panel IZQUIERDO muestra el mes del INICIO del rango, no el del fin.
     * Anclando en el fin, "Últimos 30 días" (28 jun – 27 jul) enseñaba julio y
     * agosto: el inicio quedaba fuera de pantalla y el panel derecho era un mes
     * futuro entero deshabilitado. Si el inicio ya cae en el mes actual, se
     * retrocede uno para mostrar mes anterior + mes actual.
     */
    function anclaPara(desde) {
      var base = desde || hoy;
      var a = new Date(base.getFullYear(), base.getMonth(), 1);
      if (allowFuture) return a;
      var mesHoy = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
      if (a.getTime() >= mesHoy.getTime()) {
        a = new Date(mesHoy.getFullYear(), mesHoy.getMonth() - 1, 1);
      }
      return a;
    }

    var ancla = anclaPara(inicioGuardado);

    function navegar(url) {
      window.location.assign(url.toString());
    }

    /**
     * El interruptor de comparación viaja en cada navegación: el rango y el
     * "comparar con el periodo anterior" son una sola decisión del usuario y
     * perder uno al cambiar el otro obliga a volver a marcarlo.
     */
    function comparaActiva() {
      var check = root.querySelector("[data-range-compare]");
      if (check) return check.checked;
      return root.getAttribute("data-compare") === "1";
    }

    function irA(params) {
      var url = new URL(window.location.href);
      if (!preserveQuery) {
        url.search = "";
      } else {
        ["rango", "desde", "hasta", "comparar", startParam, endParam].forEach(function (param) {
          url.searchParams.delete(param);
        });
      }
      Object.keys(params).forEach(function (k) {
        if (params[k] !== "" && params[k] != null) url.searchParams.set(k, params[k]);
      });
      if (comparaActiva()) url.searchParams.set("comparar", "1");
      navegar(url);
    }

    /** Extremos ordenados, contando la previsualización del cursor. */
    function tramo() {
      var a = inicio;
      var b = fin || (eligiendo ? hover : null);
      if (!a) return [null, null];
      if (!b) return [a, a];
      return a <= b ? [a, b] : [b, a];
    }

    /**
     * Crea las celdas de un mes UNA vez. Solo fija lo que no depende del rango
     * (hueco, futuro deshabilitado, hoy) y las registra en `celdas`. El estado
     * del tramo lo aplica pintarEstados() alternando clases.
     */
    function construirMes(grid, anio, mes) {
      grid.innerHTML = "";

      var primero = new Date(anio, mes, 1).getDay();
      var dias = new Date(anio, mes + 1, 0).getDate();

      for (var i = 0; i < primero; i++) {
        var hueco = document.createElement("span");
        hueco.className = "admin-range__day is-empty";
        grid.appendChild(hueco);
      }

      for (var d = 1; d <= dias; d++) {
        var fecha = new Date(anio, mes, d);
        fecha.setHours(0, 0, 0, 0);
        var iso = toISO(fecha);

        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "admin-range__day";
        btn.textContent = d;
        btn.setAttribute("data-iso", iso);
        btn.tabIndex = -1;
        // Cacheado: pintarEstados compara números, no objetos Date.
        btn._t = fecha.getTime();

        if (!allowFuture && fecha > hoy) {
          btn.classList.add("is-disabled");
          btn.disabled = true;
        }
        if (mismoDia(fecha, hoy)) btn.classList.add("is-today");

        celdas[iso] = btn;
        grid.appendChild(btn);
      }
    }

    /** Reconstruye ambas rejillas. Solo al abrir o al cambiar de mes. */
    function montarMeses() {
      celdas = {};
      var anioA = ancla.getFullYear();
      var mesA = ancla.getMonth();
      var siguiente = new Date(anioA, mesA + 1, 1);

      if (titleA) titleA.textContent = MESES[mesA] + " " + anioA;
      if (titleB) titleB.textContent = MESES[siguiente.getMonth()] + " " + siguiente.getFullYear();

      construirMes(gridA, anioA, mesA);
      construirMes(gridB, siguiente.getFullYear(), siguiente.getMonth());

      if (nextBtn && !allowFuture) {
        nextBtn.disabled = siguiente.getFullYear() > hoy.getFullYear() ||
          (siguiente.getFullYear() === hoy.getFullYear() && siguiente.getMonth() >= hoy.getMonth());
      }
    }

    /** Solo alterna clases de tramo sobre los botones que ya existen. */
    function pintarEstados() {
      var e = tramo();
      var tIni = e[0] ? e[0].getTime() : null;
      var tFin = e[1] ? e[1].getTime() : null;

      for (var iso in celdas) {
        if (!celdas.hasOwnProperty(iso)) continue;
        var btn = celdas[iso];
        var t = btn._t;

        var esIni = tIni !== null && t === tIni;
        var esFin = tFin !== null && t === tFin;
        var dentro = tIni !== null && tFin !== null && t > tIni && t < tFin;

        btn.classList.toggle("is-inside", dentro);
        btn.classList.toggle("is-edge", esIni || esFin);
        btn.classList.toggle("is-start", esIni);
        btn.classList.toggle("is-end", esFin);
        btn.classList.toggle("is-single", esIni && esFin);
        btn.setAttribute("aria-selected", esIni || esFin ? "true" : "false");
      }
    }

    function pintarResumen() {
      if (summary) {
        var e = tramo();
        if (!e[0]) {
          summary.textContent = "Elige la fecha de inicio";
        } else if (eligiendo && !fin) {
          summary.textContent = "Desde " + bonita(e[0]) + " — elige la fecha final";
        } else {
          var dias = Math.round((e[1] - e[0]) / 86400000) + 1;
          summary.textContent = bonita(e[0]) + " – " + bonita(e[1]) + " · " + dias + " días";
        }
      }

      if (applyBtn) applyBtn.disabled = !inicio || !fin;
    }

    function render() {
      pintarEstados();
      pintarResumen();
    }

    function irAMes(delta) {
      ancla = new Date(ancla.getFullYear(), ancla.getMonth() + delta, 1);
      montarMeses();
      render();
    }

    // ── Interacción con la rejilla ──────────────────────────────────────
    function celdaDe(target) {
      var el = target && target.closest ? target.closest(".admin-range__day[data-iso]") : null;
      return el && !el.disabled ? el : null;
    }

    function elegir(fecha) {
      if (!inicio || !eligiendo) {
        inicio = fecha;
        fin = null;
        eligiendo = true;
      } else {
        fin = fecha;
        eligiendo = false;
        if (inicio > fin) { var t = inicio; inicio = fin; fin = t; }
      }
      hover = null;
      hoverIso = null;
      render();
    }

    /** Roving tabindex: solo un día es tabulable a la vez. */
    function enfocar(iso, mover) {
      var prev = focoIso && celdas[focoIso];
      if (prev) prev.tabIndex = -1;
      focoIso = iso;
      var btn = celdas[iso];
      if (!btn) return;
      btn.tabIndex = 0;
      if (mover !== false) btn.focus();
    }

    function desplazar(dias) {
      var base = focoIso ? parseISO(focoIso) : (inicio || hoy);
      var destino = new Date(base.getFullYear(), base.getMonth(), base.getDate() + dias);
      destino.setHours(0, 0, 0, 0);
      if (!allowFuture && destino > hoy) destino = new Date(hoy.getTime());

      var iso = toISO(destino);
      if (!celdas[iso]) {
        // Salió de los dos meses montados: se remonta alrededor del destino.
        ancla = anclaPara(destino);
        montarMeses();
      }
      if (eligiendo && inicio && !fin) { hover = destino; hoverIso = iso; }
      render();
      enfocar(iso);
    }

    // mouseover BURBUJEA (mouseenter no), así que basta un listener por rejilla.
    function onGridOver(ev) {
      if (!eligiendo || !inicio || fin) return;
      var btn = celdaDe(ev.target);
      if (!btn) return;
      var iso = btn.getAttribute("data-iso");
      if (iso === hoverIso) return;   // corta el ruido del mousemove
      hoverIso = iso;
      hover = parseISO(iso);
      render();
    }

    function onGridLeave() {
      if (!eligiendo || fin || hover === null) return;
      hover = null;
      hoverIso = null;
      render();
    }

    [gridA, gridB].forEach(function (g) {
      g.addEventListener("click", function (ev) {
        var btn = celdaDe(ev.target);
        if (!btn) return;
        elegir(parseISO(btn.getAttribute("data-iso")));
        enfocar(btn.getAttribute("data-iso"));
      });
      g.addEventListener("mouseover", onGridOver);
      g.addEventListener("mouseleave", onGridLeave);
    });

    pop.addEventListener("keydown", function (ev) {
      var k = ev.key;

      if (k === "Escape") { ev.stopPropagation(); cerrar(); return; }

      if (k === "Enter" || k === " " || k === "Spacebar") {
        if (!focoIso || !celdas[focoIso] || celdas[focoIso].disabled) return;
        ev.preventDefault();
        elegir(parseISO(focoIso));
        enfocar(focoIso);
        return;
      }

      var saltos = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, PageUp: -30, PageDown: 30 };
      if (saltos[k] === undefined) return;
      ev.preventDefault();
      desplazar(saltos[k]);
    });

    /**
     * El popover va en position:absolute; si se sale de la ventana se voltea
     * hacia arriba o hacia la izquierda antes de que el usuario lo vea.
     */
    function ajustarPosicion() {
      pop.classList.remove("admin-range__pop--arriba", "admin-range__pop--izq");
      var r = pop.getBoundingClientRect();
      if (r.bottom > window.innerHeight - 8 && r.top > r.height + 16) {
        pop.classList.add("admin-range__pop--arriba");
      }
      r = pop.getBoundingClientRect();
      if (r.left < 8) pop.classList.add("admin-range__pop--izq");
    }

    function abrir() {
      inicio = inicioGuardado;
      fin = finGuardado;
      eligiendo = false;
      hover = null;
      hoverIso = null;
      ancla = anclaPara(inicio);

      pop.hidden = false;
      trigger.setAttribute("aria-expanded", "true");

      montarMeses();
      render();
      ajustarPosicion();

      var isoFoco = toISO(inicio || hoy);
      enfocar(celdas[isoFoco] ? isoFoco : toISO(hoy), false);
    }

    function cerrar() {
      pop.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
      trigger.focus();
    }

    trigger.addEventListener("click", function (event) {
      event.stopPropagation();
      if (pop.hidden) abrir(); else cerrar();
    });

    if (prevBtn) prevBtn.addEventListener("click", function () { irAMes(-1); });
    if (nextBtn) nextBtn.addEventListener("click", function () { irAMes(1); });

    if (cancelBtn) cancelBtn.addEventListener("click", cerrar);

    if (applyBtn) {
      applyBtn.addEventListener("click", function () {
        if (!inicio || !fin) return;
        var params = {};
        params[startParam] = toISO(inicio);
        params[endParam] = toISO(fin);
        irA(params);
      });
    }

    root.querySelectorAll("[data-range-preset]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var val = btn.getAttribute("data-range-preset");
        if (val === "custom") {
          // Ya se está viendo el calendario; solo se marca el modo.
          root.querySelectorAll("[data-range-preset]").forEach(function (b) {
            b.classList.toggle("is-active", b === btn);
          });
          return;
        }
        irA({ rango: val });
      });
    });

    // Marcar/desmarcar la comparación recarga de inmediato: es un cambio de
    // datos, no un ajuste que espere al botón Aplicar.
    var compareCheck = root.querySelector("[data-range-compare]");
    if (compareCheck) {
      compareCheck.addEventListener("change", function () {
        var preset = parseInt(root.getAttribute("data-preset"), 10);
        irA(preset > 0
          ? { rango: preset }
          : (function () {
            var params = {};
            params[startParam] = root.getAttribute("data-start");
            params[endParam] = root.getAttribute("data-end");
            return params;
          }()));
      });
    }

    document.addEventListener("click", function (event) {
      if (!pop.hidden && !root.contains(event.target)) cerrar();
    });

    // El resumen del disparador se pinta sin montar el calendario: las celdas
    // se construyen la primera vez que se abre.
    pintarResumen();
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-analytics-range-picker]").forEach(initRangePicker);
  });
})();
