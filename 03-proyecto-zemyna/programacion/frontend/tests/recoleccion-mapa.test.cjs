const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/recoleccion-mapa.js'), 'utf8');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; }
  setAttribute(key, value) { this[key] = value; }
}
const all = n => [n, ...n.children.flatMap(all)];
const text = n => all(n).map(n => n.textContent).join(' ');
function boot(missing = false, dimensions = null) {
  const markers = [], events = {}, calls = { fit: 0, remove: 0, resize: 0 };
  const map = { setView() { return this; }, fitBounds(points) { calls.fit++; calls.bounds = points; }, invalidateSize() { calls.resize++; }, remove() { calls.remove++; } };
  const group = { layers: [], addTo() { return this; }, clearLayers() { this.layers = []; }, addLayer(m) { this.layers.push(m); }, zoomToShowLayer(marker, callback) { calls.revealed = marker; callback(); } };
  const L = { map: () => map, markerClusterGroup: () => group, divIcon: x => x,
    tileLayer: () => ({ on(key, fn) { events[key] = fn; return this; }, addTo() { return this; } }),
    marker: (point, options) => { const m = { point, options, openPopup() { this.opened = true; }, bindPopup(p) { this.popup = p; return this; }, on(key, fn) { this[key] = fn; return this; } }; markers.push(m); return m; } };
  const context = { window: { L: missing ? undefined : L }, document: { createElement: tag => { const n = new Element(tag); if (dimensions) n.getBoundingClientRect = () => dimensions; return n; } } };
  vm.runInNewContext(source, context);
  return { api: context.window.CollectionRouteMap.create(), host: new Element('section'), group, markers, calls, events };
}
const rows = () => Array.from({ length: 109 }, (_, i) => ({ id_contenedor: i + 1, codigo: `C-${i}`, latitud: -34.8, longitud: -56.2, fecha_atencion: i % 2 ? null : '2026-10-16' }));
test('popup con contraste AA y carga progresiva sin márgenes que excedan el panel', () => {
  const css = fs.readFileSync(path.join(__dirname, '../public/assets/css/recoleccion.css'), 'utf8');
  const luminance = hex => {
    const channels = hex.match(/\w\w/g).map(c => parseInt(c, 16) / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4);
    return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
  };
  for (const [selector, expected] of [['strong', '#1f2937'], ['p', '#374151']]) {
    const rule = css.match(new RegExp('\\.collection-map-pane \\.leaflet-popup-content ' + selector + '\\s*\\{([^}]+)\\}'));
    assert.ok(rule); assert.ok(rule[1].includes('color: ' + expected));
    const contrast = (1 + .05) / (luminance(expected.slice(1)) + .05);
    assert.ok(contrast >= 4.5, `${selector}: ${contrast}`);
  }
  assert.match(css, /\.collection-map-pane \.leaflet-popup-tip\s*\{[^}]*background: #ffffff/);
  assert.match(css, /\.collection-shell \.container-more\s*\{[^}]*margin: 8px 0;[^}]*box-sizing: border-box/);
  assert.match(css, /\.container-actions\s*\{[^}]*flex-wrap: wrap/);
});
test('109 marcadores seguros, estados y encuadre solo al cambiar recorrido', () => {
  const h = boot(), data = rows(); data[0].codigo = '<img onerror=alert(1)>';
  h.api.render(h.host, 8, data, 'todos'); assert.equal(h.group.layers.length, 109); assert.equal(h.calls.fit, 1);
  assert.ok(h.markers[0].options.icon.className.includes('attended'));
  assert.ok(h.markers[1].options.icon.className.includes('pending'));
  h.markers[0].click(); assert.ok(text(h.host).includes('<img onerror=alert(1)>'));
  assert.equal(h.markers[0].popup.children[0].textContent, '<img onerror=alert(1)>');
  h.api.render(h.host, 8, data, 'pendientes'); assert.equal(h.group.layers.length, 54); assert.equal(h.calls.fit, 1);
  h.api.resize(); assert.ok(text(h.host).includes('Sin acción de atención disponible'));
  h.api.render(h.host, 9, data, 'todos'); assert.equal(h.calls.fit, 2);
  assert.ok(text(h.host.children.at(-1)).includes('Seleccioná un marcador'));
});

test('panel oculto pospone encuadre y usa latitud/longitud numéricas al mostrarse', () => {
  const size = { width: 0, height: 0 }, h = boot(false, size), data = rows();
  data[0].latitud = '-34.7846665'; data[0].longitud = '-56.2602916';
  h.api.render(h.host, 8, data, 'todos'); assert.equal(h.group.layers.length, 109);
  assert.equal(h.calls.fit, 0); assert.equal(h.calls.resize, 0);
  assert.equal(h.markers[0].point[0], -34.7846665); assert.equal(h.markers[0].point[1], -56.2602916);
  size.width = 360; size.height = 300; h.api.resize();
  assert.equal(h.calls.fit, 1); assert.equal(h.calls.bounds.length, 109);
  assert.equal(h.calls.bounds[0][0], -34.7846665);
  h.api.resize(); assert.equal(h.calls.fit, 1);
});

test('documento carga los estilos visuales de los grupos y filas sin tercera columna automática', () => {
  const html = fs.readFileSync(path.join(__dirname, '../public/recoleccion.html'), 'utf8');
  const css = fs.readFileSync(path.join(__dirname, '../public/assets/css/recoleccion.css'), 'utf8');
  assert.match(html, /leaflet\.markercluster@1\.5\.3\/dist\/MarkerCluster\.Default\.css/);
  assert.match(css, /\.container-row\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\) auto;/);
  assert.match(css, /\.container-actions\s*\{[^}]*grid-column: 1 \/ -1/);
});
test('vacío y coordenadas inválidas no generan puntos ni encuadres ficticios', () => {
  const h = boot(); h.api.render(h.host, 1, [], 'todos'); assert.equal(h.group.layers.length, 0); assert.equal(h.calls.fit, 0);
  h.api.render(h.host, 1, [{ id_contenedor: 1, latitud: null, longitud: 0 }, { id_contenedor: 2, latitud: 91, longitud: 0 }], 'todos');
  assert.equal(h.group.layers.length, 0); assert.ok(text(h.host).includes('2 sin coordenadas'));
});
test('fallo de Leaflet o teselas ofrece lista; limpieza elimina selección y mapa', () => {
  const missing = boot(true); assert.doesNotThrow(() => missing.api.render(missing.host, 1, rows(), 'todos'));
  assert.ok(text(missing.host).includes('Mapa no disponible'));
  const h = boot(); h.api.render(h.host, 1, rows(), 'todos'); h.events.tileerror(); assert.ok(text(h.host).includes('La lista y las operaciones'));
  h.markers[0].click(); h.api.clear(); assert.equal(h.calls.remove, 1); assert.ok(!text(h.host).includes('Ficha informativa'));
});

test('centrado revela marcador agrupado y ficha recibe acción compartida sin escribir', () => {
  const h = boot(); let selected;
  const action = new Element('button'); action.textContent = 'Marcar como atendido';
  h.api.render(h.host, 8, rows(), 'todos', { selected: '2', onSelect: id => { selected = id; }, action: () => action });
  assert.ok(h.markers[1].options.icon.className.includes('selected'));
  assert.equal(h.api.focus('2'), true); assert.equal(h.calls.revealed, h.markers[1]); assert.equal(h.markers[1].opened, true);
  h.markers[1].click(); assert.equal(selected, '2'); assert.ok(text(h.host).includes('Marcar como atendido'));
  assert.equal(h.api.focus('999'), false);
});
