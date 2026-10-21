import { ChangeDetectorRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { BuscarContenedorPage } from '../buscar-contenedor/buscar-contenedor.page';
import { vi } from 'vitest';
import { CaptchaReportePage } from '../captcha-reporte/captcha-reporte.page';
import { FormularioReportePage } from '../formulario-reporte/formulario-reporte.page';
import { FlujoIncidenciaService, contenedorIdentificado, incidenciaRegistrada, reportePreparado } from './flujo-incidencia.service';
import { ContenedoresService } from './contenedores.service';

describe('Flujo real de incidencias: contratos HTTP y estados', () => {
  let http: HttpTestingController;
  let flujo: FlujoIncidenciaService;
  const navigate = vi.fn().mockResolvedValue(true);
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(),
      { provide: Router, useValue: { navigate, parseUrl: (url: string) => url } },
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: { get: () => 'mapa' } } } },
      { provide: ChangeDetectorRef, useValue: { markForCheck: () => undefined } },
    ] });
    http = TestBed.inject(HttpTestingController);
    flujo = TestBed.inject(FlujoIncidenciaService);
    navigate.mockClear();
  });
  afterEach(() => http.verify());
  function preparar(foto: File | null = null) {
    flujo.identificar({ id_contenedor: 1, codigo: 'CTN-001', direccion: '18 de Julio', latitud: -34.9, longitud: -56.1, estado: 'Disponible' }, 'calle');
    const form = TestBed.runInInjectionContext(() => new FormularioReportePage());
    form.ionViewWillEnter();
    form.problema = 'Contenedor Desbordado'; form.foto = foto;
    form.enviarReporte();
    const captcha = TestBed.runInInjectionContext(() => new CaptchaReportePage());
    captcha.respuesta = String(captcha.numeroA + captcha.numeroB);
    return captcha;
  }
  it('mantiene la consulta inicial del mapa al entrar en la vista Ionic', () => {
    const page = TestBed.runInInjectionContext(() => new BuscarContenedorPage());
    page.buscarZona({ north: -34.9, south: -34.91, east: -56.18, west: -56.19, zoom: 16 });
    const request = http.expectOne(r => r.params.get('view') === 'map');
    page.ionViewWillEnter();
    expect(request.cancelled).toBe(false);
    request.flush({ success: true, data: [], meta: { hasMore: false } });
    expect(page.realizada).toBe(true);
    expect(page.cargando).toBe(false);
  });
  it('bloquea rutas sin contenedor, reporte o alta', () => {
    for (const guard of [contenedorIdentificado, reportePreparado, incidenciaRegistrada]) {
      expect(TestBed.runInInjectionContext(() => guard({} as never, {} as never))).toBe('/reporte-incidencia');
    }
  });
  it('busca solamente por el endpoint público y codifica el texto', () => {
    TestBed.inject(ContenedoresService).buscar('18 & Rivera').subscribe();
    const req = http.expectOne(r => r.url.endsWith('/contenedores.php'));
    expect(req.request.params.get('view')).toBe('search');
    expect(req.request.params.get('q')).toBe('18 & Rivera'); req.flush({success:true,data:[]});
  });
  it('motivo obligatorio y celular/foto opcionales', () => {
    preparar();
    expect(flujo.pendiente?.celular).toBe(''); expect(flujo.pendiente?.foto).toBeNull();
    const form = TestBed.runInInjectionContext(() => new FormularioReportePage());
    form.ionViewWillEnter(); form.problema = '';
    expect(form.formularioValido).toBe(false);
  });
  it('no envía si la suma es incorrecta', async () => {
    const page = preparar(); page.respuesta = '-1'; await page.confirmar();
    http.expectNone(r => r.method === 'POST'); expect(flujo.registrada).toBeNull();
  });
  it('envía una sola vez y conserva el ID/tracking real si falla la foto', async () => {
    const foto = new File(['foto'], 'evidencia.png', {type:'image/png'});
    const page = preparar(foto);
    const pending = page.confirmar(); await page.confirmar();
    const alta = http.expectOne('/backend/api/incidencias.php');
    expect(Object.keys(alta.request.body).sort()).toEqual(['celular','consentimiento_whatsapp','descripcion','direccion','id_contenedor','tipo_problema']);
    expect(alta.request.body.consentimiento_whatsapp).toBe(false);
    expect(alta.request.body.id_contenedor).toBe(1);
    alta.flush({success:true,data:{id_incidencia:987,tracking_number:'INC-2026-ABCDE'}});
    await Promise.resolve();
    const upload = http.expectOne('/backend/api/foto.php');
    expect(upload.request.body.get('id_incidencia')).toBe('987'); expect(upload.request.body.get('foto').name).toBe(foto.name);
    expect(upload.request.body.get('foto').size).toBe(foto.size);
    expect(upload.request.body.get('foto').type).toBe(foto.type);
    upload.flush({}, {status:500,statusText:'Error'}); await Promise.resolve();
    http.expectOne(r => r.params.get('tracking_number') === 'INC-2026-ABCDE').flush({success:true,data:{estado:'Pendiente',fecha_reporte:'2026-09-22 12:00:00'}});
    await pending;
    expect(flujo.registrada?.id_incidencia).toBe(987);
    expect(flujo.registrada?.aviso).toContain('no se pudo adjuntar');
    expect(navigate).toHaveBeenLastCalledWith(['/incidencia-generada'], {replaceUrl:true});
    await page.confirmar(); http.expectNone(r => r.method === 'POST');
  });
  it('un error del POST no genera éxito ni tracking', async () => {
    const page = preparar(); navigate.mockClear();
    const borrador = flujo.pendiente;
    const pending = page.confirmar();
    http.expectOne('/backend/api/incidencias.php').flush({}, {status:500,statusText:'Error'});
    await pending;
    expect(flujo.registrada).toBeNull(); expect(navigate).not.toHaveBeenCalled(); expect(page.validando).toBe(false);
    expect(flujo.pendiente).toBe(borrador); expect(page.error).toContain('No pudimos confirmar');
  });
});
