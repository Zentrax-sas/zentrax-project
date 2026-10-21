import { ErroresService, MENSAJES, registroConfirmado } from '../errores.service';
import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { FlujoIncidenciaService } from '../reporte-incidencia/flujo-incidencia.service';
import { IncidenciasService } from '../reporte-incidencia/incidencias.service';
import { Router } from '@angular/router';
import {
  IonBackButton,
  IonButton,
  IonContent,
  IonIcon,
  IonInput
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  shieldCheckmarkOutline,
  refreshOutline,
  checkmarkCircleOutline
} from 'ionicons/icons';

@Component({
  selector: 'app-captcha-reporte',
  templateUrl: './captcha-reporte.page.html',
  styleUrls: ['./captcha-reporte.page.scss'],
  imports: [
    IonContent,
    IonBackButton,
    IonButton,
    IonIcon,
    IonInput
  ]
})
export class CaptchaReportePage {
  private readonly errores = inject(ErroresService);
  private readonly router = inject(Router);

  numeroA = 0;
  numeroB = 0;
  respuesta = '';
  error = '';
  validando = false;

  private readonly flujo = inject(FlujoIncidenciaService);
  private readonly api = inject(IncidenciasService);
  private readonly cd = inject(ChangeDetectorRef);

  ionViewWillEnter(): void {
    if (this.flujo.registrada) { void this.router.navigate(['/incidencia-generada'], { replaceUrl: true }); return; }
    if (!this.flujo.pendiente) { void this.router.navigate(['/reporte-incidencia'], { replaceUrl: true }); return; }
    this.validando = false;
    this.generarCaptcha();
    this.cd.markForCheck();
  }

  constructor() {
    addIcons({
      shieldCheckmarkOutline,
      refreshOutline,
      checkmarkCircleOutline
    });

    this.generarCaptcha();
  }

  generarCaptcha(): void {
    if (this.validando) return;
    this.numeroA = Math.floor(Math.random() * 8) + 2;
    this.numeroB = Math.floor(Math.random() * 8) + 2;
    this.respuesta = '';
    this.error = '';
  }

  actualizarRespuesta(value: string | number | null | undefined): void {
    this.respuesta = value?.toString() ?? '';
    this.error = '';
  }

  async confirmar(): Promise<void> {
    if (this.validando) return;
    if (this.flujo.registrada) { await this.router.navigate(['/incidencia-generada'], { replaceUrl: true }); return; }
    const reporte = this.flujo.pendiente;
    if (!reporte) { await this.router.navigate(['/reporte-incidencia'], { replaceUrl: true }); return; }

    const respuestaLimpia = this.respuesta.trim();

    if (!respuestaLimpia) {
      this.error = 'Ingresá el resultado de la operación.';
      return;
    }

    const valor = Number(respuestaLimpia);

    if (!/^\d+$/.test(respuestaLimpia) || !Number.isFinite(valor)) {
      this.error = 'Ingresá un número válido.';
      return;
    }

    if (valor !== this.numeroA + this.numeroB) {
      this.regenerarConError();
      return;
    }

    this.validando = true;
    this.error = '';

    try {
      const alta = await firstValueFrom(this.api.crear(reporte));
      if (!registroConfirmado(alta, 'INC')) throw new Error('Alta sin confirmación.');
      const registrada = {
        celular: reporte.celular,
        ...alta, id_contenedor: reporte.id_contenedor,
        codigo_contenedor: reporte.codigo_contenedor, direccion: reporte.direccion, motivo: reporte.motivo,
        aviso: '', fecha: undefined as string | undefined, estado: undefined as string | undefined,
      };
      // Conservar el alta antes de subir la foto: un fallo posterior no repite el POST.
      this.flujo.registrada = registrada;
      if (reporte.foto) {
        try { await firstValueFrom(this.api.subirFoto(alta.id_incidencia, reporte.foto)); }
        catch { registrada.aviso = 'La incidencia quedó registrada, pero no se pudo adjuntar la foto.'; }
      }
      try {
        const estado = await firstValueFrom(this.api.seguimiento(alta.tracking_number));
        registrada.fecha = estado.fecha_reporte;
        registrada.estado = estado.estado;
      } catch { registrada.aviso += ' No se pudo consultar la fecha y el estado. Conservá el número de seguimiento.'; }
      this.flujo.pendiente = null;
      await this.router.navigate(['/incidencia-generada'], { replaceUrl: true });
    } catch (error) {
      this.error = this.errores.mensaje(error, 'incidencia');
    } finally {
      this.validando = false;
      this.cd.markForCheck();
    }
  }

  private regenerarConError(): void {
    this.numeroA = Math.floor(Math.random() * 8) + 2;
    this.numeroB = Math.floor(Math.random() * 8) + 2;
    this.respuesta = '';
    this.error = 'La respuesta no es correcta. Probá con la nueva operación.';
  }
}
