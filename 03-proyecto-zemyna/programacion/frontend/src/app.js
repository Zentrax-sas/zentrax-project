async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const json = await response.json();

    if (!response.ok || !json.success) {
        throw new Error(json.message || 'Error en la petición');
    }

    return json;
}

async function cargarListado(endpoint, contenedorId) {
    const el = document.getElementById(contenedorId);
    if (!el) return;

    el.textContent = 'Cargando...';
    try {
        const json = await requestJson(endpoint);
        if (!json.data || json.data.length === 0) {
            el.innerHTML = 'Sin registros.';
            return;
        }
        el.innerHTML = json.data.map(item => `<li>${JSON.stringify(item)}</li>`).join('');
    } catch (e) {
        el.textContent = 'Error al cargar: ' + e.message;
    }
}

async function crearRegistro(endpoint, payload) {
    const json = await requestJson(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    return json;
}

async function cargarUsuarios() {
    return cargarListado('/api/usuarios.php', 'lista-usuarios');
}

async function cargarContenedores() {
    return cargarListado('/api/contenedores.php', 'lista-contenedores');
}

async function cargarCamiones() {
    return cargarListado('/api/camiones.php', 'lista-camiones');
}

window.ZemynaApp = {
    cargarUsuarios,
    cargarContenedores,
    cargarCamiones,
    crearRegistro
};
