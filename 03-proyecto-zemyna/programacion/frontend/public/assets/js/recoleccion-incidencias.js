(() => {
  const el = id => document.getElementById(id);
  const url = params => buildApiUrl(`/backend/api/recoleccion.php${params ? `?${new URLSearchParams(params)}` : ''}`, { cacheBust: false });
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  let page = 1, context = null, selected = null, saving = false, denied = false, stopped = false;
  let listVersion = 0, detailVersion = 0, listRequest, detailRequest;
  const status = message => { el('ownIncidentStatus').textContent = message; };
  function closeDetail() {
    selected = null; detailVersion++; detailRequest?.abort();
    el('ownIncidentDetail').replaceChildren(); el('ownIncidentDetail').hidden = true;
  }
  function clear() {
    listVersion++; listRequest?.abort(); closeDetail(); context = null;
    el('ownIncidentList').replaceChildren(); el('ownIncidentContext').textContent = '';
    el('ownIncidentPrevious').disabled = el('ownIncidentNext').disabled = true;
    el('ownIncidentRefresh').disabled = stopped;
  }
  function expired() {
    stopped = true; clear(); status('Tu sesión venció. Iniciá sesión nuevamente.');
    window.dispatchEvent(new Event('zemyna:session-expired'));
    window.location.replace(buildFrontendUrl('login.html'));
  }
  function lock() {
    for (const button of el('ownIncidents').querySelectorAll('button')) button.disabled = true;
  }
  async function read(params, signal) {
    const response = await fetch(url(params), { cache: 'no-store', signal });
    const json = await response.json();
    if (!response.ok || !json.success) throw Object.assign(new Error(json.message || 'No se pudo consultar incidencias.'), { status: response.status, code: json.code });
    return json;
  }
  function showContext(c) {
    const v = c?.vehiculo;
    el('ownIncidentContext').textContent = `${c?.cuadrilla?.nombre || 'Cuadrilla no disponible'} · ${c?.cuadrilla?.turno || 'Turno no disponible'} · ${v ? `Vehículo V19: ${v.matricula} (${v.estado})` : c?.estado_v19 === 'ambiguo' ? 'Asignación V19 ambigua' : 'Sin vehículo V19 disponible'} · ${c?.funcion_operativa || 'Función no determinada'}`;
  }
  function location(row, parent) {
    if (row.contenedor_codigo) parent.append(node('p', `Contenedor: ${row.contenedor_codigo}`));
    if (row.contenedor_direccion) parent.append(node('p', row.contenedor_direccion));
    const lat = Number(row.latitud), lon = Number(row.longitud);
    if (row.latitud != null && row.longitud != null && String(row.latitud).trim() && String(row.longitud).trim() && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) {
      parent.append(node('p', `Ubicación: ${lat}, ${lon}`));
      const a = node('a', 'Ver ubicación en mapa'); a.href = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=18/${lat}/${lon}`;
      a.target = '_blank'; a.rel = 'noopener noreferrer'; parent.append(a);
    }
  }
  function button(parent, label, handler) {
    const b = node('button', label); b.type = 'button'; b.disabled = saving;
    b.addEventListener('click', handler); parent.append(b); return b;
  }
  function summary(row, parent) {
    parent.append(node('h3', row.tracking_number), node('p', `Estado: ${row.estado} · Atención: ${row.atencion?.estado || 'Sin intento operativo'}`), node('p', `Prioridad: ${row.prioridad} · Tipo: ${row.tipo_problema}`));
    location(row, parent);
  }
  function actions(row, parent) {
    if (denied || context?.puede_operar_incidencias !== true || !row.atencion || row.estado === 'Resuelta') return;
    const state = row.atencion.estado;
    if (state === 'Asignada') {
      button(parent, 'Aceptar', () => operate(row, 'aceptar_incidencia'));
      button(parent, 'Rechazar', () => {
        if (parent.querySelector('form')) return;
        const form = node('form'), label = node('label', 'Motivo del rechazo (1–500 caracteres)'), input = node('textarea');
        input.required = true; input.maxLength = 1000; input.rows = 3; label.append(input); form.append(label);
        const submit = node('button', 'Confirmar rechazo'); submit.type = 'submit'; form.append(submit);
        form.addEventListener('submit', event => {
          event.preventDefault(); const reason = input.value.trim();
          if (!reason || [...reason].length > 500) { status('Indicá un motivo de 1–500 caracteres.'); input.focus(); return; }
          operate(row, 'rechazar_incidencia', reason);
        });
        parent.append(form); input.focus();
      });
    } else if (state === 'Aceptada') button(parent, 'Iniciar atención', () => operate(row, 'iniciar_atencion_incidencia'));
    else if (state === 'En atención' && row.estado === 'En Proceso') button(parent, 'Finalizar atención', () => operate(row, 'finalizar_atencion_incidencia'));
  }
  async function detail(id) {
    if (saving || stopped) return false;
    detailRequest?.abort(); detailRequest = new AbortController(); const version = ++detailVersion;
    selected = id; el('ownIncidentDetail').hidden = false; el('ownIncidentDetail').replaceChildren(node('p', 'Consultando detalle…'));
    try {
      const json = await read({ view: 'incidencias_propias', id_incidencia: id }, detailRequest.signal);
      if (version !== detailVersion || stopped) return false;
      context = json.contexto_operativo; showContext(context);
      const parent = el('ownIncidentDetail'), row = json.data[0]; parent.replaceChildren();
      summary(row, parent); parent.append(node('p', row.descripcion), node('p', `Reportada: ${row.fecha_reporte}`));
      if (row.ruta_nombre) parent.append(node('p', `Ruta: ${row.ruta_nombre}`));
      actions(row, parent); button(parent, 'Cerrar detalle', closeDetail); parent.focus(); return true;
    } catch (error) {
      if (version !== detailVersion || error.name === 'AbortError') return false;
      if (error.status === 401) expired();
      else if (error.status === 404) { closeDetail(); await load(1); status('La incidencia ya no está disponible para tu cuadrilla.'); }
      else { if (error.status === 403) { denied = true; clear(); } status(error.message); }
      return false;
    }
  }
  async function load(next = 1) {
    if (saving || stopped) return false;
    listRequest?.abort(); listRequest = new AbortController(); const version = ++listVersion;
    closeDetail(); context = null; el('ownIncidentList').replaceChildren(); lock(); status('Consultando incidencias…');
    try {
      const json = await read({ view: 'incidencias_propias', page: next, limit: 20 }, listRequest.signal);
      if (version !== listVersion || stopped) return false;
      page = json.meta.page; context = json.contexto_operativo; showContext(context);
      for (const row of json.data) {
        const card = node('article'); summary(row, card);
        button(card, 'Ver detalle', () => detail(row.id_incidencia)); actions(row, card); el('ownIncidentList').append(card);
      }
      el('ownIncidentPage').textContent = `Página ${page}`;
      el('ownIncidentPrevious').disabled = page <= 1; el('ownIncidentNext').disabled = !json.meta.has_more;
      status(denied ? 'No tenés autorización para operar incidencias.' : json.data.length ? '' : 'No tenés incidencias activas asignadas.'); return true;
    } catch (error) {
      if (version !== listVersion || error.name === 'AbortError') return false;
      if (error.status === 401) expired();
      else { if (error.status === 403) denied = true; clear(); status(error.message || 'No se pudo consultar. Actualizá para volver a intentar.'); }
      return false;
    } finally { if (!stopped && version === listVersion) el('ownIncidentRefresh').disabled = false; }
  }
  async function operate(row, action, reason) {
    if (saving || stopped || denied || context?.puede_operar_incidencias !== true) return;
    let restoreButtons = false;
    saving = true; listVersion++; detailVersion++; listRequest?.abort(); detailRequest?.abort();
    const buttons = [...el('ownIncidents').querySelectorAll('button')].map(b => [b, b.disabled]); lock(); status('Guardando…');
    const body = { accion: action, id_incidencia: row.id_incidencia, id_atencion_incidencia: row.atencion.id_atencion_incidencia, estado_operativo_esperado: row.atencion.estado };
    if (reason !== undefined) body.motivo = reason;
    try {
      const response = await fetch(url(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const json = await response.json();
      if (stopped) return;
      if (!response.ok || !json.success) throw Object.assign(new Error(json.message || 'No se pudo guardar.'), { status: response.status, code: json.code });
      const id = selected; saving = false;
      let refreshed = await load(1);
      if (refreshed && id && !['rechazar_incidencia', 'finalizar_atencion_incidencia'].includes(action)) refreshed = await detail(id);
      if (!stopped) status(refreshed ? 'Operación confirmada por el servidor.' : 'Operación confirmada, pero no se pudo actualizar la pantalla. Actualizá la información.');
    } catch (error) {
      saving = false;
      if (stopped) return;
      if (error.status === 401) expired();
      else if (error.status === 403) { denied = true; closeDetail(); await load(1); if (!stopped) status('No tenés autorización para operar incidencias.'); }
      else if ([404, 409].includes(error.status)) {
        const id = selected; closeDetail();
        const refreshed = await load(1);
        if (refreshed && id && error.status === 409 && error.code !== 'sin_pertenencia') await detail(id);
        if (!stopped) status(error.status === 404 ? 'La incidencia ya no está disponible para tu cuadrilla.' : error.code === 'sin_pertenencia' ? 'No tenés pertenencia vigente a una cuadrilla.' : 'La situación cambió. Se consultó nuevamente el servidor; revisá el estado antes de continuar.');
      } else {
        restoreButtons = error.status === 400;
        if (error.status !== 400) { context = null; closeDetail(); el('ownIncidentList').replaceChildren(); el('ownIncidentRefresh').disabled = false; }
        status(error.status === 400 ? error.message : 'No se pudo confirmar la operación. Actualizá antes de volver a operar.');
      }
    } finally {
      saving = false; if (!stopped && restoreButtons) buttons.forEach(([b, disabled]) => { b.disabled = disabled; });
    }
  }
  el('ownIncidentPrevious').addEventListener('click', () => load(page - 1));
  el('ownIncidentNext').addEventListener('click', () => load(page + 1));
  el('ownIncidentRefresh').addEventListener('click', () => { denied = false; load(1); });
  window.addEventListener('zemyna:session-expired', () => { stopped = true; clear(); });
  window.addEventListener('pagehide', () => { stopped = true; clear(); });
  load();
})();
