import { SeguimientoLocalService } from '../seguimiento-local.service';
import { GuardarWhatsappService } from '../guardar-whatsapp.service';
import { EditarTramiteComponent } from '../editar-tramite.component';
import { DatosEdicion } from '../edicion-tramite.service';
import { ComprobanteIncidenciaService } from '../incidencia-generada/comprobante-incidencia.service';
import { ChangeDetectorRef, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { IonButton, IonContent, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { checkmarkCircleOutline, copyOutline, homeOutline, downloadOutline, bookmarkOutline, searchOutline, logoWhatsapp } from 'ionicons/icons';
import { SolicitudRetiroFlowService, SolicitudRetiroRegistrada } from '../solicitud-retiro/solicitud-retiro-flow.service';

@Component({
  selector: 'app-solicitud-generada',
  templateUrl: './solicitud-generada.page.html',
  styleUrls: ['./solicitud-generada.page.scss'],
  imports: [IonButton, IonContent, IonIcon, EditarTramiteComponent]
})
export class SolicitudGeneradaPage {
  private readonly whatsapp = inject(GuardarWhatsappService);
  abriendoWhatsapp = false;
  private readonly router = inject(Router);
  private readonly flujo = inject(SolicitudRetiroFlowService);
  private readonly cd = inject(ChangeDetectorRef);

  private readonly comprobante = inject(ComprobanteIncidenciaService);
  private readonly seguimientoLocal = inject(SeguimientoLocalService);
  seguimientoActivo = false;
  generandoComprobante = false;
  solicitud: SolicitudRetiroRegistrada | null = null;
  copiado = false;
  mensaje = '';

  constructor() {
    addIcons({ checkmarkCircleOutline, copyOutline, homeOutline, downloadOutline, bookmarkOutline, searchOutline, logoWhatsapp });
  }

  ionViewWillEnter(): void {
    if (!this.flujo.registrada) {
      void this.router.navigate(['/solicitud-retiro'], { replaceUrl: true });
      return;
    }
    this.solicitud = this.flujo.registrada;
    this.copiado = false;
    this.mensaje = '';
    try { this.seguimientoActivo = this.seguimientoLocal.guardado(this.solicitud.tracking_number); }
    catch { this.seguimientoActivo = false; this.mensaje = 'No se pudo leer el guardado en este dispositivo.'; }
    this.cd.markForCheck();
  }

  get fechaFormateada(): string {
    if (!this.solicitud?.fecha) return 'No disponible';
    const fecha = new Date(this.solicitud.fecha.replace(' ', 'T'));
    if (Number.isNaN(fecha.getTime())) return this.solicitud.fecha;
    return new Intl.DateTimeFormat('es-UY', { dateStyle: 'short', timeStyle: 'short' }).format(fecha);
  }

  async copiarCodigo(): Promise<void> {
    const codigo = this.solicitud?.tracking_number;
    if (!codigo) return;
    try {
      await navigator.clipboard.writeText(codigo);
      this.copiado = true;
      this.mensaje = 'Código de seguimiento copiado.';
    } catch {
      this.mensaje = 'No se pudo copiar automáticamente.';
    }
    this.cd.markForCheck();
  }

  async descargarComprobante(): Promise<void> {
    if (!this.solicitud || this.generandoComprobante) return;
    this.generandoComprobante = true;
    try { await this.comprobante.generarRetiro(this.solicitud); this.mensaje = 'Comprobante generado.'; }
    catch { this.mensaje = 'No se pudo generar o compartir el comprobante. Intentá nuevamente.'; }
    finally { this.generandoComprobante = false; this.cd.markForCheck(); }
  }
  guardarSeguimiento(): void {
    if (!this.solicitud) return;
    const activo = !this.seguimientoActivo;
    try {
      this.seguimientoLocal.guardar(this.solicitud.tracking_number, activo);
      this.seguimientoActivo = activo;
      this.mensaje = activo ? 'Seguimiento guardado en este dispositivo.' : 'Guardado local desactivado.';
    } catch { this.mensaje = 'No se pudo actualizar el guardado en este dispositivo. Intentá nuevamente.'; }
    this.cd.markForCheck();
  }
  consultarSeguimiento(): void {
    if (this.solicitud) void this.router.navigate(['/consultar-estado'], {queryParams:{codigo:this.solicitud.tracking_number}});
  }
  async guardarEnWhatsapp(): Promise<void> {
    if (this.abriendoWhatsapp || !this.solicitud) return;
    this.abriendoWhatsapp = true;
    try { this.mensaje = await this.whatsapp.abrir('retiro', this.solicitud.tracking_number, this.solicitud.celular); }
    catch (error) { this.mensaje = (error as Error).message; }
    finally { this.abriendoWhatsapp = false; this.cd.markForCheck(); }
  }
  aplicarEdicion(datos:DatosEdicion):void {
    if(this.solicitud){
      this.solicitud={...this.solicitud,descripcion:datos.descripcion??'',tipo_residuo:datos.tipo_residuo??this.solicitud.tipo_residuo,estado:datos.estado};
      this.flujo.registrada=this.solicitud;this.cd.markForCheck();
    }
  }
  volverInicio(): void {
    this.flujo.reiniciar();
    void this.router.navigate(['/home']);
  }
}
