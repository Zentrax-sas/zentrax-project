/* Solo representa el recorrido autorizado; no consulta APIs ni registra operaciones. */
window.CollectionRouteMap = {
  create() {
    const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
    const root = node('section'); root.className = 'route-map-content';
    const canvas = node('div'); canvas.className = 'route-map-canvas'; canvas.setAttribute('aria-label', 'Contenedores del recorrido');
    const status = node('p'); status.setAttribute('role', 'status');
    const info = node('div'); info.className = 'route-map-selection'; info.setAttribute('aria-live', 'polite');
    root.append(canvas, status, info);
    let map, group, tripId = null, selected = null, rows = [], records = new Map(), fitted = false, tileFailed = false;
    let options = {};
    const valid = c => c.latitud != null && c.longitud != null && String(c.latitud).trim() !== '' && String(c.longitud).trim() !== ''
      && Number.isFinite(Number(c.latitud)) && Number.isFinite(Number(c.longitud)) && Math.abs(Number(c.latitud)) <= 90 && Math.abs(Number(c.longitud)) <= 180;
    const state = c => c.fecha_atencion ? 'Atendido' : 'Pendiente';
    function layout() {
      if (!map) return;
      const size = canvas.getBoundingClientRect?.();
      // No consumir el encuadre inicial mientras el panel esté oculto.
      if (size && (size.width <= 0 || size.height <= 0)) return;
      map.invalidateSize();
      const points = rows.filter(valid).map(c => [Number(c.latitud), Number(c.longitud)]);
      if (!fitted && points.length) { map.fitBounds(points, { padding: [24, 24], maxZoom: 17 }); fitted = true; }
    }
    function selection() {
      const c = rows.find(c => String(c.id_contenedor) === selected);
      info.replaceChildren();
      if (!c) { info.append(node('p', 'Seleccioná un marcador para consultar su código y estado.')); return; }
      info.append(node('strong', c.codigo), node('p', state(c)));
      const action = options.action?.(c);
      if (action) info.append(action);
      else info.append(node('p', 'Sin acción de atención disponible para este contenedor.'));
    }
    function init() {
      if (map) return;
      if (!window.L) throw new Error('Leaflet no disponible');
      const L = window.L;
      map = L.map(canvas, { maxZoom: 19 }).setView([-34.9, -56.16], 12);
      group = (L.markerClusterGroup ? L.markerClusterGroup({ chunkedLoading: true }) : L.layerGroup()).addTo(map);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap contributors' })
        .on('tileerror', () => { tileFailed = true; status.textContent = 'No se pudo cargar el mapa base. La lista y las operaciones siguen disponibles.'; options.onUnavailable?.(); }).addTo(map);
    }
    function clear() {
      map?.remove(); map = group = null; tripId = selected = null; rows = []; records.clear(); fitted = false; tileFailed = false; info.replaceChildren();
    }
    return {
      render(host, id, containers, filter, configuration = {}) {
        options = configuration;
        host.append(root);
        try {
          if (tripId !== id) { group?.clearLayers(); records.clear(); selected = null; fitted = false; tripId = id; }
          rows = containers;
          if ('selected' in options) selected = options.selected;
          if (!rows.some(c => String(c.id_contenedor) === selected)) selected = null;
          init(); group.clearLayers(); records.clear();
          const visible = rows.filter(c => filter === 'todos' || (filter === 'atendidos' ? !!c.fecha_atencion : !c.fecha_atencion));
          for (const c of visible.filter(valid)) {
            const popup = node('div'); popup.append(node('strong', c.codigo), node('p', state(c)));
            const marker = window.L.marker([Number(c.latitud), Number(c.longitud)], {
              title: `${c.codigo} · ${state(c)}`, keyboard: true,
              icon: window.L.divIcon({ className: `route-container-marker ${c.fecha_atencion ? 'attended' : 'pending'}${String(c.id_contenedor) === selected ? ' selected' : ''}`, html: c.fecha_atencion ? '✓' : '•', iconSize: [30, 30] })
            }).bindPopup(popup);
            marker.on('click', () => { const id = String(c.id_contenedor); if (options.onSelect?.(id) === false) return; selected = id; selection(); });
            group.addLayer(marker); records.set(String(c.id_contenedor), marker);
          }
          const points = rows.filter(valid).map(c => [Number(c.latitud), Number(c.longitud)]);
          if (!tileFailed) status.textContent = `${records.size} contenedores ubicados en el filtro. ${rows.length - points.length} sin coordenadas válidas. Pendiente: punto violeta; atendido: tilde verde. No representa un trayecto por calles.`;
          selection(); layout();
          return !tileFailed;
        } catch {
          status.textContent = 'Mapa no disponible. Podés continuar trabajando desde la lista.';
          return false;
        }
      },
      focus(id) {
        const marker = records.get(String(id)), expectedTrip = tripId;
        if (!marker || !map) return false;
        layout();
        const reveal = () => { if (expectedTrip !== tripId || records.get(String(id)) !== marker) return; marker.openPopup(); };
        if (group.zoomToShowLayer) group.zoomToShowLayer(marker, reveal);
        else { map.setView(marker.getLatLng(), 17); reveal(); }
        return true;
      },
      resize() { try { layout(); } catch { status.textContent = 'No se pudo ajustar el mapa. La lista sigue disponible.'; } },
      clear
    };
  }
};
