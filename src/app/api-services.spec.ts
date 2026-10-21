import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Observable } from 'rxjs';
import { API_BASE_URL } from './api.config';
import { ContenedoresService } from './reporte-incidencia/contenedores.service';
import { IncidenciasService } from './reporte-incidencia/incidencias.service';
import { SeguimientoService } from './consultar-estado/seguimiento.service';
import { NovedadesService } from './novedades/novedades.service';
import { SolicitudesRetiroService } from './solicitud-retiro/solicitudes-retiro.service';

describe('Servicios con base central', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });
  it.each([true, false, undefined])('envía consentimiento %s explícitamente en JSON y multipart', consentimiento => {
    TestBed.inject(IncidenciasService).crear({ id_contenedor: '1', codigo_contenedor: 'CTN-001', direccion: 'Brasil', metodo: 'calle', motivo: 'Contenedor Desbordado', descripcion: 'Prueba', celular: '099123456', foto: null, consentimiento_whatsapp: consentimiento }).subscribe();
    const incidencia = http.expectOne(`${API_BASE_URL}/incidencias.php`);
    expect(incidencia.request.body.celular).toBe('099123456');
    expect(incidencia.request.body.consentimiento_whatsapp).toBe(consentimiento === true);
    incidencia.flush({ success: true, data: { id_incidencia: 1, tracking_number: 'INC-2026-ABCDE' } });
    const retiro = TestBed.inject(SolicitudesRetiroService).crear({ id_tipo_residuo: 9, tipo_residuo: 'Mueble', direccion: 'Brasil', descripcion: 'Mesa', celular: '099123456', foto: null, consentimiento_whatsapp: consentimiento }, '2').subscribe();
    const solicitud = http.expectOne(`${API_BASE_URL}/solicitud-retiro.php`);
    expect(solicitud.request.body.get('consentimiento_whatsapp')).toBe(consentimiento === true ? '1' : '0');
    retiro.unsubscribe();
  });

  it('todos los endpoints conservan su contrato y reciben la base central sin doble slash', () => {
    expect(API_BASE_URL).toBe('/backend/api');
    const contenedores = TestBed.inject(ContenedoresService);
    const incidencias = TestBed.inject(IncidenciasService);
    const retiros = TestBed.inject(SolicitudesRetiroService);
    const requests: { endpoint: string; method: string; stream: Observable<unknown> }[] = [
      { endpoint: 'contenedores.php', method: 'GET', stream: contenedores.buscar('calle') },
      { endpoint: 'contenedores.php', method: 'GET', stream: contenedores.sugerirCalles('calle') },
      { endpoint: 'contenedores.php', method: 'GET', stream: contenedores.enMapa({ north: 1, south: 0, east: 1, west: 0, zoom: 16 }) },
      { endpoint: 'incidencias.php', method: 'GET', stream: incidencias.seguimiento('INC-2026-ABCDE') },
      { endpoint: 'incidencias.php', method: 'POST', stream: incidencias.crear({
        id_contenedor: '1', codigo_contenedor: 'CTN-001', direccion: 'calle', metodo: 'qr',
        motivo: 'prueba', descripcion: '', celular: '', foto: null,
      }) },
      { endpoint: 'foto.php', method: 'POST', stream: incidencias.subirFoto(1, new File([''], 'foto.png')) },
      { endpoint: 'incidencias.php', method: 'GET', stream: TestBed.inject(SeguimientoService).consultar('INC-2026-ABCDE') },
      { endpoint: 'novedades.php', method: 'GET', stream: TestBed.inject(NovedadesService).listar() },
      { endpoint: 'captcha.php', method: 'GET', stream: retiros.obtenerCaptcha() },
      { endpoint: 'solicitud-retiro.php', method: 'POST', stream: retiros.crear({
        id_tipo_residuo: 9, tipo_residuo: 'Residuos voluminosos', direccion: 'calle', descripcion: 'prueba', celular: '', foto: null,
      }, '2') },
    ];
    for (const { endpoint, method, stream } of requests) {
      const subscription = stream.subscribe();
      const request = http.expectOne(req => req.url === `${API_BASE_URL}/${endpoint}`);
      expect(request.request.method).toBe(method);
      expect(new URL(request.request.url, 'https://localhost').pathname).not.toContain('//');
      if (endpoint === 'captcha.php' || endpoint === 'solicitud-retiro.php') {
        expect(request.request.withCredentials).toBe(true);
      }
      subscription.unsubscribe();
    }
  });
});
