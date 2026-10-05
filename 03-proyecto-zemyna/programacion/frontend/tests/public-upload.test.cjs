const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/mapa.js'), 'utf8');

function harness({ photoStatus = 201, token = 'temporary-secret', photo = true, networkFailure = false } = {}) {
  const elements = new Map();
  function element() { return { value: '', files: [], textContent: '', style: {}, dataset: {}, listeners: {},
    classList: { add() {}, remove() {} }, setAttribute() {}, focus() {}, scrollIntoView() {},
    reportValidity: () => true, reset() {}, addEventListener(name, callback) { this.listeners[name] = callback; } }; }
  const ids = ['form-reporte', 'submit-reporte', 'reporte-message', 'estado-global-reporte', 'reporte-confirmacion',
    'tracking-code-confirmacion', 'confirmacion-message', 'copiar-tracking', 'descargar-comprobante', 'nuevo-reporte',
    'form-msg-vacio', 'form-id-contenedor', 'form-direccion', 'foto_incidencia', 'tipo_incidencia', 'toast-exito'];
  for (const id of ids) elements.set(id, element());
  elements.get('form-id-contenedor').value = '1'; elements.get('form-direccion').value = 'Lugar de prueba';
  elements.get('tipo_incidencia').value = 'desborde'; elements.get('foto_incidencia').files = photo ? [{ name: 'test.png' }] : [];
  const requests = [], storageWrites = [];
  const jsonResponse = (status, body) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, text: async () => JSON.stringify(body) });
  const context = vm.createContext({ console, window: { location: { protocol: 'http:' } }, navigator: {},
    ZemynaMap: { create: () => ({ map: {}, refreshIncidents() {} }) },
    L: { Control: { extend: () => class { addTo() {} } }, control: {} },
    document: { getElementById: id => elements.get(id) || null, createElement: () => element(), body: { appendChild() {} } },
    buildApiUrl: url => url, setTimeout: () => 1, clearTimeout() {},
    localStorage: { setItem: (...args) => storageWrites.push(args) }, sessionStorage: { setItem: (...args) => storageWrites.push(args) },
    FormData: class { constructor() { this.entries = new Map(); } append(key, value) { this.entries.set(key, value); } },
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/incidencias.php')) return jsonResponse(201, { success: true, data: { id_incidencia: 123, tracking_number: 'INC-2026-ABCDE', upload_token: token } });
      if (networkFailure) throw new Error('Conexión interrumpida');
      return jsonResponse(photoStatus, { success: photoStatus < 400, message: 'La autorización de fotografía expiró.' });
    }
  });
  vm.runInContext(source, context);
  return { elements, requests, storageWrites, context, submit: () => elements.get('submit-reporte').listeners.click() };
}

test('alta y multipart reciben/envían token una sola vez sin guardarlo', async () => {
  const h = harness(); await h.submit(); assert.equal(h.requests.length, 2);
  const upload = h.requests[1]; assert.equal(upload.url, '/backend/api/foto.php');
  assert.equal(upload.options.body.entries.get('id_incidencia'), '123');
  assert.equal(upload.options.body.entries.get('upload_token'), 'temporary-secret');
  assert.ok(upload.options.body.entries.get('foto')); assert.equal(upload.options.credentials, 'same-origin');
  assert.equal(h.elements.get('tracking-code-confirmacion').textContent, 'INC-2026-ABCDE');
  assert.equal(h.storageWrites.length, 0);
  assert.equal(JSON.stringify(vm.runInContext('ultimoReporte', h.context)).includes('temporary-secret'), false);
  assert.equal(h.requests.some(request => request.url.includes('temporary-secret')), false);
});
for (const configuration of [{ photoStatus: 403 }, { photoStatus: 409 }, { photoStatus: 500 }, { networkFailure: true }, { token: undefined }]) {
  test(`fallo de foto conserva tracking sin recrear incidencia: ${JSON.stringify(configuration)}`, async () => {
    // null evita que el valor por defecto del harness regenere un token.
    const h = harness(configuration.token === undefined && 'token' in configuration ? { token: null } : configuration);
    await h.submit();
    assert.equal(h.requests.filter(request => request.url.endsWith('/incidencias.php')).length, 1);
    assert.equal(h.elements.get('tracking-code-confirmacion').textContent, 'INC-2026-ABCDE');
    assert.match(h.elements.get('reporte-message').textContent, /no se pudo adjuntar/);
    assert.match(h.elements.get('reporte-message').textContent, /INC-2026-ABCDE/);
    assert.equal(h.storageWrites.length, 0);
  });
}
test('reporte sin foto conserva tracking y no ejecuta upload', async () => {
  const h = harness({ photo: false }); await h.submit(); assert.equal(h.requests.length, 1);
  assert.equal(h.elements.get('tracking-code-confirmacion').textContent, 'INC-2026-ABCDE');
});
