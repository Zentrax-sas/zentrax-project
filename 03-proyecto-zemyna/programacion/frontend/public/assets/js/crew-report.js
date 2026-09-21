/* Reporte puntual del trabajador. Nunca inicia seguimiento continuo ni geocodificación. */
(() => {
  const el = id => document.getElementById(id);
  let map, containerLayer, pointMarker, selectedContainer = null, point = null;
  let active = false, saving = false, completed = false, generation = 0, loadVersion = 0, geoVersion = 0;
  let loadRequest, loadTimer, geoTimer;
  const validPoint = value => value && Number.isFinite(value.lat) && Number.isFinite(value.lng) && Math.abs(value.lat) <= 90 && Math.abs(value.lng) <= 180;
  const editable = () => active && !saving && !completed;
  const stopGeo = () => { geoVersion++; clearTimeout(geoTimer); };
  function showLocation() {
    el('crewContainer').textContent = selectedContainer ? `Contenedor ${selectedContainer.codigo}` : 'Sin contenedor asociado.';
    el('crewLocation').textContent = point ? `Ubicación marcada del problema: ${point.lat}, ${point.lng}. Se guardará este punto${selectedContainer ? ', asociado al contenedor seleccionado' : ''}.` : selectedContainer ? 'Se usará la ubicación del contenedor relacionado; no se guarda un punto propio.' : 'Ubicación sin seleccionar. Tocá el mapa o ingresá las coordenadas.';
    el('crewLatitude').value = point ? point.lat : '';
    el('crewLongitude').value = point ? point.lng : '';
  }
  function choosePoint(value) {
    if (!editable() || !validPoint(value)) return false;
    stopGeo();
    point = { lat: Number(value.lat.toFixed(7)), lng: Number(value.lng.toFixed(7)) };
    el('crewGeoStatus').textContent = '';
    if (pointMarker) pointMarker.setLatLng([point.lat, point.lng]);
    else {
      pointMarker = L.marker([point.lat, point.lng], { draggable: true, title: 'Ubicación marcada del problema: arrastrá para ajustar', keyboard: true }).addTo(map);
      pointMarker.on('dragend', () => {
        if (!editable()) { pointMarker.setLatLng([point.lat, point.lng]); return; }
        const position = pointMarker.getLatLng(); choosePoint({ lat: position.lat, lng: position.lng });
      });
    }
    showLocation();
    return true;
  }
  function clearPoint() {
    stopGeo();
    point = null;
    if (pointMarker) map.removeLayer(pointMarker);
    pointMarker = null;
    showLocation();
  }
  async function loadContainers() {
    if (!active) return;
    loadRequest?.abort();
    const version = ++loadVersion;
    containerLayer.clearLayers();
    if (map.getZoom() < 13) { el('crewMapStatus').textContent = 'Acercá el mapa para elegir contenedores. Podés marcar el problema en cualquier punto.'; return; }
    const bounds = map.getBounds(), sw = bounds.getSouthWest(), ne = bounds.getNorthEast();
    const query = new URLSearchParams({ view: 'map', south: sw.lat, north: ne.lat, west: sw.lng, east: ne.lng, zoom: map.getZoom() });
    const request = new AbortController(); loadRequest = request;
    el('crewMapStatus').textContent = 'Cargando contenedores…';
    try {
      const response = await fetch(buildApiUrl(`/backend/api/contenedores.php?${query}`), { signal: request.signal });
      const json = await readJsonResponse(response);
      if (version !== loadVersion || !active) return;
      if (!response.ok || !json.success || !Array.isArray(json.data)) throw new Error('No se pudieron cargar los contenedores. Podés marcar el punto manualmente.');
      const seen = new Set();
      for (const container of json.data) {
        const coords = { lat: Number(container.latitud), lng: Number(container.longitud) };
        if (container.latitud == null || container.longitud == null || !validPoint(coords) || seen.has(String(container.id_contenedor))) continue;
        seen.add(String(container.id_contenedor));
        const marker = L.circleMarker([coords.lat, coords.lng], { radius: 9, color: '#ffffff', fillColor: '#22c55e', fillOpacity: .9, weight: 2, bubblingMouseEvents: false });
        const popup = document.createElement('p'); popup.textContent = `Contenedor ${container.codigo}: tocá para asociarlo.`;
        marker.bindPopup(popup).on('click', () => {
          if (!editable()) return;
          selectedContainer = container;
          clearPoint();
        });
        containerLayer.addLayer(marker);
      }
      el('crewMapStatus').textContent = json.meta?.hasMore ? 'Hay más contenedores. Acercá el mapa para verlos.' : `${seen.size} contenedores disponibles en esta zona.`;
    } catch (error) {
      if (version === loadVersion && active && error.name !== 'AbortError') el('crewMapStatus').textContent = 'No se pudieron cargar los contenedores. Podés marcar el punto manualmente.';
    }
  }
  function scheduleContainers() {
    loadVersion++; loadRequest?.abort(); clearTimeout(loadTimer);
    if (active) loadTimer = setTimeout(loadContainers, 300);
  }
  function initializeMap() {
    if (map) { map.invalidateSize(); return; }
    map = L.map('crewMap', { maxZoom: 19 }).setView([-34.915, -56.154], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
    containerLayer = L.layerGroup().addTo(map);
    map.on('click', event => choosePoint({ lat: event.latlng.lat, lng: event.latlng.lng }));
    map.on('movestart zoomstart', () => { loadVersion++; loadRequest?.abort(); clearTimeout(loadTimer); });
    map.on('moveend zoomend', scheduleContainers);
  }
  function pause() {
    active = false; generation++; loadVersion++; loadRequest?.abort(); clearTimeout(loadTimer); stopGeo();
  }
  async function open() {
    pause(); const version = generation;
    el('crewForm').hidden = true; el('crewStatus').textContent = 'Validando permiso para reportar…';
    try {
      if (!await adminSessionReady) throw new Error('Se requiere una sesión válida.');
      if (version !== generation) return;
      const result = await incidentApi({ view: 'crew' });
      if (version !== generation) return;
      const select = el('crewType'), previous = select.value;
      select.replaceChildren(new Option('Elegí un tipo', ''));
      for (const type of result.data.tipos) select.add(new Option(type, type));
      select.value = previous;
      active = true; el('crewForm').hidden = false; el('crewStatus').textContent = '';
      initializeMap(); showLocation(); loadContainers();
    } catch (error) {
      if (version === generation) { el('crewStatus').textContent = error.message; el('crewConfirmation').hidden = true; el('crewNew').hidden = true; }
    }
  }
  el('crewClearContainer').addEventListener('click', () => { if (editable()) { selectedContainer = null; showLocation(); } });
  el('crewClearPoint').addEventListener('click', () => { if (editable()) clearPoint(); });
  el('crewApplyPoint').addEventListener('click', () => {
    if (!editable()) return;
    const lat = el('crewLatitude').value.trim(), lng = el('crewLongitude').value.trim();
    if (!lat || !lng || !choosePoint({ lat: Number(lat), lng: Number(lng) })) { el('crewStatus').textContent = 'Ingresá ambas coordenadas dentro de rango.'; return; }
    el('crewStatus').textContent = ''; map.setView([point.lat, point.lng], Math.max(map.getZoom(), 15));
  });
  el('crewDevice').addEventListener('click', () => {
    if (!editable()) return;
    stopGeo(); const version = geoVersion;
    const failed = () => { if (version !== geoVersion || !editable()) return; stopGeo(); el('crewGeoStatus').textContent = 'No se obtuvo la ubicación. Tocá el mapa para marcarla manualmente.'; };
    if (!navigator.geolocation) { failed(); return; }
    el('crewGeoStatus').textContent = 'Solicitando ubicación del dispositivo… Podés marcar el punto manualmente sin esperar.';
    geoTimer = setTimeout(failed, 9000);
    try {
      navigator.geolocation.getCurrentPosition(position => {
        if (version !== geoVersion || !editable()) return;
        if (!choosePoint({ lat: position.coords.latitude, lng: position.coords.longitude })) { failed(); return; }
        map.setView([point.lat, point.lng], 17);
        el('crewGeoStatus').textContent = 'Ubicación obtenida. Comprobá y ajustá el punto antes de enviar.';
      }, failed, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
    } catch (error) { failed(); }
  });
  el('crewForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!editable() || !el('crewForm').reportValidity()) return;
    if (!selectedContainer && !point) { el('crewStatus').textContent = 'Sin contenedor debés marcar una ubicación válida.'; return; }
    // Si se editaron coordenadas a mano, exigir aplicarlas para no enviar un punto anterior.
    if (el('crewLatitude').value !== (point ? String(point.lat) : '') || el('crewLongitude').value !== (point ? String(point.lng) : '')) {
      el('crewStatus').textContent = 'Aplicá las coordenadas con «Ajustar punto» antes de enviar.'; return;
    }
    const payload = { tipo_problema: el('crewType').value, descripcion: el('crewDescription').value.trim() };
    if (selectedContainer) payload.id_contenedor = selectedContainer.id_contenedor;
    if (point) { payload.latitud = point.lat; payload.longitud = point.lng; }
    saving = true; stopGeo(); el('crewFields').disabled = true; el('crewStatus').textContent = 'Guardando reporte…';
    try {
      const result = await incidentApi({ view: 'crew' }, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      if (!result.data?.tracking_number) throw new Error('Respuesta sin tracking. Verificá con administración antes de reenviar.');
      completed = true; el('crewStatus').textContent = '';
      el('crewConfirmation').textContent = `Reporte guardado. Seguimiento: ${result.data.tracking_number}. Ya está disponible para administración.`;
      el('crewConfirmation').hidden = false; el('crewNew').hidden = false;
    } catch (error) { el('crewStatus').textContent = error.message; }
    finally { saving = false; el('crewFields').disabled = completed; }
  });
  el('crewNew').addEventListener('click', () => {
    if (!active || saving) return;
    completed = false; el('crewFields').disabled = false; el('crewForm').reset();
    selectedContainer = null; clearPoint(); el('crewConfirmation').hidden = true; el('crewNew').hidden = true; el('crewStatus').textContent = '';
  });
  window.CrewReport = { open, pause };
})();
