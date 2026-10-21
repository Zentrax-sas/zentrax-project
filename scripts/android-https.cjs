// Local demo TLS edge. No dependencies; PHP remains on loopback HTTP.
const https = require('node:https');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const host = '172.28.57.181';
const port = 8443;
const apiPath = '/zentrax-project/03-proyecto-zemyna/programacion/backend/api/';
const tlsDir = path.resolve(__dirname, '../tmp/android-https');
const endpoints = new Set(['captcha.php', 'incidencias.php', 'solicitud-retiro.php',
  'foto.php', 'contenedores.php', 'novedades.php', 'tipos_residuo.php']);
const methods = new Set(['GET', 'POST', 'OPTIONS']);

function secureSessionCookie(cookie, origin) {
  if (!cookie.startsWith('PHPSESSID=')) return cookie;
  // TLS terminates here. Keep the opaque PHP session id; never expose it to JS.
  const pair = cookie.split(';', 1)[0];
  return `${pair}; Path=/; HttpOnly; Secure; SameSite=${origin === 'https://localhost' ? 'None' : 'Lax'}`;
}

function createServer() {
  const server = https.createServer({
    key: fs.readFileSync(path.join(tlsDir, 'server.key')),
    cert: fs.readFileSync(path.join(tlsDir, 'server.crt')),
    minVersion: 'TLSv1.2',
  }, (req, res) => {
    const client = req.socket.remoteAddress;
    // Bind only to the Wi-Fi address and restrict clients to its actual /24 LAN.
    if (!/^172\.28\.57\.\d{1,3}$/.test(client || '')) {
      res.writeHead(403).end(); return;
    }
    const target = (req.url || '').split('?', 1)[0];
    if (req.headers.host !== `${host}:${port}` || !target.startsWith(apiPath) ||
        !endpoints.has(target.slice(apiPath.length)) || !methods.has(req.method) ||
        (target === `${apiPath}tipos_residuo.php` && !['GET', 'OPTIONS'].includes(req.method))) {
      res.writeHead(404).end(); return;
    }
    // Explicit header allowlist: do not trust forwarded headers, hosts or proxies.
    const headers = { host: 'localhost' };
    for (const name of ['origin', 'content-type', 'content-length', 'cookie',
      'accept', 'access-control-request-method', 'access-control-request-headers']) {
      if (req.headers[name] !== undefined) headers[name] = req.headers[name];
    }
    const upstream = http.request({ hostname: '127.0.0.1', port: 80,
      path: req.url, method: req.method, headers }, response => {
      const output = { ...response.headers };
      delete output.connection;
      delete output['keep-alive'];
      if (output['set-cookie']) {
        output['set-cookie'] = output['set-cookie'].map(c => secureSessionCookie(c, req.headers.origin));
      }
      res.writeHead(response.statusCode, output);
      response.pipe(res);
    });
    upstream.setTimeout(60000, () => upstream.destroy(new Error('Backend timeout')));
    upstream.on('error', () => {
      if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end('{"success":false,"message":"Backend unavailable"}');
    });
    req.on('aborted', () => upstream.destroy());
    req.pipe(upstream);
  });
  server.requestTimeout = 65000;
  return server;
}

if (require.main === module) {
  createServer().listen(port, host, () => console.log(`Zemyna demo HTTPS: https://${host}:${port}${apiPath}`));
}
module.exports = { createServer, secureSessionCookie };
