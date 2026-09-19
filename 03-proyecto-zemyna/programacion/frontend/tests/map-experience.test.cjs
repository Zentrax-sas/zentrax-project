const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const publicDir = path.join(__dirname, '../public');
const source = fs.readFileSync(path.join(publicDir, 'assets/js/zemyna-map.js'), 'utf8');
class Element {
  constructor(tag = '') { this.tag = tag; this.checked = false; this.value = ''; this.textContent = ''; this.children = []; this.listeners = {}; this.dataset = {}; this.style = {}; this.files = []; this.classList = { add() {}, remove() {}, toggle() {} }; }
  set value(value) { this._value = String(value); }
  get value() { return this._value; }
  querySelector() { return new Element(); }
  querySelectorAll() { return []; }
  reportValidity() { return true; }
  reset() {}
  replaceChildren(...nodes) { this.children = nodes; }
  appendChild(node) { this.children.push(node); }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  append(...nodes) { this.children.push(...nodes); }
  change() { this.listeners.change?.(); }
}
function text(node) { return node.textContent + (node.children || []).map(text).join(' '); }
function harness(administrative = false, callbacks = {}, bootPublic = false) {
  const html = fs.readFileSync(path.join(publicDir, administrative ? 'admin.html' : 'index.html'), 'utf8');
  const elements = new Map();
  for (const match of html.matchAll(/<([a-z][a-z0-9]*)\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
    const element = new Element(match[1]); element.checked = /\bchecked\b/.test(match[2]); elements.set(match[3], element);
  }
  const groups = [], requests = [], timers = new Map(); let timerId = 0, zoom = 14;
  const events = new Map();
  const bounds = { getSouthWest: () => ({ lat: -35, lng: -56.3 }), getNorthEast: () => ({ lat: -34.8, lng: -56 }), contains: () => true };
  const map = { setView() { return this; }, getZoom: () => zoom, getBounds: () => bounds, addLayer() {}, removeLayer() {}, invalidateSize() {},
    on(names, fn) { for (const name of names.split(' ')) { if (!events.has(name)) events.set(name, []); events.get(name).push(fn); } } };
  const marker = (coords, options) => ({ coords, options, events: {}, bindPopup(popup) { this.popup = popup; return this; }, setPopupContent(popup) { this.popup = popup; },
    setLatLng(coords) { this.coords = coords; }, getLatLng() { return this.coords; }, setStyle() {}, on(event, fn) { this.events[event] = fn; } });
  const L = { Control: { extend: () => class { addTo() {} } }, control: {}, map: () => map, marker, circleMarker: marker, divIcon: options => options, tileLayer: () => ({ addTo() {} }),
    markerClusterGroup() { const group = { markers: new Set(), addTo() { return this; }, addLayer(m) { this.markers.add(m); }, removeLayer(m) { this.markers.delete(m); }, clearLayers() { this.markers.clear(); } }; groups.push(group); return group; } };
  const context = vm.createContext({ window: { location: { protocol: 'http:' } }, navigator: {}, L, console, Map, Set, URLSearchParams, AbortController,
    document: { getElementById: id => elements.get(id) || null, createElement: tag => new Element(tag), createTextNode: value => ({ textContent: value }) },
    buildApiUrl: value => value, setTimeout: fn => { timers.set(++timerId, fn); return timerId; }, clearTimeout: id => timers.delete(id),
    fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) });
  vm.runInContext(source, context);
  let instance;
  if (bootPublic === 'admin') {
    context.ZemynaMap = context.window.ZemynaMap;
    context.location = { hash: '' };
    context.window.setInterval = () => 1;
    context.window.clearInterval = () => {};
    context.window.addEventListener = () => {};
    context.window.scrollTo = () => {};
    context.window.location.replace = () => {};
    context.document.querySelectorAll = () => [];
    context.document.querySelector = () => new Element();
    context.document.addEventListener = () => {};
    context.buildFrontendUrl = value => value;
    vm.runInContext(fs.readFileSync(path.join(publicDir, 'assets/js/admin.js'), 'utf8'), context);
  } else if (bootPublic) {
    context.ZemynaMap = context.window.ZemynaMap;
    vm.runInContext(fs.readFileSync(path.join(publicDir, 'mapa.js'), 'utf8'), context);
    instance = vm.runInContext('citizenMap', context);
  } else instance = context.window.ZemynaMap.create({ administrative, ...callbacks });
  return { instance, html, elements, groups, requests, run: code => vm.runInContext(code, context), zoom: value => { zoom = value; },
    emit: name => { for (const fn of events.get(name) || []) fn(); },
    flush: () => { const batch = [...timers.values()]; timers.clear(); for (const fn of batch) fn(); },
    incidents: () => requests.filter(r => r.url.includes('/incidencias.php')), containers: () => requests.filter(r => r.url.includes('/contenedores.php')) };
}
const item = (id = 1, estado = 'Pendiente') => ({ id_incidencia: id, estado, prioridad: 'Alta', tipo_problema: 'Contenedor Desbordado', fecha_reporte: '2026-09-18', contenedor_codigo: 'C-1', latitud: -34.91, longitud: -56.15, tracking_number: 'privado', descripcion: 'privado' });
const respond = (request, data = [], status = 200, meta = {}) => request.resolve({ status, ok: status < 300, headers: { get: () => 'application/json' }, text: async () => JSON.stringify({ success: status < 300, data, meta }), json: async () => ({ success: status < 300, data, meta }) });
const tick = () => new Promise(resolve => setImmediate(resolve));
function enable(h) { h.elements.get('map-show-incidents').checked = true; h.elements.get('map-show-incidents').change(); }

test('publico conserva contenedores y no consulta incidencias apagadas', () => {
  const h = harness(); assert.equal(h.elements.get('map-show-containers').checked, true);
  assert.equal(h.containers().length, 1); assert.equal(h.incidents().length, 0);
  h.emit('moveend'); h.flush(); assert.equal(h.incidents().length, 0);
  assert.equal(h.elements.has('map-incident-priority'), false); assert.equal(h.elements.has('map-incident-state'), false);
});
test('publico consulta dos estados activos y deduplica sin mostrar resueltas ni prioridad', async () => {
  const h = harness(); enable(h);
  assert.deepEqual(h.incidents().map(r => new URLSearchParams(r.url.split('?')[1]).get('estado')), ['Pendiente', 'En Proceso']);
  respond(h.incidents()[0], [item(), item(2, 'Resuelta')]); respond(h.incidents()[1], [item(), item(3, 'En Proceso')]); await tick();
  assert.equal(h.groups[1].markers.size, 2);
  for (const marker of h.groups[1].markers) { const content = text(marker.popup); assert.doesNotMatch(content, /Prioridad|Alta|privado|Resuelta/); assert.match(content, /Estado:|Fecha:|Contenedor:/); assert.deepEqual(Object.keys(marker.events), []); }
});
test('desactivar cancela ambas consultas y descarta sus respuestas', async () => {
  const h = harness(); enable(h); const requests = h.incidents();
  h.elements.get('map-show-incidents').checked = false; h.elements.get('map-show-incidents').change();
  assert.ok(requests.every(r => r.options.signal.aborted));
  requests.forEach(r => respond(r, [item()])); await tick(); assert.equal(h.groups[1].markers.size, 0);
  h.emit('moveend'); h.flush(); assert.equal(h.incidents().length, 2);
});
test('leyenda legible y contenedor seleccionable sin cambios desde incidencias', async () => {
  let selected; const h = harness(false, { onContainerSelect: data => { selected = data.id_contenedor; } });
  respond(h.containers()[0], [{ id_contenedor: 9, codigo: 'C-9', latitud: -34.91, longitud: -56.15 }]); await tick();
  [...h.groups[0].markers][0].events.click(); assert.equal(selected, 9);
  const legend = h.html.match(/<p class="map-legend">([^<]+)<\/p>/)[1];
  assert.doesNotMatch(legend, /�|Ã|Â|«|»/); assert.match(legend, /grupos numerados/);
});
test('administracion solicita ruta protegida y filtra estado y prioridad', async () => {
  const h = harness(true);
  assert.match(h.incidents()[0].url, /admin=1/);
  h.elements.get('map-incident-state').value = 'Resuelta'; h.elements.get('map-incident-state').change();
  h.elements.get('map-incident-priority').value = 'Alta'; h.elements.get('map-incident-priority').change(); h.flush();
  const r = h.incidents().at(-1); assert.match(r.url, /estado=Resuelta/); assert.match(r.url, /prioridad=Alta/);
  respond(r, [item(2, 'Resuelta')]); await tick(); assert.equal(h.groups[1].markers.size, 1);
  assert.match(text([...h.groups[1].markers][0].popup), /Prioridad: Alta/);
});
test('administracion reutiliza detalle sin insertar datos privados en popup', async () => {
  let selected; const h = harness(true, { onIncidentSelect: id => { selected = id; } });
  respond(h.incidents()[0], [item()]); await tick(); const popup = [...h.groups[1].markers][0].popup;
  assert.doesNotMatch(text(popup), /privado/); popup.children.find(c => c.tag === 'button').listeners.click(); assert.equal(selected, 1);
});
test('movimiento rapido aborta y descarta respuestas obsoletas administrativas', async () => {
  const h = harness(true); const old = h.incidents()[0]; h.emit('movestart'); assert.equal(old.options.signal.aborted, true);
  h.emit('moveend'); h.emit('moveend'); h.flush(); assert.equal(h.incidents().length, 2);
  respond(h.incidents()[1], [item(2), item(2)]); await tick(); respond(old, [item(1)]); await tick();
  assert.equal(h.groups[1].markers.size, 1); assert.match(text([...h.groups[1].markers][0].popup), /Estado:/);
});
test('actualizaciones repetidas no duplican marcadores administrativos', async () => {
  const h = harness(true); respond(h.incidents()[0], [item(), item()]); await tick(); const marker = [...h.groups[1].markers][0];
  h.instance.refreshIncidents(); h.flush(); respond(h.incidents().at(-1), [item()]); await tick();
  assert.equal(h.groups[1].markers.size, 1); assert.equal([...h.groups[1].markers][0], marker);
});
test('perder autorizacion limpia el mapa y detiene solicitudes', async () => {
  let denied = false; const h = harness(true, { onAccessDenied: () => { denied = true; } });
  respond(h.incidents()[0], [], 403); await tick(); assert.equal(denied, true); assert.equal(h.groups[1].markers.size, 0);
  h.emit('moveend'); h.flush(); assert.equal(h.incidents().length, 1);
});
test('carga vacio error y zoom minimo tienen estados controlados', async () => {
  const h = harness(true); const status = h.elements.get('mapa-incidencias-status'); assert.equal(status.dataset.state, 'loading');
  respond(h.incidents()[0]); await tick(); assert.equal(status.dataset.state, 'empty');
  h.instance.refreshIncidents(); h.flush(); h.incidents().at(-1).reject(new Error('network')); await tick(); assert.equal(status.dataset.state, 'error');
  h.zoom(12); h.instance.refreshIncidents(); h.flush(); assert.equal(h.incidents().length, 2); assert.equal(h.groups[1].markers.size, 0);
});
test('fallo de una consulta activa cancela la otra y no presenta resultado parcial', async () => {
  const h = harness(); enable(h); h.incidents()[0].reject(new Error('network')); await tick();
  assert.ok(h.incidents()[1].options.signal.aborted); assert.equal(h.groups[1].markers.size, 0); assert.equal(h.elements.get('mapa-incidencias-status').dataset.state, 'error');
});

test('arranque ciudadano, seleccion, registro y tracking mantienen el recorrido real del script', async () => {
  const h = harness(false, {}, true);
  respond(h.containers()[0], [{ id_contenedor: 9, codigo: 'C-9', direccion: 'Dirección', latitud: -34.91, longitud: -56.15 }]); await tick();
  const selecting = [...h.groups[0].markers][0].events.click();
  respond(h.requests.at(-1), { direccion: 'Dirección verificada' }); await selecting;
  assert.equal(h.elements.get('form-id-contenedor').value, '9');
  h.elements.get('tipo_incidencia').value = 'desborde';
  const submitting = h.elements.get('submit-reporte').listeners.click();
  const request = h.requests.at(-1); assert.equal(request.options.method, 'POST');
  assert.equal(JSON.parse(request.options.body).id_contenedor, '9');
  respond(request, { id_incidencia: 12, tracking_number: 'INC-2026-ABCDE' }, 201); await submitting;
  assert.equal(h.elements.get('tracking-code-confirmacion').textContent, 'INC-2026-ABCDE');
  assert.equal(h.elements.get('reporte-confirmacion').hidden, false);
  h.elements.get('tracking-number').value = 'INC-2026-ABCDE';
  const tracking = h.elements.get('tracking-form').listeners.submit({ preventDefault() {} });
  respond(h.requests.at(-1), { tracking_number: 'INC-2026-ABCDE', estado: 'Resuelta', fecha_reporte: '2026-09-18', tipo_problema: 'Contenedor Desbordado' }); await tracking;
  assert.equal(h.elements.get('tracking-result').hidden, false);
  assert.match(text(h.elements.get('tracking-result')), /Resuelta/);
});

test('panel no inicializa Leaflet antes de validar sesion y permiso', async () => {
  const h = harness(true, {}, 'admin');
  const opening = h.run('abrirMapaOperativo()');
  assert.equal(h.groups.length, 0); assert.equal(h.requests.length, 1);
  respond(h.requests[0], { nombre: 'Prueba', apellido: '', roles: [] }); await tick();
  assert.equal(h.groups.length, 0); assert.equal(h.requests.length, 2);
  respond(h.requests[1], []); await opening;
  assert.equal(h.groups.length, 2); assert.equal(h.elements.get('operationalMapPanel').hidden, false);
});
test('panel no construye mapa ante sesion denegada', async () => {
  const h = harness(true, {}, 'admin'); const opening = h.run('abrirMapaOperativo()');
  respond(h.requests[0], [], 401); await opening;
  assert.equal(h.groups.length, 0); assert.equal(h.elements.get('operationalMapPanel').hidden, true);
});
test('panel no construye mapa ante permiso denegado', async () => {
  const h = harness(true, {}, 'admin'); const opening = h.run('abrirMapaOperativo()');
  respond(h.requests[0], { nombre: 'Prueba', roles: [] }); await tick();
  respond(h.requests[1], [], 403); await opening;
  assert.equal(h.groups.length, 0); assert.equal(h.elements.get('operationalMapPanel').hidden, true);
});


test('popups publicos y administrativos conservan acentos sin reconversion', async () => {
  for (const administrative of [false, true]) {
    const h = harness(administrative); if (!administrative) enable(h);
    const row = { ...item(), tipo_problema: 'Contenedor Roto/Dañado', contenedor_codigo: 'Prueba á é í ó ú Á É Í Ó Ú ñ Ñ ü Ü ¿ ¡' };
    for (const request of h.incidents()) respond(request, [row]);
    await tick();
    const content = text([...h.groups[1].markers][0].popup);
    assert.ok(content.includes(row.tipo_problema));
    assert.ok(content.includes(row.contenedor_codigo));
    assert.doesNotMatch(content, /Ã|Â|�/);
  }
});
