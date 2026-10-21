import { InjectionToken, Injectable, inject } from '@angular/core';
import { App, RestoredListenerEvent } from '@capacitor/app';
import { ActionSheetController } from '@ionic/angular';
import type { CameraPlugin, Photo } from '@capacitor/camera';
import { Capacitor } from '@capacitor/core';

interface FotoCameraModule {
  Camera: Pick<CameraPlugin, 'getPhoto'>;
  CameraErrorCode: typeof import('@capacitor/camera').CameraErrorCode;
  CameraResultType: typeof import('@capacitor/camera').CameraResultType;
  CameraSource: typeof import('@capacitor/camera').CameraSource;
}

export const FOTO_CAMERA = new InjectionToken<() => Promise<FotoCameraModule>>('FOTO_CAMERA', {
  providedIn: 'root',
  factory: () => async () => {
    const camera = await import('@capacitor/camera');
    return {
      Camera: camera.Camera,
      CameraErrorCode: camera.CameraErrorCode,
      CameraResultType: camera.CameraResultType,
      CameraSource: camera.CameraSource,
    };
  },
});
export type FormularioConFoto = 'incidencia' | 'retiro';

const formularioFotoPendienteKey = 'zemyna:camera:pending-form';

const formatosPermitidos: Record<string, { mime: string; extension: string }> = {
  jpg: { mime: 'image/jpeg', extension: 'jpg' },
  jpeg: { mime: 'image/jpeg', extension: 'jpg' },
  png: { mime: 'image/png', extension: 'png' },
  webp: { mime: 'image/webp', extension: 'webp' },
};
const fuenteElegida = { Camera: 'CAMERA', Photos: 'PHOTOS' } as const;

@Injectable({ providedIn: 'root' })
export class FotoPickerService {
  private readonly actionSheetController = inject(ActionSheetController);
  private readonly loadCamera = inject(FOTO_CAMERA);
  private restoredPhoto: { form: FormularioConFoto; file: Promise<{file: File | null; error?: unknown}> } | null = null;
  private restoreListener?: Promise<unknown>;

  get esNativo(): boolean {
    return Capacitor.isNativePlatform();
  }

  registrarResultadoRestaurado(): void {
    if (!this.esNativo || this.restoreListener) return;
    this.restoreListener = App.addListener('appRestoredResult', event => {
      void this.recibirResultadoRestaurado(event);
    });
  }

  async recibirResultadoRestaurado(event: RestoredListenerEvent): Promise<void> {
    if (event.pluginId !== 'Camera' || event.methodName !== 'getPhoto') return;
    let form: FormularioConFoto | null = null;
    try {
      const guardado = localStorage.getItem(formularioFotoPendienteKey);
      if (guardado === 'incidencia' || guardado === 'retiro') form = guardado;
      localStorage.removeItem(formularioFotoPendienteKey);
    } catch { return; }
    if (!form) return;
    if (!event.success || !event.data) {
      this.restoredPhoto = {form, file:Promise.resolve({file:null, error:new Error('No se pudo recuperar la foto.')})};
      return;
    }
    this.restoredPhoto = {
      form,
      file: this.convertirFoto(event.data as Photo).then(file => ({file})).catch(error => ({file:null,error})),
    };
  }

  async consumirFotoRestaurada(form: FormularioConFoto): Promise<File | null> {
    if (!this.restoredPhoto || this.restoredPhoto.form !== form) return null;
    const restored = this.restoredPhoto;
    this.restoredPhoto = null;
    const result = await restored.file;
    if (result.error) throw result.error;
    return result.file;
  }

  async seleccionarFoto(form: FormularioConFoto): Promise<File | null> {
    let fuente: 'CAMERA' | 'PHOTOS' | null = null;
    const actionSheet = await this.actionSheetController.create({
      header: 'Agregar foto',
      cssClass: 'zemyna-foto-action-sheet',
      buttons: [
        { text: 'Sacar foto', handler: () => { fuente = fuenteElegida.Camera; } },
        { text: 'Elegir de galería', handler: () => { fuente = fuenteElegida.Photos; } },
        { text: 'Cancelar', role: 'cancel' },
      ],
    });
    await actionSheet.present();
    await actionSheet.onDidDismiss();
    if (!fuente) return null;

    try { localStorage.setItem(formularioFotoPendienteKey, form); } catch { /* Photo selection still works if storage is unavailable. */ }
    try {
      const cameraModule = await this.loadCamera();
      const photo: Photo = await cameraModule.Camera.getPhoto({
        source: fuente === fuenteElegida.Camera ? cameraModule.CameraSource.Camera : cameraModule.CameraSource.Photos,
        resultType: cameraModule.CameraResultType.Uri,
        quality: 90,
        width: 2048,
        height: 2048,
        saveToGallery: false,
      });
      return await this.convertirFoto(photo);
    } catch (error) {
      const codigo = typeof error === 'object' && error !== null && 'code' in error
        ? error.code
        : undefined;
      if (codigo === 'OS-PLUG-CAMR-0006' || codigo === 'OS-PLUG-CAMR-0020') {
        return null;
      }
      throw error;
      } finally {
        try { localStorage.removeItem(formularioFotoPendienteKey); } catch { /* Storage can be unavailable in browser privacy modes. */ }
    }
  }

    private async convertirFoto(photo: Photo): Promise<File> {
      if (!photo.webPath) throw new Error('La cámara no devolvió una ruta para la foto.');
      const response = await fetch(photo.webPath, {signal: AbortSignal.timeout(15000)});
      if (!response.ok) throw new Error('No se pudo leer la foto seleccionada.');
      const blob = await response.blob();
      const formato = formatosPermitidos[photo.format.toLowerCase()];
      if (!formato) throw new Error('La foto debe ser JPG, PNG o WEBP.');
      return new File([blob], `foto-${Date.now()}.${formato.extension}`, {
        type: formato.mime,
        lastModified: Date.now(),
      });
    }
}