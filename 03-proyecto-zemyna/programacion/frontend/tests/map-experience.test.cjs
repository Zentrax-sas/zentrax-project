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
  focus() {}
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
function harness(administrative = false, callbacks = {}, bootPublic = false) {
  const html = fs.readFileSync(path.join(publicDir, administrative ? 'admin.html' : 'index.html'), 'utf8');
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
  const context = vm.createContext({ window: { location: { protocol: 'http:' } }, navigator: {}, L, console, Map, Set, URLSearchParams, AbortController, Option: function (text, value) { const e = new Element('option'); e.textContent = text; e.value = value; return e; },
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
  return { instance, html, elements, groups, requests, layers, views, run: code => vm.runInContext(code, context), zoom: value => { zoom = value; },
    emit: (name, event) => { for (const fn of events.get(name) || []) fn(event); },
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
