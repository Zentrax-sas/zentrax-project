const app = document.getElementById('app');
const menuButton = document.getElementById('menuButton');
const toast = document.getElementById('toast');

menuButton.addEventListener('click', () => {
  const opened = app.classList.toggle('menu-open');
  menuButton.setAttribute('aria-expanded', String(opened));
});

const titles = { resumen: 'Resumen operativo', contenedores: 'Contenedores', camiones: 'Camiones', centros: 'Centros', maquinaria: 'Maquinaria', usuarios: 'Usuarios y roles' };
function openView(viewName) {
  if (!titles[viewName]) return;
  document.querySelectorAll('.nav-link').forEach(item => item.classList.toggle('active', item.dataset.view === viewName));
  document.querySelectorAll('.view').forEach(view => view.classList.toggle('active', view.id === `view-${viewName}`));
  document.querySelector('.title-block h1').textContent = titles[viewName];
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (viewName === 'usuarios') cargarUsuariosAdmin();
  if (viewName === 'contenedores') cargarContenedoresAdmin();
  if (viewName === 'camiones') cargarCamionesAdmin();
  if (viewName === 'centros') cargarCentrosAdmin();
  if (viewName === 'maquinaria') cargarMaquinariaAdmin();
}

document.querySelectorAll('.nav-link').forEach(link => {
  link.addEventListener('click', event => {
    const viewName = link.dataset.view;
    if (viewName) {
      event.preventDefault();
      openView(viewName);
      history.replaceState(null, '', `#${viewName}`);
    }
    app.classList.remove('menu-open');
    menuButton.setAttribute('aria-expanded', 'false');
  });
});

const initialView = location.hash.replace('#', '');
if (titles[initialView]) openView(initialView);

function enableTableFilter(searchId, filterId, rowsId, countId, emptyId, itemName) {
  const search = document.getElementById(searchId);
  const filter = document.getElementById(filterId);
  const update = () => {
    const rows = [...document.querySelectorAll(`#${rowsId} tr`)];
    const query = search.value.trim().toLocaleLowerCase('es');
    const status = filter.value;
    let visible = 0;
    rows.forEach(row => {
      const matchesText = row.dataset.search.includes(query);
      const matchesStatus = status === 'todos' || row.dataset.status === status;
      row.hidden = !(matchesText && matchesStatus);
      if (!row.hidden) visible += 1;
    });
    document.getElementById(countId).textContent = `${visible} ${itemName} ${visible === 1 ? 'mostrado' : 'mostrados'}`;
    document.getElementById(emptyId).style.display = visible ? 'none' : 'block';
  };
  search.addEventListener('input', update);
  filter.addEventListener('change', update);
}

enableTableFilter('containerSearch', 'containerFilter', 'containerRows', 'containerCount', 'containerEmpty', 'contenedor');
enableTableFilter('truckSearch', 'truckFilter', 'truckRows', 'truckCount', 'truckEmpty', 'camión');

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;'
  }[character]));
}

async function cargarUsuariosAdmin() {
  const rows = document.getElementById('userRows');
  const count = document.getElementById('userCount');
  const empty = document.getElementById('userEmpty');
  if (!rows || rows.dataset.loading === 'true') return;

  rows.dataset.loading = 'true';
  try {
    const response = await fetch(buildApiUrl('/backend/api/usuarios.php'));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudieron cargar usuarios.');

    const usuarios = Array.isArray(json.data) ? json.data : [];
    window.userRecords = Object.fromEntries(usuarios.map(usuario => [String(usuario.id_usuario), usuario]));
    rows.innerHTML = usuarios.map(usuario => {
      const nombre = `${usuario.nombre ?? ''} ${usuario.apellido ?? ''}`.trim();
      const iniciales = nombre.split(/\s+/).map(parte => parte[0] || '').join('').slice(0, 2).toUpperCase();
      const roles = Array.isArray(usuario.roles) ? usuario.roles : [];
      const rol = roles[0]?.nombre || 'Sin rol';
      const sector = roles[0]?.sector || 'Sin sector';
      const estado = usuario.activo || 'Inactivo';
      return `<tr><td><div class="asset-cell"><span class="avatar">${escapeHtml(iniciales)}</span><strong>${escapeHtml(nombre)}</strong></div></td><td>${escapeHtml(usuario.email)}</td><td>${escapeHtml(rol)}</td><td>${escapeHtml(sector)}</td><td>Sin registro</td><td><span class="state${estado === 'Inactivo' ? ' pause' : ''}">${escapeHtml(estado)}</span></td><td><button class="table-action" type="button" data-action="edit-user" data-id="${escapeHtml(usuario.id_usuario)}">Editar</button> <button class="table-action" type="button" data-action="delete-user" data-id="${escapeHtml(usuario.id_usuario)}">Dar de baja</button></td></tr>`;
    }).join('');
    count.textContent = `${usuarios.length} ${usuarios.length === 1 ? 'usuario mostrado' : 'usuarios mostrados'}`;
    empty.hidden = usuarios.length !== 0;
  } catch (error) {
    rows.innerHTML = '';
    count.textContent = error.message;
    empty.hidden = false;
  } finally {
    rows.dataset.loading = 'false';
  }
}

async function cargarContenedoresAdmin() {
  const rows = document.getElementById('containerRows');
  const count = document.getElementById('containerCount');
  const empty = document.getElementById('containerEmpty');
  if (!rows || rows.dataset.loading === 'true') return;

  rows.dataset.loading = 'true';
  try {
    const response = await fetch(buildApiUrl('/backend/api/contenedores.php?limit=100&page=1'));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudieron cargar contenedores.');

    const contenedores = Array.isArray(json.data) ? json.data : [];
    window.containerRecords = Object.fromEntries(contenedores.map(contenedor => [String(contenedor.id_contenedor), contenedor]));
    rows.innerHTML = contenedores.map(contenedor => {
      const estado = String(contenedor.estado || 'Desconocido');
      const estadoNormalizado = estado.toLocaleLowerCase('es');
      const estadoFiltro = estadoNormalizado === 'disponible' ? 'operativo'
        : estadoNormalizado === 'fuera de servicio' ? 'mantenimiento' : 'atencion';
      const codigo = contenedor.codigo || `CTN-${contenedor.id_contenedor}`;
      const direccion = contenedor.direccion || 'Sin dirección';
      const busqueda = `${codigo} ${direccion} ${estado}`.toLocaleLowerCase('es');
      const claseEstado = estadoFiltro === 'operativo' ? 'state' : 'priority medium';
      return `<tr data-search="${escapeHtml(busqueda)}" data-status="${estadoFiltro}"><td><div class="asset-cell"><span class="asset-icon">□</span><div><div class="asset-code">${escapeHtml(codigo)}</div><div class="asset-detail">ID ${escapeHtml(contenedor.id_contenedor)}</div></div></div></td><td>${escapeHtml(direccion)}</td><td>Sin zona</td><td>${escapeHtml(contenedor.capacidad)} L</td><td>${escapeHtml(contenedor.latitud)}, ${escapeHtml(contenedor.longitud)}</td><td><span class="${claseEstado}">${escapeHtml(estado)}</span></td><td><button class="table-action" type="button" data-action="edit-container" data-id="${escapeHtml(contenedor.id_contenedor)}">Editar</button> <button class="table-action" type="button" data-action="delete-container" data-id="${escapeHtml(contenedor.id_contenedor)}">Dar de baja</button></td></tr>`;
    }).join('');
    count.textContent = `${contenedores.length} ${contenedores.length === 1 ? 'contenedor mostrado' : 'contenedores mostrados'}`;
    empty.hidden = contenedores.length !== 0;
    document.getElementById('containerSearch')?.dispatchEvent(new Event('input'));
  } catch (error) {
    rows.innerHTML = '';
    count.textContent = error.message;
    empty.hidden = false;
  } finally {
    rows.dataset.loading = 'false';
  }
}

async function cargarCamionesAdmin() {
  const rows = document.getElementById('truckRows');
  const count = document.getElementById('truckCount');
  const empty = document.getElementById('truckEmpty');
  if (!rows || rows.dataset.loading === 'true') return;

  rows.dataset.loading = 'true';
  try {
    const response = await fetch(buildApiUrl('/backend/api/vehiculos.php'));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudieron cargar camiones.');

    const vehiculos = Array.isArray(json.data) ? json.data : [];
    window.vehicleRecords = Object.fromEntries(vehiculos.map(vehiculo => [String(vehiculo.id_vehiculo), vehiculo]));
    rows.innerHTML = vehiculos.map(vehiculo => {
      const estado = String(vehiculo.estado || 'Desconocido');
      const estadoNormalizado = estado.toLocaleLowerCase('es');
      const estadoFiltro = estadoNormalizado === 'disponible' ? 'disponible'
        : estadoNormalizado === 'en mantenimiento' ? 'mantenimiento' : 'activo';
      const matricula = vehiculo.matricula || 'Sin matrícula';
      const unidad = `VH-${vehiculo.id_vehiculo}`;
      const busqueda = `${unidad} ${matricula} ${vehiculo.marca || ''} ${vehiculo.modelo || ''} ${estado}`.toLocaleLowerCase('es');
      const claseEstado = estadoFiltro === 'mantenimiento' ? 'state pause' : 'state';
      return `<tr data-search="${escapeHtml(busqueda)}" data-status="${estadoFiltro}"><td><div class="asset-cell"><span class="asset-icon">▱</span><div><div class="asset-code">${escapeHtml(unidad)}</div><div class="asset-detail">${escapeHtml(vehiculo.marca)} ${escapeHtml(vehiculo.modelo)}</div></div></div></td><td>${escapeHtml(matricula)}</td><td>Tipo residuo #${escapeHtml(vehiculo.id_tipo_residuo)}</td><td>Sin asignar</td><td>Sin ruta</td><td><span class="${claseEstado}">${escapeHtml(estado)}</span></td><td><button class="table-action" type="button" data-action="edit-truck" data-id="${escapeHtml(vehiculo.id_vehiculo)}">Editar</button> <button class="table-action" type="button" data-action="delete-truck" data-id="${escapeHtml(vehiculo.id_vehiculo)}">Dar de baja</button></td></tr>`;
    }).join('');
    count.textContent = `${vehiculos.length} ${vehiculos.length === 1 ? 'camión mostrado' : 'camiones mostrados'}`;
    empty.hidden = vehiculos.length !== 0;
    document.getElementById('truckSearch')?.dispatchEvent(new Event('input'));
  } catch (error) {
    rows.innerHTML = '';
    count.textContent = error.message;
    empty.hidden = false;
  } finally {
    rows.dataset.loading = 'false';
  }
}

async function cargarCentrosAdmin() {
  const rows = document.getElementById('centerRows');
  const count = document.getElementById('centerCount');
  const empty = document.getElementById('centerEmpty');
  if (!rows || rows.dataset.loading === 'true') return;
  rows.dataset.loading = 'true';
  try {
    const response = await fetch(buildApiUrl('/backend/api/centros.php'));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudieron cargar centros.');
    const centros = Array.isArray(json.data) ? json.data : [];
    window.centerRecords = Object.fromEntries(centros.map(centro => [String(centro.id_centro), centro]));
    rows.innerHTML = centros.map(centro => `<tr><td><div class="asset-cell"><span class="asset-icon">⌂</span><strong>${escapeHtml(centro.nombre)}</strong></div></td><td>${escapeHtml(centro.direccion)}</td><td>${escapeHtml(centro.telefono || 'Sin teléfono')}</td><td><span class="state">Activo</span></td><td><button class="table-action" type="button" data-action="edit-center" data-id="${escapeHtml(centro.id_centro)}">Editar</button> <button class="table-action" type="button" data-action="delete-center" data-id="${escapeHtml(centro.id_centro)}">Dar de baja</button></td></tr>`).join('');
    count.textContent = `${centros.length} ${centros.length === 1 ? 'centro mostrado' : 'centros mostrados'}`;
    empty.hidden = centros.length !== 0;
  } catch (error) {
    rows.innerHTML = '';
    count.textContent = error.message;
    empty.hidden = false;
  } finally {
    rows.dataset.loading = 'false';
  }
}

async function cargarMaquinariaAdmin() {
  const rows = document.getElementById('machineRows');
  const count = document.getElementById('machineCount');
  const empty = document.getElementById('machineEmpty');
  if (!rows || rows.dataset.loading === 'true') return;
  rows.dataset.loading = 'true';
  try {
    const response = await fetch(buildApiUrl('/backend/api/maquinaria.php'));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo cargar maquinaria.');
    const maquinaria = Array.isArray(json.data) ? json.data : [];
    window.machineRecords = Object.fromEntries(maquinaria.map(recurso => [String(recurso.id_maquinaria), recurso]));
    rows.innerHTML = maquinaria.map(recurso => `<tr><td><div class="asset-cell"><span class="asset-icon">⚙</span><strong>${escapeHtml(recurso.nombre)}</strong></div></td><td>${escapeHtml(recurso.tipo)}</td><td>Centro #${escapeHtml(recurso.id_centro)}</td><td><span class="state">${escapeHtml(recurso.estado)}</span></td><td><button class="table-action" type="button" data-action="edit-machine" data-id="${escapeHtml(recurso.id_maquinaria)}">Editar</button> <button class="table-action" type="button" data-action="delete-machine" data-id="${escapeHtml(recurso.id_maquinaria)}">Dar de baja</button></td></tr>`).join('');
    count.textContent = `${maquinaria.length} ${maquinaria.length === 1 ? 'recurso mostrado' : 'recursos mostrados'}`;
    empty.hidden = maquinaria.length !== 0;
  } catch (error) {
    rows.innerHTML = '';
    count.textContent = error.message;
    empty.hidden = false;
  } finally {
    rows.dataset.loading = 'false';
  }
}

const deleteConfig = {
  'delete-user': {
    endpoint: '/backend/api/usuarios.php',
    field: 'id_usuario',
    reload: cargarUsuariosAdmin,
    label: 'usuario'
  },
  'delete-container': {
    endpoint: '/backend/api/contenedores.php',
    field: 'id_contenedor',
    reload: cargarContenedoresAdmin,
    label: 'contenedor'
  },
  'delete-truck': {
    endpoint: '/backend/api/vehiculos.php',
    field: 'id_vehiculo',
    reload: cargarCamionesAdmin,
    label: 'vehículo'
  },
  'delete-center': {
    endpoint: '/backend/api/centros.php',
    field: 'id_centro',
    reload: cargarCentrosAdmin,
    label: 'centro'
  },
  'delete-machine': {
    endpoint: '/backend/api/maquinaria.php',
    field: 'id_maquinaria',
    reload: cargarMaquinariaAdmin,
    label: 'maquinaria'
  }
};

document.addEventListener('click', async event => {
  const button = event.target.closest('[data-action]');
  if (button?.dataset.action === 'edit-center') {
    const center = window.centerRecords?.[button.dataset.id];
    if (!center) return;
    openView('centros');
    centerForm.hidden = false;
    centerForm.querySelector('[name="id_centro"]').value = center.id_centro;
    centerForm.querySelector('[name="nombre"]').value = center.nombre || '';
    centerForm.querySelector('[name="direccion"]').value = center.direccion || '';
    centerForm.querySelector('[name="telefono"]').value = center.telefono || '';
    centerForm.querySelector('button[type="submit"]').textContent = 'Actualizar centro';
    centerForm.querySelector('[name="nombre"]').focus();
    return;
  }
  if (button?.dataset.action === 'edit-machine') {
    const machine = window.machineRecords?.[button.dataset.id];
    if (!machine) return;
    openView('maquinaria');
    machineForm.hidden = false;
    machineForm.querySelector('[name="id_maquinaria"]').value = machine.id_maquinaria;
    machineForm.querySelector('[name="nombre"]').value = machine.nombre || '';
    machineForm.querySelector('[name="tipo"]').value = machine.tipo || '';
    machineForm.querySelector('[name="estado"]').value = machine.estado || 'Disponible';
    machineForm.querySelector('[name="id_centro"]').value = machine.id_centro || '';
    machineForm.querySelector('button[type="submit"]').textContent = 'Actualizar maquinaria';
    machineForm.querySelector('[name="nombre"]').focus();
    return;
  }
  if (button?.dataset.action === 'edit-user') {
    const user = window.userRecords?.[button.dataset.id];
    if (!user) return;
    openView('usuarios');
    userForm.hidden = false;
    userForm.querySelector('[name="contrasena"]').required = false;
    userForm.querySelector('[name="id_usuario"]').value = user.id_usuario;
    userForm.querySelector('[name="nombre"]').value = user.nombre || '';
    userForm.querySelector('[name="apellido"]').value = user.apellido || '';
    userForm.querySelector('[name="email"]').value = user.email || '';
    userForm.querySelector('[name="contrasena"]').value = '';
    userForm.querySelector('[name="telefono"]').value = user.telefono || '';
    userForm.querySelector('[name="id_centro"]').value = user.id_centro || '';
    userForm.querySelector('button[type="submit"]').textContent = 'Actualizar usuario';
    userForm.querySelector('[name="nombre"]').focus();
    return;
  }
  if (button?.dataset.action === 'edit-container') {
    const container = window.containerRecords?.[button.dataset.id];
    if (!container) return;
    openView('contenedores');
    containerForm.hidden = false;
    containerForm.querySelector('[name="id_contenedor"]').value = container.id_contenedor;
    containerForm.querySelector('[name="codigo"]').value = container.codigo || '';
    containerForm.querySelector('[name="capacidad"]').value = container.capacidad || '';
    containerForm.querySelector('[name="direccion"]').value = container.direccion || '';
    containerForm.querySelector('[name="latitud"]').value = container.latitud || '';
    containerForm.querySelector('[name="longitud"]').value = container.longitud || '';
    containerForm.querySelector('[name="estado"]').value = container.estado || 'Disponible';
    containerForm.querySelector('[name="id_tipo_residuo"]').value = container.id_tipo_residuo || '';
    containerForm.querySelector('[name="id_ruta"]').value = container.id_ruta || '';
    containerForm.querySelector('button[type="submit"]').textContent = 'Actualizar contenedor';
    containerForm.querySelector('[name="codigo"]').focus();
    return;
  }
  if (button?.dataset.action === 'edit-truck') {
    const vehicle = window.vehicleRecords?.[button.dataset.id];
    if (!vehicle) return;
    openView('camiones');
    truckForm.hidden = false;
    truckForm.querySelector('[name="id_vehiculo"]').value = vehicle.id_vehiculo;
    truckForm.querySelector('[name="matricula"]').value = vehicle.matricula || '';
    truckForm.querySelector('[name="marca"]').value = vehicle.marca || '';
    truckForm.querySelector('[name="modelo"]').value = vehicle.modelo || '';
    truckForm.querySelector('[name="capacidad_carga"]').value = vehicle.capacidad_carga || '';
    truckForm.querySelector('[name="estado"]').value = vehicle.estado || 'Disponible';
    truckForm.querySelector('[name="id_tipo_residuo"]').value = vehicle.id_tipo_residuo || '';
    truckForm.querySelector('button[type="submit"]').textContent = 'Actualizar vehículo';
    truckForm.querySelector('[name="matricula"]').focus();
    return;
  }
  const config = button ? deleteConfig[button.dataset.action] : null;
  if (!config || button.disabled) return;
  if (!window.confirm(`¿Confirmás la baja lógica del ${config.label}?`)) return;

  button.disabled = true;
  button.textContent = 'Procesando...';
  try {
    const response = await fetch(buildApiUrl(config.endpoint), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ [config.field]: Number(button.dataset.id) })
    });
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || `No se pudo dar de baja el ${config.label}.`);
    showToast(`${config.label[0].toUpperCase()}${config.label.slice(1)} dado de baja.`, 'El registro se conservó en la base.');
    await config.reload();
  } catch (error) {
    button.disabled = false;
    button.textContent = 'Dar de baja';
    showToast('No se pudo completar la baja.', error.message);
  }
});

const newContainerButton = document.getElementById('newContainerButton');
const cancelContainerButton = document.getElementById('cancelContainerButton');
const containerForm = document.getElementById('containerForm');
const containerFormMessage = document.getElementById('containerFormMessage');

newContainerButton?.addEventListener('click', () => {
  containerForm.reset();
  containerForm.querySelector('[name="id_contenedor"]').value = '';
  containerForm.querySelector('button[type="submit"]').textContent = 'Guardar contenedor';
  containerForm.hidden = false;
  containerForm.querySelector('input')?.focus();
});

cancelContainerButton?.addEventListener('click', () => {
  containerForm.reset();
  containerForm.hidden = true;
  containerFormMessage.textContent = '';
  containerForm.querySelector('button[type="submit"]').textContent = 'Guardar contenedor';
});

containerForm?.addEventListener('submit', async event => {
  event.preventDefault();
  containerFormMessage.textContent = 'Guardando contenedor...';
  const payload = Object.fromEntries(new FormData(containerForm).entries());
  const idContenedor = payload.id_contenedor;
  const method = idContenedor ? 'PUT' : 'POST';
  delete payload.id_contenedor;
  payload.capacidad = Number(payload.capacidad);
  payload.latitud = Number(payload.latitud);
  payload.longitud = Number(payload.longitud);
  payload.id_tipo_residuo = Number(payload.id_tipo_residuo);
  payload.id_ruta = Number(payload.id_ruta);

  try {
    const response = await fetch(buildApiUrl('/backend/api/contenedores.php'), {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo registrar el contenedor.');
    containerForm.reset();
    containerForm.hidden = true;
    containerFormMessage.textContent = '';
    showToast(idContenedor ? 'Contenedor actualizado.' : 'Contenedor registrado.', 'La lista se actualizó correctamente.');
    await cargarContenedoresAdmin();
  } catch (error) {
    containerFormMessage.textContent = error.message;
  }
});

function setupCreateForm(formId, messageId, endpoint, successTitle, reload) {
  const form = document.getElementById(formId);
  const message = document.getElementById(messageId);
  const cancelButton = form?.querySelector('[id^="cancel"]');
  const openButtonId = formId === 'centerForm' ? 'newCenterButton' : 'newMachineButton';
  const openButton = document.getElementById(openButtonId);
  const idField = form?.querySelector('input[type="hidden"]');
  const submitButton = form?.querySelector('button[type="submit"]');

  openButton?.addEventListener('click', () => {
    form.reset();
    idField.value = '';
    submitButton.textContent = formId === 'centerForm' ? 'Guardar centro' : 'Guardar maquinaria';
    form.hidden = false;
    form.querySelector('input')?.focus();
  });

  cancelButton?.addEventListener('click', () => {
    form.reset();
    idField.value = '';
    form.hidden = true;
    message.textContent = '';
    submitButton.textContent = formId === 'centerForm' ? 'Guardar centro' : 'Guardar maquinaria';
  });

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    message.textContent = 'Guardando...';
    const payload = Object.fromEntries(new FormData(form).entries());
    const recordId = payload[idField.name];
    const method = recordId ? 'PUT' : 'POST';
    delete payload[idField.name];
    if (payload.id_centro) payload.id_centro = Number(payload.id_centro);

    try {
      const response = await fetch(buildApiUrl(endpoint), {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const json = await response.json();
      if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo guardar el registro.');
      form.reset();
      idField.value = '';
      form.hidden = true;
      message.textContent = '';
      showToast(recordId ? successTitle.replace('registrad', 'actualizad') : successTitle, 'La lista se actualizó correctamente.');
      submitButton.textContent = formId === 'centerForm' ? 'Guardar centro' : 'Guardar maquinaria';
      await reload();
    } catch (error) {
      message.textContent = error.message;
    }
  });
}

setupCreateForm('centerForm', 'centerFormMessage', '/backend/api/centros.php', 'Centro registrado.', cargarCentrosAdmin);
setupCreateForm('machineForm', 'machineFormMessage', '/backend/api/maquinaria.php', 'Maquinaria registrada.', cargarMaquinariaAdmin);

const newUserButton = document.getElementById('newUserButton');
const cancelUserButton = document.getElementById('cancelUserButton');
const userForm = document.getElementById('userForm');
const userFormMessage = document.getElementById('userFormMessage');

newUserButton?.addEventListener('click', () => {
  userForm.reset();
  userForm.querySelector('[name="id_usuario"]').value = '';
  userForm.querySelector('[name="contrasena"]').required = true;
  userForm.querySelector('button[type="submit"]').textContent = 'Guardar usuario';
  userForm.hidden = false;
  userForm.querySelector('input')?.focus();
});

cancelUserButton?.addEventListener('click', () => {
  userForm.reset();
  userForm.hidden = true;
  userFormMessage.textContent = '';
  userForm.querySelector('[name="contrasena"]').required = true;
  userForm.querySelector('button[type="submit"]').textContent = 'Guardar usuario';
});

userForm?.addEventListener('submit', async event => {
  event.preventDefault();
  userFormMessage.textContent = 'Guardando usuario...';
  const payload = Object.fromEntries(new FormData(userForm).entries());
  const idUsuario = payload.id_usuario;
  const method = idUsuario ? 'PUT' : 'POST';
  delete payload.id_usuario;
  if (idUsuario && payload.contrasena === '') delete payload.contrasena;
  payload.id_centro = Number(payload.id_centro);

  try {
    const response = await fetch(buildApiUrl('/backend/api/usuarios.php'), {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo registrar el usuario.');
    userForm.reset();
    userForm.hidden = true;
    userFormMessage.textContent = '';
    showToast(idUsuario ? 'Usuario actualizado.' : 'Usuario registrado.', 'La lista se actualizó correctamente.');
    await cargarUsuariosAdmin();
  } catch (error) {
    userFormMessage.textContent = error.message;
  }
});

const newTruckButton = document.getElementById('newTruckButton');
const cancelTruckButton = document.getElementById('cancelTruckButton');
const truckForm = document.getElementById('truckForm');
const truckFormMessage = document.getElementById('truckFormMessage');

newTruckButton?.addEventListener('click', () => {
  truckForm.reset();
  truckForm.querySelector('[name="id_vehiculo"]').value = '';
  truckForm.querySelector('button[type="submit"]').textContent = 'Guardar vehículo';
  truckForm.hidden = false;
  truckForm.querySelector('input')?.focus();
});

cancelTruckButton?.addEventListener('click', () => {
  truckForm.reset();
  truckForm.hidden = true;
  truckFormMessage.textContent = '';
  truckForm.querySelector('button[type="submit"]').textContent = 'Guardar vehículo';
});

truckForm?.addEventListener('submit', async event => {
  event.preventDefault();
  truckFormMessage.textContent = 'Guardando vehículo...';
  const payload = Object.fromEntries(new FormData(truckForm).entries());
  const idVehiculo = payload.id_vehiculo;
  const method = idVehiculo ? 'PUT' : 'POST';
  delete payload.id_vehiculo;
  payload.capacidad_carga = Number(payload.capacidad_carga);
  payload.id_tipo_residuo = Number(payload.id_tipo_residuo);

  try {
    const response = await fetch(buildApiUrl('/backend/api/vehiculos.php'), {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'No se pudo registrar el vehículo.');
    truckForm.reset();
    truckForm.hidden = true;
    truckFormMessage.textContent = '';
    showToast(idVehiculo ? 'Vehículo actualizado.' : 'Vehículo registrado.', 'La lista se actualizó correctamente.');
    await cargarCamionesAdmin();
  } catch (error) {
    truckFormMessage.textContent = error.message;
  }
});

document.querySelectorAll('.table-action, .demo-action').forEach(button => {
  button.addEventListener('click', () => showToast('Función demostrativa.', 'Se conectará al módulo correspondiente cuando las API estén habilitadas.'));
});

function showToast(title, detail = '') {
  toast.textContent = '';

  const titleNode = document.createElement('strong');
  titleNode.textContent = title;
  toast.appendChild(titleNode);

  if (detail) {
    toast.appendChild(document.createTextNode(` ${detail}`));
  }

  toast.classList.add('show');
  window.clearTimeout(window.toastTimer);
  window.toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2600);
}

document.getElementById('refreshButton').addEventListener('click', event => {
  const button = event.currentTarget;
  button.textContent = '↻ Actualizando…';
  button.disabled = true;
  window.setTimeout(() => {
    button.textContent = '✓ Información actualizada';
    showToast('Información actualizada.', 'Se sincronizaron rutas, flota e incidencias.');
    window.setTimeout(() => {
      button.textContent = '↻ Actualizar información';
      button.disabled = false;
    }, 1400);
  }, 700);
});

document.getElementById('notificationButton').addEventListener('click', () => {
  showToast('3 alertas operativas.', 'Hay incidencias prioritarias pendientes de revisión.');
});
