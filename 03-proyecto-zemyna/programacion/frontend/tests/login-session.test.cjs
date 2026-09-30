const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const publicDir = path.join(__dirname, '../public');
const html = fs.readFileSync(path.join(publicDir, 'login.html'), 'utf8');
const config = fs.readFileSync(path.join(publicDir, 'config.js'), 'utf8');
const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)]
  .filter(match => !/\bsrc=/.test(match[1])).map(match => match[2]);
const response = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const anonymous = () => response(401, { success: false, message: 'No autenticado.' });
const session = roles => response(200, { success: true, data: { id_usuario: 7, roles } });
const settle = () => new Promise(resolve => setImmediate(resolve));

function page(fetch, href = 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion/frontend/public/login.html') {
  const elements = new Map();
  for (const match of html.matchAll(/<\w+\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
    assert.equal(elements.has(match[2]), false, 'ID duplicado en login.html: ' + match[2]);
    elements.set(match[2], { hidden: /\bhidden\b/.test(match[1]), textContent: '', value: '', listeners: {},
      addEventListener(event, fn) { this.listeners[event] = fn; } });
  }
  const redirects = [], requests = [], timers = new Map();
  const storage = new Proxy({}, { get() { throw new Error('No debe usarse almacenamiento de autenticación'); } });
  const context = vm.createContext({ URL, AbortController, console, localStorage: storage, sessionStorage: storage,
    document: { getElementById: id => elements.get(id) },
    window: { location: { href, protocol: new URL(href).protocol, replace: url => redirects.push(url) },
      localStorage: storage, sessionStorage: storage,
      setTimeout(fn, ms) { timers.set(1, { fn, ms }); return 1; },
      clearTimeout(id) { timers.delete(id); } },
    fetch(url, options = {}) { requests.push({ url, options }); return fetch(url, options); }
  });
  vm.runInContext(config, context);
  scripts.forEach(script => vm.runInContext(script, context));
  return { elements, redirects, requests, timers,
    async submit() {
      elements.get('email').value = 'operario@example.test';
      elements.get('password').value = 'clave-de-prueba';
      await elements.get('login-form').listeners.submit({ preventDefault() {} });
    } };
}

test('sin sesión: formulario oculto mientras consulta y visible después de 401', async () => {
  let resolve;
  const tab = page(() => new Promise(done => { resolve = done; }));
  assert.equal(tab.elements.get('login-panel').hidden, true);
  assert.match(html, /\.login-form\[hidden\]\s*\{\s*display:\s*none/);
  await tab.submit();
  assert.equal(tab.requests.length, 1);
  const request = tab.requests[0];
  assert.match(request.url, /\/backend\/api\/session\.php$/);
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.cache, 'no-store');
  resolve(anonymous()); await settle();
  assert.equal(tab.elements.get('login-panel').hidden, false);
  assert.equal(tab.elements.get('session-status').hidden, true);
  assert.deepEqual(tab.redirects, []);
  assert.equal(tab.timers.size, 0);
});

for (const role of ['ADMINISTRADOR_TI','RESPONSABLE_SECTORIAL','ADMINISTRATIVO_OPERATIVO','OPERARIO','INSPECTOR','Superusuario',' responsable ']) {
  test('sesión existente y login exitoso comparten destino para ' + role, async () => {
    const existing = page(async () => session([role]));
    await settle();
    assert.equal(existing.elements.get('login-panel').hidden, true);
    assert.equal(existing.redirects.length, 1);
    let calls = 0;
    const fresh = page(async () => ++calls === 1 ? anonymous() : session([{ nombre: role }]));
    await settle(); await fresh.submit();
    assert.deepEqual(fresh.redirects, existing.redirects);
    assert.match(existing.redirects[0], /\/frontend\/public\/admin\.html$/);
    assert.equal(fresh.requests[1].options.method, 'POST');
    assert.deepEqual(JSON.parse(fresh.requests[1].options.body), { email: 'operario@example.test', password: 'clave-de-prueba' });
  });
}

test('segunda pestaña consulta la misma sesión backend; después de logout vuelve al formulario', async () => {
  let authenticated = true;
  const backend = async () => authenticated ? session(['OPERARIO']) : anonymous();
  const first = page(backend); await settle();
  const second = page(backend); await settle();
  assert.deepEqual(first.redirects, second.redirects);
  assert.equal(second.redirects.length, 1);
  assert.equal(second.elements.get('login-panel').hidden, true);
  // Simula la respuesta backend posterior a session_destroy() del logout existente.
  authenticated = false;
  const afterLogout = page(backend); await settle();
  assert.deepEqual(afterLogout.redirects, []);
  assert.equal(afterLogout.elements.get('login-panel').hidden, false);
});

for (const [name, result] of [
  ['expirada', anonymous()],
  ['prohibida', response(403, { success: false })],
  ['error servidor', response(503, { success: false })],
  ['success falso', response(200, { success: false })],
  ['JSON null', response(200, null)],
  ['usuario inválido', response(200, { success: true, data: { id_usuario: 0, roles: ['OPERARIO'] } })],
  ['sin usuario', response(200, { success: true, data: { roles: ['OPERARIO'] } })],
  ['sin roles', session([])],
  ['rol desconocido', session(['DESCONOCIDO'])]
]) {
  test(name + ': muestra formulario sin redirigir ni repetir la consulta', async () => {
    const tab = page(async () => result);
    await settle(); await settle();
    assert.equal(tab.elements.get('login-panel').hidden, false);
    assert.deepEqual(tab.redirects, []);
    assert.equal(tab.requests.length, 1);
    assert.equal(tab.timers.size, 0);
  });
}

test('fallo de red y JSON inválido no causan loops y permiten login posterior', async () => {
  for (const failure of [
    async () => { throw new TypeError('Failed to fetch'); },
    async () => ({ ok: true, json: async () => { throw new SyntaxError('JSON inválido'); } })
  ]) {
    let calls = 0;
    const tab = page((...args) => ++calls === 1 ? failure(...args) : Promise.resolve(session(['OPERARIO'])));
    await settle();
    assert.equal(tab.elements.get('login-panel').hidden, false);
    assert.deepEqual(tab.redirects, []);
    assert.equal(tab.requests.length, 1);
    assert.match(tab.elements.get('login-message').textContent, /No se pudo comprobar/);
    await tab.submit();
    assert.equal(tab.redirects.length, 1);
  }
});

test('consulta que no responde: timeout cancela y habilita formulario', async () => {
  const tab = page((url, options) => new Promise((resolve, reject) => {
    options.signal.addEventListener('abort', () => reject(new Error('Aborted')));
  }));
  const timer = tab.timers.get(1);
  assert.equal(timer.ms, 8000);
  timer.fn(); await settle();
  assert.equal(tab.requests[0].options.signal.aborted, true);
  assert.equal(tab.elements.get('login-panel').hidden, false);
  assert.deepEqual(tab.redirects, []);
  assert.equal(tab.requests.length, 1);
});

test('parámetros URL no seleccionan identidad, rol ni destino', async () => {
  const tab = page(async () => session(['DESCONOCIDO', 'OPERARIO']),
    'http://localhost/app/frontend/public/login.html?id_usuario=999&rol=ADMINISTRADOR_TI&next=https://evil.test#admin');
  await settle();
  assert.deepEqual(tab.redirects, ['http://localhost/app/frontend/public/admin.html']);
  assert.equal(tab.requests[0].url, 'http://localhost/app/backend/api/session.php');
});

test('login rechaza credenciales y roles desconocidos sin redirigir', async () => {
  for (const result of [anonymous(), session([{ nombre: 'DESCONOCIDO' }])]) {
    let calls = 0;
    const tab = page(async () => ++calls === 1 ? anonymous() : result);
    await settle(); await tab.submit();
    assert.deepEqual(tab.redirects, []);
    assert.equal(tab.elements.get('login-panel').hidden, false);
    assert.match(tab.elements.get('login-message').textContent, /Credenciales inválidas|rol interno vigente/);
  }
});
