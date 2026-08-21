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

if (submitReporteButton) {
    submitReporteButton.addEventListener('click', async function () {
        const tieneSeleccion = document.getElementById('form-id-contenedor').value.trim() !== '';
        if (!tieneSeleccion) {
            if (reporteMessage) {
                reporteMessage.textContent = 'Seleccioná un contenedor del mapa antes de enviar.';
            }
            return;
        }

        const fotoInput = document.getElementById('foto_incidencia');
        const fotoSeleccionada = fotoInput && fotoInput.files && fotoInput.files.length > 0 ? fotoInput.files[0] : null;

        if (fotoSeleccionada && fotoInput.files.length > 1) {
            if (reporteMessage) {
                reporteMessage.textContent = 'Solo se permite una foto por incidencia.';
            }
            return;
        }

        if (reporteMessage) {
            reporteMessage.textContent = 'Enviando incidencia...';
        }

        try {
            const response = await fetch(buildApiUrl('/backend/api/incidencias.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    id_contenedor: document.getElementById('form-id-contenedor').value,
                    tipo_problema: document.getElementById('tipo_incidencia').value,
                    direccion: document.getElementById('form-direccion').value
                })
            });

            const json = await response.json();
            if (!response.ok || !json.success) {
                throw new Error(json.message || 'Error al enviar el reporte.');
            }

            let mensajeFinal = 'Incidencia enviada correctamente.';
            const idIncidencia = json?.data?.id_incidencia;

            if (fotoSeleccionada && idIncidencia) {
                const formData = new FormData();
                formData.append('id_incidencia', String(idIncidencia));
                formData.append('foto', fotoSeleccionada);

                const fotoResponse = await fetch(buildApiUrl('/backend/api/foto.php'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                const fotoJson = await fotoResponse.json();
                if (!fotoResponse.ok || !fotoJson.success) {
                    mensajeFinal = 'Incidencia registrada, pero la foto no pudo guardarse.';
                    actualizarEstadoGlobal(mensajeFinal, 'error');
                    mostrarToast(mensajeFinal, 'error');
                } else {
                    mensajeFinal = 'Incidencia enviada correctamente con foto adjunta.';
                }
            }

            if (reporteMessage) {
                reporteMessage.textContent = mensajeFinal;
            }
            actualizarEstadoGlobal(
                'Ultimo envio exitoso.',
                'exito'
            );
            mostrarToast('Exito: reporte enviado.', 'exito');

            formReporte.reset();
            if (fotoInput) {
                fotoInput.value = '';
            }
            document.getElementById('form-msg-vacio').style.display = 'block';
            formReporte.style.display = 'none';
        } catch (error) {
            if (reporteMessage) {
                reporteMessage.textContent = error.message;
            }
            actualizarEstadoGlobal(error.message || 'No se pudo enviar el reporte.', 'error');
            mostrarToast(error.message || 'No se pudo enviar el reporte.', 'error');
        }
    });
}

const trackingForm = document.getElementById('tracking-form');
const trackingMessage = document.getElementById('tracking-message');
const trackingResult = document.getElementById('tracking-result');

if (trackingForm) {
    trackingForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        const trackingNumber = document.getElementById('tracking-number').value.trim();
        trackingMessage.textContent = 'Consultando...';
        trackingMessage.classList.remove('error');
        trackingResult.hidden = true;

        try {
            const response = await fetch(buildApiUrl(`/backend/api/incidencias.php?tracking_number=${encodeURIComponent(trackingNumber)}`));
            const json = await response.json();
            if (!response.ok || !json.success) {
                throw new Error(json.message || 'No se encontró la incidencia.');
            }

            const incidencia = json.data;
            trackingResult.innerHTML = `<strong>${incidencia.tracking_number}</strong><br>
                Estado: ${incidencia.estado}<br>
                Fecha: ${incidencia.fecha_reporte}<br>
                Problema: ${incidencia.tipo_problema}`;
            trackingResult.hidden = false;
            trackingMessage.textContent = '';
        } catch (error) {
            trackingMessage.textContent = error.message;
            trackingMessage.classList.add('error');
        }
    });
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
        const idContenedor = contenedor.id_contenedor ?? contenedor.id ?? 'Sin ID';
        const codigoContenedor = contenedor.codigo ?? contenedor.id ?? idContenedor;
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

        marcador.bindPopup(`<b>Contenedor:</b> ${codigoContenedor}<br>Hacé clic para reportar.`);

        marcador.on('click', function() {
            const form = document.getElementById('form-reporte');
            const placeholder = document.getElementById('form-msg-vacio');
            if (placeholder) placeholder.style.display = 'none';
            if (form) form.style.display = 'block';
            document.getElementById('form-id-contenedor').value = idContenedor;
            document.getElementById('form-direccion').value = direccion;
            if (reporteMessage) reporteMessage.textContent = '';
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
        const tieneGeoCompleta = contenedores.every(c => c?.latitud !== undefined && c?.longitud !== undefined);

        // Si la API devuelve un set parcial (ej. 2-3 mocks), usamos el set local completo para la demo.
        if (contenedores.length >= contenedoresIM.length && tieneGeoCompleta) {
            renderContenedores(contenedores);
            return;
        }

        throw new Error('La API devolvió un set parcial; se usa fallback local.');
    } catch (error) {
        console.warn('No se pudo cargar la API de contenedores, usando datos de prueba.', error);
        renderContenedores(contenedoresIM.map((contenedor, index) => ({
            id_contenedor: (index % 3) + 1,
            codigo: contenedor.id,
            latitud: contenedor.lat,
            longitud: contenedor.lng,
            direccion: `${contenedor.calle} y ${contenedor.esquina}`,
            calle: contenedor.calle,
            esquina: contenedor.esquina
        })));
    }
}

cargarContenedoresMapa();