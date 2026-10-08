const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const base = path.join(__dirname, '../public');
const html = fs.readFileSync(path.join(base, 'admin.html'), 'utf8');
const admin = fs.readFileSync(path.join(base, 'assets/js/admin.js'), 'utf8');
const moduleSource = fs.readFileSync(path.join(base, 'assets/js/solicitudes-admin.js'), 'utf8');
// Ejecutar el router real, con sus listeners y el controlador real de solicitudes.
const navigation = admin.slice(admin.indexOf('const titles ='), admin.indexOf('function enableTableFilter('));
class Element {
  constructor(id = '') { this.id = id; this.children = []; this.listeners = {}; this.textContent = ''; this.value = ''; this.dataset = {}; this.classes = new Set(); this.classList = { toggle: (c, on) => on ? this.classes.add(c) : this.classes.delete(c), remove: c => this.classes.delete(c) }; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(k, v) { this[k] = v; }
  addEventListener(k, fn) { this.listeners[k] = fn; }
  querySelectorAll() { return []; }
}
function boot({ cached = false, hash = '', denied = true } = {}) {
  const nodes = new Map(); for (const m of html.matchAll(/\bid="([^"]+)"/g)) nodes.set(m[1], new Element(m[1]));
  const views = [...html.matchAll(/<section class="view[^"\n]*" id="([^"]+)"/g)].map(m => nodes.get(m[1]));
  nodes.get('view-resumen').classes.add('active');
  const links = [...html.matchAll(/<a class="nav-link[^"\n]*"[^>]*data-view="([^"]+)"/g)].map(m => { const e = new Element(); e.dataset.view = m[1]; return e; });
  const title = new Element(); title.textContent = 'Resumen operativo'; const events = {}, calls = [], location = { hash };
  const context = vm.createContext({ document: { getElementById: id => nodes.get(id), createElement: () => new Element(), querySelectorAll: s => s === '.nav-link' ? links : views, querySelector: () => title },
    window: { addEventListener: (name, fn) => { events[name] = fn; }, scrollTo() {}, location: { replace() {} } },
    location, history: { replaceState: (_, __, value) => { location.hash = value; } }, app: new Element(), menuButton: new Element(), operationalMapVersion: 0, operationalMap: null,
    AbortController, URLSearchParams, WeakMap, buildApiUrl: x => x, buildFrontendUrl: x => x,
    fetch: async (url) => { calls.push(url); return { status: denied ? 403 : 200, ok: !denied, json: async () => denied ? { success: false, message: 'No tenés permisos vigentes en Operaciones.' } : { success: true, csrf_token: 'token', data: { items: [], page: 1, has_more: false } } }; }
  });
  // La copia cacheada anterior al registro F6.2B no reconoce este nombre.
  vm.runInContext(cached ? navigation.replace("solicitudes: 'Solicitudes especiales', ", '') : navigation, context);
  vm.runInContext(moduleSource, context);
  events.DOMContentLoaded();
  return { nodes, links, title, calls, location, click: () => links.find(l => l.dataset.view === 'solicitudes').listeners.click({ preventDefault() {} }) };
}
const tick = () => new Promise(resolve => setImmediate(resolve));
test('reproduce URL #solicitudes con Resumen visible al reutilizar admin.js anterior cacheado', async () => {
  const h = boot({ cached: true }); h.click(); await tick();
  assert.equal(h.location.hash, '#solicitudes'); assert.equal(h.nodes.get('view-resumen').classes.has('active'), true); assert.equal(h.nodes.get('view-solicitudes').classes.has('active'), false); assert.equal(h.calls.length, 0);
  assert.match(html, /assets\/js\/admin\.js\?v=8/); // URL distinta de v7: el navegador solicita el router actualizado.
});
test('clic con usuario sin permiso abre Solicitudes y muestra denegación, no Resumen ni datos', async () => {
  const h = boot(); h.click(); await tick();
  assert.equal(h.location.hash, '#solicitudes'); assert.equal(h.title.textContent, 'Solicitudes especiales'); assert.equal(h.nodes.get('view-solicitudes').classes.has('active'), true); assert.equal(h.nodes.get('view-resumen').classes.has('active'), false);
  assert.match(h.nodes.get('requestsStatus').textContent, /No disponés de permisos/); assert.equal(h.nodes.get('requestsRows').children.length, 0); assert.equal(h.calls.length, 1);
});
test('acceso directo admin.html#solicitudes inicializa controlador y conserva acceso denegado', async () => {
  const h = boot({ hash: '#solicitudes' }); await tick();
  assert.equal(h.title.textContent, 'Solicitudes especiales'); assert.equal(h.nodes.get('view-solicitudes').classes.has('active'), true); assert.equal(h.nodes.get('view-resumen').classes.has('active'), false); assert.match(h.nodes.get('requestsStatus').textContent, /No disponés de permisos/); assert.equal(h.calls.length, 1);
});
