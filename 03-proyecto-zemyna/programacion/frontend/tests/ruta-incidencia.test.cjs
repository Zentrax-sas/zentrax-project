const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const root = path.join(__dirname, '../public');
const source = fs.readFileSync(path.join(root, 'assets/js/ruta-incidencia.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'recoleccion.html'), 'utf8');
class Element {
  constructor(tag = '') { this.tag = tag; this.children = []; this.listeners = {}; this.textContent = ''; this.hidden = false; }
  append(...nodes) { for (const n of nodes) { n.parent = this; this.children.push(n); } }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(n => n !== this); }
  setAttribute(k, v) { this[k] = v; }
  addEventListener(k, f) { (this.listeners[k] ||= []).push(f); }
  querySelectorAll(tag) { return this.children.flatMap(c => [...(c.tag === tag ? [c] : []), ...c.querySelectorAll(tag)]); }
  querySelector(tag) { return this.querySelectorAll(tag)[0]; }
  focus() {}
  set innerHTML(_) { throw new Error('HTML inseguro'); }
}
const tick = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setImmediate(r)); };
const text = n => [n.textContent, ...n.children.map(text)].join(' ');
const row = (id = 1) => ({ id_incidencia: id, tracking_number: `INC-${id}`, estado: 'Pendiente', prioridad: 'Alta',
  tipo_problema: 'Test', descripcion: 'Test', fecha_reporte: '2026-10-01', fecha_resolucion: null,
  latitud: -34.901, longitud: -56.161, atencion: { id_atencion_incidencia: id + 10, estado: 'Aceptada' } });
const context = () => ({ puede_operar_incidencias: true, funcion_operativa: 'APOYO', estado_v19: 'vigente',
  vehiculo: { activo: 1, estado: 'En Servicio', matricula: 'TEST' }, cuadrilla: { nombre: 'Propia', turno: 'Matutino' } });
const data = () => ({ consultiva: true, motor: 'OSRM', perfil: 'driving', origen: { latitud: -34.9, longitud: -56.16 },
  destino: { latitud: -34.901, longitud: -56.161, fuente: 'problema' }, distancia_metros: 1500, duracion_segundos: 180,
  geometria: { type: 'LineString', coordinates: [[-56.16, -34.9], [-56.1605, -34.9], [-56.161, -34.901]] },
  puntos_ajustados: [{ latitud: -34.9, longitud: -56.16, ajuste_metros: 0 }, { latitud: -34.901, longitud: -56.161, ajuste_metros: 0 }],
  advertencias: ['Dato consultivo <img onerror=x>'] });
function boot(integrated = false, withLeaflet = true) {
  const elements = new Map(), requests = [], maps = [], geometry = [], markers = [], events = {}, redirects = [];
  for (const m of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*id="([^"]+)"[^>]*>/g)) elements.set(m[2], new Element(m[1]));
  elements.get('ownIncidents').append(...[...elements].filter(([id]) => id.startsWith('ownIncident') && id !== 'ownIncidents').map(([, n]) => n));
  const window = { location: { hash: '', replace: u => redirects.push(u) },
    addEventListener: (k, f) => (events[k] ||= []).push(f), dispatchEvent: e => events[e.type]?.forEach(f => f(e)) };
  const layer = () => ({ addTo(map) { map.layers.add(this); return this; }, on() { return this; }, getBounds() { return 'bounds'; } });
  const L = { map: () => {
    const m = { layers: new Set(), handlers: {}, center: { lat: -34.9, lng: -56.16 },
      setView() { return this; }, on(k, f) { this.handlers[k] = f; return this; }, invalidateSize() {},
      getCenter() { return this.center; }, fitBounds(b) { this.bounds = b; }, removeLayer(l) { this.layers.delete(l); },
      remove() { this.removed = true; this.layers.clear(); } }; maps.push(m); return m;
  }, tileLayer: layer, marker: p => { markers.push(p); return layer(); }, geoJSON: g => { geometry.push(g); return layer(); } };
  const sandbox = vm.createContext({ window, Event, AbortController, URLSearchParams,
    document: { getElementById: id => elements.get(id), createElement: tag => new Element(tag) },
    buildApiUrl: (p, options) => { assert.equal(options.cacheBust, false); return p; }, buildFrontendUrl: p => p,
    ...(withLeaflet ? { L } : {}), fetch: (url, options = {}) => new Promise(resolve => requests.push({ url, options, resolve })) });
  vm.runInContext(source, sandbox);
  if (integrated) vm.runInContext(fs.readFileSync(path.join(root, 'assets/js/recoleccion-incidencias.js'), 'utf8'), sandbox);
  const parent = elements.get('ownIncidentDetail');
  return { parent, elements, requests, maps, geometry, markers, events, redirects, window,
    mount: (r = row(), c = context()) => window.IncidentRoute.mount(r, c, parent),
    click(label, n = parent) { const b = n.querySelectorAll('button').find(b => b.textContent === label); assert.ok(b, label); for (const f of b.listeners.click || []) f(); return b; },
    reply(i, d = data(), status = 200) { requests[i].resolve({ status, ok: status === 200, json: async () => ({ success: status === 200, data: d, message: 'sensitive server text' }) }); },
    emit(k) { events[k]?.forEach(f => f()); } };
}
function opened() { const h = boot(); h.mount(); h.click('Ver ruta sugerida'); return h; }
function selected() { const h = opened(); h.maps[0].handlers.click({ latlng: { lat: -34.9, lng: -56.16 } }); return h; }
test('acción solo en detalle aceptado con APOYO, V19 y coordenadas válidas', () => {
  const h = boot(); h.mount(); assert.ok(h.parent.querySelectorAll('button').some(b => b.textContent === 'Ver ruta sugerida'));
  for (const change of [c => c.puede_operar_incidencias = false, c => c.funcion_operativa = 'REGULAR', c => c.estado_v19 = 'ambiguo', c => c.vehiculo.activo = 0, c => c.vehiculo.estado = 'En Mantenimiento']) {
    const c = context(); change(c); h.parent.replaceChildren(); h.mount(row(), c);
    assert.equal(h.parent.querySelectorAll('button').length, 0);
  }
  for (const state of ['Asignada', 'Finalizada', 'Rechazada', 'Cancelada']) {
    const r = row(); r.atencion.estado = state; h.parent.replaceChildren(); h.mount(r); assert.equal(h.parent.querySelectorAll('button').length, 0);
  }
  const r = row(); r.latitud = null; h.parent.replaceChildren(); h.mount(r);
  assert.match(text(h.parent), /no tiene coordenadas/); assert.equal(h.requests.length, 0);
});
test('En atención permite ruta solo con incidencia En Proceso y contexto vigente', async () => {
  const h = boot(), r = row(); r.atencion.estado = 'En atención';
  h.mount(r); assert.equal(h.parent.querySelectorAll('button').length, 0);
  r.estado = 'En Proceso'; h.mount(r); h.click('Ver ruta sugerida');
  h.click('Usar centro del mapa como origen'); h.click('Consultar ruta sugerida');
  h.reply(0); await tick(); assert.equal(h.geometry.length, 1);
  const c = context(); c.estado_v19 = 'sin_asignacion'; h.mount(r, c);
  assert.equal(h.parent.querySelectorAll('button').length, 0); assert.equal(h.maps[0].removed, true);
});
test('origen requiere elección explícita y solo envía GET de F5.2A', async () => {
  const h = opened(); assert.equal(h.click('Consultar ruta sugerida').disabled, true); assert.equal(h.requests.length, 0);
  h.maps[0].handlers.click({ latlng: { lat: -34.900000012, lng: -56.160000012 } });
  assert.match(text(h.parent), /Punto manual; no es la posición actual del camión/);
  h.click('Consultar ruta sugerida'); const q = new URL(h.requests[0].url, 'http://test');
  assert.equal(q.pathname, '/backend/api/ruta_apoyo.php');
  assert.deepEqual([...q.searchParams.keys()], ['id_incidencia', 'origen_latitud', 'origen_longitud']);
  assert.equal(q.searchParams.get('origen_longitud'), '-56.1600000');
  assert.equal(h.requests[0].options.method, 'GET'); assert.equal(h.requests[0].options.cache, 'no-store');
  assert.equal(h.requests[0].options.credentials, 'same-origin'); assert.equal(h.requests[0].options.body, undefined);
  h.reply(0); await tick();
});
test('confirmar centro permite selección explícita sin GPS', () => {
  const h = opened(); h.click('Usar centro del mapa como origen'); assert.match(text(h.parent), /Origen seleccionado: -34.9, -56.16/);
  assert.doesNotMatch(source, /getCurrentPosition|watchPosition|navigator\.geolocation/);
});
test('dibuja GeoJSON del servidor con curvas y muestra estimaciones, destino y advertencias seguras', async () => {
  const h = selected(); h.click('Consultar ruta sugerida'); assert.match(text(h.parent), /Consultando ruta/);
  h.reply(0); await tick(); assert.deepEqual(JSON.parse(JSON.stringify(h.geometry[0])), data().geometria);
  assert.equal(h.maps[0].bounds, 'bounds'); assert.deepEqual(JSON.parse(JSON.stringify(h.markers[1])), [-34.901, -56.161]);
  assert.match(text(h.parent), /Distancia estimada: 1,5 km.*Duración estimada: 3 min/);
  assert.match(text(h.parent), /Motor: OSRM/); assert.match(text(h.parent), /independiente del Dijkstra/);
  assert.match(text(h.parent), /No sustituye las indicaciones viales ni garantiza acceso para vehículos pesados/);
  assert.match(text(h.parent), /<img onerror=x>/); assert.doesNotMatch(source, /L\.polyline|innerHTML/);
});
test('rechaza respuestas inválidas y nunca dibuja líneas de reemplazo', async () => {
  for (const mutate of [d => d.geometria.type = 'Polygon', d => d.geometria.coordinates = [[0, 91]], d => d.distancia_metros = -1,
    d => d.duracion_segundos = '180', d => d.motor = 'Dijkstra', d => d.origen.longitud = 0, d => d.consultiva = false]) {
    const h = selected(), d = data(); mutate(d); h.click('Consultar ruta sugerida'); h.reply(0, d); await tick();
    assert.equal(h.geometry.length, 0); assert.match(text(h.parent), /respuesta vial válida/);
  }
});
test('errores HTTP eliminan rutas previas y bloquean contexto revocado u obsoleto', async () => {
  for (const status of [400, 403, 404, 409, 422, 502, 503, 504]) {
    const h = selected(); h.click('Consultar ruta sugerida'); h.reply(0); await tick();
    h.click('Consultar ruta sugerida'); assert.equal(h.maps[0].layers.size, 2); // tile + origen; sin ruta/destino.
    h.reply(1, null, status); await tick();
    assert.doesNotMatch(text(h.parent), /Destino autorizado|sensitive server text/);
    if ([403, 404, 409].includes(status)) { assert.equal(h.maps[0].removed, true); assert.equal(h.click('Consultar ruta sugerida').disabled, true); }
    else assert.equal(h.maps[0].removed, undefined);
    if (status === 422) assert.match(text(h.parent), /No hay una ruta disponible/);
    if (status === 503) assert.match(text(h.parent), /OSRM.*indisponible/);
  }
});
test('401 limpia mapa y datos, notifica sesión expirada y dirige a login', async () => {
  const h = selected(); h.click('Consultar ruta sugerida'); h.reply(0, null, 401); await tick();
  assert.equal(h.maps[0].removed, true); assert.equal(h.parent.children.length, 0); assert.deepEqual(h.redirects, ['login.html']);
});
test('JSON ilegible se trata como respuesta inválida sin dibujar', async () => {
  const h = selected(); h.click('Consultar ruta sugerida'); h.requests[0].resolve({ ok: true, status: 200, json: async () => { throw new Error('secret'); } });
  await tick(); assert.equal(h.geometry.length, 0); assert.doesNotMatch(text(h.parent), /secret/);
});
test('cambio de origen e incidencia descarta respuestas tardías aunque fetch ignore abort', async () => {
  const h = selected(); h.click('Consultar ruta sugerida');
  h.maps[0].handlers.click({ latlng: { lat: -34.91, lng: -56.17 } }); assert.equal(h.requests[0].options.signal.aborted, true);
  h.reply(0); await tick(); assert.equal(h.geometry.length, 0);
  h.mount(row(2)); h.click('Ver ruta sugerida'); h.click('Usar centro del mapa como origen'); h.click('Consultar ruta sugerida');
  h.mount(row(3)); h.reply(1); await tick(); assert.equal(h.geometry.length, 0); assert.equal(h.maps[1].removed, true);
});
test('salida, navegación, refresh y pérdida de sesión limpian todo', async () => {
  for (const event of ['pagehide', 'zemyna:session-expired', 'hashchange', 'report', 'refresh']) {
    const h = selected(); h.click('Consultar ruta sugerida');
    if (event === 'hashchange') h.window.location.hash = '#solicitudes';
    if (event === 'report') h.elements.get('collectionReportButton').listeners.click.forEach(f => f());
    else if (event === 'refresh') h.elements.get('collectionRetry').listeners.click.forEach(f => f());
    else h.emit(event);
    h.reply(0); await tick(); assert.equal(h.maps[0].removed, true); assert.equal(h.geometry.length, 0); assert.equal(h.parent.children.length, 0);
  }
});
test('cerrar ruta permite reabrir y descarta incluso un JSON pendiente', async () => {
  const h = selected(); h.click('Consultar ruta sugerida'); let finish;
  h.requests[0].resolve({ ok: true, status: 200, json: () => new Promise(r => finish = r) }); await tick();
  h.click('Cerrar ruta sugerida'); finish({ success: true, data: data() }); await tick();
  assert.equal(h.geometry.length, 0); assert.equal(h.maps[0].removed, true);
  h.click('Ver ruta sugerida'); assert.equal(h.maps.length, 2);
  assert.match(text(h.parent), /Origen sin seleccionar/);
});
test('Leaflet ausente muestra indisponibilidad y no consulta', () => {
  const h = boot(false, false); h.mount(); h.click('Ver ruta sugerida'); assert.match(text(h.parent), /No se pudo cargar Leaflet/); assert.equal(h.requests.length, 0);
});
test('integración con detalle real conserva acciones y limpia al cerrar o cambiar trabajo', async () => {
  const h = boot(true); const json = { success: true, data: [row()], contexto_operativo: context(), meta: { page: 1, has_more: false } };
  h.requests[0].resolve({ ok: true, status: 200, json: async () => json }); await tick();
  const card = h.elements.get('ownIncidentList').children[0];
  assert.deepEqual(card.querySelectorAll('button').map(b => b.textContent), ['Ver detalle', 'Iniciar atención']);
  h.click('Ver detalle', card); h.requests[1].resolve({ ok: true, status: 200, json: async () => json }); await tick();
  assert.ok(h.parent.querySelectorAll('button').some(b => b.textContent === 'Iniciar atención'));
  h.click('Ver ruta sugerida'); h.click('Usar centro del mapa como origen'); h.click('Consultar ruta sugerida');
  h.click('Cerrar detalle'); h.reply(2); await tick(); assert.equal(h.maps[0].removed, true); assert.equal(h.parent.hidden, true);
  assert.equal(h.geometry.length, 0); assert.ok(h.requests.every(r => !r.options.body && (!r.options.method || r.options.method === 'GET')));
  assert.match(html, /ruta-incidencia\.js\?v=2/); assert.match(html, /recoleccion-incidencias\.js\?v=2/);
});
