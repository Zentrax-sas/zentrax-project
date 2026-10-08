const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const html = fs.readFileSync(path.join(__dirname, '../public/admin.html'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/solicitudes-admin.js'), 'utf8');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.value = ''; this.textContent = ''; this.disabled = false; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  setAttribute(name, value) { this[name] = value; }
  querySelectorAll() { return all(this).filter(n => ['button', 'input', 'select', 'textarea'].includes(n.tag)); }
  focus() {}
  set innerHTML(_) { throw new Error('Unsafe HTML'); }
}
const all = n => [n, ...n.children.flatMap(all)];
const tick = async () => { for (let i = 0; i < 6; i++) await new Promise(r => setImmediate(r)); };
function record() {
  return { solicitud: { id_solicitud: 14, tracking_number: 'REF-2026-ABCDE', fecha: '2026-10-07', tipo_solicitud: 'Reciclables', tipo_residuo: '<img onerror=x>', id_tipo_residuo: 38, estado: 'Pendiente', direccion: '<script>x</script>', descripcion: '<img src=x>', email: 'test@test.invalid', telefono: '099111111', id_cuadrilla: null, fecha_confirmacion_residuo: null, id_usuario_confirma_residuo: null },
    intento_actual: null, historial: [], historial_page: 1, historial_has_more: false, historica_programada_sin_intento: true,
    estado_esperado: 'Pendiente', id_atencion_esperada: null, version_esperada: 'a'.repeat(64),
    capacidades: { consultar: true, modificar: true, confirmar_residuo: true, asignar: true, reasignar: false, desasignar: false, cancelar: true }, motivo_bloqueo: null };
}
function harness({ modify = true, candidates = true, putStatus = 200, deferred = false, listStatus = 200, optionsConflict = false } = {}) {
  const nodes = new Map(); for (const id of html.matchAll(/\bid="([^"]+)"/g)) nodes.set(id[1], new Element('div'));
  for (const id of ['requestsState', 'requestsService']) nodes.get(id).tag = 'select';
  for (const id of ['requestsFrom', 'requestsTo']) nodes.get(id).tag = 'input';
  const root = nodes.get('view-solicitudes'); root.append(...['requestsFilters','requestsRefresh','requestsRows','requestsStatus','requestsPrevious','requestsNext','requestsPage','requestsDetail'].map(id => nodes.get(id)));
  nodes.get('requestsFilters').append(...['requestsState','requestsService','requestsFrom','requestsTo'].map(id => nodes.get(id)));
  const calls = [], redirects = [], confirmations = []; let resolvePut;
  const d = record(); if (!modify) { for (const k of Object.keys(d.capacidades)) d.capacidades[k] = k === 'consultar'; d.motivo_bloqueo = 'Acceso de solo lectura.'; }
  const response = (data, status = 200) => ({ ok: status === 200, status, json: async () => ({ success: status === 200, data, csrf_token: 'token', message: 'Conflicto' }) });
  const context = vm.createContext({ document: { getElementById: id => nodes.get(id), createElement: tag => new Element(tag) }, window: { location: { replace: u => redirects.push(u) }, confirm: text => { confirmations.push(text); return true; } }, URLSearchParams, AbortController, WeakMap, buildApiUrl: x => x, buildFrontendUrl: x => x,
    fetch: async (url, options) => {
      calls.push({ url, options });
      if (options.method === 'PUT') { if (deferred) return new Promise(r => { resolvePut = () => r(response(d, putStatus)); }); return response(d, putStatus); }
      if (url.includes('tipos_residuo')) return response([{ id_tipo_residuo: 38, nombre: 'Plástico' }, { id_tipo_residuo: 49, nombre: '<svg onload=x>' }]);
      const q = new URL(url, 'http://test').searchParams;
      if (q.has('opciones')) { if (optionsConflict) return response(null,409); return response({ items: candidates ? [{ id_cuadrilla: 7, nombre: '<img onerror=x>', id_vehiculo: 2, matricula: 'TEST', funcion_operativa: 'APOYO', id_asignacion_vehiculo: 9, id_usa: 4 }] : [], motivo: candidates ? null : 'No hay candidatas.' }); }
      if (q.has('id')) return response(d);
      return response({ items: [{ ...d.solicitud, requiere_confirmacion: true, historica_programada_sin_intento: true }], page: Number(q.get('page')), limit: 20, has_more: true }, listStatus);
    } });
  vm.runInContext(source, context);
  return { nodes, calls, redirects, confirmations, d, api: context.window.SolicitudesAdmin, finish: () => resolvePut(), detail: () => nodes.get('requestsDetail') };
}
async function ready(options) { const h = harness(options); await h.api.open(); all(h.nodes.get('requestsRows')).find(n => n.textContent === 'Ver detalle').listeners.click(); await tick(); return h; }
const formNamed = (h, text) => all(h.detail()).find(n => n.tag === 'form' && all(n).some(c => c.tag === 'button' && c.textContent === text));
const send = form => form.listeners.submit({ preventDefault() {} });
test('integración del panel y renderizado seguro de detalle/historial sin fabricar registros', async () => {
  assert.match(html, /data-view="solicitudes"/); assert.match(html, /assets\/js\/solicitudes-admin.js/);
  const h = await ready(); const text = all(h.detail()).map(n => n.textContent).join(' ');
  assert.match(text, /<script>x<\/script>/); assert.match(text, /Sin intentos registrados/); assert.match(text, /Programada histórica/);
  assert.match(all(h.nodes.get('requestsRows')).map(n => n.textContent).join(' '), /pendiente de confirmación/);
});
test('filtros y paginación utilizan únicamente parámetros existentes', async () => {
  const h = harness(); await h.api.open(); h.nodes.get('requestsNext').listeners.click(); await tick();
  assert.match(h.calls.at(-1).url, /page=2/);
  h.nodes.get('requestsState').value = 'Cerradas'; h.nodes.get('requestsService').value = 'Reciclables'; h.nodes.get('requestsFrom').value = '2026-01-01'; h.nodes.get('requestsTo').value = '2026-10-07';
  send(h.nodes.get('requestsFilters')); await tick(); const q = new URL(h.calls.at(-1).url, 'http://test').searchParams;
  assert.equal(q.get('page'), '1'); assert.equal(q.get('estado'), 'Cerradas'); assert.equal(q.get('tipo_solicitud'), 'Reciclables'); assert.equal(q.get('desde'), '2026-01-01'); assert.equal(q.get('limit'), '20');
});
test('confirmación explícita histórica avisa reclasificación y envía CSRF/valores esperados', async () => {
  const h = await ready(); const f = formNamed(h, 'Confirmar residuo'); const select = all(f).find(n => n.tag === 'select'); select.value = '49'; select.listeners.change();
  assert.match(all(f).map(n => n.textContent).join(' '), /modificar la clasificación/); send(f); await tick();
  const c = h.calls.find(c => c.options.method === 'PUT'); assert.equal(c.options.headers['X-CSRF-Token'], 'token');
  assert.deepEqual(JSON.parse(c.options.body), { accion: 'confirmar_residuo', id_solicitud: 14, estado_esperado: 'Pendiente', version_esperada: 'a'.repeat(64), id_atencion_esperada: null, id_tipo_residuo: 49 });
});
test('selector sin candidatas muestra motivo y bloquea asignación', async () => {
  const h = await ready({ candidates: false }); const f = formNamed(h, 'Asignar'); assert.equal(all(f).find(n => n.tag === 'button').disabled, true);
  assert.match(all(f).map(n => n.textContent).join(' '), /No hay candidatas/); send(f); await tick(); assert.equal(h.calls.some(c => c.options.method === 'PUT'), false);
});
test('asignación evita doble envío y no agrega vehículo, recorrido, actor ni fecha', async () => {
  const h = await ready({ deferred: true }); const f = formNamed(h, 'Asignar'); all(f).find(n => n.tag === 'select').value = '9'; send(f); send(f); await tick();
  const puts = h.calls.filter(c => c.options.method === 'PUT'); assert.equal(puts.length, 1);
  assert.deepEqual(JSON.parse(puts[0].options.body), { accion: 'asignar', id_solicitud: 14, estado_esperado: 'Pendiente', version_esperada: 'a'.repeat(64), id_atencion_esperada: null, id_cuadrilla: 7, id_asignacion_vehiculo: 9, id_usa: 4 });
  h.finish(); await tick(); assert.equal(h.nodes.get('requestsState').disabled, false);
});
test('409 refresca detalle/opciones y conserva filtros/página sin reintentar PUT', async () => {
  const h = await ready({ putStatus: 409 }); h.nodes.get('requestsNext').listeners.click(); await tick();
  const f = formNamed(h, 'Asignar'); all(f).find(n => n.tag === 'select').value = '9'; send(f); await tick();
  assert.equal(h.calls.filter(c => c.options.method === 'PUT').length, 1); assert.equal(h.calls.filter(c => c.url.includes('opciones=asignacion')).length, 2);
  assert.match(h.nodes.get('requestsStatus').textContent, /confirmá nuevamente/); assert.match(h.calls.findLast(c => c.url.includes('solicitudes_admin.php') && !c.url.includes('id=') && c.options.method === 'GET').url, /page=2/);
});
test('solo lectura no consulta candidatas ni ofrece formularios', async () => {
  const h = await ready({ modify: false }); assert.equal(all(h.detail()).some(n => n.tag === 'form'), false); assert.equal(h.calls.some(c => c.url.includes('opciones=')), false);
  assert.match(all(h.detail()).map(n => n.textContent).join(' '), /solo lectura/);
});
test('403 explica falta de permisos; 401 redirige a login', async () => {
  const h = harness({ listStatus: 403 }); await h.api.open(); assert.match(h.nodes.get('requestsStatus').textContent, /No disponés de permisos/);
  const u = harness({ listStatus: 401 }); await u.api.open(); assert.deepEqual(u.redirects, ['login.html']);
});
test('reasignar, desasignar y cancelar requieren motivo y confirmación', async () => {
  for (const [action,label] of [['reasignar','Reasignar'],['desasignar','Desasignar'],['cancelar','Cancelar solicitud']]) {
    const h = harness(); h.d.solicitud.estado = h.d.estado_esperado = 'Programada'; h.d.id_atencion_esperada = 5;
    h.d.capacidades[action] = true; h.d.capacidades.asignar = false; await h.api.open(); all(h.nodes.get('requestsRows')).find(n => n.textContent === 'Ver detalle').listeners.click(); await tick();
    const f = formNamed(h,label); if(action==='reasignar') all(f).find(n=>n.tag==='select').value='9';
    send(f); await tick(); assert.equal(h.calls.some(c=>c.options.method==='PUT'),false);
    all(f).find(n=>n.tag==='textarea').value='Motivo explícito'; send(f); await tick();
    const body=JSON.parse(h.calls.find(c=>c.options.method==='PUT').options.body); assert.equal(body.accion,action); assert.equal(body.motivo,'Motivo explícito'); assert.equal(body.id_atencion_esperada,5); assert.equal(h.confirmations.length,1);
  }
});
test('En atención y terminales no ofrecen acciones incluso ante capacidades contradictorias',async()=>{
  for(const state of ['En atención','Finalizada','Cancelada']){
    const h=harness();h.d.solicitud.estado=state;await h.api.open();all(h.nodes.get('requestsRows')).find(n=>n.textContent==='Ver detalle').listeners.click();await tick();assert.equal(all(h.detail()).some(n=>n.tag==='form'),false);
  }
});
test('historial real preserva referencias y paginación propia',async()=>{
  const h=harness();h.d.historial_has_more=true;h.d.historial=[{id_atencion_solicitud:3,estado:'Interrumpida',id_cuadrilla:7,id_asignacion_vehiculo:8,fecha_asignacion:'2026-01-01',id_usuario_asigna:2,motivo_cierre:'<img onerror=x>'}];
  await h.api.open();all(h.nodes.get('requestsRows')).find(n=>n.textContent==='Ver detalle').listeners.click();await tick();assert.match(all(h.detail()).map(n=>n.textContent).join(' '),/Utilización V19 #8/);
  all(h.detail()).find(n=>n.textContent==='Historial siguiente').listeners.click();await tick();assert.ok(h.calls.some(c=>c.url.includes('historial_page=2')));
});
test('conflicto en opciones refresca una vez y evita bucle de consultas',async()=>{
  const h=await ready({optionsConflict:true});assert.equal(h.calls.filter(c=>c.url.includes('opciones=asignacion')).length,2);assert.match(h.nodes.get('requestsStatus').textContent,/disponibilidad cambió/);
});
test('pausar descarta detalle anterior y no guarda desde formularios obsoletos',async()=>{
  const h=await ready();const f=formNamed(h,'Asignar');all(f).find(n=>n.tag==='select').value='9';h.api.pause();send(f);await tick();assert.equal(h.calls.some(c=>c.options.method==='PUT'),false);assert.equal(h.detail().hidden,true);
});
