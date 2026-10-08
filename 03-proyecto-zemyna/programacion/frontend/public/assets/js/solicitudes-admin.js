(() => {
  const byId = id => document.getElementById(id);
  const root = byId('view-solicitudes');
  if (!root) return;
  const rows = byId('requestsRows'), status = byId('requestsStatus'), detail = byId('requestsDetail');
  let active = false, page = 1, hasMore = false, token = '', busy = false, data = null;
  let listVersion = 0, detailVersion = 0, listRequest, detailRequest;
  let filters = { estado: 'Pendiente' };
  const disabledBeforeSave = new WeakMap();
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = String(text); return n; };
  const button = (text, fn) => { const n = node('button', text); n.type = 'button'; n.className = 'link-button'; n.addEventListener('click', fn); return n; };
  const show = value => value == null || value === '' ? 'Sin registro' : String(value);
  function controls() {
    root.querySelectorAll('button,input,select,textarea').forEach(n => {
      if (busy) { if (!disabledBeforeSave.has(n)) disabledBeforeSave.set(n, n.disabled); n.disabled = true; }
      else if (disabledBeforeSave.has(n)) { n.disabled = disabledBeforeSave.get(n); disabledBeforeSave.delete(n); }
    });
    byId('requestsPrevious').disabled = busy || page <= 1;
    byId('requestsNext').disabled = busy || !hasMore;
  }
  async function api(params, body, signal, catalog = false) {
    const path = catalog ? '/backend/api/tipos_residuo.php' : '/backend/api/solicitudes_admin.php';
    const response = await fetch(buildApiUrl(path + (params ? `?${new URLSearchParams(params)}` : ''), { cacheBust: false }), {
      method: body ? 'PUT' : 'GET', cache: 'no-store', credentials: 'same-origin', signal,
      ...(body ? { headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify(body) } : {})
    });
    if (response.status === 401) window.location.replace(buildFrontendUrl('login.html'));
    const json = await response.json();
    if (!response.ok || !json.success) { const e = new Error(json.message || 'No se pudo completar la consulta.'); e.status = response.status; throw e; }
    if (!catalog && json.csrf_token) token = json.csrf_token;
    return json.data;
  }
  async function loadList() {
    listRequest?.abort(); listRequest = new AbortController(); const version = ++listVersion;
    rows.replaceChildren(); status.textContent = 'Cargando solicitudes…'; hasMore = false; controls();
    try {
      const result = await api({ ...filters, page, limit: 20 }, null, listRequest.signal);
      if (!active || version !== listVersion) return;
      page = result.page; hasMore = result.has_more;
      for (const s of result.items) {
        const tr = node('tr');
        for (const value of [s.tracking_number, s.fecha, show(s.tipo_solicitud), s.estado,
          `${s.requiere_confirmacion ? 'Residuo pendiente de confirmación' : 'Residuo confirmado'}${s.historica_programada_sin_intento ? ' · Programada histórica sin intento' : ''}`]) tr.append(node('td', value));
        const td = node('td'); td.append(button('Ver detalle', () => { if (!busy) openDetail(Number(s.id_solicitud)); })); tr.append(td); rows.append(tr);
      }
      status.textContent = result.items.length ? `${result.items.length} solicitudes en esta página.` : 'No hay solicitudes para estos filtros.';
      byId('requestsPage').textContent = `Página ${page}`;
    } catch (e) { if (version === listVersion && e.name !== 'AbortError') status.textContent = e.status === 403 ? 'No disponés de permisos para consultar solicitudes especiales.' : e.message; }
    finally { if (version === listVersion) controls(); }
  }
  function attempt(a) {
    const box = node('div');
    box.append(node('p', `Intento #${a.id_atencion_solicitud} · ${a.estado} · Cuadrilla #${a.id_cuadrilla} · Utilización V19 #${a.id_asignacion_vehiculo}`));
    for (const [label, date, actor] of [['Asignación','fecha_asignacion','id_usuario_asigna'],['Aceptación','fecha_aceptacion','id_usuario_acepta'],['Inicio','fecha_inicio','id_usuario_inicia'],['Cierre','fecha_cierre','id_usuario_cierra']]) {
      if (a[date] != null) box.append(node('p', `${label}: ${a[date]} · Usuario #${a[actor]}`));
    }
    if (a.motivo_cierre != null) box.append(node('p', `Motivo: ${a.motivo_cierre}`));
    return box;
  }
  function renderDetail(d, id, version, recoverOptions) {
    const s = d.solicitud, cap = d.capacidades;
    detail.replaceChildren(); detail.hidden = false;
    const title = node('h3', `Solicitud ${s.tracking_number}`); title.tabIndex = -1; detail.append(title);
    detail.append(button('Cerrar detalle', () => { if (!busy) { detailVersion++; detailRequest?.abort(); detail.hidden = true; data = null; } }));
    const fields = { Fecha: s.fecha, Servicio: s.tipo_solicitud, Residuo: s.tipo_residuo, Estado: s.estado, Dirección: s.direccion, Descripción: s.descripcion,
      Email: s.email, Teléfono: s.telefono, Cuadrilla: s.id_cuadrilla == null ? 'Sin asignar' : `#${s.id_cuadrilla}`,
      'Confirmación del residuo': s.fecha_confirmacion_residuo, 'Autor de confirmación': s.id_usuario_confirma_residuo == null ? (s.fecha_confirmacion_residuo ? 'Declaración pública' : 'Sin registro') : `Usuario #${s.id_usuario_confirma_residuo}` };
    const dl = node('dl'); dl.className = 'incident-detail-fields'; for (const [label,value] of Object.entries(fields)) dl.append(node('dt',label),node('dd',show(value))); detail.append(dl);
    if (d.historica_programada_sin_intento) detail.append(node('p','Programada histórica sin intento registrado. La gestión actual no reconstruye asignaciones anteriores.'));
    if (!s.fecha_confirmacion_residuo) detail.append(node('p','Residuo pendiente de confirmación administrativa.'));
    if (d.motivo_bloqueo) detail.append(node('p',d.motivo_bloqueo));
    detail.append(node('h4','Intento actual'),d.intento_actual ? attempt(d.intento_actual) : node('p','Sin intento abierto.'),node('h4','Historial operativo'));
    if (!d.historial.length) detail.append(node('p','Sin intentos registrados.'));
    for (const a of d.historial) detail.append(attempt(a));
    const prev = button('Historial anterior',()=>{if(!busy)openDetail(id,d.historial_page-1);}), next = button('Historial siguiente',()=>{if(!busy)openDetail(id,d.historial_page+1);});
    prev.disabled = d.historial_page <= 1; next.disabled = !d.historial_has_more; detail.append(prev,node('span',` Página ${d.historial_page} `),next);
    const message = node('p'); message.setAttribute('role','status'); message.setAttribute('aria-live','polite'); detail.append(message);
    const editable = !['En atención','Finalizada','Cancelada'].includes(s.estado);
    if (editable && cap.confirmar_residuo) addResidue(d,id,version,message);
    if (editable && (cap.asignar || cap.reasignar)) addAssignment(d,id,version,message,recoverOptions);
    for (const [action,label] of [['desasignar','Desasignar'],['cancelar','Cancelar solicitud']]) if (editable && cap[action]) {
      const form = node('form'); form.className = 'resource-form';
      const reason = node('textarea'); reason.required = true; reason.maxLength = 500;
      const labelNode = node('label',`Motivo para ${label.toLowerCase()} `); labelNode.append(reason);
      const submit = node('button',label); submit.type = 'submit'; submit.className = 'primary-button'; form.append(labelNode,submit);
      form.addEventListener('submit',e=>{e.preventDefault();save(d,id,version,action,{motivo:reason.value.trim()},message,`¿Confirmás ${label.toLowerCase()}?`);}); detail.append(form);
    }
    title.focus(); controls();
  }
  async function addResidue(d,id,version,message) {
    const form = node('form'); form.className = 'resource-form'; const select = node('select'); select.required = true; select.disabled = true;
    const label = node('label','Confirmar residuo explícito '); label.append(select);
    const warning = node('p',`Clasificación actual: ${show(d.solicitud.tipo_residuo)}.`);
    const submit = node('button','Confirmar residuo'); submit.type = 'submit'; submit.className = 'primary-button'; submit.disabled = true; form.append(label,warning,submit); detail.append(form);
    select.addEventListener('change',()=>{warning.textContent = Number(select.value)!==Number(d.solicitud.id_tipo_residuo) ? 'Vas a modificar la clasificación actual. Se conservará solamente la última confirmación.' : `Clasificación actual: ${show(d.solicitud.tipo_residuo)}.`;});
    let catalog = [];
    form.addEventListener('submit',e=>{e.preventDefault();if(!catalog.some(r=>String(r.id_tipo_residuo)===select.value)){message.textContent='Seleccioná un residuo del catálogo.';return;}save(d,id,version,'confirmar_residuo',{id_tipo_residuo:Number(select.value)},message,Number(select.value)!==Number(d.solicitud.id_tipo_residuo)?'¿Confirmás el cambio de clasificación?':'¿Confirmás el residuo seleccionado?');});
    try {
      catalog = await api(null,null,detailRequest.signal,true);
      if (version !== detailVersion || !active) return;
      select.replaceChildren(); const blank = node('option','Seleccionar residuo'); blank.value=''; select.append(blank);
      for(const r of catalog){const o=node('option',r.nombre);o.value=String(r.id_tipo_residuo);select.append(o);}
      select.value=''; select.disabled=submit.disabled=busy || !catalog.length;
      if(!catalog.length)warning.textContent='El catálogo no tiene residuos disponibles.';
    } catch(e){if(version===detailVersion && e.name!=='AbortError')warning.textContent=`No se pudo cargar el catálogo. ${e.message} Volvé a abrir el detalle.`;}
  }
  async function addAssignment(d,id,version,message,recoverOptions) {
    const form=node('form');form.className='resource-form';const select=node('select');select.required=true;select.disabled=true;
    const label=node('label','Cuadrilla y vehículo APOYO ');label.append(select);const help=node('p','Cargando candidatas…');
    const reassign=d.capacidades.reasignar;const reason=node('textarea');reason.required=true;reason.maxLength=500;
    form.append(label,help);if(reassign){const rl=node('label','Motivo de reasignación ');rl.append(reason);form.append(rl);}
    const submit=node('button',reassign?'Reasignar':'Asignar');submit.type='submit';submit.className='primary-button';submit.disabled=true;form.append(submit);detail.append(form);
    let options=[];
    form.addEventListener('submit',e=>{e.preventDefault();const o=options.find(r=>String(r.id_asignacion_vehiculo)===select.value);if(!o){message.textContent='Seleccioná una candidata vigente.';return;}
      save(d,id,version,reassign?'reasignar':'asignar',{id_cuadrilla:Number(o.id_cuadrilla),id_asignacion_vehiculo:Number(o.id_asignacion_vehiculo),id_usa:Number(o.id_usa),...(reassign?{motivo:reason.value.trim()}:{})},message,`¿Asignar a ${o.nombre}, vehículo ${o.matricula} · APOYO?`);});
    try {
      const result=await api({id,opciones:'asignacion'},null,detailRequest.signal);if(version!==detailVersion || !active)return;
      options=result.items;const blank=node('option','Seleccionar candidata');blank.value='';select.replaceChildren(blank);
      for(const o of options){const n=node('option',`${o.nombre} · Vehículo #${o.id_vehiculo} · ${o.matricula} · ${o.funcion_operativa}`);n.value=String(o.id_asignacion_vehiculo);select.append(n);}
      select.value='';select.disabled=submit.disabled=busy || !options.length;help.textContent=result.motivo || 'APOYO no requiere recorrido fijo. La disponibilidad se revalida al guardar.';
    }catch(e){if(version===detailVersion && e.name!=='AbortError'){
      help.textContent=e.message;submit.disabled=true;
      if(e.status===409 && recoverOptions){await Promise.all([loadList(),openDetail(id,1,false)]);status.textContent='La disponibilidad cambió. Se actualizó el detalle; revisá las opciones.';}
    }}
  }
  async function openDetail(id,historyPage=1,recoverOptions=true) {
    detailRequest?.abort();detailRequest=new AbortController();const version=++detailVersion;data=null;detail.hidden=false;detail.replaceChildren(node('p','Cargando detalle…'));
    try {const d=await api({id,historial_page:historyPage},null,detailRequest.signal);if(!active || version!==detailVersion)return;data=d;renderDetail(d,id,version,recoverOptions);}
    catch(e){if(version===detailVersion && e.name!=='AbortError')detail.replaceChildren(node('p',e.status===403?'No disponés de permisos para consultar este detalle.':e.message));}
  }
  async function save(d,id,version,action,extra,message,confirmation) {
    if(busy || version!==detailVersion || !active || data!==d || !d.capacidades[action])return;
    if('motivo' in extra && (!extra.motivo || [...extra.motivo].length>500)){message.textContent='Indicá un motivo de entre 1 y 500 caracteres.';return;}
    if(!window.confirm(confirmation))return;
    const body={accion:action,id_solicitud:id,estado_esperado:d.estado_esperado,version_esperada:d.version_esperada,id_atencion_esperada:d.id_atencion_esperada,...extra};
    busy=true;controls();message.textContent='Guardando…';
    try {await api(null,body);if(active && version===detailVersion){busy=false;await Promise.all([loadList(),openDetail(id)]);status.textContent='Operación completada.';}}
    catch(e){if(active && version===detailVersion){busy=false;await Promise.all([loadList(),openDetail(id)]);status.textContent=e.status===409?'La solicitud cambió. Se actualizaron el detalle y las opciones; revisá y confirmá nuevamente.':e.message;}}
    finally {busy=false;controls();}
  }
  function pause(){active=false;listVersion++;detailVersion++;listRequest?.abort();detailRequest?.abort();data=null;detail.hidden=true;}
  async function open(){active=true;await loadList();}
  byId('requestsFilters').addEventListener('submit',e=>{e.preventDefault();if(busy)return;filters={};for(const [key,id] of [['estado','requestsState'],['desde','requestsFrom'],['hasta','requestsTo'],['tipo_solicitud','requestsService']])if(byId(id).value)filters[key]=byId(id).value;page=1;detailVersion++;detailRequest?.abort();detail.hidden=true;data=null;loadList();});
  byId('requestsRefresh').addEventListener('click',()=>{if(!busy){loadList();if(data)openDetail(Number(data.solicitud.id_solicitud),data.historial_page);}});
  byId('requestsPrevious').addEventListener('click',()=>{if(!busy && page>1){page--;loadList();}});
  byId('requestsNext').addEventListener('click',()=>{if(!busy && hasMore){page++;loadList();}});
  window.SolicitudesAdmin={open,pause};
})();
