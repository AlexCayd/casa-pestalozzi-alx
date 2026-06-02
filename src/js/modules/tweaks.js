/* Panel de Tweaks */
function initTweaks() {
  var panel = $("#tweaks");

  function setHero(v) {
    body.setAttribute("data-hero", v);
    $$('[data-tw="hero"] .tw-opt').forEach(function(b) { b.classList.toggle("sel", b.dataset.val === v); });
    if (window.ScrollTrigger) ScrollTrigger.refresh();
  }

  function setAccentTw(v) {
    applyAccent(v);
    $$('[data-tw="accent"] .tw-swatch').forEach(function(b) { b.classList.toggle("sel", b.dataset.val === v); });
  }

  function setSwitch(name, on) {
    var sw = $('.tw-switch[data-tw="' + name + '"]');
    if (sw) sw.classList.toggle("on", !!on);
    if (name === "cursor") setCursorEnabled(on);
    if (name === "smooth") { on ? startLenis() : stopLenis(); }
    if (name === "anim") { body.classList.toggle("no-anim", !on); }
  }

  // Inicializar desde defaults
  setHero(T.hero);
  setAccentTw(T.accent);
  setSwitch("cursor", T.cursor);
  setSwitch("smooth", T.smooth);
  setSwitch("anim", T.anim);

  // Conectar controles
  $$('[data-tw="hero"] .tw-opt').forEach(function(b) {
    b.addEventListener("click", function() { setHero(b.dataset.val); persist({ hero: b.dataset.val }); });
  });
  $$('[data-tw="accent"] .tw-swatch').forEach(function(b) {
    b.addEventListener("click", function() { setAccentTw(b.dataset.val); persist({ accent: b.dataset.val }); });
  });
  ["cursor", "smooth", "anim"].forEach(function(name) {
    var sw = $('.tw-switch[data-tw="' + name + '"]');
    if (sw) sw.addEventListener("click", function() {
      var on = !sw.classList.contains("on");
      setSwitch(name, on);
      persist({ [name]: on });
    });
  });

  $("#twClose").addEventListener("click", function() {
    panel.classList.remove("open");
    try { window.parent.postMessage({ type: "__edit_mode_dismissed" }, "*"); } catch (e) {}
  });

  function persist(obj) {
    try { window.parent.postMessage({ type: "__edit_mode_set_keys", edits: obj }, "*"); } catch (e) {}
  }

  window.addEventListener("message", function(e) {
    var d = e.data || {};
    if (d.type === "__activate_edit_mode") panel.classList.add("open");
    else if (d.type === "__deactivate_edit_mode") panel.classList.remove("open");
  });

  try { window.parent.postMessage({ type: "__edit_mode_available" }, "*"); } catch (e) {}
}
