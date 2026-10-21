// Chromium policy check, not an Android certification. Trust only the demo leaf
// SPKI in an isolated temporary profile; never disable certificate validation globally.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { X509Certificate, createHash } = require('node:crypto');
const dir = path.resolve(__dirname, '../tmp/android-https');
const leaf = new X509Certificate(fs.readFileSync(path.join(dir, 'server.crt')));
const pin = createHash('sha256').update(leaf.publicKey.export({ type: 'spki', format: 'der' })).digest('base64');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const requests = [];
const errors = [];
(async () => {
  const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless=new', '--remote-debugging-port=9237', `--user-data-dir=${dir}/browser-profile`,
    `--ignore-certificate-errors-spki-list=${pin}`, '--no-first-run', 'about:blank',
  ], { windowsHide: true, stdio: 'ignore' });
  let ws;
  try {
    let tabs;
    for (let i = 0; i < 80; i++) {
      try { tabs = await (await fetch('http://127.0.0.1:9237/json')).json(); break; }
      catch { await pause(250); }
    }
    assert.ok(tabs, 'Chrome debugging endpoint');
    ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    const pending = new Map(); let id = 0;
    const send = (method, params = {}) => new Promise((resolve, reject) => {
      const timer = setTimeout(() => { pending.delete(key); reject(new Error(`CDP timeout: ${method}`)); }, 30000);
      const key = ++id;
      pending.set(key, { resolve: value => { clearTimeout(timer); resolve(value); },
        reject: error => { clearTimeout(timer); reject(error); } });
      ws.send(JSON.stringify({ id: key, method, params }));
    });
    ws.addEventListener('message', async event => {
      const message = JSON.parse(event.data);
      if (message.id) {
        const item = pending.get(message.id); pending.delete(message.id);
        if (item) message.error ? item.reject(message.error) : item.resolve(message.result);
      }
      if (message.method === 'Fetch.requestPaused') {
        await send('Fetch.fulfillRequest', { requestId: message.params.requestId, responseCode: 200,
          responseHeaders: [{ name: 'Content-Type', value: 'text/html' }],
          body: Buffer.from('<!doctype html><link rel="icon" href="data:,"><title>Zemyna session test</title>').toString('base64') });
      }
      if (message.method === 'Network.loadingFailed') errors.push(message.params);
      if (message.method === 'Log.entryAdded') errors.push(message.params.entry);
      if (message.method === 'Network.requestWillBeSentExtraInfo') {
        requests.push({ requestId: message.params.requestId,
          origin: message.params.headers.Origin,
          cookieSent: !!message.params.headers.Cookie,
          blockedCookies: message.params.associatedCookies.map(c => c.blockedReasons) });
      }
    });
    await send('Network.enable'); await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
    // Equivalent to accepting Chrome's local-network prompt for this test origin.
    await send('Browser.grantPermissions', { origin: 'https://localhost', permissions: ['localNetwork', 'loopbackNetwork'] });
    await send('Fetch.enable', { patterns: [{ urlPattern: 'https://localhost/*', resourceType: 'Document' }] });
    await send('Page.navigate', { url: 'https://localhost/zemyna-session-check' });
    await pause(1000);
    const response = await send('Runtime.evaluate', { awaitPromise: true, returnByValue: true,
      expression: `(async () => {
        const base = 'https://172.28.57.181:8443/zentrax-project/03-proyecto-zemyna/programacion/backend/api';
        const get = await fetch(base + '/captcha.php', {credentials:'include'});
        const captcha = await get.json();
        const numbers = captcha.data.pregunta.match(/(\\d+)\\s*\\+\\s*(\\d+)/);
        const form = new FormData();
        form.append('captcha_respuesta', String(Number(numbers[1])+Number(numbers[2])));
        form.append('foto', new Blob(['invalid'], {type:'text/plain'}), 'invalid.txt');
        const post = await fetch(base + '/solicitud-retiro.php', {method:'POST', credentials:'include', body:form});
        const body = await post.json();
        const replay = await fetch(base + '/solicitud-retiro.php', {method:'POST', credentials:'include', body:form});
        return {origin:location.origin, secureContext:isSecureContext, captchaStatus:get.status,
          postStatus:post.status, body, replay:await replay.json()};
      })()` });
    if (response.exceptionDetails) {
      fs.writeFileSync(path.join(dir, 'browser-evidence.json'), JSON.stringify({ exception: response.exceptionDetails, requests, errors }, null, 2));
      throw new Error(JSON.stringify({ exception: response.exceptionDetails.text, errors }));
    }
    const result = response.result.value;
    const cookies = await send('Network.getCookies', { urls: ['https://172.28.57.181:8443/'] });
    const cookie = cookies.cookies.find(c => c.name === 'PHPSESSID');
    const attributes = cookie ? { ...cookie, value: '[redacted]' } : null;
    fs.writeFileSync(path.join(dir, 'browser-evidence.json'), JSON.stringify({ result, cookie: attributes, requests, errors }, null, 2));
    assert.equal(result.origin, 'https://localhost');
    assert.equal(result.secureContext, true);
    assert.equal(result.captchaStatus, 200);
    assert.equal(result.body.message, 'Solo se permiten imágenes JPG, PNG o WEBP.');
    assert.ok(result.replay.errors.captcha_respuesta);
    assert.ok(cookie?.secure && cookie?.httpOnly && cookie?.sameSite === 'None');
    assert.ok(requests.some(r => r.cookieSent));
    console.log('PASS: Chromium HTTPS origin, CORS, Secure/HttpOnly/None session cookie and one-use CAPTCHA. No DB writes.');
  } finally { ws?.close(); browser.kill(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
