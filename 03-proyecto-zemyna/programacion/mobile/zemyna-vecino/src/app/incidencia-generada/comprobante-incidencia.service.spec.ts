import { Capacitor } from '@capacitor/core';
import { afterEach, vi } from 'vitest';
import { ComprobanteIncidenciaService, fechaUruguay } from './comprobante-incidencia.service';


describe('Comprobante de incidencia', () => {
  const service = new ComprobanteIncidenciaService();
  afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

  it('rechaza tracking vacío antes de generar o guardar', async () => {
    const crear = vi.spyOn(service, 'crearPdf');
    await expect(service.generar({ tracking_number: ' ' })).rejects.toThrow('seguimiento');
    expect(crear).not.toHaveBeenCalled();
  });

  it('conserva la fecha SQL uruguaya y convierte fechas con zona a Montevideo', () => {
    expect(fechaUruguay('2026-10-07 14:35:00')).toContain('14:35:00');
    expect(fechaUruguay('2026-10-07T17:35:00Z')).toBe(fechaUruguay('2026-10-07 14:35:00'));
    expect(fechaUruguay('2026-10-08T01:35:00Z')).toContain('7/10/26');
    expect(fechaUruguay()).toBe('No especificado');
    expect(fechaUruguay('incorrecta')).toBe('No especificado');
  });

  it('usa el nombre requerido y entrega el PDF producido', async () => {
    const pdf = new ArrayBuffer(3);
    vi.spyOn(service, 'crearPdf').mockResolvedValue(pdf);
    const entregar = vi.spyOn(service, 'entregar').mockResolvedValue();
    await service.generar({ tracking_number: 'INC-2026-ABC' });
    expect(entregar).toHaveBeenCalledWith(pdf, 'comprobante-incidencia-INC-2026-ABC.pdf');
  });

  it('Android guarda los bytes como base64 en cache y comparte el URI nativo', async () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(true);
    const guardar = vi.spyOn(service.nativo, 'guardar').mockResolvedValue({ uri: 'file:///cache/comprobante.pdf' });
    const compartir = vi.spyOn(service.nativo, 'compartir').mockResolvedValue({});
    await service.entregar(new TextEncoder().encode('%PDF-1.3').buffer, 'comprobante.pdf');
    expect(guardar).toHaveBeenCalledWith('comprobante.pdf', btoa('%PDF-1.3'));
    expect(compartir).toHaveBeenCalledWith('file:///cache/comprobante.pdf');
  });

  it('propaga errores de almacenamiento nativo sin intentar compartir', async () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(true);
    vi.spyOn(service.nativo, 'guardar').mockRejectedValue(new Error('Sin espacio'));
    const compartir = vi.spyOn(service.nativo, 'compartir');
    await expect(service.entregar(new ArrayBuffer(1), 'comprobante.pdf')).rejects.toThrow('Sin espacio');
    expect(compartir).not.toHaveBeenCalled();
  });

  it('propaga errores o cancelación del diálogo nativo', async () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(true);
    vi.spyOn(service.nativo, 'guardar').mockResolvedValue({ uri: 'file:///cache/comprobante.pdf' });
    vi.spyOn(service.nativo, 'compartir').mockRejectedValue(new Error('Share canceled'));
    await expect(service.entregar(new ArrayBuffer(1), 'comprobante.pdf')).rejects.toThrow('Share canceled');
  });
  it('reutiliza el generador y entrega un comprobante de retiro con sus datos y coordenadas', async () => {
    const pdf=new ArrayBuffer(3);const crear=vi.spyOn(service,'crearPdf').mockResolvedValue(pdf);
    const entregar=vi.spyOn(service,'entregar').mockResolvedValue();
    const retiro={id_solicitud:42,tracking_number:'RET-2026-ABCDE',tipo_residuo:'Mueble',direccion:'Rivera 123',estado:'Pendiente',fecha:'2026-10-13 12:00:00',celular:null,latitud:-34.9,longitud:-56.1};
    await service.generarRetiro(retiro);
    expect(crear).toHaveBeenCalledWith({...retiro,celular:undefined,tramite:'retiro'});
    expect(entregar).toHaveBeenCalledWith(pdf,'comprobante-retiro-RET-2026-ABCDE.pdf');
  });

});

