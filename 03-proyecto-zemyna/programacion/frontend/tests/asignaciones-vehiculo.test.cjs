const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/asignaciones-vehiculo.js'), 'utf8');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.dataset = {}; this.value = ''; this.textContent = ''; }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; }
  setAttribute(key, value) { this[key] = value; }
  addEventListener(key, fn) { this.listeners[key] = fn; }
  focus() {}
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const all = root => [root, ...root.children.flatMap(all)];
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => ({ success: status === 200, data, message: 'Conflicto' }) });
function harness({ actual = null, modify = true, postStatus = 200, history, noAlternatives = false } = {}) {
  const root = new Element('section'), calls = [], redirects = [];
  const detail = { actual, historial: actual ? [actual] : [], has_more: false, vehiculos: [
    { id_vehiculo: 2, matricula: '<img onerror=x>', funcion_operativa: 'APOYO', activo: 1, estado: 'Disponible', autorizaciones: 1 },
    { id_vehiculo: 3, matricula: 'INACTIVO', funcion_operativa: 'REGULAR', activo: 0, estado: 'Disponible', autorizaciones: 1 },
    { id_vehiculo: 4, matricula: 'PENDIENTE', funcion_operativa: null, activo: 1, estado: 'Disponible', autorizaciones: 1 }
  ] };
  if (history) detail.historial = history;
  if (noAlternatives) detail.vehiculos = [];
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

test('confirmación V19 deshabilitada sin selección y motivo; reactivación tras consulta', async () => {
  const h = harness(); await h.api.open(7);
  const form = all(h.root).find(n => n.tag === 'form');
  const submit = all(form).find(n => n.tag === 'button');
  const select = all(form).find(n => n.tag === 'select');
  assert.equal(submit.disabled, true);
  select.value = '2'; select.listeners.change(); assert.equal(submit.disabled, false);
  select.value = ''; select.listeners.change(); assert.equal(submit.disabled, true);
  const current = harness({ actual: { id_asignacion_vehiculo: 9, id_vehiculo: 1 } }); await current.api.open(7);
  const nodes = all(current.root), input = nodes.find(n => n.tag === 'input');
  const change = nodes.find(n => n.textContent === 'Cambiar vehículo');
  const close = nodes.find(n => n.textContent === 'Finalizar utilización');
  assert.equal(change.disabled, true); assert.equal(close.disabled, true);
  input.value = 'Motivo'; input.listeners.input(); assert.equal(close.disabled, false); assert.equal(change.disabled, true);
});

test('historial legible conserva fechas, estado y motivo sin presentar IDs como responsables', async () => {
  const h = harness({ history: [
    { id_asignacion_vehiculo: 3, id_usuario_asigna: 2, matricula: 'ABC1234', fecha_inicio: '2026-10-04 18:15:00', fecha_fin: null },
    { id_asignacion_vehiculo: 4, id_usuario_asigna: 5, id_usuario_finaliza: 6, matricula: 'XYZ9999', fecha_inicio: '2026-10-03 08:00:00', fecha_fin: '2026-10-03 16:30:00', motivo_cierre: '<motivo de prueba>' }
  ] });
  await h.api.open(7);
  const records = all(h.root).filter(n => n.className === 'vehicle-utilization-record');
  const content = records.map(r => all(r).map(n => n.textContent).join(' ')).join(' ');
  for (const value of ['ABC1234', 'En utilización', '04/10/2026 · 18:15', 'Utilización finalizada', '03/10/2026 · 16:30', 'Responsable no disponible', '<motivo de prueba>']) assert.ok(content.includes(value), value);
  assert.ok(!content.includes('#')); assert.equal(records[0].dataset.assignmentId, 3); assert.equal(records[1].dataset.closedBy, 6);
  assert.ok(all(h.root).some(n => n.textContent === 'Anterior'));
});

test('sin alternativas permite cerrar con motivo sin afirmar ocupación o incompatibilidad', async () => {
  const h = harness({ actual: { id_asignacion_vehiculo: 9, id_vehiculo: 1, matricula: 'ACTUAL' }, noAlternatives: true });
  await h.api.open(7);
  const nodes = all(h.root), select = nodes.find(n => n.tag === 'select');
  assert.equal(select.disabled, true);
  assert.ok(nodes.some(n => n.textContent.startsWith('No hay alternativas seleccionables')));
  const input = nodes.find(n => n.tag === 'input'); input.value = 'Cierre de prueba'; input.listeners.input();
  assert.equal(nodes.find(n => n.textContent === 'Cambiar vehículo').disabled, true);
  const close = nodes.find(n => n.textContent === 'Finalizar utilización'); assert.equal(close.disabled, false);
  close.listeners.click(); await settle(); await settle();
  assert.deepEqual(JSON.parse(h.calls.find(c => c.options.method === 'POST').options.body), { accion: 'cerrar', id_cuadrilla: 7, id_asignacion_vehiculo: 9, motivo: 'Cierre de prueba' });
});
