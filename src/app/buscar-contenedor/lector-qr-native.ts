import { Observable } from 'rxjs';
import type { LectorQr } from './lector-qr';

type ScannerModule = typeof import('@capacitor/barcode-scanner');

export class LectorQrNative implements LectorQr {
  private ocupado = false;

  constructor(private readonly cargar: () => Promise<ScannerModule> = () => import('@capacitor/barcode-scanner')) {}

  leer(_video: HTMLVideoElement): Observable<string> {
    return new Observable(subscriber => {
      if (this.ocupado) {
        subscriber.complete();
        return;
      }
      this.ocupado = true;
      const ejecutar = async () => {
        try {
          const scanner = await this.cargar();
          if (subscriber.closed) return;
          const resultado = await scanner.CapacitorBarcodeScanner.scanBarcode({
            hint: scanner.CapacitorBarcodeScannerTypeHint.QR_CODE,
            cameraDirection: scanner.CapacitorBarcodeScannerCameraDirection.BACK,
            scanButton: false,
            scanInstructions: 'Apuntá al QR del contenedor.',
            cancelButtonAccessibilityLabel: 'Cancelar lectura',
          });
          if (subscriber.closed) return;
          if (resultado.format !== scanner.CapacitorBarcodeScannerTypeHint.QR_CODE ||
              typeof resultado.ScanResult !== 'string' || !resultado.ScanResult.trim()) {
            throw new Error('Invalid scan result');
          }
          // La interpretación del código sigue exclusivamente en codigo-qr.ts.
          subscriber.next(resultado.ScanResult);
          subscriber.complete();
        } catch (error: unknown) {
          if (subscriber.closed) return;
          const code = typeof error === 'object' && error !== null && 'code' in error ? error.code : undefined;
          if (code === 'OS-PLUG-BARC-0006') {
            subscriber.complete();
          } else {
            subscriber.error(new Error(code === 'OS-PLUG-BARC-0007'
              ? 'No se pudo abrir la cámara. Revisá los permisos o ingresá el código.'
              : 'No se pudo leer el QR. Reintentá o ingresá el código.'));
          }
        } finally {
          this.ocupado = false;
        }
      };
      void ejecutar();
      // 3.1.2 no expone stopScan. Cancelar cierra la suscripción: se ignoran
      // resultados tardíos. La actividad se cierra con cancelar/atrás nativos;
      // el bloqueo dura hasta que termina para impedir cámaras superpuestas.
    });
  }
}
