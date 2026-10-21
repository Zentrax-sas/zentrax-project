const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');
const http = require('node:http');
const { createHash, X509Certificate } = require('node:crypto');
const { secureSessionCookie } = require('./android-https.cjs');
const root = path.resolve(__dirname, '..');
const ca = fs.readFileSync(path.join(root, 'android/app/src/debug/res/raw/zemyna_demo_ca.crt'));
const base = 'https://172.28.57.181:8443/zentrax-project/03-proyecto-zemyna/programacion/backend/api';
const evidence = [];
function redactCookie(value) {
  return value?.replace(/PHPSESSID=([^;\s]+)/g, (_, id) =>
    `PHPSESSID=[sha256:${createHash('sha256').update(id).digest('hex').slice(0, 12)}]`);
}
async function request(url, method = 'GET', headers = {}, body = '') {
  return new Promise((resolve, reject) => {
    const transport = url.startsWith('https:') ? https : http;
    const req = transport.request(url, { method, headers, ca, timeout: 15000 }, res => {
      let text = '';
      res.setEncoding('utf8');
      res.on('data', chunk => text += chunk);
      res.on('end', () => {
        const record = { url, method, origin: headers.Origin || null,
          requestCookie: redactCookie(headers.Cookie) || null, status: res.statusCode,
          headers: { ...res.headers, 'set-cookie': res.headers['set-cookie']?.map(redactCookie) }, body: text };
        evidence.push(record);
        resolve({ status: res.statusCode, headers: res.headers, text,
          data: text.startsWith('{') ? JSON.parse(text) : null });
      });
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', err => { evidence.push({ url, method, error: err.message }); reject(err); });
    req.end(body);
  });
}

test('Android debug trusts only demo CA/IP; release does not include it', () => {
  const cert = new X509Certificate(fs.readFileSync(path.join(root, 'tmp/android-https/server.crt')));
  assert.equal(cert.checkIP('172.28.57.181'), '172.28.57.181');
  assert.equal(cert.checkIP('172.28.57.182'), undefined);
  assert.ok(cert.verify(new X509Certificate(ca).publicKey));
  assert.ok(Date.parse(cert.validTo) > Date.now());
  const xml = fs.readFileSync(path.join(root, 'android/app/src/debug/res/xml/zemyna_lan_network_security.xml'), 'utf8');
  assert.ok(!xml.includes('cleartextTrafficPermitted="true"'));
  assert.ok(xml.includes('@raw/zemyna_demo_ca'));
  const release = fs.readFileSync(path.join(root, 'android/app/src/main/AndroidManifest.xml'), 'utf8');
  assert.ok(!release.includes('zemyna_lan_network_security'));
  assert.ok(!fs.existsSync(path.join(root, 'android/app/src/main/res/raw/zemyna_demo_ca.crt')));
  assert.match(fs.readFileSync(path.join(root, 'android/app/src/debug/java/io/ionic/starter/LanDebugActivity.java'), 'utf8'), /MIXED_CONTENT_NEVER_ALLOW/);
});

test('TLS edge preserves opaque PHP session and strengthens its cookie', () => {
  assert.equal(secureSessionCookie('PHPSESSID=opaque; path=/; HttpOnly; SameSite=Lax', 'https://localhost'),
    'PHPSESSID=opaque; Path=/; HttpOnly; Secure; SameSite=None');
  assert.ok(secureSessionCookie('PHPSESSID=opaque; path=/', 'http://localhost:8101').endsWith('SameSite=Lax'));
});

test('real HTTPS/CORS/PHP session, web regression, tracking; no database writes', async () => {
  try {
    for (const Origin of ['https://localhost', 'http://localhost:4200', 'http://127.0.0.1:4200',
      'http://localhost:8100', 'http://127.0.0.1:8100', 'http://localhost:8101', 'http://127.0.0.1:8101']) {
      const pre = await request(`${base}/incidencias.php`, 'OPTIONS', { Origin,
        'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'Content-Type' });
      assert.equal(pre.status, 204);
      assert.equal(pre.headers['access-control-allow-origin'], Origin);
      assert.equal(pre.headers['access-control-allow-credentials'], 'true');
      assert.match(pre.headers.vary, /Origin/);
      assert.equal(pre.headers['set-cookie'], undefined);
    }
    for (const Origin of ['http://localhost:8102', 'https://localhost.evil.test', 'null']) {
      const res = await request(`${base}/incidencias.php`, 'POST', { Origin, 'Content-Type': 'application/json' }, '{}');
      assert.equal(res.status, 403);
      assert.equal(res.data.message, 'Origen no permitido.');
      assert.equal(res.headers['set-cookie'], undefined);
      assert.equal(res.headers['access-control-allow-origin'], undefined);
    }
    const Origin = 'https://localhost';
    const captcha = await request(`${base}/captcha.php`, 'GET', { Origin });
    assert.equal(captcha.status, 200);
    const cookie = captcha.headers['set-cookie'][0];
    assert.match(cookie, /; Secure; SameSite=None/);
    assert.match(cookie, /; HttpOnly/);
    assert.ok(!cookie.includes('Domain='));
    const Cookie = cookie.split(';')[0];
    const [, a, b] = captcha.data.data.pregunta.match(/(\d+)\s*\+\s*(\d+)/);
    const answer = Number(a) + Number(b);
    // Existing incidence API does not consume a server CAPTCHA. Invalid data stops
    // before INSERT, while PHP resumes the exact same session without setting a new id.
    const incidence = await request(`${base}/incidencias.php`, 'POST',
      { Origin, Cookie, 'Content-Type': 'application/json' }, '{}');
    assert.equal(incidence.status, 400);
    assert.equal(incidence.headers['set-cookie'], undefined);
    assert.ok(incidence.data.errors.includes('La descripción es obligatoria.'));
    const boundary = 'ZemynaClosureBoundary';
    const payload = `--${boundary}\r\nContent-Disposition: form-data; name="captcha_respuesta"\r\n\r\n${answer}\r\n`
      + `--${boundary}\r\nContent-Disposition: form-data; name="foto"; filename="invalid.txt"\r\nContent-Type: text/plain\r\n\r\ninvalid\r\n--${boundary}--\r\n`;
    const headers = { Origin, 'Content-Type': `multipart/form-data; boundary=${boundary}` };
    const missing = await request(`${base}/solicitud-retiro.php`, 'POST', headers, payload);
    assert.ok(missing.data.errors.captcha_respuesta);
    const retiro = await request(`${base}/solicitud-retiro.php`, 'POST', { ...headers, Cookie }, payload);
    assert.equal(retiro.status, 400);
    assert.equal(retiro.data.message, 'Solo se permiten imágenes JPG, PNG o WEBP.');
    assert.equal(retiro.headers['set-cookie'], undefined);
    const replay = await request(`${base}/solicitud-retiro.php`, 'POST', { ...headers, Cookie }, payload);
    assert.ok(replay.data.errors.captcha_respuesta);
    const web = base.replace('https://172.28.57.181:8443', 'http://localhost');
    const webCaptcha = await request(`${web}/captcha.php`, 'GET', { Origin: 'http://localhost:8101' });
    assert.equal(webCaptcha.status, 200);
    assert.match(webCaptcha.headers['set-cookie'][0], /SameSite=Lax/);
    assert.ok(!webCaptcha.headers['set-cookie'][0].includes('Secure'));
    const webPost = await request(`${web}/incidencias.php`, 'POST',
      { Origin: 'http://localhost:8101', 'Content-Type': 'application/json' }, '{}');
    assert.equal(webPost.status, 400); // payload rejected, no longer blocked by CORS
    const tracking = await request(`${base}/incidencias.php?tracking_number=INC-2026-C04B7&detalle=1`, 'GET', { Origin });
    assert.equal(tracking.status, 200);
    assert.equal(tracking.data.data.tracking_number, 'INC-2026-C04B7');
    const notFound = await request(`${base}/incidencias.php?tracking_number=RET-2026-00000&detalle=1`, 'GET', { Origin });
    assert.equal(notFound.status, 404);
    assert.equal((await request('https://172.28.57.181:8443/phpmyadmin/')).status, 404);
  } finally {
    fs.writeFileSync(path.join(root, 'tmp/android-https/http-evidence.json'), JSON.stringify(evidence, null, 2));
  }
});
