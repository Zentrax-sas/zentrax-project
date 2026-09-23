(() => {
  const el = id => document.getElementById(id);
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  const button = (text, fn) => { const b = node('button', text); b.type = 'button'; b.addEventListener('click', fn); return b; };
  const url = params => buildApiUrl(`/backend/api/recoleccion.php?${new URLSearchParams(params)}`, { cacheBust: false });
  let permissions = {}, selected, members, page = 1, tripPage = 1, active = false, version = 0, request, saving = false, action, focusReturn, tab = 'summary';
  function fail(response, json) {
    if (response.status === 401) window.location.replace(buildFrontendUrl('login.html'));
    if (!response.ok || !json.success) throw new Error(response.status === 403 ? 'No tenés permiso para esta consulta.' : json.message || 'No se pudo completar la solicitud.');
    return json;
  }
  async function get(params, signal) {
    const r = await fetch(url(params), { cache: 'no-store', signal }); return fail(r, await r.json());
  }
  const ready = (async () => {
    if (!await adminSessionReady) return false;
    try { permissions = (await get({ view: 'permisos' })).data; el('squadNav').hidden = !permissions.administracion; return permissions.administracion; }
    catch { el('squadNav').hidden = true; return false; }
  })();
  function table(headers, rows) {
    const wrapper = node('div'); wrapper.className = 'card data-panel';
    const t = node('table'), head = node('thead'), tr = node('tr'), body = node('tbody');
    headers.forEach(h => tr.append(node('th', h))); head.append(tr); t.append(head, body);
    for (const values of rows) { const row = node('tr'); values.forEach((v, i) => { const cell = node('td'); cell.dataset.label = headers[i]; if (typeof v === 'object') cell.append(v); else cell.textContent = v; row.append(cell); }); body.append(row); }
    wrapper.append(t); return wrapper;
  }
  const vehicles = rows => rows?.length ? rows.map(v => `${v.matricula}${Number(v.activo) === 0 ? ' · Baja lógica' : ''}`).join(', ') : 'Sin vehículo relacionado';
  const progress = t => t ? `${t.progreso.atendidos} de ${t.progreso.total} atendidos` : 'Sin recorrido';
  const author = (trip, id) => { const u = trip.autores?.find(a => Number(a.id_usuario) === Number(id)); return u ? `${u.nombre} ${u.apellido}` : 'Sin autor registrado'; };
  function list(data) {
    el('squadList').hidden = false; el('squadDetail').hidden = true; el('squadPaging').hidden = false;
    el('squadList').replaceChildren(table(['Cuadrilla', 'Integrantes activos', 'Vehículo', 'Recorrido actual', 'Estado', 'Acción'], data.lista.items.map(c => [c.nombre, String(c.integrantes_activos), vehicles(c.vehiculos || c.recorrido_actual?.vehiculos), c.recorrido_actual?.ruta_nombre || 'Sin recorrido', c.recorrido_actual?.estado || 'Sin recorrido', button('Ver detalle', () => select(c))])));
    el('squadPage').textContent = `Página ${page} · ${data.lista.total} cuadrillas`; el('squadPrev').disabled = page <= 1; el('squadNext').disabled = page * data.lista.page_size >= data.lista.total;
    el('squadStatus').textContent = data.lista.items.length ? '' : 'No hay cuadrillas registradas.';
  }
  function summary(data) {
    selected = data.cuadrilla; const trip = data.recorrido_actual;
    const card = node('article'); card.className = 'card squad-summary';
    card.append(node('h3', trip?.estado || 'Sin recorrido disponible'), node('p', `${data.integrantes_activos} integrantes activos · Turno ${selected.turno}`), node('p', `Vehículos: ${vehicles(data.vehiculos)}`));
    if (trip) card.append(node('h3', trip.ruta_nombre), node('p', progress(trip)), node('p', `Inicio: ${trip.fecha_inicio} · Fin: ${trip.fecha_fin || 'Sin finalizar'}`));
    el('squadSummary').replaceChildren(card);
    el('squadName').textContent = selected.nombre;
    el('squadMembersTab').hidden = !permissions.integrantes;
    el('squadAssignTrip').hidden = !permissions.asignar_recorridos;
    el('squadDetail').hidden = false; el('squadList').hidden = true; el('squadPaging').hidden = true;
    trips(data);
  }
  function trips(data) {
    el('squadTripRows').replaceChildren(table(['Ruta', 'Estado', 'Inicio', 'Finalización', 'Inició', 'Finalizó', 'Progreso', 'Acción'], data.lista.items.map(t => [t.ruta_nombre, t.estado, t.fecha_inicio, t.fecha_fin || 'Sin finalizar', author(t, t.id_usuario_inicio), t.fecha_fin ? author(t, t.id_usuario_fin) : '—', progress(t), button('Ver detalle', () => tripDetail(t.id_recorrido))])));
    if (!data.lista.items.length) el('squadTripRows').append(node('p', 'No hay recorridos para este filtro.'));
    el('squadTripPage').textContent = `Página ${tripPage} · ${data.lista.total} recorridos`;
    el('squadTripPrev').disabled = tripPage <= 1; el('squadTripNext').disabled = tripPage * data.lista.page_size >= data.lista.total;
  }
  async function load(params, render) {
    request?.abort(); request = new AbortController(); const current = ++version;
    el('squadStatus').textContent = 'Cargando…';
    try { const result = await get(params, request.signal); if (current !== version || !active) return; el('squadStatus').textContent = ''; render(result.data, result); }
    catch (error) { if (current === version && error.name !== 'AbortError') { el('squadStatus').textContent = error.message; el('squadList').hidden = true; el('squadDetail').hidden = true; el('squadPaging').hidden = true; } }
  }
  function select(squad) {
    if (saving) return; selected = squad; tripPage = 1; el('squadState').value = ''; el('squadTripDetail').replaceChildren(); setTab('summary');
    return load({ view: 'administracion', id_cuadrilla: squad.id_cuadrilla }, summary);
  }
  function memberRows(rows, current) {
    if (!rows.length) return node('p', current ? 'No hay integrantes activos.' : 'No hay pertenencias anteriores.');
    return table(['Integrante', 'Desde', 'Hasta', 'Asignó', 'Acción'], rows.map(u => [`${u.nombre} ${u.apellido}`, u.fecha_inicio, u.fecha_fin || 'Vigente', u.asignador_nombre ? `${u.asignador_nombre} ${u.asignador_apellido || ''}` : 'Administrador no disponible', current && permissions.modificar_integrantes ? button('Finalizar pertenencia', event => finish(u, event.currentTarget)) : '—']));
  }
  function renderMembers(data, result) {
    members = data; permissions.modificar_integrantes = result.puede_gestionar === true;
    el('squadActiveMembers').replaceChildren(memberRows(data.historial.filter(u => !u.fecha_fin), true));
    el('squadHistory').replaceChildren(memberRows(data.historial.filter(u => u.fecha_fin), false));
    el('squadAdd').hidden = !permissions.modificar_integrantes; el('squadUsers').hidden = !permissions.usuarios;
  }
  function setTab(name) {
    tab = name;
    for (const [key, suffix] of [['summary','Summary'],['members','Members'],['trips','Trips']]) { el(`squad${suffix}`).hidden = key !== name; el(`squad${suffix}Tab`).setAttribute('aria-pressed', String(key === name)); }
    if (name === 'members' && selected && permissions.integrantes) load({ view: 'integrantes', id_cuadrilla: selected.id_cuadrilla }, renderMembers);
  }
  async function tripDetail(id) {
    return load({ view: 'administracion', id_cuadrilla: selected.id_cuadrilla, id_recorrido: id }, data => {
      const t = data.recorrido, card = node('article'); card.className = 'card squad-summary';
      card.append(node('h3', `${t.ruta_nombre} · ${t.estado}`), node('p', progress(t)), node('p', `Inicio: ${t.fecha_inicio} · ${author(t,t.id_usuario_inicio)}`), node('p', `Fin: ${t.fecha_fin || 'Sin finalizar'} · ${author(t,t.id_usuario_fin)}`));
      card.append(table(['Contenedor', 'Atención', 'Autor'], t.contenedores.map(c => [c.codigo, c.fecha_atencion || 'Pendiente', c.fecha_atencion ? `${c.autor_nombre} ${c.autor_apellido}` : '—']))); el('squadTripDetail').replaceChildren(card); card.scrollIntoView({ block: 'nearest' });
    });
  }
  function showDialog(trigger) { focusReturn = trigger; el('squadDialogStatus').textContent = ''; el('squadDialog').showModal(); }
  function assignment() {
    if (!members || !permissions.modificar_integrantes) return;
    el('squadSelectGroup').hidden = false; el('squadEligible').disabled = false; el('squadDialogTitle').textContent = `Agregar operario a ${selected.nombre}`;
    el('squadEligible').replaceChildren();
    for (const u of members.elegibles) { const o = node('option', `${u.nombre} ${u.apellido}`); o.value = u.id_usuario; el('squadEligible').append(o); }
    el('squadExcluded').replaceChildren(...(members.no_elegibles || []).map(u => node('li', `${u.nombre} ${u.apellido} — ${u.motivo}`)));
    if (!members.no_elegibles?.length) el('squadExcluded').append(node('li', 'No hay usuarios excluidos.'));
    el('squadDialogUsers').hidden = !permissions.usuarios;
    el('squadEligible').value = members.elegibles[0]?.id_usuario || ''; chooseUser(); showDialog(el('squadAdd')); el('squadEligible').focus();
  }
  function chooseUser() {
    const u = members.elegibles.find(u => String(u.id_usuario) === el('squadEligible').value);
    const same = u?.pertenencia && Number(u.pertenencia.id_cuadrilla) === Number(selected.id_cuadrilla);
    el('squadConfirm').disabled = !u || Boolean(same);
    el('squadUserState').textContent = u ? `Activo · Permisos operativos vigentes · ${u.pertenencia ? `Cuadrilla actual: ${u.pertenencia.nombre}` : 'Sin cuadrilla actual'}` : 'No hay operarios disponibles para asignar. Revisá que el usuario esté activo y tenga permisos operativos vigentes en el sector Operaciones.';
    el('squadConfirm').textContent = u?.pertenencia ? 'Confirmar traslado' : 'Confirmar asignación';
    el('squadConfirmText').textContent = same ? 'Este usuario ya pertenece a la cuadrilla seleccionada.' : !u ? '' : u.pertenencia ? `${u.nombre} ${u.apellido} pertenece actualmente a ${u.pertenencia.nombre}. ¿Deseás trasladarlo a ${selected.nombre}?` : `Asignar a ${u.nombre} ${u.apellido} a ${selected.nombre}.`;
    action = u ? { accion: u.pertenencia ? 'trasladar' : 'asignar', integrante: u.id_usuario, destino: selected.id_cuadrilla } : null;
    if (u?.pertenencia) action.pertenencia = u.pertenencia.id_usuario_cuadrilla;
  }
  function finish(u, trigger) {
    if (!permissions.modificar_integrantes) return;
    action = { accion: 'finalizar_pertenencia', integrante: u.id_usuario, pertenencia: u.id_usuario_cuadrilla };
    el('squadSelectGroup').hidden = true; el('squadEligible').disabled = true; el('squadDialogTitle').textContent = 'Finalizar pertenencia';
    el('squadConfirmText').textContent = `${u.nombre} ${u.apellido} dejará de pertenecer a ${selected.nombre}. Esta acción conservará el historial.`;
    el('squadConfirm').textContent = 'Finalizar pertenencia'; el('squadConfirm').disabled = false; showDialog(trigger); el('squadCancel').focus();
  }
  el('squadAssignment').addEventListener('submit', async event => {
    event.preventDefault(); if (saving || !action || el('squadConfirm').disabled) return;
    const targetSquad = selected.id_cuadrilla;
    saving = true; el('squadConfirm').disabled = true; el('squadCancel').disabled = true; el('squadEligible').disabled = true; el('squadDialogStatus').textContent = 'Guardando…';
    try { const response = await fetch(url({}), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(action) }); fail(response, await response.json()); el('squadDialog').close(); if (active && selected?.id_cuadrilla === targetSquad) await load({ view: 'integrantes', id_cuadrilla: targetSquad }, renderMembers); }
    catch (error) { el('squadDialogStatus').textContent = error.message; }
    finally { saving = false; el('squadCancel').disabled = false; el('squadEligible').disabled = false; el('squadConfirm').disabled = false; }
  });
  el('squadDialog').addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  el('squadDialog').addEventListener('close', () => focusReturn?.focus());
  el('squadCancel').addEventListener('click', () => { if (!saving) el('squadDialog').close(); });
  el('squadAdd').addEventListener('click', assignment); el('squadEligible').addEventListener('change', chooseUser);
  for (const id of ['squadUsers', 'squadDialogUsers']) el(id).addEventListener('click', event => {
    event.preventDefault(); if (saving) return; el('squadDialog').close(); openView('usuarios'); history.replaceState(null, '', '#usuarios');
  });
  el('squadSummaryTab').addEventListener('click', () => { setTab('summary'); if (selected) load({ view: 'administracion', id_cuadrilla: selected.id_cuadrilla }, summary); });
  el('squadMembersTab').addEventListener('click', () => setTab('members')); el('squadTripsTab').addEventListener('click', () => setTab('trips'));
  const catalog = () => load({ view: 'administracion', page }, list);
  const tripList = () => { const params = { view: 'administracion', id_cuadrilla: selected.id_cuadrilla, page: tripPage }; if (el('squadState').value) params.estado = el('squadState').value; load(params, trips); };
  el('squadBack').addEventListener('click', () => { selected = null; catalog(); });
  el('squadRefresh').addEventListener('click', () => selected ? (tab === 'members' ? setTab('members') : load({ view:'administracion', id_cuadrilla: selected.id_cuadrilla }, summary)) : (permissions.administracion ? catalog() : open()));
  el('squadPrev').addEventListener('click', () => { if (page > 1) { page--; catalog(); } }); el('squadNext').addEventListener('click', () => { page++; catalog(); });
  el('squadTripPrev').addEventListener('click', () => { if (tripPage > 1) { tripPage--; tripList(); } }); el('squadTripNext').addEventListener('click', () => { tripPage++; tripList(); }); el('squadState').addEventListener('change', () => { tripPage = 1; el('squadTripDetail').replaceChildren(); tripList(); });
  let availablePage = 1, available, dialogVersion = 0, dialogRequest;
  function chooseTrip() {
    const trip = available?.items.find(t => String(t.id_recorrido) === el('squadAvailableTrip').value);
    const vehicle = available?.vehiculos.find(v => String(v.id_usa) === el('squadTripVehicle').value);
    el('squadTripPreview').textContent = trip ? `${trip.ruta_nombre} · ${trip.estado} · Inicio previsto: ${trip.fecha_inicio} · Fin: ${trip.fecha_fin || 'Sin finalizar'} · Sin cuadrilla asignada` : '';
    el('squadTripConfirm').disabled = saving || !trip || !vehicle || Boolean(available?.conflicto);
  }
  async function availableTrips() {
    dialogRequest?.abort(); dialogRequest = new AbortController(); const current = ++dialogVersion;
    available = null; el('squadTripConfirm').disabled = true; el('squadAvailableTrip').replaceChildren(); el('squadTripVehicle').replaceChildren(); el('squadTripPreview').textContent = '';
    el('squadTripDialogStatus').textContent = 'Cargando recorridos disponibles…';
    try {
      const result = await get({ view: 'asignables', id_cuadrilla: selected.id_cuadrilla, page: availablePage }, dialogRequest.signal);
      if (current !== dialogVersion || !active || !el('squadTripDialog').open) return;
      available = result.data;
      for (const trip of available.items) { const option = node('option', `${trip.ruta_nombre} · ${trip.fecha_inicio} · ${trip.estado}`); option.value = trip.id_recorrido; el('squadAvailableTrip').append(option); }
      for (const vehicle of available.vehiculos) { const option = node('option', `${vehicle.matricula} · ${vehicle.estado}`); option.value = vehicle.id_usa; el('squadTripVehicle').append(option); }
      el('squadAvailableTrip').value = available.items[0]?.id_recorrido || ''; el('squadTripVehicle').value = available.vehiculos[0]?.id_usa || '';
      el('squadAvailablePage').textContent = `Página ${availablePage} · ${available.total} recorridos`;
      el('squadAvailablePrev').disabled = availablePage <= 1; el('squadAvailableNext').disabled = availablePage * available.page_size >= available.total;
      el('squadTripDialogStatus').textContent = available.conflicto || (!available.vehiculos.length ? 'La cuadrilla no tiene un vehículo relacionado disponible. Revisá la configuración administrativa.' : !available.items.length ? 'No hay recorridos pendientes sin asignación. Podés crear uno con una ruta existente.' : '');
      chooseTrip();
    } catch (error) { if (current === dialogVersion && error.name !== 'AbortError') el('squadTripDialogStatus').textContent = error.message; }
  }
  el('squadAssignTrip').addEventListener('click', () => {
    if (saving || !permissions.asignar_recorridos || !selected) return;
    availablePage = 1; el('squadTripDialogTitle').textContent = `Asignar recorrido a ${selected.nombre}`;
    el('squadCreateTrip').hidden = true; el('squadManageTrips').hidden = !permissions.crear_recorridos;
    el('squadCreateStatus').textContent = ''; el('squadTripDialog').showModal(); availableTrips(); el('squadTripCancel').focus();
  });
  el('squadAvailableTrip').addEventListener('change', chooseTrip); el('squadTripVehicle').addEventListener('change', chooseTrip);
  el('squadAvailablePrev').addEventListener('click', () => { if (!saving && availablePage > 1) { availablePage--; availableTrips(); } });
  el('squadAvailableNext').addEventListener('click', () => { if (!saving && available && availablePage * available.page_size < available.total) { availablePage++; availableTrips(); } });
  const tripControls = ['squadTripConfirm', 'squadTripCancel', 'squadAvailableTrip', 'squadTripVehicle', 'squadAvailablePrev', 'squadAvailableNext', 'squadCreateSubmit', 'squadCreateRoute', 'squadCreateDate'];
  function busyTrip(value) { saving = value; tripControls.forEach(id => { el(id).disabled = value; }); if (!value) { el('squadAvailablePrev').disabled = availablePage <= 1; el('squadAvailableNext').disabled = !available || availablePage * available.page_size >= available.total; chooseTrip(); } }
  el('squadTripAssignment').addEventListener('submit', async event => {
    event.preventDefault(); if (saving || el('squadTripConfirm').disabled || !selected) return;
    const squad = selected.id_cuadrilla;
    const body = { accion: 'asignar_recorrido', destino: squad, id_recorrido: Number(el('squadAvailableTrip').value), id_usa: Number(el('squadTripVehicle').value) };
    busyTrip(true); el('squadTripDialogStatus').textContent = 'Asignando recorrido…';
    try {
      const response = await fetch(url({}), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); fail(response, await response.json());
      el('squadTripDialog').close();
      if (active && selected?.id_cuadrilla === squad) {
        tripPage = 1; el('squadState').value = ''; el('squadTripDetail').replaceChildren(); setTab('trips');
        await load({ view: 'administracion', id_cuadrilla: squad }, data => { summary(data); el('squadStatus').textContent = 'Recorrido asignado. Ya está disponible para los integrantes de la cuadrilla.'; });
      }
    } catch (error) { el('squadTripDialogStatus').textContent = error.message; }
    finally { busyTrip(false); }
  });
  el('squadTripCancel').addEventListener('click', () => { if (!saving) el('squadTripDialog').close(); });
  el('squadTripDialog').addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  el('squadTripDialog').addEventListener('close', () => { dialogVersion++; dialogRequest?.abort(); el('squadAssignTrip').focus(); });
  el('squadManageTrips').addEventListener('click', async event => {
    event.preventDefault(); if (saving || !permissions.crear_recorridos) return;
    el('squadCreateTrip').hidden = false; el('squadCreateRoute').replaceChildren(); el('squadCreateSubmit').disabled = true; el('squadCreateStatus').textContent = 'Cargando rutas…';
    const current = dialogVersion;
    try {
      const response = await fetch(buildApiUrl('/backend/api/rutas.php', { cacheBust: false }), { cache: 'no-store' }); const result = fail(response, await response.json());
      if (current !== dialogVersion || !active) return;
      for (const route of result.data) { const option = node('option', `${route.nombre} · ${route.zona}`); option.value = route.id_ruta; el('squadCreateRoute').append(option); }
      el('squadCreateStatus').textContent = result.data.length ? '' : 'No hay rutas registradas. El administrador debe crear una ruta antes del recorrido.';
      el('squadCreateSubmit').disabled = !result.data.length; el('squadCreateRoute').focus();
    } catch (error) { if (current === dialogVersion) el('squadCreateStatus').textContent = error.message; }
  });
  el('squadCreateTripForm').addEventListener('submit', async event => {
    event.preventDefault(); if (saving || !permissions.crear_recorridos || el('squadCreateSubmit').disabled) return;
    const route = Number(el('squadCreateRoute').value), date = el('squadCreateDate').value;
    if (!Number.isInteger(route) || route <= 0 || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(date)) { el('squadCreateStatus').textContent = 'Seleccioná una ruta y una fecha de inicio válida.'; return; }
    busyTrip(true); el('squadCreateStatus').textContent = 'Creando recorrido…';
    try {
      const response = await fetch(buildApiUrl('/backend/api/recorridos.php', { cacheBust: false }), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_ruta: route, fecha_inicio: date.replace('T', ' ') + ':00', estado: 'Pendiente' }) });
      fail(response, await response.json()); el('squadCreateStatus').textContent = 'Recorrido creado. Seleccionalo arriba para confirmar su asignación.';
      availablePage = 1; await availableTrips();
    } catch (error) { el('squadCreateStatus').textContent = error.message; }
    finally { busyTrip(false); }
  });
  async function open() { active = true; if (!await ready) { el('squadStatus').textContent = 'No tenés permiso para administrar cuadrillas.'; return; } if (active) { selected = null; await catalog(); } }
  function pause() { active = false; version++; request?.abort(); dialogVersion++; dialogRequest?.abort(); }
  window.SquadAdmin = { open, pause };
})();
