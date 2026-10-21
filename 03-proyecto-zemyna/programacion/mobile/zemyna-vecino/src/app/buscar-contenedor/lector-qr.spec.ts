import { vi } from 'vitest';
import { crearLectorQr, LectorQrWeb } from './lector-qr';
import { Capacitor } from '@capacitor/core';
import { LectorQrNative } from './lector-qr-native';

describe('Selección del lector QR', () => {
  afterEach(() => vi.restoreAllMocks());
  it('elige el lector web en navegador', () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(false);
    vi.spyOn(Capacitor, 'getPlatform').mockReturnValue('web');
    expect(crearLectorQr()).toBeInstanceOf(LectorQrWeb);
  });
  it('elige el adaptador nativo únicamente en Android nativo', () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(true);
    vi.spyOn(Capacitor, 'getPlatform').mockReturnValue('android');
    expect(crearLectorQr()).toBeInstanceOf(LectorQrNative);
  });
  it('conserva el fallback web en otras plataformas', () => {
    vi.spyOn(Capacitor, 'isNativePlatform').mockReturnValue(true);
    vi.spyOn(Capacitor, 'getPlatform').mockReturnValue('ios');
    expect(crearLectorQr()).toBeInstanceOf(LectorQrWeb);
  });
});

describe('Adaptador de cámara QR web', () => {
  let video: HTMLVideoElement;
  const stop = vi.fn();
  const detect = vi.fn();
  const getUserMedia = vi.fn();
  beforeEach(() => {
    vi.useFakeTimers();
    stop.mockReset(); detect.mockReset(); getUserMedia.mockReset();
    video = document.createElement('video');
    vi.spyOn(video, 'play').mockResolvedValue();
    vi.stubGlobal('BarcodeDetector', class {
      static async getSupportedFormats() { return ['qr_code']; }
      detect = detect;
    });
    vi.stubGlobal('navigator', { mediaDevices: { getUserMedia } });
    getUserMedia.mockResolvedValue({ getTracks: () => [{ stop }] });
  });
  afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); vi.useRealTimers(); });
  it('emite el QR y libera la cámara al completar', async () => {
    detect.mockResolvedValue([{ rawValue: 'CTN-001' }]);
    const next = vi.fn();
    new LectorQrWeb().leer(video).subscribe(next);
    await vi.advanceTimersByTimeAsync(0);
    expect(next).toHaveBeenCalledWith('CTN-001');
    expect(stop).toHaveBeenCalledOnce();
    expect(video.srcObject).toBeNull();
    expect(getUserMedia).toHaveBeenCalledWith({ audio: false, video: { facingMode: 'environment' } });
  });
  it('libera un permiso que se resuelve después de salir', async () => {
    let resolve!: (value: unknown) => void;
    getUserMedia.mockReturnValue(new Promise(r => resolve = r));
    const sub = new LectorQrWeb().leer(video).subscribe();
    await vi.advanceTimersByTimeAsync(0);
    sub.unsubscribe();
    resolve({ getTracks: () => [{ stop }] });
    await vi.advanceTimersByTimeAsync(0);
    expect(stop).toHaveBeenCalledOnce();
    expect(detect).not.toHaveBeenCalled();
  });
  it('ofrece entrada manual si el navegador no tiene lector', () => {
    vi.stubGlobal('BarcodeDetector', undefined);
    const error = vi.fn();
    new LectorQrWeb().leer(video).subscribe({ error });
    expect(error.mock.calls[0][0].message).toContain('Ingresá o pegá');
    expect(getUserMedia).not.toHaveBeenCalled();
  });
  it('informa permiso denegado sin bloquear el reintento', async () => {
    getUserMedia.mockRejectedValue(new Error('NotAllowedError'));
    const error = vi.fn();
    new LectorQrWeb().leer(video).subscribe({ error });
    await vi.advanceTimersByTimeAsync(0);
    expect(error.mock.calls[0][0].message).toContain('Revisá los permisos');
  });
  it('detiene la cámara cuando no detecta un QR en 30 segundos', async () => {
    detect.mockResolvedValue([]);
    const error = vi.fn();
    new LectorQrWeb().leer(video).subscribe({ error });
    await vi.advanceTimersByTimeAsync(30000);
    expect(error.mock.calls[0][0].message).toContain('No se detectó');
    expect(stop).toHaveBeenCalledOnce();
  });
});
