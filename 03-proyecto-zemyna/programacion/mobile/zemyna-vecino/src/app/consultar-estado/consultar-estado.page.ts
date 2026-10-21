import { ActivatedRoute } from '@angular/router';
import { ErroresService, ErrorUsuario } from '../errores.service';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, IonContent } from '@ionic/angular';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, firstValueFrom } from 'rxjs';
import { FORMATO_TRACKING, Seguimiento, SeguimientoService } from './seguimiento.service';
import { EditarTramiteComponent } from '../editar-tramite.component';
import { EdicionTramiteService, DatosEdicion } from '../edicion-tramite.service';

@Component({
  selector: 'app-consultar-estado',
  templateUrl: './consultar-estado.page.html',
  styleUrls: ['./consultar-estado.page.scss'],
  imports: [FormsModule, RouterLink, IonContent, EditarTramiteComponent],
})
export class ConsultarEstadoPage {
  private readonly alertas=inject(AlertController);
  private readonly edicion=inject(EdicionTramiteService);
  readonly cancelando=signal(false);
  readonly aviso=signal('');
  private readonly errores = inject(ErroresService);
  private readonly service = inject(SeguimientoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);
  codigo = this.route.snapshot.queryParamMap.get('codigo') ?? '';
  ionViewWillEnter(): void { const codigo = this.route.snapshot.queryParamMap.get('codigo'); if (codigo && FORMATO_TRACKING.test(codigo)) { this.codigo = codigo; this.consultar(); } }
  readonly cargando = signal(false);
  readonly error = signal('');
  readonly resultado = signal<Seguimiento | null>(null);

  limpiarResultado() {
    this.resultado.set(null);
    this.error.set('');
    this.aviso.set('');
  }

  consultar() {
    if (this.cargando()||this.cancelando()) return;
    this.limpiarResultado();
    this.codigo = this.codigo.trim().toUpperCase();
    if (!FORMATO_TRACKING.test(this.codigo)) {
      this.error.set('Ingresá un código válido: INC, RET o REF, año de 4 cifras y 5 caracteres de 0 a 9 o de A a F.');
      return;
    }
    this.cargando.set(true);
    this.service.consultar(this.codigo).pipe(
      takeUntilDestroyed(this.destroyRef), finalize(() => this.cargando.set(false)),
    ).subscribe({
      next: result => this.resultado.set(result),
      error: (err: unknown) => this.error.set(this.errores.mensaje(err, 'estado').replace(/un trámite/g,'una incidencia o solicitud').replace(/trámite/g,'incidencia o solicitud')),
    });
  }
  nombre(dato:Seguimiento):string{return dato.tracking_number.startsWith('INC-')?'incidencia':'solicitud';}
  soportaCancelacion(dato:Seguimiento):boolean{return /^(INC|RET)-/.test(dato.tracking_number);}
  finalizada(dato:Seguimiento):boolean{return ['resuelta','resuelto','cancelada','cancelado','finalizada','finalizado','cerrada','cerrado'].includes(dato.estado.trim().toLowerCase());}
  permiteCancelar(dato:Seguimiento):boolean{return this.soportaCancelacion(dato)&&dato.estado==='Pendiente'&&dato.cancelable===true&&!dato.asignada;}
  mensajeAsignada(dato:Seguimiento):string{return `Esta ${this.nombre(dato)} ya fue asignada. Para solicitar la cancelación, comunicate con Atención al Cliente al 0800 0800.`;}
  aplicarEdicion(dato:DatosEdicion):void{this.resultado.update(actual=>({...actual,...dato}));}
  async cancelar():Promise<void>{
    const dato=this.resultado();if(!dato||!this.permiteCancelar(dato)||this.cancelando()||this.cargando())return;
    this.cancelando.set(true);this.error.set('');this.aviso.set('');
    try{
      const alerta=await this.alertas.create({header:`¿Querés cancelar esta ${this.nombre(dato)}?`,buttons:[{text:'Volver',role:'cancel'},{text:'Sí, cancelar',role:'confirm'}]});
      await alerta.present();const {role}=await alerta.onDidDismiss();if(role!=='confirm')return;
      if(!Number.isInteger(dato.revision))throw new Error('Falta versión actual.');
      const actual=await firstValueFrom(this.edicion.cancelar(dato.tracking_number,dato.revision!));
      this.resultado.set(actual);this.aviso.set(dato.tracking_number.startsWith('INC-')?'Incidencia cancelada.':'Solicitud cancelada.');
    }catch(error){
      const original=error instanceof ErrorUsuario?error.original:error;
      if(original instanceof HttpErrorResponse&&original.status===409){
        const actual=original.error?.data as Seguimiento|undefined;
        if(actual?.tracking_number===dato.tracking_number&&typeof actual.estado==='string')this.resultado.set(actual);
        this.error.set(actual&&!this.finalizada(actual)&&!this.permiteCancelar(actual)?'El estado cambió. Revisá la información actual.':'El estado o los datos cambiaron. Revisá la información actual antes de cancelar.');
      }else if(original instanceof HttpErrorResponse&&original.status===403){this.error.set(`Este dispositivo no tiene autorización para cancelar esta ${this.nombre(dato)}.`);}
      else{this.error.set('No pudimos confirmar la cancelación. Consultá el estado antes de reintentar.');}
    }finally{this.cancelando.set(false);}
  }
}
