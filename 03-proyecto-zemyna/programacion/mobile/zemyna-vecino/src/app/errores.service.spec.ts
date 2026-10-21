import { TestBed } from '@angular/core/testing';
import { HttpClient, HttpErrorResponse, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { firstValueFrom, TimeoutError } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { API_BASE_URL } from './api.config';
import { ErroresService, erroresApiInterceptor, MENSAJES, registroConfirmado } from './errores.service';

describe('Errores consistentes de Zemyna', () => {
  afterEach(() => { vi.unstubAllGlobals(); TestBed.resetTestingModule(); });
  it.each([0,400,403,404,500,503])('oculta detalles técnicos HTTP %s', status => {
    const errores = new ErroresService();
    const e = new HttpErrorResponse({status, error:{message:'PHP Fatal error: SQLSTATE JSON localhost'}});
    expect(errores.mensaje(e)).toBe(MENSAJES.conexion);
    expect(errores.mensaje(e)).not.toContain('SQLSTATE');
  });
  it('distingue permisos, GPS, dirección, CAPTCHA, tracking y timeout', () => {
    const s = new ErroresService();
    expect(s.mensaje(new TimeoutError())).toBe(MENSAJES.conexion);
    expect(s.mensaje({code:1},'ubicacion')).toBe(MENSAJES.ubicacion);
    expect(s.mensaje(new Error('IDE no responde'),'direccion')).toBe(MENSAJES.direccion);
    expect(s.mensaje(new DOMException('denied','NotAllowedError'),'foto')).toBe(MENSAJES.fotoPermiso);
    expect(s.mensaje(new DOMException('denied','NotAllowedError'),'qr')).toBe(MENSAJES.qrPermiso);
    expect(s.mensaje(new HttpErrorResponse({status:404}),'estado')).toBe(MENSAJES.tracking);
    expect(s.mensaje(new HttpErrorResponse({status:400,error:{errors:{captcha_respuesta:'Fatal PHP'}}}),'retiro')).toBe(MENSAJES.captcha);
    expect(s.mensaje(new HttpErrorResponse({status:401}),'captcha')).toBe(MENSAJES.sesion);
    expect(s.describir(new HttpErrorResponse({status:500})).categoria).toBe('servidor');
  });
  it.each(['incidencia','retiro'] as const)('no afirma inexistencia ante POST ambiguo: %s', contexto => {
    const s = new ErroresService();
    for (const e of [new TimeoutError(),new HttpErrorResponse({status:0}),new HttpErrorResponse({status:500}),new Error('sin tracking')]) {
      expect(s.mensaje(e,contexto)).toContain('No pudimos confirmar');
      expect(s.mensaje(e,contexto)).not.toContain('No se generó');
    }
    expect(s.mensaje(new HttpErrorResponse({status:422}),contexto)).toBe(MENSAJES[contexto]);
  });
  it('no acepta éxito sin tracking e ID correctos', () => {
    expect(registroConfirmado({id_incidencia:1,tracking_number:'INC-2026-ABCDE'},'INC')).toBe(true);
    expect(registroConfirmado({id_solicitud:1,tracking_number:'RET-2026-ABCDE'},'RET')).toBe(true);
    for(const data of [null,{}, {id_incidencia:1}, {id_incidencia:0,tracking_number:'INC-2026-ABCDE'}, {id_incidencia:1,tracking_number:'RET-2026-ABCDE'}]) expect(registroConfirmado(data,'INC')).toBe(false);
  });
  it('backend apagado: normaliza la respuesta y permite reintentar', async () => {
    TestBed.configureTestingModule({providers:[provideHttpClient(withInterceptors([erroresApiInterceptor])),provideHttpClientTesting()]});
    const http=TestBed.inject(HttpClient), mock=TestBed.inject(HttpTestingController);
    const p=firstValueFrom(http.get(`${API_BASE_URL}/contenedores.php`));
    const rejection=expect(p).rejects.toMatchObject({mensaje:MENSAJES.conexion});
    mock.expectOne(`${API_BASE_URL}/contenedores.php`).error(new ProgressEvent('error'));
    await rejection;
    const retry=firstValueFrom(http.get(`${API_BASE_URL}/contenedores.php`));
    mock.expectOne(`${API_BASE_URL}/contenedores.php`).flush({success:true,data:[],meta:{hasMore:false}});
    expect(await retry).toMatchObject({success:true});mock.verify();
  });
  it('sin red no intenta POST y puede afirmar que no se envió', async () => {
    vi.stubGlobal('navigator',{onLine:false});
    TestBed.configureTestingModule({providers:[provideHttpClient(withInterceptors([erroresApiInterceptor])),provideHttpClientTesting()]});
    await expect(firstValueFrom(TestBed.inject(HttpClient).post(`${API_BASE_URL}/incidencias.php`,{}))).rejects.toMatchObject({enviado:false,mensaje:MENSAJES.incidencia});
    TestBed.inject(HttpTestingController).expectNone(`${API_BASE_URL}/incidencias.php`);
  });
});
