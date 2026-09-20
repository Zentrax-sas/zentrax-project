/* Informe de solo lectura: utiliza sesión, API y escape del panel. */
(() => {
  const get = id => document.getElementById(id);
  let page = 1, query = {}, request, version = 0;
  const pause = () => { version++; request?.abort(); };
  async function load() {
    pause();
    const current = version;
    request = new AbortController();
    const signal = request.signal;
    get('reportRows').replaceChildren();
    get('reportTotals').textContent = 'Totales pendientes de consulta.';
    get('reportPage').textContent = '';
    get('reportPrevious').disabled = get('reportNext').disabled = true;
    get('reportMessage').textContent = 'Cargando informe…';
    try {
      if (!(await adminSessionReady)) throw new Error('Se requiere una sesión válida.');
      if (current !== version) return;
      const result = await incidentApi({ view: 'report', ...query, page, limit: 20 }, { signal });
      if (current !== version) return;
      get('reportRows').innerHTML = result.data.map(row => {
        const location = row.contenedor_codigo ? `Contenedor ${row.contenedor_codigo}` : row.ruta_nombre ? `Ruta ${row.ruta_nombre}` : 'Sin ubicación asociada';
        return '<tr>' + [row.tracking_number, row.fecha_reporte, row.tipo_problema, row.estado, row.prioridad, location, row.fecha_resolucion || (row.estado === 'Resuelta' ? 'Fecha de resolución no registrada' : 'No resuelta')].map(value => `<td>${escapeHtml(value)}</td>`).join('') + '</tr>';
      }).join('');
      get('reportTotals').textContent = `Abiertas: ${result.totals.abiertas} · Cerradas: ${result.totals.cerradas} · Total: ${result.totals.total}`;
      get('reportMessage').textContent = result.data.length ? `${result.data.length} incidencias en esta página.` : 'No hay incidencias para esta página y filtros.';
      get('reportPage').textContent = `Página ${page} · ${result.meta.pages} páginas con resultados`;
      get('reportPrevious').disabled = page <= 1;
      get('reportNext').disabled = page >= result.meta.pages;
    } catch (error) {
      if (current !== version || error.name === 'AbortError') return;
      get('reportMessage').textContent = error.message;
    }
  }
  get('reportFilters').addEventListener('submit', event => {
    event.preventDefault();
    query = { grupo: get('reportGroup').value, desde: get('reportFrom').value, hasta: get('reportTo').value };
    page = 1;
    load();
  });
  get('reportPrevious').addEventListener('click', () => { if (page > 1) { page--; load(); } });
  get('reportNext').addEventListener('click', () => { page++; load(); });
  window.IncidenceReport = { load, pause };
})();
