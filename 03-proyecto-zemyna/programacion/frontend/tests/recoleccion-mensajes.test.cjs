const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/recoleccion.js'), 'utf8');
class Element {
  constructor() { this.children = []; this.textContent = ''; this.hidden = false; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener() {}
}
const text = element => [element.textContent, ...element.children.map(text)].join(' ');
async function boot(status, json) {
  const elements = new Map(), requests = [], redirects = [];
  const sandbox = vm.createContext({ AbortController, URLSearchParams, Event,
    document: { getElementById(id) { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); },
      createElement() { return new Element(); } },
    window: { addEventListener() {}, dispatchEvent() {}, location: { replace(url) { redirects.push(url); } } },
    buildApiUrl: p => p, buildFrontendUrl: p => p,
    fetch: async (url, options) => { requests.push({ url, options }); return { status, ok: status === 200, json: async () => json }; }
  });
  vm.runInContext(source, sandbox);
  await new Promise(resolve => setImmediate(resolve));
  return { elements, requests, redirects };
}
test('sin recorrido regular explica APOYO sin crear operaciones ni inferir asignaciones', async () => {
  const h = await boot(409, { success: false, code: 'sin_recorrido',
    data: { pertenencia: { nombre: 'Prueba', turno: 'Matutino' }, recorrido: null } });
  assert.match(text(h.elements.get('collectionItems')), /Sin recorrido regular activo/);
  assert.match(text(h.elements.get('collectionItems')), /Si trabajás como APOYO, no necesitás un recorrido regular/);
  assert.match(text(h.elements.get('collectionItems')), /según tus permisos/);
  assert.equal(h.elements.get('collectionPanel').hidden, false);
  assert.equal(h.requests.length, 1); assert.notEqual(h.requests[0].options.method, 'POST');
});
test('401, 403 y 503 conservan rechazo y no presentan el estado sin recorrido', async () => {
  for (const status of [401, 403, 503]) {
    const h = await boot(status, { success: false, message: 'SQLSTATE contraseña privada' });
    assert.equal(h.elements.get('collectionPanel').hidden, true);
    assert.doesNotMatch(text(h.elements.get('collectionStatus')), /SQLSTATE|contraseña/);
    assert.equal(h.redirects.length, status === 401 ? 1 : 0);
  }
});
