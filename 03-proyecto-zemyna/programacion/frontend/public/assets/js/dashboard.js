(() => {
  const el = id => document.getElementById(id);
  const node = (tag, value = '') => { const n = document.createElement(tag); n.textContent = String(value); return n; };
  const display = value => value == null ? 'Sin datos' : String(value);
  const destinations = new Set(['#incidencias', '#camiones', '#contenedores', '#cuadrillas', '#informe-incidencias']);
  let active = false, enabled = false, loading = false, version = 0, request, applied = {}, last, pendingKey = '';
  function error(response, body) {
    if (response.status === 401) {
      enabled = false; last = null; el('dashboardData').hidden = true; el('dashboardNav').hidden = true;
      window.location.replace(buildFrontendUrl('login.html')); return 'Tu sesión venció. Iniciá sesión nuevamente.';
    }
    if (response.status === 403) {
      enabled = false; last = null; el('dashboardData').hidden = true; el('dashboardNav').hidden = true;
      return 'No tenés los permisos necesarios para consultar el resumen operativo.';
    }
    if (response.status === 400) return `Filtros inválidos. ${body.message || 'Revisá las fechas.'}`;
    return 'No se pudo consultar el resumen. Intentá nuevamente más tarde.';
  }
  async function get(params, signal) {
    const qs = new URLSearchParams(params).toString();
    const response = await fetch(buildApiUrl(`/backend/api/dashboard.php${qs ? '?' + qs : ''}`, { cacheBust: false }), { cache: 'no-store', signal });
    const body = await response.json(); return { response, body };
  }
  const ready = (async () => {
    if (!await adminSessionReady) { el('dashboardStatus').textContent = 'No se pudo validar la sesión.'; return false; }
    try {
      const { response, body } = await get({ view: 'permisos' });
      if (!response.ok || !body.success) { el('dashboardStatus').textContent = error(response, body); return false; }
      enabled = true; el('dashboardNav').hidden = false; el('dashboardFilterFields').disabled = false; el('dashboardRefresh').disabled = false; return true;
    } catch { el('dashboardStatus').textContent = 'No se pudo verificar el acceso. Recargá el panel para intentar nuevamente.'; return false; }
  })();
  function link(module, links) {
    const href = links?.[module]; if (!destinations.has(href)) return null;
    const a = node('a', 'Consultar módulo'); a.href = href;
    a.addEventListener('click', event => { event.preventDefault(); openView(href.slice(1)); history.replaceState(null, '', href); }); return a;
  }
  function cards(id, definitions, values, links) {
    el(id).replaceChildren(...definitions.map(([key, title, explanation, module]) => {
      const card = node('article'); card.className = 'card dashboard-metric';
      card.append(node('h4', title), node('strong', display(values[key])), node('p', explanation));
      const a = link(module, links); if (a) card.append(a); return card;
    }));
  }
  function table(caption, headers, rows) {
    const t = node('table'); t.append(node('caption', caption)); const head = node('thead'), hr = node('tr'), body = node('tbody');
    headers.forEach(label => { const cell = node('th', label); cell.setAttribute('scope', 'col'); hr.append(cell); }); head.append(hr);
    for (const row of rows) { const tr = node('tr'); row.forEach((value, i) => { const cell = node('td'); cell.dataset.label = headers[i]; if (typeof value === 'object' && value !== null) cell.append(value); else cell.textContent = display(value); tr.append(cell); }); body.append(tr); }
    t.append(head, body); return t;
  }
  function bar(value, max, tone) {
    const wrapper = node('div'), visual = node('span'); visual.className = `dashboard-bar ${tone}`; visual.setAttribute('aria-hidden', 'true');
    visual.style.width = `${max > 0 ? Math.max(0, Math.min(100, Number(value) / max * 100)) : 0}%`;
    wrapper.append(node('span', display(value)), visual); return wrapper;
  }
  function render(data) {
    cards('dashboardCurrent', [
      ['incidencias_activas', 'Incidencias activas', 'Pendiente o En Proceso.', 'incidencias'],
      ['recorridos_en_proceso', 'Recorridos en proceso', 'Ejecuciones actualmente en curso.', 'cuadrillas'],
      ['cuadrillas_sin_recorrido', 'Cuadrillas sin recorrido', 'Sin ejecución Pendiente o En Proceso relacionada.', 'cuadrillas'],
      ['camiones_disponibles', 'Camiones disponibles', 'Activos, disponibles y sin recorrido operativo relacionado.', 'camiones'],
      ['camiones_no_disponibles', 'Camiones ocupados o no disponibles', 'Activos, en otro estado o relacionados con una ejecución operativa.', 'camiones'],
      ['contenedores_prioridad_alta', 'Contenedores con prioridad alta', 'Contenedores distintos con alguna incidencia activa Alta.', 'incidencias']
    ], data.actual, data.enlaces);
    cards('dashboardResults', [
      ['incidencias_reportadas', 'Incidencias reportadas', 'Según fecha de reporte; incluye reportes sin contenedor.', 'informe'],
      ['incidencias_resueltas', 'Incidencias resueltas', 'Según fecha de resolución. Sin filtros incluye históricos sin fecha.', 'informe'],
      ['tiempo_promedio_resolucion_horas', 'Resolución promedio (horas)', `Resoluciones con duración válida: ${data.periodo_resultados.resoluciones_con_duracion}. Según fecha de resolución.`, 'informe'],
      ['recorridos_finalizados', 'Recorridos finalizados', 'Según fecha de finalización.', 'cuadrillas'],
      ['contenedores_atendidos', 'Atenciones de contenedores', 'Según fecha de atención. Una por contenedor y recorrido.', 'cuadrillas']
    ], data.periodo_resultados, data.enlaces);
    el('dashboardHistorical').textContent = `${data.historicas_sin_fecha_resolucion} incidencias resueltas históricas sin fecha de resolución: no se incluyen en rangos de resolución ni en el promedio.`;
    const states = ['Pendiente', 'En Proceso', 'Resuelta'], priorities = ['Baja', 'Media', 'Alta'];
    const max = Math.max(0, ...data.incidencias_por_estado_prioridad.map(r => Number(r.cantidad)));
    el('dashboardChart').replaceChildren(table('Cantidad de incidencias por estado y prioridad', ['Estado', ...priorities], states.map(state => [state, ...priorities.map((priority, i) => bar(data.incidencias_por_estado_prioridad.find(r => r.estado === state && r.prioridad === priority)?.cantidad ?? 0, max, `priority-${i}`))])));
    if (!data.incidencias_por_estado_prioridad.length) el('dashboardChart').append(node('p', 'No hay incidencias reportadas en el período.'));
    const topMax = Math.max(0, ...data.contenedores_problematicos.map(r => Number(r.cantidad)));
    el('dashboardTop').replaceChildren(table('Ranking por cantidad; desempate por código e identificador', ['Contenedor', 'Ubicación pública', 'Incidencias'], data.contenedores_problematicos.map(c => [c.codigo, c.direccion || 'Sin referencia disponible', bar(c.cantidad, topMax, 'ranking')])));
    if (!data.contenedores_problematicos.length) el('dashboardTop').append(node('p', 'Sin contenedores con incidencias en el período.'));
    const containers = link('contenedores', data.enlaces); if (containers) el('dashboardTop').append(containers);
    el('dashboardTrips').replaceChildren(table('Recorridos por fecha de inicio', ['Estado', 'Cantidad'], Object.entries(data.recorridos.por_estado)));
    el('dashboardProgress').textContent = `Contenedores esperados: ${display(data.recorridos.contenedores_esperados)}. Pendientes: ${display(data.recorridos.contenedores_pendientes)}. Avance: ${display(data.recorridos.porcentaje_avance)}. ${data.recorridos.motivo}`;
    el('dashboardAttention').replaceChildren(...data.requiere_atencion.map(item => {
      const card = node('article'); card.className = 'dashboard-attention'; card.append(node('h4', `${item.nivel} · ${item.cantidad}`), node('p', item.descripcion));
      const a = link(item.modulo, data.enlaces); if (a) card.append(a); return card;
    }));
    if (!data.requiere_atencion.length) el('dashboardAttention').append(node('p', 'No hay situaciones críticas detectadas actualmente.'));
    el('dashboardPeriod').textContent = `${data.periodo.descripcion} · Última actualización: ${data.generado_en} · Hora de Montevideo · Ámbito global de Operaciones`;
    el('dashboardData').hidden = false;
  }
  async function load(params, replace = false) {
    const key = JSON.stringify(params);
    if (!enabled || !active || (loading && (!replace || pendingKey === key))) return;
    pendingKey = key;
    request?.abort(); request = new AbortController(); const current = ++version; loading = true; el('dashboardRefresh').disabled = true;
    el('dashboardStatus').textContent = last ? 'Actualizando resumen…' : 'Cargando resumen…';
    try {
      const { response, body } = await get(params, request.signal);
      if (current !== version || !active) return;
      if (!response.ok || !body.success) throw new Error(error(response, body));
      last = body.data; applied = { ...params }; render(last); el('dashboardStatus').textContent = 'Resumen actualizado.';
    } catch (err) {
      if (current !== version || !active || err.name === 'AbortError') return;
      el('dashboardStatus').textContent = (err instanceof TypeError ? 'Error de red. Intentá nuevamente.' : err.message || 'No se pudo consultar el resumen.') + (last ? ' Se conserva el último resultado válido.' : '');
    } finally { if (current === version) { loading = false; el('dashboardRefresh').disabled = !enabled; el('dashboardFilterFields').disabled = !enabled; } }
  }
  el('dashboardFilters').addEventListener('submit', event => {
    event.preventDefault(); const params = {};
    if (el('dashboardFrom').value) params.fecha_desde = el('dashboardFrom').value;
    if (el('dashboardTo').value) params.fecha_hasta = el('dashboardTo').value;
    load(params, true);
  });
  el('dashboardClear').addEventListener('click', () => { el('dashboardFrom').value = ''; el('dashboardTo').value = ''; load({}, true); });
  el('dashboardRefresh').addEventListener('click', () => load(applied));
  async function open() { active = true; if (await ready && active) await load(applied); }
  function pause() { active = false; version++; request?.abort(); loading = false; el('dashboardRefresh').disabled = !enabled; }
  window.Dashboard = { open, pause };
})();
