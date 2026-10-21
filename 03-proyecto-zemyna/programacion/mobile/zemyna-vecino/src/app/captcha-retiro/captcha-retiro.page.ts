import { ErroresService, MENSAJES, registroConfirmado } from '../errores.service';
import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { Router } from '@angular/router';
import {
  IonBackButton,
  IonButton,
  IonContent,
  IonIcon,
  IonInput
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import { checkmarkCircleOutline, refreshOutline, shieldCheckmarkOutline } from 'ionicons/icons';
import { SolicitudRetiroFlowService } from '../solicitud-retiro/solicitud-retiro-flow.service';
import { SolicitudesRetiroService } from '../solicitud-retiro/solicitudes-retiro.service';

@Component({
  selector: 'app-captcha-retiro',
  templateUrl: './captcha-retiro.page.html',
  styleUrls: ['./captcha-retiro.page.scss'],
  imports: [IonContent, IonBackButton, IonButton, IonIcon, IonInput]
})
export class CaptchaRetiroPage {
  private readonly errores = inject(ErroresService);
  private readonly router = inject(Router);
  private readonly flujo = inject(SolicitudRetiroFlowService);
  private readonly api = inject(SolicitudesRetiroService);
  private readonly cd = inject(ChangeDetectorRef);

  pregunta = '';
  respuesta = '';
  error = '';
  cargandoCaptcha = false;
  enviando = false;

  constructor() {
    addIcons({ checkmarkCircleOutline, refreshOutline, shieldCheckmarkOutline });
  }

  async ionViewWillEnter(): Promise<void> {
    if (!this.flujo.pendiente) {
      await this.router.navigate(['/solicitud-retiro'], { replaceUrl: true });
      return;
    }
    this.respuesta = '';
    await this.cargarCaptcha();
  }

  actualizarRespuesta(value: string | number | null | undefined): void {
    this.respuesta = value?.toString() ?? '';
    this.error = '';
  }

  async cargarCaptcha(preservarError = false): Promise<void> {
    this.cargandoCaptcha = true;
    if (!preservarError) this.error = '';
    try {
      this.pregunta = await firstValueFrom(this.api.obtenerCaptcha());
      this.respuesta = '';
    } catch (error) {
      this.pregunta = '';
      const aviso = this.errores.mensaje(error, 'captcha');
      this.error = preservarError && this.error ? this.error + ' ' + aviso : aviso;
    } finally {
      this.cargandoCaptcha = false;
      this.cd.markForCheck();
    }
  }

  async confirmar(): Promise<void> {
    if (this.enviando) return;
    const borrador = this.flujo.pendiente;
    if (!borrador) {
      await this.router.navigate(['/solicitud-retiro'], { replaceUrl: true });
      return;
    }
    if (!/^\d+$/.test(this.respuesta.trim())) {
      this.error = 'Ingresá el resultado de la operación.';
      return;
    }

    this.enviando = true;
    this.error = '';
    try {
      const alta = await firstValueFrom(this.api.crear(borrador, this.respuesta.trim()));
      if (!registroConfirmado(alta, 'RET')) throw new Error('Alta sin confirmación.');
      this.flujo.registrada = alta;
      this.flujo.pendiente = null;
      await this.router.navigate(['/solicitud-generada'], { replaceUrl: true });
    } catch (error) {
      this.error = this.mensajeError(error);
      await this.cargarCaptcha(true);
    } finally {
      this.enviando = false;
      this.cd.markForCheck();
    }
  }

  private mensajeError(error: unknown): string { return this.errores.mensaje(error, 'retiro'); }
}
