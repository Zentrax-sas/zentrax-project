const APP_BASE_URL = (() => {
    const base = new URL('.', window.location.href);
    return base.toString().replace(/\/$/, '');
})();

const BACKEND_BASE_URL = (() => {
    const base = new URL('../../backend', window.location.href);
    return base.toString().replace(/\/$/, '');
})();

function buildApiUrl(path) {
    const normalizedPath = `/${String(path).replace(/^\/+/, '')}`.replace(/^\/(?:backend\/)+/, '/');
    const separator = normalizedPath.includes('?') ? '&' : '?';
    return `${BACKEND_BASE_URL}${normalizedPath}${separator}t=${Date.now()}`;
}

function buildFrontendUrl(page) {
    return `${APP_BASE_URL}/${page}`;
}

window.APP_BASE_URL = APP_BASE_URL;
window.BACKEND_BASE_URL = BACKEND_BASE_URL;
window.buildApiUrl = buildApiUrl;
window.buildFrontendUrl = buildFrontendUrl;
