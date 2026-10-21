import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { DatosEdicion, EdicionTramiteService } from './edicion-tramite.service';
import { SolicitudesRetiroService, TipoResiduo } from './solicitud-retiro/solicitudes-retiro.service';
import { ErrorUsuario } from './errores.service';

@Component({selector:'app-editar-tramite',standalone:true,imports:[FormsModule],template:`
  @if (tracking().startsWith('INC-') || tracking().startsWith('RET-')) {
    @if (!datos()) { <button type="button" (click)="abrir()" [disabled]="ocupado()">{{ contextoConsulta() ? 'Editar ' + nombre : 'Editar datos del trámite' }}</button> }
    @if (datos(); as actual) {
      <form (ngSubmit)="guardar()">
        @if (actual.editable) {
          <label> {{ incidencia ? 'Motivo de la incidencia' : 'Tipo de residuo' }}
            <select name="tipo" [(ngModel)]="tipo" [disabled]="ocupado()" required>
              @if (incidencia) { @for (motivo of motivos; track motivo) { <option [value]="motivo">{{ motivo }}</option> } }
              @else { @for (residuo of tipos; track residuo.id_tipo_residuo) { <option [value]="residuo.id_tipo_residuo">{{ residuo.nombre }}</option> } }
            </select>
          </label>
          <label>Descripción<textarea name="descripcion" [(ngModel)]="descripcion" [maxlength]="incidencia ? 500 : 220" [disabled]="ocupado()" rows="4" required></textarea></label>
          <button type="submit" [disabled]="ocupado() || !descripcion.trim() || !tipo">{{ ocupado() ? 'Guardando…' : 'Guardar cambios' }}</button>
        } @else { <p>{{ contextoConsulta() ? 'Esta ' + nombre + ' está cerrada y no permite cambios.' : 'El trámite está cerrado y no permite cambios.' }}</p> }
        <button type="button" (click)="cancelar()" [disabled]="ocupado()">Cancelar edición</button>
      </form>
    }
    @if (mensaje()) { <p role="status">{{ mensaje() }}</p> }
  }
`,styles:`
  :host{display:block}form{display:grid;gap:10px;margin-top:10px}label{display:grid;gap:6px;font-size:13px;color:#f4f7f5}
  select,textarea{box-sizing:border-box;width:100%;min-width:0;min-height:48px;border:1px solid #59675f;border-radius:12px;padding:10px;background:#121915;color:#fff;font:inherit}
  textarea{resize:vertical}button{width:100%;min-height:48px;border:1px solid #36403b;border-radius:12px;background:#151c19;color:#a8e600;font-size:14px;font-weight:700;padding:10px;cursor:pointer}
  button:disabled{opacity:.6}p{font-size:13px;color:#f4f7f5;overflow-wrap:anywhere}:is(button,select,textarea):focus-visible{outline:2px solid #a8e600;outline-offset:2px}
`})
export class EditarTramiteComponent {
  readonly edicion=inject(EdicionTramiteService);
  private readonly retiros=inject(SolicitudesRetiroService);
  tracking=input.required<string>();
  contextoConsulta=input(false);
  get nombre():string{return this.incidencia?'incidencia':'solicitud';}
  actualizado=output<DatosEdicion>();
  readonly datos=signal<DatosEdicion|null>(null);
  readonly ocupado=signal(false);
  readonly mensaje=signal('');
  readonly motivos=['Contenedor Desbordado','Contenedor Roto/Dañado','Obstruido por Vehículo','Incendio/Vandalismo'];
  tipos:TipoResiduo[]=[];
  descripcion=''; tipo:string|number='';
  get incidencia():boolean{return this.tracking().startsWith('INC-');}
  async abrir():Promise<void>{
    if(this.ocupado())return;this.ocupado.set(true);this.mensaje.set('');
    try{
      const actual=await firstValueFrom(this.edicion.consultar(this.tracking()));
      if(!this.incidencia&&actual.editable)this.tipos=await firstValueFrom(this.retiros.obtenerTiposResiduo());
      this.descripcion=actual.descripcion??'';this.tipo=this.incidencia?actual.tipo_problema??'':actual.id_tipo_residuo??'';this.datos.set(actual);
    }catch(error){this.fallo(error);}finally{this.ocupado.set(false);}
  }
  cancelar():void{this.datos.set(null);this.mensaje.set('');}
  async guardar():Promise<void>{
    const actual=this.datos();if(this.ocupado()||!actual||!actual.editable)return;
    if(!this.descripcion.trim()){this.mensaje.set('Ingresá una descripción.');return;}
    this.ocupado.set(true);this.mensaje.set('');
    try{
      const datos={descripcion:this.descripcion,revision:actual.revision,...(this.incidencia?{tipo_problema:String(this.tipo)}:{id_tipo_residuo:Number(this.tipo)})};
      const guardado=await firstValueFrom(this.edicion.guardar(this.tracking(),datos));
      this.actualizado.emit(guardado);this.datos.set(null);this.mensaje.set('Cambios guardados correctamente.');
    }catch(error){this.fallo(error);}finally{this.ocupado.set(false);}
  }
  private fallo(error:unknown):void{
    const original=error instanceof ErrorUsuario?error.original:error;
    const estado=original instanceof HttpErrorResponse?original.status:0;
    this.mensaje.set(estado===409?'El trámite cambió o está cerrado. Conservá tu texto y volvé a consultar antes de editar.':estado===403?'Este dispositivo no tiene autorización para editar el trámite.':estado===400?'Revisá la descripción y el tipo seleccionado.':'No pudimos guardar o cargar los datos. Intentá nuevamente.');
    if(this.contextoConsulta())this.mensaje.update(texto=>texto.replace(/El trámite/g,'La '+this.nombre).replace(/el trámite/g,'la '+this.nombre).replace(/está cerrado/g,'está cerrada'));
  }
}
