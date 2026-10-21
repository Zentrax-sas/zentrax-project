import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { ConsultarEstadoPage } from './consultar-estado.page';
import { API_BASE_URL } from '../api.config';

describe('Consultar estado: pantalla y HTTP', () => {
  let fixture: ComponentFixture<ConsultarEstadoPage>;
  let http: HttpTestingController;
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ConsultarEstadoPage],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(ConsultarEstadoPage);
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });
  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });

  function enviar(codigo: string) {
    fixture.componentInstance.codigo = codigo;
    fixture.nativeElement.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();
  }
  function request(codigo: string) {
    return http.expectOne(req => req.url === `${API_BASE_URL}/incidencias.php` &&
      req.method === 'GET' && req.params.get('tracking_number') === codigo && req.params.get('detalle') === '1');
  }
  function datos(codigo: string) {
    return { tracking_number: codigo, tipo_tramite: codigo.startsWith('INC') ? 'Incidencia' : 'Solicitud de retiro',
      estado: 'En revisión', fecha: '2026-09-27 10:30:00', direccion: 'Rivera 123', descripcion: 'Descripción del trámite' };
  }

  for (const prefijo of ['INC', 'RET', 'REF']) {
    it(`consulta ${prefijo}, normaliza y presenta los datos reales`, () => {
      const codigo = `${prefijo}-2026-ABCDE`;
      enviar(` ${codigo.toLowerCase()} `);
      expect(fixture.nativeElement.querySelector('button').disabled).toBe(true);
      fixture.componentInstance.consultar(); // No duplica una consulta en curso.
      request(codigo).flush({ success: true, data: datos(codigo) });
      fixture.detectChanges();
      for (const valor of Object.values(datos(codigo))) expect(fixture.nativeElement.textContent).toContain(valor);
      expect(fixture.componentInstance.cargando()).toBe(false);
    });
  }
  for (const codigo of ['', 'INC-2026-ZZZZZ', 'RET-26-ABCDE', 'ABC-2026-ABCDE']) {
    it(`rechaza formato inválido: ${codigo}`, () => {
      enviar(codigo);
      http.expectNone(() => true);
      expect(fixture.nativeElement.querySelector('[role="alert"]').textContent).toContain('código válido');
    });
  }
  it('muestra ausencia de registro y permite reintentar', () => {
    enviar('RET-2026-ABCDE');
    request('RET-2026-ABCDE').flush({ success: false }, { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('No encontramos una incidencia o solicitud');
    enviar('INC-2026-ABCDE');
    request('INC-2026-ABCDE').flush({ success: true, data: datos('INC-2026-ABCDE') });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role="alert"]')).toBeNull();
  });
  it('no conserva resultados anteriores si una nueva consulta falla', () => {
    enviar('INC-2026-ABCDE');
    request('INC-2026-ABCDE').flush({ success: true, data: datos('INC-2026-ABCDE') });
    enviar('RET-2026-ABCDE');
    request('RET-2026-ABCDE').error(new ProgressEvent('error'));
    fixture.detectChanges();
    expect(fixture.componentInstance.resultado()).toBeNull();
    expect(fixture.nativeElement.textContent).toContain('No pudimos conectarnos');
  });
  it('rechaza una respuesta incompleta sin inventar datos', () => {
    enviar('RET-2026-ABCDE');
    request('RET-2026-ABCDE').flush({ success: true, data: { estado: 'Pendiente' } });
    expect(fixture.componentInstance.resultado()).toBeNull();
    expect(fixture.componentInstance.error()).toContain('No pudimos');
  });
  it('informa dirección ausente y omite descripción nula', () => {
    enviar('INC-2026-ABCDE');
    request('INC-2026-ABCDE').flush({ success: true, data: { ...datos('INC-2026-ABCDE'), direccion: null, descripcion: null } });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Sin dirección registrada');
    expect(fixture.nativeElement.querySelector('.descripcion')).toBeNull();
  });
});
