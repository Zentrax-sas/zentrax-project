import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { Subject } from 'rxjs';
import { vi } from 'vitest';
import { BuscarContenedorPage } from './buscar-contenedor.page';
import { LECTOR_QR } from './lector-qr';
import { FlujoIncidenciaService } from '../reporte-incidencia/flujo-incidencia.service';
import { routes } from '../app.routes';
import { API_BASE_URL } from '../api.config';

describe('Flujo QR', () => {
  let fixture: ComponentFixture<BuscarContenedorPage>;
  let page: BuscarContenedorPage;
  let http: HttpTestingController;
  let lectura: Subject<string>;
  const contenedor = { id_contenedor: 1, codigo: 'CTN-001', direccion: 'Av. Brasil 123', estado: 'Disponible', latitud: -34.9, longitud: -56.1 };
  beforeEach(async () => {
    lectura = new Subject<string>();
    await TestBed.configureTestingModule({
      imports: [BuscarContenedorPage],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes),
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({ metodo: 'qr' }) } } },
        { provide: LECTOR_QR, useValue: { leer: () => lectura.asObservable() } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(BuscarContenedorPage);
    page = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });
  afterEach(() => { fixture.destroy(); http.verify(); TestBed.resetTestingModule(); });
  function consulta() {
    return http.expectOne(r => r.url === `${API_BASE_URL}/contenedores.php` && r.params.get('view') === 'search' && r.params.get('q') === 'CTN-001');
  }
  it('no carga contenedores al entrar y muestra los datos reales de un QR leído', () => {
    http.expectNone(() => true);
    page.escanear();
    expect(page.escaneando).toBe(true);
    lectura.next('CTN-001');
    expect(page.escaneando).toBe(false);
    expect(lectura.observed).toBe(false);
    consulta().flush({ success: true, data: [contenedor, { ...contenedor, id_contenedor: 2, codigo: 'CTN-0010' }] });
    fixture.detectChanges();
    expect(page.resultados).toEqual([contenedor]);
    const texto = fixture.nativeElement.textContent;
    for (const campo of ['CTN-001', 'Av. Brasil 123', 'Disponible', '-34.90000, -56.10000', 'Reportar incidencia en este contenedor']) expect(texto).toContain(campo);
  });
  it('acepta pegar una URL usando el mismo endpoint y nunca solicita esa URL', () => {
    page.actualizar('calle', 'https://example.org/qr?codigo_contenedor=ctn-001');
    page.buscar();
    consulta().flush({ success: true, data: [contenedor] });
    expect(page.resultados).toEqual([contenedor]);
  });
  it('rechaza QR inválido antes de consultar y permite reintentar', () => {
    page.escanear(); lectura.next('javascript:alert(1)');
    expect(page.error).toContain('QR inválido');
    http.expectNone(() => true);
    page.actualizar('calle', 'CTN-001'); page.buscar();
    consulta().flush({ success: true, data: [contenedor] });
    expect(page.error).toBe('');
  });
  it('informa contenedor inexistente sin permitir selección de coincidencias parciales', () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().flush({ success: true, data: [{ ...contenedor, codigo: 'CTN-0010' }] });
    expect(page.resultados).toEqual([]);
    expect(page.error).toContain('No se encontró un contenedor activo');
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate');
    page.elegir(contenedor);
    expect(navigate).not.toHaveBeenCalled();
  });
  it('no afirma inexistencia cuando la búsqueda alcanza el límite', () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().flush({ success: true, data: Array.from({ length: 20 }, (_, n) => ({ ...contenedor, codigo: `CTN-001${n}` })) });
    expect(page.error).toContain('no pudo confirmar');
  });
  it('pasa al formulario existente con el contenedor identificado y método QR', async () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().flush({ success: true, data: [contenedor] });
    fixture.detectChanges();
    const boton = fixture.nativeElement.querySelector('.qr-resultado ion-button') as HTMLElement;
    boton.click();
    await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/formulario-reporte');
    const flujo = TestBed.inject(FlujoIncidenciaService);
    expect(flujo.contenedor).toEqual(contenedor);
    expect(flujo.metodo).toBe('qr');
    expect(flujo.pendiente).toBeNull();
  });
  it('descarta resultados al editar y cancela la lectura al salir', () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().flush({ success: true, data: [contenedor] });
    page.actualizar('calle', 'CTN-002');
    expect(page.resultados).toEqual([]);
    page.escanear(); page.ionViewWillLeave();
    expect(lectura.observed).toBe(false);
    lectura.next('CTN-001'); http.expectNone(() => true);
  });
  it('informa fallo de red y deja reintentar', () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().error(new ProgressEvent('error'));
    expect(page.error).toContain('No pudimos conectarnos');
    expect(page.cargando).toBe(false);
    page.buscar(); consulta().flush({ success: true, data: [contenedor] });
    expect(page.error).toBe('');
  });
  it('rechaza una respuesta con ID inválido', () => {
    page.calle = 'CTN-001'; page.buscar();
    consulta().flush({ success: true, data: [{ ...contenedor, id_contenedor: -1 }] });
    expect(page.resultados).toEqual([]);
    expect(page.error).toContain('No se pudo confirmar');
  });
});
