/* Consulta vial F5.3: sin acciones operativas, GPS ni cálculo local de rutas. */
(() => {
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  const number = n => typeof n === 'number' && Number.isFinite(n);
  const point = p => p && number(p.latitud) && number(p.longitud) && Math.abs(p.latitud) <= 90 && Math.abs(p.longitud) <= 180;
  const coordinate = p => Array.isArray(p) && p.length === 2 && point({ latitud: p[1], longitud: p[0] });
  let version = 0, current = null, request, map, line, originMarker, destinationMarker;
  function removeRoute() {
    if (line) map?.removeLayer(line);
    if (destinationMarker) map?.removeLayer(destinationMarker);
    line = destinationMarker = null;
    if (current) { current.result.replaceChildren(); current.result.hidden = true; }
  }
  function clear() {
    version++; request?.abort(); removeRoute(); map?.remove();
    map = originMarker = null; current?.section.remove(); current = null;
  }
  function eligible(row, c) {
    return c?.puede_operar_incidencias === true && c.funcion_operativa === 'APOYO'
      && c.estado_v19 === 'vigente' && Number(c.vehiculo?.activo) === 1
      && ['Disponible', 'En Servicio'].includes(c.vehiculo?.estado)
      && ((row.atencion?.estado === 'Aceptada' && ['Pendiente', 'En Proceso'].includes(row.estado))
        || (row.atencion?.estado === 'En atención' && row.estado === 'En Proceso'))
      && row.fecha_resolucion == null;
  }
  function location(row) {
    if (row.latitud == null || row.longitud == null || String(row.latitud).trim() === '' || String(row.longitud).trim() === '') return null;
    const p = { latitud: Number(row.latitud), longitud: Number(row.longitud) }; return point(p) ? p : null;
  }
  function select(p) {
    if (!current || !point(p)) return;
    version++; request?.abort(); removeRoute();
    current.origin = { latitud: Number(p.latitud.toFixed(7)), longitud: Number(p.longitud.toFixed(7)) };
    if (originMarker) map.removeLayer(originMarker);
    originMarker = L.marker([current.origin.latitud, current.origin.longitud]).addTo(map);
    current.originText.textContent = `Origen seleccionado: ${current.origin.latitud}, ${current.origin.longitud}. Punto manual; no es la posición actual del camión.`;
    current.status.textContent = 'Origen seleccionado. Consultá para obtener una sugerencia vial.';
    current.submit.disabled = false;
  }
  function validData(d, origin) {
    return d && d.consultiva === true && d.motor === 'OSRM' && d.perfil === 'driving'
      && point(d.origen) && d.origen.latitud === origin.latitud && d.origen.longitud === origin.longitud
      && point(d.destino) && ['problema', 'contenedor'].includes(d.destino.fuente)
      && number(d.distancia_metros) && d.distancia_metros >= 0 && number(d.duracion_segundos) && d.duracion_segundos >= 0
      && d.geometria?.type === 'LineString' && Array.isArray(d.geometria.coordinates)
      && d.geometria.coordinates.length >= 2 && d.geometria.coordinates.length <= 10000
      && d.geometria.coordinates.every(coordinate)
      && Array.isArray(d.puntos_ajustados) && d.puntos_ajustados.length === 2
      && d.puntos_ajustados.every(p => point(p) && number(p.ajuste_metros) && p.ajuste_metros >= 0 && p.ajuste_metros <= 100)
      && Array.isArray(d.advertencias) && d.advertencias.length <= 30
      && d.advertencias.every(w => typeof w === 'string' && w.length <= 2000);
  }
  async function load() {
    if (!current?.origin || current.blocked) return;
    request?.abort(); request = new AbortController(); const token = ++version, view = current;
    const origin = { ...view.origin }; removeRoute(); view.status.textContent = 'Consultando ruta sugerida…'; view.submit.disabled = true;
    try {
      const query = new URLSearchParams({ id_incidencia: view.id, origen_latitud: origin.latitud.toFixed(7), origen_longitud: origin.longitud.toFixed(7) });
      const response = await fetch(buildApiUrl(`/backend/api/ruta_apoyo.php?${query}`, { cacheBust: false }),
        { method: 'GET', cache: 'no-store', credentials: 'same-origin', signal: request.signal });
      if (current !== view || token !== version) return;
      if (!response.ok) throw Object.assign(new Error(), { status: response.status });
      const body = await response.json();
      if (current !== view || token !== version) return;
      if (body.success !== true || !validData(body.data, origin)) throw new Error('respuesta');
      const d = body.data;
      // L.geoJSON recibe longitud/latitud y dibuja únicamente la geometría del backend.
      line = L.geoJSON(d.geometria, { style: { color: '#7756c8', weight: 5 } }).addTo(map);
      destinationMarker = L.marker([d.destino.latitud, d.destino.longitud]).addTo(map);
      map.fitBounds(line.getBounds(), { padding: [24, 24] });
      view.result.hidden = false;
      view.result.append(node('p', `Destino autorizado (${d.destino.fuente}): ${d.destino.latitud}, ${d.destino.longitud}.`),
        node('p', `Distancia estimada: ${(d.distancia_metros / 1000).toLocaleString('es-UY', { maximumFractionDigits: 2 })} km · Duración estimada: ${(d.duracion_segundos / 60).toLocaleString('es-UY', { maximumFractionDigits: 1 })} min.`),
        node('p', `Motor: ${d.motor} · Perfil: ${d.perfil}. Cálculo independiente del Dijkstra académico PHP.`));
      const warnings = node('ul'); for (const warning of d.advertencias) warnings.append(node('li', warning));
      view.result.append(warnings, node('p', `Ajuste a la vía: origen ${d.puntos_ajustados[0].ajuste_metros.toFixed(1)} m; destino ${d.puntos_ajustados[1].ajuste_metros.toFixed(1)} m.`));
      view.status.textContent = 'Ruta sugerida recibida. Las estimaciones no son garantías.';
    } catch (error) {
      if (current !== view || token !== version || error.name === 'AbortError') return;
      removeRoute();
      view.status.textContent = ({ 400: 'El origen o la consulta no son válidos. Volvé a seleccionar el punto.',
        401: 'Tu sesión venció. Iniciá sesión nuevamente.', 403: 'No tenés permisos vigentes para consultar esta ruta.',
        404: 'La incidencia ya no está disponible para tu cuadrilla. Actualizá el detalle.',
        409: 'La asignación o el contexto cambiaron. Actualizá el detalle antes de consultar.',
        422: 'No hay una ruta disponible o la ubicación requiere revisión por su ajuste a la vía.',
        502: 'El servicio vial devolvió una respuesta inválida.', 503: 'El servicio OSRM está temporalmente indisponible o sin configurar.',
        504: 'El servicio OSRM agotó el tiempo de consulta.' })[error.status] || 'No se recibió una respuesta vial válida. Volvé a intentar.';
      if ([401, 403, 404, 409].includes(error.status)) {
        view.blocked = true; map?.remove(); map = originMarker = null;
        view.mapElement.hidden = true; view.center.disabled = true;
      }
      if (error.status === 401) {
        window.dispatchEvent(new Event('zemyna:session-expired'));
        window.location.replace(buildFrontendUrl('login.html'));
      }
    } finally { if (current === view && token === version) view.submit.disabled = view.blocked || !view.origin; }
  }
  function mount(row, context, parent) {
    clear();
    const section = node('section'); section.className = 'incident-route'; parent.append(section);
    current = { section, result: node('div'), id: row.id_incidencia };
    if (!eligible(row, context)) { section.append(node('p', 'Podés consultar la ruta con un intento aceptado o en atención y una asignación operativa APOYO vigente.')); return; }
    const destination = location(row);
    if (!destination) { section.append(node('p', 'Esta incidencia no tiene coordenadas puntuales disponibles para consultar una ruta.')); return; }
    const open = node('button', 'Ver ruta sugerida'); open.type = 'button'; section.append(open);
    // Registrar el contenedor antes de abrir permite limpiar también una acción pendiente.
    open.addEventListener('click', () => {
      if (current?.section !== section || current.mapElement) return;
      open.hidden = true; section.append(node('h3', 'Ruta sugerida'));
      const warning = node('p', 'Ruta sugerida. No sustituye las indicaciones viales ni garantiza acceso para vehículos pesados.'); warning.className = 'route-warning';
      const originText = node('p', 'Origen sin seleccionar. Tocá el mapa para elegir el punto de partida.'), status = node('p');
      status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
      const mapElement = node('div'); mapElement.className = 'incident-route-map'; mapElement.setAttribute('aria-label', 'Mapa para seleccionar explícitamente el origen de la sugerencia');
      const center = node('button', 'Usar centro del mapa como origen'); center.type = 'button';
      const submit = node('button', 'Consultar ruta sugerida'); submit.type = 'button'; submit.disabled = true;
      const close = node('button', 'Cerrar ruta sugerida'); close.type = 'button'; close.addEventListener('click', () => mount(row, context, parent));
      current = { ...current, originText, status, mapElement, center, submit, origin: null, blocked: false }; current.result.hidden = true;
      section.append(warning, node('p', 'Elegí un origen manual. También podés mover el mapa con las flechas y confirmar su centro. No se consulta GPS ni se sigue al camión.'),
        originText, mapElement, center, submit, status, current.result, close);
      if (typeof L === 'undefined') { status.textContent = 'No se pudo cargar Leaflet. Recargá la página para utilizar el mapa.'; center.disabled = true; return; }
      try {
        map = L.map(mapElement).setView([destination.latitud, destination.longitud], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 })
          .on('tileerror', () => { if (current?.section === section) status.textContent = 'El mapa base no está disponible. No se dibujan rutas alternativas.'; }).addTo(map);
        map.on('click', event => { if (!current?.blocked) select({ latitud: event.latlng.lat, longitud: event.latlng.lng }); });
        center.addEventListener('click', () => { if (map && !current?.blocked) { const p = map.getCenter(); select({ latitud: p.lat, longitud: p.lng }); } });
        submit.addEventListener('click', load); map.invalidateSize();
      } catch (error) { status.textContent = 'No se pudo inicializar el mapa. Cerrá y volvé a consultar el detalle.'; center.disabled = true; }
    });
  }
  window.IncidentRoute = { mount, clear };
  window.addEventListener('zemyna:session-expired', clear);
  window.addEventListener('pagehide', clear);
  window.addEventListener('hashchange', () => { if (window.location.hash && window.location.hash !== '#ownIncidents') clear(); });
  document.getElementById('collectionReportButton')?.addEventListener('click', clear);
  document.getElementById('collectionRetry')?.addEventListener('click', clear);
})();
