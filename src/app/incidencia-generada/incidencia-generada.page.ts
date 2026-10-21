import { SeguimientoLocalService } from '../seguimiento-local.service';
import { GuardarWhatsappService } from '../guardar-whatsapp.service';
import { EditarTramiteComponent } from '../editar-tramite.component';
import { DatosEdicion } from '../edicion-tramite.service';
import { ComprobanteIncidenciaService } from './comprobante-incidencia.service';
import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { FlujoIncidenciaService, IncidenciaRegistrada } from '../reporte-incidencia/flujo-incidencia.service';
import { Router } from '@angular/router';
import {
  IonButton,
  IonContent,
  IonIcon
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  checkmarkCircleOutline,
  copyOutline,
  downloadOutline,
  notificationsOutline,
  logoWhatsapp,
  homeOutline
} from 'ionicons/icons';

@Component({
  selector: 'app-incidencia-generada',
  templateUrl: './incidencia-generada.page.html',
  styleUrls: ['./incidencia-generada.page.scss'],
  imports: [
    EditarTramiteComponent,
    IonContent,
    IonButton,
    IonIcon
  ]
})
export class IncidenciaGeneradaPage {
  private readonly whatsapp = inject(GuardarWhatsappService);
  abriendoWhatsapp = false;
  private readonly seguimientoLocal = inject(SeguimientoLocalService);
  private readonly flujo = inject(FlujoIncidenciaService);
  private readonly cd = inject(ChangeDetectorRef);
  private readonly comprobante = inject(ComprobanteIncidenciaService);
  private generandoComprobante = false;
  incidencia: Partial<IncidenciaRegistrada> = {};

  ionViewWillEnter(): void {
    if (!this.flujo.registrada) { void this.router.navigate(['/reporte-incidencia'], { replaceUrl: true }); return; }
    this.incidencia = this.flujo.registrada;
    this.mensaje = this.incidencia.aviso ?? '';
    this.copiado = false;
    try { this.seguimientoActivo = this.seguimientoLocal.guardado(this.incidencia.tracking_number ?? ''); }
    catch { this.seguimientoActivo = false; }
    this.cd.markForCheck();
  }

  copiado = false;
  seguimientoActivo = false;
  mensaje = '';

  private readonly router = inject(Router);

  constructor() {
    addIcons({
      checkmarkCircleOutline,
      copyOutline,
      downloadOutline,
      notificationsOutline,
      logoWhatsapp,
      homeOutline
    });
  }

  get numeroSeguimiento(): string {
    return this.incidencia.tracking_number || '';
  }

  get fechaFormateada(): string {
    if (!this.incidencia.fecha) return 'No disponible';

    const fecha = new Date(this.incidencia.fecha.replace(' ', 'T'));

    if (Number.isNaN(fecha.getTime())) return this.incidencia.fecha;

    return new Intl.DateTimeFormat('es-UY', {
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(fecha);
  }

  async copiarNumero(): Promise<void> {
    if (!this.incidencia.tracking_number) {
      this.mensaje = 'No se pudo encontrar el número de seguimiento.';
      return;
    }

    try {
      await navigator.clipboard.writeText(this.incidencia.tracking_number);
      this.copiado = true;
      this.mensaje = 'Número de seguimiento copiado.';

      setTimeout(() => {
        this.copiado = false;
        this.cd.markForCheck();
      }, 1800);
    } catch {
      this.mensaje = 'No se pudo copiar automáticamente.';
    } finally { this.cd.markForCheck(); }
  }

  async descargarComprobante(): Promise<void> {
    if (this.generandoComprobante) return;
    if (!this.incidencia.tracking_number?.trim()) {
      this.mensaje = 'No se pudo generar el comprobante. Falta el número de seguimiento.';
      this.cd.markForCheck();
      return;
    }
    this.generandoComprobante = true;
    try {
      await this.comprobante.generar({
        ...this.incidencia,
        metodo: this.flujo.contenedor ? this.flujo.metodo : undefined,
      });
      this.mensaje = 'Comprobante generado.';
    } catch {
      this.mensaje = 'No se pudo generar o compartir el comprobante. Intentá nuevamente.';
    } finally {
      this.generandoComprobante = false;
      this.cd.markForCheck();
    }
  }
  activarSeguimiento(): void {
    if (!this.incidencia.tracking_number) return;
    const siguienteEstado = !this.seguimientoActivo;
    try {
      this.seguimientoLocal.guardar(this.incidencia.tracking_number, siguienteEstado);
    } catch {
      this.mensaje = 'No se pudo actualizar el guardado en este dispositivo. Intentá nuevamente.';
      return;
    }
    this.seguimientoActivo = siguienteEstado;
    this.mensaje = this.seguimientoActivo
      ? 'Seguimiento guardado en este dispositivo.'
      : 'Guardado local desactivado.';
  }

  async guardarEnWhatsapp(): Promise<void> {
    if (this.abriendoWhatsapp) return;
    this.abriendoWhatsapp = true;
    try { this.mensaje = await this.whatsapp.abrir('incidencia', this.numeroSeguimiento, this.incidencia.celular); }
    catch (error) { this.mensaje = (error as Error).message; }
    finally { this.abriendoWhatsapp = false; this.cd.markForCheck(); }
  }
  aplicarEdicion(datos:DatosEdicion):void {
    this.incidencia={...this.incidencia,descripcion:datos.descripcion??'',estado:datos.estado,motivo:datos.tipo_problema??this.incidencia.motivo??''};
    if(this.flujo.registrada)this.flujo.registrada={...this.flujo.registrada,...this.incidencia};
    this.cd.markForCheck();
  }

  volverInicio(): void {
    this.router.navigate(['/home']);
  }
}

