// Adaptación de la pantalla operativa al formulario de problemas existente.
const adminSessionReady = Promise.resolve(true);
const readJsonResponse = response => response.json();
async function incidentApi(params, options = {}) {
  const response = await fetch(buildApiUrl(`/backend/api/incidencias.php?${new URLSearchParams(params)}`), options);
  const json = await response.json();
  if (response.status === 401) window.location.replace(buildFrontendUrl('login.html'));
  if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo procesar el reporte.');
  return json;
}
document.getElementById('collectionReportButton').addEventListener('click', () => {
  const panel = document.getElementById('collectionReport');
  panel.hidden = !panel.hidden;
  if (!panel.hidden) { window.CrewReport.open(); panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  else window.CrewReport.pause();
});
