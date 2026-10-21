import { ChangeDetectorRef, ElementRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { vi } from 'vitest';
import { BuscarContenedorPage } from './buscar-contenedor.page';

describe('Autocompletado de calle y esquina', () => {
  let page: BuscarContenedorPage;
  let http: HttpTestingController;
  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({ providers: [
      provideHttpClient(), provideHttpClientTesting(),
      { provide: ChangeDetectorRef, useValue: { markForCheck: vi.fn() } },
      { provide: Router, useValue: { navigate: vi.fn() } },
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: { get: () => 'calle' } } } },
    ] });
    http = TestBed.inject(HttpTestingController);
    page = TestBed.runInInjectionContext(() => new BuscarContenedorPage());
  });
  afterEach(() => { page.ngOnDestroy(); http.verify(); vi.useRealTimers(); });
  const streets = () => http.expectOne(r => r.params.get('view') === 'streets');

  it.each(['Mig', 'Miguel Mart', 'Martinez', 'Martínez', 'Est'])('consulta %s después de 300 ms y muestra la respuesta real', q => {
    page.actualizar('calle', q);
    vi.advanceTimersByTime(299);
    http.expectNone(() => true);
    expect(page.sugerenciasRealizadas).toBe(false);
    vi.advanceTimersByTime(1);
    const req = streets();
    expect(req.request.params.get('q')).toBe(q);
    req.flush({ success: true, data: ['Nombre devuelto por el servidor'] });
    expect(page.sugerencias).toEqual(['Nombre devuelto por el servidor']);
    expect(page.sugerenciasCargando).toBe(false);
  });

  it.each(['', 'M', '   ', 'a'.repeat(81)])('no consulta texto fuera de límites: %s', q => {
    page.actualizar('calle', q); vi.advanceTimersByTime(350);
    http.expectNone(() => true);
    expect(page.campoSugerencias).toBeNull();
  });

  it('reinicia el debounce y cancela inmediatamente la petición anterior', () => {
    page.actualizar('calle', 'Mi'); vi.advanceTimersByTime(200);
    page.actualizar('calle', 'Mig'); vi.advanceTimersByTime(300);
    const anterior = streets();
    page.actualizar('calle', 'Miguel Mart');
    expect(anterior.cancelled).toBe(true);
    vi.advanceTimersByTime(300);
    expect(streets().request.params.get('q')).toBe('Miguel Mart');
  });

  it('selecciona calle y esquina y busca con parámetros separados', () => {
    page.actualizar('calle', 'Mig'); vi.advanceTimersByTime(300);
    streets().flush({ success: true, data: ['Miguel Martínez'] });
    page.seleccionarSugerencia('calle', page.sugerencias[0]);
    expect(page.calle).toBe('Miguel Martínez');
    expect(page.campoSugerencias).toBeNull();
    page.actualizar('esquina', 'Est'); vi.advanceTimersByTime(300);
    const esquina = streets();
    expect(esquina.request.params.get('calle')).toBe('Miguel Martínez');
    esquina.flush({ success: true, data: ['Estación'] });
    page.seleccionarSugerencia('esquina', page.sugerencias[0]);
    expect(page.esquina).toBe('Estación');
    expect(page.sugerencias).toEqual([]);
    page.buscar();
    const final = http.expectOne(r => r.params.get('view') === 'search');
    expect(final.request.params.get('calle')).toBe('Miguel Martínez');
    expect(final.request.params.get('esquina')).toBe('Estación');
    expect(final.request.params.has('q')).toBe(false);
    final.flush({ success: true, data: [] });
    expect(page.realizada).toBe(true);
  });

  it('descarta el contexto seleccionado cuando se edita la calle', () => {
    page.seleccionarSugerencia('calle', 'Av. Brasil');
    page.actualizar('calle', 'Brito'); page.actualizar('esquina', 'Est');
    vi.advanceTimersByTime(300);
    expect(streets().request.params.has('calle')).toBe(false);
  });

  it('distingue una respuesta vacía de un error y limita las opciones a diez', () => {
    page.actualizar('calle', 'zz'); vi.advanceTimersByTime(300);
    streets().flush({ success: true, data: [] });
    expect(page.sugerenciasRealizadas).toBe(true);
    expect(page.sugerencias).toEqual([]);
    page.actualizar('calle', 'Mi'); vi.advanceTimersByTime(300);
    streets().flush({}, { status: 500, statusText: 'Error' });
    expect(page.sugerenciasRealizadas).toBe(false);
    expect(page.sugerenciasError).not.toBe('');
    page.sugerir('calle'); vi.advanceTimersByTime(300);
    streets().flush({ success: true, data: Array.from({ length: 15 }, (_, i) => `Calle ${i}`) });
    expect(page.sugerencias.length).toBe(10);
  });

  it('cancela sugerencias al salir y al borrar el texto', () => {
    page.actualizar('calle', 'Mig'); vi.advanceTimersByTime(300);
    const req = streets(); page.actualizar('calle', 'M');
    expect(req.cancelled).toBe(true);
    page.actualizar('esquina', 'Est'); page.ionViewWillLeave();
    vi.advanceTimersByTime(300); http.expectNone(() => true);
    expect(page.campoSugerencias).toBeNull();
  });

  it('permite recorrer sugerencias con flechas sin desplazar la página', () => {
    const campos = document.createElement('div');
    campos.innerHTML = '<div class="sugerencias"><button>Uno</button><button>Dos</button></div>';
    document.body.append(campos); page.campos = new ElementRef(campos);
    try {
      const down = new KeyboardEvent('keydown', { key: 'ArrowDown', cancelable: true });
      page.navegarSugerencias(down);
      expect(down.defaultPrevented).toBe(true);
      expect(document.activeElement).toBe(campos.querySelectorAll('button')[0]);
      page.navegarSugerencias(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
      expect(document.activeElement).toBe(campos.querySelectorAll('button')[1]);
    } finally { campos.remove(); }
  });
});
