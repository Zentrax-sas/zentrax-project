// Ejecuta los scripts efectivamente servidos, incluido config.js, y captura el primer fetch.
// No sustituye la validación visual de un navegador.
const vm = require('node:vm');
(async () => {
  const page = process.argv[2];
  const response = await fetch(page, { cache: 'no-store' });
  if (!response.ok) throw new Error(`HTML: HTTP ${response.status}`);
  const html = await response.text(), elements = new Map(); let first;
  const context = vm.createContext({ URL, URLSearchParams, AbortController, Date,
    window: { location: { href: page }, addEventListener() {} },
    document: { getElementById(id) { if (!elements.has(id)) elements.set(id, { replaceChildren() {}, addEventListener() {} }); return elements.get(id); } },
    fetch(url, options) { first ||= { url, method: options.method || 'GET', body: options.body ?? null }; return new Promise(() => {}); }
  });
  for (const match of html.matchAll(/<script src="([^"]+)"/g)) {
    // Solo los scripts que construyen la primera consulta; Leaflet y el formulario se activan aparte.
    if (!/^(config\.js|assets\/js\/recoleccion\.js)(\?|$)/.test(match[1])) continue;
    const script = await fetch(new URL(match[1], page), { cache: 'no-store' });
    if (!script.ok) throw new Error(`JavaScript: HTTP ${script.status}`);
    vm.runInContext(await script.text(), context);
  }
  if (!first) throw new Error('La página no realizó la consulta inicial');
  process.stdout.write(JSON.stringify(first));
})().catch(error => { console.error(error.message); process.exitCode = 1; });
