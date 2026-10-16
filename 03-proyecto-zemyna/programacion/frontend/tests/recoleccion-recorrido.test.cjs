const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/recoleccion.js'), 'utf8');
class Element {
  constructor(tag = '') { this.tag = tag; this.children = []; this.textContent = ''; this.hidden = false; this.listeners = {}; this.attributes = {}; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener(key, fn) { this.listeners[key] = fn; }
  setAttribute(key, value) { this.attributes[key] = value; }
  querySelectorAll(tag) { return this.children.flatMap(c => [...(c.tag === tag ? [c] : []), ...c.querySelectorAll(tag)]); }
}
const text = n => [n.textContent, ...n.children.map(text)].join(' ');
const tick = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setImmediate(r)); };
function payload(state = 'En Proceso', allowed = true, length = 105) {
  const containers = Array.from({ length }, (_, i) => ({ id_contenedor: i + 1, codigo: `C-${i + 1}`,
    direccion: i === 0 ? 'Ubicación importada IdM — X: 50000, Y: 60000' : 'Av. Prueba', latitud: -34.9, longitud: -56.16,
    fecha_atencion: (i + 1) % 3 === 0 ? '2026-10-01' : null }));
  const attended = containers.filter(c => c.fecha_atencion).length;
  return { success: true, puede_operar: allowed, data: { pertenencia: { nombre: 'Cuadrilla prueba', turno: 'Matutino' },
    recorrido: { id_recorrido: 9, ruta_nombre: 'Ruta prueba', estado: state, fecha_inicio: '2026-10-01', fecha_fin: null, autores: [],
      vehiculos: [{ matricula: 'TEST', estado: 'Disponible', activo: 1 }], contenedores: containers,
      progreso: { total: length, atendidos: attended, pendientes: length - attended } } } };
}
function boot(routeMap, width = 1200) {
  const elements = new Map(), requests = [], confirmations = [], events = {};
  const get = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
  const media = { matches: width <= 900, addEventListener(key, fn) { this.change = fn; } };
  vm.runInNewContext(source, { AbortController, URLSearchParams, Event,
    document: { getElementById: get, createElement: tag => new Element(tag), querySelectorAll: () => get('collectionItems').querySelectorAll('button') },
    window: { matchMedia: () => media, CollectionRouteMap: routeMap ? { create: () => routeMap } : undefined, addEventListener: (key, fn) => { events[key] = fn; }, dispatchEvent() {}, location: { replace() {} }, confirm: question => { confirmations.push(question); return true; } },
    buildApiUrl: (url, options) => { assert.equal(options.cacheBust, false); return url; }, buildFrontendUrl: p => p,
    fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve })) });
  const click = label => { const button = get('collectionItems').querySelectorAll('button').find(b => b.textContent === label); assert.ok(button, label); button.listeners.click(); };
  return { get, requests, confirmations, events, click, media,
    rows: () => get('collectionItems').querySelectorAll('div').find(c => c.className === 'container-list')?.children || [],
    reply: (index, json = payload(), status = 200) => requests[index].resolve({ status, ok: status === 200, json: async () => json }) };
}
test('resumen, filtro inicial y carga progresiva conservan todos los pendientes y su orden', async () => {
  const h = boot(); h.reply(0); await tick();
  assert.match(text(h.get('collectionItems')), /Total.*Atendidos.*Pendientes/);
  assert.equal(h.rows().length, 20);
  assert.doesNotMatch(text(h.get('collectionItems')), /Ubicación importada|X:|Y:|-34\.9|-56\.16/);
  assert.match(text(h.rows()[0]), /Ubicación disponible en mapa/);
  assert.equal(h.rows()[0].querySelectorAll('a').length, 0);
  assert.ok(h.rows()[0].querySelectorAll('button').some(b => b.textContent === 'Ver en mapa'));
  h.click('Mostrar 20 más'); h.click('Mostrar 20 más'); h.click('Mostrar 20 más');
  assert.deepEqual(h.rows().map(r => Number(r.children[0].children[0].textContent.slice(2))), payload().data.recorrido.contenedores.filter(c => !c.fecha_atencion).map(c => c.id_contenedor));
  assert.equal(h.requests.length, 1);
  h.click('Atendidos'); assert.equal(h.rows().length, 20); assert.equal(h.rows()[0].querySelectorAll('button').some(b => b.textContent === 'Atendido'), false);
  h.click('Todos'); h.click('Mostrar 20 más'); h.click('Mostrar 20 más'); h.click('Mostrar 20 más'); h.click('Mostrar 20 más'); h.click('Mostrar 20 más');
  assert.equal(h.rows().length, 105); assert.equal(h.rows().at(-1).children[0].children[0].textContent, 'C-105');
});

test('360/390/768: solo un panel visible; escritorio restaura ambos al cambiar ancho', async () => {
  for (const width of [360, 390, 768]) {
    let resized = 0;
    const h = boot({ render() {}, clear() {}, resize() { resized++; } }, width);
    h.reply(0, payload('En Proceso', true, 109)); await tick();
    const panes = h.get('collectionItems').querySelectorAll('section');
    const list = panes.find(n => n.className === 'collection-list-pane'), map = panes.find(n => n.className === 'collection-map-pane');
    assert.equal(list.hidden, true); assert.equal(map.hidden, false);
    h.click('Mapa'); assert.equal(list.hidden, true); assert.equal(map.hidden, false); assert.ok(resized > 0);
    h.click('Lista'); assert.equal(list.hidden, false); assert.equal(map.hidden, true);
    h.media.matches = false; h.media.change(); assert.equal(list.hidden, false); assert.equal(map.hidden, false);
    assert.equal(h.requests.length, 1);
  }
});

test('mapa recibe solo respuesta vigente; alternancia y filtros no ejecutan escrituras', async () => {
  const renders = []; let clears = 0, resizes = 0;
  const h = boot({ render(host, id, containers, filter) { renders.push({ id, containers, filter }); }, resize() { resizes++; }, clear() { clears++; } });
  h.reply(0, payload('En Proceso', true, 109)); await tick();
  assert.equal(renders.at(-1).containers.length, 109); assert.equal(h.rows().length, 20);
  h.click('Mapa'); h.click('Lista'); assert.ok(resizes >= 2);
  h.click('Todos'); assert.equal(renders.at(-1).filter, 'todos'); assert.equal(h.requests.length, 1);
  h.get('collectionRetry').listeners.click(); h.events['zemyna:session-expired']();
  const count = renders.length; h.reply(1); await tick(); assert.equal(renders.length, count); assert.ok(clears > 0);
  assert.ok(h.requests.every(r => !r.options.method || r.options.method === 'GET'));
});
test('Atendido usa contrato existente, sin éxito optimista y refresca desde backend', async () => {
  const h = boot(); h.reply(0); await tick(); h.click('Atendido');
  assert.deepEqual(JSON.parse(h.requests[1].options.body), { accion: 'atender', id_recorrido: 9, id_contenedor: 1 });
  assert.equal(h.requests[1].options.method, 'POST'); assert.match(text(h.rows()[0]), /Pendiente/);
  h.reply(1, { success: true }); await tick(); assert.equal(h.requests.length, 3);
  const next = payload(); next.data.recorrido.contenedores[0].fecha_atencion = '2026-10-02';
  next.data.recorrido.progreso.atendidos++; next.data.recorrido.progreso.pendientes--;
  h.reply(2, next); await tick(); assert.equal(h.rows()[0].children[0].children[0].textContent, 'C-2');
});
test('inicio y cierre mantienen confirmación y payload; permisos y estados limitan botones', async () => {
  for (const [state, label, action] of [['Pendiente','Iniciar recorrido','iniciar'],['En Proceso','Finalizar recorrido','finalizar']]) {
    const h = boot(); h.reply(0, payload(state)); await tick(); h.click(label);
    assert.deepEqual(JSON.parse(h.requests[1].options.body), { accion: action, id_recorrido: 9 });
    assert.equal(h.confirmations.length, 1);
    if (action === 'finalizar') assert.match(h.confirmations[0], /70 contenedores pendientes/);
  }
  for (const [state, allowed] of [['En Proceso', false], ['Finalizado', true], ['Pendiente', true]]) {
    const h = boot(); h.reply(0, payload(state, allowed)); await tick();
    assert.equal(h.rows().flatMap(r => r.querySelectorAll('button')).some(b => b.textContent === 'Atendido'), false);
    assert.equal(h.get('collectionItems').querySelectorAll('button').some(b => /No atendido/.test(b.textContent)), false);
  }
});
test('un error de guardado no marca atención y una respuesta obsoleta no reaparece', async () => {
  const h = boot(); h.reply(0); await tick(); h.click('Atendido');
  h.reply(1, { success: false, message: 'La situación cambió' }, 409); await tick();
  assert.match(text(h.rows()[0]), /Pendiente/); assert.equal(h.requests.length, 2);
  h.get('collectionRetry').listeners.click(); h.events['zemyna:session-expired']();
  h.reply(2); await tick(); assert.equal(h.get('collectionPanel').hidden, true); assert.equal(h.rows().length, 0);
});

test('selección compartida amplía tarjetas y Ver en mapa centra sin abrir URL externa', async () => {
  let config, focused;
  const h = boot({ render(host, id, rows, filter, options) { config = options; return true; }, resize() {}, clear() {}, focus(id) { focused = id; return true; } }, 390);
  h.reply(0, payload('En Proceso', true, 109)); await tick();
  config.onSelect('109'); assert.equal(config.selected, '109'); assert.equal(h.rows().at(-1).children[0].children[0].textContent, 'C-109');
  assert.ok(h.rows().at(-1).className.includes('selected'));
  h.click('Lista'); h.rows().at(-1).querySelectorAll('button').find(b => b.textContent === 'Ver en mapa').listeners.click();
  assert.equal(focused, '109'); assert.equal(h.requests.length, 1);
  assert.equal(h.rows().flatMap(r => r.querySelectorAll('a')).length, 0);
  h.click('Atendidos'); assert.equal(config.selected, '109'); h.click('Pendientes'); h.click('Lista');
  assert.equal(h.rows().at(-1).children[0].children[0].textContent, 'C-109');
});

test('ficha reutiliza guardado: rechazo conserva estado, doble pulsación no duplica y éxito refresca', async () => {
  let config;
  const h = boot({ render(host, id, rows, filter, options) { config = options; return true; }, resize() {}, clear() {} }, 360);
  h.reply(0, payload('En Proceso', true, 109)); await tick(); config.onSelect('1');
  const c = payload().data.recorrido.contenedores[0];
  const first = config.action(c); assert.equal(first.textContent, 'Marcar como atendido');
  first.listeners.click(); first.listeners.click(); assert.equal(h.requests.length, 2);
  assert.deepEqual(JSON.parse(h.requests[1].options.body), { accion: 'atender', id_recorrido: 9, id_contenedor: 1 });
  h.reply(1, { success: false, message: 'Rechazo controlado' }, 409); await tick();
  assert.match(text(h.rows()[0]), /Pendiente/); assert.match(h.get('collectionStatus').textContent, /Rechazo controlado/);
  first.listeners.click(); h.reply(2, { success: true }); await tick();
  const data = payload('En Proceso', true, 109); data.data.recorrido.contenedores[0].fecha_atencion = '2026-10-16';
  data.data.recorrido.progreso.atendidos++; data.data.recorrido.progreso.pendientes--;
  h.reply(3, data); await tick();
  assert.equal(config.selected, '1'); assert.equal(config.action(data.data.recorrido.contenedores[0]), null);
  assert.equal(h.rows()[0].children[0].children[0].textContent, 'C-2');
  h.get('collectionRetry').listeners.click(); const next = payload(); next.data.recorrido.id_recorrido = 10;
  h.reply(4, next); await tick(); assert.equal(config.selected, null);
});

test('ficha sin permiso no ofrece atención y fallo de mapa vuelve a Lista', async () => {
  let config;
  const h = boot({ render(host, id, rows, filter, options) { config = options; return false; }, resize() {}, clear() {} }, 390);
  h.reply(0, payload('Finalizado', false)); await tick();
  assert.equal(config.action(payload().data.recorrido.contenedores[0]), null);
  const list = h.get('collectionItems').querySelectorAll('section').find(n => n.className === 'collection-list-pane');
  assert.equal(list.hidden, false);
});

test('respuesta de atención posterior a expiración no restaura mapa ni inicia refresco', async () => {
  let config;
  const h = boot({ render(host, id, rows, filter, options) { config = options; return true; }, resize() {}, clear() {} });
  h.reply(0); await tick(); const action = config.action(payload().data.recorrido.contenedores[0]);
  action.listeners.click(); assert.equal(config.onSelect('2'), false);
  h.events['zemyna:session-expired'](); h.reply(1, { success: true }); await tick();
  assert.equal(h.requests.length, 2); assert.equal(h.get('collectionPanel').hidden, true);
  action.listeners.click(); assert.equal(h.requests.length, 2);
});
