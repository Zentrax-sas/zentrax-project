import { inject, Injectable } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

export interface SolicitudRetiroBorrador {
  id_tipo_residuo: number;
  tipo_residuo: string;
  direccion: string;
  descripcion: string;
  celular: string;
  consentimiento_whatsapp?: boolean;
  foto: File | null;
  calle?: string;
  calle_busqueda?: string;
  numero_referencia?: string;
  latitud?: number;
  longitud?: number;
}

export interface SolicitudRetiroRegistrada {
  edicion_token?: string | null;
  descripcion?: string;
  id_solicitud: number;
  tracking_number: string;
  tipo_residuo: string;
  direccion: string;
  estado: string;
  fecha: string;
  celular: string | null;
  calle?: string;
  numero_referencia?: string;
  latitud?: number;
  longitud?: number;
}

@Injectable({ providedIn: 'root' })
export class SolicitudRetiroFlowService {
  pendiente: SolicitudRetiroBorrador | null = null;
  registrada: SolicitudRetiroRegistrada | null = null;

  reiniciar(): void {
    this.pendiente = null;
    this.registrada = null;
  }
}

export const solicitudRetiroPreparada: CanActivateFn = () =>
  !!inject(SolicitudRetiroFlowService).pendiente || inject(Router).parseUrl('/solicitud-retiro');

export const solicitudRetiroRegistrada: CanActivateFn = () =>
  !!inject(SolicitudRetiroFlowService).registrada || inject(Router).parseUrl('/solicitud-retiro');
