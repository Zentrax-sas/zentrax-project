document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('contact-form');
  const result = document.getElementById('form-result');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    result.textContent = 'Enviando...';

    const data = new FormData(form);

    fetch(form.action, {
      method: 'POST',
      body: data,
      headers: {
        'Accept': 'application/json'
      }
    })
    .then(response => response.json())
    .then(json => {
      if (json.success) {
        result.textContent = json.message || 'Mensaje enviado correctamente.';
        form.reset();
      } else {
        result.textContent = json.message || 'Ocurrió un error al enviar.';
      }
    })
    .catch(err => {
      result.textContent = 'Error de red: ' + (err.message || err);
    });
  });
});
