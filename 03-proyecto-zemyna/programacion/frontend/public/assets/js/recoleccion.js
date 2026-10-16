(() => {
  const el = id => document.getElementById(id);
  // La API valida parámetros funcionales: la caché se controla con fetch/no-store, no con t.
  const apiUrl = params => {
    const search = new URLSearchParams(params || {}).toString();
    return buildApiUrl(`/backend/api/recoleccion.php${search ? `?${search}` : ''}`, { cacheBust: false });
  };
  function sessionExpired() {
    clearMap();
    window.dispatchEvent?.(new Event('zemyna:session-expired'));
    el('collectionStatus').textContent = 'Tu sesión venció. Iniciá sesión nuevamente.';
    el('collectionLogin').hidden = false;
    el('collectionLogin').href = buildFrontendUrl('login.html');
    window.location.replace(buildFrontendUrl('login.html'));
    el('collectionPanel').hidden = true;
  }
  function responseError(status, json) {
    if (status === 403) return 'Tu cuenta no está habilitada para operar recorridos. Contactá a un administrador.';
    if (status === 400 || status === 422) return `Solicitud inválida. ${json.message || 'Volvé a consultar.'}`;
    if (status >= 500) return 'Error del servidor. Volvé a intentar más tarde.';
    if (json.code === 'recorrido_ambiguo') return 'Recorrido compartido o ambiguo. El administrador debe resolver la asignación de cuadrilla.';
    return json.message || 'No se pudo completar la consulta.';
  }

  let query = {}, version = 0, request, saving = false, canOperate = false;
  let containerFilter = 'pendientes', visibleLimit = 20, shownTrip = null;
  let routeMap, collectionView = 'list';
  let selectedContainer = null;
  const compactViewport = window.matchMedia?.('(max-width: 900px)');
  let applyLayout;
  compactViewport?.addEventListener('change', () => applyLayout?.());
  function clearMap() { selectedContainer = null; routeMap?.clear(); }
  const node = (tag, value) => { const n = document.createElement(tag); n.textContent = value; return n; };
  const vehicles = rows => rows.length ? rows.map(v => `${v.matricula} (${v.estado}; ${Number(v.activo) === 1 ? 'activo' : 'baja lógica'})`).join(', ') : 'Sin vehículos relacionados.';
  const actionButton = (label, body, confirmation) => {
    const born = version;
    const button = node('button', label); button.type = 'button';
    button.disabled = saving;
    button.addEventListener('click', () => { if (born === version) save(body, confirmation); }); return button;
  };
  function mapLink(row, parent, select) {
    const lat = Number(row.latitud), lon = Number(row.longitud);
    if (row.latitud != null && row.longitud != null && String(row.latitud).trim() && String(row.longitud).trim() && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
      const b = node('button', 'Ver en mapa'); b.type = 'button'; b.addEventListener('click', () => select(String(row.id_contenedor), true));
      parent.append(b);
    }
  }
  function operational(data, writable) {
    el('collectionPanel').hidden = false;
    el('collectionHeading').textContent = data.pertenencia ? `${data.pertenencia.nombre} · ${data.pertenencia.turno}` : 'Detalle del recorrido';
    el('collectionVehicles').textContent = '';
    const trip = data.recorrido;
    el('collectionTimezone').hidden = !trip?.fecha_inicio && !trip?.fecha_fin;
    if (!trip) {
      clearMap(); shownTrip = null;
      const card = node('article', ''); card.className = 'collection-empty';
      card.append(node('h3', 'Sin recorrido asignado'), node('p', 'Tu cuadrilla todavía no tiene un recorrido disponible. Podés actualizar la información más tarde o reportar un problema detectado durante tu jornada.'));
      el('collectionItems').append(card); el('collectionStatus').textContent = ''; return;
    }
    if (shownTrip !== trip.id_recorrido) {
      selectedContainer = null;
      const validPoint = trip.contenedores.some(c => c.latitud != null && c.longitud != null && String(c.latitud).trim() && String(c.longitud).trim() && Number.isFinite(Number(c.latitud)) && Number.isFinite(Number(c.longitud)) && Math.abs(Number(c.latitud)) <= 90 && Math.abs(Number(c.longitud)) <= 180);
      collectionView = compactViewport?.matches && validPoint && window.CollectionRouteMap ? 'map' : 'list';
      shownTrip = trip.id_recorrido; containerFilter = trip.progreso.pendientes > 0 ? 'pendientes' : 'todos'; visibleLimit = 20;
    }
    const card = node('article', ''); card.className = 'collection-summary';
    card.append(node('h3', trip.ruta_nombre), node('p', `Recorrido: ${trip.estado}`),
      node('p', `Vehículo: ${vehicles(trip.vehiculos)}`));
    const metrics = node('div', ''); metrics.className = 'collection-metrics';
    for (const [label, value] of [['Total', trip.progreso.total], ['Atendidos', trip.progreso.atendidos], ['Pendientes', trip.progreso.pendientes]]) {
      const metric = node('p', ''); metric.append(node('strong', String(value)), node('span', label)); metrics.append(metric);
    }
    const progress = node('progress', ''); progress.max = Math.max(1, trip.progreso.total); progress.value = trip.progreso.atendidos;
    progress.setAttribute?.('aria-label', `${trip.progreso.atendidos} de ${trip.progreso.total} contenedores atendidos`);
    card.append(metrics, progress);
    const operation = node('div', ''); operation.className = 'collection-actions';
    if (writable && trip.estado === 'Pendiente') operation.append(actionButton('Iniciar recorrido', { accion: 'iniciar', id_recorrido: trip.id_recorrido }, '¿Iniciar este recorrido? Se registrará tu usuario y la hora actual.'));
    if (writable && trip.estado === 'En Proceso') operation.append(actionButton('Finalizar recorrido', { accion: 'finalizar', id_recorrido: trip.id_recorrido }, `¿Finalizar el recorrido con ${trip.progreso.pendientes} contenedores pendientes? Se conservará el historial.`));
    card.append(operation); el('collectionItems').append(card);
    const toolbar = node('nav', ''); toolbar.className = 'container-filters'; toolbar.setAttribute?.('aria-label', 'Filtrar contenedores');
    const list = node('div', ''); list.className = 'container-list';
    const count = node('p', ''); count.className = 'container-count'; count.setAttribute?.('role', 'status');
    const more = node('button', 'Mostrar 20 más'); more.type = 'button'; more.className = 'container-more';
    const workspace = node('div', ''); workspace.className = 'collection-workspace';
    const listPane = node('section', ''); listPane.className = 'collection-list-pane'; listPane.setAttribute('aria-label', 'Lista de contenedores');
    const mapPane = node('section', ''); mapPane.className = 'collection-map-pane'; mapPane.setAttribute('aria-label', 'Mapa del recorrido');
    const switcher = node('nav', ''); switcher.className = 'collection-view-switch'; switcher.setAttribute('aria-label', 'Vista de contenedores');
    const viewButtons = [];
    const snapshot = version;
    const current = () => version === snapshot && shownTrip === trip.id_recorrido;
    const cards = new Map();
    const filtered = () => trip.contenedores.filter(c => containerFilter === 'todos' || (containerFilter === 'atendidos' ? !!c.fecha_atencion : !c.fecha_atencion));
    const attendAction = (c, label = 'Atendido') => current() && writable && trip.estado === 'En Proceso' && !c.fecha_atencion
      ? actionButton(label, { accion: 'atender', id_recorrido: trip.id_recorrido, id_contenedor: c.id_contenedor }) : null;
    function locate() {
      const card = cards.get(selectedContainer);
      if (card && !listPane.hidden) { card.scrollIntoView?.({ block: 'nearest' }); card.focus?.({ preventScroll: true }); }
    }
    function selectContainer(id, center = false) {
      if (saving || !current()) return false;
      selectedContainer = id;
      const index = filtered().findIndex(c => String(c.id_contenedor) === id);
      if (index >= 0) visibleLimit = Math.max(visibleLimit, index + 1);
      renderContainers();
      if (center) { setView('map'); if (routeMap?.focus?.(id) === false) setView('list'); }
      else locate();
    }
    function layout() {
      const compact = compactViewport?.matches === true;
      listPane.hidden = compact && collectionView === 'map';
      mapPane.hidden = compact && collectionView === 'list';
      routeMap?.resize();
    }
    applyLayout = layout;
    function setView(view) {
      if (!current()) return;
      if (view === 'list') {
        const index = filtered().findIndex(c => String(c.id_contenedor) === selectedContainer);
        if (index >= visibleLimit) { visibleLimit = index + 1; renderContainers(); }
      }
      collectionView = view; workspace.setAttribute('data-view', view);
      for (const [key, b] of viewButtons) b.setAttribute('aria-pressed', String(key === view));
      layout();
      if (view === 'list') locate();
    }
    for (const [key, label] of [['list', 'Lista'], ['map', 'Mapa']]) {
      const b = node('button', label); b.type = 'button'; b.addEventListener('click', () => setView(key)); viewButtons.push([key, b]); switcher.append(b);
    }
    listPane.append(count, list, more); workspace.append(listPane, mapPane);
    const filters = [];
    function renderContainers() {
      const rows = filtered();
      list.replaceChildren(); cards.clear();
      for (const c of rows.slice(0, visibleLimit)) {
        const point = node('article', ''); point.className = `container-row${String(c.id_contenedor) === selectedContainer ? ' selected' : ''}`; point.setAttribute('tabindex', '-1'); cards.set(String(c.id_contenedor), point);
        const info = node('div', ''); info.className = 'container-info';
        const raw = typeof c.direccion === 'string' ? c.direccion.trim() : '';
        const technical = /ubicaci[oó]n importada|\b[XY]:\s*[-\d]|\b(?:latitud|longitud)\s*[:=]|^[-+\d.,\s]+$/i.test(raw);
        const hasLocation = c.latitud != null && c.longitud != null && String(c.latitud).trim() && String(c.longitud).trim()
          && Number.isFinite(Number(c.latitud)) && Number.isFinite(Number(c.longitud)) && Math.abs(Number(c.latitud)) <= 90 && Math.abs(Number(c.longitud)) <= 180;
        info.append(node('h3', c.codigo), node('p', raw && !technical ? raw : hasLocation ? 'Ubicación disponible en mapa' : 'Ubicación no disponible'));
        const state = node('span', c.fecha_atencion ? 'Atendido' : 'Pendiente'); state.className = c.fecha_atencion ? 'container-state attended' : 'container-state pending';
        const actions = node('div', ''); actions.className = 'container-actions'; mapLink(c, actions, selectContainer);
        const attend = attendAction(c); if (attend) actions.append(attend);
        point.append(info, state, actions); list.append(point);
      }
      count.textContent = rows.length ? `Mostrando ${Math.min(visibleLimit, rows.length)} de ${rows.length} contenedores. Se conserva el orden del recorrido.` : 'No hay contenedores en este filtro.';
      more.hidden = rows.length <= visibleLimit;
      for (const [key, button] of filters) button.setAttribute?.('aria-pressed', String(key === containerFilter));
      try {
        if (!routeMap && window.CollectionRouteMap) routeMap = window.CollectionRouteMap.create();
        if (routeMap) {
          const available = routeMap.render(mapPane, trip.id_recorrido, trip.contenedores, containerFilter, {
            selected: selectedContainer, onSelect: id => selectContainer(id), action: c => attendAction(c, 'Marcar como atendido'),
            onUnavailable: () => { if (current()) setView('list'); }
          });
          if (available === false) setView('list');
        } else { mapPane.textContent = 'Mapa no disponible. Podés continuar trabajando desde la lista.'; setView('list'); }
      } catch { mapPane.textContent = 'Mapa no disponible. Podés continuar con la lista.'; setView('list'); }
    }
    for (const [key, label] of [['pendientes', 'Pendientes'], ['atendidos', 'Atendidos'], ['todos', 'Todos']]) {
      const filter = node('button', label); filter.type = 'button';
      filter.addEventListener('click', () => { if (saving) return; containerFilter = key; visibleLimit = 20; renderContainers(); });
      filters.push([key, filter]); toolbar.append(filter);
    }
    more.addEventListener('click', () => { if (saving) return; visibleLimit += 20; renderContainers(); });
    el('collectionItems').append(toolbar, switcher, workspace); setView(collectionView); renderContainers();
    el('collectionStatus').textContent = '';
  }
  async function save(body, confirmation) {
    if (saving || (confirmation && !window.confirm(confirmation))) return;
    saving = true;
    const operationVersion = version;
    const buttons = [...document.querySelectorAll('.collection-shell button')].map(button => ({ button, disabled: button.disabled })); buttons.forEach(({ button }) => { button.disabled = true; });
    el('collectionStatus').textContent = 'Guardando…';
    try {
      const response = await fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const json = await response.json();
      if (operationVersion !== version) return;
      if (response.status === 401) { sessionExpired(); return; }
      if (!response.ok || !json.success) throw new Error(responseError(response.status, json));
      saving = false;
      await load(body.id_recorrido && !query.view ? { id_recorrido: body.id_recorrido } : query);
      el('collectionStatus').textContent = 'Operación confirmada por el servidor.';
    } catch (error) { el('collectionStatus').textContent = error.message; }
    finally { saving = false; buttons.forEach(({ button, disabled }) => { button.disabled = disabled; }); }
  }
  async function load(next = {}) {
    if (saving) return;
    query = { ...next }; request?.abort(); const current = ++version; request = new AbortController();
    el('collectionPanel').hidden = true; el('collectionTimezone').hidden = true; el('collectionItems').replaceChildren();
    el('collectionLogin').hidden = true;
    el('collectionStatus').textContent = 'Consultando…';

    try {
      const response = await fetch(apiUrl(query), { signal: request.signal, cache: 'no-store' });
      const json = await response.json();
      if (current !== version) return;
      if (response.status === 401) { sessionExpired(); return; }
      if (response.status === 409 && ['sin_pertenencia', 'sin_recorrido'].includes(json.code)) {
        clearMap();
        if (json.code === 'sin_recorrido') operational(json.data, false);
        el('collectionStatus').textContent = json.code === 'sin_pertenencia' ? 'No tenés una cuadrilla asignada actualmente. Contactá a un administrador.' : '';
        return;
      }
      if (!response.ok || !json.success) throw new Error(responseError(response.status, json));
      canOperate = json.puede_operar === true;
      operational(json.data, canOperate);
    } catch (error) {
      if (current !== version || error.name === 'AbortError') return;
      clearMap();
      el('collectionPanel').hidden = true;
      el('collectionStatus').textContent = error.message || 'No se pudo consultar recolección.';
    }
  }
  el('collectionRetry').addEventListener('click', () => load(query));
  window.addEventListener('pagehide', () => { version++; request?.abort(); clearMap(); });
  window.addEventListener('zemyna:session-expired', () => {
    version++; request?.abort(); el('collectionPanel').hidden = true;
    clearMap();
    el('collectionItems').replaceChildren(); el('collectionVehicles').textContent = '';
  });
  load();
})();
