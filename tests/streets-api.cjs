// Integración HTTP de solo lectura; usa las calles existentes, sin insertar datos.
const assert = require('node:assert/strict');
const endpoint = process.env.STREETS_API_URL || 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion/backend/api/contenedores.php';
async function get(params) {
  const response = await fetch(`${endpoint}?${new URLSearchParams(params)}`, { signal: AbortSignal.timeout(15000) });
  return { status: response.status, body: await response.json() };
}
function valid(result) {
  assert.equal(result.status, 200);
  assert.equal(result.body.success, true);
  const names = result.body.data;
  assert.ok(Array.isArray(names) && names.length <= 10);
  assert.ok(names.every(s => typeof s === 'string' && [...s].length >= 2 && [...s].length <= 80 && !/[\x00-\x1f<>]/u.test(s)));
  assert.equal(new Set(names.map(s => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase())).size, names.length);
  return names;
}
(async () => {
  const observations = {};
  for (const q of ['Mig', 'Miguel Mart', 'Martinez', 'Martínez', 'Est']) {
    observations[q] = valid(await get({ view: 'streets', q }));
  }
  assert.deepEqual(observations.Martinez, observations['Martínez']);
  for (const q of ['', 'M', 'a'.repeat(81)]) assert.equal((await get({ view: 'streets', q })).status, 400);
  assert.equal((await get({ view: 'streets', 'q[]': 'Mig' })).status, 400);
  assert.equal((await get({ view: 'streets', q: 'Mi', 'calle[]': 'Brasil' })).status, 400);
  assert.equal((await get({ view: 'streets', q: 'Mi', calle: 'a'.repeat(81) })).status, 400);
  for (const q of ['zzzz_no_existe_921837', '%%', '__', "' OR 1=1 --"]) {
    assert.deepEqual(valid(await get({ view: 'streets', q })), []);
  }
  const names = valid(await get({ view: 'streets', q: 'Brasil' }));
  assert.ok(names.includes('Av. Brasil'), 'Se necesita la dirección existente Av. Brasil para esta prueba de integración.');
  for (const q of ['BRASIL', 'Brásil', 'Avda. Brasil']) {
    assert.deepEqual(valid(await get({ view: 'streets', q })), names);
  }
  const corners = valid(await get({ view: 'streets', q: 'Gadea', calle: 'Av. Brasil' }));
  assert.ok(corners.length > 0);
  assert.deepEqual(valid(await get({ view: 'streets', q: 'Gadea', calle: 'Brito del Pino' })), []);
  const found = await get({ view: 'search', calle: 'Av. Brasil', esquina: corners[0] });
  assert.equal(found.status, 200);
  assert.ok(found.body.data.length > 0);
  for (const row of found.body.data) assert.deepEqual(Object.keys(row).sort(), ['id_contenedor', 'codigo', 'direccion', 'latitud', 'longitud', 'estado'].sort());
  assert.equal((await get({})).status, 401);
  console.log(JSON.stringify({ success: true, observations, selected: { calle: 'Av. Brasil', esquina: corners[0] }, results: found.body.data.length, caseAccentAbbreviation: true, validations: true, adminProtected: true }, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
