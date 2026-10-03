(() => {
  const root = document.getElementById('vehicleAssignment');
  if (!root) return;
  let version = 0, request, busy = false;
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
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
  async function open(squad, page = 1) {
    request?.abort(); request = new AbortController(); const currentVersion = ++version;
    root.hidden = false; root.replaceChildren(node('p', 'Cargando utilización…'));
    try {
      const [catalog, data] = await Promise.all([api(null, null, request.signal), api({ id_cuadrilla: squad, page }, null, request.signal)]);
      if (currentVersion !== version) return;
      const current = data.actual;
      root.replaceChildren(node('h3', 'Utilización actual del vehículo'), node('p', current
        ? `${current.matricula} · ${current.funcion_operativa || 'Pendiente'} · ${current.estado} · Inicio: ${current.fecha_inicio}`
        : 'Sin utilización abierta.'));
      const status = node('p'); status.setAttribute('role', 'status'); root.append(status);
      if (catalog.puede_modificar) {
        const form = node('form'), select = node('select'), label = node('label', 'Vehículo autorizado ');
        select.required = true; select.append(new Option('Seleccionar vehículo', ''));
        for (const vehicle of data.vehiculos) {
          if (Number(vehicle.activo) !== 1 || vehicle.estado === 'En Mantenimiento' || !vehicle.funcion_operativa || Number(vehicle.autorizaciones) !== 1 || Number(vehicle.id_vehiculo) === Number(current?.id_vehiculo)) continue;
          select.append(new Option(`${vehicle.matricula} · ${vehicle.funcion_operativa} · ${vehicle.estado}`, String(vehicle.id_vehiculo)));
        }
        label.append(select); form.append(label);
        const reason = node('input'); reason.maxLength = 150;
        if (current) { const rl = node('label', 'Motivo de cambio o cierre '); reason.required = true; rl.append(reason); form.append(rl); }
        const submit = node('button', current ? 'Cambiar vehículo' : 'Asignar vehículo'); submit.type = 'submit'; form.append(submit);
        const close = node('button', 'Finalizar utilización'); close.type = 'button';
        if (current) form.append(close);
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
          try { await api(null, body); if (currentVersion === version) await open(squad); }
          catch (e) { if (currentVersion === version) status.textContent = e.message + ' Actualizá la consulta si cambió la utilización.'; }
          finally { busy = false; submit.disabled = close.disabled = false; }
        }
        form.addEventListener('submit', e => { e.preventDefault(); save(current ? 'cambiar' : 'abrir'); });
        close.addEventListener('click', () => save('cerrar')); root.append(form);
      }
      const refresh = node('button', 'Actualizar utilización'); refresh.type = 'button'; refresh.addEventListener('click', () => { if (!busy) open(squad, page); }); root.append(refresh);
      const history = node('details'); history.append(node('summary', 'Historial de utilización'));
      for (const row of data.historial) history.append(node('p', `#${row.id_asignacion_vehiculo} · ${row.matricula} · ${row.fecha_inicio} → ${row.fecha_fin || 'Abierta'} · Asignó: #${row.id_usuario_asigna}${row.fecha_fin ? ` · Cerró: #${row.id_usuario_finaliza} · ${row.motivo_cierre}` : ''}`));
      root.append(history);
      const previous = node('button', 'Anterior'), next = node('button', 'Siguiente'); previous.type = next.type = 'button';
      previous.disabled = page <= 1; next.disabled = !data.has_more;
      previous.addEventListener('click', () => { if (!busy) open(squad, page - 1); }); next.addEventListener('click', () => { if (!busy) open(squad, page + 1); });
      root.append(previous, node('span', ` Página ${page} `), next);
    } catch (e) { if (currentVersion === version && e.name !== 'AbortError') root.replaceChildren(node('p', e.message)); }
  }
  window.VehicleAssignmentAdmin = { open, pause };
})();
