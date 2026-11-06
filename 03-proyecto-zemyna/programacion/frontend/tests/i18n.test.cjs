const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const publicDir = path.join(__dirname, '../public');
const i18nDir = path.join(publicDir, 'assets/js/i18n');

class TextNode {
  constructor(value) { this.nodeType = 3; this.nodeValue = value; }
}

class Element {
  constructor(tagName) {
    this.nodeType = 1;
    this.tagName = tagName.toUpperCase();
    this.childNodes = [];
    this.attributes = new Map();
    this.listeners = {};
    this.value = '';
  }
  append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
  appendChild(node) { node.parentNode = this; this.childNodes.push(node); return node; }
  addEventListener(event, callback) { this.listeners[event] = callback; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  hasAttribute(name) { return this.attributes.has(name); }
  get textContent() { return this.childNodes.map(node => node.nodeType === 3 ? node.nodeValue : node.textContent).join(''); }
  set textContent(value) { this.childNodes = [new TextNode(String(value))]; }
}

class Observer {
  constructor(callback) { this.callback = callback; Observer.last = this; }
  observe() {}
}

function createPage(initialCookie = '', configureCatalogs, placementSelector) {
  const documentElement = new Element('html');
  const head = new Element('head');
  const body = new Element('body');
  const placementTarget = placementSelector ? new Element('div') : null;
  documentElement.append(head, body);
  if (placementTarget) body.appendChild(placementTarget);
  const cookie = { value: initialCookie };
  const document = {
    documentElement,
    head,
    body,
    get cookie() { return cookie.value; },
    set cookie(value) { cookie.value = value.split(';', 1)[0]; },
    createElement: tag => new Element(tag),
    querySelector(selector) { return selector === placementSelector ? placementTarget : null; },
    getElementById(id) {
      const find = node => {
        if (node.id === id) return node;
        for (const child of node.childNodes || []) {
          const found = find(child);
          if (found) return found;
        }
        return null;
      };
      return find(documentElement);
    }
  };
  const window = {
    location: { pathname: '/zemyna/frontend/public/index.html', protocol: 'http:' }
  };
  const context = vm.createContext({ window, document, MutationObserver: Observer });
  vm.runInContext(fs.readFileSync(path.join(i18nDir, 'es.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(i18nDir, 'en.js'), 'utf8'), context);
  if (configureCatalogs) configureCatalogs(window.ZEMYNA_LOCALES);
  vm.runInContext(fs.readFileSync(path.join(publicDir, 'assets/js/i18n.js'), 'utf8'), context);
  return { window, document, context, cookie, observer: Observer, placementTarget };
}

test('Spanish is the default and English translations preserve the Spanish fallback', () => {
  const page = createPage();
  assert.equal(page.window.ZemynaI18n.getLanguage(), 'es');
  assert.equal(page.window.ZemynaI18n.t('Pendiente'), 'Pendiente');
  page.window.ZemynaI18n.setLanguage('en');
  assert.equal(page.window.ZemynaI18n.t('Pendiente'), 'Pending');
  assert.equal(page.window.ZemynaI18n.t('Texto sin traducción'), 'Texto sin traducción');

  const missingEnglish = createPage('', locales => { delete locales.en.common.home; });
  missingEnglish.window.ZemynaI18n.setLanguage('en');
  assert.equal(missingEnglish.window.ZemynaI18n.t('Inicio'), 'Inicio');
});

test('selector changes language, translates rendered text safely, and writes a persistent cookie', () => {
  const page = createPage();
  const paragraph = new Element('p');
  paragraph.textContent = 'Pendiente';
  paragraph.setAttribute('aria-label', 'Navegación principal');
  page.document.body.appendChild(paragraph);
  page.window.ZemynaI18n.setLanguage('es');

  const selector = page.document.getElementById('zemyna-language');
  assert.ok(selector);
  selector.value = 'en';
  selector.listeners.change();
  assert.equal(page.document.documentElement.lang, 'en');
  assert.equal(paragraph.textContent, 'Pending');
  assert.equal(paragraph.getAttribute('aria-label'), 'Main navigation');
  assert.match(page.cookie.value, /^zemyna_language=en$/);
  assert.equal(page.window.ZemynaI18n.t('<img src=x onerror=alert(1)>'), '<img src=x onerror=alert(1)>');

  const dynamic = new Element('p');
  dynamic.textContent = 'Reporte recibido';
  page.document.body.appendChild(dynamic);
  page.observer.last.callback([{ type: 'childList', addedNodes: [dynamic] }]);
  assert.equal(dynamic.textContent, 'Report received');
  const nextPage = createPage(page.cookie.value);
  assert.equal(nextPage.window.ZemynaI18n.getLanguage(), 'en');
  page.window.ZemynaI18n.setLanguage('es');
  assert.equal(dynamic.textContent, 'Reporte recibido');
});

test('language selector is placed inside each page navigation/header without overlay positioning', () => {
  const placements = [
    ['.navbar .collapse.navbar-collapse .d-flex.align-items-center.ms-auto', 'zemyna-language-control--navbar', 'zemyna-language-host--navbar'],
    ['.navbar > div[style*="display: flex"]', 'zemyna-language-control--citizen', 'zemyna-language-host--citizen'],
    ['.sidebar-bottom', 'zemyna-language-control--admin', null],
    ['.collection-shell > header.view-heading', 'zemyna-language-control--collection', 'zemyna-language-host--collection'],
    ['.login-visual', 'zemyna-language-control--login', 'zemyna-language-host--login']
  ];

  for (const [selector, controlClass, hostClass] of placements) {
    const page = createPage('', undefined, selector);
    const control = page.document.getElementById('zemyna-language-control');
    assert.ok(control);
    assert.equal(control.parentNode, page.placementTarget);
    assert.match(control.getAttribute('class'), new RegExp(controlClass));
    if (hostClass) assert.match(page.placementTarget.getAttribute('class'), new RegExp(hostClass));

    const style = page.document.head.childNodes[0].textContent;
    assert.match(style, /position:\s*static/);
    assert.doesNotMatch(style, /position:\s*(?:fixed|absolute)/);
    assert.match(style, /flex-wrap:\s*wrap/);
  }
});

test('dynamic values and CAPTCHA prompts are translated without changing values', () => {
  const page = createPage();
  page.window.ZemynaI18n.setLanguage('en');
  assert.equal(page.window.ZemynaI18n.t('¿Cuánto es 4 + 7?'), 'What is 4 + 7?');
  assert.equal(
    page.window.ZemynaI18n.t('¿Finalizar el recorrido con 3 contenedores pendientes? Se conservará el historial.'),
    'Finish the route with 3 containers pending? The history will be retained.'
  );
  const persistedState = 'En Proceso';
  assert.equal(page.window.ZemynaI18n.t(persistedState), 'In Progress');
  assert.equal(persistedState, 'En Proceso');
});

test('all public pages load the shared language catalogs and translator', () => {
  for (const page of ['landing.html', 'index.html', 'login.html', 'admin.html', 'recoleccion.html', 'solicitud.html']) {
    const html = fs.readFileSync(path.join(publicDir, page), 'utf8');
    assert.match(html, /assets\/js\/i18n\/es\.js/);
    assert.match(html, /assets\/js\/i18n\/en\.js/);
    assert.match(html, /assets\/js\/i18n\.js/);
  }
});
