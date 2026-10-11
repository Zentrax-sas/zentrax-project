const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '../public');
const html = fs.readFileSync(path.join(root, 'recoleccion.html'), 'utf8');
class Element {
  constructor(tag = '') { this.tag = tag; this.children = []; this.textContent = ''; this.listeners = {}; this.value = ''; this.disabled = false; this.hidden = false; this.focuses = 0; }
  append(...nodes) { for (const n of nodes) { n.parent = this; this.children.push(n); } }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  querySelectorAll(selector) { const tags = selector.split(',').map(s => s.trim()); return this.children.flatMap(c => [...(tags.includes(c.tag) ? [c] : []), ...c.querySelectorAll(selector)]); }
  querySelector(selector) { return this.querySelectorAll(selector)[0]; }
  remove() { this.parent.children = this.parent.children.filter(c => c !== this); }
  focus() { this.focuses++; }
  scrollIntoView() {}
  set innerHTML(_) { throw new Error('Unsafe HTML'); }
}
const tick = async () => { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); };
const text = e => e.textContent + e.children.map(text).join(' ');
function row(state = 'Asignada', id = 14, caps) {
  return { id_solicitud: id, tracking_number: `REF-${id}`, fecha: '2026-10-10 08:00:00', tipo_solicitud: 'Reciclables', tipo_residuo: 'Plástico', id_tipo_residuo: 1,
    estado: state === 'En atención' ? 'En atención' : 'Programada', direccion: '<script>dirección</script>', descripcion: '<img src=x onerror=alert(1)>', id_cuadrilla: 25,
    atencion: { id_atencion_solicitud: 1, estado: state, id_asignacion_vehiculo: 4 }, vehiculo: { id_asignacion_vehiculo: 4, id_vehiculo: 11, matricula: 'MNO9013', funcion_operativa: 'APOYO' },
    estado_esperado: state === 'En atención' ? 'En atención' : 'Programada', estado_operativo_esperado: state, version_esperada: 'a'.repeat(64),
    capacidades: caps || { aceptar_solicitud: state === 'Asignada', rechazar_solicitud: state === 'Asignada', iniciar_atencion_solicitud: state === 'Aceptada', finalizar_atencion_solicitud: state === 'En atención' }, motivo_bloqueo: null };
}
const body = (rows = [row()], page = 1, more = false) => ({ success: true, csrf_token: 'token', data: { items: rows, page, limit: 20, has_more: more, cuadrilla: { id_cuadrilla: 25 } } });
function harness(hash = '', realConfig = false) {
  const elements = new Map(), requests = [], redirects = [], events = {}, confirmations = [], apiOptions = [];
  for (const m of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*id="([^"]+)"[^>]*>/g)) {
    const e = new Element(m[1]); e.hidden = /\bhidden\b/.test(m[0]); e.disabled = /\bdisabled\b/.test(m[0]); elements.set(m[2], e);
  }
  elements.get('solicitudes').append(...[...elements].filter(([id]) => id.startsWith('ownRequest')).map(([, e]) => e));
  elements.get('ownIncidents').append(...[...elements].filter(([id]) => id.startsWith('ownIncident') && id !== 'ownIncidents').map(([, e]) => e));
  let confirm = true, reports = 0;
  const context = vm.createContext({ URL, URLSearchParams, AbortController, Event,
    buildApiUrl: (p, options) => { apiOptions.push(options); return p; }, buildFrontendUrl: p => p,
    document: { getElementById: id => elements.get(id), createElement: tag => new Element(tag), querySelectorAll: selector => [...elements.values()].filter(e => e.tag === 'button') },
    window: { location: { hash, href: `http://localhost/zentrax-project/03-proyecto-zemyna/programacion/frontend/public/recoleccion.html${hash}`, replace: p => redirects.push(p) }, confirm: message => { confirmations.push(message); return confirm; },
      CrewReport: { open: () => reports++, pause() {} }, addEventListener: (n, f) => { (events[n] ||= []).push(f); }, dispatchEvent: e => events[e.type]?.forEach(f => f(e)) },
    fetch: (url, options = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) });
  const run = file => vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), context);
  if (realConfig) run('config.js');
  run('assets/js/recoleccion-solicitudes.js');
  return { elements, requests, redirects, confirmations, apiOptions, run, get reports() { return reports; },
    reply(index, json, status = 200) { requests[index].resolve({ status, ok: status < 300, json: async () => json }); },
    click(label, parent = elements.get('solicitudes')) { const b = parent.querySelectorAll('button').find(b => b.textContent === label); assert.ok(b, label); return b.listeners.click(); },
    confirm(value) { confirm = value; }, event(name) { context.window.dispatchEvent(new Event(name)); }, hash(value) { context.window.location.hash = value; context.window.dispatchEvent(new Event('hashchange')); } };
}
async function ready(rows = [row()], page = 1, more = false) { const h = harness(); h.reply(0, body(rows, page, more)); await tick(); return h; }
test('navegación directa usa el ancla y carga el módulo independiente en el HTML real', async () => {
  assert.match(html, /href="#solicitudes"/); assert.match(html, /assets\/js\/recoleccion-solicitudes.js\?v=1/);
  const h = harness('#solicitudes'); assert.equal(h.elements.get('solicitudes').focuses, 1); assert.equal(h.elements.get('solicitudes').hidden, false);
  assert.equal(h.requests[0].url, '/backend/api/solicitudes_propias.php?page=1&limit=20');
  assert.equal(h.requests[0].options.cache, 'no-store'); assert.equal(h.requests[0].options.credentials, 'same-origin'); assert.equal(h.apiOptions[0].cacheBust, false);
  h.reply(0, body()); await tick(); h.hash('#solicitudes'); assert.equal(h.elements.get('solicitudes').focuses, 2);
});
test('listado y detalle muestran solo información operativa y renderizan texto seguro', async () => {
  const r = row(); r.email = 'PRIVATE_EMAIL'; r.telefono = 'PRIVATE_PHONE'; r.historial = ['PRIVATE_HISTORY'];
  const h = await ready([r]); const list = h.elements.get('ownRequestList');
  for (const value of ['REF-14', '2026-10-10', 'Reciclables', 'Plástico', 'Programada', 'Asignada', '<script>dirección</script>']) assert.ok(text(list).includes(value));
  h.click('Ver detalle'); assert.equal(h.requests[1].url, '/backend/api/solicitudes_propias.php?id_solicitud=14'); h.reply(1, body([r])); await tick();
  const detail = h.elements.get('ownRequestDetail');
  for (const value of ['<img src=x onerror=alert(1)>', 'Cuadrilla: 25', 'MNO9013', 'APOYO', 'V19 4']) assert.ok(text(detail).includes(value));
  assert.doesNotMatch(text(list) + text(detail), /PRIVATE_/); assert.equal(detail.hidden, false);
});
test('config.js real construye solicitudes_propias sin timestamp ni endpoint administrativo', () => {
  const h = harness('#solicitudes', true), u = new URL(h.requests[0].url);
  assert.equal(u.pathname, '/zentrax-project/03-proyecto-zemyna/programacion/backend/api/solicitudes_propias.php');
  assert.deepEqual([...u.searchParams], [['page', '1'], ['limit', '20']]);
});
test('paginación usa page y limit del backend y muestra estado vacío', async () => {
  const h = await ready([row()], 1, true); h.elements.get('ownRequestNext').listeners.click(); assert.match(h.requests[1].url, /page=2&limit=20/);
  h.reply(1, body([row('Aceptada', 15)], 2)); await tick(); assert.equal(h.elements.get('ownRequestPrevious').disabled, false); assert.equal(h.elements.get('ownRequestNext').disabled, true);
  h.elements.get('ownRequestPrevious').listeners.click(); h.reply(2, body([])); await tick(); assert.match(text(h.elements.get('ownRequestStatus')), /No tenés solicitudes/);
});
test('acciones dependen estrictamente de capacidades, incluso con un estado Asignada', async () => {
  const rows = [row(), row('Aceptada', 15), row('En atención', 16), row('Asignada', 17, {}), row('Asignada', 18, { aceptar_solicitud: 'true' })];
  const h = await ready(rows);
  assert.deepEqual(h.elements.get('ownRequestList').children.map(c => c.querySelectorAll('button').map(b => b.textContent)),
    [['Ver detalle', 'Aceptar', 'Rechazar'], ['Ver detalle', 'Iniciar atención'], ['Ver detalle', 'Finalizar atención'], ['Ver detalle'], ['Ver detalle']]);
});
test('aceptar, iniciar y finalizar confirman, envían contrato exacto y evitan doble envío', async () => {
  for (const [state, label, action] of [['Asignada', 'Aceptar', 'aceptar_solicitud'], ['Aceptada', 'Iniciar atención', 'iniciar_atencion_solicitud'], ['En atención', 'Finalizar atención', 'finalizar_atencion_solicitud']]) {
    const r = row(state), h = await ready([r]); h.confirm(false); await h.click(label); assert.equal(h.requests.length, 1); h.confirm(true);
    const pending = h.click(label); h.click(label); assert.equal(h.requests.length, 2); const req = h.requests[1];
    assert.equal(req.url, '/backend/api/solicitudes_propias.php'); assert.equal(req.options.method, 'PUT'); assert.equal(req.options.headers['X-CSRF-Token'], 'token');
    assert.deepEqual(JSON.parse(req.options.body), { accion: action, id_solicitud: 14, id_atencion_solicitud: 1, estado_esperado: r.estado_esperado, estado_operativo_esperado: state, version_esperada: 'a'.repeat(64) });
    assert.ok(h.elements.get('solicitudes').querySelectorAll('button').every(b => b.disabled));
    h.reply(1, { success: true }); await tick(); h.reply(2, body([])); await pending; assert.match(text(h.elements.get('ownRequestStatus')), /Operación confirmada por el servidor/);
  }
});
test('rechazo valida motivo, confirma y conserva texto ante 400 antes de reintentar', async () => {
  const h = await ready(); h.click('Rechazar'); const form = h.elements.get('ownRequestList').querySelector('form'), input = form.querySelector('textarea');
  const submit = () => form.listeners.submit({ preventDefault() {} });
  for (const reason of ['  ', 'á'.repeat(501)]) { input.value = reason; submit(); assert.equal(h.requests.length, 1); }
  input.value = '  Falta equipo  '; h.confirm(false); await submit(); assert.equal(h.requests.length, 1); h.confirm(true);
  const pending = submit(); submit(); assert.equal(h.requests.length, 2); assert.equal(JSON.parse(h.requests[1].options.body).motivo, 'Falta equipo');
  h.reply(1, { success: false, message: 'Motivo inválido' }, 400); await pending; assert.equal(input.value, '  Falta equipo  '); assert.equal(input.disabled, false);
  const retry = submit(); h.reply(2, { success: true }); await tick(); h.reply(3, body([])); await retry;
  assert.equal(h.elements.get('ownRequestList').children.length, 0); assert.equal(h.elements.get('ownRequestDetail').hidden, true);
});
test('trabajos bloqueados permanecen visibles sin acciones y conservan V19 registrada', async () => {
  const r = row('En atención', 14, {}); r.motivo_bloqueo = 'La utilización se cerró.'; r.vehiculo = null;
  const h = await ready([r]); assert.match(text(h.elements.get('ownRequestList')), /Recurso bloqueado: La utilización se cerró/);
  assert.deepEqual(h.elements.get('ownRequestList').querySelectorAll('button').map(b => b.textContent), ['Ver detalle']);
  h.click('Ver detalle'); h.reply(1, body([r])); await tick(); assert.match(text(h.elements.get('ownRequestDetail')), /V19 registrada: 4/);
});
test('409 refresca listado y detalle con versión nueva, sin repetir la mutación', async () => {
  const h = await ready(); const pending = h.click('Aceptar'); h.reply(1, { success: false, message: 'Cambió' }, 409); await tick();
  assert.match(h.requests[2].url, /page=1/); h.reply(2, body([row('Aceptada')])); await tick(); assert.match(h.requests[3].url, /id_solicitud=14/);
  const current = row('Aceptada'); current.version_esperada = 'b'.repeat(64); h.reply(3, body([current])); await pending;
  assert.match(text(h.elements.get('ownRequestStatus')), /información actualizada/); assert.equal(h.requests.filter(r => r.options.method === 'PUT').length, 1);
  h.click('Iniciar atención', h.elements.get('ownRequestDetail')); assert.equal(JSON.parse(h.requests[4].options.body).version_esperada, 'b'.repeat(64));
});
test('404 de detalle o mutación elimina el trabajo perdido y consulta nuevamente', async () => {
  for (const kind of ['detail', 'put']) {
    const h = await ready(); const pending = h.click(kind === 'detail' ? 'Ver detalle' : 'Aceptar'); h.reply(1, { success: false }, 404); await tick();
    assert.equal(h.elements.get('ownRequestDetail').hidden, true); assert.equal(h.elements.get('ownRequestList').children.length, 0);
    h.reply(2, body([])); await pending; assert.match(text(h.elements.get('ownRequestStatus')), /ya no está disponible/);
    assert.equal(h.requests.filter(r => /id_solicitud=/.test(r.url)).length, kind === 'detail' ? 1 : 0);
  }
});
test('409 consultando detalle refresca lista y detalle sin bucles de reintento', async () => {
  const h = await ready(); const pending = h.click('Ver detalle'); h.reply(1, { success: false }, 409); await tick();
  h.reply(2, body()); await tick(); assert.match(h.requests[3].url, /id_solicitud=14/);
  h.reply(3, { success: false }, 409); await pending;
  assert.equal(h.requests.length, 4); assert.equal(h.elements.get('ownRequestDetail').hidden, true);
});
test('403 invalida respuestas de detalle y listado pendientes; refresh puede recuperar acceso', async () => {
  const h = await ready(); h.click('Ver detalle'); const refresh = h.elements.get('ownRequestRefresh').listeners.click();
  h.reply(2, { success: false }, 403); await refresh; h.reply(1, body()); await tick();
  assert.equal(h.elements.get('ownRequestDetail').hidden, true); assert.equal(h.elements.get('ownRequestList').children.length, 0);
  const retry = h.elements.get('ownRequestRefresh').listeners.click(); h.reply(3, body()); await retry;
  assert.match(text(h.elements.get('ownRequestList')), /REF-14/); assert.ok(h.elements.get('ownRequestList').querySelectorAll('button').some(b => b.textContent === 'Aceptar'));
});
test('401 y 403 en lectura, detalle o mutación limpian todos los datos protegidos', async () => {
  for (const code of [401, 403]) for (const kind of ['list', 'detail', 'put']) {
    const h = await ready(); const pending = kind === 'list' ? h.elements.get('ownRequestRefresh').listeners.click() : h.click(kind === 'detail' ? 'Ver detalle' : 'Aceptar');
    h.reply(1, { success: false }, code); await pending;
    assert.equal(h.elements.get('ownRequestList').children.length, 0); assert.equal(h.elements.get('ownRequestDetail').hidden, true);
    assert.match(text(h.elements.get('ownRequestStatus')), code === 401 ? /sesión venció/ : /autorización/);
    assert.deepEqual(h.redirects, code === 401 ? ['login.html'] : []);
  }
});
test('evento de sesión y pagehide impiden revelar respuestas tardías GET y PUT', async () => {
  for (const event of ['zemyna:session-expired', 'pagehide']) {
    const h = harness(); h.event(event); h.reply(0, body()); await tick(); assert.equal(h.elements.get('ownRequestList').children.length, 0);
    const saving = await ready(); const pending = saving.click('Aceptar'); saving.event(event); saving.reply(1, { success: true }); await pending;
    assert.equal(saving.elements.get('ownRequestList').children.length, 0); assert.equal(saving.requests.length, 2);
  }
});
test('errores de red no habilitan reenvíos inciertos y distinguen éxito sin refresh', async () => {
  const h = await ready(); const pending = h.click('Aceptar'); h.requests[1].reject(new Error('Red')); await pending;
  assert.match(text(h.elements.get('ownRequestStatus')), /No se pudo confirmar/); assert.equal(h.elements.get('ownRequestList').children.length, 0); assert.equal(h.elements.get('ownRequestRefresh').disabled, false);
  const saved = await ready(); const operation = saved.click('Aceptar'); saved.reply(1, { success: true }); await tick(); saved.requests[2].reject(new Error('Red')); await operation;
  assert.match(text(saved.elements.get('ownRequestStatus')), /Operación confirmada, pero/);
});
test('recorridos, F4 y reportar problemas siguen independientes, incluso APOYO sin recorrido', async () => {
  const h = harness(); h.run('assets/js/recoleccion.js'); h.run('assets/js/recoleccion-incidencias.js'); h.run('assets/js/recoleccion-report.js');
  assert.equal(h.requests[1].url, '/backend/api/recoleccion.php'); assert.match(h.requests[2].url, /view=incidencias_propias/);
  h.reply(0, body()); h.reply(1, { success: false, code: 'sin_recorrido', data: { pertenencia: { nombre: 'Propia', turno: 'Matutino' }, recorrido: null } }, 409);
  h.reply(2, { success: true, data: [], meta: { page: 1, has_more: false }, contexto_operativo: {} }); await tick();
  assert.match(text(h.elements.get('collectionItems')), /Sin recorrido asignado/); assert.match(text(h.elements.get('ownRequestList')), /REF-14/);
  h.elements.get('collectionReportButton').listeners.click(); assert.equal(h.reports, 1);
});
