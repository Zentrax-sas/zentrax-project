/* Carga y marcadores compartidos; la autorización administrativa también se aplica en la API. */
window.ZemynaMap = {
create(options = {}) {
let active = true;
const administrative = options.administrative === true;
const map = L.map(options.mapId || 'mapa-vedette', { maxZoom: 19 }).setView([-34.9150, -56.1540], 14);
const clusterGroup = L.markerClusterGroup({
    chunkedLoading: true,
    disableClusteringAtZoom: 18
}).addTo(map);
const incidentClusterGroup = L.markerClusterGroup({
    chunkedLoading: true,
    spiderfyOnMaxZoom: true,
    iconCreateFunction: group => L.divIcon({
        html: `<span class="incident-map-cluster">! ${group.getChildCount()}</span>`,
        className: 'incident-map-icon', iconSize: [42, 36], iconAnchor: [-8, 36]
    })
}).addTo(map);
const showContainers = document.getElementById('map-show-containers');
const showIncidents = document.getElementById('map-show-incidents');
const incidentState = administrative ? document.getElementById('map-incident-state') : null;
const incidentPriority = administrative ? document.getElementById('map-incident-priority') : null;
const incidentMarkers = new Map();
let incidentsLoadTimer;
let incidentsRequestController;
let incidentsRequestSequence = 0;
const MIN_MAP_ZOOM_FOR_CONTAINERS = 13;
const CONTAINER_LOAD_DEBOUNCE_MS = 300;
let contenedoresLoadTimer = null;
let contenedoresRequestController = null;
let contenedoresRequestSequence = 0;
const contenedorMarkers = new Map();

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
    className: 'zemyna-map-tiles'
}).addTo(map);

function markerColor(estado) {
    const normalizedState = String(estado || 'disponible').toLowerCase();
    if (normalizedState === 'lleno' || normalizedState === 'amarillo') return '#f59e0b';
    if (normalizedState === 'dañado' || normalizedState === 'danado' || normalizedState === 'rojo') return '#ef4444';
    if (normalizedState === 'fuera de servicio' || normalizedState === 'en mantenimiento' || normalizedState === 'gris' || normalizedState === 'desconocido') return '#6b7280';
    return '#22c55e';
}

function popupContent(codigoContenedor) {
    const popup = document.createElement('p');
    const popupTitle = document.createElement('strong');
    popupTitle.textContent = 'Contenedor: ';
    popup.append(popupTitle, document.createTextNode(String(codigoContenedor)), document.createElement('br'), document.createTextNode(administrative ? 'Contenedor de referencia.' : 'Hacé clic para reportar.'));
    return popup;
}

function createContainerMarker(contenedor, idKey) {
    const record = { data: contenedor, marker: null };
    const lat = parseFloat(contenedor.latitud);
    const lng = parseFloat(contenedor.longitud);
    const marcador = L.circleMarker([lat, lng], {
        radius: 10,
        fillColor: markerColor(contenedor.estado),
        color: '#ffffff',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.9
    });
    record.marker = marcador;
    marcador.bindPopup(popupContent(contenedor.codigo ?? idKey));

    if (options.onContainerSelect) marcador.on('click', () => options.onContainerSelect(record.data));

    return record;
}

function reconcileContainerMarkers(contenedores) {
    const visibleIds = new Set();

    contenedores.forEach(contenedor => {
        const idContenedor = contenedor.id_contenedor;
        if (idContenedor === null || idContenedor === undefined) return;
        const idKey = String(idContenedor);
        if (!validMapCoordinates(contenedor)) return;
        const lat = Number(contenedor.latitud);
        const lng = Number(contenedor.longitud);
        visibleIds.add(idKey);

        const existing = contenedorMarkers.get(idKey);
        if (existing) {
            existing.data = contenedor;
            existing.marker.setLatLng([lat, lng]);
            existing.marker.setStyle({ fillColor: markerColor(contenedor.estado) });
            existing.marker.setPopupContent(popupContent(contenedor.codigo ?? idContenedor));
            return;
        }

        const record = createContainerMarker(contenedor, idKey);
        contenedorMarkers.set(idKey, record);
        clusterGroup.addLayer(record.marker);
    });

    contenedorMarkers.forEach((record, idKey) => {
        if (visibleIds.has(idKey)) return;
        clusterGroup.removeLayer(record.marker);
        contenedorMarkers.delete(idKey);
    });
}

function clearContainerMarkers() {
    clusterGroup.clearLayers();
    contenedorMarkers.clear();
}

function setMapStatus(message, state = '') {
    const status = document.getElementById('mapa-status');
    if (!status) return;
    status.textContent = message;
    status.dataset.state = state;
}

function obtenerParametrosViewport() {
    const bounds = map.getBounds();
    const surOeste = bounds.getSouthWest();
    const norEste = bounds.getNorthEast();
    const parametros = new URLSearchParams({
        view: 'map',
        north: norEste.lat.toFixed(6),
        south: surOeste.lat.toFixed(6),
        east: norEste.lng.toFixed(6),
        west: surOeste.lng.toFixed(6),
        zoom: String(map.getZoom())
    });
    return parametros.toString();
}

async function cargarContenedoresMapa() {
    if (!active) return;
    if (!showContainers.checked) {
        clearContainerMarkers();
        setMapStatus('Capa de contenedores oculta.');
        return;
    }
    if (map.getZoom() < MIN_MAP_ZOOM_FOR_CONTAINERS) {
        contenedoresRequestSequence++;
        contenedoresRequestController?.abort();
        contenedoresRequestController = null;
        clearContainerMarkers();
        setMapStatus('Acercá el mapa para ver los contenedores.', 'zoom');
        return;
    }

    contenedoresRequestController?.abort();
    const requestController = new AbortController();
    contenedoresRequestController = requestController;
    const requestId = ++contenedoresRequestSequence;
    setMapStatus('Cargando contenedores...', 'loading');

    try {
        const response = await fetch(buildApiUrl(`/backend/api/contenedores.php?${obtenerParametrosViewport()}`), {
            signal: requestController.signal
        });
        const json = await response.json();

        if (requestId !== contenedoresRequestSequence) return;

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Error al cargar contenedores');
        }

        const contenedores = Array.isArray(json.data) ? json.data : [];
        reconcileContainerMarkers(contenedores);

        if (json.meta?.hasMore) {
            setMapStatus('Hay demasiados resultados. Acercá el mapa para ver un área menor.', 'limit');
        } else if (contenedores.length === 0) {
            setMapStatus('No hay contenedores visibles en esta zona.', 'empty');
        } else {
            setMapStatus(`${contenedores.length} contenedores visibles.`, 'success');
        }
    } catch (error) {
        if (error.name === 'AbortError') return;
        if (requestId !== contenedoresRequestSequence) return;
        console.warn('No se pudieron cargar los contenedores desde la API.', error);
        setMapStatus('No se pudieron cargar los contenedores. Intentá nuevamente.', 'error');
    } finally {
        if (contenedoresRequestController === requestController) {
            contenedoresRequestController = null;
        }
    }
}

function programarCargaContenedores() {
    if (!active) return;
    contenedoresRequestSequence++;
    contenedoresRequestController?.abort();
    clearTimeout(contenedoresLoadTimer);
    contenedoresLoadTimer = setTimeout(cargarContenedoresMapa, CONTAINER_LOAD_DEBOUNCE_MS);
}

map.on('moveend zoomend', programarCargaContenedores);
cargarContenedoresMapa();


function validMapCoordinates(item) {
    return ['latitud', 'longitud'].every(key =>
        (typeof item[key] === 'number' || typeof item[key] === 'string') && String(item[key]).trim() !== '' && Number.isFinite(Number(item[key])))
        && Math.abs(Number(item.latitud)) <= 90 && Math.abs(Number(item.longitud)) <= 180;
}

function incidentPopup(item) {
    const popup = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = item.tipo_problema || 'Tipo no disponible';
    popup.append(title);
    const fields = [['Estado', item.estado], ['Fecha', item.fecha_reporte], ['Contenedor', item.contenedor_codigo]];
    if (administrative) fields.splice(1, 0, ['Prioridad', item.prioridad]);
    for (const [label, value] of fields) {
        const line = document.createElement('p');
        line.textContent = `${label}: ${value ?? 'No disponible'}`;
        popup.append(line);
    }
    if (administrative && options.onIncidentSelect) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Abrir detalle';
        button.addEventListener('click', () => options.onIncidentSelect(item.id_incidencia));
        popup.append(button);
    }
    return popup;
}

function reconcileIncidentMarkers(items) {
    const visibleIds = new Set();
    for (const item of items) {
        if (!administrative && !['Pendiente', 'En Proceso'].includes(item.estado)) continue;
        if (!validMapCoordinates(item) || !Number.isSafeInteger(Number(item.id_incidencia)) || Number(item.id_incidencia) <= 0) continue;
        const key = String(item.id_incidencia);
        visibleIds.add(key);
        const signature = JSON.stringify(item);
        const existing = incidentMarkers.get(key);
        if (existing) {
            if (existing.signature !== signature) {
                existing.marker.setLatLng([Number(item.latitud), Number(item.longitud)]);
                existing.marker.setPopupContent(incidentPopup(item));
                existing.signature = signature;
            }
            continue;
        }
        const marker = L.marker([Number(item.latitud), Number(item.longitud)], {
            icon: L.divIcon({ html: '<span class="incident-map-symbol"><b>!</b></span>', className: 'incident-map-icon', iconSize: [30, 30], iconAnchor: [-8, 30] }),
            title: 'Incidencia: consultar reporte', alt: 'Incidencia', keyboard: true
        }).bindPopup(incidentPopup(item));
        // No tiene handler de selección: abrir una incidencia nunca cambia el formulario.
        incidentMarkers.set(key, { marker, signature });
        incidentClusterGroup.addLayer(marker);
    }
    for (const [key, record] of incidentMarkers) {
        if (!visibleIds.has(key)) {
            incidentClusterGroup.removeLayer(record.marker);
            incidentMarkers.delete(key);
        }
    }
}

function clearIncidentMarkers() {
    incidentClusterGroup.clearLayers();
    incidentMarkers.clear();
}

function setIncidentMapStatus(message, state = '') {
    const status = document.getElementById('mapa-incidencias-status');
    status.textContent = message;
    status.dataset.state = state;
}

async function cargarIncidenciasMapa() {
    if (!active) return;
    incidentsRequestController?.abort();
    const requestId = ++incidentsRequestSequence;
    if (!showIncidents.checked || map.getZoom() < MIN_MAP_ZOOM_FOR_CONTAINERS) {
        clearIncidentMarkers();
        setIncidentMapStatus(showIncidents.checked ? 'Acercá el mapa para ver las incidencias.' : 'Capa de incidencias oculta.');
        return;
    }
    const controller = new AbortController();
    incidentsRequestController = controller;
    const params = new URLSearchParams(obtenerParametrosViewport());
    if (administrative) {
        params.set('admin', '1');
        if (incidentState.value) params.set('estado', incidentState.value);
        if (incidentPriority.value) params.set('prioridad', incidentPriority.value);
    }
    const queries = administrative ? [params] : ['Pendiente', 'En Proceso'].map(estado => {
        const query = new URLSearchParams(params);
        query.set('estado', estado);
        query.set('limit', '150');
        return query;
    });
    setIncidentMapStatus('Cargando incidencias…', 'loading');
    try {
        const responses = await Promise.all(queries.map(async query => {
            const response = await fetch(buildApiUrl(`/backend/api/incidencias.php?${query}`), { signal: controller.signal });
            if (administrative && (response.status === 401 || response.status === 403)) {
                if (requestId === incidentsRequestSequence) {
                    pause();
                    options.onAccessDenied?.();
                }
                throw new Error('Acceso no autorizado');
            }
            const json = await response.json();
            if (!response.ok || !json.success || !Array.isArray(json.data)) throw new Error('Respuesta inválida');
            return json;
        }));
        if (requestId !== incidentsRequestSequence || controller.signal.aborted || !active) return;
        const records = new Map();
        for (const response of responses) {
            for (const item of response.data) {
                if (administrative || ['Pendiente', 'En Proceso'].includes(item.estado)) records.set(String(item.id_incidencia), item);
            }
        }
        reconcileIncidentMarkers([...records.values()]);
        const hasMore = responses.some(response => response.meta?.hasMore);
        setIncidentMapStatus(hasMore
            ? `${records.size} incidencias mostradas. Acercá el mapa para ver los demás reportes.`
            : records.size ? `${records.size} incidencias visibles.` : 'No hay incidencias para esta zona y filtros.', hasMore ? 'limit' : records.size ? 'success' : 'empty');
    } catch (error) {
        if (error.name === 'AbortError' || requestId !== incidentsRequestSequence) return;
        controller.abort();
        clearIncidentMarkers();
        setIncidentMapStatus('No se pudieron cargar las incidencias. Mové el mapa o cambiá los filtros para reintentar.', 'error');
    } finally {
        if (incidentsRequestController === controller) incidentsRequestController = null;
    }
}

function cancelarCargaIncidencias() {
    incidentsRequestSequence++;
    incidentsRequestController?.abort();
    clearTimeout(incidentsLoadTimer);
}
function programarCargaIncidencias() {
    cancelarCargaIncidencias();
    if (!active || !showIncidents.checked) return;
    // Retira los marcadores que ya no están en el área visible mientras llega la nueva respuesta.
    const bounds = map.getBounds();
    for (const [key, record] of incidentMarkers) {
        if (!bounds.contains(record.marker.getLatLng())) {
            incidentClusterGroup.removeLayer(record.marker);
            incidentMarkers.delete(key);
        }
    }
    incidentsLoadTimer = setTimeout(cargarIncidenciasMapa, CONTAINER_LOAD_DEBOUNCE_MS);
}

showContainers.addEventListener('change', () => {
    contenedoresRequestSequence++;
    contenedoresRequestController?.abort();
    clearTimeout(contenedoresLoadTimer);
    if (showContainers.checked) map.addLayer(clusterGroup);
    else map.removeLayer(clusterGroup);
    cargarContenedoresMapa();
});
showIncidents.addEventListener('change', () => {
    cancelarCargaIncidencias();
    if (showIncidents.checked) map.addLayer(incidentClusterGroup);
    else map.removeLayer(incidentClusterGroup);
    cargarIncidenciasMapa();
});
for (const filter of [incidentState, incidentPriority].filter(Boolean)) {
    filter.addEventListener('change', () => {
        clearIncidentMarkers();
        programarCargaIncidencias();
    });
}
map.on('movestart zoomstart', () => {
    cancelarCargaIncidencias();
    contenedoresRequestSequence++;
    contenedoresRequestController?.abort();
    clearTimeout(contenedoresLoadTimer);
});
map.on('moveend zoomend', programarCargaIncidencias);
cargarIncidenciasMapa();

function pause() {
    active = false;
    cancelarCargaIncidencias();
    contenedoresRequestSequence++;
    contenedoresRequestController?.abort();
    clearTimeout(contenedoresLoadTimer);
    clearIncidentMarkers();
    clearContainerMarkers();
}
function resume() {
    active = true;
    map.invalidateSize();
    cargarContenedoresMapa();
    cargarIncidenciasMapa();
}
return { map, pause, resume, refreshIncidents: programarCargaIncidencias };

}
};
