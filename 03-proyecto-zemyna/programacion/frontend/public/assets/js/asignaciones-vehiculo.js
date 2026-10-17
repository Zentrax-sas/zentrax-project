(() => {
  const root = document.getElementById('vehicleAssignment');
  if (!root) return;
  let version = 0, request, busy = false;
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  // Fecha local del servidor: formatear sin convertirla a otra zona horaria.
  const dateLabel = value => {
    if (!value) return 'Fecha no disponible';
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/.exec(value);
    return match ? `${match[3]}/${match[2]}/${match[1]} · ${match[4]}:${match[5]}` : value;
  };
  const api = async (params, body, signal) => {
    const response = await fetch(buildApiUrl('/backend/api/asignaciones_vehiculo.php' + (params ? `?${new URLSearchParams(params)}` : ''), { cacheBust: false }), {
      method: body ? 'POST' : 'GET', cache: 'no-store', signal,
      ...(body ? { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {})
    });
    const result = await response.json();
    if (response.status === 401) window.location.replace(buildFrontendUrl('login.html'));
    if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo consultar la utilización.');
    return result.data;
  };
  function pause() { version++; request?.abort(); root.hidden = true; }
  async function open(squad, page = 1, onDetail) {
    request?.abort(); request = new AbortController(); const currentVersion = ++version;
    root.hidden = false; root.replaceChildren(node('p', 'Cargando utilización…'));
    try {
      const [catalog, data] = await Promise.all([api(null, null, request.signal), api({ id_cuadrilla: squad, page }, null, request.signal)]);
      if (currentVersion !== version) return;
      onDetail?.(data);
      const current = data.actual;
      root.className = 'vehicle-utilization-panel';
      root.replaceChildren(node('h3', 'Gestión de utilización'), node('p', current
        ? `Vehículo actual: ${current.matricula} · Utilización vigente desde ${dateLabel(current.fecha_inicio)}`
        : 'Sin utilización abierta.'));
      const status = node('p'); status.setAttribute('role', 'status'); root.append(status);
      if (catalog.puede_modificar) {
        const form = node('form'), select = node('select'), label = node('label', current ? 'Vehículo alternativo autorizado ' : 'Vehículo autorizado ');
        form.className = 'vehicle-utilization-form';
        select.required = true; select.append(new Option(current ? 'Seleccionar alternativa para cambiar' : 'Seleccionar vehículo', ''));
        let alternatives = 0;
        for (const vehicle of data.vehiculos) {
          if (Number(vehicle.activo) !== 1 || vehicle.estado === 'En Mantenimiento' || !vehicle.funcion_operativa || Number(vehicle.autorizaciones) !== 1 || Number(vehicle.id_vehiculo) === Number(current?.id_vehiculo)) continue;
          select.append(new Option(`${vehicle.matricula} · ${vehicle.funcion_operativa} · ${vehicle.estado}`, String(vehicle.id_vehiculo)));
          alternatives++;
        }
        label.append(select); form.append(label);
        select.disabled = alternatives === 0;
        if (!alternatives) form.append(node('p', 'No hay alternativas seleccionables en esta consulta. Esto no indica por sí solo ocupación o incompatibilidad.'));
        const reason = node('input'); reason.maxLength = 150;
        if (current) { const rl = node('label', 'Motivo de cambio o cierre '); reason.required = true; rl.append(reason); form.append(rl); }
        const actions = node('div'); actions.className = 'vehicle-utilization-actions';
        const submit = node('button', current ? 'Cambiar vehículo' : 'Asignar vehículo'); submit.type = 'submit'; actions.append(submit); form.append(actions);
        const close = node('button', 'Finalizar utilización'); close.type = 'button';
        if (current) { close.className = 'vehicle-utilization-close'; actions.append(close); }
        function validate(feedback = true) {
          submit.disabled = busy || !select.value || Boolean(current && !reason.value.trim());
          close.disabled = busy || Boolean(current && !reason.value.trim());
          if (feedback) status.textContent = current && !select.value
            ? 'Para cambiar, seleccioná una alternativa e indicá el motivo. Para finalizar la utilización actual, solo se requiere el motivo.'
            : !select.value ? 'Seleccioná un vehículo autorizado para asignar la utilización.' : current && !reason.value.trim() ? 'Indicá el motivo para continuar.' : '';
        }
        select.addEventListener('change', validate); reason.addEventListener('input', validate); validate();
        async function save(action) {
          if (busy || currentVersion !== version) return;
          if (action !== 'abrir' && !reason.value.trim()) { status.textContent = 'Indicá el motivo.'; reason.focus(); return; }
          const body = { accion: action, id_cuadrilla: Number(squad) };
          if (action !== 'cerrar') {
            if (!select.value) { status.textContent = 'Seleccioná un vehículo autorizado.'; return; }
            body.id_vehiculo = Number(select.value);
          }
          if (current) { body.id_asignacion_vehiculo = Number(current.id_asignacion_vehiculo); body.motivo = reason.value.trim(); }
          busy = true; submit.disabled = close.disabled = true; status.textContent = 'Guardando…';
          try { await api(null, body); busy = false; if (currentVersion === version) await open(squad, 1, onDetail); }
          catch (e) { if (currentVersion === version) status.textContent = e.message + ' Actualizá la consulta si cambió la utilización.'; }
          finally { busy = false; validate(false); }
        }
        form.addEventListener('submit', e => { e.preventDefault(); save(current ? 'cambiar' : 'abrir'); });
        close.addEventListener('click', () => save('cerrar')); root.append(form);
      }
      const refresh = node('button', 'Actualizar utilización'); refresh.className = 'vehicle-utilization-refresh'; refresh.type = 'button'; refresh.addEventListener('click', () => { if (!busy) open(squad, page, onDetail); }); root.append(refresh);
      const history = node('details'); history.className = 'vehicle-utilization-history'; history.append(node('summary', 'Historial de utilización'));
      if (!data.historial.length) history.append(node('p', 'No hay registros de utilización en esta página.'));
      for (const row of data.historial) {
        const record = node('article'); record.className = 'vehicle-utilization-record';
        record.dataset.assignmentId = row.id_asignacion_vehiculo;
        record.dataset.assignedBy = row.id_usuario_asigna;
        record.dataset.closedBy = row.id_usuario_finaliza ?? '';
        record.append(node('strong', row.matricula), node('p', row.fecha_fin ? 'Utilización finalizada' : 'En utilización'),
          node('p', `Inicio: ${dateLabel(row.fecha_inicio)}`), node('p', 'Asignado por: Responsable no disponible'));
        if (row.fecha_fin) record.append(node('p', `Finalización: ${dateLabel(row.fecha_fin)}`), node('p', 'Finalizado por: Responsable no disponible'), node('p', `Motivo: ${row.motivo_cierre || 'No disponible'}`));
        history.append(record);
      }
      root.append(history);
      const previous = node('button', 'Anterior'), next = node('button', 'Siguiente'); previous.type = next.type = 'button';
      previous.disabled = page <= 1; next.disabled = !data.has_more;
      previous.addEventListener('click', () => { if (!busy) open(squad, page - 1, onDetail); }); next.addEventListener('click', () => { if (!busy) open(squad, page + 1, onDetail); });
      const paging = node('div'); paging.className = 'vehicle-utilization-actions'; paging.append(previous, node('span', ` Página ${page} `), next); history.append(paging);
    } catch (e) { if (currentVersion === version && e.name !== 'AbortError') { onDetail?.(null); root.replaceChildren(node('p', 'Consulta de utilización no disponible. Actualizá el detalle para reintentar.')); } }
  }
  window.VehicleAssignmentAdmin = { open, pause };
})();
