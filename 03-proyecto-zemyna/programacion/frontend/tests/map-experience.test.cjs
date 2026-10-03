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
  setAttribute(name, value) { this[name] = value; }
  showModal() { this.open = true; }
  close() { this.open = false; this.listeners.close?.(); }
  focus() { this.focused = true; }
  scrollIntoView() {}
  reset() {}
  replaceChildren(...nodes) { this.children = nodes; }
  appendChild(node) { this.children.push(node); }
  add(node) { this.children.push(node); }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  append(...nodes) { this.children.push(...nodes); }
  change() { this.listeners.change?.(); }
}
function text(node) { return node.textContent + (node.children || []).map(text).join(' '); }
function harness(administrative = false, callbacks = {}, bootPublic = false, page = null) {
  const html = fs.readFileSync(path.join(publicDir, page || (administrative ? 'admin.html' : 'index.html')), 'utf8');
  const elements = new Map();
  for (const match of html.matchAll(/<([a-z][a-z0-9]*)\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
    const element = new Element(match[1]); element.checked = /\bchecked\b/.test(match[2]); elements.set(match[3], element);
  }
  const groups = [], requests = [], layers = new Set(), views = [], timers = new Map(); let timerId = 0, zoom = 14;
  const events = new Map();
  const bounds = { getSouthWest: () => ({ lat: -35, lng: -56.3 }), getNorthEast: () => ({ lat: -34.8, lng: -56 }), contains: () => true };
  const map = { setView(coords, level) { views.push({ coords, level }); return this; }, getZoom: () => zoom, getBounds: () => bounds, addLayer(layer) { layers.add(layer); }, removeLayer(layer) { layers.delete(layer); }, invalidateSize() {},
    on(names, fn) { for (const name of names.split(' ')) { if (!events.has(name)) events.set(name, []); events.get(name).push(fn); } } };
  const marker = (coords, options) => ({ coords, options, events: {}, bindPopup(popup) { this.popup = popup; return this; }, setPopupContent(popup) { this.popup = popup; },
    setLatLng(coords) { this.coords = coords; }, addTo(target) { target.addLayer(this); return this; }, getLatLng() { return { lat: this.coords[0], lng: this.coords[1] }; }, setStyle() {}, on(event, fn) { this.events[event] = fn; } });
  const L = { Control: { extend: () => class { addTo() {} } }, control: {}, map: () => map, marker, circleMarker: marker, divIcon: options => options, tileLayer: () => ({ addTo() {} }),
    markerClusterGroup() { const group = { markers: new Set(), addTo() { return this; }, addLayer(m) { this.markers.add(m); }, removeLayer(m) { this.markers.delete(m); }, clearLayers() { this.markers.clear(); } }; groups.push(group); return group; } };
  L.layerGroup = L.markerClusterGroup;
  const context = vm.createContext({ window: { location: { protocol: 'http:' }, confirm: () => true }, navigator: {}, L, console, Map, Set, URLSearchParams, AbortController, Option: function (text, value) { const e = new Element('option'); e.textContent = text; e.value = value; return e; },
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
  } else if (bootPublic === 'standalone') {
    context.buildFrontendUrl = value => value;
  } else if (bootPublic) {
    context.ZemynaMap = context.window.ZemynaMap;
    vm.runInContext(fs.readFileSync(path.join(publicDir, 'mapa.js'), 'utf8'), context);
    instance = vm.runInContext('citizenMap', context);
  } else instance = context.window.ZemynaMap.create({ administrative, ...callbacks });
  return { instance, html, elements, groups, requests, layers, views, run: code => vm.runInContext(code, context), zoom: value => { zoom = value; },
    emit: (name, event) => { for (const fn of events.get(name) || []) fn(event); },
    flush: () => { const batch = [...timers.values()]; timers.clear(); for (const fn of batch) fn(); },
    incidents: () => requests.filter(r => r.url.includes('/incidencias.php')), containers: () => requests.filter(r => r.url.includes('/contenedores.php')) };
}
const item = (id = 1, estado = 'Pendiente') => ({ id_incidencia: id, estado, prioridad: 'Alta', tipo_problema: 'Contenedor Desbordado', fecha_reporte: '2026-09-18', contenedor_codigo: 'C-1', latitud: -34.91, longitud: -56.15, tracking_number: 'privado', descripcion: 'privado' });
const respond = (request, data = [], status = 200, meta = {}) => request.resolve({ status, ok: status < 300, headers: { get: () => 'application/json' }, text: async () => JSON.stringify({ success: status < 300, data, meta }), json: async () => ({ success: status < 300, data, meta }) });
const respondApi = (request, body, status = 200) => request.resolve({ status, ok: status < 300, headers: { get: () => 'application/json' }, text: async () => JSON.stringify(body), json: async () => body });
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
  h.elements.get('map-incident-state').value = 'En Proceso'; h.elements.get('map-incident-state').change();
  h.elements.get('map-incident-priority').value = 'Alta'; h.elements.get('map-incident-priority').change(); h.flush();
  const r = h.incidents().at(-1); assert.match(r.url, /estado=En\+Proceso/); assert.match(r.url, /prioridad=Alta/);
  assert.match(r.url, /activas=1/); respond(r, [item(2, 'En Proceso'), item(3, 'Resuelta')]); await tick(); assert.equal(h.groups[1].markers.size, 1);
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


async function reportHarness() {
  const h = harness(true, {}, 'admin');
  h.run(fs.readFileSync(path.join(publicDir, 'assets/js/incidence-report.js'), 'utf8'));
  respond(h.requests[0], { nombre: 'Prueba', roles: [] });
  const loading = h.run('window.IncidenceReport.load()');
  await tick();
  return { h, loading };
}
function reportResponse(request, data, total = 25) {
  request.resolve({ status: 200, ok: true, text: async () => JSON.stringify({ success: true, data,
    totals: { abiertas: total, cerradas: 0, total }, meta: { pages: Math.ceil(total / 20) } }) });
}
test('informe pagina filtra y escapa datos sin limitar los totales a la pagina', async () => {
  const { h, loading } = await reportHarness();
  assert.match(h.requests.at(-1).url, /view=report/);
  const row = { tracking_number: '<img onerror=alert(1)>', tipo_problema: 'Dañado', estado: 'Pendiente', prioridad: 'Media', fecha_reporte: '2026-09-19', contenedor_codigo: 'C-1' };
  reportResponse(h.requests.at(-1), [row]); await loading;
  assert.match(h.elements.get('reportRows').innerHTML, /&lt;img/);
  assert.doesNotMatch(h.elements.get('reportRows').innerHTML, /<img/);
  assert.match(h.elements.get('reportTotals').textContent, /Total: 25/);
  assert.equal(h.elements.get('reportNext').disabled, false);
  h.elements.get('reportNext').listeners.click(); await tick();
  assert.match(h.requests.at(-1).url, /page=2/);
  reportResponse(h.requests.at(-1), [row]); await tick();
  h.elements.get('reportGroup').value = 'cerradas';
  h.elements.get('reportFrom').value = '2026-01-01';
  h.elements.get('reportTo').value = '2026-12-31';
  h.elements.get('reportFilters').listeners.submit({ preventDefault() {} }); await tick();
  assert.match(h.requests.at(-1).url, /grupo=cerradas/);
  assert.match(h.requests.at(-1).url, /desde=2026-01-01/);
  assert.match(h.requests.at(-1).url, /hasta=2026-12-31/);
  assert.match(h.requests.at(-1).url, /page=1/);
});
test('informe cancela respuestas obsoletas y controla vacio error y carga', async () => {
  const { h, loading } = await reportHarness(); const old = h.requests.at(-1);
  assert.match(h.elements.get('reportMessage').textContent, /Cargando/);
  const next = h.run('window.IncidenceReport.load()'); await tick();
  assert.equal(old.options.signal.aborted, true);
  reportResponse(h.requests.at(-1), [], 0); await next;
  reportResponse(old, [{ tracking_number: 'obsoleto' }]); await loading;
  assert.equal(h.elements.get('reportRows').innerHTML, '');
  assert.match(h.elements.get('reportMessage').textContent, /No hay/);
  const denied = h.run('window.IncidenceReport.load()'); await tick();
  respond(h.requests.at(-1), [], 403); await denied;
  assert.equal(h.elements.get('reportNext').disabled, true);
  assert.match(h.elements.get('reportTotals').textContent, /pendientes/);
  assert.match(h.elements.get('reportMessage').textContent, /No se pudo/);
});


test('prioridades administrativas sin letras conservan forma colores y texto accesible; publico no muestra prioridad', async () => {
  const h = harness(true);
  respond(h.incidents()[0], ['Baja', 'Media', 'Alta'].map((prioridad, i) => ({ ...item(i + 1), prioridad })));
  await tick();
  const markers = [...h.groups[1].markers];
  for (const [i, priority] of ['Baja', 'Media', 'Alta'].entries()) {
    assert.doesNotMatch(markers[i].options.icon.html, /<b>[BMA]<\/b>/);
    assert.match(markers[i].options.icon.html, /incident-map-symbol/);
    assert.ok(markers[i].options.title.includes(`prioridad ${priority}`));
    assert.ok(markers[i].options.alt.includes(`prioridad ${priority}`));
    assert.ok(text(markers[i].popup).includes(`Prioridad: ${priority}`));
  }
  assert.equal(new Set(markers.map(m => m.options.icon.html)).size, 3);
  const p = harness(); enable(p); for (const req of p.incidents()) respond(req, [item()]); await tick();
  assert.match([...p.groups[1].markers][0].options.icon.html, /<b>!<\/b>/);
  assert.doesNotMatch([...p.groups[1].markers][0].options.title, /Alta|prioridad/);
});

test('localizar resuelta cancela carga masiva y volver elimina marcador individual', async () => {
  const h = harness(true); const pending = h.incidents()[0];
  assert.equal(h.instance.focusIncident(item(9, 'Resuelta')), true);
  assert.equal(pending.options.signal.aborted, true);
  assert.equal(h.groups[1].markers.size, 1);
  const marker = [...h.groups[1].markers][0];
  assert.match(text(marker.popup), /contenedor relacionado/);
  assert.match(marker.options.icon.html, /<b>R<\/b>/);
  h.emit('movestart'); h.emit('moveend'); h.flush();
  assert.equal(h.incidents().length, 1);
  respond(pending, [item()]); await tick();
  assert.equal(h.groups[1].markers.size, 1);
  h.instance.showActiveIncidents();
  assert.equal(h.groups[1].markers.size, 0);
  assert.match(h.incidents().at(-1).url, /activas=1/);
  respond(h.incidents().at(-1), [item(), item(9, 'Resuelta')]); await tick();
  assert.equal(h.groups[1].markers.size, 1);
  h.instance.focusIncident(item());
  assert.equal(h.groups[1].markers.size, 1);
  assert.equal(h.instance.focusIncident({ ...item(), latitud: null }), false);
  h.instance.refreshIncidents();
  assert.equal(h.groups[1].markers.size, 0);
  assert.match(h.incidents().at(-1).url, /activas=1/);
});


test('detalle muestra resolucion historica desconocida y oculta mapa sin ubicacion', async () => {
  const h = harness(true, {}, 'admin');
  const loading = h.run('mostrarIncidencia(9)');
  respond(h.requests.at(-1), [{ ...item(9, 'Resuelta'), fecha_resolucion: null }]); await tick();
  respond(h.requests.at(-1), null); await loading;
  assert.equal(h.elements.get('incidentViewMap').hidden, true);
  assert.match(h.elements.get('incidentDetailFields').innerHTML, /Fecha de resolución no registrada/);
  const located = h.run('mostrarIncidencia(9)');
  respond(h.requests.at(-1), [{ ...item(9, 'Resuelta'), fecha_resolucion: '2026-09-19 13:00:00' }]); await tick();
  respond(h.requests.at(-1), item(9, 'Resuelta')); await located;
  assert.equal(h.elements.get('incidentViewMap').hidden, false);
  assert.match(h.elements.get('incidentDetailFields').innerHTML, /2026-09-19 13:00:00/);
});


async function crewHarness(status = 200) {
  const h = harness(true, {}, 'admin');
  h.run(fs.readFileSync(path.join(publicDir, 'assets/js/crew-report.js'), 'utf8'));
  respond(h.requests[0], { nombre: 'Operario', roles: ['OPERARIO'] });
  const opening = h.run('window.CrewReport.open()'); await tick();
  respond(h.requests.at(-1), { tipos: ['Contenedor Desbordado', 'Obstruido por Vehículo'] }, status);
  await opening;
  return h;
}

test('reporte de cuadrilla exige permiso antes de mostrar formulario y mapa', async () => {
  const h = await crewHarness(403);
  assert.equal(h.elements.get('crewForm').hidden, true);
  assert.equal(h.groups.length, 0);
  assert.match(h.elements.get('crewStatus').textContent, /No se pudo/);
});

test('cuadrilla marca punto manual recibe tracking y no envia identidad ni duplicados', async () => {
  const h = await crewHarness();
  assert.equal(h.elements.get('crewForm').hidden, false);
  const submit = () => h.elements.get('crewForm').listeners.submit({ preventDefault() {} });
  await submit(); assert.match(h.elements.get('crewStatus').textContent, /Sin contenedor/);
  h.elements.get('crewLatitude').value = '-34.91'; h.elements.get('crewLongitude').value = '-56.15';
  h.elements.get('crewApplyPoint').listeners.click();
  assert.match(h.elements.get('crewLocation').textContent, /Ubicación marcada del problema/);
  h.elements.get('crewType').value = 'Obstruido por Vehículo'; h.elements.get('crewDescription').value = 'Problema observado';
  const saving = submit(); const request = h.requests.at(-1);
  assert.equal(request.options.method, 'POST'); assert.match(request.url, /view=crew/);
  assert.deepEqual(JSON.parse(request.options.body), { tipo_problema: 'Obstruido por Vehículo', descripcion: 'Problema observado', latitud: -34.91, longitud: -56.15 });
  const count = h.requests.length; await submit(); assert.equal(h.requests.length, count);
  respond(request, { tracking_number: 'INC-2026-ABCDE', id_incidencia: 23 }, 201); await saving;
  assert.match(h.elements.get('crewConfirmation').textContent, /INC-2026-ABCDE/);
  assert.equal(h.elements.get('crewFields').disabled, true);
  await submit(); assert.equal(h.requests.length, count);
});

test('cuadrilla selecciona contenedor sin inventar punto y conserva formulario ante fallo', async () => {
  const h = await crewHarness();
  respond(h.containers().at(-1), [{ id_contenedor: 9, codigo: 'C-9', latitud: -34.91, longitud: -56.15 }]); await tick();
  [...h.groups[0].markers][0].events.click();
  assert.match(h.elements.get('crewLocation').textContent, /ubicación del contenedor/);
  h.elements.get('crewType').value = 'Contenedor Desbordado'; h.elements.get('crewDescription').value = 'Desborde';
  const saving = h.elements.get('crewForm').listeners.submit({ preventDefault() {} });
  const body = JSON.parse(h.requests.at(-1).options.body);
  assert.equal(body.id_contenedor, 9); assert.equal('latitud' in body, false);
  h.requests.at(-1).reject(new Error('Error de red')); await saving;
  assert.equal(h.elements.get('crewFields').disabled, false);
  assert.equal(h.elements.get('crewDescription').value, 'Desborde');
  assert.match(h.elements.get('crewStatus').textContent, /Error de red/);
});

test('ubicacion del dispositivo es opcional y respuesta tardia no pisa seleccion manual', async () => {
  const h = await crewHarness();
  h.run('navigator.geolocation = { getCurrentPosition(success, error, options) { window.geoSuccess = success; window.geoError = error; window.geoOptions = options; } }');
  assert.equal(h.run('window.geoSuccess'), undefined);
  h.elements.get('crewDevice').listeners.click();
  assert.equal(h.run('window.geoOptions.timeout'), 8000);
  h.run('window.geoError({ code: 1 })');
  assert.match(h.elements.get('crewGeoStatus').textContent, /manualmente/);
  h.elements.get('crewDevice').listeners.click();
  h.emit('click', { latlng: { lat: -34.9, lng: -56.1 } });
  h.run('window.geoSuccess({ coords: { latitude: 10, longitude: 20 } })');
  assert.equal(h.elements.get('crewLatitude').value, '-34.9');
  h.elements.get('crewDevice').listeners.click(); h.flush();
  assert.match(h.elements.get('crewGeoStatus').textContent, /manualmente/);
  h.elements.get('crewDevice').listeners.click();
  h.run('window.geoSuccess({ coords: { latitude: -34.92, longitude: -56.12 } })');
  assert.equal(h.elements.get('crewLatitude').value, '-34.92');
  assert.equal(h.views.at(-1).level, 17);
});

test('punto propio administrativo tiene forma y texto diferentes sin publicar prioridad ciudadana', async () => {
  const h = harness(true);
  respond(h.incidents()[0], [{ ...item(), ubicacion_origen: 'problema', contenedor_codigo: null }]); await tick();
  const marker = [...h.groups[1].markers][0];
  assert.match(marker.options.icon.html, /point-location/);
  assert.match(text(marker.popup), /Ubicación marcada del problema/);
  assert.doesNotMatch(text(marker.popup), /Ubicación del contenedor relacionado/);
});

function collectionHarness(admin = false) {
  const html = fs.readFileSync(path.join(publicDir, admin ? 'admin.html' : 'recoleccion.html'), 'utf8');
  const elements = new Map([...html.matchAll(/id="([^"]+)"/g)].map(m => [m[1], new Element()]));
  const requests = [], redirects = [], navigations = []; let confirmation = true;
  const allButtons = () => { const result = []; const visit = n => { if (n.tag === 'button') result.push(n); (n.children || []).forEach(visit); }; [...elements.values()].forEach(visit); return result; };
  const context = vm.createContext({ URL, URLSearchParams, AbortController, Number, String, console,
    document: { getElementById: id => elements.get(id), createElement: tag => new Element(tag), querySelectorAll: allButtons },
    window: { confirm: () => confirmation, addEventListener() {}, location: { href: `http://localhost/zentrax-project/03-proyecto-zemyna/programacion/frontend/public/${admin ? 'admin' : 'recoleccion'}.html`, replace: value => redirects.push(value) } },
    history: { replaceState() {} }, openView: name => navigations.push(name), adminSessionReady: Promise.resolve(true),
    fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) });
  vm.runInContext(fs.readFileSync(path.join(publicDir, 'config.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(publicDir, `assets/js/${admin ? 'cuadrillas' : 'recoleccion'}.js`), 'utf8'), context);
  const reply = (index, status, body) => requests[index].resolve({ status, ok: status >= 200 && status < 300, json: async () => body });
  return { elements, requests, redirects, navigations, reply, html, run: code => vm.runInContext(code, context), open: () => context.window.SquadAdmin.open(), confirm: value => { confirmation = value; }, click: id => elements.get(id).listeners.click({ currentTarget: elements.get(id), preventDefault() {} }) };
}
const descendants = node => [node, ...(node.children || []).flatMap(descendants)];
const actionIn = (h, id, label) => descendants(h.elements.get(id)).find(n => n.tag === 'button' && n.textContent === label);
const ownTrip = (estado = 'Pendiente', attended = false) => ({ success: true, puede_operar: true, data: {
  pertenencia: { nombre: 'Cuadrilla propia', turno: 'Matutino' }, recorrido: {
    id_recorrido: 8, ruta_nombre: 'Ruta propia', estado, fecha_inicio: '2026-09-21 08:00:00', fecha_fin: estado === 'Finalizado' ? '2026-09-21 09:00:00' : null,
    id_usuario_inicio: 3, id_usuario_fin: estado === 'Finalizado' ? 3 : null, autores: [{ id_usuario: 3, nombre: 'Operario', apellido: 'Prueba' }], vehiculos: [],
    progreso: { total: 1, atendidos: attended ? 1 : 0, pendientes: attended ? 0 : 1 },
    contenedores: [{ id_contenedor: 5, codigo: 'C5', latitud: -34.9, longitud: -56.1, fecha_atencion: attended ? '2026-09-21 08:30:00' : null, autor_nombre: 'Operario', autor_apellido: 'Prueba' }]
  }
} });
const findAction = (h, label) => h.elements.get('collectionItems').children.flatMap(c => c.children).find(n => n.tag === 'button' && n.textContent === label);

test('operario no presenta administración ni consulta otras cuadrillas aunque cambie el hash', async () => {
  const h=collectionHarness(); h.run("window.location.hash='#cuadrillas'");
  assert.equal(h.elements.has('collectionAdmin'),false); assert.equal(h.elements.has('squadEligible'),false);
  assert.equal(new URL(h.requests[0].url).search,'');
  h.reply(0,409,{code:'sin_pertenencia',message:'Sin pertenencia',puede_consultar_administracion:true}); await tick();
  assert.equal(h.elements.get('collectionPanel').hidden,true);
  assert.equal(h.elements.get('collectionStatus').textContent,'No tenés una cuadrilla asignada actualmente. Contactá a un administrador.');
  assert.equal(h.requests.length,1);
});
test('contrato real config.js: consulta propia sin identidad ni timestamp automático', () => {
  const h=collectionHarness(), r=h.requests[0]; assert.equal(new URL(r.url).search,''); assert.equal(r.options.body,undefined); assert.equal(r.options.cache,'no-store');
});
test('operario distingue sesión vencida, permisos, falta de recorrido, ambigüedad y servidor', async () => {
  const h=collectionHarness(); h.reply(0,401,{}); await tick(); assert.match(h.redirects[0],/login.html$/);
  h.click('collectionRetry'); h.reply(1,403,{}); await tick(); assert.match(h.elements.get('collectionStatus').textContent,/no está habilitada/);
  h.click('collectionRetry'); h.reply(2,409,{code:'sin_recorrido',data:{pertenencia:{nombre:'Propia'},recorrido:null}}); await tick(); assert.equal(h.elements.get('collectionStatus').textContent,''); assert.match(text(h.elements.get('collectionItems')),/Sin recorrido asignado/);
  h.click('collectionRetry'); h.reply(3,409,{code:'recorrido_ambiguo'}); await tick(); assert.match(h.elements.get('collectionStatus').textContent,/compartido o ambiguo/);
  h.click('collectionRetry'); h.reply(4,400,{message:'Inválido'}); await tick(); assert.match(h.elements.get('collectionStatus').textContent,/Solicitud inválida/);
  h.click('collectionRetry'); h.reply(5,503,{}); await tick(); assert.match(h.elements.get('collectionStatus').textContent,/Error del servidor/);
});
test('operario cancela consultas y descarta respuestas antiguas', async () => {
  const h=collectionHarness(); h.click('collectionRetry'); assert.equal(h.requests[0].options.signal.aborted,true);
  h.reply(1,200,ownTrip()); await tick(); h.reply(0,409,{code:'sin_pertenencia',message:'Obsoleto'}); await tick();
  assert.match(text(h.elements.get('collectionItems')),/Ruta propia/); assert.doesNotMatch(h.elements.get('collectionStatus').textContent,/Obsoleto/);
});
test('operario renderiza texto seguro, coordenadas válidas y autoría propia', async () => {
  const h=collectionHarness(),body=ownTrip(); body.data.recorrido.ruta_nombre='<img onerror=alert(1)>'; h.reply(0,200,body); await tick();
  const nodes=descendants(h.elements.get('collectionItems')); assert.ok(nodes.some(n=>n.textContent.includes('<img onerror=alert(1)>'))); assert.ok(nodes.every(n=>n.innerHTML===undefined));
  assert.ok(nodes.some(n=>n.tag==='a' && n.href.startsWith('https://www.openstreetmap.org/')));
  assert.match(text(h.elements.get('collectionItems')),/Operario Prueba/);
});
test('recolección propia confirma inicio, bloquea doble envío y muestra resultado real', async () => {
  const h=collectionHarness();h.reply(0,200,ownTrip());await tick();const start=findAction(h,'Iniciar recorrido');
  h.confirm(false);start.listeners.click();assert.equal(h.requests.length,1);h.confirm(true);start.listeners.click();start.listeners.click();assert.equal(h.requests.length,2);assert.equal(start.disabled,true);
  assert.deepEqual(JSON.parse(h.requests[1].options.body),{accion:'iniciar',id_recorrido:8});
  h.reply(1,200,ownTrip('En Proceso'));await tick();h.reply(2,200,ownTrip('En Proceso'));await tick();assert.equal(findAction(h,'Iniciar recorrido'),undefined);assert.ok(findAction(h,'Marcar como atendido'));
});
test('atención conserva identidad de sesión y finalización requiere confirmación', async () => {
  const h=collectionHarness();h.reply(0,200,ownTrip('En Proceso'));await tick();findAction(h,'Marcar como atendido').listeners.click();
  assert.deepEqual(JSON.parse(h.requests[1].options.body),{accion:'atender',id_recorrido:8,id_contenedor:5});
  h.reply(1,200,ownTrip('En Proceso',true));await tick();h.reply(2,200,ownTrip('En Proceso',true));await tick();assert.equal(findAction(h,'Marcar como atendido'),undefined);assert.match(text(h.elements.get('collectionItems')),/1 de 1 contenedores atendidos/);
  const finish=findAction(h,'Finalizar recorrido');h.confirm(false);finish.listeners.click();assert.equal(h.requests.length,3);h.confirm(true);finish.listeners.click();h.reply(3,200,ownTrip('Finalizado',true));await tick();h.reply(4,200,ownTrip('Finalizado',true));await tick();assert.equal(findAction(h,'Finalizar recorrido'),undefined);
});
test('solo lectura no muestra acciones y un conflicto no inventa éxito', async () => {
  const h=collectionHarness(),body=ownTrip();body.puede_operar=false;h.reply(0,200,body);await tick();assert.equal(findAction(h,'Iniciar recorrido'),undefined);
  h.click('collectionRetry');h.reply(1,200,ownTrip());await tick();findAction(h,'Iniciar recorrido').listeners.click();h.reply(2,409,{message:'Ya fue iniciado'});await tick();assert.equal(h.elements.get('collectionStatus').textContent,'Ya fue iniciado');
});
const catalogBody=()=>({success:true,data:{tipo:'cuadrillas',lista:{items:[{id_cuadrilla:2,nombre:'Destino',turno:'Matutino',integrantes_activos:1,recorrido_actual:ownTrip().data.recorrido}],page:1,page_size:25,total:1}}});
const detailBody=()=>({success:true,data:{cuadrilla:{id_cuadrilla:2,nombre:'Destino',turno:'Matutino'},integrantes_activos:1,vehiculos:[],recorrido_actual:ownTrip().data.recorrido,lista:{items:[ownTrip().data.recorrido],page:1,page_size:25,total:1}}});
const membersBody=()=>({success:true,puede_gestionar:true,data:{historial:[{id_usuario:3,id_usuario_cuadrilla:7,nombre:'Operario',apellido:'Prueba',fecha_inicio:'2026-01-01',fecha_fin:null,asignador_nombre:'Ana',asignador_apellido:'Gestión'},{id_usuario:4,nombre:'Anterior',apellido:'Prueba',fecha_inicio:'2025-01-01',fecha_fin:'2025-02-01'}],elegibles:[{id_usuario:3,nombre:'Operario',apellido:'Prueba',pertenencia:{nombre:'Origen',id_cuadrilla:1,id_usuario_cuadrilla:7}},{id_usuario:4,nombre:'Nuevo',apellido:'Prueba',pertenencia:null},{id_usuario:5,nombre:'Actual',apellido:'Prueba',pertenencia:{nombre:'Destino',id_cuadrilla:2,id_usuario_cuadrilla:8}}]}});
async function adminReady(){const h=collectionHarness(true);await tick();h.reply(0,200,{success:true,data:{administracion:true,integrantes:true,modificar_integrantes:true,usuarios:true,asignar_recorridos:true,crear_recorridos:true}});await tick();h.open();await tick();h.reply(1,200,catalogBody());await tick();return h;}
async function adminMembers(){const h=await adminReady();actionIn(h,'squadList','Ver detalle').listeners.click();h.reply(2,200,detailBody());await tick();h.click('squadMembersTab');h.reply(3,200,membersBody());await tick();return h;}
test('administración oculta menú y rechaza acceso por hash sin autorización',async()=>{
  const h=collectionHarness(true);await tick();h.reply(0,200,{success:true,data:{administracion:false}});await tick();await h.open();assert.equal(h.elements.get('squadNav').hidden,true);assert.equal(h.requests.length,1);assert.match(h.elements.get('squadStatus').textContent,/No tenés permiso/);
});
test('cuadrillas navega listado resumen integrantes e historial con autores legibles',async()=>{
  const h=await adminMembers();assert.equal(h.elements.get('squadNav').hidden,false);assert.equal(h.elements.get('squadName').textContent,'Destino');
  assert.match(text(h.elements.get('squadActiveMembers')),/Ana Gestión/);assert.match(text(h.elements.get('squadHistory')),/Anterior/);assert.doesNotMatch(text(h.elements.get('squadActiveMembers')),/Anterior/);
  h.click('squadTripsTab');assert.equal(h.elements.get('squadTrips').hidden,false);assert.match(text(h.elements.get('squadTripRows')),/Ruta propia/);
});
test('asignación y traslado tienen confirmaciones específicas y bloquean pertenencia redundante',async()=>{
  const h=await adminMembers();h.click('squadAdd');assert.equal(h.elements.get('squadDialog').open,true);assert.match(h.elements.get('squadDialogTitle').textContent,/Agregar operario a Destino/);assert.match(h.elements.get('squadConfirmText').textContent,/Origen.*trasladarlo a Destino/);assert.equal(h.elements.get('squadConfirm').textContent,'Confirmar traslado');
  h.elements.get('squadEligible').value=5;h.elements.get('squadEligible').change();assert.equal(h.elements.get('squadConfirm').disabled,true);assert.match(h.elements.get('squadConfirmText').textContent,/ya pertenece/);
  h.elements.get('squadEligible').value=4;h.elements.get('squadEligible').change();assert.equal(h.elements.get('squadConfirm').textContent,'Confirmar asignación');assert.equal(h.elements.get('squadConfirm').disabled,false);
  h.elements.get('squadEligible').value=3;h.elements.get('squadEligible').change();h.elements.get('squadAssignment').listeners.submit({preventDefault(){}});assert.deepEqual(JSON.parse(h.requests[4].options.body),{accion:'trasladar',integrante:3,destino:2,pertenencia:7});assert.equal(h.elements.get('squadConfirm').disabled,true);
  h.reply(4,200,{success:true});await tick();h.reply(5,200,membersBody());await tick();assert.equal(h.elements.get('squadDialog').open,false);
});
test('finalizar pertenencia identifica persona y cuadrilla y conserva historial',async()=>{
  const h=await adminMembers(),finish=actionIn(h,'squadActiveMembers','Finalizar pertenencia');finish.listeners.click({currentTarget:finish});assert.match(h.elements.get('squadConfirmText').textContent,/Operario Prueba.*Destino.*historial/);h.click('squadCancel');assert.equal(h.requests.length,4);assert.equal(finish.focused,true);
  finish.listeners.click({currentTarget:finish});h.elements.get('squadAssignment').listeners.submit({preventDefault(){}});assert.deepEqual(JSON.parse(h.requests[4].options.body),{accion:'finalizar_pertenencia',integrante:3,pertenencia:7});
});
test('administración filtra y pagina recorridos sin perder cuadrilla; escapa valores',async()=>{
  const h=await adminReady();const malicious=catalogBody();malicious.data.lista.items[0].nombre='<img onerror=alert(1)>';h.click('squadRefresh');h.reply(2,200,malicious);await tick();assert.match(text(h.elements.get('squadList')),/<img onerror=alert/);assert.ok(descendants(h.elements.get('squadList')).every(n=>n.innerHTML===undefined));
  actionIn(h,'squadList','Ver detalle').listeners.click();h.reply(3,200,detailBody());await tick();h.click('squadTripsTab');h.elements.get('squadState').value='En Proceso';h.elements.get('squadState').change();let params=new URL(h.requests[4].url).searchParams;assert.equal(params.get('id_cuadrilla'),'2');assert.equal(params.get('estado'),'En Proceso');
  const body=detailBody();body.data.lista.total=30;h.reply(4,200,body);await tick();h.click('squadTripNext');params=new URL(h.requests[5].url).searchParams;assert.equal(params.get('page'),'2');assert.equal(params.get('estado'),'En Proceso');
});
test('administración controla error vacío y respuestas obsoletas',async()=>{
  const h=await adminReady();h.click('squadRefresh');h.click('squadRefresh');assert.equal(h.requests[2].options.signal.aborted,true);const empty=catalogBody();empty.data.lista.items=[];empty.data.lista.total=0;h.reply(3,200,empty);await tick();h.reply(2,403,{message:'Obsoleto'});await tick();assert.match(h.elements.get('squadStatus').textContent,/No hay cuadrillas/);
  h.click('squadRefresh');h.reply(4,403,{});await tick();assert.equal(h.elements.get('squadList').hidden,true);assert.match(h.elements.get('squadStatus').textContent,/No tenés permiso/);
});

test('operario abre reporte reutilizado sin navegación administrativa y lo pausa al cerrar',async()=>{
  const h=collectionHarness(); h.run("window.CrewReport={open(){window.reportOpen=true;},pause(){window.reportPaused=true;}}");
  h.run(fs.readFileSync(path.join(publicDir,'assets/js/recoleccion-report.js'),'utf8'));
  h.elements.get('collectionReport').hidden=true;h.click('collectionReportButton');assert.equal(h.elements.get('collectionReport').hidden,false);assert.equal(h.run('window.reportOpen'),true);
  h.click('collectionReportButton');assert.equal(h.run('window.reportPaused'),true);assert.equal(h.elements.has('squadNav'),false);
});
test('modal conserva foco y bloquea Escape mientras guarda; finalizar no valida selector oculto',async()=>{
  const h=await adminMembers();h.click('squadAdd');h.click('squadCancel');assert.equal(h.elements.get('squadAdd').focused,true);
  const finish=actionIn(h,'squadActiveMembers','Finalizar pertenencia');finish.listeners.click({currentTarget:finish});assert.equal(h.elements.get('squadEligible').disabled,true);
  h.elements.get('squadAssignment').listeners.submit({preventDefault(){}});let prevented=false;h.elements.get('squadDialog').listeners.cancel({preventDefault(){prevented=true;}});assert.equal(prevented,true);
});


test('Mi recolección vuelve al panel y presenta tarjeta sin fechas con acciones disponibles', async () => {
  const h=collectionHarness();
  const target=h.html.match(/id="collectionBack" href="([^"]+)"/)[1];
  assert.equal(new URL(target,'http://localhost/app/recoleccion.html').href,'http://localhost/app/admin.html');
  h.reply(0,409,{code:'sin_recorrido',data:{pertenencia:{nombre:'Cuadrilla Alpha',turno:'Matutino'},recorrido:null}}); await tick();
  assert.equal(h.elements.get('collectionHeading').textContent,'Cuadrilla Alpha · Matutino');
  assert.match(text(h.elements.get('collectionItems')),/Sin recorrido asignado.*Tu cuadrilla todavía no tiene un recorrido disponible/);
  assert.equal(h.elements.get('collectionTimezone').hidden,true);
  assert.equal(findAction(h,'Iniciar recorrido'),undefined);
  h.click('collectionRetry');assert.equal(h.requests.length,2);assert.equal(new URL(h.requests[1].url).search,'');
  h.reply(1,200,ownTrip());await tick();assert.equal(h.elements.get('collectionTimezone').hidden,false);
  assert.ok(findAction(h,'Iniciar recorrido'));
});

test('HTML real de Mi recolección permite reportar sin recorrido usando el módulo compartido', async () => {
  const h=harness(false,{},'standalone','recoleccion.html');
  h.elements.get('collectionReport').hidden=true;
  h.run(fs.readFileSync(path.join(publicDir,'assets/js/recoleccion-report.js'),'utf8'));
  h.run(fs.readFileSync(path.join(publicDir,'assets/js/crew-report.js'),'utf8'));
  h.elements.get('collectionReportButton').listeners.click();await tick();
  respond(h.requests[0],{tipos:['Contenedor Desbordado']});await tick();
  assert.equal(h.elements.get('crewForm').hidden,false);assert.equal(h.groups.length,1);
  h.elements.get('crewLatitude').value='-34.9';h.elements.get('crewLongitude').value='-56.1';h.elements.get('crewApplyPoint').listeners.click();
  h.elements.get('crewType').value='Contenedor Desbordado';h.elements.get('crewDescription').value='Prueba sin recorrido';
  const pending=h.elements.get('crewForm').listeners.submit({preventDefault(){}});
  assert.deepEqual(JSON.parse(h.requests.at(-1).options.body),{tipo_problema:'Contenedor Desbordado',descripcion:'Prueba sin recorrido',latitud:-34.9,longitud:-56.1});
  respond(h.requests.at(-1),{tracking_number:'INC-TEST'},201);await pending;assert.match(h.elements.get('crewConfirmation').textContent,/INC-TEST/);
});

test('Cuadrillas conserva nombre accesible e icono del catálogo Bootstrap del proyecto',async()=>{
  const h=await adminReady();assert.equal(h.elements.get('squadNav').hidden,false);
  assert.match(h.html, /id="squadNav"[^>]*><i class="nav-icon bi bi-people" aria-hidden="true"><\/i>Cuadrillas<\/a>/);
  assert.match(h.html,/bootstrap-icons@1\.11\.0/);
});

test('Usuarios sugiere Operaciones por permisos sin imponer sector ni nombre del rol',()=>{
  const fields=Object.fromEntries(['id_rol','sector','fecha_desde','fecha_hasta'].map(n=>[n,new Element()]));
  const form={querySelector:selector=>fields[selector.match(/name="([^"]+)"/)[1]]}, message=new Element();
  const context=vm.createContext({window:{},Intl,Date});vm.runInContext(fs.readFileSync(path.join(publicDir,'assets/js/user-eligibility.js'),'utf8'),context);
  const roles=[{id_rol:7,nombre:'Rol configurable',permisos_recorrido:['recorrido.consultar','recorrido.operar']},{id_rol:8,nombre:'OPERARIO',permisos_recorrido:[]}];
  const render=context.window.UserEligibility.bind(form,message,()=>roles);
  fields.fecha_desde.value='2020-01-01';fields.id_rol.value=7;fields.id_rol.change();
  assert.equal(fields.sector.value,'OPERACIONES');assert.equal(message.hidden,true);
  fields.sector.value='PUNTOS_Y_DESTINOS';fields.sector.change();assert.equal(message.hidden,false);assert.match(message.textContent,/podrá guardarse.*no será elegible/);
  fields.id_rol.change();assert.equal(fields.sector.value,'PUNTOS_Y_DESTINOS');
  fields.id_rol.value=8;fields.sector.value='';fields.id_rol.change();assert.equal(fields.sector.value,'');assert.equal(message.hidden,false);
  fields.id_rol.value=7;fields.sector.value='OPERACIONES';fields.fecha_hasta.value='2020-02-01';render();assert.equal(message.hidden,false);
  fields.fecha_hasta.value='';fields.fecha_desde.value='2099-01-01';render();assert.equal(message.hidden,false);
});

test('Agregar operario explica exclusiones sin HTML y ofrece Gestionar usuarios',async()=>{
  const h=await adminMembers();h.click('squadMembersTab');const body=membersBody();body.data.elegibles=[];body.data.no_elegibles=[{nombre:'Luis',apellido:'Suárez',motivo:'Sector Puntos y destinos'},{nombre:'<img>',apellido:'X',motivo:'Cuenta inactiva'}];
  h.reply(4,200,body);await tick();h.click('squadAdd');
  assert.match(h.html,/Seleccionar operario/);assert.match(h.html,/Se pueden asignar usuarios activos con permisos vigentes/);
  assert.match(text(h.elements.get('squadExcluded')),/Luis Suárez — Sector Puntos y destinos/);
  assert.ok(descendants(h.elements.get('squadExcluded')).every(n=>n.innerHTML===undefined));
  assert.equal(h.elements.get('squadConfirm').disabled,true);assert.match(h.elements.get('squadUserState').textContent,/No hay operarios disponibles para asignar/);
  h.click('squadDialogUsers');assert.deepEqual(h.navigations,['usuarios']);assert.equal(h.elements.get('squadDialog').open,false);
});
const availableBody=()=>({success:true,data:{items:[{id_recorrido:21,ruta_nombre:'Ruta disponible',estado:'Pendiente',fecha_inicio:'2026-09-22 09:00:00',fecha_fin:null}],vehiculos:[{id_usa:9,matricula:'TEST1',estado:'Disponible'}],total:1,page:1,page_size:25,conflicto:null}});
async function tripDialog(){const h=await adminReady();actionIn(h,'squadList','Ver detalle').listeners.click();h.reply(2,200,detailBody());await tick();h.click('squadTripsTab');h.click('squadAssignTrip');return h;}

test('Asignar recorrido confirma destino y vehículo, bloquea doble envío y actualiza resumen y tabla',async()=>{
  const h=await tripDialog();assert.equal(h.elements.get('squadTripDialog').open,true);assert.match(h.elements.get('squadTripDialogTitle').textContent,/Asignar recorrido a Destino/);assert.match(h.elements.get('squadTripDialogStatus').textContent,/Cargando/);
  assert.equal(new URL(h.requests[3].url).searchParams.get('view'),'asignables');
  h.reply(3,200,availableBody());await tick();assert.equal(h.elements.get('squadTripConfirm').disabled,false);assert.match(h.elements.get('squadTripPreview').textContent,/Sin cuadrilla asignada/);
  const submit=()=>h.elements.get('squadTripAssignment').listeners.submit({preventDefault(){}});
  const saving=submit();submit();assert.equal(h.requests.length,5);assert.deepEqual(JSON.parse(h.requests[4].options.body),{accion:'asignar_recorrido',destino:2,id_recorrido:21,id_usa:9});
  let prevented=false;h.elements.get('squadTripDialog').listeners.cancel({preventDefault(){prevented=true;}});assert.equal(prevented,true);
  h.reply(4,200,{success:true});await tick();const updated=detailBody();updated.data.recorrido_actual.ruta_nombre='Nueva ruta';updated.data.lista.items[0].ruta_nombre='Nueva ruta';h.reply(5,200,updated);await saving;
  assert.equal(h.elements.get('squadTripDialog').open,false);assert.equal(h.elements.get('squadAssignTrip').focused,true);
  assert.match(text(h.elements.get('squadSummary')),/Nueva ruta/);assert.match(text(h.elements.get('squadTripRows')),/Nueva ruta/);assert.match(h.elements.get('squadStatus').textContent,/Recorrido asignado/);
});

test('Asignación de recorridos controla vacío, vehículo ausente, conflicto y error del servidor',async()=>{
  for(const kind of ['empty','vehicle','conflict','error']){
    const h=await tripDialog(),body=availableBody();
    if(kind==='empty'){body.data.items=[];body.data.total=0;}if(kind==='vehicle')body.data.vehiculos=[];if(kind==='conflict')body.data.conflicto='La cuadrilla ya tiene recorrido';
    h.reply(3,kind==='error'?503:200,kind==='error'?{message:'No disponible'}:body);await tick();
    assert.equal(h.elements.get('squadTripConfirm').disabled,true);assert.notEqual(h.elements.get('squadTripDialogStatus').textContent,'');
    h.click('squadTripCancel');assert.equal(h.elements.get('squadTripDialog').open,false);
  }
  const h=await tripDialog();h.reply(3,200,availableBody());await tick();const saving=h.elements.get('squadTripAssignment').listeners.submit({preventDefault(){}});
  h.reply(4,409,{message:'El recorrido ya tiene una asignación'});await saving;assert.equal(h.elements.get('squadTripDialog').open,true);assert.match(h.elements.get('squadTripDialogStatus').textContent,/ya tiene una asignación/);assert.equal(h.requests.length,5);
});

test('Cerrar modal cancela consulta y descarta respuestas obsoletas; paginación conserva destino',async()=>{
  const h=await tripDialog();h.click('squadTripCancel');assert.equal(h.requests[3].options.signal.aborted,true);h.reply(3,200,availableBody());await tick();assert.equal(h.elements.get('squadTripConfirm').disabled,true);
  h.click('squadAssignTrip');const body=availableBody();body.data.total=30;h.reply(4,200,body);await tick();h.click('squadAvailableNext');
  const params=new URL(h.requests[5].url).searchParams;assert.equal(params.get('page'),'2');assert.equal(params.get('id_cuadrilla'),'2');
});

test('Creación de recorrido usa CRUD existente y luego recarga opciones para asignarlo',async()=>{
  const h=await tripDialog();h.reply(3,200,availableBody());await tick();h.click('squadManageTrips');assert.match(h.requests[4].url,/rutas.php/);
  h.reply(4,200,{success:true,data:[{id_ruta:6,nombre:'Ruta real',zona:'Centro'}]});await tick();
  h.elements.get('squadCreateRoute').value=6;h.elements.get('squadCreateDate').value='2026-09-22T09:30';
  const saving=h.elements.get('squadCreateTripForm').listeners.submit({preventDefault(){}});
  assert.match(h.requests[5].url,/recorridos.php$/);assert.deepEqual(JSON.parse(h.requests[5].options.body),{id_ruta:6,fecha_inicio:'2026-09-22 09:30:00',estado:'Pendiente'});
  h.reply(5,201,{success:true,data:{id_recorrido:21}});await tick();h.reply(6,200,availableBody());await saving;
  assert.match(h.elements.get('squadCreateStatus').textContent,/Recorrido creado/);assert.equal(h.elements.get('squadTripConfirm').disabled,false);
});


function dashboardHarness(session = true) {
  const html=fs.readFileSync(path.join(publicDir,'admin.html'),'utf8');
  const elements=new Map([...html.matchAll(/id="([^"]+)"/g)].map(m=>[m[1],new Element()]));
  const requests=[],redirects=[],navigations=[];
  const context=vm.createContext({URLSearchParams,AbortController,console,TypeError,
    document:{getElementById:id=>elements.get(id),createElement:tag=>new Element(tag)},
    window:{location:{replace:url=>redirects.push(url)}},history:{replaceState(){}},openView:name=>navigations.push(name),
    buildApiUrl:(url,options)=>{assert.equal(options.cacheBust,false);return url;},buildFrontendUrl:url=>url,adminSessionReady:Promise.resolve(session),
    fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))});
  vm.runInContext(fs.readFileSync(path.join(publicDir,'assets/js/dashboard.js'),'utf8'),context);
  const reply=(i,status,body)=>requests[i].resolve({status,ok:status>=200&&status<300,json:async()=>body});
  return {html,elements,requests,redirects,navigations,reply,open:()=>context.window.Dashboard.open(),pause:()=>context.window.Dashboard.pause(),click:id=>elements.get(id).listeners.click({preventDefault(){}}),submit:()=>elements.get('dashboardFilters').listeners.submit({preventDefault(){}})};
}
const dashboardBody=()=>({success:true,data:{periodo:{descripcion:'Todos los registros'},sector:'OPERACIONES',generado_en:'2026-09-22 09:30:00',
 actual:{incidencias_activas:0,recorridos_en_proceso:2,cuadrillas_sin_recorrido:1,camiones_disponibles:3,camiones_no_disponibles:1,contenedores_prioridad_alta:0},
 periodo_resultados:{incidencias_reportadas:3,incidencias_resueltas:1,tiempo_promedio_resolucion_horas:null,resoluciones_con_duracion:0,recorridos_finalizados:1,contenedores_atendidos:4},
 incidencias_por_estado_prioridad:[{estado:'Pendiente',prioridad:'Alta',cantidad:3}],contenedores_problematicos:[{codigo:'C-1',direccion:'Lugar público',cantidad:3}],
 recorridos:{por_estado:{Pendiente:1,'En Proceso':2,Finalizado:1,Cancelado:0},contenedores_esperados:null,contenedores_pendientes:null,porcentaje_avance:null,motivo:'No hay instantánea histórica.'},
 historicas_sin_fecha_resolucion:1,requiere_atencion:[],enlaces:{incidencias:'#incidencias',cuadrillas:'#cuadrillas',contenedores:'#contenedores',informe:'#informe-incidencias',camiones:'#camiones'}}});
async function dashboardReady(){const h=dashboardHarness();await tick();h.reply(0,200,{success:true,data:{habilitado:true}});await tick();h.open();await tick();return h;}

test('dashboard valida sesión y permiso antes de mostrar menú o consultar métricas',async()=>{
 const denied=dashboardHarness(false);await denied.open();assert.equal(denied.requests.length,0);
 const h=dashboardHarness();await tick();const opening=h.open();h.reply(0,403,{});await opening;
 assert.equal(h.elements.get('dashboardNav').hidden,true);assert.equal(h.requests.length,1);assert.match(h.elements.get('dashboardStatus').textContent,/permisos/);
 assert.match(h.html,/id="dashboardNav" hidden href="#resumen"/);
});

test('dashboard muestra seis tarjetas actuales, cero y ausencia de promedio sin inventar datos',async()=>{
 const h=await dashboardReady();assert.match(h.elements.get('dashboardStatus').textContent,/Cargando/);h.reply(1,200,dashboardBody());await tick();
 assert.equal(h.elements.get('dashboardCurrent').children.length,6);assert.equal(h.elements.get('dashboardNav').hidden,false);
 assert.match(text(h.elements.get('dashboardCurrent').children[0]),/0/);assert.match(text(h.elements.get('dashboardResults')),/Sin datos/);
 assert.match(h.elements.get('dashboardProgress').textContent,/Pendientes: Sin datos/);assert.match(h.elements.get('dashboardPeriod').textContent,/Hora de Montevideo/);
});

test('dashboard filtros y limpiar consultan agregados, actualizar conserva filtros aplicados',async()=>{
 const h=await dashboardReady();h.reply(1,200,dashboardBody());await tick();h.elements.get('dashboardFrom').value='2026-01-01';h.elements.get('dashboardTo').value='2026-01-31';h.submit();
 assert.match(h.requests[2].url,/fecha_desde=2026-01-01&fecha_hasta=2026-01-31/);h.reply(2,200,dashboardBody());await tick();
 h.click('dashboardRefresh');assert.equal(h.requests[3].url,h.requests[2].url);h.reply(3,200,dashboardBody());await tick();
 h.click('dashboardClear');assert.equal(h.requests[4].url,'/backend/api/dashboard.php');assert.equal(h.elements.get('dashboardFrom').value,'');
});

test('dashboard cancela filtros anteriores, ignora respuestas obsoletas y no duplica actualizaciones',async()=>{
 const h=await dashboardReady();h.click('dashboardRefresh');h.click('dashboardRefresh');assert.equal(h.requests.length,2);
 h.elements.get('dashboardFrom').value='2026-01-01';h.submit();h.submit();assert.equal(h.requests.length,3);assert.equal(h.requests[1].options.signal.aborted,true);
 const latest=dashboardBody();latest.data.actual.incidencias_activas=17;h.reply(2,200,latest);await tick();h.reply(1,200,dashboardBody());await tick();
 assert.match(text(h.elements.get('dashboardCurrent').children[0]),/17/);
 h.click('dashboardRefresh');h.pause();assert.equal(h.requests[3].options.signal.aborted,true);h.reply(3,200,dashboardBody());await tick();assert.match(text(h.elements.get('dashboardCurrent').children[0]),/17/);
});

test('dashboard distingue 400 y error de servidor conservando último resultado válido',async()=>{
 for(const status of [400,503]){const h=await dashboardReady();h.reply(1,200,dashboardBody());await tick();h.click('dashboardRefresh');h.reply(2,status,{message:'Rango inválido'});await tick();
 assert.equal(h.elements.get('dashboardData').hidden,false);assert.match(h.elements.get('dashboardStatus').textContent,/último resultado válido/);assert.match(h.elements.get('dashboardStatus').textContent,status===400?/Filtros inválidos/:/Intentá nuevamente/);}
});

test('dashboard revocación 401 y 403 elimina datos y menú; error de red permite reintentar',async()=>{
 for(const status of [401,403]){const h=await dashboardReady();h.reply(1,200,dashboardBody());await tick();h.click('dashboardRefresh');h.reply(2,status,{});await tick();assert.equal(h.elements.get('dashboardData').hidden,true);assert.equal(h.elements.get('dashboardNav').hidden,true);if(status===401)assert.deepEqual(h.redirects,['login.html']);}
 const h=await dashboardReady();h.requests[1].reject(new TypeError('fetch failed'));await tick();assert.match(h.elements.get('dashboardStatus').textContent,/Error de red/);assert.equal(h.elements.get('dashboardRefresh').disabled,false);
});

test('dashboard dos gráficos tienen tabla accesible y estados vacíos explícitos',async()=>{
 const h=await dashboardReady(),body=dashboardBody();body.data.incidencias_por_estado_prioridad=[];body.data.contenedores_problematicos=[];h.reply(1,200,body);await tick();
 for(const id of ['dashboardChart','dashboardTop']){assert.ok(descendants(h.elements.get(id)).some(n=>n.tag==='table'));assert.ok(descendants(h.elements.get(id)).some(n=>n.tag==='caption'));}
 assert.match(text(h.elements.get('dashboardChart')),/No hay incidencias/);assert.match(text(h.elements.get('dashboardTop')),/Sin contenedores/);assert.match(text(h.elements.get('dashboardAttention')),/No hay situaciones críticas/);
});

test('dashboard gráficos, situaciones y enlaces seguros conservan texto accesible y escape',async()=>{
 const h=await dashboardReady(),body=dashboardBody();body.data.contenedores_problematicos[0].codigo='<img onerror=alert(1)>';body.data.requiere_atencion=[{tipo:'alta',nivel:'crítico',cantidad:3,descripcion:'Incidencias activas Alta',modulo:'incidencias'}];body.data.enlaces.camiones='https://example.invalid';h.reply(1,200,body);await tick();
 assert.match(text(h.elements.get('dashboardChart')),/Pendiente.*3/);assert.match(text(h.elements.get('dashboardTop')),/<img onerror/);
 assert.ok(descendants(h.elements.get('dashboardTop')).every(n=>n.innerHTML===undefined));assert.match(text(h.elements.get('dashboardAttention')),/crítico · 3.*Incidencias activas Alta/);
 const links=descendants(h.elements.get('dashboardCurrent')).filter(n=>n.tag==='a');assert.ok(links.every(n=>n.href.startsWith('#')));links[0].listeners.click({preventDefault(){}});assert.deepEqual(h.navigations,['incidencias']);
 assert.ok(descendants(h.elements.get('dashboardTop')).filter(n=>n.tag==='td').every(n=>n.dataset.label));
});


test('bandeja F2 carga activas, respeta has_more con veinte filas y muestra Sin asignar escapado', async () => {
  const h = harness(true, {}, 'admin');
  let loading = h.run('cargarIncidenciasAdmin()');
  assert.equal(new URL(h.requests.at(-1).url, 'http://localhost').searchParams.get('activas'), '1');
  const rows = Array.from({ length: 20 }, (_, i) => ({ ...item(i + 1), cuadrilla_nombre: null, contenedor_direccion: '<script>privado</script>' }));
  respond(h.requests.at(-1), rows, 200, { has_more: false }); await loading;
  assert.equal(h.elements.get('incidentNext').disabled, true);
  assert.match(h.elements.get('incidentRows').innerHTML, /Sin asignar/);
  assert.doesNotMatch(h.elements.get('incidentRows').innerHTML, /<script>/);
  loading = h.run('cargarIncidenciasAdmin()');
  respond(h.requests.at(-1), rows, 200, { has_more: true }); await loading;
  assert.equal(h.elements.get('incidentNext').disabled, false);
});

test('bandeja F2 conserva filtros y pagina al abrir y cerrar detalle, evidencia usa endpoint protegido', async () => {
  const h = harness(true, {}, 'admin');
  h.run("incidentQuery = { estado: 'En Proceso', prioridad: 'Alta', tipo_problema: 'Contenedor Desbordado', id_ruta: '7', zona: 'Centro', desde: '2026-08-01', hasta: '2026-09-01' }; incidentPage = 2;");
  const before = h.run('JSON.stringify(incidentQuery)');
  const detail = h.run('mostrarIncidencia(1)');
  respond(h.requests.at(-1), [{ ...item(1), evidencias: [{ id_foto: 5, fecha: '2026-08-20' }] }]); await tick();
  respond(h.requests.at(-1), null); await detail;
  assert.equal(h.elements.get('incidentEvidence').children[0].src, '/backend/api/foto.php?id=5');
  h.elements.get('incidentClose').listeners.click();
  assert.equal(h.run('JSON.stringify(incidentQuery)'), before);
  assert.equal(h.run('incidentPage'), 2);
  const loading = h.run('cargarIncidenciasAdmin()');
  const query = new URL(h.requests.at(-1).url, 'http://localhost').searchParams;
  for (const [key, value] of Object.entries(JSON.parse(before))) assert.equal(query.get(key), value);
  assert.equal(query.get('page'), '2');
  respond(h.requests.at(-1), [], 200, { has_more: false }); await loading;
  assert.equal(h.elements.get('incidentNext').disabled, true);
});


test('bandeja F2 formulario combina filtros y opciones usan texto seguro de la API existente', async () => {
  const h = harness(true, {}, 'admin');
  const options = h.run('cargarFiltrosIncidencias()');
  assert.equal(new URL(h.requests.at(-1).url, 'http://localhost').searchParams.get('opciones'), 'filtros');
  respond(h.requests.at(-1), { tipos: ['Contenedor Desbordado'], rutas: [{ id_ruta: 7, nombre: '<script>ruta</script>', zona: 'Centro' }] }); await options;
  assert.equal(h.elements.get('incidentRoute').children[1].textContent, '<script>ruta</script> — Centro');
  assert.equal(h.elements.get('incidentZone').children[1].value, 'Centro');
  h.run("globalThis.FormData = class { *[Symbol.iterator]() { yield ['estado', 'activas']; yield ['prioridad', 'Alta']; yield ['tipo_problema', 'Contenedor Desbordado']; yield ['id_ruta', '7']; yield ['zona', 'Centro']; yield ['desde', '2026-08-01']; yield ['hasta', '2026-08-31']; } };");
  h.elements.get('incidentFilters').listeners.submit({ preventDefault() {} });
  const query = new URL(h.requests.at(-1).url, 'http://localhost').searchParams;
  assert.equal(query.get('activas'), '1'); assert.equal(query.has('estado'), false);
  for (const [key, value] of Object.entries({ prioridad: 'Alta', tipo_problema: 'Contenedor Desbordado', id_ruta: '7', zona: 'Centro', desde: '2026-08-01', hasta: '2026-08-31', page: '1' })) assert.equal(query.get(key), value);
  respond(h.requests.at(-1), [], 200, { has_more: false }); await tick();
  assert.match(h.elements.get('incidentMessage').textContent, /No hay incidencias/);
});

async function openAssignableIncident(h, incident, options) {
  const requestFor = predicate => h.incidents().filter(request => predicate(new URL(request.url, 'http://localhost').searchParams)).at(-1);
  h.elements.get('incidentForm').elements = Object.fromEntries(['id_incidencia', 'estado', 'prioridad'].map(name => [name, new Element('input')]));
  const loading = h.run(`mostrarIncidencia(${incident.id_incidencia})`);
  const detailRequest = requestFor(query => query.get('id') === String(incident.id_incidencia) && !query.has('view'));
  assert.ok(detailRequest, 'se consultó el detalle administrativo');
  respondApi(detailRequest, { success: true, can_update: true, can_assign: true, data: [incident] });
  await tick();
  const locationRequest = requestFor(query => query.get('view') === 'location' && query.get('id') === String(incident.id_incidencia));
  assert.ok(locationRequest, 'se consultó la ubicación');
  respond(locationRequest, null);
  await tick();
  const optionsRequest = requestFor(query => query.get('opciones') === 'asignacion');
  assert.ok(optionsRequest, `se consultaron las opciones operativas; solicitudes: ${h.incidents().map(request => request.url).join(' | ')}; estado: ${h.elements.get('incidentSaveMessage').textContent}`);
  respondApi(optionsRequest, { success: true, data: options });
  await loading;
}

test('F3 evita enviar la misma asignación o una desasignación sin cambios', async () => {
  const h = harness(true, {}, 'admin');
  const incident = { ...item(31), id_cuadrilla: 4 };
  const option = { id_asignacion_vehiculo: 80, funcion_operativa: 'REGULAR', id_usa: 8, id_cuadrilla: 4, id_recorrido: 12, nombre: 'Cuadrilla cuatro', ruta_nombre: 'Ruta doce', estado_recorrido: 'Pendiente', matricula: 'ABC123', estado_vehiculo: 'Disponible' };
  await openAssignableIncident(h, incident, [option]);
  const before = h.requests.length;
  await h.elements.get('incidentAssignmentForm').listeners.submit({ preventDefault() {} });
  assert.equal(h.requests.length, before);
  assert.match(h.elements.get('incidentSquadMessage').textContent, /ya tiene esa asignación/);
  assert.equal(h.elements.get('incidentAssignmentSave').disabled, false);
});

for (const support of [false,true]) test(`F3 ${support?'APOYO':'REGULAR'} muestra función y envía versión V19 con recorrido nullable`, async () => {
  const h=harness(true,{},'admin');const incident={...item(33),id_cuadrilla:null};
  const option={id_asignacion_vehiculo:81,id_usa:8,id_cuadrilla:4,id_recorrido:support?null:12,nombre:'Cuadrilla prueba',matricula:'TEST1',funcion_operativa:support?'APOYO':'REGULAR',estado_vehiculo:'En Servicio',ruta_nombre:support?null:'Ruta doce',estado_recorrido:support?null:'Pendiente'};
  await openAssignableIncident(h,incident,[option]);
  const select=h.elements.get('incidentAssignmentOption');assert.match(text(select),support?/Sin recorrido fijo/:/Ruta doce/);assert.match(text(select),support?/APOYO/:/REGULAR/);select.value='81';
  const saving=h.elements.get('incidentAssignmentForm').listeners.submit({preventDefault(){}});
  const request=h.requests.at(-1);const body=JSON.parse(request.options.body);
  assert.equal(body.id_asignacion_vehiculo,81);assert.equal(body.id_usa,8);assert.equal(body.id_recorrido,support?null:12);assert.equal('id_vehiculo'in body,false);
  respondApi(request,{success:true,message:'Asignada'});await tick();respond(h.requests.at(-1),[]);await saving;
});

test('F3 opciones vacías explican utilización abierta y permiten desasignar',async()=>{
  const h=harness(true,{},'admin');await openAssignableIncident(h,{...item(34),id_cuadrilla:4},[]);
  assert.match(h.elements.get('incidentSquadMessage').textContent,/utilización abierta/);assert.equal(h.elements.get('incidentAssignmentSave').disabled,false);
  const saving=h.elements.get('incidentAssignmentForm').listeners.submit({preventDefault(){}});const request=h.requests.at(-1);const body=JSON.parse(request.options.body);
  assert.equal(body.id_cuadrilla,null);assert.equal('id_asignacion_vehiculo'in body,false);respondApi(request,{success:true,message:'Desasignada'});await tick();respond(h.requests.at(-1),[]);await saving;
});

test('F3 un 409 refresca bandeja, detalle y opciones sin perder filtros ni pagina F2', async () => {
  const h = harness(true, {}, 'admin');
  h.run("incidentQuery = { estado: 'En Proceso', prioridad: 'Alta' }; incidentPage = 3;");
  const incident = { ...item(32), id_cuadrilla: null };
  const option = { id_asignacion_vehiculo: 90, funcion_operativa: 'REGULAR', id_usa: 9, id_cuadrilla: 5, id_recorrido: 13, nombre: 'Cuadrilla cinco', ruta_nombre: 'Ruta trece', estado_recorrido: 'En Proceso', matricula: 'XYZ987', estado_vehiculo: 'Disponible' };
  await openAssignableIncident(h, incident, [option]);
  h.elements.get('incidentAssignmentOption').value = '90';
  const saving = h.elements.get('incidentAssignmentForm').listeners.submit({ preventDefault() {} });
  const assignmentRequest = h.requests.at(-1);
  assert.equal(assignmentRequest.options.method, 'PUT');
  assert.equal(JSON.parse(assignmentRequest.options.body).id_asignacion_vehiculo, 90);
  const duplicate = h.elements.get('incidentAssignmentForm').listeners.submit({ preventDefault() {} });
  assert.equal(h.requests.filter(request => request.options?.method === 'PUT').length, 1);
  await duplicate;
  respondApi(assignmentRequest, { success: false, message: 'La incidencia cambió.' }, 409);
  await tick();
  const inboxRequest = h.requests.at(-1);
  const inboxQuery = new URL(inboxRequest.url, 'http://localhost').searchParams;
  assert.equal(inboxQuery.get('page'), '3');
  assert.equal(inboxQuery.get('estado'), 'En Proceso');
  assert.equal(inboxQuery.get('prioridad'), 'Alta');
  respond(inboxRequest, [incident]);
  await tick();
  respondApi(h.requests.at(-1), { success: true, can_update: true, can_assign: true, data: [incident] });
  await tick();
  respond(h.requests.at(-1), null);
  await tick();
  respondApi(h.requests.at(-1), { success: true, data: [option] });
  await saving;
  assert.equal(h.elements.get('incidentAssignmentForm').hidden, false);
  assert.match(h.elements.get('incidentSquadMessage').textContent, /Se actualizaron el detalle y las opciones/);
  assert.equal(h.elements.get('incidentAssignmentOption').disabled, false);
  assert.equal(h.run('incidentPage'), 3);
});
