const app = document.getElementById('app');
const menuButton = document.getElementById('menuButton');
const toast = document.getElementById('toast');

menuButton.addEventListener('click', () => {
  const opened = app.classList.toggle('menu-open');
  menuButton.setAttribute('aria-expanded', String(opened));
});

const titles = { resumen: 'Resumen operativo', contenedores: 'Contenedores', camiones: 'Camiones', usuarios: 'Usuarios y roles' };
function openView(viewName) {
  if (!titles[viewName]) return;
  document.querySelectorAll('.nav-link').forEach(item => item.classList.toggle('active', item.dataset.view === viewName));
  document.querySelectorAll('.view').forEach(view => view.classList.toggle('active', view.id === `view-${viewName}`));
  document.querySelector('.title-block h1').textContent = titles[viewName];
  window.scrollTo({ top: 0, behavior: 'smooth' });
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
  const rows = [...document.querySelectorAll(`#${rowsId} tr`)];
  const update = () => {
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
