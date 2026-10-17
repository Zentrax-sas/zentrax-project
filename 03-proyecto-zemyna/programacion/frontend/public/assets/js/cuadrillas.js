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
  let memberCount, memberNames, preparationUpdate, membersLoading = false, contextVersion = 0;
  function clearContext() {
    contextVersion++; members = null; membersLoading = false; memberCount = memberNames = preparationUpdate = null; action = null;
    window.VehicleAssignmentAdmin?.pause();
    el('squadDetail').hidden = true; el('squadSummary').replaceChildren();
    el('squadActiveMembers').replaceChildren(); el('squadHistory').replaceChildren();
    el('squadConfirm').disabled = true; el('squadTripConfirm').disabled = true;
    dialogVersion++; dialogRequest?.abort();
    for (const id of ['squadDialog', 'squadTripDialog']) if (el(id).open) el(id).close();
  }
  const memberCountText = count => `${count} ${Number(count) === 1 ? 'integrante con pertenencia vigente' : 'integrantes con pertenencia vigente'} · Turno ${selected.turno}`;
  const author = (trip, id) => { const u = trip.autores?.find(a => Number(a.id_usuario) === Number(id)); return u ? `${u.nombre} ${u.apellido}` : 'Sin autor registrado'; };
  function list(data) {
    el('squadList').hidden = false; el('squadDetail').hidden = true; el('squadPaging').hidden = false;
    el('squadList').replaceChildren(table(['Cuadrilla', 'Integrantes con pertenencia vigente', 'Vehículo', 'Recorrido actual', 'Estado', 'Acción'], data.lista.items.map(c => [c.nombre, String(c.integrantes_activos), vehicles(c.vehiculos || c.recorrido_actual?.vehiculos), c.recorrido_actual?.ruta_nombre || 'Sin recorrido', c.recorrido_actual?.estado || 'Sin recorrido', button('Ver detalle', () => select(c))])));
    el('squadPage').textContent = `Página ${page} · ${data.lista.total} cuadrillas`; el('squadPrev').disabled = page <= 1; el('squadNext').disabled = page * data.lista.page_size >= data.lista.total;
    el('squadStatus').textContent = data.lista.items.length ? '' : 'No hay cuadrillas registradas.';
  }
  function summary(data) {
    selected = data.cuadrilla; const trip = data.recorrido_actual;
    const squad = selected.id_cuadrilla, current = ++contextVersion;
    const valid = () => active && current === contextVersion && selected?.id_cuadrilla === squad;
    const card = node('article'); card.className = 'card squad-summary';
    const guide = node('section'); guide.className = 'squad-preparation'; guide.hidden = true;
    guide.id = 'squadPreparationGuide'; guide.setAttribute('aria-labelledby', 'squadPreparationTitle');
    const guideTitle = node('h3', 'Preparar jornada'); guideTitle.setAttribute('tabindex', '-1');
    guideTitle.id = 'squadPreparationTitle';
    const steps = node('ol');
    const memberStep = node('p', permissions.integrantes ? 'Consultando integrantes…' : 'No disponible: consulta de integrantes sin permiso.');
    const vehicleStep = node('p', 'Consultando utilización vigente…');
    const routeStep = node('p', trip ? `${trip.ruta_nombre} · ${trip.estado}. Revisá el recorrido existente antes de preparar otro.` : 'No configurado: sin recorrido actual. Podés consultar las herramientas existentes.');
    for (const [title, status] of [['Cuadrilla e integrantes', memberStep], ['Vehículo autorizado y utilización vigente', vehicleStep], ['Ruta y recorrido', routeStep]]) {
      const step = node('li'); status.setAttribute('role', 'status'); step.append(node('h4', title), status); steps.append(step);
    }
    const review = node('li'); review.append(node('h4', 'Revisión de la preparación'), node('p', 'Esta guía muestra información consultada. No certifica disponibilidad, compatibilidad ni preparación completa. Cada operación conserva sus validaciones y confirmación propia.')); steps.append(review);
    guide.append(guideTitle, node('p', `Cuadrilla: ${selected.nombre} · Turno ${selected.turno}`), steps);
    const tools = node('div'); tools.className = 'squad-preparation-tools';
    if (permissions.integrantes) tools.append(button('Gestionar integrantes', () => { if (!valid()) return; setTab('members'); el('squadMembersTab').focus(); }));
    const utilizationLink = button('Consultar utilización', () => {
      if (!valid()) return;
      const target = el('vehicleAssignment'); target.setAttribute('tabindex', '-1'); target.focus(); target.scrollIntoView({ block: 'nearest' });
    }); utilizationLink.disabled = true; tools.append(utilizationLink);
    tools.append(button(trip ? 'Ver recorrido actual y recorridos' : 'Consultar recorridos', () => { if (!valid()) return; setTab('trips'); el('squadTripsTab').focus(); }));
    if (!trip && permissions.asignar_recorridos) tools.append(button('Abrir herramientas de asignación', () => { if (!valid()) return; setTab('trips'); el('squadAssignTrip').click(); }));
    guide.append(tools, node('p', trip ? 'Ya existe un recorrido operativo. Consultalo para continuar.' : permissions.crear_recorridos ? 'La creación de recorridos sigue disponible dentro de las herramientas de asignación. Abrirlas no guarda cambios.' : 'Creación no disponible con los permisos actuales. La asignación conserva sus controles existentes.'));
    if (permissions.asignar_recorridos) {
      const access = button('Preparar jornada', () => { if (!valid()) return; guide.hidden = !guide.hidden; access.setAttribute('aria-expanded', String(!guide.hidden)); if (!guide.hidden) guideTitle.focus(); });
      access.className = 'primary-button'; access.setAttribute('aria-expanded', 'false');
      access.setAttribute('aria-controls', 'squadPreparationGuide');
      card.append(access, guide);
    }
    preparationUpdate = count => { if (valid()) memberStep.textContent = count ? `Consultado: ${count} integrantes con pertenencia vigente. La habilitación se valida al operar.` : 'No configurado: sin integrantes con pertenencia vigente.'; };
    memberCount = node('p', memberCountText(data.integrantes_activos));
    memberNames = node('ul'); memberNames.className = 'squad-member-names';
    const memberStatus = node('p', permissions.integrantes ? 'Cargando integrantes…' : 'Consulta de integrantes no disponible con tus permisos.');
    memberStatus.setAttribute('role', 'status');
    const utilization = node('p', 'Consultando utilización vigente…'); utilization.setAttribute('role', 'status');
    memberCount.className = 'squad-secondary';
    const grid = node('div'); grid.className = 'squad-overview-grid';
    const block = title => { const section = node('section'); section.className = 'squad-overview-block'; section.append(node('h4', title)); grid.append(section); return section; };
    const vehicleBlock = block('Vehículo actual'), tripBlock = block('Recorrido actual'), membersBlock = block('Integrantes con pertenencia vigente'), authorizedBlock = block('Vehículos autorizados');
    vehicleBlock.append(utilization); membersBlock.append(memberStatus, memberNames);
    authorizedBlock.append(node('p', data.vehiculos?.length ? vehicles(data.vehiculos) : 'Sin vehículos autorizados en esta consulta.'), node('p', 'La autorización no confirma disponibilidad ni compatibilidad para un recorrido.'));
    authorizedBlock.className += ' squad-secondary';
    card.append(memberCount, grid);
    if (permissions.integrantes) {
      membersBlock.append(button('Gestionar integrantes', () => { setTab('members'); el('squadMembersTab').focus(); }));
      membersLoading = true;
      el('squadActiveMembers').replaceChildren(node('p', 'Cargando integrantes…'));
      get({ view: 'integrantes', id_cuadrilla: squad }).then(result => {
        if (!valid()) return;
        renderMembers(result.data, result); memberStatus.textContent = '';
      }).catch(() => { if (valid()) { membersLoading = false; memberStatus.textContent = 'No se pudo consultar los integrantes con el acceso actual. Actualizá el detalle para reintentar.'; memberStep.textContent = 'No se pudo consultar integrantes. Actualizá el detalle para reintentar.'; el('squadActiveMembers').replaceChildren(node('p', memberStep.textContent)); } });
    }
    if (window.VehicleAssignmentAdmin) window.VehicleAssignmentAdmin.open(squad, 1, detail => {
      if (!valid()) return;
      utilizationLink.disabled = !detail;
      vehicleStep.textContent = detail ? `${detail.vehiculos?.length ? 'Consultado: vehículos autorizados.' : 'No configurado: sin vehículos autorizados en la consulta V19.'} ${detail.actual ? `Utilización vigente consultada: ${detail.actual.matricula}.` : 'No configurado: sin utilización vigente.'} No se ha confirmado disponibilidad ni compatibilidad.` : 'No disponible: no se pudo consultar utilización con el acceso actual. Actualizá el detalle para reintentar.';
      utilization.textContent = '';
      if (detail?.actual) utilization.replaceChildren(node('strong', detail.actual.matricula), node('span', `${detail.actual.funcion_operativa || 'Sin clasificación'} · ${detail.actual.estado}`), node('span', 'Utilización V19 vigente'));
      else utilization.textContent = detail ? 'Sin utilización vigente.' : 'Consulta de utilización no disponible. No se pudo confirmar el vehículo vigente.';
    });
    else { utilization.textContent = 'Consulta de utilización no disponible.'; vehicleStep.textContent = 'No disponible: herramienta de consulta de utilización.'; }
    if (trip) tripBlock.append(node('strong', trip.ruta_nombre), node('p', trip.estado), node('p', progress(trip)), node('p', `Inicio: ${trip.fecha_inicio} · Fin: ${trip.fecha_fin || 'Sin finalizar'}`));
    else tripBlock.append(node('p', 'Sin recorrido actual asignado.'));
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
    catch (error) { if (current === version && error.name !== 'AbortError') { clearContext(); el('squadStatus').textContent = error.message; el('squadList').hidden = true; el('squadDetail').hidden = true; el('squadPaging').hidden = true; } }
  }
  function select(squad) {
    if (saving) return; clearContext(); selected = squad; tripPage = 1; el('squadState').value = ''; el('squadTripDetail').replaceChildren(); setTab('summary');
    return load({ view: 'administracion', id_cuadrilla: squad.id_cuadrilla }, summary);
  }
  function memberRows(rows, current) {
    if (!rows.length) return node('p', current ? 'No hay integrantes con pertenencia vigente.' : 'No hay pertenencias anteriores.');
    return table(['Integrante', 'Desde', 'Hasta', 'Asignó', 'Acción'], rows.map(u => [`${u.nombre} ${u.apellido}`, u.fecha_inicio, u.fecha_fin || 'Vigente', u.asignador_nombre ? `${u.asignador_nombre} ${u.asignador_apellido || ''}` : 'Administrador no disponible', current && permissions.modificar_integrantes ? button('Finalizar pertenencia', event => finish(u, event.currentTarget)) : '—']));
  }
  function renderMembers(data, result) {
    membersLoading = false;
    members = data; permissions.modificar_integrantes = result.puede_gestionar === true;
    const currentMembers = data.historial.filter(u => !u.fecha_fin);
    preparationUpdate?.(currentMembers.length);
    if (memberCount) memberCount.textContent = memberCountText(currentMembers.length);
    if (memberNames) memberNames.replaceChildren(...(currentMembers.length ? currentMembers.map(u => node('li', `${u.nombre} ${u.apellido}`)) : [node('li', 'No hay integrantes con pertenencia vigente.')]));
    el('squadActiveMembers').replaceChildren(memberRows(data.historial.filter(u => !u.fecha_fin), true));
    el('squadHistory').replaceChildren(memberRows(data.historial.filter(u => u.fecha_fin), false));
    el('squadAdd').hidden = !permissions.modificar_integrantes; el('squadUsers').hidden = !permissions.usuarios;
  }
  function setTab(name, refresh = false) {
    if (name === 'members' && !permissions.integrantes) return;
    tab = name;
    el('squadSummaryPanel').hidden = name !== 'summary';
    for (const [key, suffix] of [['summary','Summary'],['members','Members'],['trips','Trips']]) {
      el(`squad${suffix}`).hidden = key !== name;
      el(`squad${suffix}Tab`).setAttribute('aria-selected', String(key === name));
      el(`squad${suffix}Tab`).setAttribute('tabindex', key === name ? '0' : '-1');
    }
    if (name === 'members' && selected && !membersLoading && (refresh || !members)) {
      membersLoading = true;
      el('squadActiveMembers').replaceChildren(node('p', 'Cargando integrantes…'));
      load({ view: 'integrantes', id_cuadrilla: selected.id_cuadrilla }, renderMembers);
    }
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
    if (!selected || !members || !permissions.modificar_integrantes) return;
    el('squadSelectGroup').hidden = false; el('squadEligible').disabled = false; el('squadDialogTitle').textContent = `Agregar operario a ${selected.nombre}`;
    el('squadEligible').replaceChildren();
    for (const u of members.elegibles) { const o = node('option', `${u.nombre} ${u.apellido}`); o.value = u.id_usuario; el('squadEligible').append(o); }
    el('squadExcluded').replaceChildren(...(members.no_elegibles || []).map(u => node('li', `${u.nombre} ${u.apellido} — ${u.motivo}`)));
    if (!members.no_elegibles?.length) el('squadExcluded').append(node('li', 'Solo se muestran personas con habilitación operativa vigente.'));
    el('squadDialogUsers').hidden = !permissions.usuarios;
    el('squadEligible').value = members.elegibles[0]?.id_usuario || ''; chooseUser(); showDialog(el('squadAdd')); el('squadEligible').focus();
  }
  function chooseUser() {
    const u = members.elegibles.find(u => String(u.id_usuario) === el('squadEligible').value);
    const same = u?.pertenencia && Number(u.pertenencia.id_cuadrilla) === Number(selected.id_cuadrilla);
    el('squadConfirm').disabled = saving || !u || Boolean(same);
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
    finally { saving = false; el('squadCancel').disabled = false; el('squadEligible').disabled = false; el('squadConfirm').disabled = !action; }
  });
  el('squadDialog').addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  el('squadDialog').addEventListener('close', () => focusReturn?.focus());
  el('squadCancel').addEventListener('click', () => { if (!saving) el('squadDialog').close(); });
  el('squadAdd').addEventListener('click', assignment); el('squadEligible').addEventListener('change', chooseUser);
  for (const id of ['squadUsers', 'squadDialogUsers']) el(id).addEventListener('click', event => {
    event.preventDefault(); if (saving) return; el('squadDialog').close(); openView('usuarios'); history.replaceState(null, '', '#usuarios');
  });
  el('squadSummaryTab').addEventListener('click', () => setTab('summary'));
  el('squadMembersTab').addEventListener('click', () => setTab('members')); el('squadTripsTab').addEventListener('click', () => setTab('trips'));
  const tabs = [['summary', 'squadSummaryTab'], ['members', 'squadMembersTab'], ['trips', 'squadTripsTab']];
  for (const [name, id] of tabs) el(id).addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const available = tabs.filter(([, target]) => !el(target).hidden);
    const index = available.findIndex(([key]) => key === name);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? available.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + available.length) % available.length;
    event.preventDefault(); setTab(available[next][0]); el(available[next][1]).focus();
  });
  const catalog = () => load({ view: 'administracion', page }, list);
  const tripList = () => { const params = { view: 'administracion', id_cuadrilla: selected.id_cuadrilla, page: tripPage }; if (el('squadState').value) params.estado = el('squadState').value; load(params, trips); };
  el('squadBack').addEventListener('click', () => { if (saving) return; clearContext(); selected = null; catalog(); });
  el('squadRefresh').addEventListener('click', () => selected ? (tab === 'members' ? setTab('members', true) : load({ view:'administracion', id_cuadrilla: selected.id_cuadrilla }, summary)) : (permissions.administracion ? catalog() : open()));
  el('squadPrev').addEventListener('click', () => { if (page > 1) { page--; catalog(); } }); el('squadNext').addEventListener('click', () => { page++; catalog(); });
  el('squadTripPrev').addEventListener('click', () => { if (tripPage > 1) { tripPage--; tripList(); } }); el('squadTripNext').addEventListener('click', () => { tripPage++; tripList(); }); el('squadState').addEventListener('change', () => { tripPage = 1; el('squadTripDetail').replaceChildren(); tripList(); });
  let availablePage = 1, available, dialogVersion = 0, dialogRequest;
  function chooseTrip() {
    const trip = available?.items.find(t => String(t.id_recorrido) === el('squadAvailableTrip').value);
    const vehicle = available?.vehiculos.find(v => String(v.id_usa) === el('squadTripVehicle').value);
    el('squadTripPreview').textContent = trip ? `${trip.ruta_nombre} · ${trip.estado} · Inicio previsto: ${trip.fecha_inicio} · Fin: ${trip.fecha_fin || 'Sin finalizar'} · Sin cuadrilla asignada` : '';
    el('squadTripConfirm').disabled = saving || !trip || !vehicle || Boolean(available?.conflicto);
  }
  async function availableTrips(preferredTrip = null) {
    dialogRequest?.abort(); dialogRequest = new AbortController(); const current = ++dialogVersion;
    available = null; el('squadTripConfirm').disabled = true; el('squadAvailableTrip').replaceChildren(); el('squadTripVehicle').replaceChildren(); el('squadTripPreview').textContent = '';
    el('squadTripDialogStatus').textContent = 'Cargando recorridos disponibles…';
    try {
      const result = await get({ view: 'asignables', id_cuadrilla: selected.id_cuadrilla, page: availablePage }, dialogRequest.signal);
      if (current !== dialogVersion || !active || !el('squadTripDialog').open) return;
      available = result.data;
      for (const trip of available.items) { const option = node('option', `${trip.ruta_nombre} · ${trip.fecha_inicio} · ${trip.estado}`); option.value = trip.id_recorrido; el('squadAvailableTrip').append(option); }
      for (const vehicle of available.vehiculos) { const option = node('option', `${vehicle.matricula} · ${vehicle.estado}`); option.value = vehicle.id_usa; el('squadTripVehicle').append(option); }
      el('squadAvailableTrip').value = available.items.find(t => Number(t.id_recorrido) === Number(preferredTrip))?.id_recorrido || available.items[0]?.id_recorrido || ''; el('squadTripVehicle').value = available.vehiculos[0]?.id_usa || '';
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
  function tripContext() {
    const squad = selected?.id_cuadrilla, context = contextVersion, dialog = dialogVersion;
    return () => active && selected?.id_cuadrilla === squad && contextVersion === context
      && dialogVersion === dialog && el('squadTripDialog').open;
  }
  el('squadTripAssignment').addEventListener('submit', async event => {
    event.preventDefault(); if (saving || el('squadTripConfirm').disabled || !selected || !el('squadTripDialog').open) return;
    const squad = selected.id_cuadrilla;
    const isCurrent = tripContext();
    const body = { accion: 'asignar_recorrido', destino: squad, id_recorrido: Number(el('squadAvailableTrip').value), id_usa: Number(el('squadTripVehicle').value) };
    busyTrip(true); el('squadTripDialogStatus').textContent = 'Asignando recorrido…';
    try {
      const response = await fetch(url({}), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const result = await response.json(); if (!isCurrent()) return; fail(response, result);
      busyTrip(false);
      el('squadTripDialog').close();
      if (active && selected?.id_cuadrilla === squad) {
        tripPage = 1; el('squadState').value = ''; el('squadTripDetail').replaceChildren(); setTab('trips');
        await load({ view: 'administracion', id_cuadrilla: squad }, data => { summary(data); el('squadStatus').textContent = 'Recorrido asignado. Ya está disponible para los integrantes de la cuadrilla.'; });
      }
    } catch (error) { if (isCurrent()) el('squadTripDialogStatus').textContent = error.message; }
    finally { if (isCurrent()) busyTrip(false); else saving = false; }
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
    event.preventDefault(); if (saving || !selected || !el('squadTripDialog').open || !permissions.crear_recorridos || el('squadCreateSubmit').disabled) return;
    const route = Number(el('squadCreateRoute').value), date = el('squadCreateDate').value;
    if (!Number.isInteger(route) || route <= 0 || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(date)) { el('squadCreateStatus').textContent = 'Seleccioná una ruta y una fecha de inicio válida.'; return; }
    let isCurrent = tripContext();
    busyTrip(true); el('squadCreateStatus').textContent = 'Creando recorrido…';
    try {
      const response = await fetch(buildApiUrl('/backend/api/recorridos.php', { cacheBust: false }), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_ruta: route, fecha_inicio: date.replace('T', ' ') + ':00', estado: 'Pendiente' }) });
      const result = await response.json(); if (!isCurrent()) return; fail(response, result);
      el('squadCreateStatus').textContent = `Recorrido creado (${result.data.id_recorrido}). Revisá la selección antes de asignarlo; si no aparece en esta página, consultá las siguientes. No es necesario crearlo nuevamente.`;
      availablePage = 1;
      const refresh = availableTrips(result.data.id_recorrido); isCurrent = tripContext(); await refresh;
    } catch (error) { if (isCurrent()) el('squadCreateStatus').textContent = error.message; }
    finally { if (isCurrent()) busyTrip(false); else saving = false; }
  });
  async function open() { active = true; if (!await ready) { el('squadStatus').textContent = 'No tenés permiso para administrar cuadrillas.'; return; } if (active) { clearContext(); selected = null; await catalog(); } }
  function pause() { clearContext(); active = false; version++; request?.abort(); dialogVersion++; dialogRequest?.abort(); }
  window.SquadAdmin = { open, pause };
})();
