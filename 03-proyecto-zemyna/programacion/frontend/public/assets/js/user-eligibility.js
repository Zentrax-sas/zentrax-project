/* Orientación basada en permisos del catálogo; el backend decide la elegibilidad real. */
(() => {
  const warning = 'Este usuario podrá guardarse, pero no será elegible para integrar cuadrillas porque no tiene permisos para operar recorridos en el sector Operaciones.';
  function evaluate(role, sector, from, until, today) {
    const permissions = role?.permisos_recorrido || [];
    const operative = permissions.includes('recorrido.consultar') && (permissions.includes('recorrido.operar') || permissions.includes('recorrido.modificar'));
    return { operative, eligible: operative && sector === 'OPERACIONES' && Boolean(from) && from <= today && (!until || until >= today) };
  }
  function bind(form, message, roles) {
    const field = name => form.querySelector(`[name="${name}"]`);
    function render(suggest = false) {
      const role = roles().find(r => String(r.id_rol) === field('id_rol').value);
      const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Montevideo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      let result = evaluate(role, field('sector').value, field('fecha_desde').value, field('fecha_hasta').value, today);
      if (suggest && result.operative && !field('sector').value) {
        field('sector').value = 'OPERACIONES';
        result = evaluate(role, field('sector').value, field('fecha_desde').value, field('fecha_hasta').value, today);
      }
      message.hidden = !role || result.eligible;
      message.textContent = !role || result.eligible ? '' : warning + (result.operative ? ' Para integrar cuadrillas, elegí Operaciones y una asignación vigente.' : '');
      return result;
    }
    field('id_rol').addEventListener('change', () => render(true));
    for (const name of ['sector', 'fecha_desde', 'fecha_hasta']) field(name).addEventListener('change', () => render());
    return render;
  }
  window.UserEligibility = { evaluate, bind };
})();
