/* Encuesta de salida del comensal (/feedback).
   ------------------------------------------------------------------
   Estaba escrita a mano dentro de views/feedback/index.php, y por vivir ahí no
   podía usar nada de la casa: reimplementaba los avisos con un <p> propio y
   llevaba su PROPIA copia de la tabla de colores y de los cinco `path` de las
   caras —una tercera, después de la del PHP y la del SCSS, y las tres con
   valores distintos—.

   Aquí dentro del bundle tiene a mano AppNotice y GSAP, y el resumen CLONA el
   nodo de la cara original en vez de volver a construirla. Los dibujos existen
   en un solo sitio: renderEscala(), en la vista.

   Sigue siendo ES5 en scope global, como el resto de src/js. */
(function () {
  "use strict";

  var CAMPOS = [
    "calidad_sabor",
    "atencion_mesero",
    "tiempo_espera",
    "experiencia_global",
  ];
  var ETIQUETAS = {
    calidad_sabor: "Calidad y sabor",
    atencion_mesero: "Atención del personal",
    tiempo_espera: "Tiempo de espera",
    experiencia_global: "Experiencia global",
  };
  var TOTAL = 6; // 4 escalas + comentario libre + resumen

  var form = document.getElementById("fb-form");
  var pasos = document.querySelectorAll(".fb-step");
  if (!form || !pasos.length) return;

  var wrap = document.getElementById("fb-form-wrap");
  var exito = document.getElementById("fb-success");
  var btnPrev = document.getElementById("fb-prev");
  var btnNext = document.getElementById("fb-next");
  var btnEnviar = document.getElementById("fb-submit");
  var etiquetaEnviar = document.getElementById("fb-submit-label");
  var relleno = document.getElementById("fb-progress-fill");
  var contador = document.getElementById("fb-progress-label");
  var barra = document.querySelector(".fb-progress__bar");
  var resumen = document.getElementById("fb-resumen");

  var actual = 0;
  var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Un aviso siempre lleva texto de respaldo: un `mensaje` vacío del servidor no
     debe producir una caja en blanco (CLAUDE.md). */
  function aviso(texto, variante) {
    var msg = (texto || "").trim() || "Ocurrió un error. Intenta de nuevo.";
    if (window.AppNotice && window.AppNotice.show) {
      window.AppNotice.show({ text: msg, variant: variante || "error" });
    }
  }

  // ── Pasos ───────────────────────────────────────────────────
  //
  // La visibilidad va por CLASE y no por style.display en línea: así el paso
  // puede animarse y el CSS sigue mandando sobre la presentación.
  function mostrarPaso(indice) {
    if (indice === TOTAL - 1) construirResumen();

    var entra = pasos[indice];
    Array.prototype.forEach.call(pasos, function (paso, i) {
      paso.classList.toggle("fb-step--active", i === indice);
    });
    actual = indice;
    pintarUI();

    // GSAP es opcional: sin él —o con movimiento reducido— el paso aparece en
    // seco, que es exactamente lo que hacía antes.
    if (!reduce && window.gsap && entra) {
      window.gsap.fromTo(
        entra,
        { opacity: 0, y: 18 },
        { opacity: 1, y: 0, duration: 0.45, ease: "power3.out", clearProps: "transform" }
      );
    }
  }

  function pintarUI() {
    var pct = (actual / (TOTAL - 1)) * 100;
    if (relleno) relleno.style.width = pct + "%";
    if (contador) contador.textContent = actual + 1 + " de " + TOTAL;
    if (barra) barra.setAttribute("aria-valuenow", String(actual + 1));

    var esUltimo = actual === TOTAL - 1;
    btnPrev.hidden = actual === 0;
    btnNext.hidden = esUltimo;
    btnEnviar.hidden = !esUltimo;
  }

  function valorDe(campo) {
    var input = document.getElementById("fb-val-" + campo);
    return input ? parseInt(input.value, 10) : 0;
  }

  function pasoValido() {
    // Los pasos 5 y 6 —comentario y resumen— no exigen nada.
    if (actual >= CAMPOS.length) return true;
    return !!valorDe(CAMPOS[actual]);
  }

  /* Marca la cara elegida dentro de una escala y sincroniza el input oculto.
     Sirve para las escalas de los pasos y para las del resumen, que son el
     mismo marcado clonado. */
  function elegir(escala, valor) {
    var campo = escala.dataset.campo;
    Array.prototype.forEach.call(escala.querySelectorAll(".fb-face"), function (f) {
      var activa = parseInt(f.dataset.valor, 10) === valor;
      f.classList.toggle("fb-face--active", activa);
      f.setAttribute("aria-pressed", activa ? "true" : "false");
    });

    var input = document.getElementById("fb-val-" + campo);
    if (input) input.value = valor;

    /* La barra de progreso se tiñe con el color de la cara recién elegida. El
       valor NO se escribe aquí como hex: se copia el color ya calculado de la
       propia cara, así que sigue saliendo de --c-* y no hay una segunda tabla
       de colores en el JS que se pueda desincronizar del SCSS. */
    var elegida = escala.querySelector(".fb-face--active");
    if (elegida && document.documentElement) {
      document.documentElement.style.setProperty(
        "--fb-tono",
        getComputedStyle(elegida).getPropertyValue("--cara").trim()
      );
    }

    // La otra copia de la misma escala (paso ↔ resumen) tiene que seguirla.
    Array.prototype.forEach.call(
      document.querySelectorAll('.fb-escala[data-campo="' + campo + '"]'),
      function (otra) {
        if (otra === escala) return;
        Array.prototype.forEach.call(otra.querySelectorAll(".fb-face"), function (f) {
          var activa = parseInt(f.dataset.valor, 10) === valor;
          f.classList.toggle("fb-face--active", activa);
          f.setAttribute("aria-pressed", activa ? "true" : "false");
        });
      }
    );
  }

  // ── Resumen ─────────────────────────────────────────────────
  //
  // CLONA las escalas de los pasos en vez de reconstruirlas. Antes el JS tenía
  // su propia tabla de colores y sus propios `path`, y bastaba con tocar el PHP
  // para que el resumen dibujara otra cosa.
  function construirResumen() {
    if (!resumen) return;
    resumen.textContent = "";

    CAMPOS.forEach(function (campo) {
      var original = document.querySelector('.fb-escala[data-campo="' + campo + '"]');
      if (!original) return;

      var fila = document.createElement("div");
      fila.className = "fb-resumen-row";

      var titulo = document.createElement("span");
      titulo.className = "fb-resumen-row__label";
      titulo.textContent = ETIQUETAS[campo];

      var copia = original.cloneNode(true);
      copia.classList.add("fb-escala--resumen");
      // El input oculto es único por campo: el clon no debe traerse un duplicado
      // con el mismo id, o document.getElementById devolvería el equivocado.
      var duplicado = copia.querySelector('input[type="hidden"]');
      if (duplicado) duplicado.remove();

      fila.appendChild(titulo);
      fila.appendChild(copia);
      resumen.appendChild(fila);
    });

    var comentario = (document.getElementById("fb-comentario") || {}).value || "";
    if (comentario.trim()) {
      var caja = document.createElement("div");
      caja.className = "fb-resumen-comment";

      var rotulo = document.createElement("span");
      rotulo.className = "fb-resumen-comment__label";
      rotulo.textContent = "Tu comentario";

      var texto = document.createElement("p");
      texto.className = "fb-resumen-comment__text";
      // textContent y no innerHTML: lo escribió el comensal.
      texto.textContent = comentario.trim();

      caja.appendChild(rotulo);
      caja.appendChild(texto);
      resumen.appendChild(caja);
    }
  }

  // ── Eventos ─────────────────────────────────────────────────
  //
  // Delegado en el formulario y en el resumen: las escalas del resumen se crean
  // después, así que engancharlas una a una obligaba a re-registrar oyentes.
  function alPulsarCara(evento) {
    var cara = evento.target.closest(".fb-face");
    if (!cara) return;
    var escala = cara.closest(".fb-escala");
    if (!escala) return;

    elegir(escala, parseInt(cara.dataset.valor, 10));

    // Autoavance sólo desde los pasos de escala, nunca desde el resumen: ahí el
    // comensal está corrigiendo y saltar de pantalla sería quitarle el control.
    if (!escala.classList.contains("fb-escala--resumen") && actual < CAMPOS.length) {
      window.setTimeout(function () {
        if (actual < TOTAL - 1) mostrarPaso(actual + 1);
      }, 220);
    }
  }

  form.addEventListener("click", alPulsarCara);
  if (resumen) resumen.addEventListener("click", alPulsarCara);

  btnNext.addEventListener("click", function () {
    if (!pasoValido()) {
      aviso("Selecciona una opción para continuar.", "warning");
      return;
    }
    if (actual < TOTAL - 1) mostrarPaso(actual + 1);
  });

  btnPrev.addEventListener("click", function () {
    if (actual > 0) mostrarPaso(actual - 1);
  });

  form.addEventListener("submit", function (evento) {
    evento.preventDefault();

    var datos = { token: document.getElementById("fb-token").value };
    var completo = true;

    CAMPOS.forEach(function (campo) {
      var valor = valorDe(campo);
      if (!valor || valor < 1 || valor > 5) completo = false;
      datos[campo] = valor;
    });

    if (!completo) {
      aviso("Por favor responde las cuatro preguntas.", "warning");
      return;
    }

    datos.comentario = (document.getElementById("fb-comentario") || {}).value || "";
    btnEnviar.disabled = true;
    etiquetaEnviar.textContent = "Enviando…";

    fetch("/api/feedback", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(datos),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (res && res.ok) {
          if (wrap) wrap.hidden = true;
          if (exito) exito.hidden = false;
          window.scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" });
          return;
        }
        aviso(res && res.msg);
        btnEnviar.disabled = false;
        etiquetaEnviar.textContent = "Enviar reseña";
      })
      .catch(function () {
        aviso("Error de conexión. Revisa la señal e intenta de nuevo.");
        btnEnviar.disabled = false;
        etiquetaEnviar.textContent = "Enviar reseña";
      });
  });

  mostrarPaso(0);
})();
