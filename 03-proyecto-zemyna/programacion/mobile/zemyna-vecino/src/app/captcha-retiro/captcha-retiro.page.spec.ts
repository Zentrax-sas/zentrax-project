import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { afterEach } from 'vitest';
import { SolicitudRetiroFlowService } from '../solicitud-retiro/solicitud-retiro-flow.service';
import { CaptchaRetiroPage } from './captcha-retiro.page';

describe('CaptchaRetiroPage', () => {
  let page: CaptchaRetiroPage;
  let fixture: ComponentFixture<CaptchaRetiroPage>;
  let http: HttpTestingController;
  let navigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    navigate = vi.fn().mockResolvedValue(true);
    TestBed.configureTestingModule({ providers: [
      provideHttpClient(), provideHttpClientTesting(),
      provideRouter([]),
    ] });
    navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    TestBed.inject(SolicitudRetiroFlowService).pendiente = {
      id_tipo_residuo: 9, tipo_residuo: 'Residuos voluminosos', direccion: 'Av. Rivera 123', descripcion: 'Mueble grande', celular: '', foto: null
    };
    fixture = TestBed.createComponent(CaptchaRetiroPage);
    page = fixture.componentInstance;
    fixture.detectChanges();
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });

  it('crea en el backend y usa su respuesta real para abrir la confirmación', async () => {
    const entering = page.ionViewWillEnter();
    http.expectOne('/backend/api/captcha.php').flush({ success: true, data: { pregunta: '¿Cuánto es 2 + 3?' } });
    await entering;
    page.actualizarRespuesta('5');

    const confirming = page.confirmar();
    const request = http.expectOne('/backend/api/solicitud-retiro.php');
    expect((request.request.body as FormData).get('descripcion')).toBe('Mueble grande');
    request.flush({ success: true, data: {
      id_solicitud: 42, tracking_number: 'RET-2026-ABCDE', tipo_residuo: 'Mueble',
      direccion: 'Av. Rivera 123', estado: 'Pendiente', fecha: '2026-09-27 12:00:00', celular: null
    }, message: 'Creada', errors: [] });
    await confirming;

    expect(navigate).toHaveBeenCalledWith(['/solicitud-generada'], { replaceUrl: true });
    expect(TestBed.inject(SolicitudRetiroFlowService).registrada?.tracking_number).toBe('RET-2026-ABCDE');
    expect(TestBed.inject(SolicitudRetiroFlowService).pendiente).toBeNull();
  });

  it('muestra error HTTP y pide un captcha nuevo sin navegar a éxito', async () => {
    const entering = page.ionViewWillEnter();
    http.expectOne('/backend/api/captcha.php').flush({ success: true, data: { pregunta: '¿Cuánto es 2 + 3?' } });
    await entering;
    page.actualizarRespuesta('5');

    const confirming = page.confirmar();
    http.expectOne('/backend/api/solicitud-retiro.php').flush(
      { success: false, message: 'Error', data: null }, { status: 500, statusText: 'Server Error' }
    );
    await Promise.resolve();
    http.expectOne('/backend/api/captcha.php').flush({ success: true, data: { pregunta: '¿Cuánto es 4 + 1?' } });
    await confirming;

    expect(page.error).toContain('No pudimos confirmar el registro');
    expect(navigate).not.toHaveBeenCalledWith(['/solicitud-generada'], expect.anything());
    expect(page.pregunta).toBe('¿Cuánto es 4 + 1?');
    expect(page.enviando).toBe(false);
    expect(TestBed.inject(SolicitudRetiroFlowService).pendiente?.descripcion).toBe('Mueble grande');
    expect(TestBed.inject(SolicitudRetiroFlowService).registrada).toBeNull();
  });
  it('no muestra éxito sin tracking y conserva el borrador para reintentar', async () => {
    const flow = TestBed.inject(SolicitudRetiroFlowService);
    const borrador = flow.pendiente;
    const entering = page.ionViewWillEnter();
    http.expectOne('/backend/api/captcha.php').flush({success:true,data:{pregunta:'2 + 3'}});
    await entering; page.actualizarRespuesta('5');
    const pending = page.confirmar();
    http.expectOne('/backend/api/solicitud-retiro.php').flush({success:true,data:{id_solicitud:42,tracking_number:''}});
    await Promise.resolve();
    http.expectOne('/backend/api/captcha.php').flush({success:true,data:{pregunta:'4 + 1'}});
    await pending;
    expect(flow.registrada).toBeNull();expect(flow.pendiente).toBe(borrador);
    expect(page.enviando).toBe(false);expect(page.error).toContain('No pudimos confirmar');
    expect(navigate).not.toHaveBeenCalledWith(['/solicitud-generada'],expect.anything());
  });

});