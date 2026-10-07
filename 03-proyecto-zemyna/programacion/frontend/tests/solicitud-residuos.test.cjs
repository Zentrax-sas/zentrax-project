const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '../public');
const html = fs.readFileSync(path.join(root, 'solicitud.html'), 'utf8');
class Element {
  constructor() { this.value = ''; this.textContent = ''; this.children = []; this.listeners = {}; this.style = {}; this.disabled = false; }
  addEventListener(n, f) { this.listeners[n] = f; }
  replaceChildren(...children) { this.children = children; }
  append(...children) { this.children.push(...children); }
  appendChild(child) { this.children.push(child); }
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const catalog = [
  { id_tipo_residuo:27,nombre:'Papel y cartón' }, { id_tipo_residuo:38,nombre:'Plástico' },
  { id_tipo_residuo:41,nombre:'Vidrio' }, { id_tipo_residuo:42,nombre:'Metal' },
  { id_tipo_residuo:49,nombre:'Residuos voluminosos' }, { id_tipo_residuo:51,nombre:'Electrónicos' },
  { id_tipo_residuo:52,nombre:'Pilas y baterías' }, { id_tipo_residuo:53,nombre:'Escombros' }
];
function harness() {
  const elements = new Map(), requests = [], submit = new Element();
  for (const m of html.matchAll(/\bid="([^"]+)"/g)) elements.set(m[1], new Element());
  const form = elements.get('solicitudForm'); form.querySelector = () => submit;
  form.reset = () => { elements.get('tipo_solicitud').value = ''; };
  elements.get('acepta_prueba').checked = true;
  for (const [id,value] of Object.entries({direccion:'Test',email:'test@test.invalid',telefono:'099111111',descripcion:'Papel y vidrio',captcha_respuesta:'7'})) elements.get(id).value = value;
  const context = vm.createContext({ console, AbortController, buildApiUrl: p => p,
    window: { location: { protocol:'http:' }, addEventListener() {} },
    document: { getElementById: id => elements.get(id), createElement: () => new Element(), createTextNode: text => ({textContent:text}), querySelectorAll: () => [] },
    fetch: (url,options={}) => new Promise((resolve,reject) => requests.push({url,options,resolve,reject})) });
  vm.runInContext(fs.readFileSync(path.join(root,'assets/js/solicitud-residuos.js'),'utf8'), context);
  for (const m of html.matchAll(/<script>([\s\S]*?)<\/script>/g)) vm.runInContext(m[1],context);
  return {elements,requests,submit,
    reply(index,data,status=200){requests[index].resolve({ok:status<300,status,json:async()=>data});},
    service(name){elements.get('tipo_solicitud').value=name;elements.get('tipo_solicitud').listeners.change();},
    send(){return vm.runInContext('handleSubmit({preventDefault(){}})',context);}
  };
}
async function ready() {const h=harness();h.reply(0,{success:true,data:catalog});h.reply(1,{success:true,data:{pregunta:'3+4'}});await tick();return h;}
test('Reciclables ofrece catálogo explícito con IDs reales y envía el elegido sin inferencia',async()=>{
  const h=await ready();h.service('Reciclables');const select=h.elements.get('id_tipo_residuo');
  assert.deepEqual(select.children.map(o=>o.textContent),['Seleccionar residuo principal…','Papel y cartón','Plástico','Vidrio','Metal']);
  await h.send();assert.equal(h.requests.length,2);
  select.value='38';const pending=h.send();assert.equal(h.requests[2].url,'/backend/api/solicitud.php');
  const body=JSON.parse(h.requests[2].options.body);
  assert.equal(body.id_tipo_residuo,38);assert.equal(body.tipo_solicitud,'Reciclables');
  assert.deepEqual(Object.keys(body).sort(),['captcha_respuesta','descripcion','direccion','email','id_tipo_residuo','telefono','tipo_solicitud']);
  h.reply(2,{success:true,tracking_number:'REF-2026-ABCDE'});await pending;
  assert.equal(select.disabled,true);
});
test('Gran volumen obtiene Residuos voluminosos del catálogo y cambiar servicio limpia selección',async()=>{
  const h=await ready();h.service('Gran volumen');assert.equal(h.elements.get('id_tipo_residuo').value,'49');
  const pending=h.send();assert.equal(JSON.parse(h.requests[2].options.body).id_tipo_residuo,49);
  h.reply(2,{success:false,message:'Test'},400);await pending;
  h.service('Reciclables');assert.equal(h.elements.get('id_tipo_residuo').value,'');
});
test('catálogo fallido o clasificación ambigua no permite fallback ni envío silencioso',async()=>{
  const h=harness();h.requests[0].reject(new Error('Red'));h.reply(1,{success:true,data:{pregunta:'3+4'}});await tick();
  h.service('Gran volumen');await h.send();assert.equal(h.requests.length,2);
  h.elements.get('residueRetry').listeners.click();
  h.reply(2,{success:true,data:[...catalog,{id_tipo_residuo:99,nombre:'Residuos voluminosos'}]});await tick();
  await h.send();assert.equal(h.requests.length,3);assert.equal(h.elements.get('id_tipo_residuo').disabled,true);
});
