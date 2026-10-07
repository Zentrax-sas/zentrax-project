const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '../public');
class Element {
  constructor(tag = '') { this.tag = tag; this.children = []; this.textContent = ''; this.listeners = {}; this.value = ''; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  querySelectorAll(tag) { return this.children.flatMap(c => [...(c.tag === tag ? [c] : []), ...c.querySelectorAll(tag)]); }
  querySelector(tag) { return this.querySelectorAll(tag)[0]; }
  focus() {}
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const text = e => e.textContent + e.children.map(text).join(' ');
const row = (state = 'Asignada', functional = 'Pendiente', id = 1) => ({ id_incidencia: id, tracking_number: `INC-${id}`, estado: functional, prioridad: 'Alta', tipo_problema: '<script>', descripcion: '<img>', fecha_reporte: '2026-10-05', atencion: state ? { id_atencion_incidencia: 10 + id, estado: state } : null });
const body = (rows = [row()], operate = true, more = false) => ({ success: true, data: rows, meta: { page: 1, has_more: more }, contexto_operativo: { puede_operar_incidencias: operate, cuadrilla: { nombre: 'Propia', turno: 'Matutino' }, funcion_operativa: 'APOYO', vehiculo: { matricula: 'APOYO1', estado: 'Disponible' }, recorrido: null } });
function harness() {
  const elements = new Map(), requests = [], redirects = [], events = {};
  const html = fs.readFileSync(path.join(root, 'recoleccion.html'), 'utf8');
  for (const m of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*id="([^"]+)"[^>]*>/g)) elements.set(m[2], new Element(m[1]));
  // Connect only the module subtree; the scripts still run against the actual HTML IDs.
  elements.get('ownIncidents').append(...[...elements].filter(([id]) => id.startsWith('ownIncident') && id !== 'ownIncidents').map(([, e]) => e));
  const context = vm.createContext({ URLSearchParams, AbortController, Event, buildApiUrl: p => p, buildFrontendUrl: p => p,
    document: { getElementById: id => elements.get(id), createElement: tag => new Element(tag) },
    window: { location: { replace: p => redirects.push(p) }, addEventListener: (n, f) => { (events[n] ||= []).push(f); }, dispatchEvent: e => events[e.type]?.forEach(f => f(e)) },
    fetch: (url, options = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) });
  vm.runInContext(fs.readFileSync(path.join(root, 'assets/js/recoleccion-incidencias.js'), 'utf8'), context);
  return { elements, requests, redirects, run: file => vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), context),
    reply(index, json, status = 200) { requests[index].resolve({ status, ok: status < 300, json: async () => json }); },
    click(label, parent = elements.get('ownIncidents')) { const b = parent.querySelectorAll('button').find(b => b.textContent === label); assert.ok(b, label); return b.listeners.click(); } };
}
async function ready(rows, operate = true) { const h = harness(); h.reply(0, body(rows, operate)); await tick(); return h; }
test('listado, detalle seguro, paginación y matriz de acciones dependen del servidor', async () => {
  const rows = [row(), row('Aceptada','Pendiente',2), row('En atención','En Proceso',3), row('Finalizada','Resuelta',4), row(null,'Pendiente',5), row('En atención','Pendiente',6)];
  const h = await ready(rows);
  const cards = h.elements.get('ownIncidentList').children;
  assert.deepEqual(cards.map(c => c.querySelectorAll('button').map(b => b.textContent)), [['Ver detalle','Aceptar','Rechazar'],['Ver detalle','Iniciar atención'],['Ver detalle','Finalizar atención'],['Ver detalle'],['Ver detalle'],['Ver detalle']]);
  assert.match(text(cards[0]), /<script>/); assert.match(text(h.elements.get('ownIncidentContext')), /APOYO1.*APOYO/);
  h.click('Ver detalle', cards[0]); assert.match(h.requests[1].url, /view=incidencias_propias&id_incidencia=1/);
  h.reply(1, body([row()])); await tick(); assert.match(text(h.elements.get('ownIncidentDetail')), /<img>/);
  h.elements.get('ownIncidentNext').listeners.click(); assert.match(h.requests[2].url,/page=2/);
  h.reply(2, { ...body([]), meta: { page: 2, has_more: false } }); await tick();
  assert.equal(h.elements.get('ownIncidentPrevious').disabled, false); assert.match(text(h.elements.get('ownIncidentStatus')), /No tenés incidencias/);
  const readonly = await ready([row()], false); assert.deepEqual(readonly.elements.get('ownIncidentList').querySelectorAll('button').map(b => b.textContent), ['Ver detalle']);
});
test('aceptar, iniciar y finalizar envían contrato exacto y consultan después del éxito', async () => {
  for (const [state, functional, label, action] of [['Asignada','Pendiente','Aceptar','aceptar_incidencia'],['Aceptada','Pendiente','Iniciar atención','iniciar_atencion_incidencia'],['En atención','En Proceso','Finalizar atención','finalizar_atencion_incidencia']]) {
    const h = await ready([row(state,functional)]); const saving = h.click(label); h.click(label);
    assert.equal(h.requests.length,2);
    assert.deepEqual(JSON.parse(h.requests[1].options.body), { accion: action, id_incidencia:1, id_atencion_incidencia:11, estado_operativo_esperado:state });
    assert.equal(h.requests[1].url, '/backend/api/recoleccion.php'); h.reply(1,{success:true}); await tick();
    assert.match(h.requests[2].url,/page=1/); h.reply(2,body([])); await saving;
    assert.match(text(h.elements.get('ownIncidentStatus')), /Operación confirmada por el servidor/);
  }
});
test('rechazo valida motivo, conserva texto ante 400 y desaparece tras éxito', async () => {
  const h = await ready(); h.click('Rechazar'); const form = h.elements.get('ownIncidentList').querySelector('form'), input = form.querySelector('textarea');
  const submit = () => form.listeners.submit({ preventDefault() {} });
  input.value='  '; submit(); assert.equal(h.requests.length,1);
  input.value='á'.repeat(501); submit(); assert.equal(h.requests.length,1);
  input.value='  Falta equipo  '; submit();
  assert.deepEqual(JSON.parse(h.requests[1].options.body),{accion:'rechazar_incidencia',id_incidencia:1,id_atencion_incidencia:11,estado_operativo_esperado:'Asignada',motivo:'Falta equipo'});
  h.reply(1,{success:false,message:'Motivo inválido'},400); await tick(); assert.equal(input.value,'  Falta equipo  ');
  submit(); h.reply(2,{success:true}); await tick(); h.reply(3,body([])); await tick(); assert.equal(h.elements.get('ownIncidentList').children.length,0);
});
test('sin recorrido carga F4 de forma independiente y conserva consultas existentes', async () => {
  const h = harness(); h.run('assets/js/recoleccion.js');
  assert.match(h.requests[0].url,/view=incidencias_propias/); assert.equal(h.requests[1].url,'/backend/api/recoleccion.php');
  h.reply(1,{success:false,code:'sin_recorrido',data:{pertenencia:{nombre:'Propia',turno:'Matutino'},recorrido:null}},409);
  h.reply(0,body()); await tick();
  assert.match(text(h.elements.get('collectionItems')), /Sin recorrido asignado/);
  assert.match(text(h.elements.get('ownIncidentList')), /INC-1/);
});
test('403 retira acciones; 409 y 404 consultan sin repetir POST', async () => {
  for (const code of [403,409,404]) {
    const h = await ready(); const saving = h.click('Aceptar'); h.reply(1,{success:false,code:'conflicto_operativo',message:'Cambió'},code); await tick();
    h.reply(2,body()); await saving;
    assert.equal(h.requests.filter(r=>r.options.method==='POST').length,1);
    if(code===403) assert.deepEqual(h.elements.get('ownIncidentList').querySelectorAll('button').map(b=>b.textContent),['Ver detalle']);
    else assert.match(text(h.elements.get('ownIncidentStatus')), /cambió|ya no está disponible/);
  }
});
test('refresh fallido distingue éxito confirmado; 401 limpia; red permite actualizar', async () => {
  const h=await ready(); const saving=h.click('Aceptar'); h.reply(1,{success:true}); await tick(); h.requests[2].reject(new Error('Red')); await saving;
  assert.match(text(h.elements.get('ownIncidentStatus')),/Operación confirmada, pero no se pudo actualizar/); assert.equal(h.elements.get('ownIncidentRefresh').disabled,false);
  const expired=await ready(); expired.elements.get('ownIncidentRefresh').listeners.click(); expired.reply(1,{success:false},401); await tick();
  assert.deepEqual(expired.redirects,['login.html']); assert.equal(expired.elements.get('ownIncidentList').children.length,0);
  const lost=await ready(); const pending=lost.click('Aceptar'); lost.requests[1].reject(new Error('Red')); await pending;
  assert.match(text(lost.elements.get('ownIncidentStatus')),/No se pudo confirmar/); assert.equal(lost.elements.get('ownIncidentRefresh').disabled,false);
});
