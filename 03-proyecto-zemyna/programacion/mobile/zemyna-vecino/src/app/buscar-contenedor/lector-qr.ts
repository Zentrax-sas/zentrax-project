import { InjectionToken } from '@angular/core';
import { Observable } from 'rxjs';
import { Capacitor } from '@capacitor/core';
import { LectorQrNative } from './lector-qr-native';

export interface LectorQr {
  leer(video: HTMLVideoElement): Observable<string>;
}
interface Detector { detect(video: HTMLVideoElement): Promise<{ rawValue: string }[]>; }
interface ConstructorDetector {
  new(options: { formats: string[] }): Detector;
  getSupportedFormats(): Promise<string[]>;
}

// La plataforma determina el adaptador; ambos entregan el texto al mismo flujo.
export function crearLectorQr(): LectorQr {
  return Capacitor.isNativePlatform() && Capacitor.getPlatform() === 'android'
    ? new LectorQrNative()
    : new LectorQrWeb();
}

export const LECTOR_QR = new InjectionToken<LectorQr>('LectorQr', {
  providedIn: 'root', factory: crearLectorQr,
});

export class LectorQrWeb implements LectorQr {
  leer(video: HTMLVideoElement): Observable<string> {
    return new Observable(subscriber => {
      let stream: MediaStream | undefined;
      let timer: ReturnType<typeof setTimeout> | undefined;
      let limite: ReturnType<typeof setTimeout> | undefined;
      const fallo = (mensaje: string) => subscriber.error(new Error(mensaje));
      const iniciar = async () => {
        const Detector = (globalThis as unknown as { BarcodeDetector?: ConstructorDetector }).BarcodeDetector;
        if (!Detector || !navigator.mediaDevices?.getUserMedia) {
          fallo('La cámara QR no está disponible en este navegador. Ingresá o pegá el código.'); return;
        }
        try {
          if (!(await Detector.getSupportedFormats()).includes('qr_code')) {
            fallo('Este navegador no reconoce QR. Ingresá o pegá el código.'); return;
          }
          if (subscriber.closed) return;
          const detector = new Detector({ formats: ['qr_code'] });
          const media = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: 'environment' } });
          if (subscriber.closed) { media.getTracks().forEach(track => track.stop()); return; }
          stream = media;
          video.srcObject = media;
          await video.play();
          if (subscriber.closed) return;
          limite = setTimeout(() => fallo('No se detectó un QR. Reintentá o ingresá el código.'), 30000);
          const detectar = async () => {
            try {
              const codigos = await detector.detect(video);
              if (subscriber.closed) return;
              if (codigos[0]) { subscriber.next(codigos[0].rawValue); subscriber.complete(); return; }
              timer = setTimeout(() => void detectar(), 350);
            } catch {
              if (!subscriber.closed) fallo('No se pudo leer el QR. Reintentá o ingresá el código.');
            }
          };
          void detectar();
        } catch {
          if (!subscriber.closed) fallo('No se pudo abrir la cámara. Revisá los permisos o ingresá el código.');
        }
      };
      void iniciar();
      return () => {
        clearTimeout(timer); clearTimeout(limite);
        stream?.getTracks().forEach(track => track.stop());
        if (stream && video.srcObject === stream) video.srcObject = null;
      };
    });
  }
}
