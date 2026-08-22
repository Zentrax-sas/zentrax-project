if (window.location.protocol === 'file:') {
    window.location.replace(`${APP_BASE_URL}/index.html`);
}

const map = L.map('mapa-vedette', { maxZoom: 19 }).setView([-34.9150, -56.1540], 14);
const clusterGroup = L.markerClusterGroup({
    chunkedLoading: true,
    disableClusteringAtZoom: 18
}).addTo(map);
const MIN_MAP_ZOOM_FOR_CONTAINERS = 12;
let contenedoresLoadTimer = null;

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO'
}).addTo(map);

const userLocationMarker = {
    marker: null,
    isLoading: false,
    controlButton: null
};

L.Control.Geolocalizacion = L.Control.extend({
    onAdd() {
        const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
        const button = L.DomUtil.create('a', 'leaflet-control-geolocalizacion', container);

        container.style.overflow = 'visible';
        container.style.display = 'flex';
        container.style.alignItems = 'center';

        button.href = '#';
        button.title = 'Mi ubicación';
        button.setAttribute('role', 'button');
        button.setAttribute('aria-label', 'Mi ubicación');
        button.textContent = '📍 Ubicación';
        button.style.display = 'inline-flex';
        button.style.alignItems = 'center';
        button.style.justifyContent = 'center';
        button.style.gap = '5px';
        button.style.width = 'auto';
        button.style.minWidth = 'max-content';
        button.style.maxWidth = 'none';
        button.style.padding = '6px 10px';
        button.style.borderRadius = '8px';
        button.style.border = '1px solid rgba(255,255,255,0.3)';
        button.style.background = '#ffffff';
        button.style.color = '#1f2937';
        button.style.fontSize = '12px';
        button.style.fontWeight = '700';
        button.style.lineHeight = '1.1';
        button.style.boxShadow = '0 2px 8px rgba(15, 23, 42, 0.18)';
        button.style.cursor = 'pointer';
        button.style.whiteSpace = 'nowrap';
        button.style.overflow = 'visible';

        L.DomEvent.on(button, 'click', L.DomEvent.stopPropagation)
            .on(button, 'click', L.DomEvent.preventDefault)
            .on(button, 'click', () => {
                obtenerUbicacionUsuario();
            });

        userLocationMarker.controlButton = button;
        return container;
    }
});

L.control.geolocalizacion = function (options) {
    return new L.Control.Geolocalizacion(options);
};

L.control.geolocalizacion({ position: 'topright' }).addTo(map);

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

function actualizarEstadoBotonGeolocalizacion(cargando = false) {
    const button = userLocationMarker.controlButton;
    if (!button) return;

    button.disabled = cargando;
    button.setAttribute('aria-disabled', String(cargando));
    button.style.opacity = cargando ? '0.7' : '1';
    button.style.cursor = cargando ? 'wait' : 'pointer';
    button.title = cargando ? 'Obteniendo ubicación...' : 'Mi ubicación';
    button.textContent = cargando ? '⏳ Obteniendo…' : '📍 Mi ubicación';
}

function actualizarMarcadorUbicacion(latitud, longitud) {
    const latLng = [latitud, longitud];

    if (!userLocationMarker.marker) {
        userLocationMarker.marker = L.circleMarker(latLng, {
            radius: 8,
            fillColor: '#2563eb',
            color: '#ffffff',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.95
        }).addTo(map);

        userLocationMarker.marker.bindPopup('Estás aquí');
    } else {
        userLocationMarker.marker.setLatLng(latLng);
    }

    map.setView(latLng, 16);
    userLocationMarker.marker.openPopup();
}

function obtenerUbicacionUsuario() {
    if (!navigator.geolocation) {
        const error = new Error('Tu navegador no soporta geolocalización.');
        mostrarToast(error.message, 'error');
        return;
    }

    actualizarEstadoBotonGeolocalizacion(true);

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const { latitude, longitude } = position.coords;
            actualizarMarcadorUbicacion(latitude, longitude);
            actualizarEstadoBotonGeolocalizacion(false);
        },
        (error) => {
            actualizarEstadoBotonGeolocalizacion(false);
            mostrarToast(error.message || 'No se pudo obtener tu ubicación.', 'error');
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
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

function renderContenedores(contenedores) {
    contenedores.forEach(contenedor => {
        const lat = parseFloat(contenedor.latitud ?? contenedor.lat ?? -34.9150);
        const lng = parseFloat(contenedor.longitud ?? contenedor.lng ?? -56.1540);
        const idContenedor = contenedor.id_contenedor ?? contenedor.id ?? 'Sin ID';
        const codigoContenedor = contenedor.codigo ?? contenedor.id ?? idContenedor;
        const direccion = contenedor.direccion || (contenedor.calle && contenedor.esquina ? `${contenedor.calle} y ${contenedor.esquina}` : 'Sin dirección');
        const estado = String(contenedor.estado || 'disponible').toLowerCase();
        let colorNeon = '#22c55e';

        if (estado === 'lleno' || estado === 'amarillo') colorNeon = '#f59e0b';
        else if (estado === 'dañado' || estado === 'danado' || estado === 'rojo') colorNeon = '#ef4444';
        else if (estado === 'fuera de servicio' || estado === 'en mantenimiento' || estado === 'gris' || estado === 'desconocido') colorNeon = '#6b7280';

        const marcador = L.circleMarker([lat, lng], {
            radius: 10,
            fillColor: colorNeon,
            color: '#ffffff',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.9
        });

        clusterGroup.addLayer(marcador);

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

function mostrarAvisoZoom() {
    mostrarToast('Acercate en el mapa para ver los contenedores.', 'error');
}

function obtenerParametrosViewport() {
    const bounds = map.getBounds();
    const surOeste = bounds.getSouthWest();
    const norEste = bounds.getNorthEast();
    const parametros = new URLSearchParams({
        min_lat: surOeste.lat.toFixed(6),
        min_lon: surOeste.lng.toFixed(6),
        max_lat: norEste.lat.toFixed(6),
        max_lon: norEste.lng.toFixed(6),
        limit: '2000'
    });
    return parametros.toString();
}

async function cargarContenedoresMapa() {
    if (map.getZoom() < MIN_MAP_ZOOM_FOR_CONTAINERS) {
        clusterGroup.clearLayers();
        mostrarAvisoZoom();
        return;
    }

    clusterGroup.clearLayers();

    try {
        const response = await fetch(buildApiUrl(`/backend/api/contenedores.php?${obtenerParametrosViewport()}`));
        const json = await response.json();

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Error al cargar contenedores');
        }

        const contenedores = Array.isArray(json.data) ? json.data : [];

        if (contenedores.length === 0) {
            console.info('No hay contenedores cargados en la base de datos.');
            return;
        }

        renderContenedores(contenedores);
    } catch (error) {
        console.warn('No se pudieron cargar los contenedores desde la API.', error);
        const contenedoresLocales = Array.isArray(window.contenedoresIM) ? window.contenedoresIM : [];
        if (contenedoresLocales.length > 0) {
            renderContenedores(contenedoresLocales);
            return;
        }
        mostrarToast('No se pudieron cargar los contenedores.', 'error');
    }
}

function programarCargaContenedores() {
    clearTimeout(contenedoresLoadTimer);
    contenedoresLoadTimer = setTimeout(cargarContenedoresMapa, 400);
}

map.on('moveend zoomend', programarCargaContenedores);
cargarContenedoresMapa();