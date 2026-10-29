/*
 * Perfiles sugeridos de Usuarios sobre el formulario real renderizado por PHP.
 * Comprueba que un perfil rellena solo controles existentes, respeta el techo
 * del actor, calcula la credencial y que el envío contiene permisos directos.
 * jsdom llega como dependencia transitiva del lockfile.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(root, 'src/js/admin/users-directos.js'), 'utf8');
let casos = 0;

async function montar(caso) {
  const html = execFileSync('php', [path.join(__dirname, 'render-usuarios-perfiles.php'), JSON.stringify(caso)], { cwd: root, encoding: 'utf8' });
  assert.doesNotMatch(html, /(Warning|Notice|Deprecated|Fatal error)/, 'La vista emitió avisos de PHP.');
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only' });
  dom.window.eval(script);
  // El script arranca en DOMContentLoaded, que jsdom despacha de forma asíncrona.
  if (dom.window.document.readyState === 'loading') {
    await new Promise((listo) => dom.window.document.addEventListener('DOMContentLoaded', listo));
  }
  const doc = dom.window.document;
  const form = doc.querySelector('[data-user-direct-form]');
  return {
    doc,
    form,
    elegir(valor) {
      const radio = doc.querySelector('input[name=perfil_sugerido][value="' + valor + '"]');
      assert.ok(radio, 'No existe el perfil ' + valor);
      radio.checked = true;
      radio.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    },
    marcado() {
      const radio = doc.querySelector('input[name=perfil_sugerido]:checked');
      return radio ? radio.value : null;
    },
    nivel(slug, modulo) { return doc.getElementById('scope-' + slug + '-' + modulo).value; },
    activo(slug) { return doc.querySelector('[data-scope="' + slug + '"] [data-scope-toggle]').checked; },
    envio() {
      const datos = {};
      for (const [k, v] of new dom.window.FormData(form)) datos[k] = v;
      return datos;
    },
    estado() { return doc.querySelector('[data-user-preset-status]').textContent; },
    change(el) { el.dispatchEvent(new dom.window.Event('change', { bubbles: true })); },
  };
}

(async () => {
  // ── Actor con alcance Global completo ────────────────────────────────
  {
    const f = await montar({ ambitos: ['global', 'restaurante', 'panaderia'] });
    const panel = f.doc.querySelector('[data-user-presets]');
    assert.equal(panel.hidden, false, 'La mejora progresiva no mostró los perfiles.');
    assert.equal(f.marcado(), null, 'Un alta vacía no debe marcar perfil.');
    assert.deepEqual(
      [...f.doc.querySelectorAll('[data-user-preset]')].map((r) => r.value),
      ['mesero', 'admin_restaurante', 'admin_panaderia', 'acceso_total']
    );
    casos++;

    f.elegir('mesero');
    assert.equal(f.activo('restaurante'), true);
    assert.equal(f.activo('global'), false);
    assert.equal(f.activo('panaderia'), false);
    assert.equal(f.nivel('restaurante', 'pdv'), 'operar');
    for (const m of ['reservations_map', 'area', 'menu', 'users', 'configuration']) assert.equal(f.nivel('restaurante', m), '', 'Mesero no debe tener ' + m);
    assert.equal(f.form.getAttribute('data-resolved-auth-type'), 'nip');
    assert.equal(f.marcado(), 'mesero');
    assert.match(f.estado(), /Mesero aplicado: entra con NIP/);
    const envioMesero = f.envio();
    assert.equal(envioMesero['ambitos[restaurante][activo]'], '1');
    assert.equal(envioMesero['ambitos[restaurante][permisos][pdv]'], 'operar');
    assert.equal(envioMesero['ambitos[global][activo]'], '0');
    assert.equal(envioMesero['ambitos[restaurante][facultades][]'], undefined, 'Mesero no recibe facultades.');
    assert.equal(Object.keys(envioMesero).some((k) => k.startsWith('ambitos[global][permisos]')), false, 'Un ámbito apagado no envía permisos.');
    assert.equal(envioMesero.password, undefined, 'El acceso por NIP no envía contraseña.');
    casos++;

    f.elegir('admin_restaurante');
    for (const [m, def] of Object.entries({ pdv: 'operar', area: 'operar', reservations_map: 'operar', menu: 'editar', users: 'editar', configuration: 'editar', finanzas: 'editar' })) {
      assert.equal(f.nivel('restaurante', m), def, 'Admin de Restaurante sin ' + m);
    }
    assert.equal(f.activo('panaderia'), false);
    assert.equal(f.doc.querySelectorAll('[data-scope="restaurante"] input[name*="[facultades]"]:checked').length, 3);
    assert.equal(f.form.getAttribute('data-resolved-auth-type'), 'contrasena');
    assert.equal(f.doc.getElementById('direct-password').required, true, 'El panel exige contraseña en el alta.');
    assert.equal(f.marcado(), 'admin_restaurante');
    casos++;

    f.elegir('admin_panaderia');
    assert.equal(f.activo('restaurante'), false);
    assert.equal(f.activo('panaderia'), true);
    assert.equal(f.nivel('panaderia', 'menu'), 'editar');
    assert.equal(f.nivel('restaurante', 'pdv'), '', 'Cambiar de perfil limpia el ámbito anterior.');
    assert.equal(f.doc.getElementById('scope-panaderia-pdv'), null, 'Panadería no tiene POS.');
    casos++;

    f.elegir('acceso_total');
    for (const slug of ['global', 'restaurante', 'panaderia']) {
      assert.equal(f.activo(slug), true);
      assert.equal(f.nivel(slug, 'users'), 'editar');
    }
    assert.equal(f.nivel('restaurante', 'pdv'), 'operar');
    assert.equal(f.envio().es_superadmin, undefined, 'Acceso total no envía la bandera protegida.');
    assert.equal(f.marcado(), 'acceso_total');
    casos++;

    // Un ajuste manual convierte la selección en personalizada.
    const menu = f.doc.getElementById('scope-global-menu');
    menu.value = 'ver';
    f.change(menu);
    assert.equal(f.marcado(), 'personalizado');
    menu.value = 'editar';
    f.change(menu);
    assert.equal(f.marcado(), 'acceso_total', 'Volver al estado del perfil lo reconoce.');
    casos++;
  }

  // ── Actor local de Restaurante con techo parcial ─────────────────────
  {
    const f = await montar({
      contexto: 'restaurante',
      ambitos: ['restaurante'],
      techo: { pdv: 'operar', menu: 'ver', analytics: 'editar' },
      facultades: { 'usuarios.gestionar': 'Gestionar cuentas.' },
    });
    assert.deepEqual([...f.doc.querySelectorAll('[data-user-preset]')].map((r) => r.value), ['mesero', 'admin_restaurante'],
      'Un actor local no ve perfiles de otros ámbitos.');
    f.elegir('admin_restaurante');
    assert.equal(f.nivel('restaurante', 'menu'), 'ver', 'El techo de Lectura no se supera.');
    assert.equal(f.nivel('restaurante', 'analytics'), 'editar');
    assert.equal(f.nivel('restaurante', 'finanzas'), '', 'Sin techo no se asigna el módulo.');
    assert.match(f.estado(), /menor nivel/);
    assert.equal(f.marcado(), 'admin_restaurante', 'El perfil recortado sigue reconocido.');
    casos++;
  }

  // ── Edición: un mesero existente se reconoce al abrir ────────────────
  {
    const f = await montar({
      ambitos: ['global', 'restaurante', 'panaderia'],
      usuario: { id: 7, username: 'mesero_1', nombre: 'Mesero', activo: true, tipo_acceso: 'nip', ambitos: { restaurante: { activo: true, permisos: { pdv: 'operar' }, facultades: [] } } },
    });
    assert.equal(f.marcado(), 'mesero');
    const reservas = f.doc.getElementById('scope-restaurante-reservations_map');
    reservas.value = 'operar';
    f.change(reservas);
    assert.equal(f.marcado(), 'personalizado');
    casos++;
  }

  // ── Sin JavaScript la sección queda oculta y el servidor la ignora ───
  {
    const html = execFileSync('php', [path.join(__dirname, 'render-usuarios-perfiles.php'), JSON.stringify({ ambitos: ['restaurante'] })], { cwd: root, encoding: 'utf8' });
    assert.match(html, /data-user-presets hidden/);
    const controlador = fs.readFileSync(path.join(root, 'controllers/AdminUsuariosAmbitosController.php'), 'utf8');
    assert.doesNotMatch(controlador.match(/function datosPost[\s\S]*?\n {4}}/)[0], /perfil_sugerido/, 'El servidor no debe leer el perfil.');
    casos++;
  }

  console.log('Perfiles sugeridos de Usuarios: ' + casos + ' casos OK');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
