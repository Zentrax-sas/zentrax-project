const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/asignaciones-vehiculo.js'), 'utf8');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.value = ''; this.textContent = ''; }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; }
  setAttribute(key, value) { this[key] = value; }
  addEventListener(key, fn) { this.listeners[key] = fn; }
  focus() {}
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const all = root => [root, ...root.children.flatMap(all)];
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => ({ success: status === 200, data, message: 'Conflicto' }) });
function harness({ actual = null, modify = true, postStatus = 200 } = {}) {
  const root = new Element('section'), calls = [], redirects = [];
  const detail = { actual, historial: actual ? [actual] : [], has_more: false, vehiculos: [
    { id_vehiculo: 2, matricula: '<img onerror=x>', funcion_operativa: 'APOYO', activo: 1, estado: 'Disponible', autorizaciones: 1 },
    { id_vehiculo: 3, matricula: 'INACTIVO', funcion_operativa: 'REGULAR', activo: 0, estado: 'Disponible', autorizaciones: 1 },
    { id_vehiculo: 4, matricula: 'PENDIENTE', funcion_operativa: null, activo: 1, estado: 'Disponible', autorizaciones: 1 }
  ] };
  const context = vm.createContext({ document: { getElementById: () => root, createElement: tag => new Element(tag) },
    window: { location: { replace: u => redirects.push(u) } }, URLSearchParams, AbortController,
    Option: function (text, value) { const n = new Element('option'); n.textContent = text; n.value = value; return n; },
    buildApiUrl: x => x, buildFrontendUrl: x => x,
    fetch: async (url, options) => { calls.push({ url, options }); return options.method === 'POST' ? reply({}, postStatus) : reply(url.includes('?') ? detail : { puede_modificar: modify }); }
  });
  vm.runInContext(source, context);
  return { root, calls, redirects, api: context.window.VehicleAssignmentAdmin };
}
test('apertura usa recursos administrativos, sin actores ni fechas y filtra candidatos', async () => {
  const h = harness(); await h.api.open(7);
  const select = all(h.root).find(n => n.tag === 'select');
  assert.equal(select.children.length, 2); assert.equal(select.children[1].textContent.includes('<img onerror=x>'), true);
  select.value = '2'; all(h.root).find(n => n.tag === 'form').listeners.submit({ preventDefault() {} }); await settle(); await settle();
  assert.deepEqual(JSON.parse(h.calls.find(c => c.options.method === 'POST').options.body), { accion: 'abrir', id_cuadrilla: 7, id_vehiculo: 2 });
});
test('consulta sin permiso de modificar no presenta acciones', async () => {
  const h = harness({ modify: false }); await h.api.open(1); assert.equal(all(h.root).some(n => n.tag === 'form'), false);
});
test('cierre exige motivo y version; un 409 no muestra exito', async () => {
  const h = harness({ actual: { id_asignacion_vehiculo: 9, id_vehiculo: 1, matricula: 'V1', funcion_operativa: 'REGULAR', estado: 'Disponible', fecha_inicio: '2026-01-01', id_usuario_asigna: 1 }, postStatus: 409 });
  await h.api.open(7);
  const close = all(h.root).find(n => n.textContent === 'Finalizar utilización'); close.listeners.click(); await settle();
  assert.equal(h.calls.some(c => c.options.method === 'POST'), false);
  all(h.root).find(n => n.tag === 'input').value = 'Fin'; close.listeners.click(); await settle();
  assert.deepEqual(JSON.parse(h.calls.find(c => c.options.method === 'POST').options.body), { accion: 'cerrar', id_cuadrilla: 7, id_asignacion_vehiculo: 9, motivo: 'Fin' });
  assert.equal(all(h.root).some(n => n.textContent.includes('Conflicto')), true);
});
test('pausar descarta respuestas anteriores', async () => {
  const h = harness(); const pending = h.api.open(1); h.api.pause(); await pending; assert.equal(h.root.hidden, true);
});
