import { firstValueFrom } from 'rxjs';
import { vi } from 'vitest';
import * as scanner from '@capacitor/barcode-scanner';
import { LectorQrNative } from './lector-qr-native';

describe('Lector QR Android', () => {
  const video = document.createElement('video');
  const scanBarcode = vi.fn<typeof scanner.CapacitorBarcodeScanner.scanBarcode>();
  const cargar = vi.fn(async () => ({
    ...scanner,
    CapacitorBarcodeScanner: class extends scanner.CapacitorBarcodeScanner {
      static override scanBarcode = scanBarcode;
    },
  }));
  let lector: LectorQrNative;
  beforeEach(() => {
    scanBarcode.mockReset(); cargar.mockClear();
    lector = new LectorQrNative(cargar);
  });

  it('solicita solo QR y cámara trasera y devuelve el texto sin reinterpretarlo', async () => {
    const texto = 'https://ejemplo.test/?codigo=CTN-001';
    scanBarcode.mockResolvedValue({ ScanResult: texto, format: scanner.CapacitorBarcodeScannerTypeHint.QR_CODE });
    expect(await firstValueFrom(lector.leer(video))).toBe(texto);
    expect(scanBarcode).toHaveBeenCalledWith(expect.objectContaining({
      hint: scanner.CapacitorBarcodeScannerTypeHint.QR_CODE,
      cameraDirection: scanner.CapacitorBarcodeScannerCameraDirection.BACK,
      scanButton: false,
    }));
  });

  it('completa sin error cuando el usuario cancela en la cámara nativa', async () => {
    scanBarcode.mockRejectedValue({ code: 'OS-PLUG-BARC-0006' });
    const next = vi.fn(), error = vi.fn(), complete = vi.fn();
    lector.leer(video).subscribe({ next, error, complete });
    await vi.waitFor(() => expect(complete).toHaveBeenCalledOnce());
    expect(next).not.toHaveBeenCalled(); expect(error).not.toHaveBeenCalled();
  });

  it('maneja permiso denegado con el mensaje existente y permite reintentar', async () => {
    scanBarcode.mockRejectedValueOnce({ code: 'OS-PLUG-BARC-0007' });
    await expect(firstValueFrom(lector.leer(video))).rejects.toThrow('No se pudo abrir la cámara. Revisá los permisos o ingresá el código.');
    scanBarcode.mockResolvedValue({ ScanResult: 'CTN-001', format: 0 });
    expect(await firstValueFrom(lector.leer(video))).toBe('CTN-001');
  });

  it('maneja errores del scanner sin exponer su mensaje interno', async () => {
    scanBarcode.mockRejectedValue({ code: 'OS-PLUG-BARC-0009', message: 'Native error' });
    await expect(firstValueFrom(lector.leer(video))).rejects.toThrow('No se pudo leer el QR. Reintentá o ingresá el código.');
  });

  it.each([
    { ScanResult: '12345', format: scanner.CapacitorBarcodeScannerTypeHint.CODE_128 },
    { ScanResult: '', format: scanner.CapacitorBarcodeScannerTypeHint.QR_CODE },
  ])('rechaza resultados vacíos o de otro formato: %j', async resultado => {
    scanBarcode.mockResolvedValue(resultado);
    await expect(firstValueFrom(lector.leer(video))).rejects.toThrow('No se pudo leer el QR.');
  });

  it('no abre la cámara si se cancela mientras se carga el adaptador', async () => {
    const sub = lector.leer(video).subscribe();
    sub.unsubscribe();
    await Promise.resolve(); await Promise.resolve();
    expect(scanBarcode).not.toHaveBeenCalled();
  });

  it('ignora resultados tardíos y no abre sesiones superpuestas', async () => {
    let resolver!: (resultado: scanner.CapacitorBarcodeScannerScanResult) => void;
    scanBarcode.mockReturnValue(new Promise(resolve => resolver = resolve));
    const next = vi.fn(), error = vi.fn();
    const sub = lector.leer(video).subscribe({ next, error });
    await vi.waitFor(() => expect(scanBarcode).toHaveBeenCalledOnce());
    sub.unsubscribe();
    const complete = vi.fn();
    lector.leer(video).subscribe({ complete });
    expect(complete).toHaveBeenCalledOnce();
    expect(scanBarcode).toHaveBeenCalledOnce();
    resolver({ ScanResult: 'CTN-001', format: 0 });
    await Promise.resolve(); await Promise.resolve();
    expect(next).not.toHaveBeenCalled(); expect(error).not.toHaveBeenCalled();
    scanBarcode.mockResolvedValue({ ScanResult: 'CTN-002', format: 0 });
    expect(await firstValueFrom(lector.leer(video))).toBe('CTN-002');
  });

  it('ignora también errores nativos posteriores a la cancelación', async () => {
    let rechazar!: (error: unknown) => void;
    scanBarcode.mockReturnValue(new Promise((_, reject) => rechazar = reject));
    const error = vi.fn();
    const sub = lector.leer(video).subscribe({ error });
    await vi.waitFor(() => expect(scanBarcode).toHaveBeenCalledOnce());
    sub.unsubscribe(); rechazar({ code: 'OS-PLUG-BARC-0007' });
    await Promise.resolve(); await Promise.resolve();
    expect(error).not.toHaveBeenCalled();
  });
});
