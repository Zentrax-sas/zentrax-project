const map = L.map('mapa-vedette').setView([-34.9150, -56.1540], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO'
}).addTo(map);

const formReporte = document.getElementById('form-reporte');
const captchaWrapper = document.getElementById('captcha-wrapper');
const captchaQuestion = document.getElementById('captcha-question');
const captchaInput = document.getElementById('captcha_respuesta');
const reporteMessage = document.getElementById('reporte-message');

async function cargarCaptchaReporte() {
    if (!captchaQuestion) return;

    captchaQuestion.textContent = 'Cargando pregunta...';

    try {
        const response = await fetch('../../backend/api/captcha.php');
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

    if (!captchaQuestion || captchaQuestion.textContent === 'Cargando pregunta...' || captchaQuestion.textContent === 'Error al cargar captcha.') {
        cargarCaptchaReporte();
    }
}

if (formReporte) {
    formReporte.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (captchaWrapper && captchaWrapper.style.display === 'none') {
            mostrarCaptchaReporte();
            if (reporteMessage) {
                reporteMessage.textContent = 'Completá el captcha para enviar la incidencia.';
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
            const response = await fetch('../../backend/api/solicitud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_contenedor: document.getElementById('form-id-contenedor').value,
                    direccion: document.getElementById('form-direccion').value,
                    tipo_incidencia: document.getElementById('tipo_incidencia').value,
                    captcha_respuesta: captchaInput.value
                })
            });

            const json = await response.json();
            if (!response.ok || !json.success) {
                throw new Error(json.message || 'Error al enviar el reporte.');
            }

            if (reporteMessage) {
                reporteMessage.textContent = 'Incidencia enviada correctamente.';
            }

            formReporte.reset();
            document.getElementById('form-msg-vacio').style.display = 'block';
            formReporte.style.display = 'none';
            if (captchaWrapper) {
                captchaWrapper.style.display = 'none';
            }
        } catch (error) {
            if (reporteMessage) {
                reporteMessage.textContent = error.message;
            }
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
        let colorNeon = '#555555';

        if (contenedor.estado === 'verde') colorNeon = '#a8e063';
        else if (contenedor.estado === 'amarillo') colorNeon = '#f1c40f';
        else if (contenedor.estado === 'rojo') colorNeon = '#e74c3c';

        const marcador = L.circleMarker([lat, lng], {
            radius: 8,
            fillColor: colorNeon,
            color: colorNeon,
            weight: 2,
            opacity: 1,
            fillOpacity: 0.6
        }).addTo(map);

        marcador.bindPopup(`<b>Contenedor:</b> ${idContenedor}<br>Hacé clic para reportar.`);

        marcador.on('click', function() {
            document.getElementById('form-msg-vacio').style.display = 'none';
            document.getElementById('form-reporte').style.display = 'block';
            document.getElementById('form-id-contenedor').value = idContenedor;
            document.getElementById('form-direccion').value = direccion;
        });
    });
}

async function cargarContenedoresMapa() {
    try {
        const response = await fetch('../../backend/api/contenedores.php');
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