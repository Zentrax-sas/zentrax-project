document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.innerHTML = open ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-bars"></i>';
    });
  }

  const elements = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    elements.forEach((element) => observer.observe(element));
  } else {
    elements.forEach((element) => element.classList.add('visible'));
  }

  const form = document.getElementById('contact-form');
  const result = document.getElementById('form-result');
  if (!form || !result) return;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    result.textContent = 'Enviando mensaje...';
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('No fue posible enviar el mensaje.');
      const data = await response.json();
      result.textContent = data.message || '¡Gracias! Recibimos tu mensaje.';
      form.reset();
    } catch (error) {
      result.textContent = 'No pudimos enviarlo. Escribinos por WhatsApp.';
    }
  });
});
