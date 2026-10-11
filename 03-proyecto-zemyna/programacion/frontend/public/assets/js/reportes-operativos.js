(() => {
  const el = id => document.getElementById(id);
  if (!el('view-reportes')) return;
  const output = el('operationalReportsData'), status = el('operationalReportsStatus');
  const node = (tag,text='') => { const n=document.createElement(tag);n.textContent=String(text);return n; };
  const display = value => value == null ? 'Sin datos' : String(value);
  let active=false,version=0,request,applied={informe:'solicitudes'};
  function clear() { output.replaceChildren();output.hidden=true; }
  function pause() { active=false;version++;request?.abort();clear();el('operationalReportsRefresh').disabled=false; }
  function metrics(parent,title,values) {
    parent.append(node('h4',title));const dl=node('dl');dl.className='incident-detail-fields';
    for (const [label,value] of values) dl.append(node('dt',label),node('dd',display(value)));
    parent.append(dl);
  }
  function section(title) { const n=node('section');n.className='card panel';n.append(node('h3',title));output.append(n);return n; }
  function render(d) {
    clear();output.hidden=false;
    output.append(node('p',`Actividad: ${d.periodo.desde || 'Sin límite inicial'} · ${d.periodo.hasta || 'Sin límite final'}. Zona horaria: ${d.zona_horaria}. Generado: ${d.generado_en}.`));
    if (d.solicitudes) {
      const s=d.solicitudes,p=section('Solicitudes especiales');
      metrics(p,'Estados actuales (sin filtro temporal)',Object.entries(s.estados_actuales));
      metrics(p,'Actividad durante el período',[['Solicitudes creadas',s.periodo.creadas],['Solicitudes finalizadas con fecha registrada',s.periodo.finalizadas]]);
      metrics(p,'Diagnósticos actuales',[['Pendientes de clasificación (Pendiente/Programada sin confirmación)',s.pendientes_clasificacion_historica_actual],['Finalizadas sin fecha registrada',s.finalizadas_sin_fecha_actual]]);
      p.append(node('p','Se cuentan solicitudes, no intentos. Las finalizadas sin fecha no se incluyen en la actividad del período.'));
    }
    if (d.atencion) for (const [key,label] of [['incidencias','Incidencias'],['solicitudes','Solicitudes especiales']]) {
      const a=d.atencion[key],p=section(`Atención operativa · ${label}`);
      metrics(p,'Estados actuales de intentos registrados (incluye cerrados)',Object.entries(a.estados_actuales));
      metrics(p,'Hitos durante el período',[['Aceptaciones',a.hitos_periodo.aceptados],['Inicios de atención',a.hitos_periodo.iniciados],['Cierres por finalización',a.hitos_periodo.finalizados],['Cierres por rechazo',a.hitos_periodo.rechazados],['Cierres por interrupción',a.hitos_periodo.interrumpidos]]);
      for (const [result,label] of [['finalizados','Finalizados'],['interrumpidos','Interrumpidos']]) {
        const duration=a.duracion_atencion[result];
        metrics(p,`Duración de atención · ${label}`,[['Promedio (segundos)',duration.promedio_segundos],['Intentos con fechas válidas (muestras)',duration.muestras]]);
      }
      p.append(node('p','La duración va del inicio al cierre, según cierres del período. Los hitos pueden pertenecer al mismo intento: no se suman como trabajos únicos. Sin muestras, el promedio es Sin datos.'));
    }
    if (d.recorridos) {
      const r=d.recorridos,p=section('Recorridos y contenedores');
      metrics(p,'Actividad durante el período',[['Recorridos iniciados con autor registrado',r.iniciados],['Recorridos finalizados con fecha registrada',r.finalizados],['Atenciones de contenedores registradas',r.atenciones_contenedores]]);
      metrics(p,'Diagnósticos actuales (sin filtro temporal)',[['Finalizados sin fecha registrada',r.finalizados_sin_fecha_actual],['En proceso/finalizados sin autor de inicio',r.sin_autor_inicio_actual]]);
      p.append(node('p','Los inicios sin autor no se infieren de fechas previstas. Un contenedor atendido en dos recorridos representa dos atenciones. No hay una planificación histórica para calcular cumplimiento.'));
    }
  }
  async function load() {
    request?.abort();request=new AbortController();const current=++version;
    clear();status.textContent='Consultando reporte…';el('operationalReportsRefresh').disabled=true;
    try {
      if (typeof adminSessionReady!=='undefined' && !await adminSessionReady) throw new Error('No se pudo validar la sesión.');
      if (!active || current!==version) return;
      const response=await fetch(buildApiUrl(`/backend/api/reportes_operativos.php?${new URLSearchParams(applied)}`,{cacheBust:false}),{cache:'no-store',credentials:'same-origin',signal:request.signal});
      if (!active || current!==version) return;
      if ([401,403].includes(response.status)) throw Object.assign(new Error(),{status:response.status});
      const body=await response.json();if (!active || current!==version) return;
      if (!response.ok || !body.success) throw Object.assign(new Error(),{status:response.status});
      render(body.data);status.textContent='Reporte actualizado.';
    } catch (e) {
      if (!active || current!==version || e.name==='AbortError') return;
      clear();
      status.textContent=({400:'Revisá el informe y las fechas seleccionadas.',401:'Tu sesión venció. Iniciá sesión nuevamente.',403:'No disponés de permisos para las fuentes de este informe. Podés seleccionar otro informe.',404:'El servicio de reportes no está disponible.',500:'No se pudo generar el reporte. Intentá nuevamente.',503:'El servicio está temporalmente no disponible. Intentá nuevamente.'})[e.status] || 'No se pudo consultar el reporte. Intentá nuevamente.';
      if(e.status===401)window.location.replace(buildFrontendUrl('login.html'));
    } finally {if(current===version)el('operationalReportsRefresh').disabled=false;}
  }
  function validDate(v) {
    if (!v) return true;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v) || v<'1000-01-01' || v>'9999-12-30') return false;
    const d=new Date(`${v}T00:00:00Z`);return Number.isFinite(d.getTime()) && d.toISOString().slice(0,10)===v;
  }
  el('operationalReportsFilters').addEventListener('submit',e=>{
    e.preventDefault();if(!active)return;
    const informe=el('operationalReportsType').value,desde=el('operationalReportsFrom').value,hasta=el('operationalReportsTo').value;
    if (!['solicitudes','atencion','recorridos','todos'].includes(informe) || !validDate(desde) || !validDate(hasta) || (desde && hasta && desde>hasta)) {
      version++;request?.abort();clear();el('operationalReportsRefresh').disabled=false;status.textContent='Usá fechas válidas: Desde no puede superar Hasta.';return;
    }
    applied={informe,...(desde?{desde}:{}),...(hasta?{hasta}:{})};load();
  });
  el('operationalReportsRefresh').addEventListener('click',()=>{if(active)load();});
  window.addEventListener('zemyna:session-expired',()=>{pause();status.textContent='Tu sesión venció. Iniciá sesión nuevamente.';});
  window.addEventListener('pagehide',pause);
  window.ReportesOperativos={open:()=>{active=true;return load();},pause};
})();
