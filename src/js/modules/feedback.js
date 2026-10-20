/* Encuesta de salida del comensal (/feedback).
   ------------------------------------------------------------------
   La vista (views/feedback/index.php) ya es un formulario completo que funciona
   sin este archivo: radios reales, `required` y envío nativo a /api/feedback.
   Lo que hace esto es convertirlo en una encuesta de pregunta en pregunta:

   · el paso activo y la barra de avance (la clase `fb-js` que pone el guardia
     de la vista oculta los demás; aquí se dice `fb-listo` al terminar de
     arrancar, y si no llega a decirse la vista devuelve el formulario seguido);
   · el autoavance al tocar una cara, sólo con el dedo o el ratón: con las
     flechas del teclado se está recorriendo el grupo, y saltar de pregunta a
     cada flecha sería quitarle el control;
   · el resumen, que CLONA las escalas (los dibujos existen en un solo sitio);
   · el envío por fetch, con su estado de carga, su error junto al botón y la
     tarjeta final.

   Sin GSAP: el cambio de paso lo anima la Web Animations API, que viene con el
   navegador, y todo lo demás —las caras, el pop, el sello final— es CSS. La
   vista ya no carga la librería.

   Los avisos de validación y de red van EN la tarjeta, junto a lo que los
   provoca, y no en AppNotice: en una tablet que sostiene el comensal, un aviso
   en la esquina superior derecha queda fuera de donde está mirando.

   ES5 en scope global, como el resto de src/js. */
(function () {
  "use strict";

  var form = document.getElementById("fb-form");
  if (!form) return;
  var pasos = form.querySelectorAll(".fb-step");
  var btnPrev = document.getElementById("fb-prev");
  var btnNext = document.getElementById("fb-next");
  var btnEnviar = document.getElementById("fb-submit");
  if (!pasos.length || !btnPrev || !btnNext || !btnEnviar) return;

  var raiz = document.documentElement;
  var tarjeta = document.getElementById("fb-form-wrap");
  var exito = document.getElementById("fb-success");
  var etiquetaNext = document.getElementById("fb-next-label");
  var etiquetaEnviar = document.getElementById("fb-submit-label");
  var barra = document.getElementById("fb-progress");
  var cuenta = document.getElementById("fb-progress-label");
  var segmentos = barra ? barra.querySelectorAll(".fb-progreso__seg") : [];
  var resumen = document.getElementById("fb-resumen");
  var comentario = document.getElementById("fb-comentario");
  var tokenInput = document.getElementById("fb-token");
  var errorEnvio = document.getElementById("fb-error");
  var errorTexto = document.getElementById("fb-error-texto");

  // Los campos y sus nombres cortos salen del marcado (data-campo /
  // data-nombre): la vista es la única que los escribe.
  var CAMPOS = [];
  var NOMBRES = {};
  cada(form.querySelectorAll(".fb-escala"), function (escala) {
    var campo = escala.getAttribute("data-campo");
    CAMPOS.push(campo);
    NOMBRES[campo] = escala.getAttribute("data-nombre") || campo;
  });

  var TOTAL = pasos.length;
  var PASO_COMENTARIO = CAMPOS.length;
  var PASO_RESUMEN = TOTAL - 1;

  var TEXTO_FALTA = "Elige una cara para seguir.";
  var TEXTO_FALLO = "No pudimos guardar tu reseña. Inténtalo de nuevo, por favor.";
  var TEXTO_RED = "No hay conexión con la casa. Revisa la señal e inténtalo de nuevo.";

  var actual = 0;
  var avance = null;
  var porPuntero = false;
  var enviando = false;

  // Se consulta en cada uso y no una vez al cargar: la preferencia puede
  // cambiar con la página abierta.
  var consultaReducida = window.matchMedia
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : null;

  function reducido() {
    return !!(consultaReducida && consultaReducida.matches);
  }

  function cada(lista, fn) {
    Array.prototype.forEach.call(lista, fn);
  }

  function enfocar(nodo) {
    if (!nodo) return;
    try {
      nodo.focus({ preventScroll: true });
    } catch (e) {
      nodo.focus();
    }
  }

  // ── Lectura de respuestas ───────────────────────────────────
  //
  // Las copias del resumen se renombran («resumen-<campo>»), así que el
  // `name` del campo sólo lo llevan los radios de los pasos: son la fuente.
  function marcadoDe(campo) {
    return form.querySelector('input[name="' + campo + '"]:checked');
  }

  function valorDe(campo) {
    var marcado = marcadoDe(campo);
    return marcado ? parseInt(marcado.value, 10) : 0;
  }

  function etiquetaDe(campo) {
    var marcado = marcadoDe(campo);
    var rotulo = marcado && marcado.parentNode.querySelector(".fb-face__label");
    return rotulo ? rotulo.textContent : "";
  }

  function primerPendiente() {
    for (var i = 0; i < CAMPOS.length; i++) {
      if (!valorDe(CAMPOS[i])) return i;
    }
    return -1;
  }

  // ── Avisos en su sitio ──────────────────────────────────────
  function aviso(paso, texto) {
    var nodo = paso && paso.querySelector(".fb-step__aviso");
    if (!nodo) return;
    var span = nodo.querySelector(".fb-alerta__texto");
    if (span) span.textContent = texto || "";
    nodo.classList.toggle("is-visible", !!texto);
  }

  function mostrarError(texto) {
    if (!errorEnvio) return;
    if (errorTexto) errorTexto.textContent = texto || TEXTO_FALLO;
    errorEnvio.classList.add("is-visible");
  }

  function ocultarError() {
    if (!errorEnvio) return;
    errorEnvio.classList.remove("is-visible");
    if (errorTexto) errorTexto.textContent = "";
  }

  /* La escala tiembla al pedir una respuesta que falta: dice «aquí» sin otro
     color que el del mensaje. Con movimiento reducido basta el mensaje. */
  function sacudir(nodo) {
    if (!nodo || reducido() || typeof nodo.animate !== "function") return;
    nodo.animate(
      [
        { transform: "translateX(0)" },
        { transform: "translateX(-7px)" },
        { transform: "translateX(6px)" },
        { transform: "translateX(-3px)" },
        { transform: "translateX(0)" },
      ],
      { duration: 340, easing: "ease-out" }
    );
  }

  function faltaRespuesta(indice) {
    var paso = pasos[indice];
    if (!paso) return;
    aviso(paso, TEXTO_FALTA);
    var escala = paso.querySelector(".fb-escala");
    sacudir(escala);
    // El foco entra al grupo: quien va con teclado o lector de pantalla queda
    // donde tiene que responder, y el aviso le llega por aria-describedby.
    enfocar(escala && escala.querySelector(".fb-face__input"));
  }

  // ── Pasos ───────────────────────────────────────────────────
  function rotularSiguiente() {
    if (!etiquetaNext) return;
    // En el comentario, que es opcional, el botón dice lo que va a pasar: con
    // la caja vacía no se «sigue», se omite.
    var vacio = !comentario || !comentario.value.replace(/^\s+|\s+$/g, "");
    etiquetaNext.textContent = actual === PASO_COMENTARIO && vacio ? "Omitir" : "Siguiente";
  }

  function pintarUI() {
    // La visibilidad de Anterior / Siguiente / Enviar la decide el CSS por
    // data-posicion: nada de alternar [hidden] desde aquí. Posición y no
    // número de paso para que el CSS no tenga que saber cuántos hay.
    form.setAttribute("data-paso", String(actual));
    form.setAttribute(
      "data-posicion",
      actual === 0 ? "primero" : actual === TOTAL - 1 ? "ultimo" : "medio"
    );
    if (cuenta) cuenta.textContent = actual + 1 + " de " + TOTAL;
    if (barra) {
      barra.setAttribute("aria-valuenow", String(actual + 1));
      barra.setAttribute("aria-valuetext", "Paso " + (actual + 1) + " de " + TOTAL);
    }
    // Un segmento respondido toma el color de su cara (data-valor → CSS): la
    // barra dice cuánto falta y también cómo va yendo la visita.
    cada(segmentos, function (seg, i) {
      var valor = i < CAMPOS.length ? valorDe(CAMPOS[i]) : 0;
      seg.classList.toggle("is-hecho", i < actual);
      seg.classList.toggle("is-actual", i === actual);
      if (valor) seg.setAttribute("data-valor", String(valor));
      else seg.removeAttribute("data-valor");
    });
    rotularSiguiente();
  }

  /* Entra desde el lado hacia el que se avanza: adelante desde la derecha,
     atrás desde la izquierda. Sin `fill`, al terminar el paso vuelve a lo que
     diga el CSS. Con movimiento reducido, sólo fundido: reducido no es
     inmóvil, el cambio de pregunta se tiene que notar. */
  function animarEntrada(paso, direccion) {
    if (!paso || typeof paso.animate !== "function") return;
    if (reducido()) {
      paso.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160, easing: "linear" });
      return;
    }
    paso.animate(
      [
        { opacity: 0, transform: "translateX(" + direccion * 20 + "px)" },
        { opacity: 1, transform: "translateX(0)" },
      ],
      { duration: 280, easing: "cubic-bezier(0.2, 0.7, 0.2, 1)" }
    );
  }

  function enfocarTitulo(paso) {
    // El foco va al titular para que el lector de pantalla anuncie la pregunta
    // nueva; es tabindex="-1" y no pinta anillo.
    enfocar(paso.querySelector(".fb-step__titulo"));
    // En un teléfono, al volver del resumen —que es largo— la tarjeta puede
    // quedar por encima del borde.
    if (tarjeta && tarjeta.getBoundingClientRect().top < 0) {
      tarjeta.scrollIntoView({ block: "start", behavior: reducido() ? "auto" : "smooth" });
    }
  }

  function cancelarAvance() {
    if (avance) window.clearTimeout(avance);
    avance = null;
  }

  function mostrarPaso(indice, opciones) {
    opciones = opciones || {};
    if (indice < 0 || indice >= TOTAL) return;
    cancelarAvance();
    if (indice === PASO_RESUMEN) construirResumen();

    var direccion = indice >= actual ? 1 : -1;
    aviso(pasos[actual], "");
    cada(pasos, function (paso, i) {
      paso.classList.toggle("fb-step--active", i === indice);
    });
    actual = indice;
    pintarUI();

    if (opciones.animar !== false) animarEntrada(pasos[indice], direccion);
    if (opciones.enfocar !== false) enfocarTitulo(pasos[indice]);
  }

  function avanzar() {
    if (actual < CAMPOS.length && !valorDe(CAMPOS[actual])) {
      faltaRespuesta(actual);
      return;
    }
    if (actual < TOTAL - 1) mostrarPaso(actual + 1);
  }

  /* El toque elige y, un instante después, pasa a la siguiente pregunta. El
     instante es el que dura el pop de la cara: si se avanzara en el mismo
     toque, el comensal no llegaría a ver qué marcó. */
  function programarAvance() {
    cancelarAvance();
    avance = window.setTimeout(function () {
      avance = null;
      if (actual < PASO_COMENTARIO && valorDe(CAMPOS[actual])) mostrarPaso(actual + 1);
    }, reducido() ? 240 : 480);
  }

  // ── Resumen ─────────────────────────────────────────────────
  //
  // CLONA las escalas de los pasos en vez de reconstruirlas: los dibujos viven
  // en un solo sitio, renderEscala(). Las copias se renombran para formar su
  // propio grupo —con el mismo `name` y dentro del mismo <form>, marcar una
  // copia DESMARCARÍA el original— y la otra copia se sincroniza a mano.
  function construirResumen() {
    if (!resumen) return;
    resumen.textContent = "";

    CAMPOS.forEach(function (campo) {
      var original = form.querySelector(
        '.fb-escala[data-campo="' + campo + '"]:not(.fb-escala--resumen)'
      );
      if (!original) return;

      var fila = document.createElement("div");
      fila.className = "fb-resumen__fila";

      var cabeza = document.createElement("div");
      cabeza.className = "fb-resumen__cabeza";

      var nombre = document.createElement("span");
      nombre.className = "fb-resumen__nombre";
      nombre.id = "fb-resumen-" + campo;
      nombre.textContent = NOMBRES[campo];

      var valor = document.createElement("span");
      valor.className = "fb-resumen__valor";
      valor.setAttribute("data-valor-de", campo);
      valor.textContent = etiquetaDe(campo) || "Sin responder";

      cabeza.appendChild(nombre);
      cabeza.appendChild(valor);

      var elegido = valorDe(campo);
      var copia = original.cloneNode(true);
      copia.classList.add("fb-escala--resumen");
      copia.setAttribute("aria-labelledby", nombre.id);
      copia.removeAttribute("aria-describedby");
      copia.removeAttribute("aria-required");
      // Se renombra ANTES de insertar: suelto, el clon no pertenece a ningún
      // formulario y su `checked` no toca al grupo original.
      cada(copia.querySelectorAll(".fb-face__input"), function (input) {
        input.name = "resumen-" + campo;
        input.required = false;
        input.checked = parseInt(input.value, 10) === elegido;
      });

      fila.appendChild(cabeza);
      fila.appendChild(copia);
      resumen.appendChild(fila);
    });

    // El comentario: hundido como un campo, con su acción a la derecha. Si no
    // hay, se dice —un hueco no distingue «no escribí» de «se perdió»— y se
    // ofrece añadirlo sin tener que ir hacia atrás paso a paso.
    var texto = comentario ? comentario.value.replace(/^\s+|\s+$/g, "") : "";
    var nota = document.createElement("div");
    nota.className = "fb-resumen__nota" + (texto ? "" : " fb-resumen__nota--vacia");

    var rotulo = document.createElement("span");
    rotulo.className = "fb-resumen__nombre";
    rotulo.textContent = "Tu comentario";

    var cuerpo = document.createElement("p");
    cuerpo.className = "fb-resumen__texto";
    // textContent y no innerHTML: lo escribió el comensal.
    cuerpo.textContent = texto || "Sin comentario.";

    var editar = document.createElement("button");
    editar.type = "button";
    editar.className = "fb-enlace";
    editar.textContent = texto ? "Editar" : "Añadir";
    editar.setAttribute("aria-label", texto ? "Editar tu comentario" : "Añadir un comentario");
    editar.addEventListener("click", function () {
      mostrarPaso(PASO_COMENTARIO);
    });

    nota.appendChild(rotulo);
    nota.appendChild(editar);
    nota.appendChild(cuerpo);
    resumen.appendChild(nota);
  }

  // ── Elegir una cara ─────────────────────────────────────────
  //
  // `change` y no `click`: es lo único que dispara igual el dedo, el ratón,
  // las flechas y la barra espaciadora. Qué lo disparó lo dice porPuntero, que
  // se fija en el pointerdown previo.
  function marcarPuntero(evento) {
    var objetivo = evento.target;
    porPuntero = !!(objetivo && objetivo.closest && objetivo.closest(".fb-face"));
  }

  if (window.PointerEvent) {
    form.addEventListener("pointerdown", marcarPuntero, true);
  } else {
    form.addEventListener("mousedown", marcarPuntero, true);
    form.addEventListener("touchstart", marcarPuntero, true);
  }
  // Safari de iOS sólo aplica :active a lo que tiene un oyente táctil: sin éste
  // la cara no se hundiría bajo el dedo.
  form.addEventListener("touchstart", function () {}, { passive: true });

  form.addEventListener("change", function (evento) {
    var input = evento.target;
    if (!input || input.type !== "radio" || !input.closest) return;
    var escala = input.closest(".fb-escala");
    if (!escala) return;

    var campo = escala.getAttribute("data-campo");
    var valor = parseInt(input.value, 10);
    var enResumen = escala.classList.contains("fb-escala--resumen");

    // La otra copia de la misma escala (paso ↔ resumen) la sigue. Asignar
    // `checked` no dispara `change`, así que no hay ida y vuelta.
    var gemela = form.querySelector(
      '.fb-escala[data-campo="' + campo + '"]' +
        (enResumen ? ":not(.fb-escala--resumen)" : ".fb-escala--resumen")
    );
    if (gemela) {
      cada(gemela.querySelectorAll(".fb-face__input"), function (otro) {
        otro.checked = parseInt(otro.value, 10) === valor;
      });
    }

    var rotuloValor = resumen && resumen.querySelector('[data-valor-de="' + campo + '"]');
    if (rotuloValor) rotuloValor.textContent = etiquetaDe(campo);

    var paso = escala.closest(".fb-step");
    aviso(paso, "");
    ocultarError();
    pintarUI();

    if (!enResumen && porPuntero && paso === pasos[actual] && actual < PASO_COMENTARIO) {
      programarAvance();
    }
    porPuntero = false;
  });

  form.addEventListener("keydown", function (evento) {
    porPuntero = false;
    // Enter sobre una cara es «listo, la siguiente»: la contraparte de teclado
    // del autoavance. Sin esto, según el navegador, Enter en un radio intenta
    // enviar el formulario entero desde la primera pregunta.
    if (evento.key !== "Enter") return;
    var objetivo = evento.target;
    if (!objetivo || objetivo.type !== "radio") return;
    evento.preventDefault();
    if (actual < PASO_RESUMEN) avanzar();
  });

  btnNext.addEventListener("click", avanzar);

  btnPrev.addEventListener("click", function () {
    if (actual > 0) mostrarPaso(actual - 1);
  });

  if (comentario) comentario.addEventListener("input", rotularSiguiente);

  // ── Envío ───────────────────────────────────────────────────
  function ponerEnviando(activo) {
    enviando = activo;
    btnEnviar.disabled = activo;
    btnPrev.disabled = activo;
    btnEnviar.classList.toggle("is-cargando", activo);
    if (activo) btnEnviar.setAttribute("aria-busy", "true");
    else btnEnviar.removeAttribute("aria-busy");
    form.classList.toggle("fb-form--enviando", activo);
    if (etiquetaEnviar) etiquetaEnviar.textContent = activo ? "Enviando…" : "Enviar reseña";
  }

  function fallo(texto) {
    ponerEnviando(false);
    mostrarError(texto);
    enfocar(btnEnviar);
  }

  /* La tarjeta final sustituye a la encuesta en el mismo sitio. Su entrada
     —el sello que se dibuja, el titular que sube— es CSS y arranca sola al
     dejar de estar [hidden]. */
  function terminar() {
    if (tarjeta) tarjeta.hidden = true;
    if (exito) {
      exito.hidden = false;
      enfocar(exito);
    }
    window.scrollTo(0, 0);
  }

  function enviar() {
    var datos = {
      token: tokenInput ? tokenInput.value : "",
      comentario: comentario ? comentario.value : "",
    };
    CAMPOS.forEach(function (campo) {
      datos[campo] = valorDe(campo);
    });

    ocultarError();
    ponerEnviando(true);

    // Una tablet con la red caída deja el fetch colgado indefinidamente; a los
    // 15 s se corta y el comensal ve un error con el que puede hacer algo.
    var control = typeof window.AbortController === "function" ? new window.AbortController() : null;
    var reloj = window.setTimeout(function () {
      if (control) control.abort();
    }, 15000);

    var peticion = {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(datos),
    };
    if (control) peticion.signal = control.signal;

    fetch(form.getAttribute("action") || "/api/feedback", peticion)
      .then(function (respuesta) {
        // Un 500 con HTML no es JSON: se trata como un fallo, no como una
        // excepción sin explicar.
        return respuesta.json().catch(function () {
          return { ok: false };
        });
      })
      .then(function (res) {
        window.clearTimeout(reloj);
        if (res && res.ok) {
          terminar();
          return;
        }
        // El `msg` del servidor es técnico («Valoración inválida en …»): al
        // comensal se le dice algo que entienda.
        fallo(TEXTO_FALLO);
      })
      .catch(function () {
        window.clearTimeout(reloj);
        fallo(TEXTO_RED);
      });
  }

  form.addEventListener("submit", function (evento) {
    evento.preventDefault();
    if (enviando) return;
    if (actual < PASO_RESUMEN) {
      avanzar();
      return;
    }
    var pendiente = primerPendiente();
    if (pendiente !== -1) {
      mostrarPaso(pendiente);
      faltaRespuesta(pendiente);
      return;
    }
    enviar();
  });

  // ── Arranque ────────────────────────────────────────────────
  //
  // La validación nativa se apaga: aquí se valida pregunta a pregunta y el
  // aviso sale en su sitio, no en la burbuja del navegador.
  form.noValidate = true;
  raiz.classList.add("fb-js");

  // Si el navegador restauró respuestas (recarga, atrás/adelante) se arranca en
  // la primera pregunta sin contestar, no en la primera de todas.
  var inicio = primerPendiente();
  mostrarPaso(inicio === -1 ? PASO_COMENTARIO : inicio, { animar: false, enfocar: false });

  raiz.classList.add("fb-listo");
})();
