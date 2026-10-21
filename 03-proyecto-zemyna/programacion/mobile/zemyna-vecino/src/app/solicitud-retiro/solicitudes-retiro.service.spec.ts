import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { afterEach } from 'vitest';
import { SolicitudesRetiroService } from './solicitudes-retiro.service';
import { SolicitudRetiroBorrador } from './solicitud-retiro-flow.service';

describe('SolicitudesRetiroService', () => {
  let api: SolicitudesRetiroService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(SolicitudesRetiroService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });

  it('lee el endpoint existente y mantiene nombres e IDs del catálogo sin categorías locales', async () => {
    const result = firstValueFrom(api.obtenerTiposResiduo());
    const request = http.expectOne('/backend/api/tipos_residuo.php');
    expect(request.request.method).toBe('GET');
    request.flush({ success: true, data: [{ id_tipo_residuo: '72', nombre: 'Nombre de BD' }] });
    await expect(result).resolves.toEqual([{ id_tipo_residuo: 72, nombre: 'Nombre de BD' }]);
  });

  it('rechaza respuestas fallidas o IDs inválidos sin reemplazarlos por una lista fija', async () => {
    for (const response of [ { success: false, data: [] }, { success: true, data: [{ id_tipo_residuo: 0, nombre: 'Inválido' }] } ]) {
      const result = firstValueFrom(api.obtenerTiposResiduo());
      http.expectOne('/backend/api/tipos_residuo.php').flush(response);
      await expect(result).rejects.toThrow('No se pudieron cargar los tipos de residuo.');
    }
  });

  it('mantiene credenciales en la secuencia CAPTCHA y envío sin transportar la sesión en el payload', async () => {
    const captcha = firstValueFrom(api.obtenerCaptcha());
    const get = http.expectOne('/backend/api/captcha.php');
    expect(get.request.withCredentials).toBe(true);
    get.flush({ success: true, data: { pregunta: '¿Cuánto es 2 + 3?' } });
    await captcha;
    const result = firstValueFrom(api.crear({
      id_tipo_residuo: 9, tipo_residuo: 'Residuos voluminosos', direccion: 'Av. Rivera 123', descripcion: 'Mueble grande', celular: '', foto: null
    }, '5'));
    const post = http.expectOne('/backend/api/solicitud-retiro.php');
    expect(post.request.withCredentials).toBe(true);
    expect(post.request.headers.has('Cookie')).toBe(false);
    expect(Array.from((post.request.body as FormData).keys())).toEqual([
      'id_tipo_residuo', 'direccion', 'descripcion', 'calle', 'numero_referencia', 'latitud', 'longitud', 'consentimiento_whatsapp', 'captcha_respuesta'
    ]);
    post.flush({ success: true, data: registro() });
    await expect(result).resolves.toEqual(registro());
  });

  it('obtiene la operación de captcha desde el backend con credenciales de sesión', async () => {
    const result = firstValueFrom(api.obtenerCaptcha());
    const request = http.expectOne('/backend/api/captcha.php');
    expect(request.request.method).toBe('GET');
    expect(request.request.withCredentials).toBe(true);
    request.flush({ success: true, data: { pregunta: '¿Cuánto es 2 + 3?' } });
    await expect(result).resolves.toBe('¿Cuánto es 2 + 3?');
  });

  it('envía campos y foto opcional como multipart y valida la respuesta real', async () => {
    const borrador: SolicitudRetiroBorrador = {
      id_tipo_residuo: 9, tipo_residuo: 'Residuos voluminosos', direccion: 'Av. Rivera 123', descripcion: 'Mueble grande',
      celular: '099 123 456', foto: new File(['imagen'], 'foto.png', { type: 'image/png' })
    };
    const result = firstValueFrom(api.crear(borrador, '5'));
    const request = http.expectOne('/backend/api/solicitud-retiro.php');
    const body = request.request.body as FormData;
    expect(request.request.method).toBe('POST');
    expect(request.request.withCredentials).toBe(true);
    expect(body.get('id_tipo_residuo')).toBe('9');
    expect(body.has('tipo_residuo')).toBe(false);
    expect(body.get('direccion')).toBe('Av. Rivera 123');
    expect(body.get('descripcion')).toBe('Mueble grande');
    expect(body.get('celular')).toBe('099 123 456');
    expect(body.get('captcha_respuesta')).toBe('5');
    expect(body.get('foto')).toBeInstanceOf(File);
    request.flush({ success: true, data: registro(), message: 'Creada', errors: [] });
    await expect(result).resolves.toEqual(registro());
  });

  it('omite celular y foto opcionales y propaga errores HTTP', async () => {
    const borrador: SolicitudRetiroBorrador = {
      id_tipo_residuo: 1, tipo_residuo: 'Orgánico', direccion: 'Camino 12', descripcion: 'Ramas', celular: '', foto: null
    };
    const failed = firstValueFrom(api.crear(borrador, '8'));
    const request = http.expectOne('/backend/api/solicitud-retiro.php');
    const body = request.request.body as FormData;
    expect(body.has('celular')).toBe(false);
    expect(body.has('foto')).toBe(false);
    request.flush({ success: false, message: 'Error', data: null }, { status: 500, statusText: 'Server Error' });
    await expect(failed).rejects.toBeTruthy();
  });
});

function registro() {
  return {
    id_solicitud: 42, tracking_number: 'RET-2026-ABCDE', tipo_residuo: 'Mueble',
    direccion: 'Av. Rivera 123', estado: 'Pendiente', fecha: '2026-09-27 12:00:00', celular: '099 123 456'
  };
}

