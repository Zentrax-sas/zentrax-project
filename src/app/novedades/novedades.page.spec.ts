import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { NovedadesPage } from './novedades.page';
import { API_BASE_URL } from '../api.config';

describe('Novedades ciudadanas', () => {
  let fixture: ComponentFixture<NovedadesPage>;
  let http: HttpTestingController;
  const fila = { id: 1, tipo: 'Incidencia', titulo: 'Reporte de contenedor desbordado',
    fecha: '2026-09-27 08:30:00', direccion: 'Av. Brasil 123', estado: 'Pendiente' };
  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [NovedadesPage],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(NovedadesPage);
    http = TestBed.inject(HttpTestingController);
    fixture.componentInstance.ionViewWillEnter();
    fixture.detectChanges();
  });
  afterEach(() => { fixture.destroy(); http.verify(); TestBed.resetTestingModule(); });
  const request = () => http.expectOne(`${API_BASE_URL}/novedades.php`);
  it('muestra carga y luego los datos reales por fecha descendente', () => {
    expect(fixture.nativeElement.textContent).toContain('Cargando novedades');
    expect(fixture.nativeElement.querySelector('button').disabled).toBe(true);
    const req = request(); expect(req.request.method).toBe('GET');
    req.flush({ success: true, data: [fila, { ...fila, id: 2, fecha: '2026-09-27 09:00:00', estado: 'Resuelta' }] });
    fixture.detectChanges();
    expect(fixture.componentInstance.novedades().map(n => n.id)).toEqual([2, 1]);
    const articulos = fixture.nativeElement.querySelectorAll('article');
    expect(articulos.length).toBe(2);
    expect(articulos[0].textContent).toContain('Resuelta');
    expect(articulos[0].textContent).toContain('27/09/2026 · 09:00');
    expect(articulos[0].textContent).toContain('Av. Brasil 123');
    expect(fixture.nativeElement.textContent).not.toContain('Contenido de ejemplo');
  });
  it('muestra vacío sin inventar tarjetas', () => {
    request().flush({ success: true, data: [] }); fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('No hay novedades públicas');
    expect(fixture.nativeElement.querySelector('article')).toBeNull();
  });
  it('distingue error de vacío y permite reintentar', () => {
    request().flush({}, { status: 500, statusText: 'Server error' }); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role="alert"]').textContent).toContain('No pudimos');
    expect(fixture.nativeElement.textContent).not.toContain('No hay novedades');
    fixture.nativeElement.querySelector('button').click();
    request().flush({ success: true, data: [fila] }); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role="alert"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('article')).not.toBeNull();
  });
  it('descarta campos adicionales y omite una dirección ausente', () => {
    request().flush({ success: true, data: [{ ...fila, direccion: null, email: 'privado@example.org',
      telefono: '099123456', descripcion: 'Texto privado', tracking_number: 'INC-2026-ABCDE' }] });
    fixture.detectChanges();
    expect(Object.keys(fixture.componentInstance.novedades()[0]).sort()).toEqual(['id', 'tipo', 'titulo', 'fecha', 'direccion', 'estado'].sort());
    expect(fixture.nativeElement.textContent).not.toContain('privado');
    expect(fixture.nativeElement.querySelector('.direccion')).toBeNull();
  });
  it('escapa el contenido recibido en lugar de interpretarlo como HTML', () => {
    request().flush({ success: true, data: [{ ...fila, direccion: '<img src=x onerror=alert(1)>' }] });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('article img')).toBeNull();
    expect(fixture.nativeElement.textContent).toContain('<img');
  });
  it.each([
    { success: false, data: [] }, { success: true, data: null },
    { success: true, data: [{ ...fila, estado: 'Inventado' }] },
    { success: true, data: [{ ...fila, fecha: 'ayer' }] },
    { success: true, data: [fila, fila] },
  ])('rechaza una respuesta inválida', respuesta => {
    request().flush(respuesta); fixture.detectChanges();
    expect(fixture.componentInstance.error()).not.toBe('');
    expect(fixture.nativeElement.querySelector('article')).toBeNull();
  });
  it('cancela al salir y consulta de nuevo al regresar', () => {
    const anterior = request(); fixture.componentInstance.ionViewWillLeave();
    expect(anterior.cancelled).toBe(true);
    fixture.componentInstance.ionViewWillEnter();
    request().flush({ success: true, data: [fila] });
    expect(fixture.componentInstance.novedades().length).toBe(1);
  });
});
