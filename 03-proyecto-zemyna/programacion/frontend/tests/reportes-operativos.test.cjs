const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const base=path.join(__dirname,'../public');
const html=fs.readFileSync(path.join(base,'admin.html'),'utf8');
const source=fs.readFileSync(path.join(base,'assets/js/reportes-operativos.js'),'utf8');
const admin=fs.readFileSync(path.join(base,'assets/js/admin.js'),'utf8');
const router=admin.slice(admin.indexOf('const titles ='),admin.indexOf('function enableTableFilter('));
class Element {
  constructor(id=''){this.id=id;this.children=[];this.listeners={};this.textContent='';this.value='';this.dataset={};this.classes=new Set();this.classList={toggle:(c,on)=>on?this.classes.add(c):this.classes.delete(c),remove:c=>this.classes.delete(c)};}
  append(...n){this.children.push(...n);} replaceChildren(...n){this.children=n;}
  addEventListener(k,f){this.listeners[k]=f;} setAttribute(k,v){this[k]=v;}
  set innerHTML(_){throw new Error('HTML inseguro');}
}
const text=n=>[n.textContent,...n.children.map(text)].join(' ');
const tick=async()=>{for(let i=0;i<4;i++)await new Promise(r=>setImmediate(r));};
function data(type='todos') {
  const attempt={estados_actuales:{Asignada:0,Aceptada:1,'En atención':0,Finalizada:1,Rechazada:0,Interrumpida:1},hitos_periodo:{aceptados:2,iniciados:1,finalizados:1,rechazados:0,interrumpidos:1},duracion_atencion:{finalizados:{muestras:1,promedio_segundos:1200},interrumpidos:{muestras:0,promedio_segundos:null}}};
  return {informe:type,zona_horaria:'America/Montevideo',periodo:{desde:null,hasta:null},generado_en:'2026-10-10 10:00:00',
    ...(['solicitudes','todos'].includes(type)?{solicitudes:{estados_actuales:{Pendiente:2,Programada:1,'En atención':0,Finalizada:1,Cancelada:0},periodo:{creadas:2,finalizadas:1},pendientes_clasificacion_historica_actual:2,finalizadas_sin_fecha_actual:1}}:{}),
    ...(['atencion','todos'].includes(type)?{atencion:{incidencias:structuredClone(attempt),solicitudes:structuredClone(attempt)}}:{}),
    ...(['recorridos','todos'].includes(type)?{recorridos:{iniciados:1,finalizados:1,atenciones_contenedores:3,finalizados_sin_fecha_actual:1,sin_autor_inicio_actual:2}}:{})};
}
function boot(hash='#reportes') {
  const nodes=new Map();for(const m of html.matchAll(/\bid="([^"]+)"/g))nodes.set(m[1],new Element(m[1]));
  nodes.get('operationalReportsType').value='solicitudes';nodes.get('operationalReportsData').hidden=true;
  const views=[...html.matchAll(/<section class="view[^"\n]*" id="([^"]+)"/g)].map(m=>nodes.get(m[1]));
  const links=[...html.matchAll(/<a class="nav-link[^"\n]*"[^>]*data-view="([^"]+)"/g)].map(m=>{const n=new Element();n.dataset.view=m[1];return n;});
  const requests=[],redirects=[],events={},modules=[],location={hash,replace:u=>redirects.push(u)},title=new Element();
  const module=name=>({open:()=>modules.push(name),load:()=>modules.push(name),pause(){}});
  const context=vm.createContext({document:{getElementById:id=>nodes.get(id),createElement:tag=>new Element(tag),querySelectorAll:s=>s==='.nav-link'?links:views,querySelector:()=>title},
    window:{addEventListener:(k,f)=>events[k]=f,scrollTo(){},location,Dashboard:module('resumen'),SolicitudesAdmin:module('solicitudes'),SquadAdmin:module('cuadrillas'),IncidenceReport:module('informe-incidencias')},
    location,history:{replaceState:(_,__,v)=>location.hash=v},app:new Element(),menuButton:new Element(),operationalMapVersion:0,operationalMap:null,
    adminSessionReady:Promise.resolve(true),AbortController,URLSearchParams,buildApiUrl:(u,options)=>{assert.equal(options.cacheBust,false);return u;},buildFrontendUrl:u=>u,
    fetch:(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}))});
  vm.runInContext(router,context);vm.runInContext(source,context);events.DOMContentLoaded();
  const click=view=>links.find(n=>n.dataset.view===view).listeners.click({preventDefault(){}});
  const reply=(i,d=data(),code=200)=>requests[i].resolve({status:code,ok:code===200,json:async()=>({success:code===200,data:d,message:'<img onerror=x>'})});
  return {nodes,requests,redirects,events,modules,location,title,click,reply,output:()=>nodes.get('operationalReportsData'),submit:()=>nodes.get('operationalReportsFilters').listeners.submit({preventDefault(){}})};
}
async function ready(type='solicitudes'){const h=boot();await tick();h.reply(0,data(type));await tick();return h;}
test('router real abre #reportes directamente, por menú y conserva otros módulos',async()=>{
  const h=boot();await tick();assert.equal(h.title.textContent,'Reportes operativos');assert.equal(h.nodes.get('view-reportes').classes.has('active'),true);
  assert.match(html,/admin\.js\?v=9/);assert.match(html,/reportes-operativos\.js\?v=1/);
  for(const view of ['solicitudes','cuadrillas','informe-incidencias','resumen']){h.click(view);assert.equal(h.location.hash,'#'+view);assert.equal(h.nodes.get('view-reportes').classes.has('active'),false);assert.ok(h.modules.includes(view));}
  h.click('reportes');await tick();assert.equal(h.location.hash,'#reportes');assert.equal(h.requests.length,2);
});
test('filtros utilizan contrato real sin período arbitrario ni paginación',async()=>{
  const h=await ready();assert.equal(h.requests[0].url,'/backend/api/reportes_operativos.php?informe=solicitudes');
  h.nodes.get('operationalReportsType').value='atencion';h.nodes.get('operationalReportsFrom').value='2026-02-01';h.nodes.get('operationalReportsTo').value='2026-02-28';h.submit();await tick();
  const q=new URL(h.requests[1].url,'http://test').searchParams;assert.deepEqual([...q.keys()],['informe','desde','hasta']);assert.equal(q.get('desde'),'2026-02-01');assert.equal(q.get('informe'),'atencion');
  assert.equal(h.requests[1].options.cache,'no-store');assert.equal(h.requests[1].options.credentials,'same-origin');
});
test('fechas inválidas y orden inverso se rechazan antes de consultar',async()=>{
  const h=await ready();for(const [from,to] of [['2026-02-30',''],['2026-03-02','2026-03-01'],['','9999-12-31'],['0999-01-01',''],['2026-01-01T00:00','']]){
    h.nodes.get('operationalReportsFrom').value=from;h.nodes.get('operationalReportsTo').value=to;h.submit();await tick();assert.equal(h.requests.length,1);assert.match(h.nodes.get('operationalReportsStatus').textContent,/Usá fechas válidas/);
  }
  h.nodes.get('operationalReportsFrom').value='2024-02-29';h.nodes.get('operationalReportsTo').value='';h.submit();await tick();assert.equal(h.requests.length,2);
});
test('representa tres informes y vista general, diferenciando estados e hitos',async()=>{
  for(const type of ['solicitudes','atencion','recorridos','todos']){
    const h=boot();h.nodes.get('operationalReportsType').value=type;h.submit();await tick();h.reply(h.requests.length-1,data(type));await tick();
    const t=text(h.output());assert.match(t,/America\/Montevideo/);
    if(type==='solicitudes'||type==='todos'){assert.match(t,/Estados actuales/);assert.match(t,/Solicitudes creadas/);assert.match(t,/Finalizadas sin fecha registrada/);}
    if(type==='atencion'||type==='todos'){assert.match(t,/Atención operativa · Incidencias/);assert.match(t,/Atención operativa · Solicitudes especiales/);assert.match(t,/Promedio \(segundos\).*1200/);assert.match(t,/muestras/);assert.match(t,/Cierres por rechazo/);assert.match(t,/Cierres por interrupción/);assert.match(t,/Sin datos/);}
    if(type==='recorridos'||type==='todos'){assert.match(t,/Atenciones de contenedores registradas/);assert.match(t,/sin autor de inicio/);assert.match(t,/No hay una planificación histórica/);}
  }
});
test('cero es conteo real; NULL y duración sin muestras son Sin datos',async()=>{
  const h=boot();await tick();const d=data('todos');d.solicitudes.periodo={creadas:0,finalizadas:0};d.atencion.incidencias.duracion_atencion.finalizados={muestras:0,promedio_segundos:null};h.reply(0,d);await tick();
  const t=text(h.output());assert.match(t,/Solicitudes creadas 0/);assert.match(t,/Promedio \(segundos\) Sin datos/);assert.match(t,/Finalizadas sin fecha registrada 1/);
});
test('errores HTTP limpian resultados previos, con mensajes seguros y 401 a login',async()=>{
  for(const code of [400,401,403,404,500,503]){
    const h=await ready();h.nodes.get('operationalReportsRefresh').listeners.click();await tick();h.reply(1,null,code);await tick();assert.equal(h.output().hidden,true);assert.equal(h.output().children.length,0);assert.doesNotMatch(h.nodes.get('operationalReportsStatus').textContent,/<img/);
    assert.equal(h.redirects.length,code===401?1:0);if(code===401)assert.equal(h.redirects[0],'login.html');if(code===403)assert.match(h.nodes.get('operationalReportsStatus').textContent,/permisos/);
    assert.equal(h.nodes.get('operationalReportsRefresh').disabled,false);
  }
});
test('descarta respuestas viejas al cambiar filtros y navegar',async()=>{
  const h=boot();await tick();h.nodes.get('operationalReportsType').value='recorridos';h.submit();await tick();assert.equal(h.requests[0].options.signal.aborted,true);
  h.reply(1,data('recorridos'));await tick();h.reply(0,data('solicitudes'));await tick();assert.doesNotMatch(text(h.output()),/Solicitudes creadas/);
  h.nodes.get('operationalReportsRefresh').listeners.click();await tick();h.click('resumen');h.reply(2,data('todos'));await tick();assert.equal(h.output().hidden,true);assert.equal(h.output().children.length,0);
});
test('denegación de vista general permite otro informe autorizado sin inferir roles',async()=>{
  const h=boot();h.nodes.get('operationalReportsType').value='todos';h.submit();await tick();h.reply(h.requests.length-1,null,403);await tick();assert.equal(h.output().hidden,true);
  h.nodes.get('operationalReportsType').value='solicitudes';h.submit();await tick();h.reply(h.requests.length-1,data('solicitudes'));await tick();assert.equal(h.output().hidden,false);
});
test('sesión expirada borra datos y descarta consultas pendientes',async()=>{
  const h=await ready();h.nodes.get('operationalReportsRefresh').listeners.click();await tick();h.events['zemyna:session-expired']();h.reply(1,data('todos'));await tick();assert.equal(h.output().hidden,true);assert.equal(h.output().children.length,0);assert.match(h.nodes.get('operationalReportsStatus').textContent,/sesión venció/);
});
test('renderiza texto seguro y reutiliza reglas responsive existentes',async()=>{
  const h=boot();await tick();const d=data('solicitudes');d.generado_en='<img onerror=x>';d.solicitudes.estados_actuales={'<script>x</script>':1};h.reply(0,d);await tick();assert.match(text(h.output()),/<script>x<\/script>/);assert.match(text(h.output()),/<img onerror=x>/);
  const css=fs.readFileSync(path.join(base,'assets/css/admin.css'),'utf8');assert.match(css,/\.incident-filters \{[^}]*flex-wrap: wrap/);assert.match(css,/@media \(max-width: 600px\) \{ \.incident-detail-fields \{ grid-template-columns: 1fr/);
  assert.match(html,/id="view-reportes"/);assert.doesNotMatch(source,/localStorage|sessionStorage|innerHTML/);
});
