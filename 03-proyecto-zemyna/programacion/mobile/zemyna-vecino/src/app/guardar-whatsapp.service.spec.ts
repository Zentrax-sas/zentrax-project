import { TestBed } from '@angular/core/testing';
import { Capacitor } from '@capacitor/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { of, throwError } from 'rxjs';
import { SeguimientoService } from './consultar-estado/seguimiento.service';
import { celularUruguay, enlaceWhatsApp, GuardarWhatsappService, WHATSAPP_EXTERNO } from './guardar-whatsapp.service';

afterEach(() => vi.restoreAllMocks());
describe('Guardar en WhatsApp', () => {
  const consulta={consultar:vi.fn()};
  beforeEach(()=>{
    consulta.consultar.mockImplementation((tracking:string)=>of({tracking_number:tracking,estado:'Pendiente',descripcion:'Microondas',tipo_residuo:'Electrodoméstico'}));
    TestBed.configureTestingModule({providers:[{provide:SeguimientoService,useValue:consulta}]});
  });
  it.each(['099 123 456', '99123456', '+598 99 123 456', '59899123456', '0059899123456'])('normaliza %s a Uruguay', telefono => {
    expect(celularUruguay(telefono)).toBe('59899123456');
  });
  it.each([undefined, null, '', '123', '+54999123456', 'celular 099123456', '00099123456'])('no asigna destinatario inválido %s', telefono => {
    expect(celularUruguay(telefono)).toBe('');
    const enlace = enlaceWhatsApp('incidencia', 'INC-2026-ABCDE', telefono);
    expect(enlace.web).toMatch(/^https:\/\/wa.me\/\?text=/);
    expect(enlace.nativa).not.toContain('phone=');
  });
  it.each(['incidencia', 'retiro'] as const)('codifica tracking y texto exacto de %s', tipo => {
    const tracking = `${tipo === 'incidencia' ? 'INC' : 'RET'}-2026-ABCDE`;
    const enlace = enlaceWhatsApp(tipo, tracking, '099123456');
    const mensaje = `Zemyna - Seguimiento de ${tipo === 'incidencia' ? 'incidencia' : 'solicitud de retiro'}\nCódigo: ${tracking}\nGuardá este mensaje para consultar el estado de tu ${tipo === 'incidencia' ? 'incidencia' : 'solicitud'}.`;
    expect(enlace.web).toBe(`https://wa.me/59899123456?text=${encodeURIComponent(mensaje)}`);
    expect(enlace.nativa).toBe(`whatsapp://send?phone=59899123456&text=${encodeURIComponent(mensaje)}`);
  });
  it('rechaza tracking ausente o de otro trámite', () => {
    expect(() => enlaceWhatsApp('incidencia', '')).toThrow();
    expect(() => enlaceWhatsApp('retiro', 'INC-2026-ABCDE')).toThrow();
  });
  it('abre navegador y detecta bloqueo o cancelación de apertura', async () => {
    vi.spyOn(Capacitor, 'getPlatform').mockReturnValue('web');
    const abrir = vi.spyOn(window, 'open').mockReturnValue(null);
    const servicio = TestBed.inject(GuardarWhatsappService);
    await expect(servicio.abrir('retiro', 'RET-2026-ABCDE')).rejects.toThrow('No pudimos abrir WhatsApp');
    const ventana={opener:window,location:{href:''},close:vi.fn()};
    abrir.mockReturnValue(ventana as unknown as Window);
    expect(await servicio.abrir('retiro', 'RET-2026-ABCDE')).toContain('Si cancelás');
    expect(abrir).toHaveBeenLastCalledWith('', '_blank');
    expect(decodeURIComponent(ventana.location.href)).toContain('Microondas');
  });
  it('usa el puente Android sin navegar el WebView y maneja su fallo', async () => {
    vi.spyOn(Capacitor, 'getPlatform').mockReturnValue('android');
    const abrir = vi.fn().mockResolvedValue(undefined);
    TestBed.configureTestingModule({providers:[{provide:WHATSAPP_EXTERNO,useValue:{abrir}}]});
    const servicio = TestBed.inject(GuardarWhatsappService);
    await servicio.abrir('incidencia', 'INC-2026-ABCDE', '099123456');
    expect(decodeURIComponent(abrir.mock.calls[0][0].nativa)).toContain('Código: INC-2026-ABCDE');
    expect(decodeURIComponent(abrir.mock.calls[0][0].nativa)).toContain('Microondas');
    abrir.mockRejectedValue(new Error('native internals'));
    await expect(servicio.abrir('incidencia', 'INC-2026-ABCDE')).rejects.toThrow('No pudimos abrir WhatsApp');
  });
  it('consulta datos actuales cada vez y no comparte copias viejas ante error',async()=>{
    vi.spyOn(Capacitor,'getPlatform').mockReturnValue('android');
    const abrir=vi.fn().mockResolvedValue(undefined);
    TestBed.configureTestingModule({providers:[{provide:WHATSAPP_EXTERNO,useValue:{abrir}}]});
    const servicio=TestBed.inject(GuardarWhatsappService);
    await servicio.abrir('retiro','RET-2026-ABCDE');
    expect(decodeURIComponent(abrir.mock.calls[0][0].nativa)).toContain('Microondas');
    consulta.consultar.mockReturnValue(of({tracking_number:'RET-2026-ABCDE',estado:'Programada',descripcion:'Heladera',tipo_residuo:'Electrónico'}));
    await servicio.abrir('retiro','RET-2026-ABCDE');
    expect(decodeURIComponent(abrir.mock.calls[1][0].nativa)).toContain('Heladera');
    expect(decodeURIComponent(abrir.mock.calls[1][0].nativa)).not.toContain('Microondas');
    consulta.consultar.mockReturnValue(throwError(()=>new Error('offline')));
    await expect(servicio.abrir('retiro','RET-2026-ABCDE')).rejects.toThrow('datos actuales');
    expect(abrir).toHaveBeenCalledTimes(2);
  });
});
