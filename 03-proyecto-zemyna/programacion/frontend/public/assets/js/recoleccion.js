(() => {
  const el = id => document.getElementById(id);
  // La API valida parámetros funcionales: la caché se controla con fetch/no-store, no con t.
  const apiUrl = params => {
    const search = new URLSearchParams(params || {}).toString();
    return buildApiUrl(`/backend/api/recoleccion.php${search ? `?${search}` : ''}`, { cacheBust: false });
  };
  function sessionExpired() {
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
  const node = (tag, value) => { const n = document.createElement(tag); n.textContent = value; return n; };
  const vehicles = rows => rows.length ? rows.map(v => `${v.matricula} (${v.estado}; ${Number(v.activo) === 1 ? 'activo' : 'baja lógica'})`).join(', ') : 'Sin vehículos relacionados.';
  const actionButton = (label, body, confirmation) => {
    const button = node('button', label); button.type = 'button';
    button.addEventListener('click', () => save(body, confirmation)); return button;
  };
  function mapLink(row, parent) {
    const lat = Number(row.latitud), lon = Number(row.longitud);
    if (row.latitud != null && row.longitud != null && String(row.latitud).trim() && String(row.longitud).trim() && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
      const a = node('a', 'Ver en mapa'); a.href = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=18/${lat}/${lon}`; a.target = '_blank'; a.rel = 'noopener noreferrer'; parent.append(a);
    }
  }
  function operational(data, writable) {
    el('collectionPanel').hidden = false;
    el('collectionHeading').textContent = data.pertenencia ? `${data.pertenencia.nombre} · ${data.pertenencia.turno}` : 'Detalle del recorrido';
    el('collectionVehicles').textContent = '';
    const trip = data.recorrido;
    el('collectionTimezone').hidden = !trip?.fecha_inicio && !trip?.fecha_fin;
    if (!trip) {
      const card = node('article', ''); card.className = 'collection-empty';
      card.append(node('h3', 'Sin recorrido asignado'), node('p', 'Tu cuadrilla todavía no tiene un recorrido disponible. Podés actualizar la información más tarde o reportar un problema detectado durante tu jornada.'));
      el('collectionItems').append(card); el('collectionStatus').textContent = ''; return;
    }
    const name = id => { const author = trip.autores.find(u => Number(u.id_usuario) === Number(id)); return author ? `${author.nombre} ${author.apellido}` : 'Histórico sin autor registrado'; };
    const card = node('article', '');
    card.append(node('h3', `${trip.ruta_nombre} · ${trip.estado}`), node('p', `Inicio${trip.estado === 'Pendiente' ? ' previsto' : ''}: ${trip.fecha_inicio} · ${name(trip.id_usuario_inicio)}`), node('p', `Fin: ${trip.fecha_fin || 'Sin finalizar'} · ${trip.fecha_fin ? name(trip.id_usuario_fin) : '—'}`), node('p', `Vehículos: ${vehicles(trip.vehiculos)}`));
    const progress = node('p', `${trip.progreso.atendidos} de ${trip.progreso.total} contenedores atendidos · ${trip.progreso.pendientes} pendientes`); card.append(progress);
    if (writable && trip.estado === 'Pendiente') card.append(actionButton('Iniciar recorrido', { accion: 'iniciar', id_recorrido: trip.id_recorrido }, '¿Iniciar este recorrido? Se registrará tu usuario y la hora actual.'));
    if (writable && trip.estado === 'En Proceso') card.append(actionButton('Finalizar recorrido', { accion: 'finalizar', id_recorrido: trip.id_recorrido }, `¿Finalizar el recorrido con ${trip.progreso.pendientes} contenedores pendientes? Se conservará el historial.`));
    el('collectionItems').append(card);
    for (const c of trip.contenedores) {
      const point = node('article', ''); point.append(node('h3', c.codigo), node('p', c.direccion || 'Sin dirección'), node('p', c.fecha_atencion ? `Atendido: ${c.fecha_atencion} · ${c.autor_nombre} ${c.autor_apellido}` : 'Pendiente'));
      mapLink(c, point);
      if (writable && trip.estado === 'En Proceso' && !c.fecha_atencion) point.append(actionButton('Marcar como atendido', { accion: 'atender', id_recorrido: trip.id_recorrido, id_contenedor: c.id_contenedor }));
      el('collectionItems').append(point);
    }
    el('collectionStatus').textContent = '';
  }
  async function save(body, confirmation) {
    if (saving || (confirmation && !window.confirm(confirmation))) return;
    saving = true;
    const buttons = [...document.querySelectorAll('.collection-shell button')].map(button => ({ button, disabled: button.disabled })); buttons.forEach(({ button }) => { button.disabled = true; });
    el('collectionStatus').textContent = 'Guardando…';
    try {
      const response = await fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const json = await response.json();
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
        if (json.code === 'sin_recorrido') operational(json.data, false);
        el('collectionStatus').textContent = json.code === 'sin_pertenencia' ? 'No tenés una cuadrilla asignada actualmente. Contactá a un administrador.' : '';
        return;
      }
      if (!response.ok || !json.success) throw new Error(responseError(response.status, json));
      canOperate = json.puede_operar === true;
      operational(json.data, canOperate);
    } catch (error) {
      if (current !== version || error.name === 'AbortError') return;
      el('collectionPanel').hidden = true;
      el('collectionStatus').textContent = error.message || 'No se pudo consultar recolección.';
    }
  }
  el('collectionRetry').addEventListener('click', () => load(query));
  window.addEventListener('pagehide', () => { version++; request?.abort(); });
  load();
})();
