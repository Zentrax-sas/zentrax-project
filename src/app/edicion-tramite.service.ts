import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, timeout } from 'rxjs';
import { API_BASE_URL } from './api.config';
import { FORMATO_TRACKING, Seguimiento } from './consultar-estado/seguimiento.service';
import { FlujoIncidenciaService } from './reporte-incidencia/flujo-incidencia.service';
import { SolicitudRetiroFlowService } from './solicitud-retiro/solicitud-retiro-flow.service';

export interface DatosEdicion extends Seguimiento {
  editable: boolean;
  revision: number;
  tipo_problema?: string;
  id_tipo_residuo?: number;
  tipo_residuo?: string;
  celular?: string | null;
  updated_at?: string | null;
}
@Injectable({providedIn:'root'})
export class EdicionTramiteService {
  private readonly incidencias=inject(FlujoIncidenciaService);
  private readonly retiros=inject(SolicitudRetiroFlowService);
  private readonly http = inject(HttpClient);
  private readonly claves = new Map<string,string>();
  recordar(data: {tracking_number:string; edicion_token?:string | null}): void {
    if (!/^[a-f0-9]{64}$/.test(data.edicion_token ?? '')) return;
    this.claves.set(data.tracking_number,data.edicion_token!);
    try { localStorage.setItem(`edicion:${data.tracking_number}`,data.edicion_token!); } catch { /* Conservar acceso en esta sesión. */ }
  }
  private clave(tracking: string): string {
    try { return this.claves.get(tracking) ?? localStorage.getItem(`edicion:${tracking}`) ?? ''; }
    catch { return this.claves.get(tracking) ?? ''; }
  }
  disponible(tracking: string): boolean { return /^[a-f0-9]{64}$/.test(this.clave(tracking)); }
  consultar(tracking: string) { return this.peticion(tracking); }
  guardar(tracking:string, datos: {descripcion:string;revision:number;tipo_problema?:string;id_tipo_residuo?:number}) {
    return this.peticion(tracking,datos);
  }
  cancelar(tracking:string,revision:number){
    if(!/^(INC|RET)-\d{4}-[A-F0-9]{5}$/.test(tracking))throw new Error('Código inválido.');
    return this.http.post<{success:boolean;data:Seguimiento}>(`${API_BASE_URL}/cancelacion.php`,{revision},{params:{tracking_number:tracking},headers:{Authorization:`Bearer ${this.clave(tracking)}`},withCredentials:true}).pipe(timeout(15000),map(response=>{
      if(!response.success||response.data?.tracking_number!==tracking||response.data.estado!=='Cancelada'||!response.data.fecha_cancelacion)throw new Error('No se pudo confirmar la cancelación.');
      if(this.incidencias.registrada?.tracking_number===tracking)this.incidencias.registrada={...this.incidencias.registrada,estado:'Cancelada'};
      if(this.retiros.registrada?.tracking_number===tracking)this.retiros.registrada={...this.retiros.registrada,estado:'Cancelada'};
      return response.data;
    }));
  }
  private peticion(tracking:string, datos?:object) {
    if (!FORMATO_TRACKING.test(tracking)) throw new Error('Código inválido.');
    const url=`${API_BASE_URL}/edicion-tramite.php`;
    const options={params:{tracking_number:tracking},headers:{Authorization:`Bearer ${this.clave(tracking)}`},withCredentials:true};
    const request=datos ? this.http.put<{success:boolean;data:DatosEdicion}>(url,datos,options) : this.http.get<{success:boolean;data:DatosEdicion}>(url,options);
    return request.pipe(timeout(15000),map(response=>{
      if (!response.success || response.data?.tracking_number!==tracking || !Number.isInteger(response.data.revision)) throw new Error('No se pudo confirmar la edición.');
      if(datos){
        const actual=response.data;
        if(this.incidencias.registrada?.tracking_number===tracking){
          this.incidencias.registrada={...this.incidencias.registrada,descripcion:actual.descripcion??'',motivo:actual.tipo_problema??this.incidencias.registrada.motivo,estado:actual.estado};
        }
        if(this.retiros.registrada?.tracking_number===tracking){
          this.retiros.registrada={...this.retiros.registrada,descripcion:actual.descripcion??'',tipo_residuo:actual.tipo_residuo??this.retiros.registrada.tipo_residuo,estado:actual.estado};
        }
      }
      return response.data;
    }));
  }
}
