const APP_BASE_URL = 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion/frontend/public';

if (window.location.protocol === 'file:') {
    window.location.replace(`${APP_BASE_URL}/index.html`);
}

const map = L.map('mapa-vedette').setView([-34.9150, -56.1540], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO'
}).addTo(map);

const formReporte = document.getElementById('form-reporte');
const submitReporteButton = document.getElementById('submit-reporte');
const captchaWrapper = document.getElementById('captcha-wrapper');
const captchaQuestion = document.getElementById('captcha-question');
const captchaInput = document.getElementById('captcha_respuesta');
const reporteMessage = document.getElementById('reporte-message');
const estadoGlobalReporte = document.getElementById('estado-global-reporte');
let toastTimerId = null;

function actualizarEstadoGlobal(mensaje, tipo = 'exito') {
    if (!estadoGlobalReporte) return;
    estadoGlobalReporte.textContent = mensaje || '';
    estadoGlobalReporte.classList.remove('exito', 'error');
    estadoGlobalReporte.classList.add(tipo === 'error' ? 'error' : 'exito');
}

function getOrCreateToastElement() {
    let toast = document.getElementById('toast-exito');
    if (toast) return toast;

    toast = document.createElement('div');
    toast.id = 'toast-exito';
    toast.className = 'toast-exito';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
    return toast;
}

function mostrarToast(texto, tipo = 'exito') {
    const toastExito = getOrCreateToastElement();

    if (toastTimerId) {
        clearTimeout(toastTimerId);
    }

    toastExito.textContent = texto;
    if (tipo === 'error') {
        toastExito.classList.add('toast-error');
    } else {
        toastExito.classList.remove('toast-error');
    }
    toastExito.classList.add('show');

    toastTimerId = setTimeout(() => {
        toastExito.classList.remove('show');
    }, 2600);
}

function buildApiUrl(path) {
    const normalizedPath = path.startsWith('/') ? path : `/${path}`;
    const separator = normalizedPath.includes('?') ? '&' : '?';
    return `http://localhost/zentrax-project/03-proyecto-zemyna/programacion${normalizedPath}${separator}t=${Date.now()}`;
}

async function cargarCaptchaReporte() {
    if (!captchaQuestion) return;

    captchaQuestion.textContent = 'Cargando pregunta...';

    try {
        const response = await fetch(buildApiUrl('/backend/api/captcha.php'), {
            credentials: 'same-origin'
        });
        const json = await response.json();

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'No se pudo cargar el captcha.');
        }

        captchaQuestion.textContent = json.data?.pregunta || 'Captcha no disponible.';
        if (captchaInput) captchaInput.value = '';
    } catch (error) {
        console.error(error);
        captchaQuestion.textContent = 'Error al cargar captcha.';
    }
}

function mostrarCaptchaReporte() {
    if (captchaWrapper) {
        captchaWrapper.style.display = 'block';
    }

    if (captchaInput) {
        captchaInput.removeAttribute('disabled');
        captchaInput.setAttribute('required', 'required');
    }

    // Cada captcha se invalida al enviarse, por eso al mostrar el bloque pedimos uno nuevo siempre.
    cargarCaptchaReporte();
}

if (submitReporteButton) {
    submitReporteButton.addEventListener('click', async function () {
        const tieneSeleccion = document.getElementById('form-id-contenedor').value.trim() !== '';
        if (!tieneSeleccion) {
            if (reporteMessage) {
                reporteMessage.textContent = 'Seleccioná un contenedor del mapa antes de enviar.';
            }
            return;
        }

        if (captchaWrapper && captchaWrapper.style.display === 'none') {
            mostrarCaptchaReporte();
            if (reporteMessage) {
                reporteMessage.textContent = 'Ingresá la respuesta del captcha y volvé a presionar ENVIAR REPORTE.';
            }
            return;
        }

        if (!captchaInput || !captchaInput.value.trim()) {
            if (reporteMessage) {
                reporteMessage.textContent = 'Ingresá la respuesta del captcha.';
            }
            return;
        }

        if (reporteMessage) {
            reporteMessage.textContent = 'Enviando incidencia...';
        }

        try {
            const response = await fetch(buildApiUrl('/backend/api/solicitud.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    direccion: document.getElementById('form-direccion').value || document.getElementById('form-id-contenedor').value,
                    email: 'demo@zemyna.com',
                    telefono: '+598 99 000 000',
                    tipo_solicitud: document.getElementById('tipo_incidencia').value || 'Incidencia',
                    descripcion: `Incidencia en contenedor ${document.getElementById('form-id-contenedor').value} - ${document.getElementById('form-direccion').value}`,
                    captcha_respuesta: captchaInput.value
                })
            });

            const json = await response.json();
            if (!response.ok || !json.success) {
                const detalleCaptcha = json?.errors?.captcha_respuesta;
                throw new Error(detalleCaptcha || json.message || 'Error al enviar el reporte.');
            }

            const tracking = json?.tracking_number || json?.data?.tracking_number || null;
            if (reporteMessage) {
                reporteMessage.textContent = tracking
                    ? `Incidencia enviada correctamente. Nro de seguimiento: ${tracking}`
                    : 'Incidencia enviada correctamente.';
            }
            actualizarEstadoGlobal(
                tracking
                    ? `Ultimo envio exitoso. Seguimiento: ${tracking}`
                    : 'Ultimo envio exitoso.',
                'exito'
            );
            mostrarToast(
                tracking
                    ? `Exito: reporte enviado. Seguimiento ${tracking}`
                    : 'Exito: reporte enviado en modo simulacion.',
                'exito'
            );

            formReporte.reset();
            document.getElementById('form-msg-vacio').style.display = 'block';
            formReporte.style.display = 'none';
            if (captchaWrapper) {
                captchaWrapper.style.display = 'none';
            }
            if (captchaInput) {
                captchaInput.setAttribute('disabled', 'disabled');
            }
        } catch (error) {
            if (reporteMessage) {
                reporteMessage.textContent = error.message;
            }
            actualizarEstadoGlobal(error.message || 'No se pudo enviar el reporte.', 'error');
            mostrarToast(error.message || 'No se pudo enviar el reporte.', 'error');
            if (captchaInput) {
                captchaInput.value = '';
            }
            cargarCaptchaReporte();
        }
    });
}

const refreshCaptchaButton = document.getElementById('refresh-captcha');
if (refreshCaptchaButton) {
    refreshCaptchaButton.addEventListener('click', cargarCaptchaReporte);
}

const contenedoresIM = [
    { id: 'CH_RM_CL_101', lat: -34.9011, lng: -56.1645, calle: 'Av. Brasil', esquina: 'Lázaro Gadea' },
    { id: 'CH_RS_CL_102', lat: -34.9034, lng: -56.1682, calle: 'Brito del Pino', esquina: 'Charrúa' },
    { id: 'CH_RM_CL_103', lat: -34.8985, lng: -56.1610, calle: 'Pocitos', esquina: 'Av. Francisco Soca' },
    { id: 'CH_RS_CL_104', lat: -34.9051, lng: -56.1554, calle: 'Benito Blanco', esquina: 'Gabriel A. Pereira' },
    { id: 'CH_RM_CL_105', lat: -34.9122, lng: -56.1598, calle: 'Juan Benito Blanco', esquina: 'Echevarriarza' },
    { id: 'B_RM_CL_201', lat: -34.9065, lng: -56.1852, calle: 'Av. 18 de Julio', esquina: 'Tacuarí' },
    { id: 'B_RS_CL_202', lat: -34.9102, lng: -56.1920, calle: 'San José', esquina: 'Zelmar Michelini' },
    { id: 'B_RM_CL_203', lat: -34.9021, lng: -56.1789, calle: 'Canelones', esquina: 'Juan Paullier' },
    { id: 'B_RS_CL_204', lat: -34.9088, lng: -56.2014, calle: 'Soriano', esquina: 'Ciudadela' },
    { id: 'B_RM_CL_205', lat: -34.8994, lng: -56.1895, calle: 'Colonia', esquina: 'Arenanal Grande' }
];

function renderContenedores(contenedores) {
    contenedores.forEach(contenedor => {
        const lat = parseFloat(contenedor.latitud ?? contenedor.lat ?? -34.9150);
        const lng = parseFloat(contenedor.longitud ?? contenedor.lng ?? -56.1540);
        const idContenedor = contenedor.id_contenedor ?? contenedor.id ?? contenedor.codigo ?? 'Sin ID';
        const direccion = contenedor.direccion || (contenedor.calle && contenedor.esquina ? `${contenedor.calle} y ${contenedor.esquina}` : 'Sin dirección');
        const estado = String(contenedor.estado || 'verde').toLowerCase();
        let colorNeon = '#22c55e';

        if (estado === 'verde') colorNeon = '#22c55e';
        else if (estado === 'amarillo') colorNeon = '#f59e0b';
        else if (estado === 'rojo') colorNeon = '#ef4444';
        else if (estado === 'gris' || estado === 'desconocido') colorNeon = '#6b7280';

        const marcador = L.circleMarker([lat, lng], {
            radius: 10,
            fillColor: colorNeon,
            color: '#ffffff',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.9
        }).addTo(map);

        marcador.bindPopup(`<b>Contenedor:</b> ${idContenedor}<br>Hacé clic para reportar.`);

        marcador.on('click', function() {
            const form = document.getElementById('form-reporte');
            const placeholder = document.getElementById('form-msg-vacio');
            if (placeholder) placeholder.style.display = 'none';
            if (form) form.style.display = 'block';
            document.getElementById('form-id-contenedor').value = idContenedor;
            document.getElementById('form-direccion').value = direccion;
            if (reporteMessage) reporteMessage.textContent = '';
            if (captchaWrapper) captchaWrapper.style.display = 'none';
            if (captchaInput) captchaInput.value = '';
        });
    });
}

async function cargarContenedoresMapa() {
    try {
        const response = await fetch(buildApiUrl('/backend/api/contenedores.php'));
        const json = await response.json();

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Error al cargar contenedores');
        }

        const contenedores = Array.isArray(json.data) ? json.data : [];
        if (contenedores.length > 0) {
            renderContenedores(contenedores);
            return;
        }

        throw new Error('No hay contenedores disponibles en la respuesta.');
    } catch (error) {
        console.warn('No se pudo cargar la API de contenedores, usando datos de prueba.', error);
        renderContenedores(contenedoresIM.map(contenedor => ({
            id_contenedor: contenedor.id,
            latitud: contenedor.lat,
            longitud: contenedor.lng,
            direccion: `${contenedor.calle} y ${contenedor.esquina}`,
            calle: contenedor.calle,
            esquina: contenedor.esquina
        })));
    }
}

cargarContenedoresMapa();