const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/cuadrillas.js'), 'utf8');
class Element {
  get value() { return this._value || ''; }
  set value(value) { this._value = String(value); }
  constructor(tag = 'div') { this.tag = tag; this.children = []; this.listeners = {}; this.dataset = {}; this.value = ''; this.textContent = ''; this.hidden = false; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(k, v) { this[k] = v; }
  addEventListener(k, fn) { this.listeners[k] = fn; }
  focus() { this.focused = true; }
  scrollIntoView() {}
  click() { return this.listeners.click?.(); }
  close() { this.open = false; this.listeners.close?.(); }
  showModal() { this.open = true; }
}
const all = n => [n, ...n.children.flatMap(all)];
const text = n => all(n).map(e => e.textContent).join(' ');
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(r => setImmediate(r)); };
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => ({ success: status === 200, data }) });
function boot({ members = true, empty = false, trip = true, denied = false, delayed = false, assign = true, tripState = 'Pendiente', operations = false } = {}) {
  const elements = {}, calls = [], pending = [], vehicle = [], redirects = [], posts = [];
  const el = id => elements[id] ||= new Element();
  const squads = [1, 2].map(id => ({ id_cuadrilla: id, nombre: `Cuadrilla ${id}`, turno: 'Matutino', integrantes_activos: empty ? 0 : 1 }));
  const list = items => ({ items, total: items.length, page_size: 25 });
  const context = vm.createContext({ document: { getElementById: el, createElement: tag => new Element(tag) },
    window: { VehicleAssignmentAdmin: { pause() {}, open(id, page, callback) { vehicle.push({ id, callback }); } }, location: { replace: u => redirects.push(u) } },
    adminSessionReady: Promise.resolve(true), buildApiUrl: x => x, buildFrontendUrl: x => x, URLSearchParams, AbortController,
    fetch: async (url, options) => {
      calls.push({ url, options }); const q = new URL(url, 'http://local').searchParams, id = Number(q.get('id_cuadrilla'));
      if (options.method === 'POST') return new Promise(resolve => posts.push({ url, body: JSON.parse(options.body), resolve }));
      if (url.includes('/rutas.php')) return reply([{ id_ruta: 8, nombre: 'Ruta prueba', zona: 'G' }]);
      if (q.get('view') === 'permisos') return reply({ administracion: true, integrantes: members, modificar_integrantes: members, asignar_recorridos: assign, crear_recorridos: assign });
      if (q.get('view') === 'asignables') return reply({ items: operations ? [10, 99].map(id => ({ id_recorrido: id, ruta_nombre: 'Ruta prueba', estado: 'Pendiente', fecha_inicio: '2026-10-16' })) : [], vehiculos: operations ? [{ id_usa: 5, matricula: 'PRUEBA' }] : [], total: operations ? 2 : 0, page_size: 25 });
      if (q.get('view') === 'integrantes') {
        const data = { historial: empty ? [] : [{ nombre: `Operario ${id}`, apellido: '<texto>', fecha_fin: null }], elegibles: [], no_elegibles: [] };
        if (delayed) return new Promise(resolve => pending.push(() => resolve(reply(data))));
        return reply(data, denied ? 403 : 200);
      }
      if (!id) return reply({ lista: list(squads) });
      return reply({ cuadrilla: squads[id - 1], integrantes_activos: empty ? 0 : 1, vehiculos: [{ matricula: `AUT-${id}`, activo: 1 }], recorrido_actual: trip ? { ruta_nombre: `Ruta ${id}`, estado: tripState, fecha_inicio: '2026-10-15', progreso: { atendidos: 0, total: 3 } } : null, lista: list([]) });
    } });
  vm.runInContext(source, context);
  const select = async id => { all(el('squadList')).filter(n => n.tag === 'button')[id - 1].listeners.click(); await settle(); };
  return { el, calls, pending, vehicle, select, posts, api: context.window.SquadAdmin };
}

async function prepareOperation() {
  const h = boot({ trip: false, operations: true }); await h.api.open(); await h.select(1);
  h.el('squadAssignTrip').click(); await settle();
  h.el('squadManageTrips').listeners.click({ preventDefault() {} }); await settle();
  h.el('squadCreateRoute').value = '8'; h.el('squadCreateDate').value = '2026-10-16T08:00';
  return h;
}
const submit = (h, id) => h.el(id).listeners.submit({ preventDefault() {} });
const postReply = (post, status = 200, data = {}) => post.resolve({ ok: status >= 200 && status < 300, status, json: async () => ({ success: status >= 200 && status < 300, data, message: 'Rechazo controlado' }) });

test('creación selecciona ID confirmado y asignación conserva payload; doble pulsación no duplica', async () => {
  const h = await prepareOperation(); submit(h, 'squadCreateTripForm'); submit(h, 'squadCreateTripForm');
  assert.equal(h.posts.length, 1); assert.deepEqual(h.posts[0].body, { id_ruta: 8, fecha_inicio: '2026-10-16 08:00:00', estado: 'Pendiente' });
  postReply(h.posts[0], 201, { id_recorrido: 99 }); await settle();
  assert.equal(Number(h.el('squadAvailableTrip').value), 99); assert.equal(h.el('squadTripConfirm').disabled, false);
  submit(h, 'squadTripAssignment'); submit(h, 'squadTripAssignment'); assert.equal(h.posts.length, 2);
  assert.deepEqual(h.posts[1].body, { accion: 'asignar_recorrido', destino: 1, id_recorrido: 99, id_usa: 5 });
  postReply(h.posts[1]); await settle(); assert.equal(h.el('squadTripDialog').open, false);
  assert.match(h.el('squadStatus').textContent, /Recorrido asignado/); assert.equal(h.posts.length, 2);
});

test('403/409/503 de creación y asignación conservan errores sin escrituras adicionales', async () => {
  for (const form of ['squadCreateTripForm', 'squadTripAssignment']) for (const status of [403, 409, 503]) {
    const h = await prepareOperation(); submit(h, form); postReply(h.posts[0], status); await settle();
    assert.equal(h.posts.length, 1); assert.equal(h.el('squadTripDialog').open, true);
    assert.match(h.el(form === 'squadCreateTripForm' ? 'squadCreateStatus' : 'squadTripDialogStatus').textContent, status === 403 ? /permiso/ : /Rechazo controlado/);
    assert.equal(h.el('squadTripConfirm').disabled, false);
  }
});

test('cierre durante POST descarta respuesta tardía y consulta posterior recupera creación', async () => {
  for (const form of ['squadCreateTripForm', 'squadTripAssignment']) {
    const h = await prepareOperation(); submit(h, form); h.el('squadTripDialog').close();
    const count = h.calls.length, previous = h.el('squadCreateStatus').textContent;
    postReply(h.posts[0], form === 'squadCreateTripForm' ? 201 : 200, { id_recorrido: 99 }); await settle();
    assert.equal(h.calls.length, count); assert.equal(h.el('squadCreateStatus').textContent, previous);
    h.el('squadAssignTrip').click(); await settle(); assert.ok(h.el('squadAvailableTrip').children.some(n => Number(n.value) === 99));
    assert.equal(h.posts.length, 1);
  }
});

test('cambio de módulo/cuadrilla durante POST no recibe actualizaciones de la anterior', async () => {
  const h = await prepareOperation(); submit(h, 'squadCreateTripForm');
  await h.select(2); assert.equal(h.el('squadName').textContent, 'Cuadrilla 1'); // cambio bloqueado durante guardado
  h.api.pause(); await h.api.open(); const count = h.calls.length;
  postReply(h.posts[0], 201, { id_recorrido: 99 }); await settle(); assert.equal(h.calls.length, count);
  await h.select(2); assert.equal(h.el('squadName').textContent, 'Cuadrilla 2');
  assert.equal(h.el('squadTripDialog').open, false); assert.equal(h.posts.length, 1);
});
test('resumen muestra integrantes, autorización y recorrido sin cambiar pestaña; solo GET', async () => {
  const h = boot(); await h.api.open(); await h.select(1);
  h.vehicle[0].callback({ actual: { matricula: 'VIGENTE', funcion_operativa: 'REGULAR', estado: 'Disponible' } });
  const summary = text(h.el('squadSummary'));
  assert.equal(h.el('squadName').textContent, 'Cuadrilla 1');
  for (const value of ['Matutino', 'Operario 1 <texto>', 'AUT-1', 'VIGENTE', 'Ruta 1', 'Pendiente', 'no confirma disponibilidad']) assert.ok(summary.includes(value), value);
  h.el('squadTripsTab').listeners.click(); assert.equal(h.el('squadSummary').hidden, true);
  assert.ok(h.calls.every(c => !c.options.method || c.options.method === 'GET'));
});

test('pestañas muestran un único panel y no repiten consultas; teclado y cambio de cuadrilla', async () => {
  const h = boot(); await h.api.open(); await h.select(1);
  const count = h.calls.length;
  for (const [tab, panel] of [['squadMembersTab', 'squadMembers'], ['squadTripsTab', 'squadTrips'], ['squadSummaryTab', 'squadSummaryPanel']]) {
    h.el(tab).click(); await settle();
    assert.equal(h.el(tab)['aria-selected'], 'true'); assert.equal(h.el(tab).tabindex, '0');
    for (const id of ['squadSummaryPanel', 'squadMembers', 'squadTrips']) assert.equal(h.el(id).hidden, id !== panel);
  }
  assert.equal(h.calls.length, count);
  const key = (id, value) => h.el(id).listeners.keydown({ key: value, preventDefault() {} });
  key('squadSummaryTab', 'ArrowRight'); assert.equal(h.el('squadMembers').hidden, false); assert.equal(h.el('squadMembersTab').focused, true);
  key('squadMembersTab', 'End'); assert.equal(h.el('squadTrips').hidden, false);
  key('squadTripsTab', 'Home'); assert.equal(h.el('squadSummaryPanel').hidden, false);
  key('squadSummaryTab', 'ArrowLeft'); assert.equal(h.el('squadTrips').hidden, false);
  await h.select(2); assert.equal(h.el('squadSummaryPanel').hidden, false); assert.equal(h.el('squadTrips').hidden, true);
});

test('carga pendiente no duplica consulta y teclado omite integrantes sin permiso', async () => {
  const h = boot({ delayed: true }); await h.api.open(); await h.select(1);
  h.el('squadMembersTab').click(); h.el('squadMembersTab').click(); await settle();
  assert.equal(h.pending.length, 1); assert.match(text(h.el('squadActiveMembers')), /Cargando/);
  h.pending[0](); await settle(); assert.match(text(h.el('squadActiveMembers')), /Operario 1/);
  const limited = boot({ members: false }); await limited.api.open(); await limited.select(1);
  limited.el('squadSummaryTab').listeners.keydown({ key: 'ArrowRight', preventDefault() {} });
  assert.equal(limited.el('squadTrips').hidden, false);
});

test('HTML asocia pestañas y paneles, utilización pertenece solo a Resumen', () => {
  const html = fs.readFileSync(path.join(__dirname, '../public/admin.html'), 'utf8');
  assert.match(html, /role="tablist"/);
  for (const [tab, panel] of [['squadSummaryTab', 'squadSummaryPanel'], ['squadMembersTab', 'squadMembers'], ['squadTripsTab', 'squadTrips']]) {
    assert.ok(html.includes(`aria-controls="${panel}"`)); assert.ok(html.includes(`role="tabpanel" aria-labelledby="${tab}"`));
  }
  assert.match(html, /id="squadSummaryPanel"[^]*?id="vehicleAssignment"[^]*?<\/section>\s*<\/section>\s*<section id="squadMembers"/);
});

test('Preparar jornada respeta permisos y muestra guía informativa sin escrituras', async () => {
  const h = boot({ empty: true, trip: false }); await h.api.open(); await h.select(1);
  h.vehicle[0].callback({ actual: null, vehiculos: [] });
  const nodes = all(h.el('squadSummary')), access = nodes.find(n => n.tag === 'button' && n.textContent === 'Preparar jornada');
  const guide = nodes.find(n => n.className === 'squad-preparation');
  assert.equal(guide.hidden, true); access.click(); assert.equal(guide.hidden, false); assert.equal(access['aria-expanded'], 'true');
  for (const value of ['sin integrantes con pertenencia vigente', 'sin vehículos autorizados', 'sin utilización vigente', 'sin recorrido actual', 'No certifica disponibilidad']) assert.ok(text(guide).includes(value), value);
  nodes.find(n => n.textContent === 'Abrir herramientas de asignación').click(); await settle();
  assert.equal(h.el('squadTripDialog').open, true); assert.equal(h.el('squadTripConfirm').disabled, true);
  assert.ok(h.calls.every(c => !c.options.method || c.options.method === 'GET'));
  const limited = boot({ assign: false }); await limited.api.open(); await limited.select(1);
  assert.ok(!all(limited.el('squadSummary')).some(n => n.textContent === 'Preparar jornada'));
});

test('guía prioriza recorrido operativo y navega a consultas existentes', async () => {
  for (const tripState of ['Pendiente', 'En Proceso']) {
    const h = boot({ tripState }); await h.api.open(); await h.select(1);
    h.vehicle[0].callback({ actual: { matricula: 'ACTUAL' }, vehiculos: [{ matricula: 'ACTUAL' }] });
    const guide = all(h.el('squadSummary')).find(n => n.className === 'squad-preparation');
    assert.ok(text(guide).includes(`Ruta 1 · ${tripState}`));
    assert.ok(!all(guide).some(n => n.textContent === 'Abrir herramientas de asignación'));
    all(guide).find(n => n.textContent === 'Ver recorrido actual y recorridos').click(); assert.equal(h.el('squadTrips').hidden, false);
    all(guide).find(n => n.textContent === 'Consultar utilización').click();
    all(guide).find(n => n.textContent === 'Gestionar integrantes').click(); await settle(); assert.equal(h.el('squadMembers').hidden, false);
    assert.ok(h.calls.every(c => !c.options.method || c.options.method === 'GET'));
  }
});

test('guía informa errores y sus acciones antiguas no navegan tras cambiar cuadrilla', async () => {
  const h = boot({ denied: true, trip: false }); await h.api.open(); await h.select(1); h.vehicle[0].callback(null);
  const guide = all(h.el('squadSummary')).find(n => n.className === 'squad-preparation');
  assert.ok(text(guide).includes('No se pudo consultar integrantes'));
  assert.equal(all(guide).find(n => n.textContent === 'Consultar utilización').disabled, true);
  const oldAction = all(guide).find(n => n.textContent === 'Abrir herramientas de asignación');
  await h.select(2); const count = h.calls.length; oldAction.click(); await settle(); assert.equal(h.calls.length, count);
  const newGuide = all(h.el('squadSummary')).find(n => n.className === 'squad-preparation'); assert.equal(newGuide.hidden, true);
});
test('estados vacíos distinguen integrantes, utilización y recorrido', async () => {
  const h = boot({ empty: true, trip: false }); await h.api.open(); await h.select(1); h.vehicle[0].callback({ actual: null });
  const summary = text(h.el('squadSummary'));
  for (const value of ['No hay integrantes con pertenencia vigente', 'Sin utilización vigente', 'Sin recorrido actual asignado']) assert.ok(summary.includes(value), value + " => " + summary);
});
test('consultas no autorizadas mantienen estados neutrales y no muestran nombres', async () => {
  const h = boot({ denied: true }); await h.api.open(); await h.select(1); h.vehicle[0].callback(null);
  assert.ok(text(h.el('squadSummary')).includes('No se pudo consultar los integrantes'));
  assert.ok(!text(h.el('squadSummary')).includes('Operario 1'));
  assert.ok(text(h.el('squadSummary')).includes('No se pudo confirmar'));
  const limited = boot({ members: false }); await limited.api.open(); await limited.select(1);
  assert.ok(!limited.calls.some(c => c.url.includes('view=integrantes')));
});
test('cambio rápido y pausa descartan integrantes y utilización tardíos', async () => {
  const h = boot({ delayed: true }); await h.api.open(); await h.select(1);
  // Simula selección rápida desde un consumidor que conserva el botón del catálogo.
  await h.select(2);
  h.pending[0](); h.vehicle[0].callback({ actual: { matricula: 'ANTERIOR' } }); await settle();
  assert.ok(!text(h.el('squadSummary')).includes('Operario 1'));
  assert.ok(!text(h.el('squadSummary')).includes('ANTERIOR'));
  h.pending[1](); h.vehicle[1].callback({ actual: null }); await settle();
  assert.ok(text(h.el('squadSummary')).includes('Operario 2'));
  h.api.pause(); assert.equal(h.el('squadDetail').hidden, true);
  h.vehicle[1].callback({ actual: { matricula: 'TARDIO' } }); assert.equal(text(h.el('squadSummary')), '');
});
