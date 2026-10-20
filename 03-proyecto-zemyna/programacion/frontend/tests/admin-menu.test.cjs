const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const admin = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin.js'), 'utf8');
const source = admin.slice(admin.indexOf("menuButton.addEventListener('click'"), admin.indexOf('const titles ='));
function boot() {
  const classes = new Set(), events = {}, attributes = {}; let focus = 0;
  const state = { mobile: true, dialog: false };
  const app = { classList: { contains: key => classes.has(key), remove: key => classes.delete(key),
    toggle(key) { if (classes.has(key)) { classes.delete(key); return false; } classes.add(key); return true; } } };
  const menuButton = { addEventListener: (key, fn) => { events[key] = fn; }, setAttribute: (key, value) => { attributes[key] = value; }, focus() { focus++; } };
  vm.runInNewContext(source, { app, menuButton,
    document: { querySelector: () => state.dialog ? {} : null },
    window: { addEventListener: (key, fn) => { events[key] = fn; }, matchMedia: () => ({ matches: state.mobile }) } });
  return { state, classes, attributes, click: () => events.click(), focus: () => focus,
    key: (overrides = {}) => events.keydown({ key: 'Escape', defaultPrevented: false, target: { closest: () => null }, ...overrides }) };
}
test('Escape cierra menú móvil, sincroniza aria y devuelve foco al botón', () => {
  const h = boot(); h.click(); assert.equal(h.attributes['aria-expanded'], 'true');
  h.key(); assert.equal(h.classes.has('menu-open'), false);
  assert.equal(h.attributes['aria-expanded'], 'false'); assert.equal(h.focus(), 1);
  h.key(); assert.equal(h.focus(), 1);
});
test('otras teclas y Escape ya gestionado no cierran el menú', () => {
  const h = boot(); h.click(); h.key({ key: 'Enter' }); h.key({ defaultPrevented: true });
  assert.equal(h.classes.has('menu-open'), true); assert.equal(h.focus(), 0);
});
test('no intercepta Escape de diálogos, formularios ni controles editables', () => {
  const h = boot(); h.click(); h.state.dialog = true; h.key();
  assert.equal(h.classes.has('menu-open'), true);
  h.state.dialog = false;
  for (const kind of ['dialog', 'form', 'input', 'textarea', 'select', 'contenteditable']) {
    h.key({ target: { closest: selector => { assert.match(selector, /dialog, form, input, textarea, select/); return { kind }; } } });
    assert.equal(h.classes.has('menu-open'), true);
  }
  assert.equal(h.focus(), 0);
});
test('no cambia el menú de escritorio ni el cierre por botón', () => {
  const h = boot(); h.click(); h.state.mobile = false; h.key();
  assert.equal(h.classes.has('menu-open'), true);
  h.click(); assert.equal(h.classes.has('menu-open'), false);
  assert.equal(h.attributes['aria-expanded'], 'false'); assert.equal(h.focus(), 0);
});
