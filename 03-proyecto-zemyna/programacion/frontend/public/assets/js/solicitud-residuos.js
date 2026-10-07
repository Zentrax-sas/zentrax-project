(() => {
  const service = document.getElementById('tipo_solicitud');
  const select = document.getElementById('id_tipo_residuo');
  const status = document.getElementById('residueStatus');
  // Alcance del servicio, por nombres del catálogo; los IDs siempre provienen del servidor.
  const allowed = { Reciclables: ['Papel y cartón', 'Plástico', 'Vidrio', 'Metal'], 'Gran volumen': ['Residuos voluminosos'] };
  let catalog = [], options = [], version = 0, request;
  function refreshOptions() {
    options = []; select.replaceChildren(); select.disabled = true;
    const names = allowed[service.value];
    if (!names) { status.textContent = 'Seleccioná primero el tipo de solicitud.'; return; }
    for (const name of names) {
      const rows = catalog.filter(row => row.nombre === name);
      if (rows.length === 1 && /^[1-9][0-9]*$/.test(String(rows[0].id_tipo_residuo)) && Number(rows[0].id_tipo_residuo) <= 2147483647) options.push(rows[0]);
    }
    if (!options.length) { status.textContent = 'No hay residuos disponibles para este servicio. Actualizá el catálogo.'; return; }
    const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Seleccionar residuo principal…'; select.append(placeholder);
    for (const row of options) {
      const option = document.createElement('option'); option.value = String(row.id_tipo_residuo); option.textContent = row.nombre; select.append(option);
    }
    select.disabled = false;
    // Gran volumen tiene una sola categoría explícita, identificada en el catálogo real.
    select.value = service.value === 'Gran volumen' ? String(options[0].id_tipo_residuo) : '';
    status.textContent = service.value === 'Gran volumen' ? 'Residuo principal: Residuos voluminosos.' : 'Seleccioná el material principal que querés retirar.';
  }
  async function load() {
    request?.abort(); request = new AbortController(); const current = ++version;
    catalog = []; options = []; select.replaceChildren(); select.disabled = true; status.textContent = 'Cargando catálogo…';
    try {
      const response = await fetch(buildApiUrl('/backend/api/tipos_residuo.php', { cacheBust: false }), { cache: 'no-store', signal: request.signal });
      const json = await response.json();
      if (current !== version) return;
      if (!response.ok || !json.success || !Array.isArray(json.data)) throw new Error('Catálogo no disponible.');
      catalog = json.data; refreshOptions();
    } catch (error) {
      if (current !== version || error.name === 'AbortError') return;
      status.textContent = 'No se pudo cargar el catálogo. Actualizalo antes de enviar.';
    }
  }
  window.SolicitudResiduos = {
    refreshOptions,
    selected() { return !select.disabled && options.some(row => String(row.id_tipo_residuo) === select.value) ? Number(select.value) : null; }
  };
  service.addEventListener('change', refreshOptions);
  document.getElementById('residueRetry').addEventListener('click', load);
  window.addEventListener('pagehide', () => { version++; request?.abort(); });
  load();
})();
