import { MENSAJES } from '../errores.service';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { vi } from 'vitest';
import { By } from '@angular/platform-browser';
import { MapaContenedoresComponent } from './mapa-contenedores.component';
import { BuscarContenedorPage } from './buscar-contenedor.page';
import { FlujoIncidenciaService } from '../reporte-incidencia/flujo-incidencia.service';
import { routes } from '../app.routes';
import { ContenedorPublico } from '../reporte-incidencia/contenedores.service';

describe('Identificación previa al formulario', () => {
  let fixture: ComponentFixture<BuscarContenedorPage>;
  let page: BuscarContenedorPage;
  let http: HttpTestingController;
  let flujo: FlujoIncidenciaService;
  let ruta: { snapshot: { queryParamMap: ReturnType<typeof convertToParamMap> } };
  const contenedor = { id_contenedor: 1, codigo: 'CTN-001', direccion: 'Av. Brasil 123', estado: 'Disponible', latitud: -34.9, longitud: -56.1 };
  beforeEach(async () => {
    ruta = { snapshot: { queryParamMap: convertToParamMap({ metodo: 'calle' }) } };
    await TestBed.configureTestingModule({ imports: [BuscarContenedorPage], providers: [
      provideHttpClient(), provideHttpClientTesting(), provideRouter(routes),
      { provide: ActivatedRoute, useValue: ruta },
    ] }).compileComponents();
    fixture = TestBed.createComponent(BuscarContenedorPage);
    page = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    flujo = TestBed.inject(FlujoIncidenciaService);
    fixture.detectChanges();
  });
  afterEach(() => { fixture.destroy(); http.verify(); vi.restoreAllMocks(); vi.unstubAllGlobals(); TestBed.resetTestingModule(); });
  function calle() {
    page.calle = 'Brasil'; page.esquina = 'Soca'; page.buscar();
    const req = http.expectOne(r => r.params.get('view') === 'search');
    expect(req.request.params.get('calle')).toBe('Brasil');
    expect(req.request.params.get('esquina')).toBe('Soca');
    req.flush({ success: true, data: [contenedor] });
  }
  async function mostrarMapa(): Promise<void> {
    fixture.destroy();
    ruta.snapshot.queryParamMap = convertToParamMap({ metodo: 'mapa' });
    fixture = TestBed.createComponent(BuscarContenedorPage); page = fixture.componentInstance;
    fixture.detectChanges(); await fixture.whenStable();
    http.expectOne(r => r.params.get('view') === 'map').flush({ success: true,
      data: [{ ...contenedor, latitud: -34.905, longitud: -56.185 }], meta: { hasMore: false } });
    fixture.detectChanges();
  }
  function botonUbicacion(): HTMLButtonElement {
    return fixture.nativeElement.querySelector('app-mapa-contenedores button.localizar') as HTMLButtonElement;
  }
  it('busca por calle y muestra resumen sin abrir el formulario al elegir', () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate');
    calle(); fixture.detectChanges();
    (fixture.nativeElement.querySelector('app-mapa-contenedores button.punto') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(page.seleccionado).toEqual(contenedor);
    expect(fixture.nativeElement.querySelector('.calle-detalle').textContent).toContain('Av. Brasil 123');
    expect(fixture.nativeElement.querySelector('.calle-detalle').textContent).toContain('Disponible');
    expect(fixture.nativeElement.querySelector('app-resultados-contenedores')).toBeNull();
    expect(fixture.nativeElement.querySelector('.campos')).not.toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    expect(flujo.contenedor).toBeNull();
  });
  it('continúa al formulario existente con el contenedor confirmado', async () => {
    calle(); page.seleccionarContenedor(page.resultados[0]); fixture.detectChanges();
    (fixture.nativeElement.querySelector('.seleccionar-calle') as HTMLElement).click();
    await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/formulario-reporte');
    expect(flujo.contenedor).toEqual(contenedor);
    expect(flujo.metodo).toBe('calle');
  });
  it('cambia de contenedor conservando los resultados y quitando la confirmación anterior', () => {
    calle(); page.seleccionarContenedor(page.resultados[0]);
    flujo.identificar(contenedor, 'calle');
    page.cambiarContenedor(); fixture.detectChanges();
    expect(page.seleccionado).toBeNull(); expect(flujo.contenedor).toBeNull();
    expect(page.resultados).toEqual([contenedor]);
    expect(fixture.nativeElement.querySelector('.confirmacion')).toBeNull();
    const otro = { ...contenedor, id_contenedor: 2, codigo: 'CTN-002' };
    page.resultados = [otro]; page.seleccionarContenedor(otro);
    expect(page.seleccionado?.codigo).toBe('CTN-002');
  });
  it('cancela una búsqueda pendiente y vuelve a los métodos sin selección', async () => {
    page.calle = 'Brasil'; page.buscar();
    const req = http.expectOne(r => r.params.get('view') === 'search');
    flujo.identificar(contenedor, 'calle'); page.cancelar();
    await fixture.whenStable();
    expect(req.cancelled).toBe(true);
    expect(flujo.contenedor).toBeNull(); expect(page.resultados).toEqual([]);
    expect(TestBed.inject(Router).url).toBe('/reporte-incidencia');
  });
  it('GPS consulta solo la zona obtenida y permite revisar un candidato', () => {
    page.metodo = 'gps';
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (ok: PositionCallback) => ok({ coords: { latitude: -34.9, longitude: -56.1 } } as GeolocationPosition) } });
    page.localizar();
    const req = http.expectOne(r => r.params.get('view') === 'map');
    expect(Number(req.request.params.get('north'))).toBeCloseTo(-34.895);
    expect(Number(req.request.params.get('west'))).toBeCloseTo(-56.105);
    req.flush({ success: true, data: [contenedor], meta: { hasMore: false } });
    page.seleccionarContenedor(page.resultados[0]);
    expect(page.seleccionado).toEqual(contenedor);
  });
  it('GPS ordena por distancia ascendente y limita la lista a cinco', () => {
    page.metodo = 'gps';
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (ok: PositionCallback) => ok({
      coords: { latitude: -34.9, longitude: -56.1 },
    } as GeolocationPosition) } });
    page.localizar();
    const candidatos: ContenedorPublico[] = [0.004, 0.001, -0.002, 0.0005, 0.01, -0.0002, -0.003].map((offset, index) => ({
      ...contenedor,
      id_contenedor: index + 1,
      codigo: `CTN-${index + 1}`,
      latitud: -34.9 + offset,
    }));
    candidatos.push({ ...contenedor, id_contenedor: 8, codigo: 'CTN-8', latitud: null });
    http.expectOne(r => r.params.get('view') === 'map').flush({ success: true, data: candidatos, meta: { hasMore: true } });
    expect(page.resultados.map(item => item.id_contenedor)).toEqual([6, 4, 2, 3, 7]);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-resultados-contenedores button').textContent).toContain('Distancia: 22 m');
    page.seleccionarContenedor(page.resultados[0]);
    expect(page.seleccionado?.codigo).toBe('CTN-6');
  });
  it('la búsqueda por calle conserva el orden y todos los resultados recibidos', () => {
    page.calle = 'Brasil';
    page.buscar();
    const candidatos = Array.from({ length: 7 }, (_, index) => ({
      ...contenedor,
      id_contenedor: index + 1,
      codigo: `CTN-${index + 1}`,
    }));
    http.expectOne(r => r.params.get('view') === 'search').flush({ success: true, data: candidatos });
    expect(page.resultados).toEqual(candidatos);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-resultados-contenedores')).toBeNull();
    expect(fixture.nativeElement.querySelector('app-mapa-contenedores')).not.toBeNull();
  });
  it('GPS denegado muestra error y permite cambiar de método', async () => {
    page.metodo = 'gps';
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (_ok: PositionCallback, error: PositionErrorCallback) => error({ code: 1 } as GeolocationPositionError) } });
    page.localizar();
    expect(page.error).toBe(MENSAJES.ubicacion);
    expect(page.cargando).toBe(false);
    page.cancelar(); await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/reporte-incidencia');
  });
  it('ignora respuestas de GPS posteriores a cancelar', async () => {
    page.metodo = 'gps'; let callback!: PositionCallback;
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (ok: PositionCallback) => { callback = ok; } } });
    page.localizar(); page.cancelar(); await fixture.whenStable();
    callback({ coords: { latitude: -34.9, longitude: -56.1 } } as GeolocationPosition);
    http.expectNone(() => true); expect(page.cargando).toBe(false);
  });
  it('mapa consulta la zona visible y elige desde un punto real', async () => {
    await mostrarMapa();
    (fixture.nativeElement.querySelector('app-mapa-contenedores button.punto') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(page.seleccionado?.codigo).toBe('CTN-001');
    expect(fixture.nativeElement.querySelector('.confirmacion')).toBeNull();
    expect(fixture.nativeElement.querySelector('app-mapa-contenedores .punto.seleccionado').getAttribute('aria-pressed')).toBe('true');
    expect(fixture.nativeElement.querySelector('#contenedor-mapa').value).toBe('1');
    expect(fixture.nativeElement.querySelector('app-resultados-contenedores')).toBeNull();
  });
  it('centra el mapa en la ubicación obtenida y no selecciona un contenedor', async () => {
    await mostrarMapa();
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (ok: PositionCallback) => ok({
      coords: { latitude: -34.9, longitude: -56.1 },
    } as GeolocationPosition) } });
    botonUbicacion().click(); fixture.detectChanges();
    const req = http.expectOne(r => r.params.get('view') === 'map');
    expect(Number(req.request.params.get('zoom'))).toBe(17);
    expect((Number(req.request.params.get('north')) + Number(req.request.params.get('south'))) / 2).toBeCloseTo(-34.9, 2);
    expect((Number(req.request.params.get('east')) + Number(req.request.params.get('west'))) / 2).toBeCloseTo(-56.1, 4);
    req.flush({ success: true, data: [{ ...contenedor, latitud: -34.9, longitud: -56.1 }], meta: { hasMore: false } });
    fixture.detectChanges();
    expect(page.seleccionado).toBeNull();
    expect(flujo.contenedor).toBeNull();
  });
  it('desplegable centra, sincroniza marcador y habilita confirmar conservando método mapa', async () => {
    await mostrarMapa();
    const boton = () => fixture.nativeElement.querySelector('.seleccionar-mapa');
    expect(boton().disabled).toBe(true);
    expect(page.seleccionado).toBeNull();
    const mapa = fixture.debugElement.query(By.directive(MapaContenedoresComponent)).componentInstance as MapaContenedoresComponent;
    const centrar = vi.spyOn(mapa, 'centrarEn');
    const select = fixture.nativeElement.querySelector('#contenedor-mapa') as HTMLSelectElement;
    select.value = '1'; select.dispatchEvent(new Event('change')); fixture.detectChanges();
    expect(centrar).toHaveBeenCalledWith(-34.905, -56.185);
    expect(boton().disabled).toBe(true); // La nueva zona todavía está cargando.
    const elegido = { ...contenedor, latitud:-34.905, longitud:-56.185 };
    http.expectOne(r => r.params.get('view') === 'map').flush({ success:true, data:[elegido], meta:{hasMore:false} });
    fixture.detectChanges();
    expect(select.value).toBe('1');
    expect(mapa.punto(elegido)[0]).toBeCloseTo(160);
    expect(mapa.punto(elegido)[1]).toBeCloseTo(160);
    expect(fixture.nativeElement.querySelector('.punto.seleccionado')).not.toBeNull();
    expect(boton().disabled).toBe(false);
    boton().click(); await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/formulario-reporte');
    expect(flujo.contenedor).toEqual(elegido);
    expect(flujo.metodo).toBe('mapa');
  });
  it('mantiene selección explícita al mover la zona y bloquea confirmar ante error', async () => {
    await mostrarMapa();
    page.seleccionarContenedor(page.resultados[0]);
    const elegido = page.seleccionado;
    const mapa = fixture.debugElement.query(By.directive(MapaContenedoresComponent)).componentInstance as MapaContenedoresComponent;
    mapa.mover(128, 0);
    http.expectOne(r => r.params.get('view') === 'map').flush({ success:true, data:[], meta:{hasMore:false} });
    fixture.detectChanges();
    expect(page.seleccionado).toBe(elegido);
    expect(page.resultados).toContain(elegido);
    mapa.acercar(1);
    http.expectOne(r => r.params.get('view') === 'map').flush({}, {status:500,statusText:'Error'});
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.seleccionar-mapa').disabled).toBe(true);
    page.continuar(); expect(flujo.contenedor).toBeNull();
  });
  it('rechaza selección del desplegable ajena a resultados y permite vaciarla', async () => {
    await mostrarMapa();
    page.seleccionarDesdeMapa('999');
    expect(page.seleccionado).toBeNull();
    page.seleccionarContenedor(page.resultados[0]);
    page.seleccionarDesdeMapa('');
    expect(page.seleccionMapaValida).toBe(false);
    http.expectNone(r => r.params.get('view') === 'map');
  });
  it('maneja permiso denegado al solicitar ubicación desde el mapa', async () => {
    await mostrarMapa();
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (_ok: PositionCallback, error: PositionErrorCallback) => error({ code: 1 } as GeolocationPositionError) } });
    botonUbicacion().click(); fixture.detectChanges();
    expect(page.error).toBe(MENSAJES.ubicacion);
    expect(page.cargando).toBe(false);
    http.expectNone(r => r.params.get('view') === 'map');
  });
  it('maneja timeout/error de geolocalización desde el mapa', async () => {
    await mostrarMapa();
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: (_ok: PositionCallback, error: PositionErrorCallback) => error({ code: 3 } as GeolocationPositionError) } });
    botonUbicacion().click(); fixture.detectChanges();
    expect(page.error).toBe(MENSAJES.ubicacion);
    expect(page.cargando).toBe(false);
    http.expectNone(r => r.params.get('view') === 'map');
  });
  it('maneja la geolocalización no disponible desde el mapa', async () => {
    await mostrarMapa();
    vi.stubGlobal('navigator', {});
    botonUbicacion().click(); fixture.detectChanges();
    expect(page.error).toBe(MENSAJES.ubicacion);
    expect(page.cargando).toBe(false);
    http.expectNone(r => r.params.get('view') === 'map');
  });
  it('bloquea continuar sin selección y rechaza contenedores ajenos a los resultados', () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate');
    page.continuar(); page.seleccionarContenedor(contenedor); page.continuar();
    expect(navigate).not.toHaveBeenCalled();
    expect(page.seleccionado).toBeNull();
  });
  it('al iniciar otro reporte no recupera una selección antigua de la vista Ionic', () => {
    calle(); page.seleccionarContenedor(page.resultados[0]); flujo.reiniciar();
    page.ionViewWillEnter(); expect(page.seleccionado).toBeNull();
  });
  it('ajusta todos los resultados, cambia el único marcador seleccionado y actualiza la ficha sin GPS', () => {
    const otro = { ...contenedor, id_contenedor: 2, codigo: 'IDM-002', direccion: 'Martínez 456', esquina: 'Soca', referencia: 'Frente a la plaza', estado: 'En servicio', latitud: -34.95, longitud: -56.2 };
    page.calle = 'Martí'; page.buscar();
    http.expectOne(r => r.params.get('view') === 'search' && r.params.get('calle') === 'Martí').flush({ success: true, data: [contenedor, otro] });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.calle-detalle')).toBeNull();
    expect(fixture.nativeElement.querySelector('.seleccionar-calle')).toBeNull();
    expect(fixture.nativeElement.querySelector('.localizar')).toBeNull();
    const puntos = fixture.nativeElement.querySelectorAll('.punto') as NodeListOf<HTMLButtonElement>;
    expect(puntos.length).toBe(2);
    puntos[0].click(); fixture.detectChanges();
    expect(puntos[0].classList.contains('seleccionado')).toBe(true);
    puntos[1].click(); fixture.detectChanges();
    expect(puntos[0].classList.contains('seleccionado')).toBe(false);
    expect(puntos[1].getAttribute('aria-pressed')).toBe('true');
    expect(fixture.nativeElement.querySelectorAll('.punto.seleccionado').length).toBe(1);
    expect(fixture.nativeElement.querySelector('.calle-detalle').textContent).toContain('IDM-002');
    expect(fixture.nativeElement.querySelector('.calle-detalle').textContent).toContain('Frente a la plaza');
    expect(page.seleccionado).toEqual(otro);
    const mapa = fixture.debugElement.query(By.directive(MapaContenedoresComponent)).componentInstance as MapaContenedoresComponent;
    mapa.mover(10, 0);
    http.expectNone(r => r.params.get('view') === 'map');
  });
  it('nueva búsqueda o edición limpia la ficha y el flujo; cancelar limpia todo sin generar incidencia', async () => {
    calle(); page.seleccionarContenedor(page.resultados[0]); flujo.identificar(contenedor, 'calle');
    page.actualizar('esquina', ''); fixture.detectChanges();
    expect(page.seleccionado).toBeNull(); expect(flujo.contenedor).toBeNull();
    expect(fixture.nativeElement.querySelector('.calle-detalle')).toBeNull();
    calle(); page.seleccionarContenedor(page.resultados[0]); fixture.detectChanges();
    (fixture.nativeElement.querySelector('.calle-acciones ion-button[fill="outline"]') as HTMLElement).click();
    await fixture.whenStable();
    expect(page.seleccionado).toBeNull(); expect(page.resultados).toEqual([]);
    expect(flujo.contenedor).toBeNull(); expect(TestBed.inject(Router).url).toBe('/reporte-incidencia');
  });
  it('avisa de coordenadas ausentes y no permite confirmar esos resultados', () => {
    page.calle = 'Brasil'; page.buscar();
    const sinUbicacion = { ...contenedor, latitud: null };
    http.expectOne(r => r.params.get('view') === 'search').flush({ success: true, data: [sinUbicacion] });
    page.seleccionarContenedor(page.resultados[0]); fixture.detectChanges();
    expect(page.aviso).toContain('sin ubicación');
    expect(page.seleccionado).toBeNull();
    expect(fixture.nativeElement.querySelectorAll('.punto').length).toBe(0);
  });
});
