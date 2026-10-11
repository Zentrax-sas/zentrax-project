(() => {
  const el = id => document.getElementById(id);
  const root = el('solicitudes');
  if (!root) return;
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  const url = params => buildApiUrl(`/backend/api/solicitudes_propias.php${params ? `?${new URLSearchParams(params)}` : ''}`, { cacheBust: false });
  const status = message => { el('ownRequestStatus').textContent = message; };
  let page = 1, selected = null, csrf = null, saving = false, denied = false, stopped = false;
  let listVersion = 0, detailVersion = 0, operationVersion = 0, listRequest, detailRequest;

  function closeDetail() {
    selected = null; detailVersion++; detailRequest?.abort();
    el('ownRequestDetail').replaceChildren(); el('ownRequestDetail').hidden = true;
  }
  function clear() {
    listVersion++; listRequest?.abort(); closeDetail(); operationVersion++;
    csrf = null; saving = false; el('ownRequestList').replaceChildren(); el('ownRequestPage').textContent = '';
    el('ownRequestPrevious').disabled = el('ownRequestNext').disabled = true;
    el('ownRequestRefresh').disabled = stopped;
  }
  function expired() {
    if (stopped) return;
    stopped = true; clear(); status('Tu sesión venció. Iniciá sesión nuevamente.');
    window.dispatchEvent(new Event('zemyna:session-expired'));
    window.location.replace(buildFrontendUrl('login.html'));
  }
  function forbidden() {
    denied = true; clear(); status('No disponés de autorización para operar solicitudes especiales. Actualizá para volver a consultar.');
  }
  async function read(params, signal) {
    const response = await fetch(url(params), { cache: 'no-store', credentials: 'same-origin', signal });
    const json = await response.json();
    if (!response.ok || !json.success) throw Object.assign(new Error(json.message || 'No se pudo consultar solicitudes.'), { status: response.status });
    return json;
  }
  function button(parent, label, handler) {
    const b = node('button', label); b.type = 'button'; b.disabled = saving;
    b.addEventListener('click', handler); parent.append(b); return b;
  }
  function summary(row, parent) {
    parent.append(node('h3', row.tracking_number), node('p', `Fecha: ${row.fecha}`),
      node('p', `Servicio: ${row.tipo_solicitud} · Residuo: ${row.tipo_residuo || 'Sin confirmación disponible'}`),
      node('p', `Estado: ${row.estado} · Intento: ${row.atencion?.estado || 'No disponible'}`),
      node('p', `Dirección: ${row.direccion || 'No disponible'}`));
    if (row.motivo_bloqueo) {
      const warning = node('p', `Recurso bloqueado: ${row.motivo_bloqueo}`); warning.className = 'request-blocked'; parent.append(warning);
    }
  }
  function actions(row, parent) {
    if (denied || !csrf || !row.atencion) return;
    const capabilities = row.capacidades || {};
    if (capabilities.aceptar_solicitud === true) button(parent, 'Aceptar', () => operate(row, 'aceptar_solicitud'));
    if (capabilities.rechazar_solicitud === true) button(parent, 'Rechazar', () => {
      if (saving || parent.querySelector('form')) return;
      const form = node('form'), label = node('label', 'Motivo del rechazo (1–500 caracteres)'), input = node('textarea');
      input.required = true; input.maxLength = 1000; input.rows = 3; label.append(input); form.append(label);
      const submit = node('button', 'Confirmar rechazo'); submit.type = 'submit'; form.append(submit);
      button(form, 'Cancelar rechazo', () => form.remove());
      form.addEventListener('submit', event => {
        event.preventDefault(); const reason = input.value.trim();
        if (!reason || [...reason].length > 500) { status('Indicá un motivo de 1–500 caracteres.'); input.focus(); return; }
        return operate(row, 'rechazar_solicitud', reason);
      });
      parent.append(form); input.focus();
    });
    if (capabilities.iniciar_atencion_solicitud === true) button(parent, 'Iniciar atención', () => operate(row, 'iniciar_atencion_solicitud'));
    if (capabilities.finalizar_atencion_solicitud === true) button(parent, 'Finalizar atención', () => operate(row, 'finalizar_atencion_solicitud'));
  }
  async function detail(id, recoverConflict = true) {
    if (saving || stopped || denied) return false;
    detailRequest?.abort(); detailRequest = new AbortController(); const version = ++detailVersion;
    selected = id; const parent = el('ownRequestDetail'); parent.hidden = false; parent.replaceChildren(node('p', 'Consultando detalle…'));
    try {
      const json = await read({ id_solicitud: id }, detailRequest.signal);
      if (version !== detailVersion || stopped || denied) return false;
      csrf = json.csrf_token; const row = json.data.items[0];
      parent.replaceChildren(); summary(row, parent); parent.append(node('p', row.descripcion || 'Sin descripción'), node('p', `Cuadrilla: ${row.id_cuadrilla}`));
      const v = row.vehiculo;
      parent.append(node('p', v ? `Vehículo registrado: ${v.matricula} · ID ${v.id_vehiculo} · ${v.funcion_operativa} · V19 ${v.id_asignacion_vehiculo}` : `Vehículo no disponible para operar · V19 registrada: ${row.atencion.id_asignacion_vehiculo}`));
      actions(row, parent); button(parent, 'Cerrar detalle', closeDetail); parent.focus(); return true;
    } catch (error) {
      if (version !== detailVersion || error.name === 'AbortError' || stopped) return false;
      if (error.status === 401) expired();
      else if (error.status === 403) forbidden();
      else if (error.status === 404) { closeDetail(); await load(page); if (!stopped && !denied) status('La solicitud ya no está disponible para tu cuadrilla.'); }
      else if (error.status === 409 && recoverConflict) {
        closeDetail(); const refreshed = await load(page);
        const recovered = refreshed ? await detail(id, false) : false;
        if (!stopped && !denied) status(recovered ? 'La situación cambió. Revisá el trabajo actualizado.' : 'La situación cambió. Volvé a consultar antes de continuar.');
        return recovered;
      }
      else { closeDetail(); status(error.message || 'No se pudo consultar el detalle.'); }
      return false;
    }
  }
  async function load(next = page, recoverEmpty = true) {
    if (saving || stopped) return false;
    listRequest?.abort(); listRequest = new AbortController(); const version = ++listVersion;
    closeDetail(); csrf = null; el('ownRequestList').replaceChildren();
    el('ownRequestPrevious').disabled = el('ownRequestNext').disabled = el('ownRequestRefresh').disabled = true;
    status('Consultando solicitudes…');
    try {
      const json = await read({ page: next, limit: 20 }, listRequest.signal);
      if (version !== listVersion || stopped) return false;
      denied = false; csrf = json.csrf_token; page = json.data.page;
      if (recoverEmpty && page > 1 && !json.data.items.length) return await load(page - 1, false);
      for (const row of json.data.items) {
        const card = node('article'); summary(row, card);
        button(card, 'Ver detalle', () => detail(row.id_solicitud)); actions(row, card); el('ownRequestList').append(card);
      }
      el('ownRequestPage').textContent = `Página ${page}`;
      el('ownRequestPrevious').disabled = page <= 1; el('ownRequestNext').disabled = !json.data.has_more;
      status(json.data.items.length ? '' : 'No tenés solicitudes especiales activas asignadas.'); return true;
    } catch (error) {
      if (version !== listVersion || error.name === 'AbortError' || stopped) return false;
      if (error.status === 401) expired();
      else if (error.status === 403) forbidden();
      else { clear(); status(error.message || 'No se pudo consultar. Actualizá para volver a intentar.'); }
      return false;
    } finally { if (!stopped && version === listVersion) el('ownRequestRefresh').disabled = false; }
  }
  async function operate(row, action, reason) {
    if (saving || stopped || denied || !csrf || row.capacidades?.[action] !== true) return;
    const messages = { aceptar_solicitud: '¿Aceptar esta solicitud?', rechazar_solicitud: '¿Rechazar esta solicitud? Volverá a Pendiente sin cuadrilla asignada.', iniciar_atencion_solicitud: '¿Iniciar la atención de esta solicitud?', finalizar_atencion_solicitud: '¿Finalizar la atención de esta solicitud?' };
    if (!window.confirm(messages[action])) return;
    saving = true; const version = ++operationVersion;
    listVersion++; detailVersion++; listRequest?.abort(); detailRequest?.abort();
    const controls = [...root.querySelectorAll('button, textarea')].map(control => [control, control.disabled]);
    controls.forEach(([control]) => { control.disabled = true; }); status('Guardando…');
    let restore = false;
    const body = { accion: action, id_solicitud: row.id_solicitud, id_atencion_solicitud: row.atencion.id_atencion_solicitud,
      estado_esperado: row.estado_esperado, estado_operativo_esperado: row.estado_operativo_esperado, version_esperada: row.version_esperada };
    if (action === 'rechazar_solicitud') body.motivo = reason;
    try {
      const response = await fetch(url(), { method: 'PUT', cache: 'no-store', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) });
      const json = await response.json(); if (version !== operationVersion || stopped) return;
      if (!response.ok || !json.success) throw Object.assign(new Error(json.message || 'No se pudo guardar.'), { status: response.status });
      const id = selected; saving = false; let refreshed = await load(page);
      if (refreshed && id && !['rechazar_solicitud', 'finalizar_atencion_solicitud'].includes(action)) refreshed = await detail(id);
      if (!stopped && !denied) status(refreshed ? 'Operación confirmada por el servidor.' : 'Operación confirmada, pero no se pudo actualizar. Actualizá la información.');
    } catch (error) {
      if (version !== operationVersion || stopped) return;
      saving = false;
      if (error.status === 401) expired();
      else if (error.status === 403) forbidden();
      else if ([404, 409].includes(error.status)) {
        closeDetail(); const refreshed = await load(page);
        const detailRefreshed = error.status === 409 && refreshed ? await detail(row.id_solicitud) : false;
        if (!stopped && !denied) status(error.status === 404 ? 'La solicitud ya no está disponible para tu cuadrilla.' : refreshed && detailRefreshed ? 'La situación cambió. Revisá la información actualizada antes de continuar.' : 'La situación cambió. No se pudo recuperar el trabajo; actualizá antes de continuar.');
      } else {
        restore = error.status === 400;
        if (!restore) clear();
        status(restore ? error.message : 'No se pudo confirmar la operación. Actualizá antes de volver a operar.');
      }
    } finally { if (version === operationVersion && !stopped) { saving = false; if (restore) controls.forEach(([control, disabled]) => { control.disabled = disabled; }); } }
  }
  el('ownRequestPrevious').addEventListener('click', () => load(page - 1));
  el('ownRequestNext').addEventListener('click', () => load(page + 1));
  el('ownRequestRefresh').addEventListener('click', () => load(page));
  window.addEventListener('zemyna:session-expired', () => { stopped = true; clear(); status('Tu sesión venció. Iniciá sesión nuevamente.'); });
  window.addEventListener('pagehide', () => { stopped = true; clear(); });
  const focusSection = () => { if (window.location.hash === '#solicitudes') root.focus(); };
  window.addEventListener('hashchange', focusSection); focusSection();
  load();
})();
