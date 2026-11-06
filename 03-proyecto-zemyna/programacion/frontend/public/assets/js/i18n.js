(function (window, document) {
  'use strict';

  const catalogs = window.ZEMYNA_LOCALES || {};
  const sourceToKey = new Map();
  const flatCatalogs = {};
  const templatePatterns = [];
  const supportedLanguages = ['es', 'en'];
  const textState = new WeakMap();
  const attributeState = new WeakMap();
  const translatableAttributes = ['aria-label', 'placeholder', 'title', 'alt'];
  const cookieName = 'zemyna_language';

  function flatten(value, prefix, output) {
    Object.keys(value || {}).forEach(key => {
      const path = prefix ? `${prefix}.${key}` : key;
      if (value[key] && typeof value[key] === 'object') flatten(value[key], path, output);
      else output[path] = String(value[key]);
    });
    return output;
  }

  supportedLanguages.forEach(language => {
    flatCatalogs[language] = flatten(catalogs[language], '', {});
  });
  Object.keys(flatCatalogs.es || {}).forEach(key => {
    const source = normalize(flatCatalogs.es[key]);
    sourceToKey.set(source, key);
    if (source.includes('{')) {
      const placeholders = [];
      const parts = source.split(/(\{[a-zA-Z0-9_]+\})/g);
      const pattern = parts.map(part => {
        if (/^\{[a-zA-Z0-9_]+\}$/.test(part)) {
          placeholders.push(part.slice(1, -1));
          return '(.+?)';
        }
        return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      }).join('');
      templatePatterns.push({ key, placeholders, pattern: new RegExp(`^${pattern}$`) });
    }
  });

  function normalize(value) {
    return String(value).trim().replace(/\s+/g, ' ');
  }

  function getCookie(name) {
    const prefix = `${name}=`;
    const entry = String(document.cookie || '').split(';').map(part => part.trim()).find(part => part.startsWith(prefix));
    return entry ? entry.slice(prefix.length) : '';
  }

  function initialLanguage() {
    const preferred = getCookie(cookieName);
    return supportedLanguages.includes(preferred) ? preferred : 'es';
  }

  let language = initialLanguage();

  function translate(value, variables) {
    const input = String(value);
    const normalized = normalize(input);
    const key = flatCatalogs.es[normalized] ? normalized : sourceToKey.get(normalized);
    let result = key ? (flatCatalogs[language][key] || flatCatalogs.es[key]) : normalized;
    if (!key) {
      for (const template of templatePatterns) {
        const match = normalized.match(template.pattern);
        if (!match) continue;
        result = flatCatalogs[language][template.key] || flatCatalogs.es[template.key];
        template.placeholders.forEach((name, index) => {
          const value = translateExact(match[index + 1]);
          result = result.replace(`{${name}}`, value);
        });
        break;
      }
    }
    if (variables && typeof variables === 'object') {
      Object.keys(variables).forEach(name => {
        result = result.replace(new RegExp(`\\{${name}\\}`, 'g'), String(variables[name]));
      });
    }
    if (input.length !== input.trim().length) {
      const leading = input.match(/^\s*/)[0];
      const trailing = input.match(/\s*$/)[0];
      return leading + result + trailing;
    }
    return result;
  }

  function translateExact(value) {
    const key = sourceToKey.get(normalize(value));
    return key ? (flatCatalogs[language][key] || flatCatalogs.es[key]) : value;
  }

  function translateTextNode(node) {
    const current = node.nodeValue;
    const previous = textState.get(node);
    const source = previous && current === previous.translated ? previous.source : current;
    const translated = translate(source);
    textState.set(node, { source, translated });
    if (current !== translated) node.nodeValue = translated;
  }

  function translateAttribute(element, attribute) {
    if (!element.hasAttribute(attribute)) return;
    const current = element.getAttribute(attribute);
    const state = attributeState.get(element) || {};
    const previous = state[attribute];
    const source = previous && current === previous.translated ? previous.source : current;
    const translated = translate(source);
    state[attribute] = { source, translated };
    attributeState.set(element, state);
    if (current !== translated) element.setAttribute(attribute, translated);
  }

  function translateTree(node) {
    if (!node) return;
    if (node.nodeType === 3) {
      translateTextNode(node);
      return;
    }
    if (node.nodeType !== 1 && node.nodeType !== 9 && node.nodeType !== 11) return;
    if (node.nodeType === 1) {
      if (node.hasAttribute('data-zemyna-i18n-ignore')) return;
      if (node.tagName === 'SCRIPT' || node.tagName === 'STYLE') return;
      translatableAttributes.forEach(attribute => translateAttribute(node, attribute));
    }
    Array.from(node.childNodes || []).forEach(translateTree);
  }

  function applyLanguage() {
    if (!document.documentElement) return;
    document.documentElement.lang = language;
    translateTree(document.documentElement);
    const selector = document.getElementById('zemyna-language');
    const label = document.getElementById('zemyna-language-label');
    if (selector) {
      selector.value = language;
      selector.setAttribute('aria-label', translate('common.language'));
    }
    if (label) label.textContent = translate('common.language');
  }

  function setLanguage(nextLanguage) {
    if (!supportedLanguages.includes(nextLanguage)) {
      throw new RangeError(`Unsupported language: ${nextLanguage}`);
    }
    language = nextLanguage;
    const path = window.location && window.location.pathname
      ? window.location.pathname.replace(/[^/]*$/, '')
      : '/';
    const secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${cookieName}=${encodeURIComponent(language)}; Max-Age=31536000; Path=${path || '/'}; SameSite=Lax${secure}`;
    applyLanguage();
  }

  function addLanguageSelector() {
    if (!document.body || document.getElementById('zemyna-language-control')) return;

    const placements = [
      {
        selector: '.navbar .collapse.navbar-collapse .d-flex.align-items-center.ms-auto',
        className: 'zemyna-language-control--navbar',
        hostClass: 'zemyna-language-host--navbar'
      },
      {
        selector: '.navbar > div[style*="display: flex"]',
        className: 'zemyna-language-control--citizen',
        hostClass: 'zemyna-language-host--citizen'
      },
      {
        selector: '.sidebar-bottom',
        className: 'zemyna-language-control--admin'
      },
      {
        selector: '.collection-shell > header.view-heading',
        className: 'zemyna-language-control--collection',
        hostClass: 'zemyna-language-host--collection'
      },
      {
        selector: '.login-visual',
        className: 'zemyna-language-control--login',
        hostClass: 'zemyna-language-host--login'
      }
    ];
    const placement = placements.find(item => document.querySelector(item.selector));
    const parent = placement ? document.querySelector(placement.selector) : document.body;
    if (placement?.hostClass) {
      parent.setAttribute('class', `${parent.getAttribute('class') || ''} ${placement.hostClass}`.trim());
    }

    const control = document.createElement('div');
    control.id = 'zemyna-language-control';
    control.setAttribute('class', `zemyna-language-control ${placement?.className || 'zemyna-language-control--fallback'}`);
    control.setAttribute('data-zemyna-i18n-ignore', '');

    const style = document.createElement('style');
    style.textContent = `
      #zemyna-language-control {
        position: static;
        z-index: auto;
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        gap: 7px;
        max-width: 100%;
        margin: 0;
        padding: 5px 8px;
        border: 1px solid rgba(148, 163, 184, .35);
        border-radius: 8px;
        background: rgba(15, 23, 42, .72);
        color: #e5e7eb;
        font: 500 12px/1.2 system-ui, sans-serif;
        box-shadow: none;
        white-space: nowrap;
      }
      #zemyna-language-control label { margin: 0; color: inherit; font: inherit; }
      #zemyna-language-control select {
        width: auto;
        min-width: 76px;
        max-width: 100%;
        margin: 0;
        padding: 4px 22px 4px 7px;
        border: 1px solid rgba(148, 163, 184, .45);
        border-radius: 5px;
        background-color: #fff;
        color: #111827;
        font: inherit;
        line-height: 1.2;
        cursor: pointer;
      }
      #zemyna-language-control--navbar { margin-inline-start: 4px; }
      #zemyna-language-control--citizen { margin-inline-start: 2px; }
      #zemyna-language-control--admin {
        width: max-content;
        margin: 10px auto 0;
        border-color: rgba(199, 125, 255, .28);
        background: rgba(32, 25, 46, .72);
        color: #f5f1fb;
      }
      #zemyna-language-control--collection { margin-inline-start: auto; }
      #zemyna-language-control--login {
        margin-top: 14px;
        border-color: rgba(168, 224, 99, .3);
        background: rgba(13, 13, 13, .72);
        color: #f3f4f6;
      }
      #zemyna-language-control--fallback { margin: 12px; }
      #zemyna-language-control:focus-within { outline: 2px solid rgba(143, 227, 136, .75); outline-offset: 2px; }
      @media (max-width: 991.98px) {
        .zemyna-language-host--navbar { width: 100%; flex-wrap: wrap; justify-content: flex-start; }
        #zemyna-language-control--navbar { margin: 8px 0 0; }
      }
      @media (max-width: 768px) {
        .navbar:has(.zemyna-language-host--citizen) { flex-wrap: wrap; justify-content: center; gap: 12px; }
        .zemyna-language-host--citizen { width: 100%; flex-wrap: wrap; justify-content: center; gap: 8px !important; }
        .login-container { height: auto; min-height: 100vh; }
        .zemyna-language-host--login { height: auto; min-height: 30vh; }
        .login-form { height: auto; min-height: 70vh; }
        #zemyna-language-control--citizen { margin: 6px 0 0; }
        #zemyna-language-control--collection { margin-inline-start: 0; }
        #zemyna-language-control--login { margin-top: 10px; }
      }
    `;

    const label = document.createElement('label');
    label.id = 'zemyna-language-label';
    label.htmlFor = 'zemyna-language';
    label.textContent = 'Idioma';

    const select = document.createElement('select');
    select.id = 'zemyna-language';
    select.setAttribute('aria-label', 'Idioma');
    [
      ['es', 'Español'],
      ['en', 'English']
    ].forEach(([value, name]) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = name;
      select.appendChild(option);
    });
    select.value = language;
    select.addEventListener('change', () => setLanguage(select.value));
    control.append(label, select);
    document.head.appendChild(style);
    parent.appendChild(control);
  }

  const api = {
    t: translate,
    setLanguage,
    getLanguage: () => language,
    getSupportedLanguages: () => supportedLanguages.slice()
  };
  window.ZemynaI18n = api;

  if (document && document.documentElement) {
    addLanguageSelector();
    applyLanguage();
    const observer = new MutationObserver(records => records.forEach(record => {
      if (record.type === 'characterData') translateTextNode(record.target);
      else if (record.type === 'attributes') translateAttribute(record.target, record.attributeName);
      else record.addedNodes.forEach(translateTree);
    }));
    observer.observe(document.documentElement, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: translatableAttributes
    });
    window.alert = window.alert && ((alert => message => alert.call(window, translate(message)))(window.alert));
    window.confirm = window.confirm && ((confirm => message => confirm.call(window, translate(message)))(window.confirm));
  }
})(window, document);
